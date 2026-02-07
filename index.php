<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/donate.php';
require_once __DIR__ . '/includes/ai_helpers.php';

$searchQuery = $_GET['q'] ?? '';
$pdo = db();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$semanticApiKey = env_value('OPENAI_API_KEY');
$semanticResults = [];
$semanticError = null;
$offset = ($page - 1) * $perPage;

function buildSearchUrl(array $overrides = []): string
{
    $params = $_GET;
    if (!isset($params['q'])) {
        $params['q'] = $GLOBALS['searchQuery'];
    }
    $params = array_merge($params, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }
    return '/?' . http_build_query($params);
}  

function resolveMediaUrl(?string $localPath, ?string $sourceUrl): ?string
{
    $url = $localPath ?: $sourceUrl;
    if (!$url) {
        return null;
    }
    // For local paths, encode spaces/unsafe chars per segment and prepend slash
    if (!preg_match('/^https?:\\/\\//i', $url)) {
        $segments = array_map('rawurlencode', array_filter(explode('/', ltrim($url, '/')), fn($s) => $s !== ''));
        $url = '/' . implode('/', $segments);
        return $url;
    }
    if (!preg_match('/^https?:\\/\\//i', $url)) {
        $url = '/' . ltrim($url, '/');
    }
    return $url;
}
$results = [];
$emailResults = [];
$flightResults = [];
$photoResults = [];
$entityResults = [];
$entityDocResults = [];
$newsResults = [];
$docTotalCount = 0;
$emailTotalCount = 0;
$flightTotalCount = 0;
$photoTotalCount = 0;
$entityTotalCount = 0;
$newsTotalCount = 0;
$error = null;

if ($searchQuery) {
    // Check search cache first (2-minute TTL per query+page)
    $searchCacheKey = 'search_' . md5(strtolower(trim($searchQuery)) . '_p' . $page);
    $cachedSearch = Cache::get($searchCacheKey);
    if ($cachedSearch && is_array($cachedSearch)) {
        $results = $cachedSearch['results'] ?? [];
        $docTotalCount = $cachedSearch['docTotalCount'] ?? 0;
        $emailResults = $cachedSearch['emailResults'] ?? [];
        $emailTotalCount = $cachedSearch['emailTotalCount'] ?? 0;
        $flightResults = $cachedSearch['flightResults'] ?? [];
        $flightTotalCount = $cachedSearch['flightTotalCount'] ?? 0;
        $photoResults = $cachedSearch['photoResults'] ?? [];
        $photoTotalCount = $cachedSearch['photoTotalCount'] ?? 0;
        $entityResults = $cachedSearch['entityResults'] ?? [];
        $entityTotalCount = $cachedSearch['entityTotalCount'] ?? 0;
        $entityDocResults = $cachedSearch['entityDocResults'] ?? [];
        $newsResults = $cachedSearch['newsResults'] ?? [];
        $newsTotalCount = $cachedSearch['newsTotalCount'] ?? 0;
        goto searchDone;
    }

    try {
        $normalizedQuery = strtolower(trim($searchQuery));
        $normalizedQuery = ltrim($normalizedQuery, ".");
        $extWhitelist = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'doc', 'docx', 'xls', 'xlsx'];
        $fileTypeQuery = in_array($normalizedQuery, $extWhitelist, true) ? $normalizedQuery : '__none__';
        $canUseFulltext = (mb_strlen(trim($searchQuery)) >= 3);
        $like = '%' . $searchQuery . '%';

        if ($canUseFulltext) {
            // Step 1: Find document IDs matching via OCR text (FULLTEXT index, fast)
            $ocrDocIds = [];
            try {
                $ocrStmt = $pdo->prepare("
                    SELECT DISTINCT document_id
                    FROM pages
                    WHERE MATCH(ocr_text) AGAINST(:q IN NATURAL LANGUAGE MODE)
                    LIMIT 1000
                ");
                $ocrStmt->execute([':q' => $searchQuery]);
                $ocrDocIds = $ocrStmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (\Exception $e) {
                // FULLTEXT on pages not available, skip OCR search
            }

            // Build IN clause for OCR document IDs (safe: integer IDs from our DB)
            $ocrInClause = '';
            $ocrIdList = '0'; // default: no match
            if (!empty($ocrDocIds)) {
                $ocrIdList = implode(',', array_map('intval', $ocrDocIds));
                $ocrInClause = "OR d.id IN ({$ocrIdList})";
            }

            // Step 2: Main document search using FULLTEXT index
            $stmt = $pdo->prepare("
                SELECT d.id, d.title, d.description, d.source_url, d.local_path, d.file_type, d.ai_summary, d.data_set,
                       MATCH(d.title, d.description, d.ai_summary) AGAINST(:q_ft IN NATURAL LANGUAGE MODE) as score,
                       IF(d.id IN ({$ocrIdList}), 20, 0) as ocr_score
                FROM documents d
                WHERE MATCH(d.title, d.description, d.ai_summary) AGAINST(:q_ft2 IN NATURAL LANGUAGE MODE)
                   OR d.data_set LIKE :q_ds
                   OR d.file_type = :q_file_type
                   {$ocrInClause}
                ORDER BY (score + ocr_score) DESC, d.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':q_ft', $searchQuery);
            $stmt->bindValue(':q_ft2', $searchQuery);
            $stmt->bindValue(':q_ds', $like);
            $stmt->bindValue(':q_file_type', $fileTypeQuery);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            // Count query using FULLTEXT
            $countDocsStmt = $pdo->prepare("
                SELECT COUNT(*) AS total
                FROM documents d
                WHERE MATCH(d.title, d.description, d.ai_summary) AGAINST(:q_ft IN NATURAL LANGUAGE MODE)
                   OR d.data_set LIKE :q_ds
                   OR d.file_type = :q_file_type
                   {$ocrInClause}
            ");
            $countDocsStmt->bindValue(':q_ft', $searchQuery);
            $countDocsStmt->bindValue(':q_ds', $like);
            $countDocsStmt->bindValue(':q_file_type', $fileTypeQuery);
            $countDocsStmt->execute();
            $docTotalCount = (int)$countDocsStmt->fetchColumn();
        } else {
            // Short query (< 3 chars): LIKE on title only (fast enough for 200K rows, skip OCR)
            $stmt = $pdo->prepare("
                SELECT d.id, d.title, d.description, d.source_url, d.local_path, d.file_type, d.ai_summary, d.data_set,
                       IF(d.title LIKE :q_title, 50, 5) as score,
                       0 as ocr_score
                FROM documents d
                WHERE d.title LIKE :q_title2 OR d.file_type = :q_file_type
                ORDER BY score DESC, d.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':q_title', $like);
            $stmt->bindValue(':q_title2', $like);
            $stmt->bindValue(':q_file_type', $fileTypeQuery);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $results = $stmt->fetchAll();

            $countDocsStmt = $pdo->prepare("
                SELECT COUNT(*) FROM documents WHERE title LIKE :q_title OR file_type = :q_file_type
            ");
            $countDocsStmt->execute([':q_title' => $like, ':q_file_type' => $fileTypeQuery]);
            $docTotalCount = (int)$countDocsStmt->fetchColumn();
        }

        // Emails search (FULLTEXT index: ft_email)
        $stmt = $pdo->prepare("
            SELECT id, document_id, sender, recipient, cc, subject, sent_at,
                   SUBSTRING(body, 1, 200) as body_preview,
                   MATCH(sender, recipient, subject, body) AGAINST (:eq_score IN NATURAL LANGUAGE MODE) as score
            FROM emails
            WHERE MATCH(sender, recipient, subject, body) AGAINST (:eq_where IN NATURAL LANGUAGE MODE)
            ORDER BY score DESC, sent_at DESC
            LIMIT 50
        ");
        $stmt->execute([
            ':eq_score' => $searchQuery,
            ':eq_where' => $searchQuery,
        ]);
        $emailResults = $stmt->fetchAll();

        $emailCountStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM emails
            WHERE MATCH(sender, recipient, subject, body) AGAINST (:eq_where IN NATURAL LANGUAGE MODE)
        ");
        $emailCountStmt->execute([':eq_where' => $searchQuery]);
        $emailTotalCount = (int)$emailCountStmt->fetchColumn();

        // Flight logs search (LIKE across key columns + passengers)
        $stmt = $pdo->prepare("
            SELECT f.*, GROUP_CONCAT(p.name SEPARATOR ', ') as passenger_list
            FROM flight_logs f
            LEFT JOIN passengers p ON f.id = p.flight_id
            WHERE (
                f.origin LIKE :fq_origin
                OR f.destination LIKE :fq_destination
                OR f.aircraft LIKE :fq_aircraft
                OR p.name LIKE :fq_passenger
            )
            GROUP BY f.id
            ORDER BY f.flight_date DESC
            LIMIT 50
        ");
        $like = '%' . $searchQuery . '%';
        $stmt->execute([
            ':fq_origin' => $like,
            ':fq_destination' => $like,
            ':fq_aircraft' => $like,
            ':fq_passenger' => $like,
        ]);
        $flightResults = $stmt->fetchAll();

        $flightCountStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT f.id)
            FROM flight_logs f
            LEFT JOIN passengers p ON f.id = p.flight_id
            WHERE (
                f.origin LIKE :fq_origin
                OR f.destination LIKE :fq_destination
                OR f.aircraft LIKE :fq_aircraft
                OR p.name LIKE :fq_passenger
            )
        ");
        $flightCountStmt->execute([
            ':fq_origin' => $like,
            ':fq_destination' => $like,
            ':fq_aircraft' => $like,
            ':fq_passenger' => $like,
        ]);
        $flightTotalCount = (int)$flightCountStmt->fetchColumn();

        // Photos search — FULLTEXT on documents filtered to image types
        if ($canUseFulltext) {
            $stmt = $pdo->prepare("
                SELECT id, title, description, file_type, local_path, source_url, created_at
                FROM documents
                WHERE MATCH(title, description, ai_summary) AGAINST(:pq IN NATURAL LANGUAGE MODE)
                  AND file_type IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'image')
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([':pq' => $searchQuery]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, title, description, file_type, local_path, source_url, created_at
                FROM documents
                WHERE title LIKE :pq_title
                  AND file_type IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'image')
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([':pq_title' => $like]);
        }
        $photoResults = $stmt->fetchAll();

        if ($canUseFulltext) {
            $photoCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM documents
                WHERE MATCH(title, description, ai_summary) AGAINST(:pq IN NATURAL LANGUAGE MODE)
                  AND file_type IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'image')
            ");
            $photoCountStmt->execute([':pq' => $searchQuery]);
        } else {
            $photoCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM documents
                WHERE title LIKE :pq_title
                  AND file_type IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'image')
            ");
            $photoCountStmt->execute([':pq_title' => $like]);
        }
        $photoTotalCount = (int)$photoCountStmt->fetchColumn();

        // Entity search — JOIN instead of correlated subquery
        $stmt = $pdo->prepare("
            SELECT e.id, e.name, e.type, COUNT(de.document_id) as doc_count
            FROM entities e
            LEFT JOIN document_entities de ON de.entity_id = e.id
            WHERE e.name LIKE :eq_name
            GROUP BY e.id, e.name, e.type
            ORDER BY doc_count DESC
            LIMIT 20
        ");
        $stmt->execute([':eq_name' => '%' . $searchQuery . '%']);
        $entityResults = $stmt->fetchAll();

        $entityCountStmt = $pdo->prepare("SELECT COUNT(*) FROM entities WHERE name LIKE :eq_name");
        $entityCountStmt->execute([':eq_name' => '%' . $searchQuery . '%']);
        $entityTotalCount = (int)$entityCountStmt->fetchColumn();

        // News articles search — use FULLTEXT index ft_news
        if ($canUseFulltext) {
            $stmt = $pdo->prepare("
                SELECT id, title, url, source_name, published_at, ai_summary, ai_headline, shock_score
                FROM news_articles
                WHERE status = 'processed'
                  AND MATCH(title, snippet, ai_summary) AGAINST(:nq IN NATURAL LANGUAGE MODE)
                ORDER BY shock_score DESC, published_at DESC
                LIMIT 20
            ");
            $stmt->execute([':nq' => $searchQuery]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, title, url, source_name, published_at, ai_summary, ai_headline, shock_score
                FROM news_articles
                WHERE status = 'processed' AND title LIKE :nq_title
                ORDER BY shock_score DESC, published_at DESC
                LIMIT 20
            ");
            $stmt->execute([':nq_title' => $like]);
        }
        $newsResults = $stmt->fetchAll();

        if ($canUseFulltext) {
            $newsCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM news_articles
                WHERE status = 'processed'
                  AND MATCH(title, snippet, ai_summary) AGAINST(:nq IN NATURAL LANGUAGE MODE)
            ");
            $newsCountStmt->execute([':nq' => $searchQuery]);
        } else {
            $newsCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM news_articles
                WHERE status = 'processed' AND title LIKE :nq_title
            ");
            $newsCountStmt->execute([':nq_title' => $like]);
        }
        $newsTotalCount = (int)$newsCountStmt->fetchColumn();

        // Documents by entity - if search matches an entity name exactly or closely
        if (!empty($entityResults)) {
            $topEntityIds = array_slice(array_column($entityResults, 'id'), 0, 5);
            if (!empty($topEntityIds)) {
                $placeholders = implode(',', array_fill(0, count($topEntityIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT DISTINCT d.id, d.title, d.file_type, d.ai_summary, d.data_set,
                           COUNT(de.entity_id) as entity_matches
                    FROM documents d
                    JOIN document_entities de ON de.document_id = d.id
                    WHERE de.entity_id IN ($placeholders)
                    GROUP BY d.id
                    ORDER BY entity_matches DESC
                    LIMIT 20
                ");
                $stmt->execute($topEntityIds);
                $entityDocResults = $stmt->fetchAll();
            }
        }

        // Cache search results for 2 minutes
        Cache::set($searchCacheKey, [
            'results' => $results,
            'docTotalCount' => $docTotalCount,
            'emailResults' => $emailResults,
            'emailTotalCount' => $emailTotalCount,
            'flightResults' => $flightResults,
            'flightTotalCount' => $flightTotalCount,
            'photoResults' => $photoResults,
            'photoTotalCount' => $photoTotalCount,
            'entityResults' => $entityResults,
            'entityTotalCount' => $entityTotalCount,
            'entityDocResults' => $entityDocResults,
            'newsResults' => $newsResults,
            'newsTotalCount' => $newsTotalCount,
        ], 120);

        if ($semanticApiKey) {
            try {
                $ch = curl_init('https://api.openai.com/v1/embeddings');
                $payload = [
                    'input' => str_replace("\n", ' ', $searchQuery),
                    'model' => 'text-embedding-3-small',
                ];
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $semanticApiKey,
                    ],
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                ]);
                $response = curl_exec($ch);
                $curlErr = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($response === false) {
                    throw new RuntimeException('Failed to contact OpenAI embeddings API: ' . $curlErr);
                }
                $decoded = json_decode($response, true);
                if (!isset($decoded['data'][0]['embedding'])) {
                    $apiMsg = $decoded['error']['message'] ?? 'Unknown error (HTTP ' . $httpCode . ')';
                    throw new RuntimeException('Embedding API error: ' . $apiMsg);
                }
                $queryVector = $decoded['data'][0]['embedding'];

                // Stream embeddings in batches to avoid loading entire table into memory
                $totalEmbeddings = (int)$pdo->query("SELECT COUNT(*) FROM embeddings")->fetchColumn();
                $batchSize = 500;
                $ranked = [];
                $vectorLen = count($queryVector);
                $searchStart = microtime(true);
                $searchTimeLimit = 8.0; // seconds — abort and return partial results

                for ($batchOffset = 0; $batchOffset < $totalEmbeddings; $batchOffset += $batchSize) {
                    if (microtime(true) - $searchStart > $searchTimeLimit) {
                        break;
                    }
                    $batchStmt = $pdo->prepare("SELECT id, document_id, flight_id, content_text, embedding_vector FROM embeddings LIMIT :lim OFFSET :off");
                    $batchStmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
                    $batchStmt->bindValue(':off', $batchOffset, PDO::PARAM_INT);
                    $batchStmt->execute();

                    while ($row = $batchStmt->fetch(PDO::FETCH_ASSOC)) {
                        $dbVector = json_decode($row['embedding_vector'], true);
                        if (!is_array($dbVector)) {
                            continue;
                        }
                        $score = 0.0;
                        for ($i = 0; $i < $vectorLen; $i++) {
                            $score += $queryVector[$i] * $dbVector[$i];
                        }
                        if ($score < 0.25) {
                            continue;
                        }
                        unset($row['embedding_vector']);
                        $row['score'] = $score;
                        if (count($ranked) < 10) {
                            $ranked[] = $row;
                        } elseif ($score > $ranked[array_key_last($ranked)]['score']) {
                            $ranked[] = $row;
                            usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
                            $ranked = array_slice($ranked, 0, 10);
                        }
                    }
                }

                usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
                $topHits = $ranked;

                foreach ($topHits as $hit) {
                    if ($hit['score'] < 0.25) {
                        continue;
                    }
                    if (!empty($hit['document_id'])) {
                        $docStmt = $pdo->prepare("SELECT id, title, ai_summary, data_set FROM documents WHERE id = ?");
                        $docStmt->execute([$hit['document_id']]);
                        if ($doc = $docStmt->fetch(PDO::FETCH_ASSOC)) {
                            $doc['type'] = 'document';
                            $doc['search_score'] = $hit['score'];
                            $doc['snippet'] = $hit['content_text'];
                            $semanticResults[] = $doc;
                        }
                    } elseif (!empty($hit['flight_id'])) {
                        $flightStmt = $pdo->prepare("SELECT id, origin, destination, flight_date, aircraft, ai_summary FROM flight_logs WHERE id = ?");
                        $flightStmt->execute([$hit['flight_id']]);
                        if ($flight = $flightStmt->fetch(PDO::FETCH_ASSOC)) {
                            $flight['type'] = 'flight';
                            $flight['search_score'] = $hit['score'];
                            $flight['snippet'] = $hit['content_text'];
                            $semanticResults[] = $flight;
                        }
                    }
                }
            } catch (Exception $e) {
                $semanticError = $e->getMessage();
            }
        }
    } catch (\Exception $e) {
        $error = "Unable to perform search at this time. Error: " . $e->getMessage();
    }

    // Log the search query for popularity tracking
    try {
        $normalizedQ = strtolower(trim($searchQuery));
        $ipHash = ai_hash_ip($_SERVER['REMOTE_ADDR'] ?? '');

        // Deduplicate: skip if same IP searched same term in last 5 minutes
        $dedupeStmt = $pdo->prepare("
            SELECT 1 FROM search_logs
            WHERE ip_hash = :ip_hash
              AND query_normalized = :query_normalized
              AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            LIMIT 1
        ");
        $dedupeStmt->execute([
            ':ip_hash' => $ipHash,
            ':query_normalized' => $normalizedQ,
        ]);

        if (!$dedupeStmt->fetchColumn()) {
            $totalResultsForLog = $docTotalCount + $emailTotalCount + $flightTotalCount + $photoTotalCount + $entityTotalCount + $newsTotalCount;
            $logStmt = $pdo->prepare("
                INSERT INTO search_logs (query, query_normalized, result_count, ip_hash, user_agent)
                VALUES (:query, :query_normalized, :result_count, :ip_hash, :user_agent)
            ");
            $logStmt->execute([
                ':query' => mb_substr($searchQuery, 0, 255),
                ':query_normalized' => mb_substr($normalizedQ, 0, 255),
                ':result_count' => $totalResultsForLog,
                ':ip_hash' => $ipHash,
                ':user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        }
    } catch (\Exception $e) {
        // Silently fail -- search logging should never break the search
    }

    searchDone:
}

// Check if any results contain the search query as an exact whole-word match
$hasExactMatch = false;
if ($searchQuery && ($docTotalCount + $emailTotalCount + $flightTotalCount + $photoTotalCount + $entityTotalCount + $newsTotalCount) > 0) {
    $pattern = '/\b' . preg_quote($searchQuery, '/') . '\b/iu';
    foreach ($results as $r) {
        if (preg_match($pattern, $r['title'] ?? '') || preg_match($pattern, $r['ai_summary'] ?? '') || preg_match($pattern, $r['description'] ?? '')) {
            $hasExactMatch = true;
            break;
        }
    }
    if (!$hasExactMatch) {
        foreach ($entityResults as $e) {
            if (preg_match($pattern, $e['name'] ?? '')) { $hasExactMatch = true; break; }
        }
    }
    if (!$hasExactMatch) {
        foreach ($emailResults as $e) {
            if (preg_match($pattern, $e['subject'] ?? '') || preg_match($pattern, $e['sender'] ?? '') || preg_match($pattern, $e['recipient'] ?? '')) { $hasExactMatch = true; break; }
        }
    }
    if (!$hasExactMatch) {
        foreach ($flightResults as $f) {
            if (preg_match($pattern, $f['passenger_list'] ?? '') || preg_match($pattern, $f['origin'] ?? '') || preg_match($pattern, $f['destination'] ?? '')) { $hasExactMatch = true; break; }
        }
    }
}

// Fetch homepage insights when no search query
if (!$searchQuery) {
    try {
        $pdo = db();
        
        // Homepage stats - cached for 10 minutes
        $homeStats = Cache::remember('homepage_stats', function() use ($pdo) {
            $data = [
                'docStats' => ['total' => 0, 'processed' => 0],
                'entityCount' => 0,
                'emailCount' => 0,
                'flightCount' => 0,
                'photoCount' => 0,
                'videoCount' => 0,
                'pdfCount' => 0,
                'topEntities' => [],
                'recentDocs' => [],
                'dataSets' => [],
                'recentNews' => [],
            ];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM documents");
            $data['docStats']['total'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $stmt = $pdo->query("SELECT SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed FROM documents");
            $data['docStats']['processed'] = $stmt->fetch(PDO::FETCH_ASSOC)['processed'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM entities");
            $data['entityCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM emails");
            $data['emailCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM flight_logs");
            $data['flightCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

            $photoWhere = "(LOWER(file_type) IN ('jpg','jpeg','png','gif','webp','tif','tiff') 
                OR source_url REGEXP '\\\\.(jpg|jpeg|png|gif|webp|tif|tiff)(\\\\?|$)')";
            $videoWhere = "(LOWER(file_type) IN ('video','mp4','webm','mov') 
                OR source_url REGEXP '\\\\.(mp4|webm|mov)(\\\\?|$)')";
            $pdfWhere = "(LOWER(file_type) = 'pdf' 
                OR source_url REGEXP '\\\\.(pdf)(\\\\?|$)')";

            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM documents WHERE $photoWhere");
            $data['photoCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM documents WHERE $videoWhere");
            $data['videoCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM documents WHERE $pdfWhere");
            $data['pdfCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // Top entities
            $stmt = $pdo->query("SELECT e.name AS entity_name,
                                        e.type AS entity_type,
                                        SUM(de.frequency) AS mention_count
                                 FROM document_entities de
                                 JOIN entities e ON e.id = de.entity_id
                                 GROUP BY e.id, e.name, e.type
                                 ORDER BY mention_count DESC
                                 LIMIT 8");
            $data['topEntities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Recent documents
            $stmt = $pdo->query("SELECT id, title, file_type, created_at,
                                        SUBSTRING(ai_summary, 1, 150) as ai_summary
                                 FROM documents 
                                 WHERE status = 'processed'
                                 ORDER BY created_at DESC 
                                 LIMIT 6");
            $data['recentDocs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Data set distribution
            $stmt = $pdo->query("SELECT data_set, COUNT(*) as count 
                                 FROM documents 
                                 WHERE data_set IS NOT NULL 
                                 GROUP BY data_set 
                                 ORDER BY CAST(REGEXP_SUBSTR(data_set, '[0-9]+') AS UNSIGNED), data_set
                                 LIMIT 20");
            $data['dataSets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recent news articles
            $stmt = $pdo->query("SELECT id, title, url, source_name, published_at,
                                        ai_headline, ai_summary, shock_score
                                 FROM news_articles
                                 WHERE status = 'processed'
                                 ORDER BY published_at DESC
                                 LIMIT 6");
            $data['recentNews'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Trending entities from news (extracted from entities_mentioned JSON)
            $data['trendingEntities'] = [];
            try {
                $newsEntities = $pdo->query("
                    SELECT entities_mentioned, shock_score
                    FROM news_articles
                    WHERE status = 'processed'
                      AND entities_mentioned IS NOT NULL
                      AND entities_mentioned != '[]'
                      AND published_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ")->fetchAll(PDO::FETCH_ASSOC);

                $entityAgg = [];
                foreach ($newsEntities as $row) {
                    $entities = json_decode($row['entities_mentioned'], true);
                    if (!is_array($entities)) continue;
                    $weight = max(1, ((int)($row['shock_score'] ?? 5)) - 2);
                    foreach ($entities as $name) {
                        $name = trim($name);
                        if (strlen($name) < 3) continue;
                        $key = strtolower($name);
                        if (!isset($entityAgg[$key])) {
                            $entityAgg[$key] = ['name' => $name, 'mentions' => 0, 'weighted' => 0];
                        }
                        $entityAgg[$key]['mentions']++;
                        $entityAgg[$key]['weighted'] += $weight;
                    }
                }
                uasort($entityAgg, fn($a, $b) => $b['weighted'] <=> $a['weighted']);
                $data['trendingEntities'] = array_values(array_slice($entityAgg, 0, 12));
            } catch (\Exception $e) {
                // Non-fatal
            }

            // Top user searches (last 7 days) for trending section
            $data['topSearches'] = [];
            try {
                $data['topSearches'] = $pdo->query("
                    SELECT query_normalized as query, COUNT(*) as cnt
                    FROM search_logs
                    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND result_count > 0
                      AND LENGTH(query_normalized) >= 3
                    GROUP BY query_normalized
                    HAVING cnt >= 3
                    ORDER BY cnt DESC
                    LIMIT 8
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Non-fatal
            }

            // Top photos by view count (last 30 days)
            $data['topPhotos'] = [];
            try {
                $photoWhereCond = "(LOWER(d.file_type) IN ('jpg','jpeg','png','gif','webp','tif','tiff')
                    OR d.source_url REGEXP '\\\\.(jpg|jpeg|png|gif|webp|tif|tiff)(\\\\?|$)')";
                $data['topPhotos'] = $pdo->query("
                    SELECT d.id, d.title, d.local_path, d.source_url, d.data_set,
                           COUNT(pv.id) AS view_count
                    FROM photo_views pv
                    JOIN documents d ON d.id = pv.document_id
                    WHERE pv.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND $photoWhereCond
                    GROUP BY d.id
                    ORDER BY view_count DESC
                    LIMIT 8
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                // Non-fatal — table may not exist yet
            }

            return $data;
        }, 600); // 10 minute cache
        
        // Extract cached data
        $docStats = $homeStats['docStats'];
        $entityCount = $homeStats['entityCount'];
        $emailCount = $homeStats['emailCount'];
        $flightCount = $homeStats['flightCount'];
        $topEntities = $homeStats['topEntities'];
        $recentDocs = $homeStats['recentDocs'];
        $dataSets = $homeStats['dataSets'];
        $recentNews = $homeStats['recentNews'] ?? [];
        $trendingEntities = $homeStats['trendingEntities'] ?? [];
        $topSearches = $homeStats['topSearches'] ?? [];
        $topPhotos = $homeStats['topPhotos'] ?? [];

    } catch (\Exception $e) {
        // Silently fail, homepage will still work with empty stats
        $docStats = ['total' => 0, 'processed' => 0];
        $entityCount = 0;
        $emailCount = 0;
        $flightCount = 0;
        $topEntities = [];
        $recentDocs = [];
        $dataSets = [];
        $recentNews = [];
        $trendingEntities = [];
        $topSearches = [];
        $topPhotos = [];
    }
}

$page_title = 'Epstein Search';
$meta_description = 'Search 4,700+ DOJ Epstein documents, emails, flight logs, and photos. Browse AI-summarized files, view entity networks, and chat with the archive using Ask Epstein AI.';
$og_title = 'Epstein Suite — Search DOJ Epstein Files, Emails & Flight Logs';
$og_description = 'The most comprehensive searchable index of public Epstein-related documents. AI summaries, full-text OCR search, entity extraction, and flight log analysis.';
$lock_body_scroll = false;
$globalSearchQuery = $searchQuery;
if (!empty($searchQuery) && $page > 1) {
    $noindex = true;
}

$homepageFaqs = [
    [
        'question' => 'How do I start using Epstein Suite?',
        'answer' => 'Type any name, place, or phrase into the global search above or jump straight into <a href="/drive.php" class="text-blue-600 hover:underline">Epstein Drive</a> to browse the DOJ releases like a familiar cloud drive.'
    ],
    [
        'question' => 'Can the Ask Epstein AI cite the original PDFs?',
        'answer' => 'Yes. Ask queries the archive, cites document and page numbers, and links directly to the PDF so you can verify every answer.'
    ],
    [
        'question' => 'Where do the documents come from?',
        'answer' => 'Everything is sourced from the DOJ transparency releases, FOIA productions, and public court filings. Each document card lists its original source URL.'
    ],
    [
        'question' => 'Is Epstein Suite free to use?',
        'answer' => 'Yes. The suite is supported by volunteers and donations. You can browse, search, and chat with the archive without creating an account.'
    ],
];

$spotlightStats = [
    [
        'label' => 'Documents',
        'value' => number_format($docStats['total'] ?? 0),
    ],
    [
        'label' => 'Emails',
        'value' => number_format($emailCount ?? 0),
    ],
    [
        'label' => 'Entities',
        'value' => number_format($entityCount ?? 0),
    ],
    [
        'label' => 'Photos',
        'value' => number_format($homeStats['photoCount'] ?? 0),
        'caption' => 'JPG · PNG · GIF',
    ],
    [
        'label' => 'Videos',
        'value' => number_format($homeStats['videoCount'] ?? 0),
        'caption' => 'MP4 · MOV',
    ],
    [
        'label' => 'PDF Evidence',
        'value' => number_format($homeStats['pdfCount'] ?? 0),
        'caption' => 'Scanned exhibits',
    ],
];

$samplePrompts = [
    [
        'question' => 'Who flew to Little St. James on March 12, 2002?',
        'cta' => 'Ask this in AI',
        'prefill' => 'Who flew to Little St. James on March 12, 2002?'
    ],
    [
        'question' => 'Summarize the 2020.08.03 Order #1 from V.I. Superior Court.',
        'cta' => 'Preview summary',
        'prefill' => 'Summarize the 2020.08.03 Order #1 from V.I. Superior Court.'
    ],
    [
        'question' => 'Which emails mention Sheryl Williams between 2004-2006?',
        'cta' => 'Search the Mail set',
        'prefill' => 'List emails mentioning Sheryl Williams between 2004 and 2006.'
    ],
    [
        'question' => 'Find entities connected to Global Jet in the DOJ dump.',
        'cta' => 'Explore entities',
        'prefill' => 'Find entities connected to Global Jet in the DOJ dump.'
    ],
    [
        'question' => 'Which depositions mention Prince Andrew between 2015 and 2019?',
        'cta' => 'Search depositions',
        'prefill' => 'Which depositions mention Prince Andrew between 2015 and 2019?'
    ],
    [
        'question' => 'Summarize the most recent filings in U.S. Virgin Islands v. JPMorgan Chase.',
        'cta' => 'Preview filings',
        'prefill' => 'Summarize the most recent filings in U.S. Virgin Islands v. JPMorgan Chase.'
    ],
    [
        'question' => 'List DOJ or FOIA documents referencing the “Madam 13” nonprofit tied to Maxwell.',
        'cta' => 'Scan FOIA releases',
        'prefill' => 'List DOJ or FOIA documents referencing the “Madam 13” nonprofit tied to Maxwell.'
    ],
    [
        'question' => 'Show a timeline of Epstein aircraft tail numbers (N212JE, N120JE, etc.) with their destinations.',
        'cta' => 'Build tail-number timeline',
        'prefill' => 'Show a timeline of Epstein aircraft tail numbers (N212JE, N120JE, etc.) with their destinations.'
    ],
];

$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(function ($faq) {
        return [
            '@type' => 'Question',
            'name' => strip_tags($faq['question']),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => strip_tags($faq['answer']),
            ],
        ];
    }, $homepageFaqs),
];

$extra_head_tags[] = '<script type="application/ld+json">' . json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';

require_once __DIR__ . '/includes/header_suite.php';
?>

    <main class="flex-grow flex flex-col items-center">

        <?php if (!$searchQuery): ?>
            <!-- Homepage Styles -->
            <style>
                .fade-up { animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) var(--delay, 0s) both; }
                @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
                .qa-scroll::-webkit-scrollbar { display: none; }
                .qa-scroll { -ms-overflow-style: none; scrollbar-width: none; }
                @keyframes subtlePulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 0.7; } }
                .hero-glow { animation: subtlePulse 4s ease-in-out infinite; }
                .search-ring { box-shadow: 0 0 0 1px rgba(99,102,241,0.08), 0 1px 3px rgba(0,0,0,0.04), 0 8px 24px rgba(99,102,241,0.06); }
                .search-ring:focus-within { box-shadow: 0 0 0 3px rgba(99,102,241,0.15), 0 4px 20px rgba(99,102,241,0.12); }
                .quick-card { transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
                .quick-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
            </style>

            <!-- HERO -->
            <div class="relative w-full overflow-hidden">
                <!-- Subtle radial glow behind hero -->
                <div class="hero-glow absolute top-0 left-1/2 -translate-x-1/2 w-[500px] h-[300px] bg-gradient-to-b from-blue-400/20 via-purple-300/10 to-transparent rounded-full blur-3xl pointer-events-none"></div>

                <div class="relative w-full max-w-3xl mx-auto px-4 pt-5 pb-1 text-center fade-up" style="--delay:0s">
                    <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight mb-1.5">
                        <span class="bg-clip-text text-transparent bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600">EpsteinSuite</span>
                    </h1>
                    <p class="text-slate-500 text-xs sm:text-sm max-w-lg mx-auto mb-4 leading-relaxed">
                        Search <?= number_format(($docStats['total'] ?? 0) + ($emailCount ?? 0) + ($flightCount ?? 0)) ?>+ documents, emails, and flight logs from DOJ releases &mdash; all in one place.
                    </p>
                    <form action="/" method="GET" class="relative">
                        <div class="relative group search-ring rounded-2xl bg-white transition-all">
                            <div class="absolute inset-y-0 left-0 pl-4 sm:pl-5 flex items-center pointer-events-none">
                                <svg class="h-5 w-5 text-slate-400 group-focus-within:text-indigo-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <input type="text" name="q"
                                class="block w-full pl-12 sm:pl-14 pr-28 sm:pr-36 py-3 bg-transparent border-0 rounded-2xl focus:outline-none text-base sm:text-lg placeholder-slate-400"
                                placeholder="Search documents, people, flights..." autofocus>
                            <button type="submit" class="absolute inset-y-2 right-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-5 sm:px-7 rounded-xl font-bold text-sm shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40 hover:-translate-y-0.5 active:translate-y-0 transition-all">
                                Search
                            </button>
                        </div>
                    </form>

                    <?php
                        $popularSearches = Cache::remember('popular_searches', function() use ($pdo) {
                            try {
                                $sql = "SELECT query_normalized, COUNT(*) as search_count
                                        FROM search_logs
                                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                                          AND result_count > 0
                                          AND LENGTH(query_normalized) >= 3
                                        GROUP BY query_normalized
                                        HAVING search_count >= 2
                                        ORDER BY search_count DESC
                                        LIMIT 8";
                                return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
                            } catch (\Exception $e) {
                                return [];
                            }
                        }, 1800);

                        if (count($popularSearches) < 4) {
                            $entitySuggestions = Cache::remember('search_suggestions_entities', function() use ($pdo) {
                                try {
                                    $sql = "SELECT e.name
                                            FROM entities e
                                            JOIN document_entities de ON de.entity_id = e.id
                                            WHERE e.type IN ('Person', 'PERSON', 'Location', 'LOCATION', 'Organization', 'ORG')
                                            AND LENGTH(e.name) > 3 AND LENGTH(e.name) < 25
                                            AND e.name NOT LIKE '%/%' AND e.name NOT LIKE '%@%'
                                            GROUP BY e.id
                                            HAVING COUNT(de.document_id) >= 5
                                            ORDER BY COUNT(de.document_id) DESC
                                            LIMIT 8";
                                    return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
                                } catch (\Exception $e) {
                                    return [];
                                }
                            }, 3600);
                            $popularSearches = array_merge($popularSearches, $entitySuggestions);
                        }

                        $staticSuggestions = ['flight logs', 'court records', 'FOIA', 'deposition'];
                        $suggestions = array_values(array_unique(array_merge($popularSearches, $staticSuggestions)));
                        $suggestions = array_slice($suggestions, 0, 10);
                    ?>
                    <div class="flex flex-wrap items-center justify-center gap-1.5 mt-3">
                        <span class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400 mr-1">Trending</span>
                        <?php foreach ($suggestions as $s): ?>
                            <a href="/?q=<?= urlencode($s) ?>" class="group/tag px-3 py-1.5 bg-white/80 backdrop-blur border border-slate-200/80 text-slate-600 rounded-full text-xs font-medium hover:border-indigo-300 hover:text-indigo-600 hover:bg-indigo-50/60 hover:shadow-sm transition-all"><?= htmlspecialchars($s) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- QUICK ACCESS STRIP -->
            <div class="w-full max-w-5xl mx-auto px-4 mt-4 fade-up" style="--delay:0.1s">
                <div class="flex gap-2 overflow-x-auto qa-scroll pb-1 sm:grid sm:grid-cols-3 lg:grid-cols-6 sm:gap-2.5 sm:overflow-visible">
                    <?php
                    $quickLinks = [
                        ['href' => '/drive.php', 'icon' => 'folder', 'label' => 'Drive', 'count' => number_format($docStats['total'] ?? 0), 'unit' => 'files', 'color' => 'blue', 'bg' => 'from-blue-500/10 to-blue-600/5'],
                        ['href' => '/email_client.php', 'icon' => 'mail', 'label' => 'Mail', 'count' => number_format($emailCount ?? 0), 'unit' => 'emails', 'color' => 'red', 'bg' => 'from-red-500/10 to-red-600/5'],
                        ['href' => '/contacts.php', 'icon' => 'users', 'label' => 'Contacts', 'count' => number_format($entityCount ?? 0), 'unit' => 'people', 'color' => 'purple', 'bg' => 'from-purple-500/10 to-purple-600/5'],
                        ['href' => '/flight_logs.php', 'icon' => 'navigation', 'label' => 'Flights', 'count' => number_format($flightCount ?? 0), 'unit' => 'logs', 'color' => 'green', 'bg' => 'from-green-500/10 to-green-600/5'],
                        ['href' => '/photos.php', 'icon' => 'image', 'label' => 'Photos', 'count' => number_format($homeStats['photoCount'] ?? 0), 'unit' => 'images', 'color' => 'amber', 'bg' => 'from-amber-500/10 to-amber-600/5'],
                        ['href' => '/stats.php', 'icon' => 'bar-chart-2', 'label' => 'Analytics', 'count' => '', 'unit' => 'Stats', 'color' => 'emerald', 'bg' => 'from-emerald-500/10 to-emerald-600/5'],
                    ];
                    foreach ($quickLinks as $ql):
                    ?>
                    <a href="<?= $ql['href'] ?>" class="quick-card group flex-shrink-0 flex items-center gap-2 px-3 py-2.5 bg-white border border-slate-200/80 rounded-xl sm:flex-col sm:items-center sm:text-center sm:py-3.5 sm:px-2">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br <?= $ql['bg'] ?> text-<?= $ql['color'] ?>-600 flex items-center justify-center group-hover:scale-110 transition-transform sm:w-9 sm:h-9">
                            <svg class="w-4 h-4 sm:w-[18px] sm:h-[18px]" data-feather="<?= $ql['icon'] ?>"></svg>
                        </div>
                        <div class="sm:mt-1">
                            <span class="text-xs sm:text-sm font-bold text-slate-800 whitespace-nowrap"><?= $ql['label'] ?></span>
                            <?php if ($ql['count']): ?>
                            <span class="text-[10px] text-slate-400 font-semibold ml-1 sm:ml-0 sm:block sm:mt-0.5"><?= $ql['count'] ?> <?= $ql['unit'] ?></span>
                            <?php else: ?>
                            <span class="text-[10px] text-slate-400 font-semibold ml-1 sm:ml-0 sm:block sm:mt-0.5"><?= $ql['unit'] ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Amazon Orders Banner -->
            <div class="w-full max-w-5xl mx-auto px-4 mt-3 fade-up" style="--delay:0.12s">
                <a href="/orders.php" class="group block rounded-xl bg-gradient-to-r from-amber-50 via-orange-50 to-yellow-50 border border-orange-200/60 hover:border-orange-300 hover:shadow-lg hover:shadow-orange-500/10 transition-all">
                    <div class="px-4 py-2.5 flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-orange-400 to-amber-500 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-gradient-to-r from-orange-500 to-amber-500 text-white text-[10px] font-bold uppercase tracking-wide shadow-sm">New</span>
                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-orange-700 transition-colors">Amazon Purchase History</h3>
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5">Browse 886 Amazon orders linked to Epstein accounts &mdash; products, prices, dates, and categories.</p>
                        </div>
                        <svg class="w-5 h-5 text-orange-300 group-hover:text-orange-500 group-hover:translate-x-0.5 transition-all flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </a>
            </div>

            <!-- Trending Banner -->
            <div class="w-full max-w-5xl mx-auto px-4 mt-3 fade-up" style="--delay:0.13s">
                <a href="/trending.php" class="group block rounded-xl bg-gradient-to-r from-rose-50 via-pink-50 to-red-50 border border-rose-200/60 hover:border-rose-300 hover:shadow-lg hover:shadow-rose-500/10 transition-all">
                    <div class="px-4 py-2.5 flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-rose-500 to-red-600 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-gradient-to-r from-rose-500 to-red-500 text-white text-[10px] font-bold uppercase tracking-wide shadow-sm">Hot</span>
                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-rose-700 transition-colors">Trending Now</h3>
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5">See the most-viewed documents, photos, and searches right now &mdash; discover what other researchers are finding.</p>
                        </div>
                        <svg class="w-5 h-5 text-rose-300 group-hover:text-rose-500 group-hover:translate-x-0.5 transition-all flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </a>
            </div>

            <!-- Chat Room Banner -->
            <div class="w-full max-w-5xl mx-auto px-4 mt-3 fade-up" style="--delay:0.14s">
                <a href="/chatroom.php" class="group block rounded-xl bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 border border-blue-200/60 hover:border-blue-300 hover:shadow-lg hover:shadow-blue-500/10 transition-all">
                    <div class="px-4 py-2.5 flex items-center gap-3">
                        <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm group-hover:scale-110 transition-transform">
                            <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 text-white text-[10px] font-bold uppercase tracking-wide shadow-sm">Live</span>
                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-700 transition-colors">Chat Room</h3>
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5">Join the conversation &mdash; discuss documents, findings, and Epstein news with other researchers in real time.</p>
                        </div>
                        <svg class="w-5 h-5 text-blue-300 group-hover:text-blue-500 group-hover:translate-x-0.5 transition-all flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </a>
            </div>

            <!-- MAIN CONTENT + SIDEBAR -->
            <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-5 mb-12">
                <div class="lg:grid lg:grid-cols-12 lg:gap-8">

                    <!-- Main Column -->
                    <div class="lg:col-span-8 space-y-10">

                        <!-- Latest News -->
                        <?php if (!empty($recentNews)): ?>
                        <section class="fade-up" style="--delay:0.15s">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-red-600 mb-0.5">Breaking &amp; Recent</p>
                                    <h2 class="text-xl font-bold text-slate-900 tracking-tight">Latest News</h2>
                                </div>
                                <a href="/news.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 text-red-700 text-xs font-semibold hover:bg-red-100 transition-colors">
                                    All News
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach (array_slice($recentNews, 0, 3) as $i => $news):
                                    $shockScore = (int)($news['shock_score'] ?? 0);
                                    $headline = htmlspecialchars($news['ai_headline'] ?? $news['title']);
                                    $summary = htmlspecialchars($news['ai_summary'] ?? '');
                                    $source = htmlspecialchars($news['source_name'] ?? 'Unknown');
                                    $pubDate = $news['published_at'] ? date('M j', strtotime($news['published_at'])) : '';
                                    $isHot = $shockScore >= 7;
                                    $scoreColor = $shockScore >= 8 ? 'bg-red-500 text-white' : ($shockScore >= 6 ? 'bg-amber-500 text-white' : ($shockScore >= 4 ? 'bg-blue-500 text-white' : 'bg-slate-200 text-slate-600'));
                                ?>
                                <a href="/news.php"
                                   class="group relative bg-white rounded-2xl border border-slate-200 hover:border-slate-300 hover:shadow-lg transition-all overflow-hidden flex flex-col">
                                    <?php if ($i === 0 && $isHot): ?>
                                    <div class="absolute top-3 right-3 z-10">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-red-500 text-white text-[10px] font-bold uppercase tracking-wide animate-pulse">
                                            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 23c-1 0-6-4.5-6-10.5C6 5 12 1 12 1s6 4 6 11.5C18 18.5 13 23 12 23zm0-7a3.5 3.5 0 100-7 3.5 3.5 0 000 7z"/></svg>
                                            Hot
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="p-4 flex flex-col flex-1">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="<?= $scoreColor ?> text-[10px] font-bold px-1.5 py-0.5 rounded"><?= $shockScore ?>/10</span>
                                            <span class="text-[11px] text-slate-400 font-medium truncate"><?= $source ?></span>
                                            <?php if ($pubDate): ?>
                                            <span class="text-[11px] text-slate-300">&middot;</span>
                                            <span class="text-[11px] text-slate-400 flex-shrink-0"><?= $pubDate ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <h3 class="text-sm font-bold text-slate-900 group-hover:text-red-700 transition-colors mb-1.5 line-clamp-2 leading-snug"><?= $headline ?></h3>
                                        <?php if ($summary): ?>
                                        <p class="text-xs text-slate-500 line-clamp-2 flex-1"><?= $summary ?></p>
                                        <?php endif; ?>
                                        <div class="flex items-center text-[11px] text-slate-400 group-hover:text-red-600 transition-colors mt-2 pt-2 border-t border-slate-100">
                                            <span>View on News page</span>
                                            <svg class="w-3 h-3 ml-1 group-hover:translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>

                        <!-- Trending Now -->
                        <?php
                        // Map entity names to topic page slugs
                        $entityToTopic = [
                            'elon musk' => 'elon-musk-epstein-emails', 'musk' => 'elon-musk-epstein-emails',
                            'prince andrew' => 'prince-andrew-epstein-photos', 'andrew' => 'prince-andrew-epstein-photos',
                            'donald trump' => 'epstein-flight-logs-trump', 'trump' => 'epstein-flight-logs-trump',
                            'bill gates' => 'bill-gates-epstein', 'gates' => 'bill-gates-epstein',
                            'peter mandelson' => 'peter-mandelson-epstein', 'mandelson' => 'peter-mandelson-epstein',
                            'howard lutnick' => 'howard-lutnick-epstein', 'lutnick' => 'howard-lutnick-epstein',
                            'steve bannon' => 'steve-bannon-epstein', 'bannon' => 'steve-bannon-epstein',
                            'bill clinton' => 'epstein-clinton-flights', 'clinton' => 'epstein-clinton-flights',
                            'sarah ferguson' => 'sarah-ferguson-epstein', 'ferguson' => 'sarah-ferguson-epstein',
                        ];

                        // Combine news entities + top searches into unified trending items
                        $trendingItems = [];
                        $seenKeys = [];

                        // Add news entities (higher priority)
                        foreach ($trendingEntities as $ent) {
                            $key = strtolower($ent['name']);
                            if (isset($seenKeys[$key])) continue;
                            $seenKeys[$key] = true;
                            $topicSlug = $entityToTopic[$key] ?? null;
                            $href = $topicSlug ? "/topics/{$topicSlug}" : "/?q=" . urlencode($ent['name']);
                            $trendingItems[] = [
                                'label' => $ent['name'],
                                'href' => $href,
                                'mentions' => $ent['mentions'],
                                'weighted' => $ent['weighted'],
                                'type' => 'news',
                                'has_topic' => (bool)$topicSlug,
                            ];
                        }

                        // Add top searches that aren't already covered
                        foreach ($topSearches as $s) {
                            $key = strtolower($s['query']);
                            if (isset($seenKeys[$key])) continue;
                            // Skip generic terms
                            if (in_array($key, ['jeffrey epstein', 'epstein', 'jeffrey e. epstein', 'video', 'pdf', 'new york'])) continue;
                            $seenKeys[$key] = true;
                            $topicSlug = $entityToTopic[$key] ?? null;
                            $href = $topicSlug ? "/topics/{$topicSlug}" : "/?q=" . urlencode($s['query']);
                            $trendingItems[] = [
                                'label' => ucwords($s['query']),
                                'href' => $href,
                                'mentions' => (int)$s['cnt'],
                                'weighted' => 0,
                                'type' => 'search',
                                'has_topic' => (bool)$topicSlug,
                            ];
                        }
                        ?>
                        <?php if (!empty($trendingItems)): ?>
                        <section class="fade-up" style="--delay:0.17s">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-purple-600 mb-0.5">People in the News</p>
                                    <h2 class="text-xl font-bold text-slate-900 tracking-tight">Trending Now</h2>
                                </div>
                                <a href="/topics" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-purple-50 text-purple-700 text-xs font-semibold hover:bg-purple-100 transition-colors">
                                    All Topics
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </div>
                            <div class="flex flex-wrap gap-2.5">
                                <?php foreach (array_slice($trendingItems, 0, 10) as $i => $item):
                                    $isHot = $item['weighted'] >= 20 || ($item['type'] === 'search' && $item['mentions'] >= 100);
                                    $hasTopic = $item['has_topic'];
                                ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>"
                                   class="group inline-flex items-center gap-2 px-4 py-2.5 bg-white border rounded-xl hover:shadow-md transition-all
                                          <?= $isHot ? 'border-red-200 hover:border-red-300' : ($hasTopic ? 'border-purple-200 hover:border-purple-300' : 'border-slate-200 hover:border-slate-300') ?>">
                                    <?php if ($isHot): ?>
                                    <span class="flex items-center gap-1 px-1.5 py-0.5 rounded bg-red-500 text-white text-[9px] font-bold uppercase tracking-wide">
                                        <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 23c-1 0-6-4.5-6-10.5C6 5 12 1 12 1s6 4 6 11.5C18 18.5 13 23 12 23zm0-7a3.5 3.5 0 100-7 3.5 3.5 0 000 7z"/></svg>
                                        Hot
                                    </span>
                                    <?php elseif ($item['type'] === 'news'): ?>
                                    <span class="w-2 h-2 rounded-full bg-purple-400 flex-shrink-0"></span>
                                    <?php else: ?>
                                    <span class="w-2 h-2 rounded-full bg-blue-400 flex-shrink-0"></span>
                                    <?php endif; ?>
                                    <span class="text-sm font-semibold text-slate-800 group-hover:text-<?= $isHot ? 'red' : ($hasTopic ? 'purple' : 'blue') ?>-700 transition-colors"><?= htmlspecialchars($item['label']) ?></span>
                                    <?php if ($item['type'] === 'news'): ?>
                                    <span class="text-[10px] text-slate-400 font-medium"><?= $item['mentions'] ?> articles</span>
                                    <?php else: ?>
                                    <span class="text-[10px] text-slate-400 font-medium"><?= number_format($item['mentions']) ?> searches</span>
                                    <?php endif; ?>
                                    <?php if ($hasTopic): ?>
                                    <span class="text-[9px] font-bold uppercase tracking-wide text-purple-500 bg-purple-50 px-1.5 py-0.5 rounded">Topic</span>
                                    <?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>

                        <!-- Top Photos -->
                        <?php if (!empty($topPhotos)): ?>
                        <section class="fade-up" style="--delay:0.19s">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-600 mb-0.5">Most Viewed</p>
                                    <h2 class="text-xl font-bold text-slate-900 tracking-tight">Top Photos</h2>
                                </div>
                                <a href="/popular.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 text-xs font-semibold hover:bg-amber-100 transition-colors">
                                    View All
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                                <?php foreach ($topPhotos as $photo):
                                    $photoUrl = resolveMediaUrl($photo['local_path'] ?? null, $photo['source_url'] ?? null);
                                    if ($photoUrl && strpos($photoUrl, 'drive.google.com') !== false) {
                                        if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $photoUrl, $gdMatch)) {
                                            $photoUrl = 'https://drive.google.com/thumbnail?id=' . $gdMatch[1] . '&sz=w400';
                                        }
                                    }
                                    $serveUrl = null;
                                    if (!preg_match('/^https?:\/\//i', $photo['local_path'] ?? '') && ($photo['local_path'] ?? '')) {
                                        $serveUrl = '/serve.php?id=' . (int)$photo['id'];
                                    }
                                    $thumbUrl = $serveUrl ?? $photoUrl;
                                ?>
                                <a href="/document.php?id=<?= (int)$photo['id'] ?>" class="group relative aspect-square bg-slate-100 rounded-xl overflow-hidden border border-slate-200 hover:border-amber-300 hover:shadow-lg transition-all">
                                    <?php if ($thumbUrl): ?>
                                    <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($photo['title'] ?? '') ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy"
                                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="w-full h-full bg-gradient-to-br from-amber-100 to-slate-100 items-center justify-center hidden">
                                        <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <?php else: ?>
                                    <div class="w-full h-full bg-gradient-to-br from-amber-100 to-slate-100 flex items-center justify-center">
                                        <svg class="w-8 h-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <?php endif; ?>
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent pointer-events-none"></div>
                                    <div class="absolute bottom-0 left-0 right-0 p-2.5 pointer-events-none">
                                        <p class="text-white text-xs font-semibold line-clamp-1 leading-tight"><?= htmlspecialchars($photo['title'] ?? 'Untitled') ?></p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="inline-flex items-center gap-1 text-[10px] text-white/80 font-medium">
                                                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                <?= number_format((int)($photo['view_count'] ?? 0)) ?>
                                            </span>
                                            <?php if (!empty($photo['data_set'])): ?>
                                            <span class="text-[9px] text-white/50 truncate"><?= htmlspecialchars($photo['data_set']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php endif; ?>

                        <!-- Trending Leaderboard CTA -->
                        <section class="fade-up" style="--delay:0.195s">
                            <a href="/trending.php" class="group block relative overflow-hidden rounded-2xl border border-rose-200 bg-gradient-to-r from-rose-50 via-amber-50 to-purple-50 hover:border-rose-300 hover:shadow-xl hover:shadow-rose-100/50 transition-all">
                                <div class="absolute inset-0 bg-gradient-to-r from-rose-500/5 via-transparent to-purple-500/5 group-hover:from-rose-500/10 group-hover:to-purple-500/10 transition-all"></div>
                                <div class="relative flex items-center justify-between px-6 py-5">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-rose-500 to-amber-500 flex items-center justify-center shadow-lg shadow-rose-200/50 group-hover:scale-110 transition-transform">
                                            <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-rose-600 mb-0.5">Live Leaderboard</p>
                                            <h2 class="text-lg font-bold text-slate-900 tracking-tight">Trending Searches, Documents & People</h2>
                                            <p class="text-xs text-slate-500 mt-0.5">See what the world is searching for in the Epstein files right now</p>
                                        </div>
                                    </div>
                                    <div class="hidden sm:flex items-center gap-2 px-4 py-2 rounded-xl bg-white/80 border border-rose-200 text-rose-700 text-sm font-bold group-hover:bg-white group-hover:shadow-md transition-all">
                                        View Leaderboard
                                        <svg class="w-4 h-4 group-hover:translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </div>
                                </div>
                            </a>
                        </section>

                        <!-- Recent Documents -->
                        <section class="fade-up" style="--delay:0.2s">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-blue-600 mb-0.5">Latest Additions</p>
                                    <h2 class="text-xl font-bold text-slate-900 tracking-tight">Recent Documents</h2>
                                </div>
                                <a href="/drive.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 text-xs font-semibold hover:bg-blue-100 transition-colors">
                                    All Documents
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php if (!empty($recentDocs)): ?>
                                    <?php foreach (array_slice($recentDocs, 0, 4) as $doc): ?>
                                    <a href="/document.php?id=<?= $doc['id'] ?>" class="group p-4 bg-white rounded-xl border border-slate-200 hover:border-blue-200 hover:shadow-md transition-all flex gap-3">
                                        <div class="w-10 h-10 bg-slate-50 rounded-lg flex items-center justify-center text-xl flex-shrink-0 border border-slate-100 group-hover:scale-105 transition-transform">
                                            <?php
                                            $ft = $doc['file_type'] ?? '';
                                            echo match($ft) { 'pdf' => '📕', 'docx', 'doc' => '📘', 'xlsx', 'xls' => '📊', default => '📄' };
                                            ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h3 class="text-sm font-bold text-slate-900 group-hover:text-blue-600 line-clamp-1 transition-colors"><?= htmlspecialchars($doc['title']) ?></h3>
                                            <?php if (!empty($doc['ai_summary'])): ?>
                                            <p class="text-xs text-slate-500 line-clamp-2 mt-1 leading-relaxed"><?= htmlspecialchars(substr($doc['ai_summary'], 0, 120)) ?>...</p>
                                            <?php endif; ?>
                                            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 mt-2">
                                                <?= strtoupper($doc['file_type'] ?? 'DOC') ?> &middot; Indexed <?= date('M j', strtotime($doc['created_at'])) ?>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-slate-400 text-center py-8 italic col-span-2">Waiting for first document index...</p>
                                <?php endif; ?>
                            </div>
                        </section>

                        <!-- Ask AI Prompts -->
                        <section class="fade-up" style="--delay:0.25s">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-indigo-600 mb-0.5">Get Started</p>
                                    <h2 class="text-xl font-bold text-slate-900 tracking-tight">Ask Epstein AI</h2>
                                </div>
                                <a href="/ask.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold hover:bg-indigo-100 transition-colors">
                                    Open AI Chat
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <?php foreach (array_slice($samplePrompts, 0, 4) as $prompt): ?>
                                <a href="/ask.php?prefill=<?= urlencode($prompt['prefill']) ?>" class="group flex items-start gap-3 p-4 bg-white border border-slate-200 rounded-xl hover:border-indigo-200 hover:bg-indigo-50/30 transition-all">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0 group-hover:scale-105 transition-transform">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                                    </div>
                                    <p class="text-sm font-medium text-slate-700 group-hover:text-indigo-700 leading-snug transition-colors"><?= htmlspecialchars($prompt['question']) ?></p>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </section>

                    </div>

                    <!-- Sidebar (desktop only) -->
                    <aside class="hidden lg:block lg:col-span-4 fade-up" style="--delay:0.2s">
                        <div class="sticky top-24 space-y-5">

                            <!-- Archive Stats -->
                            <div class="bg-white rounded-2xl border border-slate-200 p-5">
                                <h3 class="text-xs font-bold uppercase tracking-[0.15em] text-slate-500 mb-4">Archive Stats</h3>
                                <div class="space-y-3">
                                    <?php
                                    $statItems = [
                                        ['label' => 'Documents', 'value' => number_format($docStats['total'] ?? 0), 'color' => 'bg-blue-500'],
                                        ['label' => 'Emails', 'value' => number_format($emailCount ?? 0), 'color' => 'bg-red-500'],
                                        ['label' => 'Entities', 'value' => number_format($entityCount ?? 0), 'color' => 'bg-purple-500'],
                                        ['label' => 'Flight Logs', 'value' => number_format($flightCount ?? 0), 'color' => 'bg-green-500'],
                                        ['label' => 'Photos', 'value' => number_format($homeStats['photoCount'] ?? 0), 'color' => 'bg-amber-500'],
                                        ['label' => 'PDFs', 'value' => number_format($homeStats['pdfCount'] ?? 0), 'color' => 'bg-slate-500'],
                                    ];
                                    foreach ($statItems as $si):
                                    ?>
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2 h-2 rounded-full <?= $si['color'] ?>"></span>
                                            <span class="text-sm text-slate-600"><?= $si['label'] ?></span>
                                        </div>
                                        <span class="text-sm font-bold text-slate-900"><?= $si['value'] ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Top Entities -->
                            <?php if (!empty($topEntities)): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 p-5">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-xs font-bold uppercase tracking-[0.15em] text-slate-500">Key Players</h3>
                                    <a href="/contacts.php" class="text-[11px] font-semibold text-blue-600 hover:underline">View all</a>
                                </div>
                                <div class="space-y-1">
                                    <?php foreach (array_slice($topEntities, 0, 6) as $entity): ?>
                                    <a href="/?q=<?= urlencode($entity['entity_name']) ?>" class="flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-slate-50 transition-all group">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm flex-shrink-0
                                            <?= $entity['entity_type'] === 'PERSON' ? 'bg-purple-100 text-purple-600' : ($entity['entity_type'] === 'ORG' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600') ?>">
                                            <svg class="w-4 h-4" data-feather="<?= $entity['entity_type'] === 'PERSON' ? 'user' : ($entity['entity_type'] === 'ORG' ? 'briefcase' : 'map-pin') ?>"></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="text-sm font-semibold text-slate-800 truncate group-hover:text-blue-600 transition-colors"><?= htmlspecialchars($entity['entity_name']) ?></div>
                                            <div class="text-[10px] text-slate-400 font-medium"><?= number_format($entity['mention_count']) ?> mentions</div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Ask AI CTA -->
                            <a href="/ask.php" class="block bg-gradient-to-br from-indigo-600 to-blue-700 rounded-2xl p-5 text-white hover:shadow-lg hover:shadow-indigo-500/20 transition-all group">
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="w-10 h-10 bg-white/15 rounded-xl flex items-center justify-center text-lg group-hover:bg-white/25 transition-colors">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                                    </div>
                                    <div>
                                        <div class="font-bold text-sm">Ask Epstein AI</div>
                                        <div class="text-xs text-blue-200">AI-powered document search</div>
                                    </div>
                                </div>
                                <p class="text-xs text-blue-100 leading-relaxed">Ask questions about documents, entities, flights, and emails. Get cited answers from the archive.</p>
                            </a>

                            <!-- Data Sets -->
                            <?php if (!empty($dataSets)): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 p-5">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-xs font-bold uppercase tracking-[0.15em] text-slate-500">Data Sources</h3>
                                    <span class="text-[10px] font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded"><?= count($dataSets) ?> sets</span>
                                </div>
                                <div class="space-y-2 max-h-[280px] overflow-y-auto pr-1">
                                    <?php foreach ($dataSets as $ds): ?>
                                    <a href="/drive.php?folder=<?= urlencode($ds['data_set']) ?>" class="flex items-center justify-between py-1.5 text-sm hover:text-emerald-700 transition-colors group">
                                        <span class="text-slate-700 group-hover:text-emerald-700 truncate"><?= htmlspecialchars($ds['data_set']) ?></span>
                                        <span class="text-xs text-slate-400 font-semibold flex-shrink-0 ml-2"><?= number_format($ds['count']) ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Support This Project -->
                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 rounded-2xl p-5 shadow-sm">
                                <h3 class="text-xs font-bold uppercase tracking-[0.15em] text-emerald-900 mb-3">Support This Project</h3>
                                <p class="text-xs text-slate-700 leading-relaxed mb-4">
                                    Help keep Epstein Suite online and accessible. Your donation covers hosting, OCR processing, and AI costs.
                                </p>
                                <a href="https://buy.stripe.com/6oU4gB0vMbasdXqat82VG02" target="_blank" rel="noopener" class="block text-center rounded-lg bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 hover:opacity-95 transition">
                                    ❤️ Donate via Stripe
                                </a>
                                <p class="mt-3 text-[10px] text-slate-600 text-center">
                                    One-time or recurring donations accepted
                                </p>
                            </div>

                        </div>
                    </aside>

                </div>

                <!-- Mobile-only: Stats + Entities -->
                <div class="lg:hidden mt-10 space-y-6">
                    <!-- Stats strip -->
                    <div class="flex gap-3 overflow-x-auto qa-scroll pb-1">
                        <?php
                        $mobileStats = [
                            ['label' => 'Docs', 'value' => number_format($docStats['total'] ?? 0), 'color' => 'blue'],
                            ['label' => 'Emails', 'value' => number_format($emailCount ?? 0), 'color' => 'red'],
                            ['label' => 'People', 'value' => number_format($entityCount ?? 0), 'color' => 'purple'],
                            ['label' => 'Flights', 'value' => number_format($flightCount ?? 0), 'color' => 'green'],
                            ['label' => 'Photos', 'value' => number_format($homeStats['photoCount'] ?? 0), 'color' => 'amber'],
                        ];
                        foreach ($mobileStats as $ms):
                        ?>
                        <div class="flex-shrink-0 text-center px-5 py-3 bg-white border border-slate-200 rounded-xl">
                            <div class="text-lg font-extrabold text-slate-900"><?= $ms['value'] ?></div>
                            <div class="text-[10px] font-bold uppercase tracking-wider text-<?= $ms['color'] ?>-500"><?= $ms['label'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Top Entities -->
                    <?php if (!empty($topEntities)): ?>
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-slate-800">Key Players</h3>
                            <a href="/contacts.php" class="text-xs text-blue-600 font-semibold hover:underline">View all</a>
                        </div>
                        <div class="space-y-1">
                            <?php foreach (array_slice($topEntities, 0, 5) as $entity): ?>
                            <a href="/?q=<?= urlencode($entity['entity_name']) ?>" class="flex items-center gap-3 px-3 py-2.5 bg-white border border-slate-200 rounded-xl hover:border-blue-200 transition-all">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0
                                    <?= $entity['entity_type'] === 'PERSON' ? 'bg-purple-100 text-purple-600' : ($entity['entity_type'] === 'ORG' ? 'bg-blue-100 text-blue-600' : 'bg-green-100 text-green-600') ?>">
                                    <svg class="w-4 h-4" data-feather="<?= $entity['entity_type'] === 'PERSON' ? 'user' : ($entity['entity_type'] === 'ORG' ? 'briefcase' : 'map-pin') ?>"></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-semibold text-slate-800 truncate block"><?= htmlspecialchars($entity['entity_name']) ?></span>
                                    <span class="text-[10px] text-slate-400"><?= number_format($entity['mention_count']) ?> mentions</span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Search Results Mode -->
            <style>
            .tab-btn{flex-shrink:0;padding:0.4rem 0.85rem;border-radius:0.5rem;font-size:0.8125rem;font-weight:500;color:#64748b;white-space:nowrap;transition:all 0.15s;cursor:pointer;border:1px solid transparent;background:none;line-height:1.4;}
            .tab-btn:hover{background:#f1f5f9;color:#334155;border-color:#e2e8f0;}
            .tab-btn[data-active="true"]{background:#0f172a;color:#fff;font-weight:600;border-color:#0f172a;box-shadow:0 1px 3px rgba(15,23,42,0.2);}
            .tab-btn[data-active="true"] .tab-count{background:rgba(255,255,255,0.2);color:#fff;}
            .tab-btn.tab-empty{opacity:0.35;}
            .tab-btn.tab-empty:hover{opacity:0.6;}
            .tab-count{margin-left:0.25rem;font-size:0.6875rem;background:#f1f5f9;color:#475569;padding:0.1rem 0.4rem;border-radius:0.375rem;font-weight:600;letter-spacing:-0.01em;}
            .scrollbar-hide::-webkit-scrollbar{display:none;}
            .scrollbar-hide{-ms-overflow-style:none;scrollbar-width:none;}
            .search-section-dot{width:6px;height:6px;border-radius:9999px;flex-shrink:0;}
            </style>

            <?php $totalResults = $docTotalCount + $emailTotalCount + $flightTotalCount + $photoTotalCount + $entityTotalCount + $newsTotalCount; ?>

            <?php if ($error): ?>
                <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div class="bg-red-50 text-red-700 p-4 rounded-lg mb-6 max-w-3xl"><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($totalResults === 0 && !$error): ?>
                <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                    <div class="max-w-xl mx-auto text-center">
                        <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl">🔍</div>
                        <p class="text-lg text-gray-700">Your search for "<span class="font-bold"><?= htmlspecialchars($searchQuery) ?></span>" did not match any records.</p>
                        <div class="mt-6 text-sm text-gray-500 space-y-1">
                            <p>Make sure all words are spelled correctly.</p>
                            <p>Try different or more general keywords.</p>
                        </div>
                        <a href="/ask.php?q=<?= urlencode($searchQuery) ?>" class="inline-flex items-center gap-2 mt-6 px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold text-sm shadow hover:shadow-lg transition-all">
                            Ask Epstein AI instead →
                        </a>
                    </div>
                </div>
            <?php else: ?>

            <!-- Sticky Tab Bar -->
            <div id="search-tabs" class="w-full sticky top-16 z-30 mt-4 sm:mt-6 bg-white/95 backdrop-blur-sm border-b border-slate-200/80 shadow-sm">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between pt-4 pb-2">
                        <p class="text-sm text-slate-500">
                            About <strong class="text-slate-800"><?= number_format($totalResults) ?></strong> results for
                            "<strong class="text-slate-800"><?= htmlspecialchars($searchQuery) ?></strong>"
                        </p>
                        <?php if ($totalResults > 0 && !$hasExactMatch): ?>
                            <span class="text-xs text-blue-600 hidden sm:inline">Showing partial matches</span>
                        <?php endif; ?>
                    </div>
                    <nav class="flex items-center gap-1 overflow-x-auto pb-3 scrollbar-hide" role="tablist">
                        <button data-tab="all" data-active="true" class="tab-btn" role="tab">All</button>
                        <button data-tab="documents" data-active="false" class="tab-btn <?= $docTotalCount === 0 ? 'tab-empty' : '' ?>" role="tab">
                            Documents <span class="tab-count"><?= number_format($docTotalCount) ?></span>
                        </button>
                        <button data-tab="emails" data-active="false" class="tab-btn <?= $emailTotalCount === 0 ? 'tab-empty' : '' ?>" role="tab">
                            Emails <span class="tab-count"><?= number_format($emailTotalCount) ?></span>
                        </button>
                        <button data-tab="flights" data-active="false" class="tab-btn <?= $flightTotalCount === 0 ? 'tab-empty' : '' ?>" role="tab">
                            Flights <span class="tab-count"><?= number_format($flightTotalCount) ?></span>
                        </button>
                        <button data-tab="news" data-active="false" class="tab-btn <?= $newsTotalCount === 0 ? 'tab-empty' : '' ?>" role="tab">
                            News <span class="tab-count"><?= number_format($newsTotalCount) ?></span>
                        </button>
                        <button data-tab="photos" data-active="false" class="tab-btn <?= $photoTotalCount === 0 ? 'tab-empty' : '' ?>" role="tab">
                            Photos <span class="tab-count"><?= number_format($photoTotalCount) ?></span>
                        </button>
                        <button data-tab="entities" data-active="false" class="tab-btn <?= $entityTotalCount === 0 ? 'tab-empty' : '' ?>" role="tab">
                            People <span class="tab-count"><?= number_format($entityTotalCount) ?></span>
                        </button>
                        <?php if (!empty($semanticResults)): ?>
                        <button data-tab="ai" data-active="false" class="tab-btn" role="tab">
                            AI Matches <span class="tab-count"><?= count($semanticResults) ?></span>
                        </button>
                        <?php endif; ?>
                    </nav>
                </div>
            </div>

            <!-- Results Body -->
            <div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <div class="lg:grid lg:grid-cols-12 lg:gap-8">

                    <!-- Main Content Column -->
                    <div class="lg:col-span-8 min-w-0">

                        <!-- ═══ ALL TAB ═══ -->
                        <div id="panel-all" class="tab-panel space-y-8">

                            <?php if (!empty($semanticResults)): ?>
                            <section>
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-indigo-500"></span> AI-Ranked Matches
                                    </h2>
                                    <button onclick="switchTab('ai')" class="text-sm text-indigo-600 hover:underline font-medium">View all <?= count($semanticResults) ?> →</button>
                                </div>
                                <div class="space-y-3">
                                    <?php foreach (array_slice($semanticResults, 0, 3) as $ai):
                                        $targetUrl = $ai['type'] === 'document' ? '/document.php?id=' . (int)$ai['id'] : '/flight_logs.php?id=' . (int)$ai['id'];
                                    ?>
                                    <a href="<?= $targetUrl ?>" class="block bg-gradient-to-r from-indigo-50/50 to-white border border-indigo-100 rounded-xl p-4 hover:border-indigo-300 hover:shadow-md transition-all">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-xs text-indigo-500 font-semibold uppercase tracking-wide"><?= $ai['type'] === 'document' ? 'Document' : 'Flight' ?></div>
                                                <div class="text-base font-semibold text-slate-900 truncate">
                                                    <?= $ai['type'] === 'document' ? htmlspecialchars($ai['title'] ?? 'Untitled') : htmlspecialchars(($ai['origin'] ?? '') . ' → ' . ($ai['destination'] ?? '')) ?>
                                                </div>
                                            </div>
                                            <span class="flex-shrink-0 text-xs font-bold text-indigo-600 bg-indigo-50 px-2 py-1 rounded-lg"><?= round(($ai['search_score'] ?? 0) * 100) ?>%</span>
                                        </div>
                                        <?php if (!empty($ai['snippet'])): ?>
                                            <p class="mt-1.5 text-sm text-slate-600 line-clamp-2"><?= htmlspecialchars($ai['snippet']) ?></p>
                                        <?php endif; ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($results)): ?>
                            <section>
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-blue-500"></span> Documents
                                    </h2>
                                    <button onclick="switchTab('documents')" class="text-sm text-blue-600 hover:underline font-medium">View all <?= number_format($docTotalCount) ?> →</button>
                                </div>
                                <div class="space-y-4">
                                    <?php foreach (array_slice($results, 0, 5) as $row): ?>
                                    <a href="/document.php?id=<?= $row['id'] ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-md transition-all group">
                                        <div class="flex items-start gap-3">
                                            <div class="w-8 h-8 bg-slate-100 rounded-lg flex items-center justify-center text-sm flex-shrink-0 mt-0.5">
                                                <?php
                                                $ft = $row['file_type'] ?? '';
                                                echo match($ft) { 'pdf' => '📕', 'jpg','jpeg','png','gif' => '🖼️', 'mp4','mov','video' => '🎬', 'email' => '✉️', default => '📄' };
                                                ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <h3 class="text-base font-semibold text-blue-800 group-hover:underline line-clamp-1"><?= htmlspecialchars($row['title']) ?></h3>
                                                <div class="flex items-center gap-2 mt-0.5 text-xs text-slate-500">
                                                    <span><?= htmlspecialchars($row['data_set'] ?? 'Epstein Files') ?></span>
                                                    <span class="text-slate-300">&middot;</span>
                                                    <span class="uppercase"><?= $row['file_type'] ?? 'DOC' ?></span>
                                                </div>
                                                <?php if (!empty($row['ai_summary'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1.5 line-clamp-2"><?= htmlspecialchars(substr($row['ai_summary'], 0, 200)) ?></p>
                                                <?php elseif (!empty($row['description'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1.5 line-clamp-2"><?= htmlspecialchars(substr($row['description'], 0, 200)) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($emailResults)): ?>
                            <section>
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-green-500"></span> Emails
                                    </h2>
                                    <button onclick="switchTab('emails')" class="text-sm text-green-600 hover:underline font-medium">View all <?= number_format($emailTotalCount) ?> →</button>
                                </div>
                                <div class="space-y-3">
                                    <?php foreach (array_slice($emailResults, 0, 3) as $email): ?>
                                    <a href="<?= !empty($email['document_id']) ? '/document.php?id=' . (int)$email['document_id'] : '/email_client.php?q=' . urlencode($email['subject'] ?? '') ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-green-300 hover:shadow-md transition-all">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($email['subject'] ?: '(No Subject)') ?></div>
                                                <div class="text-xs text-slate-500 mt-0.5 truncate">
                                                    <span class="font-medium text-slate-700"><?= htmlspecialchars($email['sender'] ?: 'Unknown') ?></span>
                                                    <span class="text-slate-400"> → </span>
                                                    <span><?= htmlspecialchars($email['recipient'] ?: 'Unknown') ?></span>
                                                </div>
                                                <?php if (!empty($email['body_preview'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1.5 line-clamp-1"><?= htmlspecialchars($email['body_preview']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($email['sent_at'])): ?>
                                                <span class="flex-shrink-0 text-xs text-slate-400"><?= date('M j, Y', strtotime($email['sent_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($newsResults)): ?>
                            <section>
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-red-500"></span> News
                                    </h2>
                                    <button onclick="switchTab('news')" class="text-sm text-red-600 hover:underline font-medium">View all <?= number_format($newsTotalCount) ?> →</button>
                                </div>
                                <div class="space-y-3">
                                    <?php foreach (array_slice($newsResults, 0, 3) as $news):
                                        $score = (int)($news['shock_score'] ?? 0);
                                        $badgeClass = $score >= 8 ? 'bg-red-500 text-white' : ($score >= 5 ? 'bg-amber-500 text-white' : 'bg-slate-400 text-white');
                                        $badgeLabel = $score >= 8 ? 'BREAKING' : ($score >= 5 ? 'NOTABLE' : 'UPDATE');
                                    ?>
                                    <a href="<?= htmlspecialchars($news['url']) ?>" target="_blank" rel="noopener" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-red-300 hover:shadow-md transition-all">
                                        <div class="flex items-start gap-3">
                                            <span class="flex-shrink-0 px-2 py-0.5 rounded text-xs font-bold <?= $badgeClass ?>"><?= $score ?>/10</span>
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-slate-900 line-clamp-1"><?= htmlspecialchars($news['ai_headline'] ?: $news['title']) ?></div>
                                                <div class="text-xs text-slate-500 mt-0.5">
                                                    <?= !empty($news['source_name']) ? htmlspecialchars($news['source_name']) . ' &middot; ' : '' ?>
                                                    <?= !empty($news['published_at']) ? date('M j, Y', strtotime($news['published_at'])) : '' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($flightResults)): ?>
                            <section>
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-sky-500"></span> Flights
                                    </h2>
                                    <button onclick="switchTab('flights')" class="text-sm text-sky-600 hover:underline font-medium">View all <?= number_format($flightTotalCount) ?> →</button>
                                </div>
                                <div class="space-y-3">
                                    <?php foreach (array_slice($flightResults, 0, 3) as $flight): ?>
                                    <a href="/flight_logs.php?q=<?= urlencode($searchQuery) ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-sky-300 hover:shadow-md transition-all">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($flight['origin'] ?? '') ?> <span class="text-slate-400">→</span> <?= htmlspecialchars($flight['destination'] ?? '') ?></div>
                                                <?php if (!empty($flight['passenger_list'])): ?>
                                                    <div class="text-xs text-slate-500 mt-0.5 line-clamp-1">Passengers: <?= htmlspecialchars($flight['passenger_list']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-shrink-0 text-xs text-slate-400 text-right">
                                                <?= !empty($flight['flight_date']) ? date('M j, Y', strtotime($flight['flight_date'])) : '' ?>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($photoResults)): ?>
                            <section class="lg:hidden">
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-purple-500"></span> Photos
                                    </h2>
                                    <button onclick="switchTab('photos')" class="text-sm text-purple-600 hover:underline font-medium">View all <?= number_format($photoTotalCount) ?> →</button>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    <?php foreach (array_slice($photoResults, 0, 4) as $img): ?>
                                    <a href="/document.php?id=<?= (int)$img['id'] ?>" class="group relative aspect-square bg-slate-100 rounded-xl overflow-hidden border border-slate-200 hover:border-purple-300 hover:shadow-md transition-all">
                                        <img src="/serve.php?id=<?= (int)$img['id'] ?>" alt="<?= htmlspecialchars($img['title'] ?? '') ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                                            <p class="absolute bottom-2 left-2 right-2 text-xs font-semibold text-white line-clamp-2"><?= htmlspecialchars($img['title'] ?? 'Untitled') ?></p>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                            <?php if (!empty($entityResults)): ?>
                            <section class="lg:hidden">
                                <div class="flex items-center justify-between mb-3">
                                    <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wide flex items-center gap-2">
                                        <span class="search-section-dot bg-amber-500"></span> People & Entities
                                    </h2>
                                    <button onclick="switchTab('entities')" class="text-sm text-amber-600 hover:underline font-medium">View all <?= number_format($entityTotalCount) ?> →</button>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach (array_slice($entityResults, 0, 8) as $ent):
                                        $typeColor = match($ent['type'] ?? '') { 'PERSON' => 'bg-purple-50 text-purple-700 border-purple-200', 'ORG' => 'bg-blue-50 text-blue-700 border-blue-200', 'LOCATION' => 'bg-green-50 text-green-700 border-green-200', default => 'bg-slate-50 text-slate-700 border-slate-200' };
                                    ?>
                                    <a href="/contacts.php?q=<?= urlencode($ent['name']) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium border <?= $typeColor ?> hover:shadow-sm transition-all">
                                        <?= htmlspecialchars($ent['name']) ?>
                                        <span class="text-xs opacity-50"><?= $ent['doc_count'] ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <?php endif; ?>

                        </div>

                        <!-- ═══ DOCUMENTS TAB ═══ -->
                        <div id="panel-documents" class="tab-panel hidden space-y-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold text-slate-900">Documents</h2>
                                <a href="/drive.php?q=<?= urlencode($searchQuery) ?>" class="text-sm text-blue-600 hover:underline font-medium">Browse in Drive →</a>
                            </div>
                            <?php if (empty($results)): ?>
                                <p class="text-sm text-slate-500 py-8 text-center">No document matches found.</p>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($results as $row): ?>
                                    <a href="/document.php?id=<?= $row['id'] ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-blue-300 hover:shadow-md transition-all group">
                                        <div class="flex items-start gap-3">
                                            <div class="w-8 h-8 bg-slate-100 rounded-lg flex items-center justify-center text-sm flex-shrink-0 mt-0.5">
                                                <?php
                                                $ft = $row['file_type'] ?? '';
                                                echo match($ft) { 'pdf' => '📕', 'jpg','jpeg','png','gif' => '🖼️', 'mp4','mov','video' => '🎬', 'email' => '✉️', default => '📄' };
                                                ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <h3 class="text-base font-semibold text-blue-800 group-hover:underline line-clamp-2"><?= htmlspecialchars($row['title']) ?></h3>
                                                <div class="flex items-center gap-2 mt-0.5 text-xs text-slate-500">
                                                    <span><?= htmlspecialchars($row['data_set'] ?? 'Epstein Files') ?></span>
                                                    <span class="text-slate-300">&middot;</span>
                                                    <span class="uppercase"><?= $row['file_type'] ?? 'DOC' ?></span>
                                                    <?php if (!empty($row['local_path'])): ?>
                                                        <span class="text-slate-300">&middot;</span>
                                                        <span class="text-green-600">💾 Local</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($row['ai_summary'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1.5 line-clamp-2">
                                                        <span class="inline-flex items-center text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded mr-1">AI</span>
                                                        <?= htmlspecialchars(substr($row['ai_summary'], 0, 250)) ?>
                                                    </p>
                                                <?php elseif (!empty($row['description'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1.5 line-clamp-2"><?= htmlspecialchars(substr($row['description'], 0, 250)) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php $docPages = max(1, (int)ceil(($docTotalCount ?: count($results)) / $perPage)); ?>
                                <?php if ($docPages > 1): ?>
                                <div class="flex items-center justify-between text-sm text-slate-600 pt-4 border-t border-slate-100">
                                    <span>Page <?= number_format($page) ?> of <?= number_format($docPages) ?></span>
                                    <div class="flex items-center gap-2">
                                        <?php if ($page > 1): ?>
                                            <a href="<?= buildSearchUrl(['page' => $page - 1]) ?>#documents" class="px-4 py-2 rounded-lg border border-slate-200 hover:border-blue-300 hover:text-blue-600 transition-colors font-medium">Previous</a>
                                        <?php endif; ?>
                                        <?php if ($page < $docPages): ?>
                                            <a href="<?= buildSearchUrl(['page' => $page + 1]) ?>#documents" class="px-4 py-2 rounded-lg border border-slate-200 hover:border-blue-300 hover:text-blue-600 transition-colors font-medium">Next</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- ═══ EMAILS TAB ═══ -->
                        <div id="panel-emails" class="tab-panel hidden space-y-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold text-slate-900">Emails</h2>
                                <a href="/email_client.php?q=<?= urlencode($searchQuery) ?>" class="text-sm text-green-600 hover:underline font-medium">Open in Mail →</a>
                            </div>
                            <?php if (empty($emailResults)): ?>
                                <p class="text-sm text-slate-500 py-8 text-center">No email matches found.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($emailResults as $email): ?>
                                    <a href="<?= !empty($email['document_id']) ? '/document.php?id=' . (int)$email['document_id'] : '/email_client.php?q=' . urlencode($email['subject'] ?? '') ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-green-300 hover:shadow-md transition-all">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($email['subject'] ?: '(No Subject)') ?></div>
                                                <div class="text-xs text-slate-500 mt-0.5 truncate">
                                                    <span class="font-medium text-slate-700"><?= htmlspecialchars($email['sender'] ?: 'Unknown') ?></span>
                                                    <span class="text-slate-400"> → </span>
                                                    <span><?= htmlspecialchars($email['recipient'] ?: 'Unknown') ?></span>
                                                </div>
                                                <?php if (!empty($email['body_preview'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1.5 line-clamp-2"><?= htmlspecialchars($email['body_preview']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($email['sent_at'])): ?>
                                                <span class="flex-shrink-0 text-xs text-slate-400"><?= date('M j, Y', strtotime($email['sent_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-2 text-xs text-blue-600 font-medium">View source document →</div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ═══ FLIGHTS TAB ═══ -->
                        <div id="panel-flights" class="tab-panel hidden space-y-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold text-slate-900">Flights</h2>
                                <a href="/flight_logs.php?q=<?= urlencode($searchQuery) ?>" class="text-sm text-sky-600 hover:underline font-medium">Open in Flights →</a>
                            </div>
                            <?php if (empty($flightResults)): ?>
                                <p class="text-sm text-slate-500 py-8 text-center">No flight matches found.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($flightResults as $flight): ?>
                                    <a href="/flight_logs.php?q=<?= urlencode($searchQuery) ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-sky-300 hover:shadow-md transition-all">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($flight['origin'] ?? '') ?> <span class="text-slate-400">→</span> <?= htmlspecialchars($flight['destination'] ?? '') ?></div>
                                                <?php if (!empty($flight['passenger_list'])): ?>
                                                    <div class="text-xs text-slate-500 mt-0.5 line-clamp-1">Passengers: <?= htmlspecialchars($flight['passenger_list']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-shrink-0 text-xs text-slate-400 text-right">
                                                <?php if (!empty($flight['flight_date'])): ?><?= date('M j, Y', strtotime($flight['flight_date'])) ?><br><?php endif; ?>
                                                <?= htmlspecialchars($flight['aircraft'] ?? '') ?>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ═══ NEWS TAB ═══ -->
                        <div id="panel-news" class="tab-panel hidden space-y-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold text-slate-900">News</h2>
                                <a href="/news.php" class="text-sm text-red-600 hover:underline font-medium">Open News Feed →</a>
                            </div>
                            <?php if (empty($newsResults)): ?>
                                <p class="text-sm text-slate-500 py-8 text-center">No news matches found.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($newsResults as $news):
                                        $score = (int)($news['shock_score'] ?? 0);
                                        $badgeClass = $score >= 8 ? 'bg-red-500 text-white' : ($score >= 5 ? 'bg-amber-500 text-white' : 'bg-slate-400 text-white');
                                        $badgeLabel = $score >= 8 ? 'BREAKING' : ($score >= 5 ? 'NOTABLE' : 'UPDATE');
                                    ?>
                                    <a href="<?= htmlspecialchars($news['url']) ?>" target="_blank" rel="noopener" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-red-300 hover:shadow-md transition-all">
                                        <div class="flex items-start gap-3">
                                            <span class="flex-shrink-0 px-2 py-0.5 rounded text-xs font-bold <?= $badgeClass ?>"><?= $score ?>/10 <?= $badgeLabel ?></span>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm font-semibold text-slate-900 line-clamp-2"><?= htmlspecialchars($news['ai_headline'] ?: $news['title']) ?></div>
                                                <?php if (!empty($news['ai_summary'])): ?>
                                                    <p class="text-sm text-slate-600 mt-1 line-clamp-2"><?= htmlspecialchars($news['ai_summary']) ?></p>
                                                <?php endif; ?>
                                                <div class="flex items-center gap-2 mt-1.5 text-xs text-slate-500">
                                                    <?= !empty($news['source_name']) ? '<span class="font-medium">' . htmlspecialchars($news['source_name']) . '</span><span class="text-slate-300">&middot;</span>' : '' ?>
                                                    <?= !empty($news['published_at']) ? date('M j, Y', strtotime($news['published_at'])) : '' ?>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ═══ PHOTOS TAB ═══ -->
                        <div id="panel-photos" class="tab-panel hidden space-y-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold text-slate-900">Photos</h2>
                                <a href="/photos.php?q=<?= urlencode($searchQuery) ?>" class="text-sm text-purple-600 hover:underline font-medium">Open in Photos →</a>
                            </div>
                            <?php if (empty($photoResults)): ?>
                                <p class="text-sm text-slate-500 py-8 text-center">No photo matches found.</p>
                            <?php else: ?>
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                    <?php foreach ($photoResults as $img): ?>
                                    <a href="/document.php?id=<?= (int)$img['id'] ?>" class="group relative aspect-square bg-slate-100 rounded-xl overflow-hidden border border-slate-200 hover:border-purple-300 hover:shadow-md transition-all">
                                        <img src="/serve.php?id=<?= (int)$img['id'] ?>" alt="<?= htmlspecialchars($img['title'] ?? '') ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                                            <p class="absolute bottom-2 left-2 right-2 text-xs font-semibold text-white line-clamp-2"><?= htmlspecialchars($img['title'] ?? 'Untitled') ?></p>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ═══ ENTITIES TAB ═══ -->
                        <div id="panel-entities" class="tab-panel hidden space-y-6">
                            <h2 class="text-lg font-bold text-slate-900">People & Entities</h2>
                            <?php if (empty($entityResults)): ?>
                                <p class="text-sm text-slate-500 py-8 text-center">No entity matches found.</p>
                            <?php else: ?>
                                <div class="flex flex-wrap gap-2 mb-6">
                                    <?php foreach ($entityResults as $ent):
                                        $typeColor = match($ent['type'] ?? '') { 'PERSON' => 'bg-purple-50 text-purple-700 border-purple-200', 'ORG' => 'bg-blue-50 text-blue-700 border-blue-200', 'LOCATION' => 'bg-green-50 text-green-700 border-green-200', default => 'bg-slate-50 text-slate-700 border-slate-200' };
                                        $icon = match($ent['type'] ?? '') { 'PERSON' => '👤', 'ORG' => '🏢', 'LOCATION' => '📍', default => '📌' };
                                    ?>
                                    <a href="/contacts.php?q=<?= urlencode($ent['name']) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-full text-sm font-medium border <?= $typeColor ?> hover:shadow-sm transition-all">
                                        <span><?= $icon ?></span>
                                        <?= htmlspecialchars($ent['name']) ?>
                                        <span class="text-xs opacity-50">(<?= $ent['doc_count'] ?>)</span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($entityDocResults)): ?>
                                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wide mb-3">Related Documents</h3>
                                <div class="space-y-3">
                                    <?php foreach ($entityDocResults as $doc): ?>
                                    <a href="/document.php?id=<?= (int)$doc['id'] ?>" class="block bg-white border border-slate-200 rounded-xl p-4 hover:border-amber-300 hover:shadow-md transition-all">
                                        <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($doc['title']) ?></div>
                                        <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($doc['data_set'] ?? 'Epstein Files') ?> &middot; <?= $doc['entity_matches'] ?> entity matches</div>
                                        <?php if (!empty($doc['ai_summary'])): ?>
                                            <p class="text-sm text-slate-600 mt-1.5 line-clamp-2"><?= htmlspecialchars(substr($doc['ai_summary'], 0, 200)) ?></p>
                                        <?php endif; ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <!-- ═══ AI MATCHES TAB ═══ -->
                        <?php if (!empty($semanticResults)): ?>
                        <div id="panel-ai" class="tab-panel hidden space-y-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-bold text-slate-900">AI-Ranked Matches</h2>
                                <span class="text-xs text-indigo-500 font-medium">Powered by embeddings</span>
                            </div>
                            <div class="space-y-3">
                                <?php foreach ($semanticResults as $ai):
                                    $targetUrl = $ai['type'] === 'document' ? '/document.php?id=' . (int)$ai['id'] : '/flight_logs.php?id=' . (int)$ai['id'];
                                ?>
                                <a href="<?= $targetUrl ?>" class="block bg-gradient-to-r from-indigo-50/50 to-white border border-indigo-100 rounded-xl p-4 hover:border-indigo-300 hover:shadow-md transition-all">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-xs text-indigo-500 font-semibold uppercase tracking-wide"><?= $ai['type'] === 'document' ? 'Document' : 'Flight' ?></div>
                                            <div class="text-base font-semibold text-slate-900">
                                                <?= $ai['type'] === 'document' ? htmlspecialchars($ai['title'] ?? 'Untitled') : htmlspecialchars(($ai['origin'] ?? '') . ' → ' . ($ai['destination'] ?? '')) ?>
                                            </div>
                                        </div>
                                        <span class="flex-shrink-0 text-xs font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg"><?= round(($ai['search_score'] ?? 0) * 100) ?>%</span>
                                    </div>
                                    <?php if (!empty($ai['snippet'])): ?>
                                        <p class="mt-2 text-sm text-slate-600 line-clamp-2"><?= htmlspecialchars($ai['snippet']) ?></p>
                                    <?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                    <!-- End Main Content Column -->

                    <!-- Right Sidebar (desktop only) -->
                    <aside class="hidden lg:block lg:col-span-4">
                        <div class="sticky top-40 space-y-6">

                            <!-- Open in App Links -->
                            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-3">Open in App</h3>
                                <div class="space-y-1">
                                    <a href="/drive.php?q=<?= urlencode($searchQuery) ?>" class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center text-sm group-hover:bg-blue-100 transition-colors">📄</div>
                                            <span class="text-sm font-medium text-slate-700 group-hover:text-blue-700">Drive</span>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500"><?= number_format($docTotalCount) ?></span>
                                    </a>
                                    <a href="/email_client.php?q=<?= urlencode($searchQuery) ?>" class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 bg-green-50 rounded-lg flex items-center justify-center text-sm group-hover:bg-green-100 transition-colors">✉️</div>
                                            <span class="text-sm font-medium text-slate-700 group-hover:text-green-700">Mail</span>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500"><?= number_format($emailTotalCount) ?></span>
                                    </a>
                                    <a href="/flight_logs.php?q=<?= urlencode($searchQuery) ?>" class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 bg-sky-50 rounded-lg flex items-center justify-center text-sm group-hover:bg-sky-100 transition-colors">✈️</div>
                                            <span class="text-sm font-medium text-slate-700 group-hover:text-sky-700">Flights</span>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500"><?= number_format($flightTotalCount) ?></span>
                                    </a>
                                    <a href="/news.php" class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 bg-red-50 rounded-lg flex items-center justify-center text-sm group-hover:bg-red-100 transition-colors">📰</div>
                                            <span class="text-sm font-medium text-slate-700 group-hover:text-red-700">News</span>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500"><?= number_format($newsTotalCount) ?></span>
                                    </a>
                                    <a href="/photos.php?q=<?= urlencode($searchQuery) ?>" class="flex items-center justify-between p-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 bg-purple-50 rounded-lg flex items-center justify-center text-sm group-hover:bg-purple-100 transition-colors">🖼️</div>
                                            <span class="text-sm font-medium text-slate-700 group-hover:text-purple-700">Photos</span>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500"><?= number_format($photoTotalCount) ?></span>
                                    </a>
                                </div>
                            </div>

                            <!-- Matching Entities (desktop sidebar) -->
                            <?php if (!empty($entityResults)): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-3">People & Entities</h3>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach (array_slice($entityResults, 0, 10) as $ent):
                                        $typeColor = match($ent['type'] ?? '') { 'PERSON' => 'bg-purple-50 text-purple-700', 'ORG' => 'bg-blue-50 text-blue-700', 'LOCATION' => 'bg-green-50 text-green-700', default => 'bg-slate-100 text-slate-700' };
                                    ?>
                                    <a href="/contacts.php?q=<?= urlencode($ent['name']) ?>" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium <?= $typeColor ?> hover:opacity-80 transition-opacity">
                                        <?= htmlspecialchars($ent['name']) ?>
                                        <span class="opacity-50"><?= $ent['doc_count'] ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Photo Spotlight -->
                            <?php if (!empty($photoResults)): ?>
                            <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-2xl border border-purple-200 p-5 shadow-sm">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-xs font-bold text-purple-900 uppercase tracking-wide">Photo Spotlight</h3>
                                    <button onclick="switchTab('photos')" class="text-xs text-purple-700 hover:underline font-medium">View all →</button>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <?php foreach (array_slice($photoResults, 0, 4) as $img): ?>
                                    <a href="/document.php?id=<?= (int)$img['id'] ?>" class="group relative aspect-square bg-white rounded-xl overflow-hidden border border-purple-200 hover:border-purple-400 hover:shadow-md transition-all">
                                        <img src="/serve.php?id=<?= (int)$img['id'] ?>" alt="<?= htmlspecialchars($img['title'] ?? '') ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
                                            <p class="absolute bottom-1.5 left-1.5 right-1.5 text-[10px] font-semibold text-white line-clamp-2"><?= htmlspecialchars($img['title'] ?? 'Untitled') ?></p>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Ask AI CTA -->
                            <a href="/ask.php?q=<?= urlencode($searchQuery) ?>" class="block bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl p-5 text-white shadow-sm hover:shadow-lg transition-all group">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-lg group-hover:bg-white/30 transition-colors">🤖</div>
                                    <div>
                                        <div class="text-sm font-bold">Ask Epstein AI</div>
                                        <div class="text-xs text-blue-100">Get AI-powered answers about "<?= htmlspecialchars(mb_substr($searchQuery, 0, 20)) ?><?= mb_strlen($searchQuery) > 20 ? '...' : '' ?>"</div>
                                    </div>
                                </div>
                            </a>

                            <!-- Support This Project -->
                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 rounded-2xl p-5 shadow-sm">
                                <h3 class="text-xs font-bold text-emerald-900 uppercase tracking-wide mb-3">Support This Project</h3>
                                <p class="text-xs text-slate-700 leading-relaxed mb-4">
                                    Help keep Epstein Suite online. Your donation covers hosting, OCR, and AI costs.
                                </p>
                                <a href="https://buy.stripe.com/6oU4gB0vMbasdXqat82VG02" target="_blank" rel="noopener" class="block text-center rounded-lg bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 hover:opacity-95 transition">
                                    ❤️ Donate via Stripe
                                </a>
                                <p class="mt-3 text-[10px] text-slate-600 text-center">
                                    One-time or recurring accepted
                                </p>
                            </div>

                        </div>
                    </aside>

                </div>
            </div>

            <!-- Tab Switching JS -->
            <script>
            (function(){
                var tabs=document.querySelectorAll('.tab-btn');
                var panels=document.querySelectorAll('.tab-panel');
                function switchTab(id){
                    tabs.forEach(function(b){b.dataset.active=b.dataset.tab===id?'true':'false';});
                    panels.forEach(function(p){p.classList.toggle('hidden',p.id!=='panel-'+id);});
                    history.replaceState(null,'',location.pathname+location.search+(id!=='all'?'#'+id:''));
                    var bar=document.getElementById('search-tabs');
                    if(bar)bar.scrollIntoView({behavior:'smooth',block:'nearest'});
                }
                tabs.forEach(function(b){b.addEventListener('click',function(){switchTab(b.dataset.tab);});});
                var hash=location.hash.replace('#','');
                if(hash&&document.getElementById('panel-'+hash))switchTab(hash);
                window.switchTab=switchTab;
            })();
            </script>

            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php require_once __DIR__ . '/includes/footer_suite.php'; ?>

<?php if ($searchQuery): ?>
<script>
document.addEventListener('click', function(e) {
    var panel = document.getElementById('panel-photos');
    if (!panel) return;
    var link = e.target.closest('#panel-photos a[href*="document.php?id="]');
    if (!link) return;
    var match = link.href.match(/document\.php\?id=(\d+)/);
    if (!match) return;
    var data = JSON.stringify({document_id: parseInt(match[1], 10), referrer: 'search'});
    if (navigator.sendBeacon) {
        navigator.sendBeacon('/api/log_photo_view.php', new Blob([data], {type: 'application/json'}));
    }
});
</script>
<?php endif; ?>

</body>
</html>
