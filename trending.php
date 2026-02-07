<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$period = $_GET['period'] ?? '30';
$validPeriods = ['7' => '7 Days', '30' => '30 Days', '90' => '90 Days', 'all' => 'All Time'];
if (!array_key_exists($period, $validPeriods)) {
    $period = '30';
}

function buildTrendingUrl(array $overrides = []): string
{
    global $period;
    $params = [];
    $p = $overrides['period'] ?? $period;
    if ($p !== '30') {
        $params['period'] = $p;
    }
    return 'trending.php' . ($params ? '?' . http_build_query($params) : '');
}

function sanitizeTrendingMedia(?string $value): ?string
{
    if ($value === null) return null;
    $cleaned = str_replace(["\r", "\n", "\t"], '', $value);
    $cleaned = trim($cleaned);
    return $cleaned === '' ? null : $cleaned;
}

function trendingServeUrl(int $documentId, ?string $localPath): ?string
{
    $clean = sanitizeTrendingMedia($localPath);
    if (!$clean) return null;
    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');
    if (file_exists($clean)) return '/serve.php?id=' . $documentId;
    if (strpos($clean, '/storage/') !== false) {
        $relativePath = substr($clean, strpos($clean, '/storage/') + 9);
        $candidate = $storageBase . '/' . rawurldecode($relativePath);
        if (file_exists($candidate)) return '/serve.php?id=' . $documentId;
    }
    if (strpos($clean, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($clean);
        if (file_exists($candidate)) return '/serve.php?id=' . $documentId;
    }
    $candidate = $baseDir . '/' . ltrim(rawurldecode($clean), '/');
    if (file_exists($candidate)) return '/serve.php?id=' . $documentId;
    return null;
}

function trendingThumbnail(?string $url): string
{
    $url = sanitizeTrendingMedia($url);
    if (!$url) return '';
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://drive.google.com/thumbnail?id={$matches[1]}&sz=w200";
    }
    return $url;
}

function trendingMediaUrl(?string $localPath, ?string $sourceUrl): ?string
{
    $url = sanitizeTrendingMedia($localPath) ?? sanitizeTrendingMedia($sourceUrl);
    if (!$url) return null;
    if (!preg_match('/^https?:\/\//i', $url)) {
        $segments = array_map('rawurlencode', array_filter(explode('/', ltrim($url, '/')), fn($s) => $s !== ''));
        return '/' . implode('/', $segments);
    }
    return $url;
}

function rankBadgeClass(int $rank): string
{
    return match ($rank) {
        0 => 'bg-amber-500 text-white',
        1 => 'bg-slate-400 text-white',
        2 => 'bg-amber-700 text-white',
        default => 'bg-slate-100 text-slate-600',
    };
}

function fileTypeIcon(string $fileType): string
{
    $ft = strtolower($fileType);
    if (in_array($ft, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'])) {
        return '<svg class="w-4 h-4 text-pink-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>';
    }
    if ($ft === 'pdf') {
        return '<svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>';
    }
    return '<svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
}

try {
    $pdo = db();
    $cacheKey = "trending_v2_{$period}";

    $trendingData = Cache::remember($cacheKey, function () use ($pdo, $period) {
        $data = [];
        $dateFilter = ($period !== 'all')
            ? "AND created_at > DATE_SUB(NOW(), INTERVAL " . (int)$period . " DAY)"
            : "";

        // Trending Searches
        $data['searches'] = $pdo->query("
            SELECT query_normalized AS query,
                   COUNT(*) AS search_count,
                   MAX(result_count) AS best_result_count
            FROM search_logs
            WHERE LENGTH(query_normalized) >= 2
              AND result_count > 0
              {$dateFilter}
            GROUP BY query_normalized
            ORDER BY search_count DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Top Documents
        $docDateFilter = ($period !== 'all')
            ? "AND dv.created_at > DATE_SUB(NOW(), INTERVAL " . (int)$period . " DAY)"
            : "";
        $data['documents'] = $pdo->query("
            SELECT d.id, d.title, d.file_type, d.data_set,
                   COUNT(dv.id) AS view_count,
                   MAX(dv.created_at) AS last_viewed
            FROM document_views dv
            JOIN documents d ON d.id = dv.document_id
            WHERE 1=1 {$docDateFilter}
            GROUP BY d.id
            ORDER BY view_count DESC, last_viewed DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Top Photos
        $photoWhere = "(LOWER(d.file_type) IN ('jpg','jpeg','png','gif','webp','tif','tiff')
            OR d.source_url REGEXP '\\\\.(jpg|jpeg|png|gif|webp|tif|tiff)(\\\\?|$)')";
        $photoDateFilter = ($period !== 'all')
            ? "AND pv.created_at > DATE_SUB(NOW(), INTERVAL " . (int)$period . " DAY)"
            : "";
        $data['photos'] = $pdo->query("
            SELECT d.id, d.title, d.local_path, d.source_url, d.data_set,
                   COUNT(pv.id) AS view_count
            FROM photo_views pv
            JOIN documents d ON d.id = pv.document_id
            WHERE {$photoWhere} {$photoDateFilter}
            GROUP BY d.id
            ORDER BY view_count DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Top People (all-time, no date filter)
        $data['people'] = $pdo->query("
            SELECT e.id AS entity_id, e.name AS entity_name,
                   COUNT(DISTINCT de.document_id) AS doc_count,
                   SUM(de.frequency) AS total_mentions
            FROM entities e
            JOIN document_entities de ON de.entity_id = e.id
            WHERE e.type IN ('PERSON', 'Person')
            GROUP BY e.id
            ORDER BY doc_count DESC, total_mentions DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Top Passengers (all-time, no date filter)
        $data['passengers'] = $pdo->query("
            SELECT p.name, COUNT(DISTINCT p.flight_id) AS flight_count
            FROM passengers p
            GROUP BY p.name
            ORDER BY flight_count DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Summary stats
        $data['stats'] = [
            'total_searches' => (int)$pdo->query("SELECT COUNT(*) FROM search_logs WHERE 1=1 {$dateFilter}")->fetchColumn(),
            'total_doc_views' => (int)$pdo->query("SELECT COUNT(*) FROM document_views WHERE 1=1 " . (($period !== 'all') ? "AND created_at > DATE_SUB(NOW(), INTERVAL " . (int)$period . " DAY)" : ""))->fetchColumn(),
            'total_photo_views' => (int)$pdo->query("SELECT COUNT(*) FROM photo_views WHERE 1=1 " . (($period !== 'all') ? "AND created_at > DATE_SUB(NOW(), INTERVAL " . (int)$period . " DAY)" : ""))->fetchColumn(),
            'total_entities' => (int)$pdo->query("SELECT COUNT(DISTINCT e.id) FROM entities e JOIN document_entities de ON de.entity_id = e.id WHERE e.type IN ('PERSON','Person')")->fetchColumn(),
        ];

        return $data;
    }, 300);

} catch (Exception $e) {
    $trendingData = [
        'searches' => [], 'documents' => [], 'photos' => [],
        'people' => [], 'passengers' => [],
        'stats' => ['total_searches' => 0, 'total_doc_views' => 0, 'total_photo_views' => 0, 'total_entities' => 0],
    ];
}

$page_title = 'Trending';
$meta_description = 'See what\'s trending on Epstein Suite: the most-searched queries, most-viewed documents and photos, top mentioned people, and most frequent flight passengers.';
$canonical_url = 'https://epsteinsuite.com/trending.php' . ($period !== '30' ? '?period=' . $period : '');
$extra_head_tags = [];
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex-1 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="/" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4"/></svg>
                </a>
                <svg class="w-4 h-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="text-sm font-medium text-slate-700">Trending</span>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 tracking-tight mt-3">Trending</h1>
            <p class="text-slate-500 mt-1">The most popular searches, documents, photos, people, and flight passengers across the archive.</p>
        </div>

        <!-- Stats Bar -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-blue-700"><?= number_format($trendingData['stats']['total_searches']) ?></div>
                <div class="text-xs text-blue-600 font-medium mt-0.5">Searches</div>
            </div>
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-emerald-700"><?= number_format($trendingData['stats']['total_doc_views']) ?></div>
                <div class="text-xs text-emerald-600 font-medium mt-0.5">Doc Views</div>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-amber-700"><?= number_format($trendingData['stats']['total_photo_views']) ?></div>
                <div class="text-xs text-amber-600 font-medium mt-0.5">Photo Views</div>
            </div>
            <div class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-purple-700"><?= number_format($trendingData['stats']['total_entities']) ?></div>
                <div class="text-xs text-purple-600 font-medium mt-0.5">People Tracked</div>
            </div>
        </div>

        <!-- Period Filter -->
        <div class="flex items-center gap-2 mb-8">
            <span class="text-sm text-slate-500 font-medium">Period:</span>
            <?php foreach ($validPeriods as $key => $label): ?>
                <a href="<?= buildTrendingUrl(['period' => $key]) ?>"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $period === $key ? 'bg-blue-100 text-blue-800 border border-blue-300' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 border border-transparent' ?>">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Leaderboard Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Trending Searches -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Trending Searches</h2>
                        <p class="text-xs text-slate-500">Most searched queries by users</p>
                    </div>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php if (empty($trendingData['searches'])): ?>
                        <div class="px-5 py-8 text-center text-sm text-slate-400">No search data yet for this period.</div>
                    <?php else: ?>
                        <?php foreach ($trendingData['searches'] as $rank => $item): ?>
                            <a href="/?q=<?= urlencode($item['query']) ?>" class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50 transition-colors group">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 <?= rankBadgeClass($rank) ?>">
                                    <?= $rank + 1 ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-slate-800 group-hover:text-blue-600 transition-colors truncate block"><?= htmlspecialchars($item['query']) ?></span>
                                </div>
                                <span class="text-xs font-bold text-slate-400 flex-shrink-0"><?= number_format((int)$item['search_count']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Documents -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Top Documents</h2>
                        <p class="text-xs text-slate-500">Most viewed documents and files</p>
                    </div>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php if (empty($trendingData['documents'])): ?>
                        <div class="px-5 py-8 text-center text-sm text-slate-400">No document view data yet. Views are tracked as users browse documents.</div>
                    <?php else: ?>
                        <?php foreach ($trendingData['documents'] as $rank => $item): ?>
                            <a href="/document.php?id=<?= (int)$item['id'] ?>" class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50 transition-colors group">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 <?= rankBadgeClass($rank) ?>">
                                    <?= $rank + 1 ?>
                                </div>
                                <div class="flex-shrink-0"><?= fileTypeIcon($item['file_type'] ?? '') ?></div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-slate-800 group-hover:text-emerald-600 transition-colors line-clamp-1"><?= htmlspecialchars($item['title'] ?? 'Untitled') ?></span>
                                    <?php if (!empty($item['data_set'])): ?>
                                        <span class="text-[10px] text-slate-400"><?= htmlspecialchars($item['data_set']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs font-bold text-slate-400 flex-shrink-0"><?= number_format((int)$item['view_count']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Photos -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-slate-900">Top Photos</h2>
                            <p class="text-xs text-slate-500">Most viewed images from the archive</p>
                        </div>
                    </div>
                    <a href="/popular.php?period=<?= $period ?>" class="text-xs text-blue-600 hover:text-blue-800 font-medium">View all</a>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php if (empty($trendingData['photos'])): ?>
                        <div class="px-5 py-8 text-center text-sm text-slate-400">No photo view data yet for this period.</div>
                    <?php else: ?>
                        <?php foreach ($trendingData['photos'] as $rank => $photo):
                            $serveUrl = trendingServeUrl((int)$photo['id'], $photo['local_path'] ?? null);
                            $mediaUrl = $serveUrl ?? trendingMediaUrl($photo['local_path'] ?? null, $photo['source_url'] ?? null);
                            if ($mediaUrl && strpos($mediaUrl, 'drive.google.com') !== false) {
                                $mediaUrl = trendingThumbnail($mediaUrl);
                            }
                        ?>
                            <a href="/document.php?id=<?= (int)$photo['id'] ?>" class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50 transition-colors group">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 <?= rankBadgeClass($rank) ?>">
                                    <?= $rank + 1 ?>
                                </div>
                                <?php if ($mediaUrl): ?>
                                <div class="w-10 h-10 rounded-lg overflow-hidden bg-slate-100 flex-shrink-0">
                                    <img src="<?= htmlspecialchars($mediaUrl) ?>" alt="" class="w-full h-full object-cover" loading="lazy"
                                         onerror="this.style.display='none';">
                                </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-slate-800 group-hover:text-amber-600 transition-colors line-clamp-1"><?= htmlspecialchars($photo['title'] ?? 'Untitled') ?></span>
                                    <?php if (!empty($photo['data_set'])): ?>
                                        <span class="text-[10px] text-slate-400"><?= htmlspecialchars($photo['data_set']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs font-bold text-slate-400 flex-shrink-0"><?= number_format((int)$photo['view_count']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top People -->
            <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                    <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Top People</h2>
                        <p class="text-xs text-slate-500">Most mentioned individuals across documents</p>
                    </div>
                    <span class="ml-auto px-2 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-bold rounded-full uppercase tracking-wide">All-time</span>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php if (empty($trendingData['people'])): ?>
                        <div class="px-5 py-8 text-center text-sm text-slate-400">No entity data available.</div>
                    <?php else: ?>
                        <?php foreach ($trendingData['people'] as $rank => $item): ?>
                            <a href="/entity.php?id=<?= (int)$item['entity_id'] ?>" class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50 transition-colors group">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 <?= rankBadgeClass($rank) ?>">
                                    <?= $rank + 1 ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-slate-800 group-hover:text-purple-600 transition-colors truncate block"><?= htmlspecialchars($item['entity_name']) ?></span>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <span class="text-xs font-bold text-slate-400"><?= number_format((int)$item['doc_count']) ?> docs</span>
                                    <span class="text-[10px] text-slate-300 ml-1">(<?= number_format((int)$item['total_mentions']) ?> mentions)</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Top Passengers (full width) -->
        <div class="mt-6 bg-white border border-slate-200 rounded-xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
                <div class="w-8 h-8 bg-rose-100 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </div>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Top Flight Passengers</h2>
                    <p class="text-xs text-slate-500">Most frequent passengers on Epstein-linked flights</p>
                </div>
                <span class="ml-auto px-2 py-0.5 bg-slate-100 text-slate-500 text-[10px] font-bold rounded-full uppercase tracking-wide">All-time</span>
            </div>
            <?php if (empty($trendingData['passengers'])): ?>
                <div class="px-5 py-8 text-center text-sm text-slate-400">No flight passenger data available.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 divide-y sm:divide-y-0 divide-slate-50">
                    <?php foreach ($trendingData['passengers'] as $rank => $item): ?>
                        <a href="/flight_logs.php?search=<?= urlencode($item['name']) ?>" class="flex sm:flex-col items-center sm:items-center gap-3 sm:gap-1 px-5 py-3 sm:py-4 hover:bg-slate-50 transition-colors group text-center">
                            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 <?= rankBadgeClass($rank) ?>">
                                <?= $rank + 1 ?>
                            </div>
                            <span class="text-sm font-medium text-slate-800 group-hover:text-rose-600 transition-colors sm:mt-1"><?= htmlspecialchars($item['name']) ?></span>
                            <span class="text-xs font-bold text-slate-400 sm:mt-0.5"><?= number_format((int)$item['flight_count']) ?> flights</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
