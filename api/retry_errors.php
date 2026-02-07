<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$clearPages = !empty($payload['clear_pages']);

$requiredToken = getenv('REPROCESS_TOKEN');
if (is_string($requiredToken) && trim($requiredToken) !== '') {
    $provided = '';
    if (!empty($_SERVER['HTTP_X_REPROCESS_TOKEN'])) {
        $provided = (string)$_SERVER['HTTP_X_REPROCESS_TOKEN'];
    } elseif (isset($payload['token'])) {
        $provided = (string)$payload['token'];
    }

    if (!hash_equals(trim($requiredToken), trim($provided))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->query("SELECT id FROM documents WHERE status = 'error'");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $count = count($ids);

    if ($count === 0) {
        $pdo->rollBack();
        echo json_encode(['ok' => true, 'count' => 0]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, $count, '?'));

    if ($clearPages) {
        $delPages = $pdo->prepare("DELETE FROM pages WHERE document_id IN ($placeholders)");
        $delPages->execute($ids);
    }

    try {
        $delErrors = $pdo->prepare("DELETE FROM ingestion_errors WHERE document_id IN ($placeholders)");
        $delErrors->execute($ids);
    } catch (Throwable $e) {
        // Table might not exist yet; ignore.
    }

    $update = $pdo->prepare("UPDATE documents
        SET status = 'pending',
            ai_summary = NULL,
            description = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id IN ($placeholders)");
    $update->execute($ids);

    $pdo->commit();

    echo json_encode(['ok' => true, 'count' => $count, 'clear_pages' => $clearPages]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
