<?php
// /profile/students.php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

// параметры фильтра/поиска
$klass = trim($_GET['klass'] ?? '');
$q     = trim($_GET['q'] ?? '');

// --- получить список доступных классов для фильтра ---
$res = $con->query("SELECT DISTINCT COALESCE(NULLIF(klass,''),'') AS klass FROM stud ORDER BY klass");
$classes = [];
if ($res) {
  while ($r = $res->fetch_assoc()) {
    if ($r['klass'] !== '') $classes[] = $r['klass'];
  }
  $res->close();
}

// --- основной запрос: имя, класс, баланс уроков
// balance_lessons = (COUNT(p.id) * 8) - SUM(visited)
// note: используем LEFT JOIN, GROUP BY
$sql = "
SELECT s.user_id,
       CONCAT(s.lastname,' ',s.name) AS fio,
       COALESCE(NULLIF(s.klass,''),'—') AS klass,
       COALESCE(COUNT(DISTINCT p.id),0) * 8
         - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0) AS balance_lessons,
       s.phone
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
// поиск по имени
if ($q !== '') {
  $where[] = "(s.name LIKE ? OR s.lastname LIKE ? OR CONCAT(s.lastname,' ',s.name) LIKE ? OR s.phone LIKE ?)";
  $like = "%$q%";
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  $types .= 'ssss';
}

if ($where) $sql .= " WHERE " . implode(' AND ', $where);

$sql .= " GROUP BY s.user_id, fio, klass, s.phone ORDER BY klass ASC, fio ASC";

// подготовка и выполнение
$stmt = $con->prepare($sql);
if (!$stmt) {
  die("DB error: " . $con->error);
}
if ($params) {
  // bind params dynamically
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
?>

<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Список учеников — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
  <style>
    /* дополнительные локальные стили для страницы */
    .filter-row { display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .filter-row .search { flex:1; min-width:160px; }
    .dot { width:12px; height:12px; border-radius:50%; display:inline-block; margin-right:8px; vertical-align:middle; box-shadow:0 2px 6px rgba(0,0,0,.06); }
    .dot.green{ background:#12a150; }
    .dot.red{ background:#b00020; }
    .dot.gray{ background:#9aa0a6; }
    .students-actions { display:flex; gap:8px; align-items:center; margin-left:auto; }
    @media (max-width:720px){ .filter-row { flex-direction:column; align-items:stretch; } .students-actions{ margin-left:0; } }
    .small-muted{ color:var(--muted); font-size:13px; }
    .csv-link{ font-size:13px; color:var(--brand); text-decoration:underline; }
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
        <select name="klass" onchange="this.form.submit()" style="padding:10px;border-radius:8px;border:1px solid var(--border);">
          <option value="">— Все классы —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $c=== $klass ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>

        <input class="search input" type="search" name="q" placeholder="Поиск по имени или телефону" value="<?= htmlspecialchars($q) ?>">

        <div class="students-actions">
          <button type="submit" class="btn btn-ghost">Применить</button>
          <a class="btn" href="/profile/students.php">Сброс</a>
          <a class="csv-link" href="/profile/students_export.php?<?= http_build_query(['klass'=>$klass,'q'=>$q]) ?>">Экспорт CSV</a>
        </div>
      </form>
    </div>

    <table class="table" style="margin-top:12px;">
      <thead>
        <tr>
          <th>Имя</th>
          <th style="width:120px;">Класс</th>
          <th style="width:220px;">Задолженность</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$students): ?>
          <tr><td colspan="3">Учеников не найдено.</td></tr>
        <?php else: foreach ($students as $s): 
            $balance = (int)$s['balance_lessons']; 
            if ($balance < 0) { $state = 'debt'; $num = abs($balance); $dot='red'; $label = "{$num} урок(ов) должник"; }
            elseif ($balance > 0) { $state = 'prepaid'; $num = $balance; $dot='green'; $label = "Предоплата: {$num} ур."; }
            else { $state = 'ok'; $num = 0; $dot='gray'; $label = "Баланс: 0"; }
        ?>
          <tr>
            <td>
              <a href="/profile/student.php?user_id=<?= (int)$s['user_id'] ?>"><?= htmlspecialchars($s['fio']) ?></a>
              <div class="small-muted"><?= htmlspecialchars($s['phone'] ?? '') ?></div>
            </td>
            <td><?= htmlspecialchars($s['klass']) ?></td>
            <td>
              <span class="dot <?= $dot ?>" aria-hidden="true"></span>
              <strong style="vertical-align:middle"><?= ($state==='debt' ? "<span style='color:var(--danger)'>" . ($num) . " ур.</span>" : "<span style='color:#0a5fb0'>{$num} ур.</span>") ?></strong>
              <div class="small-muted" style="margin-top:4px;"><?= htmlspecialchars($label) ?></div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</body>
</html>
