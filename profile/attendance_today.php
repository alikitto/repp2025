<?php
// /profile/attendance_today.php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

// Текущая дата и день недели
$today_date = date('Y-m-d');
$wd = (int)date('N');

// --- POST: сохранить посещения (upsert) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_check')) { csrf_check(); }
    $date = $_POST['date'] ?? $today_date;

    // Получаем список пользователей по расписанию сегодня
    $st = $con->prepare("
      SELECT s.user_id
      FROM schedule sc
      JOIN stud s ON s.user_id = sc.user_id
      WHERE sc.weekday=?
      ORDER BY sc.time
    ");
    if (!$st) {
        error_log("attendance_today: prepare SELECT failed: " . $con->error);
        http_response_code(500); echo "Ошибка получения списка."; exit;
    }
    $st->bind_param('i', $wd);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // Сохраняем в транзакции; используем INSERT ... ON DUPLICATE KEY UPDATE
    mysqli_begin_transaction($con);
    try {
        $upsert = $con->prepare(
            "INSERT INTO `dates` (`user_id`, `dates`, `visited`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `visited` = VALUES(`visited`)"
        );
        if (!$upsert) throw new RuntimeException("Prepare upsert failed: " . $con->error);

        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            // если чекбокс отмечен — 1, иначе 0
            $visited = isset($_POST['visited'][$uid]) ? 1 : 0;
            if (!$upsert->bind_param('isi', $uid, $date, $visited)) {
                throw new RuntimeException("Bind failed: " . $upsert->error);
            }
            if (!$upsert->execute()) {
                throw new RuntimeException("Execute failed for user {$uid}: " . $upsert->error);
            }
        }

        $upsert->close();
        mysqli_commit($con);

        $_SESSION['flash_attendance'] = ['date' => $date, 'count' => count($rows)];
        header("Location: /profile/attendance_today.php");
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        error_log("attendance_today save error: " . $e->getMessage());
        http_response_code(500);
        echo "<p>Ошибка при сохранении посещений. См. лог.</p>";
        exit;
    }
}

// --- Display: загрузка списка по расписанию ---
$st = $con->prepare("
  SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
  FROM schedule sc
  JOIN stud s ON s.user_id = sc.user_id
  WHERE sc.weekday=?
  ORDER BY sc.time
");
if (!$st) { error_log("attendance_today: prepare display failed: " . $con->error); echo "Ошибка"; exit; }
$st->bind_param('i', $wd);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$flash = $_SESSION['flash_attendance'] ?? null;
unset($_SESSION['flash_attendance']);
$showModal = is_array($flash);
$modalText = $showModal ? "Посещений занесены за {$flash['date']} — строк: {$flash['count']}" : '';

$active = 'attendance';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Отметить посещения — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    /* немного стилей для списка в confirm modal */
    .absent-list { margin:8px 0 0; padding-left:16px; color:#111; }
    .absent-list li { margin:6px 0; }
    .confirm-note { color:var(--muted); margin-top:8px; font-size:14px; }
  </style>
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
      <?php $months = [
  1=>'Января',2=>'Февраля',3=>'Марта',4=>'Апреля',5=>'Мая',6=>'Июня',
  7=>'Июля',8=>'Августа',9=>'Сентября',10=>'Октября',11=>'Ноября',12=>'Декабря'
];
$prettyDate = (int)date('j', strtotime($today_date)) . ' ' . ($months[(int)date('n', strtotime($today_date))] ?? '');
?>
    <h2>Отметить посещения — <?= htmlspecialchars($prettyDate) ?></h2>

    <?php if (!$today): ?>
      <p>Сегодня по расписанию нет учеников.</p>
    <?php else: ?>
      <form id="attendanceForm" method="post" action="/profile/attendance_today.php">
        <input type="hidden" name="date" value="<?= htmlspecialchars($today_date) ?>">
        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <?php endif; ?>

        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
          <button type="button" id="markAllBtn" class="btn btn-ghost">Отметить всех</button>
          <button type="button" id="clearAllBtn" class="btn">Снять отметки</button>
          <div style="margin-left:auto;color:var(--muted);font-size:14px;">По умолчанию чекбоксы отмечены</div>
        </div>

        <table class="table today" role="table" aria-label="Отметить посещения">
          <thead>
            <tr>
              <th style="width:18%;">Время</th>
              <th>Ученик</th>
              <th style="width:16%;text-align:center">Посетил</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($today as $t): $uid = (int)$t['user_id']; ?>
              <tr>
                <td class="time-cell"><?= htmlspecialchars(substr($t['time'],0,5)) ?></td>
                <td><a class="link-strong" href="/profile/student.php?user_id=<?= $uid ?>"><?= htmlspecialchars($t['fio']) ?></a></td>
                <td style="text-align:center;">
                  <label class="visit-label" title="Посетил">
                    <input class="visit-checkbox" type="checkbox" name="visited[<?= $uid ?>]" value="1" checked>
                    <span style="display:inline-block;width:16px;height:16px;"></span>
                  </label>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
          <button type="button" id="saveBtn" class="btn">Сохранить посещения</button>
          <a class="btn btn-ghost" href="/profile/index.php">Отмена</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Confirm modal: показываем список отсутствующих и спрашиваем "Вы подтверждаете, что эти ученики не пришли?" -->
<div id="confirmModal" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <h3 id="confirmTitle">Подтвердите отсутствие</h3>
    <p class="confirm-note">Вы подтверждаете, что эти ученики не пришли?</p>
    <ul id="absentList" class="absent-list"></ul>

    <div class="modal-actions" style="margin-top:12px">
      <button id="confirmSubmit" class="btn">Подтвердить</button>
      <button id="confirmCancel" class="btn btn-ghost">Отмена</button>
    </div>
    <button class="modal-close" id="confirmClose" aria-label="Закрыть">✕</button>
  </div>
</div>

<!-- Success modal -->
<div id="successModal" class="modal" <?php if (!$showModal) echo 'hidden'; ?>>
  <div class="modal-card">
    <div class="modal-icon success">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2l4-4"/></svg>
    </div>
    <h3>Посещений занесены</h3>
    <p><?= htmlspecialchars($modalText) ?></p>
    <div class="modal-actions">
      <a class="btn" href="/profile/index.php">На главную</a>
      <a class="btn btn-ghost" href="/profile/attendance_today.php">Отметить ещё</a>
    </div>
    <button class="modal-close" id="modalClose" aria-label="Закрыть">✕</button>
  </div>
</div>

<script>
(function(){
  // Хелперы
  const rows = Array.from(document.querySelectorAll('table.table.today tbody tr'));
  const checkboxes = () => Array.from(document.querySelectorAll('.visit-checkbox'));

  // Кнопки массовой отметки
  const markAllBtn = document.getElementById('markAllBtn');
  const clearAllBtn = document.getElementById('clearAllBtn');
  markAllBtn?.addEventListener('click', ()=> checkboxes().forEach(cb => cb.checked = true));
  clearAllBtn?.addEventListener('click', ()=> checkboxes().forEach(cb => cb.checked = false));

  // confirm modal logic
  const saveBtn = document.getElementById('saveBtn');
  const confirmModal = document.getElementById('confirmModal');
  const confirmSubmit = document.getElementById('confirmSubmit');
  const confirmCancel = document.getElementById('confirmCancel');
  const confirmClose = document.getElementById('confirmClose');
  const absentList = document.getElementById('absentList');
  const form = document.getElementById('attendanceForm');

  function buildAbsentList() {
    // Собираем unchecked чекбоксы и соответствующие им имена (берём из td)
    const absents = [];
    rows.forEach(r => {
      const cb = r.querySelector('.visit-checkbox');
      if (!cb) return;
      if (!cb.checked) {
        // имя в второй колонке
        const nameTd = r.querySelectorAll('td')[1];
        const name = nameTd ? nameTd.textContent.trim() : '—';
        absents.push(name);
      }
    });
    return absents;
  }

  function openConfirm(){
    const absents = buildAbsentList();
    absentList.innerHTML = '';
    if (absents.length === 0) {
      const li = document.createElement('li');
      li.textContent = 'Отсутствующих нет. Вы уверен(а), что хотите сохранить?';
      absentList.appendChild(li);
    } else {
      absents.forEach(n=>{
        const li = document.createElement('li');
        li.textContent = n;
        absentList.appendChild(li);
      });
    }
    confirmModal.removeAttribute('hidden');
    document.body.classList.add('noscroll');
  }
  function closeConfirm(){
    confirmModal.setAttribute('hidden','');
    document.body.classList.remove('noscroll');
  }

  saveBtn?.addEventListener('click', (e)=>{ e.preventDefault(); openConfirm(); });
  confirmCancel?.addEventListener('click', (e)=>{ e.preventDefault(); closeConfirm(); });
  confirmClose?.addEventListener('click', (e)=>{ e.preventDefault(); closeConfirm(); });

  confirmSubmit?.addEventListener('click', (e)=> {
    e.preventDefault();
    // при подтверждении просто отправляем форму — сервер обновит/создаст записи (upsert)
    confirmSubmit.disabled = true;
    form.submit();
  });

  // клавиатура, клик по модалке
  window.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ if(confirmModal && !confirmModal.hasAttribute('hidden')) closeConfirm(); } });
  confirmModal?.addEventListener('click', e => { if (e.target === confirmModal) closeConfirm(); });

  // success modal handlers
  const successModal = document.getElementById('successModal');
  const successClose = document.getElementById('modalClose');
  if (successModal && !successModal.hasAttribute('hidden')) { document.body.classList.add('noscroll'); }
  successClose?.addEventListener('click', ()=> { successModal?.setAttribute('hidden',''); document.body.classList.remove('noscroll'); });
  successModal?.addEventListener('click', e => { if (e.target === successModal) { successModal.setAttribute('hidden',''); document.body.classList.remove('noscroll'); } });
})();
</script>
</body>
</html>
