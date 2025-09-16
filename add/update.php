<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

$id = (int)($_GET['user_id'] ?? 0);
if ($id <= 0) { die('bad user_id'); }

$name       = trim($_POST['name'] ?? '');
$lastname   = trim($_POST['lastname'] ?? '');
$klass      = trim($_POST['klass'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$school     = trim($_POST['school'] ?? '');
$parentname = trim($_POST['parentname'] ?? '');
$parent     = trim($_POST['parent'] ?? '');
$note       = trim($_POST['note'] ?? '');
$money      = (float)($_POST['money'] ?? 0);

$day1 = $_POST['day1'] ?? ''; $time1 = $_POST['time1'] ?? '';
$day2 = $_POST['day2'] ?? ''; $time2 = $_POST['time2'] ?? '';
$day3 = $_POST['day3'] ?? ''; $time3 = $_POST['time3'] ?? '';

function wd_to_num($s){$m=['Понедельник'=>1,'Вторник'=>2,'Среда'=>3,'Четверг'=>4,'Пятница'=>5,'Суббота'=>6,'Воскресенье'=>7];return $m[$s]??0;}

mysqli_begin_transaction($con);
try{
  $stmt=$con->prepare("UPDATE stud SET name=?, lastname=?, klass=?, phone=?, parentname=?, parent=?, school=?, note=?, money=? WHERE user_id=?");
  $stmt->bind_param('ssssssssdi',$name,$lastname,$klass,$phone,$parentname,$parent,$school,$note,$money,$id);
  $stmt->execute(); $stmt->close();

  $con->query("DELETE FROM schedule WHERE user_id={$id}");
  $slots=[[wd_to_num($day1),$time1],[wd_to_num($day2),$time2],[wd_to_num($day3),$time3]];
  $ins=$con->prepare("INSERT INTO schedule (user_id, weekday, time) VALUES (?,?,?)");
  foreach($slots as [$wd,$tm]){ if($wd>=1 && $wd<=7 && $tm!==''){ $ins->bind_param('iis',$id,$wd,$tm); $ins->execute(); } }
  $ins->close();

  mysqli_commit($con);
  echo "<meta http-equiv='refresh' content='0;URL=/profile/student.php?user_id={$id}&ok=1' />";
}catch(Throwable $e){ mysqli_rollback($con); http_response_code(500); echo 'Ошибка обновления ученика';}
