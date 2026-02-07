<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$baseUrl = 'https://epsteinsuite.com';
$today = date('Y-m-d');

/**
 * Dynamic XML Sitemap
 *
 * Includes:
 *  - Static pages (home, drive, news, ask, etc.)
 *  - Drive folder categories
 *  - All processed documents
 *  - All entities with linked documents
 *  - Timeline year pages
 *
 * Cached for 1 hour to avoid hammering the DB on every crawl.
 */
$xml = Cache::remember('sitemap_xml_v3', function () use ($baseUrl, $today): string {
    $pdo = db();
    $urls = [];

    // Helper to add a URL entry
    $addUrl = function (string $loc, string $lastmod, string $changefreq, string $priority) use (&$urls): void {
        $urls[] = [
            'loc'        => $loc,
            'lastmod'    => $lastmod,
            'changefreq' => $changefreq,
            'priority'   => $priority,
        ];
    };

    // ─── Static Pages ───────────────────────────────────────────────
    $addUrl($baseUrl . '/',                  $today, 'hourly',  '1.0');
    $addUrl($baseUrl . '/drive.php',         $today, 'hourly',  '0.9');
    $addUrl($baseUrl . '/news.php',          $today, 'hourly',  '0.9');
    $addUrl($baseUrl . '/ask.php',           $today, 'daily',   '0.8');
    $addUrl($baseUrl . '/photos.php',        $today, 'daily',   '0.8');
    $addUrl($baseUrl . '/email_client.php',  $today, 'daily',   '0.8');
    $addUrl($baseUrl . '/flight_logs.php',   $today, 'daily',   '0.8');
    $addUrl($baseUrl . '/contacts.php',      $today, 'daily',   '0.7');
    $addUrl($baseUrl . '/insights.php',      $today, 'daily',   '0.7');
    $addUrl($baseUrl . '/timeline.php',      $today, 'daily',   '0.7');
    $addUrl($baseUrl . '/top_findings.php',  $today, 'daily',   '0.7');
    $addUrl($baseUrl . '/profile.php',       $today, 'weekly',  '0.7');
    $addUrl($baseUrl . '/stats.php',         $today, 'daily',   '0.6');
    $addUrl($baseUrl . '/sources.php',       $today, 'weekly',  '0.6');
    $addUrl($baseUrl . '/efta_release.php',  $today, 'weekly',  '0.6');
    $addUrl($baseUrl . '/transparency.php',  $today, 'monthly', '0.5');
    $addUrl($baseUrl . '/roadmap.php',       $today, 'monthly', '0.5');
    $addUrl($baseUrl . '/about.php',         $today, 'monthly', '0.5');
    $addUrl($baseUrl . '/orders.php',        $today, 'daily',   '0.7');
    $addUrl($baseUrl . '/contact.php',       $today, 'monthly', '0.4');
    $addUrl($baseUrl . '/press_kit.php',     $today, 'monthly', '0.4');
    $addUrl($baseUrl . '/advertising.php',   $today, 'monthly', '0.3');
    $addUrl($baseUrl . '/business.php',      $today, 'monthly', '0.3');
    $addUrl($baseUrl . '/tech.php',          $today, 'monthly', '0.3');
    $addUrl($baseUrl . '/privacy.php',       $today, 'monthly', '0.3');
    $addUrl($baseUrl . '/terms.php',         $today, 'monthly', '0.3');

    // ─── Topic Landing Pages ──────────────────────────────────────────
    $topicSlugs = [
        'elon-musk-epstein-emails',
        'prince-andrew-epstein-photos',
        'epstein-flight-logs-trump',
        'bill-gates-epstein',
        'peter-mandelson-epstein',
        'howard-lutnick-epstein',
        'steve-bannon-epstein',
        'epstein-island-photos',
        'epstein-clinton-flights',
        'sarah-ferguson-epstein',
    ];
    foreach ($topicSlugs as $topicSlug) {
        $addUrl($baseUrl . '/topics/' . $topicSlug, $today, 'weekly', '0.8');
    }

    // ─── Drive Folder Categories ────────────────────────────────────
    $folders = [
        'Court Records',
        'FOIA',
        'House Disclosures',
        'House Oversight',
        'DOJ Disclosures',
    ];
    foreach ($folders as $folder) {
        $addUrl(
            $baseUrl . '/drive.php?folder=category-' . rawurlencode($folder),
            $today, 'daily', '0.8'
        );
    }

    // ─── All Processed Documents ────────────────────────────────────
    try {
        $stmt = $pdo->query(
            "SELECT id, COALESCE(updated_at, created_at) AS lastmod
             FROM documents
             WHERE status = 'processed'
             ORDER BY id"
        );
        while ($doc = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lastmod = $doc['lastmod'] ? date('Y-m-d', strtotime($doc['lastmod'])) : $today;
            $addUrl(
                $baseUrl . '/document.php?id=' . (int)$doc['id'],
                $lastmod, 'weekly', '0.7'
            );
        }
    } catch (Exception $e) {
        // Continue without documents
    }

    // ─── Entity Pages ───────────────────────────────────────────────
    try {
        $stmt = $pdo->query(
            "SELECT e.id, MAX(COALESCE(d.updated_at, d.created_at)) AS lastmod
             FROM entities e
             JOIN document_entities de ON e.id = de.entity_id
             JOIN documents d ON d.id = de.document_id
             GROUP BY e.id
             ORDER BY e.id"
        );
        while ($entity = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $lastmod = $entity['lastmod'] ? date('Y-m-d', strtotime($entity['lastmod'])) : $today;
            $addUrl(
                $baseUrl . '/entity.php?id=' . (int)$entity['id'],
                $lastmod, 'weekly', '0.6'
            );
        }
    } catch (Exception $e) {
        // Continue without entities
    }

    // ─── Timeline Year Pages ────────────────────────────────────────
    try {
        $stmt = $pdo->query(
            "SELECT DISTINCT YEAR(COALESCE(document_date, created_at)) AS yr
             FROM documents
             WHERE COALESCE(document_date, created_at) IS NOT NULL
             ORDER BY yr"
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['yr']) {
                $addUrl(
                    $baseUrl . '/timeline.php?year=' . (int)$row['yr'],
                    $today, 'monthly', '0.5'
                );
            }
        }
    } catch (Exception $e) {
        // Continue without timeline years
    }

    // ─── Build XML ──────────────────────────────────────────────────
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>{$u['lastmod']}</lastmod>\n";
        $xml .= "    <changefreq>{$u['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$u['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';

    return $xml;
}, 3600); // Cache for 1 hour

echo $xml;
