<?php
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
session_start();
if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
  echo "<meta http-equiv='refresh' content='0;URL=/index.php' />"; exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить ученика</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="navbar">
        <a href="index.php" class="btn">На главную</a>
    </div>

    <div class="content">
        <h1>Добавить ученика</h1>
        <form action="save.php" method="POST">
            <div class="form-group">
                <label for="firstname">Имя</label>
                <input type="text" id="firstname" name="name" required>
            </div>
            <div class="form-group">
                <label for="lastname">Фамилия</label>
                <input type="text" id="lastname" name="lastname" required>
            </div>

            <!-- Расписание -->
            <div class="form-group">
                <label for="schedule1">Расписание (24-часовой формат)</label>
                <div class="time-picker">
                    <input type="time" id="schedule1" name="time1" required>
                    <select name="day1" required>
                        <option value="Понедельник">Понедельник</option>
                        <option value="Вторник">Вторник</option>
                        <option value="Среда">Среда</option>
                        <option value="Четверг">Четверг</option>
                        <option value="Пятница">Пятница</option>
                        <option value="Суббота">Суббота</option>
                        <option value="Воскресенье">Воскресенье</option>
                    </select>

                    <input type="time" id="schedule2" name="time2">
                    <select name="day2">
                        <option value="Понедельник">Понедельник</option>
                        <option value="Вторник">Вторник</option>
                        <option value="Среда">Среда</option>
                        <option value="Четверг">Четверг</option>
                        <option value="Пятница">Пятница</option>
                        <option value="Суббота">Суббота</option>
                        <option value="Воскресенье">Воскресенье</option>
                    </select>

                    <input type="time" id="schedule3" name="time3">
                    <select name="day3">
                        <option value="Понедельник">Понедельник</option>
                        <option value="Вторник">Вторник</option>
                        <option value="Среда">Среда</option>
                        <option value="Четверг">Четверг</option>
                        <option value="Пятница">Пятница</option>
                        <option value="Суббота">Суббота</option>
                        <option value="Воскресенье">Воскресенье</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="btn">Создать ученика</button>
            </div>
        </form>
    </div>
</body>
</html>
