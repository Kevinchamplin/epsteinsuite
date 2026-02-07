<?php
// migrate.php - Run this via browser or CLI to migrate the database
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db.php';

echo "<h1>Database Migration</h1>";

try {
    $pdo = db();
    echo "<p>Connected to database successfully.</p>";
    
    $schemaFile = __DIR__ . '/config/schema.sql';
    if (!file_exists($schemaFile)) {
        die("Schema file not found at: $schemaFile");
    }
    
    $sql = file_get_contents($schemaFile);
    
    // Split by semicolon to handle multiple statements
    // This is a naive split but works for standard dumps/schemas
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        try {
            $pdo->exec($statement);
            // Truncate long statements for display
            $displaySql = strlen($statement) > 60 ? substr($statement, 0, 60) . '...' : $statement;
            echo "<div style='color: green;'>Executed: " . htmlspecialchars($displaySql) . "</div>";
        } catch (PDOException $e) {
            // Ignore "Table already exists" errors somewhat safely by checking code
            // But usually, we just print the error. IF NOT EXISTS handles safety.
            echo "<div style='color: orange;'>Warning: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    echo "<h2>Migration Completed.</h2>";
    echo "<p>Please delete this file (migrate.php) from the server after use for security.</p>";

} catch (Exception $e) {
    echo "<div style='color: red;'>Fatal Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}
