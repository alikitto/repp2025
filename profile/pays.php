<?php
// /add/pays.php - безопасный апдейт: сервер валидирует, вставляет и возвращает JSON при AJAX
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check(); // если у тебя есть CSRF функция

// helper to send JSON
function json_resp($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
$date    = $_POST['date'] ?? date('Y-m-d');
$lessons_in = isset($_POST['lessons']) ? (int)$_POST['lessons'] : 0;
$amount_in  = isset($_POST['amount']) ? trim($_POST['amount']) : '';

// basic validation
if ($user_id <= 0) {
    // если AJAX — вернуть JSON, иначе показать текст
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) json_resp(400, ['ok'=>false,'error'=>'bad user_id']);
    die('bad user_id');
}

// получаем price (s.money) из stud
$st = $con->prepare("SELECT COALESCE(money,0) AS money FROM stud WHERE user_id = ?");
if (!$st) {
    error_log("add/pays.php: prepare failed (select stud): " . $con->error);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) json_resp(500, ['ok'=>false,'error'=>'DB prepare failed']);
    die('Server error');
}
$st->bind_param('i', $user_id);
$st->execute();
$res = $st->get_result()->fetch_assoc();
$st->close();

$price = isset($res['money']) ? (float)$res['money'] : 0.0;

// lessons default = 8
$lessons = ($lessons_in > 0) ? $lessons_in : 8;

// normalize amount if provided
$amount = 0.0;
if ($amount_in !== '') {
    $norm = str_replace(',', '.', preg_replace('/[^\d,\.]/', '', $amount_in));
    if ($norm !== '' && is_numeric($norm)) {
        $amount = (float)$norm;
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) json_resp(400, ['ok'=>false,'error'=>'Некорректная сумма']);
        die('Некорректная сумма оплаты');
    }
} else {
    // если сумма не пришла — подставляем из stud.money
    if ($price > 0) {
        $amount = $price;
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) json_resp(400, ['ok'=>false,'error'=>'Цена пакета не задана в карточке ученика']);
        die('Цена пакета не задана в карточке ученика');
    }
}

// Вставляем в pays
mysqli_begin_transaction($con);
try {
    $ins = $con->prepare("INSERT INTO pays (user_id, dates, lessons, amount) VALUES (?, ?, ?, ?)");
    if (!$ins) {
        throw new RuntimeException("Prepare failed: " . $con->error);
    }
    $ins->bind_param('isid', $user_id, $date, $lessons, $amount);
    if (!$ins->execute()) {
        throw new RuntimeException("Execute failed: " . $ins->error);
    }
    $inserted_id = $ins->insert_id;
    $ins->close();
    mysqli_commit($con);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        json_resp(200, ['ok'=>true, 'id' => $inserted_id, 'user_id' => $user_id]);
    } else {
        header("Location: /profile/student.php?user_id={$user_id}&ok_pay=1");
        exit;
    }
} catch (Throwable $e) {
    mysqli_rollback($con);
    error_log("add/pays.php error: " . $e->getMessage());
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) json_resp(500, ['ok'=>false,'error'=>'Server error']);
    http_response_code(500);
    echo "Server error";
    exit;
}
