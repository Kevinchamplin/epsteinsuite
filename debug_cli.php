<?php
require_once __DIR__ . '/includes/db.php';

echo "Testing env_value from CLI...\n";
$user = env_value('DB_USERNAME');
echo "DB_USERNAME: " . ($user ? $user : "NULL (Failed)") . "\n";
echo "Active DB User: " . ($user ?? 'root') . "\n";

try {
    $pdo = db();
    echo "DB Connection: SUCCESS\n";
} catch (Exception $e) {
    echo "DB Connection: FAILED (" . $e->getMessage() . ")\n";
}
?>