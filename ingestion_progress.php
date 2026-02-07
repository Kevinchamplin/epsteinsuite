<?php
require_once __DIR__ . '/includes/db.php';

function ingestion_errors_available(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $pdo->query("SELECT 1 FROM ingestion_errors LIMIT 1");
        $cache = true;
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Aggregate ingestion stats for dashboard + API.
 */
function get_progress_data(PDO $pdo): array {
    $data = [
        'totals' => [
            'documents' => 0,
            'local' => 0,
            'remote' => 0,
            'ocr' => 0,
            'ai' => 0,
        ],
        'statuses' => [],
        'remote_queue' => [],
        'recent_errors' => [],
        'generated_at' => gmdate('c'),
    ];

    $hasErrors = ingestion_errors_available($pdo);
    $data['errors_enabled'] = $hasErrors;

    $data['totals']['documents'] = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
    $data['totals']['local'] = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE local_path IS NOT NULL AND local_path != ''")->fetchColumn();
    $data['totals']['remote'] = $data['totals']['documents'] - $data['totals']['local'];
    $data['totals']['ai'] = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE ai_summary IS NOT NULL AND ai_summary != ''")->fetchColumn();
    $data['totals']['ocr'] = (int)$pdo->query("SELECT COUNT(DISTINCT document_id) FROM pages WHERE ocr_text IS NOT NULL AND ocr_text != ''")->fetchColumn();

    $statusRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM documents GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statusRows as $row) {
        $status = $row['status'] ?: 'unknown';
        $data['statuses'][$status] = (int)$row['cnt'];
    }

    if ($hasErrors) {
        $remoteSql = "
            SELECT
                d.id,
                d.title,
                d.data_set,
                d.status,
                d.updated_at,
                (
                    SELECT ie.step FROM ingestion_errors ie
                    WHERE ie.document_id = d.id
                    ORDER BY ie.created_at DESC
                    LIMIT 1
                ) AS error_step,
                (
                    SELECT ie.message FROM ingestion_errors ie
                    WHERE ie.document_id = d.id
                    ORDER BY ie.created_at DESC
                    LIMIT 1
                ) AS error_message,
                (
                    SELECT ie.created_at FROM ingestion_errors ie
                    WHERE ie.document_id = d.id
                    ORDER BY ie.created_at DESC
                    LIMIT 1
                ) AS error_created_at
            FROM documents d
            WHERE (d.local_path IS NULL OR d.local_path = '')
            ORDER BY d.updated_at DESC
            LIMIT 25
        ";
    } else {
        $remoteSql = "
            SELECT id, title, data_set, status, updated_at, NULL AS error_step, NULL AS error_message, NULL AS error_created_at
            FROM documents
            WHERE (local_path IS NULL OR local_path = '')
            ORDER BY updated_at DESC
            LIMIT 25
        ";
    }
    $data['remote_queue'] = $pdo->query($remoteSql)->fetchAll(PDO::FETCH_ASSOC);

    if ($hasErrors) {
        $errorSql = "
            SELECT
                d.id,
                d.title,
                d.data_set,
                d.status,
                ie.step AS error_step,
                ie.message AS error_message,
                ie.created_at AS error_created_at
            FROM ingestion_errors ie
            JOIN documents d ON d.id = ie.document_id
            ORDER BY ie.created_at DESC
            LIMIT 25
        ";
        $data['recent_errors'] = $pdo->query($errorSql)->fetchAll(PDO::FETCH_ASSOC);
    }

    return $data;
}

$pdo = db();

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode(get_progress_data($pdo));
    exit;
}

$page_title = 'Ingestion Progress - Epstein Suite';
$meta_description = 'Live ingestion dashboard showing download, OCR, and AI summary coverage.';
$progress = get_progress_data($pdo);
$tokenRequired = (bool) trim((string) (getenv('REPROCESS_TOKEN') ?? ''));

require_once __DIR__ . '/includes/header_suite.php';
?>

<main class="flex-1 w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8" data-progress-root data-token-required="<?= $tokenRequired ? '1' : '0' ?>">
    <div class="flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Ingestion Progress</h1>
            <p class="text-slate-600 text-sm">Live counts pulled directly from production every few seconds while scripts run.</p>
        </div>
        <div class="text-sm text-slate-500">
            Last updated: <span data-progress-updated><?= htmlspecialchars(date('M j, Y g:i:s A T'), ENT_QUOTES) ?></span>
        </div>
    </div>

    <section class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Total documents</p>
            <p class="text-3xl font-bold text-slate-900" data-stat="documents"><?= number_format($progress['totals']['documents']) ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Local copies</p>
            <p class="text-3xl font-bold text-emerald-600" data-stat="local"><?= number_format($progress['totals']['local']) ?></p>
            <p class="text-xs text-slate-400" data-stat="localPct"></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Remote only</p>
            <p class="text-3xl font-bold text-rose-600" data-stat="remote"><?= number_format($progress['totals']['remote']) ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">OCR complete</p>
            <p class="text-3xl font-bold text-indigo-600" data-stat="ocr"><?= number_format($progress['totals']['ocr']) ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">AI summaries</p>
            <p class="text-3xl font-bold text-purple-600" data-stat="ai"><?= number_format($progress['totals']['ai']) ?></p>
        </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Document Status</h2>
                <span class="text-xs text-slate-500">Updated live</span>
            </div>
            <button class="text-xs font-semibold inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-rose-200 text-rose-600 hover:bg-rose-50"
                data-action="retry-all">
                Retry all errors
            </button>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4" data-status-grid>
            <?php
            $statusBadges = [
                'pending' => ['label' => 'Pending', 'color' => 'bg-slate-100 text-slate-700'],
                'downloaded' => ['label' => 'Downloaded', 'color' => 'bg-blue-100 text-blue-700'],
                'processed' => ['label' => 'Processed', 'color' => 'bg-emerald-100 text-emerald-700'],
                'error' => ['label' => 'Errors', 'color' => 'bg-rose-100 text-rose-700'],
            ];
            foreach ($statusBadges as $key => $config):
                $value = $progress['statuses'][$key] ?? 0;
            ?>
            <div class="p-4 border border-slate-100 rounded-xl <?= $config['color'] ?>">
                <p class="text-xs uppercase tracking-wide"><?= $config['label'] ?></p>
                <p class="text-2xl font-bold" data-status="<?= $key ?>"><?= number_format($value) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Remote-only queue</h2>
                    <p class="text-xs text-slate-500">Documents still waiting for a local copy</p>
                </div>
                <span class="text-xs text-slate-400">Top 25</span>
            </div>
            <div class="space-y-3" data-remote-queue>
                <?php if (empty($progress['remote_queue'])): ?>
                    <p class="text-sm text-slate-500">Queue is clear 🙌</p>
                <?php else: ?>
                    <?php foreach ($progress['remote_queue'] as $row): ?>
                        <div class="p-3 border border-slate-100 rounded-xl hover:border-blue-200 transition-colors space-y-2">
                            <div class="flex items-center justify-between text-xs uppercase tracking-wide text-slate-400">
                                <span><?= htmlspecialchars($row['data_set'] ?? 'Uncategorized') ?></span>
                                <span><?= date('M j, g:i a', strtotime($row['updated_at'])) ?></span>
                            </div>
                            <a href="/document.php?id=<?= (int)$row['id'] ?>" class="text-sm font-semibold text-slate-800 hover:text-blue-600 line-clamp-2"><?= htmlspecialchars($row['title']) ?></a>
                            <p class="text-xs text-slate-400">Status: <?= htmlspecialchars($row['status'] ?? 'unknown') ?></p>
                            <div class="flex items-center gap-3 text-xs">
                                <?php if (!empty($row['error_message'])): ?>
                                    <button class="text-blue-600 hover:underline" data-action="view-error"
                                        data-doc-id="<?= (int)$row['id'] ?>"
                                        data-doc-title="<?= htmlspecialchars($row['title'], ENT_QUOTES) ?>"
                                        data-error-step="<?= htmlspecialchars($row['error_step'] ?? 'unknown', ENT_QUOTES) ?>"
                                        data-error-message="<?= htmlspecialchars($row['error_message'] ?? '', ENT_QUOTES) ?>"
                                        data-error-timestamp="<?= htmlspecialchars($row['error_created_at'] ?? '', ENT_QUOTES) ?>">
                                        View error
                                    </button>
                                <?php endif; ?>
                                <button class="text-emerald-600 hover:underline" data-action="retry"
                                    data-doc-id="<?= (int)$row['id'] ?>"
                                    data-doc-title="<?= htmlspecialchars($row['title'], ENT_QUOTES) ?>">
                                    Retry download/OCR
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Recent errors</h2>
                    <p class="text-xs text-slate-500">Documents marked error (download/OCR)</p>
                </div>
                <span class="text-xs text-slate-400">Top 25</span>
            </div>
            <div class="space-y-3" data-error-list>
                <?php if (empty($progress['recent_errors'])): ?>
                    <p class="text-sm text-slate-500">No errors 🎉</p>
                <?php else: ?>
                    <?php foreach ($progress['recent_errors'] as $row): ?>
                        <div class="p-3 border border-rose-100 bg-rose-50/50 rounded-xl space-y-2">
                            <div class="flex items-center justify-between text-xs uppercase tracking-wide text-rose-400">
                                <span><?= htmlspecialchars($row['data_set'] ?? 'Uncategorized') ?></span>
                                <span><?= date('M j, g:i a', strtotime($row['error_created_at'] ?? $row['updated_at'] ?? 'now')) ?></span>
                            </div>
                            <a href="/document.php?id=<?= (int)$row['id'] ?>" class="text-sm font-semibold text-rose-700 hover:text-rose-900 line-clamp-2"><?= htmlspecialchars($row['title']) ?></a>
                            <p class="text-xs text-rose-500">Status: <?= htmlspecialchars($row['status'] ?? 'error') ?></p>
                            <div class="flex items-center gap-3 text-xs">
                                <button class="text-rose-600 hover:underline" data-action="view-error"
                                    data-doc-id="<?= (int)$row['id'] ?>"
                                    data-doc-title="<?= htmlspecialchars($row['title'], ENT_QUOTES) ?>"
                                    data-error-step="<?= htmlspecialchars($row['error_step'] ?? 'unknown', ENT_QUOTES) ?>"
                                    data-error-message="<?= htmlspecialchars($row['error_message'] ?? '', ENT_QUOTES) ?>"
                                    data-error-timestamp="<?= htmlspecialchars($row['error_created_at'] ?? '', ENT_QUOTES) ?>">
                                    View details
                                </button>
                                <button class="text-emerald-600 hover:underline" data-action="retry"
                                    data-doc-id="<?= (int)$row['id'] ?>"
                                    data-doc-title="<?= htmlspecialchars($row['title'], ENT_QUOTES) ?>">
                                    Retry now
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Retry settings</h2>
                <p class="text-xs text-slate-500">Paste the reprocess token if required to trigger retries directly from this page.</p>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-500">
                <span class="inline-flex items-center px-2 py-1 rounded-full <?= $tokenRequired ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' ?>">
                    <?= $tokenRequired ? 'Token required' : 'Token optional' ?>
                </span>
            </div>
        </div>
        <div class="mt-4">
            <label class="text-xs font-semibold text-slate-600">Reprocess token</label>
            <input type="password" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm" placeholder="<?= $tokenRequired ? 'Paste REPROCESS_TOKEN here to enable retries' : 'Optional – leave blank if not set' ?>" data-token-input>
            <p class="text-xs text-slate-400 mt-2">
                Stored locally in this browser only. Leave blank if no token is configured on the server.
            </p>
        </div>
    </section>
</main>

<div class="fixed inset-0 bg-slate-900/40 hidden items-center justify-center z-50" data-error-modal>
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 p-6 space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-slate-400" data-modal-step>Step</p>
                <h3 class="text-lg font-semibold text-slate-900" data-modal-title>Document Title</h3>
                <p class="text-xs text-slate-400" data-modal-timestamp></p>
            </div>
            <button class="text-slate-400 hover:text-slate-600" data-action="close-modal" aria-label="Close">
                ✕
            </button>
        </div>
        <p class="text-sm text-slate-700 whitespace-pre-line" data-modal-message></p>
        <div class="flex items-center justify-between text-sm">
            <a href="#" class="text-blue-600 hover:underline" data-modal-doc-link target="_blank" rel="noopener">Open document</a>
            <button class="text-emerald-600 hover:underline" data-action="modal-retry" data-doc-id="">Retry now</button>
        </div>
    </div>
</div>

<script>
(() => {
    const root = document.querySelector('[data-progress-root]');
    if (!root) return;

    const statsEls = {
        documents: root.querySelector('[data-stat="documents"]'),
        local: root.querySelector('[data-stat="local"]'),
        remote: root.querySelector('[data-stat="remote"]'),
        ocr: root.querySelector('[data-stat="ocr"]'),
        ai: root.querySelector('[data-stat="ai"]'),
        localPct: root.querySelector('[data-stat="localPct"]')
    };
    const updatedEl = root.querySelector('[data-progress-updated]');
    const statusEls = {
        pending: root.querySelector('[data-status="pending"]'),
        downloaded: root.querySelector('[data-status="downloaded"]'),
        processed: root.querySelector('[data-status="processed"]'),
        error: root.querySelector('[data-status="error"]')
    };
    const remoteWrapper = root.querySelector('[data-remote-queue]');
    const errorWrapper = root.querySelector('[data-error-list]');
    const modal = document.querySelector('[data-error-modal]');
    const modalTitle = modal?.querySelector('[data-modal-title]');
    const modalStep = modal?.querySelector('[data-modal-step]');
    const modalMessage = modal?.querySelector('[data-modal-message]');
    const modalTimestamp = modal?.querySelector('[data-modal-timestamp]');
    const modalDocLink = modal?.querySelector('[data-modal-doc-link]');
    const modalRetryButton = modal?.querySelector('[data-action="modal-retry"]');
    const tokenInput = root.querySelector('[data-token-input]');
    const tokenStorageKey = 'ingestion_reprocess_token';
    if (tokenInput) {
        const saved = localStorage.getItem(tokenStorageKey);
        if (saved) tokenInput.value = saved;
        tokenInput.addEventListener('input', () => {
            localStorage.setItem(tokenStorageKey, tokenInput.value);
        });
    }

    const needsToken = root.dataset.tokenRequired === '1';

    async function refresh() {
        try {
            const res = await fetch('/ingestion_progress.php?format=json', { cache: 'no-store' });
            if (!res.ok) throw new Error('Network error');
            const data = await res.json();

            const formatter = new Intl.NumberFormat();
            const totalDocs = data.totals.documents || 0;

            statsEls.documents.textContent = formatter.format(totalDocs);
            statsEls.local.textContent = formatter.format(data.totals.local || 0);
            statsEls.remote.textContent = formatter.format(data.totals.remote || 0);
            statsEls.ocr.textContent = formatter.format(data.totals.ocr || 0);
            statsEls.ai.textContent = formatter.format(data.totals.ai || 0);
            statsEls.localPct.textContent = totalDocs
                ? `${Math.round((data.totals.local / totalDocs) * 100)}% local coverage`
                : '0% local coverage';

            Object.entries(statusEls).forEach(([key, el]) => {
                if (el) el.textContent = formatter.format(data.statuses?.[key] || 0);
            });

            const encode = (value = '') => {
                const div = document.createElement('div');
                div.textContent = value ?? '';
                return div.innerHTML;
            };

            remoteWrapper.innerHTML = data.remote_queue?.length
                ? data.remote_queue.map(row => {
                    const errorSection = row.error_message
                        ? `<button class="text-blue-600 hover:underline" data-action="view-error"
                                data-doc-id="${row.id}"
                                data-doc-title="${encode(row.title)}"
                                data-error-step="${encode(row.error_step ?? 'unknown')}"
                                data-error-message="${encode(row.error_message)}"
                                data-error-timestamp="${row.error_created_at ?? ''}">
                                View error
                            </button>`
                        : '';
                    return `
                        <div class="p-3 border border-slate-100 rounded-xl hover:border-blue-200 transition-colors space-y-2">
                            <div class="flex items-center justify-between text-xs uppercase tracking-wide text-slate-400">
                                <span>${encode(row.data_set ?? 'Uncategorized')}</span>
                                <span>${row.updated_at ? new Date(row.updated_at).toLocaleString() : ''}</span>
                            </div>
                            <a href="/document.php?id=${row.id}" class="text-sm font-semibold text-slate-800 hover:text-blue-600 line-clamp-2">${encode(row.title)}</a>
                            <p class="text-xs text-slate-400">Status: ${encode(row.status ?? 'unknown')}</p>
                            <div class="flex items-center gap-3 text-xs">
                                ${errorSection}
                                <button class="text-emerald-600 hover:underline" data-action="retry"
                                    data-doc-id="${row.id}"
                                    data-doc-title="${encode(row.title)}">
                                    Retry download/OCR
                                </button>
                            </div>
                        </div>
                    `;
                }).join('')
                : '<p class="text-sm text-slate-500">Queue is clear 🙌</p>';

            errorWrapper.innerHTML = data.recent_errors?.length
                ? data.recent_errors.map(row => `
                        <div class="p-3 border border-rose-100 bg-rose-50/50 rounded-xl space-y-2">
                            <div class="flex items-center justify-between text-xs uppercase tracking-wide text-rose-400">
                                <span>${encode(row.data_set ?? 'Uncategorized')}</span>
                                <span>${row.error_created_at ? new Date(row.error_created_at).toLocaleString() : ''}</span>
                            </div>
                            <a href="/document.php?id=${row.id}" class="text-sm font-semibold text-rose-700 hover:text-rose-900 line-clamp-2">${encode(row.title)}</a>
                            <p class="text-xs text-rose-500">Status: ${encode(row.status ?? 'error')}</p>
                            <div class="flex items-center gap-3 text-xs">
                                <button class="text-rose-600 hover:underline" data-action="view-error"
                                    data-doc-id="${row.id}"
                                    data-doc-title="${encode(row.title)}"
                                    data-error-step="${encode(row.error_step ?? 'unknown')}"
                                    data-error-message="${encode(row.error_message ?? '')}"
                                    data-error-timestamp="${row.error_created_at ?? ''}">
                                    View details
                                </button>
                                <button class="text-emerald-600 hover:underline" data-action="retry"
                                    data-doc-id="${row.id}"
                                    data-doc-title="${encode(row.title)}">
                                    Retry now
                                </button>
                            </div>
                        </div>
                    `).join('')
                : '<p class="text-sm text-slate-500">No errors 🎉</p>';

            updatedEl.textContent = new Date().toLocaleString();
        } catch (err) {
            console.error('Refresh failed', err);
        }
    }

    setInterval(refresh, 10000);

    function showModal({ docId, docTitle, errorStep, errorMessage, errorTimestamp }) {
        if (!modal) return;
        modalTitle.textContent = docTitle || `Document #${docId}`;
        modalStep.textContent = errorStep ? `Step: ${errorStep}` : 'Step: unknown';
        modalMessage.textContent = errorMessage || 'No additional details recorded.';
        modalTimestamp.textContent = errorTimestamp ? new Date(errorTimestamp).toLocaleString() : '';
        modalDocLink.href = `/document.php?id=${docId}`;
        modalRetryButton.dataset.docId = docId;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function hideModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    async function retryDocument(docId, docTitle) {
        if (!docId) return;
        const token = tokenInput?.value.trim();
        if (needsToken && !token) {
            alert('This server requires a reprocess token. Paste it in the field above before retrying.');
            return;
        }
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (token) {
                headers['X-Reprocess-Token'] = token;
            }
            const res = await fetch('/api/reprocess_document.php', {
                method: 'POST',
                headers,
                body: JSON.stringify({ id: parseInt(docId, 10), clear_pages: false, token })
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json.ok) {
                throw new Error(json.error || 'Retry failed');
            }
            alert(`Queued "${docTitle || docId}" for reprocessing.`);
            refresh();
        } catch (err) {
            alert(err.message);
        }
    }

    async function retryAllErrors() {
        const token = tokenInput?.value.trim();
        if (needsToken && !token) {
            alert('This server requires a reprocess token. Paste it in the field above before retrying.');
            return;
        }
        if (!confirm('Requeue every document currently marked error?')) {
            return;
        }
        try {
            const headers = { 'Content-Type': 'application/json' };
            if (token) {
                headers['X-Reprocess-Token'] = token;
            }
            const res = await fetch('/api/retry_errors.php', {
                method: 'POST',
                headers,
                body: JSON.stringify({ clear_pages: false, token })
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json.ok) {
                throw new Error(json.error || 'Bulk retry failed');
            }
            alert(json.count
                ? `Queued ${json.count} document(s) for reprocessing.`
                : 'No error documents to retry.');
            refresh();
        } catch (err) {
            alert(err.message);
        }
    }

    root.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        if (action === 'view-error') {
            event.preventDefault();
            showModal({
                docId: btn.dataset.docId,
                docTitle: btn.dataset.docTitle,
                errorStep: btn.dataset.errorStep,
                errorMessage: btn.dataset.errorMessage,
                errorTimestamp: btn.dataset.errorTimestamp
            });
        } else if (action === 'retry') {
            event.preventDefault();
            retryDocument(btn.dataset.docId, btn.dataset.docTitle);
        } else if (action === 'close-modal') {
            event.preventDefault();
            hideModal();
        } else if (action === 'retry-all') {
            event.preventDefault();
            retryAllErrors();
        }
    });

    modalRetryButton?.addEventListener('click', (event) => {
        const docId = modalRetryButton.dataset.docId;
        const title = modalTitle?.textContent || '';
        retryDocument(docId, title);
        event.preventDefault();
    });

    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            hideModal();
        }
    });
})();
</script>

</body>
</html>
