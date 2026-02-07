# Processing Priority System & SEO Topic Pages

> How we prioritize document processing for maximum SEO impact and how the topic landing pages work.
> Last updated: February 6, 2026

---

## Overview

The system has two parts that work together:

1. **Processing Priority** — A triage system that ensures high-traffic documents (Trump, Musk, Gates, Prince Andrew, etc.) get OCR'd and AI-summarized before the 200K+ document backlog.
2. **SEO Topic Pages** — Keyword-targeted landing pages at `/topics/{slug}` that capture search traffic for the most Googled Epstein-related queries, with FAQ schema for rich snippets.

---

## Part 1: Processing Priority System

### How It Works

Every document in the `documents` table has a `processing_priority` column (TINYINT, default 5). Lower number = processed first. The pipeline queries use `ORDER BY processing_priority ASC`, so P0 docs get OCR'd and summarized before P5 docs.

### Priority Scale

| Priority | Label | What it matches | Example |
|----------|-------|-----------------|---------|
| **P0** | Critical | FBI Investigations, NTOC, high-traffic names in title | `Trump`, `Musk`, `Gates`, `Prince Andrew` |
| **P1** | High | SDNY Cases, Death Investigation/MCC, FBI 302s | `SDNY`, `Yahoo.Mail`, `MCC`, `FBI.302` |
| **P2** | Medium | OIG Records, UK Royals, political figures | `Mandelson`, `Barak`, `Bannon`, `Ferguson` |
| **P3** | Normal | Tech/billionaire correspondence, flights, island images | `flight`, `manifest`, `EP_IMAGES`, `ISLAND` |
| **P5** | Default | Everything else | Bulk DOJ pages without keyword matches |

### Files Involved

| File | Purpose |
|------|---------|
| `scripts/set_processing_priorities.php` | The priority assignment script — contains all rules |
| `scripts/run_ingest_pipeline.py` | Pipeline orchestrator — calls priority script as Step 0 |
| `scripts/download_and_ocr.py` | OCR — queries `ORDER BY processing_priority ASC` |
| `scripts/generate_ai_summaries.py` | AI summaries — queries `ORDER BY processing_priority ASC` |
| `migrate_processing_priority.php` | Migration — adds column + index (idempotent) |

### Database Requirements

```sql
-- Column (added by migration)
ALTER TABLE documents ADD COLUMN processing_priority TINYINT DEFAULT 5 AFTER status;

-- Compound index for priority-based queries
ALTER TABLE documents ADD INDEX idx_documents_priority_status (processing_priority, status);
```

The migration script `migrate_processing_priority.php` handles this idempotently — safe to run multiple times.

### Running the Priority Script

```bash
# From the scripts/ directory on the server:

# Dry run — shows what would change, changes nothing
php set_processing_priorities.php

# Apply — updates documents with lower priorities
php set_processing_priorities.php --apply

# Reset + apply — sets everything back to 5, then re-applies all rules
php set_processing_priorities.php --apply --reset
```

**Key behavior:** The script only *upgrades* priority (sets a lower number). A document already at P0 won't get bumped to P2 even if a P2 rule matches. This means rules are safe to overlap — the most important rule wins.

### How Rules Work

Each rule is a tuple of `[priority, description, SQL WHERE clause]` in the `$rules` array:

```php
$rules = [
    [0, 'FBI Investigations (NTOC, Trump tips)', "
        (d.data_set LIKE '%FBI%' OR d.data_set LIKE '%Source 5%')
        OR d.title REGEXP 'NTOC|National Threat Operations|FBI.*(302|Interview|Investigation)'
    "],
    // ... more rules
];
```

The script runs each rule as:
```sql
UPDATE documents d
SET processing_priority = {priority}
WHERE processing_priority > {priority}
AND ({where_clause})
```

### Adding New Priority Rules

Edit `scripts/set_processing_priorities.php` and add a new entry to the `$rules` array:

```php
// Example: Prioritize documents mentioning a new person of interest
[1, 'New Person of Interest', "
    d.title REGEXP 'Person Name|Alias'
    OR d.source_url REGEXP 'specific_file_pattern'
"],
```

Then run:
```bash
php set_processing_priorities.php           # Dry run first
php set_processing_priorities.php --apply   # Apply if counts look right
```

### Pipeline Integration

The pipeline orchestrator (`run_ingest_pipeline.py`) runs priority assignment as **Step 0** before any processing:

```
Step 0: php set_processing_priorities.php --apply   ← Auto-runs every pipeline execution
Step 1: download_and_ocr.py      (ORDER BY processing_priority ASC)
Step 2: process_media.py
Step 3: generate_ai_summaries.py  (ORDER BY processing_priority ASC)
Step 4: extract_emails.py
Step 5: enrich_flight_data.py
Step 6: generate_embeddings.py
```

- Step 0 is **non-fatal** — if it errors, the pipeline continues
- Skip with `--skip-priorities` flag
- The nightly cron (#5 in CRONS.md) runs at 1:15am daily, so priorities are refreshed every night

### Cron Integration

The daily pipeline cron (Plesk Scheduled Task #5) already runs `run_ingest_pipeline.py`, which now includes Step 0 automatically. No cron changes needed.

The hourly OCR cron (#4) runs `download_and_ocr.py` directly (no Step 0), but it still respects `ORDER BY processing_priority ASC`, so any previously-prioritized documents are processed first.

### Checking Priority Distribution

The script prints a distribution table after every run:

```
=== Current Priority Distribution ===
  P0: 257 total (162 processed, 23 pending)
  P2: 13 total (8 processed, 0 pending)
  P3: 1846 total (1843 processed, 3 pending)
  P5: 204979 total (17418 processed, 186808 pending)
```

You can also check manually:
```sql
SELECT processing_priority, COUNT(*) as cnt,
       SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
       SUM(CASE WHEN status IN ('pending', 'downloaded') THEN 1 ELSE 0 END) as pending
FROM documents
GROUP BY processing_priority
ORDER BY processing_priority;
```

---

## Part 2: SEO Topic Landing Pages

### How It Works

A single PHP file (`topic.php`) serves 10+ keyword-targeted landing pages via clean URLs. Each page is optimized for a specific high-traffic Google search query.

### Files Involved

| File | Purpose |
|------|---------|
| `topic.php` | The landing page PHP file — contains all topic definitions and rendering |
| `.htaccess` | URL rewrite rules for clean `/topics/slug` URLs |
| `sitemap.php` | Dynamic sitemap — includes all topic page URLs |

### URL Structure

```
/topics/                              → Topics index (lists all topics)
/topics/elon-musk-epstein-emails      → Individual topic page
/topics/prince-andrew-epstein-photos  → Individual topic page
/topics/nonexistent-slug              → 404 page (noindex)
/topic.php                            → Topics index (direct access)
/topic.php?slug=elon-musk-epstein-emails → Individual topic (direct access)
```

### Current Topics

| Slug | Target Search Query | Priority |
|------|-------------------|----------|
| `elon-musk-epstein-emails` | "Elon Musk Epstein emails" | Very high volume |
| `prince-andrew-epstein-photos` | "Prince Andrew Epstein photos" | Very high volume |
| `epstein-flight-logs-trump` | "Epstein flight logs Trump" | Very high volume |
| `bill-gates-epstein` | "Bill Gates Epstein" | Very high volume |
| `peter-mandelson-epstein` | "Peter Mandelson Epstein" | High (UK traffic) |
| `howard-lutnick-epstein` | "Howard Lutnick Epstein" | High (political) |
| `steve-bannon-epstein` | "Steve Bannon Epstein" | Medium |
| `epstein-island-photos` | "Epstein island photos" | Very high volume |
| `epstein-clinton-flights` | "Clinton Epstein flights" | Very high volume |
| `sarah-ferguson-epstein` | "Sarah Ferguson Epstein" | High (UK traffic) |

### What Each Topic Page Includes

1. **SEO metadata** — Unique `<title>`, `meta description`, `og:title`, `og:description`, canonical URL
2. **FAQPage schema** — 3 Q&A pairs per topic → eligible for Google FAQ rich snippets
3. **BreadcrumbList schema** — Search > Topics > Topic Name
4. **Editorial intro** — 1-2 paragraph summary of what the archive contains for this topic
5. **Source context box** — Amber callout with provenance/caveats about the documents
6. **Related documents** — Dynamic grid of up to 12 matching documents from the database (cached 1 hour)
7. **FAQ accordion** — Expandable Q&A section matching the schema
8. **CTA section** — Dark banner linking to Search, Drive, and Ask AI

### How Topic Data Is Structured

Each topic is a PHP array in `topic.php`:

```php
'elon-musk-epstein-emails' => [
    'title' => 'Elon Musk & Jeffrey Epstein Emails',           // <title> tag
    'h1' => 'Elon Musk & Jeffrey Epstein: Email Correspondence', // Page heading
    'meta_description' => '...',    // Google snippet (max 160 chars)
    'og_description' => '...',      // Social sharing description
    'search_terms' => ['Elon Musk', 'Musk'],  // DB search for related docs
    'intro' => '...',               // Editorial paragraph
    'context' => '...',             // Source context callout
    'faq' => [                      // FAQ schema + accordion
        ['q' => '...', 'a' => '...'],
        ['q' => '...', 'a' => '...'],
        ['q' => '...', 'a' => '...'],
    ],
],
```

### Adding a New Topic

1. **Add the topic array** to the `$topics` array in `topic.php`:

```php
'new-person-epstein' => [
    'title' => 'New Person & Jeffrey Epstein Documents',
    'h1' => 'New Person & Jeffrey Epstein: Connection Details',
    'meta_description' => 'Read New Person and Jeffrey Epstein documents from DOJ files...',
    'og_description' => 'DOJ-released documents about New Person and Jeffrey Epstein...',
    'search_terms' => ['New Person', 'PersonLastName'],
    'intro' => 'Paragraph describing what the archive contains...',
    'context' => 'Source provenance and caveats...',
    'faq' => [
        ['q' => 'Question 1?', 'a' => 'Answer 1.'],
        ['q' => 'Question 2?', 'a' => 'Answer 2.'],
        ['q' => 'Question 3?', 'a' => 'Answer 3.'],
    ],
],
```

2. **Add the slug to the sitemap** in `sitemap.php` (in the `$topicSlugs` array):

```php
$topicSlugs = [
    'elon-musk-epstein-emails',
    // ... existing slugs ...
    'new-person-epstein',  // Add here
];
```

3. **Upload both files** and clear sitemap cache:

```bash
# Upload
curl -T topic.php --user 'epstein815:74u6$9Jwg' ftp://815hosting.com/topic.php
curl -T sitemap.php --user 'epstein815:74u6$9Jwg' ftp://815hosting.com/sitemap.php

# Clear sitemap cache (or wait up to 1 hour for TTL expiry)
# The cache key is 'sitemap_xml_v3' — delete via FTP or wait for the 5am cache clear cron
```

4. **Verify:**

```bash
curl -s -o /dev/null -w "%{http_code}" "https://epsteinsuite.com/topics/new-person-epstein"
# Should return 200
```

No `.htaccess` changes needed — the existing rewrite `^topics/([a-z0-9-]+)$` catches any lowercase alphanumeric slug.

### .htaccess Routing

```apache
# Topics index
RewriteRule ^topics/?$ topic.php [L]

# Individual topic pages
RewriteRule ^topics/([a-z0-9-]+)$ topic.php?slug=$1 [L,QSA]
```

### Sitemap Integration

The `sitemap.php` file includes all topic slugs with priority 0.8 and weekly change frequency:

```php
$topicSlugs = [
    'elon-musk-epstein-emails',
    'prince-andrew-epstein-photos',
    // ... all slugs
];
foreach ($topicSlugs as $topicSlug) {
    $addUrl($baseUrl . '/topics/' . $topicSlug, $today, 'weekly', '0.8');
}
```

### Document Matching

Each topic has a `search_terms` array. The page queries the database with:

```sql
SELECT d.id, d.title, d.ai_summary, d.relevance_score, d.file_type, d.created_at
FROM documents d
WHERE d.status = 'processed'
  AND (d.title LIKE '%Elon Musk%' OR d.title LIKE '%Musk%')
ORDER BY d.relevance_score DESC, d.created_at DESC
LIMIT 12
```

Results are cached for 1 hour per topic (cache key: `topic_{slug}_docs`).

### Caching

| Cache Key | TTL | What |
|-----------|-----|------|
| `topic_{slug}_docs` | 1 hour | Related document results for each topic |
| `topic_{slug}_count` | 1 hour | Total document count for each topic |
| `sitemap_xml_v3` | 1 hour | Full sitemap XML |

All caches are cleared by the daily 5am cache clear cron (#8 in CRONS.md).

---

## Part 3: How They Work Together

```
New DOJ release drops
        │
        ▼
Scrapers register 5,000 new docs (priority = 5 default)
        │
        ▼
1:15am cron: run_ingest_pipeline.py
        │
        ├── Step 0: set_processing_priorities.php --apply
        │   └── FBI/Trump/Musk/Gates docs → P0
        │   └── SDNY/MCC/FBI 302 docs → P1
        │   └── OIG/Royals docs → P2
        │   └── Flights/island images → P3
        │   └── Everything else stays P5
        │
        ├── Step 1: OCR (ORDER BY processing_priority ASC)
        │   └── P0 docs processed FIRST
        │
        ├── Step 3: AI summaries (ORDER BY processing_priority ASC)
        │   └── P0 docs get summaries FIRST
        │
        └── Steps 4-6: emails, flights, embeddings
                │
                ▼
        Topic pages at /topics/elon-musk-epstein-emails etc.
        automatically show the newly processed documents
        (cached query refreshes every hour)
                │
                ▼
        Google crawls sitemap → indexes topic pages →
        users searching "Elon Musk Epstein emails" land on our page
        with FAQPage rich snippets
```

---

## Quick Reference

### Run priority assignment manually
```bash
cd /var/www/vhosts/kevinchamplin.com/epstein.kevinchamplin.com/scripts
php set_processing_priorities.php              # Dry run
php set_processing_priorities.php --apply       # Apply
php set_processing_priorities.php --apply --reset  # Reset all, then apply
```

### Run full pipeline manually
```bash
cd /var/www/vhosts/kevinchamplin.com/epstein.kevinchamplin.com/scripts
source venv/bin/activate
python3 run_ingest_pipeline.py --limit 500 --ai-limit 500 --browser-fallback --no-previews --workers 2 --cost-limit 5
```

### Run pipeline WITHOUT priorities
```bash
python3 run_ingest_pipeline.py --skip-priorities --limit 200
```

### Check current priority distribution
```bash
php set_processing_priorities.php  # Dry run shows distribution at the end
```

### Test a topic page
```bash
curl -s -o /dev/null -w "%{http_code}" "https://epsteinsuite.com/topics/elon-musk-epstein-emails"
# Should return 200
```

### Verify sitemap includes topics
```bash
curl -s "https://epsteinsuite.com/sitemap.xml" | grep -c "topics/"
# Should return 10 (or however many topics exist)
```

### Upload after changes
```bash
curl -T topic.php --user 'epstein815:74u6$9Jwg' ftp://815hosting.com/topic.php
curl -T sitemap.php --user 'epstein815:74u6$9Jwg' ftp://815hosting.com/sitemap.php
curl -T scripts/set_processing_priorities.php --user 'epstein815:74u6$9Jwg' ftp://815hosting.com/scripts/set_processing_priorities.php
curl -T scripts/run_ingest_pipeline.py --user 'epstein815:74u6$9Jwg' ftp://815hosting.com/scripts/run_ingest_pipeline.py
```

### Run migration (idempotent, safe to re-run)
```bash
curl "https://epsteinsuite.com/migrate_processing_priority.php"
# Expected: "documents.processing_priority already exists. idx_documents_priority_status index already exists. Migration complete."
```
