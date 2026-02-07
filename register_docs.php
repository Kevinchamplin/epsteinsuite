<?php
/**
 * Document Registration Page
 * Visit this page to register all PDFs in storage/documents
 */

require_once __DIR__ . '/includes/db.php';

set_time_limit(300); // 5 minutes

$storageDir = __DIR__ . '/storage/documents';
$pdo = db();

echo "<html><head><title>Register Documents</title></head><body>";
echo "<h1>Document Registration</h1>";
echo "<p>Scanning: $storageDir</p>";

// Find all PDFs
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($storageDir)
);

$pdfs = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
        $pdfs[] = $file->getPathname();
    }
}

echo "<p>Found " . count($pdfs) . " PDFs</p>";
echo "<pre>";

$registered = 0;
$skipped = 0;

foreach ($pdfs as $pdfPath) {
    $relativePath = str_replace(__DIR__ . '/', '', $pdfPath);
    $filename = basename($pdfPath);
    $fileSize = filesize($pdfPath);

    // Create title
    $title = pathinfo($filename, PATHINFO_FILENAME);
    $title = str_replace(['_', '-'], ' ', $title);
    $title = preg_replace('/\s+/', ' ', trim($title));

    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM documents WHERE local_path = ?");
    $stmt->execute([$relativePath]);

    if ($stmt->fetch()) {
        $skipped++;
        echo ".";
        flush();
        continue;
    }

    // Insert
    try {
        $stmt = $pdo->prepare("
            INSERT INTO documents (title, local_path, file_type, file_size, status, created_at)
            VALUES (?, ?, 'pdf', ?, 'downloaded', NOW())
        ");
        $stmt->execute([$title, $relativePath, $fileSize]);

        $registered++;
        echo "+";

        if ($registered % 50 === 0) {
            echo " [$registered]\n";
        }
        flush();
    } catch (Exception $e) {
        echo "E";
        flush();
    }
}

echo "</pre>";
echo "<h2>Complete!</h2>";
echo "<p>Registered: $registered</p>";
echo "<p>Skipped: $skipped</p>";
echo "<p>Total: " . count($pdfs) . "</p>";
echo "<p><a href='/'>Back to Home</a></p>";
echo "</body></html>";
