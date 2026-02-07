<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$search = $_GET['q'] ?? '';
$category = $_GET['category'] ?? '';
$year = $_GET['year'] ?? '';
$minPrice = $_GET['min_price'] ?? '';
$maxPrice = $_GET['max_price'] ?? '';
$sortBy = $_GET['sort'] ?? 'order_date';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

// Whitelist sort columns and direction
$allowedSorts = ['order_date', 'price', 'product_name', 'order_number'];
if (!in_array($sortBy, $allowedSorts, true)) $sortBy = 'order_date';
if (!in_array($sortDir, ['ASC', 'DESC'], true)) $sortDir = 'DESC';

try {
    $pdo = db();

    // Build WHERE clause
    $where = [];
    $params = [];

    if ($search !== '') {
        if (mb_strlen($search) >= 3) {
            $where[] = "MATCH(product_name, delivery_status, category) AGAINST (:search IN NATURAL LANGUAGE MODE)";
            $params['search'] = $search;
        } else {
            $where[] = "(product_name LIKE :searchLike OR order_number LIKE :searchLike2)";
            $params['searchLike'] = "%$search%";
            $params['searchLike2'] = "%$search%";
        }
    }

    if ($category !== '') {
        $where[] = "category = :category";
        $params['category'] = $category;
    }

    if ($year !== '') {
        $where[] = "YEAR(order_date) = :year";
        $params['year'] = (int)$year;
    }

    if ($minPrice !== '') {
        $where[] = "price >= :minPrice";
        $params['minPrice'] = (float)$minPrice;
    }

    if ($maxPrice !== '') {
        $where[] = "price <= :maxPrice";
        $params['maxPrice'] = (float)$maxPrice;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Handle NULL prices in sort
    $orderBySQL = $sortBy === 'price'
        ? "ORDER BY price IS NULL, price $sortDir"
        : "ORDER BY $sortBy $sortDir";

    // Total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM amazon_orders $whereSQL");
    $stmt->execute($params);
    $totalCount = (int)$stmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $perPage));

    // Paginated results
    $stmt = $pdo->prepare("SELECT * FROM amazon_orders $whereSQL $orderBySQL LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats (cached 5 min)
    $stats = Cache::remember('orders_stats', function () use ($pdo) {
        $s = [];
        $s['total'] = (int)$pdo->query("SELECT COUNT(*) FROM amazon_orders")->fetchColumn();
        $s['total_spend'] = (float)$pdo->query("SELECT COALESCE(SUM(price), 0) FROM amazon_orders")->fetchColumn();
        $s['with_price'] = (int)$pdo->query("SELECT COUNT(*) FROM amazon_orders WHERE price IS NOT NULL")->fetchColumn();
        $s['avg_price'] = $s['with_price'] > 0
            ? (float)$pdo->query("SELECT AVG(price) FROM amazon_orders WHERE price IS NOT NULL")->fetchColumn()
            : 0;
        $row = $pdo->query("SELECT MIN(order_date) AS earliest, MAX(order_date) AS latest FROM amazon_orders WHERE order_date IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
        $s['earliest'] = $row['earliest'];
        $s['latest'] = $row['latest'];
        return $s;
    }, 300);

    // Categories for sidebar
    $categories = Cache::remember('orders_categories', function () use ($pdo) {
        return $pdo->query("SELECT category, COUNT(*) AS cnt FROM amazon_orders WHERE category IS NOT NULL GROUP BY category ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
    }, 300);

    // Years for sidebar
    $years = Cache::remember('orders_years', function () use ($pdo) {
        return $pdo->query("SELECT DISTINCT YEAR(order_date) AS yr FROM amazon_orders WHERE order_date IS NOT NULL ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);
    }, 300);

} catch (Exception $e) {
    $orders = [];
    $totalCount = 0;
    $totalPages = 1;
    $stats = ['total' => 0, 'total_spend' => 0, 'with_price' => 0, 'avg_price' => 0, 'earliest' => null, 'latest' => null];
    $categories = [];
    $years = [];
}

// Category badge colors
function categoryColor(string $cat): string {
    return match ($cat) {
        'Books' => 'bg-purple-100 text-purple-700',
        'Health & Medical' => 'bg-red-100 text-red-700',
        'Electronics' => 'bg-blue-100 text-blue-700',
        'Household' => 'bg-green-100 text-green-700',
        'Clothing & Accessories' => 'bg-pink-100 text-pink-700',
        'Food & Beverage' => 'bg-amber-100 text-amber-700',
        'Office & Supplies' => 'bg-slate-100 text-slate-700',
        'Personal Care' => 'bg-teal-100 text-teal-700',
        default => 'bg-gray-100 text-gray-600',
    };
}

// Build URL helper
function buildOrderUrl(array $overrides = []): string {
    global $search, $category, $year, $minPrice, $maxPrice, $sortBy, $sortDir, $page;
    $params = [];
    if ($search) $params['q'] = $search;
    if ($category) $params['category'] = $category;
    if ($year) $params['year'] = $year;
    if ($minPrice) $params['min_price'] = $minPrice;
    if ($maxPrice) $params['max_price'] = $maxPrice;
    if ($sortBy !== 'order_date') $params['sort'] = $sortBy;
    if ($sortDir !== 'DESC') $params['dir'] = $sortDir;
    $params['page'] = $page;
    $params = array_merge($params, $overrides);
    // Clean defaults
    if (empty($params['q'])) unset($params['q']);
    if (empty($params['category'])) unset($params['category']);
    if (empty($params['year'])) unset($params['year']);
    if (empty($params['min_price'])) unset($params['min_price']);
    if (empty($params['max_price'])) unset($params['max_price']);
    if (($params['sort'] ?? 'order_date') === 'order_date') unset($params['sort']);
    if (($params['dir'] ?? 'DESC') === 'DESC') unset($params['dir']);
    if (($params['page'] ?? 1) <= 1) unset($params['page']);
    return 'orders.php' . ($params ? '?' . http_build_query($params) : '');
}

$page_title = 'Purchase History — Amazon Orders';
$meta_description = 'Browse ' . number_format($stats['total']) . ' Amazon orders linked to Jeffrey Epstein, including books, electronics, household items, and more.';
$og_title = 'Epstein Amazon Purchase History';
$lock_body_scroll = true;
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex flex-col flex-1 overflow-hidden bg-slate-50">
    <!-- Stats Banner -->
    <div class="bg-gradient-to-br from-slate-800 to-slate-900 text-white">
        <div class="max-w-7xl mx-auto px-4 py-6 sm:px-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Purchase History</h1>
                    <p class="text-slate-400 text-sm mt-1">Amazon orders associated with Jeffrey Epstein's accounts</p>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white/10 rounded-xl px-4 py-3">
                    <div class="text-2xl font-bold"><?= number_format($stats['total']) ?></div>
                    <div class="text-xs text-slate-400 uppercase tracking-wider">Total Orders</div>
                </div>
                <div class="bg-white/10 rounded-xl px-4 py-3">
                    <div class="text-2xl font-bold">$<?= number_format($stats['total_spend'], 2) ?></div>
                    <div class="text-xs text-slate-400 uppercase tracking-wider">Total Spend</div>
                </div>
                <div class="bg-white/10 rounded-xl px-4 py-3">
                    <div class="text-2xl font-bold">$<?= number_format($stats['avg_price'], 2) ?></div>
                    <div class="text-xs text-slate-400 uppercase tracking-wider">Avg Order</div>
                </div>
                <div class="bg-white/10 rounded-xl px-4 py-3">
                    <div class="text-2xl font-bold"><?= $stats['earliest'] ? date('Y', strtotime($stats['earliest'])) . '–' . date('Y', strtotime($stats['latest'])) : 'N/A' ?></div>
                    <div class="text-xs text-slate-400 uppercase tracking-wider">Date Range</div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-1 overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 flex-shrink-0 overflow-y-auto border-r border-slate-200 bg-white py-4 hidden md:block">
            <!-- Search -->
            <div class="px-4 mb-4">
                <form method="GET" action="orders.php">
                    <?php if ($category): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
                    <?php if ($year): ?><input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>"><?php endif; ?>
                    <?php if ($sortBy !== 'order_date'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>"><?php endif; ?>
                    <?php if ($sortDir !== 'DESC'): ?><input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>"><?php endif; ?>
                    <div class="relative">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Search orders..."
                            class="w-full pl-8 pr-3 py-2 text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <svg class="w-4 h-4 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </form>
            </div>

            <!-- Sort -->
            <div class="px-4 mb-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Sort By</div>
                <div class="space-y-1">
                    <?php
                    $sortOptions = [
                        ['sort' => 'order_date', 'dir' => 'DESC', 'label' => 'Newest First'],
                        ['sort' => 'order_date', 'dir' => 'ASC', 'label' => 'Oldest First'],
                        ['sort' => 'price', 'dir' => 'DESC', 'label' => 'Price: High to Low'],
                        ['sort' => 'price', 'dir' => 'ASC', 'label' => 'Price: Low to High'],
                        ['sort' => 'product_name', 'dir' => 'ASC', 'label' => 'Name: A–Z'],
                    ];
                    foreach ($sortOptions as $opt):
                        $active = ($sortBy === $opt['sort'] && $sortDir === $opt['dir']);
                    ?>
                        <a href="<?= buildOrderUrl(['sort' => $opt['sort'], 'dir' => $opt['dir'], 'page' => 1]) ?>"
                            class="block px-3 py-1.5 rounded-lg text-sm <?= $active ? 'bg-blue-50 text-blue-700 font-medium' : 'text-slate-600 hover:bg-slate-50' ?>">
                            <?= $opt['label'] ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Year Filter -->
            <?php if (!empty($years)): ?>
            <div class="px-4 mb-4 border-t border-slate-100 pt-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Year</div>
                <div class="space-y-1">
                    <?php if ($year): ?>
                        <a href="<?= buildOrderUrl(['year' => '', 'page' => 1]) ?>" class="block px-3 py-1.5 rounded-lg text-sm text-blue-600 hover:bg-blue-50">Clear</a>
                    <?php endif; ?>
                    <?php foreach ($years as $yr): ?>
                        <a href="<?= buildOrderUrl(['year' => $yr, 'page' => 1]) ?>"
                            class="block px-3 py-1.5 rounded-lg text-sm <?= (string)$year === (string)$yr ? 'bg-blue-50 text-blue-700 font-medium' : 'text-slate-600 hover:bg-slate-50' ?>">
                            <?= $yr ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Category Filter -->
            <?php if (!empty($categories)): ?>
            <div class="px-4 mb-4 border-t border-slate-100 pt-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Category</div>
                <div class="space-y-1 max-h-64 overflow-y-auto">
                    <?php if ($category): ?>
                        <a href="<?= buildOrderUrl(['category' => '', 'page' => 1]) ?>" class="block px-3 py-1.5 rounded-lg text-sm text-blue-600 hover:bg-blue-50">Clear</a>
                    <?php endif; ?>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?= buildOrderUrl(['category' => $cat['category'], 'page' => 1]) ?>"
                            class="flex items-center justify-between px-3 py-1.5 rounded-lg text-sm <?= $category === $cat['category'] ? 'bg-blue-50 text-blue-700 font-medium' : 'text-slate-600 hover:bg-slate-50' ?>">
                            <span class="truncate"><?= htmlspecialchars($cat['category']) ?></span>
                            <span class="text-xs text-slate-400 ml-2"><?= $cat['cnt'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Price Filter -->
            <div class="px-4 mb-4 border-t border-slate-100 pt-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Price Range</div>
                <form method="GET" action="orders.php" class="flex items-center gap-2">
                    <?php if ($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                    <?php if ($category): ?><input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
                    <?php if ($year): ?><input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>"><?php endif; ?>
                    <?php if ($sortBy !== 'order_date'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>"><?php endif; ?>
                    <?php if ($sortDir !== 'DESC'): ?><input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>"><?php endif; ?>
                    <input type="number" name="min_price" value="<?= htmlspecialchars($minPrice) ?>" placeholder="Min"
                        class="w-20 px-2 py-1.5 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500" step="0.01" min="0">
                    <span class="text-slate-400">–</span>
                    <input type="number" name="max_price" value="<?= htmlspecialchars($maxPrice) ?>" placeholder="Max"
                        class="w-20 px-2 py-1.5 text-sm border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500" step="0.01" min="0">
                    <button type="submit" class="px-2 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-sm text-slate-600">Go</button>
                </form>
                <?php if ($minPrice || $maxPrice): ?>
                    <a href="<?= buildOrderUrl(['min_price' => '', 'max_price' => '', 'page' => 1]) ?>" class="text-xs text-blue-600 hover:underline mt-1 block">Clear price filter</a>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto p-4 md:p-6">
            <!-- Mobile search -->
            <div class="md:hidden mb-4">
                <form method="GET" action="orders.php">
                    <div class="relative">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search orders..."
                            class="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <svg class="w-5 h-5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </form>
            </div>

            <!-- Active filters -->
            <?php if ($search || $category || $year || $minPrice || $maxPrice): ?>
            <div class="mb-4 flex items-center gap-2 flex-wrap">
                <?php if ($search): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-sm">
                        "<?= htmlspecialchars($search) ?>"
                        <a href="<?= buildOrderUrl(['q' => '', 'page' => 1]) ?>" class="hover:text-blue-900">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($category): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-green-50 text-green-700 rounded-full text-sm">
                        <?= htmlspecialchars($category) ?>
                        <a href="<?= buildOrderUrl(['category' => '', 'page' => 1]) ?>" class="hover:text-green-900">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($year): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-purple-50 text-purple-700 rounded-full text-sm">
                        <?= htmlspecialchars($year) ?>
                        <a href="<?= buildOrderUrl(['year' => '', 'page' => 1]) ?>" class="hover:text-purple-900">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if ($minPrice || $maxPrice): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-amber-50 text-amber-700 rounded-full text-sm">
                        $<?= $minPrice ?: '0' ?> – $<?= $maxPrice ?: '...' ?>
                        <a href="<?= buildOrderUrl(['min_price' => '', 'max_price' => '', 'page' => 1]) ?>" class="hover:text-amber-900">&times;</a>
                    </span>
                <?php endif; ?>
                <a href="orders.php" class="text-sm text-slate-500 hover:text-slate-700">Clear all</a>
            </div>
            <?php endif; ?>

            <!-- Results count -->
            <div class="mb-4 text-sm text-slate-500">
                <?= number_format($totalCount) ?> order<?= $totalCount !== 1 ? 's' : '' ?>
                <?php if ($search): ?> matching "<?= htmlspecialchars($search) ?>"<?php endif; ?>
            </div>

            <?php if (empty($orders)): ?>
                <div class="flex flex-col items-center justify-center py-20 text-center">
                    <div class="w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-medium text-slate-900 mb-2">No orders found</h2>
                    <p class="text-slate-500 max-w-sm">Try adjusting your filters or search terms.</p>
                </div>
            <?php else: ?>
                <!-- Order Cards Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($orders as $order): ?>
                        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden hover:shadow-md transition-shadow cursor-pointer order-card-item"
                             data-order='<?= htmlspecialchars(json_encode($order, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'>
                            <div class="flex gap-4 p-4">
                                <!-- Product Image -->
                                <div class="w-20 h-20 flex-shrink-0 bg-slate-100 rounded-lg overflow-hidden flex items-center justify-center">
                                    <?php if (!empty($order['product_image_url'])): ?>
                                        <img src="<?= htmlspecialchars($order['product_image_url']) ?>"
                                             alt="<?= htmlspecialchars($order['product_name']) ?>"
                                             class="w-full h-full object-contain"
                                             loading="lazy"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <div class="w-full h-full items-center justify-center hidden">
                                            <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                            </svg>
                                        </div>
                                    <?php else: ?>
                                        <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                        </svg>
                                    <?php endif; ?>
                                </div>

                                <!-- Details -->
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-sm font-semibold text-slate-900 line-clamp-2 leading-snug"><?= htmlspecialchars($order['product_name']) ?></h3>
                                    <div class="mt-1 flex items-center gap-2 text-xs text-slate-500">
                                        <span><?= $order['order_date'] ? date('M j, Y', strtotime($order['order_date'])) : 'No date' ?></span>
                                        <?php if (!empty($order['price'])): ?>
                                            <span class="text-slate-300">|</span>
                                            <span class="font-semibold text-slate-700">$<?= number_format((float)$order['price'], 2) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1.5 flex items-center gap-2 flex-wrap">
                                        <?php if (!empty($order['category'])): ?>
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold <?= categoryColor($order['category']) ?>">
                                                <?= htmlspecialchars($order['category']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($order['rating'])): ?>
                                            <span class="flex items-center gap-0.5 text-amber-500 text-xs">
                                                <?php for ($i = 0; $i < (int)$order['rating']; $i++): ?>
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                                <?php endfor; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Footer -->
                            <div class="px-4 py-2 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                                <span class="text-[10px] text-slate-400 font-mono">#<?= htmlspecialchars($order['order_number']) ?></span>
                                <?php if (!empty($order['delivery_status'])): ?>
                                    <span class="text-[10px] font-medium text-green-600"><?= htmlspecialchars($order['delivery_status']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="mt-6 flex items-center justify-between border-t border-slate-200 pt-4">
                    <div class="text-sm text-slate-500">
                        Page <?= $page ?> of <?= number_format($totalPages) ?>
                    </div>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildOrderUrl(['page' => $page - 1]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm">Prev</a>
                        <?php endif; ?>
                        <?php
                        $startP = max(1, $page - 2);
                        $endP = min($totalPages, $page + 2);
                        for ($i = $startP; $i <= $endP; $i++):
                        ?>
                            <?php if ($i === $page): ?>
                                <span class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-sm font-medium"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= buildOrderUrl(['page' => $i]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildOrderUrl(['page' => $page + 1]) ?>" class="px-3 py-1.5 border border-slate-200 rounded-lg hover:bg-slate-50 text-sm">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Order Detail Modal -->
<div id="orderModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeOrderModal()"></div>
    <div class="absolute inset-4 md:inset-y-10 md:inset-x-auto md:left-1/2 md:-translate-x-1/2 md:w-full md:max-w-lg bg-white rounded-2xl shadow-2xl overflow-y-auto">
        <div class="sticky top-0 bg-white border-b border-slate-100 px-6 py-4 flex items-center justify-between z-10">
            <h2 class="text-lg font-bold text-slate-900">Order Details</h2>
            <button onclick="closeOrderModal()" class="p-1 hover:bg-slate-100 rounded-lg">
                <svg class="w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="modalContent" class="p-6">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<script>
function openOrderModal(orderData) {
    const m = document.getElementById('orderModal');
    const c = document.getElementById('modalContent');

    let imageHtml = '';
    if (orderData.product_image_url) {
        imageHtml = `<div class="w-full h-48 bg-slate-50 rounded-xl overflow-hidden flex items-center justify-center mb-4">
            <img src="${escapeHtml(orderData.product_image_url)}" alt="${escapeHtml(orderData.product_name)}" class="max-w-full max-h-full object-contain">
        </div>`;
    }

    let ratingHtml = '';
    if (orderData.rating) {
        const stars = '&#9733;'.repeat(parseInt(orderData.rating));
        const empty = '&#9734;'.repeat(5 - parseInt(orderData.rating));
        ratingHtml = `<div class="flex items-center gap-1 text-amber-500 text-sm">${stars}<span class="text-slate-300">${empty}</span></div>`;
    }

    let priceHtml = orderData.price ? `$${parseFloat(orderData.price).toFixed(2)}` : 'N/A';

    let amazonLink = '';
    if (orderData.asin) {
        amazonLink = `<a href="https://www.amazon.com/dp/${escapeHtml(orderData.asin)}" target="_blank" rel="noopener noreferrer"
            class="inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 mt-3">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
            View on Amazon
        </a>`;
    }

    c.innerHTML = `
        ${imageHtml}
        <h3 class="text-lg font-bold text-slate-900 leading-snug">${escapeHtml(orderData.product_name)}</h3>
        ${ratingHtml}
        ${amazonLink}
        <div class="mt-5 space-y-3">
            <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Order #</span>
                <span class="text-sm font-mono text-slate-700">${escapeHtml(orderData.order_number)}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Date</span>
                <span class="text-sm text-slate-700">${orderData.order_date ? new Date(orderData.order_date).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'}) : 'N/A'}</span>
            </div>
            <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Price</span>
                <span class="text-sm font-semibold text-slate-900">${priceHtml}</span>
            </div>
            ${orderData.quantity > 1 ? `<div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Quantity</span>
                <span class="text-sm text-slate-700">${orderData.quantity}</span>
            </div>` : ''}
            <div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Status</span>
                <span class="text-sm font-medium text-green-600">${escapeHtml(orderData.delivery_status || 'N/A')}</span>
            </div>
            ${orderData.category ? `<div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Category</span>
                <span class="text-sm text-slate-700">${escapeHtml(orderData.category)}</span>
            </div>` : ''}
            ${orderData.asin ? `<div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">ASIN</span>
                <span class="text-sm font-mono text-slate-700">${escapeHtml(orderData.asin)}</span>
            </div>` : ''}
            ${orderData.delivery_address ? `<div class="flex justify-between py-2 border-b border-slate-100">
                <span class="text-sm text-slate-500">Ship To</span>
                <span class="text-sm text-slate-700">${escapeHtml(orderData.delivery_address)}</span>
            </div>` : ''}
        </div>
    `;

    m.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeOrderModal() {
    document.getElementById('orderModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function escapeHtml(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = String(text);
    return d.innerHTML;
}

// Click handlers for order cards
document.querySelectorAll('.order-card-item').forEach(card => {
    card.addEventListener('click', () => {
        try {
            const data = JSON.parse(card.dataset.order);
            openOrderModal(data);
        } catch(e) {
            console.error('Failed to parse order data', e);
        }
    });
});

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeOrderModal();
});
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
