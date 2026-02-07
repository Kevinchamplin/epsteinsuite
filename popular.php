<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$period = $_GET['period'] ?? '30';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 48;
$offset = ($page - 1) * $perPage;

// Validate period
$validPeriods = ['7' => '7 Days', '30' => '30 Days', '90' => '90 Days', 'all' => 'All Time'];
if (!array_key_exists($period, $validPeriods)) {
    $period = '30';
}

function sanitizeMediaInput(?string $value): ?string
{
    if ($value === null) return null;
    $cleaned = str_replace(["\r", "\n", "\t"], '', $value);
    $cleaned = trim($cleaned);
    return $cleaned === '' ? null : $cleaned;
}

function resolveLocalAbsolutePath(?string $localPath): ?string
{
    $clean = sanitizeMediaInput($localPath);
    if (!$clean) return null;
    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');
    if (file_exists($clean)) return realpath($clean) ?: $clean;
    if (strpos($clean, '/storage/') !== false) {
        $relativePath = substr($clean, strpos($clean, '/storage/') + 9);
        $candidate = $storageBase . '/' . rawurldecode($relativePath);
        if (file_exists($candidate)) return realpath($candidate) ?: $candidate;
    }
    if (strpos($clean, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($clean);
        if (file_exists($candidate)) return realpath($candidate) ?: $candidate;
    }
    $candidate = $baseDir . '/' . ltrim(rawurldecode($clean), '/');
    if (file_exists($candidate)) return realpath($candidate) ?: $candidate;
    return null;
}

function resolveServeUrlIfLocal(int $documentId, ?string $localPath): ?string
{
    return resolveLocalAbsolutePath($localPath) ? '/serve.php?id=' . $documentId : null;
}

function getGoogleDriveThumbnail(?string $url, string $size = 'w400'): string
{
    $url = sanitizeMediaInput($url);
    if (!$url) return '';
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://drive.google.com/thumbnail?id={$matches[1]}&sz={$size}";
    }
    return $url;
}

function resolveMediaUrl(?string $localPath, ?string $sourceUrl): ?string
{
    $url = sanitizeMediaInput($localPath) ?? sanitizeMediaInput($sourceUrl);
    if (!$url) return null;
    if (!preg_match('/^https?:\/\//i', $url)) {
        $segments = array_map('rawurlencode', array_filter(explode('/', ltrim($url, '/')), fn($s) => $s !== ''));
        return '/' . implode('/', $segments);
    }
    return $url;
}

try {
    $pdo = db();

    $photoWhere = "(LOWER(d.file_type) IN ('jpg','jpeg','png','gif','webp','tif','tiff')
        OR d.source_url REGEXP '\\\\.(jpg|jpeg|png|gif|webp|tif|tiff)(\\\\?|$)')";

    $dateFilter = '';
    if ($period !== 'all') {
        $dateFilter = "AND pv.created_at > DATE_SUB(NOW(), INTERVAL " . (int)$period . " DAY)";
    }

    // Total count of unique photos with views
    $countSql = "SELECT COUNT(DISTINCT pv.document_id)
                 FROM photo_views pv
                 JOIN documents d ON d.id = pv.document_id
                 WHERE $photoWhere $dateFilter";
    $totalCount = (int)$pdo->query($countSql)->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $perPage));

    // Get ranked photos
    $sql = "SELECT d.id, d.title, d.local_path, d.source_url, d.data_set, d.file_type,
                   COUNT(pv.id) AS view_count,
                   MIN(pv.created_at) AS first_viewed,
                   MAX(pv.created_at) AS last_viewed
            FROM photo_views pv
            JOIN documents d ON d.id = pv.document_id
            WHERE $photoWhere $dateFilter
            GROUP BY d.id
            ORDER BY view_count DESC, last_viewed DESC
            LIMIT $perPage OFFSET $offset";
    $photos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Overall stats
    $statsSql = "SELECT COUNT(*) AS total_views,
                        COUNT(DISTINCT document_id) AS unique_photos,
                        COUNT(DISTINCT ip_hash) AS unique_viewers
                 FROM photo_views pv
                 WHERE 1=1 $dateFilter";
    $stats = $pdo->query($statsSql)->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $totalCount = 0;
    $totalPages = 1;
    $photos = [];
    $stats = ['total_views' => 0, 'unique_photos' => 0, 'unique_viewers' => 0];
}

function buildPopularUrl(array $overrides = []): string
{
    global $period, $page;
    $params = [];
    if (($overrides['period'] ?? $period) !== '30') {
        $params['period'] = $overrides['period'] ?? $period;
    }
    $p = $overrides['page'] ?? $page;
    if ($p > 1) {
        $params['page'] = $p;
    }
    return 'popular.php' . ($params ? '?' . http_build_query($params) : '');
}

$page_title = 'Popular Photos';
$meta_description = 'Most viewed photos from the Epstein document archive, ranked by popularity. Browse the most-clicked images from DOJ releases, FBI Vault files, and public court filings.';
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
                <a href="/photos.php" class="text-slate-400 hover:text-slate-600 text-sm font-medium transition-colors">Photos</a>
                <svg class="w-4 h-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="text-sm font-medium text-slate-700">Popular</span>
            </div>
            <h1 class="text-3xl font-bold text-slate-900 tracking-tight mt-3">Popular Photos</h1>
            <p class="text-slate-500 mt-1">Most viewed photos from the Epstein document archive, ranked by clicks.</p>
        </div>

        <!-- Stats Bar -->
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-amber-700"><?= number_format((int)$stats['total_views']) ?></div>
                <div class="text-xs text-amber-600 font-medium mt-0.5">Total Views</div>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-blue-700"><?= number_format((int)$stats['unique_photos']) ?></div>
                <div class="text-xs text-blue-600 font-medium mt-0.5">Photos Viewed</div>
            </div>
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 text-center">
                <div class="text-2xl font-bold text-emerald-700"><?= number_format((int)$stats['unique_viewers']) ?></div>
                <div class="text-xs text-emerald-600 font-medium mt-0.5">Unique Viewers</div>
            </div>
        </div>

        <!-- Period Filter -->
        <div class="flex items-center gap-2 mb-6">
            <span class="text-sm text-slate-500 font-medium">Period:</span>
            <?php foreach ($validPeriods as $key => $label): ?>
                <a href="<?= buildPopularUrl(['period' => $key, 'page' => 1]) ?>"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors <?= $period === $key ? 'bg-amber-100 text-amber-800 border border-amber-300' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 border border-transparent' ?>">
                    <?= $label ?>
                </a>
            <?php endforeach; ?>
            <div class="ml-auto text-sm text-slate-500">
                <?= number_format($totalCount) ?> photo<?= $totalCount !== 1 ? 's' : '' ?>
            </div>
        </div>

        <?php if (empty($photos)): ?>
            <!-- Empty State -->
            <div class="flex flex-col items-center justify-center py-20 text-center">
                <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-slate-900 mb-1">No popular photos yet</h2>
                <p class="text-slate-500 text-sm max-w-sm">Photo popularity is tracked when users click on photos. Browse the <a href="/photos.php" class="text-blue-600 hover:underline">photo gallery</a> to get started.</p>
            </div>
        <?php else: ?>
            <!-- Photo Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4">
                <?php foreach ($photos as $rank => $photo):
                    $absoluteRank = $offset + $rank + 1;
                    $serveUrl = resolveServeUrlIfLocal((int)$photo['id'], $photo['local_path'] ?? null);
                    $mediaUrl = $serveUrl ?? resolveMediaUrl($photo['local_path'] ?? null, $photo['source_url'] ?? null);
                    if ($mediaUrl && strpos($mediaUrl, 'drive.google.com') !== false) {
                        $mediaUrl = getGoogleDriveThumbnail($mediaUrl);
                    }
                ?>
                <a href="/document.php?id=<?= (int)$photo['id'] ?>" class="group relative aspect-square bg-slate-100 rounded-xl overflow-hidden border border-slate-200 hover:border-amber-300 hover:shadow-lg transition-all">
                    <!-- Rank Badge -->
                    <div class="absolute top-2 left-2 z-10 min-w-[24px] h-6 px-1.5 bg-black/70 backdrop-blur-sm rounded-md flex items-center justify-center">
                        <span class="text-white text-xs font-bold">#<?= $absoluteRank ?></span>
                    </div>

                    <?php if ($mediaUrl): ?>
                    <img src="<?= htmlspecialchars($mediaUrl) ?>" alt="<?= htmlspecialchars($photo['title'] ?? '') ?>"
                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="w-full h-full bg-gradient-to-br from-amber-100 to-slate-100 items-center justify-center hidden">
                        <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <?php else: ?>
                    <div class="w-full h-full bg-gradient-to-br from-amber-100 to-slate-100 flex items-center justify-center">
                        <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <?php endif; ?>

                    <!-- Gradient overlay -->
                    <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/10 to-transparent pointer-events-none"></div>

                    <!-- Info overlay -->
                    <div class="absolute bottom-0 left-0 right-0 p-2.5 pointer-events-none">
                        <p class="text-white text-xs font-semibold line-clamp-2 leading-tight"><?= htmlspecialchars($photo['title'] ?? 'Untitled') ?></p>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="inline-flex items-center gap-1 text-[10px] text-white/90 font-bold bg-amber-500/80 px-1.5 py-0.5 rounded">
                                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <?= number_format((int)$photo['view_count']) ?>
                            </span>
                            <?php if (!empty($photo['data_set'])): ?>
                            <span class="text-[9px] text-white/50 truncate"><?= htmlspecialchars($photo['data_set']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-8 flex items-center justify-between border-t border-slate-200 pt-6">
                <div class="text-sm text-slate-500">
                    Page <?= $page ?> of <?= number_format($totalPages) ?> (<?= number_format($totalCount) ?> photos)
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= buildPopularUrl(['page' => 1]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm font-medium">First</a>
                        <a href="<?= buildPopularUrl(['page' => $page - 1]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm font-medium">&larr; Prev</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i === $page): ?>
                            <span class="px-3 py-1.5 bg-amber-600 text-white rounded-lg text-sm font-bold"><?= $i ?></span>
                        <?php else: ?>
                            <a href="<?= buildPopularUrl(['page' => $i]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm font-medium"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= buildPopularUrl(['page' => $page + 1]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm font-medium">Next &rarr;</a>
                        <a href="<?= buildPopularUrl(['page' => $totalPages]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm font-medium">Last</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Track clicks on this page too -->
<script>
document.addEventListener('click', function(e) {
    var link = e.target.closest('a[href*="document.php?id="]');
    if (!link) return;
    var match = link.href.match(/document\.php\?id=(\d+)/);
    if (!match) return;
    var data = JSON.stringify({document_id: parseInt(match[1], 10), referrer: 'popular'});
    if (navigator.sendBeacon) {
        navigator.sendBeacon('/api/log_photo_view.php', new Blob([data], {type: 'application/json'}));
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
