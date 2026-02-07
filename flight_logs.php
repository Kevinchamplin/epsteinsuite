<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$search = $_GET['q'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

/**
 * Build a flight_logs URL preserving current filter state with overrides.
 */
function buildFlightUrl(array $overrides = []): string
{
    global $search, $filterAircraft, $filterYear;
    $params = [];
    if ($search) $params['q'] = $search;
    if ($filterYear) $params['year'] = $filterYear;
    if (!empty($filterAircraft)) {
        foreach ($filterAircraft as $i => $ac) {
            $params["aircraft[$i]"] = $ac;
        }
    }
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    // Clean up defaults
    if (empty($params['q'])) unset($params['q']);
    if (empty($params['year'])) unset($params['year']);
    if (($params['page'] ?? 1) <= 1) unset($params['page']);
    return '/flight_logs.php' . ($params ? '?' . http_build_query($params) : '');
}

try {
    $pdo = db();

    // Fetched Filters
    $filterAircraft = $_GET['aircraft'] ?? [];
    $filterYear = $_GET['year'] ?? '';

    // Build WHERE clause (always start with WHERE 1=1 so AND clauses are valid)
    $whereConditions = [];
    $params = [];

    // Search Filter
    if ($search) {
        $whereConditions[] = "(f.origin LIKE :s OR f.destination LIKE :s OR f.aircraft LIKE :s OR p.name LIKE :s)";
        $params['s'] = "%$search%";
    }

    // Aircraft Filter
    if (!empty($filterAircraft) && is_array($filterAircraft)) {
        $placeholders = [];
        foreach ($filterAircraft as $i => $ac) {
            $key = ":ac$i";
            $placeholders[] = $key;
            $params[$key] = $ac;
        }
        $whereConditions[] = "f.aircraft IN (" . implode(',', $placeholders) . ")";
    }

    // Year Filter
    if ($filterYear) {
        $whereConditions[] = "YEAR(f.flight_date) = :year";
        $params['year'] = $filterYear;
    }

    $whereSql = "WHERE 1=1";
    if (!empty($whereConditions)) {
        $whereSql .= " AND " . implode(' AND ', $whereConditions);
    }

    // 1. Map Query - Fetch AI Score & Summary (all matching flights, no pagination)
    $mapSql = "
        SELECT DISTINCT f.origin_lat, f.origin_lng, f.destination_lat, f.destination_lng,
               f.origin, f.destination, f.origin_airport_code, f.destination_airport_code,
               f.origin_city, f.destination_city, f.flight_date, f.aircraft, f.distance_miles,
               f.significance_score, f.ai_summary
        FROM flight_logs f
        LEFT JOIN passengers p ON f.id = p.flight_id
        $whereSql
        AND f.origin_lat IS NOT NULL AND f.destination_lat IS NOT NULL
        ORDER BY f.flight_date DESC
        LIMIT 2000
    ";

    $mapStmt = $pdo->prepare($mapSql);
    $mapStmt->execute($params);
    $mapFlights = $mapStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Count Query (for pagination)
    $countSql = "
        SELECT COUNT(DISTINCT f.id)
        FROM flight_logs f
        LEFT JOIN passengers p ON f.id = p.flight_id
        $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalFlights = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalFlights / $perPage));

    // Clamp page to valid range
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    // 3. Main List Query (with document join and pagination)
    $listSql = "
        SELECT f.*,
               GROUP_CONCAT(p.name SEPARATOR ', ') as passenger_list,
               d.id as doc_id, d.title as doc_title, d.file_type as doc_file_type,
               d.local_path as doc_local_path, d.data_set as doc_data_set
        FROM flight_logs f
        LEFT JOIN passengers p ON f.id = p.flight_id
        LEFT JOIN documents d ON f.document_id = d.id
        $whereSql
        GROUP BY f.id
        ORDER BY f.flight_date DESC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $flights = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Cross-link passengers to entities
    $allPassengerNames = [];
    foreach ($flights as $flight) {
        if (!empty($flight['passenger_list'])) {
            foreach (explode(',', $flight['passenger_list']) as $p) {
                $name = trim($p);
                if ($name) $allPassengerNames[$name] = true;
            }
        }
    }

    // 5. Filters Data (cached — these rarely change)
    $availableAircraft = Cache::remember('flight_available_aircraft', function () use ($pdo): array {
        return $pdo->query("SELECT DISTINCT aircraft FROM flight_logs WHERE aircraft IS NOT NULL AND aircraft != '' ORDER BY aircraft")->fetchAll(PDO::FETCH_COLUMN);
    }, 600);

    $availableYears = Cache::remember('flight_available_years', function () use ($pdo): array {
        return $pdo->query("SELECT DISTINCT YEAR(flight_date) as yr FROM flight_logs WHERE flight_date IS NOT NULL ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);
    }, 600);

    // 6. Top Passengers (cached)
    $topPassengers = Cache::remember('flight_top_passengers', function () use ($pdo): array {
        return $pdo->query("
            SELECT p.name, COUNT(DISTINCT p.flight_id) as flight_count
            FROM passengers p
            INNER JOIN flight_logs f ON f.id = p.flight_id
            GROUP BY p.name
            ORDER BY flight_count DESC
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);
    }, 600);

    // Add top passenger names to the entity lookup pool
    foreach ($topPassengers as $tp) {
        $allPassengerNames[$tp['name']] = true;
    }

    // Batch entity lookup
    $entityMap = []; // name => ['id' => X, 'type' => 'person']
    if (!empty($allPassengerNames)) {
        $names = array_keys($allPassengerNames);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $entityStmt = $pdo->prepare("SELECT id, name, type FROM entities WHERE name IN ($placeholders)");
        $entityStmt->execute($names);
        foreach ($entityStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $entityMap[$e['name']] = $e;
        }
    }

} catch (Exception $e) {
    $error = "Unable to load data: " . $e->getMessage();
    $flights = [];
    $mapFlights = [];
    $totalFlights = 0;
    $totalPages = 1;
    $topPassengers = [];
    $entityMap = [];
    $availableAircraft = [];
    $availableYears = [];
}

$page_title = 'Epstein Flights';
$meta_description = 'Explore Epstein flight logs with interactive maps, passenger manifests, AI significance scoring, and connections to source documents. Filter by aircraft, year, and passenger.';
$og_title = 'Epstein Flight Logs — Interactive Map & Passenger Manifests';
$og_description = 'Explore flight logs with AI significance scores, interactive maps, and passenger data from the DOJ Epstein file releases.';
$lock_body_scroll = true; // Use full height layout
$extra_head_tags = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />'
];
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex flex-1 overflow-hidden bg-white h-[calc(100vh-64px)]">
    <!-- Sidebar Filters -->
    <aside
        class="w-64 flex-shrink-0 flex flex-col py-4 pr-4 border-r border-gray-200 hidden md:flex h-full overflow-y-auto bg-white z-20">
        <form action="" method="GET" class="px-4 mb-4">
            <?php if ($search): ?>
                <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
            <?php endif; ?>

            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-normal text-slate-600">Filters</h2>
                <?php if ($filterAircraft || $filterYear): ?>
                    <a href="/flight_logs.php<?= $search ? '?q=' . urlencode($search) : '' ?>"
                        class="text-xs text-blue-600 hover:underline">Clear all</a>
                <?php endif; ?>
            </div>

            <div class="space-y-6">
                <!-- Legend -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Significance Map</label>
                    <div class="space-y-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-red-500"></span>
                            <span class="text-gray-700">Shocking (Score 9-10)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-orange-500"></span>
                            <span class="text-gray-700">Notable (Score 7-8)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-blue-500"></span>
                            <span class="text-gray-700">Routine Flight</span>
                        </div>
                    </div>
                </div>

                <!-- Year Filter -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Year</label>
                    <select name="year" onchange="this.form.submit()"
                        class="w-full text-sm border-gray-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">All Years</option>
                        <?php foreach ($availableYears as $yr): ?>
                            <option value="<?= $yr ?>" <?= $filterYear == $yr ? 'selected' : '' ?>><?= $yr ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Aircraft Filter -->
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Aircraft</label>
                    <div class="space-y-2">
                        <?php foreach ($availableAircraft as $ac): ?>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="aircraft[]" value="<?= htmlspecialchars($ac) ?>"
                                    <?= (in_array($ac, $filterAircraft)) ? 'checked' : '' ?> onchange="this.form.submit()"
                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm text-gray-700 break-words"><?= htmlspecialchars($ac) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Top Passengers -->
                <?php if (!empty($topPassengers)): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Top Passengers</label>
                    <div class="space-y-1 max-h-64 overflow-y-auto">
                        <?php foreach ($topPassengers as $tp):
                            $isActive = ($search === $tp['name']);
                            $tpEntity = $entityMap[$tp['name']] ?? null;
                        ?>
                            <div class="flex items-center justify-between text-xs py-0.5">
                                <?php if ($tpEntity): ?>
                                    <a href="/entity.php?id=<?= (int)$tpEntity['id'] ?>"
                                       class="text-purple-700 hover:underline truncate flex-1"
                                       title="View entity profile">
                                        <?= htmlspecialchars($tp['name']) ?>
                                    </a>
                                <?php else: ?>
                                    <a href="/flight_logs.php?q=<?= urlencode($tp['name']) ?>"
                                       class="<?= $isActive ? 'text-blue-700 font-bold' : 'text-gray-700 hover:text-blue-600' ?> truncate flex-1">
                                        <?= htmlspecialchars($tp['name']) ?>
                                    </a>
                                <?php endif; ?>
                                <span class="text-gray-400 ml-2 flex-shrink-0"><?= $tp['flight_count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden relative">
        <!-- Map Container -->
        <div id="flight-map" class="h-1/2 w-full border-b border-gray-200 shadow-inner bg-slate-50 relative z-10"></div>

        <!-- Flight List -->
        <div class="h-1/2 overflow-y-auto p-6 bg-gray-50">
            <div class="flex justify-between items-center mb-4 max-w-4xl">
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Flight Manifests
                    (<?= number_format($totalFlights) ?>)</h3>
                <span class="text-xs text-gray-400">Sorted by Date (Newest First)</span>
            </div>

            <?php if (empty($flights)): ?>
                <div class="flex flex-col items-center justify-center h-64 text-gray-400">
                    <p>No flights found matching criteria.</p>
                    <?php if (isset($error)): ?>
                        <p class="text-red-500 text-sm mt-2">Error: <?= htmlspecialchars($error) ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="space-y-4 max-w-4xl">
                    <?php foreach ($flights as $flight): ?>
                        <div
                            class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow relative overflow-hidden"
                            id="flight-<?= $flight['id'] ?>">
                            <!-- Indicator Bar -->
                            <?php
                            $score = $flight['significance_score'] ?? 0;
                            $barColor = 'bg-gray-200';
                            if ($score >= 9)
                                $barColor = 'bg-red-500';
                            elseif ($score >= 7)
                                $barColor = 'bg-orange-500';
                            elseif ($score >= 4)
                                $barColor = 'bg-blue-400';
                            ?>
                            <div class="absolute left-0 top-0 bottom-0 w-1 <?= $barColor ?>"></div>

                            <div class="flex items-center justify-between mb-3 pl-2">
                                <div class="flex items-center gap-4">
                                    <div class="text-lg font-medium text-gray-900 w-32">
                                        <?= date('M d, Y', strtotime($flight['flight_date'])) ?>
                                    </div>
                                    <div class="flex items-center gap-3 text-gray-600">
                                        <div class="flex flex-col">
                                            <span
                                                class="font-medium"><?= htmlspecialchars($flight['origin_city'] ?? $flight['origin']) ?></span>
                                            <span
                                                class="text-xs text-gray-400"><?= htmlspecialchars($flight['origin_airport_code'] ?? '') ?></span>
                                        </div>
                                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M17 8l4 4m0 0l-4 4m4-4H3" />
                                        </svg>
                                        <div class="flex flex-col">
                                            <span
                                                class="font-medium"><?= htmlspecialchars($flight['destination_city'] ?? $flight['destination']) ?></span>
                                            <span
                                                class="text-xs text-gray-400"><?= htmlspecialchars($flight['destination_airport_code'] ?? '') ?></span>
                                        </div>
                                    </div>
                                    <?php if (!empty($flight['distance_miles']) || !empty($flight['flight_duration_hours'])): ?>
                                        <div class="flex items-center gap-2 text-xs text-gray-400 ml-1">
                                            <?php if (!empty($flight['distance_miles'])): ?>
                                                <span><?= number_format((int)$flight['distance_miles']) ?> mi</span>
                                            <?php endif; ?>
                                            <?php if (!empty($flight['flight_duration_hours'])): ?>
                                                <span><?= number_format((float)$flight['flight_duration_hours'], 1) ?> hrs</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php if (!empty($flight['doc_data_set'])): ?>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full <?= str_contains($flight['doc_data_set'], 'JMail') ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600' ?>">
                                            <?= htmlspecialchars($flight['doc_data_set']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($flight['doc_id'])): ?>
                                        <a href="/document.php?id=<?= (int)$flight['doc_id'] ?>"
                                           class="inline-flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 hover:underline"
                                           title="<?= htmlspecialchars($flight['doc_title'] ?? 'Source Document') ?>">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                      d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            Source
                                        </a>
                                    <?php endif; ?>
                                    <div class="text-xs font-mono bg-gray-100 px-2 py-1 rounded text-gray-600">
                                        <?= htmlspecialchars($flight['aircraft']) ?>
                                    </div>
                                </div>
                            </div>

                            <div class="pl-2 ml-36">
                                <?php if (!empty($flight['ai_summary'])): ?>
                                    <div
                                        class="mb-3 p-3 bg-slate-50 rounded-md border border-slate-100 text-sm italic text-slate-600 flex gap-2">
                                        <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                        <div>
                                            <span class="text-xs font-bold text-purple-600 uppercase mr-1">AI Insight</span>
                                            <?= htmlspecialchars($flight['ai_summary']) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($flight['passenger_list']): ?>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach (explode(',', $flight['passenger_list']) as $p):
                                            $pName = trim($p);
                                            $entity = $entityMap[$pName] ?? null;
                                        ?>
                                            <?php if ($entity): ?>
                                                <a href="/entity.php?id=<?= (int)$entity['id'] ?>"
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700 hover:bg-purple-100"
                                                    title="View entity profile">
                                                    <?= htmlspecialchars($pName) ?>
                                                </a>
                                            <?php else: ?>
                                                <a href="/flight_logs.php?q=<?= urlencode($pName) ?>"
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100">
                                                    <?= htmlspecialchars($pName) ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Expand Details Toggle -->
                                <?php
                                $hasDetails = !empty($flight['tail_number']) || !empty($flight['aircraft_type'])
                                    || !empty($flight['flight_purpose']) || !empty($flight['notes'])
                                    || !empty($flight['doc_id']);
                                ?>
                                <?php if ($hasDetails): ?>
                                    <button onclick="toggleFlightDetail(<?= $flight['id'] ?>)"
                                            class="mt-3 text-xs text-slate-500 hover:text-blue-600 flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5 transition-transform" id="chevron-<?= $flight['id'] ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                        <span id="toggle-text-<?= $flight['id'] ?>">Show details</span>
                                    </button>

                                    <div id="detail-<?= $flight['id'] ?>" class="hidden mt-3 border-t border-gray-100 pt-3">
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                                            <?php if (!empty($flight['tail_number'])): ?>
                                                <div>
                                                    <span class="font-bold text-slate-500 uppercase block">Tail Number</span>
                                                    <div class="text-slate-800 font-mono"><?= htmlspecialchars($flight['tail_number']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($flight['aircraft_type'])): ?>
                                                <div>
                                                    <span class="font-bold text-slate-500 uppercase block">Aircraft Type</span>
                                                    <div class="text-slate-800"><?= htmlspecialchars($flight['aircraft_type']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($flight['distance_miles'])): ?>
                                                <div>
                                                    <span class="font-bold text-slate-500 uppercase block">Distance</span>
                                                    <div class="text-slate-800"><?= number_format((int)$flight['distance_miles']) ?> mi</div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($flight['flight_duration_hours'])): ?>
                                                <div>
                                                    <span class="font-bold text-slate-500 uppercase block">Duration</span>
                                                    <div class="text-slate-800"><?= number_format((float)$flight['flight_duration_hours'], 1) ?> hrs</div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($flight['flight_purpose'])): ?>
                                                <div class="col-span-2">
                                                    <span class="font-bold text-slate-500 uppercase block">Purpose</span>
                                                    <div class="text-slate-800"><?= htmlspecialchars($flight['flight_purpose']) ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($flight['notes'])): ?>
                                            <div class="mt-2 text-xs text-slate-600">
                                                <span class="font-bold text-slate-500 uppercase">Notes:</span>
                                                <?= htmlspecialchars($flight['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($flight['doc_id'])): ?>
                                            <div class="mt-3 flex items-center gap-3">
                                                <a href="/document.php?id=<?= (int)$flight['doc_id'] ?>"
                                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-medium hover:bg-blue-100">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                    </svg>
                                                    View Source Document
                                                </a>
                                                <?php if (!empty($flight['doc_local_path'])): ?>
                                                    <a href="/serve.php?id=<?= (int)$flight['doc_id'] ?>" target="_blank"
                                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 text-slate-700 rounded-lg text-xs font-medium hover:bg-slate-100 border border-slate-200">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                  d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                        </svg>
                                                        View PDF
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4 max-w-4xl">
                    <div class="text-sm text-gray-500">
                        Page <?= $page ?> of <?= number_format($totalPages) ?>
                    </div>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars(buildFlightUrl(['page' => $page - 1])) ?>"
                               class="px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 text-sm text-gray-700">Prev</a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1): ?>
                            <a href="<?= htmlspecialchars(buildFlightUrl(['page' => 1])) ?>"
                               class="px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 text-sm text-gray-700">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="px-2 text-gray-400">&hellip;</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm font-medium"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(buildFlightUrl(['page' => $i])) ?>"
                                   class="px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 text-sm text-gray-700"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="px-2 text-gray-400">&hellip;</span>
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars(buildFlightUrl(['page' => $totalPages])) ?>"
                               class="px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 text-sm text-gray-700"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars(buildFlightUrl(['page' => $page + 1])) ?>"
                               class="px-3 py-1.5 border border-gray-200 rounded-lg hover:bg-gray-50 text-sm text-gray-700">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    function toggleFlightDetail(id) {
        var detail = document.getElementById('detail-' + id);
        var chevron = document.getElementById('chevron-' + id);
        var text = document.getElementById('toggle-text-' + id);
        if (detail.classList.contains('hidden')) {
            detail.classList.remove('hidden');
            chevron.style.transform = 'rotate(180deg)';
            text.textContent = 'Hide details';
        } else {
            detail.classList.add('hidden');
            chevron.style.transform = '';
            text.textContent = 'Show details';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var map = L.map('flight-map', { scrollWheelZoom: false }).setView([30, -55], 3);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; CARTO', subdomains: 'abcd', maxZoom: 19
        }).addTo(map);

        var flights = <?php echo json_encode($mapFlights); ?>;
        var locations = {};
        var paths = [];

        function getArc(start, end) {
            var lat1 = parseFloat(start[0]), lng1 = parseFloat(start[1]);
            var lat2 = parseFloat(end[0]), lng2 = parseFloat(end[1]);
            var midLat = (lat1 + lat2) / 2;
            var midLng = (lng1 + lng2) / 2;
            var dist = Math.sqrt(Math.pow(lat2 - lat1, 2) + Math.pow(lng2 - lng1, 2));
            var arcLat = midLat + (dist * 0.2);
            var points = [];
            for (var i = 0; i <= 20; i++) {
                var t = i / 20;
                var lat = Math.pow(1 - t, 2) * lat1 + 2 * (1 - t) * t * arcLat + Math.pow(t, 2) * lat2;
                var lng = Math.pow(1 - t, 2) * lng1 + 2 * (1 - t) * t * midLng + Math.pow(t, 2) * lng2;
                points.push([lat, lng]);
            }
            return points;
        }

        flights.forEach(function (flight) {
            if (flight.origin_lat && flight.origin_lng) {
                var key = flight.origin_lat + ',' + flight.origin_lng;
                if (!locations[key]) locations[key] = { lat: flight.origin_lat, lng: flight.origin_lng, code: flight.origin_airport_code, name: flight.origin_city, count: 0 };
                locations[key].count++;
            }
            if (flight.destination_lat && flight.destination_lng) {
                var key = flight.destination_lat + ',' + flight.destination_lng;
                if (!locations[key]) locations[key] = { lat: flight.destination_lat, lng: flight.destination_lng, code: flight.destination_airport_code, name: flight.destination_city, count: 0 };
                locations[key].count++;
            }
            if (flight.origin_lat && flight.destination_lat) {
                paths.push({
                    from: [flight.origin_lat, flight.origin_lng],
                    to: [flight.destination_lat, flight.destination_lng],
                    info: flight
                });
            }
        });

        // Draw Arcs
        paths.forEach(function (p) {
            var score = parseInt(p.info.significance_score || 0);
            var color = '#3b82f6';
            var weight = 1.5;
            var zIndex = 1;
            var opacity = 0.4;

            if (score >= 9) { color = '#ef4444'; weight = 3; zIndex = 100; opacity = 0.8; }
            else if (score >= 7) { color = '#f97316'; weight = 2; zIndex = 50; opacity = 0.6; }

            var polyline = L.polyline(getArc(p.from, p.to), {
                color: color, weight: weight, opacity: opacity, lineCap: 'round'
            }).addTo(map);

            if (score >= 7) polyline.bringToFront();

            var scoreBadge = score >= 7 ? `<span class="ml-auto text-[10px] font-bold text-white px-1.5 rounded ${score >= 9 ? 'bg-red-500' : 'bg-orange-500'}">Score: ${score}</span>` : '';
            var summary = p.info.ai_summary ? `<div class="mt-2 text-xs text-slate-600 bg-slate-50 p-2 rounded italic border border-slate-100">${p.info.ai_summary}</div>` : '';

            polyline.bindPopup(`
                <div class="text-sm font-sans min-w-[220px]">
                    <div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100">
                        <span class="font-bold text-slate-800">${p.info.origin_airport_code || 'DEP'}</span>
                        <span class="text-gray-400">&rarr;</span>
                        <span class="font-bold text-slate-800">${p.info.destination_airport_code || 'ARR'}</span>
                        ${scoreBadge}
                    </div>
                    <div class="text-xs text-gray-500 mb-1">
                        ${new Date(p.info.flight_date).toLocaleDateString()} · ${p.info.aircraft}
                    </div>
                    ${summary}
                </div>
            `);

            polyline.on('mouseover', function (e) { e.target.setStyle({ weight: 4, opacity: 1 }); e.target.bringToFront(); });
            polyline.on('mouseout', function (e) { e.target.setStyle({ weight: weight, opacity: opacity }); });
        });

        // Draw Airport Markers
        for (var k in locations) {
            var loc = locations[k];
            var radius = Math.min(10, Math.max(4, Math.log(loc.count + 1) * 3));
            L.circleMarker([loc.lat, loc.lng], {
                radius: radius, fillColor: '#0f172a', color: '#fff', weight: 1.5, fillOpacity: 0.9
            }).addTo(map).bindPopup(`<div class="text-center font-bold text-xs">${loc.code || loc.name} (${loc.count})</div>`);
        }
    });
</script>
