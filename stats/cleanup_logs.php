<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$cutoff = time() - 3 * 86400;
$pdo = wt_pdo();
try {
    $pdo->beginTransaction();
    $logsTable = wt_logs_table();
    $playersTable = wt_log_players_table();

    $logIdsStmt = $pdo->prepare("SELECT log_id FROM $logsTable WHERE started_at < :cutoff");
    $logIdsStmt->execute([':cutoff' => $cutoff]);
    $logIds = $logIdsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($logIds)) {
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));
        $deletePlayersSql = "DELETE FROM $playersTable WHERE log_id IN ($placeholders)";
        $deleteLogsSql = "DELETE FROM $logsTable WHERE log_id IN ($placeholders)";
        $pdo->prepare($deletePlayersSql)->execute($logIds);
        $pdo->prepare($deleteLogsSql)->execute($logIds);
        echo "Deleted logs " . implode(', ', $logIds) . "\n";
    } else {
        echo "No old logs to delete.\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Failed to delete logs: " . $e->getMessage() . "\n");
    exit(1);
}
