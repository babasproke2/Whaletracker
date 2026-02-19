<?php
/**
 * WhaleTracker web configuration.
 *
 * Copy your desired values here if they differ from the defaults.
 */

define('WT_DB_HOST', getenv('WT_DB_HOST') ?: '127.0.0.1');
define('WT_DB_NAME', getenv('WT_DB_NAME') ?: 'sourcemod');
define('WT_DB_USER', getenv('WT_DB_USER') ?: 'tf2server');
define('WT_DB_PASS', getenv('WT_DB_PASS') ?: 'jamkat22');
define('WT_DB_TABLE', getenv('WT_DB_TABLE') ?: 'whaletracker');
define('WT_DB_MAP_TABLE', getenv('WT_DB_MAP_TABLE') ?: 'whaletracker_mapstats');

define('WT_STEAM_API_KEY', getenv('STEAM_API_KEY') ?: '');

define('WT_STEAM_RETURN_URL', getenv('WT_STEAM_RETURN_URL'));

// Cache TTL for profile lookups (seconds). Increase to 24h to reduce Steam API calls
define('WT_CACHE_TTL', 24 * 3600);
// Avatar images are cached locally for this many seconds
define('WT_AVATAR_CACHE_TTL', 6 * 3600);

// Bump when logs fragment markup changes to force static rebuilds
define('WT_LOGS_FRAGMENT_VERSION', '20251115');

// Secret used to obfuscate web client IP into a short tag; set in env WT_CHAT_IP_SECRET
define('WT_CHAT_IP_SECRET', getenv('WT_CHAT_IP_SECRET') ?: '');

// Logs caching + pagination controls
define('WT_LOGS_TOTAL_LIMIT', (int)(getenv('WT_LOGS_TOTAL_LIMIT') ?: 50));
define('WT_LOGS_PAGE_SIZE', (int)(getenv('WT_LOGS_PAGE_SIZE') ?: 25));
define('WT_LOGS_MAX_PAGES', (int)(getenv('WT_LOGS_MAX_PAGES') ?: 2));
define('WT_LOGS_HISTORY_LIMIT', (int)(getenv('WT_LOGS_HISTORY_LIMIT') ?: 50));
define('WT_STATS_MIN_PLAYTIME_SORT', (int)(getenv('WT_STATS_MIN_PLAYTIME_SORT') ?: (4 * 3600)));
$autoRefreshEnv = getenv('WT_LOGS_AUTO_REFRESH');
$autoRefresh = false;
if ($autoRefreshEnv !== false && $autoRefreshEnv !== '') {
    $autoRefresh = in_array(strtolower($autoRefreshEnv), ['1', 'true', 'yes', 'on'], true);
}
define('WT_LOGS_AUTO_REFRESH', $autoRefresh);
define('WT_LOGS_REFRESH_INTERVAL', (int)(getenv('WT_LOGS_REFRESH_INTERVAL') ?: 120));

// Base URL for TF2 class icons used across the UI
define('WT_CLASS_ICON_BASE', getenv('WT_CLASS_ICON_BASE') ?: '/leaderboard/');

// Default avatars served from this codebase (downloaded from Steam)
define('WT_DEFAULT_AVATAR_URL', '/stats/assets/whaley-avatar.jpg');
define('WT_SECONDARY_AVATAR_URL', '/stats/assets/whaley-avatar-2.jpg');
define('WT_SERVER_AVATAR_URL', '/stats/assets/server-chat-avatar.jpg');
