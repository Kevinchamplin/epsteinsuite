<?php
// migrate_processing_priority.php - Adds documents.processing_priority for triage-based processing.
// Run: php migrate_processing_priority.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    $dbName = env_value('DB_NAME');
    if (!$dbName) {
        throw new RuntimeException('DB_NAME missing; cannot run migration.');
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );
    $stmt->execute(['db' => $dbName, 't' => 'documents', 'c' => 'processing_priority']);

    if ($stmt->fetchColumn()) {
        echo "documents.processing_priority already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `documents` ADD COLUMN `processing_priority` TINYINT DEFAULT 5 AFTER `status`");
        echo "Added documents.processing_priority.\n";
    }

    // Add compound index for priority-based queries
    $idxStmt = $pdo->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_documents_priority_status'
        LIMIT 1
    ");
    $idxStmt->execute(['db' => $dbName]);

    if ($idxStmt->fetchColumn()) {
        echo "idx_documents_priority_status index already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `documents` ADD INDEX `idx_documents_priority_status` (`processing_priority`, `status`)");
        echo "Added idx_documents_priority_status index.\n";
    }

    echo "Migration complete.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
