<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

// Получаем POST-данные
$user_id = (int)($_POST['user_id'] ?? 0);
$date    = $_POST['date'] ?? date('Y-m-d');
$lessons = (int)($_POST['lessons'] ?? 8);
$amount  = trim($_POST['amount'] ?? '');

// Проверка
if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad user_id']);
    exit;
}

// Если сумма не передана, берём money из stud и считаем
if ($amount === '' || $amount <= 0) {
    $res = $con->prepare("SELECT money FROM stud WHERE user_id=?");
    $res->bind_param('i', $user_id);
    $res->execute();
    $res->bind_result($money);
    if ($res->fetch()) {
        $amount = $money * $lessons;
    }
    $res->close();
}

// Сохраняем оплату
$ins = $con->prepare("INSERT INTO pays (user_id, date, lessons, amount) VALUES (?,?,?,?)");
$ins->bind_param('isid', $user_id, $date, $lessons, $amount);
$ok = $ins->execute();
$ins->close();

header('Content-Type: application/json');
if ($ok) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $con->error]);
}
