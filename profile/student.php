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
            $con->prepare("DELETE FROM dates WHERE user_id=?")->execute([$uid]);
            $con->prepare("DELETE FROM pays WHERE user_id=?")->execute([$uid]);
        }
        echo json_encode(['ok'=>$ok?true:false]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'unknown_action']);
    exit;
}

/* ---------- Получение данных ученика и статистики ---------- */
$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { http_response_code(400); echo "Bad user_id"; exit; }

$st = $con->prepare("SELECT user_id, lastname, name, klass, COALESCE(money,0) AS money FROM stud WHERE user_id = ?");
$st->bind_param('i', $user_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();
if (!$student) { http_response_code(404); echo "Ученика не найдено"; exit; }

$st = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND visited=1");
$st->bind_param('i', $user_id);
$st->execute(); $st->bind_result($visits_count); $st->fetch(); $st->close();

$st = $con->prepare("SELECT COALESCE(SUM(lessons),0) AS paid_lessons, COUNT(*) AS pays_count FROM pays WHERE user_id=?");
$st->bind_param('i', $user_id);
$st->execute(); $st->bind_result($paid_lessons, $pays_count); $st->fetch(); $st->close();

$balance_lessons = (int)$paid_lessons - (int)$visits_count;

$v = $_GET['v'] ?? 'all';
$v = in_array($v, ['all','1','0'], true) ? $v : 'all';

$sqlVisits = "SELECT dates_id, user_id, `dates`, COALESCE(visited,0) AS visited FROM dates WHERE user_id=? ";
if ($v === '1') { $sqlVisits .= "AND visited=1 "; }
elseif ($v === '0') { $sqlVisits .= "AND visited=0 "; }
$sqlVisits .= "ORDER BY `dates` DESC, dates_id DESC";
$st = $con->prepare($sqlVisits);
$st->bind_param('i', $user_id);
$st->execute();
$visits = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

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
        .badge.warn{ background:#fffbe6; border-color:#ffe69c; }
        .badge.negative{ background:#fff3f3; border-color:#ffd4d4; }

        .toolbar { display:flex; gap:8px; margin-left:auto; align-items:flex-start; flex-wrap:wrap; }
        .btn { padding:var(--btn-pad); border-radius:var(--btn-radius); border:1px solid var(--border); background:#fff; cursor:pointer; line-height:1; font-size:14px; }
        .btn.primary { background:#0a5fb0; color:#fff; border-color:#0a5fb0; }
        .btn.gray { background:#f1f3f5; color:#333; border-color:#e1e5ea; }
        .btn.pay { background:#9be7a0; border-color:#7bc885; color:#033; }
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

        /* Мобайл */
        @media (max-width:680px){
            .toolbar { width:100%; flex-wrap:nowrap; gap:6px; }
            .toolbar .btn { flex:1 1 0; min-width:0; white-space:nowrap; }
            .page-head h2 { font-size:20px; }
            .table th, .table td { padding:8px 10px; font-size:14px; }
            .tab { font-size:12px; padding:5px 7px; }
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

    <!-- Новый блок "Управление" -->
    <div class="card section">
        <h3>Управление</h3>
        <div style="display:flex; gap:12px; margin-bottom:16px;">
            <button class="btn gray sm" id="btnEditStudent">✎ Редактировать</button>
            <button class="btn danger sm" id="btnDeleteStudent">❗ Удалить ученика</button>
        </div>
        <div style="display:flex; gap:12px;">
            <button class="btn pay sm" id="btnAddVisit">+ Посещение</button>
            <button class="btn gray sm" id="btnAddPay">+ Оплата</button>
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
                    <th style="width:130px;">Дата</th>
                    <th style="width:120px;">Статус</th>
                    <th style="width:120px;">Действие</th>
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
                            <button class="icon-btn js-del-visit"
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

        <?php $sum_lessons = 0; $sum_amount = 0.0;
          foreach ($pays as $p){ $sum_lessons += (int)$p['lessons']; $sum_amount += (float)$p['amount']; } ?>

        <table class="table">
            <thead>
                <tr>
                    <th style="width:130px;">Дата</th>
                    <th style="width:100px;">Уроков</th>
                    <th style="width:120px;">Сумма, AZN</th>
                    <th style="width:120px;">Действие</th>
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
                            <button class="icon-btn js-del-pay"
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
