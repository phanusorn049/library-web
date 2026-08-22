<?php
require_once __DIR__ . '/bootstrap.php';

// 1. ตรวจสอบสิทธิ์การเข้าถึง (เฉพาะ Admin เท่านั้น)
require_admin();

$conn = db();

// ---------------- การจัดการ Action จาก Form ----------------

$alertMsg = '';
if (isset($_POST['add_book'])) {
    verify_csrf();
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $book_type = trim($_POST['book_type']);
    $cover_image = trim($_POST['cover_image']) ?: 'https://via.placeholder.com/150';

    if (mb_strlen($title) < 1 || mb_strlen($title) > 255 || mb_strlen($author) < 1 || mb_strlen($author) > 255 || mb_strlen($book_type) < 1 || mb_strlen($book_type) > 100 || !filter_var($cover_image, FILTER_VALIDATE_URL)) {
        $alertMsg = "กรุณาตรวจสอบข้อมูลหนังสือและลิงก์รูปภาพ";
    } else {
        $stmtAdd = mysqli_prepare($conn, "INSERT INTO books (title, author, book_type, cover_image, status) VALUES (?, ?, ?, ?, 'available')");
        mysqli_stmt_bind_param($stmtAdd, "ssss", $title, $author, $book_type, $cover_image);
        if (mysqli_stmt_execute($stmtAdd)) {
            $alertMsg = "เพิ่มหนังสือเรียบร้อยแล้ว!";
        }
        mysqli_stmt_close($stmtAdd);
    }
}

if (isset($_POST['update_role'])) {
    verify_csrf();
    $target_uid = (int)$_POST['user_id'];
    $new_role = $_POST['role'];

    if (!in_array($new_role, ['user', 'admin'], true) || $target_uid < 1) {
        http_response_code(422);
        exit('ข้อมูลสิทธิ์ไม่ถูกต้อง');
    }

    $stmtRole = mysqli_prepare($conn, "UPDATE users SET role = ? WHERE user_id = ?");
    mysqli_stmt_bind_param($stmtRole, "si", $new_role, $target_uid);
    mysqli_stmt_execute($stmtRole);
    mysqli_stmt_close($stmtRole);
    $alertMsg = "อัปเดตสิทธิ์ผู้ใช้งานเรียบร้อยแล้ว!";
}

// ---------------- ระบบ Dashboard & Analytics ----------------

$maxDateQuery = mysqli_query($conn, "SELECT MAX(borrow_date) as max_date FROM book_borrowing_data_1000_books");
$maxDateRow = mysqli_fetch_assoc($maxDateQuery);
$baseDate = $maxDateRow['max_date'] ?? date('Y-m-d');

$filter = $_GET['filter'] ?? 'all';
$daysMapping = ['week' => 7, 'month' => 30, 'year' => 365];

if (!in_array($filter, array_merge(['all'], array_keys($daysMapping)), true)) {
    $filter = 'all';
}

$isFiltered = array_key_exists($filter, $daysMapping);
if ($isFiltered) {
    $days = $daysMapping[$filter];
    $timeCondition = "borrow_date >= DATE_SUB(?, INTERVAL ? DAY)";
} else {
    $timeCondition = "1=1";
}

function executeDynamicQuery($conn, $sql, $isFiltered, $baseDate, $days) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($isFiltered) {
        mysqli_stmt_bind_param($stmt, "si", $baseDate, $days);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

// 1. Total Borrow Metrics
$sqlTotalBorrow = "SELECT COUNT(*) total FROM book_borrowing_data_1000_books WHERE $timeCondition";
$stmtTB = executeDynamicQuery($conn, $sqlTotalBorrow, $isFiltered, $baseDate, $days ?? 0);
$totalBorrow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTB))['total'] ?? 0;
mysqli_stmt_close($stmtTB);

// 2. Distinct Books Metrics
$sqlTotalBooks = "SELECT COUNT(DISTINCT book_id) total FROM book_borrowing_data_1000_books WHERE $timeCondition";
$stmtBK = executeDynamicQuery($conn, $sqlTotalBooks, $isFiltered, $baseDate, $days ?? 0);
$totalBooks = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBK))['total'] ?? 0;
mysqli_stmt_close($stmtBK);

// 3. Popular Genres Category
$sqlPopular = "SELECT book_type, COUNT(*) total FROM book_borrowing_data_1000_books WHERE $timeCondition GROUP BY book_type ORDER BY total DESC LIMIT 1";
$stmtPop = executeDynamicQuery($conn, $sqlPopular, $isFiltered, $baseDate, $days ?? 0);
$popularTypeRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtPop));
$popularType = $popularTypeRow['book_type'] ?? '';
mysqli_stmt_close($stmtPop);

$estimatedSavings = $totalBorrow * 250;

// 4. Critical Stock Alerts
$sqlAlerts = "SELECT book_id, book_type, COUNT(*) total FROM book_borrowing_data_1000_books WHERE $timeCondition GROUP BY book_id, book_type HAVING total > 5 ORDER BY total DESC LIMIT 2";
$stmtAlerts = executeDynamicQuery($conn, $sqlAlerts, $isFiltered, $baseDate, $days ?? 0);
$alerts = mysqli_fetch_all(mysqli_stmt_get_result($stmtAlerts), MYSQLI_ASSOC);
mysqli_stmt_close($stmtAlerts);

// 5. Analytics Charts Integration
$sqlChart = "SELECT book_type, COUNT(*) total FROM book_borrowing_data_1000_books WHERE $timeCondition GROUP BY book_type ORDER BY total DESC";
$stmtChart = executeDynamicQuery($conn, $sqlChart, $isFiltered, $baseDate, $days ?? 0);
$queryChart = mysqli_stmt_get_result($stmtChart);
$labels = []; $values = [];
while ($row = mysqli_fetch_assoc($queryChart)) {
    $labels[] = $row['book_type'];
    $values[] = $row['total'];
}
mysqli_stmt_close($stmtChart);

// 6. Resource Lists Collection
$sqlTop = "SELECT book_id, book_type, COUNT(*) total FROM book_borrowing_data_1000_books WHERE $timeCondition GROUP BY book_id, book_type ORDER BY total DESC LIMIT 10";
$stmtTop = executeDynamicQuery($conn, $sqlTop, $isFiltered, $baseDate, $days ?? 0);
$topBorrowResult = mysqli_stmt_get_result($stmtTop);

$sqlLeast = "SELECT book_id, book_type, COUNT(*) total FROM book_borrowing_data_1000_books WHERE $timeCondition GROUP BY book_id, book_type ORDER BY total ASC LIMIT 5";
$stmtLeast = executeDynamicQuery($conn, $sqlLeast, $isFiltered, $baseDate, $days ?? 0);
$leastBorrowResult = mysqli_stmt_get_result($stmtLeast);

// 7. วิเคราะห์แนวโน้มและโอกาสในการส่งเสริมหนังสือที่ใช้น้อย
$historyTimeCondition = $isFiltered
    ? "h.borrow_date >= DATE_SUB(?, INTERVAL ? DAY)"
    : "1=1";

$sqlTrend = "SELECT DATE_FORMAT(h.borrow_date, '%Y-%m') AS period, COUNT(*) AS total
             FROM book_borrowing_data_1000_books h
             WHERE $historyTimeCondition
             GROUP BY period ORDER BY period ASC";
$stmtTrend = executeDynamicQuery($conn, $sqlTrend, $isFiltered, $baseDate, $days ?? 0);
$trendRows = mysqli_fetch_all(mysqli_stmt_get_result($stmtTrend), MYSQLI_ASSOC);
mysqli_stmt_close($stmtTrend);
$trendLabels = array_column($trendRows, 'period');
$trendValues = array_map('intval', array_column($trendRows, 'total'));

$sqlUnderused = "SELECT b.book_id, b.title, b.author, b.book_type, COUNT(h.book_id) AS total
                 FROM books b
                 LEFT JOIN book_borrowing_data_1000_books h
                   ON h.book_id = b.book_id AND $historyTimeCondition
                 WHERE b.status = 'available'
                 GROUP BY b.book_id, b.title, b.author, b.book_type
                 ORDER BY total ASC, b.title ASC LIMIT 5";
$stmtUnderused = executeDynamicQuery($conn, $sqlUnderused, $isFiltered, $baseDate, $days ?? 0);
$underusedBooks = mysqli_fetch_all(mysqli_stmt_get_result($stmtUnderused), MYSQLI_ASSOC);
mysqli_stmt_close($stmtUnderused);

$sqlInactiveCount = "SELECT COUNT(*) AS total FROM (
                        SELECT b.book_id
                        FROM books b
                        LEFT JOIN book_borrowing_data_1000_books h
                          ON h.book_id = b.book_id AND $historyTimeCondition
                        GROUP BY b.book_id
                        HAVING COUNT(h.book_id) = 0
                     ) AS inactive_books";
$stmtInactive = executeDynamicQuery($conn, $sqlInactiveCount, $isFiltered, $baseDate, $days ?? 0);
$inactiveBooks = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmtInactive))['total'] ?? 0);
mysqli_stmt_close($stmtInactive);

$sqlTopShare = "SELECT COALESCE(SUM(total), 0) AS total FROM (
                    SELECT COUNT(*) AS total
                    FROM book_borrowing_data_1000_books
                    WHERE $timeCondition
                    GROUP BY book_id
                    ORDER BY total DESC LIMIT 10
                ) AS top_books";
$stmtTopShare = executeDynamicQuery($conn, $sqlTopShare, $isFiltered, $baseDate, $days ?? 0);
$topBorrowShareCount = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTopShare))['total'] ?? 0);
mysqli_stmt_close($stmtTopShare);
$topBorrowShare = $totalBorrow > 0 ? round(($topBorrowShareCount / $totalBorrow) * 100, 1) : 0;

// ---------------- 🤖 7. Async AJAX API Endpoint สำหรับ Gemini AI ----------------
if (isset($_GET['action']) && $_GET['action'] === 'get_ai_analysis') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/gemini_config.php';

    $aiModel = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.5-flash';
    $apiKey  = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    $url     = defined('GEMINI_API_URL') ? GEMINI_API_URL : '';

    if (empty($apiKey) || empty($url)) {
        echo json_encode(['status' => 'error', 'message' => '⚠️ ยังไม่ได้ตั้งค่า GEMINI_API_KEY ใน gemini_config.php']);
        exit;
    }

    $topBooksText = "";
    mysqli_data_seek($topBorrowResult, 0);
    $count = 0;
    while ($tb = mysqli_fetch_assoc($topBorrowResult)) {
        if ($count++ < 5) {
            $topBooksText .= "- Book ID: {$tb['book_id']} | หมวด: {$tb['book_type']} | ยืม {$tb['total']} ครั้ง\n";
        }
    }

    $genreText = "";
    foreach ($labels as $i => $label) {
        $genreText .= "- {$label}: " . ($values[$i] ?? 0) . " ครั้ง\n";
    }

    $promptText = <<<PROMPT
คุณคือ AI ผู้ช่วยวิเคราะห์ข้อมูลสำหรับผู้ดูแลห้องสมุด

วิเคราะห์ข้อมูล Dashboard ต่อไปนี้ โดยใช้เฉพาะข้อมูลที่ให้มา
ช่วงเวลาที่เลือก: {$filter}
ยอดยืมรวม: {$totalBorrow} ครั้ง
จำนวนหนังสือที่ถูกยืมแบบไม่ซ้ำ: {$totalBooks} เล่ม
หมวดหมู่ยอดนิยม: {$popularType}
มูลค่าความคุ้มค่าโดยประมาณ: {$estimatedSavings} บาท

สถิติการยืมแยกตามหมวด:
{$genreText}

หนังสือ/รายการที่มียอดยืมสูง:
{$topBooksText}

ข้อกำหนดคำตอบ:
1. ตอบเป็นภาษาไทยเท่านั้น ห้ามใช้ภาษาอังกฤษหรือ Markdown
2. ตอบตามแม่แบบนี้เท่านั้น โดยแทนที่ข้อความในวงเล็บ:
1. ภาพรวมการใช้งาน: (สรุปยอดยืม 1 ประโยค)
2. หมวดหมู่ยอดนิยม: (สรุปหมวดหมู่ 1 ประโยค)
3. หนังสือที่ควรส่งเสริม: (เสนอจากข้อมูลที่ให้ 1 ประโยค)
4. ข้อเสนอแนะ: (เสนอแนวทาง 1 ประโยค)
3. ต้องมีครบ 4 ข้อ เริ่มด้วย 1. และจบด้วยข้อ 4. เท่านั้น
4. ห้ามคัดลอกคำสั่ง ตัวอย่าง หรือข้อความภาษาอังกฤษจากข้อมูลเข้ามาในคำตอบ
PROMPT;

    $postData = [
        "contents" => [["role" => "user", "parts" => [["text" => $promptText]]]],
        // ภาษาไทยใช้ token ค่อนข้างมาก จึงเผื่อจำนวน token เพื่อไม่ให้คำตอบ
        // ถูกตัดกลางประโยคเมื่อ Gemini สรุปครบทุกข้อ
        "generationConfig" => ["temperature" => 0.2, "maxOutputTokens" => 768]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CAINFO => __DIR__ . '/certs/cacert.pem',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . trim($apiKey)],
        CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $aiHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $aiHttpCode >= 200 && $aiHttpCode < 300) {
        $responseData = json_decode($response, true);
        $aiText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($aiText !== '') {
            echo json_encode([
                'status' => 'success',
                'analysis' => trim($aiText),
                'meta' => "อัปเดตข้อมูลเรียบร้อย"
            ]);
            exit;
        }
    }

    echo json_encode([
        'status' => 'error',
        'message' => "⚠️ ไม่สามารถเชื่อมต่อ Gemini API ได้ (" . ($curlError ?: "HTTP Code: {$aiHttpCode}") . ")"
    ]);
    exit;
}

// 8. CSV Export Engine
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=library_report.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['หัวข้อการจัดอันดับ', 'ข้อมูลสรุปสถิติ']);
    fputcsv($output, ['จำนวนการยืมทั้งหมด', $totalBorrow]);
    fputcsv($output, ['จำนวนหนังสือทั้งหมด', $totalBooks]);
    fputcsv($output, ['ประเภทยอดนิยม', $popularType]);
    fputcsv($output, ['ประมาณการมูลค่าความคุ้มค่า (บาท)', $estimatedSavings]);
    fputcsv($output, []);
    fputcsv($output, ['หนังสือที่มีการยืมสูงสุด 10 อันดับ']);
    fputcsv($output, ['Book ID', 'ประเภท', 'ยอดยืม']);

    $stmtExp = executeDynamicQuery($conn, $sqlTop, $isFiltered, $baseDate, $days ?? 0);
    $resExp = mysqli_stmt_get_result($stmtExp);
    while ($row = mysqli_fetch_assoc($resExp)) {
        fputcsv($output, [$row['book_id'], $row['book_type'], $row['total']]);
    }
    mysqli_stmt_close($stmtExp);
    fclose($output);
    exit;
}

$usersResult = mysqli_query($conn, "SELECT user_id, username, fullname, role FROM users LIMIT 20");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/app.css">
<title>Library Intelligence Dashboard - Admin Panel</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{ margin:0; background:#f8fafc; font-family:system-ui, -apple-system, sans-serif; color:#1e293b; }
.container{ max-width:1200px; margin:auto; padding:24px; }
.header-actions{ display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px; }
h1{ margin:0; color:#111827; font-size:26px; font-weight:700; }
.controls{ display:flex; gap:12px; align-items:center; }
select, .btn, input[type="text"]{ padding:10px 16px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; outline:none; font-family:inherit; }
select{ background:white; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.05); }
.btn{ background:#10b981; color:white; border:none; cursor:pointer; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; }
.btn:hover{ background:#059669; }
.btn-main{ background:#2563eb; }
.btn-main:hover{ background:#1d4ed8; }

.alert-banner{ background:#fff1f2; border-left:4px solid #f43f5e; padding:14px 20px; border-radius:8px; margin-bottom:24px; color:#9f1239; font-size:14px; }
.cards{ display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:20px; margin-bottom:24px; }
.card{ background:white; padding:24px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-top:4px solid #10b981; }
.card.roi { border-top-color: #3b82f6; }
.card p{ margin:0 0 6px 0; color:#64748b; font-size:13px; font-weight:500; text-transform:uppercase; }
.card h2{ margin:0; font-size:28px; color:#0f172a; font-weight:700; }

.grid{ display:grid; grid-template-columns:2fr 1fr; gap:24px; margin-bottom:24px; align-items: start; }
@media (max-width: 968px) { .grid { grid-template-columns: 1fr; } }

.panel{ background:white; padding:24px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
.panel h2{ margin-top:0; margin-bottom:20px; font-size:16px; font-weight:600; color:#334155; border-bottom:1px solid #f1f5f9; padding-bottom:12px; }
table{ width:100%; border-collapse:collapse; }
th,td{ padding:12px; border-bottom:1px solid #f1f5f9; text-align:left; font-size:14px; }
th{ background:#f8fafc; color:#64748b; font-weight:600; }
.badge{ background:#fee2e2; color:#ef4444; padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600; }

/* 🤖 AI Card Style - แก้ไขความสูงให้ยืดตามเนื้อหา และจัดการตัดคำ/จัดบรรทัด */
.ai { 
    background: #064e3b; 
    color: #ecfdf5; 
    height: auto !important; 
    min-height: 0;
    max-height: none !important; 
    overflow: visible !important; 
}
.ai h2 { color: white; border-bottom: 1px solid #047857; }
.ai-content { 
    font-size: 14px; 
    line-height: 1.8; 
    overflow-wrap: anywhere;
    white-space: pre-line; 
}
.ai-content strong { color: #6ee7b7; font-weight: 600; }

.chart-container { position: relative; margin: auto; height: 260px; width: 260px; }
.trend-container { position: relative; height: 300px; }
.insight-list { margin:0; padding-left:20px; color:#475569; line-height:1.8; font-size:14px; }
.recommendation-tag { display:inline-block; margin-top:5px; padding:3px 8px; border-radius:999px; background:#d1fae5; color:#065f46; font-size:11px; font-weight:600; }

.scroll-buttons { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column; gap: 10px; z-index: 1000; }
.btn-scroll { width: 44px; height: 44px; background: #2563eb; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 20px; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }

/* Spinner Loading */
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.8s linear infinite; vertical-align: middle; margin-right: 6px; }
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div class="container">
    <?php if($alertMsg): ?>
        <div style="background:#dcfce7; color:#166534; padding:12px 20px; border-radius:8px; margin-bottom:20px; font-weight:600;">
            ✅ <?php echo htmlspecialchars($alertMsg); ?>
        </div>
    <?php endif; ?>

    <div class="header-actions">
        <div>
            <h1>Library Intelligence Dashboard</h1>
            <span style="font-size: 14px; color: #64748b;">ผู้ดูแลระบบ: <b><?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin'); ?></b></span>
        </div>
        <div class="controls">
            <a href="mainpage.php" class="btn btn-main">🏠 กลับหน้าหลัก</a>
            <form method="GET" action="">
                <select name="filter" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                    <option value="week" <?php echo $filter === 'week' ? 'selected' : ''; ?>>7 วันล่าสุด</option>
                    <option value="month" <?php echo $filter === 'month' ? 'selected' : ''; ?>>30 วันล่าสุด</option>
                    <option value="year" <?php echo $filter === 'year' ? 'selected' : ''; ?>>1 ปีล่าสุด</option>
                </select>
            </form>
            <a href="?filter=<?php echo urlencode($filter); ?>&export=1" class="btn">Export CSV</a>
        </div>
    </div>

    <?php if(!empty($alerts)): ?>
    <div class="alert-banner">
        <strong>⚠️ คลังหนังสือวิกฤต (Stock Shortage Risk):</strong>
        <?php foreach($alerts as $alert): ?>
            <div>หนังสือรหัส <code><?php echo htmlspecialchars($alert['book_id']); ?></code> (หมวด <?php echo htmlspecialchars($alert['book_type']); ?>) มีอัตราการยืมสูงถึง <?php echo $alert['total']; ?> ครั้ง แนะนำให้เพิ่มจำนวนเล่มบนชั้นวาง</div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="cards">
        <div class="card">
            <p>จำนวนการยืมทั้งหมด</p>
            <h2><?php echo number_format($totalBorrow);?></h2>
        </div>
        <div class="card">
            <p>หนังสือที่ถูกยืม</p>
            <h2><?php echo number_format($totalBooks);?></h2>
        </div>
        <div class="card">
            <p>ประเภทยอดนิยม</p>
            <h2><?php echo htmlspecialchars($popularType !== '' ? $popularType : '-');?></h2>
        </div>
        <div class="card roi">
            <p>มูลค่าความคุ้มค่ารวม</p>
            <h2>฿<?php echo number_format($estimatedSavings);?></h2>
        </div>
        <div class="card">
            <p>หนังสือที่ยังไม่ถูกยืม</p>
            <h2><?php echo number_format($inactiveBooks);?></h2>
        </div>
        <div class="card roi">
            <p>ยอดยืมจาก Top 10</p>
            <h2><?php echo number_format($topBorrowShare, 1);?>%</h2>
        </div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>สัดส่วนประเภทหนังสือ</h2>
            <div class="chart-container">
                <canvas id="chart"></canvas>
            </div>
        </div>

        <!-- 🤖 กล่องแสดงผลบทวิเคราะห์จาก Gemini AI -->
        <div class="panel ai">
            <h2>สรุปข้อมูลการใช้งาน</h2>
            <div id="aiMeta" style="font-size:12px; margin:-10px 0 14px; opacity:.9;">
                <span class="spinner"></span> กำลังจัดเตรียมสรุปข้อมูล...
            </div>
            <div id="aiContent" class="ai-content">กำลังประมวลผลข้อมูลสถิติ...</div>
        </div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>แนวโน้มการยืมตามเวลา</h2>
            <div class="trend-container"><canvas id="trendChart"></canvas></div>
        </div>
        <div class="panel">
            <h2>Insight สำหรับการตัดสินใจ</h2>
            <ul class="insight-list">
                <li>หนังสือที่ยังไม่มีการยืมในช่วงที่เลือก: <b><?php echo number_format($inactiveBooks); ?> เล่ม</b></li>
                <li>หนังสือ 10 อันดับแรกสร้างยอดยืม <b><?php echo number_format($topBorrowShare, 1); ?>%</b> ของทั้งหมด</li>
                <li>ใช้รายการ “หนังสือควรส่งเสริม” เพื่อวางแผนแนะนำบนหน้าแรกหรือจัดกิจกรรมส่งเสริมการอ่าน</li>
            </ul>
        </div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>Top Borrowed Books</h2>
            <table>
                <thead>
                    <tr>
                        <th>Book ID</th>
                        <th>ประเภท</th>
                        <th>ยอดยืม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($r = mysqli_fetch_assoc($topBorrowResult)){ ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['book_id']);?></td>
                        <td><?php echo htmlspecialchars($r['book_type']);?></td>
                        <td><?php echo (int)$r['total'];?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="panel">
            <h2>หนังสือควรส่งเสริมการใช้งาน</h2>
            <table>
                <thead>
                    <tr>
                        <th>หนังสือ</th>
                        <th>ประเภท</th>
                        <th>ยอดยืม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($underusedBooks as $r){ ?>
                    <tr>
                        <td>
                            <b><?php echo htmlspecialchars($r['title']);?></b><br>
                            <small><?php echo htmlspecialchars($r['author']);?></small>
                            <span class="recommendation-tag">แนะนำเพื่อเพิ่มการใช้งาน</span>
                        </td>
                        <td><?php echo htmlspecialchars($r['book_type']);?></td>
                        <td><span class="badge"><?php echo (int)$r['total'];?></span></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ส่วน Admin Tools: เพิ่มหนังสือ & จัดการสมาชิก -->
    <div class="grid">
        <div class="panel">
            <h2>➕ เพิ่มหนังสือใหม่เข้าสู่ระบบ</h2>
            <form method="POST" style="display:flex; flex-direction:column; gap:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="text" name="title" placeholder="ชื่อหนังสือ" required>
                <input type="text" name="author" placeholder="ชื่อผู้แต่ง" required>
                <input type="text" name="book_type" placeholder="หมวดหมู่หนังสือ" required>
                <input type="text" name="cover_image" placeholder="URL รูปภาพปก (เว้นว่างไว้สำหรับภาพเริ่มต้น)">
                <button type="submit" name="add_book" class="btn" style="justify-content:center;">บันทึกหนังสือ</button>
            </form>
        </div>

        <div class="panel">
            <h2>👤 จัดการสิทธิ์สมาชิก</h2>
            <table>
                <thead>
                    <tr>
                        <th>ชื่อผู้ใช้</th>
                        <th>สิทธิ์</th>
                        <th>จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($user = mysqli_fetch_assoc($usersResult)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><b><?php echo htmlspecialchars($user['role']); ?></b></td>
                        <td>
                            <form method="POST" style="display:flex; gap:6px;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                                <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                <select name="role" style="padding:4px 8px; font-size:12px;">
                                    <option value="user" <?php echo $user['role']==='user'?'selected':''; ?>>User</option>
                                    <option value="admin" <?php echo $user['role']==='admin'?'selected':''; ?>>Admin</option>
                                </select>
                                <button type="submit" name="update_role" class="btn" style="padding:4px 8px; font-size:12px;">บันทึก</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="scroll-buttons">
    <a href="#bottom" class="btn-scroll" title="ลงไปล่างสุด">↓</a>
    <a href="#" class="btn-scroll" title="กลับขึ้นด้านบน">↑</a>
</div>
<div id="bottom"></div>

<script>
// 1. Render Chart
const ctx = document.getElementById('chart').getContext('2d');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($labels); ?>,
        datasets: [{
            data: <?php echo json_encode($values); ?>,
            backgroundColor: ['#064e3b', '#047857', '#10b981', '#34d399', '#6ee7b7', '#a7f3d0', '#cbd5e1', '#e2e8f0'],
            borderWidth: 1,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'right',
                labels: { boxWidth: 12, font: { size: 12 } }
            }
        }
    }
});

const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trendLabels); ?>,
        datasets: [{
            label: 'จำนวนการยืม',
            data: <?php echo json_encode($trendValues); ?>,
            borderColor: '#047857',
            backgroundColor: 'rgba(16, 185, 129, 0.15)',
            fill: true,
            tension: 0.3,
            pointRadius: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

// 2. แปลงข้อความ AI เป็น HTML อย่างปลอดภัย แล้วคงรูปแบบการขึ้นบรรทัดไว้
function formatAiText(text) {
    if (!text) return '';
    const escapeHtml = (value) => value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    return escapeHtml(text)
        .replace(/^An analysis of.*?:\s*/gim, '')
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .trim();
}

// 3. Fetch AI Strategic Analysis แบบ Async หลังหน้าเว็บโหลดเสร็จ
document.addEventListener('DOMContentLoaded', () => {
    const currentFilter = '<?php echo urlencode($filter); ?>';
    fetch(`?action=get_ai_analysis&filter=${currentFilter}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                document.getElementById('aiMeta').innerText = data.meta;
                document.getElementById('aiContent').innerHTML = formatAiText(data.analysis);
            } else {
                document.getElementById('aiMeta').innerText = 'สถานะ: ขัดข้อง';
                document.getElementById('aiContent').innerText = data.message;
            }
        })
        .catch(err => {
            document.getElementById('aiMeta').innerText = 'สถานะ: การเชื่อมต่อล้มเหลว';
            document.getElementById('aiContent').innerText = '⚠️ ไม่สามารถดึงข้อมูลจาก AI ได้ในขณะนี้';
        });
});
</script>
</body>
</html>
<?php
mysqli_close($conn);
?>
