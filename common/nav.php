<?php
// $active можно передать из страницы: 'home' | 'schedule' | 'students' | 'add-student' | 'attendance'
if (!isset($active)) $active = '';
?>
<header class="topbar">
  <button id="menuBtn" class="hamburger" aria-label="Меню" aria-expanded="false" aria-controls="sideMenu">
    <span></span><span></span><span></span>
  </button>
  <div class="brand">Tutor CRM</div>
</header>

<nav id="sideMenu" class="sidemenu" aria-hidden="true">
  <div class="menu-header">Навигация</div>

  <a href="/profile/index.php" class="<?= $active==='home'?'active':'' ?>">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12l9-9 9 9"/><path d="M9 21V9h6v12"/></svg>
    </span> Главная
  </a>

  <a href="/profile/schedule.php" class="<?= $active==='schedule'?'active':'' ?>">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </span> Расписание
  </a>

  <a href="/profile/list.php" class="<?= $active==='list'?'active':'' ?>">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </span> Список учеников
  </a>

  <a href="/add/student.php" class="<?= $active==='add-student'?'active':'' ?>">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
    </span> Добавить ученика
  </a>

  <a href="/profile/attendance_today.php" class="<?= $active==='attendance'?'active':'' ?>">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </span> Отметить посещения за сегодня
  </a>

    <a href="/profile/finance.php" class="<?= $active==='finance'?'active':'' ?>">
    <span class="icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    </span> Финансы
  </a>


</nav>
<div id="menuBackdrop" class="backdrop" hidden></div>

<script>
// простое управление меню (общий для всех страниц)
(function(){
  const btn = document.getElementById('menuBtn');
  const menu = document.getElementById('sideMenu');
  const backdrop = document.getElementById('menuBackdrop');
  function openMenu(){ menu.classList.add('open'); backdrop.hidden=false; btn.setAttribute('aria-expanded','true'); menu.setAttribute('aria-hidden','false'); document.body.classList.add('noscroll'); }
  function closeMenu(){ menu.classList.remove('open'); backdrop.hidden=true; btn.setAttribute('aria-expanded','false'); menu.setAttribute('aria-hidden','true'); document.body.classList.remove('noscroll'); }
  btn?.addEventListener('click',()=> menu.classList.contains('open')?closeMenu():openMenu());
  backdrop?.addEventListener('click',closeMenu);
  window.addEventListener('keydown',e=>{ if(e.key==='Escape') closeMenu(); });
})();
</script>
