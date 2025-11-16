<?php
$pageTitle = $pageTitle ?? 'The Youkai Pound · WhaleTracker';
$activePage = $activePage ?? 'cumulative';
$navOnlineCount = $navOnlineCount ?? '0 / 32';
$tabRevision = $tabRevision ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="/stats/css/whaletracker.css">
    <?php if (!empty($isMotdEmbed)): ?>
    <style>
        .header {
            display: none !important;
        }
        .page {
            padding-top: 1.5rem;
        }
    </style>
    <?php endif; ?>
</head>
<body<?= !empty($isMotdEmbed) ? ' class="motd-embed"' : '' ?>>
<div class="page">
    <header class="header">
        <div class="brand">
            <img src="/stats/whaletracker_logo.png" style="width:50%" alt="WhaleTracker logo">
        </div>
        <div class="animation">
            <img src="/stats/wholesome2.gif" alt="Wholesome">
        </div>
        <div class="header-actions">
            <?php if (WT_STEAM_API_KEY === ''): ?>
                <small class="muted">Set <code>STEAM_API_KEY</code> to enable avatars and names.</small>
            <?php endif; ?>
            <?php if (!empty($currentUserDisplay)): ?>
                <div class="header-user">
                    <img class="header-user-avatar" src="<?= htmlspecialchars($currentUserDisplay['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <div class="header-user-meta">
                        <div class="header-user-name"><?= htmlspecialchars($currentUserDisplay['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <a class="steam-logout" href="/stats/steam_login.php?action=logout" title="Sign out of WhaleTracker">Sign out</a>
                    </div>
                </div>
            <?php else: ?>
                <a class="steam-login" href="/stats/steam_login.php?action=login">Sign in through Steam</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="tabs">
        <div class="tab-controls">
            <a class="tab-button <?= $activePage === 'cumulative' ? 'active' : '' ?>" href="/stats/">
                <span class="tab-button-label tab-button-label--desktop">All Time Stats</span>
                <span class="tab-button-label tab-button-label--mobile">Stats</span>
            </a>
            <a class="tab-button <?= $activePage === 'online' ? 'active' : '' ?>" href="/stats/online/">
                <span class="tab-button-label tab-button-label--desktop">Online Now</span>
                <span class="tab-button-label tab-button-label--mobile">Online</span>
                <span class="tab-button-count" id="nav-online-count" aria-live="polite"><?= htmlspecialchars($navOnlineCount, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <a class="tab-button <?= $activePage === 'logs' ? 'active' : '' ?>" href="/stats/logs/">
                <span class="tab-button-label tab-button-label--desktop">Match Logs</span>
                <span class="tab-button-label tab-button-label--mobile">Logs</span>
            </a>
            <a class="tab-button <?= $activePage === 'chat' ? 'active' : '' ?>" href="/stats/chat/">
                <span class="tab-button-label tab-button-label--desktop">Live Chat</span>
                <span class="tab-button-label tab-button-label--mobile">Chat</span>
            </a>
            <?php if (!empty($tabRevision)): ?>
                <span class="tab-hash" title="Build Hash">#<?= htmlspecialchars($tabRevision, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
