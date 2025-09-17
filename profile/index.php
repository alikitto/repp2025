<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

$wd = (int)date('N');

// кто сегодня
$st = $con->prepare("
SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
FROM schedule sc
JOIN stud s ON s.user_id = sc.user_id
WHERE sc.weekday=?
ORDER BY sc.time");
$st->bind_param('i',$wd);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// должники: показывать только тех, у кого долг >= 8 уроков
// (т.е. пришёл на 8+ занятий больше, чем оплачено)
$deb = $con->query("
SELECT
  s.user_id,
  CONCAT(s.lastname,' ',s.name) AS fio,
  (COALESCE(COUNT(p.id),0)*8 - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0)) AS balance_lessons
FROM stud s
LEFT JOIN dates d ON d.user_id = s.user_id
LEFT JOIN pays  p ON p.user_id = s.user_id
GROUP BY s.user_id, fio
HAVING balance_lessons <= -8
ORDER BY balance_lessons ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Главная — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Topbar + гамбургер -->
<header class="topbar">
  <button id="menuBtn" class="hamburger" aria-label="Меню" aria-expanded="false" aria-controls="sideMenu">
    <span></span><span></span><span></span>
  </button>
  <div class="brand">Tutor CRM</div>
</header>

<!-- Левое мобильное меню -->
<nav id="sideMenu" class="sidemenu" aria-hidden="true">
  <div class="menu-header">Навигация</div>
  <a href="/profile/schedule.php">
    <span class="icon">
      <!-- calendar -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </span>
    Расписание
  </a>
  <a href="/profile/students.php">
    <span class="icon">
      <!-- users -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </span>
    Список учеников
  </a>
  <a href="/add/student.php">
    <span class="icon">
      <!-- user-plus -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
    </span>
    Добавить ученика
  </a>
  <a href="/profile/attendance_today.php">
    <span class="icon">
      <!-- check-square -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </span>
    Отметить посещения за сегодня
  </a>

  <div class="menu-footer">
    <a href="/profile/index.php" class="muted">Главная</a>
  </div>
</nav>

<!-- Затемнение при открытом меню -->
<div id="menuBackdrop" class="backdrop" hidden></div>

<div class="content">

  <div class="card">
    <h2>Кто приходит сегодня</h2>
    <?php if (!$today): ?>
      <p>Сегодня занятий нет.</p>
    <?php else: ?>
      <table class="table today">
        <thead>
          <tr>
            <th style="width:70%;">Имя</th>
            <th style="width:30%;">Время</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($today as $t): ?>
          <tr>
            <td>
              <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$t['user_id'] ?>">
                <?= htmlspecialchars($t['fio']) ?>
              </a>
            </td>
            <td class="time-cell"><?= htmlspecialchars(substr($t['time'],0,5)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Должники</h2>
    <?php if (!$deb): ?>
      <p>Должников нет 🎉</p>
    <?php else: ?>
      <table class="table debtors">
        <thead>
          <tr>
            <th>Ученик</th>
            <th style="width:160px;">Долг (уроков)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($deb as $r): ?>
          <tr>
            <td>
              <a class="link-strong" href="/profile/student.php?user_id=<?= (int)$r['user_id'] ?>">
                <?= htmlspecialchars($r['fio']) ?>
              </a>
            </td>
            <td class="debt"><?= abs((int)$r['balance_lessons']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>

<script>
  // Простое управление меню
  (function(){
    const btn = document.getElementById('menuBtn');
    const menu = document.getElementById('sideMenu');
    const backdrop = document.getElementById('menuBackdrop');

    function openMenu() {
      menu.classList.add('open');
      backdrop.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      menu.setAttribute('aria-hidden','false');
      document.body.classList.add('noscroll');
    }
    function closeMenu() {
      menu.classList.remove('open');
      backdrop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
      menu.setAttribute('aria-hidden','true');
      document.body.classList.remove('noscroll');
    }
    btn.addEventListener('click', () => {
      menu.classList.contains('open') ? closeMenu() : openMenu();
    });
    backdrop.addEventListener('click', closeMenu);
    window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closeMenu(); });
  })();
</script>
</body>
</html>
