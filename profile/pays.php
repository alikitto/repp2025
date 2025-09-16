<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';

$sql = "
SELECT
  s.user_id,
  CONCAT(s.lastname, ' ', s.name) AS fio,
  COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END), 0) AS visits_total,
  COALESCE(COUNT(p.id), 0) AS pays_total,
  (COALESCE(COUNT(p.id),0)*8 - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0)) AS balance_lessons,
  MAX(p.date) AS last_pay_date
FROM stud s
LEFT JOIN dates d ON d.user_id = s.user_id
LEFT JOIN pays  p ON p.user_id = s.user_id
GROUP BY s.user_id, fio
ORDER BY fio;
";
$rows = $con->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8"/>
  <title>Оплаты и балансы — Tutor CRM</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
  <div class="content">
    <div class="card">
      <h1>Оплаты и балансы (1 оплата = 8 уроков)</h1>
      <table>
        <thead>
          <tr>
            <th>Ученик</th>
            <th>Посещений</th>
            <th>Оплат</th>
            <th>Баланс (уроков)</th>
            <th>Статус</th>
            <th>Последняя оплата</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $bal = (int)$r['balance_lessons'];
          if ($bal < 0)      { $status = '<span style="color:#b00020;font-weight:700">Долг: '.abs($bal).'</span>'; }
          elseif ($bal > 0 ) { $status = '<span style="color:#0a5fb0;font-weight:600">Оплачено вперёд: '.$bal.'</span>'; }
          else               { $status = '<span style="color:#0a7b34;font-weight:600">Всё ок</span>'; }
        ?>
          <tr>
            <td><a href="/profile/student.php?user_id=<?= (int)$r['user_id']?>"><?=htmlspecialchars($r['fio'])?></a></td>
            <td><?= (int)$r['visits_total'] ?></td>
            <td><?= (int)$r['pays_total'] ?></td>
            <td><?= $bal ?></td>
            <td><?= $status ?></td>
            <td><?= $r['last_pay_date'] ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
