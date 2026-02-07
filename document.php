<?php
require_once __DIR__ . '/includes/db.php';

/** @var PDO|null $pdo */
$pdo = null;
$reportSuccess = isset($_GET['reported']);
$reportError = null;

function sendBrokenFileNotification(PDO $pdo, int $documentId, ?string $reason = null): void
{
    try {
        $stmt = $pdo->prepare("SELECT title, source_url, local_path FROM documents WHERE id = :id");
        $stmt->execute(['id' => $documentId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $title = $doc['title'] ?? '(Unknown title)';
        $sourceUrl = $doc['source_url'] ?? '';
        $localPath = $doc['local_path'] ?? '';
        $adminEmail = env_value('ADMIN_EMAIL') ?: 'info@epsteinsuite.com';
        $siteUrl = sprintf('%s://%s', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'epsteinsuite.com');
        $documentUrl = $siteUrl . '/document.php?id=' . $documentId;

        $bodyLines = [
            "A user reported a broken document file.",
            "",
            "Document: {$title}",
            "Document ID: {$documentId}",
            "Document URL: {$documentUrl}",
            "Reason: " . ($reason !== '' ? $reason : '(not provided)'),
            "Source URL: {$sourceUrl}",
            "Local Path: {$localPath}",
            "Reporter IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            "User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            "Reported At: " . date('c'),
        ];

        $subject = "[Epstein Files] Broken document report #{$documentId}";
        $headers = [
            'From: Epstein Files <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'epsteinsuite.com') . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        @mail($adminEmail, $subject, implode("\n", $bodyLines), implode("\r\n", $headers));
    } catch (Exception $e) {
        // Swallow email failures silently to avoid blocking the report flow
    }
}

function ensureReportTable(PDO $pdo): void
{
    static $reportTableReady = false;
    if ($reportTableReady) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_file_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            reporter_ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (document_id),
            CONSTRAINT fk_report_doc FOREIGN KEY (document_id)
                REFERENCES documents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $reportTableReady = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_broken'])) {
    $docId = (int) ($_POST['document_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($docId > 0) {
        try {
            $pdo = db();
            ensureReportTable($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO document_file_reports (document_id, reason, reporter_ip, user_agent)
                VALUES (:document_id, :reason, :ip, :ua)
            ");
            $stmt->execute([
                'document_id' => $docId,
                'reason' => $reason ?: null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            ]);
            sendBrokenFileNotification($pdo, $docId, $reason);

            header('Location: /document.php?id=' . $docId . '&reported=1');
            exit;
        } catch (Exception $e) {
            $reportError = 'Could not submit the report. Please try again later.';
        }
    } else {
        $reportError = 'Invalid document reference.';
    }
}

$id = $_GET['id'] ?? 0;
$doc = null;
$entities = [];
$ocrPages = [];
$ocrTotalPages = 0;
$email = null;
$relatedDocs = [];
$relatedByEntity = [];
$ocrPage = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$ocrLimit = 5;
$ocrOffset = ($ocrPage - 1) * $ocrLimit;

try {
    if (!$pdo) {
        $pdo = db();
    }
    ensureReportTable($pdo);

    // Fetch Document with AI summary
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $doc = $stmt->fetch();

    if ($doc) {
        $stmt = $pdo->prepare("SELECT * FROM emails WHERE document_id = :id LIMIT 1");
        $stmt->execute(['id' => (int) $id]);
        $email = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Fetch Entities via document_entities join (current schema)
        $stmt = $pdo->prepare("
            SELECT e.id AS entity_id, e.name AS entity_name,
                   e.type AS entity_type,
                   de.frequency AS mention_count
            FROM document_entities de
            JOIN entities e ON e.id = de.entity_id
            WHERE de.document_id = :doc_id
            ORDER BY de.frequency DESC
            LIMIT 20
        ");
        $stmt->execute(['doc_id' => (int) $id]);
        $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch related documents by shared entities
        if (!empty($entities)) {
            $entityIds = array_column($entities, 'entity_id');
            $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT d.id, d.title, d.file_type, d.data_set,
                       COUNT(de.entity_id) as shared_entities
                FROM documents d
                JOIN document_entities de ON de.document_id = d.id
                WHERE de.entity_id IN ($placeholders)
                  AND d.id != ?
                GROUP BY d.id
                ORDER BY shared_entities DESC
                LIMIT 10
            ");
            $params = array_merge($entityIds, [(int) $id]);
            $stmt->execute($params);
            $relatedByEntity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fetch related documents by same data_set
        if (!empty($doc['data_set'])) {
            $stmt = $pdo->prepare("
                SELECT id, title, file_type, created_at
                FROM documents
                WHERE data_set = :ds AND id != :id
                ORDER BY created_at DESC
                LIMIT 6
            ");
            $stmt->execute(['ds' => $doc['data_set'], 'id' => (int) $id]);
            $relatedDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE document_id = :id AND ocr_text IS NOT NULL AND ocr_text != ''");
        $stmt->execute(['id' => $id]);
        $ocrTotalPages = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT page_number, ocr_text FROM pages WHERE document_id = :id AND ocr_text IS NOT NULL AND ocr_text != '' ORDER BY page_number ASC LIMIT :lim OFFSET :off");
        $stmt->bindValue(':id', (int) $id, PDO::PARAM_INT);
        $stmt->bindValue(':lim', (int) $ocrLimit, PDO::PARAM_INT);
        $stmt->bindValue(':off', (int) $ocrOffset, PDO::PARAM_INT);
        $stmt->execute();
        $ocrPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Log document view for trending/leaderboard
        try {
            require_once __DIR__ . '/includes/ai_helpers.php';
            $viewIpHash = ai_hash_ip($_SERVER['REMOTE_ADDR'] ?? '');
            $viewDedup = $pdo->prepare("
                SELECT 1 FROM document_views
                WHERE ip_hash = :ip_hash
                  AND document_id = :document_id
                  AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                LIMIT 1
            ");
            $viewDedup->execute([
                ':ip_hash' => $viewIpHash,
                ':document_id' => (int)$id,
            ]);
            if (!$viewDedup->fetchColumn()) {
                $viewInsert = $pdo->prepare("
                    INSERT INTO document_views (document_id, ip_hash, user_agent, referrer)
                    VALUES (:document_id, :ip_hash, :user_agent, :referrer)
                ");
                $viewInsert->execute([
                    ':document_id' => (int)$id,
                    ':ip_hash' => $viewIpHash,
                    ':user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ':referrer' => mb_substr($_SERVER['HTTP_REFERER'] ?? 'direct', 0, 50),
                ]);
            }
        } catch (\Exception $e) {
            // Silent fail -- view logging should never break document rendering
        }
    }

} catch (Exception $e) {
    // Silent fail or log
}

if (!$doc) {
    header("HTTP/1.0 404 Not Found");
    echo "Document not found.";
    exit;
}

$sourceUrl = (string) ($doc['source_url'] ?? '');
$sourceHost = (string) (parse_url($sourceUrl, PHP_URL_HOST) ?? '');
// Hide source URLs from certain internal domains
$hideSourceUrl = false;

$governmentDomains = ['justice.gov', 'vault.fbi.gov', 'oversight.house.gov', 'fbi.gov'];
$isVerifiedGovSource = false;
if ($sourceHost) {
    foreach ($governmentDomains as $govDomain) {
        if ($sourceHost === $govDomain || str_ends_with($sourceHost, '.' . $govDomain)) {
            $isVerifiedGovSource = true;
            break;
        }
    }
}

$localPath = (string) ($doc['local_path'] ?? '');
$localPublicUrl = null;
$hasLocalFile = false;
$localAbsolutePath = null;

if ($localPath) {
    $baseDir = __DIR__;
    $storageBase = realpath($baseDir . '/storage');
    $resolvedPath = null;

    // 1. Try raw localPath
    if (file_exists($localPath)) {
        $resolvedPath = realpath($localPath);
    }

    // 2. Handle /storage/ paths
    if (!$resolvedPath && strpos($localPath, '/storage/') !== false) {
        $relativePath = substr($localPath, strpos($localPath, '/storage/') + 9);
        $relativePath = rawurldecode($relativePath);
        $candidate = $storageBase . '/' . $relativePath;
        if (file_exists($candidate)) {
            $resolvedPath = realpath($candidate);
        }
    }

    // 3. Handle storage/ paths
    if (!$resolvedPath && strpos($localPath, 'storage/') === 0) {
        $candidate = $baseDir . '/' . rawurldecode($localPath);
        if (file_exists($candidate)) {
            $resolvedPath = realpath($candidate);
        }
    }

    // 4. Final attempt: re-anchor to baseDir
    if (!$resolvedPath) {
        $candidate = $baseDir . '/' . ltrim(rawurldecode($localPath), '/');
        if (file_exists($candidate)) {
            $resolvedPath = realpath($candidate);
        }
    }

    if ($resolvedPath && str_starts_with($resolvedPath, $storageBase)) {
        $hasLocalFile = true;
        $localAbsolutePath = $resolvedPath;
        $localPublicUrl = '/serve.php?id=' . (int) $id;
    }
}

$fileType = strtolower((string) ($doc['file_type'] ?? ''));
$localMime = null;
if ($hasLocalFile && $localAbsolutePath) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $localMime = @finfo_file($finfo, $localAbsolutePath) ?: null;
        finfo_close($finfo);
    }
}

// Type detection - prioritize file_type from database, then check URLs
$isPdf = ($fileType === 'pdf') || preg_match('/\.pdf(\?|$)/i', $sourceUrl) || preg_match('/\.pdf(\?|$)/i', $localPath);
$isEmail = ($fileType === 'email') || !empty($email);

$isImage = in_array($fileType, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff'], true)
    || preg_match('/\.(jpg|jpeg|png|gif|webp|tif|tiff)(\?|$)/i', $sourceUrl)
    || preg_match('/\.(jpg|jpeg|png|gif|webp|tif|tiff)(\?|$)/i', $localPath);

$isVideo = in_array($fileType, ['video', 'mp4', 'webm', 'mov'], true)
    || preg_match('/\.(mp4|webm|mov)(\?|$)/i', $sourceUrl)
    || preg_match('/\.(mp4|webm|mov)(\?|$)/i', $localPath);

$isAudio = in_array($fileType, ['audio', 'wav', 'mp3', 'ogg', 'm4a'], true)
    || preg_match('/\.(wav|mp3|ogg|m4a)(\?|$)/i', $sourceUrl)
    || preg_match('/\.(wav|mp3|ogg|m4a)(\?|$)/i', $localPath);

if ($localMime) {
    if (str_starts_with($localMime, 'application/pdf')) {
        $isPdf = true;
        $isImage = false;
    } elseif (str_starts_with($localMime, 'image/')) {
        $isImage = true;
        $isPdf = false;
    } elseif (str_starts_with($localMime, 'video/')) {
        $isVideo = true;
        $isPdf = false;
    } elseif (str_starts_with($localMime, 'audio/')) {
        $isAudio = true;
        $isPdf = false;
    }
}

// Helper to convert Google Drive URLs to embeddable/thumbnail URLs
function getGoogleDriveDirectUrl($url, $forThumbnail = false)
{
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $fileId = $matches[1];
        // Use thumbnail API which is more reliable for embedding (bypasses hotlink protection)
        // sz=w2000 gives high quality, sz=w400 for thumbnails
        $size = $forThumbnail ? 'w400' : 'w2000';
        return "https://drive.google.com/thumbnail?id={$fileId}&sz={$size}";
    }
    return $url;
}

// Convert URLs for embedding
$embedSourceUrl = $localPublicUrl ?: $sourceUrl;
if (strpos($embedSourceUrl, 'drive.google.com') !== false) {
    $embedSourceUrl = getGoogleDriveDirectUrl($embedSourceUrl);
}

// Fallback for TIF/TIFF rendering (most browsers don't support it)
$tifPreviewUrl = null;
if (preg_match('/\.(tif|tiff)(\?|$)/i', $localPath ?? '') || preg_match('/\.(tif|tiff)(\?|$)/i', $sourceUrl ?? '')) {
    // Check if we have an OCR image preview we can use instead
    $stmt = $pdo->prepare("SELECT image_path FROM pages WHERE document_id = ? AND image_path IS NOT NULL AND image_path != '' ORDER BY page_number ASC LIMIT 1");
    $stmt->execute([(int) $id]);
    $page = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($page && !empty($page['image_path'])) {
        $tifPreviewUrl = $page['image_path'];
    }
}

$pdfEmbedUrl = $isPdf ? ($localPublicUrl ?: $sourceUrl) : null;
$imageEmbedUrl = $isImage ? ($tifPreviewUrl ?: ($localPublicUrl ?: getGoogleDriveDirectUrl($sourceUrl))) : null;
$videoEmbedUrl = $isVideo ? ($localPublicUrl ?: $sourceUrl) : null;
$audioEmbedUrl = $isAudio ? ($localPublicUrl ?: $sourceUrl) : null;
$safeDescription = (string) ($doc['description'] ?? '');
if ($safeDescription && preg_match('/^\s*Imported\b/i', $safeDescription)) {
    $safeDescription = '';
}

$page_title = $doc['title'] . ' | Epstein Files';
$canonical_url = 'https://epsteinsuite.com/document.php?id=' . (int) $id;
$extra_head_tags = [];

if ($doc) {
    $docDescription = $safeDescription ?: ($doc['ai_summary'] ?? '');
    $docDescription = trim($docDescription);
    if (!$docDescription && !empty($email['body'])) {
        $docDescription = mb_substr(strip_tags($email['body']), 0, 300);
    }
    $docDescription = $docDescription ?: 'Public-source document from the DOJ Epstein files.';

    $meta_description = mb_substr(strip_tags($docDescription), 0, 160);
    $og_title = $doc['title'] . ' | Epstein Files';
    $og_description = mb_substr(strip_tags($docDescription), 0, 200);

    $documentJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'CreativeWork',
        'name' => $doc['title'],
        'description' => $docDescription,
        'datePublished' => $doc['created_at'] ?? null,
        'url' => $canonical_url,
        'isBasedOn' => $sourceUrl ?: null,
        'fileFormat' => strtoupper($doc['file_type'] ?? 'document'),
        'keywords' => array_filter(array_map('trim', explode(',', (string) $doc['data_set']))),
    ];

    if (!empty($entities)) {
        $documentJsonLd['mentions'] = array_map(function ($entity) {
            return [
                '@type' => match (strtoupper($entity['entity_type'] ?? '')) {
                    'PERSON' => 'Person',
                    'ORG', 'ORGANIZATION' => 'Organization',
                    'LOCATION' => 'Place',
                    default => 'Thing',
                },
                'name' => $entity['entity_name'],
            ];
        }, $entities);
    }

    $documentJsonLd = array_filter($documentJsonLd, fn($value) => $value !== null && $value !== '');
    $extra_head_tags[] = '<script type="application/ld+json">' . json_encode($documentJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Search', 'item' => 'https://epsteinsuite.com/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Drive', 'item' => 'https://epsteinsuite.com/drive.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $doc['title']],
        ]
    ];
    $extra_head_tags[] = '<script type="application/ld+json">' . json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex flex-1 overflow-hidden bg-slate-50">
    <!-- Document Sidebar (Info & Entities) -->
    <aside class="w-80 flex-shrink-0 flex flex-col border-r border-gray-200 bg-white overflow-y-auto hidden lg:flex">
        <!-- Document Header with Gradient -->
        <?php
        // File type styling
        $ftLower = strtolower($doc['file_type'] ?? 'doc');
        $fileTypeConfig = match ($ftLower) {
            'pdf' => ['icon' => '📕', 'gradient' => 'from-red-500 to-rose-600', 'bg' => 'bg-red-50', 'text' => 'text-red-700'],
            'jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff' => ['icon' => '🖼️', 'gradient' => 'from-emerald-500 to-teal-600', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-700'],
            'mp4', 'mov', 'video' => ['icon' => '🎬', 'gradient' => 'from-purple-500 to-violet-600', 'bg' => 'bg-purple-50', 'text' => 'text-purple-700'],
            'mp3', 'wav', 'audio' => ['icon' => '🎵', 'gradient' => 'from-amber-500 to-orange-600', 'bg' => 'bg-amber-50', 'text' => 'text-amber-700'],
            'email' => ['icon' => '✉️', 'gradient' => 'from-blue-500 to-indigo-600', 'bg' => 'bg-blue-50', 'text' => 'text-blue-700'],
            'doc', 'docx', 'txt' => ['icon' => '📝', 'gradient' => 'from-sky-500 to-blue-600', 'bg' => 'bg-sky-50', 'text' => 'text-sky-700'],
            'xls', 'xlsx' => ['icon' => '📊', 'gradient' => 'from-green-500 to-emerald-600', 'bg' => 'bg-green-50', 'text' => 'text-green-700'],
            default => ['icon' => '📄', 'gradient' => 'from-slate-500 to-gray-600', 'bg' => 'bg-slate-50', 'text' => 'text-slate-700'],
        };
        ?>
        <div class="bg-gradient-to-br <?= $fileTypeConfig['gradient'] ?> p-6 text-white space-y-4">
            <?php if ($reportSuccess): ?>
                <div
                    class="bg-white/15 border border-white/20 text-white px-4 py-3 rounded-xl text-sm flex items-center gap-2 shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Thanks for letting us know. We'll investigate the broken file shortly.
                </div>
            <?php elseif ($reportError): ?>
                <div class="bg-red-600/70 border border-red-400 text-white px-4 py-3 rounded-xl text-sm shadow-sm">
                    <?= htmlspecialchars($reportError) ?>
                </div>
            <?php endif; ?>

            <div class="flex items-start gap-4">
                <div
                    class="w-14 h-14 bg-white/20 backdrop-blur rounded-xl flex items-center justify-center text-3xl shadow-lg">
                    <?= $fileTypeConfig['icon'] ?>
                </div>
                <div class="flex-1 min-w-0">
                    <span
                        class="inline-block px-2 py-0.5 bg-white/20 backdrop-blur text-white text-xs font-bold uppercase tracking-wider rounded mb-2">
                        <?= htmlspecialchars(strtoupper($doc['file_type'] ?? 'DOC')) ?>
                    </span>
                    <h1 class="text-lg font-bold leading-tight break-words">
                        <?= htmlspecialchars($doc['title']) ?>
                    </h1>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-4 text-sm text-white/80">
                <div class="flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <?= date('M j, Y', strtotime($doc['created_at'])) ?>
                </div>
                <?php if ($hasLocalFile): ?>
                    <div class="flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Local Copy
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Error Notice for unavailable source files -->
        <?php if ($doc['status'] === 'error' && !$hasLocalFile): ?>
            <div class="p-4 border-b border-gray-100">
                <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div>
                            <h3 class="text-sm font-bold text-red-800 mb-1">Source file unavailable</h3>
                            <p class="text-xs text-red-700 leading-relaxed">This document could not be downloaded from the original government source. The file may have been moved or removed by the DOJ.</p>
                            <?php if (!$hideSourceUrl && !empty($sourceUrl) && str_starts_with($sourceUrl, 'http')): ?>
                                <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank" class="inline-flex items-center gap-1 mt-2 text-xs font-bold text-red-700 hover:text-red-900 underline">
                                    Try original source link
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="p-4 border-b border-gray-100 space-y-2">
            <?php if ($hasLocalFile): ?>
                <a href="/serve.php?id=<?= (int) $id ?>" target="_blank"
                    class="flex items-center justify-center w-full px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-sm font-medium rounded-xl hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg">
                    <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    View File
                </a>
                <div class="grid grid-cols-2 gap-2">
                    <a href="/serve.php?id=<?= (int) $id ?>&download=1"
                        class="flex items-center justify-center px-3 py-2 border border-slate-200 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">
                        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download
                    </a>
                    <?php if (!$hideSourceUrl && !empty($sourceUrl)): ?>
                        <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank"
                            class="flex items-center justify-center px-3 py-2 border border-slate-200 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-50 transition-colors">
                            <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            Source
                        </a>
                    <?php endif; ?>
                </div>
            <?php elseif (!$hideSourceUrl && !empty($sourceUrl)): ?>
                <div class="space-y-3">
                    <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank"
                        class="flex items-center justify-center w-full px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-sm font-medium rounded-xl hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg">
                        <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Download from Source
                    </a>
                    <button type="button" data-manual-download data-doc-id="<?= (int) $id ?>"
                        class="flex items-center justify-center w-full px-4 py-3 border border-dashed border-slate-300 text-slate-700 text-sm font-medium rounded-xl hover:border-blue-300 hover:text-blue-700 transition-colors">
                        <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 7h18M3 12h18M3 17h18" />
                        </svg>
                        Create local copy here
                    </button>
                    <p class="text-[11px] text-slate-500 text-center" data-manual-download-status>
                        We’ll try grabbing the file now so previews/OCR can run.
                    </p>
                </div>
            <?php endif; ?>

            <form method="POST" class="pt-2">
                <input type="hidden" name="document_id" value="<?= (int) $id ?>">
                <button type="submit" name="report_broken"
                    class="w-full flex items-center justify-center gap-2 text-xs font-medium text-slate-500 border border-dashed border-slate-200 rounded-lg px-3 py-2 hover:border-red-300 hover:text-red-600 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 8v4m0 4h.01M5.455 19h13.09a1 1 0 00.894-1.447L13.894 5.553a1 1 0 00-1.788 0L4.561 17.553A1 1 0 005.455 19z" />
                    </svg>
                    Report broken file
                </button>
                <p class="text-[10px] text-slate-400 mt-1 text-center">If the file fails to load, let us know.</p>
            </form>
        </div>

        <div class="p-4">
            <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Mentioned Entities
            </h2>
            <?php if (empty($entities)): ?>
                <div class="text-center py-6">
                    <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="text-sm text-slate-500">Processing...</p>
                    <p class="text-xs text-slate-400 mt-1">Entities will appear after AI analysis</p>
                </div>
            <?php else: ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($entities as $entity): ?>
                        <?php
                        $type = strtoupper($entity['entity_type'] ?? '');
                        $config = match ($type) {
                            'PERSON' => ['bg' => 'bg-gradient-to-r from-purple-500 to-violet-500', 'icon' => '👤'],
                            'ORG', 'ORGANIZATION' => ['bg' => 'bg-gradient-to-r from-blue-500 to-indigo-500', 'icon' => '🏢'],
                            'LOCATION' => ['bg' => 'bg-gradient-to-r from-emerald-500 to-teal-500', 'icon' => '📍'],
                            'DATE' => ['bg' => 'bg-gradient-to-r from-amber-500 to-orange-500', 'icon' => '�'],
                            default => ['bg' => 'bg-gradient-to-r from-slate-500 to-gray-500', 'icon' => '📌'],
                        };
                        ?>
                        <a href="/entity.php?id=<?= (int) $entity['entity_id'] ?>"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 <?= $config['bg'] ?> text-white text-xs font-medium rounded-full hover:opacity-90 transition-opacity shadow-sm">
                            <span><?= $config['icon'] ?></span>
                            <span class="max-w-[120px] truncate"><?= htmlspecialchars($entity['entity_name']) ?></span>
                            <?php if ($entity['mention_count'] > 1): ?>
                                <span
                                    class="bg-white/20 px-1.5 py-0.5 rounded-full text-[10px]"><?= $entity['mention_count'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Helper function for file type icons
        function getFileIcon($type)
        {
            return match (strtolower($type ?? 'doc')) {
                'pdf' => '📕',
                'jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff' => '🖼️',
                'mp4', 'mov', 'video' => '🎬',
                'mp3', 'wav', 'audio' => '🎵',
                'email' => '✉️',
                'doc', 'docx', 'txt' => '📝',
                'xls', 'xlsx' => '📊',
                default => '📄',
            };
        }
        ?>

        <?php if (!empty($relatedByEntity)): ?>
            <div class="p-4 border-t border-gray-100">
                <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    Related by Entity
                </h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($relatedByEntity, 0, 5) as $rel): ?>
                        <a href="/document.php?id=<?= (int) $rel['id'] ?>"
                            class="flex items-start gap-3 p-2 rounded-lg hover:bg-slate-50 transition-colors group">
                            <div
                                class="w-8 h-8 bg-slate-100 group-hover:bg-slate-200 rounded-lg flex items-center justify-center text-sm flex-shrink-0 transition-colors">
                                <?= getFileIcon($rel['file_type']) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div
                                    class="text-xs font-medium text-slate-800 line-clamp-2 group-hover:text-blue-600 transition-colors">
                                    <?= htmlspecialchars($rel['title']) ?>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-0.5 flex items-center gap-1">
                                    <span class="font-medium"><?= strtoupper($rel['file_type'] ?? 'DOC') ?></span>
                                    <span>•</span>
                                    <span><?= $rel['shared_entities'] ?> shared</span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($relatedDocs)): ?>
            <div class="p-4 border-t border-gray-100">
                <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    Same Collection
                </h2>
                <div class="space-y-2">
                    <?php foreach (array_slice($relatedDocs, 0, 5) as $rel): ?>
                        <a href="/document.php?id=<?= (int) $rel['id'] ?>"
                            class="flex items-start gap-3 p-2 rounded-lg hover:bg-slate-50 transition-colors group">
                            <div
                                class="w-8 h-8 bg-slate-100 group-hover:bg-slate-200 rounded-lg flex items-center justify-center text-sm flex-shrink-0 transition-colors">
                                <?= getFileIcon($rel['file_type']) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div
                                    class="text-xs font-medium text-slate-800 line-clamp-2 group-hover:text-blue-600 transition-colors">
                                    <?= htmlspecialchars($rel['title']) ?>
                                </div>
                                <div class="text-[10px] text-slate-400 mt-0.5 font-medium">
                                    <?= strtoupper($rel['file_type'] ?? 'DOC') ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </aside>

    <!-- Main Content (Preview & Summary) -->
    <main class="flex-1 flex flex-col overflow-hidden relative">
        <!-- Toolbar -->
        <div class="h-14 border-b border-gray-200 bg-white flex items-center justify-between px-6 flex-shrink-0">
            <div class="flex items-center gap-4 text-sm text-slate-500">
                <a href="/" class="hover:text-blue-600 flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Search
                </a>
            </div>
            <div class="flex items-center gap-2">
                <a href="https://twitter.com/intent/tweet?text=Found%20interesting%20document%20in%20Epstein%20Files:%20<?= urlencode($doc['title']) ?>&url=<?= urlencode("http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>"
                    target="_blank" class="text-slate-400 hover:text-blue-400 p-2 rounded-full hover:bg-slate-100">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
                    </svg>
                </a>
                <button onclick="navigator.clipboard.writeText(window.location.href); alert('Link copied!');"
                    class="text-slate-400 hover:text-gray-600 p-2 rounded-full hover:bg-slate-100">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-8">
            <div
                class="max-w-4xl mx-auto bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden min-h-[600px]">
                <div class="p-5 md:p-8 border-b border-gray-100 bg-gradient-to-br from-purple-50 to-blue-50">
                    <div class="flex items-start gap-3 mb-4">
                        <div
                            class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center text-white text-xl flex-shrink-0">
                            ✨
                        </div>
                        <div class="flex-1">
                            <h2 class="text-lg font-bold text-slate-900 mb-1">AI Generated Summary</h2>
                            <p class="text-sm text-slate-600">Powered by gpt-5-nano analysis of OCR text content</p>
                        </div>
                        <?php if (!empty($doc['ai_summary'])): ?>
                            <span class="px-3 py-1 bg-green-100 text-green-700 text-xs font-medium rounded-full">✓ Summary Ready</span>
                        <?php elseif ($doc['status'] === 'error'): ?>
                            <span class="px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded-full">Source Unavailable</span>
                        <?php elseif ($doc['status'] === 'processed' || $doc['status'] === 'downloaded'): ?>
                            <span class="px-3 py-1 bg-amber-100 text-amber-700 text-xs font-medium rounded-full">⏳ Awaiting AI</span>
                        <?php else: ?>
                            <span class="px-3 py-1 bg-slate-100 text-slate-500 text-xs font-medium rounded-full">Pending</span>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg p-6 shadow-sm border border-gray-200">
                        <?php if (!empty($doc['ai_summary'])): ?>
                            <div class="prose prose-slate max-w-none text-slate-700 leading-relaxed">
                                <p><?= nl2br(htmlspecialchars($doc['ai_summary'])) ?></p>
                            </div>
                        <?php elseif ($isEmail && !empty($email)): ?>
                            <div class="text-sm text-slate-700">
                                <div class="font-semibold text-slate-900 mb-1">
                                    <?= htmlspecialchars($email['subject'] ?? '(No Subject)') ?>
                                </div>
                                <div class="text-slate-600">
                                    From: <?= htmlspecialchars($email['sender'] ?? 'Unknown') ?>
                                </div>
                                <?php if (!empty($email['recipient'])): ?>
                                    <div class="text-slate-600">To: <?= htmlspecialchars($email['recipient']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($email['sent_at'])): ?>
                                    <div class="text-slate-600">Date:
                                        <?= htmlspecialchars(date('F j, Y g:ia', strtotime($email['sent_at']))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif (!empty($safeDescription)): ?>
                            <div class="prose prose-slate max-w-none text-slate-700 leading-relaxed">
                                <p><?= nl2br(htmlspecialchars($safeDescription)) ?></p>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-3 text-slate-400">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p class="italic">This document has been OCR'd but hasn't been processed by AI yet. Summaries are generated in batches.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isVerifiedGovSource && !$hideSourceUrl): ?>
                <div class="px-5 md:px-8 pt-0 pb-0">
                    <div class="flex items-center gap-3 px-4 py-3 bg-emerald-50 border border-emerald-200 rounded-xl text-sm">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-100 text-emerald-700 text-xs font-bold rounded-full flex-shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            Verified
                        </span>
                        <span class="text-slate-700">
                            Public Record &middot; Source:
                            <span class="font-medium text-slate-900"><?= htmlspecialchars($sourceHost) ?></span>
                        </span>
                        <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank" rel="noopener"
                           class="ml-auto text-emerald-700 hover:text-emerald-800 font-medium hover:underline flex items-center gap-1 flex-shrink-0">
                            View Original
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <div class="p-5 md:p-8">
                    <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-4">Document Content Preview
                    </h3>

                    <?php if (!empty($pdfEmbedUrl)): ?>
                        <div class="rounded-xl border border-slate-200 overflow-hidden bg-white mb-6">
                            <div class="px-4 py-3 flex items-center justify-between bg-slate-50 border-b border-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wider text-slate-700">PDF</div>
                                <div class="flex items-center gap-2">
                                    <a href="<?= htmlspecialchars($pdfEmbedUrl) ?>" target="_blank"
                                        class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Open</a>
                                    <?php if (!$hideSourceUrl && !empty($sourceUrl)): ?>
                                        <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank"
                                            class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Source
                                            URL</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="h-[60vh] md:h-[70vh] min-h-[420px] bg-slate-100">
                                <iframe src="<?= htmlspecialchars($pdfEmbedUrl) ?>#view=FitH" class="w-full h-full"
                                    loading="lazy"></iframe>
                            </div>
                            <div class="px-4 py-3 text-xs text-slate-500 bg-white border-t border-slate-200">
                                If the viewer doesn’t load, use “Open”.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($imageEmbedUrl)): ?>
                        <div class="rounded-xl border border-slate-200 overflow-hidden bg-white mb-6">
                            <div class="px-4 py-3 flex items-center justify-between bg-slate-50 border-b border-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wider text-slate-700">Image</div>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                        onclick="openImageModal('<?= htmlspecialchars($imageEmbedUrl, ENT_QUOTES) ?>', '<?= htmlspecialchars($doc['title'], ENT_QUOTES) ?>')"
                                        class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Open</button>
                                    <?php if (!$hideSourceUrl && !empty($sourceUrl)): ?>
                                        <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank"
                                            class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Source
                                            URL</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bg-slate-100 p-3">
                                <img src="<?= htmlspecialchars($imageEmbedUrl) ?>"
                                    alt="<?= htmlspecialchars($doc['title']) ?>"
                                    class="w-full max-h-[70vh] object-contain bg-white rounded-lg border border-slate-200 cursor-pointer hover:opacity-90 transition-opacity"
                                    loading="lazy"
                                    onclick="openImageModal(this.src, this.alt)"
                                    title="Click to enlarge" />
                            </div>
                            <?php if (!empty($doc['media_width'])): ?>
                                <div class="px-4 py-2 text-xs text-slate-400 bg-white border-t border-slate-200">
                                    <?= (int)$doc['media_width'] ?>x<?= (int)$doc['media_height'] ?>
                                    <?php if (!empty($doc['media_format'])): ?>
                                        | <?= htmlspecialchars($doc['media_format']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($videoEmbedUrl)): ?>
                        <div class="rounded-xl border border-slate-200 overflow-hidden bg-white mb-6">
                            <div class="px-4 py-3 flex items-center justify-between bg-slate-50 border-b border-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wider text-slate-700">🎬 Video</div>
                                <div class="flex items-center gap-2">
                                    <a href="<?= htmlspecialchars($videoEmbedUrl) ?>" target="_blank"
                                        class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Download</a>
                                    <?php if (!$hideSourceUrl && !empty($sourceUrl)): ?>
                                        <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank"
                                            class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Source
                                            URL</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bg-black p-0">
                                <video controls class="w-full max-h-[70vh]" preload="metadata">
                                    <source src="<?= htmlspecialchars($videoEmbedUrl) ?>" type="video/mp4">
                                    Your browser does not support the video tag.
                                </video>
                            </div>
                            <div class="px-4 py-3 text-xs text-slate-500 bg-white border-t border-slate-200 flex items-center justify-between">
                                <span>If the video doesn't play, use "Download" to view in your media player.</span>
                                <?php if (!empty($doc['media_duration_seconds'])): ?>
                                    <span class="text-slate-400">
                                        <?= gmdate("H:i:s", (int)$doc['media_duration_seconds']) ?>
                                        <?php if (!empty($doc['media_width'])): ?>
                                            | <?= (int)$doc['media_width'] ?>x<?= (int)$doc['media_height'] ?>
                                        <?php endif; ?>
                                        <?php if (!empty($doc['media_codec'])): ?>
                                            | <?= htmlspecialchars($doc['media_codec']) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($audioEmbedUrl)): ?>
                        <div class="rounded-xl border border-slate-200 overflow-hidden bg-white mb-6">
                            <div class="px-4 py-3 flex items-center justify-between bg-slate-50 border-b border-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wider text-slate-700">🎧 Audio</div>
                                <div class="flex items-center gap-2">
                                    <a href="<?= htmlspecialchars($audioEmbedUrl) ?>" target="_blank"
                                        class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Download</a>
                                    <?php if (!$hideSourceUrl && !empty($sourceUrl)): ?>
                                        <a href="<?= htmlspecialchars($sourceUrl) ?>" target="_blank"
                                            class="px-3 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 text-xs font-bold text-slate-700">Source
                                            URL</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bg-gradient-to-br from-purple-50 to-blue-50 p-6">
                                <audio controls class="w-full" preload="metadata">
                                    <source src="<?= htmlspecialchars($audioEmbedUrl) ?>"
                                        type="audio/<?= preg_match('/\.wav(\?|$)/i', $audioEmbedUrl) ? 'wav' : (preg_match('/\.mp3(\?|$)/i', $audioEmbedUrl) ? 'mpeg' : 'ogg') ?>">
                                    Your browser does not support the audio element.
                                </audio>
                            </div>
                            <div class="px-4 py-3 text-xs text-slate-500 bg-white border-t border-slate-200">
                                If the audio doesn't play, use "Download" to listen in your media player.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isEmail && !empty($email)): ?>
                        <div class="rounded-xl border border-slate-200 bg-white overflow-hidden mb-6">
                            <div class="px-4 py-3 bg-slate-50 border-b border-slate-200">
                                <div class="text-xs font-bold uppercase tracking-wider text-slate-700">Email</div>
                            </div>
                            <div class="p-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <div class="text-xs text-slate-500">From</div>
                                        <div class="font-medium text-slate-900 break-words">
                                            <?= htmlspecialchars($email['sender'] ?? 'Unknown') ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-slate-500">To</div>
                                        <div class="font-medium text-slate-900 break-words">
                                            <?= htmlspecialchars($email['recipient'] ?? 'Unknown') ?>
                                        </div>
                                    </div>
                                    <div class="md:col-span-2">
                                        <div class="text-xs text-slate-500">Subject</div>
                                        <div class="font-medium text-slate-900 break-words">
                                            <?= htmlspecialchars($email['subject'] ?? '(No Subject)') ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($email['sent_at'])): ?>
                                        <div class="md:col-span-2">
                                            <div class="text-xs text-slate-500">Date</div>
                                            <div class="font-medium text-slate-900">
                                                <?= htmlspecialchars(date('F j, Y g:ia', strtotime($email['sent_at']))) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($email['body'])): ?>
                                    <div class="mt-4">
                                        <div class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-2">Body</div>
                                        <div
                                            class="bg-slate-50 border border-slate-200 rounded-lg p-4 font-mono text-xs text-slate-800 whitespace-pre-wrap max-h-[520px] overflow-y-auto">
                                            <?= htmlspecialchars($email['body']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ocrPages)): ?>
                        <div class="space-y-3">
                            <?php foreach ($ocrPages as $p): ?>
                                <details class="bg-slate-50 rounded border border-slate-100">
                                    <summary
                                        class="cursor-pointer select-none px-4 py-3 text-xs font-bold text-slate-700 flex items-center justify-between">
                                        <span>Page <?= (int) $p['page_number'] ?></span>
                                        <span class="text-slate-400 font-normal">Click to expand</span>
                                    </summary>
                                    <div
                                        class="px-4 pb-4 font-mono text-xs text-slate-700 whitespace-pre-wrap max-h-96 overflow-y-auto">
                                        <?= htmlspecialchars($p['ocr_text']) ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>

                        <?php
                        $ocrTotalPagesCount = (int) ceil($ocrTotalPages / $ocrLimit);
                        $baseQuery = ['id' => $id];
                        ?>
                        <?php if ($ocrTotalPagesCount > 1): ?>
                            <div class="mt-6 flex items-center justify-between text-sm text-slate-600">
                                <div>
                                    Pages with OCR: <?= number_format($ocrTotalPages) ?>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php if ($ocrPage > 1): ?>
                                        <a class="px-3 py-1 rounded border border-slate-200 hover:bg-slate-50"
                                            href="/document.php?<?= http_build_query(array_merge($baseQuery, ['p' => $ocrPage - 1])) ?>">Prev</a>
                                    <?php endif; ?>
                                    <span class="text-xs text-slate-500">Page <?= $ocrPage ?> of
                                        <?= $ocrTotalPagesCount ?></span>
                                    <?php if ($ocrPage < $ocrTotalPagesCount): ?>
                                        <a class="px-3 py-1 rounded border border-slate-200 hover:bg-slate-50"
                                            href="/document.php?<?= http_build_query(array_merge($baseQuery, ['p' => $ocrPage + 1])) ?>">Next</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="bg-slate-50 rounded border border-slate-100 p-4 text-sm text-slate-500">
                            OCR text is not available for this document yet.

                            <div class="mt-4 flex flex-col gap-3">
                                <label class="inline-flex items-center gap-2 text-xs text-slate-600">
                                    <input id="reprocessClearPages" type="checkbox" class="rounded border-slate-300"
                                        checked>
                                    Clear existing OCR/pages and re-run from scratch
                                </label>

                                <div class="flex items-center gap-3">
                                    <button id="reprocessBtn" type="button"
                                        class="px-4 py-2 bg-blue-600 text-white rounded text-xs font-bold hover:bg-blue-700">
                                        Re-run OCR / Reprocess
                                    </button>
                                    <span id="reprocessStatus" class="text-xs text-slate-500"></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    (() => {
        const btn = document.getElementById('reprocessBtn');
        if (!btn) return;

        const statusEl = document.getElementById('reprocessStatus');
        const clearEl = document.getElementById('reprocessClearPages');

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            if (statusEl) statusEl.textContent = 'Requesting reprocess...';

            try {
                const res = await fetch('/api/reprocess_document.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: <?= (int) $id ?>,
                        clear_pages: !!(clearEl && clearEl.checked)
                    })
                });

                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    const msg = (data && data.error) ? data.error : 'Unable to reprocess';
                    if (statusEl) statusEl.textContent = msg;
                    btn.disabled = false;
                    return;
                }

                if (statusEl) statusEl.textContent = 'Queued. Refreshing...';
                window.location.reload();
            } catch (e) {
                if (statusEl) statusEl.textContent = 'Network error';
                btn.disabled = false;
            }
        });
    })();

    (() => {
        const manualBtn = document.querySelector('[data-manual-download]');
        if (!manualBtn) return;

        const statusEl = document.querySelector('[data-manual-download-status]');
        const docId = parseInt(manualBtn.getAttribute('data-doc-id') || '0', 10);

        manualBtn.addEventListener('click', async () => {
            if (!docId) return;
            manualBtn.disabled = true;
            manualBtn.classList.add('opacity-60', 'cursor-not-allowed');
            if (statusEl) statusEl.textContent = 'Fetching file…';

            try {
                const res = await fetch('/api/manual_download.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: docId })
                });

                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok) {
                    const msg = data && data.error ? data.error : 'Unable to fetch file';
                    if (statusEl) statusEl.textContent = msg;
                    manualBtn.disabled = false;
                    manualBtn.classList.remove('opacity-60', 'cursor-not-allowed');
                    return;
                }

                if (statusEl) statusEl.textContent = 'Success! Refreshing…';
                setTimeout(() => window.location.reload(), 1200);
            } catch (err) {
                if (statusEl) statusEl.textContent = 'Network error. Please try again.';
                manualBtn.disabled = false;
                manualBtn.classList.remove('opacity-60', 'cursor-not-allowed');
            }
        });
    })();
</script>

<!-- Image Lightbox Modal -->
<div id="imageModal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" onclick="closeImageModal()"></div>
    <div class="absolute inset-0 flex flex-col">
        <!-- Modal toolbar -->
        <div class="flex items-center justify-between px-4 py-3 bg-black/40 text-white flex-shrink-0 relative z-10">
            <span id="imageModalTitle" class="text-sm font-medium truncate mr-4"></span>
            <div class="flex items-center gap-2">
                <button onclick="zoomImage(-0.25)" class="p-2 rounded-lg hover:bg-white/10 transition-colors" title="Zoom out">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"/></svg>
                </button>
                <span id="zoomLevel" class="text-xs font-mono w-12 text-center">100%</span>
                <button onclick="zoomImage(0.25)" class="p-2 rounded-lg hover:bg-white/10 transition-colors" title="Zoom in">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"/></svg>
                </button>
                <button onclick="resetZoom()" class="p-2 rounded-lg hover:bg-white/10 transition-colors text-xs font-medium" title="Reset zoom">Fit</button>
                <div class="w-px h-5 bg-white/20 mx-1"></div>
                <button onclick="closeImageModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors" title="Close (Esc)">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <!-- Scrollable image container -->
        <div id="imageModalBody" class="flex-1 overflow-auto flex items-center justify-center cursor-grab active:cursor-grabbing">
            <img id="imageModalImg" src="" alt="" class="transition-transform duration-150 select-none" draggable="false" />
        </div>
    </div>
</div>

<script>
(() => {
    let currentZoom = 1;
    let baseWidth = 0;
    let baseHeight = 0;
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('imageModalImg');
    const modalTitle = document.getElementById('imageModalTitle');
    const zoomLabel = document.getElementById('zoomLevel');
    const body = document.getElementById('imageModalBody');

    function fitToContainer() {
        const cw = body.clientWidth - 32;
        const ch = body.clientHeight - 32;
        const nw = modalImg.naturalWidth || 800;
        const nh = modalImg.naturalHeight || 600;
        const scale = Math.min(cw / nw, ch / nh, 1);
        baseWidth = nw * scale;
        baseHeight = nh * scale;
    }

    function applyZoom() {
        const w = baseWidth * currentZoom;
        const h = baseHeight * currentZoom;
        modalImg.style.width = w + 'px';
        modalImg.style.height = h + 'px';
        modalImg.style.minWidth = w + 'px';
        modalImg.style.minHeight = h + 'px';
        zoomLabel.textContent = Math.round(currentZoom * 100) + '%';
    }

    window.openImageModal = function(src, alt) {
        modalImg.style.width = '';
        modalImg.style.height = '';
        modalImg.style.minWidth = '';
        modalImg.style.minHeight = '';
        modalImg.src = src;
        modalImg.alt = alt || '';
        modalTitle.textContent = alt || 'Image Preview';
        currentZoom = 1;
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        if (modalImg.complete && modalImg.naturalWidth) {
            fitToContainer();
            applyZoom();
        } else {
            modalImg.onload = function() {
                fitToContainer();
                applyZoom();
            };
        }
    };

    window.closeImageModal = function() {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
        modalImg.src = '';
    };

    window.zoomImage = function(delta) {
        const oldZoom = currentZoom;
        currentZoom = Math.min(5, Math.max(0.25, currentZoom + delta));
        if (currentZoom === oldZoom) return;

        // Preserve scroll center when zooming
        const cx = body.scrollLeft + body.clientWidth / 2;
        const cy = body.scrollTop + body.clientHeight / 2;
        const ratio = currentZoom / oldZoom;

        applyZoom();

        body.scrollLeft = cx * ratio - body.clientWidth / 2;
        body.scrollTop = cy * ratio - body.clientHeight / 2;
    };

    window.resetZoom = function() {
        currentZoom = 1;
        applyZoom();
        body.scrollLeft = 0;
        body.scrollTop = 0;
    };

    // Keyboard controls
    document.addEventListener('keydown', function(e) {
        if (modal.classList.contains('hidden')) return;
        if (e.key === 'Escape') closeImageModal();
        if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomImage(0.25); }
        if (e.key === '-') { e.preventDefault(); zoomImage(-0.25); }
        if (e.key === '0') { e.preventDefault(); resetZoom(); }
    });

    // Mouse wheel zoom
    if (body) {
        body.addEventListener('wheel', function(e) {
            if (modal.classList.contains('hidden')) return;
            e.preventDefault();
            const delta = e.deltaY < 0 ? 0.15 : -0.15;
            zoomImage(delta);
        }, { passive: false });

        // Click-and-drag panning
        let isDragging = false;
        let startX, startY, scrollStartX, scrollStartY;

        body.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return;
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            scrollStartX = body.scrollLeft;
            scrollStartY = body.scrollTop;
            body.style.cursor = 'grabbing';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            body.scrollLeft = scrollStartX - (e.clientX - startX);
            body.scrollTop = scrollStartY - (e.clientY - startY);
        });

        document.addEventListener('mouseup', function() {
            if (!isDragging) return;
            isDragging = false;
            body.style.cursor = '';
        });
    }
})();
</script>

<script>
// Track document view via sendBeacon as client-side fallback
(function() {
    var docId = <?= (int)$id ?>;
    if (docId > 0 && navigator.sendBeacon) {
        var data = JSON.stringify({document_id: docId, referrer: 'document'});
        navigator.sendBeacon('/api/log_document_view.php', new Blob([data], {type: 'application/json'}));
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>

</html>