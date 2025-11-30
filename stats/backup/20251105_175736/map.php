<?php

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$groups = wt_map_groups();
$requestedKey = $_GET['map'] ?? $_GET['group'] ?? '';
$requestedKey = strtolower(trim((string)$requestedKey));

if ($requestedKey === '' && !empty($groups)) {
    $requestedKey = array_key_first($groups);
}

$group = wt_map_group($requestedKey);
if ($group === null) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'unknown_map',
        'available' => array_keys($groups),
    ]);
    exit;
}

try {
    $players = wt_fetch_map_leaderboard($group['key']);
    echo json_encode([
        'success' => true,
        'generated_at' => time(),
        'group' => [
            'key' => $group['key'],
            'label' => $group['label'],
            'maps' => $group['maps'],
            'map_count' => count($group['maps']),
        ],
        'players' => $players,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'map_query_failed',
    ]);
}
