<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$limit = isset($argv[1]) ? (int)$argv[1] : (int)($_GET['limit'] ?? 60);
$limit = max(1, min($limit, 100));
$scopeArg = $argv[2] ?? ($_GET['scope'] ?? 'regular');
$scope = wt_logs_normalize_scope($scopeArg);

$data = wt_build_static_logs($limit, $scope);

$metaPath = $data['meta_path'] ?? null;
$pagesDir = $data['pages_dir'] ?? null;

if (PHP_SAPI === 'cli') {
    echo "Logs fragment rebuilt (limit {$limit}, scope {$scope}).\n";
    if ($pagesDir) {
        echo "Pages dir: {$pagesDir}\n";
    }
    if ($metaPath) {
        echo "Meta: {$metaPath}\n";
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'limit' => $limit,
        'pages_dir' => $pagesDir,
        'meta' => $metaPath,
        'scope' => $scope,
    ]);
}
