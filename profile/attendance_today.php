<?php
// /profile/attendance_today.php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

// текущая дата и день недели
$today_date = date('Y-m-d');
$wd = (int)date('N');

// Итоговая "красивая" дата, например: 17 Сентября
$months = [
  1 => 'Января',2=>'Февраля',3=>'Марта',4=>'Апреля',5=>'Мая',6=>'Июня',
  7=>'Июля',8=>'Августа',9=>'Сентября',10=>'Октября',11=>'Ноября',12=>'Декабря'
];
$prettyDate = (int)date('j', strtotime($today_date)) . ' ' . ($months[(int)date('n', strtotime($today_date))] ?? '');

// Обработка POST (сохранение посещений)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_check')) { csrf_check(); }

    $date = $_POST['date'] ?? $today_date;

    // получаем список тех, кто по расписанию сегодня
    $st = $con->prepare("
      SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
      FROM schedule sc
      JOIN stud s ON s.user_id = sc.user_id
      WHERE sc.weekday=?
      ORDER BY sc.time
    ");
    if (!$st) {
        error_log("attendance_today: prepare SELECT failed: " . $con->error);
        http_response_code(500);
        echo "Ошибка при получении списка по расписанию.";
        exit;
    }
    $st->bind_param('i', $wd);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // Сохраняем в транзакции с upsert
    mysqli_begin_transaction($con);
    try {
        $upsert = $con->prepare(
            "INSERT INTO `dates` (`user_id`, `dates`, `visited`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `visited` = VALUES(`visited`)"
        );
        if (!$upsert) {
            throw new RuntimeException("Prepare upsert failed: " . $con->error);
        }

        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $visited = isset($_POST['visited'][$uid]) ? 1 : 0;

            if (!$upsert->bind_param('isi', $uid, $date, $visited)) {
                throw new RuntimeException("Bind param failed: " . $upsert->error);
            }

            if (!$upsert->execute()) {
                throw new RuntimeException("Execute upsert failed for user {$uid}: " . $upsert->error);
            }
        }

        $upsert->close();
        mysqli_commit($con);

        $_SESSION['flash_attendance'] = ['date' => $date, 'count' => count($rows)];
        header("Location: /profile/attendance_today.php");
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        error_log("attendance_today: save error: " . $e->getMessage());
        http_response_code(500);
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Ошибка</title>";
        echo "<link href='/profile/css/style.css' rel='stylesheet'></head><body>";
        echo "<div class='content'><div class='card'><h2>Ошибка при сохранении посещений</h2>";
        echo "<p>Произошла ошибка при сохранении посещений. Администратор уведомлён.</p>";
        echo "<p><a class='btn' href='/profile/attendance_today.php'>Вернуться</a></p>";
        echo "</div></div></body></html>";
        exit;
    }
}

// Получение списка "кто по расписанию" для отображения
$st = $con->prepare("
  SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
  FROM schedule sc
  JOIN stud s ON s.user_id = sc.user_id
  WHERE sc.weekday=?
  ORDER BY sc.time
");
if (!$st) {
    error_log("attendance_today: prepare SELECT failed (display): " . $con->error);
    echo "Ошибка при получении списка по расписанию.";
    exit;
}
$st->bind_param('i', $wd);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// flash (и сразу удалить из сессии)
$flash = $_SESSION['flash_attendance'] ?? null;
unset($_SESSION['flash_attendance']);
$showModal = is_array($flash);
$modalText = $showModal ? "Посещений занесены за {$flash['date']} — строк: {$flash['count']}" : '';

// подключаем общий навбар
$active = 'attendance';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Отметить посещения — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    /* Небольшие локальные правки для таблицы (если не хотите - перенесите в css-файл) */
    .table.today th { background:#eef1f4; border-bottom:1px solid var(--border); }
    .table.today th:not(:last-child){ border-right:1px solid rgba(0,0,0,0.06); }
    .table.today td { vertical-align:middle; }
    .table.today .time-cell { text-align:left; font-weight:600; width:18%; }

    /* Responsive: на узких экранах делаем строки "карточками" с метками */
    @media (max-width:720px) {
      .table.today { border-collapse:separate; border-spacing:0; }
      .table.today thead { display:none; }
      .table.today tbody tr { display:block; margin-bottom:12px; border-radius:10px; background:#fff; box-shadow:0 4px 14px rgba(0,0,0,0.04); overflow:hidden; }
      .table.today tbody td { display:flex; justify-content:space-between; padding:12px 14px; border-bottom:1px solid var(--border); }
      .table.today tbody td:last-child { border-bottom:0; }
      .table.today tbody td::before {
        content: attr(data-label);
        display:block;
        font-weight:600;
        color:#555;
        margin-right:12px;
      }
      .table.today tbody td .visit-checkbox { transform:scale(1.05); }
      .table.today tbody td a.link-strong { white-space:normal; }
    }
  </style>
</head>
<body class="attendance-page">

<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <h2>Отметить посещения — <?= htmlspecialchars($prettyDate) ?></h2>

    <?php if (!$today): ?>
      <p>Сегодня по расписанию нет учеников.</p>
    <?php else: ?>
      <form method="post" action="/profile/attendance_today.php" id="attendanceForm">
        <input type="hidden" name="date" value="<?= htmlspecialchars($today_date) ?>">
        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <?php endif; ?>

        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
          <button type="button" id="markAllBtn" class="btn btn-ghost" title="Отметить всех">Отметить всех</button>
          <button type="button" id="clearAllBtn" class="btn" title="Снять отметки">Снять отметки</button>
          <div style="margin-left:auto;color:var(--muted);font-size:14px;">
            По умолчанию чекбоксы не выбраны
          </div>
        </div>

        <table class="table today" role="table" aria-label="Отметить посещения">
          <thead>
            <tr>
              <th>Время</th>
              <th>Ученик</th>
              <th>Посетил</th>
            </tr>
          </thead>
<tbody>
<?php foreach ($today as $t): $uid = (int)$t['user_id']; ?>
  <tr>
    <!-- время -->
    <td data-label="Время" class="time-cell">
      <div class="cell-content"><?= htmlspecialchars(substr($t['time'], 0, 5)) ?></div>
    </td>

    <!-- ученик -->
    <td data-label="Ученик">
      <div class="cell-content">
        <a class="link-strong" href="/profile/student.php?user_id=<?= $uid ?>"><?= htmlspecialchars($t['fio']) ?></a>
      </div>
    </td>

    <!-- чекбокс -->
    <td data-label="Посетил" class="col-visited">
      <div class="cell-content">
        <label class="visit-label" title="Отметить посетил">
          <input class="visit-checkbox" type="checkbox" name="visited[<?= $uid ?>]" value="1" aria-label="Посетил <?= htmlspecialchars($t['fio']) ?>">
          <span class="visit-custom"></span>
        </label>
      </div>
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

<!-- Confirm modal -->
<div id="confirmModal" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <h3 id="confirmTitle">Подтвердите сохранение</h3>
    <p>Вы уверены, что хотите сохранить отметки посещения за <strong><?= htmlspecialchars($prettyDate) ?></strong>?</p>
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
  const markAllBtn = document.getElementById('markAllBtn');
  const clearAllBtn = document.getElementById('clearAllBtn');
  const checkboxes = () => Array.from(document.querySelectorAll('.visit-checkbox'));

  markAllBtn?.addEventListener('click', () => { checkboxes().forEach(cb => cb.checked = true); });
  clearAllBtn?.addEventListener('click', () => { checkboxes().forEach(cb => cb.checked = false); });

  // confirm modal logic
  const saveBtn = document.getElementById('saveBtn');
  const confirmModal = document.getElementById('confirmModal');
  const confirmSubmit = document.getElementById('confirmSubmit');
  const confirmCancel = document.getElementById('confirmCancel');
  const confirmClose = document.getElementById('confirmClose');
  const form = document.getElementById('attendanceForm');

  function openConfirm(){ if(!confirmModal) return; confirmModal.removeAttribute('hidden'); document.body.classList.add('noscroll'); confirmSubmit?.focus(); }
  function closeConfirm(){ if(!confirmModal) return; confirmModal.setAttribute('hidden',''); document.body.classList.remove('noscroll'); saveBtn?.focus(); }

  saveBtn?.addEventListener('click', (e)=>{ e.preventDefault(); openConfirm(); });
  confirmCancel?.addEventListener('click', (e)=>{ e.preventDefault(); closeConfirm(); });
  confirmClose?.addEventListener('click', (e)=>{ e.preventDefault(); closeConfirm(); });
  confirmSubmit?.addEventListener('click', (e)=>{ e.preventDefault(); confirmSubmit.disabled = true; form.submit(); });

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
