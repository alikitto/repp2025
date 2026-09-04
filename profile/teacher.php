<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/util.php';
require_once __DIR__ . '/../common/groups.php';

require_admin();
apply_app_timezone($con);

$tid = (int)($_GET['id'] ?? 0);
$st = $con->prepare("SELECT id, login, name FROM users WHERE id=? AND role='teacher' LIMIT 1");
$st->bind_param('i', $tid);
$st->execute();
$teacher = $st->get_result()->fetch_assoc();
$st->close();
if (!$teacher) {
    http_response_code(404);
    exit('Учитель не найден');
}

$from = date('Y-m-01');
$to = date('Y-m-d', strtotime($from . ' +1 month'));
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

$st = $con->prepare("SELECT COUNT(*) AS n FROM stud WHERE teacher_id=? AND archived=0");
$st->bind_param('i', $tid);
$st->execute();
$students_n = (int)$st->get_result()->fetch_assoc()['n'];
$st->close();

$st = $con->prepare("SELECT COALESCE(SUM(p.amount),0) AS total FROM pays p JOIN stud s ON s.user_id=p.user_id WHERE s.teacher_id=? AND p.date>=? AND p.date<?");
$st->bind_param('iss', $tid, $from, $to);
$st->execute();
$profit_month = (float)$st->get_result()->fetch_assoc()['total'];
$st->close();

$st = $con->prepare("SELECT COALESCE(SUM(p.amount),0) AS total FROM pays p JOIN stud s ON s.user_id=p.user_id WHERE s.teacher_id=?");
$st->bind_param('i', $tid);
$st->execute();
$profit_all = (float)$st->get_result()->fetch_assoc()['total'];
$st->close();

$st = $con->prepare("
    SELECT TRIM(CONCAT(s.lastname,' ',s.name)) AS fio, COALESCE(s.klass,'') AS klass,
           COALESCE(s.money,0) AS price,
           s.pay_mode,
           COALESCE(p.paid_lessons,0) AS paid_lessons,
           COALESCE(v.visits,0) AS visits,
           lp.amount AS last_amount, lp.lessons AS last_lessons
    FROM stud s
    LEFT JOIN (SELECT user_id, SUM(lessons) AS paid_lessons FROM pays GROUP BY user_id) p ON p.user_id=s.user_id
    LEFT JOIN (SELECT user_id, COUNT(*) AS visits FROM dates WHERE visited=1 GROUP BY user_id) v ON v.user_id=s.user_id
    LEFT JOIN pays lp ON lp.id = (SELECT MAX(id) FROM pays px WHERE px.user_id=s.user_id)
    WHERE s.teacher_id=? AND s.archived=0
    ORDER BY fio
");
$st->bind_param('i', $tid);
$st->execute();
$students = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$debt_n = 0;
$debt_azn = 0.0;
$debt_lessons = 0;
foreach ($students as &$s) {
    $s['balance'] = (int)$s['paid_lessons'] - (int)$s['visits'];
    $s['debt'] = debt_azn($s['balance'], pack_unit_price((float)$s['price'], $s['last_amount'] ?? null, $s['last_lessons'] ?? null));
    if (student_is_debtor($s['balance'], student_pay_mode($s['pay_mode'] ?? 'prepaid'))) {
        $debt_n++;
        $debt_azn += $s['debt'];
        $debt_lessons += abs($s['balance']);
    }
}
unset($s);

$schedule_grouped = teacher_week_slots($con, $tid);
$group_size = group_size($con, $tid);
$today_wd = (int)date('N');
$sel = (int)($_GET['d'] ?? $today_wd);
if ($sel < 1 || $sel > 7) $sel = $today_wd;
$now_min = ((int)date('G') * 60) + (int)date('i');

$active = 'users';
$back = '/profile/users.php';
$fio = (string)($teacher['name'] ?: $teacher['login']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title><?= h($fio) ?> — Учитель</title>
    <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>
<div class="content content-wide">
    <div class="card">
        <div class="student-head">
            <div>
                <h1><?= h($fio) ?></h1>
                <p class="student-meta"><?= h((string)$teacher['login']) ?></p>
            </div>
        </div>
        <div class="student-stats">
            <div>
                <b><?= $students_n ?></b>
                <span>ученики</span>
            </div>
            <div>
                <b><?= fmt_money($profit_month) ?></b>
                <span>прибыль / мес.</span>
            </div>
            <div>
                <b><?= fmt_money($profit_all) ?></b>
                <span>прибыль всего</span>
            </div>
            <div class="<?= $debt_n ? 'tone-bad' : '' ?>">
                <b><?= fmt_money($debt_azn) ?></b>
                <span>долги</span>
                <?php if ($debt_n): ?><span class="debt-azn"><?= $debt_n ?> уч. · <?= $debt_lessons ?> ур.</span><?php endif; ?>
            </div>
        </div>
    </div>

    <h2>Расписание</h2>
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
        [$n_stud, $sessions] = day_counts($day_schedule);
        $times = array_column($day_schedule, 'start');
        $sum = $n_stud ? ru_students($n_stud) : 'никого нет';
        if ($sessions) $sum .= ' · '.ru_sessions($sessions);
    ?>
    <div class="day-panel" data-day="<?= $wd_num ?>" <?= $wd_num !== $sel ? 'hidden' : '' ?>>
        <div class="day-summary">
            <strong><?= h($wd_name) ?></strong>
            <span><?= h($sum) ?></span>
        </div>
        <?php if (!$day_schedule): ?>
            <p class="day-empty">В этот день занятий нет.</p>
        <?php else: ?>
            <div class="slot-list">
                <?php foreach ($day_schedule as $slot):
                    [$late_head, $slot_students, $late_tail] = slot_view_parts($slot);
                    $status = slot_status($wd_num, $today_wd, $now_min, $slot['start'], $slot['end'], $times);
                    $slot_cls = 'slot'.($status === 'сейчас' ? ' is-now' : '').($status && $status !== 'сейчас' ? ' is-next' : '').(!empty($slot['prelude']) ? ' is-prelude' : '');
                ?>
                <section class="<?= $slot_cls ?>">
                    <div class="slot-head">
                        <time><?= h($slot['start']) ?><span class="slot-end">–<?= h($slot['end']) ?></span></time>
                        <?php if ($status): ?><span class="slot-tag"><?= h($status) ?></span><?php endif; ?>
                    </div>
                    <?php foreach ($late_head as $student): ?>
                            <div class="person-row is-partial">
                                <div>
                                    <div class="name">
                                        <i class="partial-ico" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"></i>
                                        <span class="name-text"><?= h($student['fio']) ?></span>
                                    </div>
                                    <span class="partial-time"><?= h(person_show_range($student)) ?></span>
                                </div>
                            </div>
                    <?php endforeach; ?>
                    <?php foreach ($slot_students as $student): ?>
                        <div class="person-row">
                            <div>
                                <div class="name"><?= h($student['fio']) ?></div>
                                <?php if (($student['show_end'] ?? $student['end']) !== $slot['end']): ?>
                                    <div class="sub"><?= h(person_show_range($student)) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($late_tail): ?>
                        <?php foreach ($late_tail as $student): ?>
                            <div class="person-row is-partial">
                                <div>
                                    <div class="name">
                                        <i class="partial-ico" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"></i>
                                        <span class="name-text"><?= h($student['fio']) ?></span>
                                    </div>
                                    <span class="partial-time"><?= h(person_show_range($student)) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (empty($slot['prelude'])): ?>
                    <?php for ($i = 0, $free = group_free_seats(session_taken($slot), $group_size); $i < $free; $i++): ?>
                        <div class="person-row is-vacant">Свободно</div>
                    <?php endfor; ?>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="week-grid">
        <?php foreach ($weekdays_map as $wd_num => $wd_name):
            $day_schedule = $schedule_grouped[$wd_num] ?? [];
            $times = array_column($day_schedule, 'start');
            [$col_students] = day_counts($day_schedule);
        ?>
        <div class="week-col<?= $wd_num === $today_wd ? ' is-today' : '' ?><?= $wd_num === $sel ? ' is-active' : '' ?><?= !$day_schedule ? ' is-empty' : '' ?>" data-day="<?= $wd_num ?>">
            <button type="button" class="week-col-head js-day" data-day="<?= $wd_num ?>">
                <span><?= h($weekdays_short[$wd_num]) ?></span>
                <?php if ($col_students): ?><b><?= $col_students ?></b><?php endif; ?>
            </button>
            <?php if (!$day_schedule): ?>
                <p class="day-empty">нет занятий</p>
            <?php else: foreach ($day_schedule as $slot):
                [$late_head, $slot_students, $late_tail] = slot_view_parts($slot);
                $status = slot_status($wd_num, $today_wd, $now_min, $slot['start'], $slot['end'], $times);
            ?>
                <div class="week-slot<?= $status === 'сейчас' ? ' is-now' : '' ?><?= $status && $status !== 'сейчас' ? ' is-next' : '' ?>">
                    <div class="slot-head">
                        <time><?= h($slot['start']) ?><span class="slot-end">–<?= h($slot['end']) ?></span></time>
                        <?php if ($status): ?><span class="slot-tag"><?= h($status) ?></span><?php endif; ?>
                    </div>
                    <div class="week-people">
                    <?php foreach ($late_head as $student): ?>
                        <span class="is-partial" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"><i class="partial-ico"></i><?= h($student['name'] ?: $student['fio']) ?><span><?= h(person_show_range($student)) ?></span></span>
                    <?php endforeach; ?>
                    <?php foreach ($slot_students as $student): ?>
                        <span><?= h($student['name'] ?: $student['fio']) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($late_tail as $student): ?>
                        <span class="is-partial" title="<?= !empty($student['split']) ? 'Весь урок '.$student['start'].'–'.$student['end'] : 'Промежуточный' ?>"><i class="partial-ico"></i><?= h($student['name'] ?: $student['fio']) ?><span><?= h(person_show_range($student)) ?></span></span>
                    <?php endforeach; ?>
                    <?php if (empty($slot['prelude'])): ?>
                    <?php for ($i = 0, $free = group_free_seats(session_taken($slot), $group_size); $i < $free; $i++): ?>
                        <span class="is-vacant">Свободно</span>
                    <?php endfor; ?>
                    <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card students-card">
        <h2>Ученики</h2>
        <?php if (!$students): ?>
            <p class="muted students-empty">Учеников нет.</p>
        <?php else: ?>
        <table class="table students">
            <thead>
                <tr>
                    <th>Имя</th>
                    <th class="num">Класс</th>
                    <th class="num">Баланс</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $s): $bal = (int)$s['balance']; ?>
                <tr>
                    <td><?= h((string)$s['fio']) ?></td>
                    <td class="num"><?= h($s['klass'] !== '' ? $s['klass'] : '—') ?></td>
                    <td class="num"><span class="bal <?= balance_tone($bal, student_pay_mode($s['pay_mode'] ?? 'prepaid')) ?>"><?= $bal > 0 ? '+'.$bal : $bal ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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
})();
</script>
</body>
</html>
