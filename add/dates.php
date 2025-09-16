<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

$user_id = (int)($_GET['user_id'] ?? 0);
$date    = $_POST['dataa'] ?? date('Y-m-d');
$visited = isset($_POST['visited']) ? 1 : 0;
if ($user_id <= 0) { die('bad user_id'); }

$chk=$con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND dates=? AND visited=1");
$chk->bind_param('is',$user_id,$date); $chk->execute(); $chk->bind_result($cnt); $chk->fetch(); $chk->close();

if ((int)$cnt === 0 || $visited == 0) {
  $ins=$con->prepare("INSERT INTO dates (user_id, dates, visited) VALUES (?,?,?)");
  $ins->bind_param('isi',$user_id,$date,$visited); $ins->execute(); $ins->close();
}
echo "<meta http-equiv='refresh' content='0;URL=/profile/student.php?user_id={$user_id}&ok=1' />";
