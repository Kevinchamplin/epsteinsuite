<?php
declare(strict_types=1);

/**
 * Helper utilities for Ask Epstein Files logging + sessions.
 */

/**
 * Generate a UUID v4 string without requiring ramsey/uuid.
 */
function ai_generate_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    $hex = bin2hex($data);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function ai_sanitize_history($history): array
{
    if (!is_array($history)) {
        return [];
    }

    $allowedRoles = ['user', 'assistant'];
    $cleanHistory = [];

    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $role = strtolower((string)($entry['role'] ?? ''));
        if (!in_array($role, $allowedRoles, true)) {
            continue;
        }

        $content = trim((string)($entry['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $cleanHistory[] = [
            'role' => $role,
            'content' => mb_substr($content, 0, 1200),
        ];

        if (count($cleanHistory) >= 8) {
            break;
        }
    }

    return $cleanHistory;
}

function ai_hash_ip(?string $ip): ?string
{
    if (!$ip) {
        return null;
    }
    return hash('sha256', $ip);
}

function ai_log_debug(string $label, array $payload = []): void
{
    $logFile = __DIR__ . '/../storage/logs/ask_ai.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $entry = [
        'timestamp' => date('c'),
        'label' => $label,
        'data' => $payload,
    ];
    @file_put_contents(
        $logFile,
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND
    );
}

function openai_responses_request(string $apiKey, array $payload): array
{
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $result = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($ch);

    if ($result === false) {
        ai_log_debug('openai_http_error', [
            'status' => 0,
            'error' => $curlErr,
            'model' => $payload['model'] ?? null,
        ]);
        return ['ok' => false, 'error' => 'LLM request failed', 'status' => 502, 'curl_error' => $curlErr];
    }

    $decoded = json_decode($result, true);
    if ($status >= 400 || !isset($decoded['output']) || empty($decoded['output'])) {
        ai_log_debug('openai_api_error', [
            'status' => $status,
            'model' => $payload['model'] ?? null,
            'error' => $decoded['error']['message'] ?? 'Unknown error',
            'response_excerpt' => mb_substr($result, 0, 2000),
        ]);
        return ['ok' => false, 'error' => $decoded['error']['message'] ?? 'LLM returned an error', 'status' => $status, 'decoded' => $decoded];
    }

    ai_log_debug('openai_api_success', [
        'status' => $status,
        'model' => $payload['model'] ?? null,
        'usage' => $decoded['usage'] ?? null,
    ]);

    return ['ok' => true, 'decoded' => $decoded, 'status' => $status];
}

function ai_lookup_flights(PDO $pdo, ?string $subject): array
{
    if (!$subject) {
        return [];
    }

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
    foreach ($peopleFilters as $like) {
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
        LIMIT 10
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ai_render_flight_answer(array $rows): ?array
{
    if (empty($rows)) {
        return null;
    }

    usort($rows, function ($a, $b) {
        return strcmp((string)$a['flight_date'], (string)$b['flight_date']);
    });

    $items = [];
    $citations = [];
    $entities = [];

    foreach ($rows as $row) {
        $date = $row['flight_date'] ? date('M j, Y', strtotime($row['flight_date'])) : 'Unknown date';
        $origin = $row['origin'] ?: 'Unknown origin';
        $destination = $row['destination'] ?: 'Unknown destination';
        $aircraft = $row['aircraft'] ?: 'Aircraft unknown';
        $passengers = array_filter(array_map('trim', explode(',', (string)$row['passenger_list'])));
        $passengerPreview = $passengers ? implode(', ', array_slice($passengers, 0, 8)) : 'Passengers not recorded';
        foreach (array_slice($passengers, 0, 8) as $name) {
            if ($name !== '') {
                $entities[] = $name;
            }
        }

        $items[] = sprintf(
            '<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-slate-900">%s</p>
                    <span class="text-xs font-mono text-slate-500">%s</span>
                </div>
                <p class="text-sm text-slate-700 mt-1">%s → %s</p>
                <p class="text-xs text-slate-500 mt-2"><strong>Passengers:</strong> %s</p>
            </div>',
            htmlspecialchars($date, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($aircraft, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($origin, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($passengerPreview, ENT_QUOTES, 'UTF-8')
        );

        $docId = isset($row['document_id']) ? (int)$row['document_id'] : 0;
        if ($docId > 0) {
            $citations[] = [
                'document_id' => $docId,
                'page_number' => null,
                'quote' => sprintf(
                    'Flight on %s from %s to %s (%s). Passengers: %s.',
                    $date,
                    $origin,
                    $destination,
                    $aircraft,
                    $passengerPreview
                ),
            ];
        }
    }

    $itemsHtml = implode("\n", $items);
    $html = <<<HTML
        <div class="space-y-4">
            <div>
                <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Flight manifests</p>
                <h3 class="text-lg font-semibold text-slate-900">Flights linking Epstein & Maxwell</h3>
                <p class="text-sm text-slate-600 mt-1">Summaries below cite the passengers recorded in the manifests. Each entry includes the date, route, aircraft, and the first few listed passengers.</p>
            </div>
            <div class="grid gap-3">
                {$itemsHtml}
            </div>
        </div>
    HTML;

    $followUps = array_map(
        fn($row) => 'Show document for flight on ' . ($row['flight_date'] ? date('M j, Y', strtotime($row['flight_date'])) : 'Unknown date'),
        $rows
    );

    return [
        'answer_html' => $html,
        'citations' => array_values(array_unique($citations, SORT_REGULAR)),
        'follow_up_questions' => array_slice(array_values(array_unique($followUps)), 0, 4),
        'entities' => array_slice(array_values(array_unique($entities)), 0, 8),
    ];
}

function ai_prepare_question(string $question): array
{
    $clean = mb_strtolower(trim($question));
    $normalized = preg_replace('/[^a-z0-9\s]/u', ' ', $clean);
    $tokens = array_filter(array_map('trim', preg_split('/\s+/', $normalized) ?: []));
    $keywords = [];
    foreach ($tokens as $token) {
        if (mb_strlen($token) >= 3) {
            $keywords[] = $token;
        }
    }
    $keywords = array_values(array_unique($keywords));

    $synonymMap = [
        'flight' => ['manifest', 'plane', 'trip'],
        'flights' => ['manifest', 'plane', 'trips'],
        'mail' => ['emails', 'correspondence'],
        'email' => ['mail', 'correspondence'],
        'document' => ['exhibit', 'file', 'pdf'],
        'money' => ['payments', 'wire', 'transfer'],
        'died' => ['death', 'deceased', 'died'],
        'die' => ['death'],
        'cross' => ['link', 'match', 'overlap'],
        'reference' => ['cite', 'link', 'match'],
        'list' => ['ledger', 'registry'],
        'maxwell' => ['ghislaine', 'ghislaine maxwell'],
        'plea' => ['agreement', 'deal'],
        'estate' => ['trust', 'assets'],
    ];
    $synonyms = [];
    foreach ($keywords as $keyword) {
        if (isset($synonymMap[$keyword])) {
            $synonyms = array_merge($synonyms, $synonymMap[$keyword]);
        }
    }
    $synonyms = array_values(array_unique($synonyms));

    $intent = null;
    $subject = null;
    $year = null;
    if (preg_match('/\b(19|20)\d{2}\b/', $clean, $yearMatch)) {
        $year = (int)$yearMatch[0];
    }

    $mentionsFlight = str_contains($clean, 'flight') || str_contains($clean, 'flights') || str_contains($clean, 'manifest') || str_contains($clean, 'plane') || str_contains($clean, 'aircraft');
    $mentionsEpstein = str_contains($clean, 'epstein');
    $mentionsMaxwell = str_contains($clean, 'maxwell');

    if (preg_match('/doc\s*#?(\d{2,})/u', $clean, $docMatch)) {
        $intent = 'doc_followup';
        $subject = 'doc_' . (int)$docMatch[1];
    } elseif ($mentionsFlight && $mentionsEpstein && $mentionsMaxwell) {
        $intent = 'flight_lookup';
        $parts = ['flight', 'epstein', 'maxwell'];
        if ($year) {
            $parts[] = (string)$year;
        }
        $subject = implode('_', $parts);
    } elseif (preg_match('/when did (?:jeff(?:rey)? )?epstein .*die/', $clean) || preg_match('/date of (?:jeff(?:rey)? )?epstein\'?s death/', $clean)) {
        $intent = 'date_lookup';
        $subject = 'jeffrey epstein death';
    } elseif (strpos($clean, 'masseuse') !== false && strpos($clean, 'contact') !== false) {
        $intent = 'relationship_lookup';
        $subject = 'masseuse_contact';
    } elseif (strpos($clean, 'plea') !== false && strpos($clean, 'florida') !== false) {
        $intent = 'fact_lookup';
        $subject = 'florida_plea_agreement';
    } elseif (strpos($clean, 'maxwell') !== false && strpos($clean, 'trial') !== false) {
        $intent = 'fact_lookup';
        $subject = 'maxwell_trial';
    }

    return [
        'clean' => $clean,
        'keywords' => $keywords,
        'synonyms' => $synonyms,
        'intent' => $intent,
        'subject' => $subject,
    ];
}

function ai_get_recent_history(PDO $pdo, int $sessionId, int $limit = 6, ?int $beforeMessageId = null): array
{
    $sql = 'SELECT id, role, content FROM ai_messages WHERE session_id = :sid';
    $params = [':sid' => $sessionId];
    if ($beforeMessageId) {
        $sql .= ' AND id < :before';
        $params[':before'] = $beforeMessageId;
    }
    $sql .= ' ORDER BY id DESC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            continue;
        }
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_reverse($rows);
}

function ai_get_or_create_session(PDO $pdo, ?string $sessionToken, ?string $ip, ?string $userAgent): array
{
    $sessionToken = $sessionToken ?: ai_generate_uuid();
    $stmt = $pdo->prepare('SELECT * FROM ai_sessions WHERE session_token = :token LIMIT 1');
    $stmt->execute([':token' => $sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $update = $pdo->prepare('UPDATE ai_sessions SET last_active_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute([':id' => (int)$session['id']]);
        return $session;
    }

    $insert = $pdo->prepare('INSERT INTO ai_sessions (session_token, ip_hash, user_agent, last_active_at) VALUES (:token, :ip, :ua, CURRENT_TIMESTAMP)');
    $insert->execute([
        ':token' => $sessionToken,
        ':ip' => ai_hash_ip($ip),
        ':ua' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
    ]);

    $id = (int)$pdo->lastInsertId();

    return [
        'id' => $id,
        'session_token' => $sessionToken,
        'ip_hash' => ai_hash_ip($ip),
        'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
    ];
}

function ai_log_message(
    PDO $pdo,
    int $sessionId,
    string $role,
    string $content,
    array $metadata = [],
    ?int $tokensInput = null,
    ?int $tokensOutput = null,
    ?int $latencyMs = null,
    ?string $model = null
): int {
    $stmt = $pdo->prepare('INSERT INTO ai_messages (session_id, role, content, model, tokens_input, tokens_output, latency_ms, metadata) VALUES (:session_id, :role, :content, :model, :tokens_in, :tokens_out, :latency, :metadata)');
    $stmt->execute([
        ':session_id' => $sessionId,
        ':role' => $role,
        ':content' => $content,
        ':model' => $model,
        ':tokens_in' => $tokensInput,
        ':tokens_out' => $tokensOutput,
        ':latency' => $latencyMs,
        ':metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);

    return (int)$pdo->lastInsertId();
}

function ai_log_citations(PDO $pdo, int $messageId, array $citations): void
{
    if (!$citations) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO ai_citations (message_id, document_id, page_number, score, snippet) VALUES (:message_id, :document_id, :page_number, :score, :snippet)');
    foreach ($citations as $citation) {
        $stmt->execute([
            ':message_id' => $messageId,
            ':document_id' => (int)$citation['document_id'],
            ':page_number' => isset($citation['page_number']) ? (int)$citation['page_number'] : null,
            ':score' => isset($citation['score']) ? (float)$citation['score'] : null,
            ':snippet' => $citation['snippet'] ?? null,
        ]);
    }
}
