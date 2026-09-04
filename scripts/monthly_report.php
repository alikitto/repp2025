<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/util.php';
require_once __DIR__ . '/../common/report.php';

apply_app_timezone($con);
$force = in_array('--force', $argv ?? [], true);

if ($force) {
    [$from, $to] = monthly_report_period();
    $teachers = $con->query("SELECT id, login FROM users WHERE role='teacher'");
    $ok_all = true;
    foreach ($teachers->fetch_all(MYSQLI_ASSOC) as $t) {
        $res = send_monthly_report($con, $from, $to, (int)$t['id']);
        fwrite($res['ok'] ? STDOUT : STDERR, $t['login'] . ': ' . $res['message'] . "\n");
        if (!$res['ok']) $ok_all = false;
    }
    exit($ok_all ? 0 : 1);
}

maybe_send_monthly_report($con);
