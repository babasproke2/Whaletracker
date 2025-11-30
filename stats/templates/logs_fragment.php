<?php if (empty($logs)): ?>
    <div class="empty-state">Logs loading or unavailable...</div>
<?php else: ?>
    <?php foreach ($logs as $index => $log): ?>
        <?= wt_render_single_log($log, $index) ?>
    <?php endforeach; ?>
<?php endif; ?>
