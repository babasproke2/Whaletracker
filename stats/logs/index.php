<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$perPage = wt_logs_per_page();
$fragmentLimit = defined('WT_LOGS_TOTAL_LIMIT') ? max($perPage, (int)WT_LOGS_TOTAL_LIMIT) : max($perPage, 60);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageTitle = 'Match Logs · WhaleTracker';
$activePage = 'logs';
$tabRevisionSource = wt_logs_revision();
$tabRevision = $tabRevisionSource ? substr(md5((string)$tabRevisionSource), 0, 6) : null;
$logScope = 'regular';

$logsDataset = wt_get_cached_logs($perPage, $logScope, $page);
$logsHtml = '';
$logsInitialSource = 'none';
$logsTotalPages = (int)($logsDataset['total_pages'] ?? 1);
$logsTotalLogs = (int)($logsDataset['total_logs'] ?? 0);
$currentLogsPage = (int)($logsDataset['page'] ?? $page);
$page = $currentLogsPage;

if (!empty($logsDataset['from_cache'])) {
    $logsHtml = (string)($logsDataset['html'] ?? '');
    $logsInitialSource = $logsHtml !== '' ? 'cache' : 'none';
} else {
    $logsData = $logsDataset['logs'] ?? [];
    $logsHtml = wt_render_logs_fragment(['logs' => $logsData]);
    if ($page === 1) {
        wt_logs_cache_store(
            $logScope,
            $logsHtml,
            (int)($logsDataset['last_update'] ?? time()),
            $logsTotalLogs > 0 ? $logsTotalLogs : count($logsData)
        );
        $logsInitialSource = $logsHtml !== '' ? 'live' : 'none';
    } else {
        $logsInitialSource = $logsHtml !== '' ? 'live' : 'none';
    }
}

require __DIR__ . '/page.php';
