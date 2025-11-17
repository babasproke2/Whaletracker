<?php
include __DIR__ . '/../templates/layout_top.php';
include __DIR__ . '/../templates/tab-logs.php';
?>

<script>
const logsFragmentEndpoint = '/stats/logs_fragment_static.php';
const LOGS_PER_PAGE = <?= (int)$perPage ?>;
const LOGS_FRAGMENT_LIMIT = <?= (int)$fragmentLimit ?>;
const LOG_SCOPE = <?= json_encode($logScope ?? 'regular') ?>;
let currentPage = <?= (int)$page ?>;
const logsContainer = document.getElementById('logs-container');
const logsEmpty = document.getElementById('logs-empty');
const logsRefreshButton = document.getElementById('logs-refresh');
const paginationContainer = document.getElementById('logs-pagination');
let logsInitialized = false;
let totalPages = 1;

function buildPagination() {
    if (!paginationContainer) return;
    paginationContainer.innerHTML = '';
    if (totalPages <= 1) {
        return;
    }
    for (let page = 1; page <= totalPages; page++) {
        const btn = document.createElement('button');
        btn.textContent = page;
        btn.className = 'logs-page-button' + (page === currentPage ? ' active' : '');
        btn.addEventListener('click', () => {
            if (page !== currentPage) {
                currentPage = page;
                fetchLogs();
            }
        });
        paginationContainer.appendChild(btn);
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
        const requestUrl = `${logsFragmentEndpoint}?limit=${encodeURIComponent(LOGS_FRAGMENT_LIMIT)}&per_page=${encodeURIComponent(LOGS_PER_PAGE)}&page=${encodeURIComponent(currentPage)}&scope=${encodeURIComponent(LOG_SCOPE)}&t=${Date.now()}`;
        const response = await fetch(requestUrl, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Failed request');
        }
        const payload = await response.json();
        if (!payload || payload.ok !== true) {
            throw new Error('Invalid fragment payload');
        }
        totalPages = payload.total_pages || 1;
        logsContainer.innerHTML = payload.html || '';
        buildPagination();
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch logs:', err);
        if (logsEmpty) {
            logsEmpty.textContent = 'Failed to load logs.';
            logsEmpty.style.display = '';
        }
    }
}

function initLogsPage() {
    if (logsInitialized) {
        return;
    }
    logsInitialized = true;
    if (logsRefreshButton) {
        logsRefreshButton.addEventListener('click', () => fetchLogs());
    }
    fetchLogs();
}

document.addEventListener('DOMContentLoaded', initLogsPage);
</script>

<?php include __DIR__ . '/../templates/layout_bottom.php'; ?>
