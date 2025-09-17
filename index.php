<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

$wd = (int)date('N');

// кто сегодня
$st = $con->prepare("
SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
FROM schedule sc
JOIN stud s ON s.user_id = sc.user_id
WHERE sc.weekday=?
ORDER BY sc.time");
$st->bind_param('i',$wd);
$st->execute();
$today = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// должники: >= 8 уроков долга
$deb = $con->query("
SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio,
       (COALESCE(COUNT(p.id),0)*8 - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0)) AS balance_lessons
FROM stud s
LEFT JOIN dates d ON d.user_id = s.user_id
LEFT JOIN pays  p ON p.user_id = s.user_id
GROUP BY s.user_id, fio
HAVING balance_lessons <= -8
ORDER BY balance_lessons ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Главная — Tutor CRM</title>
  <link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>

<?php $active='home'; require __DIR__ . '/../common/nav.php'; ?>

<div class="content">
  <div class="card">
    <h2>Кто приходит сегодня</h2>
    <?php if (!$today): ?>
      <p>Сегодня занятий нет.</p>
    <?php else: ?>
      <table class="table today">
        <thead><tr><th>Имя</th><th>Время</th></tr></thead>
        <tbody>
        <?php foreach ($today as $t): ?>
          <tr>
            <td><a class="link-strong" href="/profile/student.php?user_id=<?= (int)$t['user_id'] ?>"><?= htmlspecialchars($t['fio']) ?></a></td>
            <td class="time-cell"><?= htmlspecialchars(substr($t['time'],0,5)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Должники</h2>
    <?php if (!$deb): ?>
      <p>Должников нет 🎉</p>
    <?php else: ?>
      <table class="table debtors">
        <thead><tr><th>Ученик</th><th style="width:160px;">Долг (уроков)</th></tr></thead>
        <tbody>
        <?php foreach ($deb as $r): ?>
          <tr>
            <td><a class="link-strong" href="/profile/student.php?user_id=<?= (int)$r['user_id'] ?>"><?= htmlspecialchars($r['fio']) ?></a></td>
            <td class="debt"><?= abs((int)$r['balance_lessons']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
