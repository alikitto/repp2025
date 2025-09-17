<?php
// /profile/student.php — карточка ученика (адаптив + модалка удаления ученика)
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

/* ---------- AJAX: удаление визитов/оплат/удаление ученика в этом же файле ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (function_exists('csrf_check')) {
        try { csrf_check(); } catch (Throwable $e) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false,'error'=>'csrf']);
            exit;
        }
    }
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'];
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($action === 'delete_visit') {
        $id = (int)($_POST['id'] ?? 0); // dates.dates_id
        if ($id <= 0 || $uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }
        // удаляем только у этого пользователя (безопаснее)
        $st = $con->prepare("DELETE FROM dates WHERE dates_id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $ok = $st->execute();
        echo json_encode(['ok'=>$ok?true:false]);
        exit;
    }

    if ($action === 'delete_pay') {
        $id = (int)($_POST['id'] ?? 0); // pays.id
        if ($id <= 0 || $uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }
        $st = $con->prepare("DELETE FROM pays WHERE id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $ok = $st->execute();
        echo json_encode(['ok'=>$ok?true:false]);
        exit;
    }

    if ($action === 'delete_student') {
        $csrf_check_answer = $_POST['csrf_check_answer'] ?? '';
        if ($csrf_check_answer !== '22') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'wrong_answer']); exit; }
        $st = $con->prepare("DELETE FROM stud WHERE user_id=?");
        $st->bind_param('i', $uid);
        $ok = $st->execute();
        if ($ok) {
            // Удаляем все данные
            $stmt_dates = $con->prepare("DELETE FROM dates WHERE user_id=?");
            $stmt_dates->bind_param('i', $uid);
            $stmt_dates->execute();
            
            $stmt_pays = $con->prepare("DELETE FROM pays WHERE user_id=?");
            $stmt_pays->bind_param('i', $uid);
            $stmt_pays->execute();
        }
        echo json_encode(['ok'=>$ok?true:false]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    exit;
}
/* ------------------------------------------------------------------ */

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
$st->execute(); $st->bind_result($visits_count); $st->fetch(); $st->close();

$st = $con->prepare("SELECT COALESCE(SUM(lessons),0) AS paid_lessons, COUNT(*) AS pays_count FROM pays WHERE user_id=?");
$st->bind_param('i', $user_id);
$st->execute(); $st->bind_result($paid_lessons, $pays_count); $st->fetch(); $st->close();

$balance_lessons = (int)$paid_lessons - (int)$visits_count; // >0 предоплата, <0 долг

// Фильтр посещений
$v = $_GET['v'] ?? 'all';
$v = in_array($v, ['all','1','0'], true) ? $v : 'all';

// Посещения (таблица dates: dates_id, user_id, dates, visited)
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

// Оплаты (pays: id, user_id, date, lessons, amount)
$st = $con->prepare("SELECT id, user_id, `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC");
$st->bind_param('i', $user_id);
$st->execute();
$pays = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$csrfToken = function_exists('csrf_token') ? csrf_token() : '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_amount($a){ return number_format((float)$a, 2, '.', ''); }
function fmt_date($d) {
    if (empty($d)) return '—';
    $ts = strtotime($d);
    return $ts ? date('d.m.Y', $ts) : h($d);
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= h($student['lastname'].' '.$student['name']) ?> — Карточка ученика</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    :root{ --gap:12px; --btn-pad:8px 10px; --btn-radius:10px; }
    .page-head { display:flex; gap:var(--gap); align-items:flex-start; flex-wrap:wrap; }
    .page-head h2{ margin:0; line-height:1.15; }
    .muted { color:var(--muted); font-size:13px; }

    .badges { display:flex; gap:10px; flex-wrap:wrap; }
    .badge { background:#f5f7fb; border:1px solid var(--border); border-radius:10px; padding:6px 10px; font-size:14px; }
    .badge.positive{ background:#f3fff4; border-color:#cfe9d4; }
    .badge.warn{ background:#fffbe6; border-color:#ffe69c; }  /* долг до 8 */
    .badge.negative{ background:#fff3f3; border-color:#ffd4d4; } /* долг >8 */

    .toolbar { display:flex; gap:8px; margin-left:auto; align-items:flex-start; flex-wrap:wrap; }
    .btn { padding:var(--btn-pad); border-radius:var(--btn-radius); border:1px solid var(--border); background:#fff; cursor:pointer; line-height:1; font-size:14px; text-align:center; }
    .btn.primary { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
    .btn.gray { background:#f1f3f5; color:#333; border-color:#e1e5ea; }
    .btn.pay { background:#9be7a0; border-color:#7bc885; color:#033; }
    .btn.danger { background:#b30000; color:#fff; border-color:#b30000; }
    .btn.sm { padding:6px 8px; border-radius:8px; font-size:13px; }

    .section { margin-top:16px; }
    .tabs { display:flex; gap:6px; margin-bottom:10px; }
    .tab { padding:6px 8px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; text-decoration:none; color:inherit; font-size:13px; }
    .tab.active { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }

    .table { width:100%; border-collapse:collapse; }
    .table th, .table td { padding:10px 12px; border:1px solid #e9edf3; }
    .table thead th { background:#eef3f9; }
    .td-actions { display:flex; gap:8px; justify-content:flex-start; }
    .icon-btn { padding:6px 10px; border:1px solid #ffc9c9; background:#fff5f5; color:#b30000; border-radius:8px; cursor:pointer; font-size:13px; }
    
    .management-actions { display:flex; flex-wrap:wrap; gap:12px; }
    .management-actions .btn { flex:1 1 calc(50% - 6px); }

    /* Модалки */
    .modal { position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.4); z-index:1000; }
    .modal[hidden]{ display:none; }
    .modal-card { background:#fff; padding:18px; border-radius:12px; width:420px; max-width:95vw; box-shadow:0 10px 30px rgba(0,0,0,0.2); position:relative; }
    .modal-close { position:absolute; right:10px; top:8px; border:none; background:transparent; font-size:18px; cursor:pointer; }
    .form .input, .form input[type="date"], .form input[type="number"], .form input[type="text"]{ width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; box-sizing:border-box; }
    .actions { display:flex; gap:8px; margin-top:12px; }

    /* Успешная модалка (зелёная) */
    .modal-card.success {
      background:#0f9d58; color:#fff; text-align:center;
    }
    .success-icon{ width:56px; height:56px; border-radius:50%; background:#fff; color:#0f9d58; display:inline-flex; align-items:center; justify-content:center; margin-bottom:8px; font-weight:700; }

    /* Мобайл */
    @media (max-width:680px){
      .toolbar { width:100%; flex-wrap:nowrap; gap:6px; }
      .toolbar .btn { flex:1 1 0; min-width:0; white-space:nowrap; }
      .page-head h2 { font-size:20px; }
      .table th, .table td { padding:8px 10px; font-size:14px; }
      .tab { font-size:12px; padding:5px 7px; }
      .management-actions .btn { flex-basis:100%; } /* Одна кнопка в ряд на мобильных */
    }
  </style>
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <div class="page-head">
      <div>
        <h2><?= h($student['lastname'].' '.$student['name']) ?></h2>
        <div class="muted">Класс: <?= h($student['klass'] ?: '—') ?> • Цена за урок: <?= fmt_amount($student['money']) ?> AZN</div>
      </div>

      <div class="badges">
        <?php if ($balance_lessons < 0): 
          $debt = abs($balance_lessons);
          $cls = ($debt <= 8) ? 'warn' : 'negative';
        ?>
          <div class="badge <?= $cls ?>">Долг: <?= $debt ?></div>
        <?php elseif ($balance_lessons > 0): ?>
          <div class="badge positive">Баланс: <?= $balance_lessons ?></div>
        <?php else: ?>
          <div class="badge">Баланс: 0</div>
        <?php endif; ?>
        <div class="badge">Посещений: <?= (int)$visits_count ?></div>
        <div class="badge">Оплат: <?= (int)$pays_count ?></div>
      </div>
    </div>
  </div>

  <div class="card section">
    <h3>Управление</h3>
    <div class="management-actions">
      <button class="btn pay sm" id="btnAddVisit">+ Посещение</button>
      <button class="btn gray sm" id="btnAddPay">+ Оплата</button>
      <button class="btn gray sm" id="btnEditStudent">✎ Редактировать</button>
      <button class="btn danger sm" id="btnDeleteStudent">❗ Удалить ученика</button>
    </div>
  </div>

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
          <th style="width:130px;">Дата</th>
          <th style="width:120px;">Статус</th>
          <th style="width:120px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$visits): ?>
          <tr><td colspan="3">Пока нет записей.</td></tr>
        <?php else: foreach ($visits as $row): ?>
          <tr>
            <td><?= fmt_date($row['dates']) ?></td>
            <td><?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?></td>
            <td class="td-actions">
              <button class="icon-btn js-del-visit"
                      data-id="<?= (int)$row['dates_id'] ?>"
                      data-date="<?= h(fmt_date($row['dates'])) ?>"
                      data-status="<?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?>">
                Удалить
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
      <h3 style="margin:0;">Оплаты</h3>
    </div>

    <?php $sum_lessons = 0; $sum_amount = 0.0;
      foreach ($pays as $p){ $sum_lessons += (int)$p['lessons']; $sum_amount += (float)$p['amount']; } ?>

    <table class="table">
      <thead>
        <tr>
          <th style="width:130px;">Дата</th>
          <th style="width:100px;">Уроков</th>
          <th style="width:120px;">Сумма, AZN</th>
          <th style="width:120px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$pays): ?>
          <tr><td colspan="4">Пока оплат нет.</td></tr>
        <?php else: foreach ($pays as $p): ?>
          <tr>
            <td><?= fmt_date($p['date']) ?></td>
            <td><?= (int)$p['lessons'] ?></td>
            <td><?= fmt_amount($p['amount']) ?></td>
            <td class="td-actions">
              <button class="icon-btn js-del-pay"
                      data-id="<?= (int)$p['id'] ?>"
                      data-date="<?= h(fmt_date($p['date'])) ?>"
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

<div id="modalVisit" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-visit" aria-label="Закрыть">✕</button>
    <h3>Добавить посещение</h3>
    <div class="muted" style="margin-bottom:8px;"><?= h($student['lastname'].' '.$student['name']) ?></div>
    <form id="visitForm" class="form">
      <input type="hidden" id="v_user" value="<?= (int)$student['user_id'] ?>">
      <?php if ($csrfToken): ?><input type="hidden" id="v_csrf" value="<?= h($csrfToken) ?>"><?php endif; ?>
      <label>Дата</label>
      <input type="date" id="visit_date" class="input" required>
      <label style="margin-top:8px;">Статус</label>
      <div><label><input type="checkbox" id="visit_visited" checked> Пришёл</label></div>
      <div class="actions">
        <button type="button" id="visitSubmit" class="btn primary sm">Сохранить</button>
        <button type="button" class="btn gray sm js-close-visit">Отмена</button>
      </div>
    </form>
  </div>
</div>

<div id="modalPay" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-pay" aria-label="Закрыть">✕</button>
    <h3>Добавить оплату</h3>
    <div class="muted" style="margin-bottom:8px;"><?= h($student['lastname'].' '.$student['name']) ?></div>
    <form id="payForm" class="form">
      <input type="hidden" id="p_user" value="<?= (int)$student['user_id'] ?>">
      <?php if ($csrfToken): ?><input type="hidden" id="p_csrf" value="<?= h($csrfToken) ?>"><?php endif; ?>

      <label>Дата оплаты</label>
      <input type="date" id="pay_date" class="input" required>

      <label style="margin-top:8px;">Кол-во уроков</label>
      <input type="number" id="pay_lessons" class="input" value="8" min="1" required>

      <label style="margin-top:8px;">Сумма (AZN)</label>
      <input type="text" id="pay_amount" class="input" readonly>
      <div class="muted">Сумма берётся из ставки (money) × уроков на сервере.</div>

      <div class="actions">
        <button type="button" id="paySubmit" class="btn pay sm">Сохранить</button>
        <button type="button" class="btn gray sm js-close-pay">Отмена</button>
      </div>
    </form>
  </div>
</div>

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

<div id="modalDeleteStudent" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-delete-student" aria-label="Закрыть">✕</button>
    <h3>Удалить ученика?</h3>
    <p class="muted">Это действие необратимо. Будут удалены все данные об ученике, включая посещения и оплаты.</p>
    <p class="muted" style="margin-top:10px;">Для подтверждения, введите <b>22</b> в поле ниже:</p>
    <form id="deleteStudentForm" class="form">
      <input type="text" id="delete_confirm_answer" class="input" autocomplete="off" style="margin-top:4px;">
      <div class="actions">
        <button type="button" id="deleteStudentConfirmBtn" class="btn danger sm">Удалить навсегда</button>
        <button type="button" class="btn gray sm js-close-delete-student">Отмена</button>
      </div>
    </form>
  </div>
</div>

<div id="modalSuccess" class="modal" hidden>
  <div class="modal-card success" role="dialog" aria-modal="true">
    <button class="modal-close js-close-success" aria-label="Закрыть" style="color:#fff;">✕</button>
    <div class="success-icon">✔</div>
    <h3 id="successTitle" style="margin:6px 0;">Успешно</h3>
    <p id="successText" style="opacity:.9;margin:0 0 6px 0;"></p>
  </div>
</div>

<script>
(function(){
  const uid = <?= (int)$user_id ?>;
  const csrf = <?= json_encode($csrfToken ?: '') ?>;
  const money = <?= json_encode((float)$student['money']) ?>;

  /* helpers */
  function showModal(m){ m.removeAttribute('hidden'); document.body.classList.add('noscroll'); }
  function hideModal(m){ m.setAttribute('hidden',''); document.body.classList.remove('noscroll'); }
  function todayISO(){ const d=new Date(); d.setHours(0,0,0,0); return d.toISOString().slice(0,10); }

  const modalVisit = document.getElementById('modalVisit');
  const modalPay = document.getElementById('modalPay');
  const modalConfirm = document.getElementById('modalConfirm');
  const modalSuccess = document.getElementById('modalSuccess');
  const modalDeleteStudent = document.getElementById('modalDeleteStudent');
  
  const successTitle = document.getElementById('successTitle');
  const successText = document.getElementById('successText');
  const confirmTitle = document.getElementById('confirmTitle');
  const confirmText = document.getElementById('confirmText');
  const confirmYes = document.getElementById('confirmYes');

  /* open add modals */
  document.getElementById('btnAddVisit').addEventListener('click', ()=>{
    document.getElementById('visit_date').value = todayISO();
    document.getElementById('visit_visited').checked = true;
    showModal(modalVisit);
  });
  document.getElementById('btnAddPay').addEventListener('click', ()=>{
    document.getElementById('pay_date').value = todayISO();
    document.getElementById('pay_lessons').value = 8;
    document.getElementById('pay_amount').value = (money * 8).toFixed(2); // для показа
    showModal(modalPay);
  });
  
  /* --- Student Actions --- */
  document.getElementById('btnEditStudent').addEventListener('click', () => {
    // Предполагается, что страница редактирования находится по этому адресу
    location.href = `/profile/edit_student.php?user_id=${uid}`;
  });
  
  document.getElementById('btnDeleteStudent').addEventListener('click', () => {
    showModal(modalDeleteStudent);
  });

  document.getElementById('deleteStudentConfirmBtn').addEventListener('click', async () => {
    const answer = document.getElementById('delete_confirm_answer').value;
    if (answer !== '22') {
        alert('Неверный код подтверждения.');
        return;
    }

    const form = new FormData();
    form.append('user_id', uid);
    form.append('action', 'delete_student');
    form.append('csrf_check_answer', answer);
    if (csrf) form.append('csrf', csrf);
    
    try {
        const resp = await fetch(location.pathname + '?user_id=' + uid, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const j = await resp.json().catch(() => null);
        if (!resp.ok || !j || j.ok !== true) throw new Error((j && j.error) ? j.error : ('HTTP ' + resp.status));
        
        hideModal(modalDeleteStudent);
        successTitle.textContent = 'Ученик удалён';
        successText.textContent = 'Вы будете перенаправлены на главную страницу.';
        showModal(modalSuccess);
        setTimeout(() => location.href = '/profile/', 1200); // Редирект на список учеников
    } catch (e) {
        alert('Ошибка удаления: ' + e.message);
    }
  });


  /* close modals */
  document.querySelectorAll('.js-close-visit').forEach(b=>b.addEventListener('click', ()=> hideModal(modalVisit)));
  document.querySelectorAll('.js-close-pay').forEach(b=>b.addEventListener('click', ()=> hideModal(modalPay)));
  document.querySelectorAll('.js-close-confirm').forEach(b=>b.addEventListener('click', ()=> hideModal(modalConfirm)));
  document.querySelectorAll('.js-close-success').forEach(b=>b.addEventListener('click', ()=> hideModal(modalSuccess)));
  document.querySelectorAll('.js-close-delete-student').forEach(b=>b.addEventListener('click', ()=> hideModal(modalDeleteStudent)));
  document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', (e)=>{ if (e.target === m) hideModal(m); }));
  window.addEventListener('keydown', (e)=>{ if (e.key==='Escape'){ hideModal(modalVisit); hideModal(modalPay); hideModal(modalConfirm); hideModal(modalSuccess); hideModal(modalDeleteStudent); } });

  /* recalc pay amount (visual only) */
  const payLessons = document.getElementById('pay_lessons');
  const payAmount = document.getElementById('pay_amount');
  payLessons.addEventListener('input', ()=>{
    const lessons = parseInt(payLessons.value || '0', 10) || 0;
    payAmount.value = (money * lessons).toFixed(2);
  });

  /* add visit */
  document.getElementById('visitSubmit').addEventListener('click', async ()=>{
    const dateVal = document.getElementById('visit_date').value || todayISO();
    const visited = document.getElementById('visit_visited').checked ? '1' : '';
    const form = new FormData();
    form.append('dates', dateVal);    // совместимость
    form.append('dataa', dateVal);
    if (visited) form.append('visited', '1');
    if (csrf) form.append('csrf', csrf);

    try {
      const resp = await fetch('/add/dates.php?user_id='+uid, { method:'POST', body:form, credentials:'same-origin' });
      if (!resp.ok) throw new Error('HTTP '+resp.status);
      hideModal(modalVisit);
      successTitle.textContent = 'Посещение добавлено';
      successText.textContent = '';
      showModal(modalSuccess);
      setTimeout(()=>location.reload(), 900);
    } catch(e){ alert('Ошибка при добавлении посещения: '+e.message); }
  });

  /* add pay (server calculates amount) */
  document.getElementById('paySubmit').addEventListener('click', async ()=>{
    const date = document.getElementById('pay_date').value || todayISO();
    const lessons = parseInt(document.getElementById('pay_lessons').value || '8', 10) || 8;
    const form = new FormData();
    form.append('user_id', uid);
    form.append('date', date);
    form.append('lessons', lessons);
    form.append('amount', ''); // пусто -> сервер посчитает
    if (csrf) form.append('csrf', csrf);

    try {
      const resp = await fetch('/add/pays.php', { method:'POST', body:form, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
      const j = await resp.json().catch(()=>null);
      if (!resp.ok || !j || j.ok !== true) throw new Error((j && j.error) ? j.error : ('HTTP '+resp.status));
      hideModal(modalPay);
      successTitle.textContent = 'Оплата добавлена';
      successText.textContent = `Уроков: ${lessons}`;
      showModal(modalSuccess);
      setTimeout(()=>location.reload(), 900);
    } catch(e){ alert('Ошибка при добавлении оплаты: '+e.message); }
  });

  /* delete (confirm modal -> POST to same file) */
  let pending = null; // {type:'visit'|'pay', id}
  document.querySelectorAll('.js-del-visit').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      pending = { type:'visit', id:Number(btn.dataset.id) };
      confirmTitle.textContent = 'Удалить посещение';
      confirmText.textContent = `Удалить посещение от ${btn.dataset.date} (${btn.dataset.status})?`;
      showModal(modalConfirm);
    });
  });
  document.querySelectorAll('.js-del-pay').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      pending = { type:'pay', id:Number(btn.dataset.id) };
      confirmTitle.textContent = 'Удалить оплату';
      confirmText.textContent = `Удалить оплату от ${btn.dataset.date} (уроков: ${btn.dataset.lessons}, сумма: ${btn.dataset.amount} AZN)?`;
      showModal(modalConfirm);
    });
  });

  confirmYes.addEventListener('click', async ()=>{
    if (!pending) return;
    const form = new FormData();
    form.append('user_id', uid);
    form.append('action', pending.type === 'visit' ? 'delete_visit' : 'delete_pay');
    form.append('id', String(pending.id));
    if (csrf) form.append('csrf', csrf);

    try {
      const resp = await fetch(location.pathname + '?user_id=' + uid + '<?= $v==='all' ? '' : '&v='. $v ?>', {
        method:'POST', body:form, credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest'}
      });
      const j = await resp.json().catch(()=>null);
      if (!resp.ok || !j || j.ok !== true) throw new Error((j && j.error) ? j.error : ('HTTP '+resp.status));
      hideModal(modalConfirm);
      successTitle.textContent = 'Удалено';
      successText.textContent = '';
      showModal(modalSuccess);
      setTimeout(()=>location.reload(), 800);
    } catch(e){ alert('Ошибка удаления: ' + e.message); }
  });

})();
</script>
</body>
</html>
