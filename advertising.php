<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Advertising | Epstein Suite';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full">
    <div class="max-w-4xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold text-slate-900 mb-4">Advertising</h1>
        <p class="text-slate-700 leading-relaxed mb-6">
            We keep this project lightweight and focused on public-interest search.
            If you want to support hosting, indexing, and ongoing updates, advertising and sponsorship options may be available.
        </p>

        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Guidelines</h2>
            <ul class="text-slate-700 space-y-2">
                <li>No deceptive, political, or harassment-based ads.</li>
                <li>No adult content, malware, or misleading “download” buttons.</li>
                <li>No ads implying endorsement by DOJ, Congress, victims, or any named individual.</li>
                <li>We reserve the right to refuse or remove ads at any time.</li>
            </ul>

            <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide pt-2">Sponsorships (the fun part)</h2>
            <p class="text-slate-700 leading-relaxed">
                If you’re a legit organization that supports transparency, open data, investigative journalism, or civic tech,
                reach out. Keep it classy, keep it helpful.
            </p>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Contact</div>
                <p class="text-sm text-slate-700">
                    Advertising inquiries: <a class="text-blue-700 hover:underline" href="mailto:info@epsteinsuite.com">info@epsteinsuite.com</a>
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
