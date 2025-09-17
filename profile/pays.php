<?php
// /add/pays.php - безопасная версия: сервер сам применяет defaults и проверяет данные
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

// Получаем вход
$user_id = (int)($_POST['user_id'] ?? 0);
$date    = $_POST['date'] ?? date('Y-m-d');
$lessons_in = isset($_POST['lessons']) ? (int)$_POST['lessons'] : 0;
$amount_in  = isset($_POST['amount']) ? trim($_POST['amount']) : '';

if ($user_id <= 0) {
    http_response_code(400);
    die('bad user_id');
}

// Получаем цену пакета из stud.money
$st = $con->prepare("SELECT COALESCE(money,0) AS money FROM stud WHERE user_id = ?");
$st->bind_param('i', $user_id);
$st->execute();
$res = $st->get_result()->fetch_assoc();
$st->close();

$price = isset($res['money']) ? (float)$res['money'] : 0.0;
// по умолчанию lessons = 8
$lessons = ($lessons_in > 0) ? $lessons_in : 8;

// amount: если пришла валидная сумма — используем её; иначе используем stud.money
$amount = 0.0;
if ($amount_in !== '') {
    // нормализуем запятую -> точка и убираем лишние символы
    $norm = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $amount_in));
    if ($norm !== '' && is_numeric($norm)) {
        $amount = (float)$norm;
    } else {
        http_response_code(400);
        die('Некорректная сумма оплаты');
    }
} else {
    if ($price > 0) {
        $amount = $price;
    } else {
        http_response_code(400);
        die('Цена пакета не задана в карточке ученика. Установите поле "Оплата" для ученика или введите сумму вручную.');
    }
}

// Сохраняем в транзакции
mysqli_begin_transaction($con);
try {
    $ins = $con->prepare("INSERT INTO pays (user_id, dates, lessons, amount) VALUES (?, ?, ?, ?)");
    if (!$ins) throw new RuntimeException("Prepare failed: " . $con->error);
    $ins->bind_param('isid', $user_id, $date, $lessons, $amount);
    if (!$ins->execute()) throw new RuntimeException("Execute failed: " . $ins->error);
    $ins->close();

    mysqli_commit($con);

    header("Location: /profile/student.php?user_id={$user_id}&ok_pay=1");
    exit;
} catch (Throwable $e) {
    mysqli_rollback($con);
    error_log("add/pays.php error: " . $e->getMessage());
    http_response_code(500);
    echo "Server error";
    exit;
}
