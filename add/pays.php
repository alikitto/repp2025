<?php
require_once __DIR__ . '/../profile/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
require_once __DIR__ . '/../common/util.php';
csrf_check();

// Получаем POST-данные
$user_id = (int)($_POST['user_id'] ?? 0);
$date    = valid_ymd($_POST['date'] ?? null);
$lessons = (int)($_POST['lessons'] ?? 0);
$amount_in = (float)str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
if ($amount_in < 0 || $amount_in > 20000) $amount_in = 0;

if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad user_id']);
    exit;
}
if (!stud_owned($con, $user_id)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$amount = 0.0;
$money = 0.0;
$res = $con->prepare("SELECT money FROM stud WHERE user_id=?");
$res->bind_param('i', $user_id);
$res->execute();
$res->bind_result($money);
$res->fetch();
$res->close();
$money = (float)$money;

if ($lessons <= 0 && $amount_in > 0 && $money > 0) {
    $lessons = (int)round($amount_in / $money);
}
if ($lessons <= 0) $lessons = current_pack_lessons();
$lessons = max(1, min(40, $lessons));
$amount = $amount_in > 0 ? $amount_in : ($money * $lessons);

$voice = !empty($_POST['voice']) ? 1 : 0;

// Сохраняем оплату
$ins = $con->prepare("INSERT INTO pays (user_id, date, lessons, amount, voice) VALUES (?,?,?,?,?)");
$ins->bind_param('isidi', $user_id, $date, $lessons, $amount, $voice);
$ok = $ins->execute();
$pay_id = (int)$con->insert_id;
$ins->close();

header('Content-Type: application/json');
if ($ok) {
    $fio = activity_fio($con, $user_id);
    log_activity($con, 'pay', 'Оплата: ' . $fio . ' — ' . $lessons . ' ур., ' . fmt_money((float)$amount) . ' AZN', '/profile/student.php?user_id=' . $user_id);
    echo json_encode(['ok' => true, 'id' => $pay_id]);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
}
