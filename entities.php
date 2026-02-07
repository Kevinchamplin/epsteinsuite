<?php
require_once __DIR__ . '/includes/db.php';

$page = $_GET['page'] ?? 1;
$limit = 50;
$offset = ($page - 1) * $limit;
$type = $_GET['type'] ?? 'all';
$search = $_GET['s'] ?? '';

try {
    $pdo = db();
    
    // Build Query
    $where = [];
    $params = [];
    
    if ($type !== 'all') {
        $where[] = "type = :type";
        $params['type'] = ucfirst($type);
    }
    
    if ($search) {
        $where[] = "name LIKE :search";
        $params['search'] = "%$search%";
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get Entities
    $sql = "
        SELECT e.*, COUNT(de.document_id) as doc_count 
        FROM entities e 
        LEFT JOIN document_entities de ON e.id = de.entity_id 
        $whereClause
        GROUP BY e.id 
        ORDER BY doc_count DESC, e.name ASC 
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entities = $stmt->fetchAll();
    
    // Get Total Count for Pagination
    $countSql = "SELECT COUNT(*) FROM entities $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetchColumn();
    $totalPages = ceil($total / $limit);

} catch (Exception $e) {
    $entities = [];
    $total = 0;
    $totalPages = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entities | Epstein Files</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 font-sans min-h-screen flex flex-col">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="/" class="text-xl font-bold tracking-tight text-slate-900">Epstein Files <span class="text-blue-600">Search</span></a>
            </div>
            <nav class="hidden md:flex gap-6 text-sm font-medium text-slate-600">
                <a href="/" class="hover:text-blue-600">Browse</a>
                <a href="/flight_logs.php" class="hover:text-blue-600">Flight Logs</a>
                <a href="/entities.php" class="text-blue-600">Entities</a>
                <a href="/email_client.php" class="text-red-600 hover:text-red-700 font-bold">Epstein Mail</a>
                <a href="/stats.php" class="text-emerald-600 hover:text-emerald-700 flex items-center gap-1">
                    <span class="relative flex h-2 w-2">
                      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                      <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    Live Dashboard
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow max-w-7xl mx-auto px-4 py-10 w-full">
        <div class="flex flex-col md:flex-row justify-between items-end mb-8 gap-4">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 mb-2">Entities Index</h1>
                <p class="text-slate-600">Browse people, organizations, and locations extracted from the files.</p>
            </div>
            
            <form class="flex gap-2 w-full md:w-auto">
                <select name="type" class="bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="person" <?= $type === 'person' ? 'selected' : '' ?>>People</option>
                    <option value="organization" <?= $type === 'organization' ? 'selected' : '' ?>>Organizations</option>
                    <option value="location" <?= $type === 'location' ? 'selected' : '' ?>>Locations</option>
                </select>
                <input type="text" name="s" value="<?= htmlspecialchars($search) ?>" placeholder="Find entity..." class="bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-full md:w-64">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">Filter</button>
            </form>
        </div>

        <?php if (empty($entities)): ?>
            <div class="bg-white p-12 text-center rounded-xl border border-slate-200 text-slate-500">
                No entities found matching your criteria.
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($entities as $entity): ?>
                    <a href="/?q=<?= urlencode($entity['name']) ?>" class="group bg-white p-4 rounded-xl border border-slate-200 hover:border-blue-400 hover:shadow-sm transition-all flex justify-between items-center">
                        <div class="flex items-center gap-3 overflow-hidden">
                            <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center 
                                <?= match($entity['type']) {
                                    'Person' => 'bg-blue-100 text-blue-600',
                                    'Organization' => 'bg-purple-100 text-purple-600',
                                    'Location' => 'bg-emerald-100 text-emerald-600',
                                    default => 'bg-slate-100 text-slate-600'
                                } ?>">
                                <?php if ($entity['type'] === 'Person'): ?>
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                <?php elseif ($entity['type'] === 'Organization'): ?>
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                                <?php else: ?>
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-medium text-slate-900 truncate group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($entity['name']) ?></h3>
                                <p class="text-xs text-slate-500 uppercase tracking-wider"><?= htmlspecialchars($entity['type']) ?></p>
                            </div>
                        </div>
                        <span class="flex-shrink-0 bg-slate-100 text-slate-600 text-xs font-bold px-2 py-1 rounded">
                            <?= $entity['doc_count'] ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="mt-8 flex justify-center gap-2">
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a href="?page=<?= $i ?>&type=<?= $type ?>&s=<?= urlencode($search) ?>" 
                           class="px-4 py-2 rounded-lg text-sm font-medium transition-colors
                           <?= $i == $page ? 'bg-blue-600 text-white' : 'bg-white border border-slate-300 text-slate-700 hover:bg-slate-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <footer class="bg-slate-900 text-slate-400 py-12 mt-auto">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <p>&copy; <?= date('Y') ?> Kevin Champlin. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
