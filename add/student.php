<?php
require_once __DIR__ . '/../profile/_auth.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../common/csrf.php';
$csrf_val = function_exists('csrf_token')
  ? csrf_token()
  : (isset($_SESSION['csrf']) ? $_SESSION['csrf'] : ($_SESSION['csrf']=bin2hex(random_bytes(32))));

$days = ['Понедельник','Вторник','Среда','Четверг','Пятница','Суббота','Воскресенье'];
$classes = range(5,11); // 1..11

// тайм-слоты 09:00–20:00, шаг 30 мин
$times = [];
for ($h=9; $h<=20; $h++) { foreach ([0,30] as $m) { if ($h===20 && $m>0) continue; $times[] = sprintf('%02d:%02d',$h,$m);} }

// FLASH из сессии (успех создания)
$flash = $_SESSION['flash_created'] ?? null;
unset($_SESSION['flash_created']);
$created   = is_array($flash);
$createdId = $created ? (int)$flash['id'] : 0;
$createdNm = $created ? htmlspecialchars($flash['name']) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Добавить ученика — Tutor CRM</title>
  <link rel="stylesheet" href="/profile/css/style.css">
</head>
<body>

<?php $active='add-student'; require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <h2>Добавить ученика</h2>

    <form action="/add/save.php" method="post" class="form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_val) ?>">

      <div class="grid-3">
        <div class="form-group">
          <label for="name">Имя</label>
          <input class="input" type="text" id="name" name="name" required>
        </div>

        <div class="form-group">
          <label for="klass">Класс</label>
          <select id="klass" name="klass" class="input select-big">
            <option value="">— не выбрано —</option>
            <?php foreach ($classes as $k): ?>
              <option value="<?= $k ?>"><?= $k ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="money">Оплата (AZN)</label>
          <input class="input" type="number" id="money" name="money" inputmode="decimal" step="0.01" min="0" value="0">
        </div>
      </div>

      <h3 style="margin:16px 0 8px;">Расписание (до 3 слотов)</h3>
      <table class="table today schedule-table">
        <thead><tr><th>День недели</th><th>Время</th></tr></thead>
        <tbody>
        <?php for ($i=1;$i<=3;$i++): ?>
          <tr>
            <td>
              <select class="select-big" name="day<?= $i ?>">
                <option value="">— не выбрано —</option>
                <?php foreach ($days as $d): ?>
                  <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select class="select-big" name="time<?= $i ?>">
                <option value="">— — : — —</option>
                <?php foreach ($times as $t): ?>
                  <option value="<?= $t ?>"><?= $t ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>

      <button type="submit" class="btn" style="margin-top:12px;">
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Создать ученика
      </button>
    </form>
  </div>
</div>

<!-- Модалка успеха -->
<div id="successModal" class="modal" <?php if(!$created) echo 'hidden'; ?>>
  <div class="modal-card">
    <div class="modal-icon success">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2l4-4"/></svg>
    </div>
    <h3>Ученик добавлен</h3>
    <p><strong><?= $createdNm ?: 'Ученик' ?></strong> успешно создан.</p>
    <div class="modal-actions">
      <a class="btn" href="/profile/student.php?user_id=<?= $createdId ?>">
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg>
        Карточка ученика
      </a>
      <a class="btn btn-ghost" href="/add/student.php">
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Добавить ещё
      </a>
      <a class="link" href="/profile/index.php">
        <svg class="link-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="15 18 9 12 15 6"/></svg>
        На главную
      </a>
    </div>
    <button class="modal-close" id="modalClose" aria-label="Закрыть">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
</div>

<script>
// модалка
(function(){
  const modal = document.getElementById('successModal');
  const close = document.getElementById('modalClose');
  if (modal && !modal.hasAttribute('hidden')) document.body.classList.add('noscroll');
  function hide(){ modal.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }
  close?.addEventListener('click', hide);
  window.addEventListener('keydown', e=>{ if(e.key==='Escape' && modal && !modal.hasAttribute('hidden')) hide(); });
  modal?.addEventListener('click', e => { if (e.target === modal) hide(); });
})();
</script>
</body>
</html>
