<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 60;
$limit = max(1, min($limit, 100));
$scope = wt_logs_normalize_scope($_GET['scope'] ?? 'all');

try {
    $logs = wt_fetch_logs($limit, $scope);
    echo json_encode([
        'success' => true,
        'generated_at' => time(),
        'logs' => $logs,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'internal_error',
    ]);
}
