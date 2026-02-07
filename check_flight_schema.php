<?php
require_once __DIR__ . '/includes/db.php';
$pdo = db();
$stmt = $pdo->query("DESCRIBE flight_logs");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ")\n";
}
