# Tech Stack Overview

> **Last updated:** 2026-02-05

## Web Application (PHP Suite)
- **Language & Runtime:** PHP 8.4 with strict typing, PSR-12 formatting, Composer autoloading.
- **Framework Style:** Flat PHP files as routes — no framework. Shared UI via `includes/header_suite.php` and `includes/footer_suite.php`.
- **Routing:** Flat PHP files mapped directly to suite apps (Search, Drive, Mail, Contacts, Flights, Analytics, Ask AI, Stats, etc.).
- **Templating:** Native PHP templates with shared components for navigation, modals, and layout.
- **Styling:** TailwindCSS 3.4 + custom tokens. Inter font family, responsive grid layouts, Material-inspired cards.
- **Client-Side Enhancements:** Vanilla JS modules for interactions (document actions, modals, async buttons). D3.js for entity network graphs, Leaflet.js for flight maps. Fetch API for manual downloads and reprocessing.
- **Security & Privacy:** `.env`-driven secrets via `env_value()`, PDO prepared statements (never interpolate user input), `.htaccess` blocks access to `.env` and dotfiles, explicit prohibitions on unmasking/redacting victim info.
- **Media Serving:** `serve.php` implements a multi-stage path resolver with HTTP Range header support for video seeking (206 Partial Content).
- **AI Chat:** `ask.php` + `api/ask.php` provide RAG-powered chatbot using OpenAI with document citations and session tracking.

### Data Ingestion Pipeline (6-Stage Architecture)

Processing pipeline for the 32,000+ document archive spanning DOJ Data Sets 1-12, FBI Vault, and House Oversight sources.

```
Stage 1: Source Discovery     (scrapers populate documents table with status=pending)
Stage 2: Download & OCR       (download_and_ocr.py - Tesseract + PyMuPDF)
Stage 3: Media Processing     (process_media.py - video/image metadata + thumbnails)
Stage 4: AI Enrichment        (generate_ai_summaries.py - GPT-4o summaries + entities)
Stage 5: Email Extraction     (extract_emails.py)
Stage 6: Flight & Embeddings  (analyze_flights.php, generate_embeddings.php)
```

**Stage 1: Source Discovery (Python)**
- Multiple scrapers for different data sources (DOJ, FBI Vault, House Oversight)
- `scrape_doj_disclosures.py` — Playwright BFS crawler with Akamai bot protection and age gate handling
- `scrape_doj_datasets_9_12.py` — ZIP-based scraper for Data Sets 8-12 (Jan 2026 release)
- `download_doj_zips.py` — Direct ZIP download for Data Sets 1-7

**Stage 2: Download & OCR (Python)**
- **Script:** `scripts/download_and_ocr.py`
- Downloads files from source URLs, renders PDFs via PyMuPDF/pdf2image, OCRs with Tesseract
- `--browser-fallback` flag drives headless Chromium through DOJ's Akamai bot challenge and age verification gate via `browser_fetcher.py`
- Browser fetcher handles Playwright download events (Content-Disposition: attachment) for DOJ files that trigger downloads instead of inline display
- Supports `--workers N` for parallel processing, `--dataset` filtering, checkpoint-based resumability

**Stage 3: Media Processing (Python)**
- **Script:** `scripts/process_media.py`
- Extracts video metadata via `ffprobe`, generates thumbnails via `ffmpeg`
- Image metadata via Pillow
- Optional OpenAI Whisper transcription for audio/video

**Stage 4: AI Enrichment (Python)**
- **Script:** `scripts/generate_ai_summaries.py`
- Sends OCR text to OpenAI GPT-4o (or gpt-4o-mini for bulk) for summaries + entity extraction
- Cost controls via `--cost-limit`, model selection via `--model`, rate limiting via `--rpm`
- Priority-ordered processing via `documents.processing_priority`

**Stage 5: Email Extraction (Python)**
- **Script:** `scripts/extract_emails.py`
- Parses email headers (From/To/Subject/Date) from OCR text into `emails` table

**Stage 6: Flight Analysis & Embeddings (PHP)**
- `scripts/analyze_flights.php` — Significance scoring (1-10) for flight logs
- `scripts/generate_embeddings.php` — OpenAI text-embedding-3-small vectors for cosine similarity search (PHP-side calculation, JSON storage in MySQL)

**Orchestrator:** `scripts/run_ingest_pipeline.py` chains all stages with `--skip-*` flags for selective execution.

### Entity Network Visualization (D3.js)
- **Force-Directed Graph:** Visualizes relationships between `PERSON` and `ORG` entities.
- **Connection Logic:** Edges represent co-occurrence in high-relevance documents.
- **Interactivity:** Click-to-search integration with Semantic Search.

### Interactive Maps & Visualization
- **Leaflet.js:** Powered by CartoDB Voyager tiles.
- **Flight Arcs:** Custom Bezier curve implementation for visual appeal.
- **Dynamic Coloring:** Flight paths color-coded by AI Significance Scores (Red/Orange/Blue).
- **Filters:** Server-side filtering for Aircraft and Year; client-side interaction.

### Operational Notes
- **Virtualenv:** Rebuild instructions (Python 3.11) documented in `INGESTION.md`.
- **Logs:** Stored under `storage/logs/`.
- **Parallelism:** Scripts support `--workers` CLI argument.
- **Automation / Cron Jobs:** Run `scripts/install_epstein_crons.sh` to schedule:
  1. `run_ingest_pipeline.py --browser-fallback --limit 500` nightly @ 01:15.
  2. `download_doj_zips.py` weekly Sunday @ 02:30.
  3. `scrape_doj_disclosures.py` daily @ 06:00 & 18:00.
  4. `log_document_activity.php` daily @ 06:15 & 18:15.
  5. `generate_ai_summaries.py --limit 200 --skip-has-summary` daily @ 03:30.
  6. `download_and_ocr.py --limit 50 --browser-fallback` hourly.

## Feature Reference

Detailed breakdown of each suite feature — route file, data sources, rendering, and ingestion.

### Photos (`photos.php`)

- **Data Source:** `documents` table filtered by media file types (`jpg`, `jpeg`, `png`, `gif`, `webp`, `tif`, `tiff`, plus video and PDF types)
- **Media Serving:**
  - Local files served through `serve.php?id={document_id}` (supports HTTP Range for video seeking)
  - Google Drive URLs converted to `drive.google.com/thumbnail?id={fileId}&sz=w400`
  - Video thumbnails via `video_thumb.php?id={document_id}` with fallback to `<video>` element
  - PDF previews from `storage/previews/doc_{id}_p1.jpg` or placeholder badge
- **Layout:** Responsive grid (2-6 columns based on screen size), images grouped by month/year with sticky headers
- **Filters:** Search by title/description, filter by source (`data_set`), pagination (60 per page)
- **Ingestion:** Part of the general document pipeline — files land in `storage/documents/`, thumbnails generated in `storage/previews/` during Stage 2 (Download & OCR) and Stage 3 (Media Processing)

### Flight Logs (`flight_logs.php`)

- **Data Source:** `flight_logs` table (origin, destination, aircraft, flight_date, coordinates, significance_score, ai_summary) + `passengers` table (names per flight)
- **Map (Leaflet.js):**
  - CartoDB Voyager tile layer
  - Airport markers as circle markers sized by visit frequency
  - Flight arcs using custom Bezier curve implementation
  - Color-coded by significance score: Red (9-10 "Shocking"), Orange (7-8 "Notable"), Blue (4-6 "Routine")
  - Hover popups with airport codes, date, aircraft, AI summary
- **Manifest Display:** Cards with colored left border indicator, date, origin → destination, aircraft, AI summary in italic box, passenger names as clickable blue badge pills (link to search)
- **Ingestion:**
  1. `extract_flight_logs.py` — parses flight data from OCR text
  2. `enrich_flight_data.py` — fills lat/lng coordinates from airport database
  3. `analyze_flights.php` — calculates significance_score (1-10) based on VIP passenger detection
  4. `generate_ai_summaries.py` — creates ai_summary during document enrichment

### Drive (`drive.php`)

- **Data Source:** `documents` table (metadata, status, ai_summary) + `pages` table (OCR text for search)
- **Virtual Folder Structure:** Mapped to `data_set` column and URL patterns:
  - Root folders: Court Records, FOIA Records, House Disclosures, House Oversight, DOJ Disclosures, Email Archive, Email Attachments
  - DOJ Data Sets 1-12
- **Layout:** 2-column — sidebar filters + main content grid/list with file type icons, title, file size, status, AI summary snippet (200 chars), OCR completion indicator
- **Search Scoring:** Relevance-weighted ranking:
  - Exact title match: 100pts
  - Title LIKE: 50pts
  - AI summary match: 30pts
  - Description match: 20pts
  - `data_set` match: 10pts
  - OCR text boost: +20pts (if `pages.ocr_text` matches)
  - Falls back to FULLTEXT indexes for sub-3-char queries
- **Filters:** By type (PDF, Image, Video, Doc, Excel, Email), by size (<5MB, 5-25MB, 25-100MB, 100-500MB, 500+MB), by sort (Recent, OCR Pending, OCR Complete)
- **API Endpoints:** `api/manual_download.php` (POST — trigger download), `api/reprocess_document.php` (POST — re-run OCR/AI)
- **Ingestion:** Full 6-stage pipeline — scrapers → download/OCR → media processing → AI enrichment → storage in `storage/documents/` and `storage/previews/`

### Email Client (`email_client.php`)

- **Data Source:** `emails` table — sender, recipient, cc, subject, sent_at, body, folder, attachments_count, is_starred, is_read (FULLTEXT indexed on sender, recipient, subject, body)
- **Layout:** Multi-folder mailbox interface
  - Folders: Inbox (`folder = 'inbox'`), Sent (`folder = 'sent'`), Starred (`is_starred = 1`), Attachments (`attachments_count > 0`)
  - Email list: subject, from, to, date, snippet, attachment count, star toggle
  - Pagination: 25 per page
- **Search:** FULLTEXT via `MATCH ... AGAINST` across sender/recipient/subject/body
- **Person Filter:** Name variant matching — "Last, First" ↔ "First Last" + simplified forms
- **Additional Filters:** From address, To address, Subject, Body text, Has attachments checkbox
- **API Endpoint:** `api/toggle_star.php` (POST — star/unstar via AJAX)
- **Ingestion:** `extract_emails.py` parses email headers and body from OCR text → `emails` table

### Document Viewer (`document.php?id={id}`)

- **Data Source:** `documents` (metadata, ai_summary), `pages` (OCR text per page), `entities`/`document_entities` (extracted entities), `emails` (if document is an email)
- **Content Display:**
  - Document metadata: title, source, data_set, status, dates
  - AI summary (purple highlight box, if available)
  - Extracted entities (top 20, ordered by frequency)
  - Related documents (linked by shared entities via `document_entities`)
  - OCR text pages (paginated, 5 per page with direct page links)
  - Email metadata (From/To/CC/Subject/Date/Attachments) if applicable
  - Report broken file button (POST with reason → `document_file_reports` table)
- **Media Display:** Local files via `serve.php`, Google Drive embed/thumbnail, HTML5 `<video>` player for video files

### Contacts / Entities (`contacts.php`, `entity.php?id={id}`)

- **Data Source:** `entities` table (name, type: PERSON/ORG/LOCATION) + `document_entities` (mention frequency per document)
- **Layout:** Sidebar type filter + main entity grid
- **Entity Cards:** Name, type badge (Person: blue, Organization: pink), mention count, click-through to detail page
- **Filters:** All Entities, People, Organizations, Locations + search by name (LIKE query)
- **Detail Page (`entity.php`):** Mention count, full list of related documents
- **Ingestion:** `generate_ai_summaries.py` extracts entities via GPT-4o → `entities` + `document_entities` tables

### Insights / Network Graph (`insights.php`)

- **Data Source:** Top 40 most-mentioned PERSON/ORG entities with edge weights from document co-occurrence counts
- **Visualization (D3.js v6+ force-directed layout):**
  - Nodes: Sized by mention frequency, colored by type (blue = PERSON, pink = ORG), draggable with physics simulation
  - Links: Thickness proportional to document co-occurrences, red if weight ≥ 7, orange otherwise
  - Hover: Highlights connected nodes; Click: searches entity name in Drive
- **Stats Cards:** Total entities tracked, strongest connection, top people/orgs, top entity pairs
- **API Endpoint:** `api/insights.php` (GET — returns entity stats, top entities, breakdown, recent activity, data set distribution)

### News / Intelligence Feed (`news.php`)

- **Data Source:** `news_articles` table — title, url, source_name, published_at, ai_summary, ai_headline, shock_score (0-100), score_reason, status
- **Layout:** Card grid with "Shock Score" badges (red/orange/yellow based on score)
- **Card Content:** AI-generated headline, source name + published date, AI summary snippet, score reason
- **Sort Options:** By Shock Score (default, highest first), By Date (newest first)
- **Auto-Refresh:** JavaScript polls for new articles every 3 hours
- **Ingestion:** Google News RSS feeds (Epstein-related) scraped by cron every 3 hours → GPT-4o analyzes each article for ai_headline, shock_score, score_reason
- **API Endpoint:** `api/news.php` (GET — returns top N articles with sorting)

### ASK / RAG Chatbot (`ask.php` + `api/ask.php`)

- **Data Source:** `ai_sessions`, `ai_messages`, `ai_citations` (conversation tracking) + `documents`/`pages` (retrieval context)
- **Layout:** Chat interface with user messages (left) and assistant responses (right, markdown formatted), cited documents sidebar with relevance scores, typing indicator, copy-to-clipboard
- **Session Management:** Client stores `ask_session` cookie; server tracks via `ai_sessions` table
- **RAG Workflow:**
  1. Receive question via POST `api/ask.php`
  2. Parse intent — document follow-ups vs. general queries
  3. Retrieve top 6 context chunks via `ai_retrieve_context()` using cosine similarity or FULLTEXT search
  4. Fetch last 12 messages for conversation context (sanitized to remove victim identifiers)
  5. On-demand summary generation via `ai_generate_document_summary()` if question targets a specific doc without one
  6. Send question + context + history to OpenAI GPT-4o
  7. Log response with token count and latency → `ai_messages`
  8. Extract document citations → `ai_citations` with relevance scores
  9. Return JSON: `{ok, answer, citations, session_token}`

### Search / Homepage (`index.php`)

- **Data Source:** Multi-source search across `documents`, `pages`, `emails`, `flight_logs`, `entities`, `news_articles` — all FULLTEXT indexed
- **Layout:** Large hero search bar on homepage, results grouped by type (documents, emails, flights, photos, entities, news)
- **Scoring:** Same relevance-weighted system as Drive (exact title > LIKE > summary > description > data_set)
- **Search Logs:** Queries tracked in `search_logs` table for popular query analysis

### Stats Dashboard (`stats.php`)

- **Data Source:** Aggregated queries across `documents`, `pages`, `entities`, `emails`, `flight_logs`, `document_entities`
- **Dashboard Widgets:**
  - Overview cards: total docs, processed %, pending count, entities tracked, flights, emails
  - Processing progress: bar charts for OCR %, AI summary %, entity extraction %
  - Data set distribution: table with doc counts, AI coverage, OCR coverage per data set
  - Entity stats: breakdown by PERSON/ORG/LOCATION with counts
  - Top entities: ranked by mention frequency with clickable links
  - Timeline chart: documents added per day (last 30 days)
  - File type distribution, recent activity (last 8 documents)
- **Caching:** 5-minute TTL via `Cache::remember()` for expensive aggregate queries

### API Endpoints Summary

| Endpoint | Method | Purpose | Response |
|----------|--------|---------|----------|
| `api/ask.php` | POST | RAG chatbot | `{ok, answer, citations, session_token}` |
| `api/insights.php` | GET | Entity stats/insights | `{documents, entities, flights, emails, top_entities, summary}` |
| `api/news.php` | GET | News feed | `[{id, title, shock_score, ai_summary, ...}]` |
| `api/manual_download.php` | POST | Trigger document download | `{ok, status, message}` |
| `api/reprocess_document.php` | POST | Re-run OCR/AI on a document | `{ok, queued}` |
| `api/toggle_star.php` | POST | Star/unstar email | `{ok, is_starred}` |

## Database & Storage
- **Engine:** MySQL 8.0 (InnoDB, FULLTEXT indexes on documents, pages, entities, emails).
- **Schema:** `config/schema.sql` — core tables:
  - `documents` — metadata, status lifecycle (pending → downloaded → processed), media metadata columns, processing priority, batch tracking
  - `pages` — OCR text per page (FULLTEXT indexed)
  - `entities` / `document_entities` — named entity extraction (many-to-many)
  - `emails` — parsed email metadata (FULLTEXT indexed)
  - `flight_logs` / `passengers` — flight data with significance scoring
  - `ai_sessions` / `ai_messages` / `ai_citations` — RAG chatbot conversation tracking
  - `ai_fact_bank` — curated facts for AI context
  - `ingestion_batches` — batch tracking for large-scale ingestion runs
  - `ingestion_submissions` — user-submitted evidence (URLs, uploads)
  - `search_logs` — popular search query tracking
  - `ingestion_errors` — per-document error tracking
  - `document_file_reports` — user-reported file issues
  - `contact_messages` — contact form submissions
- **Migrations:** Idempotent PHP scripts in `scripts/migrate_*.php` (check INFORMATION_SCHEMA before ALTER TABLE)
- **Caching:** File-based cache (`/cache/*.cache`) with TTL via `Cache::remember()`. Admin UI for invalidation.
- **File Storage:** `storage/documents/` for PDFs/images/videos, `storage/previews/` for thumbnails, `storage/logs/` for logs.

## Hosting & Infrastructure
- **Server OS:** AlmaLinux 8 (CentOS-compatible) on shared/managed VPS.
- **Web Server:** Apache with `.htaccess` rewrites, served under `https://epstein.kevinchamplin.com/`.
- **Process Management:** PHP-FPM via Apache module; Python scripts run via SSH/cron.
- **System Packages:** Development Tools group (gcc, make, headers) + Python 3.11, Tesseract, libmupdf, freetype, ffmpeg for OCR/media processing.
- **Background Jobs:** Cron-based automation via `install_epstein_crons.sh` + manual SSH-triggered runs for large batches.
- **Monitoring:** Log tailing (`storage/logs/download_ocr.log`, `ai_summary.log`, `pipeline.log`).
- **Deployment:** FTP via VSCode SFTP extension (`.vscode/sftp.json`) with `uploadOnSave: true`.
- **Security:**
  - `.env` in project root, blocked by `.htaccess` (FilesMatch + Files directives).
  - Admin routes gated via HTTP Basic Auth (`ADMIN_USER`/`ADMIN_PASSWORD` from .env).
  - HTTPS enforced; robots.txt tuned for public vs. private paths.

## Developer Workflow
1. Clone repo locally or SSH into server environment.
2. Copy `.env.example` → `.env`, update DB + API keys.
3. `cd scripts && python3.11 -m venv venv && source venv/bin/activate`.
4. `pip install -r requirements-full.txt` (installs Playwright, PyMuPDF, OCR deps).
5. `playwright install chromium` (for browser-based DOJ downloads).
6. Run ingestion steps as needed (`download_and_ocr.py`, `generate_ai_summaries.py`, `extract_emails.py`).
7. Verify logs, clear cache (`php -r "require 'includes/cache.php'; Cache::clear();"`), and refresh UI.

See `INGESTION.md` for detailed script reference, CLI flags, and troubleshooting.
