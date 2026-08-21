<?php
require_once __DIR__ . '/bootstrap.php';
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');

// เคลียร์ session แล้วพาผู้ใช้กลับมาหน้าหลัก
header("Location: mainpage.php");
exit;
?>
