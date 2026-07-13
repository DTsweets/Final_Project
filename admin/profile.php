<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$root = '../';
$pdo = getDB();
$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = 'alert-success';

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

// PHP resize function removed. Using Client-Side JS Canvas conversion to prevent GD library crashes.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect variables
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $old_password = $_POST['old_password'] ?? '';
    $password = $_POST['password'] ?? ''; // Plaintext pass as original schema

    // Handle Image
    $filename = $user['profile_image'];
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_pic'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($ext, $allowed)) {
            $new_filename = 'user_' . $user_id . '_' . time() . '.webp'; // เปลี่ยนเป็น WebP ทันที
            $upload_dir = __DIR__ . '/../assets/images/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                // Delete the old profile image
                if (!empty($user['profile_image']) && $user['profile_image'] !== $new_filename) {
                    $base_dir = realpath(__DIR__ . '/../assets/images/profiles');
                    if ($base_dir) {
                        $old_path = $base_dir . DIRECTORY_SEPARATOR . $user['profile_image'];
                        if (file_exists($old_path)) {
                            @unlink($old_path);
                        }
                    }
                }
                $filename = $new_filename;
                $_SESSION['profile_image'] = $filename; // Update session
            } else {
                $msg = 'เกิดข้อผิดพลาดในการบันทึกรูปภาพ';
                $msg_type = 'alert-danger';
            }
        } else {
            $msg = 'ไฟล์ไม่รองรับ อนุญาตเฉพาะภาพ JPG, PNG, GIF, WEBP';
            $msg_type = 'alert-warning';
        }
    } elseif (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE) {
        $msg = 'เกิดข้อผิดพลาดอัปโหลดรูปภาพ: รหัสข้อผิดพลาด ' . $_FILES['profile_pic']['error'];
        $msg_type = 'alert-danger';
    }

    if (!$msg && !empty($password) && $old_password !== $user['password']) {
        $msg = 'รหัสผ่านเดิมไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
        $msg_type = 'alert-danger';
    }

    if (!$msg) {
        try {
            if (!empty($password)) {
                $sql = "UPDATE users SET firstname=:fn, lastname=:ln, username=:un, email=:em, password=:pw, profile_image=:img WHERE id=:id";
                $params = [
                    ':fn' => $firstname,
                    ':ln' => $lastname,
                    ':un' => $username,
                    ':em' => $email,
                    ':pw' => $password,
                    ':img' => $filename,
                    ':id' => $user_id
                ];
            } else {
                $sql = "UPDATE users SET firstname=:fn, lastname=:ln, username=:un, email=:em, profile_image=:img WHERE id=:id";
                $params = [
                    ':fn' => $firstname,
                    ':ln' => $lastname,
                    ':un' => $username,
                    ':em' => $email,
                    ':img' => $filename,
                    ':id' => $user_id
                ];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Sync Session
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
            $_SESSION['username'] = $username;

            $msg = 'การเปลี่ยนแปลงโปรไฟล์สำเร็จเรียบร้อย!';
            $msg_type = 'alert-success';

            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute([':id' => $user_id]);
            $user = $stmt->fetch();

        } catch (PDOException $e) {
            $msg = 'ข้อผิดพลาด: ' . ($e->getCode() == 23000 ? 'Username หรือ Email ซ้ำในระบบ' : $e->getMessage());
            $msg_type = 'alert-danger';
        }
    }
}
$page_title = "แก้ไขโปรไฟล์ส่วนตัว";
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดทำโปรไฟล์ - UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
</head>

<body style="background-color: #F8F9FA;">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <link rel="stylesheet" href="<?= $root ?>assets/css/profile.css<?= asset_v('assets/css/profile.css') ?>">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="page-content" style="background-color: transparent;">

            <?php if ($msg): ?>
                <div class="alert-message <?= $msg_type ?>">
                    <?php if ($msg_type === 'alert-success'): ?>
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                    <?php endif; ?>
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="profile-wrapper">

                <!-- เลือกรูปโปรไฟล์ -->
                <div class="profile-card avatar-container">
                    <h3 style="color: #4B5563; margin-bottom: 32px; font-weight: 600;">รูปโปรไฟล์</h3>

                    <?php if (!empty($user['profile_image'])): ?>
                        <?php
                        $current_img = $user['profile_image'];
                        $webp_profile = pathinfo($current_img, PATHINFO_FILENAME) . '.webp';
                        ?>
                        <img src="<?= $root ?>assets/images/profiles/<?= htmlspecialchars($webp_profile) ?>"
                            class="avatar-preview" id="previewImg" alt="Profile" loading="lazy">
                    <?php else: ?>
                        <!-- SVG Placeholder -->
                        <div class="avatar-preview"
                            style="display: flex; align-items: center; justify-content: center; background: #FFF;"
                            id="previewSvg">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5"
                                style="width: 60px; height: 60px;">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <img src="" class="avatar-preview" id="previewImg" alt="Profile" style="display:none;">
                    <?php endif; ?>

                    <div style="width: 100%;">
                        <div class="file-input-wrapper">
                            <button type="button" class="btn-upload">เลือกรูปภาพ</button>
                            <input type="file" name="profile_pic" id="profileInput"
                                accept="image/png, image/jpeg, image/gif, image/webp">
                        </div>
                    </div>

                    <div class="file-name" id="fileName">ยังไม่ได้เลือกไฟล์</div>
                </div>

                <!-- ข้อมูลโปรไฟล์ -->
                <div class="profile-card">
                    <div class="profile-card-header">
                        <h3>ข้อมูลผู้ใช้งาน</h3>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">ชื่อจริง *</label>
                            <input type="text" name="firstname" class="form-control"
                                value="<?= htmlspecialchars($user['firstname'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">นามสกุล *</label>
                            <input type="text" name="lastname" class="form-control"
                                value="<?= htmlspecialchars($user['lastname'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ชื่อผู้ใช้งาน (Username) *</label>
                            <input type="text" name="username" class="form-control"
                                value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">อีเมล</label>
                            <input type="email" name="email" class="form-control"
                                value="<?= htmlspecialchars($user['email'] ?? '') ?>" placeholder="example@up.ac.th">
                        </div>

                        <!-- รหัสผ่าน -->
                        <div class="form-group full-width"
                            style="margin-top: 10px; border-top: 1px solid #E5E7EB; padding-top: 20px;">
                            <label class="form-label" style="color: #62368B; font-size: 1.05rem;">เปลี่ยนรหัสผ่าน
                                (เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</label>
                        </div>
                        <div class="form-group">
                            <label class="form-label">รหัสผ่านเดิม</label>
                            <input type="password" name="old_password" class="form-control"
                                placeholder="รหัสผ่านปัจจุบัน">
                            <div class="help-text">ต้องใส่รหัสผ่านเดิมก่อนถึงจะกำหนดรหัสใหม่ได้</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">รหัสผ่านใหม่</label>
                            <input type="password" name="password" class="form-control"
                                placeholder="รหัสผ่านใหม่ที่จะใช้">
                        </div>

                        <div class="form-group full-width" style="margin-top: 24px;">
                            <button type="submit" class="btn-save">
                                บันทึกการเปลี่ยนแปลง
                            </button>
                        </div>
                    </div>
                </div>

            </form>
        </div>
        <script>
        (function() {
            // Picture Preview Logic
            const profileInput = document.getElementById('profileInput');
            const fileName = document.getElementById('fileName');
            const previewImg = document.getElementById('previewImg');
            const previewSvg = document.getElementById('previewSvg');

            if (!profileInput) return;

            profileInput.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (file) {
                    fileName.textContent = "กำลังประมวลผลเป็น WebP...";
                    
                    const reader = new FileReader();
                    reader.onload = function (event) {
                        const img = new Image();
                        img.onload = function() {
                            const canvas = document.createElement('canvas');
                            let width = img.width;
                            let height = img.height;
                            
                            // Resize max 400x400
                            const MAX_SIZE = 400;
                            if (width > height && width > MAX_SIZE) {
                                height *= MAX_SIZE / width;
                                width = MAX_SIZE;
                            } else if (height > MAX_SIZE) {
                                width *= MAX_SIZE / height;
                                height = MAX_SIZE;
                            }
                            
                            canvas.width = width;
                            canvas.height = height;
                            const ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0, width, height);
                            
                            // Preview
                            previewImg.src = canvas.toDataURL('image/webp', 0.85);
                            previewImg.style.display = 'inline-block';
                            if (previewSvg) previewSvg.style.display = 'none';

                            // Convert and replace file input
                            canvas.toBlob(function(blob) {
                                const newFileName = file.name.replace(/\.[^/.]+$/, "") + ".webp";
                                const webpFile = new File([blob], newFileName, {
                                    type: 'image/webp',
                                    lastModified: new Date().getTime()
                                });
                                
                                const dataTransfer = new DataTransfer();
                                dataTransfer.items.add(webpFile);
                                profileInput.files = dataTransfer.files;
                                
                                fileName.textContent = newFileName;
                            }, 'image/webp', 0.85);
                        };
                        img.src = event.target.result;
                    }
                    reader.readAsDataURL(file);
                } else {
                    fileName.textContent = 'ยังไม่ได้เลือกไฟล์';
                }
            });
        })();
    </script>
    </main>
</body>

</html>