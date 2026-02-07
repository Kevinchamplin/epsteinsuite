<?php
require_once __DIR__ . '/includes/db.php';

function resolvePhysicalPath(?string $localPath): ?string
{
    if (!$localPath)
        return null;
    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');
    if (file_exists($localPath))
        return realpath($localPath);
    if (strpos($localPath, '/storage/') !== false) {
        $relativePath = substr($localPath, strpos($localPath, '/storage/') + 9);
        $relativePath = rawurldecode($relativePath);
        $candidate = $storageBase . '/' . $relativePath;
        if (file_exists($candidate))
            return realpath($candidate);
    }
    if (strpos($localPath, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($localPath);
        if (file_exists($candidate))
            return realpath($candidate);
    }
    $candidate = $baseDir . '/' . ltrim(rawurldecode($localPath), '/');
    if (file_exists($candidate))
        return realpath($candidate);
    return null;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT local_path FROM documents WHERE local_path LIKE '%manual_uploads%'");
$stmt->execute();
$dbDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$missing = [];
foreach ($dbDocs as $doc) {
    if (!resolvePhysicalPath($doc['local_path'])) {
        // Here's the trick: they aren't missing locally! They are missing on the SERVER.
        // My previous audit was run on the server. To run it LOCALLY, I need to check
        // if they exist LOCALLLY and if they are in the list of what the server SAID was missing.
        // Actually, the user wants to upload files that ARE local but MISSING on the server.
    }
}
?>