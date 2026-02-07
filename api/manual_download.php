<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = [];
if (is_string($rawInput) && $rawInput !== '') {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$documentId = isset($payload['id']) ? (int)$payload['id'] : 0;
if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid document id']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, source_url, file_type, local_path, status FROM documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Document not found']);
        exit;
    }

    $sourceUrl = trim((string)($doc['source_url'] ?? ''));
    if ($sourceUrl === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Document is missing a source URL']);
        exit;
    }

    $baseDir = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
    $storageDir = $baseDir . '/storage/documents';
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Unable to create storage directory');
    }

    $downloadUrl = convert_google_drive_url($sourceUrl);
    $extension = detect_extension((string)$doc['file_type'], $sourceUrl);
    $filename = sprintf('doc_%d%s', $documentId, $extension);
    $finalPath = $storageDir . '/' . $filename;

    $tmpFile = tempnam(sys_get_temp_dir(), 'doc_dl_');
    if ($tmpFile === false) {
        throw new RuntimeException('Unable to allocate temp file');
    }

    $downloadedBytes = download_file_to_path($downloadUrl, $tmpFile);
    if ($downloadedBytes <= 0) {
        @unlink($tmpFile);
        throw new RuntimeException('The file was empty or could not be downloaded');
    }

    if (!@rename($tmpFile, $finalPath)) {
        @unlink($tmpFile);
        throw new RuntimeException('Failed to move downloaded file into storage');
    }

    chmod($finalPath, 0644);

    $update = $pdo->prepare("
        UPDATE documents
           SET local_path = :path,
               status = CASE WHEN status = 'pending' THEN 'downloaded' ELSE status END,
               updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
    ");
    $update->execute([
        ':path' => $finalPath,
        ':id' => $documentId,
    ]);

    echo json_encode([
        'ok' => true,
        'id' => $documentId,
        'local_path' => $finalPath,
        'serve_url' => '/serve.php?id=' . $documentId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function convert_google_drive_url(string $url): string
{
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $fileId = $matches[1];
        return "https://drive.google.com/uc?export=download&id={$fileId}";
    }
    return $url;
}

function detect_extension(?string $fileType, string $url): string
{
    $type = strtolower((string)$fileType);
    if ($type !== '') {
        return match ($type) {
            'jpg', 'jpeg' => '.jpg',
            'png' => '.png',
            'gif' => '.gif',
            'tif', 'tiff' => '.tiff',
            'mp4' => '.mp4',
            'mov' => '.mov',
            'mp3' => '.mp3',
            default => '.pdf',
        };
    }

    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => '.jpg',
        'png' => '.png',
        'gif' => '.gif',
        'tif', 'tiff' => '.tiff',
        'mp4' => '.mp4',
        'mov' => '.mov',
        'mp3' => '.mp3',
        default => '.pdf',
    };
}

function download_file_to_path(string $url, string $targetPath): int
{
    $fp = fopen($targetPath, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Unable to open temp file for writing');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        throw new RuntimeException('Unable to initialize download');
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; EpsteinFilesBot/1.0)',
        CURLOPT_FAILONERROR => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
        ],
    ]);

    $success = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($success !== true) {
        throw new RuntimeException($err !== '' ? $err : 'Failed to download file');
    }

    return filesize($targetPath) ?: 0;
}
