<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Terms | Epstein Suite';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full">
    <div class="max-w-4xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold text-slate-900 mb-4">Terms</h1>
        <p class="text-slate-700 leading-relaxed mb-6">
            Keep it respectful, use good judgment, and treat this as a search tool—not a verdict machine.
        </p>

        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">No affiliation</h2>
                <p class="text-slate-700 leading-relaxed">
                    This site is independent and is not affiliated with the U.S. Department of Justice, Congress,
                    or any government agency.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Public-source content</h2>
                <p class="text-slate-700 leading-relaxed">
                    Content indexed here originates from public sources. We provide links back to the original source when available.
                    If you are a rights holder and believe something should be removed, contact us.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">No defamation intent</h2>
                <p class="text-slate-700 leading-relaxed">
                    This site does not make claims beyond what is supported by linked public records.
                    Names may appear because they appear in source materials. AI summaries can be wrong—always verify against the source.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Privacy & safety</h2>
                <p class="text-slate-700 leading-relaxed">
                    We do not attempt to identify victims or minors or reverse redactions. If you find sensitive content that should not be here,
                    report it and we will review.
                </p>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Contact</div>
                <p class="text-sm text-slate-700">
                    Corrections / removal: <a class="text-blue-700 hover:underline" href="mailto:info@epsteinsuite.com">info@epsteinsuite.com</a>
                </p>
            </div>
        </div>

        <div class="mt-8 text-sm text-slate-500">
            <a href="/" class="text-blue-700 hover:underline">← Back to Search</a>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
