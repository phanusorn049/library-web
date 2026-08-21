<?php
require_once __DIR__ . '/bootstrap.php';
$conn = db();

// ดึงสถานะการล็อกอินจริงจาก Session
$user_role = $_SESSION['role'] ?? 'guest';
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? '';

// รับค่า Search และ Filter
$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? 'all';
$sort = $_GET['sort'] ?? 'newest';
$notice = $_GET['notice'] ?? '';
$noticeMessages = [
    'reserved' => ['จองหนังสือเรียบร้อยแล้ว', 'success'],
    'already_reserved' => ['คุณมีรายการจองหนังสือเล่มนี้อยู่แล้ว', 'warning'],
    'unavailable' => ['หนังสือเล่มนี้ไม่พร้อมให้จองแล้ว', 'warning'],
    'error' => ['เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', 'error'],
];

// ---------------- ตั้งค่าการแบ่งหน้า (PAGINATION) ----------------
$limit = 50; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// ดึงรายการหมวดหมู่ทั้งหมดสำหรับ Dropdown
$catQuery = mysqli_query($conn, "SELECT DISTINCT book_type FROM books");
$categories = mysqli_fetch_all($catQuery, MYSQLI_ASSOC);

// สร้าง SQL Query แบบ Dynamic
$whereClause = ["1=1"];
$params = [];
$types = "";

if ($search !== '') {
    $whereClause[] = "(title LIKE ? OR author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if ($category !== 'all') {
    $whereClause[] = "book_type = ?";
    $params[] = $category;
    $types .= "s";
}

$whereSql = implode(" AND ", $whereClause);

// 1. นับจำนวนรายการทั้งหมด
$countSql = "SELECT COUNT(*) as total FROM books WHERE $whereSql";
$countStmt = mysqli_prepare($conn, $countSql);
if (!empty($params)) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalRows = mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];
$totalPages = ceil($totalRows / $limit);

// 2. ดึงข้อมูลหนังสือเฉพาะหน้าปัจจุบัน
$sortSql = "ORDER BY created_at DESC";
if ($sort === 'oldest') $sortSql = "ORDER BY created_at ASC";
if ($sort === 'title_asc') $sortSql = "ORDER BY title ASC";

$sql = "SELECT * FROM books WHERE $whereSql $sortSql LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$booksResult = mysqli_stmt_get_result($stmt);

// ---------------- 3. AI & SIDEBAR DATA LOGIC ----------------

// [ฝั่งซ้าย] หนังสือยืมสูงสุดสำหรับทุกคน
$leftPopSql = "SELECT b.*, COUNT(l.book_id) as total_borrow 
               FROM books b 
               LEFT JOIN book_borrowing_data_1000_books l ON b.book_id = l.book_id 
               GROUP BY b.book_id 
               ORDER BY total_borrow DESC LIMIT 5";
$leftPopularBooks = mysqli_query($conn, $leftPopSql);

// ==========================================================
// CUSTOM ML RECOMMENDATION ENGINE (PYTHON FASTAPI)
// ==========================================================

function getCustomMLRecommendation(int $userId): array
{
    $url = "https://library-recommender-api.onrender.com/recommend/" . $userId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        error_log('ML API cURL Error: ' . $curlError);
        return [];
    }

    $result = json_decode($response, true);
    if (!is_array($result) || !isset($result['recommendations'])) {
        return [];
    }

    return $result['recommendations'];
}

// ==========================================================
// AI / ML RECOMMENDATION - SIDEBAR RIGHT
// ==========================================================

$rightAiBooks = [];
$aiTitle = 'แนะนำสำหรับคุณและหนังสือที่น่าสนใจ';
$aiReasons = [];

if ($user_role === 'guest' || !$user_id) {
    // Guest: ใช้หนังสือยอดนิยมเป็น Fallback
    $aiTitle = 'รายการยอดนิยม';

    $guestAiSql = "
        SELECT b.*, COUNT(l.book_id) AS total_borrow
        FROM books b
        LEFT JOIN book_borrowing_data_1000_books l ON b.book_id = l.book_id
        WHERE b.status = 'available'
        GROUP BY b.book_id
        ORDER BY total_borrow DESC
        LIMIT 4
    ";

    $guestResult = mysqli_query($conn, $guestAiSql);
    if ($guestResult) {
        $rightAiBooks = mysqli_fetch_all($guestResult, MYSQLI_ASSOC);
    }
} else {
    // 1) ดึงคำแนะนำจาก Python ML API
    $recommendations = getCustomMLRecommendation((int)$user_id);

    if (!empty($recommendations)) {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_column($recommendations, 'book_id')
        )));

        foreach ($recommendations as $recommendation) {
            $aiReasons[(int)$recommendation['book_id']] = $recommendation['reason'];
        }

        if (!empty($ids)) {
            $idsString = implode(',', $ids);

            // 2) ดึงข้อมูลจริงจาก MySQL ตาม book_id ที่ ML คำนวณได้
            $aiSql = "
                SELECT *
                FROM books
                WHERE book_id IN ($idsString)
                AND status = 'available'
            ";

            $aiResult = mysqli_query($conn, $aiSql);
            $booksById = [];

            if ($aiResult) {
                while ($book = mysqli_fetch_assoc($aiResult)) {
                    $booksById[(int)$book['book_id']] = $book;
                }
            }

            // เรียงลำดับตามคะแนนที่ ML คำนวณได้
            foreach ($ids as $id) {
                if (isset($booksById[$id])) {
                    $rightAiBooks[] = $booksById[$id];
                }
            }
        }
    }

    // 3) Fallback กรณีประวัติไม่มี หรือ ML API มีปัญหาระหว่างเรียก
    if (empty($rightAiBooks)) {
        $aiTitle = 'หนังสือที่น่าสนใจ';

        $fallbackSql = "
            SELECT b.*, COUNT(h.book_id) AS borrow_count
            FROM books b
            LEFT JOIN book_borrowing_data_1000_books h ON h.book_id = b.book_id
            WHERE b.status = 'available'
            GROUP BY b.book_id
            ORDER BY borrow_count DESC, b.created_at DESC
            LIMIT 4
        ";

        $fallbackResult = mysqli_query($conn, $fallbackSql);
        if ($fallbackResult) {
            $rightAiBooks = mysqli_fetch_all($fallbackResult, MYSQLI_ASSOC);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/app.css">
<title>ห้องสมุดออนไลน์ - หน้าหลัก</title>
<style>
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: radial-gradient(circle at 15% 0%, #d1fae5 0, transparent 30%), #f8fafc; color: #1e293b; }

.sticky-top-wrapper { position: sticky; top: 0; z-index: 1000; }
.navbar { background: linear-gradient(115deg, #052e27, #047857 58%, #059669); color: white; padding: 18px 32px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 30px rgba(6,78,59,.22); }
.navbar a { color: white; text-decoration: none; font-weight: 600; }
.nav-links { display: flex; gap: 16px; align-items: center; }

.filter-bar { background: rgba(255,255,255,.92); backdrop-filter: blur(12px); padding: 14px 24px; border-bottom: 1px solid #d1fae5; box-shadow: 0 8px 20px rgba(6,78,59,.08); }
.filter-form { max-width: 1500px; margin: 0 auto; display: flex; gap: 12px; flex-wrap: wrap; }
.filter-form input, .filter-form select, .filter-form button { padding: 11px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 14px; }
.filter-form input { flex: 1; min-width: 200px; }
.btn-search { background: linear-gradient(135deg, #047857, #10b981); color: white; border: none; cursor: pointer; font-weight: 700; box-shadow: 0 6px 14px rgba(5,150,105,.22); }

.hero { max-width: 1518px; margin: 24px auto 0; padding: 0 16px; }
.hero-card { position: relative; overflow: hidden; padding: 30px 34px; border-radius: 20px; color: white; background: linear-gradient(125deg, #064e3b, #047857 56%, #059669); box-shadow: 0 18px 42px rgba(6,78,59,.22); }
.hero-card::after { content:""; position:absolute; width:300px; height:300px; border-radius:50%; right:-85px; top:-160px; background:rgba(209,250,229,.16); box-shadow:-110px 120px 0 rgba(255,255,255,.07); }
.hero-content { position:relative; z-index:1; display:flex; justify-content:space-between; gap:28px; align-items:center; }
.hero-eyebrow { margin:0 0 8px; color:#a7f3d0; font-size:12px; font-weight:800; letter-spacing:.13em; text-transform:uppercase; }
.hero h1 { margin:0; color:white!important; font-size:clamp(27px,3vw,42px); letter-spacing:-.035em; }
.hero-text { max-width:650px; margin:10px 0 0; color:#ecfdf5; line-height:1.65; font-size:15px; }
.hero-stats { display:flex; gap:10px; flex-wrap:wrap; min-width:330px; justify-content:flex-end; }
.hero-stat { min-width:105px; padding:13px 15px; border:1px solid rgba(255,255,255,.22); border-radius:13px; background:rgba(255,255,255,.11); backdrop-filter:blur(8px); }
.hero-stat b { display:block; font-size:22px; line-height:1.1; }.hero-stat span { display:block; margin-top:4px; font-size:11px; color:#d1fae5; }

.container { max-width: 1550px; margin: 24px auto; padding: 0 16px; display: flex; gap: 24px; align-items: flex-start; }
.content-main { flex: 1; min-width: 0; }

.sidebar { 
    width: 250px; 
    flex-shrink: 0; 
    position: sticky;
    top: 170px;
    height: fit-content;
}
.side-item { display: flex; gap: 10px; margin-bottom: 12px; align-items: center; text-decoration: none; color: inherit; padding: 4px; border-radius: 6px; transition: background 0.2s; }
.side-item:hover { background: #f1f5f9; }
.side-img { width: 45px; height: 60px; object-fit: cover; border-radius: 4px; background: #e2e8f0; flex-shrink: 0; }
.side-info { flex: 1; min-width: 0; }
.side-name { font-size: 13px; font-weight: 600; margin: 0; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.side-sub { font-size: 11px; color: #64748b; margin: 2px 0 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.book-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 24px; }
.book-card { background: white; border-radius: 16px; border: 1px solid #d9e8e1; overflow: hidden; display: flex; flex-direction: column; transition: transform 0.25s, box-shadow 0.25s; box-shadow: 0 6px 18px rgba(6,78,59,.07); }
.book-card:hover { transform: translateY(-7px); box-shadow: 0 20px 30px -12px rgba(6,78,59,.24); }
.book-cover { width: 100%; height: 280px; object-fit: cover; background: #f1f5f9; transition: transform .35s ease; }
.book-card:hover .book-cover { transform: scale(1.035); }

.book-details { padding: 16px; flex-grow: 1; display: flex; flex-direction: column; gap: 8px; }
.book-title { font-size: 16px; font-weight: 700; margin: 0; color: #0f172a; }
.book-meta { font-size: 13px; color: #64748b; margin: 0; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; width: fit-content; }
.badge-avail { background: #dcfce7; color: #166534; }
.badge-busy { background: #fee2e2; color: #991b1b; }

.card-actions { padding: 0 16px 16px 16px; display: flex; flex-direction: column; gap: 8px; }
.btn-action { width: 100%; padding: 8px; border-radius: 6px; text-align: center; text-decoration: none; font-size: 14px; font-weight: 600; border: none; cursor: pointer; display: block; box-sizing: border-box; }
.btn-detail { background: #ecfdf5; color: #065f46; }
.btn-reserve { background: linear-gradient(135deg, #047857, #10b981); color: white; box-shadow: 0 5px 12px rgba(5,150,105,.2); }
.btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

.pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin: 32px 0; }
.page-btn { padding: 8px 14px; border: 1px solid #cbd5e1; background: white; border-radius: 6px; text-decoration: none; color: #334155; font-weight: 600; font-size: 14px; }
.page-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
.page-btn.disabled { opacity: 0.5; pointer-events: none; }

.scroll-buttons { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column; gap: 10px; z-index: 1000; }
.btn-scroll { width: 44px; height: 44px; background: #2563eb; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 20px; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.15); transition: background 0.2s, transform 0.2s; }
.btn-scroll:hover { background: #1d4ed8; transform: scale(1.1); }

@media (max-width: 1200px) { .sidebar-right { display: none; } .hero-content { align-items:flex-start; flex-direction:column; } .hero-stats { justify-content:flex-start; min-width:0; } }
@media (max-width: 900px) { .sidebar-left { display: none; } }
@media (max-width: 640px) { .navbar { padding:15px 16px; }.hero-card { padding:24px 20px; border-radius:16px; }.hero-stats { gap:8px; }.hero-stat { min-width:92px; }.container { margin-top:18px; } }
</style>
</head>
<body>

<div class="sticky-top-wrapper">
    <header class="navbar">
        <h2>Library Catalog</h2>
        <div class="nav-links">
            <?php if($user_role === 'admin'): ?>
                <a href="dashboard.php" style="background: #f59e0b; color: #0f172a; padding: 6px 12px; border-radius: 6px; font-weight: 700;">📊 ข้อมูลหลังบ้าน (Dashboard)</a>
            <?php elseif($user_role === 'user'): ?>
                <a href="my_reservations.php">📖 หนังสือที่จอง</a>
                <a href="profile.php">👤 ข้อมูลส่วนตัว</a>
            <?php endif; ?>

            <?php if($user_role !== 'guest'): ?>
                <span style="color: #94a3b8; font-size: 14px;">สวัสดี, <b><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></b></span>
                <a href="logout.php" style="color: #ef4444;">ออกจากระบบ</a>
            <?php else: ?>
                <a href="login.php" style="background: #2563eb; padding: 6px 14px; border-radius: 6px;">เข้าสู่ระบบ / สมัครสมาชิก</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="filter-bar">
        <form class="filter-form" method="GET" action="mainpage.php">
            <input type="text" name="search" placeholder="ค้นหาชื่อหนังสือ หรือ ผู้แต่ง..." value="<?php echo htmlspecialchars($search); ?>">
            
            <select name="category">
                <option value="all">ทุกหมวดหมู่</option>
                <?php foreach($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['book_type']); ?>" <?php echo $category === $cat['book_type'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['book_type']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="sort">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>ใหม่ไปเก่า</option>
                <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>เก่าไปใหม่</option>
                <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>ก-ฮ / A-Z</option>
            </select>

            <button type="submit" class="btn-search">ค้นหา</button>
        </form>
    </div>
</div>

<?php if (isset($noticeMessages[$notice])): ?>
    <div class="notice notice-<?php echo $noticeMessages[$notice][1]; ?>" role="status">
        <?php echo htmlspecialchars($noticeMessages[$notice][0]); ?>
    </div>
<?php endif; ?>

<section class="hero" aria-label="ข้อมูลภาพรวมระบบห้องสมุด">
    <div class="hero-card">
        <div class="hero-content">
            <div>
                <p class="hero-eyebrow">Discover · Learn · Grow</p>
                <h1>ค้นพบเล่มที่ใช่<br>ในห้องสมุดที่เข้าใจคุณ</h1>
                <p class="hero-text">เลือกอ่านจากคอลเลกชันที่หลากหลาย พร้อมคำแนะนำเฉพาะบุคคลที่ช่วยให้หนังสือดี ๆ ได้ถูกค้นพบมากขึ้น</p>
            </div>
            <div class="hero-stats" aria-label="สถิติคลังหนังสือ">
                <div class="hero-stat"><b><?php echo number_format($totalRows); ?></b><span>รายการหนังสือ</span></div>
                <div class="hero-stat"><b><?php echo number_format(count($categories)); ?></b><span>หมวดหมู่</span></div>
                <div class="hero-stat"><b>AI</b><span>แนะนำเพื่อคุณ</span></div>
            </div>
        </div>
    </div>
</section>

<div class="container">
    <!-- แถบซ้าย: หนังสือนิยม -->
    <aside class="sidebar sidebar-left">
        <div class="sidebar-box">
            <h3 class="sidebar-title">🔥 ยืมสูงสุดสำหรับทุกคน</h3>
            <?php if ($leftPopularBooks && mysqli_num_rows($leftPopularBooks) > 0): ?>
                <?php while($pop = mysqli_fetch_assoc($leftPopularBooks)): ?>
                    <a href="detail.php?id=<?php echo $pop['book_id']; ?>" class="side-item">
                        <img src="<?php echo htmlspecialchars($pop['cover_image']); ?>" class="side-img" alt="Cover">
                        <div class="side-info">
                            <p class="side-name"><?php echo htmlspecialchars($pop['title']); ?></p>
                            <p class="side-sub"><?php echo htmlspecialchars($pop['book_type']); ?></p>
                        </div>
                    </a>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="font-size:12px; color:#64748b;">ไม่มีข้อมูล</p>
            <?php endif; ?>
        </div>
    </aside>

    <!-- เนื้อหาตรงกลาง: การ์ดหนังสือหลัก -->
    <main class="content-main">
        <div class="book-grid">
            <?php if (mysqli_num_rows($booksResult) > 0): ?>
                <?php while($book = mysqli_fetch_assoc($booksResult)): ?>
                    <div class="book-card">
                        <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" class="book-cover" alt="Cover">
                        
                        <div class="book-details">
                            <span class="badge <?php echo $book['status'] === 'available' ? 'badge-avail' : 'badge-busy'; ?>">
                                <?php echo $book['status'] === 'available' ? 'พร้อมให้ยืม' : 'ถูกยืม/ถูกจองแล้ว'; ?>
                            </span>
                            <h3 class="book-title"><?php echo htmlspecialchars($book['title']); ?></h3>
                            <p class="book-meta"><b>หมวดหมู่:</b> <?php echo htmlspecialchars($book['book_type']); ?></p>
                            <p class="book-meta"><b>ผู้แต่ง:</b> <?php echo htmlspecialchars($book['author']); ?></p>
                        </div>

                        <div class="card-actions">
                            <a href="detail.php?id=<?php echo $book['book_id']; ?>" class="btn-action btn-detail">รายละเอียด</a>
                            
                            <?php if($book['status'] === 'available'): ?>
                                <form method="POST" action="reserve.php" onsubmit="return confirm('คุณต้องการจองหนังสือเรื่อง «<?php echo htmlspecialchars(addslashes($book['title'])); ?>» ใช่หรือไม่?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                    <input type="hidden" name="book_id" value="<?php echo $book['book_id']; ?>">
                                    <button type="submit" class="btn-action btn-reserve">กดจองหนังสือ</button>
                                </form>
                            <?php else: ?>
                                <button class="btn-action btn-disabled" disabled>ไม่สามารถจองได้</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="grid-column: 1 / -1; text-align: center; color: #64748b; padding: 40px;">ไม่พบข้อมูลหนังสือที่ตรงกับการค้นหา</p>
            <?php endif; ?>
        </div>

        <!-- ปุ่มแบ่งหน้า (Pagination) -->
        <?php if ($totalPages > 1): ?>
            <?php 
                $queryParams = $_GET;
                unset($queryParams['page']);
                $queryString = http_build_query($queryParams);
                $queryPrefix = $queryString ? "?{$queryString}&page=" : "?page=";
            ?>
            <div class="pagination">
                <a href="<?php echo $queryPrefix . ($page - 1); ?>" class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">← ก่อนหน้า</a>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="<?php echo $queryPrefix . $i; ?>" class="page-btn <?php echo ($page == $i) ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <a href="<?php echo $queryPrefix . ($page + 1); ?>" class="page-btn <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">ถัดไป →</a>
            </div>
        <?php endif; ?>
    </main>

    <aside class="sidebar sidebar-right">
        <div class="sidebar-box" style="background:#f0fdf4; border-color:#bbf7d0;">
            <h3 class="sidebar-title" style="color:#166534; border-bottom-color:#22c55e;"><?php echo $aiTitle; ?></h3>
            <?php if (!empty($rightAiBooks)): ?>
                <?php foreach($rightAiBooks as $aiBook): ?>
                    <a href="detail.php?id=<?php echo $aiBook['book_id']; ?>" class="side-item">
                        <img src="<?php echo htmlspecialchars($aiBook['cover_image']); ?>" class="side-img" alt="Cover">
                        <div class="side-info">
                            <p class="side-name"><?php echo htmlspecialchars($aiBook['title']); ?></p>
                            <p class="side-sub"><?php echo htmlspecialchars($aiBook['book_type']); ?></p>

                            <?php
                            $aiBookId = (int)$aiBook['book_id'];
                            if (isset($aiReasons[$aiBookId])):
                            ?>
                                <p style="font-size:10px; color:#166534; margin:4px 0 0; line-height:1.35;">
                                    <?php echo htmlspecialchars($aiReasons[$aiBookId]); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="font-size:12px; color:#64748b;">ไม่มีข้อมูลแนะนำ</p>
            <?php endif; ?>
        </div>
    </aside>
</div>

<div class="scroll-buttons">
    <a href="#bottom" class="btn-scroll" title="ลงไปล่างสุด">↓</a>
    <a href="#" class="btn-scroll" title="กลับขึ้นด้านบน">↑</a>
</div>

<div id="bottom"></div>

</body>
</html>
<?php mysqli_close($conn); ?>
