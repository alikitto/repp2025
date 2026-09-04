<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';

apply_app_timezone($con);
$maxDate = date('Y-m-d');
$today_date = parse_ymd($_GET['date'] ?? null) ?? $maxDate;
if ($today_date > $maxDate) {
    $today_date = $maxDate;
    header('Location: /profile/attendance_today.php?date=' . urlencode($maxDate));
    exit;
}
$wd = (int)date('N', strtotime($today_date));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $date = require_past_or_today_ymd($_POST['date'] ?? null);
    if ($date === null) {
        http_response_code(400);
        echo "<p>Неверная или будущая дата.</p>";
        exit;
    }
    $wd_save = (int)date('N', strtotime($date));

    $st = $con->prepare("
      SELECT s.user_id, DATE_FORMAT(sc.time,'%H:%i') AS time
      FROM schedule sc
      JOIN stud s ON s.user_id = sc.user_id
      WHERE sc.weekday=? AND s.teacher_id=? AND s.archived=0
    ");
    $tid = teacher_id();
    $st->bind_param('ii', $wd_save, $tid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $slotCount = [];
    foreach ($rows as $r) {
        $u = (int)$r['user_id'];
        $slotCount[$u] = ($slotCount[$u] ?? 0) + 1;
    }

    mysqli_begin_transaction($con);
    try {
        $upsert = $con->prepare(
            "INSERT INTO `dates` (`user_id`, `dates`, `time`, `visited`) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `visited` = VALUES(`visited`)"
        );
        $posted = $_POST['visited'] ?? [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $hm = (string)$r['time'];
            $time = $hm . ':00';
            dates_realign_one($con, $uid, $date, $time, $slotCount[$uid] ?? 1);
            $visited = !empty($posted[$uid][$hm]) ? 1 : 0;
            $upsert->bind_param('issi', $uid, $date, $time, $visited);
            if (!$upsert->execute()) throw new RuntimeException('dates upsert failed');
        }
        $upsert->close();
        mysqli_commit($con);
        $d = date('d.m.Y', strtotime($date));
        log_activity($con, 'visit', 'Сохранены посещения за ' . $d . ' (' . count($rows) . ')', '/profile/attendance_today.php?date=' . urlencode($date));
        $_SESSION['flash_attendance'] = ['date' => $date, 'count' => count($rows)];
        header("Location: /profile/attendance_today.php?date=" . urlencode($date));
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        http_response_code(500);
        echo "<p>Ошибка при сохранении посещений.</p>";
        exit;
    }
}

$st = $con->prepare("
  SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio,
         DATE_FORMAT(sc.time,'%H:%i') AS time
  FROM schedule sc
  JOIN stud s ON s.user_id = sc.user_id
  WHERE sc.weekday=? AND s.teacher_id=? AND s.archived=0
  ORDER BY sc.time, s.lastname, s.name
");
$tid = teacher_id();
$st->bind_param('ii', $wd, $tid);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$todaySlots = [];
foreach ($today as $t) {
    $u = (int)$t['user_id'];
    $todaySlots[$u] = ($todaySlots[$u] ?? 0) + 1;
}
$markFallback = fn(int $uid): bool => ($todaySlots[$uid] ?? 1) === 1;

$mst = $con->prepare("SELECT d.user_id, DATE_FORMAT(d.time,'%H:%i') AS time, d.visited FROM dates d JOIN stud s ON s.user_id=d.user_id WHERE d.dates=? AND s.teacher_id=?");
$mst->bind_param('si', $today_date, $tid);
$mst->execute();
$marks = dates_visited_lookup($mst->get_result()->fetch_all(MYSQLI_ASSOC));
$mst->close();

$flash = $_SESSION['flash_attendance'] ?? null;
unset($_SESSION['flash_attendance']);
$showModal = is_array($flash);
$hasSavedForList = false;
foreach ($today as $t) {
    if (dates_mark_visited($marks, (int)$t['user_id'], (string)$t['time'], $markFallback((int)$t['user_id'])) !== null) { $hasSavedForList = true; break; }
}
$isToday = $today_date === $maxDate;
$alreadySaved = $hasSavedForList;
$editMode = isset($_GET['edit']);
$showEditor = !$alreadySaved || $editMode;
$resaveToday = $isToday && $hasSavedForList;
$present = [];
$absent = [];
if ($alreadySaved) {
    foreach ($today as $t) {
        if (dates_mark_visited($marks, (int)$t['user_id'], (string)$t['time'], $markFallback((int)$t['user_id'])) === 1) $present[] = $t;
        else $absent[] = $t;
    }
}
$months = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
$prettyDate = (int)date('j', strtotime($today_date)) . ' ' . ($months[(int)date('n', strtotime($today_date))] ?? '');
$prev = date('Y-m-d', strtotime($today_date.' -1 day'));
$next = date('Y-m-d', strtotime($today_date.' +1 day'));
$active = 'attendance';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Посещения — Tutor CRM</title>
  <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <h1>Посещения</h1>
  <form method="get" class="date-bar">
    <a class="icon-btn" href="/profile/attendance_today.php?date=<?= h($prev) ?>" aria-label="Вчера">‹</a>
    <input type="date" name="date" class="input" id="attDate" value="<?= h($today_date) ?>" max="<?= h($maxDate) ?>">
    <?php if ($isToday): ?>
      <span class="icon-btn is-disabled" aria-disabled="true" aria-label="Завтра">›</span>
    <?php else: ?>
      <a class="icon-btn" href="/profile/attendance_today.php?date=<?= h($next) ?>" aria-label="Завтра">›</a>
    <?php endif; ?>
  </form>
  <p class="muted"><?= h($prettyDate) ?></p>

  <div class="card">
    <?php if (!$today): ?>
      <p class="muted">На эту дату по расписанию никого нет.</p>
    <?php else: ?>
      <?php if (!$showEditor): ?>
        <div class="notice ok">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="currentColor"/><path d="M7.2 12.4l3.1 3.1 6.5-7.2" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <span>Посещения за этот день уже сохранены</span>
        </div>
        <?php if ($present): ?>
          <div class="visit-section">
            <h3>Были · <?= count($present) ?></h3>
            <div class="person-list">
              <?php foreach ($present as $t): ?>
                <div class="visit-row is-static">
                  <div class="info">
                    <div class="name"><?= h($t['fio']) ?></div>
                    <div class="sub"><?= h($t['time']) ?></div>
                  </div>
                  <span class="visit-badge ok">Был</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($absent): ?>
          <div class="visit-section">
            <h3>Не были · <?= count($absent) ?></h3>
            <div class="person-list">
              <?php foreach ($absent as $t): ?>
                <div class="visit-row is-static">
                  <div class="info">
                    <div class="name"><?= h($t['fio']) ?></div>
                    <div class="sub"><?= h($t['time']) ?></div>
                  </div>
                  <span class="visit-badge no">Не был</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="sticky-actions">
          <a class="btn" style="flex:1" href="/profile/attendance_today.php?date=<?= h($today_date) ?>&amp;edit=1">Отредактировать посещения за этот день</a>
        </div>
      <?php else: ?>
      <form id="attendanceForm" method="post" action="/profile/attendance_today.php" data-resave-today="<?= $resaveToday ? '1' : '0' ?>">
        <input type="hidden" name="date" value="<?= h($today_date) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <?php if ($alreadySaved): ?>
          <div class="notice ok">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="currentColor"/><path d="M7.2 12.4l3.1 3.1 6.5-7.2" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Посещения за этот день уже сохранены</span>
          </div>
        <?php endif; ?>

        <div class="toolbar">
          <label class="visit-row is-all" style="margin:0;flex:1">
            <div class="info"><div class="name">Все пришли</div></div>
            <span class="switch">
              <input type="checkbox" id="markAllBtn">
              <i></i>
            </span>
          </label>
        </div>

        <div class="person-list">
          <?php foreach ($today as $t):
            $uid = (int)$t['user_id'];
            $hm = (string)$t['time'];
            $checked = $alreadySaved && dates_mark_visited($marks, $uid, $hm, $markFallback($uid)) === 1;
          ?>
            <label class="visit-row">
              <div class="info">
                <div class="name"><?= h($t['fio']) ?></div>
                <div class="sub"><?= h($hm) ?></div>
              </div>
              <span class="switch">
                <input type="hidden" name="visited[<?= $uid ?>][<?= h($hm) ?>]" value="0">
                <input class="visit-checkbox" type="checkbox" name="visited[<?= $uid ?>][<?= h($hm) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                <i></i>
              </span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="sticky-actions">
          <button type="button" id="saveBtn" class="btn" style="flex:1">Сохранить</button>
        </div>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div id="confirmModal" class="modal" hidden>
  <div class="modal-card confirm-card" role="dialog">
    <div class="confirm-icon brand">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </div>
    <h3>Подтвердите</h3>
    <p id="resaveWarn" class="muted" hidden>Это повторное сохранение сегодняшнего дня. Данные перезапишутся.</p>
    <p class="muted" id="absentLabel">Не отмечены как пришедшие:</p>
    <ul id="absentList" class="absent-list"></ul>
    <div class="modal-actions">
      <button type="button" id="confirmCancel" class="btn gray">Отмена</button>
      <button type="button" id="confirmSubmit" class="btn">Подтвердить</button>
    </div>
  </div>
</div>

<script <?= csp_nonce_attr() ?>>
<?php if ($showModal): ?>
window.showActionOk(<?= json_encode([
    'title' => 'Посещения сохранены',
    'meta' => ru_students((int)$flash['count']),
    'date' => $prettyDate,
], JSON_UNESCAPED_UNICODE) ?>);
<?php endif; ?>
(function(){
  document.getElementById('attDate')?.addEventListener('change', function(){ this.form.submit(); });
  const rows = Array.from(document.querySelectorAll('.visit-row'));
  const checkboxes = () => Array.from(document.querySelectorAll('.visit-checkbox'));
  const markAll = document.getElementById('markAllBtn');
  function syncMarkAll(){
    const cbs = checkboxes();
    if (markAll) markAll.checked = cbs.length > 0 && cbs.every(cb => cb.checked);
  }
  markAll?.addEventListener('change', () => checkboxes().forEach(cb => { cb.checked = markAll.checked; }));
  checkboxes().forEach(cb => cb.addEventListener('change', syncMarkAll));
  syncMarkAll();

  const saveBtn = document.getElementById('saveBtn');
  const confirmModal = document.getElementById('confirmModal');
  const form = document.getElementById('attendanceForm');
  function openConfirm(){
    const absents = [];
    rows.forEach(r => {
      const cb = r.querySelector('.visit-checkbox');
      if (cb && !cb.checked) {
        const name = r.querySelector('.name')?.textContent.trim() || '';
        const tm = r.querySelector('.sub')?.textContent.trim() || '';
        absents.push(tm ? name + ' · ' + tm : name);
      }
    });
    const list = document.getElementById('absentList');
    list.innerHTML = '';
    const label = document.getElementById('absentLabel');
    if (label) label.hidden = absents.length === 0;
    (absents.length ? absents : ['Все отмечены. Сохранить?']).forEach(n => {
      const li = document.createElement('li'); li.textContent = n; list.appendChild(li);
    });
    const warn = document.getElementById('resaveWarn');
    if (warn) warn.hidden = form?.dataset.resaveToday !== '1';
    confirmModal.removeAttribute('hidden');
    document.body.classList.add('noscroll');
  }
  function closeConfirm(){ confirmModal.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }
  saveBtn?.addEventListener('click', e => { e.preventDefault(); openConfirm(); });
  document.getElementById('confirmCancel')?.addEventListener('click', e => { e.preventDefault(); closeConfirm(); });
  document.getElementById('confirmClose')?.addEventListener('click', e => { e.preventDefault(); closeConfirm(); });
  document.getElementById('confirmSubmit')?.addEventListener('click', e => { e.preventDefault(); e.target.disabled = true; form.submit(); });
})();
</script>
</body>
</html>
