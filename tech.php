<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Technology | Epstein Suite';
$last_updated = 'Last updated: December 23, 2025';

require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-1 w-full">
    <section class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-white py-16">
        <div class="max-w-5xl mx-auto px-6 space-y-6">
            <p class="text-xs uppercase tracking-[0.3em] text-slate-300"><?= htmlspecialchars($last_updated) ?></p>
            <h1 class="text-4xl md:text-5xl font-bold leading-tight">How the Epstein Suite runs</h1>
            <p class="text-lg text-slate-200 max-w-3xl">
                Public-source intelligence demands transparency and resilience. This page explains the key
                technologies behind our PHP suite, ingestion infrastructure, and secure data handling—without exposing
                any private credentials or operational secrets.
            </p>
        </div>
    </section>

    <section class="max-w-5xl mx-auto px-6 py-14 space-y-10">
        <div class="grid gap-6 md:grid-cols-2">
            <article class="p-6 bg-white border border-slate-200 rounded-2xl shadow-sm space-y-4">
                <header>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Suite at a glance</p>
                    <h2 class="text-xl font-semibold text-slate-900">PHP-first productivity interface</h2>
                </header>
                <ul class="space-y-3 text-slate-700 text-sm">
                    <li>PHP 8.4 with strict typing, PSR-12 formatting, and Composer autoloading.</li>
                    <li>Custom MVC-inspired layout: standalone page scripts combined with shared navigation and layout components.</li>
                    <li>TailwindCSS utilities layered with Material-inspired cards for a unified productivity-suite UI.</li>
                    <li>Vanilla JS helpers plus lightweight Alpine-style behaviors for modals, filters, and async actions.</li>
                    <li>Media served through a hardened delivery layer that sanitizes file paths before streaming anything to the browser.</li>
                </ul>
            </article>

            <article class="p-6 bg-white border border-slate-200 rounded-2xl shadow-sm space-y-4">
                <header>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Automation</p>
                    <h2 class="text-xl font-semibold text-slate-900">Python ingestion & AI summarization</h2>
                </header>
                <ul class="space-y-3 text-slate-700 text-sm">
                    <li>Python 3.11 virtual environments power scraping, downloads, OCR, and AI pipelines.</li>
                    <li>Playwright, pdf2image, PyMuPDF, Tesseract, and Pillow enhance scans before OCR.</li>
                    <li>OpenAI GPT-4o summaries + entity extraction via Structured Outputs for deterministic JSON.</li>
                    <li>MySQL Connector tracks ingestion status, AI logs, and entity relationships.</li>
                    <li>CLI scripts support batching, worker pools, and rate limiting to respect public sources.</li>
                </ul>
            </article>

            <article class="p-6 bg-white border border-slate-200 rounded-2xl shadow-sm space-y-4">
                <header>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Data backbone</p>
                    <h2 class="text-xl font-semibold text-slate-900">Database & storage layers</h2>
                </header>
                <ul class="space-y-3 text-slate-700 text-sm">
                    <li>MySQL 8.0 (InnoDB) hosts documents, AI summaries, entities, emails, and flight logs.</li>
                    <li>Full-text indexes enable high-signal search across OCR pages and metadata.</li>
                    <li>Redundant storage keeps documents, previews, and logs segmented from the public web root.</li>
                    <li>Edge caches reduce repeated upstream hits and can be invalidated through internal admin tools.</li>
                    <li>Backups mirror critical datasets to off-site object storage via boto3-powered maintenance scripts.</li>
                </ul>
            </article>

            <article class="p-6 bg-white border border-slate-200 rounded-2xl shadow-sm space-y-4">
                <header>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hosting & ops</p>
                    <h2 class="text-xl font-semibold text-slate-900">Infrastructure & security guardrails</h2>
                </header>
                <ul class="space-y-3 text-slate-700 text-sm">
                    <li>AlmaLinux 8 VPS running Apache + PHP-FPM; HTTPS enforced via managed certificates.</li>
                    <li>Automation jobs execute over SSH/cron with virtualenv isolation and environment-scoped secrets.</li>
                    <li>Strict separation of configuration secrets, prepared statements everywhere, CSRF protection, and explicit anti-doxxing rules.</li>
                    <li>Robots policies keep private endpoints hidden, while admin tools require signed keys plus IP allowlists.</li>
                    <li>Operational monitoring relies on structured logs and audit trails, with roadmap items for automated alerting.</li>
                </ul>
            </article>
        </div>

        <div class="p-6 bg-slate-900 text-slate-100 rounded-2xl shadow-inner space-y-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-slate-800 text-slate-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </span>
                <h2 class="text-lg font-semibold">Privacy & Safety Standards</h2>
            </div>
            <p class="text-sm text-slate-300 leading-relaxed">
                Every ingestion job respects DOJ/FBI access rules, honors removal requests, and avoids exposing
                redacted victims or minors. We ship updates through documented scripts, log every OCR/scrape run, and
                review anomalies manually before publishing new material.
            </p>
            <div class="flex flex-wrap gap-3 text-sm">
                <span class="px-3 py-1 rounded-full bg-slate-800/80 border border-slate-700">Strict PDO queries</span>
                <span class="px-3 py-1 rounded-full bg-slate-800/80 border border-slate-700">Rate-limited scraping</span>
                <span class="px-3 py-1 rounded-full bg-slate-800/80 border border-slate-700">Tesseract preprocessing</span>
                <span class="px-3 py-1 rounded-full bg-slate-800/80 border border-slate-700">OpenAI Structured Outputs</span>
            </div>
        </div>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border border-slate-200 rounded-2xl p-6 bg-white shadow-sm">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Want to go deeper?</h3>
                <p class="text-sm text-slate-600">
                    Engineers can review our full TECH.md internally for contributor details. Public readers can browse the
                    Drive to see how these systems surface raw DOJ documents.
                </p>
            </div>
            <div class="flex gap-3">
                <a href="/drive.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition">
                    Browse the archive
                </a>
                <a href="/contact.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-full border border-slate-300 text-slate-700 text-sm font-semibold hover:bg-slate-50 transition">
                    Contact the team
                </a>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
