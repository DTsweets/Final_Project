<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$root = '../';
$pdo = getDB();
$page_title = "ตั้งค่าผู้ใช้งาน";

$admin_firstname = $_SESSION['firstname'] ?? 'Admin';
$admin_lastname = $_SESSION['lastname'] ?? '';

$msg = '';
$msg_type = 'alert-success';

// ฟังก์ชันลดขนาดภาพเพื่อแก้ปัญหาภาพใหญ่/โหลดช้า และแปลงเป็น WebP
function resizeProfileImage($sourcePath, $targetPath, $inputExt)
{
    if (!file_exists($sourcePath))
        return false;
    list($origWidth, $origHeight) = @getimagesize($sourcePath);
    if (!$origWidth || !$origHeight)
        return false;

    $maxWidth = 400;
    $maxHeight = 400;
    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);

    $newWidth = ($ratio >= 1) ? $origWidth : (int) ($origWidth * $ratio);
    $newHeight = ($ratio >= 1) ? $origHeight : (int) ($origHeight * $ratio);

    $imageP = imagecreatetruecolor($newWidth, $newHeight);

    imagealphablending($imageP, false);
    imagesavealpha($imageP, true);
    $transparent = imagecolorallocatealpha($imageP, 255, 255, 255, 127);
    imagefilledrectangle($imageP, 0, 0, $newWidth, $newHeight, $transparent);

    switch ($inputExt) {
        case 'jpeg':
        case 'jpg':
            $image = @imagecreatefromjpeg($sourcePath);
            break;
        case 'png':
            $image = @imagecreatefrompng($sourcePath);
            break;
        case 'gif':
            $image = @imagecreatefromgif($sourcePath);
            break;
        case 'webp':
            $image = @imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    if (!$image)
        return false;

    imagecopyresampled($imageP, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    $success = imagewebp($imageP, $targetPath, 82);

    imagedestroy($imageP);
    imagedestroy($image);
    return $success;
}

// Handle User Management Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add User
    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $affiliation = !empty($_POST['affiliation']) ? (int) $_POST['affiliation'] : null;

        try {
            $stmt = $pdo->prepare('INSERT INTO users (username, password, firstname, lastname, email, role, Affiliation) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$username, $password, $firstname, $lastname, $email, $role, $affiliation]);
            $msg = "เพิ่มสมาชิก $firstname $lastname เรียบร้อยแล้ว";
        } catch (PDOException $e) {
            $msg_type = 'alert-danger';
            $msg = "ไม่สามารถเพิ่มสมาชิกได้: " . ($e->getCode() == 23000 ? "Username หรือ Email ซ้ำ" : $e->getMessage());
        }
    }

    // Edit User
    if ($action === 'edit') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname = trim($_POST['lastname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'user';
        $affiliation = !empty($_POST['affiliation']) ? (int) $_POST['affiliation'] : null;

        // Retrieve current profile_image in order to fallback
        $stmtImg = $pdo->prepare('SELECT profile_image FROM users WHERE id = ?');
        $stmtImg->execute([$user_id]);
        $currentUserData = $stmtImg->fetch();
        $filename = $currentUserData ? $currentUserData['profile_image'] : null;

        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_pic'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($ext, $allowed)) {
                $new_filename = 'user_' . $user_id . '_' . time() . '.webp';
                $upload_dir = __DIR__ . '/../assets/images/profiles/';
                if (!is_dir($upload_dir))
                    mkdir($upload_dir, 0777, true);
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    if (resizeProfileImage($upload_path, $upload_path, $ext)) {
                        $filename = $new_filename;
                        if ($user_id === (int) $_SESSION['user_id']) {
                            $_SESSION['profile_image'] = $filename;
                        }
                    } else {
                        $msg = 'เกิดข้อผิดพลาดในการประมวลผลรูปภาพโปรไฟล์';
                        $msg_type = 'alert-danger';
                    }
                } else {
                    $msg = 'เกิดข้อผิดพลาดในการบันทึกรูปภาพโปรไฟล์';
                    $msg_type = 'alert-danger';
                }
            } else {
                $msg = 'ไฟล์รูปโปรไฟล์ไม่รองรับ อนุญาตเฉพาะ JPG, PNG, GIF, WEBP';
                $msg_type = 'alert-warning';
            }
        } elseif (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {
            $msg = 'เกิดข้อผิดพลาดอัปโหลดรูปภาพ: รหัสข้อผิดพลาด ' . $_FILES['profile_pic']['error'];
            $msg_type = 'alert-danger';
        }

        if ($msg_type !== 'alert-danger' && $msg_type !== 'alert-warning') {
            try {
                $stmt = $pdo->prepare('UPDATE users SET username = ?, password = ?, firstname = ?, lastname = ?, email = ?, role = ?, Affiliation = ?, profile_image = ? WHERE id = ?');
                $stmt->execute([$username, $password, $firstname, $lastname, $email, $role, $affiliation, $filename, $user_id]);
                $msg = "แก้ไขข้อมูลสมาชิก $firstname $lastname เรียบร้อยแล้ว";
                $msg_type = 'alert-success';

                if ($user_id === (int) $_SESSION['user_id']) {
                    $_SESSION['firstname'] = $firstname;
                    $_SESSION['lastname'] = $lastname;
                    $_SESSION['username'] = $username;
                }
            } catch (PDOException $e) {
                $msg_type = 'alert-danger';
                $msg = "ไม่สามารถแก้ไขข้อมูลได้: " . ($e->getCode() == 23000 ? "Username หรือ Email ซ้ำ" : $e->getMessage());
            }
        }
    }

    // Delete User
    if ($action === 'delete') {
        $user_id = (int) ($_POST['user_id'] ?? 0);
        if ($user_id !== (int) $_SESSION['user_id']) {
            try {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user_id]);
                $msg = "ลบสมาชิกเรียบร้อยแล้ว";
            } catch (Exception $e) {
                $msg_type = 'alert-danger';
                $msg = "ลบสมาชิกล้มเหลว";
            }
        } else {
            $msg_type = 'alert-danger';
            $msg = "ไม่สามารถลบบัญชีตัวเองได้";
        }
    }
}

// Fetch all users
$stmt_users = $pdo->prepare("SELECT u.*, a.affiliation_item FROM users u LEFT JOIN affiliation_id a ON u.Affiliation = a.id ORDER BY u.id DESC");
$stmt_users->execute();
$users_list = $stmt_users->fetchAll(PDO::FETCH_ASSOC);
$total_users = count($users_list);

// Fetch all affiliations
$stmt_aff = $pdo->prepare("SELECT * FROM affiliation_id ORDER BY id ASC");
$stmt_aff->execute();
$affiliations = $stmt_aff->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>ตั้งค่าระบบ — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap"
        rel="stylesheet">
    <!-- Use the premium admin CSS -->
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css?v=4">
    <link rel="stylesheet" href="<?= $root ?>assets/css/settings.css?v=1">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css">
</head>

<body style="background-color: var(--bg-base);">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <style>
            /* Modal Animations */
            @keyframes fadeIn {
                from {
                    opacity: 0;
                }

                to {
                    opacity: 1;
                }
            }

            @keyframes modalPop {
                from {
                    opacity: 0;
                    transform: scale(0.92) translateY(-20px);
                }

                to {
                    opacity: 1;
                    transform: scale(1) translateY(0);
                }
            }

            /* =====================================================
               ADMIN SETTINGS CSS — Premium Minimal Light
               ===================================================== */

            .settings-container {
                max-width: 1000px;
                margin: 0 auto;
            }

            .settings-card {
                background: var(--bg-card, #FFFFFF);
                border: 1px solid var(--border, #E8DDCE);
                border-radius: 24px;
                padding: 3rem;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
                margin-bottom: 2rem;
                transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
                position: relative;
                overflow: hidden;
            }

            .settings-card:hover {
                transform: translateY(-5px);
                border-color: rgba(98, 54, 139, 0.2);
                box-shadow: 0 20px 40px rgba(98, 54, 139, 0.06);
            }

            /* Ambient accent glow */
            .settings-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 4px;
                background: linear-gradient(90deg, #62368B, #FBB03B);
                opacity: 0;
                transition: opacity 0.4s ease;
            }

            .settings-card:hover::before {
                opacity: 1;
            }

            .settings-section-title {
                font-size: 1.35rem;
                font-weight: 800;
                color: var(--text-primary, #1F2937);
                margin-bottom: 2rem;
                display: flex;
                align-items: center;
                gap: 12px;
                letter-spacing: -0.02em;
            }

            .settings-section-title svg {
                color: var(--clr-primary, #62368B);
                background: rgba(98, 54, 139, 0.08);
                padding: 6px;
                border-radius: 10px;
                width: 36px;
                height: 36px;
                box-shadow: 0 4px 10px rgba(98, 54, 139, 0.05);
            }

            .settings-form-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
            }

            @media (max-width: 768px) {
                .settings-form-row {
                    grid-template-columns: 1fr;
                }
            }

            .avatar-upload {
                display: flex;
                align-items: center;
                gap: 1.75rem;
                margin-bottom: 2.5rem;
                background: #F9FAFB;
                padding: 1.5rem;
                border-radius: 20px;
                border: 1px dashed #D1D5DB;
                transition: all 0.3s ease;
            }

            .avatar-upload:hover {
                border-color: #62368B;
                background: #F9F5FF;
            }

            .avatar-preview {
                width: 90px;
                height: 90px;
                border-radius: 50%;
                background: linear-gradient(135deg, #F3E8FF, #FDF2F8);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #62368B;
                border: 3px solid #FFFFFF;
                box-shadow: 0 8px 20px rgba(98, 54, 139, 0.15);
                overflow: hidden;
                flex-shrink: 0;
                transition: all 0.3s ease;
            }

            .avatar-preview img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .btn-upload {
                background: #FFFFFF;
                color: #4B5563;
                border: 1px solid #D1D5DB;
                padding: 10px 24px;
                border-radius: 999px;
                font-weight: 600;
                font-size: 0.9rem;
                cursor: pointer;
                transition: all 0.2s;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            }

            .btn-upload:hover {
                border-color: #62368B;
                color: #62368B;
                background: #FFFFFF;
                transform: translateY(-1px);
                box-shadow: 0 4px 10px rgba(98, 54, 139, 0.1);
            }

            /* Modal Forms Minimal Light */
            .form-group-light {
                margin-bottom: 1.25rem;
                text-align: left;
            }

            .form-label-light {
                display: block;
                font-size: 0.95rem;
                font-weight: 600;
                color: #374151;
                margin-bottom: 0.5rem;
            }

            .form-control-light {
                width: 100%;
                padding: 12px 16px;
                background: #FDFCFB;
                border: 1px solid #E8DDCE;
                border-radius: 12px;
                color: #1F2937;
                font-size: 0.95rem;
                font-family: 'Kanit', sans-serif;
                transition: all 0.3s ease;
            }

            .form-control-light:focus {
                outline: none;
                border-color: #C49A6C;
                background: #FFFFFF;
                box-shadow: 0 0 0 4px rgba(196, 154, 108, 0.1);
            }

            .btn-secondary {
                background: #F3F4F6;
                color: #4B5563;
                border: 1px solid #E5E7EB;
                border-radius: 999px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            }

            .btn-secondary:hover {
                background: #E5E7EB;
                color: #1F2937;
            }

            .btn-primary {
                color: white;
                border: none;
                border-radius: 999px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            }

            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 20px rgba(196, 154, 108, 0.4) !important;
            }
        </style>
        <?php include __DIR__ . '/includes/header.php'; ?>

        <div class="page-content">
            <div class="settings-container" style="max-width: 1500px;">
                <!-- User Management (Figma Vibe) -->
                <div>
                    <?php if ($msg): ?>
                        <!-- Toast Notification -->
                        <div id="toast-notification" style="
                            position: fixed;
                            bottom: 28px;
                            right: 28px;
                            z-index: 999999;
                            min-width: 320px;
                            max-width: 420px;
                            background: <?= $msg_type === 'alert-danger' ? '#FFFFFF' : '#FFFFFF' ?>;
                            border-radius: 16px;
                            box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 16px rgba(0,0,0,0.08);
                            overflow: hidden;
                            animation: toastSlideIn 0.45s cubic-bezier(0.16,1,0.3,1);
                            border-left: 5px solid <?= $msg_type === 'alert-danger' ? '#EF4444' : '#10B981' ?>;
                        ">
                            <!-- Toast Body -->
                            <div style="display:flex; align-items:flex-start; gap:14px; padding:18px 18px 14px 18px;">
                                <!-- Icon -->
                                <div style="width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
                                    background:<?= $msg_type === 'alert-danger' ? '#FEE2E2' : '#D1FAE5' ?>;
                                    color:<?= $msg_type === 'alert-danger' ? '#EF4444' : '#10B981' ?>;">
                                    <?php if ($msg_type === 'alert-danger'): ?>
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                                            stroke-width="2.5">
                                            <circle cx="12" cy="12" r="10" />
                                            <line x1="15" y1="9" x2="9" y2="15" />
                                            <line x1="9" y1="9" x2="15" y2="15" />
                                        </svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                                            stroke-width="2.5">
                                            <circle cx="12" cy="12" r="10" />
                                            <path d="M9 12l2 2 4-4" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <!-- Text -->
                                <div style="flex:1; min-width:0;">
                                    <p
                                        style="margin:0 0 2px 0; font-size:0.8rem; font-weight:700; color:#9CA3AF; text-transform:uppercase; letter-spacing:0.05em;">
                                        <?= $msg_type === 'alert-danger' ? 'เกิดข้อผิดพลาด' : 'สำเร็จ' ?>
                                    </p>
                                    <p
                                        style="margin:0; font-size:0.95rem; font-weight:600; color:#1F2937; line-height:1.4;">
                                        <?= htmlspecialchars($msg) ?>
                                    </p>
                                </div>
                                <!-- Close Button -->
                                <button onclick="closeToast()"
                                    style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:1.3rem;line-height:1;padding:0;flex-shrink:0;transition:color 0.2s;"
                                    onmouseenter="this.style.color='#374151'"
                                    onmouseleave="this.style.color='#9CA3AF'">&times;</button>
                            </div>
                            <!-- Progress Bar -->
                            <div id="toast-progress"
                                style="height:3px;background:<?= $msg_type === 'alert-danger' ? '#EF4444' : '#10B981' ?>;width:100%;transform-origin:left;animation:toastProgress 4s linear forwards;">
                            </div>
                        </div>
                        <style>
                            @keyframes toastSlideIn {
                                from {
                                    opacity: 0;
                                    transform: translateX(60px) scale(0.95);
                                }

                                to {
                                    opacity: 1;
                                    transform: translateX(0) scale(1);
                                }
                            }

                            @keyframes toastSlideOut {
                                from {
                                    opacity: 1;
                                    transform: translateX(0) scale(1);
                                }

                                to {
                                    opacity: 0;
                                    transform: translateX(60px) scale(0.95);
                                }
                            }

                            @keyframes toastProgress {
                                from {
                                    width: 100%;
                                }

                                to {
                                    width: 0%;
                                }
                            }
                        </style>
                        <script>
                            var _toastTimer = setTimeout(function () { closeToast(); }, 4000);
                            function closeToast() {
                                clearTimeout(_toastTimer);
                                var t = document.getElementById('toast-notification');
                                if (t) {
                                    t.style.animation = 'toastSlideOut 0.35s cubic-bezier(0.16,1,0.3,1) forwards';
                                    setTimeout(function () { if (t) t.remove(); }, 350);
                                }
                            }
                        </script>
                    <?php endif; ?>

                    <!-- Top Header for Tab -->
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                        <h2 style="font-size: 1.4rem; color: #4B5563; font-weight: 700; margin: 0;">ข้อมูลผู้ใช้งาน</h2>
                        <button type="button" onclick="document.getElementById('add-user-modal').style.display='flex'"
                            style="background: #C49A6C; color: white; border: none; padding: 8px 20px; border-radius: 999px; font-family: 'Kanit', sans-serif; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(196, 154, 108, 0.3); transition: all 0.2s;"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 12px 24px '"
                            onmouseout="this.style.transform='none'; this.style.boxShadow='0 4px 10px'">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                stroke-width="3">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            เพิ่มผู้ใช้
                        </button>
                    </div>

                    <!-- Add User Modal -->
                    <div id="add-user-modal" class="modal-overlay"
                        style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; animation: fadeIn 0.3s ease;">
                        <div class="modal-content"
                            style="background: white; width: 100%; max-width: 650px; border-radius: 20px; padding: 30px; position: relative; animation: modalPop 0.4s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                                <h3 style="font-size: 1.3rem; color: #4B5563; font-weight: 700; margin: 0;">+
                                    เพิ่มบัญชีผู้ใช้ใหม่</h3>
                                <button type="button"
                                    onclick="document.getElementById('add-user-modal').style.display='none'"
                                    style="background:none; border:none; color:#9CA3AF; cursor:pointer; font-size:1.8rem; line-height: 1; transition: color 0.2s;">&times;</button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="add">
                                <div class="settings-form-row">
                                    <div class="form-group-light">
                                        <label class="form-label-light">ชื่อ *</label>
                                        <input type="text" name="firstname" class="form-control-light" required>
                                    </div>
                                    <div class="form-group-light">
                                        <label class="form-label-light">นามสกุล *</label>
                                        <input type="text" name="lastname" class="form-control-light" required>
                                    </div>
                                </div>
                                <div class="settings-form-row">
                                    <div class="form-group-light">
                                        <label class="form-label-light">Username *</label>
                                        <input type="text" name="username" class="form-control-light" required>
                                    </div>
                                    <div class="form-group-light">
                                        <label class="form-label-light">Password *</label>
                                        <input type="password" name="password" class="form-control-light" required>
                                    </div>
                                </div>
                                <div class="settings-form-row">
                                    <div class="form-group-light">
                                        <label class="form-label-light">Email</label>
                                        <input type="email" name="email" class="form-control-light">
                                    </div>
                                    <div class="form-group-light">
                                        <label class="form-label-light">สิทธิ์การใช้งาน *</label>
                                        <select name="role" class="form-control-light" required>
                                            <option value="user">เจ้าหน้าที่บันทึกข้อมูล</option>
                                            <option value="user_n">บุคลากร/คณบดี</option>
                                            <option value="admin">ผู้ดูแลระบบ</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="settings-form-row">
                                    <div class="form-group-light" style="grid-column: 1 / -1;">
                                        <label class="form-label-light">หน่วยงาน/คณะ</label>
                                        <select name="affiliation" class="form-control-light">
                                            <option value="">-- เลือกหน่วยงาน --</option>
                                            <?php foreach ($affiliations as $aff): ?>
                                                <option value="<?= $aff['id'] ?>">
                                                    <?= htmlspecialchars($aff['affiliation_item']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div
                                    style="display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #E5E7EB; gap: 12px;">
                                    <button type="button" class="btn-secondary"
                                        onclick="document.getElementById('add-user-modal').style.display='none'"
                                        style="padding: 12px 24px; font-size: 0.95rem;">ยกเลิก</button>
                                    <button type="submit" class="btn-primary"
                                        style="background:#C49A6C; box-shadow:0 8px 15px rgba(196,154,108,0.3); padding: 12px 32px; font-size: 0.95rem;">ยืนยันเพิ่มสมาชิก</button>
                                </div>
                            </form>
                        </div>
                    </div>



                    <!-- Custom DataTables Styling (Inline) -->
                    <style>
                        .figma-table-container {
                            background: #FFFFFF;
                            border-radius: 20px;
                            border: 1px solid #E5E7EB;
                            padding: 30px;
                            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
                        }

                        .figma-card-header {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            border-bottom: 1px solid #E5E7EB;
                            padding-bottom: 15px;
                            margin-bottom: 20px;
                        }

                        .figma-card-title {
                            font-size: 1.25rem;
                            font-weight: 700;
                            color: #4B5563;
                            margin: 0;
                        }

                        .figma-top-btns {
                            display: flex;
                            gap: 10px;
                        }

                        .f-btn {
                            padding: 8px 16px;
                            border-radius: 20px;
                            font-weight: 600;
                            font-size: 0.9rem;
                            border: none;
                            cursor: pointer;
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            color: white;
                            transition: all 0.2s;
                        }

                        .f-btn-blue {
                            background: #4B8BF5;
                        }

                        .f-btn-blue:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 12px 24px;
                        }

                        .f-btn-green {
                            background: #8BC34A;
                        }

                        .f-btn-green:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 12px 24px;
                        }

                        .f-btn-blue {
                            background: #4B8BF5;
                        }

                        .f-btn-green {
                            background: #8BC34A;
                        }

                        .figma-dt-controls {
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            gap: 16px;
                            flex-wrap: wrap;
                            margin-bottom: 20px;
                            color: #6B7280;
                            font-size: 0.95rem;
                            font-family: 'Kanit', sans-serif;
                        }

                        .f-search {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                            flex: 1 1 320px;
                            min-width: 0;
                        }

                        .f-search input {
                            border: 1px solid #E8DDCE;
                            border-radius: 12px;
                            padding: 8px 14px;
                            outline: none;
                            flex: 1 1 auto;
                            width: 100%;
                            min-width: 0;
                            font-family: 'Kanit', sans-serif;
                            font-size: 0.95rem;
                            color: #4B5563;
                            transition: border-color 0.2s, box-shadow 0.2s;
                        }

                        .f-pagination {
                            flex-wrap: wrap;
                        }

                        /* จอเล็ก: ให้ช่องค้นหาและ pagination เรียงลงมาเต็มความกว้าง */
                        @media (max-width: 768px) {
                            .figma-table-container {
                                padding: 18px;
                            }

                            .figma-dt-controls {
                                flex-direction: column;
                                align-items: stretch;
                            }

                            .f-search {
                                flex-basis: auto;
                            }

                            .f-pagination {
                                justify-content: flex-start;
                            }
                        }

                        @media (max-width: 480px) {
                            .figma-card-header {
                                flex-direction: column;
                                align-items: flex-start;
                                gap: 12px;
                            }

                            .figma-top-btns {
                                width: 100%;
                                flex-wrap: wrap;
                            }
                        }

                        .f-search input:focus {
                            border-color: #C49A6C;
                            box-shadow: 0 0 0 3px rgba(196, 154, 108, 0.12);
                        }

                        .f-search input::placeholder {
                            color: #9CA3AF;
                            font-family: 'Kanit', sans-serif;
                        }

                        .figma-table {
                            width: 100%;
                            border-collapse: separate;
                            border-spacing: 4px 6px;
                            /* spacing between cols and rows */
                        }

                        .figma-table th {
                            border: 1px solid #E8DDCE;
                            border-radius: 12px;
                            padding: 12px;
                            text-align: center;
                            color: #6B7280;
                            font-weight: 600;
                            font-size: 0.85rem;
                            font-family: 'Kanit', sans-serif;
                            white-space: nowrap;
                        }

                        .figma-table th.th-status {
                            border: 1px solid #FBB03B;
                            color: #4B5563;
                        }

                        .figma-table td {
                            background: #FCF9F5;
                            padding: 16px 14px;
                            border-radius: 12px;
                            vertical-align: middle;
                            color: #4B5563;
                            font-size: 0.95rem;
                            font-family: 'Kanit', sans-serif;
                        }

                        .role-pill {
                            display: inline-block;
                            background: transparent;
                            color: #4B5563;
                            font-weight: 600;
                            text-align: center;
                            width: 100%;
                            padding: 4px 0;
                        }

                        .f-action-btn {
                            width: 32px;
                            height: 32px;
                            border-radius: 6px;
                            border: none;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            cursor: pointer;
                            color: white;
                            margin: 3px auto;
                            transition: transform 0.1s;
                        }

                        .f-action-btn:hover {
                            transform: scale(1.05);
                        }

                        .fa-edit {
                            background: #4B8BF5;
                        }

                        .fa-delete {
                            background: #EF4444;
                        }

                        /* Pagination Mock */
                        .f-pagination {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                        }

                        .f-page-btn {
                            width: 28px;
                            height: 28px;
                            border-radius: 50%;
                            background: #C49A6C;
                            color: white;
                            border: none;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }

                        .f-page-btn-b {
                            width: 28px;
                            height: 28px;
                            border-radius: 20%;
                            background: #C49A6C;
                            color: white;
                            border: none;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }

                        .f-page-btn.light {
                            background: #FDF9F3;
                            color: #4B5563;
                            border: 1px solid #E5E7EB;
                            font-weight: 600;
                        }

                        .f-page-btn-b.light {
                            background: #FDF9F3;
                            color: #4B5563;
                            border: 1px solid #E5E7EB;
                            font-weight: 600;
                        }
                    </style>

                    <div class="figma-table-container">
                        <div class="figma-card-header">
                            <h3 class="figma-card-title">ข้อมูลผู้ใช้งาน</h3>
                            <div class="figma-top-btns">
                                <button class="f-btn f-btn-blue" onclick="openExportModal()"
                                    title="ส่งออกข้อมูลผู้ใช้งานเป็น Excel">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                        <polyline points="7 10 12 15 17 10"></polyline>
                                        <line x1="12" y1="15" x2="12" y2="3"></line>
                                    </svg>
                                    Export Excel
                                </button>
                                <div style="position: relative;">
                                    <button id="col-vis-btn" class="f-btn f-btn-green"
                                        onclick="toggleColVisMenu(event)">
                                        Column visibility
                                        <svg id="col-vis-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                            stroke="currentColor" stroke-width="2" style="transition: transform 0.2s;">
                                            <polyline points="6 9 12 15 18 9"></polyline>
                                        </svg>
                                    </button>
                                    <!-- Dropdown for Column Visibility -->
                                    <div id="col-vis-menu"
                                        style="position: absolute; top: calc(100% + 8px); right: 0; background: #FFF; border: 1px solid #E8DDCE; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); padding: 10px; z-index: 1000; display: none; min-width: 180px; font-family: 'Kanit', sans-serif;">
                                        <div
                                            style="font-size: 0.75rem; font-weight: 700; color: #9CA3AF; margin-bottom: 8px; padding-left: 6px; text-transform: uppercase; letter-spacing: 0.05em;">
                                            จัดการคอลัมน์</div>
                                        <div id="col-vis-list"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="figma-dt-controls">
                            <div class="f-search">
                                <input type="text" id="user-search-input"
                                    placeholder="ค้นหาชื่อ, username, email, หน่วยงาน..." oninput="filterUserTable()">
                            </div>
                            <div class="f-pagination">
                                Show
                                <?php
                                $dd_id = 'user-entries-select';
                                $dd_name = 'user_entries';
                                $dd_options = [
                                    ['value' => 10, 'label' => '10'],
                                    ['value' => 25, 'label' => '25'],
                                    ['value' => 50, 'label' => '50'],
                                    ['value' => -1, 'label' => 'ทั้งหมด'],
                                ];
                                $dd_selected = 10;
                                $dd_placeholder = '10';
                                $dd_required = false;
                                $dd_style = 'width: 100px; height: 40px; gap: 15px;';
                                include __DIR__ . '/../components/dropdown.php';
                                ?>
                                Entries
                                <button id="user-prev-btn" class="f-page-btn" style="margin-left: 10px;"
                                    onclick="userTablePrev()">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="3">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                </button>
                                <span id="user-page-label" class="f-page-btn-b light"
                                    style="min-width:40px;text-align:center;">1</span>
                                <button id="user-next-btn" class="f-page-btn" onclick="userTableNext()">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="3">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div style="overflow-x: auto;">
                            <table class="figma-table">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;"># &uarr;&darr;</th>
                                        <th>ชื่อ - สกุล</th>
                                        <th>Username</th>
                                        <th>Password</th>
                                        <th>Email</th>
                                        <th>หน่วยงาน/คณะ</th>
                                        <th class="th-status" style="width: 100px;">Status</th>
                                        <th style="width: 60px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="user-table-body">
                                    <?php $i = 1;
                                    foreach ($users_list as $u): ?>
                                        <tr>
                                            <td style="text-align:center; font-size:1.1rem;">
                                                <?= $i++ ?>
                                            </td>
                                            <td>
                                                <div style="font-weight:600; line-height: 1.3;">
                                                    <?= htmlspecialchars($u['firstname']) ?><br>
                                                    <?= htmlspecialchars($u['lastname']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($u['username']) ?>
                                            </td>
                                            <td>
                                                <?php
                                                // Format password like "Ping1422**" if it's long enough, or just show it as is
                                                $pw = $u['password'];
                                                if (strlen($pw) > 4) {
                                                    echo htmlspecialchars(substr($pw, 0, -2) . '**');
                                                } else {
                                                    echo htmlspecialchars($pw);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($u['email'] ?? '-') ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($u['affiliation_item'] ?? '-') ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <div class="role-pill">
                                                    <?= strtoupper($u['role']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <button type="button" class="f-action-btn fa-edit"
                                                    data-target-id="<?= $u['id'] ?>"
                                                    data-firstname="<?= htmlspecialchars($u['firstname'], ENT_QUOTES) ?>"
                                                    data-lastname="<?= htmlspecialchars($u['lastname'], ENT_QUOTES) ?>"
                                                    data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
                                                    data-password="<?= htmlspecialchars($u['password'], ENT_QUOTES) ?>"
                                                    data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>"
                                                    data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                                                    data-affiliation="<?= htmlspecialchars($u['Affiliation'] ?? '', ENT_QUOTES) ?>"
                                                    onclick="openEditModal(this)">
                                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
                                                        stroke="currentColor" stroke-width="2.5">
                                                        <path d="M12 20h9"></path>
                                                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z">
                                                        </path>
                                                    </svg>
                                                </button>
                                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                                    <button type="button" class="f-action-btn fa-delete"
                                                        onclick="openDeleteModal('<?= $u['id'] ?>', '<?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname'], ENT_QUOTES) ?>')">
                                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none"
                                                            stroke="currentColor" stroke-width="2.5">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path
                                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                            </path>
                                                        </svg>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <!-- No results row (shown via JS) -->
                            <div id="user-no-results"
                                style="display:none; text-align:center; padding:32px; color:#9CA3AF;">
                                <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor"
                                    stroke-width="1.5" style="margin-bottom:10px;opacity:0.4;">
                                    <circle cx="11" cy="11" r="8" />
                                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                                <p style="font-size:0.95rem;margin:0;">ไม่พบผู้ใช้งานที่ตรงกับคำค้นหา</p>
                            </div>
                        </div>

                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; color:#9CA3AF; font-size:0.85rem; padding: 0 4px;">
                            <span id="user-table-info">กำลังโหลด...</span>
                        </div>
                    </div>

                    <!-- ======= EXPORT CONFIG MODAL ======= -->
                    <div id="export-modal" class="modal-overlay"
                        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; justify-content:center; align-items:center; animation:fadeIn 0.3s ease;">
                        <div
                            style="background:#fff; width:100%; max-width:640px; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); overflow:hidden; animation:modalPop 0.4s cubic-bezier(0.16,1,0.3,1); position:relative;">

                            <!-- Gradient top bar (same as settings-card::before) -->
                            <div style="height:4px; background:linear-gradient(90deg,#62368B,#C49A6C,#FBB03B);"></div>

                            <!-- Step Header -->
                            <div style="padding:24px 30px 14px; border-bottom:1px solid #F3F0EC;">
                                <div
                                    style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                                    <h3
                                        style="font-size:1.2rem;font-weight:800;color:#1F2937;margin:0;letter-spacing:-0.02em;">
                                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#62368B"
                                            stroke-width="2.5"
                                            style="vertical-align:middle;margin-right:8px;background:rgba(98,54,139,0.08);padding:4px;border-radius:8px;">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                            <polyline points="7 10 12 15 17 10" />
                                            <line x1="12" y1="15" x2="12" y2="3" />
                                        </svg>
                                        ส่งออกข้อมูลผู้ใช้งาน
                                    </h3>
                                    <button type="button" onclick="closeExportModal()"
                                        style="background:none;border:none;color:#9CA3AF;cursor:pointer;font-size:1.8rem;line-height:1;transition:color 0.2s;"
                                        onmouseenter="this.style.color='#374151'"
                                        onmouseleave="this.style.color='#9CA3AF'">&times;</button>
                                </div>
                                <!-- Step indicator -->
                                <div style="display:flex; align-items:center; gap:0;">
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div id="exp-step-dot-1"
                                            style="width:26px;height:26px;border-radius:50%;background:#62368B;display:flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:700;color:#fff;">
                                            1</div>
                                        <span id="exp-lbl-1"
                                            style="font-size:0.85rem;font-weight:700;color:#62368B;">เลือกข้อมูล</span>
                                    </div>
                                    <div style="flex:1;margin:0 12px;height:2px;background:#E8DDCE;border-radius:2px;">
                                        <div id="exp-step-fill"
                                            style="height:100%;width:0%;background:linear-gradient(90deg,#62368B,#C49A6C);border-radius:2px;transition:width 0.4s ease;">
                                        </div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div id="exp-step-dot-2"
                                            style="width:26px;height:26px;border-radius:50%;background:#E8DDCE;display:flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:700;color:#9CA3AF;">
                                            2</div>
                                        <span id="exp-lbl-2"
                                            style="font-size:0.85rem;font-weight:700;color:#9CA3AF;">ยืนยัน</span>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 1 -->
                            <div id="export-step-1" style="padding:24px 30px;">

                                <!-- Column group card -->
                                <div
                                    style="background:#FDFCFB;border:1px solid rgba(98,54,139,0.18);border-radius:16px;padding:14px 16px;margin-bottom:22px;">
                                    <p
                                        style="font-size:0.7rem;font-weight:700;color:#9CA3AF;margin:0 0 12px;text-transform:uppercase;letter-spacing:0.07em;">
                                        เลือกคอลัมน์</p>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;">
                                        <?php
                                        $exportCols = [
                                            'col_no' => ['label' => '# ลำดับ', 'icon' => '🔢', 'always' => true],
                                            'col_firstname' => ['label' => 'ชื่อ', 'icon' => '👤', 'always' => false],
                                            'col_lastname' => ['label' => 'นามสกุล', 'icon' => '👤', 'always' => false],
                                            'col_username' => ['label' => 'Username', 'icon' => '🔑', 'always' => false],
                                            'col_email' => ['label' => 'Email', 'icon' => '📧', 'always' => false],
                                            'col_affiliation' => ['label' => 'หน่วยงาน/คณะ', 'icon' => '🏢', 'always' => false],
                                            'col_role' => ['label' => 'สิทธิ์การใช้งาน', 'icon' => '🛡️', 'always' => false],
                                        ];
                                        foreach ($exportCols as $key => $col): ?>
                                            <label class="exp-col-lbl"
                                                style="display:flex;align-items:center;gap:8px;padding:9px 13px;background:#fff;border:1px solid #F0EBE2;border-radius:10px;cursor:<?= $col['always'] ? 'default' : 'pointer' ?>;transition:all 0.18s;box-shadow:0 1px 3px rgba(0,0,0,0.04);font-family:'Kanit',sans-serif;">
                                                <input type="checkbox" class="f-custom-chk" id="<?= $key ?>"
                                                    <?= $col['always'] ? 'checked disabled' : 'checked' ?>
                                                    onchange="updateExportPreview()">
                                                <span style="font-size:0.88rem;"><?= $col['icon'] ?></span>
                                                <span
                                                    style="font-size:0.9rem;font-weight:600;color:#4B5563;"><?= $col['label'] ?></span>
                                                <?php if ($col['always']): ?><span
                                                        style="font-size:0.68rem;color:#C49A6C;margin-left:auto;font-weight:700;">บังคับ</span><?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- User filter group card -->
                                <div
                                    style="background:#FDFCFB;border:1px solid rgba(98,54,139,0.18);border-radius:16px;padding:14px 16px;margin-bottom:12px;">
                                    <p
                                        style="font-size:0.7rem;font-weight:700;color:#9CA3AF;margin:0 0 12px;text-transform:uppercase;letter-spacing:0.07em;">
                                        ผู้ใช้งานที่ต้องการ</p>
                                    <div style="display:flex;gap:8px;">
                                        <label id="lbl-all"
                                            style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:9px 18px;border:none;background:#fff;border-radius:10px;font-weight:700;color:#62368B;font-size:0.9rem;transition:all 0.18s;box-shadow:0 2px 6px rgba(98,54,139,0.12);font-family:'Kanit',sans-serif;">
                                            <input type="radio" name="user_filter" id="filter_all" value="all" checked
                                                onchange="toggleUserFilter()" style="accent-color:#62368B;"> ทั้งหมด
                                        </label>
                                        <label id="lbl-sel"
                                            style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:9px 18px;border:none;background:transparent;border-radius:10px;font-weight:600;color:#9CA3AF;font-size:0.9rem;transition:all 0.18s;font-family:'Kanit',sans-serif;">
                                            <input type="radio" name="user_filter" id="filter_select" value="select"
                                                onchange="toggleUserFilter()" style="accent-color:#62368B;"> เลือกเฉพาะ
                                        </label>
                                    </div>
                                </div>

                                <div id="user-checklist-wrap"
                                    style="display:none;max-height:175px;overflow-y:auto;border:1px solid #E8DDCE;border-radius:12px;padding:6px;background:#FDFCFB;">
                                    <div
                                        style="padding:5px 8px 6px;display:flex;justify-content:space-between;align-items:center;">
                                        <span
                                            style="font-size:0.75rem;color:#9CA3AF;font-weight:600;">รายชื่อผู้ใช้งาน</span>
                                        <div>
                                            <button type="button" onclick="selectAllUsers(true)"
                                                style="font-size:0.75rem;color:#62368B;background:none;border:none;cursor:pointer;font-weight:700;">เลือกทั้งหมด</button>
                                            <button type="button" onclick="selectAllUsers(false)"
                                                style="font-size:0.75rem;color:#EF4444;background:none;border:none;cursor:pointer;font-weight:700;margin-left:8px;">ยกเลิก</button>
                                        </div>
                                    </div>
                                    <?php foreach ($users_list as $u): ?>
                                        <label
                                            style="display:flex;align-items:center;gap:9px;padding:7px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;"
                                            onmouseenter="this.style.background='rgba(196,154,108,0.08)'"
                                            onmouseleave="this.style.background='transparent'">
                                            <input type="checkbox" class="user-cb" value="<?= $u['id'] ?>" checked
                                                onchange="updateExportPreview()"
                                                style="width:14px;height:14px;accent-color:#62368B;">
                                            <span
                                                style="font-size:0.75rem;color:#C49A6C;font-weight:700;min-width:28px;">#<?= $u['id'] ?></span>
                                            <span
                                                style="font-size:0.9rem;font-weight:600;color:#374151;"><?= htmlspecialchars($u['firstname'] . ' ' . $u['lastname']) ?></span>
                                            <span
                                                style="font-size:0.75rem;color:#9CA3AF;">(<?= htmlspecialchars($u['username']) ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Preview bar -->
                                <div id="export-preview-bar"
                                    style="margin-top:16px;padding:11px 16px;background:rgba(98,54,139,0.05);border:1px solid #E8DDCE;border-radius:12px;display:flex;align-items:center;gap:10px;">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#62368B"
                                        stroke-width="2.5">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M9 12l2 2 4-4" />
                                    </svg>
                                    <span id="exp-preview-txt"
                                        style="font-size:0.88rem;font-weight:700;color:#62368B;">พร้อมส่งออก
                                        <?= count($users_list) ?> รายการ, <?= count($exportCols) ?> คอลัมน์</span>
                                </div>
                            </div>

                            <!-- STEP 2: Confirm -->
                            <div id="export-step-2" style="display:none;padding:32px 30px 24px;text-align:center;">
                                <div
                                    style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,rgba(98,54,139,0.1),rgba(196,154,108,0.15));display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                                    <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="#62368B"
                                        stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                        <polyline points="7 10 12 15 17 10" />
                                        <line x1="12" y1="15" x2="12" y2="3" />
                                    </svg>
                                </div>
                                <h3
                                    style="font-size:1.3rem;font-weight:800;color:#1F2937;margin:0 0 8px;letter-spacing:-0.02em;">
                                    ยืนยันการส่งออก?</h3>
                                <p id="confirm-summary"
                                    style="color:#6B7280;font-size:0.95rem;line-height:1.6;margin-bottom:20px;"></p>
                                <div
                                    style="background:#FDFCFB;border:1px solid #E8DDCE;border-radius:14px;padding:16px;text-align:left;">
                                    <p
                                        style="font-size:0.75rem;font-weight:700;color:#9CA3AF;margin:0 0 10px;text-transform:uppercase;letter-spacing:0.05em;">
                                        คอลัมน์ที่เลือก</p>
                                    <div id="confirm-cols" style="display:flex;flex-wrap:wrap;gap:7px;"></div>
                                </div>
                            </div>

                            <!-- Footer Buttons -->
                            <div
                                style="padding:0 30px 24px;display:flex;justify-content:flex-end;align-items:center;gap:10px;border-top:1px solid #F3F0EC;padding-top:18px;margin-top:4px;">
                                <button id="exp-back-btn" type="button" onclick="exportGoBack()" class="btn-secondary"
                                    style="display:none;padding:11px 22px;font-size:0.92rem;">← ย้อนกลับ</button>
                                <button type="button" onclick="closeExportModal()" class="btn-secondary"
                                    style="padding:11px 22px;font-size:0.92rem;">ยกเลิก</button>
                                <button id="exp-next-btn" type="button" onclick="exportGoNext()" class="btn-primary"
                                    style="background:#62368B;box-shadow:0 8px 15px rgba(98,54,139,0.3);padding:11px 28px;font-size:0.92rem;">ถัดไป
                                    →</button>
                                <button id="exp-confirm-btn" type="button" onclick="doExport()" class="btn-primary"
                                    style="display:none;background:#C49A6C;box-shadow:0 8px 15px rgba(196,154,108,0.3);padding:11px 28px;font-size:0.92rem;">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                                        stroke-width="2.5" style="vertical-align:middle;margin-right:6px;">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                        <polyline points="7 10 12 15 17 10" />
                                        <line x1="12" y1="15" x2="12" y2="3" />
                                    </svg>
                                    ดาวน์โหลด Excel
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- ======= END EXPORT MODAL ======= -->

                    <style>
                        .f-custom-chk {
                            appearance: none;
                            -webkit-appearance: none;
                            width: 22px;
                            height: 22px;
                            background-color: #FDFCFB;
                            border: 1.5px solid #D8B4E2;
                            border-radius: 6px;
                            outline: none;
                            cursor: pointer;
                            position: relative;
                            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                            margin: 0;
                            flex-shrink: 0;
                        }

                        .f-custom-chk:hover {
                            border-color: #62368B;
                            background-color: #F9F5FF;
                        }

                        .f-custom-chk:checked {
                            background-color: #D6C2E2;
                            border-color: #D6C2E2;
                        }

                        .f-custom-chk:disabled {
                            opacity: 0.6;
                            cursor: not-allowed;
                        }

                        .f-custom-chk:checked::after {
                            content: '';
                            position: absolute;
                            left: 7px;
                            top: 3px;
                            width: 5px;
                            height: 10px;
                            border: solid #62368B;
                            border-width: 0 2.5px 2.5px 0;
                            transform: rotate(45deg);
                            border-radius: 1px;
                        }

                        .exp-col-lbl:hover {
                            border-color: rgba(196, 154, 108, 0.5) !important;
                            background: rgba(196, 154, 108, 0.07) !important;
                            box-shadow: 0 2px 8px rgba(196, 154, 108, 0.1);
                        }

                        #user-checklist-wrap::-webkit-scrollbar {
                            width: 5px;
                        }

                        #user-checklist-wrap::-webkit-scrollbar-track {
                            background: #F3F0EC;
                            border-radius: 4px;
                        }

                        #user-checklist-wrap::-webkit-scrollbar-thumb {
                            background: #C49A6C;
                            border-radius: 4px;
                        }
                    </style>
                    <script>
                        (function () {
                            var TOTAL = <?= count($users_list) ?>;
                            var CLABELS = {
                                col_no: '# ลำดับ', col_firstname: 'ชื่อ', col_lastname: 'นามสกุล',
                                col_username: 'Username', col_email: 'Email',
                                col_affiliation: 'หน่วยงาน/คณะ', col_role: 'สิทธิ์การใช้งาน'
                            };
                            window.openExportModal = function () {
                                document.getElementById('export-modal').style.display = 'flex';
                                showStep(1); updateExportPreview();
                            };
                            window.closeExportModal = function () {
                                document.getElementById('export-modal').style.display = 'none';
                            };
                            window.toggleUserFilter = function () {
                                var sel = document.getElementById('filter_select').checked;
                                document.getElementById('user-checklist-wrap').style.display = sel ? 'block' : 'none';
                                // lbl-all: active when NOT selecting specific
                                document.getElementById('lbl-all').style.background = sel ? 'transparent' : '#fff';
                                document.getElementById('lbl-all').style.boxShadow = sel ? 'none' : '0 2px 6px rgba(98,54,139,0.12)';
                                document.getElementById('lbl-all').style.color = sel ? '#9CA3AF' : '#62368B';
                                document.getElementById('lbl-all').style.fontWeight = sel ? '600' : '700';
                                // lbl-sel: active when selecting specific
                                document.getElementById('lbl-sel').style.background = sel ? '#fff' : 'transparent';
                                document.getElementById('lbl-sel').style.boxShadow = sel ? '0 2px 6px rgba(98,54,139,0.12)' : 'none';
                                document.getElementById('lbl-sel').style.color = sel ? '#62368B' : '#9CA3AF';
                                document.getElementById('lbl-sel').style.fontWeight = sel ? '700' : '600';
                                updateExportPreview();
                            };
                            window.selectAllUsers = function (v) {
                                document.querySelectorAll('.user-cb').forEach(function (c) { c.checked = v; });
                                updateExportPreview();
                            };
                            window.updateExportPreview = function () {
                                var cols = Object.keys(CLABELS).filter(function (k) { var e = document.getElementById(k); return e && e.checked; });
                                var uc = document.getElementById('filter_all').checked ? TOTAL : document.querySelectorAll('.user-cb:checked').length;
                                var txt = document.getElementById('exp-preview-txt');
                                var bar = document.getElementById('export-preview-bar');
                                var ok = cols.length > 0 && uc > 0;
                                if (txt) txt.textContent = 'พร้อมส่งออก ' + uc + ' รายการ, ' + cols.length + ' คอลัมน์';
                                if (bar) { bar.style.background = ok ? 'rgba(98,54,139,0.05)' : 'rgba(239,68,68,0.05)'; bar.style.borderColor = ok ? '#E8DDCE' : '#FCA5A5'; }
                                if (txt) txt.style.color = ok ? '#62368B' : '#DC2626';
                            };
                            window.exportGoNext = function () {
                                var cols = Object.keys(CLABELS).filter(function (k) { var e = document.getElementById(k); return e && e.checked; });
                                var uc = document.getElementById('filter_all').checked ? TOTAL : document.querySelectorAll('.user-cb:checked').length;
                                if (!cols.length) { alert('กรุณาเลือกอย่างน้อย 1 คอลัมน์'); return; }
                                if (!uc) { alert('กรุณาเลือกอย่างน้อย 1 ผู้ใช้งาน'); return; }
                                document.getElementById('confirm-summary').innerHTML = 'จะส่งออก <strong style="color:#62368B;">' + uc + ' รายการ</strong>' + (document.getElementById('filter_all').checked ? ' (ทั้งหมด)' : ' (เลือกเฉพาะ)');
                                document.getElementById('confirm-cols').innerHTML = cols.map(function (k) {
                                    return '<span style="padding:5px 13px;background:rgba(196,154,108,0.12);color:#92400E;border-radius:999px;font-size:0.8rem;font-weight:700;border:1px solid #E8DDCE;">' + CLABELS[k] + '</span>';
                                }).join('');
                                showStep(2);
                            };
                            window.exportGoBack = function () { showStep(1); };
                            window.doExport = function () {
                                var cols = Object.keys(CLABELS).filter(function (k) { var e = document.getElementById(k); return e && e.checked; });
                                var isAll = document.getElementById('filter_all').checked;
                                var uids = isAll ? ['all'] : Array.from(document.querySelectorAll('.user-cb:checked')).map(function (c) { return c.value; });
                                var form = document.createElement('form');
                                form.method = 'POST'; form.action = 'export_users.php'; form.style.display = 'none';
                                cols.forEach(function (c) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'cols[]'; i.value = c; form.appendChild(i); });
                                uids.forEach(function (u) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'user_ids[]'; i.value = u; form.appendChild(i); });
                                document.body.appendChild(form); form.submit();
                                setTimeout(closeExportModal, 600);
                            };
                            function showStep(s) {
                                document.getElementById('export-step-1').style.display = s === 1 ? 'block' : 'none';
                                document.getElementById('export-step-2').style.display = s === 2 ? 'block' : 'none';
                                document.getElementById('exp-next-btn').style.display = s === 1 ? '' : 'none';
                                document.getElementById('exp-confirm-btn').style.display = s === 2 ? '' : 'none';
                                document.getElementById('exp-back-btn').style.display = s === 2 ? '' : 'none';
                                var fill = document.getElementById('exp-step-fill');
                                var d2 = document.getElementById('exp-step-dot-2');
                                var l2 = document.getElementById('exp-lbl-2');
                                fill.style.width = s === 2 ? '100%' : '0%';
                                d2.style.background = s === 2 ? '#62368B' : '#E8DDCE';
                                d2.style.color = s === 2 ? '#fff' : '#9CA3AF';
                                l2.style.color = s === 2 ? '#62368B' : '#9CA3AF';
                            }
                        })();
                    </script>
                    <!-- Table Info Bar -->
                </div>
            </div> <!-- End User Management -->

            <!-- Edit User Modal -->
            <div id="edit-user-modal" class="modal-overlay"
                style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; justify-content: center; align-items: center; animation: fadeIn 0.3s ease;">
                <div class="modal-content"
                    style="background: white; width: 100%; max-width: 650px; border-radius: 20px; padding: 30px; position: relative; animation: modalPop 0.4s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h3 style="font-size: 1.3rem; color: #4B5563; font-weight: 700; margin: 0;">
                            แก้ไขบัญชีผู้ใช้</h3>
                        <button type="button" onclick="document.getElementById('edit-user-modal').style.display='none'"
                            style="background:none; border:none; color:#9CA3AF; cursor:pointer; font-size:1.8rem; line-height: 1; transition: color 0.2s;">&times;</button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="settings-form-row">
                            <div class="form-group-light">
                                <label class="form-label-light">ชื่อ *</label>
                                <input type="text" name="firstname" id="edit_firstname" class="form-control-light"
                                    required>
                            </div>
                            <div class="form-group-light">
                                <label class="form-label-light">นามสกุล *</label>
                                <input type="text" name="lastname" id="edit_lastname" class="form-control-light"
                                    required>
                            </div>
                        </div>
                        <div class="settings-form-row">
                            <div class="form-group-light">
                                <label class="form-label-light">Username *</label>
                                <input type="text" name="username" id="edit_username" class="form-control-light"
                                    required>
                            </div>
                            <div class="form-group-light">
                                <label class="form-label-light">Password *</label>
                                <input type="text" name="password" id="edit_password" class="form-control-light"
                                    required>
                            </div>
                        </div>
                        <div class="settings-form-row">
                            <div class="form-group-light">
                                <label class="form-label-light">Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control-light">
                            </div>
                            <div class="form-group-light">
                                <label class="form-label-light">สิทธิ์การใช้งาน *</label>
                                <select name="role" id="edit_role" class="form-control-light" required>
                                    <option value="user">เจ้าหน้าที่บันทึกข้อมูล</option>
                                    <option value="user_n">บุคลากร/คณบดี</option>
                                    <option value="admin">ผู้ดูแลระบบ</option>
                                </select>
                            </div>
                        </div>
                        <div class="settings-form-row">
                            <div class="form-group-light" style="grid-column: 1 / -1;">
                                <label class="form-label-light">หน่วยงาน/คณะ</label>
                                <select name="affiliation" id="edit_affiliation" class="form-control-light">
                                    <option value="">-- เลือกหน่วยงาน --</option>
                                    <?php foreach ($affiliations as $aff): ?>
                                        <option value="<?= $aff['id'] ?>">
                                            <?= htmlspecialchars($aff['affiliation_item']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div
                            style="display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #E5E7EB; gap: 12px;">
                            <button type="button" class="btn-secondary"
                                onclick="document.getElementById('edit-user-modal').style.display='none'"
                                style="padding: 12px 24px; font-size: 0.95rem;">ยกเลิก</button>
                            <button type="submit" class="btn-primary"
                                style="background:#4B8BF5; box-shadow:0 8px 15px rgba(75,139,245,0.3); padding: 12px 32px; font-size: 0.95rem;">บันทึกการแก้ไข</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Confirm Delete Modal -->
            <div id="delete-user-modal" class="modal-overlay"
                style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 99999; justify-content: center; align-items: center; animation: fadeIn 0.3s ease;">
                <div class="modal-content"
                    style="background: white; width: 100%; max-width: 450px; border-radius: 20px; padding: 30px; position: relative; animation: modalPop 0.4s cubic-bezier(0.16, 1, 0.3, 1); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); text-align: center;">
                    <div
                        style="width: 65px; height: 65px; border-radius: 50%; background: #FEE2E2; color: #EF4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                            </path>
                        </svg>
                    </div>
                    <h3 style="font-size: 1.4rem; color: #1F2937; font-weight: 700; margin-bottom: 10px;">
                        ยืนยันการลบสมาชิกระบบ?</h3>
                    <p id="delete-user-name"
                        style="color: #EF4444; font-size: 1rem; font-weight: 700; margin-bottom: 6px;"></p>
                    <p style="color: #6B7280; font-size: 0.95rem; margin-bottom: 25px; line-height: 1.5;">
                        คุณแน่ใจหรือไม่ว่าต้องการลบบัญชีผู้ใช้งานนี้?
                        การกระทำนี้ไม่สามารถย้อนกลับหรือเรียกคืนข้อมูลได้</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" id="delete_user_id">
                        <div style="display: flex; justify-content: center; gap: 12px;">
                            <button type="button" class="btn-secondary"
                                onclick="document.getElementById('delete-user-modal').style.display='none'"
                                style="padding: 12px 24px; font-size: 1rem; flex: 1;">ยกเลิก</button>
                            <button type="submit"
                                style="background: #EF4444; color: white; border: none; padding: 12px 24px; font-size: 1rem; border-radius: 10px; cursor: pointer; flex: 1; font-weight: 500; transition: all 0.2s; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);">ยืนยันลบ</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Helper Scripts -->
            <script>
                // Close modal when clicking outside (overlay)
                document.addEventListener('click', function (e) {
                    if (e.target.classList.contains('modal-overlay')) {
                        e.target.style.display = 'none';
                    }
                });

                // Close modals on Escape key
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        document.querySelectorAll('.modal-overlay').forEach(function (m) {
                            m.style.display = 'none';
                        });
                    }
                });

                window.openEditModal = function (btn) {
                    try {
                        document.getElementById('edit_user_id').value = btn.getAttribute('data-target-id') || '';
                        document.getElementById('edit_firstname').value = btn.getAttribute('data-firstname') || '';
                        document.getElementById('edit_lastname').value = btn.getAttribute('data-lastname') || '';
                        document.getElementById('edit_username').value = btn.getAttribute('data-username') || '';
                        document.getElementById('edit_password').value = btn.getAttribute('data-password') || '';
                        document.getElementById('edit_email').value = btn.getAttribute('data-email') || '';
                        document.getElementById('edit_role').value = btn.getAttribute('data-role') || 'user';
                        document.getElementById('edit_affiliation').value = btn.getAttribute('data-affiliation') || '';

                        // Fire change events for custom selects
                        document.getElementById('edit_role').dispatchEvent(new Event('change'));
                        document.getElementById('edit_affiliation').dispatchEvent(new Event('change'));

                        document.getElementById('edit-user-modal').style.display = 'flex';
                    } catch (error) {
                        console.error('openEditModal Error:', error);
                        alert('เกิดข้อผิดพลาดในการเปิดหน้าต่างแก้ไข');
                    }
                };

                window.openDeleteModal = function (userId, userName) {
                    try {
                        document.getElementById('delete_user_id').value = userId;
                        var nameEl = document.getElementById('delete-user-name');
                        if (nameEl) nameEl.textContent = userName ? '"' + userName + '"' : '';
                        document.getElementById('delete-user-modal').style.display = 'flex';
                    } catch (error) {
                        console.error('openDeleteModal Error:', error);
                        alert('เกิดข้อผิดพลาดในการเปิดหน้าต่างยืนยันลบ');
                    }
                };

                // ===== Real-time Search & Entries Filter =====
                (function initUserTableSearch() {
                    var _searchTerm = '';
                    var _entriesLimit = 10;
                    var _prevBtn, _nextBtn, _pageLabel;
                    var _currentPage = 1;


                    window.filterUserTable = function () {
                        var input = document.getElementById('user-search-input');
                        var select = document.getElementById('user-entries-select_input');
                        var tbody = document.getElementById('user-table-body');
                        if (!input || !tbody) return;

                        _searchTerm = input.value.trim().toLowerCase();
                        _entriesLimit = select ? parseInt(select.value) : 10;
                        _currentPage = 1;

                        renderTable();
                    };
                    document.getElementById('user-entries-select')
                        ?.addEventListener('dd:change', filterUserTable);

                    function renderTable() {
                        var tbody = document.getElementById('user-table-body');
                        if (!tbody) return;

                        var rows = Array.from(tbody.querySelectorAll('tr[data-row]'));
                        var matched = [];

                        rows.forEach(function (row) {
                            var text = (row.getAttribute('data-searchtext') || '').toLowerCase();
                            var show = !_searchTerm || text.indexOf(_searchTerm) !== -1;
                            row.style.display = show ? '' : 'none';
                            if (show) matched.push(row);
                        });

                        // Apply entries limit
                        var limit = (_entriesLimit === -1) ? matched.length : _entriesLimit;
                        var totalPages = Math.max(1, Math.ceil(matched.length / limit));
                        if (_currentPage > totalPages) _currentPage = totalPages;

                        matched.forEach(function (row, idx) {
                            var page = Math.floor(idx / limit) + 1;
                            row.style.display = (page === _currentPage) ? '' : 'none';
                            // Restore plain text
                            var cells = row.querySelectorAll('td[data-orig]');
                            cells.forEach(function (cell) {
                                cell.textContent = cell.getAttribute('data-orig');
                            });
                        });

                        // Update pagination UI
                        updatePagination(matched.length, limit, totalPages);

                        // Show/hide no-results message
                        var noResults = document.getElementById('user-no-results');
                        if (noResults) noResults.style.display = (matched.length === 0) ? 'block' : 'none';
                    }

                    function updatePagination(total, limit, totalPages) {
                        var info = document.getElementById('user-table-info');
                        if (info) {
                            var start = total === 0 ? 0 : ((_currentPage - 1) * (limit === -1 ? total : limit)) + 1;
                            var end = Math.min(_currentPage * (limit === -1 ? total : limit), total);
                            info.textContent = 'แสดง ' + start + ' - ' + end + ' จาก ' + total + ' รายการ';
                        }
                        var pageLabel = document.getElementById('user-page-label');
                        if (pageLabel) pageLabel.textContent = _currentPage + ' / ' + totalPages;

                        var prev = document.getElementById('user-prev-btn');
                        var next = document.getElementById('user-next-btn');
                        if (prev) prev.disabled = (_currentPage <= 1);
                        if (next) next.disabled = (_currentPage >= totalPages);
                    }

                    window.userTablePrev = function () {
                        if (_currentPage > 1) { _currentPage--; renderTable(); }
                    };
                    window.userTableNext = function () {
                        var tbody = document.getElementById('user-table-body');
                        if (!tbody) return;
                        var rows = Array.from(tbody.querySelectorAll('tr[data-row]'));
                        var matched = rows.filter(function (r) { return r.getAttribute('data-searchtext').toLowerCase().indexOf(_searchTerm) !== -1; });
                        var limit = (_entriesLimit === -1) ? matched.length : _entriesLimit;
                        var totalPages = Math.max(1, Math.ceil(matched.length / limit));
                        if (_currentPage < totalPages) { _currentPage++; renderTable(); }
                    };

                    // Stamp data-searchtext and data-orig on each row after DOM ready
                    document.addEventListener('DOMContentLoaded', stampRows);
                    // Also run now in case DOMContentLoaded already fired (SPA)
                    stampRows();

                    function stampRows() {
                        var tbody = document.getElementById('user-table-body');
                        if (!tbody) return;
                        var rows = tbody.querySelectorAll('tr');
                        rows.forEach(function (row) {
                            row.setAttribute('data-row', '1');
                            // Build search text from entire row text content
                            row.setAttribute('data-searchtext', row.textContent.replace(/\s+/g, ' ').trim());
                            // Stamp searchable text cells for highlight
                            var cells = row.querySelectorAll('td:not(:first-child):not(:last-child)');
                            cells.forEach(function (cell) {
                                if (!cell.getAttribute('data-orig')) {
                                    cell.setAttribute('data-orig', cell.textContent.trim());
                                }
                            });
                        });
                        renderTable();
                    }
                })();

                // ==========================================
                // COLUMN VISIBILITY LOGIC (Pure JS)
                // ==========================================
                document.addEventListener('DOMContentLoaded', initColVis);
                initColVis(); // For SPA

                function initColVis() {
                    const table = document.querySelector('.figma-table');
                    const listContainer = document.getElementById('col-vis-list');
                    if (!table || !listContainer) return;
                    if (listContainer.dataset.init === '1') return;
                    listContainer.dataset.init = '1';

                    const ths = table.querySelectorAll('thead th');
                    ths.forEach((th, index) => {
                        // Skip # and Action columns
                        if (index === 0 || index === ths.length - 1) return;

                        let label = document.createElement('label');
                        label.style.display = 'flex';
                        label.style.alignItems = 'center';
                        label.style.gap = '10px';
                        label.style.padding = '8px 12px';
                        label.style.cursor = 'pointer';
                        label.style.fontSize = '0.9rem';
                        label.style.color = '#4B5563';
                        label.style.fontWeight = '500';
                        label.style.borderRadius = '8px';
                        label.style.transition = 'background 0.2s';

                        label.onmouseenter = () => label.style.background = '#F9FAFB';
                        label.onmouseleave = () => label.style.background = 'transparent';

                        let cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.checked = true;
                        cb.className = 'f-custom-chk';
                        cb.onchange = () => toggleTableCol(index, cb.checked);

                        let textName = th.textContent.replace('↑↓', '').trim();
                        let textNode = document.createTextNode(textName);

                        label.appendChild(cb);
                        label.appendChild(textNode);
                        listContainer.appendChild(label);
                    });
                }

                window.toggleColVisMenu = function (e) {
                    if (e) e.stopPropagation();
                    const menu = document.getElementById('col-vis-menu');
                    const chevron = document.getElementById('col-vis-chevron');
                    const isOpen = menu.style.display === 'block';
                    menu.style.display = isOpen ? 'none' : 'block';
                    if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
                };

                function toggleTableCol(index, isVisible) {
                    const table = document.querySelector('.figma-table');
                    if (!table) return;
                    const display = isVisible ? '' : 'none';

                    // Toggle header
                    const ths = table.querySelectorAll('thead tr th');
                    if (ths[index]) ths[index].style.display = display;

                    // Toggle all body rows
                    const rows = table.querySelectorAll('tbody tr');
                    rows.forEach(row => {
                        const tds = row.querySelectorAll('td');
                        if (tds[index]) tds[index].style.display = display;
                    });
                }

                document.addEventListener('click', function (e) {
                    const menu = document.getElementById('col-vis-menu');
                    const btn = document.getElementById('col-vis-btn');
                    if (menu && menu.style.display === 'block' && !menu.contains(e.target) && btn && !btn.contains(e.target)) {
                        menu.style.display = 'none';
                        const chevron = document.getElementById('col-vis-chevron');
                        if (chevron) chevron.style.transform = '';
                    }
                });

                // ==========================================
                // CUSTOM SELECT COMPONENT
                // Transforms all <select class="form-control-light">
                // into a beautiful floating pill UI to match Figma
                // ==========================================
                document.addEventListener('DOMContentLoaded', initCustomSelects);
                // Run immediately for SPA transitions
                initCustomSelects();

                function initCustomSelects() {
                    const selects = document.querySelectorAll('select.form-control-light');
                    selects.forEach(select => {
                        if (select.dataset.customized) return;
                        select.dataset.customized = "true";
                        select.style.display = 'none'; // Hide native select

                        // Find closest label
                        const formGroup = select.closest('.form-group-light');
                        // Do not hide the native label anymore! Let it stay outside natively.

                        // Build Main Wrapper
                        const wrapper = document.createElement('div');
                        wrapper.className = 'f-custom-select';
                        wrapper.style.position = 'relative';
                        wrapper.style.width = '100%';
                        wrapper.style.border = '1px solid rgba(98,54,139,0.25)';
                        wrapper.style.borderRadius = '12px'; // Match 12px of normal inputs
                        wrapper.style.padding = '6px 10px';  // Reduced from 12px to offset inner pill padding
                        wrapper.style.minHeight = '48px';    // Fix height to match standard inputs
                        wrapper.style.background = '#FDFCFB';
                        wrapper.style.cursor = 'pointer';
                        wrapper.style.fontFamily = "'Kanit', sans-serif";
                        wrapper.style.transition = 'all 0.2s cubic-bezier(0.16, 1, 0.3, 1)';
                        wrapper.style.marginBottom = '6px';
                        wrapper.style.display = 'flex';
                        wrapper.style.alignItems = 'center';

                        // Hover & focus styles
                        wrapper.onmouseenter = () => wrapper.style.borderColor = '#62368B';
                        wrapper.onmouseleave = () => { if (!wrapper.classList.contains('open')) wrapper.style.borderColor = 'rgba(98,54,139,0.25)'; };

                        // Selected Pill Container
                        const pillWrap = document.createElement('div');
                        pillWrap.style.display = 'flex';
                        pillWrap.style.justifyContent = 'space-between';
                        pillWrap.style.alignItems = 'center';
                        pillWrap.style.width = '100%';

                        const pill = document.createElement('div');
                        pill.style.background = '#F8F5F1'; // Cream pill
                        pill.style.color = '#4B5563';
                        pill.style.padding = '6px 14px';
                        pill.style.borderRadius = '10px';
                        pill.style.fontSize = '0.95rem';   // Match input text size
                        pill.style.fontWeight = '600';     // Slightly less bold to match normal text
                        pill.style.display = 'inline-block';
                        pill.style.flex = '1';

                        const arrow = document.createElement('div');
                        arrow.innerHTML = `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#9CA3AF" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>`;
                        arrow.style.transition = 'transform 0.2s';
                        arrow.style.marginLeft = '12px';

                        pillWrap.appendChild(pill);
                        pillWrap.appendChild(arrow);
                        wrapper.appendChild(pillWrap);

                        // Dropdown List
                        const optBox = document.createElement('div');
                        optBox.style.position = 'absolute';
                        optBox.style.top = 'calc(100% + 6px)';
                        optBox.style.left = '0';
                        optBox.style.width = '100%';
                        optBox.style.background = '#FFFFFF';
                        optBox.style.border = '1px solid #E8DDCE';
                        optBox.style.borderRadius = '14px';
                        optBox.style.boxShadow = '0 12px 30px rgba(0,0,0,0.08)';
                        optBox.style.zIndex = '9999';
                        optBox.style.display = 'none';
                        optBox.style.padding = '8px';
                        optBox.style.maxHeight = '230px';
                        optBox.style.overflowY = 'auto';

                        // Sync value func
                        function updateSelection() {
                            let idx = select.selectedIndex;
                            let val = idx >= 0 ? select.options[idx].text : 'ไม่มีตัวเลือก';
                            pill.textContent = val;
                        }
                        updateSelection();

                        // Render options
                        Array.from(select.options).forEach((opt, index) => {
                            if (opt.value === '') return; // "ห้ามมีให้เลือก" - Skip placeholder

                            let item = document.createElement('div');
                            item.dataset.index = index; // Store original index
                            item.textContent = opt.text;
                            item.style.padding = '10px 14px';
                            item.style.borderRadius = '10px';
                            item.style.fontSize = '0.95rem';
                            item.style.fontWeight = '500';
                            item.style.color = '#4B5563';
                            item.style.cursor = 'pointer';
                            item.style.transition = 'all 0.15s';

                            // Style selected option
                            if (select.selectedIndex === index) {
                                item.style.background = 'rgba(98,54,139,0.08)';
                                item.style.color = '#62368B';
                                item.style.fontWeight = '700';
                            }

                            item.onmouseenter = () => { if (select.selectedIndex !== index) { item.style.background = '#F9FAFB'; } };
                            item.onmouseleave = () => { if (select.selectedIndex !== index) { item.style.background = 'transparent'; } };

                            item.onclick = (e) => {
                                e.stopPropagation();
                                select.selectedIndex = index;
                                updateSelection();
                                select.dispatchEvent(new Event('change'));
                                closeDrops();
                            };
                            optBox.appendChild(item);
                        });

                        wrapper.appendChild(optBox);

                        // Listen to external changes (like from Edit Modal population)
                        select.addEventListener('change', () => {
                            updateSelection();
                            // Update selection styles inside optBox
                            Array.from(optBox.children).forEach(child => {
                                let origIndex = parseInt(child.dataset.index);
                                if (select.selectedIndex === origIndex) {
                                    child.style.background = 'rgba(98,54,139,0.08)';
                                    child.style.color = '#62368B';
                                    child.style.fontWeight = '700';
                                } else {
                                    child.style.background = 'transparent';
                                    child.style.color = '#4B5563';
                                    child.style.fontWeight = '500';
                                }
                            });
                        });

                        // Toggle script
                        function toggleDrop(e) {
                            e.stopPropagation();
                            let isOpen = wrapper.classList.contains('open');
                            closeDrops();
                            if (!isOpen) {
                                wrapper.classList.add('open');
                                optBox.style.display = 'block';
                                wrapper.style.borderColor = '#62368B';
                                wrapper.style.boxShadow = '0 0 0 4px rgba(98,54,139,0.08)';
                                arrow.style.transform = 'rotate(180deg)';
                            }
                        }

                        function closeDrops() {
                            document.querySelectorAll('.f-custom-select').forEach(el => {
                                el.classList.remove('open');
                                el.style.borderColor = 'rgba(98,54,139,0.25)';
                                el.style.boxShadow = 'none';
                                el.querySelector('svg').parentElement.style.transform = 'rotate(0deg)';
                                el.querySelectorAll('div').forEach(d => {
                                    if (d.style.position === 'absolute') d.style.display = 'none';
                                });
                            });
                        }

                        wrapper.onclick = toggleDrop;
                        document.addEventListener('click', closeDrops);

                        if (formGroup) {
                            formGroup.appendChild(wrapper);
                        } else {
                            select.parentNode.insertBefore(wrapper, select.nextSibling);
                        }
                    });
                }
            </script>

        </div><!-- /.settings-container -->
        </div><!-- /.page-content -->
    </main>

</body>

</html>