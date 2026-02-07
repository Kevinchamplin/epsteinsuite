# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Epstein Suite is a searchable transparency web application providing public access to DOJ Epstein-related documents, emails, photos, and flight logs. It combines a PHP web application with a Python data ingestion pipeline and OpenAI-powered analysis to index 4,700+ documents from DOJ, FBI Vault, and House Oversight sources.

## Tech Stack

- **Backend:** PHP 8.4 (strict typing, PSR-12), no framework — flat PHP files as routes
- **Database:** MySQL 8.0 (InnoDB, FULLTEXT indexes, utf8mb4)
- **Frontend:** TailwindCSS 3.4 + vanilla JS, D3.js (entity graphs), Leaflet.js (flight maps)
- **Data Pipeline:** Python 3.11 (Playwright, pdf2image, pytesseract, OpenAI API)
- **AI:** OpenAI gpt-5-nano for summaries/entity extraction, text-embedding-3-small for vector search
- **Server:** AlmaLinux 8, Apache, PHP-FPM

## Development Commands

```bash
# Start local dev server
php -S localhost:8000

# Check PHP syntax
php -l *.php && php -l includes/*.php && php -l api/*.php

# Full ingestion pipeline
cd scripts && source venv/bin/activate
python3 run_ingest_pipeline.py --limit 200 --ai-limit 200 --browser-fallback

# Individual pipeline steps
python3 download_and_ocr.py --limit 200          # Download & OCR
python3 generate_ai_summaries.py --limit 100      # AI summaries
python3 extract_emails.py --limit 200             # Email extraction
php scripts/analyze_flights.php                    # Flight scoring
php scripts/generate_embeddings.php                # Vector embeddings

# Process single document
python3 download_and_ocr.py --document-id 2815
python3 generate_ai_summaries.py --document-id 2815

# Tail logs
tail -f storage/logs/download_ocr.log
tail -f storage/logs/ai_summary.log
tail -f storage/logs/pipeline.log
```

## Architecture

### Request Flow

Pages are flat PHP files at the root (index.php, ask.php, drive.php, email_client.php, flight_logs.php, contacts.php, stats.php, etc.) that map directly to URLs. Each page includes shared layout from `includes/header_suite.php` and `includes/footer_suite.php`.

### Key Includes

- **`includes/db.php`** — PDO singleton via `db()` function; `env_value()` reads `.env` file
- **`includes/cache.php`** — File-based `Cache` class with TTL (files in `/cache/*.cache`). Use `Cache::remember($key, $callback, $ttl)` for cached queries
- **`includes/ai_helpers.php`** — OpenAI API integration, session management, UUID generation, citation retrieval
- **`includes/header_suite.php`** / **`includes/footer_suite.php`** — Shared HTML layout, nav, SEO meta tags

### API Endpoints (`api/`)

All return JSON. Key endpoints:
- `api/ask.php` (POST) — RAG chatbot with OpenAI, returns answer + document citations
- `api/insights.php` (GET) — Entity network graph data for D3.js
- `api/manual_download.php` (POST) — Trigger document download
- `api/reprocess_document.php` (POST) — Re-run OCR/AI on a document
- `api/toggle_star.php` (POST) — Star/unstar emails

### Admin (`admin/`)

Protected by HTTP Basic Auth (`ADMIN_USER`/`ADMIN_PASSWORD` from .env). `admin/_auth.php` is the auth middleware included at the top of admin pages.

### Data Pipeline (5 stages)

1. **Source Discovery** (Python) — Scrapers populate `documents` table with `pending` status
2. **OCR Processing** (Python) — `download_and_ocr.py` downloads files → pdf2image → Tesseract → `pages` table
3. **AI Enrichment** (Python/PHP) — gpt-5-nano summaries and entity extraction → `documents.ai_summary`, `document_entities`
4. **Flight Analysis** (PHP) — Significance scoring (1-10) for flight logs
5. **Vector Embeddings** (PHP) — OpenAI text-embedding-3-small, cosine similarity search PHP-side

### Database Schema

Defined in `config/schema.sql`. Core tables: `documents` (metadata + status lifecycle: pending → downloaded → processed), `pages` (OCR text per page, FULLTEXT indexed), `entities`, `document_entities` (many-to-many), `emails` (FULLTEXT indexed), `flight_logs`, `passengers`, `ai_sessions`, `ai_messages`, `ai_citations`.

## Conventions

- All PHP files use `declare(strict_types=1)` and type hints
- All database queries use PDO prepared statements (never interpolate user input)
- Database access via the `db()` singleton — never instantiate PDO directly
- Config values come from `.env` via `env_value('KEY')` — never hardcode credentials
- File-based cache with `Cache::get/set/delete/clear/remember` — clear after bulk processing
- Document files stored in `storage/documents/`, logs in `storage/logs/`
- AI prompts explicitly forbid un-redacting names or identifying victims

## Remote Database Access

The production MySQL database is hosted at `815hosting.com`. From a local Mac, connect using Python `mysql.connector` (the local `mysql` CLI won't work due to `mysql_native_password` auth plugin incompatibility with MySQL 9.x client).

```bash
# Run schema migrations against production
cd scripts && source venv/bin/activate
python3 migrate_remote.py

# Run ad-hoc SQL against production
python3 migrate_remote.py --sql "SELECT COUNT(*) FROM documents"
python3 migrate_remote.py --sql "DESCRIBE photo_views"
```

`scripts/migrate_remote.py` reads credentials from `.env` (`DB_USERNAME`, `DB_PASSWORD`, `DB_NAME`) and connects to `815hosting.com`. The local `.env` has `DB_HOST=localhost` because the PHP app runs on the same production server — but from a dev machine, use `migrate_remote.py` instead.

## Environment Variables (`.env`)

```
DB_HOST, DB_NAME, DB_USERNAME, DB_PASSWORD
OPENAI_API_KEY
ADMIN_USER, ADMIN_PASSWORD, ADMIN_KEY
```

## OpenAI GPT-5 Family API Reference

This project uses `gpt-5-nano` via the **Chat Completions API** (`client.chat.completions.create`). Key rules:

### Model Hierarchy
| Model | Best for |
|---|---|
| gpt-5.2 | Complex reasoning, broad world knowledge, multi-step agentic tasks |
| gpt-5-mini | Cost-optimized reasoning and chat |
| gpt-5-nano | High-throughput tasks: summarization, classification, extraction |

### Chat Completions API Parameters for gpt-5-nano
- **`max_completion_tokens`** (required) — NOT `max_tokens` (deprecated/unsupported). Set high (8000-16000) because GPT-5 models are reasoning models that consume output tokens for internal "tokens of thought" before producing visible output. A low budget causes empty responses.
- **`reasoning_effort`** (string, top-level) — Controls reasoning token usage. For gpt-5-nano: `"minimal"`, `"low"`, `"medium"` (default), `"high"`. Use `"minimal"` for extraction/summarization to minimize wasted reasoning tokens.
- **`temperature`** — NOT supported on gpt-5-nano. Only works on gpt-5.1/5.2 with `reasoning_effort="none"`. Omit entirely.
- **`response_format`** — Structured output with `json_schema` is supported. Use `{"type": "json_schema", "json_schema": {"name": "...", "strict": True, "schema": {...}}}`.

### Responses API vs Chat Completions API (IMPORTANT)
The scripts use **Chat Completions API**. Parameter formats differ between APIs:
- Chat Completions: `reasoning_effort="minimal"` (top-level string)
- Responses API: `reasoning={"effort": "minimal"}` (nested dict) — DO NOT use this format with Chat Completions

### Cost Tracking (per 1M tokens)
- gpt-5-nano: $0.05 input / $0.40 output
- gpt-5-mini: (use for higher quality when needed)

### Common Pitfalls
1. `max_tokens` → use `max_completion_tokens` instead (raises error)
2. `temperature` → omit entirely for gpt-5-nano (raises error)
3. `reasoning={"effort": ...}` → use `reasoning_effort="..."` for Chat Completions (raises error)
4. Low `max_completion_tokens` (e.g. 500) → reasoning tokens exhaust budget, empty content returned
5. GPT-5 models generate internal reasoning tokens that are invisible but consume the output token budget

## Detailed Documentation

- `TECH.md` — Full tech stack, pipeline architecture, infrastructure details
- `INGESTION.md` — Data sources, script reference with all CLI flags, troubleshooting, cron setup
