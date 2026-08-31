<?php
// db_conn.php — mysqli + ENV (Railway + fallback на стандартные MYSQL* от Railway)
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
if (getenv('APP_DEBUG') === '1') {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    $perm = fileperms($envFile) & 0777;
    if ($perm !== 0600) {
        @chmod($envFile, 0600);
        error_log('Tutor: .env permissions tightened to 0600');
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if ($k === '' || getenv($k) !== false) continue;
        $v = trim($v, " \t\"'");
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }
}

// 1) сначала пробуем ваши DB_* ; если их нет — используем MYSQL* от Railway
$host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
$port = (int)(getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306);
$db   = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'tutormy';
$user = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
$pass = getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '';

// 2) подключение
$con = mysqli_init();
$flags = 0;
$loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
if (!$loopback && getenv('DB_SSL') === '1') {
    $flags = MYSQLI_CLIENT_SSL;
}
try {
    mysqli_real_connect($con, $host, $user, $pass, $db, $port, '', $flags);
} catch (Throwable $e) {
    error_log('Tutor DB connect: ' . $e->getMessage());
    die('Ошибка подключения');
}
if (!$con) {
    die('Ошибка подключения');
}
mysqli_set_charset($con, "utf8mb4");
require_once __DIR__ . '/common/intervals.php';
require_once __DIR__ . '/common/settings.php';
require_once __DIR__ . '/common/activity.php';
require_once __DIR__ . '/common/groups.php';
require_once __DIR__ . '/common/util.php';
require_once __DIR__ . '/common/auth.php';
require_once __DIR__ . '/common/totp.php';
if (!schema_is_current($con)) {
    ensure_schedule_time_end($con);
    ensure_dates_time_schema($con);
    ensure_remember_tokens($con);
    ensure_roles_schema($con);
    ensure_totp_schema($con);
    $phoneCol = $con->query("SHOW COLUMNS FROM stud LIKE 'phone'");
    if ($phoneCol && $phoneCol->num_rows > 0) {
        $con->query("ALTER TABLE stud DROP COLUMN phone");
    }
    ensure_settings_table($con);
    ensure_activity_table($con);
    ensure_column($con, 'pays', 'voice', '`voice` TINYINT NOT NULL DEFAULT 0');
    save_app_setting($con, 'schema_version', (string)SCHEMA_VERSION);
}
load_app_settings($con);
