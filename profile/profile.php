<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) {
    header('Location: /index.php');
    exit;
}

$st = $con->prepare("SELECT login, name, password_hash, fkey, totp_secret, totp_enabled FROM users WHERE id=? LIMIT 1");
$st->bind_param('i', $uid);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();
if (!$user) {
    header('Location: /index.php');
    exit;
}

$flash = '';
$flash_err = '';
$login_val = (string)$user['login'];
$name_val = (string)($user['name'] ?? '');
$has_pin = trim((string)($user['fkey'] ?? '')) !== '';
$totp_on = totp_user_on($user);
$totp_uri = '';
$profile_tab = (string)($_GET['tab'] ?? 'profile');
if ($profile_tab !== 'security') $profile_tab = 'profile';

function profile_password_ok(array $user, string $pass): bool {
    if ($pass === '' || empty($user['password_hash'])) return false;
    return password_verify($pass, (string)$user['password_hash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_start'])) {
    csrf_check();
    $profile_tab = 'security';
    $current = (string)($_POST['totp_password'] ?? '');
    if ($totp_on) {
        $flash_err = 'Двухфакторная авторизация уже включена.';
    } elseif (!profile_password_ok($user, $current)) {
        $flash_err = 'Текущий пароль неверный.';
    } else {
        $_SESSION['totp_setup'] = totp_secret_new();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_cancel'])) {
    csrf_check();
    $profile_tab = 'security';
    unset($_SESSION['totp_setup']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_confirm'])) {
    csrf_check();
    $profile_tab = 'security';
    $secret = (string)($_SESSION['totp_setup'] ?? '');
    if ($totp_on) {
        $flash_err = 'Двухфакторная авторизация уже включена.';
    } elseif ($secret === '' || !totp_verify($secret, (string)($_POST['totp_code'] ?? ''))) {
        $flash_err = 'Неверный код. Проверьте время на телефоне и попробуйте снова.';
    } else {
        $stored = function_exists('secret_encrypt') ? secret_encrypt($secret) : $secret;
        $last = isset($GLOBALS['_totp_used_counter']) ? (int)$GLOBALS['_totp_used_counter'] : 0;
        $up = $con->prepare('UPDATE users SET totp_secret=?, totp_enabled=1, totp_last_counter=? WHERE id=?');
        $up->bind_param('sii', $stored, $last, $uid);
        $up->execute();
        $up->close();
        unset($_SESSION['totp_setup']);
        $user['totp_secret'] = $secret;
        $user['totp_enabled'] = 1;
        $totp_on = true;
        remember_revoke_user($con, $uid);
        bump_user_session_rev($con, $uid);
        $flash = 'Двухфакторная авторизация включена.';
        log_activity($con, 'settings', 'Включена двухфакторная авторизация', '/profile/profile.php?tab=security');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_disable'])) {
    csrf_check();
    $profile_tab = 'security';
    $current = (string)($_POST['totp_password'] ?? '');
    if (!$totp_on) {
        $flash_err = 'Двухфакторная авторизация уже выключена.';
    } elseif (!profile_password_ok($user, $current)) {
        $flash_err = 'Текущий пароль неверный.';
    } else {
        $up = $con->prepare('UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?');
        $up->bind_param('i', $uid);
        $up->execute();
        $up->close();
        unset($_SESSION['totp_setup']);
        $user['totp_secret'] = null;
        $user['totp_enabled'] = 0;
        $totp_on = false;
        remember_revoke_user($con, $uid);
        bump_user_session_rev($con, $uid);
        $flash = 'Двухфакторная авторизация выключена.';
        log_activity($con, 'settings', 'Выключена двухфакторная авторизация', '/profile/profile.php?tab=security');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pin']) && is_teacher()) {
    csrf_check();
    $current = (string)($_POST['pin_password'] ?? '');
    $new_pin = trim((string)($_POST['new_pin'] ?? ''));
    $new_pin2 = trim((string)($_POST['new_pin2'] ?? ''));
    if (!profile_password_ok($user, $current)) {
        $flash_err = 'Текущий пароль неверный.';
    } elseif (!preg_match('/^\d{6,8}$/', $new_pin)) {
        $flash_err = 'PIN: 6–8 цифр.';
    } elseif (!hash_equals($new_pin, $new_pin2)) {
        $flash_err = 'PIN не совпадают.';
    } else {
        $hash = password_hash($new_pin, PASSWORD_BCRYPT);
        $rev = time();
        $up = $con->prepare("UPDATE users SET fkey=?, fkey_rev=? WHERE id=?");
        $up->bind_param('sii', $hash, $rev, $uid);
        $up->execute();
        $up->close();
        unset($_SESSION['fkey_unlocked'], $_SESSION['fkey_at']);
        $_SESSION['fkey_rev'] = $rev;
        $has_pin = true;
        $flash = 'PIN для финансов сохранён.';
        log_activity($con, 'settings', 'Сменён PIN финансов', '/profile/profile.php');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name_val = trim((string)($_POST['name'] ?? ''));
    $login_val = trim((string)($_POST['login'] ?? ''));
    $current = (string)($_POST['current_password'] ?? '');
    $new_pass = (string)($_POST['new_password'] ?? '');
    $new_pass2 = (string)($_POST['new_password2'] ?? '');
    $login_changed = strcasecmp($login_val, (string)$user['login']) !== 0;
    $pass_changed = $new_pass !== '' || $new_pass2 !== '';

    if ($name_val === '' || $login_val === '') {
        $flash_err = 'Имя и логин обязательны.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $login_val)) {
        $flash_err = 'Логин: 3–64 символа, латиница, цифры, . _ -';
    } elseif ($login_changed || $pass_changed) {
        if (!profile_password_ok($user, $current)) {
            $flash_err = 'Текущий пароль неверный.';
        } elseif ($pass_changed && strlen($new_pass) < 8) {
            $flash_err = 'Новый пароль — минимум 8 символов.';
        } elseif ($pass_changed && !hash_equals($new_pass, $new_pass2)) {
            $flash_err = 'Новые пароли не совпадают.';
        }
    }

    if ($flash_err === '' && $login_changed) {
        if (is_reserved_login($con, $login_val, $uid)) {
            $flash_err = 'Этот логин зарезервирован.';
        } else {
            $chk = $con->prepare("SELECT id FROM users WHERE login=? AND id<>? LIMIT 1");
            $chk->bind_param('si', $login_val, $uid);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $flash_err = 'Такой логин уже занят.';
            }
            $chk->close();
        }
    }

    if ($flash_err === '') {
        $up = $con->prepare("UPDATE users SET name=?, login=? WHERE id=?");
        $up->bind_param('ssi', $name_val, $login_val, $uid);
        $up->execute();
        $up->close();
        if ($pass_changed) {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $pw = $con->prepare("UPDATE users SET password_hash=? WHERE id=?");
            $pw->bind_param('si', $hash, $uid);
            $pw->execute();
            $pw->close();
            remember_revoke_user($con, $uid);
            remember_cookie_clear();
            session_regenerate_id(true);
            bump_user_session_rev($con, $uid);
        }
        $_SESSION['name'] = $name_val !== '' ? $name_val : $login_val;
        $_SESSION['login'] = $login_val;
        $user['login'] = $login_val;
        $user['name'] = $name_val;
        $flash = 'Профиль сохранён.';
        log_activity($con, 'settings', 'Профиль сохранён', '/profile/profile.php');
    }
}

$active = 'profile';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Профиль — Tutor CRM</title>
    <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>
<div class="content">
    <h1>Профиль</h1>
    <nav class="settings-tabs" aria-label="Разделы профиля">
        <a href="?tab=profile" class="<?= $profile_tab === 'profile' ? 'active' : '' ?>">Профиль</a>
        <a href="?tab=security" class="<?= $profile_tab === 'security' ? 'active' : '' ?>">Безопасность</a>
    </nav>
    <?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($flash_err): ?><p class="notice err"><?= h($flash_err) ?></p><?php endif; ?>

    <?php if ($profile_tab === 'security'):
        $totp_setup = !$totp_on ? (string)($_SESSION['totp_setup'] ?? '') : '';
        $totp_uri = $totp_setup !== '' ? totp_uri((string)$user['login'], $totp_setup) : '';
    ?>
    <div class="card">
        <h2>Двухфакторная авторизация</h2>
        <?php if ($totp_on): ?>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <label class="totp-head">
                <strong>Включена</strong>
                <span class="switch">
                    <input type="checkbox" checked id="totpKeepOn">
                    <i></i>
                </span>
            </label>
            <p class="muted">Пока сессия жива или включено «Запомнить меня», код снова не спрашивается. Чтобы выключить — введите пароль.</p>
            <div class="form-group">
                <label for="totp_password">Текущий пароль</label>
                <input class="input" type="password" id="totp_password" name="totp_password" required autocomplete="current-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn gray" name="totp_disable" value="1">Выключить</button>
            </div>
        </form>
        <?php elseif ($totp_setup !== ''): ?>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <label class="totp-head">
                <strong>Настройка</strong>
                <span class="switch">
                    <input type="checkbox" checked id="totpCancelSwitch">
                    <i></i>
                </span>
            </label>
            <div class="totp-setup">
                <ol>
                    <li>Установите Google Authenticator или Authy на телефон.</li>
                    <li>Нажмите «+» и отсканируйте QR — или введите ключ вручную.</li>
                    <li>Введите 6-значный код из приложения, чтобы включить.</li>
                </ol>
                <div class="totp-qr" id="totpQr" hidden></div>
                <p class="muted">Ключ для ручного ввода</p>
                <div class="totp-secret"><?= h($totp_setup) ?></div>
            </div>
            <div class="form-group">
                <label for="totp_code">Код из приложения</label>
                <input class="input" type="text" id="totp_code" name="totp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="totp_confirm" value="1">Включить</button>
                <button type="submit" class="btn gray" name="totp_cancel" value="1" formnovalidate>Отмена</button>
            </div>
        </form>
        <?php else: ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <label class="totp-head">
                <strong>Выключена</strong>
            </label>
            <div class="totp-setup">
                <p class="muted">Код из приложения на телефоне при входе. Пока вы в системе или с «Запомнить меня» — повторно не спрашивается.</p>
            </div>
            <div class="form-group">
                <label for="totp_password_start">Текущий пароль</label>
                <input class="input" type="password" id="totp_password_start" name="totp_password" required autocomplete="current-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="totp_start" value="1">Начать настройку</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="card">
        <h2><?= is_admin() ? 'Админ' : 'Учитель' ?></h2>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="name">Имя</label>
                <input class="input" type="text" id="name" name="name" required value="<?= h($name_val) ?>">
            </div>
            <div class="form-group">
                <label for="login">Логин</label>
                <input class="input" type="text" id="login" name="login" required value="<?= h($login_val) ?>" autocomplete="username">
            </div>
            <div class="form-group">
                <label for="current_password">Текущий пароль</label>
                <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password">
                <p class="muted">Нужен, чтобы сменить логин или пароль.</p>
            </div>
            <div class="form-group">
                <label for="new_password">Новый пароль</label>
                <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="new_password2">Повторите пароль</label>
                <input class="input" type="password" id="new_password2" name="new_password2" autocomplete="new-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_profile" value="1">Сохранить профиль</button>
            </div>
        </form>
    </div>

    <?php if (is_teacher()): ?>
    <div class="card">
        <h2>PIN для финансов</h2>
        <p class="muted"><?= $has_pin ? 'Нужен, чтобы открыть страницу финансов.' : 'PIN ещё не задан. Установите его, чтобы открыть финансы.' ?></p>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="pin_password">Текущий пароль</label>
                <input class="input" type="password" id="pin_password" name="pin_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_pin"><?= $has_pin ? 'Новый PIN' : 'PIN' ?></label>
                <input class="input" type="password" id="new_pin" name="new_pin" required inputmode="numeric" pattern="\d{6,8}" maxlength="8" autocomplete="off">
                <p class="muted">6–8 цифр.</p>
            </div>
            <div class="form-group">
                <label for="new_pin2">Повторите PIN</label>
                <input class="input" type="password" id="new_pin2" name="new_pin2" required inputmode="numeric" pattern="\d{6,8}" maxlength="8" autocomplete="off">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_pin" value="1"><?= $has_pin ? 'Сменить PIN' : 'Установить PIN' ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<script <?= csp_nonce_attr() ?>>
document.getElementById('totpKeepOn')?.addEventListener('change', function(){ this.checked = true; document.getElementById('totp_password')?.focus(); });
document.getElementById('totpCancelSwitch')?.addEventListener('change', function(){ this.form.querySelector('[name=totp_cancel]')?.click(); });
</script>
<?php if ($profile_tab === 'security' && $totp_uri !== ''): ?>
<script <?= csp_nonce_attr() ?> src="<?= asset('/profile/js/qrcode.min.js') ?>"></script>
<script <?= csp_nonce_attr() ?>>
(function () {
  if (typeof qrcode !== 'function') return;
  qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
  var qr = qrcode(0, 'M');
  qr.addData(<?= json_encode($totp_uri, JSON_UNESCAPED_SLASHES) ?>);
  qr.make();
  var el = document.getElementById('totpQr');
  if (!el) return;
  el.innerHTML = qr.createSvgTag(4, 0);
  el.hidden = false;
})();
</script>
<?php endif; ?>
</body>
</html>
