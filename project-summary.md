# Project Summary: Epstein Suite

> **Last Updated:** 2025-12-22
> **Status:** Active / UI Overhaul Phase

## Overview
**Epstein Suite** packages the DOJ’s Epstein releases into a cohesive set of productivity-style web apps (Search, Drive, Mail, Contacts, Flights, Analytics) so journalists and investigators can browse, verify, and cite evidence without hunting through raw PDFs. Recent work focused on polishing the homepage hero, adding Ask AI sample prompts + FAQ schema, Productizing dataset/SoftwareApplication structured data, and tightening internal links so Google and SGE recognize the brand.

## Tech Stack
- **Frontend/Backend:** PHP 8.4 (Native) + TailwindCSS
- **Database:** MySQL 8.0 (InnoDB, FULLTEXT Search)
- **Styling:** “Suite” aesthetic (Inter + rounded cards)
- **Scraping:** Python 3.13 + Playwright (Headless Browser)
- **OCR:** Tesseract + pdf2image
- **AI Analysis:** OpenAI GPT-4o (Summaries, Entity Extraction, Ask AI)

## Core Suite Features
1.  **Epstein Search** (`index.php`):
    - Google-style hero with quick stats, sample prompts, FAQ schema.
    - Instant search across documents, emails, flights, entities.
2.  **Epstein Drive** (`drive.php`):
    - Folder/File browser interface for exploring Data Sets 1-7.
    - Dataset views now emit `Dataset` JSON-LD for SEO.
3.  **Epstein Mail** (`email_client.php`):
    - Gmail-style interface for browsing extracted extracted communications.
    - Features: Inbox, Search, Read status, "Compose" (mock).
4.  **Epstein Contacts** (`contacts.php`):
    - Google Contacts-style directory of extracted People, Organizations, and Locations.
    - Filter by type and sort by mention frequency.
5.  **Epstein Flights** (`flight_logs.php`):
    - Google Flights-style interface for searching manifests.
    - Filter by aircraft tail numbers, passengers, or routes.
6.  **Epstein Analytics** (`stats.php`):
    - Google Analytics-style dashboard for project metrics.
    - Tracks total documents, AI processing status, and top entities.
7.  **Ask Epstein (Beta)** (`ask.php`):
    - RAG chatbot referencing the suite’s data with citations + follow-ups.
    - Auto-prefill sample prompts from the homepage, SoftwareApplication JSON-LD.

## Architecture
- **Web Root:** `/epstein.kevinchamplin.com/`
- **Shared Components:** `includes/header_suite.php` (App launcher, search bar, user profile).
- **Scripts:** `/epstein.kevinchamplin.com/scripts/` (Python backend for scraping/processing).
- **Storage:** `/storage/documents/` (Local file storage).

## Safety & Compliance
The AI prompts explicitly forbid un-redacting names or identifying victims/minors. The system focuses solely on public figures and investigative leads explicitly mentioned in the text.
