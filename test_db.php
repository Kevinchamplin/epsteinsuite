<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/db.php';

echo "=== Epstein Suite DB Connection Test ===\n\n";

// Show which config values are being used (redact password)
$host = env_value('DB_HOST') ?? 'localhost';
$name = env_value('DB_NAME') ?? 'epstein_db';
$user = env_value('DB_USERNAME') ?? 'root';
$pass = env_value('DB_PASSWORD') ?? '';

echo "DB_HOST:     {$host}\n";
echo "DB_NAME:     {$name}\n";
echo "DB_USERNAME: {$user}\n";
echo "DB_PASSWORD: " . str_repeat('*', max(0, strlen($pass) - 2)) . substr($pass, -2) . "\n";
echo ".env path:   " . realpath(__DIR__ . '/.env') . "\n\n";

try {
    $pdo = db();
    echo "STATUS: CONNECTED OK\n\n";

    // Quick sanity check
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM documents");
    $row = $stmt->fetch();
    echo "Documents table: {$row['total']} rows\n";

    $stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM documents GROUP BY status ORDER BY cnt DESC");
    echo "\nBy status:\n";
    while ($row = $stmt->fetch()) {
        echo "  {$row['status']}: {$row['cnt']}\n";
    }

    echo "\nDATABASE CONNECTION IS WORKING.\n";
} catch (Exception $e) {
    echo "STATUS: FAILED\n\n";
    echo "Error: " . $e->getMessage() . "\n";
}
