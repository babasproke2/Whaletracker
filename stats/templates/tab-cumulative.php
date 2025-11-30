<?php
// Cumulative stats tab content
?>
<div class="tab-content active" id="tab-cumulative">
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
                        <div class="detail-profile-links">
                            <a href="<?= htmlspecialchars($focused['profileurl']) ?>" target="_blank" rel="noopener">Steam Profile</a>
                        </div>
                    <?php endif; ?>
                    <div style="color:var(--text-muted);">
                        <?= number_format($focused['kills']) ?> K / <?= number_format($focused['deaths']) ?> D / <?= number_format($focused['assists']) ?> A
                    </div>
                </div>
            </div>
            <div class="detail-grid">
                <div>
                    <h3>Damage</h3>
                    <p<?= $damageAttr ?> title="Damage Dealt"><?= number_format($focusedDamage) ?></p>
                </div>
                <div>
                    <h3>DT</h3>
                    <p title="Damage Taken"><?= number_format($focusedDamageTaken) ?></p>
                </div>
                <div>
                    <h3>DPM</h3>
                    <p<?= $dpmAttr ?> title="Damage Per Minute"><?= number_format($focusedDpm, 1) ?></p>
                </div>
                <div>
                    <h3>DT/M</h3>
                    <p title="Damage Taken Minute"><?= number_format($focusedDtpm, 1) ?></p>
                </div>
                <div>
                    <h3>Accuracy</h3>
                    <p<?= $accuracyAttr ?> title="Shots hit vs fired"><?= number_format($focusedAccuracy, 1) ?>%</p>
                </div>
                <div>
                    <h3>Kills</h3>
                    <p title="Total Kills"><?= number_format($focused['kills']) ?></p>
                </div>
                <div>
                    <h3>Deaths</h3>
                    <p title="Total Deaths"><?= number_format($focused['deaths']) ?></p>
                </div>
                <div>
                    <h3>K/D</h3>
                    <p class="stat-kd-trigger <?= $kdClasses ?>" data-kd="<?= number_format($kdValue, 2) ?>" data-kd-average="<?= number_format($kdAverage, 2) ?>" data-kd-diff="<?= $kdDiffValue !== null ? number_format($kdDiffValue, 1) : '' ?>" title="<?= htmlspecialchars($kdTitle) ?>"><?= number_format($kdValue, 2) ?></p>
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

    <?= $summaryHtml ?>

    <div
        id="cumulative-fragment"
        data-per-page="<?= (int)$perPage ?>"
        data-player="<?= htmlspecialchars((string)($focusedPlayer ?? ''), ENT_QUOTES, 'UTF-8') ?>"
        data-fragment="/stats/cumulative_fragment.php"
        data-initial-url="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    >
        <div class="table-loading-message">Loading cumulative stats…</div>
        <noscript>
            <p class="table-noscript-warning">Enable JavaScript to view cumulative stats.</p>
        </noscript>
    </div>
</div>
