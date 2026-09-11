//! Coalesced invalidations run on every sink instance. Only the configured owner
//! rebuilds, and MySQL serializes rebuilds even when two hosts claim that role.
use crate::{
    config::{now_secs, number, Config, RANK_MIN_KD_SUM, RANK_MIN_PLAYTIME_SECONDS},
    database::{connection, with_named_lock},
};
use mysql::{params, prelude::Queryable, Pool, PooledConn};
use std::{
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    },
    thread,
    time::{Duration, Instant},
};

// Kept verbatim from the pinned repository; this refactor does not change ranks.
const WHALE_POINTS_SQL_EXPR: &str = r#"ROUND(1000.0 * SQRT(((CASE WHEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) > 0 THEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) ELSE 1 END)) / (((CASE WHEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) > 0 THEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) ELSE 1 END)) + 400.0)) * (((CASE WHEN playtime > 0 THEN playtime ELSE 0 END) / 3600.0) / (((CASE WHEN playtime > 0 THEN playtime ELSE 0 END) / 3600.0) + 20.0)) * ((5.0 * (((CASE WHEN kills > 0 THEN kills ELSE 0 END) + ((CASE WHEN assists > 0 THEN assists ELSE 0 END) * 0.35)) / ((CASE WHEN deaths > 0 THEN deaths ELSE 0 END) + 20.0))) + LN(1.0 + ((CASE WHEN damage_dealt > 0 THEN damage_dealt ELSE 0 END) / (150.0 * ((CASE WHEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) > 0 THEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) ELSE 1 END))))) + (0.60 * LN(1.0 + ((CASE WHEN healing > 0 THEN healing ELSE 0 END) / (100.0 * ((CASE WHEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) > 0 THEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) ELSE 1 END)))))) + (0.90 * LN(1.0 + ((60.0 * (CASE WHEN total_ubers > 0 THEN total_ubers ELSE 0 END)) / ((CASE WHEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) > 0 THEN ((CASE WHEN kills > 0 THEN kills ELSE 0 END) + (CASE WHEN deaths > 0 THEN deaths ELSE 0 END)) ELSE 1 END)))))))"#;

pub struct PointsCache {
    pool: Pool,
    cfg: Config,
    pending: AtomicBool,
    max_wait: Duration,
}

impl PointsCache {
    pub fn new(pool: Pool, cfg: Config) -> Arc<Self> {
        Arc::new(Self {
            pool,
            cfg,
            pending: AtomicBool::new(true),
            max_wait: Duration::from_millis(
                number("WT_POINTS_CACHE_MAX_WAIT_MS", 30_000).clamp(1, 3_600_000),
            ),
        })
    }

    pub fn mark_dirty(&self) {
        // No database/network operation on a write-completion path. swap(false)
        // in the worker cannot erase an invalidation arriving during its write.
        self.pending.store(true, Ordering::Release);
    }

    pub fn worker_loop(self: Arc<Self>) {
        let mut last_touch: Option<Instant> = None;
        loop {
            if last_touch.is_none_or(|last| last.elapsed() >= self.cfg.cache_touch)
                && self.pending.swap(false, Ordering::AcqRel)
            {
                match self.persist_invalidation() {
                    Ok(()) => last_touch = Some(Instant::now()),
                    Err(err) => {
                        self.pending.store(true, Ordering::Release);
                        eprintln!("[points-cache] invalidation retained after error: {err}");
                    }
                }
            }
            if self.cfg.bind_port() == self.cfg.cache_owner_port {
                if let Err(err) = self.poll_and_rebuild() {
                    eprintln!("[points-cache] rebuild deferred: {err}");
                }
            }
            thread::sleep(self.cfg.cache_poll);
        }
    }

    fn persist_invalidation(&self) -> Result<(), String> {
        let mut conn = connection(&self.pool)?;
        conn.query_drop(
            "INSERT INTO whaletracker_points_cache_state \
             (cache_key, dirty, dirty_updated_at, last_reason, last_rebuilt_at, dirty_generation, dirty_since) \
             VALUES ('global', 1, CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000 AS UNSIGNED), 'stats_write', 0, 1, CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000 AS UNSIGNED)) \
             ON DUPLICATE KEY UPDATE \
             dirty_since = CASE WHEN dirty = 0 OR dirty_since = 0 THEN VALUES(dirty_updated_at) ELSE dirty_since END, \
             dirty = 1, dirty_updated_at = VALUES(dirty_updated_at), \
             last_reason = VALUES(last_reason), dirty_generation = dirty_generation + 1"
        ).map_err(|err| err.to_string())
    }

    fn poll_and_rebuild(&self) -> Result<(), String> {
        let conn = connection(&self.pool)?;
        with_named_lock(conn, "wt-points-cache", 0, |conn| {
            let state: Option<(u8, u64, u64, u64, u64)> = conn.query_first(
                "SELECT dirty, dirty_updated_at, dirty_generation, dirty_since, \
                 CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000 AS UNSIGNED) \
                 FROM whaletracker_points_cache_state WHERE cache_key = 'global' LIMIT 1"
            ).map_err(|err| err.to_string())?;
            let Some((dirty, updated, generation, since, now)) = state else { return Ok(()); };
            if dirty == 0 { return Ok(()); }
            let debounce_ms = self.cfg.cache_debounce.as_millis() as u64;
            let max_wait_ms = self.max_wait.as_millis() as u64;
            if now.saturating_sub(updated) < debounce_ms
                && now.saturating_sub(if since == 0 { updated } else { since }) < max_wait_ms
            { return Ok(()); }

            self.rebuild(conn)?;
            // A later invalidation has a different generation even if it happened
            // in the same millisecond. Never acknowledge the newer writer's work.
            conn.exec_drop(
                "UPDATE whaletracker_points_cache_state SET dirty = 0, dirty_since = 0, \
                 last_rebuilt_at = CAST(UNIX_TIMESTAMP(CURRENT_TIMESTAMP(3))*1000 AS UNSIGNED), last_reason = 'rebuilt' \
                 WHERE cache_key = 'global' AND dirty = 1 AND dirty_generation = :generation",
                params! { "generation" => generation },
            ).map_err(|err| err.to_string())?;
            Ok(())
        }).map(|_| ())
    }

    fn rebuild(&self, conn: &mut PooledConn) -> Result<(), String> {
        conn.query_drop("CREATE TABLE IF NOT EXISTS whaletracker_points_cache_build LIKE whaletracker_points_cache")
            .map_err(|err| err.to_string())?;
        conn.query_drop("TRUNCATE TABLE whaletracker_points_cache_build")
            .map_err(|err| err.to_string())?;
        let insert_sql = format!(
            "INSERT INTO whaletracker_points_cache_build (steamid, points, rank, name_color, updated_at) \
             SELECT base.steamid, base.points, COALESCE(ranked.rank, 0), base.color, {now} \
             FROM (\
             SELECT w.steamid, {expr} AS points, \
             COALESCE(NULLIF(f.color COLLATE utf8mb4_uca1400_ai_ci,''), COALESCE(NULLIF(c.name_color,''), 'gold')) AS color \
             FROM whaletracker w \
             LEFT JOIN filters_namecolors f ON f.steamid COLLATE utf8mb4_uca1400_ai_ci = w.steamid \
             LEFT JOIN whaletracker_points_cache c ON c.steamid = w.steamid \
             ) base \
             LEFT JOIN (\
             SELECT eligible.steamid, ROW_NUMBER() OVER (ORDER BY eligible.points DESC, eligible.steamid ASC) AS rank \
             FROM (\
             SELECT w.steamid, {expr} AS points \
             FROM whaletracker w \
             WHERE ((CASE WHEN w.kills > 0 THEN w.kills ELSE 0 END) + (CASE WHEN w.deaths > 0 THEN w.deaths ELSE 0 END)) >= {min_kd_sum} \
             AND (CASE WHEN w.playtime > 0 THEN w.playtime ELSE 0 END) >= {min_playtime}\
             ) eligible\
             ) ranked ON ranked.steamid = base.steamid",
            now = now_secs(), expr = WHALE_POINTS_SQL_EXPR,
            min_kd_sum = RANK_MIN_KD_SUM, min_playtime = RANK_MIN_PLAYTIME_SECONDS,
        );
        conn.query_drop(insert_sql).map_err(|err| err.to_string())?;
        // Atomic publication: readers never observe an empty or half-built cache.
        conn.query_drop(
            "RENAME TABLE whaletracker_points_cache TO whaletracker_points_cache_swap, \
             whaletracker_points_cache_build TO whaletracker_points_cache, \
             whaletracker_points_cache_swap TO whaletracker_points_cache_build",
        )
        .map_err(|err| err.to_string())
    }
}
