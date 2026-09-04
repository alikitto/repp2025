<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/session.php';
app_session_start();

if (!isset($_SESSION['id']) && isset($_COOKIE['remember_me'])) {
    require_once __DIR__ . '/../db_conn.php';

    $parts = explode(':', (string)$_COOKIE['remember_me'], 2);
    $selector = $parts[0] ?? '';
    $validator = $parts[1] ?? '';

    if ($selector !== '' && $validator !== '') {
        $user = remember_find($con, $selector);
        if ($user && password_verify($validator, $user['remember_token_hashed'])) {
            remember_revoke($con, $selector);
            session_regenerate_id(true);
            if (totp_user_on($user)) {
                $_SESSION['pending_2fa'] = [
                    'id' => (int)$user['id'],
                    'login' => (string)$user['login'],
                    'remember' => true,
                    'exp' => time() + 300,
                ];
                unset($_SESSION['csrf']);
                header('Location: /index.php?step=2fa');
                exit;
            }
            session_login_user($user);
            remember_issue($con, (int)$user['id']);
        } else {
            remember_revoke($con, $selector);
            remember_cookie_clear();
        }
    }
}
// --- КОНЕЦ НОВОГО БЛОКА ---


// если пользователь все еще не авторизован (ни через сессию, ни через cookie) — редиректим
if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $noRedirectPaths = ['/index.php', '/login.php', '/auth/login.php', '/'];
    if (!in_array($path, $noRedirectPaths, true)) {
        header('Location: /index.php');
        exit;
    }
} elseif (!empty($_SESSION['id'])) {
    require_once __DIR__ . '/../common/auth.php';
    require_once __DIR__ . '/../db_conn.php';
    $epoch = session_epoch($con);
    if ($epoch > 0 && (int)($_SESSION['session_epoch'] ?? 0) !== $epoch) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        remember_cookie_clear();
        header('Location: /index.php');
        exit;
    }
    $rid = (int)$_SESSION['id'];
    $rs = $con->prepare("SELECT role, session_rev FROM users WHERE id=? LIMIT 1");
    $rs->bind_param('i', $rid);
    $rs->execute();
    $urow = $rs->get_result()->fetch_assoc() ?: [];
    $rs->close();
    $dbRev = (int)($urow['session_rev'] ?? 0);
    if ($dbRev > 0 && (int)($_SESSION['session_rev'] ?? 0) !== $dbRev) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        remember_cookie_clear();
        header('Location: /index.php');
        exit;
    }
    $_SESSION['role'] = (($urow['role'] ?? '') === 'admin') ? 'admin' : 'teacher';
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    enforce_role_path($path);
}
