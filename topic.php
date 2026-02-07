<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');

// ─── Topic Definitions ────────────────────────────────────────────────────────
// Each topic targets a high-traffic search query with editorial content,
// document search terms, and FAQ schema for rich snippets.
$topics = [
    'elon-musk-epstein-emails' => [
        'title' => 'Elon Musk & Jeffrey Epstein Emails',
        'h1' => 'Elon Musk & Jeffrey Epstein: Email Correspondence',
        'meta_description' => 'Read the Elon Musk and Jeffrey Epstein emails from the DOJ release. Includes 2012-2014 correspondence about island parties, with AI analysis and original source PDFs.',
        'og_description' => 'DOJ-released emails between Elon Musk and Jeffrey Epstein from 2012-2014, including party invitations and correspondence. Search the full archive.',
        'search_terms' => ['Elon Musk', 'Musk'],
        'intro' => 'The January 2026 DOJ release included emails from 2012-2014 between Elon Musk and Jeffrey Epstein. The most widely cited email shows Musk asking Epstein "What day/night will be the wildest party on your island?" Epstein reportedly attempted to involve Musk in his social circle, though Musk has stated he refused invitations to visit the island.',
        'context' => 'These emails were part of Data Sets 9-12 released under the Epstein Files Transparency Act. Musk has publicly stated he visited Epstein\'s New York townhouse once in 2012 at the invitation of his then-wife Talulah Riley, and that he never visited Epstein\'s island or engaged in any wrongdoing.',
        'faq' => [
            ['q' => 'Did Elon Musk visit Epstein\'s island?', 'a' => 'Musk has denied visiting the island. The released emails show party invitations but no confirmed visit to Little St. James.'],
            ['q' => 'What do the Musk-Epstein emails say?', 'a' => 'The emails from 2012-2014 include discussions about holiday parties. The most cited email has Musk asking about "the wildest party" on Epstein\'s island.'],
            ['q' => 'Where can I read the original emails?', 'a' => 'The original documents are available through the DOJ transparency portal. Epstein Suite provides searchable OCR text with links to source PDFs.'],
        ],
    ],
    'prince-andrew-epstein-photos' => [
        'title' => 'Prince Andrew & Jeffrey Epstein Photos',
        'h1' => 'Prince Andrew & Jeffrey Epstein: Photos & Evidence',
        'meta_description' => 'View Prince Andrew and Jeffrey Epstein photos from DOJ file releases. Includes seized images, Buckingham Palace correspondence, and Virginia Giuffre testimony.',
        'og_description' => 'DOJ-released photos and documents connecting Prince Andrew to Jeffrey Epstein, including seized images and Palace correspondence.',
        'search_terms' => ['Prince Andrew', 'Andrew', 'Mountbatten', 'Buckingham'],
        'intro' => 'DOJ Data Set 10 included previously unreleased photographs of Prince Andrew found among Epstein\'s seized materials. Additionally, emails show Andrew inviting Epstein to Buckingham Palace for "privacy" in 2010, telling him "Come with whomever and I\'ll be here free from 1600ish." Epstein also offered to arrange a meeting between Andrew and a "26, russian, clevere beautiful" woman.',
        'context' => 'These materials emerged from multiple DOJ data sets. Prince Andrew settled a civil lawsuit with Virginia Giuffre in 2022 for an undisclosed sum. The Johanna Sjoberg deposition (May 2016) details the "Spitting Image" puppet photo incident with Andrew and Giuffre.',
        'faq' => [
            ['q' => 'What photos of Prince Andrew were in the Epstein files?', 'a' => 'Data Set 10 included undated photos of Andrew found among Epstein\'s seized materials, along with correspondence and Palace-related emails.'],
            ['q' => 'Did Prince Andrew visit Epstein\'s island?', 'a' => 'Multiple witnesses and flight log entries place Andrew on Epstein\'s island. Andrew has acknowledged staying at Epstein\'s properties.'],
            ['q' => 'What did the Buckingham Palace emails say?', 'a' => 'Emails from 2010 show Andrew inviting Epstein to the Palace and offering privacy for their meeting.'],
        ],
    ],
    'epstein-flight-logs-trump' => [
        'title' => 'Jeffrey Epstein Flight Logs: Trump Flights',
        'h1' => 'Jeffrey Epstein Flight Logs: Donald Trump',
        'meta_description' => 'Search Epstein flight logs mentioning Donald Trump. SDNY prosecutors confirmed Trump took at least 8 Epstein flights between 1993-1996. Browse manifests with AI analysis.',
        'og_description' => 'Confirmed Epstein flight records involving Donald Trump from 1993-1996, including passenger manifests and SDNY prosecution documents.',
        'search_terms' => ['Trump', 'flight', 'manifest', 'N908JE'],
        'intro' => 'SDNY prosecutors confirmed Donald Trump took at least eight flights on Epstein\'s aircraft between 1993 and 1996, often with Ghislaine Maxwell and family members aboard. The DOJ\'s Data Set 12 contains the "National Threat Operations Center" spreadsheet summarizing thousands of unverified FBI hotline tips mentioning Trump. Additionally, the January 2026 release includes an unverified victim claim that Maxwell "presented her" to Trump at a party, which the DOJ labeled "unverified hearsay."',
        'context' => 'Flight logs are sourced from pilot logbooks for aircraft N908JE (Boeing 727 "Lolita Express"), N909JE (Gulfstream II), and other tail numbers. Trump has stated he had a falling out with Epstein and banned him from Mar-a-Lago. The NTOC spreadsheet contains uncorroborated tips submitted to the FBI hotline.',
        'faq' => [
            ['q' => 'How many times did Trump fly on Epstein\'s plane?', 'a' => 'SDNY prosecutors confirmed at least 8 flights between 1993-1996 on Epstein\'s aircraft.'],
            ['q' => 'Did Trump visit Epstein\'s island?', 'a' => 'The flight logs released so far do not show Trump flying to Little St. James. The confirmed flights were primarily between New York and Palm Beach.'],
            ['q' => 'What is the NTOC spreadsheet?', 'a' => 'The National Threat Operations Center spreadsheet in Data Set 12 summarizes unverified FBI hotline tips, including thousands mentioning Trump. These are uncorroborated public submissions.'],
        ],
    ],
    'bill-gates-epstein' => [
        'title' => 'Bill Gates & Jeffrey Epstein Documents',
        'h1' => 'Bill Gates & Jeffrey Epstein: Emails & Allegations',
        'meta_description' => 'Read Bill Gates and Jeffrey Epstein documents from the DOJ release. Draft emails allege affairs and drug procurement. Gates spokesperson called claims "absolutely absurd."',
        'og_description' => 'DOJ-released documents about Bill Gates and Jeffrey Epstein, including draft emails with affair allegations and Gates Foundation connections.',
        'search_terms' => ['Bill Gates', 'Gates'],
        'intro' => 'The January 2026 DOJ release contained draft emails from Epstein alleging that Bill Gates had extramarital affairs on the island and that a Gates Foundation employee helped him "get drugs" for "illicit trysts." A Gates spokesperson called the claims "absolutely absurd and completely false." The documents are classified as draft emails—meaning they were found on Epstein\'s devices but may never have been sent.',
        'context' => 'Gates has acknowledged meeting with Epstein multiple times between 2011 and 2014, stating the meetings were about philanthropy. The New York Times reported in 2019 that Gates visited Epstein\'s New York mansion on multiple occasions. Melinda French Gates cited Bill\'s relationship with Epstein as a factor in their 2021 divorce.',
        'faq' => [
            ['q' => 'What do the Gates-Epstein documents say?', 'a' => 'Draft emails from Epstein allege Gates had affairs on the island and that a foundation employee procured drugs. These are unverified draft emails found on Epstein\'s devices.'],
            ['q' => 'Did Bill Gates visit Epstein\'s island?', 'a' => 'Gates has denied visiting the island. He acknowledged meeting Epstein at his New York residence for philanthropy discussions between 2011-2014.'],
            ['q' => 'How did Gates respond to the allegations?', 'a' => 'A Gates spokesperson called the claims "absolutely absurd and completely false."'],
        ],
    ],
    'peter-mandelson-epstein' => [
        'title' => 'Peter Mandelson & Jeffrey Epstein Files',
        'h1' => 'Lord Peter Mandelson & Jeffrey Epstein',
        'meta_description' => 'Read Peter Mandelson and Jeffrey Epstein documents from DOJ files. Emails suggest Mandelson shared market-sensitive UK government information during the 2008 financial crash.',
        'og_description' => 'DOJ documents showing Lord Mandelson shared confidential UK government information with Epstein during the 2008 financial crisis.',
        'search_terms' => ['Mandelson'],
        'intro' => 'Emails released in January 2026 suggest Lord Peter Mandelson sent Epstein confidential market-sensitive government information regarding the 2008 global financial crash while serving as UK Business Secretary. Mandelson subsequently resigned from the Labour Party following the release of these documents.',
        'context' => 'Mandelson served as Secretary of State for Business, Innovation and Skills from 2008-2010. The leaked emails are part of the DOJ\'s OIG records (Source 6). The revelation prompted significant political fallout in the United Kingdom.',
        'faq' => [
            ['q' => 'What did Mandelson share with Epstein?', 'a' => 'Emails suggest Mandelson shared confidential market-sensitive information about the 2008 financial crash while he was UK Business Secretary.'],
            ['q' => 'What happened after the documents were released?', 'a' => 'Mandelson resigned from the Labour Party following the release of these documents in January 2026.'],
            ['q' => 'Where are the original documents?', 'a' => 'The emails are part of the DOJ transparency release and can be searched on Epstein Suite with links to original source PDFs.'],
        ],
    ],
    'howard-lutnick-epstein' => [
        'title' => 'Howard Lutnick & Jeffrey Epstein Documents',
        'h1' => 'Howard Lutnick & Jeffrey Epstein: Island Visit',
        'meta_description' => 'Read Howard Lutnick and Jeffrey Epstein documents from DOJ files. Lutnick\'s wife emailed Epstein in 2012 about anchoring their boat at his private island for lunch.',
        'og_description' => 'DOJ documents showing Commerce Secretary Howard Lutnick\'s wife emailed Epstein about visiting his private island by boat in 2012.',
        'search_terms' => ['Lutnick'],
        'intro' => 'Documents from the January 2026 DOJ release show that the wife of Howard Lutnick—now the U.S. Commerce Secretary—emailed Jeffrey Epstein in 2012 about anchoring their boat to visit his private island for lunch. Lutnick served as CEO of Cantor Fitzgerald before his appointment to the Cabinet.',
        'context' => 'Howard Lutnick was confirmed as U.S. Secretary of Commerce in 2025. The email correspondence was found among Epstein\'s seized communications and released as part of the EFTA data sets.',
        'faq' => [
            ['q' => 'What is the Lutnick-Epstein connection?', 'a' => 'Lutnick\'s wife emailed Epstein in 2012 about anchoring their boat at his private island for lunch. No allegations of wrongdoing were made.'],
            ['q' => 'Who is Howard Lutnick?', 'a' => 'Lutnick is the current U.S. Secretary of Commerce, formerly CEO of Cantor Fitzgerald.'],
            ['q' => 'Where can I read the email?', 'a' => 'The email is part of the DOJ transparency release and can be found by searching "Lutnick" on Epstein Suite.'],
        ],
    ],
    'steve-bannon-epstein' => [
        'title' => 'Steve Bannon & Jeffrey Epstein Connection',
        'h1' => 'Steve Bannon & Jeffrey Epstein: Post-Conviction Contact',
        'meta_description' => 'Read Steve Bannon and Jeffrey Epstein documents from DOJ files. Bannon is listed among individuals who maintained contact with Epstein after his 2009 release from prison.',
        'og_description' => 'DOJ documents showing Steve Bannon maintained contact with Jeffrey Epstein after his 2009 release from prison.',
        'search_terms' => ['Bannon'],
        'intro' => 'Steve Bannon is listed in the DOJ files as one of several prominent individuals who communicated with Jeffrey Epstein after his 2009 release from a Florida prison on sex offense charges. The documents place Bannon among a group of political and intellectual figures who maintained contact despite Epstein\'s conviction.',
        'context' => 'Bannon served as White House Chief Strategist under President Trump from January to August 2017. The DOJ files categorize his communications under OIG records alongside other political figures who had post-conviction contact with Epstein.',
        'faq' => [
            ['q' => 'What is the Bannon-Epstein connection?', 'a' => 'DOJ files list Bannon among individuals who communicated with Epstein after his 2009 release from prison.'],
            ['q' => 'When did Bannon communicate with Epstein?', 'a' => 'The communications are documented as occurring after Epstein\'s 2009 release. Specific dates are in the DOJ source documents.'],
            ['q' => 'Where can I find the original documents?', 'a' => 'Search "Bannon" on Epstein Suite to find all related documents with links to original DOJ source PDFs.'],
        ],
    ],
    'epstein-island-photos' => [
        'title' => 'Jeffrey Epstein Island Photos & Evidence',
        'h1' => 'Little St. James Island: Photos & Evidence',
        'meta_description' => 'Browse Jeffrey Epstein island photos from DOJ file releases. Includes property images, seized evidence from Little St. James, and aerial photographs.',
        'og_description' => 'DOJ-released photos and evidence from Jeffrey Epstein\'s private island Little St. James, including property images and seized materials.',
        'search_terms' => ['island', 'Little St. James', 'EP_IMAGES', 'property'],
        'intro' => 'The DOJ releases include property photographs, seized evidence images, and documentation from Jeffrey Epstein\'s private island, Little St. James, in the U.S. Virgin Islands. The island was a central location in the federal investigation, with evidence collected during multiple search warrant executions.',
        'context' => 'Little St. James is a 70-acre island in the U.S. Virgin Islands purchased by Epstein in 1998. The FBI executed search warrants on the property in 2019. Evidence seized included electronic devices, photographs, and documents that form part of the SDNY case files.',
        'faq' => [
            ['q' => 'What photos are in the Epstein island files?', 'a' => 'The DOJ releases include property photographs, aerial images, interior documentation, and evidence seized during FBI search warrants at Little St. James.'],
            ['q' => 'Where is Epstein\'s island?', 'a' => 'Little St. James is a 70-acre private island in the U.S. Virgin Islands, purchased by Epstein in 1998.'],
            ['q' => 'Can I browse the photos?', 'a' => 'Yes. Use the Photos section on Epstein Suite to browse all images from DOJ releases, filterable by source dataset.'],
        ],
    ],
    'epstein-clinton-flights' => [
        'title' => 'Bill Clinton & Jeffrey Epstein Flights',
        'h1' => 'Bill Clinton & Jeffrey Epstein: Flight Records',
        'meta_description' => 'Search Bill Clinton and Jeffrey Epstein flight records. Documents confirm Clinton took at least 17 flights on Epstein\'s jet and visited the White House 17 times.',
        'og_description' => 'DOJ-confirmed flight records showing Bill Clinton took 17+ flights on Epstein\'s private jet, plus White House visitor logs.',
        'search_terms' => ['Clinton', 'flight', 'White House'],
        'intro' => 'Documents from the January 2026 DOJ release confirm Bill Clinton took at least 17 flights on Epstein\'s private jet to destinations including Siberia, Morocco, and China. Visitor logs show Epstein visited the White House at least 17 times between 1993 and 1995, with former Clinton aide Mark Middleton facilitating access on at least three occasions. The release also included never-before-seen photographs of Clinton in a hot tub and pool with Ghislaine Maxwell.',
        'context' => 'Clinton\'s representatives maintain the flights were strictly related to Clinton Foundation work and that he broke off contact after the 2006 criminal charges. Emails from 2011 and 2015 in the release show Epstein himself denying that Clinton visited his private island. The House Oversight Committee voted to hold both Clintons in contempt; filmed depositions are scheduled for February 2026.',
        'faq' => [
            ['q' => 'How many flights did Clinton take on Epstein\'s plane?', 'a' => 'Documents confirm at least 17 flights to destinations including Siberia, Morocco, and China.'],
            ['q' => 'Did Clinton visit Epstein\'s island?', 'a' => 'Emails from Epstein himself deny Clinton visited the island. Clinton\'s representatives say contact was limited to foundation work.'],
            ['q' => 'What photos of Clinton were released?', 'a' => 'The January 2026 release included photos of Clinton in a hot tub and pool with Ghislaine Maxwell and a third woman whose face was redacted.'],
        ],
    ],
    'sarah-ferguson-epstein' => [
        'title' => 'Sarah Ferguson & Jeffrey Epstein Documents',
        'h1' => 'Sarah Ferguson & Jeffrey Epstein: Correspondence',
        'meta_description' => 'Read Sarah Ferguson and Jeffrey Epstein documents from DOJ files. Ferguson called Epstein her "brother" and sought his advice before appearing on Oprah Winfrey.',
        'og_description' => 'DOJ-released emails showing Sarah Ferguson called Epstein her "brother" and sought his advice before her Oprah Winfrey appearance.',
        'search_terms' => ['Sarah Ferguson', 'Ferguson'],
        'intro' => 'Emails released in January 2026 show Sarah Ferguson, the Duchess of York, described Jeffrey Epstein as the "brother" she always wanted—sent a year after his 2008 conviction. In a separate 2011 email, Ferguson sought Epstein\'s advice on how to answer questions about their relationship before appearing on Oprah Winfrey\'s show.',
        'context' => 'Ferguson publicly acknowledged in 2011 that Epstein had given her money. The Data Set 11 correspondence includes the Oprah preparation email from May 2011.',
        'faq' => [
            ['q' => 'What did Sarah Ferguson say about Epstein?', 'a' => 'In emails released by the DOJ, Ferguson called Epstein the "brother" she always wanted, sent after his 2008 conviction.'],
            ['q' => 'Did Ferguson seek Epstein\'s advice?', 'a' => 'Yes, a 2011 email shows Ferguson asking Epstein how to answer Oprah Winfrey\'s questions about their relationship.'],
            ['q' => 'Where are the Ferguson-Epstein emails?', 'a' => 'The emails are in Data Set 11 of the DOJ transparency release. Search "Sarah Ferguson" on Epstein Suite to read them.'],
        ],
    ],
];

// ─── Topics index (no slug) or 404 (bad slug) ─────────────────────────────────
if ($slug === '') {
    // Show topics index page
    $page_title = 'Epstein File Topics';
    $meta_description = 'Browse in-depth topic pages covering the most searched Epstein-related subjects: flight logs, emails, photos, and high-profile connections from the DOJ file releases.';
    $og_title = 'Epstein File Topics — In-Depth Coverage';
    $og_description = 'Explore detailed topic pages on Elon Musk emails, Prince Andrew photos, Trump flight logs, Bill Gates documents, and more from the DOJ Epstein releases.';
    $canonical_url = 'https://epsteinsuite.com/topics/';
    require_once __DIR__ . '/includes/header_suite.php';
    ?>
    <main class="flex-grow w-full max-w-5xl mx-auto px-4 py-8">
        <header class="mb-10">
            <h1 class="text-3xl md:text-4xl font-black text-slate-900 mb-4 tracking-tight">Epstein File Topics</h1>
            <p class="text-lg text-slate-600 max-w-3xl">In-depth pages covering the most searched subjects from the DOJ Epstein file releases. Each topic includes source context, related documents, and frequently asked questions.</p>
        </header>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($topics as $topicSlug => $t): ?>
            <a href="/topics/<?= htmlspecialchars($topicSlug) ?>"
               class="block rounded-2xl border border-slate-200 bg-white p-6 hover:border-blue-200 hover:shadow-lg hover:shadow-blue-500/5 hover:-translate-y-0.5 transition-all group">
                <h2 class="text-lg font-bold text-slate-900 mb-2 group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($t['title']) ?></h2>
                <p class="text-sm text-slate-500 leading-relaxed line-clamp-2"><?= htmlspecialchars(mb_substr($t['intro'], 0, 180)) ?>...</p>
                <span class="inline-flex items-center gap-1 mt-3 text-xs font-bold text-blue-600">
                    Read more
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/includes/footer_suite.php';
    exit;
}

if (!isset($topics[$slug])) {
    http_response_code(404);
    $page_title = 'Topic Not Found';
    $meta_description = 'The requested topic page was not found on Epstein Suite.';
    $noindex = true;
    require_once __DIR__ . '/includes/header_suite.php';
    echo '<main class="flex-grow flex items-center justify-center"><div class="text-center py-20"><h1 class="text-3xl font-bold text-slate-800 mb-4">Topic Not Found</h1><p class="text-slate-500 mb-6">The page you\'re looking for doesn\'t exist.</p><a href="/" class="text-blue-600 font-bold hover:underline">Search the archive</a></div></div>';
    require_once __DIR__ . '/includes/footer_suite.php';
    exit;
}

$topic = $topics[$slug];

// ─── Fetch matching documents from DB ──────────────────────────────────────────
$relatedDocs = Cache::remember("topic_{$slug}_docs", function () use ($topic): array {
    try {
        $pdo = db();
        $conditions = [];
        $params = [];
        foreach ($topic['search_terms'] as $i => $term) {
            $conditions[] = "d.title LIKE :term{$i}";
            $params["term{$i}"] = "%{$term}%";
        }
        $where = implode(' OR ', $conditions);
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.ai_summary, d.relevance_score, d.file_type, d.created_at
            FROM documents d
            WHERE d.status = 'processed' AND ({$where})
            ORDER BY d.relevance_score DESC, d.created_at DESC
            LIMIT 12
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}, 3600);

$docCount = Cache::remember("topic_{$slug}_count", function () use ($topic): int {
    try {
        $pdo = db();
        $conditions = [];
        $params = [];
        foreach ($topic['search_terms'] as $i => $term) {
            $conditions[] = "d.title LIKE :term{$i}";
            $params["term{$i}"] = "%{$term}%";
        }
        $where = implode(' OR ', $conditions);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM documents d
            WHERE d.status = 'processed' AND ({$where})
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}, 3600);

// ─── Page metadata ─────────────────────────────────────────────────────────────
$page_title = $topic['title'];
$meta_description = $topic['meta_description'];
$og_title = $topic['title'];
$og_description = $topic['og_description'];
$canonical_url = 'https://epsteinsuite.com/topics/' . $slug;

// BreadcrumbList schema
$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Search', 'item' => 'https://epsteinsuite.com/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Topics'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $topic['title']],
    ],
];

// FAQPage schema
$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($faq) => [
        '@type' => 'Question',
        'name' => $faq['q'],
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => $faq['a'],
        ],
    ], $topic['faq']),
];

$extra_head_tags = [
    '<script type="application/ld+json">' . json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES) . '</script>',
    '<script type="application/ld+json">' . json_encode($faqSchema, JSON_UNESCAPED_SLASHES) . '</script>',
];

require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full max-w-5xl mx-auto px-4 py-8">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-slate-400 mb-6">
        <a href="/" class="hover:text-blue-600">Search</a>
        <span>/</span>
        <span>Topics</span>
        <span>/</span>
        <span class="text-slate-600 font-medium"><?= htmlspecialchars($topic['title']) ?></span>
    </nav>

    <!-- Hero -->
    <header class="mb-10">
        <h1 class="text-3xl md:text-4xl font-black text-slate-900 mb-4 tracking-tight leading-tight">
            <?= htmlspecialchars($topic['h1']) ?>
        </h1>
        <p class="text-lg text-slate-600 leading-relaxed max-w-3xl">
            <?= htmlspecialchars($topic['intro']) ?>
        </p>

        <!-- Quick actions -->
        <div class="flex flex-wrap gap-3 mt-6">
            <a href="/?q=<?= rawurlencode($topic['search_terms'][0]) ?>"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-bold shadow-lg shadow-blue-600/15 hover:bg-blue-700 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Search all documents
            </a>
            <a href="/ask.php"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-sm font-bold border border-slate-200 hover:bg-slate-200 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                Ask AI about this
            </a>
            <a href="/flight_logs.php"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-sm font-bold border border-slate-200 hover:bg-slate-200 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                Flight logs
            </a>
        </div>
    </header>

    <!-- Context -->
    <section class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-10">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <h2 class="text-sm font-bold text-amber-800 mb-1">Source Context</h2>
                <p class="text-sm text-amber-700 leading-relaxed"><?= htmlspecialchars($topic['context']) ?></p>
            </div>
        </div>
    </section>

    <!-- Related Documents -->
    <?php if (!empty($relatedDocs)): ?>
    <section class="mb-12">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Related Documents</h2>
                <p class="text-sm text-slate-500"><?= number_format($docCount) ?> document<?= $docCount !== 1 ? 's' : '' ?> found in the archive</p>
            </div>
            <a href="/?q=<?= rawurlencode($topic['search_terms'][0]) ?>" class="text-sm font-bold text-blue-600 hover:text-blue-700">
                View all &rarr;
            </a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($relatedDocs as $doc): ?>
            <a href="/document.php?id=<?= (int)$doc['id'] ?>"
               class="block rounded-2xl border border-slate-200 bg-white p-5 hover:border-blue-200 hover:shadow-lg hover:shadow-blue-500/5 hover:-translate-y-0.5 transition-all">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest bg-slate-50 px-2 py-0.5 rounded border border-slate-100"><?= htmlspecialchars($doc['file_type'] ?? 'PDF') ?></span>
                    <?php if (!empty($doc['relevance_score']) && (int)$doc['relevance_score'] >= 7): ?>
                    <span class="text-[11px] font-bold text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-100">Score: <?= (int)$doc['relevance_score'] ?>/10</span>
                    <?php endif; ?>
                </div>
                <h3 class="text-sm font-bold text-slate-900 mb-1 leading-snug line-clamp-2"><?= htmlspecialchars($doc['title']) ?></h3>
                <?php if (!empty($doc['ai_summary'])): ?>
                <p class="text-xs text-slate-500 leading-relaxed line-clamp-2"><?= htmlspecialchars(mb_substr($doc['ai_summary'], 0, 200)) ?></p>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- FAQ Section -->
    <section class="mb-12">
        <h2 class="text-xl font-bold text-slate-900 mb-6">Frequently Asked Questions</h2>
        <div class="space-y-4">
            <?php foreach ($topic['faq'] as $faq): ?>
            <details class="group rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <summary class="flex items-center justify-between px-6 py-4 cursor-pointer hover:bg-slate-50 transition-colors">
                    <h3 class="text-sm font-bold text-slate-900 pr-4"><?= htmlspecialchars($faq['q']) ?></h3>
                    <svg class="w-5 h-5 text-slate-400 flex-shrink-0 group-open:rotate-180 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="px-6 pb-4">
                    <p class="text-sm text-slate-600 leading-relaxed"><?= htmlspecialchars($faq['a']) ?></p>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- CTA -->
    <section class="bg-slate-900 rounded-3xl p-8 text-center text-white mb-8">
        <h2 class="text-2xl font-bold mb-3">Explore the Full Archive</h2>
        <p class="text-slate-300 mb-6 max-w-xl mx-auto">Search 4,700+ DOJ Epstein documents, emails, flight logs, and photos with AI-powered analysis and original source PDFs.</p>
        <div class="flex flex-wrap justify-center gap-3">
            <a href="/" class="px-6 py-3 rounded-xl bg-blue-600 text-white font-bold hover:bg-blue-700 transition-colors">Search Documents</a>
            <a href="/drive.php" class="px-6 py-3 rounded-xl bg-white/10 text-white font-bold hover:bg-white/20 transition-colors border border-white/20">Browse Drive</a>
            <a href="/ask.php" class="px-6 py-3 rounded-xl bg-white/10 text-white font-bold hover:bg-white/20 transition-colors border border-white/20">Ask AI</a>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
