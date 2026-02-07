<?php
/**
 * Start AI Summary Generation
 * This page will trigger the AI summary generation process
 */

require_once __DIR__ . '/includes/db.php';

$pdo = db();

// Get count of documents needing summaries
$stmt = $pdo->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
           SUM(CASE WHEN status = 'downloaded' THEN 1 ELSE 0 END) as need_ocr
    FROM documents
");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<html><head><title>AI Summary Generation</title></head><body>";
echo "<h1>AI Summary Generation Status</h1>";
echo "<h2>Database Stats:</h2>";
echo "<ul>";
echo "<li>Total Documents: " . number_format($stats['total']) . "</li>";
echo "<li>Processed (with OCR): " . number_format($stats['processed']) . "</li>";
echo "<li>Need OCR: " . number_format($stats['need_ocr']) . "</li>";
echo "</ul>";

echo "<h2>Next Steps:</h2>";
echo "<ol>";
echo "<li><strong>OCR Processing:</strong> First, we need to run OCR on the " . number_format($stats['need_ocr']) . " documents that don't have text yet.</li>";
echo "<li><strong>AI Summaries:</strong> Then we can generate AI summaries for all documents with OCR text.</li>";
echo "</ol>";

echo "<h2>How to Proceed:</h2>";
echo "<p>The <code>generate_ai_summaries.py</code> script is ready to use. It will:</p>";
echo "<ul>";
echo "<li>Process documents in batches</li>";
echo "<li>Generate 3-5 sentence summaries</li>";
echo "<li>Extract entities (people, organizations, locations)</li>";
echo "<li>Link entities to documents</li>";
echo "</ul>";

echo "<p><strong>Estimated Cost:</strong> ~$" . number_format($stats['total'] * 0.10, 2) . " for " . number_format($stats['total']) . " documents (at ~$0.10 per document)</p>";

echo "<p><strong>Note:</strong> OCR processing needs to run first. The download_and_ocr.py script can process local files.</p>";

echo "<p><a href='/'>Back to Home</a></p>";
echo "</body></html>";
