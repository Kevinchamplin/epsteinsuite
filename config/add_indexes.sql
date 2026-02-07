-- Performance indexes to reduce 504 timeouts
-- Run on production: mysql -u root -p epstein_db < config/add_indexes.sql

ALTER TABLE documents ADD INDEX idx_documents_data_set (data_set);
ALTER TABLE documents ADD INDEX idx_documents_status (status);
ALTER TABLE documents ADD INDEX idx_documents_file_type (file_type);
