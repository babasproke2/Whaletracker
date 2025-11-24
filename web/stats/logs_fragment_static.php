<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=5');

$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : wt_logs_per_page();
$perPage = max(1, min($perPage, 100));
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$scope = wt_logs_normalize_scope($_GET['scope'] ?? 'regular');

$data = wt_get_cached_logs($perPage, $scope, $page);
$fromCache = !empty($data['from_cache']);

if ($fromCache) {
    $html = (string)($data['html'] ?? '');
} else {
    $logs = $data['logs'] ?? [];
    $html = wt_render_logs_fragment(['logs' => $logs]);
    if ($page === 1 && $perPage === wt_logs_per_page()) {
        wt_logs_cache_store(
            $scope,
            $html,
            (int)($data['last_update'] ?? time()),
            (int)($data['total_logs'] ?? count($logs))
        );
    }
}

echo json_encode([
    'ok' => true,
    'html' => $html,
    'page' => (int)($data['page'] ?? $page),
    'per_page' => $perPage,
    'total_pages' => (int)($data['total_pages'] ?? 1),
    'total_logs' => (int)($data['total_logs'] ?? 0),
    'scope' => $scope,
    'from_cache' => $fromCache,
]);
