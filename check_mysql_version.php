<?php
require_once __DIR__ . '/includes/db.php';
$pdo = db();
echo "MySQL Version: " . $pdo->query("SELECT VERSION()")->fetchColumn() . "\n";
