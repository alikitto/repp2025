<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

// 1. Получаем и проверяем ID ученика
$uid = (int)($_POST['user_id'] ?? 0);
if ($uid <= 0) {
    die('Неверный ID ученика');
}

// 2. Получаем остальные данные из формы
$name  = trim($_POST['name'] ?? '');
$klass = trim($_POST['klass'] ?? '');
$money = (float)($_POST['money'] ?? 0);
$days  = $_POST['day'] ?? [];  // Расписание приходит как массив
$times = $_POST['time'] ?? [];

if ($name === '') {
    die('Укажите имя');
}

function wd_to_num($s) {
    $m = ['Понедельник' => 1, 'Вторник' => 2, 'Среда' => 3, 'Четверг' => 4, 'Пятница' => 5, 'Суббота' => 6, 'Воскресенье' => 7];
    return $m[$s] ?? 0;
}

mysqli_begin_transaction($con);
try {
    // 3. Обновляем основную информацию в таблице `stud`
    $stmt = $con->prepare("UPDATE stud SET name=?, klass=?, money=? WHERE user_id=?");
    $stmt->bind_param('ssdi', $name, $klass, $money, $uid);
    $stmt->execute();
    $stmt->close();

    // 4. Полностью обновляем расписание: сначала удаляем старое, потом вставляем новое
    $del_stmt = $con->prepare("DELETE FROM schedule WHERE user_id = ?");
    $del_stmt->bind_param('i', $uid);
    $del_stmt->execute();
    $del_stmt->close();

    $ins_stmt = $con->prepare("INSERT INTO schedule (user_id, weekday, time) VALUES (?, ?, ?)");
    for ($i = 0; $i < count($days); $i++) {
        $day = $days[$i];
        $time = $times[$i] ?? '';
        $wd = wd_to_num($day);
        if ($wd >= 1 && $wd <= 7 && $time !== '') {
            $ins_stmt->bind_param('iis', $uid, $wd, $time);
            $ins_stmt->execute();
        }
    }
    $ins_stmt->close();

    mysqli_commit($con);

    // 5. Устанавливаем flash-сообщение и перенаправляем обратно на карточку
    $_SESSION['flash_updated'] = ['name' => $name];
    header("Location: /profile/student.php?user_id=" . $uid);
    exit;

} catch (Throwable $e) {
    mysqli_rollback($con);
    http_response_code(500);
    // Для отладки можно вывести ошибку: error_log($e->getMessage());
    echo 'Ошибка сохранения ученика';
}
