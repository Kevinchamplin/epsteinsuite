<?php
require_once 'includes/db.php';

try {
    $pdo = db();

    // Check total docs processed by AI
    $stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE ai_summary IS NOT NULL AND ai_summary != 'ERROR'");
    $aiCount = $stmt->fetchColumn();

    // Check for high relevance docs
    $stmt = $pdo->query("SELECT id, title, relevance_score FROM documents WHERE relevance_score >= 8 ORDER BY relevance_score DESC LIMIT 5");
    $topDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "AI Processed Count: " . $aiCount . "\n";
    echo "Top Findings:\n";
    foreach ($topDocs as $d) {
        echo " - [Score {$d['relevance_score']}] {$d['title']} (ID: {$d['id']})\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>