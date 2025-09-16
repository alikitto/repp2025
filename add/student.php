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
        <form action="process_student.php" method="POST">
            <div class="form-group">
                <label for="firstname">Имя</label>
                <input type="text" id="firstname" name="firstname" required>
            </div>
            <div class="form-group">
                <label for="lastname">Фамилия</label>
                <input type="text" id="lastname" name="lastname" required>
            </div>

            <!-- Расписание -->
            <div class="form-group">
                <label for="schedule1">Расписание (24-часовой формат)</label>
                <div class="time-picker">
                    <input type="time" id="schedule1" name="schedule1" required>
                    <input type="time" id="schedule2" name="schedule2">
                    <input type="time" id="schedule3" name="schedule3">
                </div>
            </div>

            <div class="form-group">
                <button type="submit" class="btn">Создать ученика</button>
            </div>
        </form>
    </div>
</body>
</html>
