<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();

    $sql = "
    CREATE TABLE IF NOT EXISTS `pdf_images` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `document_id` INT NOT NULL,
        `page_number` INT NOT NULL,
        `image_index` INT NOT NULL,
        `image_path` VARCHAR(1024) NOT NULL,
        `width` INT DEFAULT NULL,
        `height` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_doc_page_img` (`document_id`, `page_number`, `image_index`),
        KEY `idx_doc` (`document_id`),
        CONSTRAINT `fk_pdf_images_document` FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "Migration: pdf_images table created successfully.\n";

} catch (PDOException $e) {
    die("Migration Failed: " . $e->getMessage() . "\n");
}
