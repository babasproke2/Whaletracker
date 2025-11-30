<?php
$playersWeekTrendClassEsc = htmlspecialchars($playersWeekTrendClass, ENT_QUOTES, 'UTF-8');
$playersWeekTooltipEsc = htmlspecialchars($playersWeekTooltip, ENT_QUOTES, 'UTF-8');
$playersWeekChangeLabelEsc = htmlspecialchars($playersWeekChangeLabel, ENT_QUOTES, 'UTF-8');
$playtimeMonthLabelEsc = htmlspecialchars($playtimeMonthLabel, ENT_QUOTES, 'UTF-8');
?>
<section class="stat-grid">
    <div class="stat-card stat-card--whales" title="<?= $playersWeekTooltipEsc ?>">
        <h3>Total Whales</h3>
        <div class="whales-breakdown">
            <div class="whales-row">
                <span class="whales-label">All time:</span>
                <span class="whales-value"><?= number_format($totalPlayersAllTime) ?></span>
            </div>
            <div class="whales-row">
                <span class="whales-label">Month:</span>
                <span class="whales-value"><?= number_format($playersMonthCurrent) ?></span>
                <span class="whales-delta <?= $playersWeekTrendClassEsc ?>" aria-label="Monthly change not computed">—</span>
            </div>
            <div class="whales-row">
                <span class="whales-label">Week:</span>
                <span class="whales-value"><?= number_format($playersWeekCurrent) ?></span>
                <span class="whales-delta <?= $playersWeekTrendClassEsc ?>"><?= $playersWeekChangeLabelEsc ?></span>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <h3>Total Kills</h3>
        <p><?= number_format($summary['totalKills'] ?? 0) ?></p>
    </div>
    <div class="stat-card stat-card--month" title="Playtime logged in <?= $playtimeMonthLabelEsc ?>">
        <div class="stat-card-heading">
            <h3>Playtime / <?= $playtimeMonthLabelEsc ?></h3>
        </div>
        <p><?= number_format($playtimeMonthHours, 1) ?> hrs</p>
    </div>
    <div class="stat-card">
        <h3>Total Healing</h3>
        <p><?= number_format($summary['totalHealing'] ?? 0) ?></p>
    </div>
    <div class="stat-card">
        <h3>Total Headshots</h3>
        <p><?= number_format($summary['totalHeadshots'] ?? 0) ?></p>
    </div>
    <div class="stat-card">
        <h3>Total Backstabs</h3>
        <p><?= number_format($summary['totalBackstabs'] ?? 0) ?></p>
    </div>
    <div class="stat-card stat-card-streak">
        <h3>Best Killstreak</h3>
        <?php if (!empty($summary['topKillstreakOwner'])): ?>
            <?php $owner = $summary['topKillstreakOwner']; ?>
            <div class="stat-card-player">
                <img src="<?= htmlspecialchars($owner['avatar'] ?? $defaultAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <div>
                    <div class="stat-card-value"><?= number_format($summary['topKillstreak'] ?? 0) ?></div>
                    <div class="stat-card-player-name"><?= htmlspecialchars($owner['personaname'] ?? ($owner['steamid'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        <?php else: ?>
            <p><?= number_format($summary['topKillstreak'] ?? 0) ?></p>
        <?php endif; ?>
    </div>
    <div class="stat-card stat-card-streak">
        <h3>Best Killstreak / Week</h3>
        <?php if (!empty($bestKillstreakWeekLeaders)): ?>
            <div class="streak-podium">
                <?php foreach (array_slice($bestKillstreakWeekLeaders, 0, 3) as $index => $leader): ?>
                    <?php $rank = $index + 1; ?>
                    <div class="streak-podium__entry streak-podium__entry--rank<?= $rank ?>">
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
        <p><?= number_format($summary['totalDamage'] ?? 0) ?></p>
    </div>
    <div class="stat-card">
        <h3>Total Damage Taken</h3>
        <p><?= number_format($summary['totalDamageTaken'] ?? 0) ?></p>
    </div>
    <div class="stat-card stat-card-streak">
        <h3>Highest DPM / Week</h3>
        <?php if (!empty($weeklyTopDpmOwner)): ?>
            <?php $owner = $weeklyTopDpmOwner; ?>
            <div class="stat-card-player">
                <img src="<?= htmlspecialchars($owner['avatar'] ?? $defaultAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <div>
                    <div class="stat-card-value"><?= number_format($weeklyTopDpm, 1) ?></div>
                    <div class="stat-card-player-name"><?= htmlspecialchars($owner['personaname'] ?? ($owner['steamid'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></div>
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
                        <span class="gamemode-share"><?= number_format((float)($mode['percentage'] ?? 0), 1) ?>%</span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="stat-card-empty">Logs loading or unavailable...</p>
        <?php endif; ?>
    </div>
</section>
