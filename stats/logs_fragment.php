<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=10');

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : wt_logs_per_page();
$limit = max(1, $limit);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$scope = wt_logs_normalize_scope($_GET['scope'] ?? 'regular');

$data = wt_get_cached_logs($limit, $scope, $page);

if (isset($data['from_cache']) && $data['from_cache']) {
    echo $data['html'];
} else {
    $logs = $data['logs'] ?? [];
    $html = '';
    $index = 0;
    foreach ($logs as $log) {
        $html .= wt_render_single_log($log, $index);
        $index++;
    }
    
    $cacheFile = __DIR__ . '/cache/logs_page_' . $page . '_' . $scope . '.html';
    $metaFile = __DIR__ . '/cache/logs_meta.json';
    
    // Ensure cache directory exists
    if (!is_dir(__DIR__ . '/cache')) {
        mkdir(__DIR__ . '/cache', 0777, true);
    }
    
    file_put_contents($cacheFile, $html);
    
    if ($page === 1) {
        $meta = [
            'last_update' => $data['last_update'] ?? time(),
            'total_logs' => $data['total_logs'] ?? count($logs),
            'total_pages' => $data['total_pages'] ?? 1,
        ];
        file_put_contents($metaFile, json_encode($meta));
    }
    
    echo $html;
}
