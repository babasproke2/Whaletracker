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

define('WT_CACHE_TTL', 3600);
