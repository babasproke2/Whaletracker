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

function wt_chat_fetch(PDO $pdo): array {
    $stmt = $pdo->query('SELECT id, created_at, steamid, personaname, iphash, message FROM whaletracker_chat ORDER BY id ASC');
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
        ];
    }

    return [$messages, $latestId];
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
    $path = '/home/kogasa/hlserver/tf2/tf/addons/sourcemod/configs/filters.cfg';
    if (!is_file($path)) {
        return $webnames = [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $webnames = [];
    }
    $section = false;
    $depth = 0;
    $result = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '/' || $trimmed[0] === ';') {
            continue;
        }
        if (!$section) {
            if (strpos($trimmed, '"webnames"') === 0) {
                $section = true;
            }
            continue;
        }
        if ($trimmed === '{') {
            $depth++;
            continue;
        }
        if ($trimmed === '}') {
            if ($depth <= 0) {
                break;
            }
            $depth--;
            if ($depth === 0) {
                break;
            }
            continue;
        }
        if ($depth > 0 && preg_match('/"([^"]+)"\s+"([^"]+)"/', $trimmed, $matches)) {
            $result[] = [
                'name' => $matches[1],
                'color' => $matches[2],
            ];
        }
    }
    return $webnames = $result;
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
        $fullPath = __DIR__ . '/' . $filename;
        if (!is_file($fullPath)) {
            continue;
        }
        $map[strtolower($name)] = '/stats/' . rawurlencode($filename);
    }
    return $map;
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
    if (empty($options)) {
        $persona = [
            'name' => 'Web Player',
            'color' => '{default}',
            'display' => '{gold}[Web]{default} Web Player{default}',
        ];
    } else {
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

function wt_chat_cache_key(): string {
    return 'all';
}

function wt_chat_cache_load(string $revision): ?array {
    $cached = wt_fragment_load('chat', wt_chat_cache_key(), $revision);
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

function wt_chat_cache_save(string $revision, array $messages, int $latestId): void {
    wt_fragment_save('chat', wt_chat_cache_key(), $revision, [
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
        $persona = null;
        if ($steamId) {
            $profiles = wt_fetch_steam_profiles([$steamId]);
            $personaname = $profiles[$steamId]['personaname'] ?? $steamId;
        } else {
            $persona = wt_chat_session_persona();
            $personaname = $persona['display'];
        }

        $pdo->beginTransaction();
        [$serverIp, $serverPort] = wt_chat_server_identity();

        $stmt = $pdo->prepare('INSERT INTO whaletracker_chat (created_at, steamid, personaname, iphash, message, server_ip, server_port) VALUES (:ts, :steamid, :personaname, :iphash, :msg, :server_ip, :server_port)');
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

// GET: return recent chat
try {
    $pdo = wt_pdo();
    wt_chat_db_init($pdo);
    $revision = wt_chat_revision($pdo);
    $cached = wt_chat_cache_load($revision);
    if ($cached) {
        $messages = $cached['messages'];
    } else {
        [$messages, $latestId] = wt_chat_fetch($pdo);
        wt_chat_cache_save($revision, $messages, $latestId);
    }
    wt_chat_json(['ok' => true, 'messages' => $messages, 'revision' => $revision]);
} catch (Throwable $e) {
    wt_chat_log('GET failure: ' . $e->getMessage());
    wt_chat_json(['ok' => false, 'error' => 'server'], 500);
}
