<?php
include __DIR__ . '/../templates/layout_top.php';
include __DIR__ . '/../templates/tab-logs.php';
?>

<script>
const logsFragmentEndpoint = '/stats/logs_fragment_static.php';
const LOGS_PER_PAGE = <?= (int)$perPage ?>;
const LOG_SCOPE = <?= json_encode($logScope ?? 'regular') ?>;
let currentPage = <?= (int)$page ?>;
const logsContainer = document.getElementById('logs-container');
const logsEmpty = document.getElementById('logs-empty');
const logsRefreshButton = document.getElementById('logs-refresh');
const paginationContainer = document.getElementById('logs-pagination');
let totalPages = logsContainer ? Number(logsContainer.dataset.totalPages || '1') : 1;
const initialSource = logsContainer ? (logsContainer.dataset.initialSource || 'none') : 'none';
let logsLoading = false;

function setEmptyState(visible, message) {
    if (!logsEmpty) {
        return;
    }
    logsEmpty.style.display = visible ? '' : 'none';
    if (message) {
        logsEmpty.textContent = message;
    }
}

function updateEmptyStateFromContent() {
    if (!logsContainer) {
        return;
    }
    const hasContent = logsContainer.textContent.trim().length > 0;
    setEmptyState(!hasContent, hasContent ? '' : 'No logs available.');
}

function buildPagination() {
    if (!paginationContainer) {
        return;
    }
    paginationContainer.innerHTML = '';
    if (totalPages <= 1) {
        return;
    }
    for (let page = 1; page <= totalPages; page++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = page;
        btn.className = 'logs-page-button' + (page === currentPage ? ' active' : '');
        btn.addEventListener('click', () => {
            if (page !== currentPage && !logsLoading) {
                fetchLogs(page);
            }
        });
        paginationContainer.appendChild(btn);
    }
}

async function fetchLogs(targetPage = currentPage) {
    if (!logsContainer) {
        return;
    }
    logsLoading = true;
    setEmptyState(true, 'Loading logs...');
    try {
        const params = new URLSearchParams({
            page: String(targetPage),
            per_page: String(LOGS_PER_PAGE),
            scope: LOG_SCOPE,
            t: String(Date.now())
        });
        const requestUrl = `${logsFragmentEndpoint}?${params.toString()}`;
        const response = await fetch(requestUrl, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Failed request');
        }
        const payload = await response.json();
        if (!payload || payload.ok !== true) {
            throw new Error('Invalid fragment payload');
        }
        logsContainer.innerHTML = payload.html || '';
        currentPage = Number(payload.page) || targetPage;
        totalPages = Number(payload.total_pages) || 1;
        if (logsContainer.dataset) {
            logsContainer.dataset.currentPage = String(currentPage);
            logsContainer.dataset.totalPages = String(totalPages);
            logsContainer.dataset.totalLogs = String(payload.total_logs || 0);
            logsContainer.dataset.initialSource = payload.from_cache ? 'cache' : 'live';
        }
        updateEmptyStateFromContent();
        buildPagination();
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch logs:', err);
        setEmptyState(true, 'Failed to load logs.');
    } finally {
        logsLoading = false;
    }
}

function initLogsPage() {
    if (!logsContainer) {
        return;
    }
    buildPagination();
    updateEmptyStateFromContent();
    if (initialSource === 'none') {
        fetchLogs(currentPage);
    }
    if (logsRefreshButton) {
        logsRefreshButton.addEventListener('click', () => fetchLogs(currentPage));
    }
}

document.addEventListener('DOMContentLoaded', initLogsPage);
</script>

<?php include __DIR__ . '/../templates/layout_bottom.php'; ?>
