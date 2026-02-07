<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Business | Epstein Suite';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full">
    <div class="max-w-4xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold text-slate-900 mb-4">Business</h1>
        <p class="text-slate-700 leading-relaxed mb-6">
            This site is built for public-interest search and discoverability.
            If you’re a journalist, researcher, nonprofit, or platform that wants structured access or integration,
            we can collaborate.
        </p>

        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Partnership ideas</h2>
            <ul class="text-slate-700 space-y-2">
                <li>Data exports (when permitted) for research workflows.</li>
                <li>API / automation for monitoring new releases.</li>
                <li>Accessibility improvements and UI/UX upgrades.</li>
                <li>Verification + corrections pipeline.</li>
            </ul>

            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide pt-2">Important boundaries</h2>
            <ul class="text-slate-700 space-y-2">
                <li>We don’t provide legal opinions or investigative conclusions.</li>
                <li>We don’t help identify victims/minors or reverse redactions.</li>
                <li>We prioritize accuracy, provenance, and harm reduction.</li>
            </ul>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Contact</div>
                <p class="text-sm text-slate-700">
                    Partnerships: <a class="text-blue-700 hover:underline" href="mailto:info@epsteinsuite.com">info@epsteinsuite.com</a>
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
