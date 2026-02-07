<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$cacheTtl = 300;

$transparencyStats = Cache::remember('transparency_stats', function () {
    $pdo = db();
    $data = [];

    $data['lastIngestion'] = $pdo->query(
        "SELECT MAX(created_at) FROM documents"
    )->fetchColumn() ?: null;

    $data['totalDocuments'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM documents"
    )->fetchColumn();

    $data['processedDocuments'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM documents WHERE status = 'processed'"
    )->fetchColumn();

    $data['totalEntities'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM entities"
    )->fetchColumn();

    $data['totalOcrPages'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM pages WHERE ocr_text IS NOT NULL AND ocr_text != ''"
    )->fetchColumn();

    $data['aiSummaryCount'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM documents WHERE ai_summary IS NOT NULL AND ai_summary != ''"
    )->fetchColumn();

    $data['localCopies'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM documents WHERE local_path IS NOT NULL AND local_path != ''"
    )->fetchColumn();

    return $data;
}, $cacheTtl);

$page_title = 'Transparency | Epstein Suite';
$meta_description = 'Epstein Suite transparency report: redaction policy, source integrity, processing statistics, and DMCA takedown procedures.';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full bg-slate-50">
    <div class="max-w-5xl mx-auto px-6 py-12 space-y-10">

        <div>
            <h1 class="text-3xl font-bold text-slate-900 mb-3">Transparency</h1>
            <p class="text-slate-600 leading-relaxed max-w-3xl">
                Epstein Suite is a public-source intelligence tool. Every document in this archive originates from
                official government releases. This page documents our data handling practices, source verification
                procedures, and content policies.
            </p>
        </div>

        <!-- Table of Contents -->
        <nav class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-3">On This Page</h2>
            <div class="flex flex-wrap gap-3">
                <a href="#redaction" class="px-3 py-1.5 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-blue-50 hover:text-blue-700 transition-colors">Redaction Policy</a>
                <a href="#source-integrity" class="px-3 py-1.5 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-blue-50 hover:text-blue-700 transition-colors">Source Integrity</a>
                <a href="#transparency-report" class="px-3 py-1.5 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-blue-50 hover:text-blue-700 transition-colors">Transparency Report</a>
                <a href="#dmca" class="px-3 py-1.5 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-blue-50 hover:text-blue-700 transition-colors">DMCA / Takedown</a>
            </div>
        </nav>

        <!-- Section 1: Redaction Policy -->
        <section id="redaction" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <h2 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </span>
                Redaction Policy
            </h2>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Victim & Minor Protection</h3>
                <p class="text-slate-700 leading-relaxed">
                    This project maintains a strict prohibition against identifying victims or minors. Our AI analysis
                    prompts explicitly forbid un-redacting names, reversing government redactions, or attempting to
                    identify individuals protected by law. This applies to all automated processing including GPT-4o
                    summaries and entity extraction.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">PII Protection</h3>
                <p class="text-slate-700 leading-relaxed">
                    Personally Identifiable Information (PII) of non-public figures is handled with care. When our
                    systems detect sensitive personal data (phone numbers, addresses, financial details) belonging to
                    private individuals, that content is flagged for review. We rely on government redactions as the
                    primary protection layer and supplement with AI-assisted screening.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">AI-Assisted Review</h3>
                <p class="text-slate-700 leading-relaxed">
                    Every document in the archive passes through an AI enrichment stage (OpenAI GPT-4o) that generates
                    summaries and extracts entities. These AI prompts are configured to respect existing redactions and
                    avoid speculative identification. AI summaries are navigation aids and may contain inaccuracies &mdash;
                    users should always verify against the original source document.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Manual Review Process</h3>
                <p class="text-slate-700 leading-relaxed">
                    Content flagged by users or automated systems undergoes manual review. We prioritize reports
                    involving exposed victim identities, incorrect redactions, or sensitive personal information.
                    Removal requests are typically acknowledged within 72 hours.
                </p>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Report a concern</div>
                <p class="text-sm text-slate-700">
                    If you believe content should be redacted or removed, contact:
                    <a class="text-blue-700 hover:underline" href="mailto:admin@kevinchamplin.com">admin@kevinchamplin.com</a>
                </p>
            </div>
        </section>

        <!-- Section 2: Source Integrity -->
        <section id="source-integrity" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <h2 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </span>
                Source Integrity
            </h2>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Official Government Sources</h3>
                <p class="text-slate-700 leading-relaxed mb-4">
                    Every document in this archive originates from one of three official government sources.
                    We do not host leaked, stolen, or unofficial materials. Each document links back to its
                    original source URL for independent verification.
                </p>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="border border-slate-200 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center text-xs">1</span>
                            <h4 class="font-semibold text-slate-800 text-sm">U.S. Department of Justice</h4>
                        </div>
                        <p class="text-xs text-slate-600">EFTA releases (Data Sets 1&ndash;12), court records, and BOP footage via justice.gov/epstein</p>
                    </div>
                    <div class="border border-slate-200 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 bg-slate-100 rounded-full flex items-center justify-center text-xs">2</span>
                            <h4 class="font-semibold text-slate-800 text-sm">FBI Records Vault</h4>
                        </div>
                        <p class="text-xs text-slate-600">FOIA releases from the Federal Bureau of Investigation via vault.fbi.gov</p>
                    </div>
                    <div class="border border-slate-200 rounded-xl p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-6 h-6 bg-red-100 rounded-full flex items-center justify-center text-xs">3</span>
                            <h4 class="font-semibold text-slate-800 text-sm">House Oversight Committee</h4>
                        </div>
                        <p class="text-xs text-slate-600">Epstein Estate documents released by Congress via oversight.house.gov</p>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">6-Stage Processing Pipeline</h3>
                <p class="text-slate-700 leading-relaxed mb-4">
                    Documents pass through a structured ingestion pipeline to make them searchable and analyzable.
                    No content is altered &mdash; original files are preserved alongside our processed versions.
                </p>

                <div class="grid gap-3 md:grid-cols-2">
                    <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full flex-shrink-0 mt-0.5">1</span>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Source Discovery</div>
                            <p class="text-xs text-slate-600 mt-0.5">Automated tools download and index new documents from official government portals into our database.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full flex-shrink-0 mt-0.5">2</span>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Download &amp; OCR</div>
                            <p class="text-xs text-slate-600 mt-0.5">Files are downloaded and processed through Tesseract OCR to extract machine-readable text from scanned documents.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full flex-shrink-0 mt-0.5">3</span>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Media Processing</div>
                            <p class="text-xs text-slate-600 mt-0.5">Video and image files have metadata extracted and thumbnails generated for browsing.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full flex-shrink-0 mt-0.5">4</span>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">AI Enrichment</div>
                            <p class="text-xs text-slate-600 mt-0.5">GPT-4o generates plain-language summaries and extracts named entities (people, organizations, locations) from OCR text.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full flex-shrink-0 mt-0.5">5</span>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Email Extraction</div>
                            <p class="text-xs text-slate-600 mt-0.5">Email headers (From, To, Subject, Date) are parsed from OCR text into a searchable email index.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                        <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-600 text-white text-xs font-bold rounded-full flex-shrink-0 mt-0.5">6</span>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Flight Analysis &amp; Search Embeddings</div>
                            <p class="text-xs text-slate-600 mt-0.5">Flight logs receive significance scores and all documents get vector embeddings for semantic search.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Verification</div>
                <p class="text-sm text-slate-700">
                    Every document page includes a link to the original government source URL. Visit our
                    <a class="text-blue-700 hover:underline" href="/sources.php">Sources</a> page for the complete
                    list of data origins and ingestion statistics.
                </p>
            </div>
        </section>

        <!-- Section 3: Transparency Report -->
        <section id="transparency-report" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <h2 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                </span>
                Transparency Report
            </h2>

            <p class="text-slate-700 leading-relaxed">
                Live statistics from our production database, updated every 5 minutes.
            </p>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                    <div class="text-2xl font-bold text-blue-600"><?= number_format($transparencyStats['totalDocuments']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Total Documents</div>
                </div>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                    <div class="text-2xl font-bold text-emerald-600"><?= number_format($transparencyStats['processedDocuments']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Fully Processed</div>
                </div>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                    <div class="text-2xl font-bold text-indigo-600"><?= number_format($transparencyStats['totalOcrPages']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">OCR Pages</div>
                </div>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                    <div class="text-2xl font-bold text-purple-600"><?= number_format($transparencyStats['aiSummaryCount']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">AI Summaries</div>
                </div>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                    <div class="text-2xl font-bold text-amber-600"><?= number_format($transparencyStats['totalEntities']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Entities Extracted</div>
                </div>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4">
                    <div class="text-2xl font-bold text-green-600"><?= number_format($transparencyStats['localCopies']) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Local Copies</div>
                </div>
                <?php if ($transparencyStats['lastIngestion']): ?>
                <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 md:col-span-2">
                    <div class="text-lg font-bold text-slate-800"><?= date('F j, Y \a\t g:ia T', strtotime($transparencyStats['lastIngestion'])) ?></div>
                    <div class="text-xs text-slate-500 mt-1">Last Data Ingestion</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Detailed metrics</div>
                <p class="text-sm text-slate-700">
                    For a full breakdown of ingestion health, dataset coverage, and processing progress, visit the
                    <a class="text-blue-700 hover:underline" href="/stats.php">Stats</a> and
                    <a class="text-blue-700 hover:underline" href="/sources.php">Sources</a> pages.
                </p>
            </div>
        </section>

        <!-- Section 4: DMCA / Takedown -->
        <section id="dmca" class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <h2 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                <span class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </span>
                DMCA / Takedown Notice
            </h2>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Content Removal Process</h3>
                <p class="text-slate-700 leading-relaxed">
                    Epstein Suite indexes publicly released government documents. If you are a rights holder and
                    believe that content hosted on this site infringes your copyright or should be removed for
                    legal reasons, we have a structured process for handling takedown requests.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">How to Submit a Takedown Request</h3>
                <ol class="text-slate-700 space-y-3 list-decimal list-inside">
                    <li>
                        <strong>Identify the content.</strong>
                        Provide the URL(s) of the specific page(s) or document(s) you want removed.
                    </li>
                    <li>
                        <strong>State your claim.</strong>
                        Explain the basis for removal (copyright ownership, privacy concern, legal order, etc.).
                    </li>
                    <li>
                        <strong>Provide contact information.</strong>
                        Include your full name, organization (if applicable), email address, and phone number.
                    </li>
                    <li>
                        <strong>Send your request.</strong>
                        Email your takedown notice to the address below. Include "DMCA Takedown" or "Content Removal"
                        in the subject line.
                    </li>
                </ol>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Response Timeline</h3>
                <p class="text-slate-700 leading-relaxed">
                    We acknowledge all takedown requests within <strong>72 hours</strong> of receipt. Valid requests
                    are processed and content is removed or restricted within <strong>10 business days</strong>.
                    We may contact you for additional information if the request is unclear or incomplete.
                </p>
            </div>

            <div>
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Good Faith Disclaimer</h3>
                <p class="text-slate-700 leading-relaxed">
                    This project operates in good faith as a public transparency tool. All indexed content originates
                    from official government releases. We do not make claims beyond what is supported by linked public
                    records. Being named in these documents is not an indication of wrongdoing.
                </p>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">DMCA Contact</div>
                <p class="text-sm text-slate-700">
                    Send takedown notices to:
                    <a class="text-blue-700 hover:underline" href="mailto:admin@kevinchamplin.com">admin@kevinchamplin.com</a>
                </p>
                <p class="text-xs text-slate-500 mt-2">
                    Please include "DMCA Takedown" in the subject line for priority handling.
                </p>
            </div>
        </section>

        <div class="text-sm text-slate-500">
            <a href="/" class="text-blue-700 hover:underline">&larr; Back to Search</a>
        </div>

    </div>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
