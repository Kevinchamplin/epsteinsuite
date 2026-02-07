<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$documentId = (int)($input['document_id'] ?? 0);
$referrer = mb_substr($input['referrer'] ?? 'unknown', 0, 50);

if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid document_id']);
    exit;
}

try {
    $pdo = db();

    // Ensure photo_views table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS photo_views (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            ip_hash CHAR(64) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            referrer VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_photo_views_doc (document_id),
            INDEX idx_photo_views_created (created_at),
            INDEX idx_photo_views_ip_doc (ip_hash, document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Verify document exists and is a photo type
    $stmt = $pdo->prepare("
        SELECT id FROM documents
        WHERE id = :id
          AND (LOWER(file_type) IN ('jpg','jpeg','png','gif','webp','tif','tiff')
               OR source_url REGEXP '\\\\.(jpg|jpeg|png|gif|webp|tif|tiff)(\\\\?|$)')
        LIMIT 1
    ");
    $stmt->execute([':id' => $documentId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['ok' => false, 'reason' => 'not_photo']);
        exit;
    }

    $ipHash = ai_hash_ip($_SERVER['REMOTE_ADDR'] ?? '');

    // Deduplicate: skip if same IP viewed same photo in last 5 minutes
    $dedupeStmt = $pdo->prepare("
        SELECT 1 FROM photo_views
        WHERE ip_hash = :ip_hash
          AND document_id = :document_id
          AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        LIMIT 1
    ");
    $dedupeStmt->execute([
        ':ip_hash' => $ipHash,
        ':document_id' => $documentId,
    ]);

    if ($dedupeStmt->fetchColumn()) {
        echo json_encode(['ok' => true, 'dedup' => true]);
        exit;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO photo_views (document_id, ip_hash, user_agent, referrer)
        VALUES (:document_id, :ip_hash, :user_agent, :referrer)
    ");
    $insertStmt->execute([
        ':document_id' => $documentId,
        ':ip_hash' => $ipHash,
        ':user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ':referrer' => $referrer,
    ]);

    echo json_encode(['ok' => true]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
