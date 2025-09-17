<?php
require_once __DIR__ . '/../profile/_auth.php';

// безопасный запуск сессии (без Notice)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../common/csrf.php';
$csrf_val = function_exists('csrf_token')
  ? csrf_token()
  : (isset($_SESSION['csrf']) ? $_SESSION['csrf'] : ($_SESSION['csrf']=bin2hex(random_bytes(32))));

$days = ['Понедельник','Вторник','Среда','Четверг','Пятница','Суббота','Воскресенье'];

// Тайм-слоты 09:00–20:00 с шагом 30 минут
$times = [];
for ($h=9; $h<=20; $h++) {
  foreach ([0,30] as $m) {
    if ($h === 20 && $m > 0) continue; // 20:30 не добавляем
    $times[] = sprintf('%02d:%02d', $h, $m);
  }
}

// Данные для всплывающего окна успеха (после редиректа из save.php)
$created   = isset($_GET['created']) && $_GET['created'] === '1';
$createdId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$createdNm = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : '';
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

<header class="topbar">
  <button id="menuBtn" class="hamburger" aria-label="Меню" aria-expanded="false" aria-controls="sideMenu">
    <span></span><span></span><span></span>
  </button>
  <div class="brand">Tutor CRM</div>
</header>

<nav id="sideMenu" class="sidemenu" aria-hidden="true">
  <div class="menu-header">Навигация</div>
  <a href="/profile/schedule.php">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </span> Расписание
  </a>
  <a href="/profile/students.php">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </span> Список учеников
  </a>
  <a class="active" href="/add/student.php">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
    </span> Добавить ученика
  </a>
  <a href="/profile/attendance_today.php">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </span> Отметить посещения за сегодня
  </a>
  <div class="menu-footer"><a class="muted" href="/profile/index.php">Главная</a></div>
</nav>
<div id="menuBackdrop" class="backdrop" hidden></div>

<div class="content">
  <div class="card">
    <h2>Добавить ученика</h2>

    <form action="/add/save.php" method="post" class="form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf_val) ?>">

      <div class="grid-2">
        <div class="form-group">
          <label for="name">Имя</label>
          <input class="input" type="text" id="name" name="name" required>
        </div>

        <div class="form-group">
          <label for="money">Оплата (AZN)</label>
          <input class="input" type="number" id="money" name="money" inputmode="decimal" step="0.01" min="0" value="0">
        </div>
      </div>

      <h3 style="margin:16px 0 8px;">Расписание (до 3 слотов)</h3>
      <table class="table today schedule-table">
        <thead>
          <tr>
            <th>День недели</th>
            <th>Время</th>
          </tr>
        </thead>
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
        <!-- plus icon -->
        <svg class="btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Создать ученика
      </button>
    </form>
  </div>
</div>

<!-- Success modal -->
<div id="successModal" class="modal" <?php if(!$created) echo 'hidden'; ?>>
  <div class="modal-card">
    <div class="modal-icon success">
      <!-- check-circle -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M9 12l2 2l4-4"></path>
      </svg>
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
  // меню
  (function(){
    const btn = document.getElementById('menuBtn');
    const menu = document.getElementById('sideMenu');
    const backdrop = document.getElementById('menuBackdrop');
    function openMenu(){ menu.classList.add('open'); backdrop.hidden=false; btn.setAttribute('aria-expanded','true'); menu.setAttribute('aria-hidden','false'); document.body.classList.add('noscroll'); }
    function closeMenu(){ menu.classList.remove('open'); backdrop.hidden=true; btn.setAttribute('aria-expanded','false'); menu.setAttribute('aria-hidden','true'); document.body.classList.remove('noscroll'); }
    btn.addEventListener('click',()=> menu.classList.contains('open')?closeMenu():openMenu());
    backdrop.addEventListener('click',closeMenu);
    window.addEventListener('keydown',e=>{ if(e.key==='Escape') closeMenu(); });
  })();

  // модалка
  (function(){
    const modal = document.getElementById('successModal');
    const close = document.getElementById('modalClose');
    if (modal && !modal.hasAttribute('hidden')) {
      document.body.classList.add('noscroll');
    }
    function hide(){ modal.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }
    if (close) close.addEventListener('click', hide);
    window.addEventListener('keydown', e=>{ if(e.key==='Escape' && modal && !modal.hasAttribute('hidden')) hide(); });
    modal && modal.addEventListener('click', e => { if (e.target === modal) hide(); });
  })();
</script>
</body>
</html>
