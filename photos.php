<?php
require_once __DIR__ . '/includes/db.php';

$search = $_GET['q'] ?? '';
$filter = $_GET['filter'] ?? 'photos'; // photos, videos, pdfs
$source = $_GET['source'] ?? ''; // data_set filter
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 60;
$offset = ($page - 1) * $perPage;

function sanitizeMediaInput(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    // Strip control characters that break URLs/paths and trim whitespace
    $cleaned = str_replace(["\r", "\n", "\t"], '', $value);
    $cleaned = trim($cleaned);
    return $cleaned === '' ? null : $cleaned;
}

function resolveLocalAbsolutePath(?string $localPath): ?string
{
    $clean = sanitizeMediaInput($localPath);
    if (!$clean) {
        return null;
    }

    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');

    // 1. Try raw clean path
    if (file_exists($clean)) {
        return realpath($clean) ?: $clean;
    }

    // 2. Handle /storage/ paths
    if (strpos($clean, '/storage/') !== false) {
        $relativePath = substr($clean, strpos($clean, '/storage/') + 9);
        $relativePath = rawurldecode($relativePath);
        $candidate = $storageBase . '/' . $relativePath;
        if (file_exists($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
    }

    // 3. Handle storage/ paths
    if (strpos($clean, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($clean);
        if (file_exists($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
    }

    // 4. Prepend baseDir to clean path
    $candidate = $baseDir . '/' . ltrim(rawurldecode($clean), '/');
    if (file_exists($candidate)) {
        return realpath($candidate) ?: $candidate;
    }

    return null;
}

function resolveServeUrlIfLocal(int $documentId, ?string $localPath): ?string
{
    return resolveLocalAbsolutePath($localPath) ? '/serve.php?id=' . $documentId : null;
}

// Helper to convert Google Drive URLs to embeddable thumbnail URLs
function getGoogleDriveThumbnail($url, $size = 'w400')
{
    $url = sanitizeMediaInput($url);
    if (!$url) {
        return '';
    }
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $fileId = $matches[1];
        return "https://drive.google.com/thumbnail?id={$fileId}&sz={$size}";
    }
    return $url;
}

// Resolve a usable media URL (local or external)
function resolveMediaUrl(?string $localPath, ?string $sourceUrl): ?string
{
    $url = sanitizeMediaInput($localPath) ?? sanitizeMediaInput($sourceUrl);
    if (!$url)
        return null;
    // If local relative path, encode each segment and prepend slash
    if (!preg_match('/^https?:\\/\\//i', $url)) {
        $segments = array_map('rawurlencode', array_filter(explode('/', ltrim($url, '/')), fn($s) => $s !== ''));
        return '/' . implode('/', $segments);
    }
    return $url;
}

function firstAvailablePreview(int $docId): ?string
{
    // Try first-page preview naming we generate elsewhere
    $candidates = [
        "/storage/previews/doc_{$docId}_p1.jpg",
        "/storage/previews/doc_{$docId}_page_1.jpg",
    ];
    foreach ($candidates as $c) {
        $abs = __DIR__ . $c;
        if (is_file($abs) && filesize($abs) > 0) {
            return $c;
        }
    }
    return null;
}

try {
    $pdo = db();

    // Build base WHERE clause for media types
    $photoTypes = "LOWER(file_type) IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff') OR source_url REGEXP '\\\\.(jpg|jpeg|png|gif|webp|tif|tiff)(\\\\?|$)'";
    $videoTypes = "LOWER(file_type) IN ('video', 'mp4', 'webm', 'mov') OR source_url REGEXP '\\\\.(mp4|webm|mov)(\\\\?|$)'";
    $pdfTypes = "LOWER(file_type) = 'pdf' OR source_url REGEXP '\\\\.(pdf)(\\\\?|$)'";
    $allMediaTypes = "(($photoTypes) OR ($videoTypes) OR ($pdfTypes))";

    // Normalize legacy filter values
    if (!in_array($filter, ['photos', 'videos', 'pdfs'], true)) {
        $filter = 'photos';
    }

    // Determine media type filter
    if ($filter === 'videos') {
        $mediaWhere = "($videoTypes)";
    } elseif ($filter === 'pdfs') {
        $mediaWhere = "($pdfTypes)";
    } else {
        $mediaWhere = "($photoTypes)";
    }

    // Build full query
    $params = [];
    $whereClauses = [$mediaWhere];

    if ($search) {
        $whereClauses[] = "(title LIKE :search OR description LIKE :search2 OR data_set LIKE :search3 OR ai_summary LIKE :search4)";
        $params['search'] = "%$search%";
        $params['search2'] = "%$search%";
        $params['search3'] = "%$search%";
        $params['search4'] = "%$search%";
    }

    if ($source) {
        $whereClauses[] = "data_set LIKE :source";
        $params['source'] = "%$source%";
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Get total count
    $countSql = "SELECT COUNT(*) FROM documents WHERE $whereSQL";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalCount = (int) $stmt->fetchColumn();
    $totalPages = max(1, ceil($totalCount / $perPage));

    // Get paginated results
    $sql = "SELECT id AS document_id, title, description, source_url, local_path, created_at, file_type, data_set,
                   CASE WHEN file_type IN ('video', 'mp4', 'webm', 'mov') OR source_url REGEXP '\\\\.(mp4|webm|mov)(\\\\?|$)' 
                        THEN 'video' ELSE 'image' END AS media_type
            FROM documents 
            WHERE $whereSQL
            ORDER BY created_at DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allMedia = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by Date (Month Year)
    $groupedImages = [];
    foreach ($allMedia as $img) {
        $date = date('F Y', strtotime($img['created_at']));
        if (!isset($groupedImages[$date])) {
            $groupedImages[$date] = [];
        }
        $groupedImages[$date][] = $img;
    }

    // Get counts for sidebar (filtered by source if selected)
    $sourceFilter = $source ? " AND data_set LIKE :srcCount" : "";
    $srcParams = $source ? ['srcCount' => "%$source%"] : [];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE ($photoTypes)" . $sourceFilter);
    $stmt->execute($srcParams);
    $totalImages = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE ($videoTypes)" . $sourceFilter);
    $stmt->execute($srcParams);
    $totalVideos = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE ($pdfTypes)" . $sourceFilter);
    $stmt->execute($srcParams);
    $totalPdfs = (int) $stmt->fetchColumn();

    // Get unique data sources for filter
    $stmt = $pdo->query("SELECT DISTINCT data_set FROM documents WHERE $allMediaTypes AND data_set IS NOT NULL AND data_set != '' ORDER BY data_set");
    $dataSources = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Helper to build URLs with current params
function buildPhotoUrl($overrides = [])
{
    global $search, $filter, $source, $page;
    $params = ['filter' => $filter];
    if ($search)
        $params['q'] = $search;
    if ($source)
        $params['source'] = $source;
    $params['page'] = $page;
    $params = array_merge($params, $overrides);
    if (empty($params['q']))
        unset($params['q']);
    if (empty($params['source']))
        unset($params['source']);
    if (($params['page'] ?? 1) <= 1)
        unset($params['page']);
    if (($params['filter'] ?? 'photos') === 'photos')
        unset($params['filter']);
    return 'photos.php' . ($params ? '?' . http_build_query($params) : '');
}

$page_title = 'Epstein Photos';
$meta_description = 'Browse photos, videos, and visual evidence from DOJ Epstein file releases. Searchable gallery of images extracted from seized documents and public court filings.';
$extra_head_tags = [];
$gallerySchema = [
    '@context' => 'https://schema.org',
    '@type' => 'ImageGallery',
    'name' => 'Epstein Photos & Visual Evidence',
    'description' => 'Photos, videos, and visual evidence from DOJ Epstein file releases.',
    'url' => 'https://epsteinsuite.com/photos.php',
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($gallerySchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex flex-1 overflow-hidden bg-white">
    <!-- Sidebar -->
    <aside class="w-64 flex-shrink-0 flex flex-col py-4 pr-4 border-r border-gray-200 bg-white hidden md:flex">
        <nav class="flex-1 space-y-1">
            <a href="<?= buildPhotoUrl(['filter' => 'photos', 'page' => 1]) ?>"
                class="flex items-center gap-4 px-6 py-3 rounded-r-full text-sm font-medium <?= $filter === 'photos' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                Photos (<?= number_format($totalImages) ?>)
            </a>
            <a href="<?= buildPhotoUrl(['filter' => 'videos', 'page' => 1]) ?>"
                class="flex items-center gap-4 px-6 py-3 rounded-r-full text-sm font-medium <?= $filter === 'videos' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                Videos (<?= number_format($totalVideos) ?>)
            </a>
            <a href="<?= buildPhotoUrl(['filter' => 'pdfs', 'page' => 1]) ?>"
                class="flex items-center gap-4 px-6 py-3 rounded-r-full text-sm font-medium <?= $filter === 'pdfs' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 17v-6a1 1 0 011-1h8m-4-4l4 4m0 0l-4 4m4-4H10" />
                </svg>
                PDF Evidence (<?= number_format($totalPdfs) ?>)
            </a>
        </nav>

        <?php if (!empty($dataSources)): ?>
            <div class="px-6 py-4 border-t border-gray-100">
                <div class="text-xs text-gray-500">
                    <div class="font-medium text-gray-700 mb-2">Filter by Source</div>
                    <div class="space-y-1 max-h-48 overflow-y-auto">
                        <?php if ($source): ?>
                            <a href="<?= buildPhotoUrl(['source' => '', 'page' => 1]) ?>"
                                class="block text-blue-600 hover:underline">× Clear filter</a>
                        <?php endif; ?>
                        <?php foreach (array_slice($dataSources, 0, 10) as $ds): ?>
                            <a href="<?= buildPhotoUrl(['source' => $ds, 'page' => 1]) ?>"
                                class="block truncate <?= $source === $ds ? 'text-blue-600 font-medium' : 'hover:text-blue-600' ?>">
                                <?= htmlspecialchars(strlen($ds) > 25 ? substr($ds, 0, 25) . '...' : $ds) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-4 md:p-8">
        <!-- Search Bar & Stats -->
        <div class="mb-6 flex flex-col md:flex-row md:items-center gap-4">
            <form method="GET" action="photos.php" class="flex-1 max-w-xl">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                <?php if ($source): ?><input type="hidden" name="source"
                        value="<?= htmlspecialchars($source) ?>"><?php endif; ?>
                <div class="relative">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search photos and videos..."
                        class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
            </form>
            <div class="text-sm text-gray-500">
                <?= number_format($totalCount) ?> items
                <?php if ($search): ?> matching "<?= htmlspecialchars($search) ?>"<?php endif; ?>
                <?php if ($source): ?> in <?= htmlspecialchars($source) ?><?php endif; ?>
            </div>
        </div>

        <div
            class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 flex items-start gap-3">
            <span class="text-lg leading-none">ℹ️</span>
            <p class="leading-relaxed">
                Many DOJ “photo” releases are packaged inside multi-hundred page PDFs, so those images still live inside
                the Drive view of each document. What you see here is everything we can extract directly—open the source
                PDF for embedded galleries exactly as the DOJ provided them.
            </p>
        </div>

        <?php if ($search || $source): ?>
            <div class="mb-4 flex items-center gap-2 flex-wrap">
                <?php if ($search): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-sm">
                        Search: <?= htmlspecialchars($search) ?>
                        <a href="<?= buildPhotoUrl(['q' => '', 'page' => 1]) ?>" class="hover:text-blue-900">×</a>
                    </span>
                <?php endif; ?>
                <?php if ($source): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-green-50 text-green-700 rounded-full text-sm">
                        Source: <?= htmlspecialchars($source) ?>
                        <a href="<?= buildPhotoUrl(['source' => '', 'page' => 1]) ?>" class="hover:text-green-900">×</a>
                    </span>
                <?php endif; ?>
                <a href="photos.php" class="text-sm text-gray-500 hover:text-gray-700">Clear all</a>
            </div>
        <?php endif; ?>

        <?php if (empty($groupedImages)): ?>
            <div class="flex flex-col items-center justify-center h-full text-center">
                <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                    <svg class="w-12 h-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <h2 class="text-xl font-medium text-gray-900 mb-2">No photos found</h2>
                <p class="text-gray-500 max-w-sm">
                    Images extracted from the documents will appear here.
                </p>
                <div class="mt-6">
                    <a href="/drive.php"
                        class="inline-flex items-center bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:border-blue-300 hover:shadow-sm transition-all text-sm font-medium">Browse
                        documents in Drive</a>
                </div>
            </div>
        <?php else: ?>
            <div class="space-y-8">
                <?php foreach ($groupedImages as $date => $group): ?>
                    <div>
                        <h3 class="text-sm font-medium text-gray-500 mb-4 sticky top-0 bg-white/90 backdrop-blur py-2 z-10">
                            <?= $date ?>
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                            <?php foreach ($group as $img):
                                $serveUrl = resolveServeUrlIfLocal((int) $img['document_id'], $img['local_path'] ?? null);
                                $rawMediaUrl = $serveUrl ?? resolveMediaUrl($img['local_path'] ?? null, $img['source_url'] ?? null);
                                $mediaUrl = $rawMediaUrl;
                                $usesPdfPlaceholder = false;
                                // Convert Google Drive URLs to thumbnail URLs
                                if ($mediaUrl && strpos($mediaUrl, 'drive.google.com') !== false) {
                                    $mediaUrl = getGoogleDriveThumbnail($mediaUrl);
                                }
                                $isVideo = ($img['media_type'] ?? '') === 'video';
                                $hasLocalVideo = $isVideo && (bool) $serveUrl;
                                $videoUrl = $serveUrl ?? resolveMediaUrl($img['local_path'] ?? null, $img['source_url'] ?? null);

                                // For local videos, use our thumbnail generator
                                $videoThumbUrl = $hasLocalVideo ? '/video_thumb.php?id=' . (int) $img['document_id'] : null;

                                // PDF fallback: use preview image if exists
                                $isPdf = strtolower((string) $img['file_type']) === 'pdf' || ($rawMediaUrl && preg_match('/\\.pdf(\\?|$)/i', $rawMediaUrl));
                                if ($isPdf) {
                                    $preview = firstAvailablePreview((int) $img['document_id']);
                                    if ($preview) {
                                        $mediaUrl = $preview;
                                    } else {
                                        $mediaUrl = null;
                                        $usesPdfPlaceholder = true;
                                    }
                                }
                                ?>
                                <a href="/document.php?id=<?= (int) $img['document_id'] ?>"
                                    class="group relative aspect-square bg-gray-100 rounded-xl overflow-hidden block shadow-sm hover:shadow-md transition-shadow">
                                    <?php if ($isVideo): ?>
                                        <!-- Video Thumbnail -->
                                        <div class="w-full h-full bg-gradient-to-br from-slate-800 to-slate-900 relative">
                                            <?php if ($hasLocalVideo): ?>
                                                <!-- Try to load generated thumbnail -->
                                                <img src="<?= htmlspecialchars($videoThumbUrl) ?>"
                                                    alt="<?= htmlspecialchars($img['title']) ?>" class="w-full h-full object-cover"
                                                    loading="lazy" onerror="this.style.display='none';">
                                            <?php else: ?>
                                                <!-- Fallback: Use video element to capture frame -->
                                                <video class="w-full h-full object-cover" preload="metadata" muted
                                                    onerror="this.style.display='none';">
                                                    <source src="<?= htmlspecialchars($videoUrl) ?>#t=1" type="video/mp4">
                                                </video>
                                            <?php endif; ?>

                                            <!-- Single Play button overlay -->
                                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <div
                                                    class="w-12 h-12 bg-white/90 rounded-full flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                                                    <svg class="w-5 h-5 text-slate-800 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                                        <path d="M8 5v14l11-7z" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </div>
                                    <?php elseif ($usesPdfPlaceholder): ?>
                                        <div
                                            class="w-full h-full bg-gradient-to-br from-red-600 to-rose-700 text-white flex flex-col items-center justify-center p-4 text-center space-y-2">
                                            <div class="w-12 h-12 bg-white/15 rounded-xl flex items-center justify-center">
                                                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M7 7h10M7 12h10M7 17h6" />
                                                </svg>
                                            </div>
                                            <div class="text-xs font-semibold tracking-widest uppercase">PDF</div>
                                            <div class="text-[11px] leading-tight opacity-80">Open to view full gallery</div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Image -->
                                        <img src="<?= htmlspecialchars($mediaUrl ?? '') ?>" alt="<?= htmlspecialchars($img['title']) ?>"
                                            class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                            loading="lazy"
                                            onerror="this.parentElement.innerHTML='<div class=\'w-full h-full bg-gray-200 flex items-center justify-center\'><svg class=\'w-8 h-8 text-gray-400\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z\' /></svg></div>'">
                                    <?php endif; ?>

                                    <!-- Hover overlay -->
                                    <div
                                        class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors pointer-events-none">
                                    </div>

                                    <!-- Title bar at bottom (always visible) -->
                                    <div
                                        class="absolute bottom-0 left-0 right-0 p-2 bg-gradient-to-t from-black/80 via-black/50 to-transparent pointer-events-none">
                                        <p class="text-white text-[11px] truncate font-medium leading-tight">
                                            <?= htmlspecialchars($img['title']) ?>
                                        </p>
                                        <?php if (!empty($img['data_set'])): ?>
                                            <p class="text-white/60 text-[9px] truncate mt-0.5">
                                                <?= htmlspecialchars($img['data_set']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($isVideo): ?>
                                        <!-- Video Badge -->
                                        <div
                                            class="absolute top-2 left-2 px-2 py-0.5 bg-black/60 backdrop-blur-sm rounded text-white text-[10px] font-bold flex items-center gap-1">
                                            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z" />
                                            </svg>
                                            VIDEO
                                        </div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="mt-8 flex items-center justify-between border-t border-gray-200 pt-6">
                    <div class="text-sm text-gray-500">
                        Page <?= $page ?> of <?= number_format($totalPages) ?> (<?= number_format($totalCount) ?> items)
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildPhotoUrl(['page' => 1]) ?>"
                                class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">First</a>
                            <a href="<?= buildPhotoUrl(['page' => $page - 1]) ?>"
                                class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">← Prev</a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                            <?php if ($i === $page): ?>
                                <span class="px-3 py-1 bg-blue-600 text-white rounded text-sm font-medium"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= buildPhotoUrl(['page' => $i]) ?>"
                                    class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildPhotoUrl(['page' => $page + 1]) ?>"
                                class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">Next →</a>
                            <a href="<?= buildPhotoUrl(['page' => $totalPages]) ?>"
                                class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<script>
document.addEventListener('click', function(e) {
    var link = e.target.closest('a[href*="document.php?id="]');
    if (!link) return;
    var match = link.href.match(/document\.php\?id=(\d+)/);
    if (!match) return;
    var data = JSON.stringify({document_id: parseInt(match[1], 10), referrer: 'photos'});
    if (navigator.sendBeacon) {
        navigator.sendBeacon('/api/log_photo_view.php', new Blob([data], {type: 'application/json'}));
    }
});
</script>

</body>

</html>