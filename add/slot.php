<?php
require_once __DIR__ . '/../profile/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/groups.php';
csrf_check_json();

header('Content-Type: application/json; charset=utf-8');

function slot_json_err(int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$wd = (int)($_POST['weekday'] ?? 0);
$tm = hm((string)($_POST['time'] ?? ''));
$te = hm((string)($_POST['time_end'] ?? ''));
$allowed = array_flip(schedule_time_options());
if ($wd < 1 || $wd > 7 || $tm === '' || !isset($allowed[$tm])) {
    slot_json_err(400, 'Некорректный слот');
}
if ($te !== '' && !isset($allowed[$te])) $te = '';
$te = slot_end($te, $tm);
$block_id = (int)($_POST['block_id'] ?? 0);
$partial = !empty($_POST['partial']) ? 1 : 0;
$arrival = hm((string)($_POST['arrival'] ?? ''));
if ($arrival !== '' && !isset($allowed[$arrival])) $arrival = '';
$new_slots = [[$wd, $tm, $te]];
$days = [1=>'понедельник',2=>'вторник',3=>'среду',4=>'четверг',5=>'пятницу',6=>'субботу',7=>'воскресенье'];

if ($action === 'create_block' || $action === 'update_block' || $action === 'delete_block') {
    if ($action !== 'delete_block' && time_to_min($te) <= time_to_min($tm)) {
        slot_json_err(400, 'Конец должен быть позже начала');
    }
    $tid = teacher_id();
    if ($action === 'create_block') {
        $id = find_or_create_block($con, $tid, $wd, $tm, $te);
        echo json_encode(['ok' => true, 'block_id' => $id, 'weekday' => $wd, 'time' => $tm, 'time_end' => $te], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bid = $block_id > 0 ? $block_id : 0;
    $block = $bid > 0 ? own_block($con, $bid, $tid) : null;
    if (!$block) slot_json_err(404, 'Блок не найден');
    $old_start = hm((string)$block['start']);
    $old_end = hm((string)$block['end']);
    $n = block_member_count($con, $bid);
    $label = ($days[(int)$block['weekday']] ?? '') . ' ' . $old_start . '–' . $old_end;
    if ($action === 'update_block') {
        $dup = $con->prepare("SELECT id FROM sched_blocks WHERE teacher_id=? AND weekday=? AND DATE_FORMAT(start,'%H:%i')=? AND prelude=0 AND id<>? LIMIT 1");
        $dup->bind_param('iisi', $tid, $wd, $tm, $bid);
        $dup->execute();
        $exists = (int)($dup->get_result()->fetch_assoc()['id'] ?? 0);
        $dup->close();
        if ($exists > 0) slot_json_err(400, 'Блок на это время уже есть');
        $up = $con->prepare("UPDATE sched_blocks SET start=?, end=? WHERE id=?");
        $up->bind_param('ssi', $tm, $te, $bid);
        $up->execute();
        $up->close();
        if ($old_start !== $tm) {
            $st = $con->prepare("UPDATE schedule SET time=? WHERE block_id=? AND partial=0 AND DATE_FORMAT(time,'%H:%i')=?");
            $st->bind_param('sis', $tm, $bid, $old_start);
            $st->execute();
            $st->close();
        }
        if ($old_end !== $te) {
            $st = $con->prepare("UPDATE schedule SET time_end=? WHERE block_id=? AND DATE_FORMAT(time_end,'%H:%i')=?");
            $st->bind_param('sis', $te, $bid, $old_end);
            $st->execute();
            $st->close();
        }
        log_activity($con, 'student_edit', 'Блок изменён: ' . $label . ' → ' . $tm . '–' . $te, '/profile/schedule.php');
        echo json_encode(['ok' => true, 'block_id' => $bid, 'time' => $tm, 'time_end' => $te], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $del = $con->prepare("DELETE FROM schedule WHERE block_id=?");
    $del->bind_param('i', $bid);
    $del->execute();
    $del->close();
    $del = $con->prepare("DELETE FROM sched_blocks WHERE id=? AND teacher_id=?");
    $del->bind_param('ii', $bid, $tid);
    $del->execute();
    $del->close();
    log_activity($con, 'student_edit', 'Удалён блок: ' . $label . ($n ? ' · ' . $n . ' уч.' : ''), '/profile/schedule.php');
    echo json_encode(['ok' => true, 'block_id' => $bid, 'removed' => $n], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set_partial') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0 || !stud_owned($con, $uid)) {
        slot_json_err(400, 'Ученик не найден');
    }
    $when = $arrival !== '' ? $arrival : $tm;
    $st = $con->prepare("SELECT id, block_id, time_end FROM schedule WHERE user_id=? AND weekday=? AND DATE_FORMAT(time,'%H:%i')=? LIMIT 1");
    $st->bind_param('iis', $uid, $wd, $tm);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) slot_json_err(404, 'Слот не найден');
    $sid = (int)$row['id'];
    $up = $con->prepare("UPDATE schedule SET time=?, partial=1 WHERE id=?");
    $up->bind_param('si', $when, $sid);
    $up->execute();
    $up->close();
    $fio = activity_fio($con, $uid);
    log_activity($con, 'student_edit', 'Промежуточный: ' . $fio . ' — ' . ($days[$wd] ?? '') . ' ' . $when, '/profile/student.php?user_id=' . $uid);
    echo json_encode(['ok' => true, 'user_id' => $uid, 'weekday' => $wd, 'time' => $when], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'unassign') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0 || !stud_owned($con, $uid)) {
        slot_json_err(400, 'Ученик не найден');
    }
    if (!app_mysql_lock($con, 'tutor_group_slots', 5)) {
        slot_json_err(409, 'Занятие сейчас меняет другой запрос. Повторите.');
    }
    $fio = activity_fio($con, $uid);
    $st = $con->prepare("DELETE FROM schedule WHERE user_id=? AND weekday=? AND DATE_FORMAT(time,'%H:%i')=?");
    $st->bind_param('iis', $uid, $wd, $tm);
    $ok = $st->execute();
    $n = $st->affected_rows;
    $st->close();
    app_mysql_unlock($con, 'tutor_group_slots');
    if (!$ok || $n < 1) {
        slot_json_err(404, 'Слот не найден');
    }
    $label = ($days[$wd] ?? '') . ' ' . $tm;
    log_activity($con, 'student_edit', 'Убран из расписания: ' . $fio . ' — ' . $label, '/profile/student.php?user_id=' . $uid);
    echo json_encode(['ok' => true, 'fio' => $fio, 'user_id' => $uid, 'weekday' => $wd, 'time' => $tm], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'assign') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0 || !stud_owned($con, $uid)) {
        slot_json_err(400, 'Ученик не найден');
    }
} elseif ($action === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    $lastname = trim((string)($_POST['lastname'] ?? ''));
    $klass = trim((string)($_POST['klass'] ?? ''));
    $money = (float)($_POST['money'] ?? 0);
    if ($money <= 0) $money = default_lesson_price($con);
    $pay_mode = student_pay_mode($_POST['pay_mode'] ?? 'prepaid');
    if ($name === '') slot_json_err(400, 'Укажите имя');
    if ($money <= 0) slot_json_err(400, 'Укажите цену урока');
} else {
    slot_json_err(400, 'Неизвестное действие');
}

if (!app_mysql_lock($con, 'tutor_group_slots', 5)) {
    slot_json_err(409, 'Занятие сейчас меняет другой запрос. Повторите.');
}

if ($action === 'assign') {
    $st = $con->prepare("SELECT weekday, time, time_end FROM schedule WHERE user_id=?");
    $st->bind_param('i', $uid);
    $st->execute();
    $own = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach ($own as $r) {
        $es = hm($r['time']);
        $ee = slot_end($r['time_end'] ?? '', $es);
        if ((int)$r['weekday'] === $wd && $es === $tm) {
            app_mysql_unlock($con, 'tutor_group_slots');
            slot_json_err(400, 'Этот ученик уже в слоте');
        }
        if ((int)$r['weekday'] === $wd && overlaps($tm, $te, $es, $ee)) {
            app_mysql_unlock($con, 'tutor_group_slots');
            slot_json_err(400, 'Пересекается с '.$es.'–'.$ee);
        }
    }
}

$except = $action === 'assign' ? $uid : 0;
$check = group_slots_overflow($con, $new_slots, $except);
if ($check['error']) {
    app_mysql_unlock($con, 'tutor_group_slots');
    slot_json_err(400, $check['error']);
}

mysqli_begin_transaction($con);
try {
    if ($action === 'create') {
        $tid = teacher_id();
        $stmt = $con->prepare("INSERT INTO stud (name, lastname, klass, money, pay_mode, teacher_id) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('sssdsi', $name, $lastname, $klass, $money, $pay_mode, $tid);
        if (!$stmt->execute()) throw new RuntimeException('stud insert failed');
        $uid = (int)$stmt->insert_id;
        $stmt->close();
        if ($uid <= 0) throw new RuntimeException('stud insert_id empty');
    }

    if ($block_id > 0) {
        $own = $con->prepare("SELECT id FROM sched_blocks WHERE id=? AND teacher_id=? AND weekday=? LIMIT 1");
        $tid = teacher_id();
        $own->bind_param('iii', $block_id, $tid, $wd);
        $own->execute();
        $ok = (int)($own->get_result()->fetch_assoc()['id'] ?? 0);
        $own->close();
        if ($ok < 1) $block_id = 0;
    }
    if ($block_id < 1) {
        $block_id = find_or_create_block($con, teacher_id(), $wd, $tm, $te);
    }
    $slot_time = ($partial && $arrival !== '') ? $arrival : $tm;
    $ins = $con->prepare("INSERT INTO schedule (user_id, weekday, time, time_end, block_id, partial) VALUES (?,?,?,?,?,?)");
    $ins->bind_param('iissii', $uid, $wd, $slot_time, $te, $block_id, $partial);
    if (!$ins->execute()) throw new RuntimeException('schedule insert failed');
    $ins->close();

    mysqli_commit($con);
    app_mysql_unlock($con, 'tutor_group_slots');
    if ($check['warn']) $_SESSION['flash_warn'] = $check['warn'];

    $fio = $action === 'create'
        ? trim($lastname . ' ' . $name)
        : activity_fio($con, $uid);
    $label = ($days[$wd] ?? '') . ' ' . $tm;
    log_activity(
        $con,
        $action === 'create' ? 'student_add' : 'student_edit',
        ($action === 'create' ? 'Добавлен ученик ' : 'В расписание: ') . $fio . ' — ' . $label,
        '/profile/student.php?user_id=' . $uid
    );

    $name_out = $action === 'create' ? $name : '';
    if ($name_out === '') {
        $ns = $con->prepare("SELECT name FROM stud WHERE user_id=?");
        $ns->bind_param('i', $uid);
        $ns->execute();
        $name_out = (string)($ns->get_result()->fetch_assoc()['name'] ?? '');
        $ns->close();
    }
    echo json_encode([
        'ok' => true,
        'warn' => $check['warn'],
        'fio' => $fio,
        'name' => $name_out,
        'user_id' => $uid,
        'weekday' => $wd,
        'time' => $tm,
        'time_end' => $te,
        'created' => $action === 'create',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    mysqli_rollback($con);
    app_mysql_unlock($con, 'tutor_group_slots');
    slot_json_err(500, 'Не удалось сохранить');
}
