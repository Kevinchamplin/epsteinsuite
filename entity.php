<?php
/**
 * Entity Detail Page
 * Shows all documents related to a specific entity (person, org, location)
 */
$page_title = 'Entity Details - Epstein Suite';
require_once __DIR__ . '/includes/db.php';

$entityId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$entity = null;
$documents = [];
$relatedEntities = [];
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$totalDocs = 0;

try {
    $pdo = db();
    
    // Fetch entity
    $stmt = $pdo->prepare("SELECT * FROM entities WHERE id = ?");
    $stmt->execute([$entityId]);
    $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($entity) {
        // Get total document count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM document_entities WHERE entity_id = ?");
        $stmt->execute([$entityId]);
        $totalDocs = (int)$stmt->fetchColumn();
        
        // Fetch documents linked to this entity
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.file_type, d.data_set, d.ai_summary, d.created_at,
                   de.frequency as mention_count
            FROM documents d
            JOIN document_entities de ON de.document_id = d.id
            WHERE de.entity_id = ?
            ORDER BY de.frequency DESC, d.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$entityId, $limit, $offset]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch related entities (entities that appear in the same documents)
        $stmt = $pdo->prepare("
            SELECT e.id, e.name, e.type, COUNT(DISTINCT de2.document_id) as shared_docs
            FROM entities e
            JOIN document_entities de2 ON de2.entity_id = e.id
            WHERE de2.document_id IN (
                SELECT document_id FROM document_entities WHERE entity_id = ?
            )
            AND e.id != ?
            GROUP BY e.id
            ORDER BY shared_docs DESC
            LIMIT 20
        ");
        $stmt->execute([$entityId, $entityId]);
        $relatedEntities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Silent fail
}

if (!$entity) {
    header("HTTP/1.0 404 Not Found");
    echo "Entity not found.";
    exit;
}

$page_title = htmlspecialchars($entity['name']) . ' - Epstein Suite';
$meta_description = htmlspecialchars($entity['name']) . ' appears in ' . number_format($totalDocs) . ' Epstein-related documents. View all document mentions, related entities, and connections in the DOJ files.';
$og_title = htmlspecialchars($entity['name']) . ' - Entity Profile';
$og_description = 'Explore ' . number_format($totalDocs) . ' documents mentioning ' . htmlspecialchars($entity['name']) . ' in the Epstein files archive.';
$extra_head_tags = [];

$entitySchemaType = match (strtoupper($entity['type'] ?? '')) {
    'PERSON' => 'Person',
    'ORG', 'ORGANIZATION' => 'Organization',
    'LOCATION' => 'Place',
    default => 'Thing',
};
$entitySchema = [
    '@context' => 'https://schema.org',
    '@type' => $entitySchemaType,
    'name' => $entity['name'],
    'url' => 'https://epsteinsuite.com/entity.php?id=' . (int)$entity['id'],
    'description' => $entity['name'] . ' is mentioned in ' . number_format($totalDocs) . ' documents in the Epstein Suite archive.',
];
if (!empty($relatedEntities)) {
    $entitySchema['relatedTo'] = array_map(function ($re) {
        return [
            '@type' => match (strtoupper($re['type'] ?? '')) {
                'PERSON' => 'Person',
                'ORG', 'ORGANIZATION' => 'Organization',
                default => 'Thing',
            },
            'name' => $re['name'],
            'url' => 'https://epsteinsuite.com/entity.php?id=' . (int)$re['id'],
        ];
    }, array_slice($relatedEntities, 0, 10));
}
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($entitySchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Search', 'item' => 'https://epsteinsuite.com/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Contacts', 'item' => 'https://epsteinsuite.com/contacts.php'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $entity['name']],
    ]
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once __DIR__ . '/includes/header_suite.php';

$totalPages = ceil($totalDocs / $limit);

// Entity type styling
$typeColors = [
    'PERSON' => 'bg-purple-100 text-purple-700 border-purple-200',
    'ORG' => 'bg-blue-100 text-blue-700 border-blue-200',
    'ORGANIZATION' => 'bg-blue-100 text-blue-700 border-blue-200',
    'LOCATION' => 'bg-green-100 text-green-700 border-green-200',
];
$typeIcons = [
    'PERSON' => '👤',
    'ORG' => '🏢',
    'ORGANIZATION' => '🏢',
    'LOCATION' => '📍',
];
$typeColor = $typeColors[$entity['type']] ?? 'bg-slate-100 text-slate-700 border-slate-200';
$typeIcon = $typeIcons[$entity['type']] ?? '📌';
?>

<main class="flex-1 w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Breadcrumb -->
    <nav class="text-sm text-slate-500 mb-6">
        <a href="/" class="hover:text-blue-600">Search</a>
        <span class="mx-2">›</span>
        <a href="/contacts.php" class="hover:text-blue-600">Contacts</a>
        <span class="mx-2">›</span>
        <span class="text-slate-800"><?= htmlspecialchars($entity['name']) ?></span>
    </nav>

    <!-- Entity Header -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-8">
        <div class="flex items-start gap-4">
            <div class="w-16 h-16 rounded-xl <?= $typeColor ?> border flex items-center justify-center text-3xl flex-shrink-0">
                <?= $typeIcon ?>
            </div>
            <div class="flex-1">
                <h1 class="text-2xl font-bold text-slate-900 mb-1"><?= htmlspecialchars($entity['name']) ?></h1>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $typeColor ?> border">
                        <?= htmlspecialchars($entity['type']) ?>
                    </span>
                    <span class="text-sm text-slate-500">
                        Appears in <strong><?= number_format($totalDocs) ?></strong> documents
                    </span>
                </div>
            </div>
            <div class="flex-shrink-0">
                <a href="/?q=<?= urlencode($entity['name']) ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    Search All
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Documents List -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200">
                    <h2 class="font-semibold text-slate-900">Documents (<?= number_format($totalDocs) ?>)</h2>
                </div>
                
                <?php if (empty($documents)): ?>
                    <div class="p-6 text-center text-slate-500">
                        No documents found for this entity.
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($documents as $doc): ?>
                            <a href="/document.php?id=<?= $doc['id'] ?>" class="block p-4 hover:bg-slate-50 transition-colors">
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
                                        <h3 class="font-medium text-slate-900 truncate"><?= htmlspecialchars($doc['title']) ?></h3>
                                        <?php if (!empty($doc['ai_summary'])): ?>
                                            <p class="text-sm text-slate-500 line-clamp-2 mt-1"><?= htmlspecialchars(substr($doc['ai_summary'], 0, 150)) ?>...</p>
                                        <?php endif; ?>
                                        <div class="flex items-center gap-3 mt-2 text-xs text-slate-400">
                                            <span><?= htmlspecialchars($doc['data_set'] ?? 'Unknown') ?></span>
                                            <?php if ($doc['mention_count'] > 1): ?>
                                                <span class="text-purple-600"><?= $doc['mention_count'] ?> mentions</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
                            <div class="text-sm text-slate-500">
                                Page <?= $page ?> of <?= $totalPages ?>
                            </div>
                            <div class="flex gap-2">
                                <?php if ($page > 1): ?>
                                    <a href="?id=<?= $entityId ?>&p=<?= $page - 1 ?>" class="px-3 py-1 bg-slate-100 text-slate-700 rounded hover:bg-slate-200 text-sm">← Prev</a>
                                <?php endif; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="?id=<?= $entityId ?>&p=<?= $page + 1 ?>" class="px-3 py-1 bg-slate-100 text-slate-700 rounded hover:bg-slate-200 text-sm">Next →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            
            <!-- Related Entities -->
            <?php if (!empty($relatedEntities)): ?>
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-200">
                    <h3 class="font-semibold text-slate-900 text-sm">Related Entities</h3>
                    <p class="text-xs text-slate-500">Appear in same documents</p>
                </div>
                <div class="p-4 space-y-2 max-h-96 overflow-y-auto">
                    <?php foreach ($relatedEntities as $re): 
                        $reColor = $typeColors[$re['type']] ?? 'bg-slate-100 text-slate-600';
                        $reIcon = $typeIcons[$re['type']] ?? '📌';
                    ?>
                        <a href="/entity.php?id=<?= $re['id'] ?>" class="flex items-center gap-2 p-2 rounded-lg hover:bg-slate-50 transition-colors">
                            <span class="text-sm"><?= $reIcon ?></span>
                            <span class="flex-1 text-sm text-slate-800 truncate"><?= htmlspecialchars($re['name']) ?></span>
                            <span class="text-xs text-slate-400"><?= $re['shared_docs'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="bg-white rounded-2xl border border-slate-200 p-4">
                <h3 class="font-semibold text-slate-900 text-sm mb-3">Quick Actions</h3>
                <div class="space-y-2">
                    <a href="/?q=<?= urlencode($entity['name']) ?>" class="flex items-center gap-2 p-2 rounded-lg hover:bg-slate-50 transition-colors text-sm text-slate-700">
                        <span>🔍</span> Search all mentions
                    </a>
                    <a href="/contacts.php?type=<?= urlencode($entity['type']) ?>" class="flex items-center gap-2 p-2 rounded-lg hover:bg-slate-50 transition-colors text-sm text-slate-700">
                        <span>👥</span> Browse all <?= strtolower($entity['type']) ?>s
                    </a>
                    <a href="/drive.php?q=<?= urlencode($entity['name']) ?>" class="flex items-center gap-2 p-2 rounded-lg hover:bg-slate-50 transition-colors text-sm text-slate-700">
                        <span>📂</span> Search in Drive
                    </a>
                </div>
            </div>

        </div>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
