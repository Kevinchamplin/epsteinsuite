<?php
/**
 * Timeline View
 * Shows documents organized by date/year
 */
$page_title = 'Timeline - Epstein Suite';
$meta_description = 'Browse Epstein-related documents organized by year. A chronological timeline of DOJ releases, court filings, and investigative records from the Epstein files.';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/header_suite.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$documents = [];
$yearCounts = [];

try {
    $pdo = db();
    
    // Use document_date when available, fall back to created_at
    $stmt = $pdo->query("
        SELECT YEAR(COALESCE(document_date, created_at)) AS year, COUNT(*) AS cnt
        FROM documents
        WHERE COALESCE(document_date, created_at) IS NOT NULL
        GROUP BY YEAR(COALESCE(document_date, created_at))
        ORDER BY year DESC
    ");
    $yearCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Fetch documents for selected year or recent
    if ($year) {
        $stmt = $pdo->prepare("
            SELECT id, title, file_type, data_set, ai_summary,
                   COALESCE(document_date, created_at) AS event_date
            FROM documents
            WHERE YEAR(COALESCE(document_date, created_at)) = ?
            ORDER BY event_date DESC
            LIMIT 100
        ");
        $stmt->execute([$year]);
    } else {
        $stmt = $pdo->query("
            SELECT id, title, file_type, data_set, ai_summary,
                   COALESCE(document_date, created_at) AS event_date
            FROM documents
            WHERE ai_summary IS NOT NULL AND ai_summary != ''
            ORDER BY event_date DESC
            LIMIT 50
        ");
    }
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Silent fail
}

// Group documents by month
$groupedDocs = [];
foreach ($documents as $doc) {
    $monthKey = date('F Y', strtotime($doc['event_date']));
    if (!isset($groupedDocs[$monthKey])) {
        $groupedDocs[$monthKey] = [];
    }
    $groupedDocs[$monthKey][] = $doc;
}
?>

<main class="flex-1 w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-slate-900 mb-2">Timeline</h1>
        <p class="text-slate-600">Browse documents chronologically</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
        
        <!-- Year Sidebar -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden sticky top-4">
                <div class="px-4 py-3 border-b border-slate-200">
                    <h3 class="font-semibold text-slate-900">Years</h3>
                </div>
                <div class="p-2 max-h-96 overflow-y-auto">
                    <a href="/timeline.php" class="flex items-center justify-between p-2 rounded-lg <?= !$year ? 'bg-blue-50 text-blue-700' : 'hover:bg-slate-50 text-slate-700' ?> transition-colors">
                        <span class="font-medium">Recent (AI Summarized)</span>
                    </a>
                    <?php foreach ($yearCounts as $y => $cnt): ?>
                        <a href="/timeline.php?year=<?= $y ?>" class="flex items-center justify-between p-2 rounded-lg <?= $year == $y ? 'bg-blue-50 text-blue-700' : 'hover:bg-slate-50 text-slate-700' ?> transition-colors">
                            <span class="font-medium"><?= $y ?></span>
                            <span class="text-xs text-slate-400"><?= number_format($cnt) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Timeline Content -->
        <div class="lg:col-span-3">
            <?php if (empty($documents)): ?>
                <div class="bg-white rounded-2xl border border-slate-200 p-8 text-center">
                    <div class="text-4xl mb-4">📅</div>
                    <h3 class="text-lg font-semibold text-slate-900 mb-2">No documents found</h3>
                    <p class="text-slate-500">Select a year from the sidebar to browse documents.</p>
                </div>
            <?php else: ?>
                <div class="space-y-8">
                    <?php foreach ($groupedDocs as $month => $docs): ?>
                        <div>
                            <h2 class="text-lg font-bold text-slate-900 mb-4 sticky top-0 bg-slate-50 py-2 z-10"><?= $month ?></h2>
                            <div class="relative pl-8 border-l-2 border-slate-200 space-y-4">
                                <?php foreach ($docs as $doc): ?>
                                    <div class="relative">
                                        <!-- Timeline dot -->
                                        <div class="absolute -left-[25px] w-4 h-4 bg-blue-500 rounded-full border-2 border-white"></div>
                                        
                                        <a href="/document.php?id=<?= $doc['id'] ?>" class="block bg-white rounded-xl border border-slate-200 p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                                            <div class="flex items-start gap-3">
                                                <div class="w-10 h-10 bg-slate-100 rounded-lg flex items-center justify-center text-lg flex-shrink-0">
                                                    <?php 
                                                    echo match($doc['file_type'] ?? '') {
                                                        'pdf' => '📕',
                                                        'jpg', 'jpeg', 'png' => '🖼️',
                                                        'video', 'mp4' => '🎬',
                                                        default => '📄'
                                                    };
                                                    ?>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="text-xs text-slate-400 mb-1">
                                                        <?= date('M j, Y', strtotime($doc['event_date'])) ?>
                                                        • <?= htmlspecialchars($doc['data_set'] ?? 'Unknown') ?>
                                                    </div>
                                                    <h3 class="font-medium text-slate-900 truncate"><?= htmlspecialchars($doc['title']) ?></h3>
                                                    <?php if (!empty($doc['ai_summary'])): ?>
                                                        <p class="text-sm text-slate-500 line-clamp-2 mt-1">
                                                            <span class="text-purple-600">✨</span>
                                                            <?= htmlspecialchars(substr($doc['ai_summary'], 0, 150)) ?>...
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
