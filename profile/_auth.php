<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- НОВЫЙ БЛОК АВТО-ЛОГИНА ПО COOKIE ---
// Проверяем, если пользователь НЕ залогинен, но у него есть cookie "remember_me"
if (!isset($_SESSION['id']) && isset($_COOKIE['remember_me'])) {
    
    // Подключаем базу данных, это обязательно для проверки токена
    require_once __DIR__ . '/../db_conn.php';

    list($selector, $validator) = explode(':', $_COOKIE['remember_me'], 2);

    if ($selector && $validator) {
        // Ищем публичную часть (selector) в базе данных
        $stmt_auth = $con->prepare(
            "SELECT id, login, name, remember_token_hashed 
            FROM users 
            WHERE remember_token_selector = ? AND remember_token_expires > NOW() 
            LIMIT 1"
        );
        $stmt_auth->bind_param('s', $selector);
        $stmt_auth->execute();
        $user = $stmt_auth->get_result()->fetch_assoc();
        $stmt_auth->close();

        if ($user && password_verify($validator, $user['remember_token_hashed'])) {
            // Успех! Логиним пользователя, создавая сессию
            $_SESSION['login'] = $user['login'];
            $_SESSION['id'] = (int)$user['id'];
            $_SESSION['name'] = $user['name'] ?: $user['login'];
        } else {
            // Если токен неверный или истек, удаляем cookie у пользователя
            setcookie('remember_me', '', time() - 3600, '/');
        }
    }
}
// --- КОНЕЦ НОВОГО БЛОКА ---


// если пользователь все еще не авторизован (ни через сессию, ни через cookie) — редиректим
if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
    $req = $_SERVER['REQUEST_URI'] ?? '';
    $noRedirectPaths = ['/index.php', '/login.php', '/auth/login.php'];

    $shouldRedirect = true;
    foreach ($noRedirectPaths as $p) {
        if ($p !== '' && stripos($req, $p) !== false) {
            $shouldRedirect = false;
            break;
        }
    }

    if ($shouldRedirect) {
        header('Location: /index.php');
        exit;
    }
}
