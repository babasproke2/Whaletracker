<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

try {
    $logs = wt_fetch_logs(1);
    $html = '';
    ob_start();
    include __DIR__ . '/templates/current_log_fragment.php';
    $html = ob_get_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Failed to load current log.';
}
