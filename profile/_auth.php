<?php
declare(strict_types=1);

// стартуем сессию безопасно — только если её ещё нет
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// если пользователь не авторизован — редиректим на страницу логина/индекса,
// но НЕ редиректим, если уже находимся на странице логина (чтобы избежать петли)
if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
    // текущий запрошенный URI (например "/index.php" или "/profile/index.php")
    $req = $_SERVER['REQUEST_URI'] ?? '';

    // список путей, на которые НЕ нужно редиректить (локальные страницы логина)
    $noRedirectPaths = [
        '/index.php',
        '/login.php',
        '/auth/login.php'
    ];

    // если текущий путь не один из "no redirect", то делаем редирект на /index.php
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
