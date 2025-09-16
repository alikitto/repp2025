<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

$wd = (int)date('N');
$sql = $con->prepare("
SELECT s.user_id, CONCAT(s.lastname,' ',s.name) AS fio, sc.time
FROM schedule sc
JOIN stud s ON s.user_id = sc.user_id
WHERE sc.weekday=?
ORDER BY sc.time");
$sql->bind_param('i',$wd);
$sql->execute();
$list = $sql->get_result()->fetch_all(MYSQLI_ASSOC);
$sql->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"><title>Отметить присутствие</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
<div class="content">
  <div class="card">
    <h2>Отметить присутствие (<?= htmlspecialchars(date('d.m.Y')) ?>)</h2>
    <?php if (!$list): ?>
      <p>Сегодня по расписанию никого.</p>
    <?php else: ?>
    <form method="post" action="/profile/new2.php">
      <?= csrf_field() ?>
      <table>
        <tr><th>Время</th><th>Ученик</th><th>Пришёл</th></tr>
        <?php foreach ($list as $r): ?>
          <tr>
            <td><?= htmlspecialchars(substr($r['time'],0,5)) ?></td>
            <td><?= htmlspecialchars($r['fio']) ?></td>
            <td style="text-align:center;">
              <input type="checkbox" name="user_ids[]" value="<?= (int)$r['user_id'] ?>">
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <br><button class="btn">Сохранить отмеченных</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
