<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ai_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$question = trim((string)($payload['question'] ?? ''));
if (mb_strlen($question) < 8) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please provide a longer question.']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$sessionToken = (string)($payload['session_token'] ?? ($_COOKIE['ask_session'] ?? ''));
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

try {
    $session = ai_get_or_create_session($pdo, $sessionToken, $ip, $userAgent);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to create session']);
    exit;
}

$sessionToken = $session['session_token'];
$cookieSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('ask_session', $sessionToken, [
    'expires' => time() + 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => $cookieSecure,
    'httponly' => false,
    'samesite' => 'Lax',
]);

try {
    $userMessageId = ai_log_message($pdo, (int)$session['id'], 'user', $question, [
        'ip_hash' => ai_hash_ip($ip),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to log question']);
    exit;
}

$retrievalStart = microtime(true);
$preparedQuery = ai_prepare_question($question);
$contextResult = ai_retrieve_context($pdo, $question, 6, $preparedQuery);
$retrievalMs = (int)round((microtime(true) - $retrievalStart) * 1000);
$contextChunks = $contextResult['chunks'];
$documentsMap = $contextResult['documents'];
$docSummaryGenerated = false;
$docSummaryDocId = null;

$conversationHistory = ai_get_recent_history($pdo, (int)$session['id'], 12, $userMessageId);
$sanitizedHistory = ai_sanitize_history($conversationHistory);

$apiKey = env_value('OPENAI_API_KEY');
if (!$apiKey) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'AI service unavailable']);
    exit;
}

$intent = $preparedQuery['intent'] ?? null;
if ($intent === 'doc_followup') {
    $docSummaryDocId = ai_extract_doc_id_from_subject($preparedQuery['subject'] ?? null);
    if ($docSummaryDocId) {
        if (!ai_document_has_summary($pdo, $docSummaryDocId)) {
            $docSummaryGenerated = ai_generate_document_summary($pdo, $docSummaryDocId, $apiKey);
            if ($docSummaryGenerated) {
                $retrievalStart = microtime(true);
                $contextResult = ai_retrieve_context($pdo, $question, 6, $preparedQuery);
                $retrievalMs = (int)round((microtime(true) - $retrievalStart) * 1000);
                $contextChunks = $contextResult['chunks'];
                $documentsMap = $contextResult['documents'];
            }
        }
    }
}

$answerHtml = '';
$followUps = [];
$modelCitations = [];
$entitiesFound = [];
$usage = ['input_tokens' => null, 'output_tokens' => null];
$latencyMs = 0;
$llmModel = null;
$handledInHouse = false;

if (empty($contextChunks)) {
    $contextChunks = [[
        'document_id' => null,
        'page_number' => null,
        'title' => 'Archive',
        'data_set' => null,
        'snippet' => 'Archive context not yet available; rely on realtime web intelligence plus institutional knowledge.',
        'score' => null,
        'source' => 'system',
    ]];
}

if ($intent === 'flight_lookup') {
    $flightRows = ai_lookup_flights($pdo, $preparedQuery['subject'] ?? null);
    $flightAnswer = ai_render_flight_answer($flightRows);
    if ($flightAnswer) {
        $handledInHouse = true;
        $answerHtml = $flightAnswer['answer_html'];
        $followUps = $flightAnswer['follow_up_questions'] ?? [];
        $modelCitations = $flightAnswer['citations'] ?? [];
        $entitiesFound = $flightAnswer['entities'] ?? [];
        $llmModel = 'hybrid:flight_lookup';
    }
}

if (!$handledInHouse) {
    $llmStart = microtime(true);
    $llmResponse = ai_fetch_answer_from_llm($apiKey, $question, $contextChunks, $sanitizedHistory);
    $latencyMs = (int)round((microtime(true) - $llmStart) * 1000);

    if (!$llmResponse['ok']) {
        http_response_code($llmResponse['status'] ?? 502);
        echo json_encode([
            'ok' => false,
            'error' => $llmResponse['error'] ?? 'Unable to get AI answer',
            'session_token' => $sessionToken,
        ]);
        exit;
    }

    $answerPayload = $llmResponse['payload'];
    $entitiesFound = $llmResponse['entities'] ?? [];
    $answerHtml = $answerPayload['answer_html'] ?? $answerPayload['answer_markdown'] ?? $answerPayload['answer_text'] ?? '';
    $followUps = $answerPayload['follow_up_questions'] ?? [];
    $modelCitations = $answerPayload['citations'] ?? [];
    $usage = $llmResponse['usage'];
    $llmModel = $llmResponse['model'] ?? null;
}

$metadata = [
    'retrieval_ms' => $retrievalMs,
    'llm_latency_ms' => $latencyMs,
    'context_doc_ids' => array_values(array_unique(array_map(fn ($chunk) => (int)$chunk['document_id'], $contextChunks))),
];

try {
    $assistantMessageId = ai_log_message(
        $pdo,
        (int)$session['id'],
        'assistant',
        $answerHtml,
        $metadata,
        $usage['input_tokens'] ?? null,
        $usage['output_tokens'] ?? null,
        $latencyMs,
        $llmModel
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to log AI response']);
    exit;
}

$citationsForLogging = normalize_citations($modelCitations, $contextChunks);
ai_log_citations($pdo, $assistantMessageId, $citationsForLogging);

$response = [
    'ok' => true,
    'session_token' => $sessionToken,
    'answer_html' => $answerHtml,
    'citations' => $citationsForLogging,
    'follow_up_questions' => $followUps,
    'context' => $contextChunks,
    'documents' => array_values($documentsMap),
    'usage' => $usage,
    'entities' => $entitiesFound,
];

if (!empty($citationsForLogging)) {
    $extraFollowUps = [];
    foreach ($citationsForLogging as $cite) {
        $docId = $cite['document_id'];
        $extraFollowUps[] = "Show more from Doc #{$docId}";
        if (!empty($documentsMap[$docId]['title'])) {
            $extraFollowUps[] = "Summarize more from {$documentsMap[$docId]['title']}";
        }
    }
    $response['follow_up_questions'] = array_slice(array_values(array_unique(array_merge($response['follow_up_questions'] ?? [], $extraFollowUps))), 0, 6);
}

echo json_encode($response);

function ai_retrieve_context(PDO $pdo, string $query, int $limit = 6, ?array $prepared = null): array
{
    $docChunks = [];
    $documents = [];
    $like = '%' . $query . '%';
    $prepared = $prepared ?? [];
    $keywords = $prepared['keywords'] ?? [];
    $synonyms = $prepared['synonyms'] ?? [];
    $searchTerms = array_values(array_unique(array_filter(array_merge($keywords, $synonyms))));
    $termLimit = array_slice($searchTerms, 0, 8);

    try {
        $stmt = $pdo->prepare(
            "SELECT d.id, d.title, d.data_set, d.file_type, d.ai_summary, d.source_url,
                    MATCH(d.title, d.description, d.ai_summary) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevance
             FROM documents d
             WHERE MATCH(d.title, d.description, d.ai_summary) AGAINST (:q IN NATURAL LANGUAGE MODE)
             ORDER BY relevance DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':q', $query, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $docRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $docRows = [];
    }

    if (empty($docRows)) {
        $stmt = $pdo->prepare(
            "SELECT d.id, d.title, d.data_set, d.file_type, d.ai_summary, d.source_url, 0 AS relevance
             FROM documents d
             WHERE d.title LIKE :like_title
                OR d.description LIKE :like_desc
                OR d.ai_summary LIKE :like_summary
             ORDER BY d.updated_at DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':like_title', $like, PDO::PARAM_STR);
        $stmt->bindValue(':like_desc', $like, PDO::PARAM_STR);
        $stmt->bindValue(':like_summary', $like, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $docRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (count($docRows) < $limit && !empty($termLimit)) {
        $keywordConditions = [];
        $keywordParams = [];
        foreach ($termLimit as $index => $kw) {
            $paramTitle = ':kw_doc_title_' . $index;
            $paramDesc = ':kw_doc_desc_' . $index;
            $paramSummary = ':kw_doc_sum_' . $index;
            $keywordConditions[] = "(d.title LIKE {$paramTitle} OR d.description LIKE {$paramDesc} OR d.ai_summary LIKE {$paramSummary})";
            $keywordParams[$paramTitle] = '%' . $kw . '%';
            $keywordParams[$paramDesc] = '%' . $kw . '%';
            $keywordParams[$paramSummary] = '%' . $kw . '%';
        }

        if ($keywordConditions) {
            $sql = "SELECT d.id, d.title, d.data_set, d.file_type, d.ai_summary, d.source_url, 0 AS relevance
                    FROM documents d
                    WHERE " . implode(' OR ', $keywordConditions) . "
                    ORDER BY d.updated_at DESC
                    LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            foreach ($keywordParams as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $docRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    foreach ($docRows as $row) {
        $docId = (int)$row['id'];
        $documents[$docId] = [
            'id' => $docId,
            'title' => $row['title'],
            'data_set' => $row['data_set'] ?? null,
            'file_type' => $row['file_type'] ?? null,
            'source_url' => $row['source_url'] ?? null,
        ];
        $snippet = trim((string)$row['ai_summary']);
        if ($snippet === '') {
            $snippet = 'No AI summary available yet.';
        } elseif (mb_strlen($snippet) > 500) {
            $snippet = mb_substr($snippet, 0, 500) . '…';
        }

        $docChunks[] = [
            'document_id' => $docId,
            'page_number' => null,
            'title' => $row['title'],
            'data_set' => $row['data_set'],
            'snippet' => $snippet,
            'score' => isset($row['relevance']) ? (float)$row['relevance'] : null,
            'source' => 'summary',
        ];
    }

    $ocrChunks = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT p.document_id, p.page_number,
                    SUBSTRING(p.ocr_text, 1, 800) AS snippet,
                    MATCH(p.ocr_text) AGAINST (:q IN NATURAL LANGUAGE MODE) AS relevance
             FROM pages p
             WHERE MATCH(p.ocr_text) AGAINST (:q IN NATURAL LANGUAGE MODE)
             ORDER BY relevance DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':q', $query, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ocrRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $ocrRows = [];
    }

    if (empty($ocrRows)) {
        $stmt = $pdo->prepare(
            "SELECT p.document_id, p.page_number, SUBSTRING(p.ocr_text, 1, 800) AS snippet, 0 AS relevance
             FROM pages p
             WHERE p.ocr_text LIKE :like_ocr
             ORDER BY p.page_number ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':like_ocr', $like, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ocrRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (count($ocrRows) < $limit && !empty($termLimit)) {
        $keywordConditions = [];
        $keywordParams = [];
        foreach ($termLimit as $index => $kw) {
            $param = ':kw_ocr_' . $index;
            $keywordConditions[] = "p.ocr_text LIKE {$param}";
            $keywordParams[$param] = '%' . $kw . '%';
        }

        if ($keywordConditions) {
            $sql = "SELECT p.document_id, p.page_number, SUBSTRING(p.ocr_text, 1, 800) AS snippet, 0 AS relevance
                    FROM pages p
                    WHERE " . implode(' OR ', $keywordConditions) . "
                    ORDER BY p.page_number ASC
                    LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            foreach ($keywordParams as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $ocrRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    foreach ($ocrRows as $row) {
        $docId = (int)$row['document_id'];
        if (!isset($documents[$docId])) {
            $docInfo = $pdo->prepare('SELECT id, title, data_set, file_type, source_url FROM documents WHERE id = :id LIMIT 1');
            $docInfo->execute([':id' => $docId]);
            $docRow = $docInfo->fetch(PDO::FETCH_ASSOC);
            if ($docRow) {
                $documents[$docId] = [
                    'id' => $docId,
                    'title' => $docRow['title'],
                    'data_set' => $docRow['data_set'],
                    'file_type' => $docRow['file_type'],
                    'source_url' => $docRow['source_url'],
                ];
            } else {
                $documents[$docId] = ['id' => $docId, 'title' => 'Document #' . $docId];
            }
        }

        $snippet = trim((string)$row['snippet']);
        if ($snippet === '') {
            continue;
        }

        $ocrChunks[] = [
            'document_id' => $docId,
            'page_number' => (int)$row['page_number'],
            'title' => $documents[$docId]['title'] ?? ('Document #' . $docId),
            'data_set' => $documents[$docId]['data_set'] ?? null,
            'snippet' => $snippet,
            'score' => isset($row['relevance']) ? (float)$row['relevance'] : null,
            'source' => 'ocr',
        ];
    }

    $specialChunks = [];
    if (!empty($prepared['intent']) && !empty($prepared['subject'])) {
        $specialChunks = ai_fetch_special_context($pdo, $prepared['intent'], $prepared['subject']);
        if ($specialChunks) {
            foreach ($specialChunks as $specialChunk) {
                $docId = (int)($specialChunk['document_id'] ?? 0);
                if ($docId > 0 && !isset($documents[$docId])) {
                    if (!empty($specialChunk['title']) || !empty($specialChunk['data_set'])) {
                        $documents[$docId] = [
                            'id' => $docId,
                            'title' => $specialChunk['title'] ?? ('Document #' . $docId),
                            'data_set' => $specialChunk['data_set'] ?? null,
                            'file_type' => null,
                            'source_url' => null,
                        ];
                    } else {
                        $docInfo = $pdo->prepare('SELECT id, title, data_set, file_type, source_url FROM documents WHERE id = :id LIMIT 1');
                        $docInfo->execute([':id' => $docId]);
                        $docRow = $docInfo->fetch(PDO::FETCH_ASSOC);
                        if ($docRow) {
                            $documents[$docId] = [
                                'id' => $docId,
                                'title' => $docRow['title'],
                                'data_set' => $docRow['data_set'],
                                'file_type' => $docRow['file_type'],
                                'source_url' => $docRow['source_url'],
                            ];
                        }
                    }
                }
            }
        }
    }

    $chunks = array_merge($specialChunks ?: [], $docChunks);
    $chunks = array_slice(array_merge($chunks, $ocrChunks), 0, $limit);

    return [
        'chunks' => $chunks,
        'documents' => $documents,
    ];
}

function ai_fetch_special_context(PDO $pdo, ?string $intent, ?string $subject): array
{
    $chunks = [];

    if ($intent === 'date_lookup' && $subject === 'jeffrey epstein death') {
        $needles = ['August 10, 2019', 'Aug. 10, 2019'];
        foreach ($needles as $needle) {
            $stmt = $pdo->prepare("
                SELECT p.document_id, p.page_number,
                       CASE
                           WHEN LOCATE(:needleExact, p.ocr_text) > 0 THEN SUBSTRING(p.ocr_text, GREATEST(LOCATE(:needleExact, p.ocr_text) - 120, 1), 600)
                           ELSE SUBSTRING(p.ocr_text, 1, 600)
                       END AS snippet
                FROM pages p
                WHERE p.ocr_text LIKE :needleLike
                ORDER BY p.document_id ASC
                LIMIT 1
            ");
            $stmt->execute([
                ':needleExact' => $needle,
                ':needleLike' => '%' . $needle . '%',
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty(trim((string)$row['snippet']))) {
                $chunks[] = [
                    'document_id' => (int)$row['document_id'],
                    'page_number' => (int)$row['page_number'],
                    'title' => 'Document #' . (int)$row['document_id'],
                    'data_set' => null,
                    'snippet' => trim($row['snippet']),
                    'score' => 1.0,
                    'source' => 'special',
                ];
            }
        }
    }

    if ($intent === 'relationship_lookup' && $subject === 'masseuse_contact') {
        $stmt = $pdo->prepare("
            SELECT p.document_id, p.page_number,
                   SUBSTRING(p.ocr_text, 1, 900) AS snippet
            FROM pages p
            WHERE p.ocr_text LIKE '%Masseuse%' AND p.ocr_text LIKE '%contact%'
            ORDER BY p.document_id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty(trim((string)$row['snippet']))) {
            $chunks[] = [
                'document_id' => (int)$row['document_id'],
                'page_number' => (int)$row['page_number'],
                'title' => 'Document #' . (int)$row['document_id'],
                'data_set' => null,
                'snippet' => trim($row['snippet']),
                'score' => 1.0,
                'source' => 'special',
            ];
        }
    }

    if ($intent === 'fact_lookup' && $subject === 'florida_plea_agreement') {
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.data_set, d.ai_summary,
                   SUBSTRING(d.ai_summary, 1, 600) AS snippet
            FROM documents d
            WHERE d.ai_summary LIKE '%Florida%' AND d.ai_summary LIKE '%plea agreement%'
            ORDER BY d.created_at ASC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty(trim((string)$row['snippet']))) {
            $chunks[] = [
                'document_id' => (int)$row['id'],
                'page_number' => null,
                'title' => $row['title'],
                'data_set' => $row['data_set'],
                'snippet' => trim($row['snippet']),
                'score' => 1.0,
                'source' => 'special',
            ];
        }
    }

    if ($intent === 'fact_lookup' && $subject === 'maxwell_trial') {
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.data_set,
                   SUBSTRING(d.ai_summary, 1, 600) AS snippet
            FROM documents d
            WHERE d.ai_summary LIKE '%Maxwell%' AND d.ai_summary LIKE '%trial%'
            ORDER BY d.updated_at DESC
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty(trim((string)$row['snippet']))) {
            $chunks[] = [
                'document_id' => (int)$row['id'],
                'page_number' => null,
                'title' => $row['title'],
                'data_set' => $row['data_set'],
                'snippet' => trim($row['snippet']),
                'score' => 1.0,
                'source' => 'special',
            ];
        }
    }

    if ($intent === 'flight_lookup') {
        $parts = array_filter(explode('_', (string)$subject));
        $peopleFilters = [];
        $year = null;
        foreach ($parts as $part) {
            if (preg_match('/^\d{4}$/', $part)) {
                $year = (int)$part;
            } elseif (in_array($part, ['epstein', 'jeffrey'], true)) {
                $peopleFilters['epstein'] = '%Epstein%';
            } elseif (in_array($part, ['maxwell', 'ghislaine'], true)) {
                $peopleFilters['maxwell'] = '%Maxwell%';
            }
        }

        $conditions = [];
        $params = [];
        if ($year) {
            $conditions[] = 'YEAR(f.flight_date) = :year';
            $params[':year'] = $year;
        }
        $idx = 0;
        foreach ($peopleFilters as $label => $like) {
            $paramName = ':person' . $idx;
            $conditions[] = "EXISTS (
                SELECT 1 FROM passengers p{$idx}
                WHERE p{$idx}.flight_id = f.id AND p{$idx}.name LIKE {$paramName}
            )";
            $params[$paramName] = $like;
            $idx++;
        }

        $whereSql = $conditions ? implode(' AND ', $conditions) : '1=1';
        $sql = "
            SELECT f.id, f.document_id, f.flight_date, f.origin, f.destination, f.aircraft,
                   GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS passenger_list
            FROM flight_logs f
            LEFT JOIN passengers p ON p.flight_id = f.id
            WHERE {$whereSql}
            GROUP BY f.id
            ORDER BY f.flight_date ASC
            LIMIT 6
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            if (!$row['flight_date'] || !$row['origin'] || !$row['destination']) {
                continue;
            }
            $docId = isset($row['document_id']) ? (int)$row['document_id'] : 0;
            $passengers = $row['passenger_list'] ? explode(', ', $row['passenger_list']) : [];
            $passengerPreview = $passengers ? implode(', ', array_slice($passengers, 0, 6)) : 'Passengers not recorded';
            $snippet = sprintf(
                'Flight on %s from %s → %s (%s). Passengers: %s.',
                date('M d, Y', strtotime($row['flight_date'])),
                $row['origin'],
                $row['destination'],
                $row['aircraft'] ?: 'Aircraft unknown',
                $passengerPreview
            );
            $chunks[] = [
                'document_id' => $docId > 0 ? $docId : ($row['id'] * -1),
                'page_number' => null,
                'title' => 'Flight Manifest #' . $row['id'],
                'data_set' => 'Flight Logs',
                'snippet' => $snippet,
                'score' => 1.0,
                'source' => 'flight_log',
            ];
        }
    }

    return $chunks;
}

function ai_fetch_answer_from_llm(string $apiKey, string $question, array $chunks, array $history = []): array
{
    $contextBlocks = [];
    $mentionedEntities = [];
    foreach ($chunks as $index => $chunk) {
        if (!empty($chunk['snippet'])) {
            if (preg_match_all('/([A-Z][a-z]+\s+[A-Z][a-z]+)/u', $chunk['snippet'], $names)) {
                foreach ($names[1] as $name) {
                    $mentionedEntities[] = trim($name);
                }
            }
        }
        $contextBlocks[] = sprintf(
            "[%d] Document #%d%s%s\nSource: %s\nSnippet: %s",
            $index + 1,
            $chunk['document_id'],
            isset($chunk['page_number']) && $chunk['page_number'] ? ' (Page ' . $chunk['page_number'] . ')' : '',
            $chunk['title'] ? ' — ' . $chunk['title'] : '',
            strtoupper($chunk['source']),
            $chunk['snippet']
        );
    }
    $contextText = implode("\n\n", $contextBlocks);

    $messages = [
        [
            'role' => 'system',
            'content' => implode("\n", [
                'You are ChatGPT embedded inside Ask Epstein Files, acting as a calm, expert researcher for journalists and investigators.',
                'Always sound confident, executive-ready, and collaborative—never condescending, never implying the user asked a bad question.',
                'CRITICAL: You must ALWAYS provide a substantive, concrete answer. NEVER say "The context does not provide" or "I recommend reviewing documents."',
                'If the internal archive context is thin or missing, immediately use your web search tool to find public reporting and provide a real answer.',
                'Combine internal archive evidence with web search results to give complete, helpful answers every time.',
                'Cite internal evidence as Doc #123 · p4. If you reference vetted open-web material, cite it as Web Source (domain).',
                'NEVER punt to the user. NEVER suggest they review documents without giving them actual information first.',
                'Output strict JSON with keys: answer_html (HTML allowed), citations[] (document_id, page_number, quote, url?), follow_up_questions[] (short questions).',
            ]),
        ],
    ];

    foreach ($history as $entry) {
        $messages[] = [
            'role' => $entry['role'],
            'content' => $entry['content'],
        ];
    }

    $messages[] = [
        'role' => 'user',
        'content' => "Question: {$question}\n\nContext:\n{$contextText}\n\nReturn JSON with:\n- answer_html (HTML allowed, cite as <sup>[Doc #123]</sup>)\n- citations (list of {document_id, page_number, quote, url?})\n- follow_up_questions (array of short strings)\n\nIMPORTANT: Provide a concrete, substantive answer. If the archive context above is insufficient, use your web search tool to find public reporting and combine it with any available archive evidence. Never tell the user to review documents without giving them actual information."
    ];

    $enableWebSearch = filter_var(env_value('OPENAI_ENABLE_WEB_SEARCH') ?? '1', FILTER_VALIDATE_BOOLEAN);

    $inputBlocks = array_map(
        fn($message) => [
            'role' => $message['role'],
            'content' => [
                [
                    'type' => $message['role'] === 'assistant' ? 'output_text' : 'input_text',
                    'text' => $message['content'],
                ],
            ],
        ],
        $messages
    );

    $primaryModel = env_value('OPENAI_MODEL') ?: 'gpt-4o-mini';
    $fallbackModel = env_value('OPENAI_FALLBACK_MODEL') ?: 'gpt-5-nano';
    $modelsToTry = array_values(array_unique(array_filter([$primaryModel, $fallbackModel])));

    $basePayload = [
        'temperature' => 0.2,
        'max_output_tokens' => 2000,
        'input' => $inputBlocks,
    ];
    $maxAttempts = max(1, min(5, (int)(env_value('OPENAI_RETRY_ATTEMPTS') ?: 3)));
    $throttleStatuses = [0, 408, 409, 422, 429, 500, 502, 503, 504, 524];
    $result = null;
    $modelUsed = null;

    foreach ($modelsToTry as $model) {
        $payload = $basePayload + ['model' => $model];
        $result = null;

        $payload = $basePayload + ['model' => $model];
        $toolsEnabled = $enableWebSearch && isset($payload['tools']);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = openai_responses_request($apiKey, $payload);

            if ($result['ok']) {
                $modelUsed = $model;
                break 2;
            }

            $status = (int)($result['status'] ?? 0);
            $errorLower = strtolower((string)($result['error'] ?? ''));

            if (
                $toolsEnabled
                && isset($payload['tools'])
                && $status === 400
                && str_contains($errorLower, 'web_search')
            ) {
                unset($payload['tools'], $payload['tool_choice']);
                $toolsEnabled = false;
                $attempt--;
                continue;
            }

            $looksThrottled = in_array($status, $throttleStatuses, true)
                || str_contains($errorLower, 'temporarily')
                || str_contains($errorLower, 'again later')
                || str_contains($errorLower, 'retry');

            if ($looksThrottled && $attempt < $maxAttempts) {
                $delayMs = (int)(pow(2, $attempt - 1) * 250 + random_int(0, 150));
                usleep($delayMs * 1000);
                continue;
            }

            break;
        }
    }

    if (!$result || !$result['ok']) {
        $status = (int)($result['status'] ?? 0);
        if ($status === 400 || in_array($status, $throttleStatuses, true)) {
            return [
                'ok' => false,
                'status' => 503,
                'error' => 'LLM temporarily rejected the request. Please retry.',
            ];
        }
        return $result ?? ['ok' => false, 'status' => 502, 'error' => 'Unknown AI failure'];
    }

    $decoded = $result['decoded'];

    $content = null;
    $output = $decoded['output'] ?? [];
    if (is_array($output)) {
        foreach ($output as $block) {
            $items = $block['content'] ?? [];
            foreach ($items as $item) {
                if (($item['type'] ?? '') === 'output_text' && isset($item['text'])) {
                    $content = (string)$item['text'];
                    break 2;
                }
                if (($item['type'] ?? '') === 'text' && isset($item['text'])) {
                    $content = (string)$item['text'];
                    break 2;
                }
            }
        }
    }

    if ($content === null && isset($decoded['choices'][0]['message']['content'])) {
        $content = (string)$decoded['choices'][0]['message']['content'];
    }

    if (is_string($content)) {
        $trimmed = trim($content);
        if (preg_match('/^```json\\s*(.*?)\\s*```$/is', $trimmed, $m)) {
            $content = trim($m[1]);
        } elseif (preg_match('/^```\\s*(.*?)\\s*```$/is', $trimmed, $m)) {
            $content = trim($m[1]);
        }
    }

    $json = $content !== null ? json_decode($content, true) : null;
    if (!is_array($json)) {
        $json = [
            'answer_html' => nl2br(htmlspecialchars($content)),
            'citations' => [],
            'follow_up_questions' => [],
        ];
    }

    return [
        'ok' => true,
        'payload' => $json,
        'usage' => $decoded['usage'] ?? [],
        'model' => $decoded['model'] ?? $modelUsed,
        'entities' => array_values(array_unique($mentionedEntities)),
    ];
}
function normalize_citations(array $modelCitations, array $contextChunks): array
{
    $normalized = [];
    foreach ($modelCitations as $cit) {
        $docId = isset($cit['document_id']) ? (int)$cit['document_id'] : null;
        if (!$docId) {
            continue;
        }
        $normalized[] = [
            'document_id' => $docId,
            'page_number' => isset($cit['page_number']) ? (int)$cit['page_number'] : null,
            'quote' => $cit['quote'] ?? null,
        ];
    }

    if ($normalized) {
        return $normalized;
    }

    // fallback to context chunks if model omitted citations
    foreach ($contextChunks as $chunk) {
        $normalized[] = [
            'document_id' => (int)$chunk['document_id'],
            'page_number' => $chunk['page_number'] ?? null,
            'quote' => mb_substr($chunk['snippet'], 0, 200),
        ];
    }

    return array_slice($normalized, 0, 10);
}
