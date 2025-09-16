<?php
// db_conn.php — mysqli + ENV (Railway + fallback на стандартные MYSQL* от Railway)
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1) сначала пробуем ваши DB_* ; если их нет — используем MYSQL* от Railway
$host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
$port = (int)(getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306);
$db   = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'railway';
$user = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
$pass = getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '';

// 2) подключение
$con = mysqli_init();
mysqli_real_connect($con, $host, $user, $pass, $db, $port);
if (!$con) {
    die("Ошибка подключения: " . mysqli_connect_error());
}
mysqli_set_charset($con, "utf8mb4");
