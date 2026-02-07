<?php
declare(strict_types=1);

if (defined('EFS_SHIELD_ACTIVE')) {
    return;
}
define('EFS_SHIELD_ACTIVE', true);

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$blockedAgents = [
    'GPTBot',
    'ChatGPT',
    'ChatGPT-User',
    'Google-Extended',
    'CCBot',
    'Bytespider',
    'Claude-Web',
    'Omgili',
    'omgibot',
    'DataForSeoBot',
    'SentiBot',
    'phxbot',
    'yahoocachesystem',
    'linkfluence',
];

foreach ($blockedAgents as $needle) {
    if ($needle !== '' && stripos($ua, $needle) !== false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Access denied.');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('efs_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

header('X-Robots-Tag: noai, noimageai');
header('Permissions-Policy: interest-cohort=()');

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucket = date('YmdHi');
$key = $bucket . '_' . $ip;

if (!isset($_SESSION['shield_rate'])) {
    $_SESSION['shield_rate'] = [];
}

// Drop stale buckets to keep session lean
foreach (array_keys($_SESSION['shield_rate']) as $storedKey) {
    if (substr($storedKey, 0, 12) !== $bucket) {
        unset($_SESSION['shield_rate'][$storedKey]);
    }
}

$count = ($_SESSION['shield_rate'][$key] ?? 0) + 1;
$_SESSION['shield_rate'][$key] = $count;

if ($count > 120) {
    http_response_code(429);
    header('Retry-After: 120');
    exit('Too many requests. Please slow down.');
}
