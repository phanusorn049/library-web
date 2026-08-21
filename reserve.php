<?php
require_once __DIR__ . '/bootstrap.php';
$conn = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('mainpage.php');
}

// 1. เช็คว่าล็อกอินหรือยัง
if (!isset($_SESSION['user_id'])) {
    echo "<script>
        alert('กรุณาเข้าสู่ระบบก่อนทำการจองหนังสือ');
        window.location.href = 'login.php';
    </script>";
    exit();
}

$user_id = require_login();
verify_csrf();
$book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;

// หากไม่ได้ส่ง book_id มา ให้เด้งกลับหน้าหลัก
if ($book_id <= 0) {
    header("Location: mainpage.php");
    exit();
}

// 2. ตรวจสอบข้อมูลส่วนตัวในฐานข้อมูลอีกครั้งก่อนอนุญาตให้จอง
$stmtUser = mysqli_prepare($conn, "SELECT student_id, phone, birthdate FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmtUser, "i", $user_id);
mysqli_stmt_execute($stmtUser);
$userData = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtUser));

$student_id = trim($userData['student_id'] ?? '');
$phone = trim($userData['phone'] ?? '');
$birthdate = $userData['birthdate'] ?? '';

// ถ้ากรอกข้อมูลไม่ครบถ้วน หรือรูปแบบไม่ถูกต้อง ให้เด้งไปหน้า profile.php
if (empty($student_id) || empty($phone) || empty($birthdate) || !preg_match('/^[0-9]{10}$/', $student_id) || !preg_match('/^0[0-9]{8,9}$/', $phone)) {
    echo "<script>
        alert('กรุณากรอกข้อมูลส่วนตัว (รหัสนักศึกษา 10 หลัก และ เบอร์โทรศัพท์) ให้ครบถ้วนถูกต้องก่อนทำการจอง');
        window.location.href = 'profile.php';
    </script>";
    exit();
}

// 3. ตรวจสอบสถานะหนังสือ และบันทึกการจอง
mysqli_begin_transaction($conn);
try {
    $stmtBook = mysqli_prepare($conn, "SELECT status FROM books WHERE book_id = ? FOR UPDATE");
    mysqli_stmt_bind_param($stmtBook, "i", $book_id);
    mysqli_stmt_execute($stmtBook);
    $bookData = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBook));

    if (!$bookData || $bookData['status'] !== 'available') {
        mysqli_rollback($conn);
        redirect('mainpage.php?notice=unavailable');
    }

    $stmtExisting = mysqli_prepare($conn, "SELECT 1 FROM reservations WHERE user_id = ? AND book_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtExisting, "ii", $user_id, $book_id);
    mysqli_stmt_execute($stmtExisting);
    $alreadyReserved = mysqli_stmt_get_result($stmtExisting)->num_rows > 0;
    mysqli_stmt_close($stmtExisting);

    if ($alreadyReserved) {
        mysqli_rollback($conn);
        redirect('mainpage.php?notice=already_reserved');
    }

    $stmtRes = mysqli_prepare($conn, "INSERT INTO reservations (user_id, book_id, reserve_date) VALUES (?, ?, NOW())");
    mysqli_stmt_bind_param($stmtRes, "ii", $user_id, $book_id);
    mysqli_stmt_execute($stmtRes);

    $stmtUpdate = mysqli_prepare($conn, "UPDATE books SET status = 'borrowed' WHERE book_id = ? AND status = 'available'");
    mysqli_stmt_bind_param($stmtUpdate, "i", $book_id);
    mysqli_stmt_execute($stmtUpdate);
    if (mysqli_stmt_affected_rows($stmtUpdate) !== 1) {
        throw new RuntimeException('Book status changed while reserving.');
    }

    mysqli_commit($conn);
    redirect('mainpage.php?notice=reserved');
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    error_log($exception->getMessage());
    redirect('mainpage.php?notice=error');
}
?>
