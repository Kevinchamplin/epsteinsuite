<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Privacy | Epstein Suite';
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full">
    <div class="max-w-4xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold text-slate-900 mb-4">Privacy</h1>
        <p class="text-slate-700 leading-relaxed mb-6">
            We keep things simple. We collect as little as possible, and we do not sell personal data.
        </p>

        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-6">
            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">What we collect</h2>
                <ul class="text-slate-700 space-y-2">
                    <li>Basic server logs (e.g., IP address, user agent, request path) for security and performance.</li>
                    <li>Query parameters you send (e.g., search terms) as part of normal request logs.</li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Cookies &amp; Third-Party Services</h2>
                <p class="text-slate-700 leading-relaxed mb-3">
                    We use a Consent Management Platform (Gatekeeper CMP) to obtain your consent before setting non-essential cookies.
                    You can update your preferences at any time via the consent banner.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="py-2 pr-4 text-xs font-bold text-slate-500 uppercase">Service</th>
                                <th class="py-2 pr-4 text-xs font-bold text-slate-500 uppercase">Purpose</th>
                                <th class="py-2 text-xs font-bold text-slate-500 uppercase">Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <tr><td class="py-2 pr-4">Gatekeeper CMP</td><td class="py-2 pr-4">Cookie consent management</td><td class="py-2">Essential</td></tr>
                            <tr><td class="py-2 pr-4">Google Analytics</td><td class="py-2 pr-4">Site usage analytics</td><td class="py-2">Analytics</td></tr>
                            <tr><td class="py-2 pr-4">Google Adsense</td><td class="py-2 pr-4">Advertising</td><td class="py-2">Advertising</td></tr>
                            <tr><td class="py-2 pr-4">Monetag</td><td class="py-2 pr-4">Advertising</td><td class="py-2">Advertising</td></tr>
                            <tr><td class="py-2 pr-4">Adcash</td><td class="py-2 pr-4">Advertising</td><td class="py-2">Advertising</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Your Rights (GDPR / CPRA)</h2>
                <ul class="text-slate-700 space-y-2">
                    <li><strong>Right to Access:</strong> Request what data we hold about you.</li>
                    <li><strong>Right to Deletion:</strong> Request removal of your personal data.</li>
                    <li><strong>Right to Opt Out:</strong> Decline non-essential cookies via the consent banner.</li>
                    <li><strong>Do Not Sell:</strong> We do not sell personal information to third parties.</li>
                </ul>
                <p class="text-slate-700 mt-3">
                    To exercise any of these rights, email
                    <a class="text-blue-700 hover:underline" href="mailto:admin@kevinchamplin.com">admin@kevinchamplin.com</a>.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">AI Search &amp; Chat Data</h2>
                <ul class="text-slate-700 space-y-2">
                    <li>Chat sessions are identified by anonymous UUID tokens &mdash; not tied to your identity.</li>
                    <li>IP addresses are one-way hashed (SHA-256) for rate-limiting only. We cannot reverse the hash to identify you.</li>
                    <li>Chat conversations are stored for service improvement and abuse prevention. They are not sold or shared with third parties.</li>
                </ul>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Search History</h2>
                <p class="text-slate-700 leading-relaxed">
                    Your search queries on this site are not sold, rented, or shared for marketing purposes. Anonymized
                    query logs may be used internally to improve search relevance and identify popular topics.
                </p>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-2">Data Safety</h2>
                <p class="text-slate-700 leading-relaxed">
                    We aim to avoid exposing sensitive information. We do not intentionally publish non-public personal information,
                    and we do not attempt to identify victims or minors. See our
                    <a class="text-blue-700 hover:underline" href="/transparency.php#redaction">Redaction Policy</a> for details.
                </p>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-1">Corrections / removal requests</div>
                <p class="text-sm text-slate-700">
                    If you believe content should be corrected or removed, contact:
                    <a class="text-blue-700 hover:underline" href="mailto:admin@kevinchamplin.com">admin@kevinchamplin.com</a>
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
