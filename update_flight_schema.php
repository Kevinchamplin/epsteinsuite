<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    echo "Checking flight_logs schema...\n";

    // Check for ai_summary
    $stmt = $pdo->query("SHOW COLUMNS FROM flight_logs LIKE 'ai_summary'");
    if ($stmt->rowCount() == 0) {
        echo "Adding ai_summary column...\n";
        $pdo->exec("ALTER TABLE flight_logs ADD COLUMN ai_summary TEXT DEFAULT NULL AFTER distance_miles");
    } else {
        echo "ai_summary column exists.\n";
    }

    // Check for significance_score
    $stmt = $pdo->query("SHOW COLUMNS FROM flight_logs LIKE 'significance_score'");
    if ($stmt->rowCount() == 0) {
        echo "Adding significance_score column...\n";
        $pdo->exec("ALTER TABLE flight_logs ADD COLUMN significance_score TINYINT UNSIGNED DEFAULT 0 AFTER ai_summary");
        $pdo->exec("ALTER TABLE flight_logs ADD INDEX idx_significance (significance_score)");
    } else {
        echo "significance_score column exists.\n";
    }

    echo "Schema update complete.\n";
    echo "PHP_BINARY: " . PHP_BINARY . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
