<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

$uid = (int)($_POST['user_id'] ?? 0);
if ($uid <= 0) {
    die('Неверный ID ученика');
}
require_owned_student($con, $uid);

$name  = trim($_POST['name'] ?? '');
$lastname = trim($_POST['lastname'] ?? '');
$klass = trim($_POST['klass'] ?? '');
$money = (float)($_POST['money'] ?? 0);
$pay_mode = student_pay_mode($_POST['pay_mode'] ?? 'prepaid');

if ($name === '') {
    die('Укажите имя');
}
if ($money <= 0) {
    die('Укажите цену урока');
}

$tid = teacher_id();
$stmt = $con->prepare("UPDATE stud SET name=?, lastname=?, klass=?, money=?, pay_mode=? WHERE user_id=? AND teacher_id=?");
$stmt->bind_param('sssdssi', $name, $lastname, $klass, $money, $pay_mode, $uid, $tid);
if (!$stmt->execute()) {
    http_response_code(500);
    echo 'Ошибка сохранения ученика';
    exit;
}
$stmt->close();
log_activity($con, 'student_edit', 'Изменён ученик ' . trim($lastname . ' ' . $name), '/profile/student.php?user_id=' . $uid);

$_SESSION['flash_updated'] = ['name' => $name];
header("Location: /profile/student.php?user_id=" . $uid);
exit;
