<?php

function app_public_url(string $path): string {
    $base = rtrim((string)(getenv('APP_URL') ?: ''), '/');
    if ($base === '' && PHP_SAPI !== 'cli') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' && function_exists('proxy_trusted') && proxy_trusted()) {
            $host = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        }
        if ($host !== '' && preg_match('/^[a-zA-Z0-9.-]+(?::\d+)?$/', $host)) {
            $https = function_exists('is_https') && is_https();
            $base = ($https ? 'https' : 'http') . '://' . $host;
        }
    }
    if ($base === '') {
        return '';
    }
    return $base . $path;
}

function drive_b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function drive_http(string $method, string $url, string $token, $body = null, string $contentType = 'application/json'): array {
    $headers = $token !== '' ? "Authorization: Bearer {$token}\r\n" : '';
    $payload = null;
    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers .= "Content-Type: {$contentType}\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $payload,
            'timeout' => 60,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($json)) $json = [];
    $json['_raw'] = is_string($raw) ? $raw : '';
    return $json;
}

function drive_access_token(mysqli $con): string {
    $saRaw = google_sa_json();
    if ($saRaw !== '') {
        $sa = json_decode($saRaw, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('Некорректный GOOGLE_SERVICE_ACCOUNT_JSON.');
        }
        $now = time();
        $unsigned = drive_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . drive_b64url(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
        $ok = openssl_sign($unsigned, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok) throw new RuntimeException('Не удалось подписать JWT сервисного аккаунта.');
        $jwt = $unsigned . '.' . drive_b64url($sig);
        $res = drive_http('POST', 'https://oauth2.googleapis.com/token', '', http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]), 'application/x-www-form-urlencoded');
        $token = (string)($res['access_token'] ?? '');
        if ($token === '') throw new RuntimeException('Google не выдал токен сервисного аккаунта.');
        return $token;
    }

    $refresh = google_refresh_token($con);
    $cid = google_client_id($con);
    $sec = google_client_secret($con);
    if ($refresh === '' || $cid === '' || $sec === '') {
        throw new RuntimeException('Подключите Google Drive в Настройки → Интеграции.');
    }
    $res = drive_http('POST', 'https://oauth2.googleapis.com/token', '', http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id' => $cid,
        'client_secret' => $sec,
    ]), 'application/x-www-form-urlencoded');
    $token = (string)($res['access_token'] ?? '');
    if ($token === '') throw new RuntimeException('Не удалось обновить токен Google Drive.');
    return $token;
}

function drive_ensure_folder(mysqli $con, string $token): string {
    $id = google_drive_folder_id($con);
    if ($id !== '') return $id;
    if (google_sa_json() !== '') {
        throw new RuntimeException('Укажите GOOGLE_DRIVE_FOLDER_ID и откройте папку сервисному аккаунту.');
    }
    $res = drive_http('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', $token, [
        'name' => 'Tutor CRM Backups',
        'mimeType' => 'application/vnd.google-apps.folder',
    ]);
    $id = (string)($res['id'] ?? '');
    if ($id === '') throw new RuntimeException('Не удалось создать папку на Google Drive.');
    save_app_setting($con, 'google_drive_folder_id', $id);
    return $id;
}

function drive_upload(string $token, string $folderId, string $filename, string $bytes, string $mime): string {
    $boundary = 'tutorbak_' . bin2hex(random_bytes(8));
    $meta = json_encode([
        'name' => $filename,
        'parents' => [$folderId],
        'mimeType' => $mime,
    ], JSON_UNESCAPED_UNICODE);
    $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n";
    $body .= "--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
    $res = drive_http(
        'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name',
        $token,
        $body,
        "multipart/related; boundary={$boundary}"
    );
    $id = (string)($res['id'] ?? '');
    if ($id === '') {
        $err = (string)($res['error']['message'] ?? $res['_raw'] ?? 'ошибка загрузки');
        throw new RuntimeException('Google Drive: ' . $err);
    }
    return $id;
}

function drive_http_raw(string $method, string $url, string $token): string {
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Authorization: Bearer {$token}\r\n",
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
    }
    if ($status >= 400 || !is_string($raw) || $raw === '') {
        throw new RuntimeException('Google Drive: не удалось скачать файл.');
    }
    return $raw;
}

/** @return list<array{id:string,name:string,createdTime?:string,size?:string}> */
function drive_list_backups(string $token, string $folderId, int $limit = 5): array {
    $limit = max(1, min(30, $limit));
    $q = "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed=false";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $q,
        'orderBy' => 'createdTime desc',
        'pageSize' => $limit,
        'fields' => 'files(id,name,createdTime,size)',
    ]);
    $res = drive_http('GET', $url, $token);
    $out = [];
    foreach ($res['files'] ?? [] as $f) {
        if (!is_array($f) || empty($f['id']) || empty($f['name'])) continue;
        $out[] = $f;
    }
    return $out;
}

function drive_download(string $token, string $fileId): string {
    return drive_http_raw(
        'GET',
        'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media',
        $token
    );
}
