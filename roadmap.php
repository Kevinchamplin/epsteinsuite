<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Roadmap | Epstein Suite';
$meta_description = 'Track the Epstein Suite roadmap: recently shipped ingestion tooling, UI upgrades, and what’s next for AI question answering.';

require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-1 w-full bg-slate-50">
    <section class="max-w-5xl mx-auto px-6 py-10 space-y-8">
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold text-blue-600 uppercase tracking-widest">Product Roadmap</p>
                    <h1 class="text-3xl font-bold text-slate-900 mt-1">Where Epstein Suite is headed</h1>
                    <p class="text-slate-600 mt-3 max-w-3xl">
                        We’re building a transparent, searchable archive of the DOJ releases with modern tooling. Here’s a running log of what shipped and what’s next.
                    </p>
                </div>
                <div class="text-sm text-slate-500 bg-slate-100 rounded-xl px-4 py-3 border border-slate-200">
                    <span class="font-semibold text-slate-700">Last updated:</span> December 21, 2025
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $suiteApps = [
                ['label' => 'Drive', 'stat' => '10,157 files', 'color' => 'from-blue-50 to-blue-100', 'icon' => 'folder'],
                ['label' => 'Mail', 'stat' => '1,247 emails', 'color' => 'from-rose-50 to-rose-100', 'icon' => 'mail'],
                ['label' => 'Contacts', 'stat' => '2,345 people', 'color' => 'from-purple-50 to-purple-100', 'icon' => 'users'],
                ['label' => 'Flights', 'stat' => '20 logs', 'color' => 'from-emerald-50 to-emerald-100', 'icon' => 'navigation'],
                ['label' => 'Photos', 'stat' => 'Images + scans', 'color' => 'from-amber-50 to-amber-100', 'icon' => 'image'],
                ['label' => 'Analytics', 'stat' => 'Live stats', 'color' => 'from-slate-50 to-slate-100', 'icon' => 'bar-chart-2'],
            ];
            foreach ($suiteApps as $app):
            ?>
            <div class="p-4 rounded-2xl border border-slate-200 bg-gradient-to-br <?= $app['color'] ?> flex items-center gap-4 shadow-sm">
                <div class="w-12 h-12 rounded-2xl bg-white border border-slate-200 flex items-center justify-center text-blue-600">
                    <i data-feather="<?= htmlspecialchars($app['icon']) ?>" class="w-5 h-5"></i>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-900"><?= htmlspecialchars($app['label']) ?></div>
                    <div class="text-xs text-slate-600"><?= htmlspecialchars($app['stat']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
            <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Highlights</p>
            <h2 class="text-2xl font-bold text-slate-900 mt-1">What we’ve already accomplished</h2>
            <div class="mt-5 grid md:grid-cols-2 gap-4 text-sm text-slate-700">
                <div class="p-4 rounded-xl border border-slate-100 bg-slate-50">
                    <p class="font-semibold text-slate-900 mb-2">Ingestion & search</p>
                    <ul class="space-y-2 list-disc list-inside">
                        <li>Automated download + OCR pipeline with retry + error dashboards</li>
                        <li>AI summaries aligned with OCR text and entity linking</li>
                        <li>Full-text Drive search with source linking + manual file pulls</li>
                    </ul>
                </div>
                <div class="p-4 rounded-xl border border-slate-100 bg-slate-50">
                    <p class="font-semibold text-slate-900 mb-2">Apps people can use now</p>
                    <ul class="space-y-2 list-disc list-inside">
                        <li>Epstein Drive, Mail, Contacts, Flights, Photos, Analytics</li>
                        <li>Live entity graphing + related document discovery</li>
                        <li>Source verification + broken-file reporting with admin alerts</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                <div>
                    <p class="text-xs uppercase tracking-wider text-emerald-600 font-semibold">Shipped · Dec 20, 2025</p>
                    <h2 class="text-xl font-bold text-slate-900 mt-1">Core ingestion & visibility</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-700">
                        <li class="flex items-start gap-3">
                            <span class="text-emerald-500 mt-0.5">✔</span>
                            Built detailed ingestion health views (Sources dashboard + Ingestion Progress live view) plus dataset-specific analytics.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-emerald-500 mt-0.5">✔</span>
                            Added retry APIs & UI (single + bulk) with ingestion error logging per step.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-emerald-500 mt-0.5">✔</span>
                            Upgraded Drive browsing with OCR status filters, folder fallbacks, and Feather icons.
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-xs uppercase tracking-wider text-blue-600 font-semibold">Shipped · Dec 21, 2025</p>
                    <h2 class="text-xl font-bold text-slate-900 mt-1">Reliability, Ask beta & user tools</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-700">
                        <li class="flex items-start gap-3">
                            <span class="text-blue-500 mt-0.5">✔</span>
                            Manual “Create local copy” button + API for missing files, plus MIME sniffing to render PDFs vs images correctly.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-blue-500 mt-0.5">✔</span>
                            Logging hardened by relocating download logs under <code>/storage/logs</code>.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-blue-500 mt-0.5">✔</span>
                            Broken file reporting with admin notifications and Drive/source visibility polish across the suite.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-blue-500 mt-0.5">✔</span>
                            AI summary cards + entity chips revamped to match the Suite aesthetic you see in document view.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-blue-500 mt-0.5">✔</span>
                            Launched <strong>Ask EpsteinSuite (beta)</strong> at <code>/ask.php</code>—fully logged chat with document citations and ingestion awareness.
                        </li>
                    </ul>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
                <div>
                    <p class="text-xs uppercase tracking-wider text-amber-600 font-semibold">In Progress</p>
                    <h2 class="text-xl font-bold text-slate-900 mt-1">Complete the ingestion backlog</h2>
                    <p class="text-sm text-slate-700 mt-3">
                        Finish downloading every file locally so OCR + AI can work without falling back to remote sources. The ad-hoc download button
                        and nightly scripts are converging on zero “remote-only” gaps.
                    </p>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wider text-purple-600 font-semibold">Next Up · Targeting Q1 2026</p>
                    <h2 class="text-xl font-bold text-slate-900 mt-1">Researcher APIs & programmatic access</h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-700">
                        <li class="flex items-start gap-3">
                            <span class="text-purple-500 mt-0.5">➤</span>
                            Ship authenticated REST APIs that return documents, entities, flights, and emails with pagination + webhooks.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-purple-500 mt-0.5">➤</span>
                            Provide streaming Ask responses via API so researchers can integrate the RAG pipeline into their own apps.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-purple-500 mt-0.5">➤</span>
                            Harden rate limits + audit logs so external clients stay compliant with privacy and safety requirements.
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-purple-500 mt-0.5">➤</span>
                            Expand ingestion monitors so API consumers can subscribe to dataset-specific change events.
                        </li>
                    </ul>
                </div>
                <div class="bg-slate-50 border border-dashed border-slate-300 rounded-xl p-5 text-sm text-slate-600">
                    <p class="font-semibold text-slate-800 mb-2">Have input?</p>
                    <p>
                        Email <a href="mailto:admin@kevinchamplin.com" class="text-blue-600 hover:underline">admin@kevinchamplin.com</a> with feature requests or datasets we should ingest next.
                    </p>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>

</body>
</html>
