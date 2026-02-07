<?php
require_once 'includes/db.php';
try {
    $pdo = db();
    $pages = $pdo->query("SELECT COUNT(*) FROM pages")->fetchColumn();
    $processed = $pdo->query("SELECT COUNT(*) FROM documents WHERE ai_summary IS NOT NULL AND ai_summary != ''")->fetchColumn();
    $ocr_docs = $pdo->query("SELECT COUNT(DISTINCT document_id) FROM pages")->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode([
        'total_pages_ocr' => $pages,
        'docs_with_ocr' => $ocr_docs,
        'docs_with_ai' => $processed
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
