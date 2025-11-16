<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$cacheKey = 'wt:online:latest';
$cacheTtlSeconds = 300;
$now = time();

$weaponSelectParts = [];
for ($slot = 1; $slot <= WT_MAX_WEAPON_SLOTS; $slot++) {
    $weaponSelectParts[] = sprintf(
        'weapon%d_name, weapon%d_accuracy, weapon%d_shots, weapon%d_hits',
        $slot,
        $slot,
        $slot,
        $slot
    );
}
$weaponSelectSql = implode(', ', $weaponSelectParts);
$weaponSelectClause = $weaponSelectSql !== '' ? ', ' . $weaponSelectSql : '';

$classSelectParts = [];
foreach (WT_CLASS_METADATA as $meta) {
    $slug = $meta['slug'] ?? null;
    if (!$slug) {
        continue;
    }
    $classSelectParts[] = sprintf('shots_%s', $slug);
    $classSelectParts[] = sprintf('hits_%s', $slug);
}
$classSelectSql = implode(', ', $classSelectParts);
$classSelectClause = $classSelectSql !== '' ? ', ' . $classSelectSql : '';

try {
    $pdo = wt_pdo();
    wt_clear_online_cache_flag($pdo, $cacheKey);
    $sqlExtended = sprintf(
        'SELECT steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits%s%s, '
        . 'playtime, total_ubers, classes_mask, time_connected, visible_max, last_update '
        . 'FROM whaletracker_online ORDER BY last_update DESC',
        $classSelectClause,
        $weaponSelectClause
    );
    try {
        $stmt = $pdo->query($sqlExtended);
    } catch (Throwable $e) {
        $sqlLegacy = sprintf(
            'SELECT steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits%s%s, '
            . 'playtime, total_ubers, time_connected, visible_max, last_update '
            . 'FROM whaletracker_online ORDER BY last_update DESC',
            $classSelectClause,
            $weaponSelectClause
        );
        $stmt = $pdo->query($sqlLegacy);
    }
    $players = $stmt->fetchAll();

    if (empty($players)) {
        $cachedEnvelope = wt_cache_get($cacheKey);
        if (is_array($cachedEnvelope)) {
            $cachedTimestamp = (int)($cachedEnvelope['timestamp'] ?? 0);
            $cachedResponse = $cachedEnvelope['response'] ?? null;
            $cachedPlayers = is_array($cachedResponse) ? ($cachedResponse['players'] ?? null) : null;
            if (is_array($cachedResponse)
                && is_array($cachedPlayers)
                && !empty($cachedPlayers)
                && $cachedTimestamp > 0
                && ($now - $cachedTimestamp) <= 60
            ) {
                $cachedResponse['served_from_cache'] = true;
                echo json_encode($cachedResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
        }
    }

    $visibleMax = 32;
    $steamIds = [];
    if (!empty($players)) {
        $candidate = (int)($players[0]['visible_max'] ?? 0);
        if ($candidate > 0) {
            $visibleMax = $candidate;
        }
    }

    foreach ($players as &$row) {
        unset($row['visible_max']);
        $row['shots'] = (int)($row['shots'] ?? 0);
        $row['hits'] = (int)($row['hits'] ?? 0);
        $steamId = (string)($row['steamid'] ?? '');
        if ($steamId !== '') {
            $steamIds[] = $steamId;
        }
        $row['classes_mask'] = (int)($row['classes_mask'] ?? 0);
    }
    unset($row);

    if (!empty($steamIds)) {
        $steamIds = array_values(array_unique($steamIds));
        $profiles = wt_fetch_steam_profiles($steamIds);
        $adminFlags = wt_fetch_admin_flags($steamIds);
        $defaultAvatar = WT_DEFAULT_AVATAR_URL;

        foreach ($players as &$row) {
            $steamId = (string)($row['steamid'] ?? '');
            if ($steamId === '') {
                $row['avatar'] = $defaultAvatar;
                $row['is_admin'] = 0;
                $row['class_accuracy_summary'] = [];
                $row['weapon_accuracy_summary'] = [];
                continue;
            }

            $profile = $profiles[$steamId] ?? null;
            if ($profile !== null) {
                if (!empty($profile['personaname'])) {
                    $row['personaname'] = $profile['personaname'];
                }
                if (!empty($profile['profileurl'])) {
                    $row['profileurl'] = $profile['profileurl'];
                }
            }

            $avatar = ($profile['avatarfull'] ?? '') ?: ($profile['avatarmedium'] ?? '') ?: ($profile['avatar'] ?? '');
            $row['avatar'] = $avatar ?: $defaultAvatar;
            $row['is_admin'] = !empty($adminFlags[$steamId]) ? 1 : (int)($row['is_admin'] ?? 0);

            $classSummary = [];
            foreach (WT_CLASS_METADATA as $classId => $meta) {
                $slug = $meta['slug'] ?? null;
                if (!$slug) {
                    continue;
                }
                $shotsKey = "shots_{$slug}";
                $hitsKey = "hits_{$slug}";
                $classShots = (int)($row[$shotsKey] ?? 0);
                $classHits = (int)($row[$hitsKey] ?? 0);
                unset($row[$shotsKey], $row[$hitsKey]);
                if ($classShots <= 0) {
                    continue;
                }
                $accuracy = ($classShots > 0) ? ($classHits / max($classShots, 1) * 100.0) : null;
                $label = $meta['label'] ?? ucfirst($slug);
                $classSummary[] = [
                    'label' => $label,
                    'accuracy' => $accuracy,
                    'shots' => $classShots,
                    'hits' => $classHits,
                ];
            }
            if (empty($classSummary)) {
                $totalShots = (int)($row['shots'] ?? 0);
                $totalHits = (int)($row['hits'] ?? 0);
                if ($totalShots > 0) {
                    $classSummary[] = [
                        'label' => 'Overall',
                        'accuracy' => ($totalHits / max($totalShots, 1)) * 100.0,
                        'shots' => $totalShots,
                        'hits' => $totalHits,
                    ];
                }
            }

            $weaponSummary = [];
            for ($slot = 1; $slot <= WT_MAX_WEAPON_SLOTS; $slot++) {
                $name = trim((string)($row[sprintf('weapon%d_name', $slot)] ?? ''));
                $accuracy = $row[sprintf('weapon%d_accuracy', $slot)] ?? null;
                $shotsValue = (int)($row[sprintf('weapon%d_shots', $slot)] ?? 0);
                $hitsValue = (int)($row[sprintf('weapon%d_hits', $slot)] ?? 0);
                if ($name === '' || $accuracy === null || $shotsValue <= 0) {
                    continue;
                }
                $weaponSummary[] = [
                    'name' => $name,
                    'accuracy' => (float)$accuracy,
                    'shots' => $shotsValue,
                    'hits' => $hitsValue,
                ];
            }

            $row['class_accuracy_summary'] = $classSummary;
            $row['weapon_accuracy_summary'] = $weaponSummary;

            for ($slot = 1; $slot <= WT_MAX_WEAPON_SLOTS; $slot++) {
                unset($row[sprintf('weapon%d_name', $slot)], $row[sprintf('weapon%d_accuracy', $slot)], $row[sprintf('weapon%d_shots', $slot)], $row[sprintf('weapon%d_hits', $slot)]);
            }
        }
        unset($row);
    }

    $response = [
        'success' => true,
        'updated' => $now,
        'visible_max_players' => $visibleMax,
        'players' => $players,
    ];

    if (!empty($players)) {
        wt_cache_set($cacheKey, [
            'timestamp' => $now,
            'response' => $response,
        ], $cacheTtlSeconds);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'internal_error',
    ]);
}
