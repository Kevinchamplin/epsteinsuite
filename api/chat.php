<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$cacheDir = __DIR__ . '/../cache/';

function getChatCacheFile(string $ipHash): string
{
    global $cacheDir;
    return $cacheDir . 'chat_active_' . substr($ipHash, 0, 16) . '.cache';
}

function writeChatPresence(string $ipHash, string $nickname): void
{
    $file = getChatCacheFile($ipHash);
    file_put_contents($file, json_encode(['nick' => $nickname, 'ts' => time()]));
}

function readChatPresence(string $file): ?array
{
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = @json_decode($raw, true);
    // Handle old format (plain timestamp)
    if ($data === null || !is_array($data)) {
        return ['nick' => null, 'ts' => (int)$raw];
    }
    return $data;
}

function insertSystemMessage(PDO $pdo, string $text): ?array
{
    $stmt = $pdo->prepare('INSERT INTO chat_messages (nickname, message, ip_hash) VALUES (:nickname, :message, :ip)');
    $stmt->execute([
        'nickname' => '__system__',
        'message' => $text,
        'ip' => '',
    ]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("SELECT id, nickname, message, CONVERT_TZ(created_at, @@session.time_zone, '+00:00') AS created_at FROM chat_messages WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($method === 'GET') {
    try {
        $pdo = db();

        $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;
        $nick = isset($_GET['nick']) ? trim($_GET['nick']) : '';

        // Detect departed users before fetching messages
        $cutoff = time() - 60;
        foreach (glob($cacheDir . 'chat_active_*.cache') as $f) {
            $presence = readChatPresence($f);
            if (!$presence || $presence['ts'] < $cutoff) {
                if ($presence && !empty($presence['nick'])) {
                    insertSystemMessage($pdo, $presence['nick'] . ' left the chat');
                }
                @unlink($f);
            }
        }

        if ($after > 0) {
            $stmt = $pdo->prepare("SELECT id, nickname, message, CONVERT_TZ(created_at, @@session.time_zone, '+00:00') AS created_at FROM chat_messages WHERE id > :after ORDER BY id ASC LIMIT 50");
            $stmt->execute(['after' => $after]);
        } else {
            $stmt = $pdo->prepare("SELECT id, nickname, message, CONVERT_TZ(created_at, @@session.time_zone, '+00:00') AS created_at FROM chat_messages ORDER BY id DESC LIMIT 50");
            $stmt->execute();
        }

        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Reverse the DESC results so they're in chronological order for initial load
        if ($after === 0) {
            $messages = array_reverse($messages);
        }

        // Track this viewer as active
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($nick !== '') {
            writeChatPresence($ipHash, $nick);
        } else {
            // Keep existing nickname if already in cache, just update timestamp
            $existing = readChatPresence(getChatCacheFile($ipHash));
            $existingNick = ($existing && !empty($existing['nick'])) ? $existing['nick'] : '';
            writeChatPresence($ipHash, $existingNick);
        }

        // Count active users
        $onlineCount = 0;
        foreach (glob($cacheDir . 'chat_active_*.cache') as $f) {
            $presence = readChatPresence($f);
            if ($presence && $presence['ts'] >= $cutoff) {
                $onlineCount++;
            }
        }

        echo json_encode([
            'ok' => true,
            'messages' => $messages,
            'online_count' => $onlineCount,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load messages']);
    }
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = trim((string)($input['action'] ?? ''));
    $nickname = trim((string)($input['nickname'] ?? ''));

    if ($nickname === '' || mb_strlen($nickname) > 30) {
        http_response_code(400);
        echo json_encode(['error' => 'Nickname must be 1-30 characters']);
        exit;
    }

    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    // Handle join action
    if ($action === 'join') {
        try {
            $pdo = db();

            // Check if this IP already has an active session with this nickname
            $existing = readChatPresence(getChatCacheFile($ipHash));
            $alreadyHere = $existing && !empty($existing['nick']) && $existing['nick'] === $nickname && $existing['ts'] >= (time() - 60);

            writeChatPresence($ipHash, $nickname);

            if (!$alreadyHere) {
                $sysMsg = insertSystemMessage($pdo, $nickname . ' joined the chat');
                echo json_encode(['ok' => true, 'system_message' => $sysMsg]);
            } else {
                echo json_encode(['ok' => true]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to join']);
        }
        exit;
    }

    // Regular message send
    $message = trim((string)($input['message'] ?? ''));

    if ($message === '' || mb_strlen($message) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Message must be 1-500 characters']);
        exit;
    }

    try {
        $pdo = db();

        // Rate limit: max 1 message per 2 seconds per IP
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE ip_hash = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 2 SECOND)');
        $stmt->execute(['ip' => $ipHash]);
        if ((int)$stmt->fetchColumn() > 0) {
            http_response_code(429);
            echo json_encode(['error' => 'Slow down! You can send one message every 2 seconds.']);
            exit;
        }

        // Update presence on every message
        writeChatPresence($ipHash, $nickname);

        $stmt = $pdo->prepare('INSERT INTO chat_messages (nickname, message, ip_hash) VALUES (:nickname, :message, :ip)');
        $stmt->execute([
            'nickname' => $nickname,
            'message' => $message,
            'ip' => $ipHash,
        ]);

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT id, nickname, message, CONVERT_TZ(created_at, @@session.time_zone, '+00:00') AS created_at FROM chat_messages WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'message' => $row]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send message']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
