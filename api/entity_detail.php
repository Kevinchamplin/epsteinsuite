<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cache.php';

$entityId = (int)($_GET['id'] ?? 0);

if ($entityId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid entity ID']);
    exit;
}

try {
    $pdo = db();

    $cacheKey = "entity_detail_{$entityId}";
    $result = Cache::remember($cacheKey, function () use ($pdo, $entityId): array {

        // Get entity info + doc count
        $stmt = $pdo->prepare("
            SELECT e.id, e.name, e.type, COUNT(de.document_id) AS doc_count
            FROM entities e
            LEFT JOIN document_entities de ON e.id = de.entity_id
            WHERE e.id = :id
            GROUP BY e.id
        ");
        $stmt->execute(['id' => $entityId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entity) {
            return ['error' => 'Entity not found'];
        }

        // Normalize type for display
        $rawType = strtoupper($entity['type'] ?? '');
        $typeMap = ['PERSON' => 'Person', 'ORG' => 'Organization', 'ORGANIZATION' => 'Organization', 'LOCATION' => 'Location'];
        $entity['type'] = $typeMap[$rawType] ?? ucfirst(strtolower($entity['type'] ?? 'Unknown'));

        // Top 10 co-mentioned entities
        $stmt = $pdo->prepare("
            SELECT co.id, co.name, co.type, COUNT(DISTINCT de1.document_id) AS shared_docs
            FROM document_entities de1
            JOIN document_entities de2 ON de1.document_id = de2.document_id AND de1.entity_id != de2.entity_id
            JOIN entities co ON co.id = de2.entity_id
            WHERE de1.entity_id = :id
            GROUP BY co.id
            ORDER BY shared_docs DESC
            LIMIT 10
        ");
        $stmt->execute(['id' => $entityId]);
        $coMentions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize co-mention types
        foreach ($coMentions as &$cm) {
            $cmType = strtoupper($cm['type'] ?? '');
            $cm['type'] = $typeMap[$cmType] ?? ucfirst(strtolower($cm['type'] ?? 'Unknown'));
            $cm['shared_docs'] = (int)$cm['shared_docs'];
        }
        unset($cm);

        // 5 most recent documents mentioning this entity
        $stmt = $pdo->prepare("
            SELECT d.id, d.title, d.document_date, LEFT(d.ai_summary, 200) AS ai_summary_snippet, d.file_type
            FROM documents d
            JOIN document_entities de ON d.id = de.document_id
            WHERE de.entity_id = :id AND d.status = 'processed'
            ORDER BY d.document_date DESC, d.created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['id' => $entityId]);
        $recentDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'entity' => [
                'id' => (int)$entity['id'],
                'name' => $entity['name'],
                'type' => $entity['type'],
            ],
            'doc_count' => (int)$entity['doc_count'],
            'top_co_mentions' => $coMentions,
            'recent_documents' => $recentDocs,
        ];
    }, 600);

    if (isset($result['error']) && $result['error'] === 'Entity not found') {
        http_response_code(404);
    }

    echo json_encode($result, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load entity details']);
}
