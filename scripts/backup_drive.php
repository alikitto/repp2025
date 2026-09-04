<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/backup.php';

apply_app_timezone($con);
$force = in_array('--force', $argv ?? [], true);
if (!$force && !backup_is_due($con)) {
    echo "Backup not due\n";
    exit(0);
}

$res = run_db_backup($con);
if (!$res['ok']) {
    fwrite(STDERR, $res['message'] . "\n");
    exit(1);
}
echo $res['message'] . "\n";
