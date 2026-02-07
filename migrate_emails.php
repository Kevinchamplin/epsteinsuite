<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    
    $sql = "
    CREATE TABLE IF NOT EXISTS `emails` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `document_id` INT NOT NULL,
        `sender` VARCHAR(255) DEFAULT NULL,
        `recipient` VARCHAR(255) DEFAULT NULL,
        `cc` VARCHAR(255) DEFAULT NULL,
        `subject` VARCHAR(255) DEFAULT NULL,
        `sent_at` DATETIME DEFAULT NULL,
        `body` TEXT,
        `folder` VARCHAR(50) DEFAULT 'inbox',
        `attachments_count` INT DEFAULT 0,
        `is_starred` TINYINT(1) DEFAULT 0,
        `is_read` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
        FULLTEXT KEY `ft_email` (`sender`, `recipient`, `subject`, `body`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $pdo->exec($sql);
    echo "Migration: emails table created successfully.\n";

} catch (PDOException $e) {
    die("Migration Failed: " . $e->getMessage() . "\n");
}
?>
