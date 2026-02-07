<?php
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();

    // Scrub legacy third-party import copy from production.
    // Only touches rows that contain the specific phrase pattern.
    $stmt = $pdo->prepare("UPDATE documents SET description = NULL WHERE description LIKE :needle");
    $stmt->execute([':needle' => '%Imported email from %source%']);

    echo "Migration: scrubbed descriptions for " . $stmt->rowCount() . " document(s).\n";

} catch (PDOException $e) {
    die("Migration Failed: " . $e->getMessage() . "\n");
}
