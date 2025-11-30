<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=60');

function wt_cumulative_fragment_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($_GET['perPage'] ?? 50));
$focusedPlayer = $_GET['player'] ?? null;

try {
    $defaultAvatarUrl = WT_DEFAULT_AVATAR_URL;
    $cache = wt_get_cached_cumulative($search, $page, $perPage, $focusedPlayer, $defaultAvatarUrl);
    $response = [
        'ok' => true,
        'html' => $cache['html'] ?? '',
        'page' => (int)($cache['page'] ?? $page),
        'totalRows' => (int)($cache['totalRows'] ?? 0),
        'totalPages' => (int)($cache['totalPages'] ?? 1),
        'prevPageUrl' => $cache['prevPageUrl'] ?? null,
        'nextPageUrl' => $cache['nextPageUrl'] ?? null,
    ];
    wt_cumulative_fragment_json($response);
} catch (Throwable $e) {
    error_log('[WhaleTracker] cumulative fragment error: ' . $e->getMessage());
    wt_cumulative_fragment_json(['ok' => false, 'error' => 'server'], 500);
}
