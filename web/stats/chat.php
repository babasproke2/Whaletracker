<?php
session_start();
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

function wt_chat_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function wt_chat_log(string $message): void {
    error_log('[LiveChat] ' . $message);
}

// Helpers
function wt_chat_db_init(PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS whaletracker_chat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at INT NOT NULL,
            steamid VARCHAR(32) NULL,
            personaname VARCHAR(128) NULL,
            iphash VARCHAR(64) NULL,
            message TEXT NOT NULL,
            INDEX(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS whaletracker_chat_outbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at INT NOT NULL,
            iphash VARCHAR(64) NOT NULL,
            message TEXT NOT NULL,
            INDEX(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function wt_chat_fetch(PDO $pdo, int $limit = 200, ?int $cutoff = null): array {
    if ($cutoff === null) {
        $cutoff = time() - 86400;
    }
    $stmt = $pdo->prepare('SELECT id, created_at, steamid, personaname, iphash, message FROM whaletracker_chat WHERE created_at >= :cut ORDER BY id DESC LIMIT ' . (int)$limit);
    $stmt->bindValue(':cut', $cutoff, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Build avatar map for steamids
    $steamIds = [];
    $latestId = 0;
    foreach ($rows as $r) {
        if (!empty($r['steamid'])) {
            $steamIds[] = (string)$r['steamid'];
        }
        $latestId = max($latestId, (int)($r['id'] ?? 0));
    }
    $profiles = wt_fetch_steam_profiles(array_values(array_unique($steamIds)));
    $defaultAvatar = WT_DEFAULT_AVATAR_URL;
    $messages = [];
    foreach (array_reverse($rows) as $r) { // reverse to ascending time
        $sid = $r['steamid'] ?? null;
        $profile = $sid && isset($profiles[$sid]) ? $profiles[$sid] : null;
        $iphash = $r['iphash'] ?? null;
        $avatar = $profile['avatarfull'] ?? null;
        if ($iphash === 'system') {
            $avatar = WT_SERVER_AVATAR_URL;
        } elseif ($avatar === null) {
            $avatar = wt_avatar_for_hash($iphash);
        }

        $messages[] = [
            'id' => (int)($r['id'] ?? 0),
            'created_at' => (int)($r['created_at'] ?? 0),
            'steamid' => $sid,
            'name' => $r['personaname'] ?: ($profile['personaname'] ?? ($sid ?: ($iphash ? ('Web Player #' . substr($iphash, 0, 6)) : 'Unknown'))),
            'avatar' => $avatar ?: $defaultAvatar,
            'message' => (string)($r['message'] ?? ''),
        ];
    }

    return [$messages, $latestId];
}

function wt_short_iphash(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $secret = WT_CHAT_IP_SECRET;
    return substr(hash('sha256', $secret . '|' . $ip), 0, 8);
}

function wt_chat_revision(PDO $pdo, int $limit = 200, ?int $cutoff = null): string {
    if ($cutoff === null) {
        $cutoff = time() - 86400;
    }
    $limit = max(1, (int)$limit);
    try {
        $pdo->exec('SET SESSION group_concat_max_len = 262144');
    } catch (Throwable $e) {
        // best effort; continue if it fails
    }
    $sql = 'SELECT MD5(GROUP_CONCAT(row_hash ORDER BY chat_id DESC SEPARATOR "#")) AS digest '
         . 'FROM ('
         . 'SELECT id AS chat_id, MD5(CONCAT_WS("|", id, created_at, COALESCE(steamid, ""), COALESCE(personaname, ""), COALESCE(iphash, ""), COALESCE(message, ""))) AS row_hash '
         . 'FROM whaletracker_chat WHERE created_at >= :cut ORDER BY id DESC LIMIT ' . $limit
         . ') recent';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cut', $cutoff, PDO::PARAM_INT);
    $stmt->execute();
    $hash = $stmt->fetchColumn();
    if (!$hash) {
        return 'empty:' . $limit;
    }
    return $hash . ':' . $limit;
}

function wt_chat_cache_key(int $limit): string {
    return 'limit' . max(1, (int)$limit);
}

function wt_chat_cache_load(int $limit, string $revision): ?array {
    $cached = wt_fragment_load('chat', wt_chat_cache_key($limit), $revision);
    if (!$cached) {
        return null;
    }
    $messages = $cached['messages'] ?? null;
    if (!is_array($messages)) {
        return null;
    }
    return [
        'messages' => $messages,
        'latest_id' => (int)($cached['latest_id'] ?? 0),
    ];
}

function wt_chat_cache_save(int $limit, string $revision, array $messages, int $latestId): void {
    wt_fragment_save('chat', wt_chat_cache_key($limit), $revision, [
        'html' => '',
        'messages' => $messages,
        'latest_id' => $latestId,
        'count' => count($messages),
    ]);
}

// POST: enqueue chat to DB outbox (rate-limited per IP)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 180) {
            wt_chat_log('Rejected invalid message payload');
            wt_chat_json(['ok' => false, 'error' => 'invalid'], 400);
        }
        // Rate limit: 1 message per 5 seconds per IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $rateKey = 'wt:chat:rate:' . $ip;
        $recent = wt_cache_get($rateKey);
        if ($recent !== null) {
            wt_chat_log('Rate limit hit for IP ' . $ip);
            wt_chat_json(['ok' => false, 'error' => 'rate'], 429);
        }
        wt_cache_set($rateKey, 1, 5);

        $pdo = wt_pdo();
        wt_chat_db_init($pdo);
        $hash = wt_short_iphash();
        $now = time();
        $steamId = wt_is_logged_in() ? wt_current_user_id() : null;
        $personaname = null;
        if ($steamId) {
            $profiles = wt_fetch_steam_profiles([$steamId]);
            $personaname = $profiles[$steamId]['personaname'] ?? $steamId;
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO whaletracker_chat (created_at, steamid, personaname, iphash, message) VALUES (:ts, :steamid, :personaname, :iphash, :msg)');
        $displayName = $personaname;
        if ($steamId && $personaname) {
            $displayName = $personaname . " | Web";
        }
        $stmt->execute([
            ':ts' => $now,
            ':steamid' => $steamId,
            ':personaname' => $displayName,
            ':iphash' => $hash,
            ':msg' => $message,
        ]);
        $outboxName = $displayName ?: ('Web Player # ' . $hash);
        $stmt2 = $pdo->prepare('INSERT INTO whaletracker_chat_outbox (created_at, iphash, display_name, message) VALUES (:ts, :iphash, :name, :msg)');
        $stmt2->execute([':ts' => $now, ':iphash' => $hash, ':name' => $outboxName, ':msg' => $message]);
        $pdo->commit();

        wt_chat_log(sprintf('Web message queued from %s hash %s: %s', $ip, $hash, $message));
        wt_chat_json(['ok' => true]);
    } catch (Throwable $e) {
        wt_chat_log('POST failure: ' . $e->getMessage());
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        wt_chat_json(['ok' => false, 'error' => 'server'], 500);
    }
}

// GET: return recent chat
try {
    $pdo = wt_pdo();
    wt_chat_db_init($pdo);
    $limit = 200;
    $cutoff = time() - 86400;
    $revision = wt_chat_revision($pdo, $limit, $cutoff);
    $cached = wt_chat_cache_load($limit, $revision);
    if ($cached) {
        $messages = $cached['messages'];
    } else {
        [$messages, $latestId] = wt_chat_fetch($pdo, $limit, $cutoff);
        wt_chat_cache_save($limit, $revision, $messages, $latestId);
    }
    wt_chat_json(['ok' => true, 'messages' => $messages, 'revision' => $revision]);
} catch (Throwable $e) {
    wt_chat_log('GET failure: ' . $e->getMessage());
    wt_chat_json(['ok' => false, 'error' => 'server'], 500);
}
