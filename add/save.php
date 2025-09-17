<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

$lastname = trim($_POST['lastname'] ?? '');
$name     = trim($_POST['name'] ?? '');
$money    = (float)($_POST['money'] ?? 0);

// расписание (до 3 слотов)
$day1 = $_POST['day1'] ?? ''; $time1 = $_POST['time1'] ?? '';
$day2 = $_POST['day2'] ?? ''; $time2 = $_POST['time2'] ?? '';
$day3 = $_POST['day3'] ?? ''; $time3 = $_POST['time3'] ?? '';

if ($lastname === '' || $name === '') { die('Укажите имя и фамилию'); }

function wd_to_num($s){
  $m = ['Понедельник'=>1,'Вторник'=>2,'Среда'=>3,'Четверг'=>4,'Пятница'=>5,'Суббота'=>6,'Воскресенье'=>7];
  return $m[$s] ?? 0;
}

mysqli_begin_transaction($con);
try {
  // stud: сохраняем только имя/фамилию и money (оплата)
  $stmt = $con->prepare("INSERT INTO stud (name, lastname, money) VALUES (?,?,?)");
  $stmt->bind_param('ssd', $name, $lastname, $money);
  $stmt->execute(); 
  $uid = $stmt->insert_id; 
  $stmt->close();

  // schedule
  $slots = [
    [wd_to_num($day1), $time1],
    [wd_to_num($day2), $time2],
    [wd_to_num($day3), $time3],
  ];
  $ins = $con->prepare("INSERT INTO schedule (user_id, weekday, time) VALUES (?,?,?)");
  foreach ($slots as [$wd,$tm]) {
    if ($wd >= 1 && $wd <= 7 && $tm !== '') { 
      $ins->bind_param('iis', $uid, $wd, $tm); 
      $ins->execute(); 
    }
  }
  $ins->close();

  mysqli_commit($con);
  echo "<meta http-equiv='refresh' content='0;URL=/profile/student.php?user_id={$uid}&ok=1' />";
} catch (Throwable $e) {
  mysqli_rollback($con);
  http_response_code(500);
  echo 'Ошибка сохранения ученика';
}
