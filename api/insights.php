<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = db();
    
    // Gather comprehensive statistics
    $stats = [];
    
    // Document stats
    $stmt = $pdo->query("SELECT COUNT(*) as total, 
                                SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
                                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                         FROM documents");
    $stats['documents'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Entity stats
    $stmt = $pdo->query("SELECT COALESCE(type, 'UNKNOWN') AS entity_type, COUNT(*) as count 
                         FROM entities 
                         GROUP BY COALESCE(type, 'UNKNOWN')");
    $stats['entities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Email stats
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM emails");
    $stats['emails'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Flight stats
    $stmt = $pdo->query("SELECT COUNT(DISTINCT f.id) as total_flights,
                               COUNT(DISTINCT p.name) as unique_passengers
                        FROM flight_logs f
                        LEFT JOIN passengers p ON f.id = p.flight_id");
    $stats['flights'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Top entities
    $stmt = $pdo->query("SELECT e.name AS entity_name,
                                e.type AS entity_type,
                                SUM(de.frequency) AS mention_count
                         FROM document_entities de
                         JOIN entities e ON e.id = de.entity_id
                         GROUP BY e.id, e.name, e.type
                         ORDER BY mention_count DESC
                         LIMIT 10");
    $stats['top_entities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent activity
    $stmt = $pdo->query("SELECT id, title, created_at, file_type 
                         FROM documents 
                         WHERE status = 'processed' 
                         ORDER BY created_at DESC 
                         LIMIT 5");
    $stats['recent_docs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Data set distribution
    $stmt = $pdo->query("SELECT data_set, COUNT(*) as count 
                         FROM documents 
                         WHERE data_set IS NOT NULL 
                         GROUP BY data_set 
                         ORDER BY data_set");
    $stats['data_sets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate AI insights summary
    $insights = [
        'total_documents' => (int)$stats['documents']['total'],
        'processed_documents' => (int)$stats['documents']['processed'],
        'pending_documents' => (int)$stats['documents']['pending'],
        'processing_rate' => $stats['documents']['total'] > 0 
            ? round(($stats['documents']['processed'] / $stats['documents']['total']) * 100, 1) 
            : 0,
        'total_entities' => array_sum(array_column($stats['entities'], 'count')),
        'entity_breakdown' => $stats['entities'],
        'total_emails' => (int)$stats['emails']['total'],
        'total_flights' => (int)$stats['flights']['total_flights'],
        'unique_passengers' => (int)$stats['flights']['unique_passengers'],
        'top_entities' => $stats['top_entities'],
        'recent_activity' => $stats['recent_docs'],
        'data_set_distribution' => $stats['data_sets'],
        'avg_entities_per_doc' => $stats['documents']['processed'] > 0 
            ? round(array_sum(array_column($stats['entities'], 'count')) / $stats['documents']['processed'], 1)
            : 0,
        'summary' => generateInsightSummary($stats)
    ];
    
    echo json_encode($insights, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to generate insights']);
}

function generateInsightSummary($stats) {
    $total = (int)$stats['documents']['total'];
    $processed = (int)$stats['documents']['processed'];
    $entities = array_sum(array_column($stats['entities'], 'count'));
    $emails = (int)$stats['emails']['total'];
    $flights = (int)$stats['flights']['total_flights'];
    
    $summary = [];
    
    if ($total > 0) {
        $summary[] = "The database contains {$total} documents from the DOJ Epstein Files release.";
    }
    
    if ($processed > 0) {
        $rate = round(($processed / $total) * 100);
        $summary[] = "{$processed} documents ({$rate}%) have been processed with AI analysis.";
    }
    
    if ($entities > 0) {
        $avgPerDoc = $processed > 0 ? round($entities / $processed, 1) : 0;
        $summary[] = "AI has extracted {$entities} entities (people, organizations, locations) with an average of {$avgPerDoc} entities per document.";
    }
    
    if ($emails > 0) {
        $summary[] = "{$emails} email communications have been identified and indexed.";
    }
    
    if ($flights > 0) {
        $passengers = (int)$stats['flights']['unique_passengers'];
        $summary[] = "{$flights} flight logs documented with {$passengers} unique passengers.";
    }
    
    return $summary;
}
