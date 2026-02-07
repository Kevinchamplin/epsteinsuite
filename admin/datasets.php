<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

// ── Known dataset reference info ──────────────────────────────────
$datasetRef = [
    'DOJ - Data Set 1'  => ['size' => '~1.3 GB',  'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%201.zip'],
    'DOJ - Data Set 2'  => ['size' => '~631 MB',   'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%202.zip'],
    'DOJ - Data Set 3'  => ['size' => '~595 MB',   'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%203.zip'],
    'DOJ - Data Set 4'  => ['size' => '~352 MB',   'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%204.zip'],
    'DOJ - Data Set 5'  => ['size' => '~61 MB',    'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%205.zip'],
    'DOJ - Data Set 6'  => ['size' => '~51 MB',    'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%206.zip'],
    'DOJ - Data Set 7'  => ['size' => '~97 MB',    'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%207.zip'],
    'DOJ - Data Set 8'  => ['size' => '~10 GB',    'methods' => ['zip'],           'zip' => 'https://www.justice.gov/epstein/files/DataSet%208.zip'],
    'DOJ - Data Set 9'  => ['size' => '~101 GB',   'methods' => ['torrent','scrape'], 'magnet' => 'magnet:?xt=urn:btih:0a3d4b84a77bd982c9c2761f40944402b94f9c64', 'note' => '46GB partial torrent; scrape for full coverage'],
    'DOJ - Data Set 10' => ['size' => '~82 GB',    'methods' => ['torrent'],       'magnet' => 'magnet:?xt=urn:btih:d509cc4ca1a415a9ba3b6cb920f67c44aed7fe1f', 'note' => 'Full, verified (SHA256: 7D6935B1...)'],
    'DOJ - Data Set 11' => ['size' => '~50 GB',    'methods' => ['scrape'],        'note' => 'No reliable torrent; scrape individual PDFs'],
    'DOJ - Data Set 12' => ['size' => '~114 MB',   'methods' => ['zip','torrent'], 'zip' => 'https://www.justice.gov/epstein/files/DataSet%2012.zip', 'magnet' => 'magnet:?xt=urn:btih:8bc781c7259f4b82406cd2175a1d5e9c3b6bfc90'],
];

// ── Query live stats ──────────────────────────────────────────────
$sql = "
    SELECT
        CASE
            WHEN data_set IS NULL OR data_set = '' THEN 'Uncategorized'
            ELSE data_set
        END AS data_set,
        COUNT(*) AS total,
        SUM(CASE WHEN local_path IS NOT NULL AND local_path != '' THEN 1 ELSE 0 END) AS has_local,
        SUM(CASE WHEN local_path IS NULL OR local_path = '' THEN 1 ELSE 0 END) AS missing_local,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'downloaded' THEN 1 ELSE 0 END) AS downloaded,
        SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) AS processed,
        SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
        SUM(CASE WHEN ai_summary IS NOT NULL AND ai_summary != '' THEN 1 ELSE 0 END) AS has_ai
    FROM documents
    GROUP BY data_set
    ORDER BY
        CASE WHEN data_set LIKE 'DOJ - Data Set %%' THEN 0 ELSE 1 END,
        CASE WHEN data_set LIKE 'DOJ - Data Set %%'
            THEN CAST(SUBSTRING_INDEX(data_set, ' ', -1) AS UNSIGNED)
            ELSE 999
        END,
        data_set
";

$datasets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totals = ['total' => 0, 'has_local' => 0, 'missing_local' => 0, 'pending' => 0, 'downloaded' => 0, 'processed' => 0, 'errors' => 0, 'has_ai' => 0];
foreach ($datasets as &$ds) {
    foreach ($totals as $k => &$v) {
        $ds[$k] = (int)$ds[$k];
        $v += $ds[$k];
    }
    unset($v);
    $ds['local_pct'] = $ds['total'] > 0 ? round(($ds['has_local'] / $ds['total']) * 100, 1) : 0;
    $ds['ai_pct'] = $ds['total'] > 0 ? round(($ds['has_ai'] / $ds['total']) * 100, 1) : 0;
}
unset($ds);

// ── Render ────────────────────────────────────────────────────────
admin_render_layout('Dataset Inventory', 'datasets', function () use ($datasets, $totals, $datasetRef) {
    $totalLocalPct = $totals['total'] > 0 ? round(($totals['has_local'] / $totals['total']) * 100, 1) : 0;
    $totalAiPct = $totals['total'] > 0 ? round(($totals['has_ai'] / $totals['total']) * 100, 1) : 0;
    ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Total Docs</p>
            <p class="text-3xl font-bold text-slate-900" data-stat="total"><?= number_format($totals['total']) ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Local Files</p>
            <p class="text-3xl font-bold text-emerald-600" data-stat="local"><?= number_format($totals['has_local']) ?></p>
            <p class="text-xs text-slate-400"><?= $totalLocalPct ?>%</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Missing</p>
            <p class="text-3xl font-bold text-rose-600" data-stat="missing"><?= number_format($totals['missing_local']) ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Needs OCR</p>
            <p class="text-3xl font-bold text-orange-600" data-stat="needs_ocr"><?= number_format($totals['downloaded']) ?></p>
            <p class="text-xs text-slate-400">downloaded, not processed</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Processed</p>
            <p class="text-3xl font-bold text-teal-600" data-stat="processed"><?= number_format($totals['processed']) ?></p>
            <p class="text-xs text-slate-400">OCR complete</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">Errors</p>
            <p class="text-3xl font-bold text-amber-600" data-stat="errors"><?= number_format($totals['errors']) ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-slate-500">AI Summaries</p>
            <p class="text-3xl font-bold text-purple-600" data-stat="ai"><?= number_format($totals['has_ai']) ?></p>
            <p class="text-xs text-slate-400"><?= $totalAiPct ?>%</p>
        </div>
    </div>

    <!-- Dataset Table -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Dataset Inventory</h2>
                <p class="text-xs text-slate-500 mt-1">Live counts from production DB. Auto-refreshes every 15s.</p>
            </div>
            <div class="text-xs text-slate-400">
                Updated: <span id="last-refresh"><?= date('g:i:s A') ?></span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" id="dataset-table">
                <thead>
                    <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500 bg-slate-50">
                        <th class="text-left py-3 px-4">Dataset</th>
                        <th class="text-right py-3 px-3">Total</th>
                        <th class="text-right py-3 px-3">Local</th>
                        <th class="text-right py-3 px-3">Missing</th>
                        <th class="text-right py-3 px-3">Pending</th>
                        <th class="text-right py-3 px-3">Needs OCR</th>
                        <th class="text-right py-3 px-3">Processed</th>
                        <th class="text-right py-3 px-3">Errors</th>
                        <th class="text-right py-3 px-3">AI</th>
                        <th class="text-left py-3 px-3 w-44">Coverage</th>
                        <th class="text-left py-3 px-3">Source</th>
                        <th class="text-center py-3 px-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="dataset-tbody">
                    <?php foreach ($datasets as $ds):
                        $name = $ds['data_set'];
                        $ref = $datasetRef[$name] ?? null;
                        $pct = $ds['local_pct'];

                        // Color coding
                        if ($pct >= 90) {
                            $barColor = 'bg-emerald-500';
                            $rowBg = '';
                        } elseif ($pct >= 50) {
                            $barColor = 'bg-amber-500';
                            $rowBg = '';
                        } else {
                            $barColor = 'bg-rose-500';
                            $rowBg = $ds['missing_local'] > 1000 ? 'bg-rose-50/50' : '';
                        }

                        $methods = $ref['methods'] ?? [];
                        $methodBadges = '';
                        foreach ($methods as $m) {
                            $colors = match($m) {
                                'zip' => 'bg-blue-100 text-blue-700',
                                'torrent' => 'bg-purple-100 text-purple-700',
                                'scrape' => 'bg-amber-100 text-amber-700',
                                default => 'bg-slate-100 text-slate-700',
                            };
                            $methodBadges .= "<span class=\"inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold {$colors} mr-1\">" . strtoupper($m) . "</span>";
                        }
                        if ($ref && isset($ref['size'])) {
                            $methodBadges .= "<span class=\"text-[10px] text-slate-400 ml-1\">{$ref['size']}</span>";
                        }
                    ?>
                    <tr class="hover:bg-slate-50/70 <?= $rowBg ?>" data-dataset="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
                        <td class="py-3 px-4">
                            <div class="font-medium text-slate-800"><?= htmlspecialchars($name) ?></div>
                            <?php if ($ref && isset($ref['note'])): ?>
                                <div class="text-[10px] text-slate-400 mt-0.5"><?= htmlspecialchars($ref['note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-3 text-right font-semibold text-slate-700"><?= number_format($ds['total']) ?></td>
                        <td class="py-3 px-3 text-right font-semibold text-emerald-600"><?= number_format($ds['has_local']) ?></td>
                        <td class="py-3 px-3 text-right font-semibold <?= $ds['missing_local'] > 0 ? 'text-rose-600' : 'text-slate-400' ?>">
                            <?= number_format($ds['missing_local']) ?>
                        </td>
                        <td class="py-3 px-3 text-right text-slate-600"><?= number_format($ds['pending']) ?></td>
                        <td class="py-3 px-3 text-right <?= $ds['downloaded'] > 0 ? 'text-orange-600 font-semibold' : 'text-slate-400' ?>">
                            <?= number_format($ds['downloaded']) ?>
                        </td>
                        <td class="py-3 px-3 text-right <?= $ds['processed'] > 0 ? 'text-emerald-600' : 'text-slate-400' ?>">
                            <?= number_format($ds['processed']) ?>
                        </td>
                        <td class="py-3 px-3 text-right <?= $ds['errors'] > 0 ? 'text-amber-600 font-semibold' : 'text-slate-400' ?>">
                            <?= number_format($ds['errors']) ?>
                        </td>
                        <td class="py-3 px-3 text-right text-purple-600"><?= number_format($ds['has_ai']) ?></td>
                        <td class="py-3 px-3">
                            <div class="space-y-1">
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full <?= $barColor ?> transition-all" style="width: <?= $pct ?>%;"></div>
                                </div>
                                <div class="text-[10px] text-slate-500"><?= $pct ?>% local</div>
                            </div>
                        </td>
                        <td class="py-3 px-3"><?= $methodBadges ?></td>
                        <td class="py-3 px-3 text-center">
                            <div class="flex items-center justify-center gap-1 flex-wrap">
                                <?php if (in_array('zip', $methods)): ?>
                                    <button onclick="startDownload('<?= htmlspecialchars($name, ENT_QUOTES) ?>', 'zip')"
                                        class="px-2 py-1 text-[10px] font-semibold rounded bg-blue-600 text-white hover:bg-blue-700 transition">
                                        ZIP
                                    </button>
                                <?php endif; ?>
                                <?php if (in_array('scrape', $methods) || in_array('torrent', $methods)): ?>
                                    <button onclick="startDownload('<?= htmlspecialchars($name, ENT_QUOTES) ?>', 'scrape')"
                                        class="px-2 py-1 text-[10px] font-semibold rounded bg-amber-600 text-white hover:bg-amber-700 transition">
                                        Scrape
                                    </button>
                                <?php endif; ?>
                                <?php if ($ds['pending'] > 0 || $ds['downloaded'] > 0): ?>
                                    <button onclick="startDownload('<?= htmlspecialchars($name, ENT_QUOTES) ?>', 'ocr')"
                                        class="px-2 py-1 text-[10px] font-semibold rounded bg-indigo-600 text-white hover:bg-indigo-700 transition">
                                        OCR
                                    </button>
                                <?php endif; ?>
                                <?php if ($ds['processed'] > $ds['has_ai']): ?>
                                    <button onclick="startDownload('<?= htmlspecialchars($name, ENT_QUOTES) ?>', 'ai')"
                                        class="px-2 py-1 text-[10px] font-semibold rounded bg-purple-600 text-white hover:bg-purple-700 transition">
                                        AI
                                    </button>
                                <?php endif; ?>
                                <?php if ($ref && isset($ref['magnet'])): ?>
                                    <button onclick="copyMagnet('<?= htmlspecialchars($ref['magnet'], ENT_QUOTES) ?>')"
                                        class="px-2 py-1 text-[10px] font-semibold rounded bg-purple-600 text-white hover:bg-purple-700 transition"
                                        title="Copy magnet link">
                                        Magnet
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <!-- Totals Row -->
                    <tr class="bg-slate-50 font-semibold border-t-2 border-slate-200">
                        <td class="py-3 px-4 text-slate-800">TOTAL</td>
                        <td class="py-3 px-3 text-right text-slate-800"><?= number_format($totals['total']) ?></td>
                        <td class="py-3 px-3 text-right text-emerald-700"><?= number_format($totals['has_local']) ?></td>
                        <td class="py-3 px-3 text-right text-rose-700"><?= number_format($totals['missing_local']) ?></td>
                        <td class="py-3 px-3 text-right text-slate-600"><?= number_format($totals['pending']) ?></td>
                        <td class="py-3 px-3 text-right text-orange-700"><?= number_format($totals['downloaded']) ?></td>
                        <td class="py-3 px-3 text-right text-emerald-700"><?= number_format($totals['processed']) ?></td>
                        <td class="py-3 px-3 text-right text-amber-700"><?= number_format($totals['errors']) ?></td>
                        <td class="py-3 px-3 text-right text-purple-700"><?= number_format($totals['has_ai']) ?></td>
                        <td class="py-3 px-3 text-[10px] text-slate-500"><?= $totalLocalPct ?>% overall</td>
                        <td class="py-3 px-3"></td>
                        <td class="py-3 px-3"></td>
                        <td class="py-3 px-3"></td>
                        <td class="py-3 px-3"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Magnet Links Reference -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Torrent / Magnet Links (for manual download)</h2>
        <p class="text-xs text-slate-500 mb-4">These magnet links are from Archive.org mirrors. Use aria2c or a torrent client to download, then run the ingest scripts to register files in the database.</p>
        <div class="space-y-3">
            <div class="flex items-start gap-3 p-3 border border-slate-100 rounded-lg">
                <span class="text-xs font-bold text-slate-500 w-20 flex-shrink-0 pt-0.5">Dataset 9</span>
                <div class="min-w-0 flex-1">
                    <code class="text-xs text-slate-700 break-all block">magnet:?xt=urn:btih:0a3d4b84a77bd982c9c2761f40944402b94f9c64</code>
                    <p class="text-[10px] text-slate-400 mt-1">~46GB partial (scrape for full coverage)</p>
                </div>
                <button onclick="copyMagnet('magnet:?xt=urn:btih:0a3d4b84a77bd982c9c2761f40944402b94f9c64')" class="text-xs text-blue-600 hover:underline flex-shrink-0">Copy</button>
            </div>
            <div class="flex items-start gap-3 p-3 border border-slate-100 rounded-lg">
                <span class="text-xs font-bold text-slate-500 w-20 flex-shrink-0 pt-0.5">Dataset 10</span>
                <div class="min-w-0 flex-1">
                    <code class="text-xs text-slate-700 break-all block">magnet:?xt=urn:btih:d509cc4ca1a415a9ba3b6cb920f67c44aed7fe1f</code>
                    <p class="text-[10px] text-slate-400 mt-1">~82GB full, verified (SHA256: 7D6935B1C63FF2F6BCABDD024EBC2A770F90C43B0D57B646FA7CBD4C0ABCF846)</p>
                </div>
                <button onclick="copyMagnet('magnet:?xt=urn:btih:d509cc4ca1a415a9ba3b6cb920f67c44aed7fe1f')" class="text-xs text-blue-600 hover:underline flex-shrink-0">Copy</button>
            </div>
            <div class="flex items-start gap-3 p-3 border border-slate-100 rounded-lg">
                <span class="text-xs font-bold text-slate-500 w-20 flex-shrink-0 pt-0.5">Dataset 12</span>
                <div class="min-w-0 flex-1">
                    <code class="text-xs text-slate-700 break-all block">magnet:?xt=urn:btih:8bc781c7259f4b82406cd2175a1d5e9c3b6bfc90</code>
                    <p class="text-[10px] text-slate-400 mt-1">~114MB (also available as direct ZIP)</p>
                </div>
                <button onclick="copyMagnet('magnet:?xt=urn:btih:8bc781c7259f4b82406cd2175a1d5e9c3b6bfc90')" class="text-xs text-blue-600 hover:underline flex-shrink-0">Copy</button>
            </div>
        </div>
    </div>

    <!-- Download Log -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-6" id="activity-log-card">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Activity Log</h2>
        <div id="activity-log" class="space-y-1 max-h-64 overflow-y-auto text-sm text-slate-600">
            <p class="text-slate-400 text-xs">Download actions will appear here.</p>
        </div>
    </div>

    <!-- Surebob Reference -->
    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-2">External Tool Reference</h2>
        <p class="text-sm text-slate-600 mb-3">
            <a href="https://github.com/Surebob/epstein-files-downloader" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">Surebob/epstein-files-downloader</a>
            — CLI tool for bulk downloading all 12 datasets via ZIP, torrent, or scraping. Install with <code class="text-xs bg-white px-1.5 py-0.5 rounded border">pip install epstein-downloader</code> + <code class="text-xs bg-white px-1.5 py-0.5 rounded border">brew install aria2</code>
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
            <div class="bg-white rounded-lg p-3 border border-slate-200">
                <p class="font-bold text-slate-800">Download all</p>
                <code class="text-slate-600">epstein-dl download --all</code>
            </div>
            <div class="bg-white rounded-lg p-3 border border-slate-200">
                <p class="font-bold text-slate-800">Torrents only</p>
                <code class="text-slate-600">epstein-dl download --torrents</code>
            </div>
            <div class="bg-white rounded-lg p-3 border border-slate-200">
                <p class="font-bold text-slate-800">Check status</p>
                <code class="text-slate-600">epstein-dl status</code>
            </div>
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

    async function startDownload(dataset, method) {
        if (!confirm(`Start ${method.toUpperCase()} for "${dataset}"?`)) return;

        // Scroll to activity log so user sees feedback
        document.getElementById('activity-log-card').scrollIntoView({ behavior: 'smooth', block: 'center' });
        addLog(`Starting ${method} for ${dataset}...`);

        try {
            const res = await fetch('/api/dataset_download.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Authorization': AUTH_HEADER },
                body: JSON.stringify({ dataset, method, limit: 200 })
            });
            if (!res.ok) {
                const text = await res.text();
                addLog(`ERROR: ${dataset} ${method} — HTTP ${res.status}: ${text}`, 'error');
                return;
            }
            const json = await res.json();
            if (json.ok) {
                addLog(`OK: ${json.message}`, 'success');
                addLog(`Log file on server: storage/logs/${json.log_file}`, 'info');
            } else {
                addLog(`ERROR: ${dataset} — ${json.error}`, 'error');
            }
        } catch (err) {
            addLog(`ERROR: ${dataset} — ${err.message}`, 'error');
        }
    }

    function copyMagnet(link) {
        navigator.clipboard.writeText(link).then(() => {
            addLog('Magnet link copied to clipboard', 'success');
        }).catch(() => {
            prompt('Copy this magnet link:', link);
        });
    }

    // Auto-refresh stats every 15 seconds
    async function refreshStats() {
        try {
            const res = await fetch('/api/dataset_status.php', { headers: { 'Authorization': AUTH_HEADER }, cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();
            const fmt = new Intl.NumberFormat();

            // Update summary cards
            const statMap = {
                total: data.totals.total,
                local: data.totals.has_local,
                missing: data.totals.missing_local,
                needs_ocr: data.totals.downloaded,
                processed: data.totals.processed,
                errors: data.totals.errors,
                ai: data.totals.has_ai
            };
            Object.entries(statMap).forEach(([key, val]) => {
                const el = document.querySelector(`[data-stat="${key}"]`);
                if (el) el.textContent = fmt.format(val);
            });

            // Update table rows
            data.datasets.forEach(ds => {
                const row = document.querySelector(`tr[data-dataset="${CSS.escape(ds.data_set)}"]`);
                if (!row) return;
                const cells = row.querySelectorAll('td');
                if (cells.length >= 9) {
                    cells[1].textContent = fmt.format(ds.total);
                    cells[2].textContent = fmt.format(ds.has_local);
                    cells[3].textContent = fmt.format(ds.missing_local);
                    cells[4].textContent = fmt.format(ds.pending);
                    cells[5].textContent = fmt.format(ds.downloaded);
                    cells[6].textContent = fmt.format(ds.processed);
                    cells[7].textContent = fmt.format(ds.errors);
                    cells[8].textContent = fmt.format(ds.has_ai);

                    // Update progress bar
                    const bar = cells[9]?.querySelector('div > div');
                    const label = cells[9]?.querySelector('.text-\\[10px\\]');
                    if (bar) bar.style.width = ds.local_pct + '%';
                    if (label) label.textContent = ds.local_pct + '% local';
                }
            });

            document.getElementById('last-refresh').textContent = new Date().toLocaleTimeString();
        } catch (err) {
            console.error('Refresh failed', err);
        }
    }

    setInterval(refreshStats, 15000);
    </script>
    <?php
});
