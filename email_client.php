<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/cache.php';

$folder = $_GET['folder'] ?? 'inbox';
$search = $_GET['q'] ?? '';
$person = $_GET['person'] ?? '';
$filterFrom = $_GET['from'] ?? '';
$filterTo = $_GET['to'] ?? '';
$filterSubject = $_GET['subject'] ?? '';
$filterBody = $_GET['body'] ?? '';
$filterHasAttachment = isset($_GET['has_attachment']) ? (bool)(int)$_GET['has_attachment'] : false;
$page = (int)($_GET['page'] ?? 1);
$limit = 25;
$page = max(1, $page);
$offset = ($page - 1) * $limit;

function normalize_filter_value(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return $value;
}

function person_variants(string $person): array
{
    $person = normalize_filter_value($person);
    if ($person === '') {
        return [];
    }

    $variants = [$person];

    if (strpos($person, ',') !== false) {
        [$last, $first] = array_map('trim', explode(',', $person, 2));
        if ($first !== '' && $last !== '') {
            $variants[] = $first . ' ' . $last;
        }
    }

    $simplified = preg_replace('/[^a-z0-9@ ]+/i', ' ', $person) ?? $person;
    $simplified = normalize_filter_value($simplified);
    if ($simplified !== '' && $simplified !== $person) {
        $variants[] = $simplified;
    }

    $out = [];
    foreach ($variants as $v) {
        $v = normalize_filter_value($v);
        if ($v !== '' && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;
}

try {
    $pdo = db();
    
    $where = [];
    $params = [];
    $usedFulltext = false;
    $fulltextClause = 'MATCH(sender, recipient, subject, body) AGAINST (:q IN NATURAL LANGUAGE MODE)';

    if ($folder === 'starred') {
        $where[] = "is_starred = 1";
    } elseif ($folder === 'sent') {
        $where[] = "folder = 'sent'";
    } elseif ($folder === 'attachments') {
        $where[] = "attachments_count > 0";
    } else {
        $folder = 'inbox';
        $where[] = "folder = 'inbox'";
    }
    
    $person = normalize_filter_value((string)$person);
    $search = normalize_filter_value((string)$search);
    $filterFrom = normalize_filter_value((string)$filterFrom);
    $filterTo = normalize_filter_value((string)$filterTo);
    $filterSubject = normalize_filter_value((string)$filterSubject);
    $filterBody = normalize_filter_value((string)$filterBody);

    if ($person !== '') {
        $variants = person_variants($person);
        if (!empty($variants)) {
            $clauses = [];
            foreach ($variants as $idx => $variant) {
                $keyS = 'person' . $idx . '_sender';
                $keyR = 'person' . $idx . '_recipient';
                $keyC = 'person' . $idx . '_cc';
                $clauses[] = "(sender LIKE :$keyS OR recipient LIKE :$keyR OR cc LIKE :$keyC)";
                $params[":$keyS"] = "%$variant%";
                $params[":$keyR"] = "%$variant%";
                $params[":$keyC"] = "%$variant%";
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
    }

    if ($filterFrom !== '') {
        $where[] = 'sender LIKE :filter_from';
        $params[':filter_from'] = "%$filterFrom%";
    }
    if ($filterTo !== '') {
        $where[] = '(recipient LIKE :filter_to OR cc LIKE :filter_to)';
        $params[':filter_to'] = "%$filterTo%";
    }
    if ($filterSubject !== '') {
        $where[] = 'subject LIKE :filter_subject';
        $params[':filter_subject'] = "%$filterSubject%";
    }
    if ($filterBody !== '') {
        $where[] = 'body LIKE :filter_body';
        $params[':filter_body'] = "%$filterBody%";
    }
    if ($filterHasAttachment) {
        $where[] = 'attachments_count > 0';
    }

    if ($search !== '') {
        if (mb_strlen($search) >= 3) {
            $where[] = $fulltextClause;
            $params[':q'] = $search;
            $usedFulltext = true;
        } else {
            $where[] = "(sender LIKE :s_sender OR recipient LIKE :s_recipient OR cc LIKE :s_cc OR subject LIKE :s_subject OR body LIKE :s_body)";
            $params[':s_sender'] = "%$search%";
            $params[':s_recipient'] = "%$search%";
            $params[':s_cc'] = "%$search%";
            $params[':s_subject'] = "%$search%";
            $params[':s_body'] = "%$search%";
        }
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    try {
        $sql = "SELECT * FROM emails $whereClause ORDER BY sent_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $emails = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) FROM emails $whereClause";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if (!$usedFulltext || $search === '') {
            throw $e;
        }

        $whereFallback = [];
        foreach ($where as $clause) {
            if ($clause === $fulltextClause) {
                $whereFallback[] = '(sender LIKE :s_sender OR recipient LIKE :s_recipient OR cc LIKE :s_cc OR subject LIKE :s_subject OR body LIKE :s_body)';
            } else {
                $whereFallback[] = $clause;
            }
        }
        $whereClauseFallback = !empty($whereFallback) ? 'WHERE ' . implode(' AND ', $whereFallback) : '';

        unset($params[':q']);
        $params[':s_sender'] = "%$search%";
        $params[':s_recipient'] = "%$search%";
        $params[':s_cc'] = "%$search%";
        $params[':s_subject'] = "%$search%";
        $params[':s_body'] = "%$search%";

        $sql = "SELECT * FROM emails $whereClauseFallback ORDER BY sent_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $emails = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) FROM emails $whereClauseFallback";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
    }
    $totalPages = ceil($total / $limit);
    
    $folderCounts = Cache::remember('email_folder_counts', function () use ($pdo): array {
        return [
            'inbox' => (int)$pdo->query("SELECT COUNT(*) FROM emails WHERE folder = 'inbox'")->fetchColumn(),
            'starred' => (int)($pdo->query("SELECT COUNT(*) FROM emails WHERE is_starred = 1")->fetchColumn() ?: 0),
            'sent' => (int)$pdo->query("SELECT COUNT(*) FROM emails WHERE folder = 'sent'")->fetchColumn(),
            'attachments' => (int)$pdo->query("SELECT COUNT(*) FROM emails WHERE attachments_count > 0")->fetchColumn(),
        ];
    }, 300);
    $inboxCount = $folderCounts['inbox'];
    $starredCount = $folderCounts['starred'];
    $sentCount = $folderCounts['sent'];
    $attachmentsCount = $folderCounts['attachments'];

    $topPeople = Cache::remember('email_top_people', function () use ($pdo): array {
        return $pdo->query("
            SELECT sender, COUNT(*) as cnt
            FROM emails
            WHERE sender IS NOT NULL AND sender != ''
            GROUP BY sender
            ORDER BY cnt DESC
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);
    }, 600);

} catch (Exception $e) {
    $emails = [];
    $total = 0;
    $totalPages = ceil($total / $limit);
    $inboxCount = 0;
    $starredCount = 0;
    $sentCount = 0;
    $attachmentsCount = 0;
    $topPeople = [];
}

$page_title = 'Epstein Mail - Inbox';
$meta_description = 'Browse extracted emails from the Epstein files archive. Search by sender, recipient, subject, or body text across the DOJ email releases.';
$og_title = 'Epstein Mail — Searchable Email Archive';
require_once __DIR__ . '/includes/header_suite.php';
?>

<style>
    .mail-shell {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 4rem);
        overflow: hidden;
    }
    .mail-body {
        display: flex;
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }
    .mail-sidebar {
        width: 14rem;
        flex-shrink: 0;
        overflow-y: auto;
        background: #f3f4f6;
    }
    .mail-main {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        background: white;
    }
    .mail-list {
        flex: 1;
        overflow-y: auto;
    }
    @media (max-width: 767px) {
        .mail-sidebar {
            display: none;
        }
    }
</style>

<div class="mail-shell bg-gray-100">
    <div class="mail-body">
        <!-- Sidebar (desktop only) -->
        <aside class="mail-sidebar py-2 hidden md:block">
            <div class="px-3 mb-4">
                <a href="/" class="flex items-center gap-3 bg-blue-600 hover:bg-blue-700 shadow-md hover:shadow-lg rounded-2xl px-5 py-3 transition-all text-white font-medium">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    <span>Back to Search</span>
                </a>
            </div>

            <nav class="flex-1 space-y-0.5 text-sm">
                <a href="?folder=inbox" class="flex items-center gap-4 px-4 py-1.5 rounded-r-full <?= $folder === 'inbox' && !$person && !$search ? 'bg-blue-100 text-blue-800 font-bold' : 'hover:bg-gray-200 text-gray-700' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                    Inbox
                    <span class="ml-auto text-xs font-bold"><?= number_format($inboxCount) ?></span>
                </a>
                <a href="?folder=starred" class="flex items-center gap-4 px-4 py-1.5 rounded-r-full <?= $folder === 'starred' ? 'bg-blue-100 text-blue-800 font-bold' : 'hover:bg-gray-200 text-gray-700' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                    Starred
                    <span class="ml-auto text-xs text-gray-500"><?= number_format($starredCount) ?></span>
                </a>
                <a href="?folder=sent" class="flex items-center gap-4 px-4 py-1.5 rounded-r-full <?= $folder === 'sent' ? 'bg-blue-100 text-blue-800 font-bold' : 'hover:bg-gray-200 text-gray-700' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
                    Sent
                    <span class="ml-auto text-xs text-gray-500"><?= number_format($sentCount) ?></span>
                </a>
                <a href="?folder=attachments" class="flex items-center gap-4 px-4 py-1.5 rounded-r-full <?= $folder === 'attachments' ? 'bg-blue-100 text-blue-800 font-bold' : 'hover:bg-gray-200 text-gray-700' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                    Attachments
                    <span class="ml-auto text-xs text-gray-500"><?= number_format($attachmentsCount) ?></span>
                </a>
                
                <div class="mt-6 pt-4 border-t border-gray-300">
                    <div class="px-4 py-2 text-xs font-bold text-gray-500 uppercase tracking-wider">People</div>
                    <a href="/contacts.php" class="flex items-center gap-3 px-4 py-1.5 rounded-r-full hover:bg-gray-200 text-blue-600 font-medium">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        All Contacts
                    </a>
                    <?php foreach (array_slice($topPeople, 0, 12) as $p): 
                        $name = trim($p['sender']);
                        if (strlen($name) > 20) $name = substr($name, 0, 20) . '...';
                        $isActive = normalize_filter_value((string)$person) === normalize_filter_value((string)$p['sender']);
                    ?>
                        <a href="?folder=<?= urlencode($folder) ?>&person=<?= urlencode($p['sender']) ?>&page=1" class="flex items-center gap-3 px-4 py-1 rounded-r-full <?= $isActive ? 'bg-blue-100 text-blue-800 font-medium' : 'hover:bg-gray-200 text-gray-600' ?> text-xs truncate">
                            <span class="w-5 h-5 rounded-full bg-gray-300 flex items-center justify-center text-[10px] font-bold text-gray-600"><?= strtoupper(substr($name, 0, 1)) ?></span>
                            <?= htmlspecialchars($name) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="mail-main md:rounded-tl-2xl md:border-l md:border-t border-gray-200">
            <!-- AI Overview Banner -->
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 border-b border-gray-200 px-4 sm:px-6 py-3 flex-shrink-0">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg">✨</span>
                    <span class="font-medium text-gray-800">AI Overview</span>
                </div>
                <p class="text-sm text-gray-600">
                    Explore extracted email headers and content from public-source records. Browse by name, star items, search, or
                    <a href="?page=<?= rand(1, max(1, $totalPages)) ?>" class="text-blue-600 hover:underline">visit a random page</a>.
                </p>
                <form method="GET" class="mt-3 grid grid-cols-1 md:grid-cols-5 gap-2 text-sm">
                    <input type="hidden" name="folder" value="<?= htmlspecialchars($folder) ?>">
                    <input type="hidden" name="page" value="1">
                    <div class="col-span-1">
                        <label class="text-xs font-semibold text-slate-500 block mb-1">Keyword</label>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white"
                               placeholder="Any field">
                    </div>
                    <div class="col-span-1">
                        <label class="text-xs font-semibold text-slate-500 block mb-1">From</label>
                        <input type="text" name="from" value="<?= htmlspecialchars($filterFrom) ?>"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white"
                               placeholder="Sender/email">
                    </div>
                    <div class="col-span-1">
                        <label class="text-xs font-semibold text-slate-500 block mb-1">To / CC</label>
                        <input type="text" name="to" value="<?= htmlspecialchars($filterTo) ?>"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white"
                               placeholder="Recipient">
                    </div>
                    <div class="col-span-1">
                        <label class="text-xs font-semibold text-slate-500 block mb-1">Subject</label>
                        <input type="text" name="subject" value="<?= htmlspecialchars($filterSubject) ?>"
                               class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white"
                               placeholder="Subject text">
                    </div>
                    <div class="col-span-1">
                        <label class="text-xs font-semibold text-slate-500 block mb-1">Body / Attachments</label>
                        <div class="flex gap-2 items-center">
                            <input type="text" name="body" value="<?= htmlspecialchars($filterBody) ?>"
                                   class="w-full border border-slate-200 rounded-lg px-3 py-2 bg-white"
                                   placeholder="Message text">
                            <label class="flex items-center gap-1 text-xs text-slate-500">
                                <input type="checkbox" name="has_attachment" value="1" <?= $filterHasAttachment ? 'checked' : '' ?>>
                                Has attachments
                            </label>
                            <button type="submit"
                                    class="px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700">
                                Search
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Mobile Toolbar -->
            <div class="md:hidden border-b border-gray-200 bg-white p-4 flex-shrink-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="?folder=inbox" class="px-3 py-1.5 rounded-full text-xs font-bold border <?= $folder === 'inbox' && !$person && !$search ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Inbox</a>
                    <a href="?folder=starred" class="px-3 py-1.5 rounded-full text-xs font-bold border <?= $folder === 'starred' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Starred</a>
                    <a href="?folder=sent" class="px-3 py-1.5 rounded-full text-xs font-bold border <?= $folder === 'sent' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Sent</a>
                    <a href="?folder=attachments" class="px-3 py-1.5 rounded-full text-xs font-bold border <?= $folder === 'attachments' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-200' ?>">Attachments</a>
                </div>
                <form method="GET" class="mt-3 relative">
                    <input type="hidden" name="folder" value="<?= htmlspecialchars($folder) ?>">
                    <?php if ($person): ?>
                        <input type="hidden" name="person" value="<?= htmlspecialchars($person) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="page" value="1">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </div>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                        class="block w-full pl-10 pr-3 py-2 border border-slate-200 rounded-lg leading-5 bg-slate-100 placeholder-slate-500 focus:outline-none focus:bg-white focus:ring-1 focus:ring-blue-500 text-sm"
                        placeholder="Search mail...">
                </form>
            </div>
            
            <!-- Toolbar -->
            <div class="h-12 border-b border-gray-200 flex items-center px-4 gap-4 text-gray-500 bg-white flex-shrink-0">
                <div class="flex items-center gap-1">
                    <input type="checkbox" class="rounded border-gray-300">
                    <button class="p-1 hover:bg-gray-100 rounded"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg></button>
                </div>
                <button class="p-2 hover:bg-gray-100 rounded"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg></button>
                
                <div class="ml-auto text-sm flex items-center gap-4">
                    <span><?= $offset + 1 ?>-<?= min($offset + $limit, $total) ?> of <?= $total ?></span>
                    <div class="flex">
                        <button class="p-2 hover:bg-gray-100 rounded disabled:opacity-50" <?= $page <= 1 ? 'disabled' : '' ?> onclick="window.location.href='?page=<?= $page - 1 ?>&folder=<?= urlencode($folder) ?>&person=<?= urlencode($person) ?>&q=<?= urlencode($search) ?>'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                        </button>
                        <button class="p-2 hover:bg-gray-100 rounded disabled:opacity-50" <?= $page >= $totalPages ? 'disabled' : '' ?> onclick="window.location.href='?page=<?= $page + 1 ?>&folder=<?= urlencode($folder) ?>&person=<?= urlencode($person) ?>&q=<?= urlencode($search) ?>'">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Email List -->
            <div class="mail-list bg-white">
                <?php if (empty($emails)): ?>
                    <div class="flex flex-col items-center justify-center h-full text-gray-400 py-16">
                        <svg class="w-16 h-16 mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                        <p>No messages found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($emails as $email):
                        $isRead = !empty($email['is_read']);
                        $isStarred = !empty($email['is_starred']);
                        $snippet = substr((string)($email['body'] ?? ''), 0, 140);
                    ?>
                        <div role="button" tabindex="0"
                             class="flex items-start gap-3 px-4 py-3 border-b border-gray-100 hover:bg-slate-50 cursor-pointer transition-colors <?= $isRead ? 'bg-white' : 'bg-blue-50/30' ?>"
                             onclick="openEmail(<?= htmlspecialchars(json_encode($email)) ?>)">
                            
                            <div class="hidden sm:flex items-center gap-2 pt-1 flex-shrink-0">
                                <input type="checkbox" class="rounded border-gray-300" onclick="event.stopPropagation()">
                                <button class="p-1 rounded hover:bg-gray-100 text-gray-400" onclick="event.stopPropagation(); toggleStar(<?= (int)$email['id'] ?>)">
                                    <?php if ($isStarred): ?>
                                        <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.967a1 1 0 00.95.69h4.174c.969 0 1.371 1.24.588 1.81l-3.377 2.453a1 1 0 00-.363 1.118l1.286 3.967c.3.922-.755 1.688-1.538 1.118l-3.377-2.453a1 1 0 00-1.176 0l-3.377 2.453c-.783.57-1.838-.197-1.538-1.118l1.286-3.967a1 1 0 00-.363-1.118L2.05 9.094c-.784-.57-.38-1.81.588-1.81h4.174a1 1 0 00.95-.69l1.286-3.967z"/></svg>
                                    <?php else: ?>
                                        <svg class="w-5 h-5 hover:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                                    <?php endif; ?>
                                </button>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="<?= $isRead ? 'font-semibold' : 'font-bold' ?> text-slate-900 truncate text-sm">
                                            <?= htmlspecialchars($email['subject'] ?: '(No Subject)') ?>
                                        </div>
                                        <div class="text-xs text-slate-600 truncate mt-0.5">
                                            <?= htmlspecialchars($email['sender'] ?: 'Unknown Sender') ?>
                                        </div>
                                        <div class="text-xs text-slate-500 truncate mt-1">
                                            <?= htmlspecialchars($snippet) ?><?= strlen($email['body'] ?? '') > 140 ? '…' : '' ?>
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1 flex-shrink-0">
                                        <div class="text-xs text-slate-500 font-medium">
                                            <?php if (!empty($email['sent_at'])): ?>
                                                <?= date('M j', strtotime($email['sent_at'])) ?>
                                            <?php endif; ?>
                                        </div>
                                        <button class="sm:hidden p-1 rounded hover:bg-gray-100 text-gray-400" onclick="event.stopPropagation(); toggleStar(<?= (int)$email['id'] ?>)">
                                            <?php if ($isStarred): ?>
                                                <svg class="w-4 h-4 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.967a1 1 0 00.95.69h4.174c.969 0 1.371 1.24.588 1.81l-3.377 2.453a1 1 0 00-.363 1.118l1.286 3.967c.3.922-.755 1.688-1.538 1.118l-3.377-2.453a1 1 0 00-1.176 0l-3.377 2.453c-.783.57-1.838-.197-1.538-1.118l1.286-3.967a1 1 0 00-.363-1.118L2.05 9.094c-.784-.57-.38-1.81.588-1.81h4.174a1 1 0 00.95-.69l1.286-3.967z"/></svg>
                                            <?php else: ?>
                                                <svg class="w-4 h-4 hover:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Email Modal -->
<div id="emailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4 sm:p-8">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-4xl h-full max-h-[90vh] flex flex-col">
        <div class="h-16 border-b border-gray-100 flex items-center justify-between px-4 sm:px-6 flex-shrink-0">
            <h2 class="text-lg font-medium truncate" id="modalSubject">Subject</h2>
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-sm text-gray-500" id="modalDate">Date</span>
                <button onclick="closeEmail()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto p-4 sm:p-6 bg-white">
            <div class="flex items-start gap-4 mb-6">
                <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center text-gray-500 font-bold text-lg flex-shrink-0">
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path></svg>
                </div>
                <div class="min-w-0">
                    <div class="font-bold text-black flex items-center gap-2 flex-wrap">
                        <span id="modalSender">Sender</span>
                        <span class="text-gray-400 font-normal text-sm">&lt;unknown@example.com&gt;</span>
                    </div>
                    <div class="text-sm text-gray-500">to <span id="modalRecipient">me</span></div>
                </div>
            </div>
            <div class="prose max-w-none text-gray-800 whitespace-pre-wrap font-mono text-sm" id="modalBody">
                Body
            </div>
        </div>
        <div class="bg-gray-50 p-4 border-t border-gray-200 flex justify-end gap-2 flex-shrink-0">
            <a href="/document.php" id="modalDocLink" class="px-4 py-2 bg-blue-600 text-white rounded text-sm font-bold hover:bg-blue-700">View Source Document</a>
            <button onclick="closeEmail()" class="px-4 py-2 bg-white border border-gray-300 rounded text-sm font-bold hover:bg-gray-50">Close</button>
        </div>
    </div>
</div>

<script>
    function openEmail(email) {
        document.getElementById('modalSubject').textContent = email.subject || '(No Subject)';
        document.getElementById('modalSender').textContent = email.sender || 'Unknown';
        document.getElementById('modalRecipient').textContent = email.recipient || 'Unknown';
        document.getElementById('modalBody').textContent = email.body;
        document.getElementById('modalDate').textContent = email.sent_at ? new Date(email.sent_at).toLocaleDateString() : '';
        var docLink = document.getElementById('modalDocLink');
        if (email.document_id) {
            docLink.href = '/document.php?id=' + email.document_id;
            docLink.style.display = '';
        } else {
            docLink.style.display = 'none';
        }

        document.getElementById('emailModal').classList.remove('hidden');
    }

    function closeEmail() {
        document.getElementById('emailModal').classList.add('hidden');
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === "Escape") {
            closeEmail();
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer_suite.php'; ?>
</body>
</html>
