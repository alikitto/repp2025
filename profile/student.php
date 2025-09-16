<?php
if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params(2592000);
    ini_set('session.gc_maxlifetime', 2592000);
    session_start();
}
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
    echo "<meta http-equiv='refresh' content='0;URL=/index.php' />"; exit;
}

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { die('bad user_id'); }

// студент
$st = $con->prepare("SELECT * FROM stud WHERE user_id=? LIMIT 1");
$st->bind_param('i', $user_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();
if (!$student) { die('Ученик не найден'); }

// расписание
$sc = $con->prepare("SELECT weekday, time FROM schedule WHERE user_id=? ORDER BY weekday, time");
$sc->bind_param('i', $user_id);
$sc->execute();
$schedule = $sc->get_result()->fetch_all(MYSQLI_ASSOC);
$sc->close();

// посещения
$vis = $con->prepare("SELECT dates_id, dates, visited FROM dates WHERE user_id=? ORDER BY dates DESC LIMIT 50");
$vis->bind_param('i', $user_id);
$vis->execute();
$visits = $vis->get_result()->fetch_all(MYSQLI_ASSOC);
$vis->close();

// оплаты
$py = $con->prepare("SELECT id, date FROM pays WHERE user_id=? ORDER BY date DESC LIMIT 50");
$py->bind_param('i', $user_id);
$py->execute();
$pays = $py->get_result()->fetch_all(MYSQLI_ASSOC);
$py->close();

// баланс
$q = $con->prepare("
SELECT
  (COALESCE(COUNT(p.id),0)*8 - COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0)) AS balance_lessons,
  COALESCE(SUM(CASE WHEN d.visited=1 THEN 1 ELSE 0 END),0) AS visits_total,
  COALESCE(COUNT(p.id),0) AS pays_total
FROM stud s
LEFT JOIN dates d ON d.user_id = s.user_id
LEFT JOIN pays  p ON p.user_id = s.user_id
WHERE s.user_id = ?");
$q->bind_param('i', $user_id);
$q->execute();
$balance = $q->get_result()->fetch_assoc();
$q->close();

function wd_name($n) { return [1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'][$n] ?? $n; }
$fio = htmlspecialchars($student['lastname'].' '.$student['name']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"><title><?= $fio ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
<div class="content">
  <div class="card">
    <h2><?= $fio ?></h2>
    <p><a class="btn" href="/profile/student_edit.php?user_id=<?= $user_id ?>">Редактировать</a></p>
    <p>Класс: <?= htmlspecialchars($student['klass'] ?: '—') ?>,
       Телефон: <?= htmlspecialchars($student['phone'] ?: '—') ?>,
       Школа: <?= htmlspecialchars($student['school'] ?: '—') ?><br>
       Родитель: <?= htmlspecialchars($student['parentname'] ?: '—') ?> (<?= htmlspecialchars($student['parent'] ?: '—') ?>)
    </p>
    <p>
    <?php
      $bal = (int)$balance['balance_lessons'];
      if ($bal < 0) echo '<b style="color:#b00020">Долг: '.abs($bal).'</b>';
      elseif ($bal > 0) echo '<b style="color:#0a5fb0">Оплачено вперёд: '.$bal.'</b>';
      else echo '<b style="color:#0a7b34">Всё ок</b>';
    ?>
     &nbsp;|&nbsp; Посещений: <?= (int)$balance['visits_total'] ?> | Оплат: <?= (int)$balance['pays_total'] ?>
    </p>

    <h3>Расписание</h3>
    <?php if (!$schedule): ?>
      <p>Слотов нет.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($schedule as $s): ?>
          <li><?= wd_name((int)$s['weekday']) ?> — <?= htmlspecialchars(substr($s['time'],0,5)) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Добавить посещение</h3>
    <form action="/add/dates.php?user_id=<?= $user_id ?>" method="POST">
      <?= csrf_field() ?>
      <label>Дата</label><br>
      <input type="date" name="dataa" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
      <label style="display:block;margin-top:6px;"><input type="checkbox" name="visited" value="1" checked> Пришёл</label>
      <br><button class="btn">Сохранить</button>
    </form>
  </div>

  <div class="card">
    <h3>Добавить оплату</h3>
    <form action="/add/pays.php?user_id=<?= $user_id ?>" method="POST">
      <?= csrf_field() ?>
      <label>Дата оплаты</label><br>
      <input type="date" name="dataa" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
      <br><button class="btn">Добавить оплату</button>
    </form>
  </div>

  <div class="card">
    <h3>Опасная зона</h3>
    <form action="/add/del_stud.php?user_id=<?= $user_id ?>" method="POST" onsubmit="return confirm('Удалить ученика и все его данные?');">
      <?= csrf_field() ?>
      <button class="btn-danger">Удалить ученика</button>
    </form>
  </div>

  <div class="card">
    <h3>Последние посещения</h3>
    <?php if (!$visits): ?><p>Нет посещений</p>
    <?php else: ?>
      <table><tr><th>Дата</th><th>Статус</th><th></th></tr>
        <?php foreach ($visits as $v): ?>
          <tr>
            <td><?= htmlspecialchars($v['dates']) ?></td>
            <td><?= ((int)$v['visited']===1?'Пришёл':'Не пришёл') ?></td>
            <td>
              <form action="/add/del_dates.php?user_id=<?= $user_id ?>" method="POST" onsubmit="return confirm('Удалить посещение?');">
                <?= csrf_field() ?>
                <input type="hidden" name="ids" value="<?= (int)$v['dates_id'] ?>">
                <button class="btn-danger">Удалить</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Последние оплаты</h3>
    <?php if (!$pays): ?><p>Нет оплат</p>
    <?php else: ?>
      <table><tr><th>Дата</th><th></th></tr>
        <?php foreach ($pays as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['date']) ?></td>
            <td>
              <form action="/add/del_pays.php?user_id=<?= $user_id ?>" method="POST" onsubmit="return confirm('Удалить оплату?');">
                <?= csrf_field() ?>
                <input type="hidden" name="ids" value="<?= (int)$p['id'] ?>">
                <button class="btn-danger">Удалить</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
