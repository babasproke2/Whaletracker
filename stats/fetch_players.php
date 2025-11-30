<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$search = trim($_GET['q'] ?? '');

// Fetch all matching players without pagination
$allPlayers = wt_fetch_all_matching_players($search);

echo json_encode([
    'success' => true,
    'players' => $allPlayers
]);