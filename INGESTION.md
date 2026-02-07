# Epstein Files Ingestion Pipeline

> Last updated: February 3, 2026

## Quick Start

```bash
# From the project root on the server:
cd /var/www/vhosts/kevinchamplin.com/epstein.kevinchamplin.com/scripts
source venv/bin/activate

# One-command pipeline (OCR + AI + emails + flights + embeddings)
python3 run_ingest_pipeline.py --limit 5000 --ai-limit 2000 --browser-fallback

# Run OCR and AI summaries in parallel (separate terminals)
# Terminal 1 - OCR:
python3 download_and_ocr.py --limit 5000 --workers 4 --no-previews --browser-fallback --sleep 0.1
# Terminal 2 - AI summaries:
python3 generate_ai_summaries.py --limit 2000 --workers 4 --model gpt-4o-mini --cost-limit 20
```

---

## Pipeline Architecture

The ingestion pipeline has 6 stages. Each stage can be run independently.

```
Stage 1: Source Discovery     (scrapers populate `documents` table with status=pending)
Stage 2: Download & OCR       (download_and_ocr.py)
Stage 3: Media Processing     (process_media.py - videos/images metadata)
Stage 4: AI Enrichment        (generate_ai_summaries.py - GPT summaries + entity extraction)
Stage 5: Email Extraction     (extract_emails.py)
Stage 6: Flight & Embeddings  (enrich_flight_data.py, analyze_flights.php, generate_embeddings.php)
```

### Document Status Lifecycle

```
pending -> downloaded -> processed -> (available for AI summary)
                    \-> error       -> (retryable)
                    \-> not_found   -> (skipped permanently)
```

---

## Data Sources

### DOJ Epstein Library (Data Sets 1-12)

| Source | Script | Method |
|--------|--------|--------|
| Data Sets 1-7 | `download_doj_zips.py` | ZIP download (recommended) |
| Data Sets 1-7 | `scrape_doj_datasets.py` | Web scraping (fallback) |
| Data Sets 8-12 | `scrape_doj_datasets_9_12.py` | ZIP + auto-fallback to page scraper |
| All Data Sets | `scrape_doj_disclosures.py` | Playwright BFS crawler (handles age gate) |
| Epstein Library | `scrape_doj_epstein_library.py` | Playwright BFS from seed URLs |
| Court Records | `scrape_doj_disclosures.py` | Playwright (court-records section) |
| FOIA Records | `scrape_doj_disclosures.py` | Playwright (foia section) |

### Other Sources

| Source | Script | Notes |
|--------|--------|-------|
| FBI Vault (8 parts) | `scrape_fbi_vault.py` | Uses Archive.org mirror |
| House Oversight | `scrape_pinpoint.py` | Google Journalist Studio Pinpoint |
| House Oversight | `scrape_gdrive.py` / `scrape_gdrive_deep.py` | Google Drive folders |

---

## Scripts Reference

### Orchestrators

#### `run_ingest_pipeline.py` (primary orchestrator)

Chains all processing stages in one command. Supersedes the older `ingest_pipeline.py`.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--dataset` | str | None | Restrict to a specific `data_set` value |
| `--batch-id` | int | None | Process only documents in a specific batch |
| `--media-type` | str | None | Filter to `pdf`, `video`, or `image` |
| `--limit` | int | 200 | Max docs for OCR stage |
| `--batch-size` | int | 0 | Process in chunks of N (0 = all at once) |
| `--workers` | int | 1 | Concurrent workers for OCR and AI |
| `--sleep` | float | 0.5 | Delay between docs (OCR) |
| `--dpi` | int | 200 | DPI for PDF rendering |
| `--no-previews` | flag | - | Skip preview JPEG generation (faster) |
| `--local-only` | flag | - | Never attempt remote downloads |
| `--browser-fallback` | flag | - | Use Playwright for justice.gov downloads |
| `--max-pages-per-doc` | int | 0 | Cap OCR pages per doc (0 = unlimited) |
| `--ai-limit` | int | 200 | Max docs for AI summary stage |
| `--ai-model` | str | gpt-4o-2024-08-06 | OpenAI model (`gpt-4o-mini` for bulk) |
| `--cost-limit` | float | 50.0 | Stop AI summaries at this USD amount |
| `--skip-ocr` | flag | - | Skip download & OCR stage |
| `--skip-media` | flag | - | Skip media processing stage |
| `--skip-ai` | flag | - | Skip AI summary generation |
| `--skip-flights` | flag | - | Skip flight enrichment |
| `--skip-embeddings` | flag | - | Skip embedding generation |
| `--skip-emails` | flag | - | Skip email extraction |

```bash
# Full pipeline, large batch, cheap AI model
python3 run_ingest_pipeline.py --limit 5000 --batch-size 1000 --no-previews --workers 4 \
  --ai-model gpt-4o-mini --cost-limit 20 --browser-fallback

# OCR only, skip everything else
python3 run_ingest_pipeline.py --limit 5000 --skip-ai --skip-flights --skip-embeddings --skip-emails

# AI summaries only (docs already OCR'd)
python3 run_ingest_pipeline.py --skip-ocr --skip-media --ai-limit 2000 --ai-model gpt-4o-mini

# Specific data set
python3 run_ingest_pipeline.py --dataset "DOJ - Data Set 9" --limit 1000 --browser-fallback
```

#### `ingest_pipeline.py` (legacy)

Older orchestrator. Calls scrapers + processors via subprocess. No CLI flags. Use `run_ingest_pipeline.py` instead.

---

### Stage 2: Download & OCR

#### `download_and_ocr.py`

Downloads documents from source URLs and runs Tesseract OCR. Supports PDFs and images. Uses PyMuPDF (fitz) or pdf2image for PDF rendering.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--limit` | int | 200 | Max docs to process |
| `--dataset` | str | None | Restrict to `data_set` value |
| `--batch-id` | int | None | Process only docs in a specific batch |
| `--sleep` | float | 0.5 | Delay between docs (sequential mode only) |
| `--local-only` | flag | - | Skip remote downloads, only process local files |
| `--dpi` | int | 200 | DPI for PDF rendering |
| `--no-previews` | flag | - | Skip saving preview JPEGs (significant speedup) |
| `--no-preprocess` | flag | - | Skip image preprocessing (grayscale/threshold) |
| `--workers` | int | 1 | Concurrent workers (try 2-4 for bulk) |
| `--max-pages-per-doc` | int | 0 | Cap OCR pages per document (0 = unlimited) |
| `--browser-fallback` | flag | - | Use Playwright for justice.gov failures |
| `--browser-timeout` | int | 75 | Timeout in seconds for browser downloads |

```bash
# Standard run
python3 download_and_ocr.py --limit 200 --browser-fallback

# Fast bulk processing (4 workers, skip previews, short sleep)
python3 download_and_ocr.py --limit 5000 --workers 4 --no-previews --sleep 0.1 --browser-fallback

# Local files only (after ZIP extraction)
python3 download_and_ocr.py --limit 1000 --local-only --no-previews --workers 4

# Cap large docs at 100 pages
python3 download_and_ocr.py --limit 500 --max-pages-per-doc 100

# Single data set
python3 download_and_ocr.py --dataset "DOJ - Data Set 9" --limit 500 --browser-fallback
```

- **Log:** `storage/logs/download_ocr.log`
- **Checkpoint:** `storage/logs/ocr_checkpoint.json` (resumes after interruption)
- **Output:** Files saved to `storage/documents/doc_{id}.{ext}`
- **Tables:** Reads `documents`, writes `pages`, updates `documents.status`

#### `browser_fetcher.py` (helper module)

Not run directly. Imported by `download_and_ocr.py` when `--browser-fallback` is used. Drives a headless Chromium browser through DOJ's Akamai bot challenge and age verification gate.

Features:
- 3-strategy age gate clicking (CSS selectors, Playwright API, JavaScript fallback)
- Robot verification gate handling
- Playwright download event handling — DOJ serves files with `Content-Disposition: attachment`, which triggers a download event instead of a page load. The fetcher uses `page.expect_download()` to catch and save these files, with a fallback to `response.body()` for inline content.
- Session-aware navigation (cookies from age gate carry through to file download)

---

### Stage 3: Media Processing

#### `process_media.py`

Extracts metadata and generates thumbnails for video and image files. Optionally transcribes audio/video via OpenAI Whisper.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--media-type` | str | required | `video` or `image` |
| `--limit` | int | 100 | Max documents to process |
| `--workers` | int | 1 | Concurrent workers |
| `--sleep` | float | 0.5 | Delay between docs |
| `--transcribe` | flag | - | Transcribe via OpenAI Whisper API |
| `--batch-id` | int | None | Process only docs in a specific batch |

```bash
# Extract video metadata + thumbnails
python3 process_media.py --media-type video --limit 50

# Extract image metadata
python3 process_media.py --media-type image --limit 200 --workers 4

# Transcribe videos (uses OpenAI Whisper, costs money)
python3 process_media.py --media-type video --limit 10 --transcribe
```

- **Output:** Thumbnails saved to `storage/previews/doc_{id}_thumb.jpg`
- **Tables:** Reads/writes `documents` (media_duration_seconds, media_width, media_height, media_codec), writes `pages` (transcription segments)
- **Requires:** `ffmpeg` and `ffprobe` for video, Pillow for images

---

### Stage 4: AI Enrichment

#### `generate_ai_summaries.py`

Generates GPT summaries and extracts named entities for documents that have OCR text but no summary.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--limit` | int | 100 | Max docs to process |
| `--workers` | int | 1 | Concurrent workers |
| `--sleep` | float | 0.5 | Delay between docs (sequential mode) |
| `--dataset` | str | None | Restrict to `data_set` value |
| `--batch-id` | int | None | Process only docs in a specific batch |
| `--model` | str | gpt-4o-2024-08-06 | OpenAI model name |
| `--cost-limit` | float | 50.0 | Stop at this cumulative USD cost |
| `--rpm` | int | 0 | Rate limit: max requests per minute (0 = unlimited) |
| `--skip-has-summary` | flag | - | Skip docs that already have summaries |

```bash
# Bulk processing with cheap model
python3 generate_ai_summaries.py --limit 2000 --workers 4 --model gpt-4o-mini --cost-limit 20

# High-quality processing for specific data set
python3 generate_ai_summaries.py --limit 200 --dataset "DOJ - Data Set 1" --model gpt-4o-2024-08-06

# Rate-limited (stay within OpenAI tier limits)
python3 generate_ai_summaries.py --limit 500 --rpm 50

# Resume after cost limit (re-run same command)
python3 generate_ai_summaries.py --limit 2000 --workers 4 --model gpt-4o-mini --cost-limit 20
```

**Cost estimates per model:**

| Model | Cost/doc (approx) | 30,000 docs | Speed |
|-------|-------------------|-------------|-------|
| gpt-4o-2024-08-06 | ~$0.04 | ~$1,200 | Slower |
| gpt-4o-mini | ~$0.004 | ~$120 | ~10x faster |

- **Log:** `ai_summary.log` (in project root)
- **Tables:** Reads `documents` + `pages`, writes `documents.ai_summary`, `entities`, `document_entities`
- **Requires:** `OPENAI_API_KEY` in `.env`

---

### Stage 5: Email Extraction

#### `extract_emails.py`

Parses email headers (From/To/Subject/Date) from OCR text and populates the `emails` table.

No CLI flags. Run as:
```bash
python3 extract_emails.py
```

- **Tables:** Reads `pages`, writes `emails`

---

### Stage 6: Flight & Embeddings

#### `enrich_flight_data.py` (Python)

Enriches flight log records with geocoding, distance calculations, and AI context.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--flight-id` | int | None | Enrich a specific flight by ID |
| `--all` | flag | - | Enrich all flights missing enrichment |
| `--skip-ai` | flag | - | Skip AI summary generation for flights |

```bash
python3 enrich_flight_data.py --all
python3 enrich_flight_data.py --flight-id 42
```

#### `analyze_flights.php`

PHP-based flight significance scoring (1-10 scale).

```bash
php scripts/analyze_flights.php
```

#### `generate_embeddings.php`

Generates OpenAI text-embedding-3-small vectors for cosine similarity search.

```bash
php scripts/generate_embeddings.php
```

---

### Source Discovery Scrapers

#### `scrape_doj_disclosures.py` (primary DOJ scraper)

Playwright-based BFS crawler for all DOJ Epstein pages. Handles Akamai bot protection and age verification gates. Can target specific data sets or crawl everything.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--dataset` | int (multi) | None | Target specific data set numbers (e.g., `--dataset 9 10 11`) |
| `--max-pages` | int | 0 | BFS page limit (0 = unlimited) |
| `--dry-run` | flag | - | List discovered files without inserting into DB |

```bash
# Crawl everything (all data sets, court records, FOIA)
python3 scrape_doj_disclosures.py

# Target specific data sets
python3 scrape_doj_disclosures.py --dataset 9 10 11 12

# Dry run to see what would be found
python3 scrape_doj_disclosures.py --dataset 9 --dry-run

# Limit BFS depth
python3 scrape_doj_disclosures.py --max-pages 50
```

- **Tables:** Reads/writes `documents`
- **Rate limit:** 2-second delay between page navigations (Akamai)
- **Age gate:** Auto-clicks "Yes" on DOJ age verification pages

#### `scrape_doj_datasets_9_12.py`

ZIP-based scraper for DOJ Data Sets 8-12 (January 2026 release). Attempts ZIP download first; auto-falls back to `scrape_doj_disclosures.py` if DOJ returns HTML (Akamai block).

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--dataset` | str | None | Process only a specific data set (e.g., `"Data Set 9"`) |
| `--dry-run` | flag | - | List files without downloading/inserting |
| `--resume` | flag | - | Resume interrupted downloads |
| `--skip-download` | flag | - | Skip ZIP download, process existing ZIPs |
| `--max-disk-gb` | float | 10 | Stop extraction if disk usage exceeds this |

```bash
python3 scrape_doj_datasets_9_12.py
python3 scrape_doj_datasets_9_12.py --dataset "Data Set 9" --resume
python3 scrape_doj_datasets_9_12.py --dry-run
```

- **Log:** `storage/logs/doj_zip_9_12.log`
- **Download dir:** `storage/doj_zips/`
- **Extract dir:** `storage/doj_extracted/`
- **Tables:** Reads/writes `documents`, `ingestion_batches`

#### `scrape_doj_epstein_library.py`

Playwright-based BFS crawler for the main justice.gov/epstein archive and press release pages.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--dry-run` | flag | - | List discovered files without inserting |
| `--max-pages` | int | 200 | Max pages to crawl |
| `--delay` | float | 2.0 | Seconds between navigations |

```bash
python3 scrape_doj_epstein_library.py --max-pages 100
python3 scrape_doj_epstein_library.py --dry-run
```

#### `download_doj_zips.py`

Downloads official DOJ ZIP files for Data Sets 1-7. No CLI flags. Most reliable way to get complete data for the original 7 data sets.

```bash
python3 download_doj_zips.py
```

#### `scrape_doj_datasets.py`

Web scraper for DOJ Data Sets 1-7 pages. No CLI flags. Use `download_doj_zips.py` instead when possible.

```bash
python3 scrape_doj_datasets.py
```

#### `scrape_fbi_vault.py`

Adds FBI Vault files (8 parts) from Archive.org mirror. No CLI flags.

```bash
python3 scrape_fbi_vault.py
```

#### `scrape_pinpoint.py`

Scrapes Google Journalist Studio Pinpoint for House Oversight Epstein Estate documents. No CLI flags. Uses Playwright.

```bash
python3 scrape_pinpoint.py
```

#### `scrape_gdrive.py` / `scrape_gdrive_deep.py`

Scrapes publicly shared Google Drive folders for House Oversight documents. No CLI flags. Uses Playwright.

```bash
python3 scrape_gdrive.py
```

---

### Utility Scripts

#### `batch_process.py`

Efficient batch processor for combined OCR + AI summaries. Useful for processing local ZIP-extracted files.

| Flag | Type | Default | Description |
|------|------|---------|-------------|
| `--continuous` | flag | - | Run until all documents processed |
| `--ocr-only` | flag | - | Only run OCR processing |
| `--ai-only` | flag | - | Only run AI summaries |
| `--ocr-batch` | int | 500 | OCR batch size |
| `--ai-batch` | int | 200 | AI batch size |
| `--delay` | int | 5 | Delay between batches in continuous mode |

```bash
python3 batch_process.py --continuous --ocr-only
python3 batch_process.py --ai-only --ai-batch 500
```

#### `register_local_files.py`

Scans `storage/documents/` and registers all files found in the database. No CLI flags.

```bash
python3 register_local_files.py
```

#### `seed_flight_logs.py`

Seeds the `flight_logs` and `passengers` tables from flight log documents.

#### `extract_flight_logs.py` / `import_flight_pdf.py`

Parse flight log PDFs and import structured data into `flight_logs` and `passengers`.

---

### PHP Scripts

| Script | Description |
|--------|-------------|
| `analyze_flights.php` | Flight significance scoring (1-10 scale) |
| `generate_embeddings.php` | OpenAI text-embedding-3-small vector generation |
| `generate_ai_summaries.php` | PHP-based AI summary generation (legacy) |
| `enrich_flight_data.php` | PHP-based flight enrichment (legacy) |
| `register_existing_documents.php` | Register existing files in DB |
| `simple_register.php` | Simple file registration |
| `seed_fact_bank_insights.php` | Seed the fact bank with curated insights |
| `fix_flight_schema.php` | One-time flight table schema fixes |
| `migrate_document_activity_log.php` | Migration: add document activity log table |
| `log_document_activity.php` | Log document view/download activity |

---

### Migration Scripts

Run migrations via browser or CLI. Each is idempotent (safe to run multiple times).

| Script | Purpose |
|--------|---------|
| `migrate.php` (project root) | Run `config/schema.sql` to create all tables |
| `migrate_media_metadata.php` | Add media columns (duration, width, height, codec) |
| `migrate_batch_tracking.php` | Add `ingestion_batches` table + `documents.batch_id` |
| `migrate_processing_priority.php` | Add `documents.processing_priority` column |
| `scripts/migrate_document_activity_log.php` | Add document activity tracking |

---

## Running on Production

### Server Path

```
/var/www/vhosts/kevinchamplin.com/epstein.kevinchamplin.com/
```

### Activating the venv

```bash
cd /var/www/vhosts/kevinchamplin.com/epstein.kevinchamplin.com/scripts
source venv/bin/activate
```

### Common Workflows

**Initial scrape of a new data set:**
```bash
# 1. Discover documents (scraper populates DB with status=pending)
python3 scrape_doj_disclosures.py --dataset 9

# 2. Download + OCR
python3 download_and_ocr.py --limit 5000 --browser-fallback --workers 4 --no-previews

# 3. AI summaries (can run in parallel with step 2 in another terminal)
python3 generate_ai_summaries.py --limit 2000 --workers 4 --model gpt-4o-mini --cost-limit 20

# 4. Extract emails
python3 extract_emails.py

# 5. Clear cache so stats update
php -r "require 'includes/cache.php'; Cache::clear();"
```

**Resuming after interruption:**
```bash
# Just re-run the same command - it skips already-processed docs
python3 download_and_ocr.py --limit 5000 --browser-fallback --workers 4 --no-previews
```

**Running OCR and AI in parallel (two terminals):**
```bash
# These don't conflict - OCR picks docs without pages, AI picks docs with pages but no summary

# Terminal 1: OCR
python3 download_and_ocr.py --limit 5000 --workers 4 --no-previews --browser-fallback --sleep 0.1

# Terminal 2: AI summaries
python3 generate_ai_summaries.py --limit 2000 --workers 4 --model gpt-4o-mini --cost-limit 20
```

**One-command full pipeline:**
```bash
python3 run_ingest_pipeline.py \
  --limit 5000 --batch-size 1000 \
  --workers 4 --no-previews --browser-fallback \
  --ai-limit 2000 --ai-model gpt-4o-mini --cost-limit 20
```

---

## Database Cleanup Queries

```sql
-- Remove mailto: records that got scraped by mistake
DELETE FROM documents WHERE source_url LIKE '%mailto:%';

-- Mark extensionless court record URLs as not_found (HTML pages, not files)
UPDATE documents SET status = 'not_found'
WHERE source_url LIKE '%/multimedia/Court%'
  AND source_url NOT LIKE '%.pdf'
  AND status = 'error';

-- Check processing status by data set
SELECT data_set, status, COUNT(*) as cnt
FROM documents
GROUP BY data_set, status
ORDER BY data_set, status;

-- Find docs stuck in error
SELECT id, title, status, source_url
FROM documents
WHERE status = 'error'
ORDER BY id DESC LIMIT 20;
```

---

## Log Files

All logs are in `storage/logs/` unless noted.

| Log File | Script | Location |
|----------|--------|----------|
| `download_ocr.log` | `download_and_ocr.py` | `storage/logs/` |
| `ai_summary.log` | `generate_ai_summaries.py` | project root |
| `pipeline.log` | `run_ingest_pipeline.py` | `storage/logs/` |
| `doj_zip_9_12.log` | `scrape_doj_datasets_9_12.py` | `storage/logs/` |
| `doj_zip_download.log` | `download_doj_zips.py` | `storage/logs/` |
| `batch_process.log` | `batch_process.py` | `storage/logs/` |
| `scraper_doj.log` | `scrape_doj_datasets.py` | `storage/logs/` |
| `scraper_fbi.log` | `scrape_fbi_vault.py` | `storage/logs/` |
| `ocr_checkpoint.json` | `download_and_ocr.py` | `storage/logs/` |

---

## Environment Variables

Required in `.env` (project root):

```
DB_HOST=localhost
DB_NAME=epstein_db
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
OPENAI_API_KEY=sk-...
ADMIN_USER=admin
ADMIN_PASSWORD=your_admin_password
ADMIN_KEY=your_random_admin_key
```

---

## Storage Directories

```
storage/
  documents/      # Downloaded PDFs, images, videos (doc_{id}.pdf, etc.)
  previews/       # Page preview JPEGs and video thumbnails
  doj_zips/       # Downloaded DOJ ZIP files
  doj_extracted/  # Extracted ZIP contents
  logs/           # All log files and checkpoints
```

---

## Caching

File-based cache in `/cache/*.cache` with TTL. Clear after bulk processing:

```bash
# Via PHP
php -r "require 'includes/cache.php'; Cache::clear();"

# Via admin UI
# https://epstein.kevinchamplin.com/admin/cache.php?key=YOUR_ADMIN_KEY
```

---

## Rebuilding the Python venv

```bash
cd /var/www/vhosts/kevinchamplin.com/epstein.kevinchamplin.com/scripts
deactivate 2>/dev/null || true
rm -rf venv
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip wheel
pip install -r requirements-full.txt
playwright install chromium
```

---

## Troubleshooting

### DOJ returns HTML instead of files
DOJ uses Akamai bot protection. Use `--browser-fallback` with `download_and_ocr.py` to drive a headless browser through the verification.

### "Page.goto: Download is starting" error
DOJ serves files with `Content-Disposition: attachment`, which causes Playwright's `page.goto()` to raise this error instead of returning a response. Fixed in `browser_fetcher.py` (Feb 2026) by using `page.expect_download()` with `accept_downloads=True` on the browser context. If you see this error, ensure the latest `browser_fetcher.py` is deployed on the server.

### Age verification gate not clicking
The DOJ age gate at `/age-verify?destination=...` uses JS-rendered content. The browser fetcher waits for `networkidle` + 2 seconds, then tries 3 strategies: CSS selectors, Playwright API (`get_by_role`), and JavaScript `el.click()` fallback.

### Google Drive downloads fail
Google Drive has download restrictions for large files. Scripts skip files that require confirmation.

### OCR quality is poor
Try without `--no-preprocess` (preprocessing applies grayscale + threshold). Higher `--dpi` (e.g., 300) improves quality but is slower.

### Rate limiting
- OpenAI API: Use `--rpm 50` to cap requests per minute
- DOJ website: 2-second delay between navigations (hardcoded in scrapers)
- FBI Vault: Use Archive.org mirror for reliability

### Documents keep getting re-processed
The query now excludes `status = 'processed'`. If old records still cycle, verify the updated `download_and_ocr.py` is deployed on the server.
