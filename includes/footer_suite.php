<?php
/**
 * Shared footer navigation for Epstein Suite.
 */

require_once __DIR__ . '/cache.php';

$footerLastIngestion = Cache::remember('footer_last_ingestion', function () {
    try {
        require_once __DIR__ . '/db.php';
        $pdo = db();
        return $pdo->query("SELECT MAX(created_at) FROM documents")->fetchColumn() ?: null;
    } catch (Exception $e) {
        return null;
    }
}, 600);
?>
<footer class="bg-slate-100 text-slate-500 text-sm mt-auto border-t border-slate-200">
    <div class="max-w-7xl mx-auto px-6 py-6 space-y-6">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <a href="/about.php" class="hover:underline">About</a>
            <a href="/advertising.php" class="hover:underline">Advertising</a>
            <a href="/business.php" class="hover:underline">Business</a>
            <a href="/press_kit.php" class="hover:underline">Press Kit</a>
            <a href="/sources.php" class="hover:underline">Sources</a>
            <a href="/roadmap.php" class="hover:underline">Roadmap</a>
            <a href="/contact.php" class="hover:underline">Contact</a>
            <a href="/tech.php" class="hover:underline">Tech</a>
            <a href="/transparency.php" class="hover:underline">Transparency</a>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <a href="https://buy.stripe.com/6oU4gB0vMbasdXqat82VG02" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-3 py-2 rounded-full bg-amber-500 text-white font-semibold shadow hover:bg-amber-600 focus:outline-none">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21C12 21 5 13.364 5 8.5C5 5.462 7.462 3 10.5 3C11.89 3 13.21 3.571 14 4.5C14.79 3.571 16.11 3 17.5 3C20.538 3 23 5.462 23 8.5C23 13.364 16 21 16 21H12Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Donate
            </a>
            <a href="/ask.php" class="inline-flex items-center gap-2 px-3 py-2 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold shadow hover:shadow-lg transition">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Ask Epstein AI
            </a>
            <a href="/drive.php" class="hover:underline">Drive</a>
            <a href="/news.php" class="hover:underline">News</a>
            <a href="/flight_logs.php" class="hover:underline">Flights</a>
            <a href="/privacy.php" class="hover:underline">Privacy</a>
            <a href="/terms.php" class="hover:underline">Terms</a>
            <a href="/transparency.php#dmca" class="hover:underline">DMCA</a>
            <span class="text-slate-400 hidden md:inline-flex items-center gap-2">
                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                Epstein Suite &middot; Public-source intelligence tools
            </span>
            <?php if ($footerLastIngestion): ?>
            <span class="text-slate-400 hidden md:inline-flex items-center gap-2">
                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                Last updated <?= date('M j, Y', strtotime($footerLastIngestion)) ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script>
(() => {
    const footer = document.currentScript?.previousElementSibling;
    if (!footer) return;
    const link = document.createElement('a');
    link.href = '/ask.php';
    link.setAttribute('aria-label', 'Open Ask Epstein Files');
    link.className = 'ask-fab';
    link.innerHTML = `
        <img src="/jeffrey-epstein.png" alt="Ask Epstein Files">
        <div>
            <small>Ask Epstein Files</small>
            <span class="text-sm font-semibold">Chat with the archive</span>
        </div>
    `;
    document.body.appendChild(link);
})();
</script>
