<?php
$initialLogsHtml = $logsHtml ?? '';
$logsToggleSmallUrl = $logsToggleSmallUrl ?? null;
$logsToggleSmallLabel = $logsToggleSmallLabel ?? 'Show <12 Player Logs';
?>
<div class="tab-content active" id="tab-logs">
    <div class="logs-toolbar">
        <div class="logs-toggle-group">
            <?php if (!empty($logsToggleSmallUrl)): ?>
                <a class="logs-refresh" id="logs-toggle-small" href="<?= htmlspecialchars($logsToggleSmallUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($logsToggleSmallLabel, ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </div>
        <div class="logs-refresh-group">
            <img src="../nue_transparent.gif" alt="Nue Tewi" class="logs-refresh-gif">
            <button type="button" class="logs-refresh" id="logs-refresh">Refresh Logs</button>
        </div>
    </div>
    <div class="logs-container" id="logs-container" data-scope="<?= htmlspecialchars($logScope ?? 'regular', ENT_QUOTES, 'UTF-8') ?>">
        <?= $initialLogsHtml ?>
    </div>
    <div class="empty-state" id="logs-empty" style="display:none">No logs available.</div>
    <div class="logs-pagination" id="logs-pagination"></div>
</div>
