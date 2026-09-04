<?php
require_once __DIR__ . '/../profile/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

$name   = trim($_POST['name']  ?? '');
$lastname = trim($_POST['lastname'] ?? '');
$klass  = trim($_POST['klass'] ?? '');
$money  = (float)($_POST['money'] ?? 0);
$pay_mode = student_pay_mode($_POST['pay_mode'] ?? 'prepaid');

if ($name === '') { die('Укажите имя'); }
if ($money <= 0) { die('Укажите цену урока'); }

mysqli_begin_transaction($con);
try {
  $tid = teacher_id();
  $stmt = $con->prepare("INSERT INTO stud (name, lastname, klass, money, pay_mode, teacher_id) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('sssdsi', $name, $lastname, $klass, $money, $pay_mode, $tid);
  if (!$stmt->execute()) throw new RuntimeException('stud insert failed');
  $uid = (int)$stmt->insert_id;
  $stmt->close();
  if ($uid <= 0) throw new RuntimeException('stud insert_id empty');

  mysqli_commit($con);
  log_activity($con, 'student_add', 'Добавлен ученик ' . trim($lastname . ' ' . $name), '/profile/student.php?user_id=' . $uid);

  $_SESSION['flash_created'] = ['id'=>$uid,'name'=>$name];
  header("Location: /add/student.php");
  exit;
} catch (Throwable $e) {
  mysqli_rollback($con);
  http_response_code(500);
  echo 'Ошибка сохранения ученика';
}
