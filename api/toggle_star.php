<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing id']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE emails SET is_starred = CASE WHEN is_starred = 1 THEN 0 ELSE 1 END WHERE id = :id');
    $stmt->execute(['id' => $id]);

    $stmt = $pdo->prepare('SELECT is_starred FROM emails WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $val = $stmt->fetchColumn();

    echo json_encode(['ok' => true, 'is_starred' => (int)$val]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed']);
}
