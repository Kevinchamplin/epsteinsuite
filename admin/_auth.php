<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

$adminUser = env_value('ADMIN_USER') ?: 'admin';
$adminPass = env_value('ADMIN_PASSWORD');

if (!$adminPass) {
    http_response_code(500);
    echo 'ADMIN_PASSWORD is not set.';
    exit;
}

$valid = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
    && hash_equals($adminUser, $_SERVER['PHP_AUTH_USER'])
    && hash_equals($adminPass, $_SERVER['PHP_AUTH_PW']);

if (!$valid) {
    header('WWW-Authenticate: Basic realm="Epstein Suite Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Auth required.';
    exit;
}

$pdo = db();
