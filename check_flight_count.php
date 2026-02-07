<?php
require_once __DIR__ . '/includes/db.php';
$pdo = db();
$count = $pdo->query("SELECT COUNT(*) FROM flight_logs")->fetchColumn();
echo "Total flights in DB: " . $count . "\n";

$pending = $pdo->query("SELECT COUNT(*) FROM flight_logs WHERE ai_summary IS NULL")->fetchColumn();
echo "Pending flights (ai_summary IS NULL): " . $pending . "\n";
