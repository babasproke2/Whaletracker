<?php
$initialLogsHtml = $logsHtml ?? '';
$logsInitialSource = $logsInitialSource ?? 'none';
$logsTotalPages = isset($logsTotalPages) ? (int)$logsTotalPages : 1;
$logsTotalLogs = isset($logsTotalLogs) ? (int)$logsTotalLogs : 0;
$currentLogsPage = isset($currentLogsPage) ? (int)$currentLogsPage : (int)($page ?? 1);
$logsPerPageAttr = isset($perPage) ? (int)$perPage : wt_logs_per_page();
$logScopeAttr = $logScope ?? 'regular';
?>
<div class="tab-content active" id="tab-logs">
    <div class="logs-toolbar">
        <div class="logs-toggle-group">
        </div>
        <div class="logs-refresh-group">
            <img src="../nue_transparent.gif" alt="Nue Tewi" class="logs-refresh-gif">
            <button type="button" class="logs-refresh" id="logs-refresh">Refresh Logs</button>
        </div>
    </div>
    <div
        class="logs-container"
        id="logs-container"
        data-scope="<?= htmlspecialchars($logScopeAttr, ENT_QUOTES, 'UTF-8') ?>"
        data-fragment="/stats/logs_fragment_static.php"
        data-initial-source="<?= htmlspecialchars($logsInitialSource, ENT_QUOTES, 'UTF-8') ?>"
        data-total-pages="<?= $logsTotalPages ?>"
        data-total-logs="<?= $logsTotalLogs ?>"
        data-current-page="<?= $currentLogsPage ?>"
        data-per-page="<?= $logsPerPageAttr ?>"
    >
        <?= $initialLogsHtml ?>
    </div>
    <div class="empty-state" id="logs-empty" style="display:none">No logs available.</div>
    <div class="logs-pagination" id="logs-pagination"></div>
</div>
