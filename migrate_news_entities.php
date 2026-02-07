<?php
declare(strict_types=1);
/**
 * Migration: Add entities_mentioned column to news_articles table.
 * Stores a JSON array of person/org names extracted by AI during news scraping.
 * Idempotent — safe to run multiple times.
 */

require_once __DIR__ . '/includes/db.php';
$pdo = db();

header('Content-Type: text/plain');

// 1. Add entities_mentioned column
$cols = $pdo->query("SHOW COLUMNS FROM news_articles LIKE 'entities_mentioned'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE news_articles ADD COLUMN entities_mentioned TEXT DEFAULT NULL AFTER score_reason");
    echo "Added news_articles.entities_mentioned column.\n";
} else {
    echo "news_articles.entities_mentioned already exists.\n";
}

echo "Migration complete.\n";
