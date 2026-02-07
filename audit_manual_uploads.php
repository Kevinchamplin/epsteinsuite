<?php
require_once __DIR__ . '/includes/db.php';

// Helper for robust path resolution (DRYing the logic we added to other files)
function resolvePhysicalPath(?string $localPath): ?string
{
    if (!$localPath)
        return null;
    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');

    // 1. Try raw localPath
    if (file_exists($localPath)) {
        return realpath($localPath);
    }

    // 2. Handle /storage/ paths
    if (strpos($localPath, '/storage/') !== false) {
        $relativePath = substr($localPath, strpos($localPath, '/storage/') + 9);
        $relativePath = rawurldecode($relativePath);
        $candidate = $storageBase . '/' . $relativePath;
        if (file_exists($candidate)) {
            return realpath($candidate);
        }
    }

    // 3. Handle storage/ paths
    if (strpos($localPath, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($localPath);
        if (file_exists($candidate)) {
            return realpath($candidate);
        }
    }

    // 4. Final attempt
    $candidate = $baseDir . '/' . ltrim(rawurldecode($localPath), '/');
    if (file_exists($candidate)) {
        return realpath($candidate);
    }

    return null;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, title, local_path, data_set FROM documents WHERE local_path LIKE '%manual_uploads%' ORDER BY id DESC");
$stmt->execute();
$dbDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
$counts = [
    'total' => count($dbDocs),
    'found' => 0,
    'missing' => 0
];

foreach ($dbDocs as $doc) {
    $physical = resolvePhysicalPath($doc['local_path']);
    $exists = $physical !== null;

    if ($exists) {
        $counts['found']++;
    } else {
        $counts['missing']++;
    }

    $results[] = [
        'id' => $doc['id'],
        'title' => $doc['title'],
        'db_path' => $doc['local_path'],
        'physical_path' => $physical,
        'exists' => $exists,
        'data_set' => $doc['data_set']
    ];
}

// Optional: Scan filesystem for orphans
$storageManualBase = __DIR__ . '/storage/manual_uploads';
$allFiles = [];
if (is_dir($storageManualBase)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storageManualBase));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $allFiles[] = realpath($file->getPathname());
        }
    }
}

$dbPhysicalPaths = array_filter(array_column($results, 'physical_path'));
$orphans = array_diff($allFiles, $dbPhysicalPaths);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Uploads Audit</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .status-badge {
            @apply px-2 py-1 rounded text-xs font-bold uppercase;
        }

        .status-found {
            @apply bg-green-100 text-green-800 border border-green-200;
        }

        .status-missing {
            @apply bg-red-100 text-red-800 border border-red-200;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen p-8">
    <div class="max-w-7xl mx-auto">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Manual Uploads Audit</h1>
            <p class="text-gray-600 mt-2">Checking discrepancy between database entries and physical files on disk.</p>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                <div class="text-sm text-gray-500 font-medium">Total DB Entries</div>
                <div class="text-3xl font-bold text-blue-600"><?= $counts['total'] ?></div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                <div class="text-sm text-gray-500 font-medium">Files Found</div>
                <div class="text-3xl font-bold text-green-600"><?= $counts['found'] ?></div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                <div class="text-sm text-gray-500 font-medium">Files Missing</div>
                <div class="text-3xl font-bold text-red-600"><?= $counts['missing'] ?></div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h2 class="text-lg font-semibold text-gray-800">Audit Details</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-200">
                            <th class="px-6 py-3 font-semibold text-gray-600">ID</th>
                            <th class="px-6 py-3 font-semibold text-gray-600">Title / Data Set</th>
                            <th class="px-6 py-3 font-semibold text-gray-600">Database Path</th>
                            <th class="px-6 py-3 font-semibold text-gray-600">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($results as $res): ?>
                            <tr class="<?= $res['exists'] ? 'hover:bg-green-50/30' : 'bg-red-50/50 hover:bg-red-50' ?>">
                                <td class="px-6 py-4 font-mono text-gray-500"><?= $res['id'] ?></td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-gray-900"><?= htmlspecialchars($res['title']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($res['data_set'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs font-mono break-all max-w-md text-gray-600">
                                    <?= htmlspecialchars($res['db_path']) ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($res['exists']): ?>
                                        <span class="status-badge status-found">Found</span>
                                    <?php else: ?>
                                        <span class="status-badge status-missing">Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (count($orphans) > 0): ?>
            <div class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-yellow-50">
                    <h2 class="text-lg font-semibold text-yellow-800">Orphaned Files (<?= count($orphans) ?>)</h2>
                    <p class="text-xs text-yellow-700">Files physically present in manual_uploads but not found in DB.</p>
                </div>
                <div class="p-6">
                    <ul class="text-xs font-mono space-y-1 text-gray-600">
                        <?php foreach ($orphans as $orphan): ?>
                            <li><?= htmlspecialchars(str_replace(__DIR__, '', $orphan)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>