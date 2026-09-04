<?php
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/drive.php';

function dump_database_sql(mysqli $con): string {
    $sql = "-- Tutor CRM backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = [];
    $r = $con->query('SHOW TABLES');
    if (!$r) throw new RuntimeException('Не удалось получить список таблиц.');
    while ($row = $r->fetch_row()) $tables[] = (string)$row[0];
    foreach ($tables as $t) {
        $safe = str_replace('`', '``', $t);
        $create = $con->query("SHOW CREATE TABLE `{$safe}`");
        $row = $create ? $create->fetch_assoc() : null;
        $ddl = (string)($row['Create Table'] ?? '');
        if ($ddl === '') throw new RuntimeException("Нет DDL для {$t}.");
        $sql .= "\nDROP TABLE IF EXISTS `{$safe}`;\n{$ddl};\n";
        $res = $con->query("SELECT * FROM `{$safe}`", MYSQLI_USE_RESULT);
        if (!$res) throw new RuntimeException("Не удалось прочитать {$t}.");
        while ($row = $res->fetch_assoc()) {
            $cols = [];
            $vals = [];
            foreach ($row as $col => $val) {
                $cols[] = '`' . str_replace('`', '``', (string)$col) . '`';
                $vals[] = $val === null ? 'NULL' : "'" . $con->real_escape_string((string)$val) . "'";
            }
            $sql .= 'INSERT INTO `' . $safe . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
        $res->free();
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return dump_sign_sql($sql);
}

function dump_sign_sql(string $sql): string {
    $body = rtrim(str_replace("\r\n", "\n", $sql)) . "\n";
    $sig = hash_hmac('sha256', $body, app_secret_bytes());
    return $body . '-- SIG ' . $sig . "\n";
}

function dump_verify_sql(string $sql): string {
    $sql = str_replace("\r\n", "\n", $sql);
    if (!preg_match('/^(.*?)\n-- SIG ([a-f0-9]{64})\s*\z/s', $sql, $m)) {
        throw new RuntimeException('Дамп без подписи.');
    }
    $body = $m[1] . "\n";
    $expect = hash_hmac('sha256', $body, app_secret_bytes());
    if (!hash_equals($expect, $m[2])) {
        throw new RuntimeException('Подпись дампа не совпадает.');
    }
    return $body;
}

function backup_period_key(mysqli $con, ?DateTimeImmutable $now = null): string {
    apply_app_timezone($con);
    $now = $now ?? new DateTimeImmutable('now');
    $freq = backup_freq($con);
    if ($freq === 'weekly') return $now->format('o-\WW');
    if ($freq === 'monthly') return $now->format('Y-m');
    return $now->format('Y-m-d');
}

function backup_is_due(mysqli $con, ?DateTimeImmutable $now = null): bool {
    apply_app_timezone($con);
    $now = $now ?? new DateTimeImmutable('now');
    if ($now->format('H:i') < backup_time($con)) return false;
    $freq = backup_freq($con);
    if ($freq === 'weekly' && (int)$now->format('N') !== backup_weekday($con)) return false;
    if ($freq === 'monthly') {
        $want = min(backup_monthday($con), (int)$now->format('t'));
        if ((int)$now->format('j') !== $want) return false;
    }
    return app_setting($con, 'backup_last_period', '') !== backup_period_key($con, $now);
}

function mark_backup_done(mysqli $con, ?DateTimeImmutable $now = null): void {
    apply_app_timezone($con);
    $now = $now ?? new DateTimeImmutable('now');
    save_app_setting($con, 'backup_last_period', backup_period_key($con, $now));
    save_app_setting($con, 'backup_last_at', $now->format('Y-m-d H:i:s'));
}

/** @return array{ok:bool,message:string} */
function backup_encrypt_bytes(string $plain): string {
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_secret_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || $tag === '') {
        throw new RuntimeException('Не удалось зашифровать дамп.');
    }
    return 'TENC1' . $iv . $tag . $ct;
}

function backup_decrypt_bytes(string $raw): string {
    if (!str_starts_with($raw, 'TENC1')) return $raw;
    if (strlen($raw) < 34) {
        throw new RuntimeException('Повреждённый зашифрованный дамп.');
    }
    $iv = substr($raw, 5, 12);
    $tag = substr($raw, 17, 16);
    $ct = substr($raw, 33);
    $pt = openssl_decrypt($ct, 'aes-256-gcm', app_secret_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($pt) || $pt === '') {
        throw new RuntimeException('Не удалось расшифровать дамп.');
    }
    return $pt;
}

function write_pre_restore_dump(mysqli $con): string {
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Нет каталога data/ для снимка.');
    }
    $path = $dir . '/pre-restore-' . date('Y-m-d_H-i-s') . '.sql.gz.enc';
    $gz = gzencode(dump_database_sql($con), 9);
    if ($gz === false) throw new RuntimeException('Не удалось сжать снимок.');
    if (file_put_contents($path, backup_encrypt_bytes($gz)) === false) {
        throw new RuntimeException('Не удалось сохранить снимок до restore.');
    }
    @chmod($path, 0600);
    return $path;
}

function restore_set_admin_password(mysqli $con, string $login, string $password): void {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $st = $con->prepare('UPDATE users SET password_hash=? WHERE login=? LIMIT 1');
    $st->bind_param('ss', $hash, $login);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    if ($n > 0) return;
    $st = $con->prepare("UPDATE users SET password_hash=? WHERE role='admin' ORDER BY id LIMIT 1");
    $st->bind_param('s', $hash);
    $st->execute();
    $st->close();
}

function run_db_backup(mysqli $con): array {
    apply_app_timezone($con);
    if (!app_mysql_lock($con, 'tutor_dump')) {
        return ['ok' => false, 'message' => 'Бэкап уже выполняется.'];
    }
    try {
        $db = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'tutormy';
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)$db)
            . '_' . date('Y-m-d_H-i-s') . '.sql.gz.enc';
        $gz = gzencode(dump_database_sql($con), 9);
        if ($gz === false) throw new RuntimeException('Не удалось сжать дамп.');
        $enc = backup_encrypt_bytes($gz);
        $token = drive_access_token($con);
        $folder = drive_ensure_folder($con, $token);
        drive_upload($token, $folder, $name, $enc, 'application/octet-stream');
        mark_backup_done($con);
        return ['ok' => true, 'message' => 'Бэкап загружен: ' . $name];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        app_mysql_unlock($con, 'tutor_dump');
    }
}

function apply_sql_dump(mysqli $con, string $sql): void {
    $sql = dump_verify_sql($sql);
    if ($sql === '') throw new RuntimeException('Пустой дамп.');
    if (!str_starts_with($sql, '-- Tutor CRM backup')) {
        throw new RuntimeException('Не наш дамп Tutor CRM.');
    }
    if (!app_mysql_lock($con, 'tutor_dump')) {
        throw new RuntimeException('Восстановление уже выполняется.');
    }
    try {
        write_pre_restore_dump($con);
        if (!$con->multi_query($sql)) {
            throw new RuntimeException($con->error ?: 'Ошибка импорта.');
        }
        do {
            if ($res = $con->store_result()) $res->free();
        } while ($con->more_results() && $con->next_result());
        if ($con->errno) {
            throw new RuntimeException($con->error);
        }
        bump_session_epoch($con, false);
    } finally {
        app_mysql_unlock($con, 'tutor_dump');
    }
}

/** @return array{ok:bool,message:string} */
function restore_drive_backup(mysqli $con, string $fileId): array {
    $fileId = preg_replace('/[^a-zA-Z0-9_-]/', '', $fileId) ?? '';
    if ($fileId === '') return ['ok' => false, 'message' => 'Не выбран файл.'];
    try {
        $token = drive_access_token($con);
        $folder = drive_ensure_folder($con, $token);
        $pick = null;
        foreach (drive_list_backups($token, $folder, 30) as $f) {
            if ((string)$f['id'] === $fileId) {
                $pick = $f;
                break;
            }
        }
        if ($pick === null) {
            return ['ok' => false, 'message' => 'Файл не найден в папке бэкапов.'];
        }
        $raw = drive_download($token, $fileId);
        apply_sql_dump($con, sql_from_backup_bytes($raw, (string)$pick['name']));
        return ['ok' => true, 'message' => 'База восстановлена: ' . $pick['name']];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function sql_from_backup_bytes(string $raw, string $name = ''): string {
    $low = strtolower($name);
    if (str_ends_with($low, '.enc') || str_starts_with($raw, 'TENC1')) {
        $raw = backup_decrypt_bytes($raw);
        $low = preg_replace('/\.enc$/', '', $low) ?? $low;
    }
    $gz = str_ends_with($low, '.gz')
        || (strlen($raw) >= 2 && $raw[0] === "\x1f" && $raw[1] === "\x8b");
    $sql = $gz ? gzdecode($raw) : $raw;
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Пустой или повреждённый дамп.');
    }
    return $sql;
}
