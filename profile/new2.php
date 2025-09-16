<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db_conn.php';
require_once __DIR__ . '/../common/csrf.php';
csrf_check();

$ids = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : [];
$ids = array_values(array_unique(array_filter($ids, fn($v)=>$v>0)));
$today = date('Y-m-d');

if (!$ids) { echo "<meta http-equiv='refresh' content='0;URL=/profile/attendance_today.php' />"; exit; }

$sel = $con->prepare("SELECT COUNT(*) FROM dates WHERE user_id=? AND dates=? AND visited=1");
$ins = $con->prepare("INSERT INTO dates (user_id, dates, visited) VALUES (?,?,1)");
foreach ($ids as $uid) {
    $sel->bind_param('is', $uid, $today);
    $sel->execute(); $sel->bind_result($cnt); $sel->fetch(); $sel->free_result();
    if ((int)$cnt === 0) { $ins->bind_param('is', $uid, $today); $ins->execute(); }
}
echo "<meta http-equiv='refresh' content='0;URL=/profile/index.php?ok=1' />";
