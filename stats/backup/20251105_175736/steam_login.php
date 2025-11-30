<?php

session_start();

require_once __DIR__ . '/../sb/includes/auth/openid.php';
require_once __DIR__ . '/functions.php';

$action = $_GET['action'] ?? 'login';

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . wt_base_url() . '/index.php');
    exit;
}

$returnUrl = WT_STEAM_RETURN_URL ?: wt_base_url() . '/steam_login.php';
$realm = dirname($returnUrl);

$openid = new LightOpenID(parse_url($returnUrl, PHP_URL_HOST));

if (!$openid->mode) {
    $openid->identity = 'https://steamcommunity.com/openid';
    $openid->returnUrl = $returnUrl;
    $openid->realm = $realm;
    header('Location: ' . $openid->authUrl());
    exit;
}

if ($openid->mode === 'cancel') {
    header('Location: ' . wt_base_url() . '/index.php?login=cancelled');
    exit;
}

if ($openid->validate()) {
    $identity = $openid->identity;
    if (preg_match('#https://steamcommunity.com/openid/id/(\d+)#', $identity, $matches)) {
        $_SESSION['steamid'] = $matches[1];
    }
}

header('Location: ' . wt_base_url() . '/index.php');
exit;
