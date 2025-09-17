<?php
// /profile/list.php  (упрощённая версия — убран поиск и кнопки фильтра)
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

// Параметр фильтра
$klass = trim($_GET['klass'] ?? '');

// Получаем список классов для фильтра
$res = $con->query("SELECT DISTINCT COALESCE(NULLIF(klass,''),'') AS klass FROM stud ORDER BY klass");
$classes = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['klass'] !== '') $classes[] = $r['klass'];
    }
    $res->close();
}

// Основной запрос — берём также stud.money (цена за урок)
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

// Фильтр по классу (отправляется автоматически при выборе)
if ($klass !== '') {
    $where[] = "s.klass = ?";
    $params[] = $klass;
    $types .= 's';
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

// CSRF token (если реализовано)
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
    /* Локальные стили для страницы списка */
    .filter-row { display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .filter-row .select { min-width:200px; padding:10px; border-radius:8px; border:1px solid var(--border); }
    .icon-btn { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:8px; border:1px solid var(--border); background:#fff; cursor:pointer; }
    .icon-btn:hover { box-shadow:0 6px 18px rgba(0,0,0,0.06); }
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
      <!-- Форма с автосабмитом при выборе класса -->
      <form id="filterForm" method="get" style="width:100%;">
        <select name="klass" class="select" onchange="this.form.submit()">
          <option value=""><?= htmlspecialchars('Выберите класс') ?></option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $c === $klass ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <table class="table" style="margin-top:12px;">
      <thead>
        <tr>
          <th>Имя</th>
          <th style="width:120px;">Класс</th>
          <th style="width:200px;text-align:right;">Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$students): ?>
          <tr><td colspan="3">Учеников не найдено.</td></tr>
        <?php else: foreach ($students as $s): ?>
          <tr>
            <td>
              <a href="/profile/student.php?user_id=<?= (int)$s['user_id'] ?>"><?= htmlspecialchars($s['fio']) ?></a>
              <div class="muted"><?= htmlspecialchars($s['phone'] ?? '') ?></div>
            </td>
            <td><?= htmlspecialchars($s['klass']) ?></td>
            <td class="td-actions">
              <!-- Просмотр -->
              <a class="icon-btn" title="Просмотреть карточку" href="/profile/student.php?user_id=<?= (int)$s['user_id'] ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="#0a5fb0"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
              </a>

              <!-- Редактировать (на student_edit.php) -->
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

</body>
</html>
