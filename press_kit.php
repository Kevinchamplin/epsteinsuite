<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Press Kit | Epstein Suite';
$meta_description = 'Press resources for Epstein Suite: plain-language summary, verified sources, contact details, roadmap, and interview logistics for broadcast media.';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full bg-slate-50">
    <div class="max-w-5xl mx-auto px-6 py-12 space-y-10">
        <section class="space-y-4">
            <p class="text-xs uppercase tracking-[0.3em] text-blue-500 font-semibold">Media toolkit</p>
            <h1 class="text-4xl font-bold text-slate-900">Press Kit</h1>
            <p class="text-slate-700 leading-relaxed text-lg">
                Epstein Suite is the first productivity-style dashboard for the DOJ’s Epstein files. Reporters, podcasters, and TV/radio producers can search,
                preview, and cite tens of thousands of public documents without downloading entire ZIP archives. Below you’ll find the plain-English overview,
                verified source list, and the fastest way to book interviews with the team.
            </p>
        </section>

        <section class="grid gap-6 md:grid-cols-2">
            <article class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">What you can say on-air</h2>
                <ul class="mt-4 space-y-3 text-slate-700 leading-relaxed">
                    <li>Epstein Suite is an independent, public website that mirrors the DOJ’s official Epstein document releases.</li>
                    <li>It includes Search, Drive, Mail, Contacts, Flights, Photos, and Analytics tabs—think “Google Workspace for the Epstein files.”</li>
                    <li>Every PDF is OCR’d so names, places, and quotes are searchable. AI summaries help viewers/listeners grasp context quickly.</li>
                    <li>The site links back to the DOJ source so audiences can verify every claim.</li>
                </ul>
                <a href="/about.php" class="inline-flex mt-4 text-blue-600 font-semibold hover:underline">Read the full About page →</a>
            </article>

            <article class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Key links for your research</h2>
                <ul class="mt-4 space-y-2 text-blue-600 font-semibold">
                    <li><a href="/sources.php" class="hover:underline">Source Index (DOJ Data Sets, FBI Vault, Disclosures)</a></li>
                    <li><a href="/roadmap.php" class="hover:underline">Public Roadmap (upcoming scrapers + features)</a></li>
                    <li><a href="/contact.php" class="hover:underline">Contact / Booking Form</a></li>
                    <li><a href="/about.php" class="hover:underline">About Epstein Suite</a></li>
                </ul>
                <p class="text-sm text-slate-600 mt-4">
                    Combine these links in show notes so audiences can explore the archive themselves.
                </p>
            </article>
        </section>

        <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">If you’re scheduling an interview</h2>
                <ul class="text-slate-700 space-y-2 mt-3 leading-relaxed">
                    <li>Preferred contact: <a class="text-blue-700 hover:underline" href="mailto:info@epsteinsuite.com">info@epsteinsuite.com</a></li>
                    <li>Include network/show name, preferred dates, time zone, and whether it’s radio, TV, podcast, or print.</li>
                    <li>Remote interviews supported via Zoom, Riverside, or traditional phone patch.</li>
                    <li>We can provide b-roll screen recordings, high-res logo files, or walkthroughs on request.</li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">How to cite</h2>
                <div class="text-slate-700 space-y-2 mt-3">
                    <p>Mention “Epstein Suite (epsteinsuite.com)” and link to the specific document or app view you’re referencing.</p>
                    <p>Whenever possible, also cite the underlying DOJ or FBI URL displayed in the document drawer.</p>
                </div>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Safety & respect</h2>
                <p class="text-sm text-slate-700 leading-relaxed">
                    Epstein Suite never attempts to identify or “un-redact” victims or minors. We ask partners and press to avoid victim-identifying speculation
                    and to rely on the original DOJ documents for claims.
                </p>
            </div>
        </section>

        <section class="bg-slate-900 text-white rounded-2xl p-6 shadow-lg space-y-4">
            <p class="text-xs uppercase tracking-[0.4em] text-blue-200 font-semibold">Downloadable assets</p>
            <div class="grid gap-4 md:grid-cols-2 text-sm">
                <div class="bg-white/5 rounded-xl p-4 border border-white/10">
                    <p class="font-semibold">Logos & screenshots</p>
                    <p class="text-slate-200 text-sm mt-1">High-resolution lockups plus recent UI captures for TV lower thirds.</p>
                    <a href="/storage/media/epstein-suite-press-assets.zip" class="inline-flex items-center mt-3 px-4 py-2 bg-white text-slate-900 font-semibold rounded-lg hover:bg-slate-100 transition">
                        Download .zip
                    </a>
                </div>
                <div class="bg-white/5 rounded-xl p-4 border border-white/10">
                    <p class="font-semibold">Fact sheet</p>
                    <p class="text-slate-200 text-sm mt-1">One-page PDF summarizing mission, sources, and usage guidelines.</p>
                    <a href="/storage/media/epstein-suite-fact-sheet.pdf" class="inline-flex items-center mt-3 px-4 py-2 border border-white/40 rounded-lg hover:bg-white/10 transition">
                        View PDF
                    </a>
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

