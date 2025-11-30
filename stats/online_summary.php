<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=5');

$cacheKey = 'wt:online:navcount';
$cacheTtl = 10;

$cached = wt_cache_get($cacheKey);
if (is_array($cached) && isset($cached['player_count'])) {
    echo json_encode($cached);
    return;
}

$pdo = wt_pdo();
$now = time();
$cutoff = $now - 180;

$playerCount = 0;
$visibleMax = 0;
$updated = $now;

try {
    $stmt = $pdo->prepare(
        'SELECT SUM(playercount) AS total_players, SUM(visible_max) AS total_slots, MAX(last_update) AS last_update
         FROM whaletracker_servers WHERE last_update >= :cutoff'
    );
    $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $playerCount = (int)($row['total_players'] ?? 0);
    $visibleMax = (int)($row['total_slots'] ?? 0);
    $updated = (int)($row['last_update'] ?? $now);
} catch (Throwable $e) {
    // ignore and fallback below
}

if ($playerCount <= 0 || $visibleMax <= 0) {
    try {
        $stmt = $pdo->query('SELECT COUNT(*) AS total_players FROM whaletracker_online');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        $playerCount = (int)($row['total_players'] ?? 0);
        if ($visibleMax <= 0) {
            $visibleMax = 32;
        }
        $updated = $now;
    } catch (Throwable $e) {
        // still respond below
    }
}

if ($visibleMax <= 0) {
    $visibleMax = 32;
}

$response = [
    'success' => true,
    'player_count' => $playerCount,
    'visible_max' => $visibleMax,
    'updated' => $updated,
];

wt_cache_set($cacheKey, $response, $cacheTtl);

echo json_encode($response);
