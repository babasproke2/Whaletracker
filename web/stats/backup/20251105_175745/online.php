<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$cacheKey = 'wt:online:latest';
$cacheTtlSeconds = 300;
$now = time();

try {
    $pdo = wt_pdo();
    $sqlExtended = 'SELECT steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits, '
        . 'best_class_id, best_class_accuracy, best_class_shots, best_class_hits, '
        . 'weapon1_name, weapon1_accuracy, weapon1_shots, weapon1_hits, '
        . 'weapon2_name, weapon2_accuracy, weapon2_shots, weapon2_hits, '
        . 'weapon3_name, weapon3_accuracy, weapon3_shots, weapon3_hits, '
        . 'weapon4_name, weapon4_accuracy, weapon4_shots, weapon4_hits, '
        . 'weapon5_name, weapon5_accuracy, weapon5_shots, weapon5_hits, '
        . 'weapon6_name, weapon6_accuracy, weapon6_shots, weapon6_hits, '
        . 'playtime, total_ubers, classes_mask, time_connected, visible_max, last_update '
        . 'FROM whaletracker_online ORDER BY last_update DESC';
    try {
        $stmt = $pdo->query($sqlExtended);
    } catch (Throwable $e) {
        $sqlLegacy = 'SELECT steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits, '
            . 'best_class_id, best_class_accuracy, best_class_shots, best_class_hits, '
            . 'weapon1_name, weapon1_accuracy, weapon1_shots, weapon1_hits, '
            . 'weapon2_name, weapon2_accuracy, weapon2_shots, weapon2_hits, '
            . 'weapon3_name, weapon3_accuracy, weapon3_shots, weapon3_hits, '
            . 'playtime, total_ubers, classes_mask, time_connected, visible_max, last_update '
            . 'FROM whaletracker_online ORDER BY last_update DESC';
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
        $row['best_class_id'] = (int)($row['best_class_id'] ?? 0);
        $row['best_class_accuracy'] = isset($row['best_class_accuracy']) ? (float)$row['best_class_accuracy'] : null;
        $row['best_class_shots'] = (int)($row['best_class_shots'] ?? 0);
        $row['best_class_hits'] = (int)($row['best_class_hits'] ?? 0);
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
        $defaultAvatar = 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png';

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

            $avatar = $profile['avatarfull'] ?? ($profile['avatar'] ?? null);
            $row['avatar'] = $avatar ?? $defaultAvatar;
            $row['is_admin'] = !empty($adminFlags[$steamId]) ? 1 : (int)($row['is_admin'] ?? 0);

            $classSummary = [];
            $bestClassId = (int)($row['best_class_id'] ?? 0);
            $bestClassAccuracy = isset($row['best_class_accuracy']) ? (float)$row['best_class_accuracy'] : null;
            $bestClassShots = (int)($row['best_class_shots'] ?? 0);
            $bestClassHits = (int)($row['best_class_hits'] ?? 0);
            if ($bestClassId > 0 && $bestClassAccuracy !== null && $bestClassShots > 0) {
                $meta = WT_CLASS_METADATA[$bestClassId] ?? null;
                $label = is_array($meta) ? ($meta['label'] ?? 'Class') : 'Class';
                $classSummary[] = [
                    'label' => $label,
                    'accuracy' => $bestClassAccuracy,
                    'shots' => $bestClassShots,
                    'hits' => $bestClassHits,
                ];
            }

            $weaponSummary = [];
            for ($slot = 1; $slot <= 6; $slot++) {
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

            unset($row['best_class_id'], $row['best_class_accuracy'], $row['best_class_shots'], $row['best_class_hits']);
            for ($slot = 1; $slot <= 6; $slot++) {
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
