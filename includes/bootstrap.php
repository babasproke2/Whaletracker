<?php
ini_set('session.gc_maxlifetime', 86400);
ini_set("default_socket_timeout", 2);
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../functions.php';

header('Cache-Control: public, max-age=10');

$isMotdEmbed = isset($_GET['motd']) && $_GET['motd'] !== '' && $_GET['motd'] !== '0';

$currentUserId = wt_is_logged_in() ? wt_current_user_id() : null;
$currentUserProfile = null;
$currentUserDisplay = null;
if ($currentUserId !== null) {
    $userProfiles = wt_fetch_steam_profiles([$currentUserId]);
    $currentUserProfile = $userProfiles[$currentUserId] ?? null;
    $currentUserDisplay = [
        'steamid' => $currentUserId,
        'name' => $currentUserProfile['personaname'] ?? $currentUserId,
        'avatar' => $currentUserProfile['avatarfull'] ?? ($currentUserProfile['avatar'] ?? WT_DEFAULT_AVATAR_URL),
    ];
}
