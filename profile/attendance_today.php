<?php
// /profile/attendance_today.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

// текущая дата и день недели
$today_date = date('Y-m-d');
$wd = (int)date('N');

// обработка отправки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $date = $_POST['date'] ?? $today_date;

  // получаем список тех, кто был в таблице (расписание по weekday)
  $st = $con->prepare("
    SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
    FROM schedule sc
    JOIN stud s ON s.user_id = sc.user_id
    WHERE sc.weekday=?
    ORDER BY sc.time
  ");
  $st->bind_param('i', $wd);
  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  mysqli_begin_transaction($con);
  try {
    // подготовленные запросы
    $sel = $con->prepare("SELECT id, visited FROM dates WHERE user_id=? AND dates=? LIMIT 1");
    $ins = $con->prepare("INSERT INTO dates (user_id, dates, visited) VALUES (?,?,?)");
    $upd = $con->prepare("UPDATE dates SET visited=? WHERE id=?");

    foreach ($rows as $r) {
      $uid = (int)$r['user_id'];
      $visited = isset($_POST['visited'][$uid]) ? 1 : 0;

      // проверяем есть ли запись для этой даты и ученика
      $sel->bind_param('is', $uid, $date);
      $sel->execute();
      $sel->bind_result($did, $dvisited);
      if ($sel->fetch()) {
        $sel->free_result();
        if ((int)$dvisited !== $visited) {
          $upd->bind_param('ii', $visited, $did);
          $upd->execute();
        }
      } else {
        $sel->free_result();
        $ins->bind_param('isi', $uid, $date, $visited);
        $ins->execute();
      }
    }

    $sel->close();
    $ins->close();
    $upd->close();

    mysqli_commit($con);

    // flash и редирект чтобы избежать повторной отправки
    $_SESSION['flash_attendance'] = ['date' => $date, 'count' => count($rows)];
    header("Location: /profile/attendance_today.php");
    exit;
  } catch (Throwable $e) {
    mysqli_rollback($con);
    http_response_code(500);
    echo "Ошибка при сохранении посещений";
    exit;
  }
}

// получение списка "кто по расписанию" для отображения
$st = $con->prepare("
  SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
  FROM schedule sc
  JOIN stud s ON s.user_id = sc.user_id
  WHERE sc.weekday=?
  ORDER BY sc.time
");
$st->bind_param('i', $wd);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// flash (и сразу удалить из сессии)
$flash = $_SESSION['flash_attendance'] ?? null;
unset($_SESSION['flash_attendance']);
$showModal = is_array($flash);
$modalText = $showModal ? "Посещений занесены за {$flash['date']} — строк: {$flash['count']}" : '';

// подключаем общий навбар (общий файл)
$active = 'attendance';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Отметить посещения — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <h2>Отметить посещения — <?= htmlspecialchars($today_date) ?></h2>

    <?php if (!$today): ?>
      <p>Сегодня по расписанию нет учеников.</p>
    <?php else: ?>
      <form method="post" action="/profile/attendance_today.php" id="attendanceForm">
        <input type="hidden" name="date" value="<?= htmlspecialchars($today_date) ?>">
        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <?php endif; ?>

        <!-- Кнопки массовой отметки -->
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
          <button type="button" id="markAllBtn" class="btn btn-ghost">Отметить всех</button>
          <button type="button" id="clearAllBtn" class="btn">Снять отметки</button>
          <div style="margin-left:auto;color:var(--muted);font-size:14px;">
            Отметки по умолчанию включены
          </div>
        </div>

        <table class="table today">
          <thead>
            <tr>
              <th style="width:18%;">Время</th>
              <th>Ученик</th>
              <th style="width:16%;">Посетил</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($today as $t): $uid = (int)$t['user_id']; ?>
              <tr>
                <td class="time-cell"><?= htmlspecialchars(substr($t['time'],0,5)) ?></td>
                <td><a class="link-strong" href="/profile/student.php?user_id=<?= $uid ?>"><?= htmlspecialchars($t['fio']) ?></a></td>
                <td style="text-align:center;">
                  <!-- по умолчанию отмечаем checked -->
                  <input class="visit-checkbox" type="checkbox" name="visited[<?= $uid ?>]" value="1" checked>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:12px; display:flex; gap:10px; align-items:center;">
          <!-- теперь это не submit, а кнопка, открывающая модал подтверждения -->
          <button type="button" id="saveBtn" class="btn">Сохранить посещения</button>
          <a class="btn btn-ghost" href="/profile/index.php">Отмена</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Confirm modal -->
<div id="confirmModal" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <h3 id="confirmTitle">Подтвердите сохранение</h3>
    <p>Вы уверены, что хотите сохранить отметки посещения за <strong><?= htmlspecialchars($today_date) ?></strong>?</p>
    <div class="modal-actions" style="margin-top:12px">
      <button id="confirmSubmit" class="btn">Подтвердить</button>
      <button id="confirmCancel" class="btn btn-ghost">Отмена</button>
    </div>
    <button class="modal-close" id="confirmClose" aria-label="Закрыть">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
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
      <a class="btn" href="/profile/index.php">
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12l9-9 9 9"/><path d="M9 21V9h6v12"/></svg>
        На главную
      </a>
      <a class="btn btn-ghost" href="/profile/attendance_today.php">
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Отметить ещё
      </a>
    </div>
    <button class="modal-close" id="modalClose" aria-label="Закрыть">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>

<script>
(function(){
  // массовые кнопки
  const markAllBtn = document.getElementById('markAllBtn');
  const clearAllBtn = document.getElementById('clearAllBtn');
  const checkboxes = () => Array.from(document.querySelectorAll('.visit-checkbox'));

  markAllBtn?.addEventListener('click', () => {
    checkboxes().forEach(cb => cb.checked = true);
  });

  clearAllBtn?.addEventListener('click', () => {
    checkboxes().forEach(cb => cb.checked = false);
  });

  // confirm modal logic
  const saveBtn = document.getElementById('saveBtn');
  const confirmModal = document.getElementById('confirmModal');
  const confirmSubmit = document.getElementById('confirmSubmit');
  const confirmCancel = document.getElementById('confirmCancel');
  const confirmClose = document.getElementById('confirmClose');
  const form = document.getElementById('attendanceForm');

  function openConfirm(){
    if(!confirmModal) return;
    confirmModal.removeAttribute('hidden');
    document.body.classList.add('noscroll');
    // focus on confirm button for accessibility
    confirmSubmit?.focus();
  }
  function closeConfirm(){
    if(!confirmModal) return;
    confirmModal.setAttribute('hidden','');
    document.body.classList.remove('noscroll');
    saveBtn?.focus();
  }

  saveBtn?.addEventListener('click', (e)=>{
    e.preventDefault();
    openConfirm();
  });

  confirmCancel?.addEventListener('click', (e)=>{
    e.preventDefault();
    closeConfirm();
  });
  confirmClose?.addEventListener('click', (e)=>{
    e.preventDefault();
    closeConfirm();
  });
  // submit from confirm
  confirmSubmit?.addEventListener('click', (e)=>{
    e.preventDefault();
    // disable confirm to prevent double-submit
    confirmSubmit.disabled = true;
    // submit the form
    form.submit();
  });

  // keyboard/overlay handlers
  window.addEventListener('keydown', function(e){
    if(e.key === 'Escape'){
      if(confirmModal && !confirmModal.hasAttribute('hidden')) closeConfirm();
      const successModal = document.getElementById('successModal');
      if(successModal && !successModal.hasAttribute('hidden')) {
        successModal.setAttribute('hidden','');
        document.body.classList.remove('noscroll');
      }
    }
  });
  confirmModal?.addEventListener('click', e => { if (e.target === confirmModal) closeConfirm(); });

  // success modal handlers (same as before)
  const successModal = document.getElementById('successModal');
  const successClose = document.getElementById('modalClose');
  if (successModal && !successModal.hasAttribute('hidden')) {
    document.body.classList.add('noscroll');
  }
  successClose?.addEventListener('click', ()=> { successModal?.setAttribute('hidden',''); document.body.classList.remove('noscroll'); });
  successModal?.addEventListener('click', e => { if (e.target === successModal) { successModal.setAttribute('hidden',''); document.body.classList.remove('noscroll'); } });

})();
</script>
</body>
</html>
