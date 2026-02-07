<?php
// migrate_ai_summary.php - Run via CLI (recommended) to add documents.ai_summary and rebuild FULLTEXT index.
// Example: php migrate_ai_summary.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('MIGRATION_NAME', '2025-12-20_add_documents_ai_summary');

require_once __DIR__ . '/includes/db.php';

function column_exists(PDO $pdo, string $dbName, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
    );
    $stmt->execute([
        'db' => $dbName,
        't' => $table,
        'c' => $column,
    ]);
    return (bool)$stmt->fetchColumn();
}

function get_fulltext_indexes(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Index_type = 'FULLTEXT'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byName = [];
    foreach ($rows as $r) {
        $name = (string)$r['Key_name'];
        $col = (string)$r['Column_name'];
        if (!isset($byName[$name])) {
            $byName[$name] = [];
        }
        $byName[$name][] = $col;
    }

    return $byName;
}

try {
    $pdo = db();
    $dbName = env_value('DB_NAME');
    if (!$dbName) {
        throw new RuntimeException('DB_NAME missing; cannot run migration.');
    }

    echo "== Migration: " . MIGRATION_NAME . " ==\n";

    $hasAi = column_exists($pdo, $dbName, 'documents', 'ai_summary');
    if (!$hasAi) {
        echo "Adding documents.ai_summary...\n";
        $pdo->exec("ALTER TABLE `documents` ADD COLUMN `ai_summary` LONGTEXT NULL AFTER `description`");
    } else {
        echo "documents.ai_summary already exists.\n";
    }

    // Ensure FULLTEXT includes ai_summary
    $fulltext = get_fulltext_indexes($pdo, 'documents');
    $needsRebuild = true;
    foreach ($fulltext as $idxName => $cols) {
        $colsLower = array_map('strtolower', $cols);
        sort($colsLower);
        $target = ['ai_summary', 'description', 'title'];
        sort($target);
        if ($colsLower === $target) {
            $needsRebuild = false;
            echo "FULLTEXT index already includes ai_summary (index: {$idxName}).\n";
            break;
        }
    }

    if ($needsRebuild) {
        echo "Rebuilding FULLTEXT index to include ai_summary...\n";

        // Drop existing FULLTEXT indexes (if any)
        foreach (array_keys($fulltext) as $idxName) {
            echo "Dropping FULLTEXT index {$idxName}...\n";
            $pdo->exec("ALTER TABLE `documents` DROP INDEX `{$idxName}`");
        }

        // Create a deterministic FULLTEXT index name
        echo "Creating FULLTEXT index ft_documents (title, description, ai_summary)...\n";
        $pdo->exec("ALTER TABLE `documents` ADD FULLTEXT `ft_documents` (`title`, `description`, `ai_summary`)");
    }

    echo "Migration complete.\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
