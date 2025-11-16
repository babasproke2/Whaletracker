<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$limit = max(1, min((int)($_GET['limit'] ?? 60), 100));
$paths = wt_static_logs_paths($limit);
$needBuild = true;
$currentRevision = wt_logs_revision();
$currentVersion = defined('WT_LOGS_FRAGMENT_VERSION') ? WT_LOGS_FRAGMENT_VERSION : '0';

if (is_file($paths['html']) && is_file($paths['meta'])) {
    $meta = json_decode(file_get_contents($paths['meta']), true);
    if (is_array($meta)
        && ($meta['revision'] ?? null) === $currentRevision
        && ($meta['template_version'] ?? '0') === $currentVersion) {
        $needBuild = false;
    }
}

if ($needBuild) {
    wt_build_static_logs($limit);
}

if (!is_file($paths['html'])) {
    http_response_code(503);
    echo 'Logs fragment unavailable';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile($paths['html']);
