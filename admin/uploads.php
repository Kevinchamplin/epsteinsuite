<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// Get filter status
$filterStatus = $_GET['status'] ?? 'pending';
$allowedStatuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = 'pending';
}

// Build query
$sql = "
    SELECT
        d.id,
        d.title,
        d.description,
        d.data_set,
        d.file_type,
        d.file_size,
        d.status,
        d.approval_status,
        d.approved_by,
        d.approved_at,
        d.local_path,
        d.created_at,
        (SELECT COUNT(*) FROM pages p WHERE p.document_id = d.id) as page_count,
        CASE WHEN d.ai_summary IS NOT NULL AND d.ai_summary != '' THEN 1 ELSE 0 END as has_ai_summary
    FROM documents d
    WHERE d.upload_source = 'user_upload'
";

if ($filterStatus !== 'all') {
    $sql .= " AND d.approval_status = :status";
}

$sql .= " ORDER BY d.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
if ($filterStatus !== 'all') {
    $stmt->execute([':status' => $filterStatus]);
} else {
    $stmt->execute();
}
$uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for badges
$counts = $pdo->query("
    SELECT
        approval_status,
        COUNT(*) as count
    FROM documents
    WHERE upload_source = 'user_upload'
    GROUP BY approval_status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$pendingCount = (int)($counts['pending'] ?? 0);
$approvedCount = (int)($counts['approved'] ?? 0);
$rejectedCount = (int)($counts['rejected'] ?? 0);
$totalCount = $pendingCount + $approvedCount + $rejectedCount;

// Render
admin_render_layout('User Uploads', 'uploads', function () use ($uploads, $filterStatus, $pendingCount, $approvedCount, $rejectedCount, $totalCount) {
    ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Total Uploads</p>
            <p class="text-3xl font-bold text-slate-900"><?= number_format($totalCount) ?></p>
        </div>
        <div class="bg-white border border-amber-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-amber-600">Pending Review</p>
            <p class="text-3xl font-bold text-amber-600"><?= number_format($pendingCount) ?></p>
        </div>
        <div class="bg-white border border-emerald-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-emerald-600">Approved</p>
            <p class="text-3xl font-bold text-emerald-600"><?= number_format($approvedCount) ?></p>
        </div>
        <div class="bg-white border border-rose-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-rose-600">Rejected</p>
            <p class="text-3xl font-bold text-rose-600"><?= number_format($rejectedCount) ?></p>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="border-b border-slate-200">
            <div class="flex gap-1 px-4 py-3">
                <?php
                $tabs = [
                    'pending' => ['label' => 'Pending Review', 'color' => 'amber'],
                    'approved' => ['label' => 'Approved', 'color' => 'emerald'],
                    'rejected' => ['label' => 'Rejected', 'color' => 'rose'],
                    'all' => ['label' => 'All Uploads', 'color' => 'slate'],
                ];
                foreach ($tabs as $key => $tab):
                    $active = $filterStatus === $key;
                    $colorClasses = match($tab['color']) {
                        'amber' => $active ? 'bg-amber-100 text-amber-700 border-amber-300' : 'text-slate-600 hover:bg-slate-50',
                        'emerald' => $active ? 'bg-emerald-100 text-emerald-700 border-emerald-300' : 'text-slate-600 hover:bg-slate-50',
                        'rose' => $active ? 'bg-rose-100 text-rose-700 border-rose-300' : 'text-slate-600 hover:bg-slate-50',
                        default => $active ? 'bg-slate-100 text-slate-700 border-slate-300' : 'text-slate-600 hover:bg-slate-50',
                    };
                ?>
                    <a href="?status=<?= $key ?>"
                       class="px-4 py-2 text-sm font-semibold rounded-lg border <?= $colorClasses ?> transition">
                        <?= htmlspecialchars($tab['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Uploads List -->
        <div class="divide-y divide-slate-100">
            <?php if (empty($uploads)): ?>
                <div class="p-12 text-center text-slate-400">
                    <p class="text-lg font-semibold mb-2">No uploads found</p>
                    <p class="text-sm">User uploads will appear here for review.</p>
                </div>
            <?php else: ?>
                <?php foreach ($uploads as $upload):
                    $statusColors = match($upload['approval_status']) {
                        'pending' => 'bg-amber-100 text-amber-700 border-amber-200',
                        'approved' => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                        'rejected' => 'bg-rose-100 text-rose-700 border-rose-200',
                        default => 'bg-slate-100 text-slate-700 border-slate-200',
                    };

                    $processingStatusColors = match($upload['status']) {
                        'pending' => 'bg-slate-100 text-slate-600',
                        'downloaded' => 'bg-blue-100 text-blue-700',
                        'processed' => 'bg-teal-100 text-teal-700',
                        'error' => 'bg-red-100 text-red-700',
                        default => 'bg-slate-100 text-slate-600',
                    };

                    $fileIcon = match($upload['file_type']) {
                        'pdf' => '📄',
                        'png', 'jpg', 'jpeg' => '🖼️',
                        default => '📎',
                    };
                ?>
                    <div class="p-6 hover:bg-slate-50/50 transition" data-upload-id="<?= $upload['id'] ?>">
                        <div class="flex items-start gap-4">
                            <!-- File Icon -->
                            <div class="text-4xl flex-shrink-0"><?= $fileIcon ?></div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-4 mb-2">
                                    <div class="flex-1">
                                        <h3 class="text-lg font-semibold text-slate-900 mb-1">
                                            <a href="/document.php?id=<?= $upload['id'] ?>" target="_blank" class="hover:text-blue-600 transition">
                                                <?= htmlspecialchars($upload['title']) ?>
                                            </a>
                                        </h3>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="inline-block px-2 py-1 text-xs font-semibold rounded border <?= $statusColors ?>">
                                                <?= strtoupper($upload['approval_status']) ?>
                                            </span>
                                            <span class="inline-block px-2 py-1 text-xs font-semibold rounded <?= $processingStatusColors ?>">
                                                <?= strtoupper($upload['status']) ?>
                                            </span>
                                            <?php if ($upload['data_set']): ?>
                                                <span class="text-xs text-slate-500">
                                                    📁 <?= htmlspecialchars($upload['data_set']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="text-xs text-slate-500">
                                                <?= strtoupper($upload['file_type']) ?> • <?= number_format($upload['file_size'] / 1024 / 1024, 2) ?> MB
                                            </span>
                                            <?php if ($upload['page_count'] > 0): ?>
                                                <span class="text-xs text-slate-500">
                                                    📃 <?= $upload['page_count'] ?> pages
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($upload['has_ai_summary']): ?>
                                                <span class="text-xs text-purple-600 font-semibold">
                                                    ✨ AI Summary
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <?php if ($upload['approval_status'] === 'pending'): ?>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <button onclick="approveUpload(<?= $upload['id'] ?>)"
                                                    class="px-4 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 transition">
                                                ✓ Approve
                                            </button>
                                            <button onclick="rejectUpload(<?= $upload['id'] ?>)"
                                                    class="px-4 py-2 text-sm font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 transition">
                                                ✗ Reject
                                            </button>
                                        </div>
                                    <?php elseif ($upload['approval_status'] === 'rejected'): ?>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <button onclick="approveUpload(<?= $upload['id'] ?>)"
                                                    class="px-4 py-2 text-sm font-semibold rounded-lg border border-emerald-600 text-emerald-600 hover:bg-emerald-50 transition">
                                                Restore
                                            </button>
                                            <button onclick="deleteUpload(<?= $upload['id'] ?>)"
                                                    class="px-4 py-2 text-sm font-semibold rounded-lg border border-red-600 text-red-600 hover:bg-red-50 transition">
                                                Delete
                                            </button>
                                        </div>
                                    <?php elseif ($upload['approval_status'] === 'approved'): ?>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <button onclick="rejectUpload(<?= $upload['id'] ?>)"
                                                    class="px-3 py-1.5 text-xs font-semibold rounded border border-slate-300 text-slate-600 hover:bg-slate-50 transition">
                                                Reject
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <p class="text-sm text-slate-700 mb-3 line-clamp-2">
                                    <?= htmlspecialchars($upload['description']) ?>
                                </p>

                                <?php
                                // TODO: Re-enable duplicate detection when duplicate_check_data column is added
                                // Temporarily disabled to fix 500 error
                                /*
                                if ($upload['duplicate_check_data']):
                                    $dupData = json_decode($upload['duplicate_check_data'], true);
                                    if (isset($dupData['potential_matches']) && !empty($dupData['potential_matches'])):
                                ?>
                                    <div class="mb-3 rounded-lg border-2 border-amber-300 bg-amber-50 p-3">
                                        <p class="text-xs font-semibold text-amber-900 mb-2">
                                            ⚠️ Potential Duplicates Detected (<?= count($dupData['potential_matches']) ?>)
                                        </p>
                                        <div class="space-y-2">
                                            <?php foreach ($dupData['potential_matches'] as $match): ?>
                                                <div class="text-xs bg-white rounded border border-amber-200 p-2">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <a href="/document.php?id=<?= $match['id'] ?>" target="_blank"
                                                           class="text-blue-600 hover:underline font-semibold">
                                                            Doc #<?= $match['id'] ?>: <?= htmlspecialchars($match['title']) ?>
                                                        </a>
                                                        <span class="text-amber-700 font-mono">
                                                            <?= $match['filename_similarity'] ?>% similar
                                                        </span>
                                                    </div>
                                                    <?php if ($match['size_diff_bytes'] > 0): ?>
                                                        <span class="text-slate-500">
                                                            Size diff: <?= number_format($match['size_diff_bytes'] / 1024, 1) ?> KB
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="text-xs text-amber-700 mt-2">
                                            Uploaded filename: <code class="bg-amber-100 px-1 rounded"><?= htmlspecialchars($dupData['upload_filename'] ?? 'unknown') ?></code>
                                        </p>
                                    </div>
                                <?php
                                    endif;
                                endif;
                                */
                                ?>

                                <div class="flex items-center gap-4 text-xs text-slate-500">
                                    <span>ID: <?= $upload['id'] ?></span>
                                    <span>Uploaded: <?= date('M j, Y g:i A', strtotime($upload['created_at'])) ?></span>
                                    <?php if ($upload['approved_at']): ?>
                                        <span>
                                            <?= $upload['approval_status'] === 'approved' ? 'Approved' : 'Rejected' ?>:
                                            <?= date('M j, Y g:i A', strtotime($upload['approved_at'])) ?>
                                            <?php if ($upload['approved_by']): ?>
                                                by <?= htmlspecialchars($upload['approved_by']) ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($upload['local_path'] && file_exists($upload['local_path'])): ?>
                                        <a href="/serve.php?id=<?= $upload['id'] ?>" target="_blank" class="text-blue-600 hover:underline">
                                            Download File
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Activity Log -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6" id="activity-log-card">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Activity Log</h2>
        <div id="activity-log" class="space-y-1 max-h-64 overflow-y-auto text-sm text-slate-600">
            <p class="text-slate-400 text-xs">Moderation actions will appear here.</p>
        </div>
    </div>

    <script>
    const AUTH_HEADER = 'Basic ' + <?= json_encode(base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'])) ?>;
    const log = document.getElementById('activity-log');

    function addLog(msg, type = 'info') {
        const colors = { info: 'text-blue-600', success: 'text-emerald-600', error: 'text-rose-600' };
        const p = document.createElement('p');
        p.className = `text-xs ${colors[type] || 'text-slate-600'}`;
        p.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
        log.prepend(p);
    }

    async function approveUpload(id) {
        if (!confirm('Approve this upload? It will be processed and made searchable.')) return;

        addLog(`Approving upload ${id}...`);

        try {
            const res = await fetch('/api/moderate_upload.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Authorization': AUTH_HEADER },
                body: JSON.stringify({ id, action: 'approve' })
            });

            if (!res.ok) {
                const text = await res.text();
                addLog(`ERROR: HTTP ${res.status}: ${text}`, 'error');
                return;
            }

            const json = await res.json();
            if (json.ok) {
                addLog(`✓ Upload ${id} approved`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                addLog(`ERROR: ${json.error}`, 'error');
            }
        } catch (err) {
            addLog(`ERROR: ${err.message}`, 'error');
        }
    }

    async function rejectUpload(id) {
        if (!confirm('Reject this upload? It will be hidden from search results.')) return;

        addLog(`Rejecting upload ${id}...`);

        try {
            const res = await fetch('/api/moderate_upload.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Authorization': AUTH_HEADER },
                body: JSON.stringify({ id, action: 'reject' })
            });

            if (!res.ok) {
                const text = await res.text();
                addLog(`ERROR: HTTP ${res.status}: ${text}`, 'error');
                return;
            }

            const json = await res.json();
            if (json.ok) {
                addLog(`✓ Upload ${id} rejected`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                addLog(`ERROR: ${json.error}`, 'error');
            }
        } catch (err) {
            addLog(`ERROR: ${err.message}`, 'error');
        }
    }

    async function deleteUpload(id) {
        if (!confirm('Permanently delete this upload? This cannot be undone.')) return;

        addLog(`Deleting upload ${id}...`);

        try {
            const res = await fetch('/api/moderate_upload.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Authorization': AUTH_HEADER },
                body: JSON.stringify({ id, action: 'delete' })
            });

            if (!res.ok) {
                const text = await res.text();
                addLog(`ERROR: HTTP ${res.status}: ${text}`, 'error');
                return;
            }

            const json = await res.json();
            if (json.ok) {
                addLog(`✓ Upload ${id} deleted`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                addLog(`ERROR: ${json.error}`, 'error');
            }
        } catch (err) {
            addLog(`ERROR: ${err.message}`, 'error');
        }
    }
    </script>
    <?php
});
