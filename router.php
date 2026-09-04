<?php
declare(strict_types=1);

$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = rawurldecode($path);
$p = strtolower($path);

$denied = str_contains($p, '..')
    || $p === '/db_conn.php'
    || $p === '/.env'
    || str_starts_with($p, '/.env.')
    || str_starts_with($p, '/data/')
    || $p === '/data'
    || str_starts_with($p, '/scripts/')
    || $p === '/scripts'
    || str_starts_with($p, '/common/')
    || $p === '/common'
    || str_starts_with($p, '/.git/')
    || $p === '/.git'
    || (bool)preg_match('/\.(sql|env|sh)$/', $p);

if ($denied) {
    http_response_code(404);
    exit;
}

return false;
