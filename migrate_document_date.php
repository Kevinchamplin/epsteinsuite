<?php
/**
 * Migration script to add the document_date column and index.
 */
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();

    $columnStmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'documents'
          AND COLUMN_NAME = 'document_date'
    ");
    $columnStmt->execute();
    $hasColumn = (int)$columnStmt->fetchColumn() > 0;

    if (!$hasColumn) {
        $pdo->exec("
            ALTER TABLE documents
            ADD COLUMN document_date DATETIME NULL AFTER file_type,
            ADD INDEX idx_document_date (document_date)
        ");
        echo "Added document_date column and index.\n";
    } else {
        // Ensure index exists even if column was added manually.
        $indexStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'documents'
              AND INDEX_NAME = 'idx_document_date'
        ");
        $indexStmt->execute();
        $hasIndex = (int)$indexStmt->fetchColumn() > 0;
        if (!$hasIndex) {
            $pdo->exec("ALTER TABLE documents ADD INDEX idx_document_date (document_date)");
            echo "Added idx_document_date index.\n";
        } else {
            echo "document_date column and index already exist.\n";
        }
    }
} catch (PDOException $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}
