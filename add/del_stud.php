<?php
session_start();
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();
$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) { die('bad user_id'); }
$del=$con->prepare("DELETE FROM stud WHERE user_id=?");
$del->bind_param('i',$user_id); $del->execute(); $del->close();
echo "<meta http-equiv='refresh' content='0;URL=/profile/index.php' />";
