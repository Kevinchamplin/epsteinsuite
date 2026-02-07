<?php
$page_title = 'Data Sources - Epstein Suite';
$meta_description = 'Official sources for the Epstein Suite document archive including DOJ, FBI Vault, and House Oversight Committee releases.';
require_once __DIR__ . '/includes/header_suite.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

// Get document counts by data set — cached 10 minutes
$pdo = db();

$sourceData = Cache::remember('sources_page_stats', function() use ($pdo) {
    $datasetStatsSql = "
        SELECT
            CASE
                WHEN data_set IS NULL OR data_set = '' THEN 'Uncategorized'
                ELSE data_set
            END AS data_set,
            COUNT(*) AS total_docs,
            SUM(CASE WHEN local_path IS NOT NULL AND local_path != '' THEN 1 ELSE 0 END) AS local_docs,
            SUM(CASE WHEN local_path IS NULL OR local_path = '' THEN 1 ELSE 0 END) AS remote_only_docs,
            SUM(CASE WHEN ai_summary IS NOT NULL AND ai_summary != '' THEN 1 ELSE 0 END) AS ai_docs,
            SUM(CASE WHEN ocr.document_id IS NOT NULL THEN 1 ELSE 0 END) AS ocr_docs
        FROM documents d
        LEFT JOIN (
            SELECT DISTINCT document_id
            FROM pages
            WHERE ocr_text IS NOT NULL AND ocr_text != ''
        ) ocr ON ocr.document_id = d.id
        GROUP BY data_set
        ORDER BY total_docs DESC
    ";
    $datasetStats = $pdo->query($datasetStatsSql)->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['docs' => 0, 'local' => 0, 'remote' => 0, 'ocr' => 0, 'ai' => 0];
    foreach ($datasetStats as $row) {
        $totals['docs'] += (int)$row['total_docs'];
        $totals['local'] += (int)$row['local_docs'];
        $totals['remote'] += (int)$row['remote_only_docs'];
        $totals['ocr'] += (int)$row['ocr_docs'];
        $totals['ai'] += (int)$row['ai_docs'];
    }

    $lastIngestion = $pdo->query("SELECT MAX(created_at) FROM documents")->fetchColumn();

    return [
        'datasetStats' => $datasetStats,
        'totals' => $totals,
        'lastIngestion' => $lastIngestion,
    ];
}, 600);

$datasetStats = $sourceData['datasetStats'];
$totals = $sourceData['totals'];
$lastIngestion = $sourceData['lastIngestion'];
?>

<main class="flex-1 w-full max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-slate-900 mb-2">Data Sources</h1>
        <p class="text-slate-600">All documents in the Epstein Suite are sourced from official government releases and public records.</p>
    </div>

    <!-- Status Warning -->
    <div class="bg-orange-50 border border-orange-300 rounded-xl p-4 mb-8 flex items-start gap-3">
        <span class="text-orange-500 text-xl mt-0.5">⚠️</span>
        <div>
            <h3 class="font-semibold text-orange-800 text-sm">DOJ Portal Notice (Updated Feb 2026)</h3>
            <p class="text-sm text-orange-700 mt-1">
                The DOJ has removed several thousand documents from the portal after flawed redactions inadvertently exposed victim-identifying information.
                Attorneys representing over 200 alleged victims have asked federal courts to order further takedowns.
                Some files may be temporarily unavailable while the DOJ reviews and re-redacts affected documents.
                The portal also uses bot-detection security checks that may delay or block access.
                If the DOJ portal is unavailable, try the <a href="#alt-databases" class="font-medium underline">alternative searchable databases</a> below.
            </p>
        </div>
    </div>

    <?php if ($lastIngestion): ?>
    <!-- Last Ingestion -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-8 flex items-center gap-3">
        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <div>
            <p class="text-sm font-semibold text-blue-800">Last Data Ingestion</p>
            <p class="text-sm text-blue-700"><?= date('F j, Y \a\t g:ia T', strtotime($lastIngestion)) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats Overview -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-10">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($totals['docs']) ?></div>
            <div class="text-sm text-slate-500">Total Documents</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-3xl font-bold text-emerald-600"><?= number_format($totals['local']) ?></div>
            <div class="text-sm text-slate-500 flex items-center gap-1">
                Local Copies
                <span class="text-xs text-slate-400">(<?= $totals['docs'] ? round(($totals['local'] / max(1, $totals['docs'])) * 100) : 0 ?>%)</span>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-3xl font-bold text-rose-600"><?= number_format($totals['remote']) ?></div>
            <div class="text-sm text-slate-500">Still Remote-only</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-3xl font-bold text-green-600"><?= number_format($totals['ocr']) ?></div>
            <div class="text-sm text-slate-500">OCR Complete</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($totals['ai']) ?></div>
            <div class="text-sm text-slate-500">AI Summaries</div>
        </div>
    </div>

    <!-- Coverage Dashboard -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-10">
        <div class="flex items-center justify-between mb-6 flex-wrap gap-4">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Ingestion Health (Production)</h2>
                <p class="text-sm text-slate-500 mt-1">Live counts pulled from the production database.</p>
            </div>
            <div class="text-sm text-slate-500 flex items-center gap-2">
                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-emerald-100 text-emerald-700">Local</span>
                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-rose-100 text-rose-700">Remote-only</span>
                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700">OCR</span>
                <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-700">AI</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-500">
                        <th class="text-left py-3 px-3">Data Set</th>
                        <th class="text-right py-3 px-3">Total</th>
                        <th class="text-right py-3 px-3">Local</th>
                        <th class="text-right py-3 px-3">Remote</th>
                        <th class="text-right py-3 px-3">OCR</th>
                        <th class="text-right py-3 px-3">AI</th>
                        <th class="text-left py-3 px-3 w-48">Progress</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($datasetStats as $row): 
                        $total = (int)$row['total_docs'];
                        $localPct = $total ? round(($row['local_docs'] / $total) * 100) : 0;
                        $ocrPct = $total ? round(($row['ocr_docs'] / $total) * 100) : 0;
                        $aiPct = $total ? round(($row['ai_docs'] / $total) * 100) : 0;
                    ?>
                    <tr class="hover:bg-slate-50/70">
                        <td class="py-3 px-3 font-medium text-slate-800"><?= htmlspecialchars($row['data_set']) ?></td>
                        <td class="py-3 px-3 text-right text-slate-700 font-semibold"><?= number_format($total) ?></td>
                        <td class="py-3 px-3 text-right text-emerald-600 font-semibold"><?= number_format($row['local_docs']) ?></td>
                        <td class="py-3 px-3 text-right text-rose-600 font-semibold"><?= number_format($row['remote_only_docs']) ?></td>
                        <td class="py-3 px-3 text-right text-indigo-600"><?= number_format($row['ocr_docs']) ?></td>
                        <td class="py-3 px-3 text-right text-purple-600"><?= number_format($row['ai_docs']) ?></td>
                        <td class="py-3 px-3">
                            <div class="space-y-1">
                                <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-600" style="width: <?= $localPct ?>%;"></div>
                                </div>
                                <div class="flex justify-between text-[11px] text-slate-500">
                                    <span>Local <?= $localPct ?>%</span>
                                    <span>OCR <?= $ocrPct ?>% · AI <?= $aiPct ?>%</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="bg-slate-50 font-semibold">
                        <td class="py-3 px-3 text-slate-800">Total</td>
                        <td class="py-3 px-3 text-right text-slate-800"><?= number_format($totals['docs']) ?></td>
                        <td class="py-3 px-3 text-right text-emerald-700"><?= number_format($totals['local']) ?></td>
                        <td class="py-3 px-3 text-right text-rose-700"><?= number_format($totals['remote']) ?></td>
                        <td class="py-3 px-3 text-right text-indigo-700"><?= number_format($totals['ocr']) ?></td>
                        <td class="py-3 px-3 text-right text-purple-700"><?= number_format($totals['ai']) ?></td>
                        <td class="py-3 px-3 text-[11px] text-slate-500">Updated live from production DB</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Official Sources -->
    <div class="space-y-8">
        
        <!-- DOJ -->
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <span>🏛️</span> U.S. Department of Justice
                </h2>
                <p class="text-blue-100 text-sm mt-1">Official Epstein Files Transparency Act releases — ~3.5 million pages, 2,000+ videos, 180,000+ images released January 30, 2026</p>
            </div>
            <div class="p-6">
                <div class="mb-4 space-y-2">
                    <div>
                        <a href="https://www.justice.gov/epstein" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                            justice.gov/epstein →
                        </a>
                        <span class="text-sm text-slate-500 ml-2">Central hub for all EFTA releases</span>
                    </div>
                    <div>
                        <a href="https://www.justice.gov/epstein/doj-disclosures" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                            DOJ Disclosure Database →
                        </a>
                        <span class="text-sm text-slate-500 ml-2">Searchable database with Data Sets 1-12</span>
                    </div>
                    <div>
                        <a href="https://www.justice.gov/epstein/press-releases" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                            Press Release & Letter to Congress →
                        </a>
                        <span class="text-sm text-slate-500 ml-2">Context on collection sources (FBI, NY/FL cases)</span>
                    </div>
                </div>

                <h3 class="font-semibold text-slate-800 mb-3">Data Sets 1-12 (EFTA H.R.4405)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                    <div class="border border-slate-200 rounded-lg p-3">
                        <div class="font-medium text-slate-800">Data Set <?= $i ?></div>
                        <div class="flex gap-2 mt-2 text-sm">
                            <a href="https://www.justice.gov/epstein/doj-disclosures/data-set-<?= $i ?>-files" target="_blank" rel="noopener" class="text-blue-600 hover:underline">View</a>
                            <span class="text-slate-300">|</span>
                            <a href="https://www.justice.gov/epstein/files/DataSet%20<?= $i ?>.zip" target="_blank" rel="noopener" class="text-blue-600 hover:underline">ZIP</a>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <h3 class="font-semibold text-slate-800 mb-3">First Phase Declassified Files</h3>
                <div class="space-y-2 mb-6">
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/First%20Phase%20of%20Declassified%20Epstein%20Files/A.%20Evidence%20List%20from%20US%20v.%20Maxwell,%201.20-cr-00330%20(SDNY%202020).pdf" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        📄 A. Evidence List from U.S. v. Maxwell
                    </a>
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/First%20Phase%20of%20Declassified%20Epstein%20Files/B.%20Flight%20Log%20Released%20in%20US%20v.%20Maxwell,%201.20-cr-00330%20(SDNY%202020).pdf" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        📄 B. Flight Log from U.S. v. Maxwell
                    </a>
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/First%20Phase%20of%20Declassified%20Epstein%20Files/C.%20Contact%20Book%20(Redacted).pdf" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        📄 C. Contact Book (Redacted)
                    </a>
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/First%20Phase%20of%20Declassified%20Epstein%20Files/D.%20Masseuse%20List%20(Redacted).pdf" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        📄 D. Masseuse List (Redacted)
                    </a>
                </div>

                <h3 class="font-semibold text-slate-800 mb-3">BOP Video Footage</h3>
                <div class="space-y-2">
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/BOP%20Video%20Footage/2025.07%20DOJ%20FBI%20Memorandum.pdf" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        📄 DOJ/FBI Memorandum
                    </a>
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/BOP%20Video%20Footage/video1.mp4" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        🎬 Video 1 (raw video)
                    </a>
                    <a href="https://www.justice.gov/multimedia/DOJ%20Disclosures/BOP%20Video%20Footage/video2.mp4" target="_blank" rel="noopener" class="block text-sm text-blue-600 hover:underline">
                        🎬 Video 2 (enhanced video)
                    </a>
                </div>
            </div>
        </div>

        <!-- FBI Vault -->
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="bg-gradient-to-r from-slate-700 to-slate-800 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <span>🔍</span> FBI Records: The Vault
                </h2>
                <p class="text-slate-300 text-sm mt-1">FOIA releases from the Federal Bureau of Investigation</p>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <a href="https://vault.fbi.gov/jeffrey-epstein" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                        vault.fbi.gov/jeffrey-epstein →
                    </a>
                </div>
                
                <h3 class="font-semibold text-slate-800 mb-3">Jeffrey Epstein Files (8 Parts)</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php for ($i = 1; $i <= 8; $i++): 
                        $partNum = str_pad($i, 2, '0', STR_PAD_LEFT);
                    ?>
                    <a href="https://archive.org/download/jeffrey-epstein-FBI-vault-files/Jeffrey%20Epstein%20Part%20<?= $partNum ?>%20of%2008.pdf" 
                       target="_blank" rel="noopener" 
                       class="border border-slate-200 rounded-lg p-3 hover:border-blue-300 hover:bg-blue-50 transition-colors">
                        <div class="font-medium text-slate-800">Part <?= $partNum ?> of 08</div>
                        <div class="text-xs text-slate-500 mt-1">PDF via Archive.org</div>
                    </a>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- House Oversight -->
        <div id="house-oversight" class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="bg-gradient-to-r from-red-600 to-red-700 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <span>🏠</span> House Committee on Oversight
                </h2>
                <p class="text-red-100 text-sm mt-1">Epstein Estate documents released by Congress</p>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <a href="https://oversight.house.gov/release/oversight-committee-releases-additional-epstein-estate-documents/" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                        oversight.house.gov →
                    </a>
                </div>
                <p class="text-slate-600 text-sm">
                    The House Committee on Oversight and Government Reform released over 20,000 pages of documents 
                    received from the estate of Jeffrey Epstein, including images, text files, and native documents.
                </p>
            </div>
        </div>

        <!-- Court Records -->
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="bg-gradient-to-r from-amber-500 to-amber-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <span>⚖️</span> Court Records
                </h2>
                <p class="text-amber-100 text-sm mt-1">Federal court filings and case documents</p>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <a href="https://www.justice.gov/epstein/court-records" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                        DOJ Court Records →
                    </a>
                </div>
                <p class="text-slate-600 text-sm">
                    Includes indictments, motions, judgments, and exhibits from cases including 
                    <em>U.S. v. Maxwell</em> (S.D.N.Y. 2020) and related proceedings.
                </p>
            </div>
        </div>

        <!-- Alternative Searchable Databases -->
        <div id="alt-databases" class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
            <div class="bg-gradient-to-r from-cyan-600 to-teal-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <span>🔎</span> Alternative Searchable Databases
                </h2>
                <p class="text-cyan-100 text-sm mt-1">Third-party tools with better search and uptime than the official DOJ portal</p>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <a href="https://journaliststudio.google.com/pinpoint/collections" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                        Google Journalist Studio (Pinpoint) →
                    </a>
                    <p class="text-slate-600 text-sm mt-1">
                        A highly popular searchable database that has archived the DOJ releases. Allows searching by specific names
                        across thousands of documents simultaneously. Useful when the official DOJ site is experiencing downtime.
                    </p>
                </div>
                <div class="border-t border-slate-100 pt-4">
                    <a href="https://oversight.house.gov/release/oversight-committee-releases-additional-epstein-estate-documents/" target="_blank" rel="noopener" class="text-blue-600 hover:underline font-medium">
                        House Oversight Committee Release →
                    </a>
                    <p class="text-slate-600 text-sm mt-1">
                        Contains an additional 20,000+ pages specifically related to the Epstein Estate, released separately from
                        the DOJ investigative files. See the <a href="#house-oversight" class="text-blue-600 hover:underline">House Oversight section above</a> for details.
                    </p>
                </div>
            </div>
        </div>

    </div>

    <!-- Disclaimer -->
    <div class="mt-10 bg-amber-50 border border-amber-200 rounded-xl p-6">
        <h3 class="font-semibold text-amber-800 mb-2">⚠️ Important Notice</h3>
        <ul class="text-sm text-amber-700 space-y-2">
            <li>• All content is indexed from <strong>public government sources</strong>. Verify claims against the linked originals.</li>
            <li>• <strong>DOJ Disclaimer:</strong> These files include unvetted tips and public submissions to the FBI. The presence of a name in these files does <strong>not</strong> necessarily indicate a personal association with Jeffrey Epstein or any criminal wrongdoing.</li>
            <li>• AI summaries are provided as navigation aids and may contain inaccuracies.</li>
            <li>• Being named in these documents is <strong>not an indication of wrongdoing</strong>.</li>
            <li>• Do not attempt to identify victims/minors or reverse redactions.</li>
        </ul>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
