<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'About Epstein Suite | Searchable Epstein Files';
$meta_description = 'Epstein Suite makes it easy to search, preview, and understand the thousands of public Epstein files. Learn how the suite works, where the documents come from, and how to explore the roadmap.';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full bg-slate-50">
    <div class="max-w-5xl mx-auto px-6 py-12 space-y-10">
        <section class="space-y-4">
            <p class="text-xs uppercase tracking-[0.3em] text-blue-500 font-semibold">Epstein Suite Overview</p>
            <h1 class="text-4xl font-bold text-slate-900">What is Epstein Suite?</h1>
            <p class="text-lg text-slate-700 leading-relaxed">
                Epstein Suite is a free, public dashboard that turns the massive trove of DOJ Epstein files into something anyone can actually browse.
                Instead of digging through confusing folders, you get a familiar productivity-style interface—Search, Drive, Mail, Contacts, Flights,
                Photos, and Analytics—built specifically to make finding names, timelines, and documents fast. Every feature exists to answer a simple question:
                <strong>“How can I explore these records without being a data expert?”</strong>
            </p>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <article class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Plain-language tour of the suite</h2>
                <ul class="mt-4 space-y-3 text-slate-700 leading-relaxed">
                    <li><strong>Search:</strong> Type a name or phrase and jump directly to matching documents, emails, or entities.</li>
                    <li><strong>Drive:</strong> Browse every DOJ data set like a cloud drive with folders, previews, and download links.</li>
                    <li><strong>Mail:</strong> View extracted emails with sender/recipient filters and OCR text for context.</li>
                    <li><strong>Contacts:</strong> Automatically generated address book of people, organizations, and locations mentioned in the files.</li>
                    <li><strong>Flights:</strong> Search flight logs with date, route, and passenger filters.</li>
                    <li><strong>Photos & Timeline:</strong> Photo gallery plus chronological timelines to make sense of events.</li>
                    <li><strong>Ask (beta):</strong> Our AI chatbot at <a href="/ask.php" class="text-blue-600 hover:underline">epsteinsuite.com/ask.php</a> converses with the archive, cites sources, and links back to the original documents.</li>
                    <li><strong>Analytics:</strong> Live stats showing how much of the archive has been processed and summarized.</li>
                </ul>
            </article>

            <article class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">How everything works behind the scenes</h2>
                <ul class="mt-4 space-y-3 text-slate-700 leading-relaxed">
                    <li>Python automation downloads each DOJ PDF or ZIP, runs OCR with Tesseract, and stores clean text.</li>
                    <li>AI summaries (GPT-4o) produce quick “at a glance” explanations plus entity extraction for people/orgs/locations.</li>
                    <li>MySQL search indexes everything so every page of every PDF becomes searchable in seconds.</li>
                    <li>Manual “Create local copy” controls keep files hosted here when third-party links go offline.</li>
                    <li>Strict privacy rules: we never attempt to un-redact victims or publish sensitive personal data.</li>
                </ul>
            </article>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Where the documents come from</h2>
            <p class="text-slate-700 leading-relaxed">
                Every item inside Epstein Suite is pulled from publicly available sources—no leaks, no private databases.
                We continuously monitor and mirror the official releases so nothing disappears behind broken links.
            </p>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                    <p class="text-sm text-slate-500 uppercase tracking-wide font-semibold mb-2">Primary sources</p>
                    <ul class="text-slate-700 space-y-2 text-sm leading-relaxed">
                        <li>DOJ Data Sets 1–7 ZIP archives (letters, court filings, exhibits).</li>
                        <li>DOJ Disclosures page and PACER-linked PDFs.</li>
                        <li>FBI Vault files mirrored for reliability.</li>
                    </ul>
                    <a href="/sources.php" class="inline-flex items-center text-blue-600 text-sm font-semibold mt-3 hover:underline">
                        View detailed source list →
                    </a>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                    <p class="text-sm text-slate-500 uppercase tracking-wide font-semibold mb-2">Why a new interface?</p>
                    <p class="text-sm text-slate-700 leading-relaxed">
                        The DOJ provided PDFs but not a friendly way to navigate 100,000+ pages. Epstein Suite adds search, summaries,
                        and filters so journalists, researchers, or curious citizens can actually understand what’s inside.
                    </p>
                </div>
            </div>
        </section>

        <section class="bg-gradient-to-r from-slate-900 to-slate-800 rounded-2xl p-6 text-white shadow-lg">
            <div class="space-y-3">
                <p class="text-xs uppercase tracking-[0.4em] text-blue-200 font-semibold">Roadmap & community</p>
                <h2 class="text-2xl font-bold">See what’s shipping next</h2>
                <p class="text-slate-200 leading-relaxed">
                    We publish every planned upgrade—new scrapers, better OCR, clustering, and transparency features—on the public roadmap.
                    Follow along, vote on priorities, or request new tools.
                </p>
                <div class="flex flex-wrap gap-3">
                    <a href="/roadmap.php" class="inline-flex items-center px-4 py-2 bg-white text-slate-900 font-semibold rounded-xl shadow hover:bg-slate-100 transition">
                        Explore the roadmap
                    </a>
                    <a href="/contact.php" class="inline-flex items-center px-4 py-2 border border-white/40 rounded-xl hover:bg-white/10 transition">
                        Contact the team
                    </a>
                </div>
            </div>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Frequently asked questions</h2>
            <div class="space-y-3 text-slate-700 leading-relaxed">
                <div>
                    <p class="font-semibold">Is Epstein Suite affiliated with the government?</p>
                    <p>No. This is an independent project built on top of public records. It is not endorsed by the DOJ or any official agency.</p>
                </div>
                <div>
                    <p class="font-semibold">Can I rely on the AI summaries as fact?</p>
                    <p>Summaries are there to save time, but always click through to the original PDF for verification. OCR + AI can miss context.</p>
                </div>
                <div>
                    <p class="font-semibold">How do I report an error or request removal?</p>
                    <p>Use the “Report broken file” buttons on document pages or send a message through the <a href="/contact.php" class="text-blue-600 hover:underline">contact form</a>.</p>
                </div>
            </div>
        </section>

        <div class="text-sm text-slate-500">
            <a href="/" class="text-blue-700 hover:underline">← Back to Search</a>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>

