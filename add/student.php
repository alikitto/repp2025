<?php
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
session_start();
if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
  echo "<meta http-equiv='refresh' content='0;URL=/index.php' />"; exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"><title>Добавить ученика</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="/profile/css/style.css" rel="stylesheet">
</head>
<body>
<div class="content">
  <div class="card">
    <h2>Добавить ученика</h2>
    <form method="post" action="/add/save.php">
      <?= csrf_field() ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="flex:1 1 260px"><label>Фамилия</label><input name="lastname" required></div>
        <div style="flex:1 1 260px"><label>Имя</label><input name="name" required></div>
        <div style="flex:1 1 160px"><label>Класс</label><input name="klass"></div>
        <div style="flex:1 1 160px"><label>Телефон</label><input name="phone"></div>
        <div style="flex:1 1 160px"><label>Школа</label><input name="school"></div>
        <div style="flex:1 1 160px"><label>Цена за занятие (опц.)</label><input type="number" step="0.01" min="0" name="money" value="0"></div>
      </div>
      <label>Родитель (ФИО)</label><input name="parentname">
      <label>Родитель (контакт)</label><input name="parent">
      <label>Заметки</label><textarea name="note" rows="3"></textarea>
      <h3>Расписание (до 3 слотов)</h3>
      <?php function dayopts(){return '
        <option value="">—</option>
        <option value="Понедельник">Понедельник</option>
        <option value="Вторник">Вторник</option>
        <option value="Среда">Среда</option>
        <option value="Четверг">Четверг</option>
        <option value="Пятница">Пятница</option>
        <option value="Суббота">Суббота</option>
        <option value="Воскресенье">Воскресенье</option>'; } ?>
      <?php for ($i=1;$i<=3;$i++): ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <div style="flex:1 1 200px"><label>День (<?= $i ?>)</label><select name="day<?= $i ?>"><?= dayopts() ?></select></div>
          <div style="flex:1 1 160px"><label>Время (<?= $i ?>)</label><input type="time" name="time<?= $i ?>"></div>
        </div>
      <?php endfor; ?>
      <br><button class="btn">Создать ученика</button>
    </form>
  </div>
</div>
</body>
</html>
