<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

// PHP-FPM fallback: parse HTTP_AUTHORIZATION into PHP_AUTH_USER/PW
// Apache RewriteRule may prefix with REDIRECT_
$httpAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!isset($_SERVER['PHP_AUTH_USER']) && $httpAuth !== '') {
    $authParts = explode(' ', $httpAuth, 2);
    if (strtolower($authParts[0] ?? '') === 'basic' && isset($authParts[1])) {
        $decoded = base64_decode($authParts[1]);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] = explode(':', $decoded, 2);
        }
    }
}

// Admin auth via ADMIN_KEY header or Basic Auth
$adminKey = env_value('ADMIN_KEY');
$authHeader = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
$basicValid = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
    && hash_equals(env_value('ADMIN_USER') ?: 'admin', $_SERVER['PHP_AUTH_USER'])
    && hash_equals(env_value('ADMIN_PASSWORD') ?: '', $_SERVER['PHP_AUTH_PW']);

if (!$basicValid && (!$adminKey || !hash_equals($adminKey, $authHeader))) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$dataset = trim($input['dataset'] ?? '');
$method = trim($input['method'] ?? '');
$limit = (int)($input['limit'] ?? 200);

if (!$dataset || !$method) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing dataset or method']);
    exit;
}

$allowedMethods = ['zip', 'scrape', 'ocr', 'ai'];
if (!in_array($method, $allowedMethods, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid method. Allowed: ' . implode(', ', $allowedMethods)]);
    exit;
}

$scriptsDir = realpath(__DIR__ . '/../scripts');
$logDir = realpath(__DIR__ . '/../storage/logs');
$projectRoot = realpath(__DIR__ . '/..');

if (!$scriptsDir || !$logDir || !$projectRoot) {
    http_response_code(500);
    echo json_encode(['error' => 'Scripts or logs directory not found']);
    exit;
}

// Find venv: could be scripts/venv (local) or project-root/venv (server)
$venvActivate = '';
if (is_file($scriptsDir . '/venv/bin/activate')) {
    $venvActivate = 'source ' . escapeshellarg($scriptsDir . '/venv/bin/activate') . ' && ';
} elseif (is_file($projectRoot . '/venv/bin/activate')) {
    $venvActivate = 'source ' . escapeshellarg($projectRoot . '/venv/bin/activate') . ' && ';
}

$timestamp = date('Ymd_His');
$safeDataset = preg_replace('/[^a-zA-Z0-9_-]/', '_', $dataset);
$logFile = $logDir . "/dataset_download_{$safeDataset}_{$timestamp}.log";

// ZIP download map (datasets 1-8, 12)
$zipDatasets = [];
for ($i = 1; $i <= 8; $i++) {
    $zipDatasets["DOJ - Data Set {$i}"] = true;
}
$zipDatasets['DOJ - Data Set 12'] = true;

$command = null;

if ($method === 'zip') {
    if (!isset($zipDatasets[$dataset])) {
        http_response_code(400);
        echo json_encode(['error' => "No ZIP available for {$dataset}. Use scrape method instead."]);
        exit;
    }
    // Use existing download_doj_zips.py - it processes all ZIPs in sequence,
    // but the script is safe to run (skips already-downloaded ZIPs)
    $command = sprintf(
        'cd %s && %spython3 download_doj_zips.py >> %s 2>&1',
        escapeshellarg($scriptsDir),
        $venvActivate,
        escapeshellarg($logFile)
    );
} elseif ($method === 'scrape') {
    // Use download_and_ocr.py with --dataset filter
    $command = sprintf(
        'cd %s && %spython3 download_and_ocr.py --dataset %s --limit %d >> %s 2>&1',
        escapeshellarg($scriptsDir),
        $venvActivate,
        escapeshellarg($dataset),
        $limit,
        escapeshellarg($logFile)
    );
} elseif ($method === 'ocr') {
    // Run OCR/AI on already-downloaded documents
    $command = sprintf(
        'cd %s && %spython3 download_and_ocr.py --dataset %s --limit %d >> %s 2>&1',
        escapeshellarg($scriptsDir),
        $venvActivate,
        escapeshellarg($dataset),
        $limit,
        escapeshellarg($logFile)
    );
} elseif ($method === 'ai') {
    // Generate AI summaries for processed documents missing summaries
    $command = sprintf(
        'cd %s && %spython3 generate_ai_summaries.py --dataset %s --limit %d >> %s 2>&1',
        escapeshellarg($scriptsDir),
        $venvActivate,
        escapeshellarg($dataset),
        $limit,
        escapeshellarg($logFile)
    );
}

if (!$command) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to build command']);
    exit;
}

// Run in background — redirect nohup's own output to /dev/null so exec() returns immediately
$bgCommand = sprintf('nohup bash -c %s > /dev/null 2>&1 &', escapeshellarg($command));
exec($bgCommand);

echo json_encode([
    'ok' => true,
    'dataset' => $dataset,
    'method' => $method,
    'log_file' => basename($logFile),
    'message' => "Download started in background for {$dataset} via {$method}",
]);
