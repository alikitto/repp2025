<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$args = array_slice($argv ?? [], 1);
$yes = in_array('--yes', $args, true);
$list = in_array('--list', $args, true);
$latest = in_array('--latest', $args, true);
$driveName = '';
$path = '';
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--yes' || $a === '--list' || $a === '--latest') continue;
    if ($a === '--drive') {
        $driveName = (string)($args[$i + 1] ?? '');
        $i++;
        continue;
    }
    if ($a !== '' && $a[0] !== '-') $path = $a;
}

if (!$list && !$latest && $driveName === '' && $path === '') {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/restore_backup.php <backup.sql.gz[.enc]> --yes\n");
    fwrite(STDERR, "  php scripts/restore_backup.php --list\n");
    fwrite(STDERR, "  php scripts/restore_backup.php --latest --yes\n");
    fwrite(STDERR, "  php scripts/restore_backup.php --drive NAME.sql.gz --yes\n");
    exit(1);
}

require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/backup.php';

try {
    if ($path !== '') {
        if (!$yes) {
            fwrite(STDERR, "Это перезапишет базу. Повторите с --yes: {$path}\n");
            exit(2);
        }
        $raw = file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Не удалось прочитать файл.');
        apply_sql_dump($con, sql_from_backup_bytes($raw, $path));
        echo "Restore ok\n";
        exit(0);
    }

    $token = drive_access_token($con);
    $folder = drive_ensure_folder($con, $token);
    $files = drive_list_backups($token, $folder, $list ? 30 : 5);
    if ($files === []) throw new RuntimeException('На Google Drive нет файлов бэкапа.');

    if ($list) {
        foreach ($files as $f) {
            echo ($f['createdTime'] ?? '') . "\t" . $f['name'] . "\t" . $f['id'] . "\n";
        }
        exit(0);
    }

    $pick = null;
    if ($latest) {
        $pick = $files[0];
    } else {
        foreach ($files as $f) {
            if (strcasecmp((string)$f['name'], $driveName) === 0) {
                $pick = $f;
                break;
            }
        }
        if ($pick === null) throw new RuntimeException('Файл не найден: ' . $driveName);
    }

    if (!$yes) {
        fwrite(STDERR, "Это перезапишет базу. Повторите с --yes: " . $pick['name'] . "\n");
        exit(2);
    }

    $raw = drive_download($token, (string)$pick['id']);
    apply_sql_dump($con, sql_from_backup_bytes($raw, (string)$pick['name']));
    echo 'Restore ok: ' . $pick['name'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
