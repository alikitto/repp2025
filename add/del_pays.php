<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();
$id = (int)($_POST['ids'] ?? 0);
$user_id  = (int)($_GET['user_id'] ?? 0);
if ($id <= 0) { die('bad id'); }
$del=$con->prepare("DELETE FROM pays WHERE id=?");
$del->bind_param('i',$id); $del->execute(); $del->close();
if ($user_id>0) echo "<meta http-equiv='refresh' content='0;URL=/profile/student.php?user_id={$user_id}' />";
else echo "OK";
