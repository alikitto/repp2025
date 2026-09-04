<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/util.php';

$klass = trim($_GET['klass'] ?? '');
$q = trim($_GET['q'] ?? '');
$list_tab = ($_GET['tab'] ?? '') === 'left' ? 'left' : 'active';
$archived = $list_tab === 'left' ? 1 : 0;

$tid = teacher_id();
$res = $con->prepare("SELECT DISTINCT COALESCE(NULLIF(klass,''),'') AS klass FROM stud WHERE teacher_id=? AND archived=? ORDER BY klass");
$res->bind_param('ii', $tid, $archived);
$res->execute();
$res = $res->get_result();
$classes = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['klass'] !== '') $classes[] = $r['klass'];
    }
}

$sql = "
SELECT s.user_id,
       CONCAT(TRIM(s.lastname),' ',s.name) AS fio,
       COALESCE(NULLIF(s.klass,''),'—') AS klass,
       s.pay_mode,
       " . student_balance_expr() . " AS balance_lessons
FROM stud s
" . student_balance_joins() . "
";
$where = ['s.teacher_id=?', 's.archived=?'];
$params = [$tid, $archived];
$types = 'ii';
if ($klass !== '') { $where[] = "s.klass = ?"; $params[] = $klass; $types .= 's'; }
if ($q !== '') {
    $where[] = "(s.name LIKE ? OR s.lastname LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like);
    $types .= 'ss';
}
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY klass ASC, fio ASC";

$stmt = $con->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$cnt = $con->prepare("SELECT COUNT(*) AS n FROM stud WHERE teacher_id=? AND archived=?");
$cnt->bind_param('ii', $tid, $archived);
$cnt->execute();
$total_students = (int)$cnt->get_result()->fetch_assoc()['n'];
$cnt->close();
$active = 'list';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Список учеников — Tutor CRM</title>
  <link href="<?= asset('/profile/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card students-card">
    <div class="toolbar">
      <div class="students-head">
        <h1>Ученики</h1>
        <p class="students-total"><?= $list_tab === 'left' ? 'Ушедшие' : 'Всего' ?>: <?= $total_students ?></p>
      </div>
      <?php if ($list_tab === 'active'): ?>
      <a class="btn sm" href="/add/student.php">Добавить</a>
      <?php endif; ?>
    </div>

    <div class="settings-tabs">
      <a href="/profile/list.php<?= $q !== '' ? '?'.http_build_query(['q'=>$q]) : '' ?>" class="<?= $list_tab==='active'?'active':'' ?>">Активные</a>
      <a href="/profile/list.php?<?= http_build_query(array_filter(['tab'=>'left','q'=>$q])) ?>" class="<?= $list_tab==='left'?'active':'' ?>">Ушедшие</a>
    </div>

    <form method="get" class="toolbar students-tools" id="filterForm">
      <?php if ($list_tab === 'left'): ?><input type="hidden" name="tab" value="left"><?php endif; ?>
      <input class="input search" type="search" name="q" id="q" value="<?= h($q) ?>" placeholder="Имя" enterkeyhint="search">
      <select name="klass" class="select" id="klassFilter">
        <option value="">Все классы</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= h($c) ?>" <?= $c === $klass ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if (!$students): ?>
      <p class="muted students-empty"><?= $list_tab === 'left' ? 'Ушедших нет.' : 'Учеников не найдено.' ?></p>
    <?php else: ?>
      <table class="table students">
        <thead>
          <tr>
            <th>Имя</th>
            <th class="num">Класс</th>
            <th class="num">Баланс</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): $bal = (int)$s['balance_lessons']; ?>
            <tr class="js-row" data-id="<?= (int)$s['user_id'] ?>" data-q="<?= h(mb_strtolower($s['fio'])) ?>">
              <td>
                <a href="/profile/student.php?user_id=<?= (int)$s['user_id'] ?>"><?= h($s['fio']) ?></a>
              </td>
              <td class="num"><?= h($s['klass']) ?></td>
              <td class="num"><span class="bal <?= balance_tone($bal, student_pay_mode($s['pay_mode'] ?? 'prepaid')) ?>"><?= $bal > 0 ? '+'.$bal : $bal ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<script <?= csp_nonce_attr() ?>>
document.getElementById('klassFilter')?.addEventListener('change', function(){ this.form.submit(); });
document.getElementById('q')?.addEventListener('input', function(){
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('.js-row').forEach(el => {
    el.style.display = !q || (el.dataset.q||'').includes(q) ? '' : 'none';
  });
});
</script>
</body>
</html>
