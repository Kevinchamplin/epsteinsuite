<?php
function test_resolve($localPath, $id = 18234)
{
    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');
    $fullPath = null;

    echo "Testing path: $localPath\n";

    // 1. Try raw localPath
    if (file_exists($localPath)) {
        $fullPath = realpath($localPath);
        echo "  - Found via raw path\n";
    }

    // 2. Handle /storage/ paths
    if (!$fullPath && strpos($localPath, '/storage/') !== false) {
        $relativePath = substr($localPath, strpos($localPath, '/storage/') + 9);
        $relativePath = rawurldecode($relativePath);
        $candidate = $storageBase . '/' . $relativePath;
        if (file_exists($candidate)) {
            $fullPath = realpath($candidate);
            echo "  - Found via /storage/ anchor (decoded: $relativePath)\n";
        }
    }

    // 3. Handle storage/ paths
    if (!$fullPath && strpos($localPath, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($localPath);
        if (file_exists($candidate)) {
            $fullPath = realpath($candidate);
            echo "  - Found via storage/ anchor\n";
        }
    }

    // 4. Final attempt
    if (!$fullPath) {
        $candidate = $baseDir . '/' . ltrim(rawurldecode($localPath), '/');
        if (file_exists($candidate)) {
            $fullPath = realpath($candidate);
            echo "  - Found via baseDir anchor\n";
        }
    }

    if ($fullPath) {
        echo "  - SUCCESS: $fullPath\n";
    } else {
        echo "  - FAILURE: Could not resolve\n";
    }
    echo "-----------------------------------\n";
}

$testPaths = [
    "storage/manual_uploads/photos/IMAGES 8/002/HOUSE_OVERSIGHT_013573.jpg", // Raw spaces
    "storage/manual_uploads/photos/IMAGES%208/002/HOUSE_OVERSIGHT_013573.jpg", // URL encoded
    "/Users/kevin/some/other/machine/storage/manual_uploads/photos/IMAGES 8/002/HOUSE_OVERSIGHT_013573.jpg", // Absolute from other machine
    "/storage/manual_uploads/photos/IMAGES 8/002/HOUSE_OVERSIGHT_013573.jpg", // Root-ish path
    "manual_uploads/photos/IMAGES 8/002/HOUSE_OVERSIGHT_013573.jpg", // Partial path
];

foreach ($testPaths as $p) {
    test_resolve($p);
}
?>