<?php
require_once __DIR__ . '/functions.php';

$page = isset($argv[1]) ? (int)$argv[1] : 1;
$perPage = isset($argv[2]) ? (int)$argv[2] : 50;
$delay = isset($argv[3]) ? (int)$argv[3] : 15;

try {
    $results = wt_download_cumulative_page_avatars(
        $page,
        $perPage,
        $delay,
        static function (array $entry, int $index, int $total): void {
            $status = $entry['success']
                ? sprintf('cached (%s)', $entry['avatar_cached'] ?? 'no file')
                : 'failed';
            printf(
                "[%d/%d] %s (%s) - %s\n",
                $index + 1,
                $total,
                $entry['personaname'],
                $entry['steamid'],
                $status
            );
        },
        null
    );
    printf("Processed %d players on page %d.\n", count($results), $page);
} catch (Throwable $e) {
    fwrite(STDERR, "[error] " . $e->getMessage() . "\n");
    exit(1);
}
