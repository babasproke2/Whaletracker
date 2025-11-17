<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$defaultPerPage = defined('WT_LOGS_PAGE_SIZE') ? max(1, (int)WT_LOGS_PAGE_SIZE) : 15;
$requestedLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : (defined('WT_LOGS_TOTAL_LIMIT') ? (int)WT_LOGS_TOTAL_LIMIT : 60);
$limit = max($defaultPerPage, min($requestedLimit, 300));
$scope = wt_logs_normalize_scope($_GET['scope'] ?? 'regular');
$perPage = $defaultPerPage;
$page = max(1, (int)($_GET['page'] ?? 1));
$paths = wt_static_logs_paths($limit, $scope);
$needBuild = true;
$currentRevision = wt_logs_revision();
$currentVersion = defined('WT_LOGS_FRAGMENT_VERSION') ? WT_LOGS_FRAGMENT_VERSION : '0';
$meta = null;

if (is_file($paths['meta'])) {
    $meta = json_decode(file_get_contents($paths['meta']), true);
    if (is_array($meta)
        && ($meta['revision'] ?? null) === $currentRevision
        && ($meta['template_version'] ?? '0') === $currentVersion
        && (int)($meta['per_page'] ?? 0) === $perPage
        && ($meta['scope'] ?? 'regular') === $scope) {
        $needBuild = false;
    }
}

if ($needBuild) {
    wt_build_static_logs($limit, $scope);
    $meta = null;
}

if ($meta === null && is_file($paths['meta'])) {
    $meta = json_decode(file_get_contents($paths['meta']), true);
}

if (!is_array($meta)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Logs fragment unavailable']);
    exit;
}

$totalPages = max(1, (int)($meta['total_pages'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$pagesDir = $paths['pages_dir'];
$pagePath = rtrim($pagesDir, '/') . '/page-' . $page . '.html';

if (!is_file($pagePath)) {
    wt_build_static_logs($limit, $scope);
    $meta = json_decode(file_get_contents($paths['meta']), true);
    if (!is_array($meta)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Logs fragment unavailable']);
        exit;
    }
    $totalPages = max(1, (int)($meta['total_pages'] ?? 1));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $pagePath = rtrim($pagesDir, '/') . '/page-' . $page . '.html';
    if (!is_file($pagePath)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Logs fragment unavailable']);
        exit;
    }
}

$html = file_get_contents($pagePath);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=5');
echo json_encode([
    'ok' => true,
    'html' => $html,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => $totalPages,
    'total_logs' => (int)($meta['total_logs'] ?? 0),
    'revision' => $meta['revision'] ?? null,
    'generated_at' => $meta['generated_at'] ?? null,
    'scope' => $scope,
]);
