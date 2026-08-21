<?php
require_once __DIR__ . '/bootstrap.php';
if (isset($_SESSION['user_id'])) { header("Location: mainpage.php"); exit; }
$conn = db();

$login_error = '';
$reg_error = '';
$reg_success = '';
$active_tab = $_GET['tab'] ?? 'login';

// ---------------- จัดการ เข้าสู่ระบบ (LOGIN) ----------------
if (isset($_POST['action_login'])) {
    verify_csrf();
    $active_tab = 'login';
    $username = trim($_POST['username']);
    $password = $_POST['password'] ?? '';

    $stmt = mysqli_prepare($conn, "SELECT user_id, username, fullname, password, role FROM users WHERE username = ? OR email = ?");
    mysqli_stmt_bind_param($stmt, "ss", $username, $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($user = mysqli_fetch_assoc($result)) {
        if (password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            header("Location: mainpage.php");
            exit;
        }
    }
    $login_error = "ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง";
}

// ---------------- จัดการ สมัครสมาชิก (REGISTER) ----------------
if (isset($_POST['action_register'])) {
    verify_csrf();
    $active_tab = 'register';
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['reg_username']);
    $email = trim($_POST['email']);
    $password = $_POST['reg_password'];
    $confirm_password = $_POST['confirm_password'];

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $reg_error = "ชื่อผู้ใช้ต้องมี 3–50 ตัวอักษร และใช้ได้เฉพาะ a-z, A-Z, 0-9, _, . หรือ -";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $reg_error = "รูปแบบอีเมลไม่ถูกต้อง";
    } elseif (mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) {
        $reg_error = "กรุณาระบุชื่อ-นามสกุลให้ถูกต้อง";
    } elseif (strlen($password) < 10) {
        $reg_error = "รหัสผ่านต้องมีอย่างน้อย 10 ตัวอักษร";
    } elseif ($password !== $confirm_password) {
        $reg_error = "รหัสผ่านยืนยันไม่ตรงกัน";
    } else {
        $checkStmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? OR email = ?");
        mysqli_stmt_bind_param($checkStmt, "ss", $username, $email);
        mysqli_stmt_execute($checkStmt);
        mysqli_stmt_store_result($checkStmt);

        if (mysqli_stmt_num_rows($checkStmt) > 0) {
            $reg_error = "ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้งานแล้ว";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'user'; // ผู้สมัครใหม่เป็น User เสมอ

            $insStmt = mysqli_prepare($conn, "INSERT INTO users (username, email, password, fullname, role) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($insStmt, "sssss", $username, $email, $hashed_password, $fullname, $role);

            if (mysqli_stmt_execute($insStmt)) {
                $reg_success = "สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ";
                $active_tab = 'login';
            } else {
                $reg_error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            }
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
<title>เข้าสู่ระบบ / สมัครสมาชิก</title>
<style>
* { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
.auth-card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); width: 100%; max-width: 400px; overflow: hidden; }
.tabs { display: flex; border-bottom: 1px solid #e2e8f0; }
.tab-btn { flex: 1; padding: 14px; text-align: center; background: #f1f5f9; border: none; font-weight: 600; cursor: pointer; color: #64748b; }
.tab-btn.active { background: white; color: #2563eb; border-bottom: 2px solid #2563eb; }
.form-container { padding: 24px; }
.form-group { margin-bottom: 14px; }
label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 4px; }
input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
.btn-submit { width: 100%; padding: 10px; background: #2563eb; color: white; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; margin-top: 10px; }
.btn-submit:hover { background: #1d4ed8; }
.alert { padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 14px; }
.alert-error { background: #fee2e2; color: #991b1b; }
.alert-success { background: #dcfce7; color: #166534; }
.back-link { display: block; text-align: center; margin-top: 16px; color: #64748b; text-decoration: none; font-size: 13px; }
</style>
</head>
<body>

<div class="auth-card">
    <div class="tabs">
        <button class="tab-btn <?php echo $active_tab === 'login' ? 'active' : ''; ?>" onclick="switchTab('login')">เข้าสู่ระบบ</button>
        <button class="tab-btn <?php echo $active_tab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register')">สมัครสมาชิก</button>
    </div>

    <!-- ฟอร์มเข้าสู่ระบบ -->
    <div id="login-form" class="form-container" style="display: <?php echo $active_tab === 'login' ? 'block' : 'none'; ?>;">
        <?php if($login_error): ?><div class="alert alert-error"><?php echo $login_error; ?></div><?php endif; ?>
        <?php if($reg_success): ?><div class="alert alert-success"><?php echo $reg_success; ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="form-group">
                <label>ชื่อผู้ใช้งาน หรือ อีเมล</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" name="action_login" class="btn-submit">เข้าสู่ระบบ</button>
        </form>
    </div>

    <!-- ฟอร์มสมัครสมาชิก -->
    <div id="register-form" class="form-container" style="display: <?php echo $active_tab === 'register' ? 'block' : 'none'; ?>;">
        <?php if($reg_error): ?><div class="alert alert-error"><?php echo $reg_error; ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="form-group">
                <label>ชื่อ-นามสกุล</label>
                <input type="text" name="fullname" required>
            </div>
            <div class="form-group">
                <label>ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" name="reg_username" required>
            </div>
            <div class="form-group">
                <label>อีเมล</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input type="password" name="reg_password" minlength="10" required>
            </div>
            <div class="form-group">
                <label>ยืนยันรหัสผ่าน</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" name="action_register" class="btn-submit">ยืนยันลงทะเบียน</button>
        </form>
    </div>
    
    <a href="mainpage.php" class="back-link">← กลับหน้าหลักห้องสมุด</a>
</div>

<script>
function switchTab(tab) {
    document.getElementById('login-form').style.display = tab === 'login' ? 'block' : 'none';
    document.getElementById('register-form').style.display = tab === 'register' ? 'block' : 'none';
    const btns = document.querySelectorAll('.tab-btn');
    btns[0].classList.toggle('active', tab === 'login');
    btns[1].classList.toggle('active', tab === 'register');
}
</script>
</body>
</html>
