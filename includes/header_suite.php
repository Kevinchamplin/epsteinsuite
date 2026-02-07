<?php
require_once __DIR__ . '/shield.php';
require_once __DIR__ . '/donate.php';
$current_page = basename($_SERVER['PHP_SELF']);

// Count active chat users (cache files touched in last 60s)
$_chatOnlineCount = 0;
$_chatCacheDir = __DIR__ . '/../cache/';
$_chatCutoff = time() - 60;
foreach (glob($_chatCacheDir . 'chat_active_*.cache') as $_cf) {
    $_raw = @file_get_contents($_cf);
    $_ts = 0;
    if ($_raw !== false) {
        $_data = @json_decode($_raw, true);
        $_ts = is_array($_data) ? (int)($_data['ts'] ?? 0) : (int)$_raw;
    }
    if ($_ts >= $_chatCutoff) {
        $_chatOnlineCount++;
    }
}

$lock_body_scroll = $lock_body_scroll ?? false;
$body_classes = 'bg-slate-50 text-slate-900 flex flex-col overflow-x-hidden';
if ($lock_body_scroll) {
    $body_classes .= ' h-screen overflow-hidden';
} else {
    $body_classes .= ' min-h-screen overflow-y-auto';
}

$page_title = $page_title ?? 'Epstein Suite';
if (stripos($page_title, 'epstein suite') === false) {
    $computed_page_title = $page_title . ' · Epstein Suite';
} else {
    $computed_page_title = $page_title;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'epsteinsuite.com';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

$baseUrl = $scheme . '://' . $host;
$currentUrl = $baseUrl . $requestUri;

$canonical_url = $canonical_url ?? $currentUrl;
$noindex = $noindex ?? false;

$meta_description = $meta_description ?? 'A searchable index of public-source Epstein-related documents, emails, photos, and flight logs. Explore sources, search across datasets, and verify against originals.';

$defaultOgTitle = 'Epstein Suite · Search DOJ Epstein Files & Flight Logs';
$defaultOgDescription = 'Browse 3,000+ DOJ Epstein documents, emails, photos, and flight logs with AI summaries, citations, and OCR search to verify every lead fast.';

$og_title = $og_title ?? $defaultOgTitle;
if (stripos($og_title, 'epstein suite') === false) {
    $og_title .= ' · Epstein Suite';
}
$og_description = $og_description ?? $defaultOgDescription;

$currentSearchQuery = $globalSearchQuery ?? ($_GET['q'] ?? '');
$extra_head_tags = $extra_head_tags ?? [];

$defaultOgImage = 'https://epsteinsuite.com/Epsten-Suite-Search-Files-Easily.png';
$og_image = $og_image ?? $defaultOgImage;

$ldJsonWebsite = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'Epstein Suite',
    'url' => $baseUrl . '/',
    'description' => $meta_description,
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => $baseUrl . '/?q={search_term_string}',
        'query-input' => 'required name=search_term_string'
    ]
];

$ldJsonOrganization = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'Epstein Suite',
    'url' => 'https://epsteinsuite.com',
    'logo' => 'https://epsteinsuite.com/jeffrey-epstein.png',
    'description' => 'A searchable transparency platform providing public access to DOJ Epstein-related documents, emails, photos, and flight logs.',
    'sameAs' => [
        'https://twitter.com/EpsteinSuite'
    ],
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'contactType' => 'customer service',
        'url' => 'https://epsteinsuite.com/contact.php'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-ZZD74TKB1R"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', 'G-ZZD74TKB1R');
    </script>

    <!-- Gatekeeper Consent -->
    <script data-cfasync="false" src="https://cmp.gatekeeperconsent.com/min.js"></script>
    <script data-cfasync="false" src="https://the.gatekeeperconsent.com/cmp.min.js"></script>

<!-- Google Adsense -->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1567321132190029"
     crossorigin="anonymous"></script>

<!-- Monetag OR Adcash (randomly alternate, not both) 
<script>
(function() {
    var useMonetag = Math.random() < 0.5;
    if (useMonetag) {
        if (!document.cookie.includes('monetag_loaded=1')) {
            document.cookie = 'monetag_loaded=1; path=/; max-age=1800; SameSite=Lax';
            var s = document.createElement('script');
            s.src = 'https://quge5.com/88/tag.min.js';
            s.setAttribute('data-zone', '208437');
            s.async = true;
            s.setAttribute('data-cfasync', 'false');
            document.head.appendChild(s);
        }
    } else {
        var s = document.createElement('script');
        s.id = 'aclib';
        s.src = '//acscdn.com/script/aclib.js';
        s.onload = function() {
            if (window.aclib) {
                aclib.runAutoTag({ zoneId: 'wnbpuxsurn' });
            }
        };
        document.head.appendChild(s);
    }
})();
</script>
-->


    <title><?= htmlspecialchars($computed_page_title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="description" content="<?= htmlspecialchars($meta_description, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="<?= $noindex ? 'noindex, nofollow' : 'index, follow' ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Epstein Suite">
    <meta property="og:title" content="<?= htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@EpsteinSuite">
    <meta name="twitter:title" content="<?= htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') ?>">

    <meta property="og:locale" content="en_US">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Epstein Suite - Search DOJ Epstein Files">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="/jeffrey-epstein.png">

    <script type="application/ld+json"><?= json_encode($ldJsonWebsite, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="application/ld+json"><?= json_encode($ldJsonOrganization, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php if (!empty($extra_head_tags)): ?>
        <?php foreach ($extra_head_tags as $tag): ?>
            <?= $tag . "\n" ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="https://unpkg.com/feather-icons/dist/feather.css">
    <script src="https://unpkg.com/feather-icons"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="/jeffrey-epstein.png">

    <!-- Google Translate -->
    <script type="text/javascript">
        function googleTranslateElementInit() {
            new google.translate.TranslateElement({
                pageLanguage: 'en',
                includedLanguages: 'en,es,fr,de,pt,it,nl,pl,ru,uk,zh-CN,zh-TW,ja,ko,ar,hi,he,tr,sv,da,fi,no,cs,ro,hu,el,th,vi,id,ms,tl',
                layout: google.translate.TranslateElement.InlineLayout.HORIZONTAL,
                autoDisplay: false
            }, 'google_translate_element');
        }
    </script>
    <script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
    <style>
        /* Only suppress the Google Translate top bar that pushes page down */
        .goog-te-banner-frame { display: none !important; }
        body { top: 0 !important; }
        /* Style the widget dropdown to match site */
        #google_translate_element { display: inline-block; vertical-align: middle; max-width: 100%; overflow: hidden; }
        .goog-te-gadget { font-size: 0 !important; font-family: 'Inter', sans-serif !important; }
        .goog-te-gadget span { display: none !important; }
        .goog-te-gadget .goog-te-combo {
            font-size: 0.75rem !important;
            font-family: 'Inter', sans-serif !important;
            padding: 0.35rem 0.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            background: #f8fafc;
            color: #475569;
            cursor: pointer;
            outline: none;
            appearance: auto;
        }
        .goog-te-gadget .goog-te-combo:hover { border-color: #94a3b8; }
        .goog-te-gadget .goog-te-combo:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,0.15); }
    </style>
    <style>
        /* FOUC guard: hide body until Tailwind CSS + Feather Icons are ready */
        body { font-family: 'Inter', sans-serif; opacity: 0; }
        body.ready { opacity: 1; transition: opacity 0.15s ease; }

        .app-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }

        .entity-chip {
            position: relative;
            overflow: visible;
        }

        .entity-chip::after {
            content: attr(data-entity-hover);
            position: absolute;
            left: 50%;
            bottom: calc(100% + 0.4rem);
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.9);
            color: #fff;
            padding: 0.35rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            letter-spacing: 0.04em;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.15s ease, transform 0.15s ease;
            z-index: 999;
        }

        .entity-chip:hover::after {
            opacity: 1;
            transform: translate(-50%, -0.1rem);
        }

        .ask-fab {
            position: fixed;
            right: 1.5rem;
            bottom: 1.5rem;
            z-index: 70;
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            background: linear-gradient(120deg, #111827, #1e3a8a);
            color: #fff;
            padding: 0.85rem 1.4rem;
            border-radius: 9999px;
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.35);
            border: 1px solid rgba(148, 163, 184, 0.3);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .ask-fab:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.45);
        }

        .ask-fab img {
            width: 2rem;
            height: 2rem;
            border-radius: 9999px;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .ask-fab small {
            display: block;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: rgba(248, 250, 252, 0.7);
        }

        .feedback-fab {
            position: fixed;
            left: 1.5rem;
            bottom: 1.5rem;
            z-index: 70;
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            background: linear-gradient(120deg, #f59e0b, #f97316);
            color: #fff;
            padding: 0.85rem 1.4rem;
            border-radius: 9999px;
            box-shadow: 0 15px 35px rgba(124, 45, 18, 0.35);
            border: 1px solid rgba(248, 250, 252, 0.3);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .feedback-fab:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(124, 45, 18, 0.45);
        }

        .feedback-fab small {
            display: block;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: rgba(248, 250, 252, 0.85);
        }

        @media (max-width: 640px) {
            .ask-fab {
                right: 1rem;
                bottom: 1rem;
                padding: 0.65rem 1rem;
            }

            .ask-fab span {
                font-size: 0.85rem;
            }

            .feedback-fab {
                display: none;
            }
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        document.querySelectorAll('form[action="/"]').forEach(function(form){
            form.addEventListener('submit',function(){
                var btn=form.querySelector('button[type="submit"]');
                if(!btn)return;
                var origHTML=btn.innerHTML;
                btn.disabled=true;
                btn.innerHTML='<svg class="animate-spin h-4 w-4 inline-block mr-1" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>Searching\u2026';
                btn.classList.add('opacity-80');
                setTimeout(function(){btn.disabled=false;btn.innerHTML=origHTML;btn.classList.remove('opacity-80');},15000);
            });
        });
    });
    </script>
</head>

<body class="<?= htmlspecialchars($body_classes, ENT_QUOTES, 'UTF-8') ?>">
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.feather && typeof window.feather.replace === 'function') {
                window.feather.replace();
            }
            document.body.classList.add('ready');

            // Close app grid dropdown when clicking outside
            document.addEventListener('click', function(e) {
                var wrapper = document.getElementById('app-grid-wrapper');
                var dropdown = document.getElementById('app-grid-dropdown');
                if (wrapper && dropdown && !wrapper.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        });
        // Fallback: reveal body after 3s even if a CDN resource is slow
        setTimeout(function() { document.body.classList.add('ready'); }, 3000);
    </script>

    <!-- Suite Header -->
    <header
        class="bg-white border-b border-slate-200 h-16 flex-shrink-0 flex items-center justify-between px-4 relative z-50">
        <div class="flex items-center gap-2">
            <button id="mobile-menu-toggle" onclick="document.getElementById('mobile-menu').classList.toggle('hidden')" class="p-2 hover:bg-slate-100 rounded-full md:hidden">
                <svg class="w-6 h-6 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <a href="/" class="flex items-center gap-2 text-xl font-bold text-slate-800">
                <div class="w-8 h-8 bg-slate-900 text-white rounded flex items-center justify-center text-lg">E</div>
                <span class="hidden md:inline">Epstein<span class="text-blue-600">Suite</span></span>
            </a>
        </div>

        <!-- App Search (Global) -->
        <div class="flex-1 max-w-2xl mx-4 hidden md:block">
            <form action="/" method="GET" class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input type="text" name="q" placeholder="Search across Drive, Mail, and Contacts..."
                    value="<?= htmlspecialchars($currentSearchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    class="block w-full pl-10 pr-28 py-2 border border-slate-200 rounded-lg leading-5 bg-slate-100 placeholder-slate-500 focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 sm:text-sm transition-colors">
                <button type="submit"
                    class="absolute inset-y-1.5 right-1.5 px-3 py-1.5 text-sm font-semibold text-white bg-slate-800 rounded-md hover:bg-slate-900 transition-colors flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    Search
                </button>
            </form>
        </div>

        <!-- Primary Navigation -->
        <nav class="hidden lg:flex items-center gap-3 text-sm font-semibold text-slate-600">
            <div class="relative group">
                <button class="uppercase text-xs tracking-wide text-slate-500 hover:text-blue-600 flex items-center gap-1">
                    Explore
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div class="absolute left-0 top-full pt-3 hidden group-hover:block z-40">
                    <div class="bg-white border border-slate-100 rounded-xl shadow-xl w-64 p-3 space-y-0.5">
                        <a href="/drive.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">📁 Epstein Files</span>
                            <span class="block text-[11px] text-slate-400 pl-6">4,700+ DOJ documents &amp; PDFs</span>
                        </a>
                        <a href="/photos.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">🖼 Epstein Photos</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Evidence &amp; seized images</span>
                        </a>
                        <a href="/flight_logs.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">✈️ Epstein Flights</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Lolita Express flight logs</span>
                        </a>
                        <a href="/orders.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">📦 Amazon Orders</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Epstein's purchase history</span>
                        </a>
                        <a href="/sources.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">🔗 Sources</span>
                            <span class="block text-[11px] text-slate-400 pl-6">DOJ, FBI &amp; oversight origins</span>
                        </a>
                        <a href="/timeline.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">🗓 Timeline</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Key dates &amp; case events</span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="relative group">
                <button class="uppercase text-xs tracking-wide text-slate-500 hover:text-blue-600 flex items-center gap-1">
                    Analysis
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div class="absolute left-0 top-full pt-3 hidden group-hover:block z-40">
                    <div class="bg-white border border-slate-100 rounded-xl shadow-xl w-64 p-3 space-y-0.5">
                        <a href="/top_findings.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">⭐ Epstein Findings</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Most significant discoveries</span>
                        </a>
                        <a href="/insights.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">📊 Entity Map</span>
                            <span class="block text-[11px] text-slate-400 pl-6">People &amp; connection graphs</span>
                        </a>
                        <a href="/stats.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">📈 Archive Stats</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Database metrics &amp; counts</span>
                        </a>
                        <a href="/trending.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">🔥 Trending</span>
                            <span class="block text-[11px] text-slate-400 pl-6">What's popular right now</span>
                        </a>
                        <a href="/news.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <div class="flex items-center gap-1.5">
                                <span>📰 Epstein News</span>
                                <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span></span>
                            </div>
                            <span class="block text-[11px] text-slate-400 pl-6">Latest coverage &amp; updates</span>
                        </a>
                        <a href="/ask.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">🤖 Epstein Files AI</span>
                            <span class="block text-[11px] text-slate-400 pl-6">AI-powered document search</span>
                        </a>
                    </div>
                </div>
            </div>
            <div class="relative group">
                <button class="uppercase text-xs tracking-wide text-slate-500 hover:text-blue-600 flex items-center gap-1">
                    Connect
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
                <div class="absolute left-0 top-full pt-3 hidden group-hover:block z-40">
                    <div class="bg-white border border-slate-100 rounded-xl shadow-xl w-64 p-3 space-y-0.5">
                        <a href="/email_client.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">✉️ Epstein Emails</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Seized email correspondence</span>
                        </a>
                        <a href="/contacts.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <span class="block">👥 Epstein Black Book</span>
                            <span class="block text-[11px] text-slate-400 pl-6">Contact list &amp; associates</span>
                        </a>
                        <a href="/chatroom.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50">
                            <div class="flex items-center justify-between">
                                <span>💬 Live Chat</span>
                                <?php if ($_chatOnlineCount >= 2): ?>
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px] font-bold">
                                    <span class="relative flex h-1.5 w-1.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span><span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-green-500"></span></span>
                                    <?= $_chatOnlineCount ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span class="block text-[11px] text-slate-400 pl-6">Discuss with other visitors</span>
                        </a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- App Switcher & Profile -->
        <div class="flex items-center gap-2">
            <!-- Google Translate -->
            <div id="google_translate_element" class="hidden md:inline-block"></div>
            <a href="/" class="p-2 hover:bg-slate-100 rounded-full text-slate-600 md:hidden" aria-label="Search">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </a>
            <!-- App Grid Launcher -->
            <div class="relative" id="app-grid-wrapper">
                <button onclick="var d=document.getElementById('app-grid-dropdown');d.classList.toggle('hidden');event.stopPropagation();" class="p-2 hover:bg-slate-100 rounded-full text-slate-600">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                        <path
                            d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                        </path>
                    </svg>
                </button>
                <!-- Dropdown -->
                <div id="app-grid-dropdown" class="absolute right-0 top-full pt-2 hidden z-50">
                    <div class="w-72 bg-white rounded-xl shadow-lg border border-slate-100 p-4">
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <a href="/"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-blue-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Search</span>
                            </a>
                            <a href="/top_findings.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-red-600 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Findings</span>
                            </a>
                            <a href="/drive.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-green-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Files</span>
                            </a>
                            <a href="/email_client.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-red-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Emails</span>
                            </a>
                            <a href="/contacts.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-indigo-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Contacts</span>
                            </a>
                            <a href="/chatroom.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors relative">
                                <div
                                    class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center relative">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>
                                    <?php if ($_chatOnlineCount >= 2): ?>
                                    <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 flex items-center justify-center rounded-full bg-green-500 text-white text-[9px] font-bold border-2 border-white"><?= $_chatOnlineCount ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Live Chat</span>
                            </a>
                            <a href="/flight_logs.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-amber-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Flights</span>
                            </a>
                            <a href="/news.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-red-600 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">News</span>
                            </a>
                            <a href="/photos.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-pink-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Photos</span>
                            </a>
                            <a href="/search.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-purple-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712L15 13.5l-1.242 1.242c-1.172 1.025-3.072 1.025-4.242 0-1.172-1.025-1.172-2.687 0-3.712L9 9.5l1.242-1.242z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Ask AI</span>
                            </a>
                            <a href="/insights.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-amber-600 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Entity Map</span>
                            </a>
                            <a href="/stats.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Archive Stats</span>
                            </a>
                            <a href="/trending.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-rose-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Trending</span>
                            </a>
                            <a href="/sources.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-slate-600 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Sources</span>
                            </a>
                            <a href="/orders.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-orange-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Amazon Orders</span>
                            </a>
                            <a href="/timeline.php"
                                class="flex flex-col items-center gap-1 hover:bg-slate-50 p-2 rounded-lg transition-colors">
                                <div
                                    class="w-10 h-10 bg-cyan-500 text-white rounded-full flex items-center justify-center">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <span class="text-xs font-medium text-slate-700">Timeline</span>
                            </a>
                            <div class="col-span-3 border-t border-slate-100 pt-3 mt-1">
                                <?= render_donate_button(['label' => 'Donate to Keep It Public', 'full_width' => true]) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Avatar -->
            <a href="/profile.php" class="w-8 h-8 rounded-full overflow-hidden border border-slate-200"
                aria-label="Profile">
                <img src="/jeffrey-epstein.png" alt="Epstein Suite" class="w-8 h-8 object-cover">
            </a>
        </div>
    </header>

    <!-- Mobile Menu Drawer -->
    <div id="mobile-menu" class="hidden fixed inset-0 z-[60] md:hidden">
        <div class="absolute inset-0 bg-black/40" onclick="document.getElementById('mobile-menu').classList.add('hidden')"></div>
        <nav class="absolute top-0 left-0 bottom-0 w-72 bg-white shadow-xl overflow-y-auto">
            <div class="flex items-center justify-between p-4 border-b border-slate-200">
                <a href="/" class="flex items-center gap-2 text-lg font-bold text-slate-800">
                    <div class="w-8 h-8 bg-slate-900 text-white rounded flex items-center justify-center text-lg">E</div>
                    Epstein<span class="text-blue-600">Suite</span>
                </a>
                <button onclick="document.getElementById('mobile-menu').classList.add('hidden')" class="p-2 hover:bg-slate-100 rounded-full" aria-label="Close menu">
                    <svg class="w-5 h-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="p-4 space-y-1">
                <p class="px-3 py-1 text-xs font-bold uppercase tracking-wider text-slate-400">Explore</p>
                <a href="/drive.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Files</a>
                <a href="/photos.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Photos</a>
                <a href="/flight_logs.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Flights</a>
                <a href="/orders.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Amazon Orders</a>
                <a href="/sources.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Sources</a>
                <a href="/timeline.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Timeline</a>
                <p class="px-3 py-1 pt-3 text-xs font-bold uppercase tracking-wider text-slate-400">Analysis</p>
                <a href="/top_findings.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Findings</a>
                <a href="/insights.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Entity Map</a>
                <a href="/stats.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Archive Stats</a>
                <a href="/trending.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Trending</a>
                <a href="/news.php" class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">
                    Epstein News
                    <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span></span>
                </a>
                <a href="/ask.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Files AI</a>
                <a href="/search.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Search</a>
                <p class="px-3 py-1 pt-3 text-xs font-bold uppercase tracking-wider text-slate-400">Connect</p>
                <a href="/email_client.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Emails</a>
                <a href="/contacts.php" class="block px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">Epstein Black Book</a>
                <a href="/chatroom.php" class="flex items-center justify-between px-3 py-2 rounded-lg hover:bg-slate-50 text-sm text-slate-700">
                    <span>Live Chat</span>
                    <?php if ($_chatOnlineCount >= 2): ?>
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px] font-bold">
                        <span class="relative flex h-1.5 w-1.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span><span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-green-500"></span></span>
                        <?= $_chatOnlineCount ?> online
                    </span>
                    <?php endif; ?>
                </a>
            </div>
        </nav>
    </div>

    <!-- DOJ Release / Active Ingestion Banner -->
    <div class="w-full bg-white/50 backdrop-blur-md border-b border-slate-100 py-4 px-4 sm:px-6">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-600 animate-pulse flex-shrink-0">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-slate-800 tracking-tight">System Status: Active Ingestion</p>
                        <p class="text-xs text-slate-500">Live processing: OCR, AI summaries, and data indexing in progress across ~3.5 million newly released pages.</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button onclick="document.getElementById('doj-release-details').classList.toggle('hidden')" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-50 text-blue-700 text-sm font-semibold hover:bg-blue-100 transition-all">
                        <span>What's New</span>
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                    <a href="/stats.php" class="inline-flex items-center gap-2 px-4 py-2 sm:px-5 sm:py-2.5 rounded-xl bg-slate-900 text-white text-xs sm:text-sm font-bold shadow-lg shadow-slate-900/20 hover:-translate-y-0.5 transition-all">
                        <span>View Archive Metrics</span>
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                    </a>
                </div>
            </div>

            <!-- Expandable DOJ Release Details -->
            <div id="doj-release-details" class="hidden mt-4 pt-4 border-t border-slate-200/60">
                <div class="bg-gradient-to-br from-slate-50 to-blue-50/50 rounded-xl p-5 space-y-4 relative">
                    <button onclick="document.getElementById('doj-release-details').classList.add('hidden')" class="absolute top-3 right-3 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-white/80 transition-all" aria-label="Close">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">January 30, 2026 &mdash; DOJ Releases ~3.5 Million Pages of Epstein Files</h3>
                        <p class="text-xs text-slate-600 mt-1 leading-relaxed">The U.S. Department of Justice released its largest trove of Jeffrey Epstein investigative files to date, mandated by the Epstein Files Transparency Act. This release includes more than <strong>2,000 videos</strong> and <strong>180,000 images</strong>. We are actively ingesting, OCR-processing, and indexing these documents.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div class="bg-white/70 rounded-lg p-3 border border-slate-200/50">
                            <p class="font-bold text-slate-800 mb-1">FBI Network &amp; Draft Indictment</p>
                            <p class="text-slate-600 leading-relaxed">Includes the FBI Victim Network Chart mapping Epstein's network and timeline of alleged abuse, plus a 2007 draft indictment revealing charges were prepared against Epstein and three assistants before the plea deal.</p>
                        </div>
                        <div class="bg-white/70 rounded-lg p-3 border border-slate-200/50">
                            <p class="font-bold text-slate-800 mb-1">High-Profile Correspondence</p>
                            <p class="text-slate-600 leading-relaxed">Emails involving Elon Musk (2012&ndash;2014 island invitations), Prince Andrew (photos and Buckingham Palace invitations post-2008 conviction), Bill Gates, Steve Bannon, and other public figures.</p>
                        </div>
                        <div class="bg-white/70 rounded-lg p-3 border border-slate-200/50">
                            <p class="font-bold text-slate-800 mb-1">Media &amp; Forensic Content</p>
                            <p class="text-slate-600 leading-relaxed">2,000+ videos and 180,000 images seized from Epstein properties. Note: The DOJ briefly removed files on Feb 2, 2026 after redaction errors exposed names/faces of ~100 victims.</p>
                        </div>
                        <div class="bg-white/70 rounded-lg p-3 border border-slate-200/50">
                            <p class="font-bold text-slate-800 mb-1">900+ &ldquo;Pizza&rdquo; Email References</p>
                            <p class="text-slate-600 leading-relaxed">Viral online discussion around 900+ mentions of "pizza" in the emails. Analysts note these appear to be literal references to catering and social gatherings.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                        <a href="/news.php" class="group bg-gradient-to-br from-red-50 to-white rounded-lg p-3 border border-red-200/60 hover:border-red-300 hover:shadow-md hover:shadow-red-100/50 transition-all block">
                            <p class="font-bold text-red-800 mb-1 flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" /></svg>
                                In the News
                                <svg class="w-3 h-3 ml-auto text-red-400 opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </p>
                            <p class="text-slate-600 leading-relaxed">Major media coverage of the Epstein file releases, including reporting from NYT, Washington Post, and other outlets covering new revelations from the DOJ documents.</p>
                        </a>
                        <a href="/photos.php" class="group bg-gradient-to-br from-purple-50 to-white rounded-lg p-3 border border-purple-200/60 hover:border-purple-300 hover:shadow-md hover:shadow-purple-100/50 transition-all block">
                            <p class="font-bold text-purple-800 mb-1 flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1.5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                Popular Photos
                                <svg class="w-3 h-3 ml-auto text-purple-400 opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </p>
                            <p class="text-slate-600 leading-relaxed">The most-viewed images from the seized Epstein properties, including photos of high-profile visitors, properties, and other evidence released by the DOJ.</p>
                        </a>
                        <a href="/orders.php" class="group bg-gradient-to-br from-amber-50 to-white rounded-lg p-3 border border-amber-200/60 hover:border-amber-300 hover:shadow-md hover:shadow-amber-100/50 transition-all block">
                            <p class="font-bold text-amber-800 mb-1 flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" /></svg>
                                Amazon Orders
                                <svg class="w-3 h-3 ml-auto text-amber-400 opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </p>
                            <p class="text-slate-600 leading-relaxed">Amazon purchase records found in the Epstein files revealing items ordered for various properties, providing insight into operations and day-to-day activities.</p>
                        </a>
                    </div>

                    <p class="text-[11px] text-slate-400 italic">Our pipeline is continuously processing new documents. Check the <a href="/stats.php" class="text-blue-500 hover:text-blue-700 hover:underline">Stats page</a> for real-time ingestion progress.</p>
                </div>
            </div>
        </div>
    </div>

    <a href="/ask.php" class="ask-fab" aria-label="Open Ask Epstein Files">
        <img src="/jeffrey-epstein.png" alt="Ask Epstein Files">
        <div>
            <small>Ask Epstein Files</small>
            <span class="text-sm font-semibold">Chat with the archive</span>
        </div>
    </a>
    <a href="/contact.php" class="feedback-fab" aria-label="Send feedback about Epstein Suite">
        <div>
            <small>Feedback</small>
            <span class="text-sm font-semibold">Suggest improvements</span>
        </div>
    </a>