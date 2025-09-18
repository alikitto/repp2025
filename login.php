<?php
// login.php — безопасный логин + миграция + "Запомнить меня"
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db_conn.php';

$login = $_POST['login'] ?? '';
$pass  = $_POST['password'] ?? '';

$stmt = $con->prepare("SELECT id, login, password, password_hash, name FROM users WHERE login = ? LIMIT 1");
$stmt->bind_param('s', $login);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

$ok = false;
if ($user) {
    if (!empty($user['password_hash'])) {
        $ok = password_verify($pass, $user['password_hash']);
    } elseif (!empty($user['password'])) {
        if (hash_equals($user['password'], $pass)) {
            $ok = true;
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $u = $con->prepare("UPDATE users SET password_hash=? WHERE id=?");
            $u->bind_param('si', $hash, $user['id']);
            $u->execute();
            $u->close();
        }
    }
}

if ($ok) {
    // Устанавливаем стандартные сессии
    $_SESSION['login'] = $user['login'];
    $_SESSION['id'] = (int)$user['id'];
    $_SESSION['name'] = $user['name'] ?: $user['login'];

    // --- НОВЫЙ БЛОК ДЛЯ "ЗАПОМНИТЬ МЕНЯ" ---
    if (isset($_POST['remember']) && $_POST['remember'] == '1') {
        // Генерируем токены
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));

        // Устанавливаем cookie на 30 дней
        setcookie(
            'remember_me',
            $selector . ':' . $validator,
            [
                'expires' => time() + 86400 * 30, // 86400 секунд = 1 день
                'path' => '/',
                'secure' => true, // Отправлять только по HTTPS
                'httponly' => true, // Защита от доступа через JavaScript
                'samesite' => 'Lax'
            ]
        );

        // Сохраняем токены в базу данных
        $hashed_validator = password_hash($validator, PASSWORD_DEFAULT);
        $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
        $user_id = (int)$user['id'];

        $stmt_update = $con->prepare(
            "UPDATE users SET 
                remember_token_selector = ?, 
                remember_token_hashed = ?, 
                remember_token_expires = ? 
            WHERE id = ?"
        );
        $stmt_update->bind_param('sssi', $selector, $hashed_validator, $expires, $user_id);
        $stmt_update->execute();
        $stmt_update->close();
    }
    // --- КОНЕЦ НОВОГО БЛОКА ---

    header("Location: /profile/index.php");
    exit;
}

header("Location: /index.php?err=1");
