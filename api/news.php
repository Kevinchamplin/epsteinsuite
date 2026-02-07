<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cache.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$sort = ($_GET['sort'] ?? 'shock') === 'date' ? 'date' : 'shock';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int)($_GET['per_page'] ?? 20)));
$since = $_GET['since'] ?? null;
$offset = ($page - 1) * $perPage;

try {
    $pdo = db();

    // Polling mode: return only articles newer than $since
    if ($since !== null && $since !== '') {
        $stmt = $pdo->prepare("
            SELECT id, title, url, source_name, published_at, ai_summary,
                   ai_headline, shock_score, score_reason, entities_mentioned, created_at
            FROM news_articles
            WHERE status = 'processed'
              AND created_at > :since
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([':since' => $since]);
        $articles = $stmt->fetchAll();

        echo json_encode([
            'ok' => true,
            'articles' => $articles,
            'count' => count($articles),
            'polled' => true
        ]);
        exit;
    }

    // Standard paginated request — cache for 2 minutes
    $cacheKey = "news_api_{$sort}_p{$page}_n{$perPage}";
    $result = Cache::remember($cacheKey, function () use ($pdo, $sort, $perPage, $offset) {
        $orderBy = $sort === 'date'
            ? 'published_at DESC, id DESC'
            : 'shock_score DESC, published_at DESC';

        $stmt = $pdo->prepare("
            SELECT id, title, url, source_name, published_at, ai_summary,
                   ai_headline, shock_score, score_reason, entities_mentioned, created_at
            FROM news_articles
            WHERE status = 'processed'
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $articles = $stmt->fetchAll();

        $total = (int)$pdo->query("SELECT COUNT(*) FROM news_articles WHERE status = 'processed'")->fetchColumn();

        $latestRow = $pdo->query("SELECT MAX(created_at) FROM news_articles WHERE status = 'processed'")->fetchColumn();

        return [
            'articles' => $articles,
            'total' => $total,
            'latest_at' => $latestRow,
        ];
    }, 120);

    echo json_encode([
        'ok' => true,
        'articles' => $result['articles'],
        'total' => $result['total'],
        'page' => $page,
        'per_page' => $perPage,
        'has_more' => ($offset + $perPage) < $result['total'],
        'latest_at' => $result['latest_at'],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load news']);
}
