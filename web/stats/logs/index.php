<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$perPage = defined('WT_LOGS_PAGE_SIZE') ? max(1, (int)WT_LOGS_PAGE_SIZE) : 15;
$fragmentLimit = defined('WT_LOGS_TOTAL_LIMIT') ? max($perPage, (int)WT_LOGS_TOTAL_LIMIT) : max($perPage, 60);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageTitle = 'Match Logs · WhaleTracker';
$activePage = 'logs';
$tabRevisionSource = wt_logs_revision();
$tabRevision = $tabRevisionSource ? substr(md5((string)$tabRevisionSource), 0, 6) : null;
$logScope = 'regular';
$logsToggleSmallUrl = '/stats/logs/short/';
$logsToggleSmallLabel = 'Show <12 Player Logs';

require __DIR__ . '/page.php';
