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
    $stmt = $pdo->query(
        'SELECT steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, playtime, total_ubers, classes_mask, time_connected, visible_max, last_update '
        . 'FROM whaletracker_online ORDER BY last_update DESC'
    );
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
