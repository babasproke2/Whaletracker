<div class="table-toolbar">
    <form class="search-bar toolbar-search" method="get" action="/stats/index.php" data-rate-limit-ms="1500">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search players by Steam name or SteamID">
        <button type="submit">Search</button>
        <p class="toolbar-search__rate-notice" aria-live="polite" hidden></p>
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
        <?= wt_render_cumulative_rows($pageStats, $focusedPlayer, $defaultAvatarUrl) ?>
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
