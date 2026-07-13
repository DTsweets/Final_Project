<?php
/**
 * LOGIN PAGE - FIGMA REDESIGN
 * ----------
 * รับ username + password → ตรวจสอบกับ DB → set session → redirect ตาม role
 */

session_start();

// ถ้า login อยู่แล้ว → โยนให้ router จัดการ
if (isset($_SESSION['user_id'])) {
    header('Location: router.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$error = '';
$timeout = isset($_GET['timeout']) && $_GET['timeout'] === '1';

// Read cookies for Remember Password feature
$cookie_user = $_COOKIE['rm_username'] ?? '';
$cookie_pass = $_COOKIE['rm_password'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้งานและรหัสผ่าน';
    } else {
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare(
                'SELECT u.*, a.affiliation_item AS affiliation_name
                 FROM users u
                 LEFT JOIN affiliation_id a ON a.id = u.Affiliation
                 WHERE u.username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'ไม่พบชื่อผู้ใช้งานนี้ในระบบ';
            } elseif ($user['password'] !== $password) {
                $error = 'รหัสผ่านไม่ถูกต้อง';
            } else {
                // ป้องกัน session fixation
                session_regenerate_id(true);

                // Remember Password (Set cookies for 30 days)
                if (isset($_POST['remember'])) {
                    setcookie('rm_username', $username, time() + (86400 * 30), "/");
                    setcookie('rm_password', $password, time() + (86400 * 30), "/");
                } else {
                    setcookie('rm_username', '', time() - 3600, "/");
                    setcookie('rm_password', '', time() - 3600, "/");
                }

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['firstname'] = $user['firstname'];
                $_SESSION['lastname'] = $user['lastname'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['affiliation_id'] = $user['Affiliation'];
                $_SESSION['affiliation_name'] = $user['affiliation_name'] ?? '';
                $_SESSION['profile_image'] = $user['profile_image'] ?? '';
                $_SESSION['last_activity'] = time();

                // ไปหน้าส่วนกลางเพื่อแยกสาย (Router)
                header('Location: router.php');
                exit;
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — UP Net Zero</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@700&family=Kanit:wght@400;500&display=swap"
        rel="stylesheet">

    <!-- External Stylesheet -->
    <link rel="stylesheet" href="assets/css/login.css<?= asset_v('assets/css/login.css') ?>">
    
    <!-- Preload Critical Assets (Point 6: Preload & Priority) -->
    <link rel="preload" as="image" href="assets/images/island_bg.webp">
</head>

<body>



    <!-- MAIN LOGIN CONTAINER -->
    <div class="login-container">
        <!-- MAIN LOGIN CARD -->
        <div class="login-card <?= $error ? 'shake-err' : '' ?>">

            <!-- Headers & Logo -->
            <div class="logo-area">
                <img src="assets/images/up-logo-opt.webp" alt="University of Phayao Logo" class="up-logo-img" loading="lazy">

                <h1 class="brand-title">
                    <span class="brand-up">UP</span>
                    <span class="brand-netzero">NET ZERO</span>
                </h1>
                <p class="brand-subtitle">ศูนย์สิ่งแวดล้อมและการจัดการที่ยั่งยืน</p>
            </div>

            <?php if ($timeout): ?>
                <div class="alert alert-warning">Session หมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Form Box -->
            <form method="POST" action=""
                style="width: 100%; display: flex; flex-direction: column; align-items: center;">
                <div class="form-wrapper">
                    <h2 class="form-title">ลงชื่อเพื่อเข้าใช้งานระบบ</h2>

                    <div class="form-group">
                        <label>Username</label>
                        <div
                            class="input-box <?= ($error && strpos($error, 'ชื่อผู้ใช้') !== false) ? 'error-border' : '' ?>">
                            <svg class="icon-svg" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                            </svg>
                            <input type="text" name="username" placeholder="username"
                                value="<?= htmlspecialchars($cookie_user) ?>" autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Password</label>
                        <div
                            class="input-box <?= ($error && strpos($error, 'รหัสผ่าน') !== false) ? 'error-border' : '' ?>">
                            <svg class="icon-svg" viewBox="0 0 24 24" fill="currentColor">
                                <path
                                    d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6zm9 14H6V10h12v10zm-6-3c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z" />
                            </svg>
                            <input type="password" name="password" placeholder="password"
                                value="<?= htmlspecialchars($cookie_pass) ?>" autocomplete="current-password">
                        </div>
                    </div>

                    <div class="remember-row">
                        <input type="checkbox" id="remember" name="remember" class="custom-checkbox"
                            <?= !empty($cookie_user) ? 'checked' : '' ?>>
                        <label for="remember">จำรหัสผ่าน</label>
                    </div>
                </div>

                <!-- Submit Button (Centered at bottom, outside inner box) -->
                <button type="submit" class="btn-submit">เข้าสู่ระบบ</button>
            </form>

        </div>
    </div>

</body>

</html>