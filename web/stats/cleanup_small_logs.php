<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = wt_pdo();
try {
    $pdo->beginTransaction();
    $logsTable = wt_logs_table();
    $playersTable = wt_log_players_table();
    $threshold = 20;

    echo "Identifying logs with fewer than {$threshold} players...\n";

    $sql = "
        SELECT l.log_id
        FROM $logsTable l
        LEFT JOIN (
            SELECT log_id, COUNT(DISTINCT steamid) AS actual_players
            FROM $playersTable
            GROUP BY log_id
        ) p ON l.log_id = p.log_id
        WHERE GREATEST(COALESCE(p.actual_players, 0), COALESCE(l.player_count, 0)) < :threshold
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':threshold' => $threshold]);
    $logIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($logIds)) {
        $count = count($logIds);
        echo "Found $count logs to delete.\n";

        $chunks = array_chunk($logIds, 100);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $deletePlayersSql = "DELETE FROM $playersTable WHERE log_id IN ($placeholders)";
            $pdo->prepare($deletePlayersSql)->execute($chunk);

            $deleteLogsSql = "DELETE FROM $logsTable WHERE log_id IN ($placeholders)";
            $pdo->prepare($deleteLogsSql)->execute($chunk);
        }

        echo "Successfully deleted $count logs.\n";
    } else {
        echo "No logs found with fewer than {$threshold} players.\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Failed to delete logs: " . $e->getMessage() . "\n");
    exit(1);
}
