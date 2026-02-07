<?php
/**
 * Cache Admin - View stats and clear cache
 * Access: /admin/cache.php
 */

require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/db.php';

// Simple auth check - use a secret key from .env or URL param
$envPath = __DIR__ . '/../.env';
$adminKey = '';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
    $adminKey = $env['ADMIN_KEY'] ?? $env['CACHE_CLEAR_KEY'] ?? '';
}

// Check authorization
$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
$isAuthorized = !empty($adminKey) && hash_equals($adminKey, $providedKey);

// Also allow if coming from localhost
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

if (!$isAuthorized && !$isLocalhost) {
    http_response_code(403);
    echo "Unauthorized. Add ?key=YOUR_ADMIN_KEY or access from localhost.";
    exit;
}

$message = '';
$messageType = '';

// Handle cache clear action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clear_all') {
        $count = Cache::clear();
        $message = "Cleared {$count} cache files.";
        $messageType = 'success';
    } elseif ($_POST['action'] === 'clear_key' && !empty($_POST['cache_key'])) {
        Cache::delete($_POST['cache_key']);
        $message = "Deleted cache key: " . htmlspecialchars($_POST['cache_key']);
        $messageType = 'success';
    }
}

// Get cache stats
$stats = Cache::stats();

// Get list of cache files with details
$cacheDir = __DIR__ . '/../cache';
$cacheFiles = [];
if (is_dir($cacheDir)) {
    $files = glob($cacheDir . '/*.cache');
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $data = @unserialize($content);
        $key = basename($file, '.cache');
        
        $cacheFiles[] = [
            'key' => $key,
            'size' => filesize($file),
            'created' => $data['created'] ?? filemtime($file),
            'expires' => $data['expires'] ?? 0,
            'is_expired' => ($data['expires'] ?? 0) > 0 && ($data['expires'] ?? 0) < time(),
        ];
    }
    
    // Sort by created time, newest first
    usort($cacheFiles, fn($a, $b) => $b['created'] - $a['created']);
}

$page_title = 'Cache Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> | Epstein Files</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-4xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Cache Admin</h1>
            <a href="/stats.php" class="text-blue-600 hover:underline text-sm">← Back to Stats</a>
        </div>
        
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl p-4 shadow-sm">
                <div class="text-2xl font-bold text-slate-900"><?= $stats['total_files'] ?></div>
                <div class="text-sm text-slate-500">Cache Files</div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm">
                <div class="text-2xl font-bold text-green-600"><?= $stats['valid'] ?></div>
                <div class="text-sm text-slate-500">Valid</div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm">
                <div class="text-2xl font-bold text-orange-600"><?= $stats['expired'] ?></div>
                <div class="text-sm text-slate-500">Expired</div>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm">
                <div class="text-2xl font-bold text-slate-900"><?= $stats['total_size_human'] ?></div>
                <div class="text-sm text-slate-500">Total Size</div>
            </div>
        </div>
        
        <!-- Clear All Button -->
        <div class="bg-white rounded-xl p-6 shadow-sm mb-6">
            <h2 class="text-lg font-semibold text-slate-800 mb-4">Clear Cache</h2>
            <form method="POST" onsubmit="return confirm('Are you sure you want to clear all cache?');">
                <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                    Clear All Cache (<?= $stats['total_files'] ?> files)
                </button>
            </form>
        </div>
        
        <!-- Cache Files List -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-800">Cache Files</h2>
            </div>
            
            <?php if (empty($cacheFiles)): ?>
                <div class="p-6 text-center text-slate-500">No cache files found.</div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($cacheFiles as $file): ?>
                        <div class="p-4 flex items-center justify-between hover:bg-slate-50">
                            <div class="flex-1 min-w-0">
                                <div class="font-mono text-sm text-slate-800 truncate"><?= htmlspecialchars($file['key']) ?></div>
                                <div class="text-xs text-slate-500 mt-1">
                                    <?= number_format($file['size']) ?> bytes
                                    • Created <?= date('M j, g:ia', $file['created']) ?>
                                    <?php if ($file['expires'] > 0): ?>
                                        • <?= $file['is_expired'] ? '<span class="text-orange-600">Expired</span>' : 'Expires ' . date('M j, g:ia', $file['expires']) ?>
                                    <?php else: ?>
                                        • <span class="text-green-600">Never expires</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" class="ml-4">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($providedKey) ?>">
                                <input type="hidden" name="action" value="clear_key">
                                <input type="hidden" name="cache_key" value="<?= htmlspecialchars($file['key']) ?>">
                                <button type="submit" class="text-red-600 hover:text-red-800 text-sm">Delete</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Clear URL -->
        <div class="mt-6 p-4 bg-slate-200 rounded-lg">
            <h3 class="font-semibold text-slate-700 mb-2">Quick Clear URL</h3>
            <p class="text-sm text-slate-600 mb-2">Bookmark this to quickly clear cache:</p>
            <code class="block p-2 bg-white rounded text-xs break-all">
                <?= htmlspecialchars((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/admin/cache.php?key=' . $providedKey) ?>
            </code>
        </div>
    </div>
</body>
</html>
