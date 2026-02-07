<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

$subs = $pdo->query("SELECT id, source_type, source_url, note, submitter_email, status, created_at FROM ingestion_submissions ORDER BY id DESC LIMIT 50")
    ->fetchAll(PDO::FETCH_ASSOC);

$asksStmt = $pdo->prepare("\n    SELECT m.id, m.content, m.created_at, s.session_token\n    FROM ai_messages m\n    JOIN ai_sessions s ON s.id = m.session_id\n    WHERE m.role = 'user'\n    ORDER BY m.id DESC\n    LIMIT 30\n");
$asksStmt->execute();
$asks = $asksStmt->fetchAll(PDO::FETCH_ASSOC);

$contacts = $pdo->query("SELECT id, name, email, subject, created_at FROM contact_messages ORDER BY id DESC LIMIT 20")
    ->fetchAll(PDO::FETCH_ASSOC);

admin_render_layout('Operations console', 'dashboard', function () use ($subs, $asks, $contacts) {
    ?>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="space-y-2">
            <p class="text-[11px] uppercase tracking-[0.3em] text-slate-500">Admin</p>
            <h1 class="text-3xl font-semibold text-slate-900">Operations console</h1>
            <p class="text-sm text-slate-600">Monitor submissions, Ask usage, and inbound contact messages.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="/submit-evidence.php" class="rounded-lg bg-blue-50 text-blue-800 px-3 py-2 text-xs border border-blue-100">Evidence intake</a>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <a href="#ingestion" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-200">
            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Ingestion</p>
            <h3 class="text-lg font-semibold text-slate-900">Evidence submissions</h3>
            <p class="text-sm text-slate-600">Latest intake links and notes.</p>
        </a>
        <a href="#ask" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-200">
            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Ask</p>
            <h3 class="text-lg font-semibold text-slate-900">Recent user prompts</h3>
            <p class="text-sm text-slate-600">See what users are querying.</p>
        </a>
        <a href="#contact" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-blue-200">
            <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Contact</p>
            <h3 class="text-lg font-semibold text-slate-900">Inbound messages</h3>
            <p class="text-sm text-slate-600">Recent contact form submissions.</p>
        </a>
    </div>

    <section id="ingestion" class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Ingestion</p>
                <h2 class="text-xl font-semibold text-slate-900">Evidence submissions</h2>
            </div>
            <span class="text-xs text-slate-500">Latest 50</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-left">
                <thead class="text-slate-500 border-b border-slate-100">
                    <tr>
                        <th class="py-2 pr-3">ID</th>
                        <th class="py-2 pr-3">Link</th>
                        <th class="py-2 pr-3">Note</th>
                        <th class="py-2 pr-3">Email</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2 pr-3">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($subs as $row): ?>
                        <tr class="align-top">
                            <td class="py-2 pr-3 text-slate-800"><?= (int)$row['id'] ?></td>
                            <td class="py-2 pr-3 max-w-[240px] truncate">
                                <?php if ($row['source_url']): ?>
                                    <a href="<?= htmlspecialchars($row['source_url'], ENT_QUOTES) ?>" class="text-blue-600 underline" target="_blank" rel="noopener">Drive</a>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 pr-3 max-w-[320px] text-slate-700"><?= htmlspecialchars($row['note'] ?? '', ENT_QUOTES) ?></td>
                            <td class="py-2 pr-3 text-slate-700"><?= htmlspecialchars($row['submitter_email'] ?? '', ENT_QUOTES) ?></td>
                            <td class="py-2 pr-3"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-700"><?= htmlspecialchars($row['status'], ENT_QUOTES) ?></span></td>
                            <td class="py-2 pr-3 text-slate-500 text-xs"><?= htmlspecialchars($row['created_at'], ENT_QUOTES) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="ask" class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Ask</p>
                <h2 class="text-xl font-semibold text-slate-900">Recent user questions</h2>
            </div>
            <span class="text-xs text-slate-500">Latest 30</span>
        </div>
        <div class="space-y-3">
            <?php foreach ($asks as $ask): ?>
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                    <p class="text-sm text-slate-900"><?= htmlspecialchars($ask['content'], ENT_QUOTES) ?></p>
                    <div class="text-[11px] text-slate-500 mt-1 flex items-center gap-3">
                        <span><?= htmlspecialchars($ask['created_at'], ENT_QUOTES) ?></span>
                        <span class="truncate">Session: <?= htmlspecialchars($ask['session_token'], ENT_QUOTES) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="contact" class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <p class="text-[11px] uppercase tracking-[0.2em] text-slate-500">Contact</p>
                <h2 class="text-xl font-semibold text-slate-900">Recent contact messages</h2>
            </div>
            <span class="text-xs text-slate-500">Latest 20</span>
        </div>
        <div class="space-y-3">
            <?php foreach ($contacts as $c): ?>
                <div class="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                    <div class="flex justify-between items-center text-sm text-slate-900">
                        <span><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></span>
                        <span class="text-xs text-slate-500"><?= htmlspecialchars($c['created_at'], ENT_QUOTES) ?></span>
                    </div>
                    <p class="text-xs text-slate-600"><?= htmlspecialchars($c['email'], ENT_QUOTES) ?></p>
                    <p class="text-sm text-slate-800 mt-1"><?= htmlspecialchars($c['subject'] ?? '(no subject)', ENT_QUOTES) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
});
