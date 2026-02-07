<?php
// migrate_batch_tracking.php - Creates ingestion_batches table and adds documents.batch_id.
// Run: php migrate_batch_tracking.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    $dbName = env_value('DB_NAME');
    if (!$dbName) {
        throw new RuntimeException('DB_NAME missing; cannot run migration.');
    }

    // 1. Create ingestion_batches table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ingestion_batches` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `batch_name` VARCHAR(255) NOT NULL,
            `data_set` VARCHAR(255) DEFAULT NULL,
            `total_documents` INT DEFAULT 0,
            `processed_documents` INT DEFAULT 0,
            `failed_documents` INT DEFAULT 0,
            `status` ENUM('pending', 'running', 'paused', 'completed', 'error') DEFAULT 'pending',
            `started_at` TIMESTAMP NULL,
            `completed_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`status`),
            INDEX (`data_set`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "ingestion_batches table ready.\n";

    // 2. Add documents.batch_id column
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );
    $stmt->execute(['db' => $dbName, 't' => 'documents', 'c' => 'batch_id']);

    if ($stmt->fetchColumn()) {
        echo "documents.batch_id already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `documents` ADD COLUMN `batch_id` INT DEFAULT NULL AFTER `data_set`");
        echo "Added documents.batch_id.\n";
    }

    // 3. Add index on batch_id if missing
    $idxStmt = $pdo->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'documents' AND INDEX_NAME = 'idx_documents_batch'
        LIMIT 1
    ");
    $idxStmt->execute(['db' => $dbName]);

    if ($idxStmt->fetchColumn()) {
        echo "idx_documents_batch index already exists.\n";
    } else {
        $pdo->exec("ALTER TABLE `documents` ADD INDEX `idx_documents_batch` (`batch_id`)");
        echo "Added idx_documents_batch index.\n";
    }

    echo "Migration complete.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
