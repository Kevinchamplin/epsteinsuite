<?php
require_once __DIR__ . '/includes/db.php';

$folder = $_GET['folder'] ?? 'root';
$search = $_GET['q'] ?? '';
$fileType = $_GET['type'] ?? ''; // file type filter
$sizeFilter = $_GET['size'] ?? ''; // file size filter
$sort = $_GET['sort'] ?? 'recent';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 60;
$offset = ($page - 1) * $perPage;

$allowedSorts = ['recent', 'ocr_pending', 'ocr_complete'];
$allowedSizeFilters = ['lt5', '5to25', '25to100', '100to500', '500plus'];
$hiddenFileTypes = ['dat', 'opt', 'idx', 'md5', 'md5sum', 'jsonl', 'mis', 'db', 'dbf'];
$sizeFilterLabels = [
    'lt5' => '< 5 MB',
    '5to25' => '5–25 MB',
    '25to100' => '25–100 MB',
    '100to500' => '100–500 MB',
    '500plus' => '500+ MB',
];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'recent';
}
if (!in_array($sizeFilter, $allowedSizeFilters, true)) {
    $sizeFilter = '';
}
$sizeBounds = getSizeBounds($sizeFilter);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// Helper to build URLs with current params
function buildDriveUrl($overrides = []) {
    global $folder, $search, $fileType, $page, $sort, $sizeFilter;
    $params = ['folder' => $folder];
    if ($search) $params['q'] = $search;
    if ($fileType) $params['type'] = $fileType;
    if ($sizeFilter) $params['size'] = $sizeFilter;
    if ($sort && $sort !== 'recent') $params['sort'] = $sort;
    $params['page'] = $page;
    $params = array_merge($params, $overrides);
    if (empty($params['q'])) unset($params['q']);
    if (empty($params['type'])) unset($params['type']);
    if (empty($params['size'])) unset($params['size']);
    if (empty($params['sort']) || $params['sort'] === 'recent') unset($params['sort']);
    if ($params['page'] <= 1) unset($params['page']);
    if ($params['folder'] === 'root') unset($params['folder']);
    return 'drive.php' . ($params ? '?' . http_build_query($params) : '');
}

function buildOrderClause(string $sort, string $defaultOrder): string {
    switch ($sort) {
        case 'ocr_pending':
            return "has_ocr ASC, {$defaultOrder}";
        case 'ocr_complete':
            return "has_ocr DESC, {$defaultOrder}";
        default:
            return $defaultOrder;
    }
}

function getSizeBounds(string $filter): ?array {
    if ($filter === '') {
        return null;
    }
    $mb = 1024 * 1024;
    return match ($filter) {
        'lt5' => ['min' => null, 'max' => 5 * $mb],
        '5to25' => ['min' => 5 * $mb, 'max' => 25 * $mb],
        '25to100' => ['min' => 25 * $mb, 'max' => 100 * $mb],
        '100to500' => ['min' => 100 * $mb, 'max' => 500 * $mb],
        '500plus' => ['min' => 500 * $mb, 'max' => null],
        default => null,
    };
}

function buildSizeFilterClause(?array $bounds, string $column = 'file_size'): array {
    if (!$bounds) {
        return ['', []];
    }
    $clauses = ["{$column} IS NOT NULL"];
    $params = [];
    if ($bounds['min'] !== null) {
        $clauses[] = "{$column} >= :size_min";
        $params['size_min'] = $bounds['min'];
    }
    if ($bounds['max'] !== null) {
        $clauses[] = "{$column} < :size_max";
        $params['size_max'] = $bounds['max'];
    }
    return ['(' . implode(' AND ', $clauses) . ')', $params];
}

function buildHiddenTypesClause(string $column = 'file_type'): array {
    global $hiddenFileTypes;
    if (empty($hiddenFileTypes)) {
        return ['', []];
    }
    $placeholders = [];
    $params = [];
    foreach ($hiddenFileTypes as $idx => $type) {
        $param = ":hidden_type_{$idx}";
        $placeholders[] = $param;
        $params[ltrim($param, ':')] = $type;
    }
    return ["({$column} IS NULL OR {$column} NOT IN (" . implode(', ', $placeholders) . "))", $params];
}

// Helper to convert Google Drive URLs to thumbnail URLs
function getGoogleDriveThumbnail($url) {
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        $fileId = $matches[1];
        return "https://drive.google.com/thumbnail?id={$fileId}&sz=w200";
    }
    return $url;
}

function file_type_icon(string $type): string {
    $type = strtolower($type);
    return match ($type) {
        'pdf' => 'file-text',
        'doc', 'docx' => 'file',
        'xls', 'xlsx' => 'grid',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff' => 'image',
        'mp4', 'webm', 'mov', 'video' => 'film',
        'mp3', 'wav', 'audio' => 'music',
        default => 'file',
    };
}

// Virtual Folders Logic
$virtualFolders = [
    'root' => [
        ['name' => '📁 Court Records', 'id' => 'category-Court Records', 'type' => 'folder'],
        ['name' => '📁 FOIA Records', 'id' => 'category-FOIA', 'type' => 'folder'],
        ['name' => '📁 House Disclosures', 'id' => 'category-House Disclosures', 'type' => 'folder'],
        ['name' => '📁 House Oversight', 'id' => 'category-House Oversight', 'type' => 'folder'],
        ['name' => '📁 DOJ Disclosures', 'id' => 'category-DOJ Disclosures', 'type' => 'folder'],
        ['name' => '✉️ Email Archive', 'id' => 'emails-archive', 'type' => 'folder'],
        ['name' => '📎 Email Attachments', 'id' => 'emails-attachments', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 1', 'id' => 'dataset-1', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 2', 'id' => 'dataset-2', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 3', 'id' => 'dataset-3', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 4', 'id' => 'dataset-4', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 5', 'id' => 'dataset-5', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 6', 'id' => 'dataset-6', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 7', 'id' => 'dataset-7', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 8', 'id' => 'dataset-8', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 9', 'id' => 'dataset-9', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 10', 'id' => 'dataset-10', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 11', 'id' => 'dataset-11', 'type' => 'folder'],
        ['name' => 'DOJ Data Set 12', 'id' => 'dataset-12', 'type' => 'folder'],
    ]
];

$documents = [];
$currentFolderName = 'My Drive';
$totalCount = 0;
$totalPages = 1;
$extra_head_tags = $extra_head_tags ?? [];

try {
    $pdo = db();
    
    // Get available file types for filter dropdown
    $fileTypes = $pdo->query("SELECT DISTINCT file_type FROM documents WHERE file_type IS NOT NULL AND file_type != '' ORDER BY file_type")->fetchAll(PDO::FETCH_COLUMN);

    $hasDocsFulltext = false;
    $hasPagesOcrFulltext = false;
    $normalizedQuery = strtolower(trim($search));
    $normalizedQuery = ltrim($normalizedQuery, ".");
    $extWhitelist = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'doc', 'docx', 'xls', 'xlsx', 'email'];
    $fileTypeQuery = in_array($normalizedQuery, $extWhitelist, true) ? $normalizedQuery : '__none__';
    $canUseFulltext = (mb_strlen(trim($search)) >= 3);
    try {
        $hasDocsFulltext = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND INDEX_TYPE = 'FULLTEXT' LIMIT 1")->fetchColumn();
        $hasPagesOcrFulltext = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND INDEX_TYPE = 'FULLTEXT' LIMIT 1")->fetchColumn();
    } catch (Exception $e) {
        $hasDocsFulltext = false;
        $hasPagesOcrFulltext = false;
    }

    // If we are in a specific dataset folder, fetch files with AI summaries
    if (str_starts_with($folder, 'dataset-')) {
        $datasetNum = str_replace('dataset-', '', $folder);
        $currentFolderName = "DOJ Data Set $datasetNum";
        
        $orderClause = buildOrderClause($sort, "created_at DESC");
        [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds);
        $sizeSql = $sizeClause ? " AND $sizeClause" : '';
        [$hiddenClause, $hiddenParams] = buildHiddenTypesClause('file_type');
        $hiddenSql = $hiddenClause ? " AND $hiddenClause" : '';

        $sql = "SELECT id, title, file_type, created_at, status,
                       SUBSTRING(ai_summary, 1, 200) as ai_summary, source_url,
                       EXISTS(
                           SELECT 1 FROM pages p
                           WHERE p.document_id = documents.id
                             AND p.ocr_text IS NOT NULL
                             AND p.ocr_text != ''
                       ) AS has_ocr
                FROM documents 
                WHERE (
                    data_set = :dsExact
                    OR data_set = :dsExactAlt
                    OR data_set LIKE :dsPrefix
                    OR source_url LIKE :urlPattern1
                    OR source_url LIKE :urlPattern2
                    OR source_url LIKE :urlPattern3
                    OR source_url LIKE :urlPattern4
                ){$sizeSql}{$hiddenSql}
                ORDER BY $orderClause
                LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $params = [
            'dsExact' => "Data Set $datasetNum",
            'dsExactAlt' => "DOJ Data Set $datasetNum",
            'dsPrefix' => "%Data Set $datasetNum%",
            'urlPattern1' => "%/files/DataSet%20$datasetNum/%",
            'urlPattern2' => "%/files/DataSet%20$datasetNum%",
            'urlPattern3' => "%DataSet%20$datasetNum%",
            'urlPattern4' => "%data-set-$datasetNum%"
        ];
        if ($sizeParams) {
            $params = array_merge($params, $sizeParams);
        }
        if ($hiddenParams) {
            $params = array_merge($params, $hiddenParams);
        }
        $stmt->execute($params);
        $documents = $stmt->fetchAll();

        $datasetSample = array_slice($documents, 0, 10);
        $datasetDistributions = array_map(function ($doc) {
            return [
                '@type' => 'DataDownload',
                'encodingFormat' => strtoupper($doc['file_type'] ?: 'pdf'),
                'name' => $doc['title'],
                'contentUrl' => '/document.php?id=' . (int)$doc['id'],
            ];
        }, $datasetSample);

        $datasetSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => "DOJ Epstein Files – Data Set {$datasetNum}",
            'description' => "Digitized DOJ Epstein transparency release Data Set {$datasetNum}, searchable via Epstein Suite Drive.",
            'url' => '/drive.php?folder=' . urlencode($folder),
            'creator' => [
                '@type' => 'Organization',
                'name' => 'Epstein Suite',
                'url' => 'https://epsteinsuite.com',
            ],
            'isPartOf' => [
                '@type' => 'DataCatalog',
                'name' => 'Epstein Suite Drive',
                'url' => '/drive.php',
            ],
            'distribution' => $datasetDistributions,
        ];
    } elseif (str_starts_with($folder, 'category-')) {
        $catName = str_replace('category-', '', $folder);
        $currentFolderName = $catName;

        $categoryUrlPatterns = [
            'Court Records' => '%/epstein/court-records%',
            'FOIA' => '%/epstein/foia%',
            'House Disclosures' => '%/epstein/house-disclosures%',
            'DOJ Disclosures' => '%/epstein/doj-disclosures%',
        ];

        $categoryUrlPatternsAlt = [
            'FOIA' => '%/multimedia/Freedom%20of%20Information%20Act%20(FOIA)/%',
        ];

        $urlPattern = $categoryUrlPatterns[$catName] ?? null;
        $urlPatternAlt = $categoryUrlPatternsAlt[$catName] ?? null;
        
        $whereClauses = [
            "data_set = :ds",
            "data_set LIKE :dsPrefix",
            "data_set LIKE :dsDOJPrefix"
        ];
        $params = [
            'ds' => $catName,
            'dsPrefix' => $catName . '%',
            'dsDOJPrefix' => '%' . $catName . '%'
        ];
        
        if ($urlPattern) {
            $whereClauses[] = "source_url LIKE :urlPattern";
            $params['urlPattern'] = $urlPattern;
        }
        if ($urlPatternAlt) {
            $whereClauses[] = "source_url LIKE :urlPatternAlt";
            $params['urlPatternAlt'] = $urlPatternAlt;
        }
        
        $whereSQL = "(" . implode(" OR ", $whereClauses) . ")";
        [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds);
        if ($sizeClause) {
            $whereSQL .= " AND $sizeClause";
            $params = array_merge($params, $sizeParams);
        }
        [$hiddenClause, $hiddenParams] = buildHiddenTypesClause('file_type');
        if ($hiddenClause) {
            $whereSQL .= " AND $hiddenClause";
            $params = array_merge($params, $hiddenParams);
        }
        if ($fileType) {
            $whereSQL .= " AND file_type = :fileType";
            $params['fileType'] = $fileType;
        }
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM documents WHERE $whereSQL";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalCount = (int)$stmt->fetchColumn();
        $totalPages = max(1, ceil($totalCount / $perPage));
        
        $sql = "SELECT id, title, file_type, created_at, status,
                       SUBSTRING(ai_summary, 1, 200) as ai_summary, source_url,
                       EXISTS(
                           SELECT 1 FROM pages p
                           WHERE p.document_id = documents.id
                             AND p.ocr_text IS NOT NULL
                             AND p.ocr_text != ''
                       ) AS has_ocr
                FROM documents 
                WHERE $whereSQL
                ORDER BY created_at DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
    } elseif ($folder === 'emails-archive') {
        $currentFolderName = 'Email Archive';
        
        [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds);
        $sizeSql = $sizeClause ? " AND $sizeClause" : '';
        [$hiddenClause, $hiddenParams] = buildHiddenTypesClause('file_type');
        $hiddenSql = $hiddenClause ? " AND $hiddenClause" : '';

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE file_type = 'email'{$sizeSql}");
        $countParams = $sizeParams;
        if ($hiddenParams) {
            $sizeSql .= " AND $hiddenClause";
            $countParams = array_merge($sizeParams, $hiddenParams);
        }
        $stmt->execute($countParams);
        $totalCount = (int)$stmt->fetchColumn();
        $totalPages = max(1, ceil($totalCount / $perPage));
        
        $sql = "SELECT id, title, file_type, created_at, status,
                       SUBSTRING(ai_summary, 1, 200) as ai_summary, source_url,
                       EXISTS(
                           SELECT 1 FROM pages p
                           WHERE p.document_id = documents.id
                             AND p.ocr_text IS NOT NULL
                             AND p.ocr_text != ''
                       ) AS has_ocr
                FROM documents
                WHERE file_type = 'email'{$sizeSql}{$hiddenSql}
                ORDER BY created_at DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $listParams = $sizeParams;
        if ($hiddenParams) {
            $listParams = array_merge($listParams, $hiddenParams);
        }
        $stmt->execute($listParams);
        $documents = $stmt->fetchAll();
    } elseif ($folder === 'emails-attachments') {
        $currentFolderName = 'Email Attachments';
        [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds, 'd.file_size');
        $sizeSql = $sizeClause ? " AND $sizeClause" : '';
        [$hiddenClause, $hiddenParams] = buildHiddenTypesClause('d.file_type');
        $hiddenSql = $hiddenClause ? " AND $hiddenClause" : '';

        $sql = "SELECT d.id, d.title, d.file_type, d.created_at, d.status,
                       SUBSTRING(d.ai_summary, 1, 200) as ai_summary, d.source_url,
                       EXISTS(
                           SELECT 1 FROM pages p
                           WHERE p.document_id = d.id
                             AND p.ocr_text IS NOT NULL
                             AND p.ocr_text != ''
                       ) AS has_ocr
                FROM emails e
                JOIN documents d ON d.id = e.document_id
                WHERE e.attachments_count > 0{$sizeSql}{$hiddenSql}
                ORDER BY d.created_at DESC
                LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $listParams = $sizeParams;
        if ($hiddenParams) {
            $listParams = array_merge($listParams, $hiddenParams);
        }
        $stmt->execute($listParams);
        $documents = $stmt->fetchAll();
    } elseif ($folder === 'root' && !$search) {
        // Root view: show recent files so Drive doesn't look empty
        $currentFolderName = 'My Drive';
        
        $whereSQL = "1=1";
        $params = [];
        if ($fileType) {
            $whereSQL = "file_type = :fileType";
            $params['fileType'] = $fileType;
        }
        [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds);
        if ($sizeClause) {
            $whereSQL .= " AND $sizeClause";
            $params = array_merge($params, $sizeParams);
        }
        [$hiddenClause, $hiddenParams] = buildHiddenTypesClause('file_type');
        if ($hiddenClause) {
            $whereSQL .= " AND $hiddenClause";
            $params = array_merge($params, $hiddenParams);
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE $whereSQL");
        $stmt->execute($params);
        $totalCount = (int)$stmt->fetchColumn();
        $totalPages = max(1, ceil($totalCount / $perPage));
        
        $orderClause = buildOrderClause($sort, "created_at DESC");
        $sql = "SELECT id, title, file_type, created_at, status,
                       SUBSTRING(ai_summary, 1, 200) as ai_summary, source_url,
                       EXISTS(
                           SELECT 1 FROM pages p
                           WHERE p.document_id = documents.id
                             AND p.ocr_text IS NOT NULL
                             AND p.ocr_text != ''
                       ) AS has_ocr
                FROM documents
                WHERE $whereSQL
                ORDER BY $orderClause
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
    } elseif ($search) {
        $currentFolderName = "Search results for '$search'";
        if ($hasDocsFulltext && $hasPagesOcrFulltext && $canUseFulltext) {
            [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds, 'd.file_size');
        $sizeSql = $sizeClause ? " AND $sizeClause" : '';

        $sql = "SELECT d.id, d.title, d.file_type, d.created_at, d.status,
                           SUBSTRING(d.ai_summary, 1, 200) as ai_summary, d.source_url,
                           EXISTS(
                               SELECT 1 FROM pages p
                               WHERE p.document_id = d.id
                                 AND p.ocr_text IS NOT NULL
                                 AND p.ocr_text != ''
                           ) AS has_ocr,
                           MATCH(d.title, d.description, d.ai_summary) AGAINST (:q_doc_score IN NATURAL LANGUAGE MODE) as score,
                           o.ocr_score
                    FROM documents d
                    LEFT JOIN (
                        SELECT document_id,
                               MAX(MATCH(ocr_text) AGAINST (:q_ocr_score IN NATURAL LANGUAGE MODE)) as ocr_score
                        FROM pages
                        WHERE MATCH(ocr_text) AGAINST (:q_ocr_where IN NATURAL LANGUAGE MODE)
                        GROUP BY document_id
                    ) o ON o.document_id = d.id
                    WHERE (
                        d.title LIKE :s_title
                        OR d.description LIKE :s_desc
                        OR MATCH(d.title, d.description, d.ai_summary) AGAINST (:q_doc_where IN NATURAL LANGUAGE MODE)
                        OR o.ocr_score IS NOT NULL
                        OR d.file_type = :q_file_type
                    ){$sizeSql}
                    ORDER BY (IFNULL(score, 0) + IFNULL(o.ocr_score, 0)) DESC, d.created_at DESC
                    LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $params = [
                's_title' => "%$search%",
                's_desc' => "%$search%",
                'q_doc_score' => $search,
                'q_doc_where' => $search,
                'q_ocr_score' => $search,
                'q_ocr_where' => $search,
                'q_file_type' => $fileTypeQuery,
            ];
            if ($sizeParams) {
                $params = array_merge($params, $sizeParams);
            }
            $stmt->execute($params);
            $documents = $stmt->fetchAll();
        } else {
            $like = "%$search%";
            $sql = "SELECT d.id, d.title, d.file_type, d.created_at, d.status,
                           SUBSTRING(d.ai_summary, 1, 200) as ai_summary, d.source_url,
                           EXISTS(
                               SELECT 1 FROM pages p
                               WHERE p.document_id = d.id
                                 AND p.ocr_text IS NOT NULL
                                 AND p.ocr_text != ''
                           ) AS has_ocr,
                           0 as score,
                           IF(o.has_ocr = 1, 1, NULL) as ocr_score
                    FROM documents d
                    LEFT JOIN (
                        SELECT document_id, 1 as has_ocr
                        FROM pages
                        WHERE ocr_text LIKE :q_ocr_like
                        GROUP BY document_id
                    ) o ON o.document_id = d.id
                    WHERE (
                        d.title LIKE :s_title
                        OR d.description LIKE :s_desc
                        OR d.ai_summary LIKE :s_sum
                        OR o.has_ocr = 1
                        OR d.file_type = :q_file_type
                    ){$sizeSql}
                    ORDER BY ocr_score DESC, d.created_at DESC
                    LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $params = [
                's_title' => $like,
                's_desc' => $like,
                's_sum' => $like,
                'q_ocr_like' => $like,
                'q_file_type' => $fileTypeQuery,
            ];
            if ($sizeParams) {
                $params = array_merge($params, $sizeParams);
            }
            $stmt->execute($params);
            $documents = $stmt->fetchAll();
        }
    } else {
        // Fallback: treat folder name as a dataset / label directly
        $currentFolderName = $folder;
        $decodedFolder = urldecode($folder);
        $like = '%' . $decodedFolder . '%';
        $encodedLike = '%' . rawurlencode($decodedFolder) . '%';
        $normalizedSlug = preg_replace('/[^a-z0-9]+/', '_', strtolower($decodedFolder));
        
        $whereClauses = [
            'data_set = :exact',
            'data_set LIKE :prefix',
            'title LIKE :titleLike',
            'source_url LIKE :sourceLike',
            'source_url LIKE :sourceEncodedLike'
        ];
        $params = [
            'exact' => $decodedFolder,
            'prefix' => $decodedFolder . '%',
            'titleLike' => $like,
            'sourceLike' => $like,
            'sourceEncodedLike' => $encodedLike,
        ];
        if ($normalizedSlug !== '') {
            $whereClauses[] = "REPLACE(REPLACE(REPLACE(LOWER(data_set),' ', '_'), '-', '_'), '/', '_') = :slug";
            $params['slug'] = $normalizedSlug;
            $whereClauses[] = "REPLACE(REPLACE(REPLACE(LOWER(title),' ', '_'), '-', '_'), '/', '_') LIKE :slugLike";
            $params['slugLike'] = '%' . $normalizedSlug . '%';
        }
        if ($fileType) {
            $whereClauses[] = 'file_type = :fileType';
            $params['fileType'] = $fileType;
        }
        $whereSQL = '(' . implode(' OR ', $whereClauses) . ')';
        [$sizeClause, $sizeParams] = buildSizeFilterClause($sizeBounds);
        if ($sizeClause) {
            $whereSQL .= " AND $sizeClause";
            $params = array_merge($params, $sizeParams);
        }
        [$hiddenClause, $hiddenParams] = buildHiddenTypesClause('file_type');
        if ($hiddenClause) {
            $whereSQL .= " AND $hiddenClause";
            $params = array_merge($params, $hiddenParams);
        }
        
        $countSql = "SELECT COUNT(*) FROM documents WHERE $whereSQL";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalCount = (int)$stmt->fetchColumn();
        $totalPages = max(1, ceil($totalCount / $perPage));
        
        $sql = "SELECT id, title, file_type, created_at, status,
                       SUBSTRING(ai_summary, 1, 200) as ai_summary, source_url,
                       EXISTS(
                           SELECT 1 FROM pages p
                           WHERE p.document_id = documents.id
                             AND p.ocr_text IS NOT NULL
                             AND p.ocr_text != ''
                       ) AS has_ocr
                FROM documents
                WHERE $whereSQL
                ORDER BY created_at DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
    }
    
    // Get stats for current folder
    $folderStats = [
        'total' => count($documents),
        'processed' => count(array_filter($documents, function ($d) {
            return isset($d['status']) && $d['status'] === 'processed';
        })),
        'ocr_complete' => count(array_filter($documents, function ($d) {
            return !empty($d['has_ocr']);
        })),
    ];
    $folderStats['ocr_pending'] = $folderStats['total'] - $folderStats['ocr_complete'];

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$page_title = 'Epstein Drive - ' . $currentFolderName;
$meta_description = 'Browse Epstein-related DOJ documents organized by data set. AI summaries, OCR text, and direct links to source files.';
$og_title = 'Epstein Drive — Browse DOJ Documents';
$og_description = 'Browse Epstein-related DOJ documents organized by data set. AI summaries, OCR text, and direct links to source files.';
if (!empty($datasetSchema ?? [])) {
    $extra_head_tags[] = '<script type="application/ld+json">' . json_encode($datasetSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}
require_once __DIR__ . '/includes/header_suite.php';
?>

<div class="flex flex-1 overflow-hidden bg-white">
    <!-- Sidebar -->
    <aside class="w-64 flex-shrink-0 flex flex-col py-4 pr-4 border-r border-gray-200 hidden md:flex">
        <nav class="flex-1 space-y-1">
            <a href="?folder=root" class="flex items-center gap-4 px-6 py-2 rounded-r-full text-sm font-medium <?= $folder === 'root' && !$search ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100' ?>">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" /></svg>
                My Drive
            </a>
            <a href="/contacts.php" class="flex items-center gap-4 px-6 py-2 rounded-r-full text-sm font-medium text-gray-700 hover:bg-gray-100">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                Contacts
            </a>
            <a href="/stats.php" class="flex items-center gap-4 px-6 py-2 rounded-r-full text-sm font-medium text-gray-700 hover:bg-gray-100">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                Analytics
            </a>
            <a href="/flight_logs.php" class="flex items-center gap-4 px-6 py-2 rounded-r-full text-sm font-medium text-gray-700 hover:bg-gray-100">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                Starred
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col overflow-hidden">
        
        <!-- Breadcrumb / Toolbar -->
        <div class="border-b border-gray-200 bg-white px-4 py-3">
            <div class="flex flex-col md:flex-row md:items-center gap-4">
                <!-- Breadcrumb -->
                <div class="flex items-center gap-2 text-lg text-gray-700 font-normal">
                    <?php if($folder !== 'root'): ?>
                        <a href="<?= buildDriveUrl(['folder' => 'root', 'page' => 1]) ?>" class="hover:bg-gray-100 px-2 py-1 rounded">My Drive</a>
                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        <span class="font-medium px-2 py-1 rounded"><?= htmlspecialchars($currentFolderName) ?></span>
                    <?php else: ?>
                        <span class="font-medium px-2 py-1 rounded">My Drive</span>
                    <?php endif; ?>
                </div>
                
                <!-- Search & Filters -->
                <div class="flex-1 flex items-center gap-3 md:justify-end">
                    <form method="GET" action="drive.php" class="flex-1 max-w-md hidden md:block">
                        <input type="hidden" name="folder" value="<?= htmlspecialchars($folder) ?>">
                        <div class="relative">
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                                   placeholder="Search in <?= htmlspecialchars($currentFolderName) ?>..." 
                                   class="w-full pl-10 pr-4 py-2 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </form>
                    
                    <!-- File Type Filter -->
                    <select onchange="window.location.href=this.value" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="<?= buildDriveUrl(['type' => '', 'page' => 1]) ?>" <?= !$fileType ? 'selected' : '' ?>>All Types</option>
                        <?php foreach (array_slice($fileTypes, 0, 15) as $ft): ?>
                            <option value="<?= buildDriveUrl(['type' => $ft, 'page' => 1]) ?>" <?= $fileType === $ft ? 'selected' : '' ?>><?= strtoupper(htmlspecialchars($ft)) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- File Size Filter -->
                    <select onchange="window.location.href=this.value" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="<?= buildDriveUrl(['size' => '', 'page' => 1]) ?>" <?= !$sizeFilter ? 'selected' : '' ?>>All Sizes</option>
                        <?php foreach ($sizeFilterLabels as $key => $label): ?>
                            <option value="<?= buildDriveUrl(['size' => $key, 'page' => 1]) ?>" <?= $sizeFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- OCR Sort -->
                    <select onchange="window.location.href=this.value" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="<?= buildDriveUrl(['sort' => 'recent', 'page' => 1]) ?>" <?= $sort === 'recent' ? 'selected' : '' ?>>Newest first</option>
                        <option value="<?= buildDriveUrl(['sort' => 'ocr_pending', 'page' => 1]) ?>" <?= $sort === 'ocr_pending' ? 'selected' : '' ?>>OCR pending first</option>
                        <option value="<?= buildDriveUrl(['sort' => 'ocr_complete', 'page' => 1]) ?>" <?= $sort === 'ocr_complete' ? 'selected' : '' ?>>OCR complete first</option>
                    </select>
                    
                    <span class="text-sm text-gray-500 whitespace-nowrap"><?= number_format($totalCount) ?> files</span>
                </div>
            </div>
            
            <?php if ($search || $fileType || $sizeFilter): ?>
            <div class="mt-3 flex items-center gap-2 flex-wrap">
                <?php if ($search): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-sm">
                        Search: <?= htmlspecialchars($search) ?>
                        <a href="<?= buildDriveUrl(['q' => '', 'page' => 1]) ?>" class="hover:text-blue-900">×</a>
                    </span>
                <?php endif; ?>
                <?php if ($fileType): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-green-50 text-green-700 rounded-full text-sm">
                        Type: <?= strtoupper(htmlspecialchars($fileType)) ?>
                        <a href="<?= buildDriveUrl(['type' => '', 'page' => 1]) ?>" class="hover:text-green-900">×</a>
                    </span>
                <?php endif; ?>
                <?php if ($sizeFilter): ?>
                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-amber-50 text-amber-700 rounded-full text-sm">
                        Size: <?= htmlspecialchars($sizeFilterLabels[$sizeFilter] ?? $sizeFilter) ?>
                        <a href="<?= buildDriveUrl(['size' => '', 'page' => 1]) ?>" class="hover:text-amber-900">×</a>
                    </span>
                <?php endif; ?>
                <a href="<?= buildDriveUrl(['q' => '', 'type' => '', 'size' => '', 'page' => 1]) ?>" class="text-sm text-gray-500 hover:text-gray-700">Clear all</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- File View -->
        <div class="flex-1 overflow-y-auto p-4">

            <!-- Mobile Toolbar (Folders + Search) -->
            <div class="md:hidden mb-4">
                <div class="flex items-center gap-2 overflow-x-auto pb-3">
                    <a href="?folder=root" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border <?= $folder === 'root' && !$search ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Drive</a>
                    <a href="?folder=category-FOIA" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border bg-white text-slate-700 border-slate-200">FOIA</a>
                    <a href="?folder=category-DOJ Disclosures" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border bg-white text-slate-700 border-slate-200">DOJ</a>
                    <a href="?folder=category-Court Records" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border bg-white text-slate-700 border-slate-200">Court</a>
                    <a href="?folder=emails-archive" class="flex-shrink-0 px-3 py-1.5 rounded-full text-xs font-bold border bg-white text-slate-700 border-slate-200">Emails</a>
                </div>

                <form method="GET" class="relative">
                    <input type="hidden" name="folder" value="<?= htmlspecialchars($folder) ?>">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </div>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                        class="block w-full pl-10 pr-3 py-2 border border-slate-200 rounded-lg leading-5 bg-slate-100 placeholder-slate-500 focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 text-sm"
                        placeholder="Search Drive...">
                </form>
            </div>
            
            <!-- Virtual Folders (Only show on Root) -->
            <?php if ($folder === 'root' && !$search): ?>
                <h2 class="text-sm font-medium text-gray-500 mb-4 ml-1">Folders</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-8">
                    <?php foreach ($virtualFolders['root'] as $vFolder): ?>
                        <a href="?folder=<?= $vFolder['id'] ?>" class="group flex flex-col justify-between p-4 bg-gray-50 border border-gray-200 rounded-xl hover:bg-blue-50 hover:border-blue-200 cursor-pointer transition-colors h-32">
                            <div class="flex items-start justify-between">
                                <svg class="w-10 h-10 text-gray-500 group-hover:text-blue-500 transition-colors" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path></svg>
                                <button class="p-1 rounded-full hover:bg-gray-200 opacity-0 group-hover:opacity-100 transition-opacity"><svg class="w-4 h-4 text-gray-600" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg></button>
                            </div>
                            <span class="text-sm font-medium text-gray-700 group-hover:text-blue-700 truncate"><?= htmlspecialchars($vFolder['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Files -->
            <?php if (!empty($documents)): ?>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-medium text-gray-500 ml-1">Files (<?= $folderStats['total'] ?>)</h2>
                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span><?= $folderStats['ocr_complete'] ?> OCR complete</span>
                        <span>•</span>
                        <span><?= $folderStats['ocr_pending'] ?> OCR pending</span>
                        <?php if ($folderStats['processed'] > 0): ?>
                            <span class="inline-flex items-center gap-1 text-purple-600">
                                <span class="w-2 h-2 bg-purple-500 rounded-full"></span>
                                <?= $folderStats['processed'] ?> AI-processed
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($documents as $doc): 
                        $docFileType = $doc['file_type'] ?? '';
                        $iconName = file_type_icon($docFileType);
                        $isImageFile = in_array($docFileType, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff']);
                        $isVideoFile = in_array($docFileType, ['video', 'mp4', 'webm', 'mov']);
                        $thumbnailUrl = null;
                        
                        if ($isImageFile || $isVideoFile) {
                            $thumbSource = $doc['source_url'] ?? '';
                            if (strpos($thumbSource, 'drive.google.com') !== false) {
                                $thumbnailUrl = getGoogleDriveThumbnail($thumbSource);
                            } elseif ($isImageFile) {
                                $thumbnailUrl = $thumbSource;
                            }
                        }
                        
                        $hasAI = !empty($doc['ai_summary']);
                        $hasOcr = !empty($doc['has_ocr']);
                    ?>
                        <a href="/document.php?id=<?= $doc['id'] ?>" class="group bg-white border border-gray-200 rounded-xl hover:shadow-lg hover:border-blue-300 transition-all overflow-hidden">
                            <?php if ($thumbnailUrl): ?>
                            <!-- Thumbnail Preview -->
                            <div class="h-32 bg-slate-100 overflow-hidden">
                                <img src="<?= htmlspecialchars($thumbnailUrl) ?>" 
                                     alt="<?= htmlspecialchars($doc['title']) ?>"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform"
                                     loading="lazy">
                                <?php if ($isVideoFile): ?>
                                <div class="absolute top-2 right-2 px-2 py-0.5 bg-black/60 rounded text-white text-[10px] font-bold flex items-center gap-1">
                                    <svg class="w-3 h-3" data-feather="film"></svg>
                                    VIDEO
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <!-- Header -->
                            <div class="p-4 border-b border-gray-100 bg-gradient-to-r from-slate-50 to-white">
                                <div class="flex items-start gap-3">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                        <svg class="w-6 h-6 text-blue-600" data-feather="<?= htmlspecialchars($iconName, ENT_QUOTES) ?>"></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-medium text-slate-800 line-clamp-2 group-hover:text-blue-600 transition-colors mb-1">
                                            <?= htmlspecialchars($doc['title']) ?>
                                        </h3>
                                        <div class="flex items-center gap-2 text-xs text-slate-500">
                                            <span><?= strtoupper($doc['file_type']) ?></span>
                                            <span>•</span>
                                            <span><?= date('M j, Y', strtotime($doc['created_at'])) ?></span>
                                            <?php if ($hasAI): ?>
                                                <span class="ml-auto px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full flex items-center gap-1">
                                                    ✨ AI
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- AI Summary Preview -->
                            <?php if ($hasAI): ?>
                                <div class="p-4 bg-white">
                                    <p class="text-xs text-slate-600 line-clamp-3 leading-relaxed">
                                        <?= htmlspecialchars(substr($doc['ai_summary'], 0, 150)) ?>...
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="p-4 bg-slate-50">
                                    <p class="text-xs text-slate-400 italic flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Processing AI summary...
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Footer Actions -->
                            <div class="px-4 py-3 bg-slate-50 border-t border-gray-100 flex items-center justify-between">
                                <span class="text-xs text-slate-500">
                                    <?php if ($doc['status'] === 'processed'): ?>
                                        <span class="text-green-600 flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                            Processed
                                        </span>
                                    <?php else: ?>
                                        <span class="text-amber-600 flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-pulse"></span>
                                            Pending
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <div class="flex items-center gap-2">
                                    <?php if (!$hasOcr): ?>
                                        <button type="button" class="px-2 py-1 text-[11px] font-bold rounded border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100" onclick="event.preventDefault(); event.stopPropagation(); reprocessDoc(<?= (int)$doc['id'] ?>, this)">
                                            Re-run OCR
                                        </button>
                                    <?php endif; ?>
                                    <span class="text-blue-600 group-hover:text-blue-700 text-xs font-medium flex items-center gap-1">
                                        View details
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="mt-8 flex items-center justify-between border-t border-gray-200 pt-6">
                    <div class="text-sm text-gray-500">
                        Page <?= $page ?> of <?= number_format($totalPages) ?> (<?= number_format($totalCount) ?> files)
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildDriveUrl(['page' => 1]) ?>" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">First</a>
                            <a href="<?= buildDriveUrl(['page' => $page - 1]) ?>" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">← Prev</a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <?php if ($i === $page): ?>
                                <span class="px-3 py-1 bg-blue-600 text-white rounded text-sm font-medium"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= buildDriveUrl(['page' => $i]) ?>" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildDriveUrl(['page' => $page + 1]) ?>" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">Next →</a>
                            <a href="<?= buildDriveUrl(['page' => $totalPages]) ?>" class="px-3 py-1 border border-gray-200 rounded hover:bg-gray-50 text-sm">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php elseif ($folder !== 'root' || $search): ?>
                <div class="flex flex-col items-center justify-center h-64 text-gray-400">
                    <svg class="w-16 h-16 mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" /></svg>
                    <p>Folder is empty</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
async function reprocessDoc(id, btnEl) {
    if (!id) return;
    const prevText = btnEl ? btnEl.textContent : '';
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.textContent = 'Queued...';
    }

    try {
        const res = await fetch('/api/reprocess_document.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, clear_pages: true })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) {
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.textContent = prevText || 'Re-run OCR';
            }
            alert((data && data.error) ? data.error : 'Unable to queue reprocess');
            return;
        }

        if (btnEl) {
            btnEl.textContent = 'Queued';
        }
    } catch (e) {
        if (btnEl) {
            btnEl.disabled = false;
            btnEl.textContent = prevText || 'Re-run OCR';
        }
        alert('Network error');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
