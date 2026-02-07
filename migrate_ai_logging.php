<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();

    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS `ai_sessions` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `session_token` CHAR(36) NOT NULL,
        `ip_hash` CHAR(64) DEFAULT NULL,
        `user_agent` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_active_at` TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY `uniq_ai_session_token` (`session_token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);

    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS `ai_messages` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `session_id` BIGINT UNSIGNED NOT NULL,
        `role` ENUM('user','assistant','system') NOT NULL,
        `content` LONGTEXT NOT NULL,
        `model` VARCHAR(100) DEFAULT NULL,
        `tokens_input` INT DEFAULT NULL,
        `tokens_output` INT DEFAULT NULL,
        `latency_ms` INT DEFAULT NULL,
        `metadata` JSON DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_ai_messages_session` FOREIGN KEY (`session_id`) REFERENCES `ai_sessions`(`id`) ON DELETE CASCADE,
        INDEX (`session_id`),
        INDEX (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);

    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS `ai_citations` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` BIGINT UNSIGNED NOT NULL,
        `document_id` INT NOT NULL,
        `page_number` INT DEFAULT NULL,
        `score` DECIMAL(6,4) DEFAULT NULL,
        `snippet` TEXT,
        CONSTRAINT `fk_ai_citations_message` FOREIGN KEY (`message_id`) REFERENCES `ai_messages`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_ai_citations_document` FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
        INDEX (`document_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL);

    echo "Migration: AI logging tables are ready.\n";
} catch (PDOException $e) {
    die('Migration Failed: ' . $e->getMessage() . "\n");
}
