<?php
// /profile/list.php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

$klass = trim($_GET['klass'] ?? '');
$q     = trim($_GET['q'] ?? '');

// получить список классов
$res = $con->query("SELECT DISTINCT COALESCE(NULLIF(klass,''),'') AS klass FROM stud ORDER BY klass");
$classes = [];
if ($res) {
  while ($r = $res->fetch_assoc()) {
    if ($r['klass'] !== '') $classes[] = $r['klass'];
  }
  $res->close();
}

// основной запрос: добавил s.money чтобы узнать цену пакета (сумма за 8 уроков)
$sql = "
SELECT s.user_id,
       CONCAT(s.lastname,' ',s.name) AS fio,
       COALESCE(NULLIF(s.klass,''),'—') AS klass,
       COALESCE(COUNT(DISTINCT p.id),0) * 8
         - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0) AS balance_lessons,
       s.phone,
       COALESCE(s.money,0) AS money
FROM stud s
LEFT JOIN pays p ON p.user_id = s.user_id
LEFT JOIN dates d ON d.user_id = s.user_id
";

$where = [];
$params = [];
$types = '';

// фильтр по классу
if ($klass !== '') {
  $where[] = "s.klass = ?";
  $params[] = $klass;
  $types .= 's';
}
// поиск
if ($q !== '') {
  $where[] = "(s.name LIKE ? OR s.lastname LIKE ? OR CONCAT(s.lastname,' ',s.name) LIKE ? OR s.phone LIKE ?)";
  $like = "%$q%";
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  $types .= 'ssss';
}

if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " GROUP BY s.user_id, fio, klass, s.phone, s.money ORDER BY klass ASC, fio ASC";

$stmt = $con->prepare($sql);
if (!$stmt) { die("DB error: " . $con->error); }

if ($params) {
  $bind_names = [];
  $bind_names[] = $types;
  for ($i=0;$i<count($params);$i++){
    $bind_name = 'bind' . $i;
    $$bind_name = $params[$i];
    $bind_names[] = &$$bind_name;
  }
  call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Список учеников — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    .filter-row { display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .filter-row .search { flex:1; min-width:160px; }
    .actions-panel { display:flex; gap:8px; align-items:center; justify-content:flex-end; }
    .icon-btn { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:8px; border:1px solid var(--border); background:#fff; cursor:pointer; }
    .icon-btn:hover { box-shadow:0 6px 18px rgba(0,0,0,.06); }
    .icon-btn svg{ width:18px; height:18px; stroke:currentColor; }
    .td-actions { display:flex; gap:8px; justify-content:flex-end; }
    .muted { color:var(--muted); font-size:13px; }
    @media (max-width:720px){ .filter-row{flex-direction:column;align-items:stretch} .td-actions{justify-content:flex-start} }
  </style>
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <div style="display:flex;align-items:center;gap:12px;">
      <h2 style="margin:0;">Список учеников</h2>
      <div style="margin-left:auto;">
        <a class="btn" href="/add/student.php">Добавить ученика</a>
      </div>
    </div>

    <div class="filter-row" style="margin-top:12px;">
      <form id="filterForm" method="get" style="display:flex;align-items:center;gap:8px;width:100%;">
        <!-- По умолчанию надпись "Класс (по умолчанию)" -->
        <select name="klass" onchange="this.form.submit()" style="padding:10px;border-radius:8px;border:1px solid var(--border);">
          <option value=""><?= htmlspecialchars('Класс (по умолчанию)') ?></option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $c === $klass ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>

        <input class="search input" type="search" name="q" placeholder="Поиск по имени или телефону" value="<?= htmlspecialchars($q) ?>">

        <div class="actions-panel">
          <button type="submit" class="btn btn-ghost">Применить</button>
          <a class="btn" href="/profile/list.php">Сброс</a>
        </div>
      </form>
    </div>

    <table class="table" style="margin-top:12px;">
      <thead>
        <tr>
          <th>Имя</th>
          <th style="width:120px;">Класс</th>
          <th style="width:240px;text-align:right;">Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$students): ?>
          <tr><td colspan="3">Учеников не найдено.</td></tr>
        <?php else: foreach ($students as $s):
          $balance = (int)$s['balance_lessons'];
        ?>
          <tr>
            <td>
              <a href="/profile/student.php?user_id=<?= (int)$s['user_id'] ?>"><?= htmlspecialchars($s['fio']) ?></a>
              <div class="muted"><?= htmlspecialchars($s['phone'] ?? '') ?></div>
            </td>
            <td><?= htmlspecialchars($s['klass']) ?></td>
            <td class="td-actions">
              <!-- Просмотреть карточку -->
              <a class="icon-btn" title="Просмотреть карточку" href="/profile/student.php?user_id=<?= (int)$s['user_id'] ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="#0a5fb0"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>

              <!-- Добавить посещение -->
              <button class="icon-btn js-add-visit" data-user_id="<?= (int)$s['user_id'] ?>" data-name="<?= htmlspecialchars($s['fio'], ENT_QUOTES) ?>" title="Добавить посещение">
                <svg viewBox="0 0 24 24" fill="none" stroke="#0a5fb0"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
              </button>

              <!-- Добавить оплату (иконка доллар) -->
              <button class="icon-btn js-add-pay" 
                      data-user_id="<?= (int)$s['user_id'] ?>" 
                      data-name="<?= htmlspecialchars($s['fio'], ENT_QUOTES) ?>" 
                      data-price="<?= (float)$s['money'] ?>" 
                      title="Добавить оплату">
                <svg viewBox="0 0 24 24" fill="none" stroke="#0a5fb0">
                  <path d="M12 1v2"/>
                  <path d="M12 21v2"/>
                  <path d="M17 5a5 5 0 00-10 0c0 2.5 2.5 4 5 5 2.5 1 5 2.5 5 5 0 3-3 4-6 4"/>
                </svg>
              </button>

              <!-- Редактировать — ссылка на /profile/student_edit.php -->
              <a class="icon-btn" title="Редактировать" href="/profile/student_edit.php?user_id=<?= (int)$s['user_id'] ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="#0a5fb0"><path d="M3 21l3-1 11-11 2 2-11 11-1 3z"/><path d="M14 7l3 3"/></svg>
              </a>

            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Add Visit -->
<div id="modalVisit" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-visit" aria-label="Закрыть">✕</button>
    <h3>Добавить посещение</h3>
    <p id="visitStudentName" style="font-weight:700;margin-bottom:8px;"></p>
    <form id="visitForm" class="form">
      <input type="hidden" name="user_id" id="visit_user_id">
      <?php if ($csrfToken): ?><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>"><?php endif; ?>
      <label>Дата</label>
      <input type="date" name="dataa" id="visit_date" class="input" required>

      <div class="small-row">
        <label><input type="checkbox" name="visited" id="visit_visited" checked> Посетил</label>
      </div>

      <div style="margin-top:12px; display:flex; gap:8px;">
        <button type="button" id="visitSubmit" class="btn">Сохранить посещение</button>
        <button type="button" class="btn btn-ghost js-close-visit">Отмена</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Add Payment (lessons editable, default 8; amount prefilled from stud.money but editable) -->
<div id="modalPay" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-pay" aria-label="Закрыть">✕</button>
    <h3>Добавить оплату</h3>
    <p id="payStudentName" style="font-weight:700;margin-bottom:8px;"></p>
    <form id="payForm" class="form">
      <input type="hidden" name="user_id" id="pay_user_id">
      <?php if ($csrfToken): ?><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>"><?php endif; ?>

      <label>Дата оплаты</label>
      <input type="date" name="date" id="pay_date" class="input" required>

      <label style="margin-top:8px;">Кол-во уроков (по умолчанию 8)</label>
      <input type="number" name="lessons" id="pay_lessons" class="input" value="8" min="1" required>

      <label style="margin-top:8px;">Сумма (AZN)</label>
      <input type="text" name="amount" id="pay_amount" class="input">

      <div style="margin-top:12px; display:flex; gap:8px;">
        <button type="button" id="paySubmit" class="btn">Сохранить оплату</button>
        <button type="button" class="btn btn-ghost js-close-pay">Отмена</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Success -->
<div id="modalSuccess" class="modal" hidden>
  <div class="modal-card">
    <div class="modal-icon success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2l4-4"/></svg></div>
    <h3 id="successTitle">Успешно</h3>
    <p id="successText"></p>
    <div class="modal-actions">
      <button id="successClose" class="btn">OK</button>
    </div>
  </div>
</div>

<script>
(function(){
  const visitEndpoint = '/add/dates.php'; // GET user_id, POST dataa, visited
  const payEndpoint   = '/add/pays.php';  // POST: user_id, lessons (optional), amount (optional), date

  function showModal(el){ el.removeAttribute('hidden'); document.body.classList.add('noscroll'); }
  function hideModal(el){ el.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }

  // Visit modal
  const visitBtns = document.querySelectorAll('.js-add-visit');
  const modalVisit = document.getElementById('modalVisit');
  const visitStudentName = document.getElementById('visitStudentName');
  const visitUserId = document.getElementById('visit_user_id');
  const visitDate = document.getElementById('visit_date');
  const visitVisited = document.getElementById('visit_visited');
  const visitSubmit = document.getElementById('visitSubmit');

  function todayISO(){ const d=new Date(); d.setHours(0,0,0,0); return d.toISOString().slice(0,10); }

  visitBtns.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const uid = btn.getAttribute('data-user_id');
      const name = btn.getAttribute('data-name') || 'Ученик';
      visitStudentName.textContent = name;
      visitUserId.value = uid;
      visitDate.value = todayISO();
      visitVisited.checked = true;
      showModal(modalVisit);
    });
  });
  document.querySelectorAll('.js-close-visit').forEach(b=>b.addEventListener('click', ()=> hideModal(modalVisit)));

  visitSubmit.addEventListener('click', async ()=>{
    const uid = visitUserId.value;
    const date = visitDate.value;
    const visited = visitVisited.checked ? '1' : '0';
    const url = visitEndpoint + '?user_id=' + encodeURIComponent(uid);
    const form = new FormData();
    form.append('dataa', date);
    if (visitVisited.checked) form.append('visited', '1');
    <?php if ($csrfToken): ?> form.append('csrf', '<?= addslashes($csrfToken) ?>'); <?php endif; ?>

    try {
      const resp = await fetch(url, { method:'POST', body:form, credentials:'same-origin' });
      if (resp.ok) {
        hideModal(modalVisit);
        showSuccess('Посещение сохранено', 'Посещение успешно добавлено/обновлено.');
        setTimeout(()=> location.reload(), 700);
      } else {
        const txt = await resp.text();
        throw new Error(txt || 'Ошибка сохранения');
      }
    } catch(err){ alert('Ошибка при добавлении посещения: ' + err.message); }
  });

  // Pay modal
  const payBtns = document.querySelectorAll('.js-add-pay');
  const modalPay = document.getElementById('modalPay');
  const payStudentName = document.getElementById('payStudentName');
  const payUserId = document.getElementById('pay_user_id');
  const payDate = document.getElementById('pay_date');
  const payLessons = document.getElementById('pay_lessons');
  const payAmount = document.getElementById('pay_amount');
  const paySubmit = document.getElementById('paySubmit');

  payBtns.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const uid = btn.getAttribute('data-user_id');
      const name = btn.getAttribute('data-name') || 'Ученик';
      const price = parseFloat(btn.getAttribute('data-price') || '0');
      payStudentName.textContent = name;
      payUserId.value = uid;
      payDate.value = todayISO();
      payLessons.value = '8';

      // автозаполнение суммы из stud.money, но editable
      if (!isNaN(price) && price > 0) {
        payAmount.value = price.toFixed(2);
        payAmount.readOnly = false; // editable, per your request
      } else {
        payAmount.value = '';
        payAmount.readOnly = false;
      }

      showModal(modalPay);
    });
  });
  document.querySelectorAll('.js-close-pay').forEach(b=>b.addEventListener('click', ()=> hideModal(modalPay)));

  paySubmit.addEventListener('click', async ()=>{
    const uid = payUserId.value;
    const date = payDate.value;
    const lessons = payLessons.value || '8';
    const amount = payAmount.value || '';

    const form = new FormData();
    form.append('user_id', uid);
    form.append('lessons', lessons);
    form.append('amount', amount);
    form.append('date', date);
    <?php if ($csrfToken): ?> form.append('csrf', '<?= addslashes($csrfToken) ?>'); <?php endif; ?>

    try {
      const resp = await fetch(payEndpoint, { method:'POST', body:form, credentials:'same-origin' });
      if (resp.ok) {
        hideModal(modalPay);
        showSuccess('Оплата сохранена', 'Оплата успешно добавлена.');
        setTimeout(()=> location.reload(), 700);
      } else {
        const txt = await resp.text();
        throw new Error(txt || 'Ошибка при сохранении оплаты');
      }
    } catch(err){ alert('Ошибка при добавлении оплаты: ' + err.message); }
  });

  // Success modal
  const modalSuccess = document.getElementById('modalSuccess');
  const successTitle = document.getElementById('successTitle');
  const successText = document.getElementById('successText');
  const successClose = document.getElementById('successClose');
  function showSuccess(title, text){ successTitle.textContent = title; successText.textContent = text; showModal(modalSuccess); }
  successClose.addEventListener('click', ()=> hideModal(modalSuccess));

})();
</script>
</body>
</html>
