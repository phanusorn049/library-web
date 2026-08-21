<?php
require_once __DIR__ . '/bootstrap.php';
$conn = db();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// บันทึกข้อมูลเมื่อมีการกด Submit และผ่านการตรวจฝั่ง Front-end มาแล้ว
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $student_id = trim($_POST['student_id'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $birthdate = !empty($_POST['birthdate']) ? $_POST['birthdate'] : null;
    $age = !empty($_POST['age']) ? (int)$_POST['age'] : null;
    $phone = trim($_POST['phone'] ?? '');

    $allowedGenders = ['ชาย', 'หญิง', 'อื่นๆ'];
    if (!preg_match('/^\d{10}$/', $student_id) || !in_array($gender, $allowedGenders, true) || !$birthdate || !preg_match('/^0\d{8,9}$/', $phone) || ($age !== null && ($age < 0 || $age > 120))) {
        http_response_code(422);
        exit('ข้อมูลส่วนตัวไม่ถูกต้อง');
    }

    $updateSql = "UPDATE users SET student_id = ?, gender = ?, birthdate = ?, age = ?, phone = ? WHERE user_id = ?";
    $stmtUpdate = mysqli_prepare($conn, $updateSql);
    if ($stmtUpdate) {
        mysqli_stmt_bind_param($stmtUpdate, "sssisi", $student_id, $gender, $birthdate, $age, $phone, $user_id);
        if (mysqli_stmt_execute($stmtUpdate)) {
            // บันทึกสำเร็จ: แจ้งเตือนแล้วเด้งไปหน้า mainpage.php ทันที
            echo "<script>
                alert('บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว!');
                window.location.href = 'mainpage.php';
            </script>";
            exit();
        } else {
            echo "<script>
                alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
                window.location.href = 'profile.php';
            </script>";
            exit();
        }
    }
}

// ดึงข้อมูลปัจจุบันของผู้ใช้มาแสดงในฟอร์ม
$stmtUser = mysqli_prepare($conn, "SELECT username, fullname, email, student_id, gender, birthdate, age, phone FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmtUser, "i", $user_id);
mysqli_stmt_execute($stmtUser);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtUser));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/app.css">
<title>จัดการข้อมูลส่วนตัว</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #1e293b; }

.navbar { background: #0f172a; color: white; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
.navbar a { color: white; text-decoration: none; font-weight: 600; }

.container { max-width: 650px; margin: 40px auto; padding: 0 16px; }
.card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 32px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.card-title { font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 24px; color: #0f172a; border-bottom: 2px solid #2563eb; padding-bottom: 8px; }

.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; color: #334155; }
.form-control { width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; outline: none; transition: border 0.2s; }
.form-control:focus { border-color: #2563eb; }
.form-control[readonly] { background-color: #f1f5f9; color: #64748b; cursor: not-allowed; }

.row { display: flex; gap: 16px; }
.col { flex: 1; }

.btn-submit { width: 100%; padding: 12px; background: #2563eb; color: white; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 10px; transition: background 0.2s; }
.btn-submit:hover { background: #1d4ed8; }
</style>
</head>
<body>

<header class="navbar">
    <h2>Library Catalog</h2>
    <a href="mainpage.php">← กลับหน้าหลัก</a>
</header>

<div class="container">
    <div class="card">
        <h2 class="card-title">👤 ข้อมูลส่วนตัวนักศึกษา</h2>

        <form method="POST" action="profile.php" id="profileForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <!-- ข้อมูลที่มีในระบบแล้ว -->
            <div class="form-group">
                <label>ชื่อ - นามสกุล</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['fullname'] ?? $user['username'] ?? ''); ?>" readonly>
            </div>

            <div class="form-group">
                <label>อีเมล (Email)</label>
                <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" readonly>
            </div>

            <hr style="border: 0; border-top: 1px dashed #e2e8f0; margin: 24px 0;">

            <!-- รหัสนักศึกษา: บังคับเป็นตัวเลข 10 หลักเท่านั้น -->
            <div class="form-group">
                <label>รหัสนักศึกษา <span style="color:red;">*</span></label>
                <input type="text" 
                       name="student_id" 
                       id="student_id"
                       class="form-control" 
                       placeholder="กรอกตัวเลข 10 หลัก (เช่น 6401000001)" 
                       maxlength="10" 
                       pattern="\d{10}"
                       value="<?php echo htmlspecialchars($user['student_id'] ?? ''); ?>" 
                       required>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>เพศ <span style="color:red;">*</span></label>
                    <select name="gender" class="form-control" required>
                        <option value="">-- เลือกเพศ --</option>
                        <option value="ชาย" <?php echo ($user['gender'] ?? '') === 'ชาย' ? 'selected' : ''; ?>>ชาย</option>
                        <option value="หญิง" <?php echo ($user['gender'] ?? '') === 'หญิง' ? 'selected' : ''; ?>>หญิง</option>
                        <option value="อื่นๆ" <?php echo ($user['gender'] ?? '') === 'อื่นๆ' ? 'selected' : ''; ?>>อื่นๆ</option>
                    </select>
                </div>
                <div class="col form-group">
                    <label>อายุ (ปี)</label>
                    <input type="number" name="age" class="form-control" id="ageInput" placeholder="คำนวณให้อัตโนมัติ" value="<?php echo htmlspecialchars($user['age'] ?? ''); ?>" readonly>
                </div>
            </div>

            <div class="row">
                <div class="col form-group">
                    <label>วัน/เดือน/ปี เกิด <span style="color:red;">*</span></label>
                    <input type="date" 
                           name="birthdate" 
                           class="form-control" 
                           id="birthdateInput" 
                           min="1950-01-01" 
                           max="<?php echo date('Y-m-d'); ?>" 
                           value="<?php echo htmlspecialchars($user['birthdate'] ?? ''); ?>" 
                           onchange="calculateAge()"
                           required>
                </div>

                <!-- เบอร์โทรศัพท์: บังคับขึ้นต้นด้วย 0 และเป็นตัวเลข 9-10 หลัก -->
                <div class="col form-group">
                    <label>เบอร์โทรศัพท์ <span style="color:red;">*</span></label>
                    <input type="tel" 
                           name="phone" 
                           id="phone"
                           class="form-control" 
                           placeholder="เช่น 0812345678" 
                           maxlength="10" 
                           pattern="0[0-9]{8,9}"
                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                           required>
                </div>
            </div>

            <button type="submit" class="btn-submit">บันทึกข้อมูลส่วนตัว</button>
        </form>
    </div>
</div>

<script>
// คำนวณอายุให้อัตโนมัติเมื่อเลือกวันเกิด
function calculateAge() {
    const birthdate = document.getElementById('birthdateInput').value;
    if (birthdate) {
        const today = new Date();
        const birthDate = new Date(birthdate);
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
            age--;
        }
        document.getElementById('ageInput').value = age > 0 ? age : 0;
    }
}

// ระบบดักจับคำเตือนเมื่อกรอกข้อมูลไม่ตรงตามเงื่อนไขก่อนส่งฟอร์ม
document.getElementById('student_id').addEventListener('invalid', function() {
    if (this.validity.valueMissing) {
        this.setCustomValidity('กรุณากรอกรหัสนักศึกษา');
    } else if (this.validity.patternMismatch) {
        this.setCustomValidity('รหัสนักศึกษาต้องเป็นตัวเลข 10 หลักเท่านั้น (เช่น 6401000001)');
    }
});

document.getElementById('student_id').addEventListener('input', function() {
    this.setCustomValidity('');
});

document.getElementById('phone').addEventListener('invalid', function() {
    if (this.validity.valueMissing) {
        this.setCustomValidity('กรุณากรอกเบอร์โทรศัพท์');
    } else if (this.validity.patternMismatch) {
        this.setCustomValidity('เบอร์โทรศัพท์ต้องขึ้นต้นด้วย 0 และเป็นตัวเลข 9-10 หลัก');
    }
});

document.getElementById('phone').addEventListener('input', function() {
    this.setCustomValidity('');
});
</script>

</body>
</html>
<?php mysqli_close($conn); ?>
