# Epstein Files Transparency Project

A viral, searchable database application for the DOJ Epstein Files, designed to provide public transparency through a searchable interface, OCR capabilities, and AI summarization.

## Project Structure

- **public/**: Web root (optional, if using specific public folder).
- **includes/**: PHP helper files (database connection).
- **config/**: Database schema (`schema.sql`).
- **scripts/**: Python automation scripts.
  - `scraper.py`: Playwright-based scraper to handle queues and fetch bulk zip files.
  - `processor.py`: Downloads files, performs OCR (Tesseract), and uses OpenAI GPT-4o for summarization and entity extraction.
  - `seed_flight_logs.py`: Generates dummy flight log data for testing the UI.
  - `requirements.txt`: Python dependencies.
- **index.php**: Main search interface with dynamic "Recent Documents" and "Top Entities".
- **document.php**: Detailed view for a document showing the AI summary and extracted entities.
- **flight_logs.php**: Searchable table of flight manifests.

## Setup Instructions

### 1. Database Setup

1. Create a MySQL database (default name: `epstein_db`).
2. Import the schema:
   ```bash
   mysql -u root -p epstein_db < config/schema.sql
   ```
3. Update `.env` with your database credentials.

### 2. Python Environment

It is recommended to use a virtual environment.

```bash
# Create and activate venv
python3 -m venv scripts/venv
source scripts/venv/bin/activate

# Install dependencies
pip install -r scripts/requirements.txt
playwright install
```

### 3. Configuration

Ensure your `.env` file in the project root has the following keys:

```ini
DB_HOST=localhost
DB_NAME=epstein_db
DB_USERNAME=root
DB_PASSWORD=
OPENAI_API_KEY=sk-...
```

### 4. Running the Pipeline

**Step 1: Scrape Data**
Finds files on the DOJ site and adds them to the database with `pending` status.
```bash
python scripts/scraper.py
```

**Step 2: Process Data**
Downloads pending files, runs OCR, generates AI summaries/entities, and updates the database.
```bash
python scripts/processor.py
```

**Step 3: Seed Flight Logs (Optional)**
Populates the flight logs table with dummy data for UI testing.
```bash
python scripts/seed_flight_logs.py
```

## Features Implemented

- **Smart Scraping**: Playwright integration to handle waiting rooms/queues.
- **OCR Pipeline**: Uses `pdf2image` and `pytesseract` to make image-based PDFs searchable.
- **AI Intelligence**: GPT-4o integration to summarize legal documents and extract key entities (People, Orgs, Locations).
- **Search Engine**: MySQL Full-Text Search for instant results.
- **Flight Logs**: Dedicated searchable interface for flight manifests.
- **Safety**: AI prompts explicitly forbid un-redacting names or identifying victims.

## Compliance Note

This project focuses on public interest and investigative leads. It is designed to strictly adhere to safety guidelines regarding victim privacy.
