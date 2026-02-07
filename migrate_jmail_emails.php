<?php
// migrate_jmail_emails.php — Prepare emails table for jmail.world imports.
//
// 1. Makes document_id nullable (like flight_logs)
// 2. Adds jmail_doc_id column with unique index for dedup
// 3. Adds sender_email column
// 4. Cleans up placeholder documents created by old scraper
//
// Run: php migrate_jmail_emails.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

function column_exists(PDO $pdo, string $db, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );
    $stmt->execute(['db' => $db, 't' => $table, 'c' => $column]);
    return (bool) $stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $db, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1'
    );
    $stmt->execute(['db' => $db, 't' => $table, 'i' => $index]);
    return (bool) $stmt->fetchColumn();
}

try {
    $pdo = db();
    $dbName = env_value('DB_NAME');
    if (!$dbName) {
        throw new RuntimeException('DB_NAME missing; cannot run migration.');
    }

    // --- Step 1: Make document_id nullable ---

    // Check current column definition
    $stmt = $pdo->prepare(
        "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'emails' AND COLUMN_NAME = 'document_id' LIMIT 1"
    );
    $stmt->execute(['db' => $dbName]);
    $col = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($col && $col['IS_NULLABLE'] === 'NO') {
        // Find and drop the existing FK constraint on document_id
        $stmt = $pdo->prepare(
            "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'emails'
               AND COLUMN_NAME = 'document_id' AND REFERENCED_TABLE_NAME = 'documents'
             LIMIT 1"
        );
        $stmt->execute(['db' => $dbName]);
        $fkName = $stmt->fetchColumn();

        if ($fkName) {
            $pdo->exec("ALTER TABLE `emails` DROP FOREIGN KEY `{$fkName}`");
            echo "Dropped FK constraint: {$fkName}\n";
        }

        // Make column nullable
        $pdo->exec("ALTER TABLE `emails` MODIFY COLUMN `document_id` INT DEFAULT NULL");
        echo "Made emails.document_id nullable.\n";

        // Re-add FK with ON DELETE SET NULL
        $pdo->exec(
            "ALTER TABLE `emails` ADD CONSTRAINT `fk_emails_document`
             FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE SET NULL"
        );
        echo "Re-added FK with ON DELETE SET NULL.\n";
    } else {
        echo "emails.document_id is already nullable.\n";
    }

    // --- Step 2: Add jmail_doc_id column ---

    if (!column_exists($pdo, $dbName, 'emails', 'jmail_doc_id')) {
        $pdo->exec("ALTER TABLE `emails` ADD COLUMN `jmail_doc_id` VARCHAR(100) DEFAULT NULL AFTER `document_id`");
        echo "Added emails.jmail_doc_id.\n";
    } else {
        echo "emails.jmail_doc_id already exists.\n";
    }

    if (!index_exists($pdo, $dbName, 'emails', 'idx_emails_jmail_doc_id')) {
        $pdo->exec("ALTER TABLE `emails` ADD UNIQUE INDEX `idx_emails_jmail_doc_id` (`jmail_doc_id`)");
        echo "Added unique index on jmail_doc_id.\n";
    } else {
        echo "Index idx_emails_jmail_doc_id already exists.\n";
    }

    // --- Step 3: Add sender_email column ---

    if (!column_exists($pdo, $dbName, 'emails', 'sender_email')) {
        $pdo->exec("ALTER TABLE `emails` ADD COLUMN `sender_email` VARCHAR(255) DEFAULT NULL AFTER `sender`");
        echo "Added emails.sender_email.\n";
    } else {
        echo "emails.sender_email already exists.\n";
    }

    // --- Step 4: Clean up placeholder documents from old scraper ---

    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM documents WHERE file_type = 'email' AND description LIKE 'Email record.%'"
    );
    $placeholderCount = (int) $stmt->fetchColumn();

    if ($placeholderCount > 0) {
        // Null out FK references first
        $pdo->exec(
            "UPDATE emails SET document_id = NULL
             WHERE document_id IN (
                 SELECT id FROM documents WHERE file_type = 'email' AND description LIKE 'Email record.%'
             )"
        );
        // Delete placeholder documents
        $pdo->exec("DELETE FROM documents WHERE file_type = 'email' AND description LIKE 'Email record.%'");
        echo "Cleaned up {$placeholderCount} placeholder documents.\n";
    } else {
        echo "No placeholder documents to clean up.\n";
    }

    echo "\nMigration complete.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
