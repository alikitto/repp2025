<?php
require_once __DIR__ . '/csrf.php';
if (!isset($active)) $active = '';
if (!isset($back)) $back = '';
if (!isset($back_warn)) $back_warn = false;
$tab = $active;
if ($tab === 'list' || $tab === 'add-student') $tab = 'students';
$nav_admin = function_exists('is_admin') && is_admin();
?>
<header class="topbar">
  <button id="menuBtn" class="hamburger" aria-label="Меню" aria-expanded="false" aria-controls="sideMenu">
    <span></span><span></span><span></span>
  </button>
  <?php if ($back !== ''): ?>
  <a class="back-btn" href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>"<?= $back_warn ? ' data-warn="1"' : '' ?>>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    Назад
  </a>
  <?php endif; ?>
  <div class="brand">Tutor CRM</div>
  <?php
    $act_items = [];
    $act_unread = 0;
    $act_max = 0;
    if (isset($con) && $con instanceof mysqli) {
        $act_items = activity_recent($con, 8);
        $act_max = activity_max_id($con);
        $act_unread = activity_unread($con, (int)($_SESSION['activity_seen'] ?? $_COOKIE['activity_seen'] ?? 0));
    }
    $act_h = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  ?>
  <div class="notify">
    <button type="button" id="notifyBtn" class="notify-btn" aria-label="Уведомления" aria-expanded="false" aria-controls="notifyPanel" data-max="<?= (int)$act_max ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      <?php if ($act_unread > 0): ?>
      <span class="notify-badge" id="notifyBadge"><?= $act_unread > 9 ? '9+' : (int)$act_unread ?></span>
      <?php endif; ?>
    </button>
    <div id="notifyPanel" class="notify-panel" hidden>
      <div class="notify-head">Последние действия</div>
      <?php if (!$act_items): ?>
        <p class="notify-empty">Пока нет действий</p>
      <?php else: ?>
        <ul class="notify-list">
          <?php foreach ($act_items as $a): ?>
          <li>
            <?php if (!empty($a['url'])): ?>
            <a href="<?= $act_h($a['url']) ?>">
            <?php else: ?>
            <div>
            <?php endif; ?>
              <span class="notify-text"><?= $act_h($a['text']) ?></span>
              <span class="notify-time"><?= $act_h(activity_time($a['created_at'])) ?></span>
            <?= !empty($a['url']) ? '</a>' : '</div>' ?>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <a class="notify-all" href="/profile/activity.php">Посмотреть все</a>
    </div>
  </div>
</header>

<nav id="sideMenu" class="sidemenu" aria-hidden="true">
  <div class="menu-header">Меню</div>
  <?php if ($nav_admin): ?>
  <a href="/profile/users.php" class="<?= $active==='users'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
    Учителя
  </a>
  <?php else: ?>
  <a href="/profile/index.php" class="<?= $active==='home'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12l9-9 9 9"/><path d="M9 21V9h6v12"/></svg></span>
    Главная
  </a>
  <a href="/profile/schedule.php" class="<?= $active==='schedule'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
    Расписание
  </a>
  <a href="/profile/list.php" class="<?= $active==='list'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
    Список учеников
  </a>
  <a href="/add/student.php" class="<?= $active==='add-student'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg></span>
    Добавить ученика
  </a>
  <a href="/profile/attendance_today.php" class="<?= $active==='attendance'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
    Посещения
  </a>
  <a href="/profile/finance.php" class="<?= $active==='finance'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20V16"/></svg></span>
    Финансы
  </a>
  <?php endif; ?>
  <a href="/profile/profile.php" class="<?= $active==='profile'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
    Профиль
  </a>
  <a href="/profile/settings.php" class="<?= $active==='settings'?'active':'' ?>">
    <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H8a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V8c.2.7.8 1.2 1.5 1.3H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1.1Z"/></svg></span>
    Настройки
  </a>
  <form method="post" action="/logout.php">
    <?= csrf_field() ?>
    <button type="submit" class="linkish">
      <span class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
      Выйти
    </button>
  </form>
</nav>
<div id="menuBackdrop" class="backdrop" hidden></div>
<?php if ($back_warn): ?>
<div id="leaveModal" class="modal" hidden>
  <div class="modal-card leave-card">
    <div class="leave-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <h3>Уйти без сохранения?</h3>
    <p class="muted">На странице есть несохранённые изменения. Если уйти, они пропадут.</p>
    <div class="modal-actions leave-actions">
      <button type="button" class="btn" id="leaveStay">Остаться</button>
      <button type="button" class="btn gray" id="leaveGo">Уйти</button>
    </div>
  </div>
</div>
<?php endif; ?>

<nav class="bottom-nav" aria-label="Основное меню">
  <?php if ($nav_admin): ?>
  <a href="/profile/users.php" class="<?= $tab==='users'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    Учителя
  </a>
  <a href="/profile/settings.php" class="<?= $tab==='settings'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H8a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V8c.2.7.8 1.2 1.5 1.3H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1.1Z"/></svg>
    Настройки
  </a>
  <a href="/profile/profile.php" class="<?= $tab==='profile'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    Профиль
  </a>
  <?php else: ?>
  <a href="/profile/index.php" class="<?= $tab==='home'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><path d="M3 12l9-9 9 9"/><path d="M9 21V9h6v12"/></svg>
    Сегодня
  </a>
  <a href="/profile/schedule.php" class="<?= $tab==='schedule'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    Расписание
  </a>
  <a href="/profile/attendance_today.php" class="<?= $tab==='attendance'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 14v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Посещения
  </a>
  <a href="/profile/list.php" class="<?= $tab==='students'?'active':'' ?>">
    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    Ученики
  </a>
  <button type="button" id="voiceBtn" class="voice-nav js-voice-open" aria-label="Голосовая оплата">
    <span class="voice-ic"><svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg></span>
    Голос
  </button>
  <button type="button" id="moreBtn" class="<?= in_array($active, ['finance','add-student','settings','activity','profile'], true)?'active':'' ?>">
    <svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
    Ещё
  </button>
  <?php endif; ?>
</nav>
<?php if (!$nav_admin): ?>
<button type="button" class="voice-fab js-voice-open" aria-label="Голосовая оплата">
  <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
</button>
<div id="voiceModal" class="modal" hidden>
  <div class="modal-card voice-card">
    <button type="button" class="modal-close" id="voiceClose" aria-label="Закрыть">✕</button>
    <h3 id="voiceTitle">Голосовая оплата</h3>
    <p class="muted" id="voiceHint">Скажите имя и сумму</p>
    <p class="voice-live" id="voiceLive" hidden></p>
    <div id="voiceResult" hidden></div>
    <div class="voice-mic-wrap">
      <button type="button" id="voiceListen" class="voice-mic-btn" aria-label="Слушать">
        <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
      </button>
    </div>
    <div class="modal-actions" id="voiceActions" hidden>
      <button type="button" class="btn gray" id="voiceCancel">Отмена</button>
      <button type="button" class="btn pay" id="voiceSave">Добавить</button>
    </div>
  </div>
</div>
<div id="voiceSuccess" class="voice-success" hidden>
  <div class="voice-success-mark">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
  </div>
  <p class="voice-success-text" id="voiceSuccessText"></p>
</div>
<?php endif; ?>

<script <?= csp_nonce_attr() ?>>
(function(){
  const btn = document.getElementById('menuBtn');
  const more = document.getElementById('moreBtn');
  const menu = document.getElementById('sideMenu');
  const backdrop = document.getElementById('menuBackdrop');
  function openMenu(){ menu.classList.add('open'); backdrop.hidden=false; btn?.setAttribute('aria-expanded','true'); menu.setAttribute('aria-hidden','false'); document.body.classList.add('noscroll'); }
  function closeMenu(){ menu.classList.remove('open'); backdrop.hidden=true; btn?.setAttribute('aria-expanded','false'); menu.setAttribute('aria-hidden','true'); document.body.classList.remove('noscroll'); }
  btn?.addEventListener('click',()=> menu.classList.contains('open')?closeMenu():openMenu());
  more?.addEventListener('click',()=> menu.classList.contains('open')?closeMenu():openMenu());
  backdrop?.addEventListener('click',closeMenu);
  function hideLeave(){ document.getElementById('leaveModal')?.setAttribute('hidden',''); }
  const nbtn = document.getElementById('notifyBtn');
  const npanel = document.getElementById('notifyPanel');
  function closeNotify(){
    if (!npanel) return;
    npanel.hidden = true;
    nbtn?.setAttribute('aria-expanded','false');
  }
  function openNotify(){
    if (!npanel) return;
    npanel.hidden = false;
    nbtn?.setAttribute('aria-expanded','true');
    document.getElementById('notifyBadge')?.remove();
    const maxId = nbtn?.dataset.max || '0';
    document.cookie = 'activity_seen=' + maxId + '; Path=/; Max-Age=31536000; SameSite=Lax';
  }
  nbtn?.addEventListener('click', e => {
    e.stopPropagation();
    npanel?.hidden ? openNotify() : closeNotify();
  });
  document.addEventListener('click', e => {
    if (!npanel || npanel.hidden) return;
    if (npanel.contains(e.target) || nbtn?.contains(e.target)) return;
    closeNotify();
  });
  const voiceModal = document.getElementById('voiceModal');
  let voiceSaved = false;
  let voicePayId = 0;
  function voiceGoCard(){
    const id = pendingVoice?.user_id;
    if (!id) { location.reload(); return; }
    let url = '/profile/student.php?user_id=' + id + '&tab=pays';
    if (voicePayId) url += '&pay=' + voicePayId;
    location.href = url;
  }
  function hideVoice(){
    if (!voiceModal) return;
    if (voiceSaved) { voiceGoCard(); return; }
    voiceModal.setAttribute('hidden','');
    document.getElementById('voiceBtn')?.classList.remove('active');
    stopVoice();
  }
  function showVoice(){
    if (!voiceModal) return;
    if (voiceSaved) { voiceGoCard(); return; }
    closeMenu();
    closeNotify();
    voiceModal.removeAttribute('hidden');
    document.getElementById('voiceBtn')?.classList.add('active');
    document.getElementById('voiceTitle').textContent = 'Голосовая оплата';
    document.getElementById('voiceHint').textContent = 'Скажите имя и сумму';
    document.getElementById('voiceLive').hidden = true;
    document.getElementById('voiceResult').hidden = true;
    document.getElementById('voiceActions').hidden = true;
    document.querySelector('.voice-card')?.classList.remove('is-confirm');
    pendingVoice = null;
  }
  document.querySelectorAll('.js-voice-open').forEach(el => el.addEventListener('click', showVoice));
  document.getElementById('voiceClose')?.addEventListener('click', hideVoice);
  voiceModal?.addEventListener('click', e => { if (e.target === voiceModal) hideVoice(); });

  const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
  const voiceCsrf = <?= json_encode($nav_admin ? '' : csrf_token()) ?>;
  let rec = null;
  let pendingVoice = null;
  function voiceAzn(n){
    const x = Number(n);
    return (Number.isInteger(x) ? String(x) : x.toFixed(2)) + ' AZN';
  }
  function renderVoiceCard(m){
    const box = document.getElementById('voiceResult');
    box.hidden = false;
    box.replaceChildren();
    const who = document.createElement('div');
    who.className = 'voice-who';
    who.textContent = m.fio;
    const grid = document.createElement('div');
    grid.className = 'voice-stats';
    function cell(val, label, extra){
      const d = document.createElement('div');
      if (extra) d.className = extra;
      const b = document.createElement('b');
      b.textContent = val;
      const s = document.createElement('span');
      s.textContent = label;
      d.append(b, s);
      return d;
    }
    const bal = Number(m.balance) || 0;
    const balCell = cell(bal > 0 ? '+'+bal : String(bal), m.balance_kind || 'баланс', 'voice-bal tone-' + (m.tone || 'zero'));
    if (m.debt_azn > 0) {
      const debt = document.createElement('span');
      debt.className = 'debt-azn';
      debt.textContent = voiceAzn(m.debt_azn);
      balCell.append(debt);
    }
    grid.append(cell(String(m.lessons), 'уроки'), cell(voiceAzn(m.amount), 'сумма'), balCell);
    box.append(who, grid);
  }
  function stopVoice(){
    try { rec?.stop(); } catch (e) {}
    document.getElementById('voiceListen')?.classList.remove('is-on');
  }
  async function parseVoice(text){
    const hint = document.getElementById('voiceHint');
    const box = document.getElementById('voiceResult');
    const actions = document.getElementById('voiceActions');
    hint.textContent = 'Ищу ученика…';
    const form = new FormData();
    form.append('csrf', voiceCsrf);
    form.append('text', text);
    const resp = await fetch('/add/voice.php', { method:'POST', body:form, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const j = await resp.json().catch(() => ({}));
    if (!resp.ok || !j.ok) {
      hint.textContent = j.error === 'empty' ? 'Не расслышала. Повторите.' : 'Не удалось распознать.';
      return;
    }
    const list = j.matches || [];
    box.hidden = false;
    actions.hidden = true;
    pendingVoice = null;
    document.getElementById('voiceLive').hidden = true;
    document.querySelector('.voice-card')?.classList.remove('is-confirm');
    function confirmMatch(m){
      pendingVoice = m;
      hint.textContent = 'Проверьте и добавьте';
      document.querySelector('.voice-card')?.classList.add('is-confirm');
      renderVoiceCard(m);
      actions.hidden = false;
    }
    if (!list.length) {
      hint.textContent = j.name ? 'Ученик «' + j.name + '» не найден' : 'Скажите имя и сумму';
      box.replaceChildren();
      return;
    }
    if (list.length === 1) {
      confirmMatch(list[0]);
      return;
    }
    hint.textContent = 'Кто из учеников?';
    box.replaceChildren();
    list.forEach(m => {
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'voice-pick';
      const nm = document.createElement('b');
      nm.textContent = m.fio;
      const meta = document.createElement('span');
      const bal = Number(m.balance) || 0;
      meta.textContent = (m.balance_kind || 'баланс') + ' ' + (bal > 0 ? '+'+bal : bal) + ' · ' + voiceAzn(m.amount);
      pick.append(nm, meta);
      pick.addEventListener('click', () => confirmMatch(m));
      box.append(pick);
    });
  }
  document.getElementById('voiceListen')?.addEventListener('click', () => {
    const live = document.getElementById('voiceLive');
    const hint = document.getElementById('voiceHint');
    const btn = document.getElementById('voiceListen');
    if (!window.isSecureContext) {
      hint.textContent = 'Голос работает только по HTTPS';
      return;
    }
    if (!Rec) {
      hint.textContent = 'Голос недоступен в этом браузере';
      return;
    }
    if (btn.classList.contains('is-on')) { stopVoice(); return; }
    rec = new Rec();
    rec.lang = 'ru-RU';
    rec.interimResults = true;
    rec.maxAlternatives = 5;
    rec.continuous = false;
    let recText = '';
    function altText(result){
      let best = result[0].transcript;
      let score = 0;
      for (let i = 0; i < result.length; i++) {
        const t = result[i].transcript;
        const nums = t.match(/\d+/g);
        const n = nums ? Math.max(...nums.map(Number)) : 0;
        if (n > score) { best = t; score = n; }
      }
      return best;
    }
    rec.onstart = () => { btn.classList.add('is-on'); hint.textContent = 'Слушаю…'; live.hidden = false; live.textContent = ''; recText = ''; };
    rec.onresult = e => {
      let out = '';
      for (let i = 0; i < e.results.length; i++) out += altText(e.results[i]) + ' ';
      recText = out.replace(/\s+/g, ' ').trim();
      live.textContent = recText;
    };
    rec.onerror = ev => {
      const map = { 'not-allowed': 'Разрешите доступ к микрофону', 'no-speech': 'Ничего не услышала. Ещё раз?', 'network': 'Нет сети для распознавания' };
      if (ev.error !== 'aborted') hint.textContent = map[ev.error] || 'Не удалось слушать';
      if (!recText) recText = '';
    };
    rec.onend = () => {
      btn.classList.remove('is-on');
      const t = recText.trim();
      if (t) parseVoice(t).catch(() => { hint.textContent = 'Не удалось распознать.'; });
    };
    try { rec.start(); } catch (e) { hint.textContent = 'Не удалось включить микрофон'; }
  });
  document.getElementById('voiceCancel')?.addEventListener('click', hideVoice);
  document.getElementById('voiceSave')?.addEventListener('click', async () => {
    if (!pendingVoice) return;
    const hint = document.getElementById('voiceHint');
    const saveBtn = document.getElementById('voiceSave');
    saveBtn.disabled = true;
    const form = new FormData();
    form.append('csrf', voiceCsrf);
    form.append('user_id', String(pendingVoice.user_id));
    form.append('lessons', String(pendingVoice.lessons));
    form.append('amount', String(pendingVoice.amount));
    form.append('voice', '1');
    try {
      const resp = await fetch('/add/pays.php', { method:'POST', body:form, headers:{'X-Requested-With':'XMLHttpRequest'} });
      const j = await resp.json().catch(() => ({}));
      if (!resp.ok || !j.ok) throw new Error();
      voiceSaved = true;
      voicePayId = Number(j.id) || 0;
      const m = pendingVoice;
      const x = Number(m.amount);
      const sum = (Number.isInteger(x) ? String(x) : x.toFixed(2)) + ' AZN';
      voiceModal.setAttribute('hidden','');
      const ok = document.getElementById('voiceSuccess');
      document.getElementById('voiceSuccessText').textContent = 'Успешно добавлена оплата для ученика ' + m.fio + ', сумма ' + sum;
      ok.hidden = false;
      setTimeout(voiceGoCard, 1700);
    } catch (e) {
      hint.textContent = 'Не удалось сохранить';
      saveBtn.disabled = false;
    }
  });

  window.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeMenu(); closeNotify(); hideLeave(); hideVoice(); } });

  function initLeave(){
    const back = document.querySelector('.back-btn');
    const leave = document.getElementById('leaveModal');
    if (!back || !back.dataset.warn) return;
    const form = document.querySelector('form');
    function snap(f){
      return [...f.elements].filter(el => el.name && !el.disabled).map(el => {
        if (el.type === 'checkbox' || el.type === 'radio') return el.name + '=' + (el.checked ? '1' : '0');
        return el.name + '=' + el.value;
      }).join('\n');
    }
    const initial = form ? snap(form) : '';
    let dirty = false;
    form?.addEventListener('input', () => { dirty = true; });
    form?.addEventListener('change', () => { dirty = true; });
    back.addEventListener('click', e => {
      if (!dirty && !(form && snap(form) !== initial)) return;
      e.preventDefault();
      leave?.removeAttribute('hidden');
    });
    document.getElementById('leaveStay')?.addEventListener('click', hideLeave);
    document.getElementById('leaveGo')?.addEventListener('click', () => { location.href = back.getAttribute('href'); });
    leave?.addEventListener('click', e => { if (e.target === leave) hideLeave(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initLeave);
  else initLeave();
})();
</script>
