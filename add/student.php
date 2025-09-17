<?php
require_once __DIR__ . '/../profile/_auth.php';
session_start();

// CSRF токен (совместимо с твоим csrf.php, но с фолбэком)
require_once __DIR__ . '/../common/csrf.php';
if (function_exists('csrf_token')) {
  $csrf_val = csrf_token();
} else {
  if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
  $csrf_val = $_SESSION['csrf'];
}

// дни недели для select
$days = ['Понедельник','Вторник','Среда','Четверг','Пятница','Суббота','Воскресенье'];

// тайм-слоты 09:00–20:00, шаг 30 минут
$times = [];
for ($h=9; $h<=20; $h++) {
  foreach ([0,30] as $m) {
    if ($h === 20 && $m > 0) { continue; } // не добавляем 20:30
    $times[] = sprintf('%02d:%02d', $h, $m);
  }
}
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
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
    Расписание
  </a>
  <a href="/profile/students.php">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
    Список учеников
  </a>
  <a href="/add/student.php">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg></span>
    Добавить ученика
  </a>
  <a href="/profile/attendance_today.php">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
    Отметить посещения за сегодня
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
          <label for="lastname">Фамилия</label>
          <input type="text" id="lastname" name="lastname" required>
        </div>
        <div class="form-group">
          <label for="name">Имя</label>
          <input type="text" id="name" name="name" required>
        </div>
      </div>

      <div class="form-group w-240">
        <label for="money">Оплата (AZN)</label>
        <input type="number" id="money" name="money" inputmode="decimal" step="0.01" min="0" value="0">
      </div>

      <h3 style="margin-top:16px;margin-bottom:8px;">Расписание (до 3 слотов)</h3>
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
                <select name="day<?= $i ?>">
                  <option value="">— не выбрано —</option>
                  <?php foreach ($days as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="time<?= $i ?>">
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

      <button type="submit" class="btn" style="margin-top:12px;">Создать ученика</button>
    </form>
  </div>
</div>

<script>
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
</script>
</body>
</html>
