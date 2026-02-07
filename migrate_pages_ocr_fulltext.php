<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND INDEX_TYPE = 'FULLTEXT'");
    $stmt->execute();
    $hasFulltext = (int)$stmt->fetchColumn() > 0;

    if (!$hasFulltext) {
        $pdo->exec("ALTER TABLE pages ADD FULLTEXT KEY ft_pages_ocr (ocr_text)");
        echo "Migration: FULLTEXT index added to pages.ocr_text.\n";
    } else {
        echo "Migration: FULLTEXT index already present on pages.\n";
    }
} catch (PDOException $e) {
    die("Migration Failed: " . $e->getMessage() . "\n");
}
