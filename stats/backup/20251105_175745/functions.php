<?php

require_once __DIR__ . '/config.php';

const WT_CLASS_METADATA = [
    1 => ['slug' => 'scout', 'label' => 'Scout', 'icon' => 'Scout.png'],
    2 => ['slug' => 'sniper', 'label' => 'Sniper', 'icon' => 'Sniper.png'],
    3 => ['slug' => 'soldier', 'label' => 'Soldier', 'icon' => 'Soldier.png'],
    4 => ['slug' => 'demoman', 'label' => 'Demoman', 'icon' => 'Demoman.png'],
    5 => ['slug' => 'medic', 'label' => 'Medic', 'icon' => 'Medic.png'],
    6 => ['slug' => 'heavy', 'label' => 'Heavy', 'icon' => 'Heavy.png'],
    7 => ['slug' => 'pyro', 'label' => 'Pyro', 'icon' => 'Pyro.png'],
    8 => ['slug' => 'spy', 'label' => 'Spy', 'icon' => 'Spy.png'],
    9 => ['slug' => 'engineer', 'label' => 'Engineer', 'icon' => 'Engineer.png'],
];

function wt_pdo(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', WT_DB_HOST, WT_DB_NAME);
    $pdo = new PDO($dsn, WT_DB_USER, WT_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function wt_cache_client()
{
    static $client;
    static $initialized = false;

    if ($client instanceof Redis) {
        return $client;
    }

    if ($initialized) {
        return null;
    }

    $initialized = true;

    if (!class_exists('Redis')) {
        return null;
    }

    $host = getenv('WT_CACHE_HOST') ?: '127.0.0.1';
    $port = (int)(getenv('WT_CACHE_PORT') ?: 6379);
    $timeoutEnv = getenv('WT_CACHE_TIMEOUT');
    $timeout = $timeoutEnv !== false ? (float)$timeoutEnv : 0.1;

    $redis = new Redis();

    try {
        if (!$redis->connect($host, $port, $timeout)) {
            return null;
        }
        if (defined('Redis::OPT_SERIALIZER') && defined('Redis::SERIALIZER_PHP')) {
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
        }
    } catch (Throwable $e) {
        return null;
    }

    $client = $redis;

    return $client;
}

function wt_cache_get(string $key)
{
    $redis = wt_cache_client();
    if (!$redis) {
        return null;
    }

    try {
        $value = $redis->get($key);
    } catch (Throwable $e) {
        return null;
    }

    if ($value === false) {
        return null;
    }

    return $value;
}

function wt_cache_set(string $key, $value, int $ttl): void
{
    $redis = wt_cache_client();
    if (!$redis) {
        return;
    }

    $ttl = max(1, $ttl);

    try {
        $redis->setex($key, $ttl, $value);
    } catch (Throwable $e) {
        // Ignore cache write failures.
    }
}

function wt_ensure_log_class_schema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo = wt_pdo();
    $queries = [
        'ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS classes_mask INT DEFAULT 0',
        'ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS classes_mask INT DEFAULT 0',
        'ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots INT DEFAULT 0',
        'ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits INT DEFAULT 0',
        'ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots INT DEFAULT 0',
        'ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits INT DEFAULT 0',
    ];

    foreach (WT_CLASS_METADATA as $meta) {
        $queries[] = sprintf(
            'ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_%s INT DEFAULT 0',
            $meta['slug']
        );
        $queries[] = sprintf(
            'ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_%s INT DEFAULT 0',
            $meta['slug']
        );
    }

    foreach ($queries as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // ignore - older MySQL may not support IF NOT EXISTS; best effort
        }
    }
    $ensured = true;
}

function wt_fetch_all_stats(): array
{
    $cacheKey = 'wt:stats:all';
    $cacheTtl = (int)(getenv('WT_CACHE_TTL_STATS') ?: 10);
    $disableCache = isset($_GET['nocache']);
    $cached = null;
    if (!$disableCache) {
        $cached = wt_cache_get($cacheKey);
    }
    if (is_array($cached)) {
        return $cached;
    }

    $pdo = wt_pdo();
    $stmt = $pdo->query(sprintf('SELECT * FROM %s', WT_DB_TABLE));
    $rows = $stmt->fetchAll();

    if (!$disableCache && $cacheTtl > 0) {
        wt_cache_set($cacheKey, $rows, $cacheTtl);
    }

    return $rows;
}

function wt_logs_table(): string
{
    return defined('WT_DB_LOG_TABLE') ? WT_DB_LOG_TABLE : 'whaletracker_logs';
}

function wt_log_players_table(): string
{
    return defined('WT_DB_LOG_PLAYERS_TABLE') ? WT_DB_LOG_PLAYERS_TABLE : 'whaletracker_log_players';
}

function wt_fetch_log_players(array $logIds): array
{
    wt_ensure_log_class_schema();
    $logIds = array_values(array_filter(array_unique($logIds), 'strlen'));
    if (empty($logIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($logIds), '?'));
    $sqlExtended = sprintf(
        'SELECT lp.log_id, lp.steamid, lp.personaname, lp.kills, lp.deaths, lp.assists, lp.damage, lp.damage_taken, lp.healing, lp.headshots, lp.backstabs, lp.total_ubers, lp.playtime, lp.medic_drops, lp.uber_drops, lp.airshots, lp.shots, lp.hits, lp.best_streak, lp.best_headshots_life, lp.best_backstabs_life, lp.best_score_life, lp.best_kills_life, lp.best_assists_life, lp.best_ubers_life, '
        . 'lp.weapon1_name, lp.weapon1_shots, lp.weapon1_hits, lp.weapon1_damage, lp.weapon1_defindex, '
        . 'lp.weapon2_name, lp.weapon2_shots, lp.weapon2_hits, lp.weapon2_damage, lp.weapon2_defindex, '
        . 'lp.weapon3_name, lp.weapon3_shots, lp.weapon3_hits, lp.weapon3_damage, lp.weapon3_defindex, '
        . 'lp.weapon4_name, lp.weapon4_shots, lp.weapon4_hits, lp.weapon4_damage, lp.weapon4_defindex, '
        . 'lp.weapon5_name, lp.weapon5_shots, lp.weapon5_hits, lp.weapon5_damage, lp.weapon5_defindex, '
        . 'lp.weapon6_name, lp.weapon6_shots, lp.weapon6_hits, lp.weapon6_damage, lp.weapon6_defindex, '
        . 'lp.airshots_soldier, lp.airshots_soldier_height, lp.airshots_demoman, lp.airshots_demoman_height, lp.airshots_sniper, lp.airshots_sniper_height, lp.airshots_medic, lp.airshots_medic_height, '
        . 'COALESCE(lp.classes_mask, 0) AS classes_mask, lp.is_admin, lp.last_updated, wt.best_weapon AS lifetime_best_weapon, wt.best_weapon_accuracy AS lifetime_best_weapon_accuracy '
        . 'FROM %s lp LEFT JOIN %s wt ON wt.steamid = lp.steamid WHERE lp.log_id IN (%s) ORDER BY lp.kills DESC, lp.assists DESC',
        wt_log_players_table(),
        WT_DB_TABLE,
        $placeholders
    );
    $sqlLegacy = sprintf(
        'SELECT lp.log_id, lp.steamid, lp.personaname, lp.kills, lp.deaths, lp.assists, lp.damage, lp.damage_taken, lp.healing, lp.headshots, lp.backstabs, lp.total_ubers, lp.playtime, lp.medic_drops, lp.uber_drops, lp.airshots, lp.shots, lp.hits, lp.best_streak, lp.best_headshots_life, lp.best_backstabs_life, lp.best_score_life, lp.best_kills_life, lp.best_assists_life, lp.best_ubers_life, '
        . 'lp.weapon1_name, lp.weapon1_shots, lp.weapon1_hits, lp.weapon1_damage, lp.weapon1_defindex, '
        . 'lp.weapon2_name, lp.weapon2_shots, lp.weapon2_hits, lp.weapon2_damage, lp.weapon2_defindex, '
        . 'lp.weapon3_name, lp.weapon3_shots, lp.weapon3_hits, lp.weapon3_damage, lp.weapon3_defindex, '
        . 'lp.airshots_soldier, lp.airshots_soldier_height, lp.airshots_demoman, lp.airshots_demoman_height, lp.airshots_sniper, lp.airshots_sniper_height, lp.airshots_medic, lp.airshots_medic_height, '
        . 'COALESCE(lp.classes_mask, 0) AS classes_mask, lp.is_admin, lp.last_updated, wt.best_weapon AS lifetime_best_weapon, wt.best_weapon_accuracy AS lifetime_best_weapon_accuracy '
        . 'FROM %s lp LEFT JOIN %s wt ON wt.steamid = lp.steamid WHERE lp.log_id IN (%s) ORDER BY lp.kills DESC, lp.assists DESC',
        wt_log_players_table(),
        WT_DB_TABLE,
        $placeholders
    );

    $pdo = wt_pdo();
    try {
        $stmt = $pdo->prepare($sqlExtended);
        $stmt->execute($logIds);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare($sqlLegacy);
        $stmt->execute($logIds);
    }
    $rows = $stmt->fetchAll();

    $grouped = [];
    $steamIds = [];
    foreach ($rows as $row) {
        $key = (string)($row['log_id'] ?? '');
        $steamId = (string)($row['steamid'] ?? '');
        if ($key === '' || $steamId === '') {
            continue;
        }
        $grouped[$key][] = $row;
        $steamIds[] = $steamId;
    }

    if (empty($grouped)) {
        return $grouped;
    }

    $steamIds = array_values(array_unique($steamIds));
    $profiles = wt_fetch_steam_profiles($steamIds);
    $defaultAvatar = 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png';

    foreach ($grouped as &$entries) {
        foreach ($entries as &$entry) {
            $steamId = (string)($entry['steamid'] ?? '');
            $profile = $steamId !== '' ? ($profiles[$steamId] ?? null) : null;
            $entry['personaname'] = $entry['personaname'] ?? ($profile['personaname'] ?? $steamId);
            $entry['profileurl'] = $profile['profileurl'] ?? ($entry['profileurl'] ?? null);
            $avatar = $profile['avatarfull'] ?? ($profile['avatar'] ?? null);
            $entry['avatar'] = $avatar ?? $defaultAvatar;
            $entry['avatarfull'] = $entry['avatar'];
            $entry['classes_mask'] = isset($entry['classes_mask']) ? (int)$entry['classes_mask'] : 0;

            $weaponSummary = [];
            for ($slot = 1; $slot <= 6; $slot++) {
                $nameKey = "weapon{$slot}_name";
                $shotsKey = "weapon{$slot}_shots";
                $hitsKey = "weapon{$slot}_hits";
                $damageKey = "weapon{$slot}_damage";
                $defKey = "weapon{$slot}_defindex";

                $weaponName = trim((string)($entry[$nameKey] ?? ''));
                $weaponShots = (int)($entry[$shotsKey] ?? 0);
                $weaponHits = (int)($entry[$hitsKey] ?? 0);
                $weaponDamage = (int)($entry[$damageKey] ?? 0);
                $weaponDef = (int)($entry[$defKey] ?? 0);

                unset($entry[$nameKey], $entry[$shotsKey], $entry[$hitsKey], $entry[$damageKey], $entry[$defKey]);

                if ($weaponName === '' && $weaponDamage <= 0 && $weaponShots <= 0 && $weaponHits <= 0) {
                    continue;
                }

                $accuracy = null;
                if ($weaponShots > 0) {
                    $accuracy = ($weaponHits / max($weaponShots, 1)) * 100.0;
                }

                $weaponSummary[] = [
                    'name' => $weaponName !== '' ? $weaponName : 'Unknown',
                    'shots' => $weaponShots,
                    'hits' => $weaponHits,
                    'damage' => $weaponDamage,
                    'defindex' => $weaponDef,
                    'accuracy' => $accuracy,
                ];
            }
            $entry['weapon_summary'] = $weaponSummary;

            $overallShots = (int)($entry['shots'] ?? 0);
            $overallHits = (int)($entry['hits'] ?? 0);
            $overallAccuracy = $overallShots > 0 ? ($overallHits / max($overallShots, 1) * 100.0) : null;
            $entry['class_accuracy_summary'] = ($overallAccuracy !== null)
                ? [[
                    'label' => 'Overall',
                    'accuracy' => $overallAccuracy,
                    'shots' => $overallShots,
                    'hits' => $overallHits,
                ]]
                : [];

            if (!empty($weaponSummary)) {
                usort($weaponSummary, static function (array $a, array $b): int {
                    $accA = isset($a['accuracy']) ? (float)$a['accuracy'] : 0.0;
                    $accB = isset($b['accuracy']) ? (float)$b['accuracy'] : 0.0;
                    if ($accA === $accB) {
                        $hitsA = isset($a['hits']) ? (int)$a['hits'] : 0;
                        $hitsB = isset($b['hits']) ? (int)$b['hits'] : 0;
                        return $hitsB <=> $hitsA;
                    }
                    return $accB <=> $accA;
                });
            }

            $entry['weapon_accuracy_summary'] = array_slice(array_map(static function ($weapon) {
                return [
                    'name' => $weapon['name'] ?? 'Unknown',
                    'accuracy' => isset($weapon['accuracy']) ? (float)$weapon['accuracy'] : null,
                    'shots' => isset($weapon['shots']) ? (int)$weapon['shots'] : 0,
                    'hits' => isset($weapon['hits']) ? (int)$weapon['hits'] : 0,
                ];
            }, $weaponSummary), 0, 6);

            $entry['weapon_summary'] = $weaponSummary;

            $matchBestWeapon = null;
            $matchBestAccuracy = null;
            foreach ($weaponSummary as $weapon) {
                if (!is_array($weapon)) {
                    continue;
                }
                $acc = isset($weapon['accuracy']) ? (float)$weapon['accuracy'] : null;
                if ($acc === null) {
                    continue;
                }
                if ($matchBestAccuracy === null || $acc > $matchBestAccuracy) {
                    $matchBestAccuracy = $acc;
                    $matchBestWeapon = $weapon['name'] ?? null;
                }
            }

            $lifetimeBestWeapon = isset($entry['lifetime_best_weapon']) ? (string)$entry['lifetime_best_weapon'] : '';
            $lifetimeBestWeaponAccuracy = isset($entry['lifetime_best_weapon_accuracy']) && $entry['lifetime_best_weapon_accuracy'] !== null
                ? ((float)$entry['lifetime_best_weapon_accuracy']) * 100.0
                : null;

            if ($matchBestAccuracy !== null) {
                $entry['best_weapon'] = $matchBestWeapon ?: ($lifetimeBestWeapon ?: null);
                $entry['best_weapon_accuracy'] = $matchBestAccuracy;
            } else {
                $entry['best_weapon'] = $lifetimeBestWeapon ?: null;
                $entry['best_weapon_accuracy'] = $lifetimeBestWeaponAccuracy;
            }

            unset($entry['lifetime_best_weapon'], $entry['lifetime_best_weapon_accuracy']);

            $airshotsSummary = [];
            $airshotKeys = [
                'soldier' => 'Soldier',
                'demoman' => 'Demoman',
                'sniper' => 'Sniper',
                'medic' => 'Medic',
            ];
            foreach ($airshotKeys as $key => $label) {
                $countKey = "airshots_{$key}";
                $heightKey = "airshots_{$key}_height";
                $count = (int)($entry[$countKey] ?? 0);
                $height = (int)($entry[$heightKey] ?? 0);
                unset($entry[$countKey], $entry[$heightKey]);
                $airshotsSummary[$key] = [
                    'label' => $label,
                    'count' => $count,
                    'max_height' => $height,
                ];
            }
            $entry['airshots_summary'] = $airshotsSummary;
        }
        unset($entry);
    }
    unset($entries);

    return $grouped;
}

function wt_fetch_weapon_accuracy_summary(array $steamIds, int $limit = 6): array
{
    $steamIds = array_values(array_filter(array_unique(array_map('strval', $steamIds)), 'strlen'));
    if (empty($steamIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($steamIds), '?'));
    $table = wt_log_players_table();

    $sqlExtended = sprintf(
        "SELECT steamid, weapon_name, SUM(shots) AS total_shots, SUM(hits) AS total_hits\n"
        . " FROM (\n"
        . "     SELECT steamid, weapon1_name AS weapon_name, weapon1_shots AS shots, weapon1_hits AS hits FROM %1\$s\n"
        . "     UNION ALL\n"
        . "     SELECT steamid, weapon2_name AS weapon_name, weapon2_shots AS shots, weapon2_hits AS hits FROM %1\$s\n"
        . "     UNION ALL\n"
        . "     SELECT steamid, weapon3_name AS weapon_name, weapon3_shots AS shots, weapon3_hits AS hits FROM %1\$s\n"
        . "     UNION ALL\n"
        . "     SELECT steamid, weapon4_name AS weapon_name, weapon4_shots AS shots, weapon4_hits AS hits FROM %1\$s\n"
        . "     UNION ALL\n"
        . "     SELECT steamid, weapon5_name AS weapon_name, weapon5_shots AS shots, weapon5_hits AS hits FROM %1\$s\n"
        . "     UNION ALL\n"
        . "     SELECT steamid, weapon6_name AS weapon_name, weapon6_shots AS shots, weapon6_hits AS hits FROM %1\$s\n"
        . " ) AS weapon_totals\n"
        . " WHERE steamid IN (%2\$s)\n"
        . "   AND weapon_name IS NOT NULL\n"
        . "   AND weapon_name <> ''\n"
        . "   AND shots > 0\n"
        . " GROUP BY steamid, weapon_name",
        $table,
        $placeholders
    );

    $pdo = wt_pdo();
    try {
        $stmt = $pdo->prepare($sqlExtended);
        $stmt->execute($steamIds);
    } catch (Throwable $e) {
        $sqlLegacy = sprintf(
            "SELECT steamid, weapon_name, SUM(shots) AS total_shots, SUM(hits) AS total_hits\n"
            . " FROM (\n"
            . "     SELECT steamid, weapon1_name AS weapon_name, weapon1_shots AS shots, weapon1_hits AS hits FROM %1\$s\n"
            . "     UNION ALL\n"
            . "     SELECT steamid, weapon2_name AS weapon_name, weapon2_shots AS shots, weapon2_hits AS hits FROM %1\$s\n"
            . "     UNION ALL\n"
            . "     SELECT steamid, weapon3_name AS weapon_name, weapon3_shots AS shots, weapon3_hits AS hits FROM %1\$s\n"
            . " ) AS weapon_totals\n"
            . " WHERE steamid IN (%2\$s)\n"
            . "   AND weapon_name IS NOT NULL\n"
            . "   AND weapon_name <> ''\n"
            . "   AND shots > 0\n"
            . " GROUP BY steamid, weapon_name",
            $table,
            $placeholders
        );
        $stmt = $pdo->prepare($sqlLegacy);
        $stmt->execute($steamIds);
    }

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $steamId = (string)($row['steamid'] ?? '');
        if ($steamId === '') {
            continue;
        }

        $shots = (int)($row['total_shots'] ?? 0);
        $hits = (int)($row['total_hits'] ?? 0);
        if ($shots <= 0) {
            continue;
        }

        $accuracy = $shots > 0 ? ($hits / $shots) * 100.0 : 0.0;
        $weaponName = trim((string)($row['weapon_name'] ?? ''));
        if ($weaponName === '') {
            $weaponName = 'Unknown';
        }

        if (!isset($results[$steamId])) {
            $results[$steamId] = [];
        }

        $results[$steamId][] = [
            'name' => $weaponName,
            'shots' => $shots,
            'hits' => $hits,
            'accuracy' => $accuracy,
        ];
    }

    foreach ($results as $steamId => &$weapons) {
        usort($weapons, static function (array $a, array $b): int {
            $accCmp = ($b['accuracy'] <=> $a['accuracy']);
            if ($accCmp !== 0) {
                return $accCmp;
            }
            return ($b['hits'] <=> $a['hits']);
        });
        if ($limit > 0 && count($weapons) > $limit) {
            $weapons = array_slice($weapons, 0, $limit);
        }
    }
    unset($weapons);

    return $results;
}

function wt_refresh_current_log(): void
{
    wt_ensure_log_class_schema();
    $pdo = wt_pdo();

    $sql = 'SELECT log_id, started_at, player_count FROM ' . wt_logs_table() . ' ORDER BY started_at DESC LIMIT 1';
    $current = $pdo->query($sql)->fetch();
    if (!$current) {
        return;
    }

    $logId = (string)($current['log_id'] ?? '');
    if ($logId === '') {
        return;
    }

    $startedAt = (int)($current['started_at'] ?? time());
    $existingCount = (int)($current['player_count'] ?? 0);
    $now = time();

    $players = $pdo->query('SELECT steamid, personaname, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits, playtime, total_ubers, best_streak, classes_mask FROM whaletracker_online')->fetchAll();

    $playerCount = 0;
    if (!empty($players)) {
        $steamIds = array_values(array_unique(array_filter(array_map(static function ($row) {
            return (string)($row['steamid'] ?? '');
        }, $players), 'strlen')));

        $adminFlags = !empty($steamIds) ? wt_fetch_admin_flags($steamIds) : [];

        $insertSql = 'INSERT INTO whaletracker_log_players (
                log_id, steamid, personaname, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs,
                total_ubers, playtime, medic_drops, uber_drops, airshots, shots, hits, best_streak, best_headshots_life,
                best_backstabs_life, best_score_life, best_kills_life, best_assists_life, best_ubers_life, classes_mask,
                is_admin, last_updated
            ) VALUES (
                :log_id, :steamid, :personaname, :kills, :deaths, :assists, :damage, :damage_taken, :healing, :headshots,
                :backstabs, :total_ubers, :playtime, :medic_drops, :uber_drops, :airshots, :shots, :hits, :best_streak,
                :best_headshots_life, :best_backstabs_life, :best_score_life, :best_kills_life, :best_assists_life,
                :best_ubers_life, :classes_mask, :is_admin, :last_updated
            )
            ON DUPLICATE KEY UPDATE
                personaname = VALUES(personaname),
                kills = VALUES(kills),
                deaths = VALUES(deaths),
                assists = VALUES(assists),
                damage = VALUES(damage),
                damage_taken = VALUES(damage_taken),
                healing = VALUES(healing),
                headshots = VALUES(headshots),
                backstabs = VALUES(backstabs),
                total_ubers = VALUES(total_ubers),
                playtime = VALUES(playtime),
                medic_drops = VALUES(medic_drops),
                uber_drops = VALUES(uber_drops),
                airshots = VALUES(airshots),
                shots = VALUES(shots),
                hits = VALUES(hits),
                best_streak = VALUES(best_streak),
                best_headshots_life = VALUES(best_headshots_life),
                best_backstabs_life = VALUES(best_backstabs_life),
                best_score_life = VALUES(best_score_life),
                best_kills_life = VALUES(best_kills_life),
                best_assists_life = VALUES(best_assists_life),
                best_ubers_life = VALUES(best_ubers_life),
                classes_mask = VALUES(classes_mask),
                is_admin = VALUES(is_admin),
                last_updated = VALUES(last_updated)';

        $stmt = $pdo->prepare($insertSql);

        foreach ($players as $player) {
            $steamId = (string)($player['steamid'] ?? '');
            if ($steamId === '') {
                continue;
            }

            $playerCount++;
            $personaname = $player['personaname'] ?? $steamId;
            $kills = (int)($player['kills'] ?? 0);
            $deaths = (int)($player['deaths'] ?? 0);
            $assists = (int)($player['assists'] ?? 0);
            $damage = (int)($player['damage'] ?? 0);
            $damageTaken = (int)($player['damage_taken'] ?? 0);
            $healing = (int)($player['healing'] ?? 0);
            $headshots = (int)($player['headshots'] ?? 0);
            $backstabs = (int)($player['backstabs'] ?? 0);
            $shots = (int)($player['shots'] ?? 0);
            $hits = (int)($player['hits'] ?? 0);
            $playtime = (int)($player['playtime'] ?? 0);
            $totalUbers = (int)($player['total_ubers'] ?? 0);
            $bestStreak = (int)($player['best_streak'] ?? 0);
            $classesMask = (int)($player['classes_mask'] ?? 0);
            $isAdmin = !empty($adminFlags[$steamId]) ? 1 : 0;

            $stmt->execute([
                ':log_id' => $logId,
                ':steamid' => $steamId,
                ':personaname' => $personaname,
                ':kills' => $kills,
                ':deaths' => $deaths,
                ':assists' => $assists,
                ':damage' => $damage,
                ':damage_taken' => $damageTaken,
                ':healing' => $healing,
                ':headshots' => $headshots,
                ':backstabs' => $backstabs,
                ':total_ubers' => $totalUbers,
                ':playtime' => $playtime,
                ':medic_drops' => 0,
                ':uber_drops' => 0,
                ':airshots' => 0,
                ':shots' => $shots,
                ':hits' => $hits,
                ':best_streak' => $bestStreak,
                ':best_headshots_life' => 0,
                ':best_backstabs_life' => 0,
                ':best_score_life' => 0,
                ':best_kills_life' => 0,
                ':best_assists_life' => 0,
                ':best_ubers_life' => 0,
                ':classes_mask' => $classesMask,
                ':is_admin' => $isAdmin,
                ':last_updated' => $now,
            ]);
        }
    }

    $duration = max(0, $now - $startedAt);
    $playerCount = $playerCount > 0 ? $playerCount : $existingCount;

    $update = $pdo->prepare('UPDATE whaletracker_logs SET ended_at = :now, duration = :duration, player_count = :count, updated_at = :now WHERE log_id = :id');
    $update->execute([
        ':now' => $now,
        ':duration' => $duration,
        ':count' => $playerCount,
        ':id' => $logId,
    ]);
}

function wt_fetch_logs(int $limit = 60): array
{
    $limit = max(1, min($limit, 100));

    wt_refresh_current_log();

    $fetchLimit = min($limit * 3, 300);

    $sql = sprintf(
        'SELECT log_id, map, gamemode, started_at, ended_at, duration, player_count, created_at, updated_at FROM %s WHERE player_count > 0 ORDER BY started_at DESC LIMIT %d',
        wt_logs_table(),
        $fetchLimit
    );

    $pdo = wt_pdo();
    $logs = $pdo->query($sql)->fetchAll();

    if (empty($logs)) {
        return [];
    }

    $logIds = array_column($logs, 'log_id');
    $playersByLog = wt_fetch_log_players($logIds);

    foreach ($logs as &$log) {
        $logId = (string)($log['log_id'] ?? '');
        $log['players'] = $playersByLog[$logId] ?? [];
    }
    unset($log);

    $logs = array_values(array_filter($logs, static function ($log) {
        $count = (int)($log['player_count'] ?? 0);
        if ($count <= 0 && !empty($log['players']) && is_array($log['players'])) {
            $count = count($log['players']);
        }
        return $count > 0;
    }));

    if (count($logs) > $limit) {
        $logs = array_slice($logs, 0, $limit);
    }

    return $logs;
}

function wt_fetch_admin_flags(array $steamIds): array
{
    $steamIds = array_values(array_unique(array_filter($steamIds)));
    if (empty($steamIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($steamIds), '?'));
    $sql = sprintf('SELECT steamid, is_admin FROM %s WHERE steamid IN (%s)', WT_DB_TABLE, $placeholders);

    $pdo = wt_pdo();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($steamIds);

    $flags = [];
    foreach ($stmt->fetchAll() as $row) {
        $steamId = (string)($row['steamid'] ?? '');
        if ($steamId === '') {
            continue;
        }
        $flags[$steamId] = !empty($row['is_admin']);
    }

    return $flags;
}

function wt_fetch_performance_averages(): array
{
    static $cachedAverages = null;
    if (is_array($cachedAverages)) {
        return $cachedAverages;
    }

    $cacheKey = 'wt:performance:averages';
    $cacheTtl = 86400;
    $disableCache = isset($_GET['nocache']);
    if (!$disableCache) {
        $cached = wt_cache_get($cacheKey);
        if (is_array($cached)) {
            $cachedAverages = $cached;
            return $cachedAverages;
        }
    }

    $pdo = wt_pdo();
    $sql = 'SELECT COUNT(*) AS eligible,
                   AVG(CASE WHEN deaths > 0 THEN kills / NULLIF(deaths, 0) ELSE kills END) AS avg_kd,
                   AVG(damage_dealt) AS avg_damage,
                   AVG(airshots) AS avg_airshots,
                   AVG(healing) AS avg_healing,
                   AVG(CASE WHEN playtime > 0 THEN damage_dealt / (playtime / 60.0) END) AS avg_dpm,
                   AVG(CASE WHEN shots > 0 THEN hits / shots END) AS avg_accuracy
            FROM ' . WT_DB_TABLE . '
            WHERE playtime >= 18000';

    $stmt = $pdo->query($sql);
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    $averages = [
        'eligible' => (int)($row['eligible'] ?? 0),
        'kd' => (isset($row['avg_kd']) && $row['avg_kd'] !== null) ? (float)$row['avg_kd'] : 0.0,
        'damage' => isset($row['avg_damage']) ? (float)$row['avg_damage'] : 0.0,
        'airshots' => isset($row['avg_airshots']) ? (float)$row['avg_airshots'] : 0.0,
        'healing' => isset($row['avg_healing']) ? (float)$row['avg_healing'] : 0.0,
        'dpm' => isset($row['avg_dpm']) ? (float)$row['avg_dpm'] : 0.0,
        'accuracy' => isset($row['avg_accuracy']) ? (float)$row['avg_accuracy'] * 100.0 : 0.0,
    ];

    if (!$disableCache) {
        wt_cache_set($cacheKey, $averages, $cacheTtl);
    }

    $cachedAverages = $averages;
    return $averages;
}

function wt_fetch_map_leaderboard(string $groupKey): array
{
    $group = wt_map_group($groupKey);
    if ($group === null) {
        return [];
    }

    $cacheKey = 'wt:stats:map:' . md5($group['key']);
    $cacheTtl = (int)(getenv('WT_CACHE_TTL_MAP') ?: 30);
    $cached = wt_cache_get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $maps = $group['maps'] ?? [];
    if (empty($maps)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($maps), '?'));
    $sql = sprintf(
        'SELECT steamid,
                SUM(kills) AS kills,
                SUM(deaths) AS deaths,
                SUM(assists) AS assists,
                SUM(healing) AS healing,
                SUM(headshots) AS headshots,
                SUM(backstabs) AS backstabs,
                SUM(medic_drops) AS medic_drops,
                SUM(medic_drops) AS uber_drops,
                SUM(damage_dealt) AS damage_dealt,
                SUM(damage_taken) AS damage_taken,
                SUM(playtime) AS playtime,
                MAX(best_killstreak) AS best_killstreak,
                MAX(best_weapon) AS best_weapon,
                MAX(best_weapon_accuracy) AS best_weapon_accuracy,
                MAX(last_seen) AS last_seen
         FROM %s
         WHERE map IN (%s)
         GROUP BY steamid',
        wt_map_table(),
        $placeholders
    );

    $pdo = wt_pdo();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($maps);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        if ($cacheTtl > 0) {
            wt_cache_set($cacheKey, [], $cacheTtl);
        }
        return [];
    }

    $steamIds = array_map(static function ($row) {
        return (string)($row['steamid'] ?? '');
    }, $rows);

    $adminFlags = wt_fetch_admin_flags($steamIds);

    foreach ($rows as &$row) {
        $steamId = (string)($row['steamid'] ?? '');
        $row['steamid'] = $steamId;
        $row['kills'] = (int)($row['kills'] ?? 0);
        $row['deaths'] = (int)($row['deaths'] ?? 0);
        $row['assists'] = (int)($row['assists'] ?? 0);
        $row['healing'] = (int)($row['healing'] ?? 0);
        $row['headshots'] = (int)($row['headshots'] ?? 0);
        $row['backstabs'] = (int)($row['backstabs'] ?? 0);
        $row['medic_drops'] = (int)($row['medic_drops'] ?? 0);
        $row['uber_drops'] = (int)($row['uber_drops'] ?? $row['medic_drops']);
        $row['damage_dealt'] = (int)($row['damage_dealt'] ?? 0);
        $row['damage_taken'] = (int)($row['damage_taken'] ?? 0);
        $row['playtime'] = (int)($row['playtime'] ?? 0);
        $row['best_killstreak'] = (int)($row['best_killstreak'] ?? 0);
        $row['best_weapon_accuracy'] = (float)($row['best_weapon_accuracy'] ?? 0.0);
        $row['best_weapon'] = $row['best_weapon'] ?? null;
        $row['last_seen'] = (int)($row['last_seen'] ?? 0);
        $row['is_admin'] = !empty($adminFlags[$steamId]);
    }
    unset($row);

    $profiles = wt_fetch_steam_profiles($steamIds);
    $rows = wt_stats_with_profiles($rows, $profiles);

    usort($rows, static function ($a, $b) {
        $scoreA = $a['score_total'] ?? 0;
        $scoreB = $b['score_total'] ?? 0;
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }
        return ($b['kills'] ?? 0) <=> ($a['kills'] ?? 0);
    });

    if ($cacheTtl > 0) {
        wt_cache_set($cacheKey, $rows, $cacheTtl);
    }

    return $rows;
}

function wt_format_playtime(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return sprintf('%dh %dm', $hours, $minutes);
    }
    return sprintf('%dm', max($minutes, 1));
}

function wt_normalize_steam_id(string $steamId): ?string
{
    $steamId = trim($steamId);
    if ($steamId === '') {
        return null;
    }

    if (preg_match('/^\d{17}$/', $steamId)) {
        return $steamId;
    }

    if (preg_match('/^\[U:1:(\d+)\]$/', $steamId, $matches)) {
        $accountId = (int)$matches[1];
        $steam64 = 76561197960265728 + $accountId;
        return (string)$steam64;
    }

    if (preg_match('/^STEAM_([0-5]):([0-1]):(\d+)$/', $steamId, $matches)) {
        $universe = (int)$matches[1];
        $y = (int)$matches[2];
        $z = (int)$matches[3];
        $accountId = ($z * 2) + $y;

        if ($universe <= 0) {
            $universe = 1;
        }
        if ($universe !== 1) {
            return null;
        }

        $steam64 = 76561197960265728 + $accountId;
        return (string)$steam64;
    }

    return null;
}

function wt_fetch_steam_profiles(array $steamIds): array
{
    $steamIds = array_values(array_unique(array_filter($steamIds)));
    if (empty($steamIds)) {
        return [];
    }

    $normalizedMap = [];
    foreach ($steamIds as $steamId) {
        $normalized = wt_normalize_steam_id($steamId);
        if ($normalized === null) {
            continue;
        }
        if (!isset($normalizedMap[$normalized])) {
            $normalizedMap[$normalized] = [];
        }
        $normalizedMap[$normalized][] = $steamId;
    }

    if (empty($normalizedMap)) {
        return [];
    }

    $profiles = [];
    $cachedNormalized = [];

    foreach ($normalizedMap as $normalized => $originals) {
        $cached = wt_read_cached_profile($normalized);
        if ($cached === null) {
            continue;
        }

        $cachedNormalized[] = $normalized;
        foreach ($originals as $original) {
            $profiles[$original] = $cached;
        }
    }

    $missing = array_values(array_diff(array_keys($normalizedMap), $cachedNormalized));

    if (!empty($missing) && WT_STEAM_API_KEY !== '') {
        $chunks = array_chunk($missing, 100);
        foreach ($chunks as $chunk) {
            $url = sprintf(
                'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=%s&steamids=%s',
                urlencode(WT_STEAM_API_KEY),
                implode(',', $chunk)
            );

            $response = @file_get_contents($url);
            if ($response === false) {
                continue;
            }

            $data = json_decode($response, true);
            if (!isset($data['response']['players'])) {
                continue;
            }

            foreach ($data['response']['players'] as $player) {
                $normalized = $player['steamid'] ?? null;
                if ($normalized === null) {
                    continue;
                }

                $profile = [
                    'steamid' => $normalized,
                    'personaname' => $player['personaname'] ?? $normalized,
                    'profileurl' => $player['profileurl'] ?? null,
                    'avatarfull' => $player['avatarfull'] ?? ($player['avatar'] ?? null),
                    'timecreated' => $player['timecreated'] ?? null,
                ];

                wt_write_cached_profile($normalized, $profile);

                if (!isset($normalizedMap[$normalized])) {
                    continue;
                }

                foreach ($normalizedMap[$normalized] as $original) {
                    $profiles[$original] = $profile;
                }
            }
        }
    }

    return $profiles;
}

function wt_cache_dir(): string
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function wt_cache_path(string $steamId): string
{
    return sprintf('%s/%s.json', wt_cache_dir(), preg_replace('/\D+/', '', $steamId));
}

function wt_read_cached_profile(string $steamId): ?array
{
    $path = wt_cache_path($steamId);
    if (!is_file($path)) {
        return null;
    }

    if ((time() - filemtime($path)) > WT_CACHE_TTL) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false) {
        return null;
    }

    return json_decode($json, true);
}

function wt_write_cached_profile(string $steamId, array $profile): void
{
    $path = wt_cache_path($steamId);
    @file_put_contents($path, json_encode($profile));
}

function wt_stats_with_profiles(array $stats, array $profiles): array
{
    $now = time();
    $steamIds = array_column($stats, 'steamid');
    $weaponAccuracyMap = wt_fetch_weapon_accuracy_summary($steamIds, 3);
    foreach ($stats as &$stat) {
        $steamId = $stat['steamid'];
        $profile = $profiles[$steamId] ?? null;

        $storedName = $stat['personaname'] ?? null;
        $stat['personaname'] = $profile['personaname'] ?? $storedName ?? $steamId;
        $stat['avatar'] = $profile['avatarfull'] ?? 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png';
        $stat['profileurl'] = $profile['profileurl'] ?? null;

        $stat['healing'] = isset($stat['healing']) ? (int)$stat['healing'] : 0;
        $stat['headshots'] = isset($stat['headshots']) ? (int)$stat['headshots'] : 0;
        $stat['backstabs'] = isset($stat['backstabs']) ? (int)$stat['backstabs'] : 0;
        $medicDrops = isset($stat['medic_drops']) ? (int)$stat['medic_drops'] : 0;
        $uberDrops = isset($stat['uber_drops']) ? (int)$stat['uber_drops'] : $medicDrops;
        $stat['medic_drops'] = $medicDrops;
        $stat['uber_drops'] = $uberDrops;
        $stat['damage_dealt'] = isset($stat['damage_dealt']) ? (int)$stat['damage_dealt'] : 0;
        $stat['damage_taken'] = isset($stat['damage_taken']) ? (int)$stat['damage_taken'] : 0;
        $stat['last_seen'] = isset($stat['last_seen']) ? (int)$stat['last_seen'] : 0;
        $stat['favorite_class'] = isset($stat['favorite_class']) ? (int)$stat['favorite_class'] : 0;
        $totalShots = isset($stat['shots']) ? (int)$stat['shots'] : 0;
        $totalHits = isset($stat['hits']) ? (int)$stat['hits'] : 0;
        $overallAccuracy = $totalShots > 0 ? ($totalHits / $totalShots) * 100.0 : 0.0;
        $bestAccRaw = isset($stat['best_weapon_accuracy']) ? (float)$stat['best_weapon_accuracy'] : 0.0;
        $stat['accuracy_overall'] = $overallAccuracy;
        $stat['accuracy'] = $overallAccuracy;
        $stat['best_weapon_accuracy_pct'] = $bestAccRaw * 100.0;
        $stat['kd'] = $stat['deaths'] > 0 ? $stat['kills'] / $stat['deaths'] : $stat['kills'];
        $stat['playtime_human'] = wt_format_playtime((int)$stat['playtime']);
        $minutesPlayed = ($stat['playtime'] > 0) ? ((float)$stat['playtime'] / 60.0) : 0.0;
        $stat['damage_per_minute'] = ($minutesPlayed > 0.0) ? $stat['damage_dealt'] / $minutesPlayed : 0.0;
        $stat['damage_taken_per_minute'] = ($minutesPlayed > 0.0) ? $stat['damage_taken'] / $minutesPlayed : 0.0;
        $stat['score_total'] = (int)$stat['kills'] + (int)$stat['assists'];
        $stat['is_online'] = ($stat['last_seen'] > 0) ? (($now - $stat['last_seen']) <= 60) : false;
        $stat['is_admin'] = !empty($stat['is_admin']);

        $classAccuracies = [];
        $topClass = null;
        foreach (WT_CLASS_METADATA as $classId => $meta) {
            $shotsKey = 'shots_' . $meta['slug'];
            $hitsKey = 'hits_' . $meta['slug'];
            $classShots = isset($stat[$shotsKey]) ? (int)$stat[$shotsKey] : 0;
            $classHits = isset($stat[$hitsKey]) ? (int)$stat[$hitsKey] : 0;
            $classAccuracy = $classShots > 0 ? ($classHits / $classShots) * 100.0 : null;
            $classAccuracies[$classId] = [
                'shots' => $classShots,
                'hits' => $classHits,
                'accuracy' => $classAccuracy,
                'slug' => $meta['slug'],
                'label' => $meta['label'],
                'icon' => $meta['icon'],
            ];
            if ($classAccuracy !== null && ($topClass === null || $classAccuracy > $topClass['accuracy'])) {
                $topClass = [
                    'class_id' => $classId,
                    'slug' => $meta['slug'],
                    'label' => $meta['label'],
                    'icon' => $meta['icon'],
                    'accuracy' => $classAccuracy,
                    'shots' => $classShots,
                    'hits' => $classHits,
                ];
            }
        }

        $stat['accuracy_classes'] = $classAccuracies;
        $favoriteClassId = $stat['favorite_class'];
        if ($favoriteClassId > 0 && isset(WT_CLASS_METADATA[$favoriteClassId])) {
            $meta = WT_CLASS_METADATA[$favoriteClassId];
            $classData = $classAccuracies[$favoriteClassId] ?? [
                'shots' => 0,
                'hits' => 0,
                'accuracy' => null,
                'slug' => $meta['slug'],
                'label' => $meta['label'],
                'icon' => $meta['icon'],
            ];
            $favAccuracy = $classData['accuracy'];
            if ($favAccuracy === null) {
                $favAccuracy = 0.0;
            }
            $stat['accuracy_top_class'] = [
                'class_id' => $favoriteClassId,
                'slug' => $meta['slug'],
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'accuracy' => $favAccuracy,
                'shots' => $classData['shots'],
                'hits' => $classData['hits'],
                'favorite' => true,
            ];
        } else {
            $stat['accuracy_top_class'] = $topClass;
        }

        $stat['top_weapon_accuracy'] = $weaponAccuracyMap[$steamId] ?? [];
    }

    unset($stat);

    return $stats;
}

function wt_filter_stats(array $stats, ?string $search): array
{
    if ($search === null || $search === '') {
        return $stats;
    }

    $search = strtolower($search);
    return array_values(array_filter($stats, function ($row) use ($search) {
        return stripos($row['steamid'], $search) !== false
            || stripos($row['personaname'], $search) !== false;
    }));
}

function wt_player_from_list(array $stats, ?string $steamId): ?array
{
    if ($steamId === null) {
        return null;
    }

    foreach ($stats as $row) {
        if ($row['steamid'] === $steamId) {
            return $row;
        }
    }

    return null;
}

function wt_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    return $scheme . '://' . $host . $path;
}

function wt_is_logged_in(): bool
{
    return !empty($_SESSION['steamid']);
}

function wt_current_user_id(): ?string
{
    return $_SESSION['steamid'] ?? null;
}

function wt_fetch_summary_stats(): array
{
    $pdo = wt_pdo();
    $sql = sprintf(
        'SELECT COUNT(*) AS total_players,
                COALESCE(SUM(kills), 0) AS total_kills,
                COALESCE(SUM(assists), 0) AS total_assists,
                COALESCE(SUM(playtime), 0) AS total_playtime,
                COALESCE(SUM(healing), 0) AS total_healing,
                COALESCE(SUM(headshots), 0) AS total_headshots,
                COALESCE(SUM(backstabs), 0) AS total_backstabs,
                COALESCE(SUM(damage_dealt), 0) AS total_damage,
                COALESCE(SUM(damage_taken), 0) AS total_damage_taken,
                COALESCE(SUM(medic_drops), 0) AS total_drops,
                COALESCE(SUM(total_ubers), 0) AS total_ubers_used
         FROM %s',
        WT_DB_TABLE
    );
    $totals = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalPlaytimeSeconds = (int)($totals['total_playtime'] ?? 0);
    $totalPlaytimeMinutes = $totalPlaytimeSeconds > 0 ? $totalPlaytimeSeconds / 60.0 : 0.0;
    $totalDamage = (int)($totals['total_damage'] ?? 0);
    $averageDpm = $totalPlaytimeMinutes > 0 ? $totalDamage / $totalPlaytimeMinutes : 0.0;

    $summary = [
        'totalPlayers' => (int)($totals['total_players'] ?? 0),
        'totalKills' => (int)($totals['total_kills'] ?? 0),
        'totalPlaytimeHours' => round($totalPlaytimeSeconds / 3600, 1),
        'totalHealing' => (int)($totals['total_healing'] ?? 0),
        'totalHeadshots' => (int)($totals['total_headshots'] ?? 0),
        'totalBackstabs' => (int)($totals['total_backstabs'] ?? 0),
        'totalDamage' => $totalDamage,
        'totalDamageTaken' => (int)($totals['total_damage_taken'] ?? 0),
        'totalDrops' => (int)($totals['total_drops'] ?? 0),
        'totalUbersUsed' => (int)($totals['total_ubers_used'] ?? 0),
        'averageDpm' => $averageDpm,
    ];

    $topSql = sprintf(
        'SELECT * FROM %s ORDER BY best_killstreak DESC, kills DESC LIMIT 1',
        WT_DB_TABLE
    );
    $topRow = $pdo->query($topSql)->fetch(PDO::FETCH_ASSOC) ?: null;

    $summary['topKillstreak'] = 0;
    $summary['topKillstreakOwner'] = null;

    if ($topRow) {
        $summary['topKillstreak'] = (int)($topRow['best_killstreak'] ?? 0);
        if ($summary['topKillstreak'] > 0) {
            $steamId = (string)($topRow['steamid'] ?? '');
            $profiles = wt_fetch_steam_profiles([$steamId]);
            $enriched = wt_stats_with_profiles([$topRow], $profiles);
            $summary['topKillstreakOwner'] = $enriched[0] ?? null;
        }
    }

    return $summary;
}

// This function fetches paginated player stats with two modes:
// With search: Loads ALL players, enriches with Steam profiles, 
// sorts/filters them in memory, then paginates the results (less efficient for large datasets).
// Without search: Uses efficient SQL pagination to fetch only the needed page from database,
//  then enriches those specific players with Steam profile data.
function wt_fetch_stats_paginated(?string $search, int $limit, int $offset): array
{
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $pdo = wt_pdo();
    $search = $search !== null ? trim($search) : '';

    if ($search !== '') {
        $allStats = wt_fetch_all_stats();
        if (empty($allStats)) {
            return ['rows' => [], 'total' => 0];
        }

        $profiles = wt_fetch_steam_profiles(array_column($allStats, 'steamid'));
        $allStats = wt_stats_with_profiles($allStats, $profiles);

        usort($allStats, static function ($a, $b) {
            $scoreA = $a['score_total'] ?? (($a['kills'] ?? 0) + ($a['assists'] ?? 0));
            $scoreB = $b['score_total'] ?? (($b['kills'] ?? 0) + ($b['assists'] ?? 0));
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return ($b['kills'] ?? 0) <=> ($a['kills'] ?? 0);
        });

        $filtered = wt_filter_stats($allStats, $search);
        $total = count($filtered);
        $rows = array_slice($filtered, $offset, $limit);

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    $countSql = sprintf('SELECT COUNT(*) FROM %s', WT_DB_TABLE);
    $total = (int)($pdo->query($countSql)->fetchColumn() ?: 0);

    if ($total === 0) {
        return ['rows' => [], 'total' => 0];
    }

    $sql = sprintf(
        'SELECT * FROM %s ORDER BY (kills + assists) DESC, kills DESC LIMIT :limit OFFSET :offset',
        WT_DB_TABLE
    );
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return ['rows' => [], 'total' => $total];
    }

    $profiles = wt_fetch_steam_profiles(array_column($rows, 'steamid'));
    $rows = wt_stats_with_profiles($rows, $profiles);

    return [
        'rows' => $rows,
        'total' => $total,
    ];
}

// This function fetches a player's database record by their Steam ID and enriches it with profile data from Steam's API. 
// It returns the combined player data as an array or null if not found.
function wt_fetch_player(string $steamId): ?array
{
    $steamId = trim($steamId);
    if ($steamId === '') {
        return null;
    }

    $pdo = wt_pdo();
    $sql = sprintf('SELECT * FROM %s WHERE steamid = :steamid LIMIT 1', WT_DB_TABLE);
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':steamid', $steamId, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $profiles = wt_fetch_steam_profiles([$steamId]);
    $enriched = wt_stats_with_profiles([$row], $profiles);

    return $enriched[0] ?? null;
}

function wt_summary_insights(): array
{
    $pdo = wt_pdo();

    $timezoneName = date_default_timezone_get() ?: 'UTC';
    $timezone = new DateTimeZone($timezoneName);
    $now = new DateTimeImmutable('now', $timezone);

    $monthStart = (int)$now->modify('first day of this month 00:00:00')->format('U');
    $nextMonthStart = (int)$now->modify('first day of next month 00:00:00')->format('U');
    $monthLabel = $now->format('F');

    $monthlyPlaytimeSql = sprintf(
        'SELECT COALESCE(SUM(duration), 0) AS total_duration FROM %s WHERE started_at >= :start AND started_at < :end',
        wt_logs_table()
    );
    $stmt = $pdo->prepare($monthlyPlaytimeSql);
    $stmt->execute([
        ':start' => $monthStart,
        ':end' => $nextMonthStart,
    ]);
    $monthlyPlaytimeSeconds = (int)($stmt->fetchColumn() ?: 0);
    $monthlyPlaytimeHours = round($monthlyPlaytimeSeconds / 3600, 1);

    $monthlyPlayersSql = sprintf(
        'SELECT COUNT(DISTINCT lp.steamid) AS player_count
         FROM %s lp
         INNER JOIN %s l ON l.log_id = lp.log_id
         WHERE l.started_at >= :start AND l.started_at < :end',
        wt_log_players_table(),
        wt_logs_table()
    );
    $stmt = $pdo->prepare($monthlyPlayersSql);
    $stmt->execute([
        ':start' => $monthStart,
        ':end' => $nextMonthStart,
    ]);
    $playersCurrentMonth = (int)($stmt->fetchColumn() ?: 0);

    $currentWeekStartTs = (int)$now->sub(new DateInterval('P7D'))->format('U');
    $previousWeekStartTs = (int)$now->sub(new DateInterval('P14D'))->format('U');
    $nowTs = (int)$now->format('U');

    $weeklyPlayerSql = sprintf(
        'SELECT COUNT(DISTINCT lp.steamid) AS player_count
         FROM %s lp
         INNER JOIN %s l ON l.log_id = lp.log_id
         WHERE l.started_at >= :start AND l.started_at < :end',
        wt_log_players_table(),
        wt_logs_table()
    );

    $stmt = $pdo->prepare($weeklyPlayerSql);
    $stmt->execute([
        ':start' => $currentWeekStartTs,
        ':end' => $nowTs,
    ]);
    $currentWeekPlayers = (int)($stmt->fetchColumn() ?: 0);

    $stmt->execute([
        ':start' => $previousWeekStartTs,
        ':end' => $currentWeekStartTs,
    ]);
    $previousWeekPlayers = (int)($stmt->fetchColumn() ?: 0);

    $weeklyChangePercent = null;
    $weeklyTrend = 'flat';

    if ($previousWeekPlayers > 0) {
        $weeklyChangePercent = (($currentWeekPlayers - $previousWeekPlayers) / $previousWeekPlayers) * 100;
        if ($weeklyChangePercent > 0.5) {
            $weeklyTrend = 'up';
        } elseif ($weeklyChangePercent < -0.5) {
            $weeklyTrend = 'down';
        }
    } elseif ($currentWeekPlayers > 0) {
        $weeklyTrend = 'up';
    }

    $weeklyKillstreakSql = sprintf(
        'SELECT lp.log_id,
                lp.steamid,
                lp.personaname,
                lp.best_streak,
                lp.kills,
                l.started_at
         FROM %s lp
         INNER JOIN %s l ON l.log_id = lp.log_id
         WHERE l.started_at >= :start
         ORDER BY lp.best_streak DESC, lp.kills DESC, l.started_at DESC
         LIMIT 1',
        wt_log_players_table(),
        wt_logs_table()
    );

    $stmt = $pdo->prepare($weeklyKillstreakSql);
    $stmt->execute([
        ':start' => $currentWeekStartTs,
    ]);
    $weeklyKillstreakRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $weeklyKillstreak = 0;
    $weeklyKillstreakOwner = null;
    if ($weeklyKillstreakRow && (int)($weeklyKillstreakRow['best_streak'] ?? 0) > 0) {
        $weeklyKillstreak = (int)$weeklyKillstreakRow['best_streak'];
        $ownerSteamId = (string)($weeklyKillstreakRow['steamid'] ?? '');
        $ownerProfile = [];
        if ($ownerSteamId !== '') {
            $profiles = wt_fetch_steam_profiles([$ownerSteamId]);
            $ownerProfile = $profiles[$ownerSteamId] ?? [];
        }

        $weeklyKillstreakOwner = [
            'steamid' => $ownerSteamId,
            'personaname' => $ownerProfile['personaname'] ?? ($weeklyKillstreakRow['personaname'] ?? $ownerSteamId),
            'avatar' => $ownerProfile['avatarfull']
                ?? ($ownerProfile['avatar'] ?? 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png'),
            'profileurl' => $ownerProfile['profileurl'] ?? null,
        ];
    }

    $weeklyTopDpm = 0.0;
    $weeklyTopDpmOwner = null;
    $weeklyDpmSql = sprintf(
        'SELECT lp.log_id,
                lp.steamid,
                lp.personaname,
                lp.damage,
                lp.playtime,
                l.started_at
         FROM %s lp
         INNER JOIN %s l ON l.log_id = lp.log_id
         WHERE l.started_at >= :start AND lp.playtime > 0
         ORDER BY (lp.damage * 60.0 / lp.playtime) DESC, lp.damage DESC, l.started_at DESC
         LIMIT 1',
        wt_log_players_table(),
        wt_logs_table()
    );

    $stmt = $pdo->prepare($weeklyDpmSql);
    $stmt->execute([
        ':start' => $currentWeekStartTs,
    ]);
    $weeklyDpmRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($weeklyDpmRow) {
        $damage = (int)($weeklyDpmRow['damage'] ?? 0);
        $playtime = (int)($weeklyDpmRow['playtime'] ?? 0);
        if ($playtime > 0) {
            $weeklyTopDpm = ($damage * 60.0) / $playtime;
            $dpmOwnerSteamId = (string)($weeklyDpmRow['steamid'] ?? '');
            $ownerProfile = [];
            if ($dpmOwnerSteamId !== '') {
                $profiles = wt_fetch_steam_profiles([$dpmOwnerSteamId]);
                $ownerProfile = $profiles[$dpmOwnerSteamId] ?? [];
            }

            $weeklyTopDpmOwner = [
                'steamid' => $dpmOwnerSteamId,
                'personaname' => $ownerProfile['personaname'] ?? ($weeklyDpmRow['personaname'] ?? $dpmOwnerSteamId),
                'avatar' => $ownerProfile['avatarfull']
                    ?? ($ownerProfile['avatar'] ?? 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png'),
                'profileurl' => $ownerProfile['profileurl'] ?? null,
            ];
        }
    }

    $gamemodeSql = sprintf(
        'SELECT gamemode, COUNT(*) AS mode_count
         FROM %s
         WHERE gamemode IS NOT NULL AND gamemode <> \'\'
         GROUP BY gamemode',
        wt_logs_table()
    );

    $gamemodeRows = $pdo->query($gamemodeSql)->fetchAll(PDO::FETCH_ASSOC);
    $totalLogs = 0;
    foreach ($gamemodeRows as $row) {
        $totalLogs += (int)($row['mode_count'] ?? 0);
    }

    usort($gamemodeRows, static function ($a, $b) {
        return ((int)($b['mode_count'] ?? 0)) <=> ((int)($a['mode_count'] ?? 0));
    });
    $gamemodeRows = array_slice($gamemodeRows, 0, 3);

    $gamemodeTop = [];
    foreach ($gamemodeRows as $row) {
        $count = (int)($row['mode_count'] ?? 0);
        $gamemode = (string)($row['gamemode'] ?? '');
        $percentage = $totalLogs > 0 ? ($count / $totalLogs) * 100 : 0;

        $gamemodeTop[] = [
            'label' => wt_format_gamemode_label($gamemode),
            'percentage' => $percentage,
            'count' => $count,
        ];
    }

    return [
        'playtimeMonthHours' => $monthlyPlaytimeHours,
        'playtimeMonthLabel' => $monthLabel,
        'playersCurrentWeek' => $currentWeekPlayers,
        'playersCurrentMonth' => $playersCurrentMonth,
        'playersPreviousWeek' => $previousWeekPlayers,
        'playersWeekChangePercent' => $weeklyChangePercent,
        'playersWeekTrend' => $weeklyTrend,
        'bestKillstreakWeek' => $weeklyKillstreak,
        'bestKillstreakWeekOwner' => $weeklyKillstreakOwner,
        'weeklyTopDpm' => $weeklyTopDpm,
        'weeklyTopDpmOwner' => $weeklyTopDpmOwner,
        'gamemodeTop' => $gamemodeTop,
    ];
}

function wt_format_gamemode_label(string $gamemode): string
{
    $normalized = strtolower(trim($gamemode));
    if ($normalized === '') {
        return 'Unknown';
    }

    $map = [
        'koth' => 'Koth',
        'king of the hill' => 'Koth',
        'payload' => 'Payload',
        'payload race' => 'Payload Race',
        'payload - race' => 'Payload Race',
        'cp' => 'CP',
        'control point' => 'Control Point',
        'attack/defend cp' => 'Attack/Defend',
        'attack/defend' => 'Attack/Defend',
        'arena' => 'Arena',
        'mge' => 'MGE',
        'ctf' => 'CTF',
        'mann vs machine' => 'MvM',
        'rd' => 'Robot Destruction',
        'passtime' => 'Pass Time',
    ];

    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    if (strlen($normalized) <= 3) {
        return strtoupper($normalized);
    }

    return ucwords($normalized);
}
