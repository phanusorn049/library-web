<?php
require_once __DIR__ . '/bootstrap.php';
$conn = db();

// ดึงสถานะการล็อกอิน
$user_role = $_SESSION['role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? null;

// รับค่า ID หนังสือ
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($book_id <= 0) {
    header("Location: mainpage.php");
    exit();
}

// ---------------- 1. AI Tracking Log ----------------
// บันทึกประวัติการเข้าดูหนังสือลงฐานข้อมูลอัตโนมัติเมื่อกดเข้าหน้านี้
if ($user_id) {
    $stmtLog = mysqli_prepare($conn, "INSERT INTO book_views (user_id, book_id) VALUES (?, ?)");
    if ($stmtLog) {
        mysqli_stmt_bind_param($stmtLog, "ii", $user_id, $book_id);
        mysqli_stmt_execute($stmtLog);
        mysqli_stmt_close($stmtLog);
    }
}

// ---------------- 2. ดึงข้อมูลหนังสือ ----------------
$stmt = mysqli_prepare($conn, "SELECT * FROM books WHERE book_id = ?");
mysqli_stmt_bind_param($stmt, "i", $book_id);
mysqli_stmt_execute($stmt);
$book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$book) {
    echo "<h2 style='text-align:center; margin-top:50px;'>ไม่พบข้อมูลหนังสือเล่มนี้</h2>";
    echo "<p style='text-align:center;'><a href='mainpage.php'>กลับหน้าหลัก</a></p>";
    exit();
}

// ---------------- 3. ดึงหนังสือใกล้เคียงในหมวดเดียวกัน ----------------
$stmtRelated = mysqli_prepare($conn, "SELECT * FROM books WHERE book_type = ? AND book_id != ? LIMIT 4");
mysqli_stmt_bind_param($stmtRelated, "si", $book['book_type'], $book_id);
mysqli_stmt_execute($stmtRelated);
$relatedBooks = mysqli_stmt_get_result($stmtRelated);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/app.css">
<title><?php echo htmlspecialchars($book['title']); ?> - รายละเอียดหนังสือ</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; }

.navbar { background: #0f172a; color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
.navbar a { color: white; text-decoration: none; font-weight: 600; }

.container { max-width: 1000px; margin: 40px auto; padding: 0 16px; }
.btn-back { display: inline-block; margin-bottom: 20px; padding: 8px 16px; background: #e2e8f0; color: #334155; text-decoration: none; border-radius: 6px; font-weight: 600; }
.btn-back:hover { background: #cbd5e1; }

.detail-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; padding: 32px; display: flex; gap: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.cover-img { width: 300px; height: 420px; object-fit: cover; border-radius: 12px; background: #f1f5f9; flex-shrink: 0; }

.info-content { flex: 1; display: flex; flex-direction: column; gap: 16px; }
.book-title { font-size: 24px; font-weight: 800; margin: 0; color: #0f172a; line-height: 1.3; }

.badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 700; width: fit-content; }
.badge-avail { background: #dcfce7; color: #166534; }
.badge-busy { background: #fee2e2; color: #991b1b; }

.meta-group { font-size: 15px; color: #475569; display: flex; flex-direction: column; gap: 8px; }
.synopsis { margin-top: 12px; padding-top: 16px; border-top: 1px solid #e2e8f0; line-height: 1.6; color: #334155; }

.action-area { margin-top: auto; padding-top: 20px; }
.btn-reserve { display: inline-block; width: 100%; padding: 12px; background: #10b981; color: white; text-align: center; border: none; border-radius: 8px; font-size: 16px; font-weight: 700; cursor: pointer; text-decoration: none; }
.btn-disabled { display: inline-block; width: 100%; padding: 12px; background: #e2e8f0; color: #94a3b8; text-align: center; border-radius: 8px; font-size: 16px; font-weight: 700; }

/* Related Books Section */
.related-section { margin-top: 40px; }
.section-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; }
.related-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.rel-card { background: white; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; text-decoration: none; color: inherit; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
.rel-img { width: 100%; height: 180px; object-fit: cover; border-radius: 6px; }
.rel-title { font-size: 13px; font-weight: 700; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

@media (max-width: 768px) {
    .detail-card { flex-direction: column; padding: 20px; }
    .cover-img { width: 100%; height: 350px; }
}
</style>
</head>
<body>

<header class="navbar">
    <h2>Library Catalog</h2>
    <a href="mainpage.php">← กลับหน้าหลัก</a>
</header>

<div class="container">
    <a href="mainpage.php" class="btn-back">← กลับไปหน้าค้นหา</a>

    <div class="detail-card">
        <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" class="cover-img" alt="Cover">

        <div class="info-content">
            <div>
                <span class="badge <?php echo $book['status'] === 'available' ? 'badge-avail' : 'badge-busy'; ?>">
                    <?php echo $book['status'] === 'available' ? 'พร้อมให้ยืม' : 'ถูกยืม/ถูกจองแล้ว'; ?>
                </span>
            </div>

            <h1 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h1>

            <div class="meta-group">
                <p><b>หมวดหมู่:</b> <?php echo htmlspecialchars($book['book_type']); ?></p>
                <p><b>ผู้แต่ง:</b> <?php echo htmlspecialchars($book['author']); ?></p>
                <p><b>รหัสหนังสือ:</b> #BK-<?php echo str_pad($book['book_id'], 5, '0', STR_PAD_LEFT); ?></p>
            </div>

            <div class="synopsis">
                <b>รายละเอียดย่อ:</b><br>
                <?php echo htmlspecialchars($book['description'] ?? 'ยังไม่มีรายละเอียดสำหรับหนังสือเล่มนี้'); ?>
            </div>

            <div class="action-area">
                <?php if($book['status'] === 'available'): ?>
                    <form method="POST" action="reserve.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="book_id" value="<?php echo $book['book_id']; ?>">
                        <button type="submit" class="btn-reserve">กดจองหนังสือเล่มนี้</button>
                    </form>
                <?php else: ?>
                    <div class="btn-disabled">ไม่สามารถจองได้ (หนังสือถูกยืมอยู่)</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- แนะนำหนังสือในหมวดเดียวกันเพิ่มเติม -->
    <?php if ($relatedBooks && mysqli_num_rows($relatedBooks) > 0): ?>
        <div class="related-section">
            <h3 class="section-title">📚 หนังสืออื่นๆ ในหมวด "<?php echo htmlspecialchars($book['book_type']); ?>"</h3>
            <div class="related-grid">
                <?php while($rel = mysqli_fetch_assoc($relatedBooks)): ?>
                    <a href="detail.php?id=<?php echo $rel['book_id']; ?>" class="rel-card">
                        <img src="<?php echo htmlspecialchars($rel['cover_image']); ?>" class="rel-img" alt="Cover">
                        <p class="rel-title"><?php echo htmlspecialchars($rel['title']); ?></p>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
<?php mysqli_close($conn); ?>
