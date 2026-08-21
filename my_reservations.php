<?php
require_once __DIR__ . '/bootstrap.php';
$conn = db();

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('กรุณาเข้าสู่ระบบก่อนใช้งาน'); window.location.href='login.php';</script>";
    exit();
}

$user_id = $_SESSION['user_id'];

// ---------------- ระบบยกเลิกการจอง ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation') {
    verify_csrf();
    $book_id = (int)$_POST['book_id'];

    mysqli_begin_transaction($conn);

    try {
        // 1. ลบรายการออกจากตาราง reservations โดยใช้ user_id และ book_id
        $stmtDel = mysqli_prepare($conn, "DELETE FROM reservations WHERE user_id = ? AND book_id = ?");
        mysqli_stmt_bind_param($stmtDel, "ii", $user_id, $book_id);
        mysqli_stmt_execute($stmtDel);

        if (mysqli_stmt_affected_rows($stmtDel) > 0) {
            // 2. อัปเดตสถานะหนังสือกลับมาเป็น 'available'
            $stmtUpdate = mysqli_prepare($conn, "UPDATE books SET status = 'available' WHERE book_id = ?");
            mysqli_stmt_bind_param($stmtUpdate, "i", $book_id);
            mysqli_stmt_execute($stmtUpdate);

            mysqli_commit($conn);
            echo "<script>alert('ยกเลิกการจองเรียบร้อยแล้ว'); window.location.href='my_reservations.php';</script>";
            exit();
        } else {
            mysqli_rollback($conn);
            echo "<script>alert('ไม่พบรายการจอง หรือคุณไม่มีสิทธิ์ยกเลิกรายการนี้'); window.location.href='my_reservations.php';</script>";
            exit();
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo "<script>alert('เกิดข้อผิดพลาดในการยกเลิกการจอง'); window.location.href='my_reservations.php';</script>";
        exit();
    }
}

// ---------------- ดึงข้อมูลรายการที่ผู้ใช้จองไว้ ----------------
// ใช้ SELECT r.* เพื่อดึงทุกคอลัมน์จากตาราง reservations โดยไม่ต้องระบุชื่อ PK โดยตรง
$sql = "SELECT r.*, b.book_id, b.title, b.author, b.book_type, b.cover_image 
        FROM reservations r 
        JOIN books b ON r.book_id = b.book_id 
        WHERE r.user_id = ?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/app.css">
<title>รายการหนังสือที่จองไว้ - ห้องสมุดออนไลน์</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; }

/* Navbar */
.navbar { background: #0f172a; color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
.navbar a { color: white; text-decoration: none; font-weight: 600; }
.nav-links { display: flex; gap: 16px; align-items: center; }

/* Main Container */
.container { max-width: 1000px; margin: 32px auto; padding: 0 16px; }
.page-title { font-size: 24px; font-weight: 700; margin-bottom: 24px; color: #0f172a; display: flex; align-items: center; gap: 8px; }

/* Reservation Cards Grid */
.reservation-list { display: flex; flex-direction: column; gap: 16px; }
.res-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 16px; display: flex; gap: 20px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.res-cover { width: 90px; height: 130px; object-fit: cover; border-radius: 6px; background: #cbd5e1; flex-shrink: 0; }
.res-info { flex: 1; min-width: 0; }
.res-title { font-size: 18px; font-weight: 700; margin: 0 0 8px 0; color: #0f172a; }
.res-meta { font-size: 14px; color: #64748b; margin: 4px 0; }
.res-date { font-size: 13px; color: #2563eb; font-weight: 600; margin-top: 8px; }

/* Cancel Button */
.btn-cancel { background: #ef4444; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
.btn-cancel:hover { background: #dc2626; }

/* Empty State */
.empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 12px; border: 1px dashed #cbd5e1; }
.empty-state h3 { margin: 0 0 8px 0; color: #334155; }
.empty-state p { color: #64748b; margin-bottom: 20px; }
.btn-home { display: inline-block; background: #2563eb; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; }

@media (max-width: 640px) {
    .res-card { flex-direction: column; align-items: flex-start; }
    .res-cover { width: 100%; height: 200px; }
    .btn-cancel { width: 100%; margin-top: 8px; }
}
</style>
</head>
<body>

<header class="navbar">
    <a href="mainpage.php" style="font-size: 18px; font-weight: 700;">🏠 หน้าหลักห้องสมุด</a>
    <div class="nav-links">
        <span>สวัสดี, <b><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></b></span>
        <a href="profile.php">👤 ข้อมูลส่วนตัว</a>
        <a href="logout.php" style="color: #ef4444;">ออกจากระบบ</a>
    </div>
</header>

<div class="container">
    <h1 class="page-title">📖 รายการหนังสือที่ท่านจองไว้</h1>

    <?php if (mysqli_num_rows($result) > 0): ?>
        <div class="reservation-list">
            <?php while ($row = mysqli_fetch_assoc($result)): ?>
                <div class="res-card">
                    <img src="<?php echo htmlspecialchars($row['cover_image']); ?>" class="res-cover" alt="Cover">
                    
                    <div class="res-info">
                        <h3 class="res-title"><?php echo htmlspecialchars($row['title']); ?></h3>
                        <p class="res-meta"><b>ผู้แต่ง:</b> <?php echo htmlspecialchars($row['author']); ?></p>
                        <p class="res-meta"><b>หมวดหมู่:</b> <?php echo htmlspecialchars($row['book_type']); ?></p>
                        <?php 
                            // เช็กฟิลด์วันที่ถ้ามีในตาราง
                            $dateStr = $row['created_at'] ?? $row['reserve_date'] ?? null;
                            if ($dateStr): 
                        ?>
                            <p class="res-date">📅 ทำรายการเมื่อ: <?php echo date('d/m/Y H:i น.', strtotime($dateStr)); ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <form method="POST" action="my_reservations.php" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการยกเลิกการจองหนังสือเรื่อง «<?php echo htmlspecialchars(addslashes($row['title'])); ?>» ?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                            <input type="hidden" name="action" value="cancel_reservation">
                            <input type="hidden" name="book_id" value="<?php echo $row['book_id']; ?>">
                            <button type="submit" class="btn-cancel">ยกเลิกการจอง</button>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>ยังไม่มีรายการหนังสือที่จองไว้</h3>
            <p>ท่านสามารถเลือกดูรายการหนังสือและกดจองได้จากหน้าหลัก</p>
            <a href="mainpage.php" class="btn-home">ไปหน้าค้นหาหนังสือ</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
<?php mysqli_close($conn); ?>
