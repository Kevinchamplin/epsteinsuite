<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

// Initial server-side load for SEO / no-JS fallback
$sort = ($_GET['sort'] ?? 'shock') === 'date' ? 'date' : 'shock';

try {
    $pdo = db();

    $totalArticles = (int) Cache::remember('news_total_count', function () use ($pdo) {
        return $pdo->query("SELECT COUNT(*) FROM news_articles WHERE status = 'processed'")->fetchColumn();
    }, 120);

    $orderBy = $sort === 'date'
        ? 'published_at DESC, id DESC'
        : 'shock_score DESC, published_at DESC';

    $initialArticles = Cache::remember("news_initial_{$sort}", function () use ($pdo, $orderBy) {
        $stmt = $pdo->query("
            SELECT id, title, url, source_name, published_at, ai_summary,
                   ai_headline, shock_score, score_reason, entities_mentioned, created_at
            FROM news_articles
            WHERE status = 'processed'
            ORDER BY {$orderBy}
            LIMIT 20
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, 120);

} catch (Exception $e) {
    $totalArticles = 0;
    $initialArticles = [];
}

$page_title = 'Epstein News';
$meta_description = 'Live AI-analyzed news feed tracking the latest Jeffrey Epstein case developments, file releases, and legal proceedings. Auto-updated every 3 hours.';
$og_title = 'Epstein News — Live Intelligence Feed';
$extra_head_tags = [];
$newsListSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Epstein News Feed',
    'description' => 'Live AI-analyzed news feed tracking Epstein case developments.',
    'url' => 'https://epsteinsuite.com/news.php',
];
$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($newsListSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
require_once __DIR__ . '/includes/header_suite.php';
?>

<style>
    .news-card {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .news-card:hover {
        transform: translateY(-4px) scale(1.01);
        box-shadow: 0 20px 40px -5px rgba(0, 0, 0, 0.1);
        z-index: 10;
        border-color: rgba(59, 130, 246, 0.5);
    }
    .news-card-enter {
        animation: slideDown 0.4s ease-out;
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .score-pill {
        font-variant-numeric: tabular-nums;
    }
</style>

<main class="flex-grow w-full max-w-7xl mx-auto px-4 py-8">

    <!-- Hero Header -->
    <div class="mb-10 text-center">
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-red-50 text-red-600 font-bold text-sm mb-4 border border-red-100">
            <span class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
            </span>
            Live Feed &middot; Auto-Refreshing
        </div>
        <h1 class="text-4xl md:text-5xl font-black text-slate-900 mb-4 tracking-tight">Epstein News</h1>
        <p class="text-lg text-slate-600 max-w-2xl mx-auto">
            AI-analyzed breaking news and developments. Aggregated from Google News, summarized and scored by significance every 3 hours.
        </p>
        <p class="text-sm text-slate-400 mt-2">
            <span id="news-total"><?= number_format($totalArticles) ?></span> articles tracked
            &middot;
            <span id="news-updated-ago">Loading...</span>
        </p>
    </div>

    <!-- Sort Controls -->
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div class="inline-flex rounded-xl bg-white border border-slate-200 p-1 shadow-sm">
            <button id="sort-shock" onclick="setSort('shock')"
                class="px-4 py-2 text-sm font-bold rounded-lg transition-colors <?= $sort === 'shock' ? 'bg-red-600 text-white shadow' : 'text-slate-600 hover:bg-slate-50' ?>">
                Most Significant
            </button>
            <button id="sort-date" onclick="setSort('date')"
                class="px-4 py-2 text-sm font-bold rounded-lg transition-colors <?= $sort === 'date' ? 'bg-red-600 text-white shadow' : 'text-slate-600 hover:bg-slate-50' ?>">
                Latest First
            </button>
        </div>
        <div id="news-status" class="text-xs text-slate-400 flex items-center gap-2">
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            Auto updates ever 3 hours
        </div>
    </div>

    <!-- New Articles Toast -->
    <div id="new-articles-toast" class="hidden mb-4">
        <button onclick="showNewArticles()" class="w-full py-3 rounded-xl bg-gradient-to-r from-red-500 to-rose-600 text-white font-bold text-sm shadow-lg shadow-red-500/20 hover:shadow-xl hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
            <span id="toast-text">New articles available</span>
        </button>
    </div>

    <!-- News Grid -->
    <div id="news-grid" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php if (empty($initialArticles)): ?>
            <div class="col-span-full text-center py-20 bg-slate-50 rounded-3xl border border-slate-200">
                <div class="w-20 h-20 bg-slate-200 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-400">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-slate-800 mb-2">No News Yet</h3>
                <p class="text-slate-500">The news scraper hasn't run yet. Articles will appear here once the first scrape completes.</p>
            </div>
        <?php else: ?>
            <?php foreach ($initialArticles as $article): ?>
                <?= renderNewsCard($article) ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Load More -->
    <div id="load-more-wrap" class="text-center mt-8 <?= count($initialArticles) < 20 ? 'hidden' : '' ?>">
        <button id="load-more-btn" onclick="loadMore()"
            class="inline-flex items-center gap-2 px-8 py-3 rounded-xl bg-white border border-slate-200 text-slate-700 font-bold text-sm shadow-sm hover:bg-slate-50 hover:shadow transition-all">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            Load More
        </button>
    </div>
</main>

<?php
function renderNewsCard(array $a): string {
    $score = (int) ($a['shock_score'] ?? 0);
    $headline = htmlspecialchars($a['ai_headline'] ?? $a['title'] ?? '');
    $title = htmlspecialchars($a['title'] ?? '');
    $summary = htmlspecialchars($a['ai_summary'] ?? '');
    $source = htmlspecialchars($a['source_name'] ?? 'Unknown');
    $url = htmlspecialchars($a['url'] ?? '#');
    $reason = htmlspecialchars($a['score_reason'] ?? '');

    $pubDate = '';
    if (!empty($a['published_at'])) {
        $ts = strtotime($a['published_at']);
        $pubDate = $ts ? date('M j, Y', $ts) : '';
    }

    // Parse entities for internal linking
    $entities = [];
    if (!empty($a['entities_mentioned'])) {
        $decoded = json_decode($a['entities_mentioned'], true);
        if (is_array($decoded)) {
            $entities = array_slice($decoded, 0, 5);
        }
    }

    // Build entity chips HTML
    $entityChipsHtml = '';
    if (!empty($entities)) {
        $chips = [];
        foreach ($entities as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $encName = htmlspecialchars($name);
            $urlName = urlencode($name);
            $chips[] = "<a href=\"/?q={$urlName}\" class=\"inline-flex items-center gap-1 text-[11px] font-semibold bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full hover:bg-blue-100 transition-colors border border-blue-100\">{$encName}</a>";
        }
        if (!empty($chips)) {
            $entityChipsHtml = '<div class="flex flex-wrap gap-1.5 mt-3">' . implode('', $chips) . '</div>';
        }
    }

    // Build "Explore in Archive" search term from headline
    $searchTerm = $a['ai_headline'] ?? $a['title'] ?? '';
    // Extract a useful 2-3 word search phrase: take first notable words
    $searchQ = urlencode($searchTerm);

    // Score badge styling
    if ($score >= 8) {
        $badgeBg = 'bg-red-500';
        $badgeLabel = 'BREAKING';
        $borderColor = 'border-red-200';
        $glowClass = 'shadow-red-500/10';
    } elseif ($score >= 5) {
        $badgeBg = 'bg-amber-500';
        $badgeLabel = 'NOTABLE';
        $borderColor = 'border-amber-200';
        $glowClass = 'shadow-amber-500/10';
    } else {
        $badgeBg = 'bg-slate-400';
        $badgeLabel = 'UPDATE';
        $borderColor = 'border-slate-200';
        $glowClass = '';
    }

    return <<<HTML
    <article class="news-card rounded-3xl border {$borderColor} bg-white p-6 flex flex-col gap-4 shadow-sm {$glowClass}">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="score-pill {$badgeBg} text-white text-[11px] font-black px-2.5 py-1 rounded-full uppercase tracking-wider">{$badgeLabel}</span>
                <span class="score-pill {$badgeBg} text-white text-[11px] font-black w-7 h-7 rounded-full flex items-center justify-center">{$score}</span>
            </div>
            <span class="text-xs text-slate-400">{$pubDate}</span>
        </div>
        <div>
            <h3 class="text-xl font-bold text-slate-900 mb-1 leading-tight">{$headline}</h3>
            <p class="text-xs text-slate-400 mb-3">{$title}</p>
            <p class="text-sm text-slate-600 leading-relaxed">{$summary}</p>
            {$entityChipsHtml}
        </div>
        <div class="flex items-center gap-3 text-xs text-slate-400 mt-auto pt-3 border-t border-slate-100">
            <a href="/?q={$searchQ}" class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors" title="Search our document archive">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Files
            </a>
            <a href="/email_client.php?q={$searchQ}" class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors" title="Search related emails">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Emails
            </a>
            <a href="/flight_logs.php?q={$searchQ}" class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors" title="Search flight logs">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                Flights
            </a>
            <span class="ml-auto font-medium text-slate-500">{$source}</span>
            <a href="{$url}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700">
                Original
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                </svg>
            </a>
        </div>
    </article>
    HTML;
}
?>

<script>
(() => {
    let currentSort = '<?= $sort ?>';
    let currentPage = 1;
    let latestAt = '<?= !empty($initialArticles) ? ($initialArticles[0]['created_at'] ?? '') : '' ?>';
    let pendingNew = [];
    let pollTimer = null;
    let lastPollTime = Date.now();

    // Update "last updated" timer
    function updateTimer() {
        const ago = Math.floor((Date.now() - lastPollTime) / 1000);
        const el = document.getElementById('news-updated-ago');
        if (el) {
            if (ago < 5) el.textContent = 'Just updated';
            else if (ago < 60) el.textContent = `Updated ${ago}s ago`;
            else el.textContent = `Updated ${Math.floor(ago / 60)}m ago`;
        }
    }
    setInterval(updateTimer, 5000);
    updateTimer();

    // Sort toggle
    window.setSort = function(s) {
        if (s === currentSort) return;
        currentSort = s;
        currentPage = 1;

        // Update button styles
        const shock = document.getElementById('sort-shock');
        const date = document.getElementById('sort-date');
        if (s === 'shock') {
            shock.className = 'px-4 py-2 text-sm font-bold rounded-lg transition-colors bg-red-600 text-white shadow';
            date.className = 'px-4 py-2 text-sm font-bold rounded-lg transition-colors text-slate-600 hover:bg-slate-50';
        } else {
            date.className = 'px-4 py-2 text-sm font-bold rounded-lg transition-colors bg-red-600 text-white shadow';
            shock.className = 'px-4 py-2 text-sm font-bold rounded-lg transition-colors text-slate-600 hover:bg-slate-50';
        }

        fetchArticles(true);
    };

    // Fetch articles from API
    async function fetchArticles(replace = false) {
        const page = replace ? 1 : currentPage + 1;
        try {
            const resp = await fetch(`/api/news.php?sort=${currentSort}&page=${page}&per_page=20`);
            const data = await resp.json();
            if (!data.ok) return;

            if (replace) {
                document.getElementById('news-grid').innerHTML = '';
                currentPage = 1;
            } else {
                currentPage = page;
            }

            if (data.latest_at) latestAt = data.latest_at;
            lastPollTime = Date.now();
            updateTimer();

            const grid = document.getElementById('news-grid');
            data.articles.forEach(a => {
                grid.insertAdjacentHTML('beforeend', buildCard(a));
            });

            const wrap = document.getElementById('load-more-wrap');
            if (data.has_more) wrap.classList.remove('hidden');
            else wrap.classList.add('hidden');

        } catch (e) {
            console.error('News fetch error:', e);
        }
    }

    // Load more button
    window.loadMore = function() {
        const btn = document.getElementById('load-more-btn');
        btn.disabled = true;
        btn.textContent = 'Loading...';
        fetchArticles(false).finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg> Load More';
        });
    };

    // Poll for new articles
    async function poll() {
        if (!latestAt) return;
        try {
            const resp = await fetch(`/api/news.php?since=${encodeURIComponent(latestAt)}`);
            const data = await resp.json();
            lastPollTime = Date.now();
            updateTimer();

            if (data.ok && data.articles && data.articles.length > 0) {
                pendingNew = data.articles;
                const toast = document.getElementById('new-articles-toast');
                const toastText = document.getElementById('toast-text');
                toastText.textContent = `${data.articles.length} new article${data.articles.length > 1 ? 's' : ''} available`;
                toast.classList.remove('hidden');
            }
        } catch (e) {
            console.error('Poll error:', e);
        }
    }

    // Show buffered new articles
    window.showNewArticles = function() {
        const grid = document.getElementById('news-grid');
        pendingNew.reverse().forEach(a => {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = buildCard(a, true);
            grid.insertBefore(wrapper.firstElementChild, grid.firstChild);
            if (a.created_at && a.created_at > latestAt) latestAt = a.created_at;
        });
        pendingNew = [];
        document.getElementById('new-articles-toast').classList.add('hidden');

        const total = document.getElementById('news-total');
        if (total) {
            const cur = parseInt(total.textContent.replace(/,/g, ''), 10) || 0;
            total.textContent = (cur + pendingNew.length).toLocaleString();
        }
    };

    // Build card HTML from JS object
    function buildCard(a, animate = false) {
        const score = parseInt(a.shock_score) || 0;
        let badgeBg, badgeLabel, borderColor, glowClass;
        if (score >= 8) {
            badgeBg = 'bg-red-500'; badgeLabel = 'BREAKING'; borderColor = 'border-red-200'; glowClass = 'shadow-red-500/10';
        } else if (score >= 5) {
            badgeBg = 'bg-amber-500'; badgeLabel = 'NOTABLE'; borderColor = 'border-amber-200'; glowClass = 'shadow-amber-500/10';
        } else {
            badgeBg = 'bg-slate-400'; badgeLabel = 'UPDATE'; borderColor = 'border-slate-200'; glowClass = '';
        }

        let pubDate = '';
        if (a.published_at) {
            const d = new Date(a.published_at);
            if (!isNaN(d)) pubDate = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        const esc = s => {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        };

        // Parse entities for internal linking
        let entities = [];
        if (a.entities_mentioned) {
            try {
                const parsed = typeof a.entities_mentioned === 'string' ? JSON.parse(a.entities_mentioned) : a.entities_mentioned;
                if (Array.isArray(parsed)) entities = parsed.slice(0, 5);
            } catch (e) {}
        }

        let entityChipsHtml = '';
        if (entities.length > 0) {
            const chips = entities.map(name => {
                const n = (name || '').trim();
                if (!n) return '';
                return `<a href="/?q=${encodeURIComponent(n)}" class="inline-flex items-center gap-1 text-[11px] font-semibold bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full hover:bg-blue-100 transition-colors border border-blue-100">${esc(n)}</a>`;
            }).filter(Boolean).join('');
            if (chips) entityChipsHtml = `<div class="flex flex-wrap gap-1.5 mt-3">${chips}</div>`;
        }

        const searchQ = encodeURIComponent(a.ai_headline || a.title || '');
        const animClass = animate ? ' news-card-enter' : '';

        return `<article class="news-card${animClass} rounded-3xl border ${borderColor} bg-white p-6 flex flex-col gap-4 shadow-sm ${glowClass}">
            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="score-pill ${badgeBg} text-white text-[11px] font-black px-2.5 py-1 rounded-full uppercase tracking-wider">${badgeLabel}</span>
                    <span class="score-pill ${badgeBg} text-white text-[11px] font-black w-7 h-7 rounded-full flex items-center justify-center">${score}</span>
                </div>
                <span class="text-xs text-slate-400">${esc(pubDate)}</span>
            </div>
            <div>
                <h3 class="text-xl font-bold text-slate-900 mb-1 leading-tight">${esc(a.ai_headline || a.title)}</h3>
                <p class="text-xs text-slate-400 mb-3">${esc(a.title)}</p>
                <p class="text-sm text-slate-600 leading-relaxed">${esc(a.ai_summary)}</p>
                ${entityChipsHtml}
            </div>
            <div class="flex items-center gap-3 text-xs text-slate-400 mt-auto pt-3 border-t border-slate-100">
                <a href="/?q=${searchQ}" class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors" title="Search our document archive">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Files
                </a>
                <a href="/email_client.php?q=${searchQ}" class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors" title="Search related emails">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Emails
                </a>
                <a href="/flight_logs.php?q=${searchQ}" class="inline-flex items-center gap-1 hover:text-slate-600 transition-colors" title="Search flight logs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    Flights
                </a>
                <span class="ml-auto font-medium text-slate-500">${esc(a.source_name || 'Unknown')}</span>
                <a href="${esc(a.url)}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-semibold text-blue-600 hover:text-blue-700">
                    Original
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </a>
            </div>
        </article>`;
    }

    // Start polling
    pollTimer = setInterval(poll, 90000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
