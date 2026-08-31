<?php
// /profile/student.php — карточка ученика
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';
require_once __DIR__ . '/../common/groups.php';

// Проверяем flash-сообщение об успешном обновлении
$flash_updated = $_SESSION['flash_updated'] ?? null;
unset($_SESSION['flash_updated']);
$flash_warn = $_SESSION['flash_warn'] ?? '';
unset($_SESSION['flash_warn']);

function fmt_amount($a){ return number_format((float)$a, 2, '.', ''); }
function fmt_date($d) {
    if (empty($d)) return '—';
    $ts = strtotime($d);
    return $ts ? date('d.m.Y', $ts) : h($d);
}
function trash_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';
}
function render_visit_row(array $row): string {
    $date = fmt_date($row['dates']);
    $tm = hm($row['time'] ?? '');
    $ok = !empty($row['visited']);
    $status = $ok ? 'Пришёл' : 'Не пришёл';
    $label = $date . ($tm !== '' && $tm !== '00:00' ? ' · ' . $tm : '');
    return '<div class="hist-row"><div class="name">'.h($label).'</div>'
        .'<span class="chip '.($ok ? 'ok' : 'bad').'">'.$status.'</span>'
        .'<button class="icon-btn js-del-visit" data-id="'.(int)$row['dates_id'].'" data-date="'.h($label).'" aria-label="Удалить">'.trash_svg().'</button></div>';
}
function render_pay_row(array $p, int $flash_id = 0): string {
    $date = fmt_date($p['date']);
    $lessons = (int)$p['lessons'];
    $mic = !empty($p['voice'])
        ? '<span class="pay-voice" title="Голосом" aria-label="Голосом"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg></span>'
        : '';
    $flash = $flash_id > 0 && (int)$p['id'] === $flash_id ? ' is-flash' : '';
    return '<div class="hist-row'.$flash.'"><div><div class="name">'.h($date).$mic.'</div><div class="sub">'.$lessons.' ур. · '.fmt_amount($p['amount']).' AZN</div></div>'
        .'<button class="icon-btn js-del-pay" data-id="'.(int)$p['id'].'" data-date="'.h($date).'" aria-label="Удалить">'.trash_svg().'</button></div>';
}

function student_card_stats(mysqli $con, int $user_id): array {
    $st = $con->prepare("SELECT COALESCE(money,0) AS money, pay_mode FROM stud WHERE user_id=?");
    $st->bind_param('i', $user_id);
    $st->execute();
    $stud = $st->get_result()->fetch_assoc();
    $st->close();
    $money = (float)($stud['money'] ?? 0);
    $pm = student_pay_mode($stud['pay_mode'] ?? 'prepaid');

    $st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND visited=1");
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($visits_count);
    $st->fetch();
    $st->close();

    $st = $con->prepare("SELECT COALESCE(SUM(lessons),0), COUNT(*) FROM pays WHERE user_id=?");
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($paid_lessons, $pays_count);
    $st->fetch();
    $st->close();

    $st = $con->prepare("SELECT `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 1");
    $st->bind_param('i', $user_id);
    $st->execute();
    $last_pay = $st->get_result()->fetch_assoc();
    $st->close();

    $bal = (int)$paid_lessons - (int)$visits_count;
    $unit_price = pack_unit_price($money, $last_pay['amount'] ?? null, $last_pay['lessons'] ?? null);

    $period_from = '';
    $period_visits = (int)$visits_count;
    $period_missed = 0;
    if ($bal < 0) {
        $st = $con->prepare("SELECT `dates` FROM dates WHERE user_id=? AND COALESCE(visited,0)=1 ORDER BY `dates`, dates_id LIMIT 1 OFFSET ?");
        $paid = (int)$paid_lessons;
        $st->bind_param('ii', $user_id, $paid);
        $st->execute();
        $period_from = (string)($st->get_result()->fetch_assoc()['dates'] ?? '');
        $st->close();
        if ($period_from !== '') {
            $st = $con->prepare("SELECT SUM(COALESCE(visited,0)=1), SUM(COALESCE(visited,0)=0) FROM dates WHERE user_id=? AND `dates`>=?");
            $st->bind_param('is', $user_id, $period_from);
            $st->execute();
            $st->bind_result($period_visits, $period_missed);
            $st->fetch();
            $st->close();
        }
    } else {
        $st = $con->prepare("SELECT SUM(COALESCE(visited,0)=0) FROM dates WHERE user_id=?");
        $st->bind_param('i', $user_id);
        $st->execute();
        $st->bind_result($period_missed);
        $st->fetch();
        $st->close();
    }

    $html = '<div class="tone-'.h(balance_tone($bal, $pm)).'">'
        .'<b>'.($bal > 0 ? '+'.$bal : (string)$bal).'</b>'
        .'<span>'.($bal < 0 ? 'долг' : 'баланс').'</span>'
        .($bal < 0 ? '<span class="debt-azn">'.fmt_amount(debt_azn($bal, $unit_price)).' AZN</span>' : '')
        .'</div>'
        .'<div><b>'.(int)$visits_count.'</b><span>визиты</span></div>'
        .'<div><b>'.(int)$paid_lessons.'</b><span>оплачено</span></div>'
        .'<div><b>'.(int)$pays_count.'</b><span>оплаты</span></div>';

    return [
        'html' => $html,
        'parent' => [
            'debtLessons' => $bal < 0 ? abs($bal) : 0,
            'debtAzn' => $bal < 0 ? debt_azn($bal, $unit_price) : 0,
            'remain' => max(0, $bal),
            'periodFrom' => $period_from !== '' ? fmt_date($period_from) : '',
            'visits' => (int)$period_visits,
            'missed' => (int)$period_missed,
            'lastDate' => $last_pay ? fmt_date($last_pay['date']) : '',
            'lastLessons' => $last_pay ? (int)$last_pay['lessons'] : 0,
            'lastAzn' => $last_pay ? (float)$last_pay['amount'] : 0,
        ],
    ];
}

/* ---------- AJAX HANDLER ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check_json();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid > 0 && !stud_owned($con, $uid)) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'not_found']);
        exit;
    }

    // Удаление посещения
    if ($action === 'delete_visit') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || $uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }
        $info = $con->prepare("SELECT d.`dates`, TRIM(CONCAT(s.lastname,' ',s.name)) AS fio FROM dates d JOIN stud s ON s.user_id=d.user_id WHERE d.dates_id=? AND d.user_id=?");
        $info->bind_param('ii', $id, $uid);
        $info->execute();
        $row = $info->get_result()->fetch_assoc();
        $info->close();
        $st = $con->prepare("DELETE FROM dates WHERE dates_id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $ok = $st->execute();
        if ($ok && $row) {
            $d = date('d.m.Y', strtotime((string)$row['dates']));
            log_activity($con, 'visit_del', 'Удалено посещение: ' . $row['fio'] . ' — ' . $d, '/profile/student.php?user_id=' . $uid);
        }
        $out = ['ok'=>$ok];
        if ($ok) $out += student_card_stats($con, $uid);
        echo json_encode($out);
        exit;
    }

    // Удаление оплаты
    if ($action === 'delete_pay') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || $uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }
        $info = $con->prepare("SELECT p.lessons, TRIM(CONCAT(s.lastname,' ',s.name)) AS fio FROM pays p JOIN stud s ON s.user_id=p.user_id WHERE p.id=? AND p.user_id=?");
        $info->bind_param('ii', $id, $uid);
        $info->execute();
        $row = $info->get_result()->fetch_assoc();
        $info->close();
        $st = $con->prepare("DELETE FROM pays WHERE id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $ok = $st->execute();
        if ($ok && $row) {
            log_activity($con, 'pay_del', 'Удалена оплата: ' . $row['fio'] . ' — ' . (int)$row['lessons'] . ' ур.', '/profile/student.php?user_id=' . $uid);
        }
        $out = ['ok'=>$ok];
        if ($ok) $out += student_card_stats($con, $uid);
        echo json_encode($out);
        exit;
    }

    // Архив ученика
    if ($action === 'delete_student') {
        $st = $con->prepare("SELECT TRIM(CONCAT(lastname,' ',name)) AS fio FROM stud WHERE user_id=?");
        $st->bind_param('i', $uid);
        $st->execute();
        $fio = (string)($st->get_result()->fetch_assoc()['fio'] ?? '');
        $st->close();
        $answer = trim((string)($_POST['csrf_check_answer'] ?? ''));
        if ($fio === '' || mb_strtolower($answer) !== mb_strtolower($fio)) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'wrong_answer']);
            exit;
        }
        $tid = teacher_id();
        $st = $con->prepare("UPDATE stud SET archived=1 WHERE user_id=? AND teacher_id=? AND archived=0");
        $st->bind_param('ii', $uid, $tid);
        $ok = $st->execute() && $st->affected_rows > 0;
        $st->close();
        if ($ok) {
            log_activity($con, 'student_archive', 'Ученик ушёл: ' . $fio, '/profile/list.php?tab=left');
        }
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    if ($action === 'restore_student') {
        $st = $con->prepare("SELECT TRIM(CONCAT(lastname,' ',name)) AS fio FROM stud WHERE user_id=?");
        $st->bind_param('i', $uid);
        $st->execute();
        $fio = (string)($st->get_result()->fetch_assoc()['fio'] ?? '');
        $st->close();
        $answer = trim((string)($_POST['csrf_check_answer'] ?? ''));
        if ($fio === '' || mb_strtolower($answer) !== mb_strtolower($fio)) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'wrong_answer']);
            exit;
        }
        $tid = teacher_id();
        $st = $con->prepare("UPDATE stud SET archived=0 WHERE user_id=? AND teacher_id=? AND archived=1");
        $st->bind_param('ii', $uid, $tid);
        $ok = $st->execute() && $st->affected_rows > 0;
        $st->close();
        if ($ok) {
            log_activity($con, 'student_restore', 'Возвращён ученик ' . $fio, '/profile/student.php?user_id=' . $uid);
        }
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    // Пагинация: Загрузить еще посещения
    if ($action === 'load_more_visits') {
        $offset = (int)($_POST['offset'] ?? 0);
        $v_filter = in_array($_POST['v'] ?? 'all', ['all','1','0'], true) ? $_POST['v'] : 'all';
        $sql = "SELECT dates_id, `dates`, `time`, COALESCE(visited,0) AS visited FROM dates WHERE user_id=? ";
        if ($v_filter === '1') { $sql .= "AND visited=1 "; } elseif ($v_filter === '0') { $sql .= "AND visited=0 "; }
        $sql .= "ORDER BY `dates` DESC, dates_id DESC LIMIT 15 OFFSET ?";
        
        $st = $con->prepare($sql);
        $st->bind_param('ii', $uid, $offset);
        $st->execute();
        $visits = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        $html = '';
        foreach ($visits as $row) { $html .= render_visit_row($row); }
        echo json_encode(['html' => $html, 'count' => count($visits)]);
        exit;
    }

    // Пагинация: Загрузить еще оплаты
    if ($action === 'load_more_pays') {
        $offset = (int)($_POST['offset'] ?? 0);
        $st = $con->prepare("SELECT id, `date`, lessons, amount, voice FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 15 OFFSET ?");
        $st->bind_param('ii', $uid, $offset);
        $st->execute();
        $pays = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $html = '';
        foreach ($pays as $p) { $html .= render_pay_row($p); }
        echo json_encode(['html' => $html, 'count' => count($pays)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    exit;
}
/* ------------------------------------------------------------------ */

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); echo "Bad user_id"; exit; }

// Ученик
require_owned_student($con, $user_id);
$st = $con->prepare("SELECT user_id, lastname, name, klass, COALESCE(money,0) AS money, pay_mode, COALESCE(archived,0) AS archived FROM stud WHERE user_id = ? AND teacher_id=?"); $tid = teacher_id(); $st->bind_param('ii', $user_id, $tid); $st->execute(); $student = $st->get_result()->fetch_assoc(); $st->close();
if (!$student) { render_not_found('Ученик не найден'); }

// Расписание
$st = $con->prepare("SELECT weekday, `time`, time_end FROM schedule WHERE user_id = ? ORDER BY weekday, `time`"); $st->bind_param('i', $user_id); $st->execute(); $schedule = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
$fio = trim(($student['lastname'] ?? '').' '.$student['name']);
$is_archived = (int)($student['archived'] ?? 0) === 1;
$weekdays_map = ['1'=>'Понедельник', '2'=>'Вторник', '3'=>'Среда', '4'=>'Четверг', '5'=>'Пятница', '6'=>'Суббота', '7'=>'Воскресенье'];
$weekdays_short = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];

// Фильтр и счетчики
$v = in_array($_GET['v'] ?? 'all', ['all','1','0'], true) ? $_GET['v'] : 'all';
$hist = ($_GET['tab'] ?? '') === 'pays' ? 'pays' : 'visits';
$flash_pay = (int)($_GET['pay'] ?? 0);
$sqlVisitsCount = "SELECT COUNT(*) FROM dates WHERE user_id=? ";
if ($v === '1') { $sqlVisitsCount .= "AND visited=1 "; } elseif ($v === '0') { $sqlVisitsCount .= "AND visited=0 "; }
$st = $con->prepare($sqlVisitsCount); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($total_visits); $st->fetch(); $st->close();
$st = $con->prepare("SELECT COUNT(*) FROM pays WHERE user_id=?"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($total_pays); $st->fetch(); $st->close();
$st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND visited=1"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($visits_count); $st->fetch(); $st->close();
$st = $con->prepare("SELECT COALESCE(SUM(lessons),0) AS paid_lessons, COUNT(*) AS pays_count, COALESCE(SUM(amount),0) AS paid_amount FROM pays WHERE user_id=?"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($paid_lessons, $pays_count, $paid_amount); $st->fetch(); $st->close();
$st = $con->prepare("SELECT `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 1"); $st->bind_param('i', $user_id); $st->execute(); $last_pay = $st->get_result()->fetch_assoc(); $st->close();
$balance_lessons = (int)$paid_lessons - (int)$visits_count;
$unit_price = pack_unit_price((float)$student['money'], $last_pay['amount'] ?? null, $last_pay['lessons'] ?? null);

// Отчёт родителю: предоплата — весь баланс; должник — с первого неоплаченного урока
$period_from = '';
$period_visits = (int)$visits_count;
$period_missed = 0;
if ($balance_lessons < 0) {
    $st = $con->prepare("SELECT `dates` FROM dates WHERE user_id=? AND COALESCE(visited,0)=1 ORDER BY `dates`, dates_id LIMIT 1 OFFSET ?");
    $paid = (int)$paid_lessons;
    $st->bind_param('ii', $user_id, $paid);
    $st->execute();
    $period_from = (string)($st->get_result()->fetch_assoc()['dates'] ?? '');
    $st->close();
    if ($period_from !== '') {
        $st = $con->prepare("SELECT SUM(COALESCE(visited,0)=1), SUM(COALESCE(visited,0)=0) FROM dates WHERE user_id=? AND `dates`>=?");
        $st->bind_param('is', $user_id, $period_from);
        $st->execute();
        $st->bind_result($period_visits, $period_missed);
        $st->fetch();
        $st->close();
    }
} else {
    $st = $con->prepare("SELECT SUM(COALESCE(visited,0)=0) FROM dates WHERE user_id=?");
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($period_missed);
    $st->fetch();
    $st->close();
}
$period_visits = (int)$period_visits;
$period_missed = (int)$period_missed;

// Данные для таблиц (первые 10 записей)
$sqlVisits = "SELECT dates_id, user_id, `dates`, `time`, COALESCE(visited,0) AS visited FROM dates WHERE user_id=? ";
if ($v === '1') { $sqlVisits .= "AND visited=1 "; } elseif ($v === '0') { $sqlVisits .= "AND visited=0 "; }
$sqlVisits .= "ORDER BY `dates` DESC, dates_id DESC LIMIT 10";
$st = $con->prepare($sqlVisits); $st->bind_param('i', $user_id); $st->execute(); $visits = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
$st = $con->prepare("SELECT id, user_id, `date`, lessons, amount, voice FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 10"); $st->bind_param('i', $user_id); $st->execute(); $pays = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
$st = $con->prepare("SELECT DISTINCT `date` FROM pays WHERE user_id=?"); $st->bind_param('i', $user_id); $st->execute(); $pay_dates = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'date'); $st->close();
$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= h($student['lastname'].' '.$student['name']) ?> — Карточка ученика</title>
  <link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
<?php $active = 'list'; $back = '/profile/list.php'; require __DIR__ . '/../common/nav.php'; ?>

<?php
  $bal = (int)$balance_lessons;
  $base = '/profile/student.php?user_id='.(int)$user_id;
?>
<div class="content">
  <div class="card student-card">
    <?php if ($flash_warn): ?><p class="notice warn"><?= h($flash_warn) ?></p><?php endif; ?>
    <div class="student-head">
      <div>
        <?php $pm = student_pay_mode($student['pay_mode'] ?? 'prepaid'); ?>
        <h1>
          <span class="student-name"><?= h($fio) ?></span>
          <span class="chip <?= student_pay_mode_chip($pm) ?>"><?= h(student_pay_mode_label($pm)) ?></span><?php if ($is_archived): ?>
          <span class="chip gone">Ушёл</span><?php endif; ?>
        </h1>
        <p class="student-meta"><?= trim((string)$student['klass']) !== '' ? h($student['klass']).' класс · ' : '' ?><?= fmt_price((float)$student['money']) ?> AZN/урок · <?= fmt_price((float)$student['money'] * current_pack_lessons()) ?> AZN/месяц</p>
      </div>
      <div class="student-head-side">
        <a class="icon-btn" href="/profile/edit_student.php?user_id=<?= (int)$user_id ?>" aria-label="Редактировать">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
        </a>
        <?php if ($is_archived): ?>
        <button type="button" class="btn sm" id="btnRestoreStudent">Вернуть</button>
        <?php else: ?>
        <button type="button" class="icon-btn danger" id="btnDeleteStudent" aria-label="Удалить ученика"><?= trash_svg() ?></button>
        <?php endif; ?>
      </div>
    </div>
    <div class="student-stats">
      <div class="tone-<?= balance_tone($bal, student_pay_mode($student['pay_mode'] ?? 'prepaid')) ?>">
        <b><?= $bal > 0 ? '+'.$bal : $bal ?></b>
        <span><?= $bal < 0 ? 'долг' : 'баланс' ?></span>
        <?php if ($bal < 0): ?><span class="debt-azn"><?= fmt_amount(debt_azn($bal, $unit_price)) ?> AZN</span><?php endif; ?>
      </div>
      <div>
        <b><?= (int)$visits_count ?></b>
        <span>визиты</span>
      </div>
      <div>
        <b><?= (int)$paid_lessons ?></b>
        <span>оплачено</span>
      </div>
      <div>
        <b><?= (int)$pays_count ?></b>
        <span>оплаты</span>
      </div>
    </div>

    <?php if ($schedule): ?>
    <div class="sched-list">
      <?php foreach ($schedule as $item):
        $start = substr($item['time'], 0, 5);
      ?>
      <div class="sched-row">
        <span class="sched-day"><?= h($weekdays_short[(int)$item['weekday']] ?? '') ?></span>
        <time><?= h($start) ?>–<?= h(slot_end($item['time_end'] ?? null, $start)) ?></time>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="student-actions">
      <button type="button" class="btn" id="btnAddVisit">Посещение</button>
      <button type="button" class="btn pay" id="btnAddPay">Оплата</button>
      <button type="button" class="btn gray" id="btnParentReport">Отчёт родителю</button>
    </div>
  </div>

  <div class="card hist-card">
    <div class="hist-tabs">
      <button type="button" class="<?= $hist==='visits'?'is-active':'' ?>" data-hist="visits">Посещения</button>
      <button type="button" class="<?= $hist==='pays'?'is-active':'' ?>" data-hist="pays">Оплаты</button>
    </div>

    <div class="hist-panel" data-hist="visits"<?= $hist==='pays' ? ' hidden' : '' ?>>
      <div class="seg">
        <a class="<?= $v==='all'?'is-active':'' ?>" href="<?= h($base) ?>">Все</a>
        <a class="<?= $v==='1'?'is-active':'' ?>" href="<?= h($base.'&v=1') ?>">Пришёл</a>
        <a class="<?= $v==='0'?'is-active':'' ?>" href="<?= h($base.'&v=0') ?>">Не пришёл</a>
      </div>
      <div class="hist-list" id="visits-list">
        <?php if (!$visits): ?>
          <p class="muted">Пока нет записей.</p>
        <?php else: foreach ($visits as $row) echo render_visit_row($row); endif; ?>
      </div>
      <?php if ($total_visits > 10): ?>
        <div class="load-more-container"><button type="button" class="btn gray sm" id="load-more-visits" data-offset="10">Ещё</button></div>
      <?php endif; ?>
    </div>

    <div class="hist-panel" data-hist="pays"<?= $hist==='pays' ? '' : ' hidden' ?>>
      <div class="hist-list" id="pays-list">
        <?php if (!$pays): ?>
          <p class="muted">Пока оплат нет.</p>
        <?php else: foreach ($pays as $p) echo render_pay_row($p, $flash_pay); endif; ?>
      </div>
      <?php if ($total_pays > 10): ?>
        <div class="load-more-container"><button type="button" class="btn gray sm" id="load-more-pays" data-offset="10">Ещё</button></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<div id="modalVisit" class="modal" hidden><div class="modal-card"><button class="modal-close" aria-label="Закрыть">✕</button><h3>Добавить посещение</h3><div class="muted" style="margin-bottom:8px;"><?= h($student['lastname'].' '.$student['name']) ?></div><form class="form" data-no-submit="1"><label>Дата</label><input type="date" id="visit_date" class="input" required max="<?= h(date('Y-m-d')) ?>"><div id="visit_slot_wrap" hidden style="margin-top:8px;"><label for="visit_time">Время</label><select id="visit_time" class="input"></select></div><div style="margin-top:8px;"><label><input type="checkbox" id="visit_visited" checked> Пришёл</label></div><div class="actions"><button type="button" class="btn gray sm modal-close">Отмена</button><button type="button" id="visitSubmit" class="btn primary sm">Сохранить</button></div></form></div></div>
<div id="modalPay" class="modal" hidden><div class="modal-card"><button class="modal-close" aria-label="Закрыть">✕</button><h3>Добавить оплату</h3><div class="muted" style="margin-bottom:8px;"><?= h($student['lastname'].' '.$student['name']) ?></div><form class="form" data-no-submit="1"><label>Дата оплаты</label><input type="date" id="pay_date" class="input" required><p id="pay_dup_warn" class="notice err" hidden style="margin-top:8px">На эту дату уже есть оплата. Можно добавить ещё одну.</p><label style="margin-top:8px;">Кол-во уроков</label><input type="number" id="pay_lessons" class="input" value="<?= (int)current_pack_lessons() ?>" min="1" required><label style="margin-top:8px;">Сумма (AZN)</label><input type="text" id="pay_amount" class="input" readonly><div class="muted">Сумма рассчитывается на сервере.</div><div class="actions"><button type="button" class="btn gray sm modal-close">Отмена</button><button type="button" id="paySubmit" class="btn pay sm">Сохранить</button></div></form></div></div>
<div id="modalConfirm" class="modal" hidden><div class="modal-card"><h3 id="confirmTitle">Подтверждение</h3><p id="confirmText" class="muted"></p><div class="actions"><button type="button" class="btn gray sm modal-close">Отмена</button><button type="button" id="confirmYes" class="btn danger sm">Удалить</button></div></div></div>
<div id="modalSuccess" class="modal" hidden><div class="modal-card success" role="alert"><button class="modal-close" aria-label="Закрыть" style="color:#fff;">✕</button><div class="toast-icon">✔</div><h3 class="toast-title" style="margin:6px 0;"></h3><p class="toast-text" style="opacity:.9;margin:0 0 6px 0;"></p></div></div>
<div id="modalNotify" class="modal" hidden><div class="modal-card notify" role="alert"><button class="modal-close" aria-label="Закрыть" style="color:#fff;">✕</button><div class="toast-icon">🗑️</div><h3 class="toast-title" style="margin:6px 0;"></h3><p class="toast-text" style="opacity:.9;margin:0 0 6px 0;"></p></div></div>
<div id="modalDeleteStudent" class="modal" hidden><div class="modal-card"><button class="modal-close" aria-label="Закрыть">✕</button><h3>Ученик ушёл?</h3><p class="muted">Уйдёт из списка и расписания. Слоты сохранятся — при возврате снова появятся. Оплаты останутся в финансах.</p><p class="muted" style="margin-top:10px;">Введите имя: <b><?= h($fio) ?></b></p><form class="form" data-no-submit="1"><input type="text" id="delete_confirm_answer" class="input" autocomplete="off"><div class="actions"><button type="button" class="btn gray sm modal-close">Отмена</button><button type="button" id="deleteStudentConfirmBtn" class="btn danger sm">Ушёл</button></div></form></div></div>
<div id="modalRestoreStudent" class="modal" hidden><div class="modal-card"><button class="modal-close" aria-label="Закрыть">✕</button><h3>Вернуть ученика?</h3><p class="muted">Снова появится в списке и расписании.</p><p class="muted" style="margin-top:10px;">Введите имя: <b><?= h($fio) ?></b></p><form class="form" data-no-submit="1"><input type="text" id="restore_confirm_answer" class="input" autocomplete="off"><div class="actions"><button type="button" class="btn gray sm modal-close">Отмена</button><button type="button" id="restoreStudentConfirmBtn" class="btn sm">Вернуть</button></div></form></div></div>
<div id="modalParentReport" class="modal" hidden>
  <div class="modal-card parent-report-modal">
    <button class="modal-close" aria-label="Закрыть">✕</button>
    <h3>Отчёт родителю</h3>
    <p class="muted">Карточка для WhatsApp или сохранения.</p>
    <img id="parentReportImg" class="parent-report-img" alt="Отчёт по урокам" hidden>
    <div class="actions">
      <button type="button" class="btn sm" id="parentReportShare">Поделиться</button>
      <button type="button" class="btn gray sm" id="parentReportSave">Скачать</button>
    </div>
  </div>
</div>
<div id="modalUpdateSuccess" class="modal" <?= !$flash_updated ? 'hidden' : '' ?>>
    <div class="modal-card success" role="alert">
        <button class="modal-close" aria-label="Закрыть" style="color:#fff;">✕</button>
        <div class="toast-icon">✔</div>
        <h3 class="toast-title" style="margin:6px 0;">Данные изменены</h3>
        <p class="toast-text" style="opacity:.9;margin:0 0 6px 0;">Информация об ученике успешно обновлена.</p>
        <?php if ($flash_warn): ?><p class="notice warn" style="margin:8px 0 0;"><?= h($flash_warn) ?></p><?php endif; ?>
    </div>
</div>


<script <?= csp_nonce_attr() ?>>
(function(){
  document.querySelectorAll('form[data-no-submit]').forEach(f => f.addEventListener('submit', e => e.preventDefault()));
  const uid = <?=(int)$user_id?>;
  const csrf = <?=json_encode($csrfToken)?>;
  const money = <?=(float)$student['money']?>;
  const packLessons = <?= (int)current_pack_lessons() ?>;
  const payDates = new Set(<?= json_encode(array_values($pay_dates), JSON_UNESCAPED_UNICODE) ?>);
  const visitFilter = <?=json_encode($v)?>;
  const scheduleSlots = <?= json_encode(array_map(static function ($s) {
      return ['wd' => (int)$s['weekday'], 'time' => hm($s['time'])];
  }, $schedule), JSON_UNESCAPED_UNICODE) ?>;
  const parentReport = <?= json_encode([
    'fio' => $fio,
    'klass' => trim((string)$student['klass']),
    'debtLessons' => $bal < 0 ? abs($bal) : 0,
    'debtAzn' => $bal < 0 ? debt_azn($bal, $unit_price) : 0,
    'remain' => max(0, $bal),
    'price' => (float)$student['money'],
    'periodFrom' => $period_from !== '' ? fmt_date($period_from) : '',
    'visits' => $period_visits,
    'missed' => $period_missed,
    'lastDate' => $last_pay ? fmt_date($last_pay['date']) : '',
    'lastLessons' => $last_pay ? (int)$last_pay['lessons'] : 0,
    'lastAzn' => $last_pay ? (float)$last_pay['amount'] : 0,
  ], JSON_UNESCAPED_UNICODE) ?>;
  
  const modals = {
    visit: document.getElementById('modalVisit'),
    pay: document.getElementById('modalPay'),
    confirm: document.getElementById('modalConfirm'),
    success: document.getElementById('modalSuccess'),
    notify: document.getElementById('modalNotify'),
    deleteStudent: document.getElementById('modalDeleteStudent'),
    restoreStudent: document.getElementById('modalRestoreStudent'),
    updateSuccess: document.getElementById('modalUpdateSuccess'),
    parentReport: document.getElementById('modalParentReport'),
  };

  function showModal(m){ if(m) m.removeAttribute('hidden'); }
  function hideModal(m){ if(m) m.setAttribute('hidden',''); }
  function todayISO(){ return new Date().toISOString().slice(0,10); }
  
  function showToast(type, title, text = '', duration = 1200, redirectUrl = null) {
    const modal = (type === 'success') ? modals.success : modals.notify;
    if (!modal) return;
    modal.querySelector('.toast-title').textContent = title;
    modal.querySelector('.toast-text').textContent = text;
    Object.values(modals).forEach(m => { if (m !== modal) hideModal(m); });
    showModal(modal);
    const timer = setTimeout(() => {
      if (redirectUrl) location.href = redirectUrl;
      else hideModal(modal);
    }, duration);
    modal.querySelector('.modal-close').onclick = () => {
      clearTimeout(timer);
      hideModal(modal);
    };
  }

  // General Modal Controls
  document.addEventListener('click', e => {
    if (e.target.closest('.modal-close')) e.target.closest('.modal')?.setAttribute('hidden','');
    if (e.target.matches('.modal')) e.target.setAttribute('hidden', '');
  });
  window.addEventListener('keydown', (e) => { if(e.key==='Escape') Object.values(modals).forEach(hideModal); });

  // Add Visit/Pay button handlers
  function slotsForDate(iso) {
    const d = new Date(iso + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return [];
    const wd = d.getDay() === 0 ? 7 : d.getDay();
    return scheduleSlots.filter(s => s.wd === wd);
  }
  function syncVisitSlots() {
    const iso = modals.visit.querySelector('#visit_date').value;
    const slots = slotsForDate(iso);
    const wrap = document.getElementById('visit_slot_wrap');
    const sel = document.getElementById('visit_time');
    if (!wrap || !sel) return;
    sel.innerHTML = '';
    if (slots.length >= 2) {
      slots.forEach(s => {
        const o = document.createElement('option');
        o.value = s.time;
        o.textContent = s.time;
        sel.appendChild(o);
      });
      wrap.hidden = false;
    } else {
      wrap.hidden = true;
    }
  }
  document.getElementById('btnAddVisit')?.addEventListener('click', () => {
    const inp = modals.visit.querySelector('#visit_date');
    inp.value = todayISO();
    inp.max = todayISO();
    modals.visit.querySelector('#visit_visited').checked = true;
    syncVisitSlots();
    showModal(modals.visit);
  });
  document.getElementById('visit_date')?.addEventListener('change', syncVisitSlots);
  function updatePayDupWarn() {
    const el = document.getElementById('pay_dup_warn');
    if (!el) return;
    el.hidden = !payDates.has(modals.pay.querySelector('#pay_date').value);
  }
  document.getElementById('btnAddPay')?.addEventListener('click', () => {
    const lessonsInput = modals.pay.querySelector('#pay_lessons');
    const amountInput = modals.pay.querySelector('#pay_amount');
    modals.pay.querySelector('#pay_date').value = todayISO();
    lessonsInput.value = packLessons;
    amountInput.value = (money * packLessons).toFixed(2);
    updatePayDupWarn();
    showModal(modals.pay);
  });
  document.getElementById('pay_date')?.addEventListener('input', updatePayDupWarn);
  document.getElementById('pay_date')?.addEventListener('change', updatePayDupWarn);
  document.getElementById('pay_lessons')?.addEventListener('input', e => {
    const lessons = parseInt(e.target.value || '0', 10);
    document.getElementById('pay_amount').value = (money * lessons).toFixed(2);
  });
  
  // Edit/Delete Student button handlers
  document.getElementById('btnDeleteStudent')?.addEventListener('click', () => showModal(modals.deleteStudent));
  document.querySelectorAll('.hist-tabs button').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.hist-tabs button').forEach(b => b.classList.toggle('is-active', b === btn));
      document.querySelectorAll('.hist-panel').forEach(p => { p.hidden = p.dataset.hist !== btn.dataset.hist; });
    });
  });
  
  // Add Visit submission
  document.getElementById('visitSubmit')?.addEventListener('click', async () => {
    const form = new FormData();
    form.append('csrf', csrf);
    form.append('dataa', modals.visit.querySelector('#visit_date').value);
    const slotWrap = document.getElementById('visit_slot_wrap');
    const slotSel = document.getElementById('visit_time');
    if (slotWrap && !slotWrap.hidden && slotSel?.value) form.append('time', slotSel.value);
    if (modals.visit.querySelector('#visit_visited').checked) form.append('visited', '1');
    try {
      form.append('user_id', uid);
      const resp = await fetch('/add/dates.php', { method:'POST', body:form });
      const j = await resp.json().catch(() => ({}));
      if (!resp.ok || !j.ok) {
        const map = { bad_date: 'Неверная или будущая дата', need_slot: 'Выберите время урока', save_failed: 'Не удалось сохранить' };
        throw new Error(map[j.error] || j.error || `HTTP ${resp.status}`);
      }
      hideModal(modals.visit);
      showToast('success', 'Посещение добавлено', '', 1200, location.href);
    } catch(e) { alert('Ошибка: ' + e.message); }
  });

  // Add Pay submission
  document.getElementById('paySubmit')?.addEventListener('click', async () => {
    const form = new FormData();
    form.append('csrf', csrf);
    form.append('user_id', uid);
    form.append('date', modals.pay.querySelector('#pay_date').value);
    form.append('lessons', modals.pay.querySelector('#pay_lessons').value);
    try {
      const resp = await fetch('/add/pays.php', { method:'POST', body:form, headers:{'X-Requested-With':'XMLHttpRequest'} });
      const j = await resp.json();
      if (!resp.ok || !j.ok) throw new Error(j.error || `HTTP ${resp.status}`);
      hideModal(modals.pay);
      showToast('success', 'Оплата добавлена', `Уроков: ${form.get('lessons')}`, 1200, location.href);
    } catch(e) { alert('Ошибка: ' + e.message); }
  });

  // Delete Student submission
  document.getElementById('deleteStudentConfirmBtn')?.addEventListener('click', async () => {
    const typed = modals.deleteStudent.querySelector('#delete_confirm_answer').value.trim();
    const expected = <?= json_encode($fio) ?>;
    if (typed.toLowerCase() !== String(expected).trim().toLowerCase()) return alert('Имя не совпадает.');
    const form = new FormData();
    form.append('action', 'delete_student');
    form.append('user_id', uid);
    form.append('csrf', csrf);
    form.append('csrf_check_answer', typed);
    try {
      await fetch(location.pathname, { method: 'POST', body: form });
      showToast('notify', 'Ученик ушёл', 'Оплаты сохранены в финансах.', 1500, '/profile/list.php?tab=left');
    } catch (e) { alert('Ошибка: ' + e.message); }
  });

  document.getElementById('btnRestoreStudent')?.addEventListener('click', () => showModal(modals.restoreStudent));
  document.getElementById('restoreStudentConfirmBtn')?.addEventListener('click', async () => {
    const typed = modals.restoreStudent.querySelector('#restore_confirm_answer').value.trim();
    const expected = <?= json_encode($fio) ?>;
    if (typed.toLowerCase() !== String(expected).trim().toLowerCase()) return alert('Имя не совпадает.');
    const form = new FormData();
    form.append('action', 'restore_student');
    form.append('user_id', uid);
    form.append('csrf', csrf);
    form.append('csrf_check_answer', typed);
    try {
      const resp = await fetch(location.pathname, { method: 'POST', body: form });
      const j = await resp.json();
      if (!resp.ok || !j.ok) throw new Error(j.error || `HTTP ${resp.status}`);
      showToast('success', 'Ученик возвращён', '', 1200, location.href);
    } catch (e) { alert('Ошибка: ' + e.message); }
  });

  // Delete Visit/Pay (Confirmation logic)
  let pendingDelete = null;
  document.addEventListener('click', e => {
    const delBtn = e.target.closest('.js-del-visit, .js-del-pay');
    if (!delBtn) return;
    pendingDelete = {
      type: delBtn.classList.contains('js-del-visit') ? 'visit' : 'pay',
      id: delBtn.dataset.id,
      element: delBtn.closest('.hist-row')
    };
    modals.confirm.querySelector('#confirmTitle').textContent = `Удалить ${pendingDelete.type === 'visit' ? 'посещение' : 'оплату'}?`;
    modals.confirm.querySelector('#confirmText').textContent = `Запись от ${delBtn.dataset.date} будет удалена.`;
    showModal(modals.confirm);
  });

  document.getElementById('confirmYes')?.addEventListener('click', async () => {
    if (!pendingDelete) return;
    const form = new FormData();
    form.append('action', `delete_${pendingDelete.type}`);
    form.append('id', pendingDelete.id);
    form.append('user_id', uid);
    form.append('csrf', csrf);
    try {
      const resp = await fetch(location.pathname, { method: 'POST', body: form });
      const j = await resp.json();
      if (!j.ok) throw new Error('Server error');
      pendingDelete.element.remove();
      if (j.html) {
        const box = document.querySelector('.student-stats');
        if (box) box.innerHTML = j.html;
      }
      if (j.parent) Object.assign(parentReport, j.parent);
      hideModal(modals.confirm);
      showToast('notify', 'Запись удалена');
    } catch(e) { alert('Ошибка удаления: ' + e.message); }
  });

  // Pagination
  async function loadMore(type) {
      const btn = document.getElementById(`load-more-${type}`);
      if (!btn) return;
      const offset = parseInt(btn.dataset.offset, 10);
      btn.disabled = true; btn.textContent = 'Загрузка...';
      const form = new FormData();
      form.append('action', `load_more_${type}`);
      form.append('v', visitFilter);
      form.append('offset', offset);
      form.append('user_id', uid);
      form.append('csrf', csrf);
      try {
          const resp = await fetch(location.pathname, { method: 'POST', body: form });
          const j = await resp.json();
          const list = document.getElementById(`${type === 'visits' ? 'visits' : 'pays'}-list`);
          list?.querySelector('.muted')?.remove();
          list?.insertAdjacentHTML('beforeend', j.html);
          if (j.count < 15) btn.parentElement.remove();
          else btn.dataset.offset = offset + j.count;
      } catch (e) { btn.textContent = 'Ошибка';
      } finally { btn.disabled = false; btn.textContent = 'Загрузить еще'; }
  }
  document.getElementById('load-more-visits')?.addEventListener('click', () => loadMore('visits'));
  document.getElementById('load-more-pays')?.addEventListener('click', () => loadMore('pays'));

  function azn(n){ return Number(n).toFixed(2); }
  function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }
  function drawParentCard(data) {
    const font = (w, s) => w + ' ' + s + 'px system-ui, -apple-system, Segoe UI, sans-serif';
    const prepaid = data.debtLessons <= 0;
    const accent = prepaid ? '#2fbf71' : '#f0a83c';
    const packTotal = data.visits + data.remain;
    const packUsed = data.visits;
    const showBar = prepaid && packTotal > 0;

    const W = 1080, pad = 72;
    const heroY = data.klass ? 336 : 300;
    const heroH = showBar ? 268 : 254;
    const row1Y = heroY + heroH + 86;
    const row2Y = row1Y + 88;
    const payY = row2Y + 58;
    const payH = 200;
    const H = payY + payH + 76;

    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const ctx = c.getContext('2d');
    const g = ctx.createLinearGradient(0, 0, W * 0.3, H);
    g.addColorStop(0, '#0c2238');
    g.addColorStop(1, '#16324f');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);
    ctx.fillStyle = accent;
    ctx.fillRect(0, 0, 14, H);

    const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    const now = new Date();

    ctx.letterSpacing = '3px';
    ctx.fillStyle = accent;
    ctx.font = font(700, 26);
    ctx.fillText('ОТЧЁТ ДЛЯ РОДИТЕЛЯ', pad, 88);
    ctx.letterSpacing = '0px';
    ctx.fillStyle = '#8aa4b8';
    ctx.font = font(500, 26);
    ctx.fillText(now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear(), pad, 130);

    ctx.fillStyle = '#fff';
    ctx.font = font(750, 64);
    let name = data.fio || '';
    while (ctx.measureText(name).width > W - 2 * pad && name.length > 4) name = name.slice(0, -2);
    if (name !== (data.fio || '')) name += '…';
    ctx.fillText(name, pad, 238);
    if (data.klass) {
      ctx.fillStyle = '#bcccdc';
      ctx.font = font(600, 30);
      ctx.fillText(data.klass + ' класс', pad, 286);
    }

    roundRect(ctx, pad, heroY, W - 2 * pad, heroH, 28);
    ctx.fillStyle = prepaid ? 'rgba(47,191,113,.14)' : 'rgba(240,168,60,.14)';
    ctx.fill();
    ctx.strokeStyle = prepaid ? 'rgba(47,191,113,.45)' : 'rgba(240,168,60,.45)';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.letterSpacing = '2px';
    ctx.fillStyle = accent;
    ctx.font = font(700, 26);
    ctx.fillText(prepaid ? 'ОПЛАЧЕНО ВПЕРЁД' : 'К ОПЛАТЕ', pad + 36, heroY + 60);
    ctx.letterSpacing = '0px';

    ctx.fillStyle = '#fff';
    ctx.font = font(780, 78);
    const heroValue = prepaid ? (data.remain + ' ур.') : (azn(data.debtAzn) + ' AZN');
    ctx.fillText(heroValue, pad + 36, heroY + 152);

    ctx.fillStyle = '#bcccdc';
    ctx.font = font(500, 26);
    const heroSub = prepaid
      ? 'оплаченных уроков осталось'
      : (data.debtLessons + ' ур. × ' + azn(data.price) + ' AZN');
    ctx.fillText(heroSub, pad + 36, heroY + 198);

    if (showBar) {
      const barX = pad + 36, barW = W - 2 * pad - 72, barY = heroY + heroH - 48, barH = 16;
      roundRect(ctx, barX, barY, barW, barH, 8);
      ctx.fillStyle = 'rgba(255,255,255,.14)';
      ctx.fill();
      const done = Math.min(1, packUsed / packTotal);
      if (done > 0) {
        roundRect(ctx, barX, barY, Math.max(barH, barW * done), barH, 8);
        ctx.fillStyle = accent;
        ctx.fill();
      }
    }

    [['Проведено уроков', String(data.visits), row1Y], ['Пропуски', String(data.missed), row2Y]].forEach(([label, value, y], i) => {
      if (i) {
        ctx.strokeStyle = 'rgba(255,255,255,.09)';
        ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(pad, y - 40); ctx.lineTo(W - pad, y - 40); ctx.stroke();
      }
      ctx.fillStyle = '#bcccdc';
      ctx.font = font(600, 30);
      ctx.fillText(label, pad, y);
      ctx.fillStyle = '#fff';
      ctx.font = font(750, 38);
      ctx.textAlign = 'right';
      ctx.fillText(value, W - pad, y);
      ctx.textAlign = 'left';
    });

    roundRect(ctx, pad, payY, W - 2 * pad, payH, 24);
    ctx.fillStyle = 'rgba(255,255,255,.06)';
    ctx.fill();
    ctx.letterSpacing = '2px';
    ctx.fillStyle = '#8aa4b8';
    ctx.font = font(700, 24);
    ctx.fillText('ПОСЛЕДНЯЯ ОПЛАТА', pad + 36, payY + 56);
    ctx.letterSpacing = '0px';
    if (data.lastDate) {
      ctx.fillStyle = '#fff';
      ctx.font = font(750, 42);
      ctx.fillText(data.lastDate, pad + 36, payY + 120);
      ctx.fillStyle = '#bcccdc';
      ctx.font = font(600, 28);
      ctx.fillText(data.lastLessons + ' ур. · ' + azn(data.lastAzn) + ' AZN', pad + 36, payY + 166);
    } else {
      ctx.fillStyle = '#bcccdc';
      ctx.font = font(600, 36);
      ctx.fillText('Оплат пока нет', pad + 36, payY + 132);
    }

    return c;
  }

  let parentBlob = null;
  function parentFileName() {
    const slug = (parentReport.fio || 'uchenik').replace(/\s+/g, '-').toLowerCase();
    return 'otchet-' + slug + '.png';
  }
  function canvasToBlob(canvas) {
    return new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
  }
  document.getElementById('btnParentReport')?.addEventListener('click', async () => {
    showModal(modals.parentReport);
    const canvas = drawParentCard(parentReport);
    parentBlob = await canvasToBlob(canvas);
    const img = document.getElementById('parentReportImg');
    if (img && parentBlob) {
      img.src = URL.createObjectURL(parentBlob);
      img.hidden = false;
    }
  });
  document.getElementById('parentReportSave')?.addEventListener('click', () => {
    if (!parentBlob) return;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(parentBlob);
    a.download = parentFileName();
    a.click();
  });
  document.getElementById('parentReportShare')?.addEventListener('click', async () => {
    if (!parentBlob) return;
    const file = new File([parentBlob], parentFileName(), { type: 'image/png' });
    if (navigator.canShare && navigator.canShare({ files: [file] })) {
      try { await navigator.share({ files: [file], title: 'Отчёт по урокам' }); } catch (e) {}
      return;
    }
    document.getElementById('parentReportSave')?.click();
  });

  // Logic for the update success modal
  (function() {
    const modal = modals.updateSuccess;
    if (modal && !modal.hasAttribute('hidden')) {
        const hide = () => hideModal(modal);
        setTimeout(hide, 2500); // Auto-hide after 2.5 seconds
        modal.querySelector('.modal-close').addEventListener('click', hide);
    }
  })();
})();
</script>
</body>
</html>
