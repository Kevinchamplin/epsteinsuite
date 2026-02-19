<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

// Authentication check
$adminUser = env_value('ADMIN_USER') ?: 'admin';
$adminPass = env_value('ADMIN_PASSWORD');

if (!$adminPass) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ADMIN_PASSWORD is not set']);
    exit;
}

$valid = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
    && hash_equals($adminUser, $_SERVER['PHP_AUTH_USER'])
    && hash_equals($adminPass, $_SERVER['PHP_AUTH_PW']);

if (!$valid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Method check
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse input
$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

// Validate parameters
$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$action = trim($payload['action'] ?? '');

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid document id']);
    exit;
}

$allowedActions = ['approve', 'reject', 'delete'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action. Must be: approve, reject, or delete']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Verify document exists and is user-uploaded
    $stmt = $pdo->prepare('SELECT id, title, local_path, upload_source, approval_status FROM documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch();

    if (!$doc) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Document not found']);
        exit;
    }

    if ($doc['upload_source'] !== 'user_upload') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Only user uploads can be moderated']);
        exit;
    }

    $username = $_SERVER['PHP_AUTH_USER'];
    $now = date('Y-m-d H:i:s');

    // Perform action
    if ($action === 'approve') {
        // Approve: set approval_status='approved', change status to 'downloaded' so pipeline processes it
        $stmt = $pdo->prepare("
            UPDATE documents
            SET approval_status = 'approved',
                status = CASE WHEN status = 'pending' THEN 'downloaded' ELSE status END,
                approved_by = :by,
                approved_at = :at
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id, ':by' => $username, ':at' => $now]);

        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'message' => "Document {$id} approved and queued for processing"
        ]);

    } elseif ($action === 'reject') {
        // Reject: set approval_status='rejected', keep file but hide from search
        $stmt = $pdo->prepare("
            UPDATE documents
            SET approval_status = 'rejected',
                approved_by = :by,
                approved_at = :at
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id, ':by' => $username, ':at' => $now]);

        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'message' => "Document {$id} rejected"
        ]);

    } elseif ($action === 'delete') {
        // Delete: remove file and database record
        $localPath = $doc['local_path'];

        // Delete database record (cascades to pages, document_entities, etc.)
        $stmt = $pdo->prepare('DELETE FROM documents WHERE id = :id');
        $stmt->execute([':id' => $id]);

        // Delete file from disk
        if ($localPath && file_exists($localPath)) {
            @unlink($localPath);
        }

        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'message' => "Document {$id} permanently deleted"
        ]);
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Moderation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
