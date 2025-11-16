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

define('WT_STEAM_API_KEY', getenv('STEAM_API_KEY') ?: '7FA5829F77ACA436B3E9826FC7CFF063');

define('WT_STEAM_RETURN_URL', getenv('WT_STEAM_RETURN_URL'));

// Cache TTL for profile lookups (seconds). Increase to 24h to reduce Steam API calls
define('WT_CACHE_TTL', 24 * 3600);
// Avatar images are cached locally for this many seconds
define('WT_AVATAR_CACHE_TTL', 6 * 3600);

// Bump when logs fragment markup changes to force static rebuilds
define('WT_LOGS_FRAGMENT_VERSION', '20251115');

// Secret used to obfuscate web client IP into a short tag; set in env WT_CHAT_IP_SECRET
define('WT_CHAT_IP_SECRET', getenv('WT_CHAT_IP_SECRET') ?: 'vXo8#Q3Lk9m@Zt1DbR2n');

// Default avatars served from this codebase (downloaded from Steam)
define('WT_DEFAULT_AVATAR_URL', '/stats/assets/whaley-avatar.jpg');
define('WT_SECONDARY_AVATAR_URL', '/stats/assets/whaley-avatar-2.jpg');
define('WT_SERVER_AVATAR_URL', '/stats/assets/server-chat-avatar.jpg');
