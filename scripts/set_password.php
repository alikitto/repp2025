<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$login = (string)($argv[1] ?? '');
$pass = (string)($argv[2] ?? '');
if ($login === '' || strlen($pass) < 8) {
    fwrite(STDERR, "Usage: php scripts/set_password.php LOGIN PASSWORD\n");
    exit(1);
}

require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/util.php';

$st = $con->prepare("SELECT id FROM users WHERE login=? LIMIT 1");
$st->bind_param('s', $login);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row) {
    fwrite(STDERR, "User not found\n");
    exit(1);
}
$uid = (int)$row['id'];
$hash = password_hash($pass, PASSWORD_BCRYPT);
$up = $con->prepare("UPDATE users SET password_hash=? WHERE id=?");
$up->bind_param('si', $hash, $uid);
$up->execute();
$up->close();
remember_revoke_user($con, $uid);
bump_user_session_rev($con, $uid);
echo "Password updated\n";
