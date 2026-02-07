<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

// Cache TTL: 5 minutes for stats (they change during processing)
$cacheTtl = 300;

try {
    $pdo = db();
    
    // Use cache for expensive queries
    $statsData = Cache::remember('stats_page_data', function() use ($pdo) {
        $data = [];
        
        // General Stats
        $stats = $pdo->query("SELECT status, COUNT(*) as count FROM documents GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $data['totalDocs'] = array_sum($stats);
        $data['processed'] = $stats['processed'] ?? 0;
        $data['pending'] = $stats['pending'] ?? 0;
        
        // Processing Progress Stats
        $data['withAiSummary'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE ai_summary IS NOT NULL AND ai_summary != ''")->fetchColumn();
        $data['withOcr'] = $pdo->query("SELECT COUNT(DISTINCT document_id) FROM pages WHERE ocr_text IS NOT NULL AND ocr_text != ''")->fetchColumn();
        $data['withLocalFile'] = $pdo->query("SELECT COUNT(*) FROM documents WHERE local_path IS NOT NULL AND local_path != ''")->fetchColumn();
        $data['docsLinkedToEntities'] = $pdo->query("SELECT COUNT(DISTINCT document_id) FROM document_entities")->fetchColumn();
        
        // Entity counts by type
        $data['entityStats'] = $pdo->query("SELECT COALESCE(type, 'UNKNOWN') AS entity_type, COUNT(*) as count FROM entities GROUP BY COALESCE(type, 'UNKNOWN')")->fetchAll(PDO::FETCH_ASSOC);
        $data['totalEntities'] = array_sum(array_column($data['entityStats'], 'count'));
        
        // Email and Flight counts
        $data['emailCount'] = $pdo->query("SELECT COUNT(*) as count FROM emails")->fetchColumn();
        $data['flightCount'] = $pdo->query("SELECT COUNT(*) as count FROM flight_logs")->fetchColumn();
        
        // Top Entities with clickable links
        $data['topEntities'] = $pdo->query("SELECT e.id AS entity_id, e.name AS entity_name,
                                           e.type AS entity_type,
                                           COUNT(de.document_id) AS mention_count
                                    FROM document_entities de
                                    JOIN entities e ON e.id = de.entity_id
                                    GROUP BY e.id, e.name, e.type
                                    ORDER BY mention_count DESC
                                    LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        
        // Data set distribution with processing stats
        $data['dataSets'] = $pdo->query("
            SELECT d.data_set,
                   COUNT(*) as count,
                   SUM(CASE WHEN d.ai_summary IS NOT NULL AND d.ai_summary != '' THEN 1 ELSE 0 END) as with_ai,
                   SUM(CASE WHEN ocr_check.document_id IS NOT NULL THEN 1 ELSE 0 END) as with_ocr
            FROM documents d
            LEFT JOIN (
                SELECT DISTINCT document_id FROM pages WHERE ocr_text IS NOT NULL AND ocr_text != ''
            ) ocr_check ON ocr_check.document_id = d.id
            WHERE d.data_set IS NOT NULL
            GROUP BY d.data_set
            ORDER BY count DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        // Processing timeline (last 30 days)
        $data['timeline'] = $pdo->query("SELECT DATE(created_at) as date, COUNT(*) as count FROM documents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY date")->fetchAll(PDO::FETCH_ASSOC);
        
        // File type distribution
        $data['fileTypes'] = $pdo->query("SELECT file_type, COUNT(*) as count FROM documents GROUP BY file_type ORDER BY count DESC")->fetchAll(PDO::FETCH_ASSOC);
        
        return $data;
    }, $cacheTtl);
    
    // Extract cached data
    extract($statsData);
    
    // Clean up data set names
    foreach ($dataSets as &$dsRow) {
        if (!empty($dsRow['data_set']) && is_string($dsRow['data_set'])) {
            $dsRow['data_set'] = preg_replace('/^Google\s+Drive\s*-\s*/i', '', $dsRow['data_set']) ?? $dsRow['data_set'];
        }
    }
    unset($dsRow);
    
    // Recent Activity - not cached (always fresh)
    $recent = $pdo->query("SELECT id, title, created_at, status, ai_summary FROM documents ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

    // Live Processing Queue - not cached (always fresh)
    // Documents actively in the pipeline (pending or downloaded = not yet finished)
    $processingQueue = $pdo->query("
        SELECT id, title, status, data_set, updated_at, file_type
        FROM documents
        WHERE status IN ('pending', 'downloaded')
        ORDER BY updated_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Documents awaiting AI summary (OCR done but no summary yet)
    $awaitingAi = $pdo->query("
        SELECT id, title, data_set, updated_at, file_type
        FROM documents
        WHERE status = 'processed'
          AND (ai_summary IS NULL OR ai_summary = '')
        ORDER BY updated_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Recently completed (processed in the last 24 hours)
    $recentlyCompleted = $pdo->query("
        SELECT id, title, data_set, updated_at, file_type,
               CASE WHEN ai_summary IS NOT NULL AND ai_summary != '' THEN 1 ELSE 0 END AS has_ai
        FROM documents
        WHERE status = 'processed'
          AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY updated_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Count totals for queue badges
    $queueCounts = [
        'downloading' => (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status IN ('pending', 'downloaded')")->fetchColumn(),
        'awaiting_ai' => (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'processed' AND (ai_summary IS NULL OR ai_summary = '')")->fetchColumn(),
        'errors' => (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'error'")->fetchColumn(),
    ];
    
    // Calculate percentages
    $ocrPercent = $totalDocs > 0 ? round(($withOcr / $totalDocs) * 100, 1) : 0;
    $aiPercent = $totalDocs > 0 ? round(($withAiSummary / $totalDocs) * 100, 1) : 0;
    $entityPercent = $totalDocs > 0 ? round(($docsLinkedToEntities / $totalDocs) * 100, 1) : 0;
    $processingRate = $totalDocs > 0 ? round(($processed / $totalDocs) * 100, 1) : 0;
    $avgEntitiesPerDoc = $docsLinkedToEntities > 0 ? round($totalEntities / $docsLinkedToEntities, 1) : 0;

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$page_title = 'Epstein Analytics';
$meta_description = 'Real-time analytics on the Epstein Suite archive: document processing progress, OCR coverage, entity extraction rates, and AI summary statistics across 4,700+ files.';
$extra_head_tags = [];
$statsSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Epstein Suite Analytics',
    'description' => 'Real-time archive processing statistics and analytics dashboard.',
    'url' => 'https://epsteinsuite.com/stats.php',
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($statsSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
require_once __DIR__ . '/includes/header_suite.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<?php
?>

<div class="flex flex-1 overflow-hidden bg-slate-50">
    <!-- Sidebar (Analytics Nav) -->
    <aside class="w-64 flex-shrink-0 flex flex-col py-4 pr-4 border-r border-gray-200 bg-white hidden md:flex">
        <div class="px-6 mb-6">
            <h2 class="text-xs font-bold text-gray-400 uppercase tracking-wider">Reports</h2>
        </div>
        <nav class="flex-1 space-y-1">
            <a href="#overview" data-section="overview" class="analytics-nav-link flex items-center gap-3 px-6 py-2 bg-blue-50 text-blue-700 border-r-4 border-blue-600 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                Realtime Overview
            </a>
            <a href="#audience" data-section="audience" class="analytics-nav-link flex items-center gap-3 px-6 py-2 text-gray-600 hover:bg-gray-50 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                Audience (Entities)
            </a>
            <a href="#acquisition" data-section="acquisition" class="analytics-nav-link flex items-center gap-3 px-6 py-2 text-gray-600 hover:bg-gray-50 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                Acquisition (Sources)
            </a>
            <a href="#behavior" data-section="behavior" class="analytics-nav-link flex items-center gap-3 px-6 py-2 text-gray-600 hover:bg-gray-50 font-medium text-sm">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                Behavior (Processing)
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8">
        <div class="max-w-6xl mx-auto">
            <div id="overview" class="scroll-mt-24"></div>
            <h1 class="text-2xl font-normal text-slate-800 mb-6">Dashboard</h1>

            <!-- AI Insights Banner -->
            <div class="bg-gradient-to-r from-purple-500 to-blue-600 rounded-2xl p-6 mb-8 text-white shadow-lg">
                <div class="flex items-start gap-4">
                    <div class="text-4xl">✨</div>
                    <div class="flex-1">
                        <h2 class="text-xl font-bold mb-2">AI-Powered Insights</h2>
                        <div class="space-y-1 text-sm text-white/90">
                            <p>• Database contains <strong><?= number_format($totalDocs) ?></strong> documents with <strong><?= $processingRate ?>%</strong> processed by AI</p>
                            <p>• Extracted <strong><?= number_format($totalEntities) ?></strong> entities (avg <strong><?= $avgEntitiesPerDoc ?></strong> per document)</p>
                            <p>• Indexed <strong><?= number_format($emailCount) ?></strong> email communications and <strong><?= number_format($flightCount) ?></strong> flight logs</p>
                        </div>
                        <a href="/sources.php" class="inline-flex items-center gap-2 mt-4 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            View Data Sources
                        </a>
                    </div>
                </div>
            </div>

            <!-- Processing Progress Section -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-8">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Processing Progress</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- OCR Progress -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-slate-700">OCR Text Extraction</span>
                            <span class="text-sm font-bold text-blue-600"><?= $ocrPercent ?>%</span>
                        </div>
                        <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden">
                            <div class="bg-blue-500 h-full transition-all duration-500" style="width: <?= $ocrPercent ?>%"></div>
                        </div>
                        <div class="text-xs text-slate-500 mt-1"><?= number_format($withOcr) ?> of <?= number_format($totalDocs) ?> documents</div>
                        <div class="text-xs text-slate-400 mt-0.5">Scanned documents converted to searchable text via OCR</div>
                    </div>

                    <!-- AI Summary Progress -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-slate-700">AI Summaries</span>
                            <span class="text-sm font-bold text-purple-600"><?= $aiPercent ?>%</span>
                        </div>
                        <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden">
                            <div class="bg-purple-500 h-full transition-all duration-500" style="width: <?= $aiPercent ?>%"></div>
                        </div>
                        <div class="text-xs text-slate-500 mt-1"><?= number_format($withAiSummary) ?> of <?= number_format($totalDocs) ?> documents</div>
                        <div class="text-xs text-slate-400 mt-0.5">AI-generated plain-language summaries of document contents</div>
                    </div>

                    <!-- Entity Linking Progress -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-slate-700">Entity Linking</span>
                            <span class="text-sm font-bold text-green-600"><?= $entityPercent ?>%</span>
                        </div>
                        <div class="w-full bg-slate-100 h-3 rounded-full overflow-hidden">
                            <div class="bg-green-500 h-full transition-all duration-500" style="width: <?= $entityPercent ?>%"></div>
                        </div>
                        <div class="text-xs text-slate-500 mt-1"><?= number_format($docsLinkedToEntities) ?> docs linked to <?= number_format($totalEntities) ?> entities</div>
                        <div class="text-xs text-slate-400 mt-0.5">People, organizations, and locations identified within documents</div>
                    </div>
                </div>
                
                <!-- Processing Stats Summary -->
                <div class="mt-6 pt-4 border-t border-slate-100 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                    <div>
                        <div class="text-2xl font-bold text-slate-900"><?= number_format($withLocalFile) ?></div>
                        <div class="text-xs text-slate-500">Local Files</div>
                        <div class="text-xs text-slate-400">Downloaded to our servers</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-slate-900"><?= number_format($withOcr) ?></div>
                        <div class="text-xs text-slate-500">OCR'd</div>
                        <div class="text-xs text-slate-400">Text extracted and searchable</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-slate-900"><?= number_format($withAiSummary) ?></div>
                        <div class="text-xs text-slate-500">AI Summarized</div>
                        <div class="text-xs text-slate-400">AI-generated summary available</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-slate-900"><?= number_format($totalDocs - $withOcr) ?></div>
                        <div class="text-xs text-slate-500">Pending OCR</div>
                        <div class="text-xs text-slate-400">Queued for text extraction</div>
                    </div>
                </div>
            </div>

            <!-- Live Processing Queue -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold text-slate-900">Processing Queue</h2>
                        <?php if ($queueCounts['downloading'] > 0): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-pulse"></span>
                                <?= $queueCounts['downloading'] ?> downloading
                            </span>
                        <?php endif; ?>
                        <?php if ($queueCounts['awaiting_ai'] > 0): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-700">
                                <?= $queueCounts['awaiting_ai'] ?> awaiting AI
                            </span>
                        <?php endif; ?>
                        <?php if ($queueCounts['errors'] > 0): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-700">
                                <?= $queueCounts['errors'] ?> errors
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs text-slate-400">Live</span>
                </div>

                <!-- Tabbed queue view -->
                <div class="border-b border-slate-200 mb-4">
                    <nav class="flex gap-6 -mb-px" id="queueTabs">
                        <button data-queue-tab="downloading" class="queue-tab pb-2 text-sm font-medium border-b-2 border-blue-600 text-blue-600">
                            Download / OCR<?php if ($queueCounts['downloading'] > 0): ?> <span class="text-xs opacity-70">(<?= $queueCounts['downloading'] ?>)</span><?php endif; ?>
                        </button>
                        <button data-queue-tab="awaiting-ai" class="queue-tab pb-2 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700">
                            Awaiting AI<?php if ($queueCounts['awaiting_ai'] > 0): ?> <span class="text-xs opacity-70">(<?= $queueCounts['awaiting_ai'] ?>)</span><?php endif; ?>
                        </button>
                        <button data-queue-tab="completed" class="queue-tab pb-2 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700">
                            Recently Completed
                        </button>
                    </nav>
                </div>

                <!-- Download/OCR Queue -->
                <div data-queue-panel="downloading" class="queue-panel">
                    <?php if (empty($processingQueue)): ?>
                        <div class="text-center py-8 text-slate-400">
                            <div class="text-3xl mb-2">&#10003;</div>
                            <p class="text-sm">Download queue is clear — all documents have been fetched.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($processingQueue as $doc): ?>
                                <a href="/document.php?id=<?= (int)$doc['id'] ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                                    <div class="flex-shrink-0">
                                        <?php if ($doc['status'] === 'pending'): ?>
                                            <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center">
                                                <svg class="w-4 h-4 text-amber-600 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center">
                                                <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate group-hover:text-blue-600"><?= htmlspecialchars($doc['title']) ?></p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-xs px-1.5 py-0.5 rounded <?= $doc['status'] === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700' ?>"><?= ucfirst($doc['status']) ?></span>
                                            <?php if (!empty($doc['data_set'])): ?>
                                                <span class="text-xs text-slate-400"><?= htmlspecialchars(preg_replace('/^Google\s+Drive\s*-\s*/i', '', $doc['data_set']) ?? $doc['data_set']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($doc['file_type'])): ?>
                                                <span class="text-xs text-slate-400 uppercase"><?= htmlspecialchars($doc['file_type']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-xs text-slate-400 flex-shrink-0"><?= date('M j, g:i A', strtotime($doc['updated_at'])) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($queueCounts['downloading'] > 10): ?>
                            <p class="text-xs text-slate-400 mt-3 text-center">Showing 10 of <?= number_format($queueCounts['downloading']) ?> queued</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Awaiting AI Queue -->
                <div data-queue-panel="awaiting-ai" class="queue-panel hidden">
                    <?php if (empty($awaitingAi)): ?>
                        <div class="text-center py-8 text-slate-400">
                            <div class="text-3xl mb-2">&#10003;</div>
                            <p class="text-sm">All processed documents have AI summaries.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($awaitingAi as $doc): ?>
                                <a href="/document.php?id=<?= (int)$doc['id'] ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 rounded-full bg-purple-100 flex items-center justify-center">
                                            <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate group-hover:text-purple-600"><?= htmlspecialchars($doc['title']) ?></p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700">Awaiting AI</span>
                                            <?php if (!empty($doc['data_set'])): ?>
                                                <span class="text-xs text-slate-400"><?= htmlspecialchars(preg_replace('/^Google\s+Drive\s*-\s*/i', '', $doc['data_set']) ?? $doc['data_set']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-xs text-slate-400 flex-shrink-0"><?= date('M j, g:i A', strtotime($doc['updated_at'])) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($queueCounts['awaiting_ai'] > 10): ?>
                            <p class="text-xs text-slate-400 mt-3 text-center">Showing 10 of <?= number_format($queueCounts['awaiting_ai']) ?> awaiting AI</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Recently Completed -->
                <div data-queue-panel="completed" class="queue-panel hidden">
                    <?php if (empty($recentlyCompleted)): ?>
                        <div class="text-center py-8 text-slate-400">
                            <p class="text-sm">No documents completed in the last 24 hours.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($recentlyCompleted as $doc): ?>
                                <a href="/document.php?id=<?= (int)$doc['id'] ?>" class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center">
                                            <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate group-hover:text-green-600"><?= htmlspecialchars($doc['title']) ?></p>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700">Processed</span>
                                            <?php if ($doc['has_ai']): ?>
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700">AI Summary</span>
                                            <?php endif; ?>
                                            <?php if (!empty($doc['data_set'])): ?>
                                                <span class="text-xs text-slate-400"><?= htmlspecialchars(preg_replace('/^Google\s+Drive\s*-\s*/i', '', $doc['data_set']) ?? $doc['data_set']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-xs text-slate-400 flex-shrink-0"><?= date('M j, g:i A', strtotime($doc['updated_at'])) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <a href="/drive.php" class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-all group">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-slate-500 text-xs font-bold uppercase tracking-wide">Total Documents</h3>
                        <div class="text-2xl">📄</div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-slate-900 group-hover:text-blue-600 transition-colors"><?= number_format($totalDocs) ?></span>
                    </div>
                    <div class="mt-3 text-xs text-slate-400">PDFs, images, and files cataloged from DOJ, FBI, and congressional sources</div>
                </a>

                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-slate-500 text-xs font-bold uppercase tracking-wide">Fully Processed</h3>
                        <div class="text-2xl">🤖</div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-slate-900"><?= number_format($processed) ?></span>
                        <span class="text-sm text-green-600 font-medium"><?= $processingRate ?>%</span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 mt-3 rounded-full overflow-hidden">
                        <div class="bg-gradient-to-r from-blue-500 to-purple-500 h-full transition-all duration-500" style="width: <?= $processingRate ?>%"></div>
                    </div>
                    <div class="mt-2 text-xs text-slate-400">Documents that have been downloaded, OCR'd, and analyzed</div>
                </div>

                <a href="/contacts.php" class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-all group">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-slate-500 text-xs font-bold uppercase tracking-wide">Entities Mapped</h3>
                        <div class="text-2xl">👥</div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-slate-900 group-hover:text-purple-600 transition-colors"><?= number_format($totalEntities) ?></span>
                    </div>
                    <div class="mt-3 text-xs text-slate-400">People, organizations, and locations identified across all documents</div>
                </a>

                <a href="/email_client.php" class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm hover:shadow-md transition-all group">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-slate-500 text-xs font-bold uppercase tracking-wide">Communications</h3>
                        <div class="text-2xl">✉️</div>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl font-bold text-slate-900 group-hover:text-red-600 transition-colors"><?= number_format($emailCount) ?></span>
                    </div>
                    <div class="mt-3 text-xs text-slate-400">Email messages extracted from document contents and indexed for search</div>
                </a>
            </div>

            <div id="acquisition" class="scroll-mt-24"></div>
            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Entity Distribution Chart -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">Entity Distribution</h3>
                    <div class="h-56">
                        <canvas id="entityChart" class="w-full h-full"></canvas>
                    </div>
                </div>
                
                <!-- Data Set Distribution Chart -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">Data Sets</h3>
                    <div class="h-56">
                        <canvas id="dataSetChart" class="w-full h-full"></canvas>
                    </div>
                </div>
            </div>
            
            <div id="behavior" class="scroll-mt-24"></div>
            <!-- File Types & Processing Timeline -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- File Type Distribution -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">File Types</h3>
                    <div class="h-56">
                        <canvas id="fileTypeChart" class="w-full h-full"></canvas>
                    </div>
                </div>
                
                <!-- Processing Timeline -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">Processing Timeline (30 Days)</h3>
                    <div class="h-56">
                        <canvas id="timelineChart" class="w-full h-full"></canvas>
                    </div>
                </div>
            </div>

            <div id="audience" class="scroll-mt-24"></div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Top Entities -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-slate-800">Top Entities</h3>
                        <a href="/contacts.php" class="text-sm text-blue-600 hover:text-blue-700 font-medium">View all →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($topEntities as $index => $entity): ?>
                            <a href="/?q=<?= urlencode($entity['entity_name']) ?>" class="flex items-center justify-between p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    <span class="text-slate-400 font-mono text-sm w-6">#<?= $index + 1 ?></span>
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm flex-shrink-0
                                        <?php 
                                        echo $entity['entity_type'] === 'PERSON' ? 'bg-purple-100' : 
                                             ($entity['entity_type'] === 'ORG' ? 'bg-blue-100' : 'bg-green-100');
                                        ?>">
                                        <?php 
                                        echo $entity['entity_type'] === 'PERSON' ? '👤' : 
                                             ($entity['entity_type'] === 'ORG' ? '🏢' : '📍');
                                        ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium text-slate-800 truncate group-hover:text-blue-600">
                                            <?= htmlspecialchars($entity['entity_name']) ?>
                                        </div>
                                        <div class="text-xs text-slate-500"><?= $entity['entity_type'] ?></div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="bg-slate-100 px-3 py-1 rounded-full text-sm text-slate-700 font-medium"><?= number_format($entity['mention_count']) ?></span>
                                    <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Activity Log -->
                <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-slate-800">Recent Activity</h3>
                        <span class="text-xs text-slate-500">Live feed</span>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($recent as $doc): ?>
                            <a href="/document.php?id=<?= $doc['id'] ?>" class="flex items-start gap-3 p-3 rounded-lg hover:bg-slate-50 transition-colors group">
                                <div class="flex-shrink-0 mt-1">
                                    <?php if ($doc['status'] === 'processed'): ?>
                                        <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                    <?php else: ?>
                                        <div class="w-2 h-2 bg-amber-500 rounded-full"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-slate-800 truncate group-hover:text-blue-600">
                                        <?= htmlspecialchars($doc['title']) ?>
                                    </p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-xs text-slate-500"><?= date('M j, g:i A', strtotime($doc['created_at'])) ?></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full <?= $doc['status'] === 'processed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
                                            <?= ucfirst($doc['status']) ?>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Queue tab switching
(() => {
    const tabs = document.querySelectorAll('[data-queue-tab]');
    const panels = document.querySelectorAll('[data-queue-panel]');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => {
                t.classList.remove('border-blue-600', 'text-blue-600');
                t.classList.add('border-transparent', 'text-slate-500');
            });
            tab.classList.remove('border-transparent', 'text-slate-500');
            tab.classList.add('border-blue-600', 'text-blue-600');
            panels.forEach(p => p.classList.add('hidden'));
            const target = document.querySelector(`[data-queue-panel="${tab.dataset.queueTab}"]`);
            if (target) target.classList.remove('hidden');
        });
    });
})();

(() => {
    const links = Array.from(document.querySelectorAll('.analytics-nav-link'));
    const activeClasses = ['bg-blue-50', 'text-blue-700', 'border-r-4', 'border-blue-600'];
    const inactiveClasses = ['text-gray-600'];

    const setActive = (section) => {
        links.forEach((a) => {
            const isActive = a.getAttribute('data-section') === section;
            if (isActive) {
                activeClasses.forEach((c) => a.classList.add(c));
                inactiveClasses.forEach((c) => a.classList.remove(c));
            } else {
                activeClasses.forEach((c) => a.classList.remove(c));
                if (!a.classList.contains('text-gray-600')) a.classList.add('text-gray-600');
                a.classList.remove('text-blue-700');
                a.classList.remove('bg-blue-50');
                a.classList.remove('border-r-4');
                a.classList.remove('border-blue-600');
            }
        });
    };

    const fromHash = () => {
        const h = (window.location.hash || '').replace('#', '');
        const allowed = new Set(['overview', 'audience', 'acquisition', 'behavior']);
        return allowed.has(h) ? h : 'overview';
    };

    setActive(fromHash());
    window.addEventListener('hashchange', () => setActive(fromHash()));
    links.forEach((a) => a.addEventListener('click', () => setActive(a.getAttribute('data-section') || 'overview')));
})();

// Entity Distribution Pie Chart
const entityCtx = document.getElementById('entityChart').getContext('2d');
new Chart(entityCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($entityStats, 'entity_type')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($entityStats, 'count')) ?>,
            backgroundColor: [
                'rgba(147, 51, 234, 0.8)',  // Purple for PERSON
                'rgba(59, 130, 246, 0.8)',  // Blue for ORG
                'rgba(34, 197, 94, 0.8)',   // Green for LOCATION
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    font: { size: 12 }
                }
            }
        }
    }
});

// Data Set Bar Chart
const dataSetCtx = document.getElementById('dataSetChart').getContext('2d');
new Chart(dataSetCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($dataSets, 'data_set')) ?>,
        datasets: [{
            label: 'Documents',
            data: <?= json_encode(array_column($dataSets, 'count')) ?>,
            backgroundColor: 'rgba(59, 130, 246, 0.8)',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            }
        }
    }
});

// File Type Pie Chart
const fileTypeCtx = document.getElementById('fileTypeChart').getContext('2d');
new Chart(fileTypeCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_column($fileTypes, 'file_type')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($fileTypes, 'count')) ?>,
            backgroundColor: [
                'rgba(239, 68, 68, 0.8)',
                'rgba(59, 130, 246, 0.8)',
                'rgba(34, 197, 94, 0.8)',
                'rgba(251, 191, 36, 0.8)',
                'rgba(147, 51, 234, 0.8)',
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    font: { size: 12 }
                }
            }
        }
    }
});

// Processing Timeline Line Chart
const timelineCtx = document.getElementById('timelineChart').getContext('2d');
new Chart(timelineCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($timeline, 'date')) ?>,
        datasets: [{
            label: 'Documents Processed',
            data: <?= json_encode(array_column($timeline, 'count')) ?>,
            borderColor: 'rgba(147, 51, 234, 1)',
            backgroundColor: 'rgba(147, 51, 234, 0.1)',
            fill: true,
            tension: 0.4,
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            },
            x: {
                ticks: {
                    maxRotation: 45,
                    minRotation: 45
                }
            }
        }
    }
});
</script>

</body>
</html>
