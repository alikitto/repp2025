<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { die('bad user_id'); }

// студент
$st = $con->prepare("SELECT * FROM stud WHERE user_id=? LIMIT 1");
$st->bind_param('i', $user_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();
if (!$student) { die('Ученик не найден'); }

// расписание (берём первые 3 слота для редактирования)
$sc = $con->prepare("SELECT weekday, time FROM schedule WHERE user_id=? ORDER BY weekday, time LIMIT 3");
$sc->bind_param('i', $user_id);
$sc->execute();
$slots = $sc->get_result()->fetch_all(MYSQLI_ASSOC);
$sc->close();

// префилл
function wd_name_full($n){$m=[1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];return $m[$n]??'';}
$day = ['', '', ''];
$time = ['', '', ''];
for ($i=0;$i<count($slots) && $i<3;$i++){
  $day[$i] = wd_name_full((int)$slots[$i]['weekday']);
  $time[$i] = substr($slots[$i]['time'],0,5);
}

function day_options($selected){
  $opts = ['','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота','Воскресенье'];
  $html = '';
  foreach ($opts as $opt) {
    $sel = ($opt === $selected) ? ' selected' : '';
    $label = $opt === '' ? '—' : $opt;
    $val = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
    $html .= "<option value=\"{$val}\"{$sel}>{$label}</option>";
  }
  return $html;
}

$fio = htmlspecialchars($student['lastname'].' '.$student['name']);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"><title>Редактировать — <?= $fio ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
<div class="content">
  <div class="card">
    <h2>Редактировать ученика — <?= $fio ?></h2>
    <p><a href="/profile/student.php?user_id=<?= $user_id ?>">&larr; Назад к карточке</a></p>
    <form method="post" action="/add/update.php?user_id=<?= $user_id ?>">
      <?= csrf_field() ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="flex:1 1 260px"><label>Фамилия</label><input name="lastname" value="<?= htmlspecialchars($student['lastname']) ?>" required></div>
        <div style="flex:1 1 260px"><label>Имя</label><input name="name" value="<?= htmlspecialchars($student['name']) ?>" required></div>
        <div style="flex:1 1 160px"><label>Класс</label><input name="klass" value="<?= htmlspecialchars($student['klass']) ?>"></div>
        <div style="flex:1 1 160px"><label>Телефон</label><input name="phone" value="<?= htmlspecialchars($student['phone']) ?>"></div>
        <div style="flex:1 1 160px"><label>Школа</label><input name="school" value="<?= htmlspecialchars($student['school']) ?>"></div>
        <div style="flex:1 1 160px"><label>Цена за занятие (опц.)</label><input type="number" step="0.01" min="0" name="money" value="<?= htmlspecialchars((string)$student['money']) ?>"></div>
      </div>
      <label>Родитель (ФИО)</label><input name="parentname" value="<?= htmlspecialchars($student['parentname']) ?>">
      <label>Родитель (контакт)</label><input name="parent" value="<?= htmlspecialchars($student['parent']) ?>">
      <label>Заметки</label><textarea name="note" rows="3"><?= htmlspecialchars($student['note']) ?></textarea>

      <h3>Расписание (до 3 слотов)</h3>
      <?php for ($i=1;$i<=3;$i++): $idx=$i-1; ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <div style="flex:1 1 200px">
            <label>День (<?= $i ?>)</label>
            <select name="day<?= $i ?>"><?= day_options($day[$idx]) ?></select>
          </div>
          <div style="flex:1 1 160px">
            <label>Время (<?= $i ?>)</label>
            <input type="time" name="time<?= $i ?>" value="<?= htmlspecialchars($time[$idx]) ?>">
          </div>
        </div>
      <?php endfor; ?>

      <p style="opacity:.8;font-size:13px">Примечание: если у ученика было >3 слотов, при сохранении будут оставлены только указанные тут слоты (до 3).</p>

      <br><button class="btn">Сохранить изменения</button>
    </form>
  </div>
</div>
</body>
</html>
