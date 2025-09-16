<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['login']) && empty($_SESSION['id'])) {
    echo "<meta http-equiv='refresh' content='0;URL=/index.php' />";
    exit;
}
