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

const WT_WEAPON_CATEGORY_METADATA = [
    'shotguns' => ['label' => 'Shotgun'],
    'scatterguns' => ['label' => 'Scattergun'],
    'pistols' => ['label' => 'Pistol'],
    'rocketlaunchers' => ['label' => 'Rocket Launcher'],
    'grenadelaunchers' => ['label' => 'Grenade Launcher'],
    'stickylaunchers' => ['label' => 'Sticky Launcher'],
    'snipers' => ['label' => 'Sniper Rifle'],
    'revolvers' => ['label' => 'Revolver'],
];

const WT_MAX_WEAPON_SLOTS = 3;
const WT_ADMINS_TABLE = 'admins';

function wt_class_meta_by_slug(?string $slug): ?array
{
    if ($slug === null || $slug === '') {
        return null;
    }
    foreach (WT_CLASS_METADATA as $meta) {
        if (($meta['slug'] ?? null) === $slug) {
            return $meta;
        }
    }
    return null;
}

function wt_class_icon_url(?string $slug): ?string
{
    $meta = wt_class_meta_by_slug($slug);
    if (!$meta) {
        return null;
    }
    $icon = $meta['icon'] ?? null;
    if (!$icon) {
        return null;
    }
    $base = defined('WT_CLASS_ICON_BASE') ? WT_CLASS_ICON_BASE : '/leaderboard/';
    return rtrim($base, '/') . '/' . ltrim($icon, '/');
}

function wt_weapon_category_metadata(): array
{
    return WT_WEAPON_CATEGORY_METADATA;
}

function wt_http_get(string $url, float $timeoutSeconds = 2.0): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = curl_exec($ch);
        if ($data === false) {
            error_log('[WhaleTracker] curl_get failed for ' . $url . ' — ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 400) {
            error_log('[WhaleTracker] HTTP ' . $code . ' fetching ' . $url);
            return null;
        }
        return $data;
    }

    $context = stream_context_create(['http' => ['timeout' => $timeoutSeconds]]);
    $data = @file_get_contents($url, false, $context);
    if ($data === false) {
        error_log('[WhaleTracker] file_get_contents failed for ' . $url);
        return null;
    }
    return $data;
}

function wt_total_weapon_accuracy_counts(array $row): array
{
    static $weaponAccuracyPairs = [
        ['shots_shotguns', 'hits_shotguns'],
        ['shots_scatterguns', 'hits_scatterguns'],
        ['shots_pistols', 'hits_pistols'],
        ['shots_rocketlaunchers', 'hits_rocketlaunchers'],
        ['shots_grenadelaunchers', 'hits_grenadelaunchers'],
        ['shots_stickylaunchers', 'hits_stickylaunchers'],
        ['shots_snipers', 'hits_snipers'],
        ['shots_revolvers', 'hits_revolvers'],
    ];

    $totalShots = 0;
    $totalHits = 0;
    foreach ($weaponAccuracyPairs as [$shotsKey, $hitsKey]) {
        $totalShots += (int)($row[$shotsKey] ?? 0);
        $totalHits += (int)($row[$hitsKey] ?? 0);
    }

    if ($totalShots === 0 && isset($row['shots'], $row['hits'])) {
        $totalShots = (int)$row['shots'];
        $totalHits = (int)$row['hits'];
    }

    return [$totalShots, $totalHits];
}

function wt_build_weapon_category_summary_from_row(array &$row, bool $fallbackOverall = true): array
{
    $summary = [];
    foreach (wt_weapon_category_metadata() as $slug => $meta) {
        $shotsKey = "shots_{$slug}";
        $hitsKey = "hits_{$slug}";
        $shots = (int)($row[$shotsKey] ?? 0);
        $hits = (int)($row[$hitsKey] ?? 0);
        if ($shots <= 0) {
            continue;
        }
        $accuracy = $shots > 0 ? ($hits / max($shots, 1)) * 100.0 : null;
        $summary[] = [
            'slug' => $slug,
            'label' => $meta['label'] ?? ucfirst($slug),
            'shots' => $shots,
            'hits' => $hits,
            'accuracy' => $accuracy,
        ];
    }

    usort($summary, static function (array $a, array $b): int {
        $shotsA = (int)($a['shots'] ?? 0);
        $shotsB = (int)($b['shots'] ?? 0);
        if ($shotsA === $shotsB) {
            $accA = isset($a['accuracy']) ? (float)$a['accuracy'] : 0.0;
            $accB = isset($b['accuracy']) ? (float)$b['accuracy'] : 0.0;
            return $accB <=> $accA;
        }
        return $shotsB <=> $shotsA;
    });

    if (empty($summary) && $fallbackOverall) {
        [$totalShots, $totalHits] = wt_total_weapon_accuracy_counts($row);
        if ($totalShots > 0) {
            $summary[] = [
                'slug' => 'overall',
                'label' => 'Overall',
                'shots' => $totalShots,
                'hits' => $totalHits,
                'accuracy' => ($totalHits / max($totalShots, 1)) * 100.0,
            ];
        }
    }

    return $summary;
}

function wt_assign_weapon_category_summary(array &$row, string $summaryKey = 'weapon_category_summary', ?string $primaryKey = null): void
{
    $summary = wt_build_weapon_category_summary_from_row($row);
    $row[$summaryKey] = $summary;
    if ($primaryKey !== null) {
        $row[$primaryKey] = $summary[0] ?? null;
    }
}

function wt_avatar_for_hash(?string $hash): string
{
    if (!$hash) {
        return WT_DEFAULT_AVATAR_URL;
    }

    $lastChar = strtolower(substr($hash, -1));
    if ($lastChar === '' || ctype_digit($lastChar)) {
        return WT_DEFAULT_AVATAR_URL;
    }

    return WT_SECONDARY_AVATAR_URL;
}

function wt_logs_small_threshold(): int
{
    static $threshold;
    if ($threshold !== null) {
        return $threshold;
    }
    $value = 12;
    if (defined('WT_LOGS_SMALL_THRESHOLD')) {
        $value = (int)WT_LOGS_SMALL_THRESHOLD;
    } else {
        $env = getenv('WT_LOGS_SMALL_THRESHOLD');
        if ($env !== false && $env !== '') {
            $value = (int)$env;
        }
    }
    $threshold = max(1, $value);
    return $threshold;
}

function wt_logs_normalize_scope(?string $scope): string
{
    $scope = strtolower(trim((string)$scope));
    if ($scope === 'short') {
        return 'short';
    }
    if ($scope === 'all') {
        return 'all';
    }
    return 'regular';
}

function wt_logs_scope_bounds(string $scope): array
{
    $scope = wt_logs_normalize_scope($scope);
    $threshold = wt_logs_small_threshold();
    switch ($scope) {
        case 'regular':
            return ['min' => $threshold, 'max' => null];
        case 'all':
        default:
            return ['min' => 1, 'max' => null];
    }
}

function wt_stats_min_playtime_sort(): int
{
    static $threshold = null;
    if ($threshold !== null) {
        return $threshold;
    }
    $threshold = defined('WT_STATS_MIN_PLAYTIME_SORT') ? (int)WT_STATS_MIN_PLAYTIME_SORT : (4 * 3600);
    if ($threshold < 0) {
        $threshold = 0;
    }
    return $threshold;
}

function wt_stats_order_clause(): string
{
    $threshold = wt_stats_min_playtime_sort();
    $ratioExpr = 'COALESCE((kills + (0.5 * assists)) / NULLIF(deaths, 0), (kills + (0.5 * assists)))';
    return sprintf(
        'CASE WHEN playtime >= %d THEN %s ELSE -1 END DESC, (kills + assists) DESC, kills DESC',
        $threshold,
        $ratioExpr
    );
}

function wt_update_cached_personaname(string $steamId, ?string $personaname): void
{
    $steamId = trim($steamId);
    if ($steamId === '') {
        return;
    }
    $pdo = wt_pdo();
    $lower = null;
    if ($personaname !== null && $personaname !== '') {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($personaname, 'UTF-8') : strtolower($personaname);
    }
    $sql = sprintf(
        'UPDATE %s SET cached_personaname = :persona, cached_personaname_lower = :lower WHERE steamid = :steamid',
        WT_DB_TABLE
    );
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':persona', $personaname, $personaname === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':lower', $lower, $lower === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':steamid', $steamId, PDO::PARAM_STR);
    try {
        $stmt->execute();
    } catch (Throwable $e) {
        // Ignore write failures; caching is best-effort.
    }
}

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

function wt_cache_delete(string $key): void
{
    $redis = wt_cache_client();
    if (!$redis) {
        return;
    }

    try {
        $redis->del($key);
    } catch (Throwable $e) {
        // Ignore failures.
    }
}

function wt_clear_online_cache_flag(PDO $pdo, string $cacheKey): void
{
    try {
        $stmt = $pdo->prepare('SELECT updated_at FROM whaletracker_cache_flags WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => 'online-clear']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['updated_at'] > 0) {
            wt_cache_delete($cacheKey);
            $delete = $pdo->prepare('DELETE FROM whaletracker_cache_flags WHERE name = :name');
            $delete->execute([':name' => 'online-clear']);
        }
    } catch (Throwable $e) {
        // ignore
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

    $classColumns = [
        'classes_mask INT DEFAULT 0',
        'shots_scout INT DEFAULT 0',
        'hits_scout INT DEFAULT 0',
        'shots_sniper INT DEFAULT 0',
        'hits_sniper INT DEFAULT 0',
        'shots_soldier INT DEFAULT 0',
        'hits_soldier INT DEFAULT 0',
        'shots_demoman INT DEFAULT 0',
        'hits_demoman INT DEFAULT 0',
        'shots_medic INT DEFAULT 0',
        'hits_medic INT DEFAULT 0',
        'shots_heavy INT DEFAULT 0',
        'hits_heavy INT DEFAULT 0',
        'shots_pyro INT DEFAULT 0',
        'hits_pyro INT DEFAULT 0',
        'shots_spy INT DEFAULT 0',
        'hits_spy INT DEFAULT 0',
        'shots_engineer INT DEFAULT 0',
        'hits_engineer INT DEFAULT 0',
    ];

    foreach ($classColumns as $column) {
        $queries[] = sprintf('ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS %s', $column);
        $queries[] = sprintf('ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS %s', $column);
        $queries[] = sprintf('ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS %s', $column);
    }

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
        . 'lp.shots_shotguns, lp.hits_shotguns, lp.shots_scatterguns, lp.hits_scatterguns, lp.shots_pistols, lp.hits_pistols, lp.shots_rocketlaunchers, lp.hits_rocketlaunchers, lp.shots_grenadelaunchers, lp.hits_grenadelaunchers, lp.shots_stickylaunchers, lp.hits_stickylaunchers, lp.shots_snipers, lp.hits_snipers, lp.shots_revolvers, lp.hits_revolvers, '
        . 'COALESCE(lp.classes_mask, 0) AS classes_mask, lp.is_admin, lp.last_updated '
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
        . 'lp.shots_shotguns, lp.hits_shotguns, lp.shots_scatterguns, lp.hits_scatterguns, lp.shots_pistols, lp.hits_pistols, lp.shots_rocketlaunchers, lp.hits_rocketlaunchers, lp.shots_grenadelaunchers, lp.hits_grenadelaunchers, lp.shots_stickylaunchers, lp.hits_stickylaunchers, lp.shots_snipers, lp.hits_snipers, lp.shots_revolvers, lp.hits_revolvers, '
        . 'COALESCE(lp.classes_mask, 0) AS classes_mask, lp.is_admin, lp.last_updated '
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
    $defaultAvatar = WT_DEFAULT_AVATAR_URL;

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

            wt_assign_weapon_category_summary($entry, 'weapon_category_summary', 'primary_weapon_accuracy');

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

            $entry['best_weapon'] = $matchBestWeapon ?: null;
            $entry['best_weapon_accuracy'] = $matchBestAccuracy;

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

    $players = $pdo->query('SELECT steamid, personaname, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits, playtime, total_ubers, best_streak, classes_mask, shots_scout, hits_scout, shots_sniper, hits_sniper, shots_soldier, hits_soldier, shots_demoman, hits_demoman, shots_medic, hits_medic, shots_heavy, hits_heavy, shots_pyro, hits_pyro, shots_spy, hits_spy, shots_engineer, hits_engineer FROM whaletracker_online')->fetchAll();

    $playerCount = 0;
    if (!empty($players)) {
        $steamIds = array_values(array_unique(array_filter(array_map(static function ($row) {
            return (string)($row['steamid'] ?? '');
        }, $players), 'strlen')));

        $adminFlags = !empty($steamIds) ? wt_fetch_admin_flags($steamIds) : [];
        $classSlugs = array_values(array_map(static function ($entry) {
            return $entry['slug'];
        }, WT_CLASS_METADATA));
        $classColumns = [];
        foreach ($classSlugs as $slug) {
            $classColumns[] = "shots_{$slug}";
            $classColumns[] = "hits_{$slug}";
        }
        $classColumnsSql = implode(', ', $classColumns);
        $classPlaceholdersSql = implode(', ', array_map(static function ($column) {
            return ':' . $column;
        }, $classColumns));
        $classUpdateSql = implode(', ', array_map(static function ($column) {
            return "{$column} = VALUES({$column})";
        }, $classColumns));

        $insertSql = sprintf(
            'INSERT INTO whaletracker_log_players (
                log_id, steamid, personaname, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs,
                total_ubers, playtime, medic_drops, uber_drops, airshots, shots, hits, best_streak, best_headshots_life,
                best_backstabs_life, best_score_life, best_kills_life, best_assists_life, best_ubers_life, classes_mask, %s,
                is_admin, last_updated
            ) VALUES (
                :log_id, :steamid, :personaname, :kills, :deaths, :assists, :damage, :damage_taken, :healing, :headshots,
                :backstabs, :total_ubers, :playtime, :medic_drops, :uber_drops, :airshots, :shots, :hits, :best_streak,
                :best_headshots_life, :best_backstabs_life, :best_score_life, :best_kills_life, :best_assists_life,
                :best_ubers_life, :classes_mask, %s, :is_admin, :last_updated
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
                %s,
                is_admin = VALUES(is_admin),
                last_updated = VALUES(last_updated)',
            $classColumnsSql,
            $classPlaceholdersSql,
            $classUpdateSql
        );

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
            $classParams = [];
            foreach ($classSlugs as $slug) {
                $classParams[":shots_{$slug}"] = (int)($player["shots_{$slug}"] ?? 0);
                $classParams[":hits_{$slug}"] = (int)($player["hits_{$slug}"] ?? 0);
            }
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
            ] + $classParams);
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

function wt_logs_manual_refresh_requested(): bool
{
    static $requested = null;
    if ($requested !== null) {
        return $requested;
    }
    $keys = ['refresh_logs', 'refresh_current_log', 'refresh'];
    foreach ($keys as $key) {
        if (!empty($_GET[$key])) {
            $requested = true;
            return true;
        }
    }
    $requested = false;
    return false;
}

function wt_logs_auto_refresh_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }
    $enabled = defined('WT_LOGS_AUTO_REFRESH') ? (bool)WT_LOGS_AUTO_REFRESH : false;
    return $enabled;
}

function wt_logs_refresh_interval(): int
{
    static $interval = null;
    if ($interval !== null) {
        return $interval;
    }
    $interval = defined('WT_LOGS_REFRESH_INTERVAL') ? (int)WT_LOGS_REFRESH_INTERVAL : 120;
    if ($interval < 0) {
        $interval = 0;
    }
    return $interval;
}

function wt_logs_refresh_marker_file(): string
{
    return wt_cache_dir() . '/logs_refresh.marker';
}

function wt_get_logs_refresh_marker(): int
{
    $cacheKey = 'wt:logs:last_refresh';
    $cached = wt_cache_get($cacheKey);
    if (is_numeric($cached)) {
        return (int)$cached;
    }
    $path = wt_logs_refresh_marker_file();
    if (is_file($path)) {
        $value = (int)trim((string)file_get_contents($path));
        if ($value > 0) {
            return $value;
        }
    }
    return 0;
}

function wt_set_logs_refresh_marker(int $timestamp, ?int $ttl = null): void
{
    $ttl = $ttl ?? wt_logs_refresh_interval();
    if ($ttl <= 0) {
        $ttl = 1;
    }
    $cacheKey = 'wt:logs:last_refresh';
    wt_cache_set($cacheKey, $timestamp, $ttl);
    $path = wt_logs_refresh_marker_file();
    @file_put_contents($path, (string)$timestamp);
}

function wt_maybe_refresh_current_log(): void
{
    if (wt_logs_manual_refresh_requested()) {
        wt_refresh_current_log();
        wt_set_logs_refresh_marker(time());
        return;
    }
    if (!wt_logs_auto_refresh_enabled()) {
        return;
    }
    $interval = wt_logs_refresh_interval();
    if ($interval === 0) {
        wt_refresh_current_log();
        wt_set_logs_refresh_marker(time(), 1);
        return;
    }
    $last = wt_get_logs_refresh_marker();
    $now = time();
    if ($last > 0 && ($now - $last) < $interval) {
        return;
    }
    wt_refresh_current_log();
    wt_set_logs_refresh_marker($now, $interval);
}

function wt_fetch_logs(int $limit = 15, string $scope = 'regular', int $page = 1): array
{
    $limit = max(1, min($limit, 1000));
    $page = max(1, $page);
    $offset = ($page - 1) * $limit;
    
    $scope = wt_logs_normalize_scope($scope);
    $bounds = wt_logs_scope_bounds($scope);
    $minPlayers = $bounds['min'] ?? null;
    $maxPlayers = $bounds['max'] ?? null;

    wt_maybe_refresh_current_log();

    // Fetch slightly more to handle potential filtering (though we filter by player_count in SQL now mostly)
    // But since we are paginating, we should trust the SQL limit/offset more.
    // However, the original code filtered by actual player array count which might differ from player_count column.
    // We will trust player_count column for pagination to work correctly in SQL.
    
    $whereParts = ['player_count > 0'];
    if ($minPlayers !== null && $minPlayers > 1) {
        $whereParts[] = 'player_count >= ' . (int)$minPlayers;
    }
    if ($maxPlayers !== null && $maxPlayers >= 1) {
        $whereParts[] = 'player_count <= ' . (int)$maxPlayers;
    }
    $whereClause = implode(' AND ', $whereParts);

    $sql = sprintf(
        'SELECT log_id, map, gamemode, started_at, ended_at, duration, player_count, created_at, updated_at FROM %s WHERE %s ORDER BY started_at DESC LIMIT %d OFFSET %d',
        wt_logs_table(),
        $whereClause,
        $limit,
        $offset
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

    // We are not filtering by array count anymore to preserve pagination consistency.
    // The database cleanup script ensures bad logs are gone.
    
    return array_values($logs);
}

function wt_get_cached_logs(int $limit = 0, string $scope = 'regular', int $page = 1): array
{
    $perPage = wt_logs_per_page();
    $limit = (int)$limit;
    if ($limit <= 0) {
        $limit = $perPage;
    }
    $page = max(1, (int)$page);
    $scope = wt_logs_normalize_scope($scope);
    $maxPages = wt_logs_max_pages();

    $metaFile = __DIR__ . '/cache/logs_meta.json';
    $cacheMeta = [];
    if (file_exists($metaFile)) {
        $cacheMeta = json_decode(file_get_contents($metaFile), true) ?? [];
    }

    $pdo = wt_pdo();
    $stmt = $pdo->query("SELECT MAX(updated_at) as last_update, COUNT(*) as total_logs FROM " . wt_logs_table());
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
    $lastDbUpdate = (int)($row['last_update'] ?? 0);
    $totalLogsActual = (int)($row['total_logs'] ?? 0);
    $effectiveTotal = $totalLogsActual;
    if ($maxPages > 0) {
        $maxDisplay = $maxPages * $limit;
        if ($effectiveTotal > $maxDisplay) {
            $effectiveTotal = $maxDisplay;
        }
    }
    $totalPages = max(1, (int)ceil(max($effectiveTotal, 1) / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $cacheFile = __DIR__ . '/cache/logs_page_' . $page . '_' . $scope . '.html';

    $cachedUpdate = (int)($cacheMeta['last_update'] ?? 0);
    $cachedTotal = (int)($cacheMeta['total_logs'] ?? 0);
    $cachedPages = (int)($cacheMeta['total_pages'] ?? 0);

    if ($lastDbUpdate === $cachedUpdate && $effectiveTotal === $cachedTotal && $totalPages === $cachedPages && file_exists($cacheFile)) {
        return [
            'html' => file_get_contents($cacheFile),
            'from_cache' => true,
            'last_update' => $lastDbUpdate,
            'total_logs' => $effectiveTotal,
            'total_pages' => $totalPages,
            'page' => $page,
        ];
    }

    $logs = wt_fetch_logs($limit, $scope, $page);

    return [
        'logs' => $logs,
        'from_cache' => false,
        'last_update' => $lastDbUpdate,
        'total_logs' => $effectiveTotal,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

function wt_admin_cache_file(): string
{
    return __DIR__ . '/cache/admins_cache.json';
}

function wt_admin_cache_ttl(): int
{
    return 86400;
}

function wt_get_admin_cache(bool $forceRefresh = false): array
{
    static $cachedAdmins = null;
    if ($cachedAdmins !== null && !$forceRefresh) {
        return $cachedAdmins;
    }

    $cacheFile = wt_admin_cache_file();
    $now = time();
    $ttl = wt_admin_cache_ttl();
    $needsRefresh = $forceRefresh;

    if (!$needsRefresh && is_file($cacheFile)) {
        $mtime = (int)@filemtime($cacheFile);
        if ($mtime > 0 && ($now - $mtime) <= $ttl) {
            $content = @file_get_contents($cacheFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && isset($data['admins']) && is_array($data['admins'])) {
                    $normalized = [];
                    foreach ($data['admins'] as $steamId => $flag) {
                        $steamId = (string)$steamId;
                        if ($steamId === '') {
                            continue;
                        }
                        $normalized[$steamId] = !empty($flag);
                    }
                    $cachedAdmins = $normalized;
                    return $cachedAdmins;
                }
            }
        } else {
            $needsRefresh = true;
        }
    } else {
        $needsRefresh = true;
    }

    $cachedAdmins = wt_refresh_admin_cache();
    return $cachedAdmins;
}

function wt_refresh_admin_cache(): array
{
    $pdo = wt_pdo();
    $sql = 'SELECT steamid64, admin_status FROM ' . WT_ADMINS_TABLE;
    $stmt = $pdo->query($sql);
    $admins = [];
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $steam64 = trim((string)($row['steamid64'] ?? ''));
            if ($steam64 === '') {
                continue;
            }
            $status = strtolower(trim((string)($row['admin_status'] ?? '')));
            $admins[$steam64] = in_array($status, ['1', 'yes', 'true', 'on'], true);
        }
    } else {
        error_log('[WT] Failed to query admins table for cache refresh.');
    }

    $payload = [
        'generated_at' => time(),
        'admins' => $admins,
    ];

    $cacheFile = wt_admin_cache_file();
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT);
    if ($json !== false) {
        $tmpFile = $cacheFile . '.tmp';
        file_put_contents($tmpFile, $json);
        @rename($tmpFile, $cacheFile);
    }

    return $admins;
}

function wt_fetch_admin_flags(array $steamIds): array
{
    $steamIds = array_values(array_unique(array_filter($steamIds)));
    if (empty($steamIds)) {
        return [];
    }

    $adminMap = wt_get_admin_cache(isset($_GET['refresh_admin_cache']));
    $flags = [];
    foreach ($steamIds as $steamId) {
        $steamId = (string)$steamId;
        if ($steamId === '') {
            continue;
        }
        if (isset($adminMap[$steamId])) {
            $flags[$steamId] = !empty($adminMap[$steamId]);
        }
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

function wt_fetch_map_popularity(int $limit = 50): array
{
    $limit = max(1, min($limit, 500));
    $pdo = wt_pdo();
    $sql = 'SELECT map_name, category, sub_category, popularity FROM mapsdb ORDER BY popularity DESC, map_name ASC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['map_name'] = (string)($row['map_name'] ?? '');
        $row['category'] = (string)($row['category'] ?? '');
        $row['sub_category'] = (string)($row['sub_category'] ?? '');
        $row['popularity'] = (int)($row['popularity'] ?? 0);
    }
    unset($row);
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

function wt_fetch_profiles_from_api(array $steamIds): array
{
    if (empty($steamIds) || WT_STEAM_API_KEY === '') {
        return [];
    }

    $results = [];
    $chunks = array_chunk($steamIds, 100);
    foreach ($chunks as $chunk) {
        $url = sprintf(
            'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=%s&steamids=%s',
            urlencode(WT_STEAM_API_KEY),
            implode(',', $chunk)
        );

        $response = wt_http_get($url, 4.0);
        if ($response === null) {
            error_log('[WhaleTracker] Steam API profile fetch failed for chunk starting ' . ($chunk[0] ?? 'unknown'));
            continue;
        }

        $data = json_decode($response, true);
        if (!isset($data['response']['players'])) {
            error_log('[WhaleTracker] Steam API profile response missing players for chunk starting ' . ($chunk[0] ?? 'unknown'));
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
                'avatar_source' => $player['avatarfull'] ?? ($player['avatar'] ?? null),
                'timecreated' => $player['timecreated'] ?? null,
            ];

            wt_refresh_profile_avatar($normalized, $profile);
            wt_write_cached_profile($normalized, $profile);
            wt_update_cached_personaname($normalized, $profile['personaname'] ?? $normalized);

            $results[$normalized] = $profile;
        }
    }

    return $results;
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
        $normalizedMap[$normalized][] = $steamId;
    }

    if (empty($normalizedMap)) {
        return [];
    }

    $profiles = [];
    $pending = [];

    $assignCachedProfile = static function (string $normalized, array $originals, array $cached) use (&$profiles): void {
        $avatarUrl = (string)($cached['avatarfull'] ?? '');
        if ($avatarUrl !== '' && strncmp($avatarUrl, '/stats/cache/', 13) === 0) {
            $basename = basename($avatarUrl);
            if (!$basename || !wt_avatar_cache_is_fresh($basename)) {
                $source = $cached['avatar_source'] ?? null;
                if ($source) {
                    $downloaded = wt_avatar_cache_download($normalized, $source, $cached['avatar_cached'] ?? null);
                    if ($downloaded) {
                        $cached['avatar_cached'] = $downloaded;
                        $cached['avatarfull'] = wt_avatar_cache_url_from_basename($downloaded);
                        wt_write_cached_profile($normalized, $cached);
                    }
                }
            }
        }
        foreach ($originals as $original) {
            $profiles[$original] = $cached;
        }
    };

    foreach ($normalizedMap as $normalized => $originals) {
        $cached = wt_read_cached_profile($normalized);
        if ($cached !== null) {
            $assignCachedProfile($normalized, $originals, $cached);
            continue;
        }
        $pending[$normalized] = $originals;
    }

    if (!empty($pending) && WT_STEAM_API_KEY !== '') {
        $apiProfiles = wt_fetch_profiles_from_api(array_keys($pending));
        foreach ($apiProfiles as $normalized => $profile) {
            if (empty($pending[$normalized])) {
                continue;
            }
            foreach ($pending[$normalized] as $original) {
                $profiles[$original] = $profile;
            }
            unset($pending[$normalized]);
        }
    }

    foreach ($pending as $normalized => $originals) {
        $cached = wt_read_cached_profile($normalized);
        if ($cached !== null) {
            $assignCachedProfile($normalized, $originals, $cached);
            continue;
        }

        $fallback = [
            'steamid' => $normalized,
            'personaname' => $normalized,
            'profileurl' => null,
            'avatarfull' => WT_DEFAULT_AVATAR_URL,
            'avatar_source' => null,
        ];
        wt_update_cached_personaname($normalized, $fallback['personaname']);
        foreach ($originals as $original) {
            $profiles[$original] = $fallback;
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

function wt_static_cache_dir(): string
{
    $dir = __DIR__ . '/cache/static';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function wt_cache_safe_identifier(string $steamId): string
{
    $safe = preg_replace('/\D+/', '', $steamId);
    if ($safe === '') {
        $safe = substr(md5($steamId), 0, 16);
    }
    return $safe;
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

function wt_avatar_cache_basename(string $steamId, string $extension): string
{
    return sprintf('%s-avatar.%s', wt_cache_safe_identifier($steamId), strtolower($extension ?: 'jpg'));
}

function wt_avatar_cache_disk_path(string $basename): string
{
    return wt_cache_dir() . '/' . basename($basename);
}

function wt_avatar_cache_url_from_basename(string $basename): string
{
    return '/stats/cache/' . basename($basename);
}

function wt_avatar_cache_is_fresh(?string $basename): bool
{
    if (!$basename) {
        return false;
    }

    $path = wt_avatar_cache_disk_path($basename);
    if (!is_file($path)) {
        return false;
    }

    return (time() - filemtime($path)) < WT_AVATAR_CACHE_TTL;
}

function wt_avatar_cache_download(string $steamId, string $remoteUrl, ?string $existingBasename = null, bool $forceRefresh = false): ?string
{
    $pathInfo = parse_url($remoteUrl, PHP_URL_PATH);
    $extension = strtolower(pathinfo($pathInfo ?? '', PATHINFO_EXTENSION) ?: 'jpg');
    $basename = wt_avatar_cache_basename($steamId, $extension);
    $target = wt_avatar_cache_disk_path($basename);

    if (!$forceRefresh && is_file($target) && (time() - filemtime($target)) < WT_AVATAR_CACHE_TTL) {
        return $basename;
    }

    $data = wt_http_get($remoteUrl, 6.0);
    if ($data === null) {
        error_log('[WhaleTracker] Failed to download avatar for ' . $steamId . ' from ' . $remoteUrl);
        if ($forceRefresh) {
            return null;
        }
        if ($existingBasename && wt_avatar_cache_is_fresh($existingBasename)) {
            return $existingBasename;
        }
        if (is_file($target)) {
            return $basename;
        }
        return $existingBasename;
    }

    $safe = wt_cache_safe_identifier($steamId);
    foreach (glob(wt_cache_dir() . '/' . $safe . '-avatar.*') ?: [] as $existing) {
        if ($existing !== $target) {
            @unlink($existing);
        }
    }

    @file_put_contents($target, $data);
    return $basename;
}

function wt_refresh_profile_avatar(string $steamId, array &$profile, bool $forceRefresh = false): bool
{
    $updated = false;

    $currentAvatar = $profile['avatarfull'] ?? null;
    $remoteSource = $profile['avatar_source'] ?? null;
    if ($remoteSource === null && $currentAvatar && strncmp($currentAvatar, '/stats/cache/', 13) !== 0) {
        $remoteSource = $currentAvatar;
        $profile['avatar_source'] = $remoteSource;
        $updated = true;
    }

    $cachedBasename = $profile['avatar_cached'] ?? null;
    if ($cachedBasename && !wt_avatar_cache_is_fresh($cachedBasename)) {
        $cachedBasename = null;
    }

    if ($cachedBasename) {
        $cachedUrl = wt_avatar_cache_url_from_basename($cachedBasename);
        if ($currentAvatar !== $cachedUrl) {
            $profile['avatarfull'] = $cachedUrl;
            $updated = true;
        }
        return $updated;
    }

    if (!$remoteSource) {
        return $updated;
    }

    $downloadedBasename = wt_avatar_cache_download($steamId, $remoteSource, $profile['avatar_cached'] ?? null, $forceRefresh);
    if ($downloadedBasename) {
        if (($profile['avatar_cached'] ?? null) !== $downloadedBasename) {
            $profile['avatar_cached'] = $downloadedBasename;
            $updated = true;
        }
        $cachedUrl = wt_avatar_cache_url_from_basename($downloadedBasename);
        if (($profile['avatarfull'] ?? null) !== $cachedUrl) {
            $profile['avatarfull'] = $cachedUrl;
            $updated = true;
        }
    }

    return $updated;
}

function wt_refresh_profile_avatar_now(string $steamId): ?array
{
    if (WT_STEAM_API_KEY === '') {
        return null;
    }

    $normalized = wt_normalize_steam_id($steamId);
    if ($normalized === null) {
        return null;
    }

    return wt_force_avatar_refresh($normalized);
}

function wt_force_avatar_refresh(string $steamId): ?array
{
    $profiles = wt_fetch_profiles_from_api([$steamId]);
    $profile = $profiles[$steamId] ?? null;
    if ($profile === null) {
        error_log('[WhaleTracker] Steam API did not return profile for ' . $steamId);
        return null;
    }

    // Remove any existing cached avatar files for this steamId to ensure overwrite.
    $safe = wt_cache_safe_identifier($steamId);
    foreach (glob(wt_cache_dir() . '/' . $safe . '-avatar.*') ?: [] as $existing) {
        @unlink($existing);
    }

    $remoteSource = $profile['avatar_source'] ?? ($profile['avatarfull'] ?? ($profile['avatar'] ?? null));
    if (!$remoteSource) {
        error_log('[WhaleTracker] No avatar URL found for ' . $steamId);
        return null;
    }

    $downloadedBasename = wt_avatar_cache_download($steamId, $remoteSource, $profile['avatar_cached'] ?? null, true);
    if (!$downloadedBasename) {
        error_log('[WhaleTracker] Avatar download failed for ' . $steamId . ' (source: ' . $remoteSource . ')');
        return null;
    }

    $profile['avatar_cached'] = $downloadedBasename;
    $profile['avatarfull'] = wt_avatar_cache_url_from_basename($downloadedBasename);
    $profile['avatar_source'] = $remoteSource;

    wt_write_cached_profile($steamId, $profile);
    wt_update_cached_personaname($steamId, $profile['personaname'] ?? $steamId);

    return $profile;
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
        $stat['avatar'] = $profile['avatarfull'] ?? WT_DEFAULT_AVATAR_URL;
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
        [$totalShots, $totalHits] = wt_total_weapon_accuracy_counts($stat);
        $overallAccuracy = $totalShots > 0 ? ($totalHits / $totalShots) * 100.0 : 0.0;
        $stat['accuracy_overall'] = $overallAccuracy;
        $stat['accuracy'] = $overallAccuracy;
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

        $topWeapons = $weaponAccuracyMap[$steamId] ?? [];
        $stat['top_weapon_accuracy'] = $topWeapons;
        $stat['best_weapon_accuracy_pct'] = isset($topWeapons[0]['accuracy']) ? (float)$topWeapons[0]['accuracy'] : 0.0;
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

// (RCON removed) web chat uses DB outbox handled by the game server plugin

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
    $cacheKey = 'wt:summary:totals';
    $lockKey = 'wt:lock:summary';
    $cached = wt_cache_get($cacheKey);
    
    // Soft Expiry Logic
    if (is_array($cached)) {
        $expiresAt = $cached['_expires_at'] ?? 0;
        if (time() < $expiresAt) {
            return $cached;
        }
        
        // Data is stale, try to acquire lock to update
        $redis = wt_cache_client();
        if (!$redis || !$redis->set($lockKey, '1', ['nx', 'ex' => 10])) {
            // Could not acquire lock (someone else is updating), return stale data
            return $cached;
        }
    }

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
    
    // Set soft expiry to 5 minutes from now
    $summary['_expires_at'] = time() + 300;
    
    // Store in Redis with 6 minutes TTL (1 minute grace period)
    wt_cache_set($cacheKey, $summary, 360);

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
    $orderClause = wt_stats_order_clause();

    if ($search !== '') {
        $searchLower = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
        $likeTerm = '%' . $searchLower . '%';
        $steamLike = '%' . $search . '%';
        $countSql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE cached_personaname_lower LIKE :term OR steamid LIKE :steam OR steamid = :exact',
            WT_DB_TABLE
        );
        $countStmt = $pdo->prepare($countSql);
        $countStmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
        $countStmt->bindValue(':steam', $steamLike, PDO::PARAM_STR);
        $countStmt->bindValue(':exact', $search, PDO::PARAM_STR);
        $countStmt->execute();
        $total = (int)($countStmt->fetchColumn() ?: 0);
        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $sql = sprintf(
            'SELECT * FROM %s WHERE cached_personaname_lower LIKE :term OR steamid LIKE :steam OR steamid = :exact ORDER BY %s LIMIT :limit OFFSET :offset',
            WT_DB_TABLE,
            $orderClause
        );
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':term', $likeTerm, PDO::PARAM_STR);
        $stmt->bindValue(':steam', $steamLike, PDO::PARAM_STR);
        $stmt->bindValue(':exact', $search, PDO::PARAM_STR);
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

    $countSql = sprintf('SELECT COUNT(*) FROM %s', WT_DB_TABLE);
    $total = (int)($pdo->query($countSql)->fetchColumn() ?: 0);

    if ($total === 0) {
        return ['rows' => [], 'total' => 0];
    }

    $sql = sprintf(
        'SELECT * FROM %s ORDER BY %s LIMIT :limit OFFSET :offset',
        WT_DB_TABLE,
        $orderClause
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

function wt_download_cumulative_page_avatars(
    int $page = 1,
    int $perPage = 50,
    int $delaySeconds = 15,
    ?callable $logger = null,
    ?array $steamIds = null
): array {
    if (WT_STEAM_API_KEY === '') {
        throw new RuntimeException('WT_STEAM_API_KEY is not configured.');
    }

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $delaySeconds = max(0, $delaySeconds);

    $steamIdMap = [];
    $invalidSteamIds = [];

    if ($steamIds !== null && !empty($steamIds)) {
        $validIds = [];
        foreach ($steamIds as $rawSteamId) {
            $normalized = wt_normalize_steam_id((string)$rawSteamId);
            if ($normalized === null) {
                $invalidSteamIds[] = (string)$rawSteamId;
                continue;
            }
            $validIds[$normalized] = null;
        }

        if (empty($validIds)) {
            return [];
        }

        $pdo = wt_pdo();
        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $sql = sprintf(
            'SELECT steamid, COALESCE(cached_personaname, steamid) AS personaname FROM %s WHERE steamid IN (%s)',
            WT_DB_TABLE,
            $placeholders
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_keys($validIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $steamId = (string)($row['steamid'] ?? '');
            if ($steamId === '') {
                continue;
            }
            $steamIdMap[$steamId] = (string)($row['personaname'] ?? $steamId);
            unset($validIds[$steamId]);
        }
        foreach ($validIds as $steamId => $_) {
            $steamIdMap[$steamId] = $steamId;
        }
    } else {
        $offset = ($page - 1) * $perPage;
        $pdo = wt_pdo();
        $sql = sprintf(
            'SELECT steamid, cached_personaname AS personaname FROM %s ORDER BY %s LIMIT :limit OFFSET :offset',
            WT_DB_TABLE,
            wt_stats_order_clause()
        );
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $steamId = (string)($row['steamid'] ?? '');
            if ($steamId === '') {
                continue;
            }
            $normalized = wt_normalize_steam_id($steamId);
            if ($normalized === null) {
                continue;
            }
            $label = trim((string)($row['personaname'] ?? ''));
            if ($label === '') {
                $label = $steamId;
            }
            $steamIdMap[$normalized] = $label;
        }
    }

    $processed = [];
    $validSteamIds = array_keys($steamIdMap);
    if (empty($validSteamIds) && empty($invalidSteamIds)) {
        return [];
    }

    $validTotal = count($validSteamIds);
    $total = $validTotal + count($invalidSteamIds);
    $currentIndex = 0;

    foreach ($validSteamIds as $i => $steamId) {
        $profiles = wt_fetch_profiles_from_api([$steamId]);
        $profile = $profiles[$steamId] ?? null;
        $entry = [
            'steamid' => $steamId,
            'personaname' => $steamIdMap[$steamId],
            'success' => $profile !== null,
            'avatar_cached' => $profile['avatar_cached'] ?? null,
        ];
        $processed[] = $entry;
        if ($logger) {
            $logger($entry, $currentIndex, $total);
        }
        $currentIndex++;
        if ($delaySeconds > 0 && $i < $validTotal - 1) {
            sleep($delaySeconds);
        }
    }

    foreach ($invalidSteamIds as $rawSteamId) {
        $entry = [
            'steamid' => $rawSteamId,
            'personaname' => $rawSteamId,
            'success' => false,
            'error' => 'invalid_steamid',
        ];
        $processed[] = $entry;
        if ($logger) {
            $logger($entry, $currentIndex, $total);
        }
        $currentIndex++;
    }

    return $processed;
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
         FROM %1$s lp
         INNER JOIN %2$s l ON l.log_id = lp.log_id
         WHERE l.started_at >= :start
           AND lp.log_id = (
               SELECT lp2.log_id
               FROM %1$s lp2
               INNER JOIN %2$s l2 ON l2.log_id = lp2.log_id
               WHERE lp2.steamid = lp.steamid
                 AND l2.started_at >= :start
               ORDER BY lp2.best_streak DESC, lp2.kills DESC, l2.started_at DESC
               LIMIT 1
           )
         ORDER BY lp.best_streak DESC, lp.kills DESC, l.started_at DESC
         LIMIT 3',
        wt_log_players_table(),
        wt_logs_table()
    );

    $stmt = $pdo->prepare($weeklyKillstreakSql);
    $stmt->execute([
        ':start' => $currentWeekStartTs,
    ]);
    $weeklyKillstreakRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $weeklyKillstreak = 0;
    $weeklyKillstreakOwner = null;
    $weeklyKillstreakLeaders = [];
    if (!empty($weeklyKillstreakRows)) {
        $steamIds = [];
        foreach ($weeklyKillstreakRows as $row) {
            $bestStreak = (int)($row['best_streak'] ?? 0);
            if ($bestStreak <= 0) {
                continue;
            }
            $steamId = (string)($row['steamid'] ?? '');
            if ($steamId !== '') {
                $steamIds[] = $steamId;
            }
        }

        $profiles = [];
        if (!empty($steamIds)) {
            $profiles = wt_fetch_steam_profiles(array_values(array_unique($steamIds)));
        }

        $defaultAvatar = WT_DEFAULT_AVATAR_URL;
        foreach ($weeklyKillstreakRows as $row) {
            $bestStreak = (int)($row['best_streak'] ?? 0);
            if ($bestStreak <= 0) {
                continue;
            }
            $steamId = (string)($row['steamid'] ?? '');
            $profile = $steamId !== '' ? ($profiles[$steamId] ?? []) : [];
            $weeklyKillstreakLeaders[] = [
                'steamid' => $steamId,
                'personaname' => $profile['personaname'] ?? ($row['personaname'] ?? ($steamId ?: 'Unknown')),
                'avatar' => $profile['avatarfull']
                    ?? ($profile['avatar'] ?? $defaultAvatar),
                'profileurl' => $profile['profileurl'] ?? null,
                'best_streak' => $bestStreak,
                'kills' => (int)($row['kills'] ?? 0),
            ];
        }

        if (!empty($weeklyKillstreakLeaders)) {
            $weeklyKillstreak = $weeklyKillstreakLeaders[0]['best_streak'];
            $weeklyKillstreakOwner = $weeklyKillstreakLeaders[0];
        }
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
                    ?? ($ownerProfile['avatar'] ?? WT_DEFAULT_AVATAR_URL),
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
        'bestKillstreakWeekLeaders' => $weeklyKillstreakLeaders,
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

function wt_format_log_datetime(int $timestamp): string
{
    $timezoneName = date_default_timezone_get() ?: 'UTC';
    $date = new DateTimeImmutable('@' . $timestamp);
    $date = $date->setTimezone(new DateTimeZone($timezoneName));
    return $date->format('M j, Y H:i');
}

function wt_slugify(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'unknown';
}

function wt_gamemode_icon_path(?string $gamemode): ?string
{
    if (!$gamemode) {
        return null;
    }
    $slug = wt_slugify($gamemode);
    $map = [
        'king-of-the-hill' => '/stats/assets/koth.png',
        'koth' => '/stats/assets/koth.png',
        'payload' => '/stats/assets/payload.png',
        'payload-race' => '/stats/assets/payload.png',
        'payload-push' => '/stats/assets/payload.png',
        'capture-the-flag' => '/stats/assets/ctf.png',
        'ctf' => '/stats/assets/ctf.png',
        'attack-defend-cp' => '/stats/assets/cp.png',
        'control-point' => '/stats/assets/cp.png',
        'cp' => '/stats/assets/cp.png',
        '5cp' => '/stats/assets/5cp.png',
    ];
    return $map[$slug] ?? null;
}

function wt_fragment_cache_dir(): string
{
    $dir = __DIR__ . '/cache/fragments';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function wt_fragment_paths(string $type, string $key): array
{
    $dir = wt_fragment_cache_dir();
    $base = $dir . '/' . $type . '-' . $key;
    return [
        'html' => $base . '.html',
        'meta' => $base . '.json',
    ];
}

function wt_fragment_load(string $type, string $key, string $revision, ?int $maxAgeSeconds = null): ?array
{
    $paths = wt_fragment_paths($type, $key);
    if (!is_file($paths['meta']) || !is_file($paths['html'])) {
        return null;
    }
    $meta = json_decode(file_get_contents($paths['meta']), true);
    if (!is_array($meta)) {
        return null;
    }
    $storedRevision = $meta['revision'] ?? null;
    if ($storedRevision !== $revision) {
        if ($maxAgeSeconds !== null) {
            $generatedAt = (int)($meta['generated_at'] ?? 0);
            if ($generatedAt > 0 && (time() - $generatedAt) <= $maxAgeSeconds) {
                // Serve stale cache within max age window
                $meta['stale_revision'] = $storedRevision;
                $meta['revision'] = $revision;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }
    $html = file_get_contents($paths['html']);
    if ($html === false) {
        return null;
    }
    $meta['html'] = $html;
    return $meta;
}

function wt_fragment_save(string $type, string $key, string $revision, array $payload): void
{
    $paths = wt_fragment_paths($type, $key);
    $html = $payload['html'] ?? '';
    file_put_contents($paths['html'], $html);
    $meta = $payload;
    unset($meta['html']);
    $meta['revision'] = $revision;
    $meta['generated_at'] = time();
    file_put_contents($paths['meta'], json_encode($meta));
}

function wt_cumulative_revision(): string
{
    $cacheKey = "wt:revision:cumulative";
    $cached = wt_cache_get($cacheKey);
    if ($cached !== null) {
        return (string)$cached;
    }

    $pdo = wt_pdo();
    $stmt = $pdo->query(
        "SELECT MAX(last_seen) AS recent, COUNT(*) AS total FROM " . WT_DB_TABLE
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $recent = (int)($row["recent"] ?? 0);
    $total = (int)($row["total"] ?? 0);
    
    $revision = $recent . ":" . $total;
    wt_cache_set($cacheKey, $revision, 60);
    return $revision;
}

function wt_logs_revision(): string
{
    $pdo = wt_pdo();
    $stmt = $pdo->query('SELECT MAX(updated_at) AS recent, COUNT(*) AS total FROM ' . wt_logs_table());
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $recent = (int)($row['recent'] ?? 0);
    $total = (int)($row['total'] ?? 0);
    return $recent . ':' . $total;
}
function wt_render_cumulative_fragment(array $context): string
{
    extract($context);
    ob_start();
    include __DIR__ . '/templates/cumulative_fragment.php';
    return ob_get_clean();
}

function wt_build_page_url(int $page, string $search = ''): string
{
    $params = $_GET;
    if ($search !== '') {
        $params['q'] = $search;
    } elseif (isset($params['q'])) {
        unset($params['q']);
    }
    if ($page <= 1) {
        unset($params['page']);
    } else {
        $params['page'] = $page;
    }
    $query = http_build_query($params);
    return 'index.php' . ($query !== '' ? '?' . $query : '');
}

function wt_get_cached_cumulative(string $search, int $page, int $perPage, ?string $focusedPlayer, string $defaultAvatarUrl): array
{
    $key = sha1(json_encode([$search, $page, $perPage, $focusedPlayer]));
    $revision = wt_cumulative_revision();
    $ttl = defined('WT_CUMULATIVE_FRAGMENT_TTL') ? (int)WT_CUMULATIVE_FRAGMENT_TTL : 60;
    if ($ttl <= 0) {
        $ttl = null;
    }
    $cached = wt_fragment_load('cumulative', $key, $revision, $ttl);
    if ($cached) {
        $cached['from_cache'] = true;
        return $cached;
    }

    $offset = ($page - 1) * $perPage;
    $paginated = wt_fetch_stats_paginated($search, $perPage, $offset);
    $pageStats = $paginated['rows'];
    $totalRows = (int)($paginated['total'] ?? 0);

    if ($page > 1 && empty($pageStats) && $totalRows > 0) {
        $page = (int)max(1, ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;
        $paginated = wt_fetch_stats_paginated($search, $perPage, $offset);
        $pageStats = $paginated['rows'];
        $totalRows = (int)($paginated['total'] ?? 0);
    }

    $totalPages = (int)max(1, ceil(max($totalRows, 1) / $perPage));
    $prevPage = $page > 1 ? $page - 1 : null;
    $nextPage = $page < $totalPages ? $page + 1 : null;
    $prevPageUrl = $prevPage !== null ? wt_build_page_url($prevPage, $search) : null;
    $nextPageUrl = $nextPage !== null ? wt_build_page_url($nextPage, $search) : null;

    $context = [
        'search' => $search,
        'page' => $page,
        'perPage' => $perPage,
        'pageStats' => $pageStats,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
        'prevPageUrl' => $prevPageUrl,
        'nextPageUrl' => $nextPageUrl,
        'focusedPlayer' => $focusedPlayer,
        'defaultAvatarUrl' => $defaultAvatarUrl,
    ];
    $html = wt_render_cumulative_fragment($context);

    $payload = [
        'html' => $html,
        'page' => $page,
        'perPage' => $perPage,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages,
        'prevPageUrl' => $prevPageUrl,
        'nextPageUrl' => $nextPageUrl,
        'pageStats' => $pageStats,
        'focusedPlayer' => $focusedPlayer,
        'search' => $search,
        'revision' => $revision,
    ];
    wt_fragment_save('cumulative', $key, $revision, $payload);
    $payload['from_cache'] = false;
    return $payload;
}

function wt_render_logs_fragment(array $context): string
{
    extract($context);
    ob_start();
    include __DIR__ . '/templates/logs_fragment.php';
    return ob_get_clean();
}





function wt_static_logs_paths(int $limit, string $scope = 'regular'): array
{
    $dir = wt_static_cache_dir();
    $scope = wt_logs_normalize_scope($scope);
    $suffix = $scope !== 'regular' ? '-' . $scope : '';
    $base = $dir . '/logs-limit' . $limit . $suffix;
    $pagesDir = $base . '-pages';
    if (!is_dir($pagesDir)) {
        mkdir($pagesDir, 0755, true);
    }
    return [
        'pages_dir' => $pagesDir,
        'meta' => $base . '.json',
    ];
}

function wt_build_static_logs(int $limit = 60, string $scope = 'regular'): array
{
    $scope = wt_logs_normalize_scope($scope);
    $data = wt_get_cached_logs($limit, $scope);
    $paths = wt_static_logs_paths($limit, $scope);
    $pagesDir = $paths['pages_dir'];

    foreach (glob($pagesDir . '/*.html') ?: [] as $file) {
        @unlink($file);
    }

    $logs = $data['logs'] ?? [];
    $totalLogs = is_array($logs) ? count($logs) : 0;
    $perPage = defined('WT_LOGS_PAGE_SIZE') ? max(1, (int)WT_LOGS_PAGE_SIZE) : 15;
    $chunks = array_chunk($logs, $perPage);
    if (empty($chunks)) {
        $chunks = [[]];
    }

    $page = 1;
    $writtenPages = [];
    foreach ($chunks as $chunk) {
        $html = wt_render_logs_fragment(['logs' => $chunk]);
        $pagePath = $pagesDir . '/page-' . $page . '.html';
        file_put_contents($pagePath, $html);
        $writtenPages[$page] = basename($pagePath);
        $page++;
    }

    $meta = [
        'generated_at' => time(),
        'revision' => $data['revision'] ?? null,
        'limit' => $limit,
        'template_version' => defined('WT_LOGS_FRAGMENT_VERSION') ? WT_LOGS_FRAGMENT_VERSION : '0',
        'per_page' => $perPage,
        'total_logs' => $totalLogs,
        'total_pages' => count($writtenPages) ?: 1,
        'scope' => $scope,
    ];
    file_put_contents($paths['meta'], json_encode($meta));

    return [
        'pages_dir' => $pagesDir,
        'meta_path' => $paths['meta'],
        'revision' => $data['revision'] ?? null,
        'limit' => $limit,
        'total_pages' => $meta['total_pages'],
        'per_page' => $perPage,
        'scope' => $scope,
    ];
}

function wt_summary_revision(): string
{
    $pdo = wt_pdo();
    $stmt = $pdo->query(
        'SELECT MAX(last_seen) AS recent, COUNT(*) AS total, SUM(damage_dealt) AS total_damage, SUM(total_ubers) AS total_ubers, SUM(medic_drops) AS total_drops FROM ' . WT_DB_TABLE
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $recent = (int)($row['recent'] ?? 0);
    $total = (int)($row['total'] ?? 0);
    $damage = (int)($row['total_damage'] ?? 0);
    $totalUbers = (int)($row['total_ubers'] ?? 0);
    $totalDrops = (int)($row['total_drops'] ?? 0);
    $dayBucket = (int)floor(time() / 86400);
    return implode(':', [$recent, $total, $damage, $totalUbers, $totalDrops, $dayBucket]);
}

function wt_render_summary_fragment(array $context): string
{
    extract($context);
    ob_start();
    include __DIR__ . '/templates/summary_fragment.php';
    return ob_get_clean();
}

function wt_build_summary_context(array $summary): array
{
    $playtimeMonthLabel = $summary['playtimeMonthLabel'] ?? date('F');
    $playtimeMonthHours = isset($summary['playtimeMonthHours']) ? (float)$summary['playtimeMonthHours'] : 0.0;
    $playersWeekChangePercentRaw = $summary['playersWeekChangePercent'] ?? null;
    $playersWeekTrend = $summary['playersWeekTrend'] ?? 'flat';
    if (!in_array($playersWeekTrend, ['up', 'down', 'flat'], true)) {
        $playersWeekTrend = 'flat';
    }
    if ($playersWeekChangePercentRaw !== null && $playersWeekChangePercentRaw < 0) {
        $playersWeekChangePercentRaw = 0.0;
        $playersWeekTrend = 'up';
    }
    $playersWeekTrendClass = 'stat-card-trend stat-card-trend--' . $playersWeekTrend;
    if ($playersWeekChangePercentRaw !== null) {
        $playersWeekChangeLabel = sprintf('%+.1f%%', $playersWeekChangePercentRaw);
        $playersWeekChangeTitle = 'Change vs prior 7 days: ' . $playersWeekChangeLabel;
    } else {
        $playersWeekChangeLabel = '—';
        $playersWeekChangeTitle = 'Change vs prior 7 days: not enough data';
    }

    return [
        'playtimeMonthLabel' => $playtimeMonthLabel,
        'playtimeMonthHours' => $playtimeMonthHours,
        'playersWeekTrendClass' => $playersWeekTrendClass,
        'playersWeekChangeLabel' => $playersWeekChangeLabel,
        'playersWeekTooltip' => $playersWeekChangeTitle,
        'playersWeekCurrent' => (int)($summary['playersCurrentWeek'] ?? 0),
        'playersMonthCurrent' => (int)($summary['playersCurrentMonth'] ?? 0),
        'totalPlayersAllTime' => (int)($summary['totalPlayers'] ?? 0),
        'bestKillstreakWeek' => (int)($summary['bestKillstreakWeek'] ?? 0),
        'bestKillstreakWeekLeaders' => $summary['bestKillstreakWeekLeaders'] ?? [],
        'weeklyTopDpm' => isset($summary['weeklyTopDpm']) ? (float)$summary['weeklyTopDpm'] : 0.0,
        'weeklyTopDpmOwner' => $summary['weeklyTopDpmOwner'] ?? null,
        'averageDpm' => isset($summary['averageDpm']) ? (float)$summary['averageDpm'] : 0.0,
        'totalDrops' => (int)($summary['totalDrops'] ?? 0),
        'totalUbersUsed' => (int)($summary['totalUbersUsed'] ?? 0),
        'gamemodeTop' => $summary['gamemodeTop'] ?? [],
    ];
}

function wt_get_cached_summary(): array
{
    $key = 'default';
    $revision = wt_summary_revision();
    $cached = wt_fragment_load('summary', $key, $revision);
    if ($cached) {
        $cached['from_cache'] = true;
        return $cached;
    }

    $summary = wt_fetch_summary_stats();
    $summary = array_merge($summary, wt_summary_insights());
    $performanceAverages = wt_fetch_performance_averages();
    $context = wt_build_summary_context($summary);
    $context['summary'] = $summary;
    $context['defaultAvatarUrl'] = WT_DEFAULT_AVATAR_URL;

    $html = wt_render_summary_fragment($context);
    $payload = [
        'html' => $html,
        'summary' => $summary,
        'performanceAverages' => $performanceAverages,
    ];
    wt_fragment_save('summary', $key, $revision, $payload);
    $payload['from_cache'] = false;
    return $payload;
}

function wt_stat_compare_attr(bool $enabled, float $value, float $average, bool $higherIsBetter = true): string
{
    if (!$enabled || $average <= 0.0 || !is_finite($average)) {
        return '';
    }

    $diff = (($value - $average) / $average) * 100.0;
    if (!is_finite($diff)) {
        return '';
    }

    $classSuffix = 'stat-compare--neutral';
    if (abs($diff) >= 0.05) {
        $positive = $diff >= 0.0;
        $good = $higherIsBetter ? $positive : !$positive;
        $classSuffix = $good ? 'stat-compare--better' : 'stat-compare--worse';
    }

    $classes = 'stat-compare ' . $classSuffix;
    $title = sprintf('%+.1f%% vs server average', $diff);

    return ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
}

function wt_render_cumulative_rows(array $rows, ?string $focusedSteamId, string $defaultAvatarUrl): string
{
    ob_start();
    foreach ($rows as $row) {
        $steamId = (string)($row['steamid'] ?? '');
        $personaname = (string)($row['personaname'] ?? $steamId);
        $avatar = trim((string)($row['avatar'] ?? ''));
        if ($avatar === '') {
            $avatar = $defaultAvatarUrl;
        }
        $profileUrl = $row['profileurl'] ?? null;
        $kills = (int)($row['kills'] ?? 0);
        $deaths = (int)($row['deaths'] ?? 0);
        $assists = (int)($row['assists'] ?? 0);
        $healing = (int)($row['healing'] ?? 0);
        $headshots = (int)($row['headshots'] ?? 0);
        $backstabs = (int)($row['backstabs'] ?? 0);
        $bestStreak = (int)($row['best_killstreak'] ?? 0);
        $playtimeSeconds = (int)($row['playtime'] ?? 0);
        $totalDamage = (int)($row['damage_dealt'] ?? 0);
        $damageTaken = (int)($row['damage_taken'] ?? 0);
        $airshots = (int)($row['airshots'] ?? 0);
        $drops = (int)($row['medic_drops'] ?? 0);
        $dropped = (int)($row['uber_drops'] ?? $drops);
        [$totalShots, $totalHits] = wt_total_weapon_accuracy_counts($row);
        $minutesPlayed = $playtimeSeconds > 0 ? ($playtimeSeconds / 60.0) : 0.0;
        $dpm = $minutesPlayed > 0 ? $totalDamage / $minutesPlayed : 0.0;
        $dtpm = $minutesPlayed > 0 ? $damageTaken / $minutesPlayed : 0.0;
        $score = $kills + $assists;
        $kd = $deaths > 0 ? $kills / $deaths : $kills;
        $isOnline = !empty($row['is_online']);
        $isAdmin = !empty($row['is_admin']);
        $playtimeHuman = (string)($row['playtime_human'] ?? wt_format_playtime($playtimeSeconds));
        $overallAccuracy = isset($row['accuracy_overall']) ? (float)$row['accuracy_overall'] : ($totalShots > 0 ? ($totalHits / max($totalShots, 1) * 100.0) : 0.0);

        $topAccuracyValue = null;
        $topAccuracyShots = 0;
        $topAccuracyHits = 0;
        $topAccuracyLabel = null;
        $topAccuracyClassId = 0;
        $favoriteClassId = isset($row['favorite_class']) ? (int)$row['favorite_class'] : 0;
        $favoriteClassMeta = WT_CLASS_METADATA[$favoriteClassId] ?? null;
        $favoriteClassSlug = $favoriteClassMeta['slug'] ?? null;
        $favoriteClassLabel = $favoriteClassMeta['label'] ?? null;
        $favoriteClassIcon = $favoriteClassSlug ? wt_class_icon_url($favoriteClassSlug) : null;
        if ($favoriteClassId > 0) {
            $topAccuracyClassId = $favoriteClassId;
        }

        $bestWeaponAccuracy = -1.0;
        $bestWeaponShots = 0;
        $bestWeaponHits = 0;
        $bestWeaponLabel = null;

        foreach (wt_weapon_category_metadata() as $slug => $meta) {
            $shotsKey = 'shots_' . $slug;
            $hitsKey = 'hits_' . $slug;
            $shotsValue = isset($row[$shotsKey]) ? (int)$row[$shotsKey] : 0;
            $hitsValue = isset($row[$hitsKey]) ? (int)$row[$hitsKey] : 0;
            if ($shotsValue <= 0) {
                continue;
            }
            $accValue = ($hitsValue / max($shotsValue, 1)) * 100.0;
            if ($bestWeaponAccuracy < 0.0 || $accValue > $bestWeaponAccuracy || ($accValue === $bestWeaponAccuracy && $shotsValue > $bestWeaponShots)) {
                $bestWeaponAccuracy = $accValue;
                $bestWeaponShots = $shotsValue;
                $bestWeaponHits = $hitsValue;
                $bestWeaponLabel = $meta['label'] ?? ucfirst($slug);
            }
        }

        if ($bestWeaponAccuracy >= 0.0) {
            $topAccuracyValue = $bestWeaponAccuracy;
            $topAccuracyShots = $bestWeaponShots;
            $topAccuracyHits = $bestWeaponHits;
            $topAccuracyLabel = $bestWeaponLabel;
        } elseif ($totalShots > 0) {
            $topAccuracyValue = $overallAccuracy;
            $topAccuracyShots = $totalShots;
            $topAccuracyHits = $totalHits;
            $topAccuracyLabel = 'Overall';
        }

        $accuracyTooltipParts = [];
        if ($totalShots > 0) {
            $accuracyTooltipParts[] = sprintf(
                'Overall: %s (%d/%d)',
                number_format($overallAccuracy, 1) . '%',
                $totalHits,
                $totalShots
            );
        } else {
            $accuracyTooltipParts[] = 'No shots recorded';
        }

        $weaponSegments = [];
        foreach (wt_weapon_category_metadata() as $slug => $meta) {
            $shotsKey = 'shots_' . $slug;
            $hitsKey = 'hits_' . $slug;
            $shotsValue = isset($row[$shotsKey]) ? (int)$row[$shotsKey] : 0;
            $hitsValue = isset($row[$hitsKey]) ? (int)$row[$hitsKey] : 0;
            $label = $meta['label'] ?? ucfirst($slug);
            $weaponSegments[] = sprintf(
                '%s: %s shots / %s hits',
                $label,
                number_format($shotsValue),
                number_format($hitsValue)
            );
        }

        if (!empty($weaponSegments)) {
            $accuracyTooltipParts[] = implode('; ', $weaponSegments);
        }

        $accuracyTooltip = implode(' • ', $accuracyTooltipParts);

        $rowClasses = [];
        if ($isOnline) {
            $rowClasses[] = 'online-player';
        }
        if ($focusedSteamId !== null && $steamId === $focusedSteamId) {
            $rowClasses[] = 'player-highlight';
        }
        $rowClassAttr = $rowClasses ? ' class="' . htmlspecialchars(implode(' ', $rowClasses), ENT_QUOTES, 'UTF-8') . '"' : '';

        $dataAttributes = [
            'player' => strtolower($personaname),
            'kills' => (string)$kills,
            'assists' => (string)$assists,
            'deaths' => (string)$deaths,
            'damage' => (string)$totalDamage,
            'damage_taken' => (string)$damageTaken,
            'dpm' => sprintf('%.4f', $dpm),
            'dtpm' => sprintf('%.4f', $dtpm),
            'accuracy' => sprintf('%.4f', $overallAccuracy),
            'accuracy_class' => (string)$topAccuracyClassId,
            'airshots' => (string)$airshots,
            'drops' => (string)$drops,
            'dropped' => (string)$dropped,
            'kd' => sprintf('%.4f', $kd),
            'healing' => (string)$healing,
            'headshots' => (string)$headshots,
            'backstabs' => (string)$backstabs,
            'streak' => (string)$bestStreak,
            'playtime' => (string)$playtimeSeconds,
            'score' => (string)$score,
            'online' => $isOnline ? '1' : '0',
        ];

        $dataAttrString = '';
        foreach ($dataAttributes as $key => $value) {
            $dataAttrString .= sprintf(
                ' data-%s="%s"',
                htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
            );
        }

        $nameClasses = [];
        if ($isAdmin) {
            $nameClasses[] = 'admin-name';
        }
        if ($isOnline) {
            $nameClasses[] = 'online-name';
        }
        $nameClassAttr = $nameClasses ? ' class="' . htmlspecialchars(implode(' ', $nameClasses), ENT_QUOTES, 'UTF-8') . '"' : '';

        $nameTitle = 'Player';
        if ($isOnline && $isAdmin) {
            $nameTitle = 'Connected Admin';
        } elseif ($isOnline) {
            $nameTitle = 'Connected Player';
        } elseif ($isAdmin) {
            $nameTitle = 'Admin';
        }
        $nameTitleAttr = ' title="' . htmlspecialchars($nameTitle, ENT_QUOTES, 'UTF-8') . '"';

        echo '<tr', $rowClassAttr, $dataAttrString, '>';
        echo '<td class="player-cell">';
        echo '<img class="player-avatar" src="', htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'), '" alt="" onerror="this.onerror=null;this.src=\'', htmlspecialchars($defaultAvatarUrl, ENT_QUOTES, 'UTF-8'), '\'">';
        echo '<div>';
        if (!empty($profileUrl)) {
            echo '<a', $nameClassAttr, $nameTitleAttr, ' href="', htmlspecialchars((string)$profileUrl, ENT_QUOTES, 'UTF-8'), '" target="_blank" rel="noopener">', htmlspecialchars($personaname, ENT_QUOTES, 'UTF-8'), '</a>';
        } else {
            echo '<span', $nameClassAttr, $nameTitleAttr, '>', htmlspecialchars($personaname, ENT_QUOTES, 'UTF-8'), '</span>';
        }
        echo '</div>';
        echo '</td>';

        echo '<td>', number_format($kills), '|', number_format($deaths), '|', number_format($assists), '</td>';
        echo '<td>', number_format($kd, 2), '</td>';
        echo '<td>', number_format($totalDamage), '</td>';
        echo '<td>', number_format($damageTaken), '</td>';
        echo '<td>', number_format($dpm, 1), '</td>';
        echo '<td>', number_format($dtpm, 1), '</td>';
        $accuracyCellAttr = $accuracyTooltip !== '' ? ' title="' . htmlspecialchars($accuracyTooltip, ENT_QUOTES, 'UTF-8') . '"' : '';
        echo '<td class="stat-accuracy-cell"', $accuracyCellAttr, '>';
        if ($topAccuracyValue !== null) {
            echo '<span class="stat-accuracy-value">', number_format($topAccuracyValue, 1), '%</span>';
        } else {
            echo '—';
        }
        if ($favoriteClassIcon && $favoriteClassLabel) {
            $favWeaponMap = [
                1 => ['scatterguns', 'pistols'],           // Scout
                2 => ['snipers'],                          // Sniper
                3 => ['rocketlaunchers', 'shotguns'],      // Soldier
                4 => ['grenadelaunchers', 'stickylaunchers'], // Demoman
                5 => [],                                   // Medic (no specific mapping requested)
                6 => [],                                   // Heavy
                7 => [],                                   // Pyro
                8 => ['revolvers'],                        // Spy
                9 => ['shotguns', 'pistols'],              // Engineer
            ];
            $segments = [];
            $mapped = $favWeaponMap[$favoriteClassId] ?? [];
            foreach ($mapped as $slug) {
                $label = WT_WEAPON_CATEGORY_METADATA[$slug]['label'] ?? ucfirst($slug);
                $shotsValue = isset($row['shots_' . $slug]) ? (int)$row['shots_' . $slug] : 0;
                $hitsValue = isset($row['hits_' . $slug]) ? (int)$row['hits_' . $slug] : 0;
                $segments[] = sprintf('%s: %s shots / %s hits', $label, number_format($shotsValue), number_format($hitsValue));
            }
            $title = 'Favorite class: ' . $favoriteClassLabel;
            if (!empty($segments)) {
                $title .= ' | ' . implode(', ', $segments);
            }
            echo '<img class="stat-accuracy-icon" src="', htmlspecialchars($favoriteClassIcon, ENT_QUOTES, 'UTF-8'), '" alt="', htmlspecialchars($favoriteClassLabel, ENT_QUOTES, 'UTF-8'), '" title="', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), '">';
        }
        echo '</td>';
        echo '<td>', number_format($airshots), '</td>';
        echo '<td>', number_format($drops), ' | ', number_format($dropped), '</td>';
        echo '<td>', number_format($healing), '</td>';
        echo '<td>', number_format($headshots), '</td>';
        echo '<td>', number_format($backstabs), '</td>';
        echo '<td>', number_format($bestStreak), '</td>';
        echo '<td>', htmlspecialchars($playtimeHuman, ENT_QUOTES, 'UTF-8'), '</td>';
        echo '</tr>';
    }
    return ob_get_clean();
}

function wt_render_single_log(array $log, int $index = -1): string
{
    $logId = $log['log_id'] ?? null;
    $endedAt = (int)($log['ended_at'] ?? 0);
    $isFinalized = $endedAt > 0;
    $cacheFile = null;

    if ($isFinalized && $logId) {
        $cacheDir = __DIR__ . '/cache/logs';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . '/log_' . $logId . '.html';
        if (file_exists($cacheFile)) {
            return file_get_contents($cacheFile);
        }
    }

    ob_start();
    include __DIR__ . '/templates/single_log.php';
    $html = ob_get_clean();

    if ($isFinalized && $cacheFile) {
        file_put_contents($cacheFile, $html);
    }

    return $html;
}

function wt_logs_per_page(): int
{
    static $perPage = null;
    if ($perPage !== null) {
        return $perPage;
    }
    $perPage = defined('WT_LOGS_PAGE_SIZE') ? max(1, (int)WT_LOGS_PAGE_SIZE) : 25;
    return $perPage;
}

function wt_logs_max_pages(): int
{
    static $maxPages = null;
    if ($maxPages !== null) {
        return $maxPages;
    }
    $maxPages = defined('WT_LOGS_MAX_PAGES') ? (int)WT_LOGS_MAX_PAGES : 2;
    if ($maxPages < 1) {
        $maxPages = 1;
    }
    return $maxPages;
}

function wt_logs_cache_store(string $scope, string $html, int $lastUpdate, int $totalLogs): void
{
    $cacheFile = __DIR__ . '/cache/logs_page_1_' . $scope . '.html';
    $metaFile = __DIR__ . '/cache/logs_meta.json';
    
    if (!is_dir(__DIR__ . '/cache')) {
        mkdir(__DIR__ . '/cache', 0777, true);
    }
    
    file_put_contents($cacheFile, $html);
    
    $meta = [
        'last_update' => $lastUpdate,
        'total_logs' => $totalLogs,
        'total_pages' => max(1, (int)ceil(max($totalLogs, 1) / wt_logs_per_page()))
    ];
    file_put_contents($metaFile, json_encode($meta));
}
