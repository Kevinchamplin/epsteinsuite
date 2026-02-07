<?php
/**
 * File Server - Serves local files from storage directory
 * Provides a reliable way to serve downloaded files without exposing full paths
 */

$docId = $_GET['id'] ?? null;
$download = isset($_GET['download']);

if (!$docId) {
    http_response_code(400);
    echo "Missing document ID";
    exit;
}

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT local_path, file_type, title FROM documents WHERE id = ?");
    $stmt->execute([(int) $docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc || empty($doc['local_path'])) {
        http_response_code(404);
        echo "File not found";
        exit;
    }

    $localPath = $doc['local_path'];

    // Security/Resolution: Unify how we find the file
    $storageBase = realpath(__DIR__ . '/storage');
    $fullPath = null;

    // 1. Try raw localPath (works if it's already a correct relative or absolute path for this machine)
    if (file_exists($localPath)) {
        $fullPath = realpath($localPath);
    }

    // 2. If it contains /storage/, try to re-anchor it to our current storage directory
    // This fixed issues where paths from another machine (e.g. /Users/kevin/...) were stored
    if (!$fullPath && strpos($localPath, '/storage/') !== false) {
        $relativePath = substr($localPath, strpos($localPath, '/storage/') + 9);
        // Handle potential URL encoding in the stored path
        $relativePath = rawurldecode($relativePath);
        $candidate = $storageBase . '/' . $relativePath;
        if (file_exists($candidate)) {
            $fullPath = realpath($candidate);
        }
    }

    // 3. Try relative to __DIR__ if it starts with storage/
    if (!$fullPath && strpos($localPath, 'storage/') === 0) {
        $candidate = __DIR__ . '/' . rawurldecode($localPath);
        if (file_exists($candidate)) {
            $fullPath = realpath($candidate);
        }
    }

    // 4. Final attempt: assume it's just a filename or partial path we can find in storage
    if (!$fullPath) {
        $candidate = $storageBase . '/' . ltrim(rawurldecode($localPath), '/');
        if (file_exists($candidate)) {
            $fullPath = realpath($candidate);
        }
    }

    if (!$fullPath || !file_exists($fullPath)) {
        http_response_code(404);
        echo "File not found on disk: " . htmlspecialchars(basename($localPath)) . "<br>";
        echo "DEBUG: __DIR__ is " . __DIR__ . "<br>";
        echo "DEBUG: storageBase is " . $storageBase . "<br>";
        echo "DEBUG: localPath is " . $localPath . "<br>";
        echo "DEBUG: Checked candidate " . (__DIR__ . '/' . rawurldecode($localPath)) . "<br>";
        exit;
    }

    // Security check - must be in storage directory
    if ($storageBase && strpos($fullPath, $storageBase) !== 0) {
        http_response_code(403);
        echo "Access denied";
        exit;
    }

    // Determine content type
    $fileType = strtolower($doc['file_type'] ?? '');
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

    $contentTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'txt' => 'text/plain',
    ];

    $contentType = $contentTypes[$ext] ?? $contentTypes[$fileType] ?? 'application/octet-stream';

    // Set headers
    header('Content-Type: ' . $contentType);
    $fileSize = filesize($fullPath);
    header('Content-Length: ' . $fileSize);

    if ($download) {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['title']) . '.' . $ext;
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Disposition: inline');
    }

    // Cache for 1 hour
    header('Cache-Control: public, max-age=3600');

    // Stream file in chunks to avoid exhausting memory
    if (ob_get_level()) {
        ob_end_clean();
    }

    $chunkSize = 1024 * 1024; // 1MB chunks

    // HTTP Range support for video/audio seeking in browsers
    $isMedia = in_array($ext, ['mp4', 'mov', 'webm', 'mp3', 'wav', 'ogg', 'm4a'], true)
            || in_array($fileType, ['video', 'audio'], true);

    if ($isMedia && isset($_SERVER['HTTP_RANGE'])) {
        $range = $_SERVER['HTTP_RANGE'];
        if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = (int) $matches[1];
            $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;

            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                header("Content-Range: bytes */$fileSize");
                exit;
            }

            $end = min($end, $fileSize - 1);
            $length = $end - $start + 1;

            http_response_code(206);
            header("Content-Range: bytes $start-$end/$fileSize");
            header("Content-Length: $length");
            header('Accept-Ranges: bytes');

            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                http_response_code(500);
                echo "Unable to open file";
                exit;
            }
            fseek($handle, $start);
            set_time_limit(0);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $read = min($chunkSize, $remaining);
                echo fread($handle, $read);
                flush();
                $remaining -= $read;
            }
            fclose($handle);
            exit;
        }
    }

    // Non-range request: serve full file with Accept-Ranges header for media
    if ($isMedia) {
        header('Accept-Ranges: bytes');
    }

    $handle = fopen($fullPath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        echo "Unable to open file";
        exit;
    }
    set_time_limit(0);
    while (!feof($handle)) {
        echo fread($handle, $chunkSize);
        flush();
    }
    fclose($handle);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo "Server error";
    exit;
}
