<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$logsHtml = '';
$logsLimit = 60;
$pageTitle = 'Match Logs · WhaleTracker';
$activePage = 'logs';
$tabRevisionSource = wt_logs_revision();
$tabRevision = $tabRevisionSource ? substr(md5((string)$tabRevisionSource), 0, 6) : null;

include __DIR__ . '/../templates/layout_top.php';
include __DIR__ . '/../templates/tab-logs.php';
?>

<script>
const logsFragmentEndpoint = '/stats/logs_fragment_static.php';
const LOGS_FRAGMENT_LIMIT = <?= (int)$logsLimit ?>;
const logsContainer = document.getElementById('logs-container');
const logsEmpty = document.getElementById('logs-empty');
const logsRefreshButton = document.getElementById('logs-refresh');
const logsToggleOldButton = document.getElementById('logs-toggle-old');
const logsToggleSmallButton = document.getElementById('logs-toggle-small');
let logsInitialized = false;
let showOldLogs = true;
let showSmallLogs = false;
const SMALL_LOG_THRESHOLD = 12;
const OLD_LOG_THRESHOLD_SECONDS = 86400 * 2;

function updateToggleLabels() {
    if (logsToggleOldButton) {
        logsToggleOldButton.textContent = showOldLogs ? 'Hide Old' : 'Show Old';
    }
    if (logsToggleSmallButton) {
        logsToggleSmallButton.textContent = showSmallLogs ? 'Hide <12 Player Logs' : 'Show <12 Player Logs';
    }
}

function applyLogFilters() {
    if (!logsContainer) {
        return;
    }
    const entries = logsContainer.querySelectorAll('.log-entry');
    const now = Math.floor(Date.now() / 1000);
    let visibleCount = 0;
    entries.forEach(entry => {
        const playerCount = Number(entry.dataset.playerCount || 0);
        const startedAt = Number(entry.dataset.startedAt || 0);
        let visible = true;
        if (!showSmallLogs && playerCount > 0 && playerCount < SMALL_LOG_THRESHOLD) {
            visible = false;
        }
        if (visible && !showOldLogs && startedAt > 0) {
            if ((now - startedAt) > OLD_LOG_THRESHOLD_SECONDS) {
                visible = false;
            }
        }
        entry.style.display = visible ? '' : 'none';
        if (visible) {
            visibleCount++;
        }
    });
    if (logsEmpty) {
        logsEmpty.style.display = visibleCount === 0 ? '' : 'none';
        if (visibleCount === 0) {
            logsEmpty.textContent = 'No logs match the current filters.';
        }
    }
}

async function fetchLogs() {
    if (!logsContainer) {
        return;
    }
    try {
        if (logsEmpty) {
            logsEmpty.textContent = 'Loading logs...';
            logsEmpty.style.display = '';
        }
        const response = await fetch(`${logsFragmentEndpoint}?limit=${encodeURIComponent(LOGS_FRAGMENT_LIMIT)}&t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Failed request');
        }
        const html = await response.text();
        logsContainer.innerHTML = html;
        applyLogFilters();
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch logs:', err);
        if (logsEmpty) {
            logsEmpty.textContent = '';
            logsEmpty.style.display = '';
        }
    }
}

function initLogsPage() {
    if (logsInitialized) {
        return;
    }
    logsInitialized = true;
    updateToggleLabels();
    if (logsRefreshButton) {
        logsRefreshButton.addEventListener('click', () => fetchLogs());
    }
    if (logsToggleSmallButton) {
        logsToggleSmallButton.addEventListener('click', () => {
            showSmallLogs = !showSmallLogs;
            updateToggleLabels();
            applyLogFilters();
        });
    }
    if (logsToggleOldButton) {
        logsToggleOldButton.addEventListener('click', () => {
            showOldLogs = !showOldLogs;
            updateToggleLabels();
            applyLogFilters();
        });
    }
    fetchLogs();
}

document.addEventListener('DOMContentLoaded', initLogsPage);
</script>

<?php include __DIR__ . '/../templates/layout_bottom.php'; ?>
