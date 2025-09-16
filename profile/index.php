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

// должники
$deb = $con->query("
SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio,
       (COALESCE(COUNT(p.id),0)*8 - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0)) AS balance_lessons
FROM stud s
LEFT JOIN dates d ON d.user_id = s.user_id
LEFT JOIN pays  p ON p.user_id = s.user_id
GROUP BY s.user_id, fio
HAVING balance_lessons < 0
ORDER BY balance_lessons ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"><title>Главная — Tutor CRM</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
<div class="content">
  <div class="card">
    <h2>Кто приходит сегодня</h2>
    <?php if (!$today): ?>
      <p>Сегодня занятий нет.</p>
    <?php else: ?>
      <ul>
      <?php foreach ($today as $t): ?>
        <li>
          <a href="/profile/student.php?user_id=<?= (int)$t['user_id'] ?>"><?= htmlspecialchars($t['fio']) ?></a>
          <span class="badge"><?= htmlspecialchars(substr($t['time'],0,5)) ?></span>
        </li>
      <?php endforeach; ?>
      </ul>
      <p><a class="btn" href="/profile/attendance_today.php">Отметить присутствие за сегодня</a></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Должники</h2>
    <?php if (!$deb): ?>
      <p>Должников нет 🎉</p>
    <?php else: ?>
      <table>
        <tr><th>Ученик</th><th>Долг (уроков)</th></tr>
        <?php foreach ($deb as $r): ?>
          <tr>
            <td><a href="/profile/student.php?user_id=<?= (int)$r['user_id'] ?>"><?= htmlspecialchars($r['fio']) ?></a></td>
            <td style="color:#b00020;font-weight:700;"><?= abs((int)$r['balance_lessons']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
