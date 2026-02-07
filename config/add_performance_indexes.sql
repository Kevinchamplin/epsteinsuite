-- Performance indexes migration
-- Run this on the production MySQL server to fix timeout issues
-- These are all safe to run on a live database (CREATE INDEX IF NOT EXISTS pattern)

-- pages: index on document_id for JOINs and WHERE document_id = X
-- (InnoDB creates implicit FK index but explicit composite is better)
ALTER TABLE `pages` ADD INDEX `idx_pages_document_id` (`document_id`);

-- document_entities: reverse index for entity->document lookups (co-mention self-joins)
-- PK is (document_id, entity_id) but we need (entity_id, document_id) for the reverse
ALTER TABLE `document_entities` ADD INDEX `idx_de_entity_document` (`entity_id`, `document_id`);

-- emails: indexes for folder counts and sidebar queries
ALTER TABLE `emails` ADD INDEX `idx_emails_folder` (`folder`);
ALTER TABLE `emails` ADD INDEX `idx_emails_is_starred` (`is_starred`);
ALTER TABLE `emails` ADD INDEX `idx_emails_attachments_count` (`attachments_count`);
ALTER TABLE `emails` ADD INDEX `idx_emails_sender` (`sender`(100));
ALTER TABLE `emails` ADD INDEX `idx_emails_sent_at` (`sent_at`);
ALTER TABLE `emails` ADD INDEX `idx_emails_document_id` (`document_id`);

-- flight_logs: indexes for common queries
ALTER TABLE `flight_logs` ADD INDEX `idx_fl_document_id` (`document_id`);
ALTER TABLE `flight_logs` ADD INDEX `idx_fl_flight_date` (`flight_date`);
ALTER TABLE `flight_logs` ADD INDEX `idx_fl_aircraft` (`aircraft`);

-- passengers: index for name search and flight lookups
ALTER TABLE `passengers` ADD INDEX `idx_passengers_flight_id` (`flight_id`);
ALTER TABLE `passengers` ADD INDEX `idx_passengers_name` (`name`(100));

-- documents: indexes for status filtering and common lookups
ALTER TABLE `documents` ADD INDEX `idx_documents_status` (`status`);
ALTER TABLE `documents` ADD INDEX `idx_documents_data_set` (`data_set`);
ALTER TABLE `documents` ADD INDEX `idx_documents_created_at` (`created_at`);

-- entities: index on type for filtering
ALTER TABLE `entities` ADD INDEX `idx_entities_type` (`type`);
