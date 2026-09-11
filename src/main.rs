//! WhaleTracker SQL outlet. Protocol and gameplay data remain compatible with v1.
//! Concurrency ownership lives in admission/sink; durability in journal; database
//! schema and cache publication are isolated from connection-handling threads.
mod admission;
mod config;
mod database;
mod journal;
mod journal_lock;
mod points_cache;
mod protocol;
mod runtime_limits;
mod schema;
mod sink;
mod sql;

use config::Config;
use points_cache::PointsCache;
use runtime_limits::ConnectionLimit;
use sink::SqlSink;
use std::{io, net::TcpListener, sync::Arc, thread, time::Duration};

fn main() -> io::Result<()> {
    let cfg = Config::from_env();
    if !cfg.require_localhost && cfg.auth_token.is_empty() {
        return Err(io::Error::other(
            "non-loopback clients require WT_RUST_AUTH_TOKEN",
        ));
    }
    // Reserve the listening endpoint before migrations or workers are launched.
    let listener = TcpListener::bind(&cfg.bind)?;
    let pool = database::connect_pool().map_err(io::Error::other)?;
    schema::prepare(&pool, &cfg).map_err(io::Error::other)?;
    let cache = PointsCache::new(pool.clone(), cfg.clone());
    let sink = SqlSink::new(pool, cfg.clone(), Arc::clone(&cache)).map_err(io::Error::other)?;
    sink.replay().map_err(io::Error::other)?;
    sink.spawn_workers().map_err(io::Error::other)?;
    thread::Builder::new()
        .name("points-cache".into())
        .spawn(move || {
            if std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| cache.worker_loop()))
                .is_err()
            {
                eprintln!("fatal: points-cache worker panicked");
                std::process::abort();
            }
        })?;
    let limit = ConnectionLimit::new(cfg.max_clients);
    eprintln!(
        "[sql-sink] listening={} queue_rows={} queue_bytes={} clients={}",
        cfg.bind, cfg.max_queue_rows, cfg.max_queue_bytes, cfg.max_clients
    );
    for incoming in listener.incoming() {
        match incoming {
            Ok(stream) => {
                let peer = match stream.peer_addr() {
                    Ok(peer) => peer,
                    Err(_) => continue,
                };
                if cfg.require_localhost && !peer.ip().is_loopback() {
                    continue;
                }
                let Some(permit) = limit.try_acquire() else {
                    drop(stream);
                    continue;
                };
                let sink = Arc::clone(&sink);
                let cfg = cfg.clone();
                if let Err(err) =
                    thread::Builder::new()
                        .name("sql-client".into())
                        .spawn(move || {
                            let _permit = permit;
                            if let Err(err) = protocol::handle_client(stream, sink, cfg) {
                                eprintln!("[sql-sink] connection ended: {err}");
                            }
                        })
                {
                    eprintln!("[sql-sink] cannot spawn client handler: {err}");
                }
            }
            Err(err) => {
                eprintln!("[sql-sink] accept failed: {err}");
                thread::sleep(Duration::from_millis(100));
            }
        }
    }
    Ok(())
}
