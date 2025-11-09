<?php

session_start();

require_once __DIR__ . '/functions.php';

$search = trim($_GET['q'] ?? '');
$initialTab = 'tab-cumulative';
$qLower = strtolower($search);
if ($qLower === 'logs') {
    $initialTab = 'tab-logs';
    $search = '';
} elseif ($qLower === 'online') {
    $initialTab = 'tab-online';
    $search = '';
}
$focusedPlayer = $_GET['player'] ?? null;

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$summary = wt_fetch_summary_stats();

$paginated = wt_fetch_stats_paginated($search, $perPage, $offset);
$pageStats = $paginated['rows'];
$totalRows = (int)($paginated['total'] ?? 0);

if ($page > 1 && empty($pageStats) && $totalRows > 0) {
    $page = (int)max(1, ceil($totalRows / $perPage));
    $offset = ($page - 1) * $perPage;
    $paginated = wt_fetch_stats_paginated($search, $perPage, $offset);
    $pageStats = $paginated['rows'];
    $totalRows = (int)($paginated['total'] ?? 0);
}

$totalPages = (int)max(1, ceil($totalRows / $perPage));
$prevPage = $page > 1 ? $page - 1 : null;
$nextPage = $page < $totalPages ? $page + 1 : null;
$prevPageUrl = $prevPage !== null ? wt_build_page_url($prevPage) : null;
$nextPageUrl = $nextPage !== null ? wt_build_page_url($nextPage) : null;

if ($focusedPlayer === null && wt_is_logged_in()) {
    $focusedPlayer = wt_current_user_id();
}

$focused = null;
if ($focusedPlayer !== null) {
    $focused = wt_player_from_list($pageStats, $focusedPlayer);
    if ($focused === null) {
        $focused = wt_fetch_player($focusedPlayer);
    }
}

$summary = array_merge($summary, wt_summary_insights());
$performanceAverages = wt_fetch_performance_averages();

$baseUrl = wt_base_url();

$currentUserId = wt_is_logged_in() ? wt_current_user_id() : null;
$currentUserProfile = null;
if ($currentUserId !== null) {
    $userProfiles = wt_fetch_steam_profiles([$currentUserId]);
    $currentUserProfile = $userProfiles[$currentUserId] ?? null;
}

$defaultAvatarUrl = 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png';
$currentUserDisplay = null;
if ($currentUserId !== null) {
    $currentUserDisplay = [
        'steamid' => $currentUserId,
        'name' => $currentUserProfile['personaname'] ?? $currentUserId,
        'avatar' => $currentUserProfile['avatarfull'] ?? ($currentUserProfile['avatar'] ?? $defaultAvatarUrl),
    ];
}

$lookupStats = $pageStats;
if ($focused !== null) {
    $focusedSteamId = (string)($focused['steamid'] ?? '');
    if ($focusedSteamId !== '') {
        $exists = false;
        foreach ($lookupStats as $row) {
            if (($row['steamid'] ?? '') === $focusedSteamId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $lookupStats[] = $focused;
        }
    }
}

$allStatsLookup = [];
foreach ($lookupStats as $row) {
    $steamId = (string)($row['steamid'] ?? '');
    if ($steamId === '') {
        continue;
    }
    $allStatsLookup[$steamId] = [
        'steamid' => $steamId,
        'personaname' => $row['personaname'] ?? $steamId,
        'profileurl' => $row['profileurl'] ?? null,
        'avatar' => $row['avatar'] ?? $defaultAvatarUrl,
        'avatarfull' => $row['avatar'] ?? $defaultAvatarUrl,
        'is_admin' => !empty($row['is_admin']),
    ];
}

if ($currentUserDisplay !== null) {
    $steamId = (string)($currentUserDisplay['steamid'] ?? '');
    if ($steamId !== '' && !isset($allStatsLookup[$steamId])) {
        $allStatsLookup[$steamId] = [
            'steamid' => $steamId,
            'personaname' => $currentUserDisplay['name'] ?? $steamId,
            'profileurl' => $currentUserProfile['profileurl'] ?? null,
            'avatar' => $currentUserDisplay['avatar'] ?? $defaultAvatarUrl,
            'avatarfull' => $currentUserDisplay['avatar'] ?? $defaultAvatarUrl,
            'is_admin' => !empty($currentUserProfile['is_admin']),
        ];
    }
}

$isMotdEmbed = isset($_GET['motd']) && $_GET['motd'] !== '' && $_GET['motd'] !== '0';

function wt_render_cumulative_rows(array $rows, ?string $focusedSteamId): void
{
    foreach ($rows as $row) {
        $steamId = (string)($row['steamid'] ?? '');
        $personaname = (string)($row['personaname'] ?? $steamId);
        $avatar = (string)($row['avatar'] ?? '');
        $profileUrl = $row['profileurl'] ?? null;
        $kills = (int)($row['kills'] ?? 0);
        $deaths = (int)($row['deaths'] ?? 0);
        $assists = (int)($row['assists'] ?? 0);
        $healing = (int)($row['healing'] ?? 0);
        $headshots = (int)($row['headshots'] ?? 0);
        $backstabs = (int)($row['backstabs'] ?? 0);
        $bestStreak = (int)($row['best_killstreak'] ?? 0);
        $playtimeSeconds = (int)($row['playtime'] ?? 0);
        $totalDamage = (int)($row['damage_dealt'] ?? 0);
        $damageTaken = (int)($row['damage_taken'] ?? 0);
        $airshots = (int)($row['airshots'] ?? 0);
        $drops = (int)($row['medic_drops'] ?? 0);
        $dropped = (int)($row['uber_drops'] ?? $drops);
        $totalShots = isset($row['shots']) ? (int)$row['shots'] : 0;
        $totalHits = isset($row['hits']) ? (int)$row['hits'] : 0;
        $minutesPlayed = $playtimeSeconds > 0 ? ($playtimeSeconds / 60.0) : 0.0;
        $dpm = $minutesPlayed > 0 ? $totalDamage / $minutesPlayed : 0.0;
        $dtpm = $minutesPlayed > 0 ? $damageTaken / $minutesPlayed : 0.0;
        $score = $kills + $assists;
        $kd = $deaths > 0 ? $kills / $deaths : $kills;
        $isOnline = !empty($row['is_online']);
        $isAdmin = !empty($row['is_admin']);
        $playtimeHuman = (string)($row['playtime_human'] ?? wt_format_playtime($playtimeSeconds));

        $overallAccuracy = isset($row['accuracy_overall']) ? (float)$row['accuracy_overall'] : ($totalShots > 0 ? ($totalHits / max($totalShots, 1) * 100.0) : 0.0);
        $classAccuracies = is_array($row['accuracy_classes'] ?? null) ? $row['accuracy_classes'] : [];
        $topClassData = is_array($row['accuracy_top_class'] ?? null) ? $row['accuracy_top_class'] : null;

        $topAccuracyValue = null;
        $topAccuracyShots = 0;
        $topAccuracyHits = 0;
        $topAccuracyLabel = null;
        $topAccuracyIcon = null;
        $topAccuracyClassId = 0;

        if (is_array($topClassData) && isset($topClassData['accuracy']) && $topClassData['accuracy'] !== null) {
            $topAccuracyValue = (float)$topClassData['accuracy'];
            $topAccuracyShots = (int)($topClassData['shots'] ?? 0);
            $topAccuracyHits = (int)($topClassData['hits'] ?? 0);
            $topAccuracyLabel = $topClassData['label'] ?? null;
            $topAccuracyIcon = $topClassData['icon'] ?? null;
            $topAccuracyClassId = (int)($topClassData['class_id'] ?? 0);
        } elseif ($totalShots > 0) {
            $topAccuracyValue = $overallAccuracy;
            $topAccuracyShots = $totalShots;
            $topAccuracyHits = $totalHits;
            $topAccuracyLabel = 'Overall';
        }

        $favoriteClassId = isset($row['favorite_class']) ? (int)$row['favorite_class'] : 0;
        $accuracyTooltipParts = [];
        $favoriteTooltip = null;
        foreach (WT_CLASS_METADATA as $classId => $meta) {
            $classData = $classAccuracies[$classId] ?? null;
            $classShots = isset($classData['shots']) ? (int)$classData['shots'] : 0;
            $classHits = isset($classData['hits']) ? (int)$classData['hits'] : 0;
            $classAccuracyValue = $classData['accuracy'] ?? null;

            $tooltipSegment = null;
            if ($classShots > 0 && $classAccuracyValue !== null) {
                $tooltipSegment = sprintf(
                    '%s: %s (%d/%d)',
                    $meta['label'],
                    number_format((float)$classAccuracyValue, 1) . '%',
                    $classHits,
                    $classShots
                );
            } elseif ($classId === $favoriteClassId) {
                $tooltipSegment = sprintf(
                    '%s: %s',
                    $meta['label'],
                    'No shots recorded'
                );
            }

            if ($tooltipSegment === null) {
                continue;
            }

            if ($classId === $favoriteClassId) {
                $favoriteTooltip = $tooltipSegment;
            } else {
                $accuracyTooltipParts[] = $tooltipSegment;
            }
        }

        if ($favoriteTooltip !== null) {
            array_unshift($accuracyTooltipParts, $favoriteTooltip);
        }

        if (empty($accuracyTooltipParts)) {
            if ($totalShots > 0) {
                $accuracyTooltipParts[] = sprintf(
                    'Overall: %s (%d/%d)',
                    number_format($overallAccuracy, 1) . '%',
                    $totalHits,
                    $totalShots
                );
            } else {
                $accuracyTooltipParts[] = 'No shots recorded';
            }
        }
        $topWeaponAccuracies = is_array($row['top_weapon_accuracy'] ?? null) ? $row['top_weapon_accuracy'] : [];
        $weaponTooltipParts = [];
        foreach ($topWeaponAccuracies as $weaponData) {
            if (!is_array($weaponData)) {
                continue;
            }
            $weaponName = $weaponData['name'] ?? 'Unknown';
            $weaponShots = isset($weaponData['shots']) ? (int)$weaponData['shots'] : 0;
            $weaponHits = isset($weaponData['hits']) ? (int)$weaponData['hits'] : 0;
            $weaponAccuracy = isset($weaponData['accuracy']) ? (float)$weaponData['accuracy'] : null;
            if ($weaponAccuracy === null || $weaponShots <= 0) {
                continue;
            }
            $weaponTooltipParts[] = sprintf(
                '%s: %s (%d/%d)',
                $weaponName,
                number_format($weaponAccuracy, 1) . '%',
                $weaponHits,
                $weaponShots
            );
        }

        if (!empty($weaponTooltipParts)) {
            $accuracyTooltipParts = array_merge($accuracyTooltipParts, $weaponTooltipParts);
        }

        $accuracyTooltip = implode(' • ', $accuracyTooltipParts);

        $rowClasses = [];
        if ($isOnline) {
            $rowClasses[] = 'online-player';
        }
        if ($focusedSteamId !== null && $steamId === $focusedSteamId) {
            $rowClasses[] = 'player-highlight';
        }
        $rowClassAttr = $rowClasses ? ' class="' . htmlspecialchars(implode(' ', $rowClasses), ENT_QUOTES, 'UTF-8') . '"' : '';

        $dataAttributes = [
            'player' => strtolower($personaname),
            'kills' => (string)$kills,
            'assists' => (string)$assists,
            'deaths' => (string)$deaths,
            'damage' => (string)$totalDamage,
            'damage_taken' => (string)$damageTaken,
            'dpm' => sprintf('%.4f', $dpm),
            'dtpm' => sprintf('%.4f', $dtpm),
            'accuracy' => sprintf('%.4f', $overallAccuracy),
            'accuracy_class' => (string)$topAccuracyClassId,
            'airshots' => (string)$airshots,
            'drops' => (string)$drops,
            'dropped' => (string)$dropped,
            'kd' => sprintf('%.4f', $kd),
            'healing' => (string)$healing,
            'headshots' => (string)$headshots,
            'backstabs' => (string)$backstabs,
            'streak' => (string)$bestStreak,
            'playtime' => (string)$playtimeSeconds,
            'score' => (string)$score,
            'online' => $isOnline ? '1' : '0',
        ];

        $dataAttrString = '';
        foreach ($dataAttributes as $key => $value) {
            $dataAttrString .= sprintf(
                ' data-%s="%s"',
                htmlspecialchars($key, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
            );
        }

        $nameClasses = [];
        if ($isAdmin) {
            $nameClasses[] = 'admin-name';
        }
        if ($isOnline) {
            $nameClasses[] = 'online-name';
        }
        $nameClassAttr = $nameClasses ? ' class="' . htmlspecialchars(implode(' ', $nameClasses), ENT_QUOTES, 'UTF-8') . '"' : '';

        $nameTitle = 'Player';
        if ($isOnline && $isAdmin) {
            $nameTitle = 'Connected Admin';
        } elseif ($isOnline) {
            $nameTitle = 'Connected Player';
        } elseif ($isAdmin) {
            $nameTitle = 'Admin';
        }
        $nameTitleAttr = ' title="' . htmlspecialchars($nameTitle, ENT_QUOTES, 'UTF-8') . '"';

        echo '<tr', $rowClassAttr, $dataAttrString, '>';
        echo '<td class="player-cell">';
        echo '<img class="player-avatar" src="', htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'), '" alt="" onerror="this.onerror=null;this.src=\'', htmlspecialchars($defaultAvatarUrl, ENT_QUOTES, 'UTF-8'), '\'">';
        echo '<div>';
        if (!empty($profileUrl)) {
            echo '<a', $nameClassAttr, $nameTitleAttr, ' href="', htmlspecialchars((string)$profileUrl, ENT_QUOTES, 'UTF-8'), '" target="_blank" rel="noopener">', htmlspecialchars($personaname, ENT_QUOTES, 'UTF-8'), '</a>';
        } else {
            echo '<span', $nameClassAttr, $nameTitleAttr, '>', htmlspecialchars($personaname, ENT_QUOTES, 'UTF-8'), '</span>';
        }

        echo '</div>';
        echo '</td>';

        echo '<td>', number_format($kills), '|', number_format($deaths), '|', number_format($assists), '</td>';
        echo '<td>', number_format($kd, 2), '</td>';
        echo '<td>', number_format($totalDamage), '</td>';
        echo '<td>', number_format($damageTaken), '</td>';
        echo '<td>', number_format($dpm, 1), '</td>';
        echo '<td>', number_format($dtpm, 1), '</td>';
        $accuracyCellAttr = $accuracyTooltip !== '' ? ' title="' . htmlspecialchars($accuracyTooltip, ENT_QUOTES, 'UTF-8') . '"' : '';
        echo '<td class="stat-accuracy-cell"', $accuracyCellAttr, '>';
        if ($topAccuracyValue !== null) {
            echo '<span class="stat-accuracy-value">', number_format($topAccuracyValue, 1), '%</span>';
            if ($topAccuracyIcon) {
                echo '<img class="stat-accuracy-icon" src="', htmlspecialchars($topAccuracyIcon, ENT_QUOTES, 'UTF-8'), '" alt="', htmlspecialchars($topAccuracyLabel ?? '', ENT_QUOTES, 'UTF-8'), '">';
            }
        } else {
            echo '—';
        }
        echo '</td>';
        echo '<td>', number_format($airshots), '</td>';
        echo '<td>', number_format($drops), ' | ', number_format($dropped), '</td>';
        echo '<td>', number_format($healing), '</td>';
        echo '<td>', number_format($headshots), '</td>';
        echo '<td>', number_format($backstabs), '</td>';
        echo '<td>', number_format($bestStreak), '</td>';
        echo '<td>', htmlspecialchars($playtimeHuman, ENT_QUOTES, 'UTF-8'), '</td>';
        echo '</tr>';
    }
}

if (!function_exists('wt_stat_compare_attr')) {
function wt_stat_compare_attr(bool $enabled, float $value, float $average, bool $higherIsBetter = true): string
{
        if (!$enabled || $average <= 0.0 || !is_finite($average)) {
            return '';
        }

        $diff = (($value - $average) / $average) * 100.0;
        if (!is_finite($diff)) {
            return '';
        }

        $classSuffix = 'stat-compare--neutral';
        if (abs($diff) >= 0.05) {
            $positive = $diff >= 0.0;
            $good = $higherIsBetter ? $positive : !$positive;
        $classSuffix = $good ? 'stat-compare--better' : 'stat-compare--worse';
    }

    $classes = 'stat-compare ' . $classSuffix;
        $title = sprintf('%+.1f%% vs server average', $diff);

        return ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"';
    }
}

function wt_build_page_url(int $page): string
{
    $params = $_GET;
    if ($page <= 1) {
        unset($params['page']);
    } else {
        $params['page'] = $page;
    }

    $query = http_build_query($params);
    return 'index.php' . ($query !== '' ? '?' . $query : '');
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>The Youkai Pound · WhaleTracker</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="css/whaletracker.css">
    <?php if ($isMotdEmbed): ?>
    <style>
        .header {
            display: none !important;
        }
        #logs-toggle-small,
        #logs-toggle-old {
            display: none !important;
        }
        .page {
            padding-top: 1.5rem;
        }
    </style>
    <?php endif; ?>
</head>
<body<?= $isMotdEmbed ? ' class="motd-embed"' : '' ?>>
<div class="page">
    <header class="header">
        <div class="brand">
            <img src="whaletracker_logo.png" style="width:50%" alt="WhaleTracker logo">
        </div>  
        <div class="animation">
            <img src="wholesome2.gif" alt="Wholsome">
        </div>
        <div class="header-actions">
            <?php if (WT_STEAM_API_KEY === ''): ?>
                <small class="muted">Set <code>STEAM_API_KEY</code> to enable avatars and names.</small>
            <?php endif; ?>
            <?php if ($currentUserDisplay !== null): ?>
                <div class="header-user">
                    <img class="header-user-avatar" src="<?= htmlspecialchars($currentUserDisplay['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <div class="header-user-meta">
                        <div class="header-user-name"><?= htmlspecialchars($currentUserDisplay['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <a class="steam-logout" href="steam_login.php?action=logout" title="Sign out of WhaleTracker">Sign out</a>
                    </div>
                </div>
            <?php else: ?>
                <a class="steam-login" href="steam_login.php?action=login">Sign in through Steam</a>
            <?php endif; ?>
        </div>
    </header>

    <?php
    $playtimeMonthLabel = $summary['playtimeMonthLabel'] ?? date('F');
    $playtimeMonthHours = isset($summary['playtimeMonthHours']) ? (float)$summary['playtimeMonthHours'] : 0.0;
    $playersWeekChangePercentRaw = $summary['playersWeekChangePercent'] ?? null;
    $playersWeekTrend = $summary['playersWeekTrend'] ?? 'flat';
    $playersWeekTrend = in_array($playersWeekTrend, ['up', 'down', 'flat'], true) ? $playersWeekTrend : 'flat';
    $playersWeekTrendClass = 'stat-card-trend stat-card-trend--' . $playersWeekTrend;
    if ($playersWeekChangePercentRaw !== null) {
        $playersWeekChangeLabel = sprintf('%+.1f%%', $playersWeekChangePercentRaw);
        $playersWeekChangeTitle = 'Change vs prior 7 days: ' . $playersWeekChangeLabel;
    } else {
        $playersWeekChangeLabel = '—';
        $playersWeekChangeTitle = 'Change vs prior 7 days: not enough data';
    }
    $playersWeekTooltip = htmlspecialchars($playersWeekChangeTitle, ENT_QUOTES, 'UTF-8');
    $playersWeekCurrent = (int)($summary['playersCurrentWeek'] ?? 0);
    $playersMonthCurrent = (int)($summary['playersCurrentMonth'] ?? 0);
    $totalPlayersAllTime = (int)($summary['totalPlayers'] ?? 0);
    $bestKillstreakWeek = (int)($summary['bestKillstreakWeek'] ?? 0);
    $bestKillstreakWeekOwner = $summary['bestKillstreakWeekOwner'] ?? null;
    $bestKillstreakWeekLeaders = $summary['bestKillstreakWeekLeaders'] ?? [];
    $averageDpm = isset($summary['averageDpm']) ? (float)$summary['averageDpm'] : 0.0;
    $weeklyTopDpm = isset($summary['weeklyTopDpm']) ? (float)$summary['weeklyTopDpm'] : 0.0;
    $weeklyTopDpmOwner = $summary['weeklyTopDpmOwner'] ?? null;
    $totalDrops = (int)($summary['totalDrops'] ?? 0);
    $totalUbersUsed = (int)($summary['totalUbersUsed'] ?? 0);
    $gamemodeTop = $summary['gamemodeTop'] ?? [];
    ?>

    <div class="tabs">
        <div class="tab-controls">
            <button type="button" class="tab-button <?= $initialTab === 'tab-cumulative' ? 'active' : '' ?>" data-tab="tab-cumulative">
                <span class="tab-button-label tab-button-label--desktop">All Time Stats</span>
                <span class="tab-button-label tab-button-label--mobile">All Time</span>
            </button>
            <button type="button" class="tab-button <?= $initialTab === 'tab-online' ? 'active' : '' ?>" data-tab="tab-online">
                <span class="tab-button-label tab-button-label--desktop">Online Now</span>
                <span class="tab-button-label tab-button-label--mobile">Online</span>
                <span class="tab-button-count" aria-live="polite">0 / 32</span>
            </button>
            <button type="button" class="tab-button <?= $initialTab === 'tab-logs' ? 'active' : '' ?>" data-tab="tab-logs">
                <span class="tab-button-label tab-button-label--desktop">Match Logs</span>
                <span class="tab-button-label tab-button-label--mobile">Logs</span>
            </button>
        </div>

        <div class="tab-content <?= $initialTab === 'tab-cumulative' ? 'active' : '' ?>" id="tab-cumulative">
            <?php if ($focused): ?>
                <?php
                $focusedDamage = (int)($focused['damage_dealt'] ?? 0);
                $focusedDamageTaken = (int)($focused['damage_taken'] ?? 0);
                $focusedMinutes = ($focused['playtime'] ?? 0) > 0 ? (($focused['playtime'] ?? 0) / 60.0) : 0.0;
                $focusedDpm = $focusedMinutes > 0 ? $focusedDamage / $focusedMinutes : 0.0;
                $focusedDtpm = $focusedMinutes > 0 ? $focusedDamageTaken / $focusedMinutes : 0.0;
                $focusedShots = (int)($focused['shots'] ?? 0);
                $focusedHits = (int)($focused['hits'] ?? 0);
                $focusedAccuracy = $focusedShots > 0 ? ($focusedHits / max($focusedShots, 1) * 100.0) : 0.0;
                $focusedDrops = (int)($focused['medic_drops'] ?? 0);
                $focusedDropped = (int)($focused['uber_drops'] ?? $focusedDrops);
                $comparisonEnabled = wt_is_logged_in() && !empty($performanceAverages['eligible']);
                $kdValue = $focused['deaths'] > 0 ? ($focused['kills'] / max($focused['deaths'], 1)) : ($focused['kills'] ?? 0);
                $kdAverage = (float)($performanceAverages['kd'] ?? 0);
                $damageAttr = wt_stat_compare_attr($comparisonEnabled, (float)$focusedDamage, (float)($performanceAverages['damage'] ?? 0));
                $accuracyAttr = wt_stat_compare_attr($comparisonEnabled, (float)$focusedAccuracy, (float)($performanceAverages['accuracy'] ?? 0));
                $airshotsAttr = wt_stat_compare_attr($comparisonEnabled, (float)($focused['airshots'] ?? 0), (float)($performanceAverages['airshots'] ?? 0));
                $healingAttr = wt_stat_compare_attr($comparisonEnabled, (float)($focused['healing'] ?? 0), (float)($performanceAverages['healing'] ?? 0));
                $dpmAttr = wt_stat_compare_attr($comparisonEnabled, (float)$focusedDpm, (float)($performanceAverages['dpm'] ?? 0));
                $bestWeaponName = '';
                $bestWeaponAcc = null;
                if (is_array($focused['weapon_summary'] ?? null) && !empty($focused['weapon_summary'])) {
                    $best = $focused['weapon_summary'][0];
                    $bestWeaponName = $best['name'] ?? '';
                    $bestWeaponAcc = isset($best['accuracy']) ? (float)$best['accuracy'] : null;
                }
                $kdTitle = sprintf('K/D: %.2f', $kdValue);
                $kdClasses = 'stat-kd stat-kd--neutral';
                $kdDiffValue = null;
                if ($comparisonEnabled && $kdAverage > 0.0) {
                    $kdDiff = (($kdValue - $kdAverage) / $kdAverage) * 100.0;
                    if (is_finite($kdDiff)) {
                        $kdDiffValue = $kdDiff;
                        $kdTitle = sprintf('K/D: %.2f vs server %.2f (%+.1f%%)', $kdValue, $kdAverage, $kdDiff);
                        if (abs($kdDiff) >= 0.05) {
                            $kdClasses = $kdDiff >= 0 ? 'stat-kd stat-kd--better' : 'stat-kd stat-kd--worse';
                        }
                    }
                }
                ?>

                <section class="detail-panel">
                    <div class="detail-profile">
                        <img src="<?= htmlspecialchars($focused['avatar']) ?>" alt="">
                        <div>
                            <div style="font-size:1.5rem;font-weight:600;">
                                <?= htmlspecialchars($focused['personaname']) ?>
                            </div>
                            <?php if (!empty($focused['profileurl'])): ?>
                                <div class="tagline"><a style="color:var(--accent);" href="<?= htmlspecialchars($focused['profileurl']) ?>" target="_blank" rel="noopener">View on Steam</a></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid">
                        <div>
                            <h3>Kills</h3>
                            <p class="<?= htmlspecialchars($kdClasses . ' stat-kd-trigger', ENT_QUOTES, 'UTF-8') ?>" data-kd="<?= htmlspecialchars(number_format($kdValue, 2), ENT_QUOTES, 'UTF-8') ?>" data-kd-average="<?= htmlspecialchars(number_format($kdAverage, 2), ENT_QUOTES, 'UTF-8') ?>" data-kd-diff="<?= $kdDiffValue !== null ? htmlspecialchars(sprintf('%+.1f', $kdDiffValue), ENT_QUOTES, 'UTF-8') : '' ?>" title="<?= htmlspecialchars($kdTitle, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($focused['kills']) ?></p>
                        </div>
                        <div>
                            <h3>Deaths</h3>
                            <p class="<?= htmlspecialchars($kdClasses . ' stat-kd-trigger', ENT_QUOTES, 'UTF-8') ?>" data-kd="<?= htmlspecialchars(number_format($kdValue, 2), ENT_QUOTES, 'UTF-8') ?>" data-kd-average="<?= htmlspecialchars(number_format($kdAverage, 2), ENT_QUOTES, 'UTF-8') ?>" data-kd-diff="<?= $kdDiffValue !== null ? htmlspecialchars(sprintf('%+.1f', $kdDiffValue), ENT_QUOTES, 'UTF-8') : '' ?>" title="<?= htmlspecialchars($kdTitle, ENT_QUOTES, 'UTF-8') ?>"><?= number_format($focused['deaths']) ?></p>
                        </div>
                        <div>
                            <h3>Assists</h3>
                            <p title="Assists"><?= number_format($focused['assists']) ?></p>
                        </div>
                        <div>
                            <h3>Damage</h3>
                            <p<?= $damageAttr ?> title="Damage Dealt"><?= number_format($focusedDamage) ?></p>
                        </div>
                        <div>
                            <h3>Damage Taken</h3>
                            <p title="Damage Taken"><?= number_format($focusedDamageTaken) ?></p>
                        </div>
                        <div>
                            <h3>Damage / Min</h3>
                            <p<?= $dpmAttr ?> title="Damage Per Minute"><?= number_format($focusedDpm, 1) ?></p>
                        </div>
                        <div>
                            <h3>Taken / Min</h3>
                            <p title="Damage Taken Per Minute"><?= number_format($focusedDtpm, 1) ?></p>
                        </div>
                        <div>
                            <h3>Accuracy</h3>
                            <p<?= $accuracyAttr ?> title="Accuracy"><?= number_format($focusedAccuracy, 1) ?>%</p>
                        </div>
                        <?php if ($bestWeaponName !== ''): ?>
                        <div>
                            <h3>Best Weapon</h3>
                            <p class="stat-best-weapon" title="Best Weapon by Accuracy"><?= htmlspecialchars($bestWeaponName, ENT_QUOTES, 'UTF-8') ?><?php if ($bestWeaponAcc !== null): ?> · <?= number_format($bestWeaponAcc, 1) ?>%<?php endif; ?></p>
                        </div>
                        <?php endif; ?>
                        <div>
                            <h3>Drops</h3>
                            <p title="Medic Drops"><?= number_format($focusedDrops) ?></p>
                        </div>
                        <div>
                            <h3>Dropped Ubers</h3>
                            <p title="Times Dropped"><?= number_format($focusedDropped) ?></p>
                        </div>
                        <div>
                            <h3>Total Ubers</h3>
                            <p title="Total Ubers Used"><?= number_format($focused['total_ubers']) ?></p>
                        </div>
                        <div>
                            <h3>Total Healing</h3>
                            <p<?= $healingAttr ?> title="Healing Done"><?= number_format($focused['healing']) ?></p>
                        </div>
                        <div>
                            <h3>Headshots</h3>
                            <p title="Headshots"><?= number_format($focused['headshots']) ?></p>
                        </div>
                        <div>
                            <h3>Backstabs</h3>
                            <p title="Backstabs"><?= number_format($focused['backstabs']) ?></p>
                        </div>
                        <div>
                            <h3>Airshots</h3>
                            <p<?= $airshotsAttr ?> title="Airshots"><?= number_format($focused['airshots']) ?></p>
                        </div>
                        <div>
                            <h3>Best Streak</h3>
                            <p title="Best Killstreak"><?= number_format($focused['best_killstreak']) ?></p>
                        </div>
                        <div>
                            <h3>Playtime</h3>
                            <p title="Time Played"><?= htmlspecialchars($focused['playtime_human']) ?></p>
                        </div>
                    </div>
                </section>
            <br>
            <?php endif; ?>

            <section class="stat-grid">
                    <div class="stat-card stat-card--whales" title="<?= $playersWeekTooltip ?>">
                        <h3>Total Whales</h3>
                        <div class="whales-breakdown">
                            <div class="whales-row">
                                <span class="whales-label">All time:</span>
                                <span class="whales-value"><?= number_format($totalPlayersAllTime) ?></span>
                            </div>
                            <div class="whales-row">
                                <span class="whales-label">Month:</span>
                                <span class="whales-value"><?= number_format($playersMonthCurrent) ?></span>
                                <span class="whales-delta <?= htmlspecialchars($playersWeekTrendClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="Monthly change not computed">—</span>
                            </div>
                            <div class="whales-row">
                                <span class="whales-label">Week:</span>
                                <span class="whales-value"><?= number_format($playersWeekCurrent) ?></span>
                                <span class="whales-delta <?= htmlspecialchars($playersWeekTrendClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($playersWeekChangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>Total Kills</h3>
                        <p><?= number_format($summary['totalKills']) ?></p>
                    </div>
                    <div class="stat-card stat-card--month" title="Playtime logged in <?= htmlspecialchars($playtimeMonthLabel, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="stat-card-heading">
                            <h3>Playtime / <?= htmlspecialchars($playtimeMonthLabel, ENT_QUOTES, 'UTF-8') ?></h3>
                        </div>
                        <p><?= number_format($playtimeMonthHours, 1) ?> hrs</p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Healing</h3>
                        <p><?= number_format($summary['totalHealing']) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Headshots</h3>
                        <p><?= number_format($summary['totalHeadshots']) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Backstabs</h3>
                        <p><?= number_format($summary['totalBackstabs']) ?></p>
                    </div>
                    <div class="stat-card stat-card-streak">
                        <h3>Best Killstreak</h3>
                        <?php if (!empty($summary['topKillstreakOwner'])): ?>
                            <?php $owner = $summary['topKillstreakOwner']; ?>
                            <div class="stat-card-player">
                                <img src="<?= htmlspecialchars($owner['avatar'] ?? $defaultAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <div>
                                    <div class="stat-card-value"><?= number_format($summary['topKillstreak']) ?></div>
                                    <div class="stat-card-player-name"><?= htmlspecialchars($owner['personaname'] ?? $owner['steamid'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p><?= number_format($summary['topKillstreak']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card stat-card-streak">
                        <h3>Best Killstreak / Week</h3>
                        <?php if (!empty($bestKillstreakWeekLeaders)): ?>
                            <div class="streak-podium">
                                <?php foreach (array_slice($bestKillstreakWeekLeaders, 0, 3) as $index => $leader): ?>
                                    <?php
                                        $rank = $index + 1;
                                        $entryClasses = 'streak-podium__entry streak-podium__entry--rank' . $rank;
                                    ?>
                                    <div class="<?= $entryClasses ?>">
                                        <div class="streak-podium__avatar-wrap">
                                            <img src="<?= htmlspecialchars($leader['avatar'] ?? $defaultAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <span class="streak-podium__badge">#<?= $rank ?></span>
                                        </div>
                                        <div class="streak-podium__details">
                                            <div class="streak-podium__name">
                                                <?= htmlspecialchars($leader['personaname'] ?? ($leader['steamid'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <div class="streak-podium__value"><?= number_format((int)($leader['best_streak'] ?? 0)) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($bestKillstreakWeek > 0): ?>
                            <p><?= number_format($bestKillstreakWeek) ?></p>
                        <?php else: ?>
                            <p class="stat-card-empty">No data yet</p>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card">
                        <h3>Total Damage</h3>
                        <p><?= number_format($summary['totalDamage']) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total Damage Taken</h3>
                        <p><?= number_format($summary['totalDamageTaken']) ?></p>
                    </div>
                    <div class="stat-card stat-card-streak">
                        <h3>Highest DPM / Week</h3>
                        <?php if (!empty($weeklyTopDpmOwner)): ?>
                            <?php $owner = $weeklyTopDpmOwner; ?>
                            <div class="stat-card-player">
                                <img src="<?= htmlspecialchars($owner['avatar'] ?? $defaultAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <div>
                                    <div class="stat-card-value"><?= number_format($weeklyTopDpm, 1) ?></div>
                                    <div class="stat-card-player-name"><?= htmlspecialchars($owner['personaname'] ?? $owner['steamid'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        <?php elseif ($weeklyTopDpm > 0): ?>
                            <p><?= number_format($weeklyTopDpm, 1) ?></p>
                        <?php else: ?>
                            <p class="stat-card-empty">No data yet</p>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card">
                        <h3>Average DPM</h3>
                        <p><?= number_format($averageDpm, 1) ?></p>
                    </div>
                    <div class="stat-card stat-card--drops">
                        <h3>Total Ubers</h3>
                        <div class="stat-card-metric-pair">
                            <div class="stat-card-metric">
                                <div class="stat-card-metric-label">Ubers Used</div>
                                <div class="stat-card-metric-value"><?= number_format($totalUbersUsed) ?></div>
                            </div>
                            <div class="stat-card-metric">
                                <div class="stat-card-metric-label">Ubers Dropped</div>
                                <div class="stat-card-metric-value"><?= number_format($totalDrops) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card stat-card--gamemodes">
                        <h3>Gamemodes</h3>
                        <?php if (!empty($gamemodeTop)): ?>
                            <ol class="gamemode-list">
                                <?php foreach ($gamemodeTop as $index => $mode): ?>
                                    <li class="gamemode-item">
                                        <span class="gamemode-rank"><?= $index + 1 ?>.</span>
                                        <span class="gamemode-name"><?= htmlspecialchars($mode['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="gamemode-share"><?= number_format((float)$mode['percentage'], 1) ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <p class="stat-card-empty">No logs recorded</p>
                        <?php endif; ?>
                    </div>
                </section>


            <div class="table-toolbar">
                <form class="search-bar toolbar-search" method="get" action="index.php">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search players by Steam name or SteamID">
                    <button type="submit">Search</button>
                </form>
                <div class="toolbar-spacer"></div>
                <div class="toolbar-pagination">
                    <?php if ($totalRows > 0): ?>
                        <span class="table-pagination-info">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                        <div class="table-pagination-controls">
                            <?php if ($prevPageUrl !== null): ?>
                                <a class="table-pagination-button" href="<?= htmlspecialchars($prevPageUrl, ENT_QUOTES, 'UTF-8') ?>">Prev</a>
                            <?php else: ?>
                                <span class="table-pagination-button is-disabled" aria-disabled="true">Prev</span>
                            <?php endif; ?>
                            <?php if ($nextPageUrl !== null): ?>
                                <a class="table-pagination-button" href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Next</a>
                            <?php else: ?>
                                <span class="table-pagination-button is-disabled" aria-disabled="true">Next</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="stats-table" id="stats-table-cumulative">
                    <thead>
                    <tr>
                        <th data-key="player" data-type="text" title="Player">Player</th>
                        <th data-key="kills" data-type="number" title="Kills | Deaths | Assists">Kills|Deaths|Assists</th>
                        <th data-key="kd" data-type="number" title="Kill/Death Ratio">K/D</th>
                        <th data-key="damage" data-type="number" title="Damage Dealt">Dmg</th>
                        <th data-key="damage_taken" data-type="number" title="Damage Taken">DT</th>
                        <th data-key="dpm" data-type="number" title="Damage Per Minute">D/M</th>
                        <th data-key="dtpm" data-type="number" title="Damage Taken Per Minute">DT/M</th>
                        <th data-key="accuracy" data-type="number" title="Accuracy">Acc.</th>
                        <th data-key="airshots" data-type="number" title="Airshots">AS</th>
                        <th data-key="drops" data-type="number" title="Dropped Medics | Times Dropped">Dp | Dp'd</th>
                        <th data-key="healing" data-type="number" title="Healing Done">Heals</th>
                        <th data-key="headshots" data-type="number" title="Headshots">Headshots</th>
                        <th data-key="backstabs" data-type="number" title="Backstabs">Stabs</th>
                        <th data-key="streak" data-type="number" title="Best Killstreak">Best Streak</th>
                        <th data-key="playtime" data-type="number" title="Playtime">Time</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php wt_render_cumulative_rows($pageStats, $focused['steamid'] ?? null); ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalRows > 0): ?>
                <div class="table-pagination table-pagination--bottom">
                    <span class="table-pagination-info">Page <?= (int)$page ?> / <?= (int)$totalPages ?></span>
                    <div class="table-pagination-controls">
                        <?php if ($prevPageUrl !== null): ?>
                            <a class="table-pagination-button" href="<?= htmlspecialchars($prevPageUrl, ENT_QUOTES, 'UTF-8') ?>">Prev</a>
                        <?php else: ?>
                            <span class="table-pagination-button is-disabled" aria-disabled="true">Prev</span>
                        <?php endif; ?>
                        <?php if ($nextPageUrl !== null): ?>
                            <a class="table-pagination-button" href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Next</a>
                        <?php else: ?>
                            <span class="table-pagination-button is-disabled" aria-disabled="true">Next</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>  

        <div class="tab-content <?= $initialTab === 'tab-online' ? 'active' : '' ?>" id="tab-online">
            <div class="table-wrapper">
                <div class="empty-state" id="online-empty">Map changing or no players are currently online.</div>
                <table class="stats-table" id="stats-table-online" style="display:none">
                    <thead>
                    <tr>
                        <th data-key="player" data-type="text">Player</th>
                        <th data-key="kills" data-type="number">Kills</th>
                        <th data-key="deaths" data-type="number">Deaths</th>
                        <th data-key="kd" data-type="number">K/D</th>
                        <th data-key="accuracy" data-type="number">Acc.</th>
                        <th data-key="assists" data-type="number">Assists</th>
                        <th data-key="damage" data-type="number">Damage</th>
                        <th data-key="dtpm" data-type="number">Damage Taken/Min</th>
                        <th data-key="dpm" data-type="number">Damage/Min</th>
                        <th data-key="headshots" data-type="number">Headshots</th>
                        <th data-key="backstabs" data-type="number">Stabs</th>
                        <th data-key="healing" data-type="number">Healing</th>
                        <th data-key="ubers" data-type="number">Ubers</th>
                        <th data-key="time" data-type="number">Time</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="tab-content <?= $initialTab === 'tab-logs' ? 'active' : '' ?>" id="tab-logs">
            <div class="logs-toolbar">
                <div class="logs-toggle-group">
                    <button type="button" class="logs-refresh" id="logs-toggle-small">Show &lt;12 Player Logs</button>
                    <button type="button" class="logs-refresh" id="logs-toggle-old">Hide Old</button>
                </div>
                <div class="logs-refresh-group">
                    <img src="nue_transparent.gif" alt="Nue dancing" class="logs-refresh-gif">
                    <button type="button" class="logs-refresh" id="logs-refresh">Refresh Logs</button>
                </div>
            </div>
            <div class="logs-container" id="logs-container">
                <div class="empty-state" id="logs-empty">No match logs recorded yet.</div>
            </div>
        </div>
    </div>
</div>


<script>
const allStatsLookup = <?= json_encode($allStatsLookup, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const focusedSteamId = <?= $focused ? json_encode($focused['steamid']) : 'null' ?>;
const defaultAvatar = 'https://steamcdn-a.akamaihd.net/steamcommunity/public/images/avatars/fe/fef49e7fa7a3da7fd2e8a58905cfe144.png';
const onlineEndpoint = 'online.php';
const logsEndpoint = 'logs.php';
document.addEventListener('mouseover', (event) => {
    const target = event.target;
    if (target && target.classList && target.classList.contains('stat-kd-trigger')) {
        const kdValue = target.getAttribute('data-kd') || '';
        const kdAverage = target.getAttribute('data-kd-average') || '';
        const kdDiff = target.getAttribute('data-kd-diff');
        if (kdAverage && kdDiff) {
            target.title = `K/D: ${kdValue} vs server ${kdAverage} (${kdDiff}%)`;
        } else if (kdAverage) {
            target.title = `K/D: ${kdValue} vs server ${kdAverage}`;
        } else {
            target.title = `K/D: ${kdValue}`;
        }
    }
});
const onlineRefreshMs = 4000;
const classIconBase = '/leaderboard/';
const onlineButton = document.querySelector('.tab-button[data-tab="tab-online"]');
const onlineCountLabel = onlineButton ? onlineButton.querySelector('.tab-button-count') : null;
let visibleMaxPlayers = 32;
const classNameMap = {0: 'Spectator', 1: 'Scout', 2: 'Sniper', 3: 'Soldier', 4: 'Demoman', 5: 'Medic', 6: 'Heavy', 7: 'Pyro', 8: 'Spy', 9: 'Engineer'};
const classIconMap = {
    Spectator: 'Icon_replay.png',
    Unknown: 'Icon_replay.png',
    Scout: 'Scout.png',
    Sniper: 'Sniper.png',
    Soldier: 'Soldier.png',
    Demoman: 'Demoman.png',
    Medic: 'Medic.png',
    Heavy: 'Heavy.png',
    Pyro: 'Pyro.png',
    Spy: 'Spy.png',
    Engineer: 'Engineer.png'
};

const onlineTable = document.getElementById('stats-table-online');
const onlineTbody = onlineTable ? onlineTable.querySelector('tbody') : null;
const onlineEmpty = document.getElementById('online-empty');

const logsContainer = document.getElementById('logs-container');
const logsEmpty = document.getElementById('logs-empty');
const logsRefreshButton = document.getElementById('logs-refresh');
const logsToggleOldButton = document.getElementById('logs-toggle-old');
const logsToggleSmallButton = document.getElementById('logs-toggle-small');
let logsInitialized = false;
const urlParams = new URLSearchParams(window.location.search);
let showOldLogs = true;
let showSmallLogs = false;
const SMALL_LOG_THRESHOLD = 12;
if (logsToggleOldButton) {
    logsToggleOldButton.textContent = showOldLogs ? 'Hide Old' : 'Show Old';
}
if (logsToggleSmallButton) {
    logsToggleSmallButton.textContent = showSmallLogs ? 'Hide <12 Player Logs' : 'Show <12 Player Logs';
}

function initSorting() {
    document.querySelectorAll('.stats-table').forEach(table => {
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
    });
}

function initTabs() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const target = button.dataset.tab;
            tabButtons.forEach(btn => btn.classList.toggle('active', btn === button));
            tabContents.forEach(content => content.classList.toggle('active', content.id === target));
            if (target === 'tab-logs') {
                ensureLogsInitialized();
            }
        });
    });
}

function formatPlaytime(seconds) {
    const totalSeconds = Math.max(0, Number(seconds) || 0);
    const totalMinutes = Math.floor(totalSeconds / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }
    return `${Math.max(minutes, 1)}m`;
}

function formatNumber(value, decimals) {
    const num = Number(value) || 0;
    return num.toFixed(decimals);
}

function getProfile(steamId) {
    if (!steamId || typeof allStatsLookup !== 'object' || allStatsLookup === null) {
        return null;
    }
    return allStatsLookup[steamId] || null;
}

function getClassInfo(classId) {
    const name = classNameMap[classId] || 'Unknown';
    const iconFile = classIconMap[name] || classIconMap.Unknown;
    return {
        name,
        icon: classIconBase + iconFile
    };
}

function createNumberCell(sortValue, displayValue, titleText) {
    const td = document.createElement('td');
    td.dataset.sortValue = String(sortValue);
    td.textContent = displayValue;
    if (titleText) {
        td.title = titleText;
    }
    return td;
}

function updateOnlineButtonInfo(count, maxPlayers) {
    if (!onlineButton || !onlineCountLabel) {
        return;
    }
    const safeMax = Number(maxPlayers) > 0 ? Number(maxPlayers) : 32;
    onlineCountLabel.textContent = `${count} / ${safeMax}`;
    onlineButton.classList.toggle('tab-button-glow', count > 6);
}

updateOnlineButtonInfo(0, visibleMaxPlayers);

function slugifyGamemode(name) {
    if (!name || typeof name !== 'string') {
        return 'unknown';
    }
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'unknown';
}

function gamemodeBadgeText(name) {
    const slug = slugifyGamemode(name);
    const map = {
        'king-of-the-hill': 'KOTH',
        'payload': 'PL',
        'payload-race': 'PLR',
        'capture-the-flag': 'CTF',
        'arena': 'ARENA',
        'attack-defend-cp': 'AD',
        'player-destruction': 'PD',
        'medieval': 'MED',
        'territorial-control': 'TC'
    };
    if (map[slug]) {
        return map[slug];
    }
    if (name && typeof name === 'string' && name.length > 0) {
        return name.length > 3 ? name.slice(0, 3).toUpperCase() : name.toUpperCase();
    }
    return '?';
}

function formatDateTime(timestamp) {
    if (!timestamp) {
        return 'Unknown time';
    }
    const date = new Date(Number(timestamp) * 1000);
    if (Number.isNaN(date.getTime())) {
        return 'Unknown time';
    }
    return date.toLocaleString();
}

function ensureLogsInitialized() {
    if (logsInitialized) {
        return;
    }
    logsInitialized = true;
    if (logsRefreshButton) {
        logsRefreshButton.addEventListener('click', () => {
            fetchLogs();
        });
    }
    if (logsToggleSmallButton) {
        logsToggleSmallButton.addEventListener('click', () => {
            showSmallLogs = !showSmallLogs;
            logsToggleSmallButton.textContent = showSmallLogs ? 'Hide <12 Player Logs' : 'Show <12 Player Logs';
            fetchLogs();
        });
    }
    if (logsToggleOldButton) {
        logsToggleOldButton.addEventListener('click', () => {
            showOldLogs = !showOldLogs;
            logsToggleOldButton.textContent = showOldLogs ? 'Hide Old' : 'Show Old';
            fetchLogs();
        });
    }
    fetchLogs();
}

async function fetchLogs() {
    try {
        const response = await fetch(logsEndpoint, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Failed request');
        }
        const payload = await response.json();
        if (!payload || payload.success !== true || !Array.isArray(payload.logs)) {
            throw new Error('Invalid payload');
        }
        renderLogs(payload.logs);
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch logs:', err);
        renderLogs([]);
    }
}

function buildLogTable(players) {
    const table = document.createElement('table');
    table.className = 'stats-table log-table';
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');
    const headers = [
        { key: 'player', label: 'Player', title: 'Player' },
        { key: 'kills', label: 'K', title: 'Kills' },
        { key: 'deaths', label: 'D', title: 'Deaths' },
        { key: 'kd', label: 'K/D', title: 'Kill/Death Ratio' },
        { key: 'accuracy', label: 'Acc.', title: 'Best Weapon Accuracy' },
        { key: 'damage', label: 'Dmg', title: 'Damage' },
        { key: 'dpm', label: 'D/M', title: 'Damage Per Minute' },
        { key: 'dtpm', label: 'DT/M', title: 'Damage Taken Per Minute' },
        { key: 'airshots', label: 'AS', title: 'Airshots' },
        { key: 'headshots', label: 'HS', title: 'Headshots' },
        { key: 'backstabs', label: 'BS', title: 'Backstabs' },
        { key: 'healing', label: 'Healing', title: 'Healing Done' },
        { key: 'ubers', label: 'Ubers', title: 'Total Ubers' },
        { key: 'time', label: 'Time', title: 'Time Played' },
    ];
    headers.forEach(header => {
        const th = document.createElement('th');
        th.textContent = header.label;
        th.dataset.key = header.key;
        th.title = header.title;
        headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    players.forEach(player => {
        const steamId = player.steamid;
        const profile = getProfile(steamId);
        const kills = Number(player.kills) || 0;
        const deaths = Number(player.deaths) || 0;
        const damage = Number(player.damage) || 0;
        const damageTaken = Number(player.damage_taken) || 0;
        const healing = Number(player.healing) || 0;
        const headshots = Number(player.headshots) || 0;
        const backstabs = Number(player.backstabs) || 0;
        const totalUbers = Number(player.total_ubers) || 0;
        const playtime = Number(player.playtime) || 0;
        const minutes = playtime > 0 ? (playtime / 60) : 0;
        const airshots = Number(player.airshots) || 0;
        const kd = deaths > 0 ? kills / deaths : kills;
        const dpm = minutes > 0 ? (damage / minutes) : damage;
        const dtpm = minutes > 0 ? (damageTaken / minutes) : damageTaken;
        const weaponSummary = Array.isArray(player.weapon_summary) ? player.weapon_summary : [];
        const weaponAccuracySummary = Array.isArray(player.weapon_accuracy_summary) ? player.weapon_accuracy_summary : [];

        const shotsTotal = Number(player.shots) || 0;
        const hitsTotal = Number(player.hits) || 0;
        const averageAccuracyValue = shotsTotal > 0 ? (hitsTotal / shotsTotal) * 100 : null;
        const accuracyDisplay = averageAccuracyValue !== null ? `${formatNumber(averageAccuracyValue, 1)}%` : '—';
        const accuracyCellValue = averageAccuracyValue;

        const tr = document.createElement('tr');
        const playerTd = document.createElement('td');
        playerTd.className = 'player-cell';
        const avatarImg = document.createElement('img');
        const avatarUrl = (profile && profile.avatar) || player.avatar || player.avatarfull || defaultAvatar;
        avatarImg.className = 'player-avatar';
        avatarImg.src = avatarUrl;
        avatarImg.alt = '';
        avatarImg.onerror = () => {
            avatarImg.onerror = null;
            avatarImg.src = defaultAvatar;
        };
        playerTd.appendChild(avatarImg);

        const playerInfo = document.createElement('div');
        playerInfo.className = 'log-player-info';
        const isAdmin = Number(player.is_admin) === 1;
        const titleText = isAdmin ? 'Admin' : 'Player';
        const profileUrl = (profile && profile.profileurl) || player.profileurl || null;
        const displayName = (profile && profile.personaname) || player.personaname || steamId;

        const nameElTag = profileUrl ? document.createElement('a') : document.createElement('span');
        nameElTag.textContent = displayName;
        nameElTag.title = titleText;
        nameElTag.setAttribute('aria-label', titleText);
        if (profileUrl) {
            nameElTag.href = profileUrl;
            nameElTag.target = '_blank';
            nameElTag.rel = 'noopener';
        }
        if (isAdmin) {
            nameElTag.classList.add('admin-name');
        }
        playerInfo.appendChild(nameElTag);
        appendClassIcons(playerInfo, player.classes_mask || 0);
        playerTd.appendChild(playerInfo);
        tr.appendChild(playerTd);
        tr.appendChild(createNumberCell(kills, kills.toLocaleString(), 'Kills'));
        tr.appendChild(createNumberCell(deaths, deaths.toLocaleString(), 'Deaths'));
        tr.appendChild(createNumberCell(kd.toFixed(4), formatNumber(kd, 2), 'Kill/Death Ratio'));

        const logClassSummary = Array.isArray(player.class_accuracy_summary) ? player.class_accuracy_summary : [];
        const logWeaponSummary = weaponAccuracySummary.length > 0 ? weaponAccuracySummary : weaponSummary;
        const logTooltipParts = [];
        let logBestClass = null;
        logClassSummary.forEach(entry => {
            if (!entry || typeof entry.accuracy !== 'number') {
                return;
            }
            if (!logBestClass || entry.accuracy > logBestClass.accuracy) {
                logBestClass = entry;
            }
        });
        if (logBestClass) {
            const logClassHits = Number(logBestClass.hits || 0);
            const logClassShots = Number(logBestClass.shots || 0);
            if (logClassShots > 0) {
                logTooltipParts.push(`${logBestClass.label || 'Class'}: ${formatNumber(logBestClass.accuracy, 1)}% (${logClassHits.toLocaleString()}/${logClassShots.toLocaleString()})`);
            } else {
                logTooltipParts.push(`${logBestClass.label || 'Class'}: ${formatNumber(logBestClass.accuracy, 1)}%`);
            }
        }
        logWeaponSummary.slice(0, 6).forEach(entry => {
            if (!entry || typeof entry.accuracy !== 'number') {
                return;
            }
            const wHits = Number(entry.hits || 0);
            const wShots = Number(entry.shots || 0);
            if (wShots > 0) {
                logTooltipParts.push(`${entry.name}: ${formatNumber(entry.accuracy, 1)}% (${wHits.toLocaleString()}/${wShots.toLocaleString()})`);
            } else {
                logTooltipParts.push(`${entry.name}: ${formatNumber(entry.accuracy, 1)}%`);
            }
        });
        if (logTooltipParts.length === 0) {
            if (accuracyDisplay !== '—') {
                logTooltipParts.push(`Accuracy: ${accuracyDisplay}`);
            } else {
                logTooltipParts.push('Accuracy unavailable');
            }
        }
        const logAccuracyTitle = logTooltipParts.join(' • ');

        tr.appendChild(createNumberCell(accuracyCellValue !== null ? accuracyCellValue : -1, accuracyDisplay, logAccuracyTitle));
        tr.appendChild(createNumberCell(damage, damage.toLocaleString(), 'Damage'));
        tr.appendChild(createNumberCell(dpm, formatNumber(dpm, 1), 'Damage Per Minute'));
        tr.appendChild(createNumberCell(dtpm, formatNumber(dtpm, 1), 'Damage Taken Per Minute'));
        tr.appendChild(createNumberCell(airshots, airshots.toLocaleString(), 'Airshots'));
        tr.appendChild(createNumberCell(headshots, headshots.toLocaleString(), 'Headshots'));
        tr.appendChild(createNumberCell(backstabs, backstabs.toLocaleString(), 'Backstabs'));
        tr.appendChild(createNumberCell(healing, healing.toLocaleString(), 'Healing Done'));
        tr.appendChild(createNumberCell(totalUbers, totalUbers.toLocaleString(), 'Total Ubers'));
        tr.appendChild(createNumberCell(playtime, formatPlaytime(playtime), 'Time Played'));
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    return table;
}

function buildLogWeaponBreakdown(players) {
    if (!Array.isArray(players) || players.length === 0) {
        return null;
    }

    const highlight = players
        .filter(player => Array.isArray(player.weapon_summary) && player.weapon_summary.some(weapon => {
            const shots = Number(weapon.shots) || 0;
            const hits = Number(weapon.hits) || 0;
            const damage = Number(weapon.damage) || 0;
            return damage > 0 || hits > 0 || shots > 0;
        }))
        .sort((a, b) => (Number(b.damage) || 0) - (Number(a.damage) || 0))
        .slice(0, 8);

    if (highlight.length === 0) {
        return null;
    }

    const container = document.createElement('div');
    container.className = 'log-weapon-breakdown';

    const title = document.createElement('div');
    title.className = 'log-weapon-breakdown-title';
    title.textContent = 'Accuracy Breakdown';
    container.appendChild(title);

    highlight.forEach(player => {
        const card = document.createElement('details');
        card.className = 'log-weapon-card';
        card.open = true;

        const summary = document.createElement('summary');
        summary.className = 'log-weapon-card-summary';

        const name = document.createElement('span');
        name.className = 'log-weapon-card-name';
        name.textContent = player.personaname || player.steamid || 'Unknown Player';
        summary.appendChild(name);

        const meta = document.createElement('span');
        meta.className = 'log-weapon-card-meta';
        const damage = Number(player.damage) || 0;
        const kills = Number(player.kills) || 0;
        meta.textContent = `${damage.toLocaleString()} dmg • ${kills} kills`;
        summary.appendChild(meta);

        card.appendChild(summary);

        const maxWeapons = 6;
        const weapons = Array.isArray(player.weapon_summary) ? player.weapon_summary.slice(0, maxWeapons) : [];
        if (weapons.length > 0) {
            const list = document.createElement('div');
            list.className = 'log-weapon-list';

            weapons.forEach(weapon => {
                const item = document.createElement('div');
                item.className = 'log-weapon-item';
                const weaponName = weapon.name || 'Unknown';
                let accuracyText = '—';
                if (typeof weapon.accuracy === 'number') {
                    accuracyText = `${formatNumber(weapon.accuracy, 1)}%`;
                }
                item.textContent = `${weaponName}: ${accuracyText}`;

                const shots = Number(weapon.shots) || 0;
                const hits = Number(weapon.hits) || 0;
                const damageValue = Number(weapon.damage) || 0;
                if (shots > 0 || hits > 0 || damageValue > 0) {
                    item.title = `${damageValue.toLocaleString()} dmg • ${hits}/${shots} hits`;
                }

                list.appendChild(item);
            });

            card.appendChild(list);
        } else {
            const empty = document.createElement('div');
            empty.className = 'log-weapon-empty';
            empty.textContent = 'No weapon accuracy recorded yet.';
            card.appendChild(empty);
        }

        const airshotsSummary = player.airshots_summary || {};
        const airshotEntries = Object.values(airshotsSummary)
            .filter(entry => entry && Number(entry.count) > 0);
        if (airshotEntries.length > 0) {
            const airshotsEl = document.createElement('div');
            airshotsEl.className = 'log-weapon-airshots';
            const segments = airshotEntries.map(entry => {
                const label = entry.label || '';
                const count = Number(entry.count) || 0;
                const height = Number(entry.max_height) || 0;
                let segment = `${label} ${count}`;
                if (height > 0) {
                    segment += ` (max ${height}u)`;
                }
                return segment;
            });
            airshotsEl.textContent = `Airshots: ${segments.join(' • ')}`;
            card.appendChild(airshotsEl);
        }

        container.appendChild(card);
    });

    return container;
}

function renderLogs(logs) {
    if (!logsContainer || !logsEmpty) {
        return;
    }
    logsContainer.innerHTML = '';
    if (!Array.isArray(logs) || logs.length === 0) {
        logsEmpty.style.display = '';
        return;
    }
    logsEmpty.style.display = 'none';
    let renderedLogs = 0;
    logs.forEach((log, index) => {
        const playerCountRaw = Number(log.player_count) || (Array.isArray(log.players) ? log.players.length : 0);
        if (!showSmallLogs && index !== 0 && playerCountRaw < SMALL_LOG_THRESHOLD) {
            return;
        }
        if (!showOldLogs && index > 0) {
            return;
        }
        const details = document.createElement('details');
        details.className = 'log-entry' + (index === 0 ? ' log-current' : '');
        if (index === 0) {
            details.id = 'current';
        }
        if (index === 0) {
            details.open = true;
        }
        const summary = document.createElement('summary');
        summary.className = 'log-summary';
        const badge = document.createElement('span');
        const badgeText = gamemodeBadgeText(log.gamemode || 'Unknown');
        const badgeSlug = slugifyGamemode(log.gamemode || 'Unknown');
        badge.className = `gamemode-icon gamemode-${badgeSlug}`;
        badge.textContent = badgeText;
        badge.title = log.gamemode || 'Unknown';
        summary.appendChild(badge);
        const mapNameRaw = (log.map || 'Unknown');
        const mapName = mapNameRaw.split('/').pop();
        const titleSpan = document.createElement('span');
        titleSpan.className = 'log-title';
        const durationText = formatPlaytime(Number(log.duration) || 0);
        titleSpan.textContent = `${mapName} — ${formatDateTime(log.started_at)} — ${durationText}`;
        summary.appendChild(titleSpan);
        const metaSpan = document.createElement('span');
        metaSpan.className = 'log-meta';
        const count = playerCountRaw;
        metaSpan.textContent = `${count} player${count === 1 ? '' : 's'}`;
        summary.appendChild(metaSpan);
        details.appendChild(summary);
        const body = document.createElement('div');
        body.className = 'log-body';
        if (Array.isArray(log.players) && log.players.length > 0) {
            const table = buildLogTable(log.players);
            body.appendChild(table);
            const breakdown = buildLogWeaponBreakdown(log.players);
            if (breakdown) {
                body.appendChild(breakdown);
            }
        } else {
            const empty = document.createElement('div');
            empty.className = 'empty-state';
            empty.textContent = 'No player data recorded.';
            body.appendChild(empty);
        }
        details.appendChild(body);
        logsContainer.appendChild(details);
        renderedLogs++;
    });
    if (renderedLogs === 0) {
        const emptyNotice = document.createElement('div');
        emptyNotice.className = 'empty-state';
        emptyNotice.textContent = 'All logs hidden by filters.';
        logsContainer.appendChild(emptyNotice);
    }
}

function appendClassIcons(target, mask) {
    const numericMask = Number(mask) || 0;
    if (numericMask <= 0) {
        return;
    }

    const wrapper = document.createElement('span');
    wrapper.className = 'class-icon-strip';

    for (let classId = 1; classId <= 9; classId++) {
        const bit = 1 << (classId - 1);
        if ((numericMask & bit) === 0) {
            continue;
        }

        const info = getClassInfo(classId);
        if (!info || !info.icon) {
            continue;
        }

        const icon = document.createElement('img');
        icon.className = 'online-class-icon';
        icon.src = info.icon;
        icon.alt = info.name;
        icon.title = `${info.name} class`;
        wrapper.appendChild(icon);
    }

    if (wrapper.children.length > 0) {
        target.appendChild(wrapper);
    }
}

function renderOnline(players) {
    if (!onlineTable || !onlineTbody || !onlineEmpty) {
        return;
    }
    const list = Array.isArray(players) ? players.slice() : [];
    list.sort((a, b) => {
        const scoreA = (Number(a.kills) || 0) + (Number(a.assists) || 0);
        const scoreB = (Number(b.kills) || 0) + (Number(b.assists) || 0);
        if (scoreA !== scoreB) {
            return scoreB - scoreA;
        }
        return (Number(b.kills) || 0) - (Number(a.kills) || 0);
    });
    if (list.length === 0) {
        onlineTable.style.display = 'none';
        onlineEmpty.style.display = '';
        onlineTbody.innerHTML = '';
        return;
    }
    onlineTable.style.display = '';
    onlineEmpty.style.display = 'none';
    onlineTbody.innerHTML = '';

    list.forEach(player => {
        const steamid = player.steamid;
        const profile = getProfile(steamid);
        const personaname = (profile && profile.personaname) || player.personaname || steamid;
        const avatar = (profile && profile.avatar) || player.avatar || defaultAvatar;
        const profileUrl = (profile && profile.profileurl) || player.profileurl || null;
        const isAdmin = Boolean((profile && profile.is_admin) || player.is_admin);
        const team = Number(player.team) || 0;
        const isAlive = Number(player.alive) === 1;
        const listedSpectator = Number(player.is_spectator) === 1;

        const kills = Number(player.kills) || 0;
        const deaths = Number(player.deaths) || 0;
        const assists = Number(player.assists) || 0;
        const damage = Number(player.damage) || 0;
        const damageTaken = Number(player.damage_taken) || 0;
        const healing = Number(player.healing) || 0;
        const headshots = Number(player.headshots) || 0;
        const backstabs = Number(player.backstabs) || 0;
        const totalUbers = Number(player.total_ubers) || 0;
        const shots = Number(player.shots) || 0;
        const hits = Number(player.hits) || 0;
        let timeConnected = Number(player.time_connected);
        if (!Number.isFinite(timeConnected) || timeConnected < 0) {
            timeConnected = Number(player.playtime) || 0;
        }
        const minutes = timeConnected > 0 ? (timeConnected / 60) : 0;
        const dpm = minutes > 0 ? damage / minutes : damage;
        const dtpm = minutes > 0 ? damageTaken / minutes : damageTaken;
        const kdValue = deaths > 0 ? kills / deaths : kills;
        const score = kills + assists;
        const accuracy = shots > 0 ? (hits / shots) * 100 : null;
        const accuracySortValue = accuracy !== null ? accuracy : 0;
        const accuracyDisplay = accuracy !== null ? `${formatNumber(accuracy, 1)}%` : '—';
        const classAccuracySummary = Array.isArray(player.class_accuracy_summary) ? player.class_accuracy_summary : [];
        const weaponSummary = Array.isArray(player.weapon_accuracy_summary) ? player.weapon_accuracy_summary : [];

        const tooltipParts = [];
        let bestClassEntry = null;
        classAccuracySummary.forEach(entry => {
            if (!entry || typeof entry.accuracy !== 'number') {
                return;
            }
            if (!bestClassEntry || entry.accuracy > bestClassEntry.accuracy) {
                bestClassEntry = entry;
            }
        });
        if (bestClassEntry) {
            const classHits = Number(bestClassEntry.hits || 0);
            const classShots = Number(bestClassEntry.shots || 0);
            if (classShots > 0) {
                tooltipParts.push(`${bestClassEntry.label || 'Class'}: ${formatNumber(bestClassEntry.accuracy, 1)}% (${classHits.toLocaleString()}/${classShots.toLocaleString()})`);
            } else {
                tooltipParts.push(`${bestClassEntry.label || 'Class'}: ${formatNumber(bestClassEntry.accuracy, 1)}%`);
            }
        }
        weaponSummary.slice(0, 6).forEach(entry => {
            if (!entry || typeof entry.accuracy !== 'number') {
                return;
            }
            tooltipParts.push(`${entry.name}: ${formatNumber(entry.accuracy, 1)}%`);
        });
        if (tooltipParts.length === 0) {
            if (accuracy !== null) {
                tooltipParts.push(`${formatNumber(accuracy, 1)}% overall accuracy`);
            } else {
                tooltipParts.push('Accuracy unavailable');
            }
        }
        const accuracyTitle = tooltipParts.join(' • ');

        const classInfo = getClassInfo(Number(player.class) || 0);
        const isSpectator = listedSpectator
            || (team !== 2 && team !== 3)
            || classInfo.name === 'Spectator'
            || classInfo.name === 'Unknown';

        const tr = document.createElement('tr');
        tr.classList.add('online-player');
        if (isAlive) {
            if (team === 2) {
                tr.classList.add('player-team-red');
            } else if (team === 3) {
                tr.classList.add('player-team-blue');
            } else {
                tr.classList.add('player-team-neutral');
            }
        } else {
            if (team === 2) {
                tr.classList.add('player-team-red');
            } else if (team === 3) {
                tr.classList.add('player-team-blue');
            } else {
                tr.classList.add('player-team-neutral');
            }
            tr.classList.add('player-faded');
        }
        if (isSpectator) {
            tr.classList.add('player-team-neutral', 'player-faded', 'player-spectator');
        }
        tr.dataset.player = personaname.toLowerCase();
        tr.dataset.kills = String(kills);
        tr.dataset.deaths = String(deaths);
        tr.dataset.kd = kdValue.toFixed(4);
        tr.dataset.assists = String(assists);
        tr.dataset.damage = String(damage);
        tr.dataset.damage_taken = String(damageTaken);
        tr.dataset.healing = String(healing);
        tr.dataset.headshots = String(headshots);
        tr.dataset.backstabs = String(backstabs);
        tr.dataset.dpm = dpm.toFixed(4);
        tr.dataset.dtpm = dtpm.toFixed(4);
        tr.dataset.ubers = String(totalUbers);
        tr.dataset.time = String(timeConnected);
        tr.dataset.playtime = String(timeConnected);
        tr.dataset.score = String(score);
        tr.dataset.accuracy = accuracy !== null ? accuracy.toFixed(4) : '0';
        tr.dataset.shots = String(shots);
        tr.dataset.hits = String(hits);

        if (focusedSteamId && steamid === focusedSteamId) {
            tr.classList.add('player-highlight');
        }

        const playerTd = document.createElement('td');
        playerTd.className = 'player-cell';
        const avatarImg = document.createElement('img');
        avatarImg.className = 'player-avatar';
        avatarImg.src = avatar || defaultAvatar;
        avatarImg.alt = '';
        avatarImg.onerror = () => {
            avatarImg.onerror = null;
            avatarImg.src = defaultAvatar;
        };
        playerTd.appendChild(avatarImg);
        const playerInfo = document.createElement('div');
        const nameContainer = profileUrl ? document.createElement('a') : document.createElement('span');
        const titleText = isAdmin ? 'Admin' : 'Player';
        nameContainer.textContent = personaname;
        nameContainer.title = titleText;
        nameContainer.setAttribute('aria-label', titleText);
        if (profileUrl) {
            nameContainer.href = profileUrl;
            nameContainer.target = '_blank';
            nameContainer.rel = 'noopener';
        }
        const nameClasses = ['online-name'];
        if (isAdmin) {
            nameClasses.push('admin-name');
        }
        if (team === 2) {
            nameClasses.push('team-red-name');
        } else if (team === 3) {
            nameClasses.push('team-blue-name');
        } else if (isSpectator) {
            nameClasses.push('spectator-name');
        }
        nameContainer.className = nameClasses.join(' ');
        playerInfo.appendChild(nameContainer);
        if (classInfo && classInfo.icon) {
            const classBadge = document.createElement('img');
            classBadge.className = 'online-class-icon';
            classBadge.src = classInfo.icon;
            classBadge.alt = classInfo.name;
            classBadge.title = `${classInfo.name}`;
            playerInfo.appendChild(classBadge);
        }
        playerTd.appendChild(playerInfo);
        tr.appendChild(playerTd);

        tr.appendChild(createNumberCell(kills, kills.toLocaleString(), 'Kills'));
        tr.appendChild(createNumberCell(deaths, deaths.toLocaleString(), 'Deaths'));
        tr.appendChild(createNumberCell(kdValue.toFixed(4), formatNumber(kdValue, 2), 'Kill/Death Ratio'));
        tr.appendChild(createNumberCell(accuracySortValue, accuracyDisplay, accuracyTitle));
        tr.appendChild(createNumberCell(assists, assists.toLocaleString(), 'Assists'));
        tr.appendChild(createNumberCell(damage, damage.toLocaleString(), 'Damage'));
        tr.appendChild(createNumberCell(dtpm, formatNumber(dtpm, 1), 'Damage Taken Per Minute'));
        tr.appendChild(createNumberCell(dpm, formatNumber(dpm, 1), 'Damage Per Minute'));
        tr.appendChild(createNumberCell(headshots, headshots.toLocaleString(), 'Headshots'));
        tr.appendChild(createNumberCell(backstabs, backstabs.toLocaleString(), 'Backstabs'));
        tr.appendChild(createNumberCell(healing, healing.toLocaleString(), 'Healing Done'));
        tr.appendChild(createNumberCell(totalUbers, totalUbers.toLocaleString(), 'Total Ubers'));
        tr.appendChild(createNumberCell(timeConnected, formatPlaytime(timeConnected), 'Time Connected'));

        onlineTbody.appendChild(tr);
    });
}

async function fetchOnline() {
    try {
        const response = await fetch(onlineEndpoint, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Failed request');
        }
        const payload = await response.json();
        if (!payload || payload.success !== true || !Array.isArray(payload.players)) {
            throw new Error('Invalid payload');
        }
        const players = Array.isArray(payload.players) ? payload.players.slice() : [];
        visibleMaxPlayers = Number(payload.visible_max_players) || 32;
        renderOnline(players);
        updateOnlineButtonInfo(players.length, visibleMaxPlayers);
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch online stats:', err);
        updateOnlineButtonInfo(0, visibleMaxPlayers);
    }
}

function fetchLogsIfNeeded() {
    if (!logsInitialized) {
        ensureLogsInitialized();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initSorting();
    initTabs();
    fetchLogsIfNeeded();

    if (onlineTable && onlineTbody && onlineEmpty) {
        fetchOnline();
        setInterval(fetchOnline, onlineRefreshMs);
    }

    const pageStorageKey = 'wt:page';
    const params = new URLSearchParams(window.location.search);
    const searchValue = params.get('q');
    const initialPageParam = params.get('page');
    const storedPageValue = window.localStorage.getItem(pageStorageKey);

    if (!searchValue && !initialPageParam && storedPageValue && storedPageValue !== '1') {
        params.set('page', storedPageValue);
        const redirectUrl = window.location.pathname + '?' + params.toString();
        window.location.replace(redirectUrl);
        return;
    }

    const currentPageValue = (initialPageParam && /^\d+$/.test(initialPageParam) && parseInt(initialPageParam, 10) > 0)
        ? initialPageParam
        : '1';
    window.localStorage.setItem(pageStorageKey, currentPageValue);

    const searchPageInput = document.getElementById('search-page');
    if (searchPageInput) {
        searchPageInput.value = currentPageValue;
    }

    document.querySelectorAll('.table-pagination-controls a').forEach(link => {
        link.addEventListener('click', () => {
            try {
                const linkUrl = new URL(link.href, window.location.href);
                const linkPage = linkUrl.searchParams.get('page') || '1';
                window.localStorage.setItem(pageStorageKey, linkPage);
            } catch (e) {
                // ignore invalid URL
            }
        });
    });

    const searchForm = document.getElementById('search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', () => {
            const value = searchPageInput ? (searchPageInput.value || '1') : '1';
            window.localStorage.setItem(pageStorageKey, value);
        });
    }
});
</script>
</body>
</html>
