<?php
// /profile/student.php — карточка ученика
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); echo "Bad user_id"; exit; }

// Читаем ученика
$st = $con->prepare("SELECT user_id, lastname, name, klass, COALESCE(money,0) AS money FROM stud WHERE user_id = ?");
$st->bind_param('i', $user_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();

if (!$student) { http_response_code(404); echo "Ученика не найдено"; exit; }

// Подсчёты: посещения, оплаты, баланс
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

// Фильтр посещений: all / 1 / 0
$v = $_GET['v'] ?? 'all';
$v = in_array($v, ['all','1','0'], true) ? $v : 'all';

// Получаем посещения (ИСПРАВЛЕНО: колонка `dates`, есть ключ `dates_id`)
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

// Получаем оплаты (у pays поле даты называется `date`)
$st = $con->prepare("SELECT id, user_id, `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC");
$st->bind_param('i', $user_id);
$st->execute();
$pays = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// CSRF
$csrfToken = function_exists('csrf_token') ? csrf_token() : '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_amount($a){ return number_format((float)$a, 2, '.', ''); }
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($student['lastname'] . ' ' . $student['name']) ?> — Карточка ученика</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    .page-head { display:flex; gap:16px; align-items:center; }
    .page-head h2{ margin:0; }
    .badges { display:flex; gap:10px; flex-wrap:wrap; }
    .badge { background:#f5f7fb; border:1px solid var(--border); border-radius:10px; padding:8px 12px; font-size:14px; }
    .badge.negative{ background:#fff3f3; border-color:#ffd4d4; }
    .badge.positive{ background:#f3fff4; border-color:#cfe9d4; }
    .toolbar { display:flex; gap:8px; margin-left:auto; }
    .btn { padding:8px 12px; border-radius:8px; border:1px solid var(--border); background:#fff; cursor:pointer; }
    .btn.primary { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
    .section { margin-top:16px; }
    .tabs { display:flex; gap:8px; margin-bottom:10px; }
    .tab { padding:6px 10px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; text-decoration:none; color:inherit; }
    .tab.active { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
    .table tfoot td { font-weight:600; }
    .modal { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.4); z-index:1000; }
    .modal[hidden]{ display:none; }
    .modal-card { background:#fff; padding:18px; border-radius:10px; width:420px; max-width:95%; box-shadow:0 10px 30px rgba(0,0,0,0.2); position:relative; }
    .modal-close { position:absolute; right:10px; top:8px; border:none; background:transparent; font-size:18px; cursor:pointer; }
    .form .input, .form input[type="date"], .form input[type="number"], .form input[type="text"]{ width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box; }
    .confirm-row{ display:flex; align-items:center; gap:8px; margin-top:8px; }
    .actions { display:flex; gap:8px; margin-top:12px; }
    .muted { color:var(--muted); font-size:13px; }
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
        <a class="btn" href="/profile/list.php">← К списку</a>
        <a class="btn" href="/profile/student_edit.php?user_id=<?= (int)$student['user_id'] ?>">✎ Редактировать</a>
        <button class="btn primary" id="btnAddVisit">+ Посещение</button>
        <button class="btn primary" id="btnAddPay">+ Оплата</button>
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
          <th>Комментарий</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$visits): ?>
          <tr><td colspan="3">Пока нет записей.</td></tr>
        <?php else: foreach ($visits as $row): ?>
          <tr>
            <td><?= h($row['dates']) ?></td>
            <td><?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?></td>
            <td class="muted">—</td>
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
          <th style="width:160px;">Уроков</th>
          <th style="width:160px;">Сумма, AZN</th>
          <th>Комментарий</th>
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
            <td class="muted">—</td>
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
        <label><input type="checkbox" name="visited" id="visit_visited" checked> Пришёл</label>
      </div>

      <div class="confirm-row">
        <input type="checkbox" id="visit_confirm">
        <label for="visit_confirm">Подтверждаю добавление посещения</label>
      </div>

      <div class="actions">
        <button type="button" id="visitSubmit" class="btn primary" disabled>Сохранить</button>
        <button type="button" class="btn js-close-visit">Отмена</button>
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
      <input type="text" name="amount" id="pay_amount" class="input" readonly>

      <div class="confirm-row">
        <input type="checkbox" id="pay_confirm">
        <label for="pay_confirm">Подтверждаю добавление оплаты</label>
      </div>

      <div class="actions">
        <button type="button" id="paySubmit" class="btn primary" disabled>Сохранить</button>
        <button type="button" class="btn js-close-pay">Отмена</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const modalVisit = document.getElementById('modalVisit');
  const modalPay = document.getElementById('modalPay');
  function showModal(m){ m.removeAttribute('hidden'); document.body.classList.add('noscroll'); }
  function hideModal(m){ m.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }
  function todayISO(){ const d=new Date(); d.setHours(0,0,0,0); return d.toISOString().slice(0,10); }
  const money = <?= json_encode((float)$student['money']) ?>;

  document.getElementById('btnAddVisit').addEventListener('click', ()=>{
    document.getElementById('visit_date').value = todayISO();
    document.getElementById('visit_visited').checked = true;
    document.getElementById('visit_confirm').checked = false;
    document.getElementById('visitSubmit').disabled = true;
    showModal(modalVisit);
  });
  document.getElementById('btnAddPay').addEventListener('click', ()=>{
    document.getElementById('pay_date').value = todayISO();
    document.getElementById('pay_lessons').value = 8;
    document.getElementById('pay_amount').value = (money * 8).toFixed(2);
    document.getElementById('pay_confirm').checked = false;
    document.getElementById('paySubmit').disabled = true;
    showModal(modalPay);
  });

  document.querySelectorAll('.js-close-visit').forEach(b=>b.addEventListener('click', ()=> hideModal(modalVisit)));
  document.querySelectorAll('.js-close-pay').forEach(b=>b.addEventListener('click', ()=> hideModal(modalPay)));
  document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', (e) => { if (e.target === m) hideModal(m); });
  });
  window.addEventListener('keydown', (e) => { if (e.key === 'Escape'){ hideModal(modalVisit); hideModal(modalPay);} });

  document.getElementById('visit_confirm').addEventListener('change', e=>{
    document.getElementById('visitSubmit').disabled = !e.target.checked;
  });
  document.getElementById('pay_confirm').addEventListener('change', e=>{
    document.getElementById('paySubmit').disabled = !e.target.checked;
  });

  const payLessons = document.getElementById('pay_lessons');
  const payAmount = document.getElementById('pay_amount');
  payLessons.addEventListener('input', ()=>{
    const lessons = parseInt(payLessons.value || '0', 10) || 0;
    payAmount.value = (money * lessons).toFixed(2);
  });

  // Отправка посещения: добавляю И 'dates', И 'dataa' — на случай, если сервер ждёт одно из них
  document.getElementById('visitSubmit').addEventListener('click', async ()=>{
    const dateVal = document.getElementById('visit_date').value || todayISO();
    const visited = document.getElementById('visit_visited').checked ? '1' : '';
    const uid = <?= (int)$user_id ?>;

    const form = new FormData();
    form.append('dates', dateVal);
    form.append('dataa', dateVal); // совместимость со старым обработчиком
    if (visited) form.append('visited', '1');
    <?php if ($csrfToken): ?> form.append('csrf', '<?= addslashes($csrfToken) ?>'); <?php endif; ?>

    try {
      const resp = await fetch('/add/dates.php?user_id='+uid, { method:'POST', body:form, credentials:'same-origin' });
      if (!resp.ok) throw new Error('HTTP '+resp.status);
      hideModal(modalVisit);
      location.reload();
    } catch(e){
      alert('Ошибка при добавлении посещения: '+e.message);
    }
  });

  // Отправка оплаты
  document.getElementById('paySubmit').addEventListener('click', async ()=>{
    const uid = <?= (int)$user_id ?>;
    const date = document.getElementById('pay_date').value || todayISO();
    const lessons = parseInt(document.getElementById('pay_lessons').value || '8', 10) || 8;
    const amount = document.getElementById('pay_amount').value || (money * lessons).toFixed(2);

    const form = new FormData();
    form.append('user_id', uid);
    form.append('date', date);
    form.append('lessons', lessons);
    form.append('amount', amount);
    <?php if ($csrfToken): ?> form.append('csrf', '<?= addslashes($csrfToken) ?>'); <?php endif; ?>

    try {
      const resp = await fetch('/add/pays.php', { method:'POST', body:form, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
      const j = await resp.json().catch(()=>null);
      if (!resp.ok || !j || j.ok !== true) {
        throw new Error((j && j.error) ? j.error : ('HTTP '+resp.status));
      }
      hideModal(modalPay);
      location.reload();
    } catch(e){
      alert('Ошибка при добавлении оплаты: '+e.message);
    }
  });

})();
</script>
</body>
</html>
