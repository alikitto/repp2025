<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';
require_once __DIR__ . '/../common/drive.php';

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) {
    header('Location: /index.php');
    exit;
}

$flash = '';
$flash_err = '';
$admin = is_admin();
$tid = teacher_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$admin && isset($_POST['save_lessons'])) {
        $th = clamp_setting_int((int)($_POST['debt_threshold'] ?? 8));
        $pack = clamp_setting_int((int)($_POST['pack_lessons'] ?? 8));
        $dur = (int)($_POST['lesson_duration'] ?? 90);
        if (!in_array($dur, lesson_duration_choices(), true)) $dur = 90;
        $size = clamp_setting_int((int)($_POST['group_size'] ?? 6), 1, 12);
        $period = (string)($_POST['chart_period'] ?? 'academic');
        if ($period !== 'rolling') $period = 'academic';
        save_teacher_setting($con, $tid, 'debt_threshold', (string)$th);
        save_teacher_setting($con, $tid, 'pack_lessons', (string)$pack);
        save_teacher_setting($con, $tid, 'lesson_duration', (string)$dur);
        save_teacher_setting($con, $tid, 'group_size', (string)$size);
        save_teacher_setting($con, $tid, 'chart_period', $period);
        load_app_settings($con, $tid);
        $flash = 'Настройки сохранены.';
    }

    if ($admin && isset($_POST['save_remember'])) {
        $days = clamp_setting_int((int)($_POST['remember_days'] ?? 30), 1, 365);
        save_app_setting($con, 'remember_days', (string)$days);
        $flash = 'Срок Remember me сохранён.';
    }

    if ($admin && isset($_POST['save_clock'])) {
        $tz = trim((string)($_POST['app_timezone'] ?? ''));
        if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'Asia/Baku';
        save_app_setting($con, 'app_timezone', $tz);
        apply_app_timezone($con);
        $flash = 'Часовой пояс сохранён.';
    }

    if ($admin && isset($_POST['save_google'])) {
        save_app_setting($con, 'google_client_id', trim((string)($_POST['google_client_id'] ?? '')));
        $gkey = trim((string)($_POST['google_api_key'] ?? ''));
        if ($gkey !== '') save_app_setting($con, 'google_api_key', $gkey);
        $gsec = trim((string)($_POST['google_client_secret'] ?? ''));
        if ($gsec !== '') save_app_setting($con, 'google_client_secret', $gsec);
        $flash = 'Google API сохранён.';
        $settings_tab = 'integrations';
    }

    if ($admin && isset($_POST['save_drive'])) {
        save_app_setting($con, 'google_drive_folder_id', trim((string)($_POST['google_drive_folder_id'] ?? '')));
        $t = trim((string)($_POST['backup_time'] ?? '23:00'));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) $t = '23:00';
        $freq = (string)($_POST['backup_freq'] ?? 'daily');
        if (!in_array($freq, ['daily', 'weekly', 'monthly'], true)) $freq = 'daily';
        save_app_setting($con, 'backup_time', $t);
        save_app_setting($con, 'backup_freq', $freq);
        save_app_setting($con, 'backup_weekday', (string)clamp_setting_int((int)($_POST['backup_weekday'] ?? 7), 1, 7));
        save_app_setting($con, 'backup_monthday', (string)clamp_setting_int((int)($_POST['backup_monthday'] ?? 1), 1, 28));
        $flash = 'Настройки бэкапа сохранены.';
        $settings_tab = 'integrations';
    }

    if ($admin && isset($_POST['disconnect_drive'])) {
        save_app_setting($con, 'google_refresh_token', '');
        $flash = 'Google Drive отключён.';
        $settings_tab = 'integrations';
    }

    if ($admin && isset($_POST['test_drive_backup'])) {
        $settings_tab = 'integrations';
        require_once __DIR__ . '/../common/backup.php';
        $bak = run_db_backup($con);
        if ($bak['ok']) $flash = $bak['message'];
        else $flash_err = $bak['message'];
    }

    if ($admin && isset($_POST['restore_drive_backup'])) {
        $settings_tab = 'integrations';
        $pw = (string)($_POST['restore_password'] ?? '');
        $ack = isset($_POST['restore_ack']);
        $st = $con->prepare("SELECT login, password_hash, totp_secret, totp_enabled FROM users WHERE id=? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $me = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
        if (!$ack) {
            $flash_err = 'Подтвердите, что снимок текущей базы будет сохранён.';
        } elseif (!verify_user_password($con, $uid, $pw)) {
            $flash_err = 'Неверный пароль.';
        } elseif (totp_user_on($me) && !totp_verify_user($con, array_merge($me, ['id' => $uid]), (string)($_POST['restore_totp'] ?? ''))) {
            $flash_err = 'Неверный код 2FA.';
        } else {
            require_once __DIR__ . '/../common/backup.php';
            $bak = restore_drive_backup($con, (string)($_POST['drive_file_id'] ?? ''));
            if ($bak['ok']) {
                restore_set_admin_password($con, (string)($me['login'] ?? $_SESSION['login'] ?? ''), $pw);
                session_force_logout($con);
                header('Location: /index.php?err=restored');
                exit;
            }
            $flash_err = $bak['message'];
        }
    }

    if ($admin && isset($_POST['save_telegram'])) {
        $tgt = trim((string)($_POST['tg_bot_token'] ?? ''));
        if ($tgt !== '') save_app_setting($con, 'tg_bot_token', $tgt);
        if (tg_bot_token($con) === '') {
            $flash_err = 'Bot token не сохранён — поле пустое или APP_SECRET не совпадает.';
        } else {
            require_once __DIR__ . '/../common/report.php';
            $me = tg_get_me($con);
            $flash = $me === '' ? 'Bot token сохранён, но Telegram его не принял. Проверьте токен у @BotFather.' : 'Bot token сохранён. Бот: @' . $me;
            if ($me === '') { $flash_err = $flash; $flash = ''; }
        }
        $settings_tab = 'integrations';
    }

    if (!$admin && isset($_POST['save_prices'])) {
        $monthlies = $_POST['preset_monthly'] ?? [];
        $pack_n = pack_lessons($con, $tid);
        $out = [];
        if (is_array($monthlies)) {
            foreach ($monthlies as $m) {
                $row = normalize_price_preset(['monthly' => round((float)$m, 2)], $pack_n);
                if ($row) $out[] = $row;
            }
        }
        if (!$out) {
            $flash_err = 'Нужен хотя бы один пресет с ценой за месяц.';
        } else {
            save_teacher_setting($con, $tid, 'price_presets', json_encode($out, JSON_UNESCAPED_UNICODE));
            $def_i = (int)($_POST['default_preset'] ?? 0);
            $picked_m = round((float)($monthlies[$def_i] ?? 0), 2);
            $def_m = $picked_m > 0 ? $picked_m : $out[0]['monthly'];
            save_teacher_setting($con, $tid, 'default_monthly', (string)$def_m);
            save_teacher_setting($con, $tid, 'default_price', (string)lesson_price_from_monthly($def_m, $pack_n));
            $flash = 'Пресеты цен сохранены.';
        }
    }

    if (!$admin && isset($_POST['save_telegram'])) {
        save_teacher_chat_id($con, $tid, trim((string)($_POST['tg_chat_id'] ?? '')));
        save_teacher_setting($con, $tid, 'tg_notify_debt', isset($_POST['tg_notify_debt']) ? '1' : '0');
        save_teacher_setting($con, $tid, 'tg_notify_report', isset($_POST['tg_notify_report']) ? '1' : '0');
        $debt_when = (string)($_POST['debt_when'] ?? 'after_start');
        if (!in_array($debt_when, ['clock', 'after_arrival'], true)) $debt_when = 'after_start';
        save_teacher_setting($con, $tid, 'debt_when', $debt_when);
        save_teacher_setting($con, $tid, 'debt_offset', (string)clamp_setting_int((int)($_POST['debt_offset'] ?? 15), 0, 180));
        save_teacher_setting($con, $tid, 'debt_offset_after', (string)clamp_setting_int((int)($_POST['debt_offset_after'] ?? 15), 0, 180));
        save_teacher_setting($con, $tid, 'debt_time', parse_hhmm((string)($_POST['debt_time'] ?? '21:00'), '21:00'));
        $rday = (string)($_POST['report_day'] ?? 'last');
        if ($rday !== 'last') $rday = (string)clamp_setting_int((int)$rday, 1, 28);
        save_teacher_setting($con, $tid, 'report_day', $rday);
        save_teacher_setting($con, $tid, 'report_time', parse_hhmm((string)($_POST['report_time'] ?? '20:00'), '20:00'));
        $flash = 'Telegram сохранён.';
        $settings_tab = 'telegram';
    }

    if (!$admin && isset($_POST['test_telegram'])) {
        $settings_tab = 'telegram';
        if (isset($_POST['tg_chat_id'])) save_teacher_chat_id($con, $tid, trim((string)$_POST['tg_chat_id']));
        $token = tg_bot_token($con);
        $chat = tg_chat_id($con, $tid);
        if ($chat === '') {
            $flash_err = 'Укажите ваш Chat ID.';
        } elseif ($token === '') {
            $flash_err = 'Bot token ещё не настроен администратором.';
        } else {
            require_once __DIR__ . '/../common/report.php';
            $text = "Имя: Тест Тестов\nДолг: 8 ур.\nСумма: 160.00 AZN";
            if (tg_send_message($con, $text, '', $chat, $tid)) {
                $flash = 'Тест отправлен в Telegram.';
            } else {
                $flash_err = 'Не удалось отправить. Проверьте chat id и что вы написали боту /start.';
            }
        }
    }

    if (!$admin && isset($_POST['send_monthly_report'])) {
        $settings_tab = 'telegram';
        if (isset($_POST['tg_chat_id'])) save_teacher_chat_id($con, $tid, trim((string)$_POST['tg_chat_id']));
        require_once __DIR__ . '/../common/report.php';
        [$from, $to] = monthly_report_period();
        $res = send_monthly_report($con, $from, $to, $tid);
        if ($res['ok']) $flash = $res['message'];
        else $flash_err = $res['message'];
    }

    if (!$admin && isset($_POST['wipe_all'])) {
        $step1 = (string)($_POST['wipe_step1'] ?? '');
        $phrase = trim((string)($_POST['wipe_phrase'] ?? ''));
        $wipe_pw = (string)($_POST['wipe_password'] ?? '');
        $st = $con->prepare("SELECT totp_secret, totp_enabled FROM users WHERE id=? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $me = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
        if ($step1 !== '1' || mb_strtoupper($phrase) !== 'УДАЛИТЬ') {
            $flash_err = 'Подтверждение не пройдено.';
        } elseif (!verify_user_password($con, $uid, $wipe_pw)) {
            $flash_err = 'Неверный пароль.';
        } elseif (totp_user_on($me) && !totp_verify_user($con, array_merge($me, ['id' => $uid]), (string)($_POST['wipe_totp'] ?? ''))) {
            $flash_err = 'Неверный код 2FA.';
        } else {
            $con->begin_transaction();
            try {
                $del = $con->prepare("DELETE FROM stud WHERE teacher_id=?");
                $del->bind_param('i', $tid);
                $del->execute();
                $del->close();
                $del = $con->prepare("DELETE FROM sched_blocks WHERE teacher_id=?");
                $del->bind_param('i', $tid);
                $del->execute();
                $del->close();
                $del = $con->prepare("DELETE FROM activity_log WHERE teacher_id=?");
                $del->bind_param('i', $tid);
                $del->execute();
                $del->close();
                $con->commit();
                $flash = 'Все ваши данные программы удалены.';
                log_activity($con, 'settings', 'Все данные программы удалены', '/profile/settings.php');
            } catch (Throwable $e) {
                $con->rollback();
                $flash_err = 'Не удалось удалить данные.';
            }
        }
    }

    if ($flash !== '' && !isset($_POST['wipe_all'])) {
        log_activity($con, 'settings', rtrim($flash, '.'), '/profile/settings.php');
    }
}

$th = debt_threshold($con, $tid ?: null);
$pack = pack_lessons($con, $tid ?: null);
$dur = lesson_duration($con, $tid ?: null);
$size = group_size($con, $tid ?: null);
$period = chart_period($con, $tid ?: null);
$g_key = google_api_key($con);
$g_cid = google_client_id($con);
$g_sec = google_client_secret($con);
$g_folder = google_drive_folder_id($con);
$g_drive_on = google_refresh_token($con) !== '' || google_sa_json() !== '';
$remember_days = remember_days($con);
$app_tz = app_timezone($con);
$tz_choices = timezone_choices();
if (!isset($tz_choices[$app_tz])) $tz_choices[$app_tz] = $app_tz;
$bak_time = backup_time($con);
$bak_freq = backup_freq($con);
$bak_wd = backup_weekday($con);
$bak_md = backup_monthday($con);
$bak_last = app_setting($con, 'backup_last_at', '');
$drive_backups = [];
$drive_list_err = '';
if ($admin && $g_drive_on) {
    try {
        require_once __DIR__ . '/../common/backup.php';
        $tok = drive_access_token($con);
        $fold = drive_ensure_folder($con, $tok);
        $drive_backups = drive_list_backups($tok, $fold, 5);
    } catch (Throwable $e) {
        $drive_list_err = $e->getMessage();
    }
}
$st = $con->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id=? LIMIT 1');
$st->bind_param('i', $uid);
$st->execute();
$need_totp = totp_user_on($st->get_result()->fetch_assoc() ?: []);
$st->close();
$wd_names = [1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг', 5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье'];
$tg_token = tg_bot_token($con);
$tg_chat = $tid > 0 ? tg_chat_id($con, $tid) : '';
$presets = $tid > 0 ? price_presets($con, $tid) : [];
$def_monthly = $tid > 0 ? default_monthly($con, $tid) : 0.0;
$tg_debt_on = $tid > 0 && teacher_tg_on($con, $tid, 'tg_notify_debt');
$tg_report_on = $tid > 0 && teacher_tg_on($con, $tid, 'tg_notify_report');
$debt_when = $tid > 0 ? teacher_debt_when($con, $tid) : 'after_start';
$debt_offset = $tid > 0 ? teacher_debt_offset($con, $tid) : 15;
$debt_offset_after = $tid > 0 ? teacher_debt_offset_after($con, $tid) : 15;
$debt_at = $tid > 0 ? teacher_debt_time($con, $tid) : '21:00';
$rep_day = $tid > 0 ? teacher_report_day($con, $tid) : 'last';
$rep_time = $tid > 0 ? teacher_report_time($con, $tid) : '20:00';
while (count($presets) < 3) $presets[] = ['monthly' => 0.0, 'price' => 0.0, 'label' => ''];
if (!isset($settings_tab)) $settings_tab = (string)($_GET['tab'] ?? ($admin ? 'integrations' : 'main'));
if ($admin) {
    $settings_tab = 'integrations';
} elseif ($settings_tab !== 'telegram') {
    $settings_tab = 'main';
}
$active = 'settings';
$secret_warn = $admin ? secret_health($con) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Настройки — Tutor CRM</title>
    <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>
<div class="content settings-page">
    <div class="settings-head">
        <h1>Настройки</h1>
        <p class="muted"><?= $admin ? 'Доступ, время и интеграции.' : ($settings_tab === 'telegram' ? 'Уведомления о должниках и ежемесячный отчёт.' : 'Уроки, цены, вместимость и график.') ?></p>
    </div>
    <?php if ($secret_warn !== ''): ?>
    <div class="notice warn">
        <b>Проблема с APP_SECRET.</b>
        <?= h($secret_warn) ?>
        Сохранённые токены (Telegram, Google Drive) не читаются и считаются ненастроенными.
        Восстановите прежний APP_SECRET либо введите токены заново.
    </div>
    <?php endif; ?>
    <?php if (!$admin): ?>
    <nav class="settings-tabs" aria-label="Разделы настроек">
        <a href="?tab=main" class="<?= $settings_tab === 'main' ? 'active' : '' ?>">Основные</a>
        <a href="?tab=telegram" class="<?= $settings_tab === 'telegram' ? 'active' : '' ?>">Telegram</a>
    </nav>
    <?php endif; ?>
    <?php
    $drive_flash = [
        'ok' => 'Google Drive подключён.',
        'denied' => 'Доступ к Google Drive отклонён.',
        'state' => 'Сессия OAuth устарела. Подключите снова.',
        'no_refresh' => 'Google не вернул refresh token. Включите Drive API и повторите.',
        'need_google' => 'Сначала сохраните Client ID и Client secret.',
        'need_url' => 'Задайте APP_URL в окружении (публичный адрес сайта).',
    ];
    $drive_q = (string)($_GET['drive'] ?? '');
    if ($flash === '' && $flash_err === '' && isset($drive_flash[$drive_q])) {
        if ($drive_q === 'ok') $flash = $drive_flash[$drive_q];
        else $flash_err = $drive_flash[$drive_q];
    }
    ?>
    <?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($flash_err): ?><p class="notice err"><?= h($flash_err) ?></p><?php endif; ?>

    <?php if ($admin): ?>
    <div class="card">
        <h2>Remember me</h2>
        <p class="muted">Сколько дней держать вход, если на логине отметили «Запомнить меня».</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="remember_days">Дней</label>
                <input class="input" type="number" id="remember_days" name="remember_days" min="1" max="365" value="<?= (int)$remember_days ?>" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_remember" value="1">Сохранить</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Время</h2>
        <p class="muted">Часовой пояс для расписания, бэкапа и уведомлений.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="app_timezone">Часовой пояс</label>
                <select class="select" id="app_timezone" name="app_timezone">
                    <?php foreach ($tz_choices as $id => $label): ?>
                        <option value="<?= h($id) ?>" <?= $app_tz === $id ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_clock" value="1">Сохранить</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Google API</h2>
        <p class="muted">Ключи из Google Cloud Console (Calendar, Sheets).</p>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="google_api_key">API key</label>
                <input class="input" type="password" id="google_api_key" name="google_api_key" value="" placeholder="<?= $g_key !== '' ? 'сохранён' : '' ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="google_client_id">Client ID</label>
                <input class="input" type="text" id="google_client_id" name="google_client_id" value="<?= h($g_cid) ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="google_client_secret">Client secret</label>
                <input class="input" type="password" id="google_client_secret" name="google_client_secret" value="" placeholder="<?= $g_sec !== '' ? 'сохранён' : '' ?>" autocomplete="off">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_google" value="1">Сохранить Google</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Бэкап в Google Drive</h2>
        <p class="muted">Дамп базы в папку на Диске. В Google Cloud включите Drive API и добавьте Redirect URI: <code><?= h(app_public_url('/profile/google_oauth.php')) ?></code></p>
        <p class="muted"><?= $g_drive_on ? 'Диск подключён.' : 'Диск не подключён.' ?></p>
        <?php if ($bak_last !== ''): ?>
        <p class="muted">Последний бэкап: <?= h(date('d.m.Y H:i', strtotime($bak_last))) ?></p>
        <?php endif; ?>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="google_drive_folder_id">ID папки</label>
                <input class="input" type="text" id="google_drive_folder_id" name="google_drive_folder_id" value="<?= h($g_folder) ?>" placeholder="оставьте пустым — создастся сама">
            </div>
            <div class="form-group">
                <label for="backup_time">Время</label>
                <input class="input" type="time" id="backup_time" name="backup_time" value="<?= h($bak_time) ?>" required>
            </div>
            <div class="form-group">
                <label for="backup_freq">Как часто</label>
                <select class="select" id="backup_freq" name="backup_freq">
                    <option value="daily" <?= $bak_freq === 'daily' ? 'selected' : '' ?>>Каждый день</option>
                    <option value="weekly" <?= $bak_freq === 'weekly' ? 'selected' : '' ?>>Раз в неделю</option>
                    <option value="monthly" <?= $bak_freq === 'monthly' ? 'selected' : '' ?>>Раз в месяц</option>
                </select>
            </div>
            <div class="form-group" id="backup_weekday_wrap" <?= $bak_freq !== 'weekly' ? 'hidden' : '' ?>>
                <label for="backup_weekday">День недели</label>
                <select class="select" id="backup_weekday" name="backup_weekday">
                    <?php foreach ($wd_names as $n => $label): ?>
                        <option value="<?= $n ?>" <?= $bak_wd === $n ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="backup_monthday_wrap" <?= $bak_freq !== 'monthly' ? 'hidden' : '' ?>>
                <label for="backup_monthday">Число месяца</label>
                <input class="input" type="number" id="backup_monthday" name="backup_monthday" min="1" max="28" value="<?= (int)$bak_md ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_drive" value="1">Сохранить</button>
                <a class="btn gray" href="/profile/google_oauth.php">Подключить Диск</a>
                <?php if ($g_drive_on): ?>
                <button type="submit" class="btn gray" name="test_drive_backup" value="1">Отправить бэкап сейчас</button>
                <button type="submit" class="btn gray" name="disconnect_drive" value="1">Отключить</button>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($g_drive_on): ?>
        <form method="post" class="form" autocomplete="off" style="margin-top:20px" id="restoreForm">
            <?= csrf_field() ?>
            <h3>Восстановить из Диска</h3>
            <p class="muted">Последние 5 файлов, только с подписью. Перед импортом сохраняется снимок в data/. После restore войдите снова.</p>
            <?php if ($drive_list_err !== ''): ?>
            <p class="notice err"><?= h($drive_list_err) ?></p>
            <?php elseif (!$drive_backups): ?>
            <p class="muted">Файлов бэкапа пока нет.</p>
            <?php else: ?>
            <div class="form-group">
                <?php foreach ($drive_backups as $i => $bf): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-top:8px">
                    <input type="radio" name="drive_file_id" value="<?= h($bf['id']) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <?= h($bf['name']) ?>
                    <?php if (!empty($bf['createdTime'])): ?>
                    <span class="muted"><?= h(date('d.m.Y H:i', strtotime((string)$bf['createdTime']))) ?></span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="form-group">
                <label for="restore_password">Текущий пароль</label>
                <input class="input" type="password" id="restore_password" name="restore_password" required autocomplete="current-password">
            </div>
            <?php if ($need_totp): ?>
            <div class="form-group">
                <label for="restore_totp">Код 2FA</label>
                <input class="input" type="text" id="restore_totp" name="restore_totp" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code">
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label><input type="checkbox" name="restore_ack" value="1" required> Снимок текущей базы будет сохранён в data/</label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn gray" name="restore_drive_backup" value="1">Восстановить выбранный</button>
            </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Telegram</h2>
        <p class="muted">Общий бот для всех учителей. Токен — у @BotFather. Chat ID каждый учитель указывает у себя в настройках.</p>
        <p class="muted"><?= $tg_token !== '' ? 'Токен сохранён и читается — учителям он доступен.' : 'Токен не задан: учителя не смогут отправлять уведомления.' ?></p>
        <form method="post" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="tg_bot_token">Bot token</label>
                <input class="input" type="password" id="tg_bot_token" name="tg_bot_token" value="" placeholder="<?= $tg_token !== '' ? 'сохранён' : '' ?>" autocomplete="off">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_telegram" value="1">Сохранить токен</button>
            </div>
        </form>
    </div>

    <script <?= csp_nonce_attr() ?>>
    (function () {
      document.getElementById('restoreForm')?.addEventListener('submit', function (e) {
        if (!confirm('Восстановить базу из выбранного файла? Текущие данные будут заменены.')) e.preventDefault();
      });
      const freq = document.getElementById('backup_freq');
      const wd = document.getElementById('backup_weekday_wrap');
      const md = document.getElementById('backup_monthday_wrap');
      if (!freq || !wd || !md) return;
      function sync() {
        wd.hidden = freq.value !== 'weekly';
        md.hidden = freq.value !== 'monthly';
      }
      freq.addEventListener('change', sync);
      sync();
    })();
    </script>
    <?php elseif ($settings_tab === 'telegram'): ?>
    <form method="post" class="form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="card settings-card">
            <h2>Чат</h2>
            <p class="muted settings-lead">Chat ID — у @userinfobot. Напишите боту /start.</p>
            <div class="form-group">
                <label for="tg_chat_id">Chat ID</label>
                <input class="input" type="text" id="tg_chat_id" name="tg_chat_id" value="<?= h($tg_chat) ?>" autocomplete="off">
            </div>
        </div>

        <div class="settings-pair">
            <div class="card settings-card">
                <label class="totp-head">
                    <strong>Должники</strong>
                    <span class="switch">
                        <input type="checkbox" name="tg_notify_debt" value="1" <?= $tg_debt_on ? 'checked' : '' ?>>
                        <i></i>
                    </span>
                </label>
                <p class="muted settings-lead">Когда слать напоминание о долге.</p>
                <div class="choice-stack" role="radiogroup" aria-label="Когда слать о должниках">
                    <label class="choice choice-block">
                        <input type="radio" name="debt_when" value="after_start" <?= $debt_when === 'after_start' ? 'checked' : '' ?>>
                        <span>За N минут до урока</span>
                        <input class="input input-compact" type="number" name="debt_offset" min="0" max="180" value="<?= (int)$debt_offset ?>" aria-label="Минут до урока">
                    </label>
                    <label class="choice choice-block">
                        <input type="radio" name="debt_when" value="after_arrival" <?= $debt_when === 'after_arrival' ? 'checked' : '' ?>>
                        <span>После начала прихода</span>
                        <small>через N минут</small>
                        <input class="input input-compact" type="number" name="debt_offset_after" min="0" max="180" value="<?= (int)$debt_offset_after ?>" aria-label="Минут после прихода">
                    </label>
                    <label class="choice choice-block">
                        <input type="radio" name="debt_when" value="clock" <?= $debt_when === 'clock' ? 'checked' : '' ?>>
                        <span>В выбранное время</span>
                        <small>все текущие должники раз в день</small>
                        <input class="input input-compact" type="time" name="debt_time" value="<?= h($debt_at) ?>" aria-label="Время рассылки">
                    </label>
                </div>
            </div>

            <div class="card settings-card">
                <label class="totp-head">
                    <strong>Ежемесячный отчёт</strong>
                    <span class="switch">
                        <input type="checkbox" name="tg_notify_report" value="1" <?= $tg_report_on ? 'checked' : '' ?>>
                        <i></i>
                    </span>
                </label>
                <p class="muted settings-lead">В последний день — за текущий месяц. В выбранное число — за прошлый.</p>
                <div class="form-group">
                    <label for="report_day">День</label>
                    <select class="select" id="report_day" name="report_day">
                        <option value="last" <?= $rep_day === 'last' ? 'selected' : '' ?>>Последний день месяца</option>
                        <?php for ($d = 1; $d <= 28; $d++): ?>
                            <option value="<?= $d ?>" <?= $rep_day === (string)$d ? 'selected' : '' ?>><?= $d ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="report_time">Время</label>
                    <input class="input" type="time" id="report_time" name="report_time" value="<?= h($rep_time) ?>">
                </div>
            </div>
        </div>

        <div class="form-actions settings-save">
            <button type="submit" class="btn gray" name="test_telegram" value="1">Отправить тест</button>
            <button type="submit" class="btn gray" name="send_monthly_report" value="1">Отчёт за месяц</button>
            <button type="submit" class="btn" name="save_telegram" value="1">Сохранить</button>
        </div>
    </form>
    <?php else:
        $dur_labels = [30 => '30 мин', 60 => '1 час', 90 => '1,5 часа', 120 => '2 часа'];
    ?>

    <form method="post" class="form">
        <?= csrf_field() ?>
        <div class="card settings-card">
            <h2>Уроки</h2>
            <p class="muted settings-lead">Порог должника, пакет оплаты и длительность слота.</p>
            <div class="grid-2">
                <div class="form-group">
                    <label for="debt_threshold">Порог должника</label>
                    <input class="input" type="number" id="debt_threshold" name="debt_threshold" min="1" max="99" value="<?= (int)$th ?>" required>
                    <p class="muted">В должниках, если баланс ≤ −<?= (int)$th ?>.</p>
                </div>
                <div class="form-group">
                    <label for="pack_lessons">Пакет оплаты</label>
                    <input class="input" type="number" id="pack_lessons" name="pack_lessons" min="1" max="99" value="<?= (int)$pack ?>" required>
                    <p class="muted">Подставляется при добавлении оплаты.</p>
                </div>
            </div>
            <div class="form-group">
                <span class="settings-label">Длительность урока</span>
                <div class="choice-row" role="radiogroup" aria-label="Длительность урока">
                    <?php foreach (lesson_duration_choices() as $mins): ?>
                    <label class="choice">
                        <input type="radio" name="lesson_duration" value="<?= $mins ?>" <?= $dur === $mins ? 'checked' : '' ?>>
                        <span><?= h($dur_labels[$mins] ?? ($mins . ' мин')) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted">Подставляется в «До» при выборе времени начала.</p>
            </div>
        </div>

        <div class="settings-pair">
            <div class="card settings-card">
                <h2>Вместимость</h2>
                <p class="muted settings-lead">Сколько учеников можно поставить на одно занятие.</p>
                <div class="choice-row choice-row-tight" role="radiogroup" aria-label="Вместимость">
                    <?php for ($n = 1; $n <= 8; $n++): ?>
                    <label class="choice<?= $n === 1 ? ' choice-wide' : '' ?>">
                        <input type="radio" name="group_size" value="<?= $n ?>" <?= $size === $n ? 'checked' : '' ?>>
                        <span><?= $n === 1 ? '1 · индив.' : $n ?></span>
                    </label>
                    <?php endfor; ?>
                </div>
                <p class="muted">Нового нельзя добавить, если уже <?= (int)$size ?>.</p>
            </div>
            <div class="card settings-card">
                <h2>Финансы</h2>
                <p class="muted settings-lead">Период графика на странице финансов.</p>
                <div class="choice-stack" role="radiogroup" aria-label="Период графика">
                    <label class="choice choice-block">
                        <input type="radio" name="chart_period" value="academic" <?= $period === 'academic' ? 'checked' : '' ?>>
                        <span>Учебный год</span>
                        <small>сентябрь — июль</small>
                    </label>
                    <label class="choice choice-block">
                        <input type="radio" name="chart_period" value="rolling" <?= $period === 'rolling' ? 'checked' : '' ?>>
                        <span>Последние 12 месяцев</span>
                        <small>скользящее окно</small>
                    </label>
                </div>
            </div>
        </div>

        <div class="form-actions settings-save">
            <button type="submit" class="btn" name="save_lessons" value="1">Сохранить</button>
        </div>
    </form>

    <div class="card settings-card">
        <h2>Цены урока</h2>
        <p class="muted settings-lead">Введите цену за месяц — за урок посчитается сами (месяц ÷ пакет, сейчас <?= (int)$pack ?> ур.). Пустое поле — слот не используется.</p>
        <form method="post" class="form" id="pricePresetsForm">
            <?= csrf_field() ?>
            <div class="preset-list">
            <?php $def_set = false; foreach ($presets as $i => $p):
                $is_def = !$def_set && (float)$p['monthly'] > 0 && abs((float)$p['monthly'] - $def_monthly) < 0.001;
                if ($is_def) $def_set = true;
            ?>
            <div class="preset-row">
                <span class="preset-num"><?= $i + 1 ?></span>
                <label class="preset-default">
                    <input type="radio" name="default_preset" value="<?= $i ?>" <?= $is_def ? 'checked' : '' ?>>
                    <span>по умолч.</span>
                </label>
                <label class="preset-monthly-wrap">
                    <input class="input preset-monthly" type="number" name="preset_monthly[]" inputmode="decimal" step="0.01" min="0" value="<?= (float)$p['monthly'] > 0 ? h(fmt_price((float)$p['monthly'])) : '' ?>" placeholder="150" aria-label="Цена за месяц, пресет <?= $i + 1 ?>">
                    <span>AZN/мес.</span>
                </label>
                <span class="preset-per" aria-live="polite"><?= (float)$p['price'] > 0 ? h(fmt_price((float)$p['price'])) . ' AZN/урок' : '—' ?></span>
            </div>
            <?php endforeach; ?>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn" name="save_prices" value="1">Сохранить цены</button>
            </div>
        </form>
    </div>

    <div class="card settings-card danger-zone">
        <h2>Опасная зона</h2>
        <p class="muted settings-lead">Удалит ваших учеников, посещения, оплаты и расписание. Аккаунт и настройки сохранятся.</p>
        <form method="post" id="wipeForm" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="wipe_all" value="1">
            <input type="hidden" name="wipe_step1" id="wipe_step1" value="">
            <input type="hidden" name="wipe_phrase" id="wipe_phrase" value="">
            <input type="hidden" name="wipe_password" id="wipe_password" value="">
            <?php if ($need_totp): ?>
            <input type="hidden" name="wipe_totp" id="wipe_totp" value="">
            <?php endif; ?>
            <div class="form-actions">
                <button type="button" class="btn danger" id="wipeOpen">Удалить все данные программы</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if (!$admin): ?>
<div id="wipeModal1" class="modal" hidden>
    <div class="modal-card">
        <button type="button" class="modal-close wipe-cancel" aria-label="Закрыть">✕</button>
        <h3>Удалить все данные?</h3>
        <p class="muted">Это нельзя отменить. Ученики, посещения, оплаты и расписание будут удалены.</p>
        <div class="actions">
            <button type="button" class="btn danger sm" id="wipeNext">Продолжить</button>
            <button type="button" class="btn gray sm wipe-cancel">Отмена</button>
        </div>
    </div>
</div>
<div id="wipeModal2" class="modal" hidden>
    <div class="modal-card">
        <button type="button" class="modal-close wipe-cancel" aria-label="Закрыть">✕</button>
        <h3>Подтвердите удаление</h3>
        <p class="muted">Введите <b>УДАЛИТЬ</b> и текущий пароль.</p>
        <form class="form" data-no-submit="1">
            <input class="input" type="text" id="wipePhraseInput" autocomplete="off" placeholder="УДАЛИТЬ">
            <input class="input" type="password" id="wipePasswordInput" autocomplete="current-password" placeholder="Пароль" style="margin-top:8px">
            <?php if ($need_totp): ?>
            <input class="input" type="text" id="wipeTotpInput" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="Код 2FA" style="margin-top:8px">
            <?php endif; ?>
            <div class="actions">
                <button type="button" class="btn danger sm" id="wipeConfirm">Удалить всё</button>
                <button type="button" class="btn gray sm wipe-cancel">Отмена</button>
            </div>
        </form>
    </div>
</div>
<script <?= csp_nonce_attr() ?>>
(function () {
  const packInput = document.getElementById('pack_lessons');
  const form = document.getElementById('pricePresetsForm');
  function pack() {
    const n = parseInt(packInput && packInput.value ? packInput.value : '<?= (int)$pack ?>', 10);
    return n > 0 ? n : 8;
  }
  function fmt(n) {
    return Math.abs(n - Math.round(n)) < 0.001 ? String(Math.round(n)) : n.toFixed(2);
  }
  function paint() {
    if (!form) return;
    const n = pack();
    form.querySelectorAll('.preset-row').forEach(row => {
      const inp = row.querySelector('.preset-monthly');
      const out = row.querySelector('.preset-per');
      if (!inp || !out) return;
      const m = parseFloat(String(inp.value).replace(',', '.'));
      out.textContent = (m > 0) ? fmt(Math.round(m / n * 100) / 100) + ' AZN/урок' : '—';
    });
  }
  if (form) {
    form.querySelectorAll('.preset-monthly').forEach(el => el.addEventListener('input', paint));
    if (packInput) packInput.addEventListener('input', paint);
  }
  document.querySelectorAll('form[data-no-submit]').forEach(f => f.addEventListener('submit', e => e.preventDefault()));
  const wipeForm = document.getElementById('wipeForm');
  if (!wipeForm) return;
  const m1 = document.getElementById('wipeModal1');
  const m2 = document.getElementById('wipeModal2');
  const phrase = document.getElementById('wipePhraseInput');
  function show(el) { el.removeAttribute('hidden'); }
  function hide(el) { el.setAttribute('hidden', ''); }
  function closeAll() { hide(m1); hide(m2); phrase.value = ''; }
  document.getElementById('wipeOpen').addEventListener('click', () => show(m1));
  document.getElementById('wipeNext').addEventListener('click', () => { hide(m1); show(m2); phrase.focus(); });
  document.querySelectorAll('.wipe-cancel').forEach(b => b.addEventListener('click', closeAll));
  document.getElementById('wipeConfirm').addEventListener('click', () => {
    const v = phrase.value.trim();
    if (v.toUpperCase() !== 'УДАЛИТЬ') { phrase.focus(); return; }
    const pw = document.getElementById('wipePasswordInput').value;
    if (!pw) { document.getElementById('wipePasswordInput').focus(); return; }
    const totpEl = document.getElementById('wipeTotpInput');
    if (totpEl && !totpEl.value) { totpEl.focus(); return; }
    document.getElementById('wipe_step1').value = '1';
    document.getElementById('wipe_phrase').value = v;
    document.getElementById('wipe_password').value = pw;
    if (totpEl) document.getElementById('wipe_totp').value = totpEl.value;
    wipeForm.submit();
  });
})();
</script>
<?php endif; ?>
</body>
</html>
