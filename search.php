<?php
// search.php (Semantic Search)
require_once __DIR__ . '/includes/db.php';
$pdo = db();
$apiKey = env_value('OPENAI_API_KEY');

$query = $_GET['q'] ?? '';
$results = [];
$error = null;

if ($query && $apiKey) {
    try {
        // 1. Generate Embedding for Query
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        $data = [
            'input' => str_replace("\n", " ", $query),
            'model' => 'text-embedding-3-small'
        ];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $queryVector = $response['data'][0]['embedding'] ?? null;

        if ($queryVector) {
            // 2. Stream embeddings in batches to avoid loading everything into memory
            $totalRows = (int)$pdo->query("SELECT COUNT(*) FROM embeddings")->fetchColumn();
            $batchSize = 500;
            $ranked = [];
            $vectorLen = count($queryVector);

            for ($batchOffset = 0; $batchOffset < $totalRows; $batchOffset += $batchSize) {
                $stmt = $pdo->prepare("SELECT id, document_id, flight_id, content_text, embedding_vector FROM embeddings LIMIT :lim OFFSET :off");
                $stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
                $stmt->bindValue(':off', $batchOffset, PDO::PARAM_INT);
                $stmt->execute();

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $dbVector = json_decode($row['embedding_vector'], true);
                    if (!$dbVector) continue;

                    $score = 0;
                    for ($i = 0; $i < $vectorLen; $i++) {
                        $score += $queryVector[$i] * $dbVector[$i];
                    }

                    if ($score < 0.25) continue; // Skip low relevance early

                    // Keep only top 20 using a min-heap approach
                    if (count($ranked) < 20) {
                        $row['score'] = $score;
                        unset($row['embedding_vector']); // Free memory
                        $ranked[] = $row;
                    } elseif ($score > $ranked[array_key_last($ranked)]['score']) {
                        $row['score'] = $score;
                        unset($row['embedding_vector']);
                        $ranked[] = $row;
                        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
                        $ranked = array_slice($ranked, 0, 20);
                    }
                }
            }

            usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);

            // 3. Hydrate top results with document/flight data
            $docIds = array_filter(array_column($ranked, 'document_id'));
            $flightIds = array_filter(array_column($ranked, 'flight_id'));
            $docMap = [];
            $flightMap = [];

            if ($docIds) {
                $placeholders = implode(',', array_fill(0, count($docIds), '?'));
                $stmt = $pdo->prepare("SELECT * FROM documents WHERE id IN ($placeholders)");
                $stmt->execute(array_values($docIds));
                foreach ($stmt->fetchAll() as $doc) {
                    $docMap[$doc['id']] = $doc;
                }
            }
            if ($flightIds) {
                $placeholders = implode(',', array_fill(0, count($flightIds), '?'));
                $stmt = $pdo->prepare("SELECT * FROM flight_logs WHERE id IN ($placeholders)");
                $stmt->execute(array_values($flightIds));
                foreach ($stmt->fetchAll() as $flight) {
                    $flightMap[$flight['id']] = $flight;
                }
            }

            foreach ($ranked as $hit) {
                if ($hit['document_id'] && isset($docMap[$hit['document_id']])) {
                    $doc = $docMap[$hit['document_id']];
                    $doc['type'] = 'document';
                    $doc['search_score'] = $hit['score'];
                    $doc['snippet'] = $hit['content_text'];
                    $results[] = $doc;
                } elseif ($hit['flight_id'] && isset($flightMap[$hit['flight_id']])) {
                    $flight = $flightMap[$hit['flight_id']];
                    $flight['type'] = 'flight';
                    $flight['search_score'] = $hit['score'];
                    $flight['snippet'] = $hit['content_text'];
                    $results[] = $flight;
                }
            }
        }
    } catch (Exception $e) {
        $error = "Search failed: " . $e->getMessage();
    }
}

$page_title = 'Semantic Search';
$meta_description = 'AI-powered semantic search across the Epstein document archive using OpenAI vector embeddings. Find conceptually similar documents beyond keyword matching.';
$noindex = true;
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex-1 overflow-y-auto bg-gray-50">
    <!-- Hero / Search Bar -->
    <div class="bg-white border-b border-gray-200">
        <div class="max-w-4xl mx-auto px-4 py-12 text-center">
            <h1 class="text-3xl font-light text-slate-800 mb-2">Ask the Files</h1>
            <p class="text-slate-500 mb-8 max-w-lg mx-auto">Semantic search understands the <em>meaning</em> of your
                question, connecting you to flights and documents even if keywords don't match exactly.</p>

            <form action="" method="GET" class="relative max-w-2xl mx-auto">
                <input type="text" name="q" value="<?= htmlspecialchars($query) ?>"
                    placeholder="e.g. 'Show me flights to islands involving politicians' or 'scientific funding documents'"
                    class="w-full pl-6 pr-12 py-4 rounded-full border border-gray-300 shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-lg outline-none transition-shadow hover:shadow-md">
                <button type="submit"
                    class="absolute right-3 top-3 bg-slate-900 text-white p-2 rounded-full hover:bg-black transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <!-- Results Area -->
    <div class="max-w-4xl mx-auto px-4 py-8">
        <?php if ($query && empty($results)): ?>
            <div class="text-center py-12 text-gray-400">
                <?php if ($error): ?>
                    <p class="text-red-500"><?= htmlspecialchars($error) ?></p>
                <?php else: ?>
                    <p>No sufficiently relevant results found via semantic search.</p>
                <?php endif; ?>
            </div>
        <?php elseif (!empty($results)): ?>
            <div class="space-y-6">
                <?php foreach ($results as $item): ?>
                    <?php if ($item['type'] === 'document'): ?>
                        <!-- Document Card -->
                        <div
                            class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-lg transition-all group relative overflow-hidden">
                            <div class="absolute top-0 right-0 p-2 opacity-50">
                                <span
                                    class="bg-indigo-50 text-indigo-700 text-[10px] font-bold px-2 py-0.5 rounded uppercase tracking-wide">Document</span>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-shrink-0 text-red-500 pt-1">
                                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-medium text-blue-600 group-hover:underline mb-1">
                                        <a
                                            href="/document.php?id=<?= $item['id'] ?>"><?= htmlspecialchars($item['title'] ?: 'Untitled Document') ?></a>
                                    </h3>
                                    <div class="text-sm text-gray-600 line-clamp-2 mb-2 italic">
                                        "<?= htmlspecialchars($item['ai_summary'] ?? 'No summary available.') ?>"
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-gray-400">
                                        <span>Relevance: <?= round($item['search_score'] * 100) ?>%</span>
                                        <span>ID: <?= $item['id'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Flight Card -->
                        <div
                            class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-lg transition-all group relative overflow-hidden">
                            <div class="absolute top-0 right-0 p-2 opacity-50">
                                <span
                                    class="bg-blue-50 text-blue-700 text-[10px] font-bold px-2 py-0.5 rounded uppercase tracking-wide">Flight
                                    Log</span>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-shrink-0 text-blue-500 pt-1">
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                </div>
                                <div class="w-full">
                                    <h3 class="text-lg font-medium text-slate-800 mb-1">
                                        <?= htmlspecialchars($item['origin']) ?> &rarr;
                                        <?= htmlspecialchars($item['destination']) ?>
                                    </h3>
                                    <div class="text-sm font-medium text-slate-500 mb-2">
                                        <?= date('F j, Y', strtotime($item['flight_date'])) ?> | Aircraft:
                                        <?= htmlspecialchars($item['aircraft']) ?>
                                    </div>
                                    <div class="text-sm text-gray-600 bg-slate-50 border border-slate-100 p-2 rounded mb-2 italic">
                                        <?= htmlspecialchars($item['ai_summary']) ?>
                                    </div>
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="text-gray-400">Relevance: <?= round($item['search_score'] * 100) ?>%</span>
                                        <a href="/flight_logs.php" class="text-blue-600 hover:underline">View on Map &rarr;</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Empty State / Suggestions -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-8">
                <a href="?q=documents%20about%20scientific%20funding"
                    class="bg-white p-4 rounded-lg border border-gray-100 hover:border-blue-300 hover:shadow-md transition-all text-center group">
                    <span class="block text-xl mb-2">🔬</span>
                    <span class="font-medium text-slate-700 group-hover:text-blue-600">"Scientific Funding"</span>
                </a>
                <a href="?q=flights%20to%20private%20islands"
                    class="bg-white p-4 rounded-lg border border-gray-100 hover:border-blue-300 hover:shadow-md transition-all text-center group">
                    <span class="block text-xl mb-2">🏝️</span>
                    <span class="font-medium text-slate-700 group-hover:text-blue-600">"Private Island Flights"</span>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>