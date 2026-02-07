<?php
declare(strict_types=1);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * Simple PDO helper with .env fallback.
 */
function env_value(string $key): ?string
{
    $val = getenv($key);
    if (is_string($val) && $val !== '') {
        return trim(trim($val), "\"' \t");
    }

    $paths = [
        __DIR__ . '/../.env',
        __DIR__ . '/../../.env', // parent project .env
    ];

    foreach ($paths as $envPath) {
        if (!is_file($envPath) || !is_readable($envPath)) {
            continue;
        }
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^' . preg_quote($key, '/') . '\\s*=\\s*(.+)$/i', $line, $m)) {
                $val = trim($m[1]);
                return trim($val, "\"' \t");
            }
        }
    }

    return null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST') ?? 'localhost';
    $name = env_value('DB_NAME') ?? 'epstein_db'; // Default to expected DB name
    $user = env_value('DB_USERNAME') ?? 'root';
    $pass = env_value('DB_PASSWORD') ?? '';

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database configuration is missing.');
    }

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET SESSION wait_timeout=30, SESSION net_read_timeout=30");
    } catch (\PDOException $e) {
        // Log the real error for admins
        error_log('Database connection failed: ' . $e->getMessage());

        // Show a friendly error page to visitors
        http_response_code(503);
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Temporarily Unavailable &middot; Epstein Suite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:"Inter",sans-serif;}</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center px-4">
    <div class="max-w-md w-full text-center space-y-6">
        <div class="w-16 h-16 bg-slate-900 text-white rounded-2xl flex items-center justify-center text-2xl font-bold mx-auto">E</div>
        <h1 class="text-2xl font-bold text-slate-900">We\'ll Be Right Back</h1>
        <p class="text-slate-600 leading-relaxed">Epstein Suite is temporarily unable to connect to its database. This is usually resolved within a few minutes.</p>
        <div class="bg-white border border-slate-200 rounded-xl p-4 text-sm text-slate-500">
            <p class="font-medium text-slate-700 mb-1">What you can do:</p>
            <ul class="space-y-1 text-left list-disc list-inside">
                <li>Wait a moment and refresh the page</li>
                <li>Check back shortly &mdash; we\'re likely already on it</li>
            </ul>
        </div>
        <a href="/" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-900 text-white text-sm font-bold shadow-lg hover:-translate-y-0.5 transition-all">
            Try Again
        </a>
        <p class="text-xs text-slate-400">Error reference: 503 &middot; Service Unavailable</p>
    </div>
</body>
</html>';
        exit;
    }

    return $pdo;
}
