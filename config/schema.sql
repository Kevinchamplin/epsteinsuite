-- Database Schema for Epstein Files Project

CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `ai_summary` LONGTEXT,
    `source_url` VARCHAR(2048),
    `local_path` VARCHAR(1024),
    `file_type` VARCHAR(50),
    `file_hash` CHAR(40) DEFAULT NULL,
    `document_date` DATETIME NULL,
    `file_size` BIGINT,
    `page_count` INT DEFAULT 0,
    `media_duration_seconds` INT DEFAULT NULL,
    `media_width` INT DEFAULT NULL,
    `media_height` INT DEFAULT NULL,
    `media_codec` VARCHAR(50) DEFAULT NULL,
    `media_format` VARCHAR(20) DEFAULT NULL,
    `thumbnail_path` VARCHAR(1024) DEFAULT NULL,
    `status` ENUM('pending', 'downloaded', 'processed', 'error') DEFAULT 'pending',
    `processing_priority` TINYINT DEFAULT 5,
    `data_set` VARCHAR(255) DEFAULT NULL,
    `batch_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FULLTEXT(title, description, ai_summary),
    INDEX (`file_hash`),
    INDEX `idx_documents_priority_status` (`processing_priority`, `status`),
    INDEX `idx_documents_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ingestion_errors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `step` VARCHAR(50) NOT NULL,
    `message` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    INDEX (`document_id`),
    INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `page_number` INT NOT NULL,
    `image_path` VARCHAR(1024),
    `ocr_text` LONGTEXT,
    `summary` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    INDEX `idx_pages_document_id` (`document_id`),
    FULLTEXT(ocr_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `entities` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `type` VARCHAR(50), -- e.g., 'person', 'organization', 'location'
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_entities` (
    `document_id` INT NOT NULL,
    `entity_id` INT NOT NULL,
    `frequency` INT DEFAULT 1,
    PRIMARY KEY (`document_id`, `entity_id`),
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`entity_id`) REFERENCES `entities`(`id`) ON DELETE CASCADE,
    INDEX `idx_de_entity_document` (`entity_id`, `document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `flight_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT,
    `flight_date` DATE,
    `origin` VARCHAR(255),
    `destination` VARCHAR(255),
    `aircraft` VARCHAR(100),
    `notes` TEXT,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE SET NULL,
    INDEX `idx_fl_document_id` (`document_id`),
    INDEX `idx_fl_flight_date` (`flight_date`),
    INDEX `idx_fl_aircraft` (`aircraft`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `passengers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `flight_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    FOREIGN KEY (`flight_id`) REFERENCES `flight_logs`(`id`) ON DELETE CASCADE,
    INDEX `idx_passengers_flight_id` (`flight_id`),
    INDEX `idx_passengers_name` (`name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emails` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT DEFAULT NULL,
    `jmail_doc_id` VARCHAR(100) DEFAULT NULL,
    `sender` VARCHAR(255) DEFAULT NULL,
    `sender_email` VARCHAR(255) DEFAULT NULL,
    `recipient` VARCHAR(255) DEFAULT NULL,
    `cc` VARCHAR(255) DEFAULT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    `body` LONGTEXT,
    `folder` VARCHAR(50) DEFAULT 'inbox',
    `attachments_count` INT DEFAULT 0,
    `is_starred` TINYINT(1) DEFAULT 0,
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `idx_emails_jmail_doc_id` (`jmail_doc_id`),
    INDEX `idx_emails_folder` (`folder`),
    INDEX `idx_emails_is_starred` (`is_starred`),
    INDEX `idx_emails_attachments_count` (`attachments_count`),
    INDEX `idx_emails_sender` (`sender`(100)),
    INDEX `idx_emails_sent_at` (`sent_at`),
    INDEX `idx_emails_document_id` (`document_id`),
    FULLTEXT KEY `ft_email` (`sender`, `recipient`, `subject`, `body`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_file_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `reporter_ip` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    INDEX (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contact_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `message` TEXT NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`created_at`),
    INDEX (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_sessions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `session_token` CHAR(36) NOT NULL,
    `ip_hash` CHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_active_at` TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY `uniq_ai_session_token` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `ai_fact_bank` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(80) NOT NULL UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `summary` TEXT NOT NULL,
    `document_id` INT DEFAULT NULL,
    `page_number` INT DEFAULT NULL,
    `source_url` VARCHAR(2048) DEFAULT NULL,
    `confidence` TINYINT DEFAULT 80,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batch tracking for large-scale ingestion runs
CREATE TABLE IF NOT EXISTS `ingestion_batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `batch_name` VARCHAR(255) NOT NULL,
    `data_set` VARCHAR(255) DEFAULT NULL,
    `total_documents` INT DEFAULT 0,
    `processed_documents` INT DEFAULT 0,
    `failed_documents` INT DEFAULT 0,
    `status` ENUM('pending', 'running', 'paused', 'completed', 'error') DEFAULT 'pending',
    `started_at` TIMESTAMP NULL,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`status`),
    INDEX (`data_set`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Submissions for user-provided evidence (URLs, Drive links, uploads)
CREATE TABLE IF NOT EXISTS `ingestion_submissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_type` ENUM('url','drive','file') NOT NULL,
    `source_url` VARCHAR(2048) DEFAULT NULL,
    `file_path` VARCHAR(1024) DEFAULT NULL,
    `file_size` BIGINT DEFAULT NULL,
    `note` TEXT,
    `submitter_email` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending','queued','error') DEFAULT 'pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`status`),
    INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- News articles scraped from Google News RSS with AI analysis
CREATE TABLE IF NOT EXISTS `news_articles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(500) NOT NULL,
    `url` VARCHAR(2000) NOT NULL,
    `url_hash` CHAR(64) NOT NULL UNIQUE,
    `source_name` VARCHAR(255) DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `fetched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `snippet` TEXT DEFAULT NULL,
    `ai_summary` TEXT DEFAULT NULL,
    `ai_headline` VARCHAR(500) DEFAULT NULL,
    `shock_score` TINYINT UNSIGNED DEFAULT NULL,
    `score_reason` VARCHAR(500) DEFAULT NULL,
    `entities_mentioned` TEXT DEFAULT NULL,
    `status` ENUM('pending','processed','error') NOT NULL DEFAULT 'pending',
    `error_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_news_status` (`status`),
    INDEX `idx_news_shock` (`shock_score` DESC),
    INDEX `idx_news_published` (`published_at` DESC),
    FULLTEXT INDEX `ft_news` (`title`, `snippet`, `ai_summary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Search query tracking for popular searches
CREATE TABLE IF NOT EXISTS `search_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `query` VARCHAR(255) NOT NULL,
    `query_normalized` VARCHAR(255) NOT NULL,
    `result_count` INT DEFAULT 0,
    `ip_hash` CHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_search_logs_normalized` (`query_normalized`),
    INDEX `idx_search_logs_created` (`created_at`),
    INDEX `idx_search_logs_ip_query` (`ip_hash`, `query_normalized`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Amazon order history scraped from jmail.world
CREATE TABLE IF NOT EXISTS `amazon_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NOT NULL,
    `order_date` DATE DEFAULT NULL,
    `product_name` VARCHAR(500) NOT NULL,
    `asin` VARCHAR(20) DEFAULT NULL,
    `price` DECIMAL(10,2) DEFAULT NULL,
    `quantity` INT DEFAULT 1,
    `delivery_status` VARCHAR(100) DEFAULT NULL,
    `delivery_date` VARCHAR(100) DEFAULT NULL,
    `delivery_address` VARCHAR(500) DEFAULT NULL,
    `product_image_url` VARCHAR(2048) DEFAULT NULL,
    `product_url` VARCHAR(2048) DEFAULT NULL,
    `rating` DECIMAL(2,1) DEFAULT NULL,
    `category` VARCHAR(100) DEFAULT NULL,
    `source_url` VARCHAR(2048) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_order_product` (`order_number`, `asin`),
    INDEX `idx_order_date` (`order_date`),
    INDEX `idx_category` (`category`),
    INDEX `idx_price` (`price`),
    FULLTEXT KEY `ft_orders` (`product_name`, `delivery_status`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photo/document view tracking for popularity metrics
CREATE TABLE IF NOT EXISTS `photo_views` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `ip_hash` CHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `referrer` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    INDEX `idx_photo_views_doc` (`document_id`),
    INDEX `idx_photo_views_created` (`created_at`),
    INDEX `idx_photo_views_ip_doc` (`ip_hash`, `document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General document view tracking for trending/leaderboard metrics
CREATE TABLE IF NOT EXISTS `document_views` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `document_id` INT NOT NULL,
    `ip_hash` CHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `referrer` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
    INDEX `idx_document_views_doc` (`document_id`),
    INDEX `idx_document_views_created` (`created_at`),
    INDEX `idx_document_views_ip_doc` (`ip_hash`, `document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global chat room messages
CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nickname` VARCHAR(30) NOT NULL,
    `message` VARCHAR(500) NOT NULL,
    `ip_hash` CHAR(64) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_chat_created` (`created_at`),
    INDEX `idx_chat_ip` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
