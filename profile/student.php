<?php
// /profile/student.php — карточка ученика (адаптив + модалка удаления ученика)
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_amount($a){ return number_format((float)$a, 2, '.', ''); }
function fmt_date($d) {
    if (empty($d)) return '—';
    $ts = strtotime($d);
    return $ts ? date('d.m.Y', $ts) : h($d);
}

/* ---------- AJAX HANDLER ---------- */
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

    // Удаление посещения
    if ($action === 'delete_visit') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || $uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }
        $st = $con->prepare("DELETE FROM dates WHERE dates_id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $ok = $st->execute();
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    // Удаление оплаты
    if ($action === 'delete_pay') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || $uid <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'bad_params']); exit; }
        $st = $con->prepare("DELETE FROM pays WHERE id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $ok = $st->execute();
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    // Удаление ученика
    if ($action === 'delete_student') {
        $csrf_check_answer = $_POST['csrf_check_answer'] ?? '';
        if ($csrf_check_answer !== '22') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'wrong_answer']); exit; }
        $st = $con->prepare("DELETE FROM stud WHERE user_id=?");
        $st->bind_param('i', $uid);
        $ok = $st->execute();
        if ($ok) {
            $con->prepare("DELETE FROM dates WHERE user_id=?")->execute([$uid]);
            $con->prepare("DELETE FROM pays WHERE user_id=?")->execute([$uid]);
            $con->prepare("DELETE FROM schedule WHERE user_id=?")->execute([$uid]);
        }
        echo json_encode(['ok'=>$ok]);
        exit;
    }

    // Пагинация: Загрузить еще посещения
    if ($action === 'load_more_visits') {
        $offset = (int)($_POST['offset'] ?? 0);
        $st = $con->prepare("SELECT dates_id, `dates`, COALESCE(visited,0) AS visited FROM dates WHERE user_id=? ORDER BY `dates` DESC, dates_id DESC LIMIT 15 OFFSET ?");
        $st->bind_param('ii', $uid, $offset);
        $st->execute();
        $visits = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        
        ob_start();
        foreach ($visits as $row) { ?>
            <tr>
              <td><?= fmt_date($row['dates']) ?></td>
              <td><?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?></td>
              <td class="td-actions">
                <button class="icon-btn js-del-visit" data-id="<?= (int)$row['dates_id'] ?>" data-date="<?= h(fmt_date($row['dates'])) ?>" data-status="<?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?>" aria-label="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
              </td>
            </tr>
        <?php }
        $html = ob_get_clean();
        echo json_encode(['html' => $html, 'count' => count($visits)]);
        exit;
    }

    // Пагинация: Загрузить еще оплаты
    if ($action === 'load_more_pays') {
        $offset = (int)($_POST['offset'] ?? 0);
        $st = $con->prepare("SELECT id, `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 15 OFFSET ?");
        $st->bind_param('ii', $uid, $offset);
        $st->execute();
        $pays = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        ob_start();
        foreach ($pays as $p) { ?>
            <tr>
              <td><?= fmt_date($p['date']) ?></td>
              <td><?= (int)$p['lessons'] ?></td>
              <td><?= fmt_amount($p['amount']) ?></td>
              <td class="td-actions">
                <button class="icon-btn js-del-pay" data-id="<?= (int)$p['id'] ?>" data-date="<?= h(fmt_date($p['date'])) ?>" data-lessons="<?= (int)$p['lessons'] ?>" data-amount="<?= fmt_amount($p['amount']) ?>" aria-label="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
              </td>
            </tr>
        <?php }
        $html = ob_get_clean();
        echo json_encode(['html' => $html, 'count' => count($pays)]);
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

// Расписание
$st = $con->prepare("SELECT weekday, `time` FROM schedule WHERE user_id = ? ORDER BY weekday, `time`");
$st->bind_param('i', $user_id);
$st->execute();
$schedule = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$weekdays_map = ['1'=>'Понедельник', '2'=>'Вторник', '3'=>'Среда', '4'=>'Четверг', '5'=>'Пятница', '6'=>'Суббота', '7'=>'Воскресенье'];

// Счётчики
$st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=?"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($total_visits); $st->fetch(); $st->close();
$st = $con->prepare("SELECT COUNT(*) FROM pays WHERE user_id=?"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($total_pays); $st->fetch(); $st->close();

$st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND visited=1"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($visits_count); $st->fetch(); $st->close();
$st = $con->prepare("SELECT COALESCE(SUM(lessons),0) AS paid_lessons, COUNT(*) AS pays_count FROM pays WHERE user_id=?"); $st->bind_param('i', $user_id); $st->execute(); $st->bind_result($paid_lessons, $pays_count); $st->fetch(); $st->close();
$balance_lessons = (int)$paid_lessons - (int)$visits_count;

// Фильтр посещений
$v = in_array($_GET['v'] ?? 'all', ['all','1','0'], true) ? $_GET['v'] : 'all';

// Данные для таблиц (первые 10 записей)
$sqlVisits = "SELECT dates_id, user_id, `dates`, COALESCE(visited,0) AS visited FROM dates WHERE user_id=? ";
if ($v === '1') { $sqlVisits .= "AND visited=1 "; } elseif ($v === '0') { $sqlVisits .= "AND visited=0 "; }
$sqlVisits .= "ORDER BY `dates` DESC, dates_id DESC LIMIT 10";
$st = $con->prepare($sqlVisits); $st->bind_param('i', $user_id); $st->execute(); $visits = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$st = $con->prepare("SELECT id, user_id, `date`, lessons, amount FROM pays WHERE user_id=? ORDER BY `date` DESC, id DESC LIMIT 10");
$st->bind_param('i', $user_id); $st->execute(); $pays = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
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
    body { background-color: #f8f9fa; }
    .content { max-width: 900px; }
    .page-head { display:flex; gap:var(--gap); align-items:flex-start; flex-wrap:wrap; }
    .page-head h2{ margin:0; line-height:1.15; }
    .muted { color:var(--muted); font-size:13px; }

    .badges { display:flex; gap:10px; flex-wrap:wrap; }
    .badge { background:#f5f7fb; border:1px solid var(--border); border-radius:10px; padding:6px 10px; font-size:14px; }
    .badge.positive{ background:#e7f7e8; border-color:#cfe9d4; color:#2b6431; }
    .badge.warn{ background:#fffbe6; border-color:#ffe69c; }
    .badge.negative{ background:#fff3f3; border-color:#ffd4d4; }

    .btn { padding:var(--btn-pad); border-radius:var(--btn-radius); border:1px solid var(--border); background:#fff; cursor:pointer; line-height:1.2; font-size:14px; text-align:center; display:flex; align-items:center; justify-content:center; gap:6px; }
    .btn.primary { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
    .btn.gray { background:#f1f3f5; color:#333; border-color:#e1e5ea; }
    .btn.pay { background:#28a745; border-color:#28a745; color:white; } /* Зеленый */
    .btn.danger { background:#dc3545; color:#fff; border-color:#dc3545; }
    .btn.sm { padding:8px 10px; border-radius:8px; font-size:13px; }

    .management-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
    .management-actions .btn { flex:1 1 calc(50% - 5px); }

    .section { margin-top:16px; }
    .tabs { display:flex; gap:6px; margin-bottom:10px; }
    .tab { padding:6px 8px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; text-decoration:none; color:inherit; font-size:13px; }
    .tab.active { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }

    .table { width:100%; border-collapse:collapse; }
    .table th, .table td { padding:10px 12px; border:1px solid #e9edf3; text-align:left; }
    .table thead th { background:#eef3f9; }
    .td-actions { text-align:center; }
    .icon-btn { padding:6px; border:1px solid transparent; background:transparent; color:#b30000; border-radius:8px; cursor:pointer; line-height:0; }
    .icon-btn:hover { background:#fff5f5; border-color:#ffc9c9; }
    .icon-btn svg { width:18px; height:18px; }

    .load-more-container { text-align:center; padding:16px 0 10px; }
    .schedule-table { font-size:14px; margin-top:12px; }
    .schedule-table td { padding:5px 10px 5px 0; }

    /* Модалки */
    .modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);z-index:1000}
    .modal[hidden]{display:none}
    .modal-card{background:#fff;padding:18px;border-radius:12px;width:420px;max-width:95vw;box-shadow:0 10px 30px rgba(0,0,0,0.2);position:relative}
    .modal-close{position:absolute;right:10px;top:8px;border:none;background:transparent;font-size:18px;cursor:pointer}
    .form .input,.form input[type=date],.form input[type=number],.form input[type=text]{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box}
    .actions{display:flex;gap:8px;margin-top:12px}
    .modal-card.success{background:#0f9d58;color:#fff;text-align:center}
    .success-icon{width:56px;height:56px;border-radius:50%;background:#fff;color:#0f9d58;display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;font-weight:700}

    @media (max-width:680px){
      .page-head h2{font-size:20px}
      .table th,.table td{padding:8px 10px;font-size:14px}
      .tab{font-size:12px;padding:5px 7px}
      .management-actions .btn{flex-basis:calc(50% - 5px)}
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
        <?php if ($balance_lessons < 0): $debt=abs($balance_lessons);$cls=($debt<=8)?'warn':'negative';?>
          <div class="badge <?=$cls?>">Долг: <?=$debt?></div>
        <?php elseif($balance_lessons>0):?>
          <div class="badge positive">Баланс: <?=$balance_lessons?></div>
        <?php else:?>
          <div class="badge">Баланс: 0</div>
        <?php endif;?>
        <div class="badge">Посещений: <?=(int)$visits_count?></div>
        <div class="badge">Оплат: <?=(int)$pays_count?></div>
      </div>
    </div>
    
    <?php if ($schedule): ?>
    <div style="margin-top:15px; border-top:1px solid #e9edf3; padding-top:15px;">
      <h4 style="margin:0 0 5px 0;">Расписание занятий</h4>
      <table class="schedule-table">
      <?php foreach($schedule as $item): ?>
        <tr>
          <td><strong><?= h($weekdays_map[$item['weekday']] ?? 'Неизвестный день') ?></strong></td>
          <td><?= h(substr($item['time'], 0, 5)) ?></td>
        </tr>
      <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="management-actions">
    <button class="btn pay sm" id="btnAddVisit">+ Посещение</button>
    <button class="btn pay sm" id="btnAddPay">+ Оплата</button>
    <button class="btn gray sm" id="btnEditStudent">✎ Редактировать</button>
    <button class="btn gray sm" id="btnDeleteStudent">❗ Удалить ученика</button>
  </div>


  <div class="card section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
      <h3 style="margin:0;">Посещения</h3>
      <div class="tabs" style="margin-left:auto;">
        <?php $base='/profile/student.php?user_id='.(int)$user_id;$mk=function($l,$v,$c)use($base){$h=$base.($v==='all'?'':'&v='.$v);$a=$c===$v?'active':'';return'<a class="tab '.$a.'" href="'.h($h).'">'.h($l).'</a>';};echo$mk('Все','all',$v);echo$mk('Пришёл','1',$v);echo$mk('Не пришёл','0',$v);?>
      </div>
    </div>
    <table class="table">
      <thead><tr><th style="width:130px;">Дата</th><th style="width:120px;">Статус</th><th style="width:60px;"></th></tr></thead>
      <tbody id="visits-tbody">
        <?php if (!$visits):?><tr><td colspan="3">Пока нет записей.</td></tr>
        <?php else: foreach($visits as $row):?>
          <tr>
            <td><?= fmt_date($row['dates']) ?></td>
            <td><?= $row['visited'] ? 'Пришёл' : 'Не пришёл' ?></td>
            <td class="td-actions">
              <button class="icon-btn js-del-visit" data-id="<?=(int)$row['dates_id']?>" data-date="<?=h(fmt_date($row['dates']))?>" data-status="<?=$row['visited']?'Пришёл':'Не пришёл'?>" aria-label="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
            </td>
          </tr>
        <?php endforeach; endif;?>
      </tbody>
    </table>
    <?php if ($total_visits > 10): ?>
    <div class="load-more-container">
        <button class="btn gray sm" id="load-more-visits" data-offset="10">Загрузить еще</button>
    </div>
    <?php endif; ?>
  </div>

  <div class="card section">
    <h3 style="margin:0 0 8px 0;">Оплаты</h3>
    <table class="table">
      <thead><tr><th style="width:130px;">Дата</th><th style="width:100px;">Уроков</th><th>Сумма, AZN</th><th style="width:60px;"></th></tr></thead>
      <tbody id="pays-tbody">
        <?php if(!$pays):?><tr><td colspan="4">Пока оплат нет.</td></tr>
        <?php else: foreach ($pays as $p):?>
          <tr>
            <td><?= fmt_date($p['date']) ?></td>
            <td><?= (int)$p['lessons'] ?></td>
            <td><?= fmt_amount($p['amount']) ?></td>
            <td class="td-actions">
              <button class="icon-btn js-del-pay" data-id="<?=(int)$p['id']?>" data-date="<?=h(fmt_date($p['date']))?>" data-lessons="<?=(int)$p['lessons']?>" data-amount="<?=fmt_amount($p['amount'])?>" aria-label="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
            </td>
          </tr>
        <?php endforeach; endif;?>
      </tbody>
    </table>
    <?php if ($total_pays > 10): ?>
    <div class="load-more-container">
        <button class="btn gray sm" id="load-more-pays" data-offset="10">Загрузить еще</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<div id="modalVisit" class="modal" hidden> ... </div>
<div id="modalPay" class="modal" hidden> ... </div>
<div id="modalConfirm" class="modal" hidden> ... </div>
<div id="modalSuccess" class="modal" hidden> ... </div>
<div id="modalDeleteStudent" class="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true">
    <button class="modal-close js-close-delete-student" aria-label="Закрыть">✕</button>
    <h3>Удалить ученика?</h3>
    <p class="muted">Это действие необратимо. Будут удалены все данные об ученике, включая посещения и оплаты.</p>
    <p class="muted" style="margin-top:10px;">Для подтверждения, введите <b>22</b> в поле ниже:</p>
    <form id="deleteStudentForm" class="form" onsubmit="return false;">
      <input type="text" id="delete_confirm_answer" class="input" autocomplete="off" style="margin-top:4px;">
      <div class="actions">
        <button type="button" id="deleteStudentConfirmBtn" class="btn danger sm">Удалить навсегда</button>
        <button type="button" class="btn gray sm js-close-delete-student">Отмена</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  const uid = <?= (int)$user_id ?>;
  const csrf = <?= json_encode($csrfToken) ?>;
  const money = <?= (float)$student['money'] ?>;

  /* helpers */
  function showModal(m){ m.removeAttribute('hidden'); }
  function hideModal(m){ m.setAttribute('hidden',''); }
  function todayISO(){ return new Date().toISOString().slice(0,10); }

  /* --- MODAL HANDLING --- */
  const allModals = document.querySelectorAll('.modal');
  allModals.forEach(m => {
    m.addEventListener('click', (e) => { if (e.target === m) hideModal(m); });
    const closeButtons = m.querySelectorAll('.modal-close, .js-close-visit, .js-close-pay, .js-close-confirm, .js-close-delete-student');
    closeButtons.forEach(b => b.addEventListener('click', () => hideModal(m)));
  });
  window.addEventListener('keydown', (e) => { if (e.key==='Escape') allModals.forEach(hideModal); });

  document.getElementById('btnAddVisit')?.addEventListener('click', () => showModal(document.getElementById('modalVisit')));
  document.getElementById('btnAddPay')?.addEventListener('click', () => showModal(document.getElementById('modalPay')));
  document.getElementById('btnDeleteStudent')?.addEventListener('click', () => showModal(document.getElementById('modalDeleteStudent')));
  
  document.getElementById('btnEditStudent')?.addEventListener('click', () => {
    location.href = `/profile/edit_student.php?user_id=${uid}`;
  });

  /* --- AJAX FORM SUBMISSIONS --- */
  async function submitAjax(url, formData) {
    try {
      const resp = await fetch(url, { method:'POST', body:formData, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
      const j = await resp.json().catch(()=>null);
      if (!resp.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : `HTTP ${resp.status}`);
      return j;
    } catch(e) {
      alert('Ошибка: ' + e.message);
      throw e;
    }
  }

  // Удаление ученика
  document.getElementById('deleteStudentConfirmBtn')?.addEventListener('click', async () => {
    const answer = document.getElementById('delete_confirm_answer').value;
    if (answer !== '22') return alert('Неверный код подтверждения.');
    const form = new FormData();
    form.append('action', 'delete_student');
    form.append('user_id', uid);
    form.append('csrf', csrf);
    form.append('csrf_check_answer', answer);
    try {
      await submitAjax(location.pathname, form);
      alert('Ученик удален.');
      location.href = '/profile/';
    } catch(e){}
  });

  // Удаление посещений/оплат
  let pendingDelete = null;
  const confirmYes = document.getElementById('confirmYes');
  document.addEventListener('click', e => {
    const delVisitBtn = e.target.closest('.js-del-visit');
    const delPayBtn = e.target.closest('.js-del-pay');
    if (delVisitBtn) {
        pendingDelete = {type: 'visit', id: delVisitBtn.dataset.id, element: delVisitBtn.closest('tr')};
        document.getElementById('confirmText').textContent = `Удалить посещение от ${delVisitBtn.dataset.date}?`;
        showModal(document.getElementById('modalConfirm'));
    }
    if (delPayBtn) {
        pendingDelete = {type: 'pay', id: delPayBtn.dataset.id, element: delPayBtn.closest('tr')};
        document.getElementById('confirmText').textContent = `Удалить оплату от ${delPayBtn.dataset.date}?`;
        showModal(document.getElementById('modalConfirm'));
    }
  });
  
  confirmYes?.addEventListener('click', async () => {
    if (!pendingDelete) return;
    const form = new FormData();
    form.append('action', `delete_${pendingDelete.type}`);
    form.append('id', pendingDelete.id);
    form.append('user_id', uid);
    form.append('csrf', csrf);
    try {
        await submitAjax(location.pathname, form);
        pendingDelete.element.remove();
        hideModal(document.getElementById('modalConfirm'));
    } catch(e){}
  });


  /* --- PAGINATION --- */
  async function loadMore(type) {
      const btn = document.getElementById(`load-more-${type}`);
      const tbody = document.getElementById(`${type}-tbody`);
      if (!btn || !tbody) return;
      
      const offset = parseInt(btn.dataset.offset, 10);
      btn.disabled = true;
      btn.textContent = 'Загрузка...';

      const form = new FormData();
      form.append('action', `load_more_${type}`);
      form.append('offset', offset);
      form.append('user_id', uid);
      form.append('csrf', csrf);

      try {
          const resp = await fetch(location.pathname, { method: 'POST', body: form });
          const j = await resp.json();
          if (j.html) {
              tbody.insertAdjacentHTML('beforeend', j.html);
          }
          if (j.count < 15) {
              btn.parentElement.remove();
          } else {
              btn.dataset.offset = offset + j.count;
          }
      } catch (e) {
          btn.textContent = 'Ошибка загрузки';
      } finally {
          btn.disabled = false;
          btn.textContent = 'Загрузить еще';
      }
  }

  document.getElementById('load-more-visits')?.addEventListener('click', () => loadMore('visits'));
  document.getElementById('load-more-pays')?.addEventListener('click', () => loadMore('pays'));

})();
</script>
</body>
</html>
