<div class="tab-content active" id="tab-logs">
    <div class="logs-toolbar">
        <div class="logs-toggle-group">
            <button type="button" class="logs-refresh" id="logs-toggle-small">Show &lt;12 Player Logs</button>
            <button type="button" class="logs-refresh" id="logs-toggle-old">Hide Old</button>
        </div>
        <div class="logs-refresh-group">
            <img src="../nue_transparent.gif" alt="Nue Tewi" class="logs-refresh-gif">
            <button type="button" class="logs-refresh" id="logs-refresh">Refresh Logs</button>
        </div>
    </div>
    <div class="logs-container" id="logs-container">
        <?= $logsHtml ?>
    </div>
    <div class="empty-state" id="logs-empty" style="display:none">No logs available.</div>
</div>
