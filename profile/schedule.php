<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/groups.php';

apply_app_timezone($con);

$tid = teacher_id();
$st = $con->prepare("
    SELECT sc.weekday, sc.time, sc.time_end,
           s.user_id, s.name, TRIM(CONCAT(s.lastname, ' ', s.name)) AS fio
    FROM schedule sc
    JOIN stud s ON s.user_id = sc.user_id
    WHERE s.teacher_id=? AND s.archived=0
    ORDER BY sc.weekday, sc.time
");
$st->bind_param('i', $tid);
$st->execute();
$all = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$flash_warn = $_SESSION['flash_warn'] ?? '';
unset($_SESSION['flash_warn']);

$roster = [];
$rst = $con->prepare("
    SELECT s.user_id, s.name, s.lastname, TRIM(CONCAT(s.lastname, ' ', s.name)) AS fio,
           COALESCE(NULLIF(s.klass,''),'') AS klass, s.pay_mode,
           " . student_balance_expr() . " AS balance_lessons
    FROM stud s
    " . student_balance_joins() . "
    WHERE s.teacher_id=? AND s.archived=0
    ORDER BY s.lastname, s.name
");
$rst->bind_param('i', $tid);
$rst->execute();
$alerts = [];
foreach ($rst->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $uid = (int)$row['user_id'];
    $kind = student_alert_kind((int)$row['balance_lessons'], student_pay_mode($row['pay_mode'] ?? 'prepaid'));
    $alerts[$uid] = $kind;
    $roster[$uid] = [
        'id' => $uid,
        'fio' => trim((string)$row['fio']),
        'name' => (string)$row['name'],
        'klass' => (string)$row['klass'],
        'alert' => $kind,
        'slots' => [],
    ];
}
$rst->close();
$sst = $con->prepare("
    SELECT sc.user_id, sc.weekday, sc.time, sc.time_end
    FROM schedule sc
    JOIN stud s ON s.user_id = sc.user_id
    WHERE s.teacher_id=? AND s.archived=0
");
$sst->bind_param('i', $tid);
$sst->execute();
foreach ($sst->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $uid = (int)$row['user_id'];
    if (!isset($roster[$uid])) continue;
    $start = hm($row['time']);
    $roster[$uid]['slots'][] = [
        'wd' => (int)$row['weekday'],
        'start' => $start,
        'end' => slot_end($row['time_end'] ?? '', $start),
    ];
}
$sst->close();
$roster = array_values($roster);

$schedule_grouped = teacher_week_slots($con, $tid);

$weekdays_map = [
    1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг',
    5 => 'Пятница', 6 => 'Суббота', 7 => 'Воскресенье'
];
$weekdays_short = [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'];
$months_short = [1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'май',6=>'июн',7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'];
$week_dates = [];
$monday = new DateTime('monday this week');
for ($i = 1; $i <= 7; $i++) {
    $d = (clone $monday)->modify('+' . ($i - 1) . ' days');
    $week_dates[$i] = (int)$d->format('j') . ' ' . $months_short[(int)$d->format('n')];
}
$group_size = group_size($con);
$today_wd = (int)date('N');
$sel = (int)($_GET['d'] ?? $today_wd);
if ($sel < 1 || $sel > 7) $sel = $today_wd;
$now_min = ((int)date('G') * 60) + (int)date('i');
$time_opts = schedule_time_options();
$active = 'schedule';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Расписание — Tutor CRM</title>
    <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content content-wide">
    <h1>Расписание</h1>
    <?php if ($flash_warn): ?><p class="notice warn"><?= h($flash_warn) ?></p><?php endif; ?>

    <div class="day-chips" id="dayChips">
        <?php foreach ($weekdays_short as $n => $short):
            $day = $schedule_grouped[$n] ?? [];
            $cls = 'day-chip';
            if ($n === $sel) $cls .= ' is-active';
            if ($n === $today_wd) $cls .= ' is-today';
            if (!$day) $cls .= ' is-empty';
        ?>
            <button type="button" class="<?= $cls ?>" data-day="<?= $n ?>">
                <span><?= $short ?></span>
                <b><?= h($week_dates[$n]) ?></b>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($weekdays_map as $wd_num => $wd_name):
        $day_schedule = $schedule_grouped[$wd_num] ?? [];
        [$students, $sessions] = day_counts($day_schedule);
        $times = array_column($day_schedule, 'start');
        $sum = $students ? ru_students($students) : 'никого нет';
        if ($sessions) $sum .= ' · '.ru_sessions($sessions);
    ?>
    <div class="day-panel" data-day="<?= $wd_num ?>" <?= $wd_num !== $sel ? 'hidden' : '' ?>>
        <div class="day-summary">
            <strong id="dayTitle-<?= $wd_num ?>"><?= h($wd_name) ?></strong>
            <span><?= h($sum) ?></span>
        </div>
        <?php if (!$day_schedule): ?>
            <div class="slot-list">
                <button type="button" class="slot is-create-block js-create-block" data-day="<?= $wd_num ?>">+ Создать блок</button>
            </div>
        <?php else: ?>
            <div class="slot-list">
                <?php foreach ($day_schedule as $slot):
                    [$late_head, $students, $late_tail] = slot_view_parts($slot);
                    $status = slot_status($wd_num, $today_wd, $now_min, $slot['start'], $slot['end'], $times);
                    $slot_cls = 'slot'.($status === 'сейчас' ? ' is-now' : '').($status && $status !== 'сейчас' ? ' is-next' : '').(!empty($slot['prelude']) ? ' is-prelude' : '');
                ?>
                <section class="<?= $slot_cls ?>">
                    <div class="slot-head">
                        <time><?= h($slot['start']) ?><span class="slot-end">–<?= h($slot['end']) ?></span></time>
                        <?php if ($status): ?><span class="slot-tag"><?= h($status) ?></span><?php endif; ?>
                        <?php if (empty($slot['prelude'])): ?>
                        <span class="slot-block-ops">
                            <button type="button" class="slot-block-edit js-edit-block" data-id="<?= (int)($slot['id'] ?? 0) ?>" data-day="<?= $wd_num ?>" data-start="<?= h($slot['start']) ?>" data-end="<?= h($slot['end']) ?>" aria-label="Изменить" title="Изменить"></button>
                            <button type="button" class="slot-block-del js-del-block" data-id="<?= (int)($slot['id'] ?? 0) ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($slot['start']) ?>" data-end="<?= h($slot['end']) ?>" data-n="<?= session_taken($slot) ?>" aria-label="Удалить" title="Удалить"></button>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($late_head as $student): ?>
                            <div class="person-row is-partial">
                                <a class="person-row-main" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                    <div>
                                        <div class="name">
                                            <i class="partial-ico" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"></i>
                                            <span class="name-text"><?= h($student['fio']) ?></span>
                                            <?= pay_alert_html((int)$student['user_id'], $alerts[(int)$student['user_id']] ?? '') ?>
                                        </div>
                                        <span class="partial-time"><?= h(person_show_range($student)) ?></span>
                                    </div>
                                </a>
                                <button type="button" class="slot-x js-unassign" data-id="<?= (int)$student['user_id'] ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($student['start']) ?>" data-name="<?= h($student['fio']) ?>" aria-label="Убрать">×</button>
                            </div>
                    <?php endforeach; ?>
                    <?php foreach ($students as $student): ?>
                        <div class="person-row">
                            <a class="person-row-main" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                <div>
                                    <div class="name"><?= h($student['fio']) ?><?= pay_alert_html((int)$student['user_id'], $alerts[(int)$student['user_id']] ?? '') ?></div>
                                    <?php if (($student['show_end'] ?? $student['end']) !== $slot['end']): ?>
                                        <div class="sub"><?= h(person_show_range($student)) ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <button type="button" class="slot-x js-unassign" data-id="<?= (int)$student['user_id'] ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($student['start']) ?>" data-name="<?= h($student['fio']) ?>" aria-label="Убрать">×</button>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($late_tail): ?>
                        <?php foreach ($late_tail as $student): ?>
                            <div class="person-row is-partial">
                                <a class="person-row-main" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>">
                                    <div>
                                        <div class="name">
                                            <i class="partial-ico" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"></i>
                                            <span class="name-text"><?= h($student['fio']) ?></span>
                                            <?= pay_alert_html((int)$student['user_id'], $alerts[(int)$student['user_id']] ?? '') ?>
                                        </div>
                                        <span class="partial-time"><?= h(person_show_range($student)) ?></span>
                                    </div>
                                </a>
                                <button type="button" class="slot-x js-unassign" data-id="<?= (int)$student['user_id'] ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($student['start']) ?>" data-name="<?= h($student['fio']) ?>" aria-label="Убрать">×</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (empty($slot['prelude'])): ?>
                    <?php for ($i = 0, $free = group_free_seats(session_taken($slot), $group_size); $i < $free; $i++): ?>
                        <button type="button" class="person-row is-vacant js-vacant" data-wd="<?= $wd_num ?>" data-block="<?= (int)($slot['id'] ?? 0) ?>" data-start="<?= h($slot['start']) ?>" data-end="<?= h($slot['end']) ?>">
                            <span class="vacant-plus" aria-hidden="true">+</span>
                            Свободно
                        </button>
                    <?php endfor; ?>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>
                <button type="button" class="slot-add-block js-create-block" data-day="<?= $wd_num ?>">+ Ещё блок</button>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="week-grid">
        <?php foreach ($weekdays_map as $wd_num => $wd_name):
            $day_schedule = $schedule_grouped[$wd_num] ?? [];
            $times = array_column($day_schedule, 'start');
        ?>
        <?php [$col_students] = day_counts($day_schedule); ?>
        <div class="week-col<?= $wd_num === $today_wd ? ' is-today' : '' ?><?= $wd_num === $sel ? ' is-active' : '' ?><?= !$day_schedule ? ' is-empty' : '' ?>" data-day="<?= $wd_num ?>">
            <button type="button" class="week-col-head js-day" data-day="<?= $wd_num ?>">
                <span><?= h($weekdays_short[$wd_num]) ?></span>
                <?php if ($col_students): ?><b><?= $col_students ?></b><?php endif; ?>
            </button>
            <?php if (!$day_schedule): ?>
                <button type="button" class="slot-add-block js-create-block" data-day="<?= $wd_num ?>">+ Создать блок</button>
            <?php else: foreach ($day_schedule as $slot):
                [$late_head, $students, $late_tail] = slot_view_parts($slot);
                $status = slot_status($wd_num, $today_wd, $now_min, $slot['start'], $slot['end'], $times);
            ?>
                <div class="week-slot<?= $status === 'сейчас' ? ' is-now' : '' ?><?= $status && $status !== 'сейчас' ? ' is-next' : '' ?>">
                    <div class="slot-head">
                        <time><?= h($slot['start']) ?><span class="slot-end">–<?= h($slot['end']) ?></span></time>
                        <?php if ($status): ?><span class="slot-tag"><?= h($status) ?></span><?php endif; ?>
                        <?php if (empty($slot['prelude'])): ?>
                        <span class="slot-block-ops">
                            <button type="button" class="slot-block-edit js-edit-block" data-id="<?= (int)($slot['id'] ?? 0) ?>" data-day="<?= $wd_num ?>" data-start="<?= h($slot['start']) ?>" data-end="<?= h($slot['end']) ?>" aria-label="Изменить" title="Изменить"></button>
                            <button type="button" class="slot-block-del js-del-block" data-id="<?= (int)($slot['id'] ?? 0) ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($slot['start']) ?>" data-end="<?= h($slot['end']) ?>" data-n="<?= session_taken($slot) ?>" aria-label="Удалить" title="Удалить"></button>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="week-people">
                    <?php foreach ($late_head as $student): ?>
                        <div class="week-person">
                            <a class="is-partial" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"><i class="partial-ico"></i><?= h($student['name'] ?: $student['fio']) ?><?= pay_alert_html((int)$student['user_id'], $alerts[(int)$student['user_id']] ?? '') ?><span><?= h(person_show_range($student)) ?></span></a>
                            <button type="button" class="slot-x js-unassign" data-id="<?= (int)$student['user_id'] ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($student['start']) ?>" data-name="<?= h($student['fio']) ?>" aria-label="Убрать">×</button>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($students as $student): ?>
                        <div class="week-person">
                            <a href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>"><?= h($student['name'] ?: $student['fio']) ?><?= pay_alert_html((int)$student['user_id'], $alerts[(int)$student['user_id']] ?? '') ?></a>
                            <button type="button" class="slot-x js-unassign" data-id="<?= (int)$student['user_id'] ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($student['start']) ?>" data-name="<?= h($student['fio']) ?>" aria-label="Убрать">×</button>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($late_tail as $student): ?>
                        <div class="week-person">
                            <a class="is-partial" href="/profile/student.php?user_id=<?= (int)$student['user_id'] ?>" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"><i class="partial-ico"></i><?= h($student['name'] ?: $student['fio']) ?><?= pay_alert_html((int)$student['user_id'], $alerts[(int)$student['user_id']] ?? '') ?><span><?= h(person_show_range($student)) ?></span></a>
                            <button type="button" class="slot-x js-unassign" data-id="<?= (int)$student['user_id'] ?>" data-wd="<?= $wd_num ?>" data-time="<?= h($student['start']) ?>" data-name="<?= h($student['fio']) ?>" aria-label="Убрать">×</button>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($slot['prelude'])): ?>
                    <?php for ($i = 0, $free = group_free_seats(session_taken($slot), $group_size); $i < $free; $i++): ?>
                        <button type="button" class="is-vacant js-vacant" data-wd="<?= $wd_num ?>" data-block="<?= (int)($slot['id'] ?? 0) ?>" data-start="<?= h($slot['start']) ?>" data-end="<?= h($slot['end']) ?>">+ Свободно</button>
                    <?php endfor; ?>
                    <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
                <button type="button" class="slot-add-block js-create-block" data-day="<?= $wd_num ?>">+ Ещё блок</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="slotModal" class="modal" hidden>
  <div class="modal-card slot-sheet" role="dialog" aria-labelledby="slotSheetTitle">
    <button type="button" class="modal-close" id="slotClose" aria-label="Закрыть">✕</button>
    <p class="slot-sheet-kicker">Свободное место</p>
    <h3 id="slotSheetTitle">Слот</h3>
    <label class="partial-switch">
      <span class="partial-switch-label"><i class="partial-ico"></i> Промежуточный</span>
      <input type="checkbox" id="slotPartial">
    </label>
    <div class="grid-2 slot-partial-times" id="slotArrivalWrap" hidden>
      <div class="form-group">
        <label for="slotArrival">С</label>
        <select id="slotArrival" class="input">
          <?php foreach ($time_opts as $t): ?>
          <option value="<?= h($t) ?>"><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="slotUntil">До</label>
        <select id="slotUntil" class="input">
          <?php foreach ($time_opts as $t): ?>
          <option value="<?= h($t) ?>"><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="seg-control" id="slotSeg">
      <label><input type="radio" name="slotMode" value="pick" checked> Из списка</label>
      <label><input type="radio" name="slotMode" value="new"> Новый</label>
    </div>
    <div id="slotPick" class="slot-pick">
      <input type="search" class="input slot-pick-search" id="slotSearch" placeholder="Имя или фамилия" autocomplete="off">
      <div id="slotList" class="slot-pick-list"></div>
      <div class="slot-pick-foot">
        <button type="button" class="btn" id="slotAssign" disabled>Добавить</button>
      </div>
    </div>
    <form id="slotNew" class="form" hidden>
      <div class="grid-2">
        <div class="form-group">
          <label for="slotName">Имя</label>
          <input class="input" type="text" id="slotName" name="name" required autocomplete="off">
        </div>
        <div class="form-group">
          <label for="slotLast">Фамилия</label>
          <input class="input" type="text" id="slotLast" name="lastname" autocomplete="off">
        </div>
      </div>
      <div class="form-group" style="margin-top:12px">
        <label for="slotKlass">Класс</label>
        <select id="slotKlass" name="klass" class="input select-big">
          <option value="">— не выбрано —</option>
          <?php foreach (range(5, 11) as $k): ?>
          <option value="<?= $k ?>"><?= $k ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin-top:12px">
        <label for="slotMoney">Цена урока (AZN)</label>
        <input class="input" type="number" id="slotMoney" name="money" inputmode="decimal" step="0.01" min="0.01" value="<?= h(fmt_price(default_lesson_price($con))) ?>">
        <?= price_preset_buttons($con) ?>
      </div>
      <div class="form-group" style="margin-top:12px">
        <label for="slotPay">Как платит</label>
        <select id="slotPay" name="pay_mode" class="input select-big">
          <option value="prepaid" selected>Предоплата</option>
          <option value="postpaid">В конце месяца</option>
        </select>
      </div>
      <button type="submit" class="btn" id="slotCreate" style="margin-top:14px;width:100%">Добавить в слот</button>
    </form>
    <p id="slotErr" class="notice err" hidden></p>
  </div>
</div>
<div id="blockModal" class="modal" hidden>
  <div class="modal-card confirm-card">
    <div class="confirm-icon brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 1.8"/></svg>
    </div>
    <h3 id="blockTitle">Новый блок</h3>
    <div class="grid-2">
      <div class="form-group">
        <label for="blockStart">Начало</label>
        <select id="blockStart" class="input">
          <?php foreach ($time_opts as $t): ?>
          <option value="<?= h($t) ?>" <?= $t === '14:00' ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="blockEnd">Конец</label>
        <select id="blockEnd" class="input">
          <?php foreach ($time_opts as $t): ?>
          <option value="<?= h($t) ?>" <?= $t === '15:30' ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p id="blockErr" class="notice err" hidden></p>
    <div class="modal-actions">
      <button type="button" class="btn gray" id="blockNo">Отмена</button>
      <button type="button" class="btn" id="blockYes">Создать</button>
    </div>
  </div>
</div>
<div id="partialModal" class="modal" hidden>
  <div class="modal-card confirm-card">
    <h3>Промежуточный</h3>
    <p class="confirm-meta" id="partialText"></p>
    <div class="form-group">
      <label for="partialArrival">Приходит в</label>
      <select id="partialArrival" class="input">
        <?php foreach ($time_opts as $t): ?>
        <option value="<?= h($t) ?>"><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="muted">Отдельный блок до начала группы, в группе — с часами.</p>
    <div class="modal-actions">
      <button type="button" class="btn gray" id="partialNo">Отмена</button>
      <button type="button" class="btn" id="partialYes">Отметить</button>
    </div>
  </div>
</div>
<div id="delBlockModal" class="modal" hidden>
  <div class="modal-card confirm-card">
    <div class="confirm-icon danger">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
    </div>
    <h3 id="delBlockTitle">Удалить блок?</h3>
    <p class="confirm-meta" id="delBlockText"></p>
    <div class="modal-actions">
      <button type="button" class="btn gray" id="delBlockNo">Отмена</button>
      <button type="button" class="btn danger" id="delBlockYes">Удалить</button>
    </div>
  </div>
</div>
<div id="unassignModal" class="modal" hidden>
  <div class="modal-card confirm-card">
    <div class="confirm-icon danger">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M10 14l4 4M14 14l-4 4"/></svg>
    </div>
    <h3 id="unassignTitle">Убрать из расписания?</h3>
    <p class="confirm-meta" id="unassignText"></p>
    <div class="modal-actions">
      <button type="button" class="btn gray" id="unassignNo">Отмена</button>
      <button type="button" class="btn danger" id="unassignYes">Убрать</button>
    </div>
  </div>
</div>

<script <?= csp_nonce_attr() ?>>
(function(){
  let day = <?= $sel ?>;
  const chips = document.querySelectorAll('.day-chip, .js-day');
  function show(d){
    day = ((d - 1 + 7) % 7) + 1;
    document.querySelectorAll('.day-panel').forEach(p => { p.hidden = Number(p.dataset.day) !== day; });
    document.querySelectorAll('.day-chip').forEach(c => c.classList.toggle('is-active', Number(c.dataset.day) === day));
    document.querySelectorAll('.week-col').forEach(c => c.classList.toggle('is-active', Number(c.dataset.day) === day));
    const url = new URL(location.href);
    url.searchParams.set('d', String(day));
    history.replaceState(null, '', url);
  }
  chips.forEach(c => c.addEventListener('click', () => show(Number(c.dataset.day))));
  let x0 = null;
  document.querySelector('.content').addEventListener('touchstart', e => { x0 = e.changedTouches[0].screenX; }, {passive:true});
  document.querySelector('.content').addEventListener('touchend', e => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].screenX - x0;
    if (dx > 50) show(day - 1);
    if (dx < -50) show(day + 1);
    x0 = null;
  }, {passive:true});

  const csrf = <?= json_encode(csrf_token()) ?>;
  const roster = <?= json_encode($roster, JSON_UNESCAPED_UNICODE) ?>;
  const wdShort = <?= json_encode($weekdays_short, JSON_UNESCAPED_UNICODE) ?>;
  const modal = document.getElementById('slotModal');
  const title = document.getElementById('slotSheetTitle');
  const search = document.getElementById('slotSearch');
  const list = document.getElementById('slotList');
  const pick = document.getElementById('slotPick');
  const form = document.getElementById('slotNew');
  const err = document.getElementById('slotErr');
  const money = document.getElementById('slotMoney');
  const assignBtn = document.getElementById('slotAssign');
  let slot = null;
  let picked = 0;
  let busy = false;

  function toMin(t){
    const p = String(t || '').split(':');
    return (+p[0] || 0) * 60 + (+p[1] || 0);
  }
  const lessonMin = <?= (int)lesson_duration($con) ?>;
  function plusDur(t){
    const tot = toMin(t) + lessonMin;
    return String(Math.floor(tot / 60)).padStart(2, '0') + ':' + String(tot % 60).padStart(2, '0');
  }
  function setBlockEnd(start){
    const end = document.getElementById('blockEnd');
    if (!end || !start) return;
    const next = plusDur(start);
    const opts = [...end.options];
    const hit = opts.find(o => o.value === next) || opts.filter(o => toMin(o.value) > toMin(start)).pop();
    if (hit) end.value = hit.value;
  }
  function overlaps(a0, a1, b0, b1){
    return toMin(a0) < toMin(b1) && toMin(b0) < toMin(a1);
  }
  function hide(){
    modal.setAttribute('hidden', '');
    document.body.classList.remove('noscroll');
    slot = null;
    picked = 0;
    if (assignBtn) assignBtn.disabled = true;
  }
  function syncAssign(){
    if (assignBtn) assignBtn.disabled = !picked || busy;
  }
  function showErr(msg){
    err.hidden = !msg;
    err.textContent = msg || '';
  }
  function setMode(mode){
    document.querySelectorAll('#slotSeg input').forEach(i => { i.checked = i.value === mode; });
    pick.hidden = mode !== 'pick';
    form.hidden = mode !== 'new';
    showErr('');
    if (mode === 'pick') search.focus();
    else document.getElementById('slotName').focus();
  }
  function studentState(s){
    if (!slot) return 'ok';
    for (const sl of s.slots) {
      if (sl.wd === slot.wd && sl.start === slot.start) return {kind:'here'};
      if (sl.wd === slot.wd && overlaps(slot.start, slot.end, sl.start, sl.end)) {
        return {kind:'busy', at: sl.start + '–' + sl.end};
      }
    }
    return {kind:'ok'};
  }
  function renderList(){
    const q = search.value.trim().toLowerCase();
    const rows = roster.filter(s => {
      if (!q) return true;
      return (s.fio + ' ' + s.name + ' ' + s.klass).toLowerCase().includes(q);
    });
    list.innerHTML = '';
    let shown = 0;
    rows.forEach(s => {
      const st = studentState(s);
      if (st.kind === 'here') return;
      shown++;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'slot-pick-item' + (st.kind === 'busy' ? ' is-busy' : '');
      btn.disabled = st.kind === 'busy' || busy;
      const sub = st.kind === 'busy'
        ? 'пересекается с ' + st.at
        : (s.klass ? s.klass + ' кл.' : '');
      btn.innerHTML = '<span class="name"></span>' + (sub ? '<span class="sub"></span>' : '');
      btn.querySelector('.name').textContent = s.fio || s.name;
      if (sub) btn.querySelector('.sub').textContent = sub;
      if (Number(picked) === Number(s.id)) btn.classList.add('is-on');
      if (st.kind === 'ok') btn.addEventListener('click', () => {
        picked = s.id;
        list.querySelectorAll('.slot-pick-item').forEach(el => el.classList.toggle('is-on', el === btn));
        syncAssign();
      });
      list.appendChild(btn);
    });
    if (!shown) {
      const empty = document.createElement('p');
      empty.className = 'slot-pick-empty';
      empty.textContent = roster.length ? 'Никого не нашлось' : 'Пока нет учеников — создайте нового';
      list.appendChild(empty);
    }
    syncAssign();
  }
  function openSheet(btn){
    slot = {wd: Number(btn.dataset.wd), start: btn.dataset.start, end: btn.dataset.end, block: Number(btn.dataset.block || 0)};
    const partialBox = document.getElementById('slotPartial');
    const arrivalWrap = document.getElementById('slotArrivalWrap');
    const arrival = document.getElementById('slotArrival');
    const until = document.getElementById('slotUntil');
    if (partialBox) partialBox.checked = false;
    if (arrivalWrap) arrivalWrap.hidden = true;
    if (arrival) arrival.value = btn.dataset.start;
    if (until) until.value = btn.dataset.end;
    title.textContent = (wdShort[slot.wd] || '') + ' · ' + slot.start + '–' + slot.end;
    search.value = '';
    form.reset();
    money.value = <?= json_encode(fmt_price(default_lesson_price($con))) ?>;
    document.querySelectorAll('#slotNew .price-preset').forEach(p => {
      p.classList.toggle('is-active', p.dataset.price === money.value);
    });
    showErr('');
    busy = false;
    picked = 0;
    modal.removeAttribute('hidden');
    document.body.classList.add('noscroll');
    setMode(roster.length ? 'pick' : 'new');
    renderList();
  }
  async function post(body){
    const fd = new FormData();
    fd.append('csrf', csrf);
    Object.entries(body).forEach(([k, v]) => fd.append(k, v));
    const resp = await fetch('/add/slot.php', {method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok || !data.ok) throw new Error(data.error || 'Не удалось сохранить');
    return data;
  }
  function vacantsOf(wd, start, end){
    return Array.from(document.querySelectorAll('.js-vacant')).filter(b =>
      Number(b.dataset.wd) === Number(wd) && b.dataset.start === start && b.dataset.end === end);
  }
  function rosterFind(id){ return roster.find(s => Number(s.id) === Number(id)); }
  function rosterAddSlot(id, fio, name, wd, start, end){
    let s = rosterFind(id);
    if (!s) {
      s = {id: Number(id), fio: fio || '', name: name || '', klass: '', slots: []};
      roster.push(s);
    }
    if (!s.slots.some(sl => sl.wd === Number(wd) && sl.start === start)) {
      s.slots.push({wd: Number(wd), start, end});
    }
  }
  function rosterRemoveSlot(id, wd, start){
    const s = rosterFind(id);
    if (s) s.slots = s.slots.filter(sl => !(sl.wd === Number(wd) && sl.start === start));
  }
  function studentOnDay(id, wd){
    const s = rosterFind(id);
    return !!(s && s.slots.some(sl => sl.wd === Number(wd)));
  }
  function bumpDay(wd, delta){
    const el = document.querySelector('.week-col[data-day="'+wd+'"] .week-col-head');
    if (!el) return;
    let b = el.querySelector('b');
    const n = Math.max(0, (b ? parseInt(b.textContent, 10) || 0 : 0) + delta);
    if (n <= 0) { b?.remove(); return; }
    if (!b) { b = document.createElement('b'); el.appendChild(b); }
    b.textContent = String(n);
  }
  function slotBtns(id, wd, start, end, fio){
    return '<button type="button" class="slot-x js-unassign" data-id="'+id+'" data-wd="'+wd+'" data-time="'+start+'" data-end="'+end+'" data-name="'+fio.replace(/"/g,'&quot;')+'" aria-label="Убрать">×</button>';
  }
  function payAlertHtml(id, kind){
    const on = kind === 'debt' || kind === 'warn';
    return '<span class="pay-alert'+(on?' is-'+kind:'')+'" data-pay-alert="'+id+'"'+(on?'':' hidden')+' aria-hidden="'+(on?'false':'true')+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16.2" r=".9" fill="currentColor" stroke="none"/></svg></span>';
  }
  function insertIntoSlot(wd, start, end, stu){
    const vacants = vacantsOf(wd, start, end);
    if (!vacants.length) return false;
    const seen = new Set();
    const wasOnDay = studentOnDay(stu.id, wd);
    vacants.forEach(v => {
      const parent = v.parentElement;
      if (!parent || seen.has(parent)) return;
      seen.add(parent);
      const wrap = document.createElement('div');
      const href = '/profile/student.php?user_id=' + encodeURIComponent(stu.id);
      if (parent.classList.contains('week-people') || parent.classList.contains('week-slot')) {
        wrap.className = 'week-person';
        wrap.innerHTML = '<a href="'+href+'"></a>' + slotBtns(stu.id, wd, start, end, stu.fio);
        wrap.querySelector('a').textContent = stu.name || stu.fio;
        wrap.querySelector('a').insertAdjacentHTML('beforeend', payAlertHtml(stu.id, stu.alert || ''));
      } else {
        wrap.className = 'person-row';
        wrap.innerHTML = '<a class="person-row-main" href="'+href+'"><div><div class="name"></div></div></a>' + slotBtns(stu.id, wd, start, end, stu.fio);
        wrap.querySelector('.name').textContent = stu.fio;
        wrap.querySelector('.name').insertAdjacentHTML('beforeend', payAlertHtml(stu.id, stu.alert || ''));
      }
      v.replaceWith(wrap);
    });
    rosterAddSlot(stu.id, stu.fio, stu.name, wd, start, end);
    if (!wasOnDay) bumpDay(wd, 1);
    return true;
  }
  function removeFromSlot(id, wd, start, end){
    const btns = Array.from(document.querySelectorAll('.js-unassign')).filter(b =>
      Number(b.dataset.id) === Number(id) && Number(b.dataset.wd) === Number(wd) && b.dataset.time === start);
    if (!btns.length) return false;
    const wasOnDay = studentOnDay(id, wd);
    btns.forEach(b => {
      const row = b.closest('.person-row, .week-person');
      const box = row?.parentElement;
      const slotStart = box?.querySelector('.js-vacant')?.dataset.start || start;
      const slotEnd = box?.querySelector('.js-vacant')?.dataset.end || end || '';
      const vac = document.createElement('button');
      vac.type = 'button';
      vac.className = row?.classList.contains('week-person') || box?.classList.contains('week-people')
        ? 'is-vacant js-vacant' : 'person-row is-vacant js-vacant';
      vac.dataset.wd = String(wd);
      vac.dataset.start = slotStart;
      vac.dataset.end = slotEnd;
      vac.textContent = row?.classList.contains('week-person') || box?.classList.contains('week-people') ? '+ Свободно' : 'Свободно';
      if (vac.classList.contains('person-row')) {
        vac.innerHTML = '<span class="vacant-plus" aria-hidden="true">+</span> Свободно';
      }
      if (row) row.replaceWith(vac);
    });
    rosterRemoveSlot(id, wd, start);
    if (wasOnDay && !studentOnDay(id, wd)) bumpDay(wd, -1);
    return true;
  }
  function afterSlotOk(text){
    modal.setAttribute('hidden', '');
    document.body.classList.remove('noscroll');
    hideUn();
    busy = false;
    const createBtn = document.getElementById('slotCreate');
    if (createBtn) createBtn.disabled = false;
    if (typeof window.showActionOk === 'function') window.showActionOk(text);
    location.reload();
  }
  function slotExtra(){
    const partialBox = document.getElementById('slotPartial');
    const arrival = document.getElementById('slotArrival');
    const until = document.getElementById('slotUntil');
    const extra = {};
    if (slot && slot.block) extra.block_id = slot.block;
    if (partialBox && partialBox.checked) {
      extra.partial = 1;
      extra.arrival = arrival ? arrival.value : '';
      extra.time_end = until ? until.value : '';
    }
    return extra;
  }
  async function assign(id){
    if (!slot || busy || !id) return;
    busy = true;
    syncAssign();
    const item = list.querySelector('.slot-pick-item.is-on');
    if (item) item.classList.add('is-saving');
    showErr('');
    try {
      const data = await post(Object.assign({action:'assign', user_id:id, weekday:slot.wd, time:slot.start, time_end:slot.end}, slotExtra()));
      afterSlotOk({
        title: 'Занятие добавлено',
        meta: data.fio || 'Ученик',
        date: (wdShort[slot.wd] || '') + ' ' + slot.start
      });
    } catch (e) {
      busy = false;
      if (item) item.classList.remove('is-saving');
      showErr(e.message);
      renderList();
    }
  }
  const unModal = document.getElementById('unassignModal');
  const unText = document.getElementById('unassignText');
  let pendingUn = null;
  function hideUn(){ unModal?.setAttribute('hidden',''); }
  document.addEventListener('click', e => {
    const btn = e.target.closest('.js-unassign');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    pendingUn = {id: btn.dataset.id, wd: btn.dataset.wd, time: btn.dataset.time, name: btn.dataset.name};
    if (unText) unText.textContent = (pendingUn.name || 'Ученик') + ' · ' + (wdShort[pendingUn.wd] || '') + ' ' + pendingUn.time;
    unModal?.removeAttribute('hidden');
  });
  document.getElementById('unassignNo')?.addEventListener('click', () => { hideUn(); pendingUn = null; });
  unModal?.addEventListener('click', e => { if (e.target === unModal) { hideUn(); pendingUn = null; } });
  document.getElementById('unassignYes')?.addEventListener('click', async () => {
    if (!pendingUn || busy) return;
    busy = true;
    try {
      const data = await post({action:'unassign', user_id:pendingUn.id, weekday:pendingUn.wd, time:pendingUn.time});
      const name = data.fio || pendingUn.name || 'ученика';
      afterSlotOk('Успешно убран из расписания ученик ' + name);
    } catch (e) {
      busy = false;
      hideUn();
      alert(e.message);
    }
  });
  document.querySelector('.content')?.addEventListener('click', e => {
    const b = e.target.closest('.js-vacant');
    if (b) openSheet(b);
  });
  document.getElementById('slotPartial')?.addEventListener('change', () => {
    const wrap = document.getElementById('slotArrivalWrap');
    const on = document.getElementById('slotPartial').checked;
    if (wrap) wrap.hidden = !on;
    if (on && slot) {
      const from = document.getElementById('slotArrival');
      const until = document.getElementById('slotUntil');
      if (from && !from.value) from.value = slot.start;
      if (until) until.value = slot.end;
    }
  });
  document.getElementById('slotArrival')?.addEventListener('change', () => {
    const until = document.getElementById('slotUntil');
    const next = plusDur(document.getElementById('slotArrival').value);
    if (until && [...until.options].some(o => o.value === next)) until.value = next;
  });
  let blockDay = day;
  let editBlockId = 0;
  const blockModal = document.getElementById('blockModal');
  function openBlockModal(opts){
    blockDay = Number(opts.day || day);
    editBlockId = Number(opts.id || 0);
    document.getElementById('blockTitle').textContent = editBlockId ? 'Изменить блок' : 'Новый блок';
    document.getElementById('blockYes').textContent = editBlockId ? 'Сохранить' : 'Создать';
    const start = opts.start || '14:00';
    document.getElementById('blockStart').value = start;
    if (opts.end) document.getElementById('blockEnd').value = opts.end;
    else setBlockEnd(start);
    document.getElementById('blockErr').hidden = true;
    blockModal?.removeAttribute('hidden');
  }
  document.querySelector('.content')?.addEventListener('click', e => {
    const edit = e.target.closest('.js-edit-block');
    if (edit) {
      openBlockModal({id: edit.dataset.id, day: edit.dataset.day, start: edit.dataset.start, end: edit.dataset.end});
      return;
    }
    const b = e.target.closest('.js-create-block');
    if (!b) return;
    openBlockModal({day: b.dataset.day || day});
  });
  document.getElementById('blockStart')?.addEventListener('change', () => setBlockEnd(document.getElementById('blockStart').value));
  document.getElementById('blockNo')?.addEventListener('click', () => blockModal?.setAttribute('hidden', ''));
  blockModal?.addEventListener('click', e => { if (e.target === blockModal) blockModal.setAttribute('hidden', ''); });
  document.getElementById('blockYes')?.addEventListener('click', async () => {
    const start = document.getElementById('blockStart').value;
    const end = document.getElementById('blockEnd').value;
    const errEl = document.getElementById('blockErr');
    if (toMin(end) <= toMin(start)) {
      errEl.hidden = false;
      errEl.textContent = 'Конец должен быть позже начала';
      return;
    }
    try {
      const body = {action: editBlockId ? 'update_block' : 'create_block', weekday: blockDay, time: start, time_end: end};
      if (editBlockId) body.block_id = editBlockId;
      await post(body);
      location.reload();
    } catch (e) {
      errEl.hidden = false;
      errEl.textContent = e.message;
    }
  });
  let pendingDelBlock = null;
  const delBlockModal = document.getElementById('delBlockModal');
  function ruStud(n){
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return n + ' ученик';
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return n + ' ученика';
    return n + ' учеников';
  }
  document.addEventListener('click', e => {
    const btn = e.target.closest('.js-del-block');
    if (!btn) return;
    e.preventDefault();
    pendingDelBlock = {id: btn.dataset.id, wd: btn.dataset.wd, time: btn.dataset.time, end: btn.dataset.end, n: Number(btn.dataset.n || 0)};
    const range = (pendingDelBlock.time || '') + '–' + (pendingDelBlock.end || '');
    document.getElementById('delBlockTitle').textContent = pendingDelBlock.n ? 'Удалить блок с учениками?' : 'Удалить блок?';
    document.getElementById('delBlockText').textContent = pendingDelBlock.n
      ? 'В блоке ' + range + ' ' + ruStud(pendingDelBlock.n) + '. Они будут убраны из расписания.'
      : 'Блок ' + range + ' пустой.';
    delBlockModal?.removeAttribute('hidden');
  });
  document.getElementById('delBlockNo')?.addEventListener('click', () => { delBlockModal?.setAttribute('hidden', ''); pendingDelBlock = null; });
  delBlockModal?.addEventListener('click', e => { if (e.target === delBlockModal) { delBlockModal.setAttribute('hidden', ''); pendingDelBlock = null; } });
  document.getElementById('delBlockYes')?.addEventListener('click', async () => {
    if (!pendingDelBlock) return;
    try {
      await post({action:'delete_block', block_id: pendingDelBlock.id, weekday: pendingDelBlock.wd, time: pendingDelBlock.time, time_end: pendingDelBlock.end});
      location.reload();
    } catch (e) {
      alert(e.message);
    }
  });
  let pendingPartial = null;
  const partialModal = document.getElementById('partialModal');
  document.addEventListener('click', e => {
    const btn = e.target.closest('.js-partial');
    if (!btn) return;
    e.preventDefault();
    pendingPartial = {id: btn.dataset.id, wd: btn.dataset.wd, time: btn.dataset.time, name: btn.dataset.name, blockStart: btn.dataset.blockStart};
    document.getElementById('partialText').textContent = (pendingPartial.name || 'Ученик') + ' · ' + (wdShort[pendingPartial.wd] || '') + ' ' + pendingPartial.time;
    const arr = document.getElementById('partialArrival');
    if (arr) arr.value = pendingPartial.time;
    partialModal?.removeAttribute('hidden');
  });
  document.getElementById('partialNo')?.addEventListener('click', () => { partialModal?.setAttribute('hidden', ''); pendingPartial = null; });
  partialModal?.addEventListener('click', e => { if (e.target === partialModal) { partialModal.setAttribute('hidden', ''); pendingPartial = null; } });
  document.getElementById('partialYes')?.addEventListener('click', async () => {
    if (!pendingPartial) return;
    try {
      await post({
        action:'set_partial',
        user_id: pendingPartial.id,
        weekday: pendingPartial.wd,
        time: pendingPartial.time,
        arrival: document.getElementById('partialArrival').value
      });
      location.reload();
    } catch (e) {
      alert(e.message);
    }
  });
  document.getElementById('slotClose').addEventListener('click', hide);
  modal.addEventListener('click', e => { if (e.target === modal) hide(); });
  window.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (unModal && !unModal.hasAttribute('hidden')) { hideUn(); pendingUn = null; }
    else if (!modal.hasAttribute('hidden')) hide();
  });
  document.querySelectorAll('#slotSeg input').forEach(i => i.addEventListener('change', () => setMode(i.value)));
  search.addEventListener('input', renderList);
  assignBtn?.addEventListener('click', () => assign(picked));
  document.querySelectorAll('#slotNew .price-preset').forEach(p => {
    p.addEventListener('click', () => {
      money.value = p.dataset.price;
      document.querySelectorAll('#slotNew .price-preset').forEach(x => x.classList.toggle('is-active', x === p));
    });
  });
  money.addEventListener('input', () => {
    document.querySelectorAll('#slotNew .price-preset').forEach(p => {
      p.classList.toggle('is-active', p.dataset.price === money.value);
    });
  });
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (!slot || busy) return;
    const name = document.getElementById('slotName').value.trim();
    if (!name) { showErr('Укажите имя'); return; }
    busy = true;
    document.getElementById('slotCreate').disabled = true;
    showErr('');
    try {
      const data = await post(Object.assign({
        action:'create',
        name,
        lastname: document.getElementById('slotLast').value.trim(),
        klass: document.getElementById('slotKlass').value,
        money: money.value,
        pay_mode: document.getElementById('slotPay').value,
        weekday: slot.wd,
        time: slot.start,
        time_end: slot.end
      }, slotExtra()));
      afterSlotOk({
        title: 'Новый ученик создан',
        meta: data.fio || name,
        date: (wdShort[slot.wd] || '') + ' ' + slot.start
      });
    } catch (err2) {
      busy = false;
      document.getElementById('slotCreate').disabled = false;
      showErr(err2.message);
    }
  });
})();
</script>
</body>
</html>
