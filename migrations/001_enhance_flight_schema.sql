-- Flight Data Intelligence Schema Enhancements
-- Phase 1: Add enriched columns to flight_logs table

-- Add enriched location data for origin
ALTER TABLE flight_logs 
ADD COLUMN origin_airport_code VARCHAR(10) AFTER destination,
ADD COLUMN origin_city VARCHAR(255) AFTER origin_airport_code,
ADD COLUMN origin_country VARCHAR(100) AFTER origin_city,
ADD COLUMN origin_lat DECIMAL(10, 8) AFTER origin_country,
ADD COLUMN origin_lng DECIMAL(11, 8) AFTER origin_lat;

-- Add enriched location data for destination
ALTER TABLE flight_logs
ADD COLUMN destination_airport_code VARCHAR(10) AFTER origin_lng,
ADD COLUMN destination_city VARCHAR(255) AFTER destination_airport_code,
ADD COLUMN destination_country VARCHAR(100) AFTER destination_city,
ADD COLUMN destination_lat DECIMAL(10, 8) AFTER destination_country,
ADD COLUMN destination_lng DECIMAL(11, 8) AFTER destination_lat;

-- Add flight metadata
ALTER TABLE flight_logs
ADD COLUMN flight_duration_hours DECIMAL(5, 2) AFTER destination_lng,
ADD COLUMN distance_miles INT AFTER flight_duration_hours,
ADD COLUMN flight_purpose TEXT AFTER distance_miles,
ADD COLUMN tail_number VARCHAR(20) AFTER flight_purpose,
ADD COLUMN aircraft_type VARCHAR(100) AFTER tail_number;

-- Add AI-generated insights
ALTER TABLE flight_logs
ADD COLUMN ai_summary TEXT AFTER aircraft_type,
ADD COLUMN ai_significance_score INT AFTER ai_summary,
ADD COLUMN notable_passengers JSON AFTER ai_significance_score;

-- Add data quality tracking
ALTER TABLE flight_logs
ADD COLUMN extraction_confidence DECIMAL(3, 2) AFTER notable_passengers,
ADD COLUMN extraction_method VARCHAR(50) DEFAULT 'regex' AFTER extraction_confidence,
ADD COLUMN verified BOOLEAN DEFAULT FALSE AFTER extraction_method,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER verified;

-- Add indexes for performance
CREATE INDEX idx_flight_origin_code ON flight_logs(origin_airport_code);
CREATE INDEX idx_flight_dest_code ON flight_logs(destination_airport_code);
CREATE INDEX idx_flight_significance ON flight_logs(ai_significance_score);
CREATE INDEX idx_flight_date ON flight_logs(flight_date);
CREATE INDEX idx_flight_distance ON flight_logs(distance_miles);

-- Create flight_connections table for linking flights to entities, documents, and other flights
CREATE TABLE IF NOT EXISTS flight_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flight_id INT NOT NULL,
    connection_type ENUM('entity', 'document', 'flight', 'event') NOT NULL,
    connection_id INT,
    connection_name VARCHAR(255),
    relationship_type VARCHAR(100),
    confidence DECIMAL(3, 2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flight_id) REFERENCES flight_logs(id) ON DELETE CASCADE,
    INDEX idx_flight_connection (flight_id, connection_type),
    INDEX idx_connection_type (connection_type, connection_id)
);

-- Add comment to track schema version
ALTER TABLE flight_logs COMMENT = 'Enhanced flight logs with AI enrichment - v2.0';
