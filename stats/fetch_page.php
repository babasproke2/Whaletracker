<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$result = wt_fetch_stats_paginated($search, $perPage, $offset);

// Format the rows as HTML
ob_start();
wt_render_cumulative_rows($result['rows'], $_GET['focused'] ?? null);
$rowsHtml = ob_get_clean();

echo json_encode([
    'success' => true,
    'html' => $rowsHtml,
    'total' => $result['total'],
    'page' => $page,
    'totalPages' => ceil($result['total'] / $perPage)
]);