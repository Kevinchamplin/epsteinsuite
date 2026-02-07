<?php
/**
 * Video Thumbnail Generator
 * Generates thumbnails from video files using FFmpeg
 * Usage: /video_thumb.php?id=123
 */

$docId = $_GET['id'] ?? null;

if (!$docId) {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/includes/db.php';

// Cache directory for thumbnails
$cacheDir = __DIR__ . '/cache/video_thumbs';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . '/thumb_' . (int)$docId . '.jpg';

// Serve cached thumbnail if exists
if (file_exists($cacheFile) && filemtime($cacheFile) > time() - 86400 * 30) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=2592000'); // 30 days
    readfile($cacheFile);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT local_path, source_url, file_type FROM documents WHERE id = ?");
    $stmt->execute([(int)$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        http_response_code(404);
        exit;
    }
    
    $videoPath = null;
    
    // Check for local file
    if (!empty($doc['local_path'])) {
        $localPath = $doc['local_path'];
        
        // Handle various path formats
        if (file_exists($localPath)) {
            $videoPath = $localPath;
        } else {
            // Try relative to document root
            $checkPaths = [
                __DIR__ . $localPath,
                __DIR__ . '/' . ltrim($localPath, '/'),
            ];
            foreach ($checkPaths as $path) {
                if (file_exists($path)) {
                    $videoPath = $path;
                    break;
                }
            }
        }
    }
    
    if (!$videoPath) {
        // No local video, return placeholder
        http_response_code(404);
        exit;
    }
    
    // Check if FFmpeg is available
    $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
    if (empty($ffmpegPath)) {
        $ffmpegPath = '/usr/bin/ffmpeg';
    }
    
    if (!file_exists($ffmpegPath)) {
        // FFmpeg not available, return 404
        http_response_code(404);
        exit;
    }
    
    // Generate thumbnail at 1 second mark
    $cmd = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($videoPath) . 
           ' -ss 00:00:01 -vframes 1 -vf scale=400:-1 -f image2 ' . 
           escapeshellarg($cacheFile) . ' 2>&1';
    
    exec($cmd, $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($cacheFile)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=2592000');
        readfile($cacheFile);
        exit;
    }
    
    // FFmpeg failed, return 404
    http_response_code(404);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    exit;
}
