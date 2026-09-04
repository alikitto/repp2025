<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/drive.php';
require_admin();

$cid = google_client_id($con);
$sec = google_client_secret($con);
$redirect = app_public_url('/profile/google_oauth.php');

if ($redirect === '') {
    header('Location: /profile/settings.php?tab=integrations&drive=need_url');
    exit;
}

if ($cid === '' || $sec === '') {
    header('Location: /profile/settings.php?tab=integrations&drive=need_google');
    exit;
}

if (isset($_GET['error'])) {
    header('Location: /profile/settings.php?tab=integrations&drive=denied');
    exit;
}

if (isset($_GET['code'])) {
    $state = (string)($_GET['state'] ?? '');
    $expect = (string)($_SESSION['gdrive_oauth_state'] ?? '');
    unset($_SESSION['gdrive_oauth_state']);
    if ($expect === '' || !hash_equals($expect, $state)) {
        header('Location: /profile/settings.php?tab=integrations&drive=state');
        exit;
    }
    $res = drive_http('POST', 'https://oauth2.googleapis.com/token', '', http_build_query([
        'code' => (string)$_GET['code'],
        'client_id' => $cid,
        'client_secret' => $sec,
        'redirect_uri' => $redirect,
        'grant_type' => 'authorization_code',
    ]), 'application/x-www-form-urlencoded');
    $refresh = (string)($res['refresh_token'] ?? '');
    $access = (string)($res['access_token'] ?? '');
    if ($refresh === '') {
        header('Location: /profile/settings.php?tab=integrations&drive=no_refresh');
        exit;
    }
    save_app_setting($con, 'google_refresh_token', $refresh);
    if ($access !== '' && google_drive_folder_id($con) === '') {
        try {
            drive_ensure_folder($con, $access);
        } catch (Throwable $e) {
            // папка создастся при первом бэкапе
        }
    }
    header('Location: /profile/settings.php?tab=integrations&drive=ok');
    exit;
}

$_SESSION['gdrive_oauth_state'] = bin2hex(random_bytes(16));
$qs = http_build_query([
    'client_id' => $cid,
    'redirect_uri' => $redirect,
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/drive.file',
    'access_type' => 'offline',
    'prompt' => 'consent',
    'state' => $_SESSION['gdrive_oauth_state'],
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $qs);
exit;
