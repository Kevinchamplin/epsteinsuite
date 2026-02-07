<?php
require_once __DIR__ . '/includes/db.php';

$page_title = 'Profile | Epstein Suite';
$meta_description = 'Your Epstein Suite profile. View your browsing history, starred documents, and personalized research trails.';
$noindex = true;
require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-grow w-full">
    <div class="max-w-5xl mx-auto px-6 py-10">
        <div class="flex items-start justify-between gap-6 flex-wrap">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full overflow-hidden border border-slate-200 shadow-sm">
                    <img src="/jeffrey-epstein.png" alt="Jeffrey Epstein" class="w-full h-full object-cover">
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-3xl font-bold text-slate-900">Jeffrey Epstein</h1>
                        <span class="text-xs font-semibold uppercase tracking-wide bg-slate-100 text-slate-600 px-2 py-1 rounded-full">Public figure</span>
                    </div>
                    <p class="text-slate-600 mt-1">Financier. Convicted sex offender. A case study in why “powerful network” is not a personality.</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a href="/" class="bg-white border border-slate-200 text-slate-700 px-4 py-2 rounded-lg hover:border-blue-300 hover:shadow-sm transition-all text-sm font-medium">Back to Search</a>
                <a href="/drive.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors text-sm font-medium">Open Drive</a>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Quick facts</h2>
                        <span class="text-xs text-slate-400">Public record</span>
                    </div>
                    <p class="text-slate-700 leading-relaxed mt-4">
                        This page keeps it light, but it stays grounded in widely reported public information.
                        No victim identification, no guessing, no “internet detective” cosplay.
                    </p>
                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Occupation</div>
                            <div class="text-sm text-slate-800 mt-1">Financier</div>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4">
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Known for</div>
                            <div class="text-sm text-slate-800 mt-1">Criminal case & public controversy</div>
                        </div>
                    </div>

                    <div class="mt-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
                        <div class="text-xs font-bold uppercase tracking-wide text-amber-700 mb-1">Important</div>
                        <p class="text-sm text-amber-900 leading-relaxed">
                            The Suite is a discovery tool. For anything serious, click into the documents and verify against originals.
                        </p>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Timeline (high level)</h2>
                    <div class="mt-4 space-y-3">
                        <div class="flex items-start gap-3">
                            <div class="w-10 flex-shrink-0 text-xs font-bold text-slate-500">2008</div>
                            <div class="text-sm text-slate-700">Pleaded guilty in Florida state court to prostitution-related charges; served jail time and probation.</div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-10 flex-shrink-0 text-xs font-bold text-slate-500">2019</div>
                            <div class="text-sm text-slate-700">Arrested on federal sex trafficking charges.</div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-10 flex-shrink-0 text-xs font-bold text-slate-500">2019</div>
                            <div class="text-sm text-slate-700">Died in federal custody while awaiting trial.</div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Quick actions</h2>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a href="/contacts.php" class="group bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-base font-semibold text-slate-900">👥 Explore Entities</div>
                                    <div class="text-xs text-slate-500 mt-1">People, orgs, locations</div>
                                </div>
                                <svg class="w-5 h-5 text-slate-400 group-hover:text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </div>
                        </a>
                        <a href="/flight_logs.php" class="group bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-base font-semibold text-slate-900">✈️ Browse Flights</div>
                                    <div class="text-xs text-slate-500 mt-1">Search flight logs</div>
                                </div>
                                <svg class="w-5 h-5 text-slate-400 group-hover:text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </div>
                        </a>
                        <a href="/email_client.php" class="group bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-base font-semibold text-slate-900">✉️ Open Mail</div>
                                    <div class="text-xs text-slate-500 mt-1">Extracted emails</div>
                                </div>
                                <svg class="w-5 h-5 text-slate-400 group-hover:text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </div>
                        </a>
                        <a href="/photos.php" class="group bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-base font-semibold text-slate-900">🖼️ Open Photos</div>
                                    <div class="text-xs text-slate-500 mt-1">Previews & extracted images</div>
                                </div>
                                <svg class="w-5 h-5 text-slate-400 group-hover:text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Profile details</h2>
                    <dl class="mt-4 space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-sm text-slate-500">Handle</dt>
                            <dd class="text-sm font-medium text-slate-900">@je</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-sm text-slate-500">Status</dt>
                            <dd class="text-sm font-medium text-slate-900">Not a role model</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-sm text-slate-500">Reputation</dt>
                            <dd class="text-sm font-medium text-slate-900">A cautionary tale</dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-sm text-slate-500">Public interest</dt>
                            <dd class="text-sm font-medium text-slate-900">High</dd>
                        </div>
                    </dl>
                </div>

                <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide">Totally serious button</h2>
                    <p class="text-sm text-slate-600 mt-3">
                        Tap for the official status update.
                    </p>
                    <button type="button" onclick="document.getElementById('fakeStatus').textContent = (document.getElementById('fakeStatus').textContent === 'Unimpressive') ? 'Unimpressive (but louder)' : 'Unimpressive';" class="mt-4 w-full bg-slate-900 text-white px-4 py-2 rounded-lg hover:bg-slate-800 transition-colors text-sm font-medium">
                        Update status
                    </button>
                    <div class="mt-3 text-sm text-slate-700">
                        Status: <span id="fakeStatus" class="font-semibold">Unimpressive</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
