<?php
// migrate_media_metadata.php - Adds media metadata columns to documents table.
// Run: php migrate_media_metadata.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    $dbName = env_value('DB_NAME');
    if (!$dbName) {
        throw new RuntimeException('DB_NAME missing; cannot run migration.');
    }

    $columns = [
        ['media_duration_seconds', 'INT DEFAULT NULL',          'page_count'],
        ['media_width',            'INT DEFAULT NULL',          'media_duration_seconds'],
        ['media_height',           'INT DEFAULT NULL',          'media_width'],
        ['media_codec',            'VARCHAR(50) DEFAULT NULL',  'media_height'],
        ['media_format',           'VARCHAR(20) DEFAULT NULL',  'media_codec'],
        ['thumbnail_path',         'VARCHAR(1024) DEFAULT NULL','media_format'],
    ];

    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );

    $added = 0;
    foreach ($columns as [$col, $def, $after]) {
        $stmt->execute(['db' => $dbName, 't' => 'documents', 'c' => $col]);
        if ($stmt->fetchColumn()) {
            echo "documents.{$col} already exists.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE `documents` ADD COLUMN `{$col}` {$def} AFTER `{$after}`");
        echo "Added documents.{$col}.\n";
        $added++;
    }

    echo $added > 0 ? "Migration complete: {$added} column(s) added.\n" : "Nothing to do.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
