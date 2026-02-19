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
            server_ip VARCHAR(64) NULL,
            server_port INT NULL,
            alert TINYINT(1) NOT NULL DEFAULT 1,
            INDEX(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS whaletracker_chat_outbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at INT NOT NULL,
            iphash VARCHAR(64) NOT NULL,
            display_name VARCHAR(128) DEFAULT \'\',
            message TEXT NOT NULL,
            server_ip VARCHAR(64) NULL,
            server_port INT NULL,
            delivered_to TEXT NULL,
            INDEX(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    try {
        $pdo->exec('ALTER TABLE whaletracker_chat ADD COLUMN alert TINYINT(1) NOT NULL DEFAULT 1 AFTER server_port');
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate column name') === false) {
            throw $e;
        }
    }
}

function wt_chat_server_identity(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ip = getenv('WT_CHAT_SERVER_IP');
    if ($ip === false || $ip === '') {
        $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
    }
    $port = getenv('WT_CHAT_SERVER_PORT');
    if ($port === false || $port === '') {
        $port = 443;
    }
    $cached = [$ip, (int)$port];
    return $cached;
}

function wt_chat_fetch(PDO $pdo, int $limit = 50, ?int $beforeId = null, ?int $afterId = null, bool $alertsOnly = false): array {
    $limit = max(1, min($limit, 200));
    $rows = [];
    $selectCols = 'SELECT id, created_at, steamid, personaname, iphash, message, alert FROM whaletracker_chat';
    if ($afterId !== null && $afterId > 0) {
        $sql = $selectCols . ' WHERE id > :after';
        if ($alertsOnly) {
            $sql .= ' AND alert = 1';
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $queryLimit = $limit + 1;
        $sql = $selectCols;
        $conditions = [];
        if ($beforeId !== null && $beforeId > 0) {
            $conditions[] = 'id < :before';
        }
        if ($alertsOnly) {
            $conditions[] = 'alert = 1';
        }
        if (!empty($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $queryLimit;
        $stmt = $pdo->prepare($sql);
        if ($beforeId !== null && $beforeId > 0) {
            $stmt->bindValue(':before', $beforeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasExtra = count($rows) > $limit;
        if ($hasExtra) {
            array_pop($rows);
        }
        $rows = array_reverse($rows);
    }

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
    foreach ($rows as $r) {
        $sid = $r['steamid'] ?? null;
        $profile = $sid && isset($profiles[$sid]) ? $profiles[$sid] : null;
        $iphash = $r['iphash'] ?? null;
        $avatar = $profile['avatarfull'] ?? null;
        if ($iphash === 'system') {
            $avatar = WT_SERVER_AVATAR_URL;
        } elseif (!$sid) {
            $customAvatar = wt_chat_persona_avatar($r['personaname'] ?? null);
            if ($customAvatar !== null) {
                $avatar = $customAvatar;
            } elseif ($avatar === null) {
                $avatar = wt_avatar_for_hash($iphash);
            }
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
            'alert' => ((int)($r['alert'] ?? 1)) === 1,
        ];
    }

    $oldestId = !empty($messages) ? (int)($messages[0]['id'] ?? 0) : null;
    $newestId = !empty($messages) ? (int)($messages[count($messages) - 1]['id'] ?? 0) : null;
    $hasMoreOlder = false;
    if ($afterId === null) {
        $hasMoreOlder = isset($hasExtra) ? $hasExtra : false;
    }

    return [$messages, [
        'latest_id' => $latestId,
        'oldest_id' => $oldestId,
        'newest_id' => $newestId,
        'has_more_older' => $hasMoreOlder,
    ]];
}

function wt_short_iphash(): string {
    $session = session_id();
    if ($session === '') {
        $session = bin2hex(random_bytes(16));
    }
    $secret = WT_CHAT_IP_SECRET;
    return substr(hash('sha256', $secret . '|' . $session), 0, 8);
}

function wt_chat_webnames(): array {
    static $webnames = null;
    if (is_array($webnames)) {
        return $webnames;
    }

    try {
        $pdo = wt_pdo();
        $stmt = $pdo->query('SELECT name, color FROM whaletracker_webnames ORDER BY name ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        wt_chat_log('webnames db lookup failed: ' . $e->getMessage());
        $rows = [];
    }

    $webnames = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $color = trim((string)($row['color'] ?? ''));
        if ($name === '' || $color === '') {
            continue;
        }
        $webnames[] = ['name' => $name, 'color' => $color];
    }
    return $webnames;
}

function wt_chat_webname_image_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (wt_chat_webnames() as $entry) {
        $name = trim($entry['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $filename = $name . '.jpg';
        $fullPath = __DIR__ . '/assets/' . $filename;
        if (!is_file($fullPath)) {
            continue;
        }
        $map[strtolower($name)] = '/stats/assets/' . rawurlencode($filename);
    }
    return $map;
}

function wt_chat_find_persona_option(string $query): ?array {
    $query = trim($query);
    if ($query === '') {
        return null;
    }
    $options = wt_chat_webnames();
    if (empty($options)) {
        wt_chat_log('Webnames list is empty; unable to match persona command "' . $query . '"');
        return null;
    }
    foreach ($options as $option) {
        $name = trim((string)($option['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (stripos($name, $query) !== false) {
            return $option;
        }
    }
    return null;
}

function wt_chat_persona_avatar(?string $personaDisplay): ?string {
    if ($personaDisplay === null || $personaDisplay === '') {
        return null;
    }
    $plain = preg_replace('/\{[^}]+\}/', '', $personaDisplay);
    $plain = trim($plain ?? '');
    if ($plain === '') {
        return null;
    }
    $plain = preg_replace('/^\[Web\]\s*/i', '', $plain);
    $plain = trim($plain);
    if ($plain === '') {
        return null;
    }
    $map = wt_chat_webname_image_map();
    $key = strtolower($plain);
    return $map[$key] ?? null;
}

function wt_chat_session_persona(): array {
    if (isset($_SESSION['wt_chat_persona']) && is_array($_SESSION['wt_chat_persona'])) {
        return $_SESSION['wt_chat_persona'];
    }
    $options = wt_chat_webnames();
    if (!empty($options)) {
        $choice = $options[random_int(0, count($options) - 1)];
        $display = '{gold}[Web]{default} ' . $choice['color'] . $choice['name'];
        if (substr($display, -9) !== '{default}') {
            $display .= '{default}';
        }
        $persona = [
            'name' => $choice['name'],
            'color' => $choice['color'],
            'display' => $display,
        ];
    } else {
        // No webnames available; generate a unique guest label so we never use the plain Web Player text.
        $tag = substr(bin2hex(random_bytes(3)), 0, 6);
        $display = '{gold}[Web]{default} Guest-' . $tag . '{default}';
        $persona = [
            'name' => 'Guest-' . $tag,
            'color' => '{default}',
            'display' => $display,
        ];
    }
    $_SESSION['wt_chat_persona'] = $persona;
    return $persona;
}

function wt_chat_revision(PDO $pdo): string {
    $stmt = $pdo->query('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS latest FROM whaletracker_chat');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    $total = (int)($row['total'] ?? 0);
    $latest = (int)($row['latest'] ?? 0);
    return $total . ':' . $latest;
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
        $persona = null;
        if ($steamId) {
            $profiles = wt_fetch_steam_profiles([$steamId]);
            $personaname = $profiles[$steamId]['personaname'] ?? $steamId;
        } else {
            $persona = wt_chat_session_persona();
            $personaname = $persona['display'];
        }

        if (!$steamId && strncmp($message, '/', 1) === 0) {
            $command = trim(substr($message, 1));
            $match = wt_chat_find_persona_option($command);
            if ($match !== null) {
                $display = '{gold}[Web]{default} ' . $match['color'] . $match['name'];
                if (substr($display, -9) !== '{default}') {
                    $display .= '{default}';
                }
                $persona = [
                    'name' => $match['name'],
                    'color' => $match['color'],
                    'display' => $display,
                ];
                $_SESSION['wt_chat_persona'] = $persona;
                wt_chat_json(['ok' => true, 'persona' => $persona, 'message' => 'persona-updated']);
            }

            // Debug: return full persona list when not found
            wt_chat_json([
                'ok' => true,
                'message' => 'persona-not-found',
                'options' => wt_chat_webnames(),
            ]);
        }

        $pdo->beginTransaction();
        [$serverIp, $serverPort] = wt_chat_server_identity();

        $stmt = $pdo->prepare('INSERT INTO whaletracker_chat (created_at, steamid, personaname, iphash, message, server_ip, server_port, alert) VALUES (:ts, :steamid, :personaname, :iphash, :msg, :server_ip, :server_port, :alert)');
        $displayName = $personaname;
        if ($steamId && $personaname) {
            $displayName = $personaname . " | Web";
        } elseif ($persona !== null) {
            $displayName = $persona['display'];
        }
        $stmt->execute([
            ':ts' => $now,
            ':steamid' => $steamId,
            ':personaname' => $displayName,
            ':iphash' => $hash,
            ':msg' => $message,
            ':server_ip' => $serverIp,
            ':server_port' => $serverPort,
            ':alert' => 1,
        ]);
        $outboxName = $displayName ?: 'Web Player';
        $stmt2 = $pdo->prepare('INSERT INTO whaletracker_chat_outbox (created_at, iphash, display_name, message, server_ip, server_port) VALUES (:ts, :iphash, :name, :msg, :server_ip, :server_port)');
        $stmt2->execute([
            ':ts' => $now,
            ':iphash' => $hash,
            ':name' => $outboxName,
            ':msg' => $message,
            ':server_ip' => $serverIp,
            ':server_port' => $serverPort,
        ]);
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

// GET: return paginated chat
try {
    $pdo = wt_pdo();
    wt_chat_db_init($pdo);
    $limitParam = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = max(1, min($limitParam, 200));
    $beforeId = isset($_GET['before']) ? (int)$_GET['before'] : null;
    $afterId = isset($_GET['after']) ? (int)$_GET['after'] : null;
    if ($afterId !== null && $afterId <= 0) {
        $afterId = null;
    }
    if ($beforeId !== null && $beforeId <= 0) {
        $beforeId = null;
    }
    if ($afterId !== null) {
        $beforeId = null;
    }
    $alertsOnly = isset($_GET['alerts_only']) && (int)$_GET['alerts_only'] === 1;
    [$messages, $meta] = wt_chat_fetch($pdo, $limit, $beforeId, $afterId, $alertsOnly);
    wt_chat_json([
        'ok' => true,
        'messages' => $messages,
        'oldest_id' => $meta['oldest_id'] ?? null,
        'newest_id' => $meta['newest_id'] ?? null,
        'latest_id' => $meta['latest_id'] ?? null,
        'has_more_older' => $meta['has_more_older'] ?? false,
    ]);
} catch (Throwable $e) {
    wt_chat_log('GET failure: ' . $e->getMessage());
    wt_chat_json(['ok' => false, 'error' => 'server'], 500);
}
