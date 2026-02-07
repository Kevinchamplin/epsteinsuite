<?php
// migrate_email_starred.php - Adds emails.is_starred if missing.
// Run: php migrate_email_starred.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    $dbName = env_value('DB_NAME');
    if (!$dbName) {
        throw new RuntimeException('DB_NAME missing; cannot run migration.');
    }

    $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1');
    $stmt->execute(['db' => $dbName, 't' => 'emails', 'c' => 'is_starred']);

    if ($stmt->fetchColumn()) {
        echo "emails.is_starred already exists.\n";
        exit(0);
    }

    $pdo->exec('ALTER TABLE `emails` ADD COLUMN `is_starred` TINYINT(1) DEFAULT 0 AFTER `folder`');
    echo "Added emails.is_starred.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
