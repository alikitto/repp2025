<?php
declare(strict_types=1);
require_once __DIR__ . '/common/session.php';
app_session_start();
require_once __DIR__ . '/common/csrf.php';
csrf_check();
require_once __DIR__ . '/db_conn.php';

if (!empty($_COOKIE['remember_me'])) {
    $parts = explode(':', (string)$_COOKIE['remember_me'], 2);
    remember_revoke($con, $parts[0] ?? '');
}
remember_cookie_clear();
$_SESSION = [];
session_destroy();
header("Location: /index.php");
