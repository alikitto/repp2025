<?php
declare(strict_types=1);
require_once __DIR__ . '/common/session.php';
app_session_start();
require_once __DIR__ . '/common/csrf.php';
require_once __DIR__ . '/common/util.php';

$pending = $_SESSION['pending_2fa'] ?? null;
$need_2fa = is_array($pending) && (int)($pending['exp'] ?? 0) >= time();
if (is_array($pending) && !$need_2fa) {
    unset($_SESSION['pending_2fa']);
}
$err = (string)($_GET['err'] ?? '');
if (!$need_2fa && !empty($_SESSION['id'])) {
    $home = (($_SESSION['role'] ?? '') === 'admin') ? '/profile/settings.php' : '/profile/schedule.php';
    header('Location: ' . $home);
    exit;
}
$today_n = (int)date('N');
$week_days = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
$busy = [6, 9, 11, 15, 16, 17, 18, 19, 22, 24];
$ico_user = '<svg class="login-ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5.5 18.4c1.2-3 3.6-4.4 6.5-4.4s5.3 1.4 6.5 4.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
$ico_lock = '<svg class="login-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="10.5" width="12" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8.5 10.5V8.2a3.5 3.5 0 017 0v2.3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <title>Вход — Tutor CRM</title>
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
    <link rel="stylesheet" href="<?= asset('/profile/css/style.css') ?>">
</head>
<body class="login-page">
    <div class="login-stage">
        <main class="login-main">
            <div class="login-card">
                <?php if ($need_2fa): ?>
                <p class="login-kicker">Tutor CRM</p>
                <h1>Код</h1>
                <p class="muted">Шесть цифр из приложения-аутентификатора.</p>
                <form method="post" action="/login.php" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="login-fields">
                        <label class="login-field" for="totp_code">
                            <input id="totp_code" name="totp_code" class="login-code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus autocomplete="one-time-code" placeholder="000000" aria-label="Код">
                        </label>
                    </div>
                    <button type="submit">Продолжить</button>
                    <button type="submit" name="totp_cancel" value="1" class="btn-cancel" formnovalidate>Назад</button>
                    <?php if ($err === '2'): ?>
                        <div class="error">Неверный код</div>
                    <?php elseif ($err === '4'): ?>
                        <div class="error">Слишком много попыток. Подождите.</div>
                    <?php endif; ?>
                </form>
                <?php else: ?>
                <p class="login-kicker">Tutor CRM</p>
                <h1>Вход</h1>
                <p class="muted">Логин и пароль от кабинета.</p>
                <form method="post" action="/login.php">
                    <?= csrf_field() ?>
                    <div class="login-fields">
                        <label class="login-field" for="login">
                            <?= $ico_user ?>
                            <input id="login" name="login" required autofocus autocomplete="username" placeholder="Логин" aria-label="Логин">
                        </label>
                        <label class="login-field" for="password">
                            <?= $ico_lock ?>
                            <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Пароль" aria-label="Пароль">
                            <button type="button" class="login-peek" hidden aria-label="Показать пароль"></button>
                        </label>
                    </div>
                    <label class="login-remember">
                        <input type="checkbox" name="remember" value="1">
                        <i></i>
                        Запомнить меня
                    </label>
                    <button type="submit">Войти</button>

                    <?php if ($err === '1'): ?>
                        <div class="error">Неверный логин или пароль</div>
                    <?php elseif ($err === '4'): ?>
                        <div class="error">Слишком много попыток. Подождите.</div>
                    <?php elseif ($err === '3'): ?>
                        <div class="error">Код истёк. Войдите снова.</div>
                    <?php elseif ($err === 'restored'): ?>
                        <div class="error">База восстановлена. Войдите снова.</div>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </main>
        <aside class="login-visual" aria-hidden="true">
            <div class="login-frame">
            <div class="login-shot">
                <img src="/profile/img/login-tutor.jpg?v=2" alt="" width="1024" height="768">
            </div>
            <div class="login-board">
                <div class="login-week">
                    <?php foreach ($week_days as $i => $d): ?>
                    <span<?= ($i + 1) === $today_n ? ' class="is-today"' : '' ?>><?= $d ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="login-slots">
                    <?php for ($i = 1; $i <= 28; $i++):
                        $col = (($i - 1) % 7) + 1;
                        $cls = [];
                        if (in_array($i, $busy, true)) $cls[] = 'on';
                        if ($col === $today_n) $cls[] = 'today';
                    ?>
                    <i class="<?= implode(' ', $cls) ?>" style="--d:<?= $i ?>"></i>
                    <?php endfor; ?>
                </div>
            </div>
            </div>
            <div class="login-visual-copy">
                <p class="login-brand">Tutor</p>
                <p class="login-when">ученики · расписание · оплаты</p>
            </div>
        </aside>
    </div>
    <script <?= csp_nonce_attr() ?>>
    (function () {
        var input = document.getElementById('password');
        var btn = document.querySelector('.login-peek');
        if (!input || !btn) return;
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('is-on', show);
            btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
        });
    })();
    </script>
</body>
</html>
