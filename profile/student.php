<?php
// /profile/student.php — карточка ученика (адаптив, без лишних полей)
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); echo "Bad user_id"; exit; }

// Ученик
$st = $con->prepare("SELECT user_id, lastname, name, klass, COALESCE(money,0) AS money FROM stud WHERE user_id = ?");
$st->bind_param('i', $user_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();
if (!$student) { http_response_code(404); echo "Ученика не найдено"; exit; }

// Счётчики
$st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND visited=1");
$st->bind_param('i', $user_id);
$st->execute();
$st->bind_result($visits_count);
$st->fetch();
$st->close();

$st = $con->prepare("SELECT COALESCE(SUM(lessons),0) AS paid_lessons, COUNT(*) AS pays_count FROM pays WHERE user_id=?");
$st->bind_param('i', $user_id);
$st->execute();
$st->bind_result($paid_lessons, $pays_count);
$st->fetch();
$st->close();

$balance_lessons = (int)$paid_lessons - (int)$visits_count; // >0 — предоплата, <0 — долг

// Фильтр посещений
$v = $_GET['v'] ?? 'all';
$v = in_array($v, ['all','1','0'], true) ? $v : 'all';

// Посещения (внимание: таблица dates -> поля dates_id, dates)
$sqlVisits = "SELECT dates_id, user_id, `dates`, COALESCE(visited,0) AS visited
              FROM dates WHERE user_id=? ";
if ($v === '1')      { $sqlVisits .= "AND visited=1 "; }
elseif ($v === '0')  { $sqlVisits .= "AND visited=0 "; }
$sqlVisits .= "ORDER BY `dates` DESC, dates_id DESC";
$st = $con->prepare($sqlVisits);
$st->bind_param('i', $user_id);
$st->execute();
$visits = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// Оплаты (pays: id, date, lessons, amount)
$st = $con->prepare("SELECT id, user_id, `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC");
$st->bind_param('i', $user_id);
$st->execute();
$pays = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$csrfToken = function_exists('csrf_token') ? csrf_token() : '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_amount($a){ return number_format((float)$a, 2, '.', ''); }
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= h($student['lastname'] . ' ' . $student['name']) ?> — Карточка ученика</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    :root{
      --gap:12px;
      --btn-pad:8px 12px;
      --btn-radius:10px;
    }
    .page-head { display:flex; gap:var(--gap); align-items:flex-start; flex-wrap:wrap; }
    .page-head h2{ margin:0; line-height:1.15; }
    .muted { color:var(--muted); font-size:13px; }
    .badges { display:flex; gap:10px; flex-wrap:wrap; }
    .badge { background:#f5f7fb; border:1px solid var(--border); border-radius:10px; padding:6px 10px; font-size:14px; }
    .badge.negative{ background:#fff3f3; border-color:#ffd4d4; }
    .badge.positive{ background:#f3fff4; border-color:#cfe9d4; }
    .toolbar { display:flex; gap:8px; margin-left:auto; align-items:flex-start; }
    .btn { padding:var(--btn-pad); border-radius:var(--btn-radius); border:1px solid var(--border); background:#fff; cursor:pointer; line-height:1; }
    .btn.primary { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
    .btn.gray { background:#f1f3f5; color:#333; border-color:#e1e5ea; }
    .btn.sm { padding:6px 10px; border-radius:8px; font-size:14px; }
    .section { margin-top:16px; }
    .tabs { display:flex; gap:8px; margin-bottom:10px; }
    .tab { padding:6px 10px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; text-decoration:none; color:inherit; }
    .tab.active { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
    .table { width:100%; border-collapse:separate; border-spacing:0; }
    .table th, .table td { padding:10px 12px; }
    .table thead th { background:#eef3f9; }
    .td-actions { display:flex; gap:8px; }
    .icon-btn { padding:6px 10px; border:1px solid var(--border); background:#fff; border-radius:8px; cursor:pointer; }
    .icon-btn.danger { border-color:#ffc9c9; background:#fff5f5; color:#b30000; }
    /* Модалки */
    .modal { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.4); z-index:1000; }
    .modal[hidden]{ display:none; }
    .modal-card { background:#fff; padding:18px; border-radius:12px; width:420px; max-width:95vw; box-shadow:0 10px 30px rgba(0,0,0,0.2); position:relative; }
    .modal-close { position:absolute; right:10px; top:8px; border:none; background:transparent; font-size:18px; cursor:pointer; }
    .form .input, .form input[type="date"], .form input[type="number"], .form input[type="text"]{ width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box; }
    .actions { display:flex; gap:8px; margin-top:12px; }
    /* Успех-тост */
    .toast { position:fixed; left:50%; transform:translateX(-50%); bottom:18px; background:#0a5fb0; color:#fff; padding:10px 14px; border-radius:10px; display:none; z-index:1100; }
    .toast.show{ display:block; }

    /* Мобайл */
    @media (max-width: 680px){
      .page-head { flex-direction:column; }
      .toolbar { width:100%; flex-direction:column; gap:8px; }
      .toolbar .btn { width:100%; }
      .btn.sm { padding:8px 12px; } /* чуть крупнее тап-таргет */
      .table th, .table td { padding:10px; font-size:14px; }
      .tabs { gap:6px; }
      .tab { padding:6px 8px; }
    }
  </style>
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <div class="page-head">
      <div>
        <h2><?= h($student['lastname'] . ' ' . $student['name']) ?></h2>
        <div class="muted">Класс: <?= h($student['klass'] ?: '—') ?> • Цена за урок: <?= fmt_amount($student['money']) ?> AZN</div>
      </div>

      <div class="badges">
        <?php if ($balance_lessons < 0): ?>
          <div class="badge negative">Долг: <?= abs($balance_lessons) ?></div>
        <?php elseif ($balance_lessons > 0): ?>
          <div class="badge positive">Баланс: <?= $balance_lessons ?></div>
        <?php else: ?>
          <div class="badge">Баланс: 0</div>
        <?php endif; ?>
        <div class="badge">Посещений: <?= (int)$visits_count ?></div>
        <div class="badge">Оплат: <?= (int)$pays_count ?></div>
      </div>

      <div class="toolbar">
        <a class="btn gray sm" href="/profile/student_edit.php?user_id=<?= (int)$student['user_id'] ?>">✎ Редактировать</a>
        <button class="btn primary sm" id="btnAddVisit">+ Посещение</button>
        <button class="btn primary sm" id="btnAddPay">+ Оплата</button>
      </div>
    </div>
  </div>

  <!-- Посещения -->
  <div class="card section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
      <h3 style="margin:0;">Посещения</h3>
      <div class="tabs" style="margin-left:auto;">
        <?php
          $base = '/profile/student.php?user_id='.(int)$user_id;
          $mk = function($label, $val, $cur) use ($base){ $href = $base . ($val==='all' ? '' : '&v='.$val); $active = $cur===$val ? 'active' : ''; return '<a class="tab '.$active.'" href="'.h($href).'">'.h($label).'</a>'; };
          echo $mk('Все', 'all', $v);
          echo $mk('Пришёл', '1', $v);
          echo $mk('Не пришёл', '0', $v);
        ?>
      </div>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th style="width:140px;">Дата</th>
          <th style="width:140px;">Статус</th>
          <th style="width:140px;">Действие</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$visits): ?>
          <tr><td colspan="3">Пока нет записей.</td></tr>
        <?php else: foreach ($visits as $row): ?>
          <tr>
            <td><?= h($row['dates']) ?></td>
            <td><?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?></td>
            <td class="td-actions">
              <button class="icon-btn danger js-del-visit"
                      data-id="<?= (int)$row['dates_id'] ?>"
                      data-date="<?= h($row['dates']) ?>"
                      data-status="<?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?>">
                Удалить
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Оплаты -->
  <div class="card section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
      <h3 style="margin:0;">Оплаты</h3>
    </div>

    <?php
      $sum_lessons = 0; $sum_amount = 0.0;
      foreach ($pays as $p){ $sum_lessons += (int)$p['lessons']; $sum_amount += (float)$p['amount']; }
    ?>

    <table class="table">
      <thead>
        <tr>
          <th style="width:140px;">Дата</th>
          <th style="width:120px;">Уроков</th>
          <th style="width:140px;">Сумма, AZN</th>
          <th style="width:140px;">Действие</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$pays): ?>
          <tr><td colspan="4">Пока оплат нет.</td></tr>
        <?php else: foreach ($pays as $p): ?>
          <tr>
            <td><?= h($p['date']) ?></td>
            <td><?= (int)$p['lessons'] ?></td>
            <td><?= fmt_amount($p['amount']) ?></td>
            <td class="td-actions">
              <button class="icon-btn danger js-del-pay"
                      data-id="<?= (int)$p['id'] ?>"
                      data-date="<?= h($p['date']) ?>"
                      data-lessons="<?= (int)$p['lessons'] ?>"
                      data-amount="<?= fmt_amount($p['amount']) ?>">
                Удалить
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($pays): ?>
      <tfoot>
        <tr>
          <td>Итого</td>
          <td><?= (int)$sum_lessons ?></td>
          <td><?= fmt_amount($sum_amount) ?></td>
          <td></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Модалка: Добавить посещение -->
<div id="modalVisit" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-visit" aria-label="Закрыть">✕</button>
    <h3>Добавить посещение</h3>
    <div class="muted" style="margin-bottom:8px;"><?= h($student['lastname'].' '.$student['name']) ?></div>
    <form id="visitForm" class="form">
      <input type="hidden" name="user_id" value="<?= (int)$student['user_id'] ?>">
      <?php if ($csrfToken): ?><input type="hidden" name="csrf" value="<?= h($csrfToken) ?>"><?php endif; ?>

      <label>Дата</label>
      <input type="date" id="visit_date" class="input" required>

      <label style="margin-top:8px;">Статус</label>
      <div>
        <label><input type="checkbox" id="visit_visited" checked> Пришёл</label>
      </div>

      <div class="actions">
        <button type="button" id="visitSubmit" class="btn primary sm">Сохранить</button>
        <button type="button" class="btn gray sm js-close-visit">Отмена</button>
      </div>
    </form>
  </div>
</div>

<!-- Модалка: Добавить оплату -->
<div id="modalPay" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-pay" aria-label="Закрыть">✕</button>
    <h3>Добавить оплату</h3>
    <div class="muted" style="margin-bottom:8px;"><?= h($student['lastname'].' '.$student['name']) ?></div>
    <form id="payForm" class="form">
      <input type="hidden" name="user_id" id="pay_user_id" value="<?= (int)$student['user_id'] ?>">
      <?php if ($csrfToken): ?><input type="hidden" name="csrf" value="<?= h($csrfToken) ?>"><?php endif; ?>

      <label>Дата оплаты</label>
      <input type="date" name="date" id="pay_date" class="input" required>

      <label style="margin-top:8px;">Кол-во уроков</label>
      <input type="number" name="lessons" id="pay_lessons" class="input" value="8" min="1" required>

      <label style="margin-top:8px;">Сумма (AZN)</label>
      <input type="text" id="pay_amount" class="input" readonly>
      <div class="muted">Сумма считается автоматически из ставки (money) × уроков. На сервер отправляем только lessons — сумма пересчитывается на сервере.</div>

      <div class="actions">
        <button type="button" id="paySubmit" class="btn primary sm">Сохранить</button>
        <button type="button" class="btn gray sm js-close-pay">Отмена</button>
      </div>
    </form>
  </div>
</div>

<!-- Модалка: Подтверждение удаления -->
<div id="modalConfirm" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-confirm" aria-label="Закрыть">✕</button>
    <h3 id="confirmTitle">Подтверждение</h3>
    <p id="confirmText" class="muted"></p>
    <div class="actions">
      <button type="button" id="confirmYes" class="btn primary sm">Удалить</button>
      <button type="button" class="btn gray sm js-close-confirm">Отмена</button>
    </div>
  </div>
</div>

<div id="toast" class="toast">Успешно</div>

<script>
(function(){
  const modalVisit = document.getElementById('modalVisit');
  const modalPay = document.getElementById('modalPay');
  const modalConfirm = document.getElementById('modalConfirm');
  const confirmTitle = document.getElementById('confirmTitle');
  const confirmText = document.getElementById('confirmText');
  const confirmYes = document.getElementById('confirmYes');
  const toast = document.getElementById('toast');

  function showModal(m){ m.removeAttribute('hidden'); document.body.classList.add('noscroll'); }
  function hideModal(m){ m.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }
  function showToast(msg){ toast.textContent = msg || 'Успешно'; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1300); }
  function todayISO(){ const d=new Date(); d.setHours(0,0,0,0); return d.toISOString().slice(0,10); }
  const money = <?= json_encode((float)$student['money']) ?>;
  const uid = <?= (int)$user_id ?>;
  const csrf = <?= json_encode($csrfToken ?: '') ?>;

  // Открытие модалок
  document.getElementById('btnAddVisit').addEventListener('click', ()=>{
    document.getElementById('visit_date').value = todayISO();
    document.getElementById('visit_visited').checked = true;
    showModal(modalVisit);
  });
  document.getElementById('btnAddPay').addEventListener('click', ()=>{
    document.getElementById('pay_date').value = todayISO();
    document.getElementById('pay_lessons').value = 8;
    document.getElementById('pay_amount').value = (money * 8).toFixed(2);
    showModal(modalPay);
  });

  // Закрытие модалок
  document.querySelectorAll('.js-close-visit').forEach(b=>b.addEventListener('click', ()=> hideModal(modalVisit)));
  document.querySelectorAll('.js-close-pay').forEach(b=>b.addEventListener('click', ()=> hideModal(modalPay)));
  document.querySelectorAll('.js-close-confirm').forEach(b=>b.addEventListener('click', ()=> hideModal(modalConfirm)));
  document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', (e)=>{ if (e.target === m) hideModal(m); }));
  window.addEventListener('keydown', (e)=>{ if (e.key==='Escape'){ hideModal(modalVisit); hideModal(modalPay); hideModal(modalConfirm);} });

  // Пересчёт суммы оплаты (визуально)
  const payLessons = document.getElementById('pay_lessons');
  const payAmount = document.getElementById('pay_amount');
  payLessons.addEventListener('input', ()=>{
    const lessons = parseInt(payLessons.value || '0', 10) || 0;
    payAmount.value = (money * lessons).toFixed(2);
  });

  // Добавить посещение
  document.getElementById('visitSubmit').addEventListener('click', async ()=>{
    const dateVal = document.getElementById('visit_date').value || todayISO();
    const visited = document.getElementById('visit_visited').checked ? '1' : '';

    const form = new FormData();
    form.append('dates', dateVal); // backend ждёт либо dates, либо dataa
    form.append('dataa', dateVal);
    if (visited) form.append('visited', '1');
    if (csrf) form.append('csrf', csrf);

    try {
      const resp = await fetch('/add/dates.php?user_id='+uid, { method:'POST', body:form, credentials:'same-origin' });
      if (!resp.ok) throw new Error('HTTP '+resp.status);
      hideModal(modalVisit);
      showToast('Посещение добавлено');
      setTimeout(()=>location.reload(), 900);
    } catch(e){ alert('Ошибка при добавлении посещения: '+e.message); }
  });

  // Добавить оплату (amount НЕ отправляем — сервер считает из stud.money × lessons)
  document.getElementById('paySubmit').addEventListener('click', async ()=>{
    const date = document.getElementById('pay_date').value || todayISO();
    const lessons = parseInt(document.getElementById('pay_lessons').value || '8', 10) || 8;

    const form = new FormData();
    form.append('user_id', uid);
    form.append('date', date);
    form.append('lessons', lessons);
    form.append('amount', ''); // пусто -> сервер сам посчитает
    if (csrf) form.append('csrf', csrf);

    try {
      const resp = await fetch('/add/pays.php', { method:'POST', body:form, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
      const j = await resp.json().catch(()=>null);
      if (!resp.ok || !j || j.ok !== true) throw new Error((j && j.error) ? j.error : ('HTTP '+resp.status));
      hideModal(modalPay);
      showToast('Оплата добавлена');
      setTimeout(()=>location.reload(), 900);
    } catch(e){ alert('Ошибка при добавлении оплаты: '+e.message); }
  });

  // --- Удаление (модалка подтверждения)
  let pendingDelete = null; // { type:'visit'|'pay', id:number }
  document.querySelectorAll('.js-del-visit').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const date = btn.dataset.date, status = btn.dataset.status;
      pendingDelete = { type:'visit', id: btn.dataset.id };
      confirmTitle.textContent = 'Удалить посещение';
      confirmText.textContent = `Удалить посещение от ${date} (${status})?`;
      showModal(modalConfirm);
    });
  });
  document.querySelectorAll('.js-del-pay').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const date = btn.dataset.date, lessons = btn.dataset.lessons, amount = btn.dataset.amount;
      pendingDelete = { type:'pay', id: btn.dataset.id };
      confirmTitle.textContent = 'Удалить оплату';
      confirmText.textContent = `Удалить оплату от ${date} (уроков: ${lessons}, сумма: ${amount} AZN)?`;
      showModal(modalConfirm);
    });
  });

  confirmYes.addEventListener('click', async ()=>{
    if (!pendingDelete) return;
    try {
      if (pendingDelete.type === 'visit') {
        const form = new FormData(); if (csrf) form.append('csrf', csrf);
        const resp = await fetch('/delete/dates.php?id='+encodeURIComponent(pendingDelete.id), { method:'POST', body:form, credentials:'same-origin' });
        if (!resp.ok) throw new Error('HTTP '+resp.status);
      } else {
        const form = new FormData(); if (csrf) form.append('csrf', csrf);
        const resp = await fetch('/delete/pays.php?id='+encodeURIComponent(pendingDelete.id), { method:'POST', body:form, credentials:'same-origin' });
        if (!resp.ok) throw new Error('HTTP '+resp.status);
      }
      hideModal(modalConfirm);
      showToast('Удалено');
      setTimeout(()=>location.reload(), 800);
    } catch(e){ alert('Ошибка удаления: '+e.message); }
  });

})();
</script>
</body>
</html>
