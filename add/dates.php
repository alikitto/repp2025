<?php
require_once __DIR__ . '/../profile/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';
require_once __DIR__ . '/../common/student_card.php';
csrf_check();

header('Content-Type: application/json; charset=utf-8');

function dates_json_err(int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
$date = require_past_or_today_ymd($_POST['dataa'] ?? null);
$visited = isset($_POST['visited']) ? 1 : 0;
if ($user_id <= 0) dates_json_err(400, 'bad user_id');
if ($date === null) dates_json_err(400, 'bad_date');
require_owned_student($con, $user_id);

$wd = (int)date('N', strtotime($date));
$st = $con->prepare("SELECT DATE_FORMAT(time,'%H:%i') AS t FROM schedule WHERE user_id=? AND weekday=? ORDER BY time");
$st->bind_param('ii', $user_id, $wd);
$st->execute();
$slots = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 't');
$st->close();

$posted = hm((string)($_POST['time'] ?? ''));
if (count($slots) >= 2) {
    if ($posted === '' || !in_array($posted, $slots, true)) {
        dates_json_err(400, 'need_slot');
    }
    $time = $posted . ':00';
} elseif (count($slots) === 1) {
    $time = $slots[0] . ':00';
    dates_realign_one($con, $user_id, $date, $time);
} else {
    $time = '00:00:00';
}

$upsert = $con->prepare("INSERT INTO dates (user_id, dates, time, visited) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE visited=VALUES(visited)");
$upsert->bind_param('issi', $user_id, $date, $time, $visited);
$ok = $upsert->execute();
$upsert->close();
if (!$ok) dates_json_err(500, 'save_failed');

$fio = activity_fio($con, $user_id);
$d = date('d.m.Y', strtotime($date));
$tm = hm($time);
log_activity($con, 'visit', ($visited ? 'Посещение: ' : 'Пропуск: ') . $fio . ' — ' . $d . ($tm !== '00:00' ? ' ' . $tm : ''), '/profile/student.php?user_id=' . $user_id);
$sid = $con->prepare("SELECT dates_id FROM dates WHERE user_id=? AND dates=? AND time=? LIMIT 1");
$sid->bind_param('iss', $user_id, $date, $time);
$sid->execute();
$sid->bind_result($did);
$sid->fetch();
$sid->close();
$did = (int)$did;
$row = ['dates_id' => $did, 'dates' => $date, 'time' => $time, 'visited' => $visited];
$out = ['ok' => true, 'id' => $did, 'row' => render_visit_row($row, $did)];
$out += student_card_stats($con, $user_id);
echo json_encode($out);
