<?php
// insights.php - Entity Relationship Visualization
require_once __DIR__ . '/includes/db.php';
$pdo = db();

try {
    // 1. Fetch Top Entities (Nodes)
    // Get the top 50 most mentioned entities of type PERSON or ORG
    $nodesSql = "
        SELECT e.id, e.name, e.type, COUNT(de.document_id) as mentions
        FROM entities e
        JOIN document_entities de ON e.id = de.entity_id
        WHERE e.type IN ('PERSON', 'ORG')
        GROUP BY e.id
        ORDER BY mentions DESC
        LIMIT 40
    ";
    $nodes = $pdo->query($nodesSql)->fetchAll(PDO::FETCH_ASSOC);

    // Create lookup for valid node IDs
    $validNodeIds = array_column($nodes, 'id');

    // 2. Fetch Connections (Edges)
    // A connection exists if two entities appear in the same document
    $edges = [];
    if (!empty($validNodeIds)) {
        $idsStr = implode(',', $validNodeIds);
        $edgesSql = "
            SELECT de1.entity_id as source, de2.entity_id as target, COUNT(DISTINCT de1.document_id) as weight
            FROM document_entities de1
            JOIN document_entities de2 ON de1.document_id = de2.document_id
            WHERE de1.entity_id IN ($idsStr)
              AND de2.entity_id IN ($idsStr)
              AND de1.entity_id < de2.entity_id -- Avoid duplicates and self-loops
            GROUP BY source, target
            HAVING weight >= 1
            ORDER BY weight DESC
            LIMIT 100
        ";
        $edges = $pdo->query($edgesSql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // Format for D3
    $graphData = [
        'nodes' => array_map(function ($n) {
            return [
                'id' => (int) $n['id'],
                'name' => $n['name'],
                'group' => $n['type'] === 'PERSON' ? 1 : 2,
                'val' => (int) $n['mentions']
            ];
        }, $nodes),
        'links' => array_map(function ($e) {
            return [
                'source' => (int) $e['source'],
                'target' => (int) $e['target'],
                'value' => (int) $e['weight']
            ];
        }, $edges)
    ];

    $totalNodes = count($graphData['nodes']);
    $totalLinks = count($graphData['links']);
    $topEntity = $totalNodes > 0 ? $graphData['nodes'][0] : null;
    $strongestLink = $totalLinks > 0 ? $graphData['links'][0] : null;
    $topPeople = array_slice(array_values(array_filter($graphData['nodes'], fn($n) => $n['group'] === 1)), 0, 6);
    $topOrganizations = array_slice(array_values(array_filter($graphData['nodes'], fn($n) => $n['group'] === 2)), 0, 6);
    $topPairs = array_slice($graphData['links'], 0, 8);

} catch (Exception $e) {
    $error = $e->getMessage();
    $graphData = ['nodes' => [], 'links' => []];
    $totalNodes = 0;
    $totalLinks = 0;
    $topEntity = null;
    $strongestLink = null;
    $topPeople = [];
    $topOrganizations = [];
    $topPairs = [];
}

$page_title = 'Entity Network';
$meta_description = 'Interactive entity relationship graph showing connections between people and organizations mentioned across Epstein-related DOJ documents. Powered by D3.js visualization.';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-1 bg-slate-50">
    <div class="max-w-7xl mx-auto px-4 lg:px-8 py-6 h-[calc(100vh-6rem)] flex flex-col">
        <header class="mb-4">
            <p class="text-xs uppercase tracking-[0.3em] text-slate-400 mb-2">Entity Insights</p>
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-slate-900">Who appears together?</h1>
                    <p class="text-slate-500 max-w-2xl">
                        The cards summarize the most-cited people and organizations from the DOJ files. The mini-map shows
                        which pairs are mentioned together most often. Use this to decide which names to investigate first
                        before diving into documents.
                    </p>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl px-5 py-4 shadow-sm">
                    <p class="text-xs text-slate-500 uppercase tracking-wide">How to read it</p>
                    <ul class="mt-2 text-sm text-slate-600 space-y-1">
                        <li>• <strong>Circle size</strong> = frequency of mentions.</li>
                        <li>• <span class="text-blue-600 font-semibold">Blue</span> = person, <span class="text-pink-500 font-semibold">pink</span> = organization.</li>
                        <li>• <strong>Line thickness</strong> = how many documents cite both parties.</li>
                    </ul>
                </div>
            </div>
        </header>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <article class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs text-slate-500 uppercase tracking-wide mb-2">Entities Tracked</p>
                <div class="text-3xl font-black text-slate-900"><?= number_format($totalNodes) ?></div>
                <p class="text-sm text-slate-500">People and organizations with the most mentions in released files.</p>
            </article>
            <article class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs text-slate-500 uppercase tracking-wide mb-2">Strongest Connection</p>
                <?php if ($strongestLink && $totalNodes > 0):
                    $sourceNode = array_values(array_filter($graphData['nodes'], fn($n) => $n['id'] === $strongestLink['source']))[0] ?? null;
                    $targetNode = array_values(array_filter($graphData['nodes'], fn($n) => $n['id'] === $strongestLink['target']))[0] ?? null;
                ?>
                    <div class="text-lg font-semibold text-slate-900">
                        <?= htmlspecialchars($sourceNode['name'] ?? 'Unknown') ?> → <?= htmlspecialchars($targetNode['name'] ?? 'Unknown') ?>
                    </div>
                    <p class="text-sm text-slate-500">Appear together in <?= (int)$strongestLink['value'] ?> documents.</p>
                <?php else: ?>
                    <div class="text-2xl font-semibold text-slate-900">n/a</div>
                    <p class="text-sm text-slate-500">Need more entity data to show overlaps.</p>
                <?php endif; ?>
            </article>
            <article class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs text-slate-500 uppercase tracking-wide mb-2">Most Mentioned</p>
                <?php if ($topEntity): ?>
                    <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($topEntity['name']) ?></div>
                    <p class="text-sm text-slate-500"><?= (int)$topEntity['val'] ?> mentions across the archive.</p>
                <?php else: ?>
                    <div class="text-2xl font-semibold text-slate-900">n/a</div>
                    <p class="text-sm text-slate-500">Entities will appear once AI summaries complete.</p>
                <?php endif; ?>
            </article>
        </section>

        <section class="flex-1 grid grid-cols-1 lg:grid-cols-2 gap-4 overflow-hidden">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm flex flex-col">
                <h2 class="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-3">Takeaways</h2>
                <ul class="space-y-3 text-sm text-slate-600 flex-1 overflow-auto">
                    <li>• Start by searching the top-mentioned names above; each opens semantic search with a single click.</li>
                    <li>• Use the “Strongest Connection” pair as a jumping-off point to review the overlapping documents.</li>
                    <li>• Each line in the graph represents co-mentions inside individual filings—follow the thicker links first.</li>
                    <li>• Missing someone? Run AI enrichment again or open Contacts to browse the full list.</li>
                </ul>
                <div class="mt-4">
                    <a href="/search.php" class="inline-flex items-center px-4 py-2 rounded-full bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                        Search these names →
                    </a>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
                <h2 class="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-3 px-5 pt-5">Top Connections</h2>
                <?php if (empty($topPairs)): ?>
                    <div class="flex-1 flex items-center justify-center text-slate-400 text-sm pb-6">Waiting on entity data to calculate pairings.</div>
                <?php else: ?>
                    <div class="flex-1 overflow-auto">
                        <table class="w-full text-sm text-slate-600">
                            <thead class="text-xs uppercase text-slate-400 border-b border-slate-100">
                                <tr>
                                    <th class="text-left px-5 py-2">Pair</th>
                                    <th class="text-right px-5 py-2">Shared Docs</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topPairs as $pair):
                                    $sourceNode = array_values(array_filter($graphData['nodes'], fn($n) => $n['id'] === $pair['source']))[0] ?? null;
                                    $targetNode = array_values(array_filter($graphData['nodes'], fn($n) => $n['id'] === $pair['target']))[0] ?? null;
                                ?>
                                <tr class="border-b border-slate-50 hover:bg-slate-50/50">
                                    <td class="px-5 py-3">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars($sourceNode['name'] ?? 'Unknown') ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($targetNode['name'] ?? 'Unknown') ?></div>
                                    </td>
                                    <td class="px-5 py-3 text-right font-semibold text-slate-900"><?= (int)$pair['value'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-3">Top People</h3>
                <?php if (empty($topPeople)): ?>
                    <p class="text-sm text-slate-500">Run entity extraction to populate this list.</p>
                <?php else: ?>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <?php foreach ($topPeople as $person): ?>
                            <li class="flex items-center justify-between">
                                <span><?= htmlspecialchars($person['name']) ?></span>
                                <span class="text-slate-400"><?= (int)$person['val'] ?> mentions</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-600 uppercase tracking-wide mb-3">Top Organizations</h3>
                <?php if (empty($topOrganizations)): ?>
                    <p class="text-sm text-slate-500">Run entity extraction to populate this list.</p>
                <?php else: ?>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <?php foreach ($topOrganizations as $org): ?>
                            <li class="flex items-center justify-between">
                                <span><?= htmlspecialchars($org['name']) ?></span>
                                <span class="text-slate-400"><?= (int)$org['val'] ?> mentions</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>