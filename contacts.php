<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$type = $_GET['type'] ?? 'all';
$search = trim($_GET['q'] ?? '');

// Normalize type map for consistent display
$typeMap = [
    'PERSON' => 'Person',
    'ORG' => 'Organization',
    'ORGANIZATION' => 'Organization',
    'LOCATION' => 'Location',
];

function normalizeType(string $raw, array $map): string {
    $upper = strtoupper(trim($raw));
    return $map[$upper] ?? ucfirst(strtolower($raw));
}

try {
    $pdo = db();

    // --- Sidebar counts (cached 10 min) ---
    $typeCounts = Cache::remember('contacts_type_counts', function () use ($pdo, $typeMap): array {
        $stmt = $pdo->query("
            SELECT UPPER(type) AS utype, COUNT(*) AS cnt
            FROM entities
            GROUP BY UPPER(type)
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = ['all' => 0, 'person' => 0, 'organization' => 0, 'location' => 0];
        foreach ($rows as $row) {
            $normalized = $typeMap[$row['utype']] ?? ucfirst(strtolower($row['utype']));
            $key = strtolower($normalized);
            if (isset($counts[$key])) {
                $counts[$key] += (int)$row['cnt'];
            }
            $counts['all'] += (int)$row['cnt'];
        }
        return $counts;
    }, 600);

    // --- Build WHERE clause ---
    $where = [];
    $params = [];

    if ($type !== 'all') {
        // Match against normalized type values
        $matchTypes = [];
        foreach ($typeMap as $raw => $normalized) {
            if (strtolower($normalized) === strtolower($type)) {
                $matchTypes[] = $raw;
            }
        }
        if ($matchTypes) {
            $placeholders = [];
            foreach ($matchTypes as $i => $mt) {
                $key = "type_{$i}";
                $placeholders[] = ":$key";
                $params[$key] = $mt;
            }
            $where[] = "UPPER(e.type) IN (" . implode(',', $placeholders) . ")";
        }
    }

    if ($search !== '') {
        $where[] = "e.name LIKE :search";
        $params['search'] = "%{$search}%";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // --- Get entities ---
    $sql = "
        SELECT e.id, e.name, e.type, COUNT(de.document_id) AS doc_count
        FROM entities e
        LEFT JOIN document_entities de ON e.id = de.entity_id
        $whereClause
        GROUP BY e.id
        ORDER BY doc_count DESC, e.name ASC
        LIMIT $limit OFFSET $offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Total count for pagination ---
    $countSql = "SELECT COUNT(*) FROM entities e $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = (int)ceil($total / $limit);

    // --- Fetch top co-mention for each entity on this page (cached per page) ---
    $coMentionMap = [];
    if ($contacts) {
        $entityIds = array_column($contacts, 'id');
        sort($entityIds);
        $coCacheKey = 'contacts_co_' . md5(implode(',', $entityIds));

        $coMentionMap = Cache::remember($coCacheKey, function () use ($pdo, $entityIds): array {
            $idPlaceholders = [];
            $coParams = [];
            foreach ($entityIds as $i => $eid) {
                $key = "eid_{$i}";
                $idPlaceholders[] = ":$key";
                $coParams[$key] = $eid;
            }
            $inClause = implode(',', $idPlaceholders);

            // Use a subquery to get only the top co-mention per entity efficiently
            $coSql = "
                SELECT de1.entity_id AS source_id, co.name AS co_name, COUNT(DISTINCT de1.document_id) AS shared
                FROM document_entities de1
                JOIN document_entities de2 ON de1.document_id = de2.document_id AND de1.entity_id != de2.entity_id
                JOIN entities co ON co.id = de2.entity_id
                WHERE de1.entity_id IN ($inClause)
                GROUP BY de1.entity_id, co.id
                ORDER BY de1.entity_id, shared DESC
            ";
            $stmt = $pdo->prepare($coSql);
            $stmt->execute($coParams);
            $coRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($coRows as $row) {
                $sid = $row['source_id'];
                if (!isset($map[$sid])) {
                    $map[$sid] = $row['co_name'];
                }
            }
            return $map;
        }, 600);
    }

    // Normalize types for display
    foreach ($contacts as &$c) {
        $c['display_type'] = normalizeType($c['type'], $typeMap);
        $c['co_mention'] = $coMentionMap[$c['id']] ?? null;
    }
    unset($c);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// URL builder that preserves filters
function contactsUrl(array $overrides = []): string {
    global $type, $search, $page;
    $params = [
        'type' => $overrides['type'] ?? $type,
        'q' => $overrides['q'] ?? $search,
        'page' => $overrides['page'] ?? $page,
    ];
    if ($params['q'] === '') unset($params['q']);
    if ($params['type'] === 'all') unset($params['type']);
    if ((int)$params['page'] <= 1) unset($params['page']);
    return '?' . http_build_query($params);
}

$lock_body_scroll = true;
$page_title = 'Contacts &amp; Entities';
$meta_description = 'Browse ' . number_format($typeCounts['all']) . ' people, organizations, and locations extracted from Epstein-related documents.';
$og_title = 'Epstein Contacts — ' . number_format($typeCounts['all']) . ' Entities Directory';
$extra_head_tags = [];
$collectionSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Epstein Contacts & Entities',
    'description' => 'Directory of ' . number_format($typeCounts['all']) . ' people, organizations, and locations extracted from Epstein-related documents.',
    'url' => 'https://epsteinsuite.com/contacts.php',
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($collectionSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex flex-1 overflow-hidden bg-white">
    <!-- Sidebar (Desktop) -->
    <aside class="w-64 flex-shrink-0 flex flex-col py-4 pr-4 border-r border-gray-200 hidden md:flex">
        <div class="px-4 mb-4">
            <form method="GET" class="relative">
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                </div>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                    class="block w-full pl-9 pr-3 py-2 border border-slate-200 rounded-lg text-sm bg-slate-50 placeholder-slate-400 focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Search entities...">
            </form>
        </div>

        <nav class="flex-1 space-y-1">
            <a href="<?= contactsUrl(['type' => 'all', 'page' => 1]) ?>" class="flex items-center justify-between px-6 py-2 rounded-r-full text-sm font-medium <?= $type === 'all' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <span class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                    All Contacts
                </span>
                <span class="text-xs font-bold tabular-nums"><?= number_format($typeCounts['all']) ?></span>
            </a>
            <a href="<?= contactsUrl(['type' => 'person', 'page' => 1]) ?>" class="flex items-center justify-between px-6 py-2 rounded-r-full text-sm font-medium <?= $type === 'person' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <span class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                    People
                </span>
                <span class="text-xs font-bold tabular-nums"><?= number_format($typeCounts['person']) ?></span>
            </a>
            <a href="<?= contactsUrl(['type' => 'organization', 'page' => 1]) ?>" class="flex items-center justify-between px-6 py-2 rounded-r-full text-sm font-medium <?= $type === 'organization' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <span class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                    Organizations
                </span>
                <span class="text-xs font-bold tabular-nums"><?= number_format($typeCounts['organization']) ?></span>
            </a>
            <a href="<?= contactsUrl(['type' => 'location', 'page' => 1]) ?>" class="flex items-center justify-between px-6 py-2 rounded-r-full text-sm font-medium <?= $type === 'location' ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <span class="flex items-center gap-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    Locations
                </span>
                <span class="text-xs font-bold tabular-nums"><?= number_format($typeCounts['location']) ?></span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-hidden relative">
        <!-- Mobile Toolbar -->
        <div class="md:hidden border-b border-gray-200 bg-white px-4 py-3">
            <div class="flex items-center gap-2 overflow-x-auto pb-2">
                <a href="<?= contactsUrl(['type' => 'all', 'page' => 1]) ?>" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border <?= $type === 'all' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">All <span class="opacity-70"><?= number_format($typeCounts['all']) ?></span></a>
                <a href="<?= contactsUrl(['type' => 'person', 'page' => 1]) ?>" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border <?= $type === 'person' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">People <span class="opacity-70"><?= number_format($typeCounts['person']) ?></span></a>
                <a href="<?= contactsUrl(['type' => 'organization', 'page' => 1]) ?>" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border <?= $type === 'organization' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Orgs <span class="opacity-70"><?= number_format($typeCounts['organization']) ?></span></a>
                <a href="<?= contactsUrl(['type' => 'location', 'page' => 1]) ?>" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border <?= $type === 'location' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Locations <span class="opacity-70"><?= number_format($typeCounts['location']) ?></span></a>
            </div>
            <form method="GET" class="relative">
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                </div>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                    class="block w-full pl-10 pr-3 py-2 border border-slate-200 rounded-lg leading-5 bg-slate-100 placeholder-slate-500 focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 text-sm"
                    placeholder="Search entities...">
            </form>
        </div>

        <?php if ($search !== ''): ?>
            <div class="px-4 py-2 bg-amber-50 border-b border-amber-200 flex items-center justify-between text-sm">
                <span class="text-amber-800">Showing results for "<strong><?= htmlspecialchars($search) ?></strong>" &mdash; <?= number_format($total) ?> found</span>
                <a href="<?= contactsUrl(['q' => '', 'page' => 1]) ?>" class="text-amber-700 hover:text-amber-900 font-medium">Clear</a>
            </div>
        <?php endif; ?>

        <!-- List Header (Desktop) -->
        <div class="h-12 border-b border-gray-200 items-center px-4 bg-white text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:flex">
            <div class="w-2/5 pl-12">Name</div>
            <div class="w-1/6">Type</div>
            <div class="w-1/6">Mentions</div>
            <div class="w-1/4">Frequently With</div>
        </div>

        <!-- Contacts List -->
        <div class="flex-1 overflow-y-auto" id="contacts-list">
            <?php if (empty($contacts)): ?>
                <div class="flex flex-col items-center justify-center h-full text-gray-400 py-20">
                    <svg class="w-16 h-16 mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                    <p class="text-lg font-medium mb-1">No entities found</p>
                    <p class="text-sm">Try a different search or filter.</p>
                </div>
            <?php else: ?>
                <!-- Mobile Cards -->
                <div class="md:hidden divide-y divide-gray-100">
                    <?php foreach ($contacts as $contact):
                        $initial = mb_substr($contact['name'], 0, 1);
                        $typeColors = ['Person' => 'bg-purple-600', 'Organization' => 'bg-blue-600', 'Location' => 'bg-emerald-600'];
                        $colorClass = $typeColors[$contact['display_type']] ?? 'bg-gray-600';
                    ?>
                        <div class="block px-4 py-3 bg-white hover:bg-slate-50 cursor-pointer entity-row" data-entity-id="<?= (int)$contact['id'] ?>">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full <?= $colorClass ?> text-white flex items-center justify-center font-bold text-sm flex-shrink-0">
                                    <?= strtoupper($initial) ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($contact['name']) ?></div>
                                    <div class="flex items-center gap-2 mt-1">
                                        <?php
                                        $badgeColors = ['Person' => 'bg-purple-100 text-purple-700', 'Organization' => 'bg-blue-100 text-blue-700', 'Location' => 'bg-emerald-100 text-emerald-700'];
                                        $badge = $badgeColors[$contact['display_type']] ?? 'bg-gray-100 text-gray-700';
                                        ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>"><?= htmlspecialchars($contact['display_type']) ?></span>
                                        <span class="text-xs text-slate-500"><?= number_format((int)$contact['doc_count']) ?> docs</span>
                                    </div>
                                    <?php if ($contact['co_mention']): ?>
                                        <div class="text-xs text-slate-400 mt-1 truncate">Frequently with: <?= htmlspecialchars($contact['co_mention']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <svg class="w-5 h-5 text-slate-300 flex-shrink-0 mt-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Desktop Table -->
                <table class="w-full text-sm hidden md:table">
                    <tbody>
                        <?php foreach ($contacts as $contact):
                            $initial = mb_substr($contact['name'], 0, 1);
                            $typeColors = ['Person' => 'bg-purple-600', 'Organization' => 'bg-blue-600', 'Location' => 'bg-emerald-600'];
                            $colorClass = $typeColors[$contact['display_type']] ?? 'bg-gray-600';
                            $badgeColors = ['Person' => 'bg-purple-100 text-purple-700', 'Organization' => 'bg-blue-100 text-blue-700', 'Location' => 'bg-emerald-100 text-emerald-700'];
                            $badge = $badgeColors[$contact['display_type']] ?? 'bg-gray-100 text-gray-700';
                        ?>
                            <tr class="group hover:bg-slate-50 border-b border-gray-100 cursor-pointer transition-colors entity-row" data-entity-id="<?= (int)$contact['id'] ?>">
                                <td class="pl-4 py-2.5 w-2/5">
                                    <div class="flex items-center gap-4">
                                        <div class="w-8 h-8 rounded-full <?= $colorClass ?> text-white flex items-center justify-center font-medium text-sm flex-shrink-0">
                                            <?= strtoupper($initial) ?>
                                        </div>
                                        <span class="text-gray-900 font-medium truncate"><?= htmlspecialchars($contact['name']) ?></span>
                                    </div>
                                </td>
                                <td class="py-2.5 w-1/6">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badge ?>"><?= htmlspecialchars($contact['display_type']) ?></span>
                                </td>
                                <td class="py-2.5 w-1/6">
                                    <span class="text-gray-700 font-medium"><?= number_format((int)$contact['doc_count']) ?></span>
                                    <span class="text-gray-400 ml-1">docs</span>
                                </td>
                                <td class="py-2.5 w-1/4 text-gray-500 text-xs truncate pr-4">
                                    <?php if ($contact['co_mention']): ?>
                                        <?= htmlspecialchars($contact['co_mention']) ?>
                                    <?php else: ?>
                                        <span class="text-gray-300">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Footer / Pagination -->
        <?php if ($total > 0): ?>
        <div class="h-14 border-t border-gray-200 flex items-center justify-between px-6 bg-white flex-shrink-0">
            <span class="text-sm text-gray-500">
                <?= number_format($offset + 1) ?>&ndash;<?= number_format(min($offset + $limit, $total)) ?> of <?= number_format($total) ?>
            </span>
            <div class="flex items-center gap-1">
                <?php if ($page > 1): ?>
                    <a href="<?= contactsUrl(['page' => $page - 1]) ?>" class="p-2 hover:bg-gray-100 rounded-full text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                <?php else: ?>
                    <span class="p-2 opacity-30"><svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg></span>
                <?php endif; ?>

                <span class="text-xs text-gray-500 px-2">Page <?= $page ?> of <?= $totalPages ?></span>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= contactsUrl(['page' => $page + 1]) ?>" class="p-2 hover:bg-gray-100 rounded-full text-gray-600">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </a>
                <?php else: ?>
                    <span class="p-2 opacity-30"><svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Detail Panel (Slide-in) -->
    <div id="detail-panel" class="fixed inset-0 z-50 hidden">
        <!-- Backdrop -->
        <div id="detail-backdrop" class="absolute inset-0 bg-black/30 backdrop-blur-sm"></div>

        <!-- Panel -->
        <div id="detail-drawer" class="absolute right-0 top-0 bottom-0 w-full max-w-md bg-white shadow-2xl border-l border-gray-200 transform translate-x-full transition-transform duration-300 ease-out flex flex-col md:top-0">
            <!-- Panel Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 flex-shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                    <div id="detail-avatar" class="w-10 h-10 rounded-full bg-gray-400 text-white flex items-center justify-center font-bold text-lg flex-shrink-0"></div>
                    <div class="min-w-0">
                        <h2 id="detail-name" class="text-lg font-semibold text-slate-900 truncate"></h2>
                        <span id="detail-type-badge" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"></span>
                    </div>
                </div>
                <button id="detail-close" class="p-2 hover:bg-gray-100 rounded-full text-gray-500">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Panel Body -->
            <div id="detail-body" class="flex-1 overflow-y-auto">
                <!-- Loading spinner -->
                <div id="detail-loading" class="flex items-center justify-center py-20">
                    <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>

                <!-- Content (hidden until loaded) -->
                <div id="detail-content" class="hidden">
                    <!-- Doc count stat -->
                    <div class="px-6 py-4 border-b border-gray-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            </div>
                            <div>
                                <div id="detail-doc-count" class="text-2xl font-bold text-slate-900"></div>
                                <div class="text-xs text-slate-500">document mentions</div>
                            </div>
                        </div>
                    </div>

                    <!-- Co-mentioned entities -->
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Frequently Mentioned With</h3>
                        <div id="detail-co-mentions" class="space-y-2"></div>
                        <div id="detail-no-co-mentions" class="hidden text-sm text-slate-400">No co-mentioned entities found.</div>
                    </div>

                    <!-- Recent documents -->
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Recent Documents</h3>
                        <div id="detail-recent-docs" class="space-y-3"></div>
                        <div id="detail-no-docs" class="hidden text-sm text-slate-400">No processed documents found.</div>
                    </div>

                    <!-- Action buttons -->
                    <div class="px-6 py-4">
                        <a id="detail-search-link" href="#" class="flex items-center justify-center gap-2 w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            Search All Documents
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const panel = document.getElementById('detail-panel');
    const drawer = document.getElementById('detail-drawer');
    const backdrop = document.getElementById('detail-backdrop');
    const closeBtn = document.getElementById('detail-close');
    const loading = document.getElementById('detail-loading');
    const content = document.getElementById('detail-content');

    const typeAvatarColors = { Person: 'bg-purple-600', Organization: 'bg-blue-600', Location: 'bg-emerald-600' };
    const typeBadgeColors = { Person: 'bg-purple-100 text-purple-700', Organization: 'bg-blue-100 text-blue-700', Location: 'bg-emerald-100 text-emerald-700' };

    function openPanel(entityId) {
        panel.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        loading.classList.remove('hidden');
        content.classList.add('hidden');

        requestAnimationFrame(() => {
            drawer.classList.remove('translate-x-full');
        });

        fetch('/api/entity_detail.php?id=' + entityId)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    loading.innerHTML = '<p class="text-red-500 text-sm">' + data.error + '</p>';
                    return;
                }
                renderPanel(data);
                loading.classList.add('hidden');
                content.classList.remove('hidden');
            })
            .catch(() => {
                loading.innerHTML = '<p class="text-red-500 text-sm">Failed to load details.</p>';
            });
    }

    function closePanel() {
        drawer.classList.add('translate-x-full');
        setTimeout(() => {
            panel.classList.add('hidden');
            document.body.style.overflow = '';
            loading.innerHTML = `<svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>`;
        }, 300);
    }

    function renderPanel(data) {
        const e = data.entity;
        const initial = e.name.charAt(0).toUpperCase();
        const avatarColor = typeAvatarColors[e.type] || 'bg-gray-600';
        const badgeColor = typeBadgeColors[e.type] || 'bg-gray-100 text-gray-700';

        document.getElementById('detail-avatar').className = 'w-10 h-10 rounded-full text-white flex items-center justify-center font-bold text-lg flex-shrink-0 ' + avatarColor;
        document.getElementById('detail-avatar').textContent = initial;
        document.getElementById('detail-name').textContent = e.name;

        const badge = document.getElementById('detail-type-badge');
        badge.className = 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ' + badgeColor;
        badge.textContent = e.type;

        document.getElementById('detail-doc-count').textContent = data.doc_count.toLocaleString();
        document.getElementById('detail-search-link').href = '/?q=' + encodeURIComponent(e.name);

        // Co-mentions
        const coEl = document.getElementById('detail-co-mentions');
        const noCo = document.getElementById('detail-no-co-mentions');
        coEl.innerHTML = '';

        if (data.top_co_mentions && data.top_co_mentions.length > 0) {
            noCo.classList.add('hidden');
            data.top_co_mentions.forEach(cm => {
                const cmBadge = typeBadgeColors[cm.type] || 'bg-gray-100 text-gray-700';
                const cmAvatar = typeAvatarColors[cm.type] || 'bg-gray-600';
                const div = document.createElement('div');
                div.className = 'flex items-center justify-between py-1.5 px-2 rounded-lg hover:bg-slate-50 cursor-pointer entity-row';
                div.dataset.entityId = cm.id;
                div.innerHTML = `
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-7 h-7 rounded-full ${cmAvatar} text-white flex items-center justify-center font-medium text-xs flex-shrink-0">${cm.name.charAt(0).toUpperCase()}</div>
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-slate-800 truncate">${escapeHtml(cm.name)}</div>
                            <span class="inline-flex items-center px-1.5 py-0 rounded-full text-[10px] font-medium ${cmBadge}">${escapeHtml(cm.type)}</span>
                        </div>
                    </div>
                    <span class="text-xs text-slate-400 flex-shrink-0 ml-2">${cm.shared_docs} shared</span>
                `;
                div.addEventListener('click', () => openPanel(cm.id));
                coEl.appendChild(div);
            });
        } else {
            noCo.classList.remove('hidden');
        }

        // Recent documents
        const docsEl = document.getElementById('detail-recent-docs');
        const noDocs = document.getElementById('detail-no-docs');
        docsEl.innerHTML = '';

        if (data.recent_documents && data.recent_documents.length > 0) {
            noDocs.classList.add('hidden');
            data.recent_documents.forEach(doc => {
                const a = document.createElement('a');
                a.href = '/drive.php?doc=' + doc.id;
                a.className = 'block p-3 rounded-lg border border-gray-100 hover:border-blue-200 hover:bg-blue-50/50 transition-colors';
                const dateStr = doc.document_date ? new Date(doc.document_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '';
                const snippet = doc.ai_summary_snippet ? escapeHtml(doc.ai_summary_snippet.substring(0, 150)) + (doc.ai_summary_snippet.length > 150 ? '...' : '') : '';
                a.innerHTML = `
                    <div class="text-sm font-medium text-slate-800 truncate">${escapeHtml(doc.title || 'Untitled')}</div>
                    ${dateStr ? `<div class="text-xs text-slate-400 mt-0.5">${dateStr}${doc.file_type ? ' &middot; ' + escapeHtml(doc.file_type.toUpperCase()) : ''}</div>` : ''}
                    ${snippet ? `<div class="text-xs text-slate-500 mt-1 line-clamp-2">${snippet}</div>` : ''}
                `;
                docsEl.appendChild(a);
            });
        } else {
            noDocs.classList.remove('hidden');
        }
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // Event delegation for entity rows
    document.addEventListener('click', function(e) {
        const row = e.target.closest('.entity-row');
        if (row && row.dataset.entityId) {
            e.preventDefault();
            openPanel(parseInt(row.dataset.entityId));
        }
    });

    backdrop.addEventListener('click', closePanel);
    closeBtn.addEventListener('click', closePanel);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !panel.classList.contains('hidden')) {
            closePanel();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
