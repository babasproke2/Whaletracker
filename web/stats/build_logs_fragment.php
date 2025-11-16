<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$limit = isset($argv[1]) ? (int)$argv[1] : (int)($_GET['limit'] ?? 60);
$limit = max(1, min($limit, 100));

$data = wt_build_static_logs($limit);

$metaPath = $data['meta_path'] ?? null;
$htmlPath = $data['html_path'] ?? null;

if (PHP_SAPI === 'cli') {
    echo "Logs fragment rebuilt (limit {$limit}).\n";
    if ($htmlPath) {
        echo "HTML: {$htmlPath}\n";
    }
    if ($metaPath) {
        echo "Meta: {$metaPath}\n";
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'limit' => $limit,
        'html' => $htmlPath,
        'meta' => $metaPath,
    ]);
}
