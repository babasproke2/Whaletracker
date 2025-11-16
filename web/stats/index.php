<?php
require_once __DIR__ . '/includes/bootstrap.php';

$search = trim($_GET['q'] ?? '');
$focusedPlayer = $_GET['player'] ?? null;
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$summaryCache = wt_get_cached_summary();
$summary = $summaryCache['summary'] ?? [];
$performanceAverages = $summaryCache['performanceAverages'] ?? [];
$summaryHtml = $summaryCache['html'] ?? '';

$defaultAvatarUrl = WT_DEFAULT_AVATAR_URL;

$cumulativeCache = wt_get_cached_cumulative($search, $page, $perPage, $focusedPlayer, $defaultAvatarUrl);
$page = (int)($cumulativeCache['page'] ?? $page);
$pageStats = $cumulativeCache['pageStats'] ?? [];
$totalRows = (int)($cumulativeCache['totalRows'] ?? 0);
$totalPages = (int)($cumulativeCache['totalPages'] ?? max(1, ceil(max($totalRows, 1) / $perPage)));
$prevPageUrl = $cumulativeCache['prevPageUrl'] ?? null;
$nextPageUrl = $cumulativeCache['nextPageUrl'] ?? null;
$cumulativeHtml = $cumulativeCache['html'] ?? '';

if ($focusedPlayer === null && wt_is_logged_in()) {
    $focusedPlayer = wt_current_user_id();
}

$focused = null;
if ($focusedPlayer !== null) {
    foreach ($pageStats as $row) {
        if (($row['steamid'] ?? '') === (string)$focusedPlayer) {
            $focused = $row;
            break;
        }
    }
    if ($focused === null) {
        $focused = wt_fetch_player($focusedPlayer);
    }
}

$pageTitle = 'The Youkai Pound · WhaleTracker';
$activePage = 'cumulative';
$tabRevision = isset($cumulativeCache['revision']) ? substr(md5((string)$cumulativeCache['revision']), 0, 6) : null;

include __DIR__ . '/templates/layout_top.php';

include __DIR__ . '/templates/tab-cumulative.php';
?>

<script>
const cumulativeFragmentEndpoint = '/stats/cumulative_fragment.php';

function attachSortingToTable(table) {
    if (!table || table.dataset.sortBound === '1') {
        return;
    }
    const tbody = table.querySelector('tbody');
    if (!tbody) {
        return;
    }
    const headers = table.querySelectorAll('th[data-key]');
    const sortState = { key: null, direction: 1 };
    headers.forEach(header => {
        header.addEventListener('click', () => {
            const key = header.dataset.key;
            const type = header.dataset.type || 'text';
            if (sortState.key === key) {
                sortState.direction *= -1;
            } else {
                sortState.key = key;
                sortState.direction = 1;
            }
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                let av = a.dataset[key] ?? '';
                let bv = b.dataset[key] ?? '';
                if (type === 'number') {
                    av = Number(av);
                    bv = Number(bv);
                }
                if (av < bv) return -1 * sortState.direction;
                if (av > bv) return 1 * sortState.direction;
                return 0;
            });
            rows.forEach(row => tbody.appendChild(row));
            headers.forEach(h => h.classList.remove('sorted-asc', 'sorted-desc'));
            header.classList.add(sortState.direction === 1 ? 'sorted-asc' : 'sorted-desc');
        });
    });
    table.dataset.sortBound = '1';
}

function initSorting() {
    document.querySelectorAll('.stats-table').forEach(table => attachSortingToTable(table));
}

let cumulativeLoading = false;
function initCumulativeFragment() {
    const container = document.getElementById('cumulative-fragment');
    if (!container || cumulativeLoading) {
        return;
    }
    container.addEventListener('click', event => {
        const link = event.target.closest('.table-pagination-button[href]');
        if (!link) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') {
            return;
        }
        event.preventDefault();
        loadCumulativePage(link.getAttribute('href'));
    });
}

async function loadCumulativePage(targetUrl) {
    const container = document.getElementById('cumulative-fragment');
    if (!container || cumulativeLoading || !targetUrl) {
        return;
    }
    cumulativeLoading = true;
    container.classList.add('is-loading');
    try {
        const linkUrl = new URL(targetUrl, window.location.href);
        const fetchUrl = new URL(cumulativeFragmentEndpoint, window.location.href);
        const perPage = container.dataset.perPage || '50';
        linkUrl.searchParams.forEach((value, key) => {
            if (value !== null) {
                fetchUrl.searchParams.set(key, value);
            }
        });
        if (!fetchUrl.searchParams.has('page')) {
            fetchUrl.searchParams.set('page', linkUrl.searchParams.get('page') || '1');
        }
        fetchUrl.searchParams.set('perPage', perPage);
        const response = await fetch(fetchUrl.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error('Failed to fetch cumulative fragment');
        }
        const payload = await response.json();
        if (!payload || payload.ok !== true) {
            throw new Error('Invalid fragment payload');
        }
        container.innerHTML = payload.html || '';
        const cumulativeTable = container.querySelector('#stats-table-cumulative');
        if (cumulativeTable) {
            attachSortingToTable(cumulativeTable);
        }
        const finalUrl = linkUrl.pathname + linkUrl.search;
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', finalUrl);
        }
    } catch (err) {
        console.error('[WhaleTracker] Failed to load cumulative page', err);
        window.location.href = targetUrl;
    } finally {
        cumulativeLoading = false;
        container.classList.remove('is-loading');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initSorting();
    initCumulativeFragment();
});
</script>

<?php include __DIR__ . '/templates/layout_bottom.php'; ?>
