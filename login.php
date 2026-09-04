<?php
// login.php — безопасный логин + миграция + "Запомнить меня"
declare(strict_types=1);
require_once __DIR__ . '/common/session.php';
app_session_start();
require_once __DIR__ . '/common/csrf.php';
csrf_check();
require_once __DIR__ . '/db_conn.php';

$ip = client_ip();

if (isset($_POST['totp_cancel'])) {
    unset($_SESSION['pending_2fa']);
    header('Location: /index.php');
    exit;
}

if (isset($_POST['totp_code'])) {
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!is_array($pending) || (int)($pending['exp'] ?? 0) < time()) {
        unset($_SESSION['pending_2fa']);
        header('Location: /index.php?err=3');
        exit;
    }
    $login = (string)($pending['login'] ?? '');
    if (login_rate_limited($ip, $login)) {
        header('Location: /index.php?err=4');
        exit;
    }
    $uid = (int)($pending['id'] ?? 0);
    $st = $con->prepare('SELECT id, login, name, role, totp_secret, totp_enabled FROM users WHERE id=? LIMIT 1');
    $st->bind_param('i', $uid);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
    if ($user && totp_verify_user($con, $user, (string)$_POST['totp_code'])) {
        unset($_SESSION['pending_2fa']);
        session_regenerate_id(true);
        session_login_user($user);
        if (!empty($pending['remember'])) {
            remember_issue($con, (int)$user['id']);
        }
        login_rate_clear($ip, $login);
        $home = (($_SESSION['role'] ?? '') === 'admin') ? '/profile/settings.php' : '/profile/schedule.php';
        header('Location: ' . $home);
        exit;
    }
    login_rate_hit($ip, $login);
    header('Location: /index.php?err=2');
    exit;
}

$login = $_POST['login'] ?? '';
$pass  = $_POST['password'] ?? '';

if (login_rate_limited($ip, $login)) {
    header("Location: /index.php?err=4");
    exit;
}

$stmt = $con->prepare("SELECT id, login, password_hash, name, role, totp_secret, totp_enabled FROM users WHERE login = ? LIMIT 1");
$stmt->bind_param('s', $login);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

$ok = false;
if ($user && !empty($user['password_hash'])) {
    $ok = password_verify($pass, $user['password_hash']);
}

if ($ok) {
    if (totp_user_on($user)) {
        session_regenerate_id(true);
        $_SESSION['pending_2fa'] = [
            'id' => (int)$user['id'],
            'login' => (string)$user['login'],
            'remember' => isset($_POST['remember']) && $_POST['remember'] == '1',
            'exp' => time() + 300,
        ];
        unset($_SESSION['csrf']);
        header('Location: /index.php?step=2fa');
        exit;
    }

    session_regenerate_id(true);
    session_login_user($user);

    if (isset($_POST['remember']) && $_POST['remember'] == '1') {
        remember_issue($con, (int)$user['id']);
    }

    login_rate_clear($ip, $login);
    $home = (($_SESSION['role'] ?? '') === 'admin') ? '/profile/settings.php' : '/profile/schedule.php';
    header("Location: " . $home);
    exit;
}

login_rate_hit($ip, $login);
header("Location: /index.php?err=1");
