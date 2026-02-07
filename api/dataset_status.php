<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

// PHP-FPM fallback: parse HTTP_AUTHORIZATION into PHP_AUTH_USER/PW
// Apache RewriteRule may prefix with REDIRECT_
$httpAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!isset($_SERVER['PHP_AUTH_USER']) && $httpAuth !== '') {
    $authParts = explode(' ', $httpAuth, 2);
    if (strtolower($authParts[0] ?? '') === 'basic' && isset($authParts[1])) {
        $decoded = base64_decode($authParts[1]);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] = explode(':', $decoded, 2);
        }
    }
}

// Admin auth via ADMIN_KEY header or Basic Auth
$adminKey = env_value('ADMIN_KEY');
$authHeader = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
$basicValid = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
    && hash_equals(env_value('ADMIN_USER') ?: 'admin', $_SERVER['PHP_AUTH_USER'])
    && hash_equals(env_value('ADMIN_PASSWORD') ?: '', $_SERVER['PHP_AUTH_PW']);

if (!$basicValid && (!$adminKey || !hash_equals($adminKey, $authHeader))) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$pdo = db();

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
        SUM(CASE WHEN ai_summary IS NOT NULL AND ai_summary != '' THEN 1 ELSE 0 END) AS has_ai,
        SUM(ocr_done) AS has_ocr
    FROM (
        SELECT
            d.*,
            CASE WHEN EXISTS (
                SELECT 1 FROM pages p WHERE p.document_id = d.id AND p.ocr_text IS NOT NULL AND p.ocr_text != ''
            ) THEN 1 ELSE 0 END AS ocr_done
        FROM documents d
    ) sub
    GROUP BY data_set
    ORDER BY
        CASE WHEN data_set LIKE 'DOJ - Data Set %' THEN 0 ELSE 1 END,
        CASE WHEN data_set LIKE 'DOJ - Data Set %'
            THEN CAST(SUBSTRING_INDEX(data_set, ' ', -1) AS UNSIGNED)
            ELSE 999
        END,
        data_set
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Cast numerics
foreach ($rows as &$row) {
    foreach (['total', 'has_local', 'missing_local', 'pending', 'downloaded', 'processed', 'errors', 'has_ai', 'has_ocr'] as $col) {
        $row[$col] = (int)$row[$col];
    }
    $row['local_pct'] = $row['total'] > 0 ? round(($row['has_local'] / $row['total']) * 100, 1) : 0;
    $row['ocr_pct'] = $row['total'] > 0 ? round(($row['has_ocr'] / $row['total']) * 100, 1) : 0;
    $row['ai_pct'] = $row['total'] > 0 ? round(($row['has_ai'] / $row['total']) * 100, 1) : 0;
}
unset($row);

// Totals
$totals = [
    'total' => 0, 'has_local' => 0, 'missing_local' => 0,
    'pending' => 0, 'downloaded' => 0, 'processed' => 0, 'errors' => 0,
    'has_ai' => 0, 'has_ocr' => 0,
];
foreach ($rows as $r) {
    foreach ($totals as $k => &$v) {
        $v += $r[$k];
    }
    unset($v);
}
$totals['local_pct'] = $totals['total'] > 0 ? round(($totals['has_local'] / $totals['total']) * 100, 1) : 0;
$totals['ocr_pct'] = $totals['total'] > 0 ? round(($totals['has_ocr'] / $totals['total']) * 100, 1) : 0;
$totals['ai_pct'] = $totals['total'] > 0 ? round(($totals['has_ai'] / $totals['total']) * 100, 1) : 0;

echo json_encode([
    'datasets' => $rows,
    'totals' => $totals,
    'generated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
