<?php
/**
 * SHARED — หน้าโปรไฟล์ส่วนตัว (admin / officer / dean)
 * ผู้เรียกกำหนดก่อน include: $pdo, $root, $SIDEBAR, $HEADER (null = โซนที่ไม่มีแถบหัว เช่น dean)
 *
 * หน้าตา: การ์ดหัว (รูป ชื่อ บทบาท หน่วยงาน) → รูปโปรไฟล์ (ลากวาง/ดูตัวอย่าง) + ข้อมูลผู้ใช้ + เปลี่ยนรหัสผ่าน (พับได้)
 *         → แถบบันทึกติดขอบล่าง · สไตล์: officer-entry.css + profile.css · สคริปต์: profile.js (+ data-entry.js สำหรับเตือนก่อนออก)
 */
require_once __DIR__ . '/profile_entry.php';

$uid = (int) $_SESSION['user_id'];
$load_user = function () use ($pdo, $uid) {
    $st = $pdo->prepare('SELECT u.*, a.affiliation_item FROM users u LEFT JOIN affiliation_id a ON a.id = u.Affiliation WHERE u.id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: [];
};
$user = $load_user();
$IMG_DIR = __DIR__ . '/../assets/images/profiles';

$errors = []; $sticky = null; $flash = ''; $flash_t = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$errors, $data] = profile_validate($_POST, $user);
    $file = $_FILES['profile_pic'] ?? null;
    if ($imgErr = profile_check_image($file)) $errors['profile_pic'] = $imgErr;
    if (!$errors) $errors = profile_duplicates($pdo, $uid, $data);
    if (!$errors) {
        try {
            $hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            $img = profile_apply($pdo, $uid, $user, $data, $hasFile ? $file : null, $IMG_DIR, 'move_uploaded_file');
            $_SESSION['firstname'] = $data['firstname'];
            $_SESSION['lastname']  = $data['lastname'];
            $_SESSION['username']  = $data['username'];
            $_SESSION['profile_image'] = $img ?? '';
            // PRG: รีเฟรชหลังบันทึกไม่ส่งฟอร์มซ้ำ
            header('Location: profile.php?saved=1' . ($data['password'] !== null ? '&pw=1' : '') . ($hasFile ? '&img=1' : '')); exit;
        } catch (Exception $e) {
            $errors['_form'] = safe_error_message($e);
        }
    }
    $sticky = $_POST;   // คงค่าที่พิมพ์ไว้ (ยกเว้นรหัสผ่าน) เมื่อมีข้อผิดพลาด
    $flash = $errors['_form'] ?? 'บันทึกไม่สำเร็จ กรุณาแก้ไขช่องที่แจ้งไว้';
    $flash_t = 'danger';
}
if (isset($_GET['saved'])) {
    $flash = 'บันทึกโปรไฟล์เรียบร้อยแล้ว' . (isset($_GET['pw']) ? ' · เปลี่ยนรหัสผ่านแล้ว' : '');
}

$page_title = 'แก้ไขโปรไฟล์ส่วนตัว';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$val = fn(string $k) => $h($sticky !== null ? ($sticky[$k] ?? '') : ($user[$k] ?? ''));
$imgSrc = !empty($user['profile_image']) ? $root . 'assets/images/profiles/' . rawurlencode(pathinfo($user['profile_image'], PATHINFO_FILENAME) . '.webp') : '';
$pwOpen = isset($errors['old_password']) || isset($errors['password']) || isset($errors['password_confirm']);
$fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''));
$avatarSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
$eye = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path class="pf-eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle class="pf-eye-open" cx="12" cy="12" r="3"/><line class="pf-eye-off" x1="3" y1="3" x2="21" y2="21"/></svg>';

/** ช่องกรอก 1 ช่อง: label + input + ข้อความผิด (ข้อความจากเซิร์ฟเวอร์แสดงทันที) */
$field = function (string $name, string $label, string $inputHtml, string $extra = '') use ($errors, $h) {
    $err = $errors[$name] ?? '';
    return '<div class="pf-field' . ($err ? ' is-invalid' : '') . '"' . ($err ? ' data-server="1"' : '') . '>'
        . '<label class="pf-label" for="pf_' . $name . '">' . $label . '</label>' . $inputHtml . $extra
        . '<span class="pf-err" role="alert">' . $h($err) . '</span></div>';
};
$pwInput = fn(string $name, string $ph, string $ac) => '<div class="pf-pw-wrap"><input class="pf-input" type="password" id="pf_' . $name . '" name="' . $name . '" placeholder="' . $ph . '" autocomplete="' . $ac . '" maxlength="100"' . (isset($errors[$name]) ? ' aria-invalid="true"' : '') . '>'
    . '<button type="button" class="pf-eye" aria-label="แสดงรหัสผ่าน" aria-pressed="false">' . $eye . '</button></div>';
$txtInput = fn(string $name, string $type, string $ph, int $max, bool $req, string $ac) => '<input class="pf-input" type="' . $type . '" id="pf_' . $name . '" name="' . $name . '" value="' . $val($name) . '" placeholder="' . $ph . '" maxlength="' . $max . '" autocomplete="' . $ac . '"' . ($req ? ' required' : '') . (isset($errors[$name]) ? ' aria-invalid="true"' : '') . '>';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ส่วนตัว — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/profile.css<?= asset_v('assets/css/profile.css') ?>">
</head>
<body style="background:#F6F4F9;">
    <?php include $SIDEBAR; ?>
    <main class="main-content">
        <?php if ($HEADER) include $HEADER; ?>
        <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

        <div class="oe-page pf-page">
            <form method="POST" enctype="multipart/form-data" id="pfForm" novalidate><?= csrf_field() ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= PROFILE_IMG_MAX ?>">

                <!-- การ์ดหัว -->
                <section class="pf-hero oe-rise">
                    <div class="pf-hero-avatar<?= $imgSrc ? ' has-img' : '' ?><?= isset($_GET['img']) ? ' pf-pop' : '' ?>">
                        <?php if ($imgSrc): ?><img src="<?= $h($imgSrc) ?>" alt=""><?php else: ?><?= $avatarSvg ?><?php endif; ?>
                    </div>
                    <div class="pf-hero-text">
                        <span class="pf-role"><?= $h(profile_role_label((string) ($user['role'] ?? ''))) ?></span>
                        <h1 class="pf-name"><?= $h($fullName ?: ($user['username'] ?? '')) ?></h1>
                        <div class="pf-meta">
                            <?php if (!empty($user['affiliation_item'])): ?><span><?= ic('building', 15) ?> <?= $h($user['affiliation_item']) ?></span><?php endif; ?>
                            <?php if (!empty($user['position'])): ?><span><?= ic('user', 15) ?> <?= $h($user['position']) ?></span><?php endif; ?>
                            <span><?= ic('hash', 15) ?> <?= $h($user['username'] ?? '') ?></span>
                            <?php if (!empty($user['email'])): ?><span><?= ic('mail', 15) ?> <?= $h($user['email']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </section>

                <div class="pf-grid">
                    <!-- รูปโปรไฟล์ -->
                    <section class="oe-panel pf-card oe-rise" style="--i:1;">
                        <h2 class="pf-h2"><?= ic('user', 18) ?> รูปโปรไฟล์</h2>
                        <label class="pf-drop" id="pfDrop" for="pfFile">
                            <span class="pf-preview<?= $imgSrc ? ' has-img' : '' ?>" id="pfPreview" data-src="<?= $h($imgSrc) ?>">
                                <img src="<?= $h($imgSrc) ?>" alt="ตัวอย่างรูปโปรไฟล์"><?= $avatarSvg ?>
                                <span class="pf-preview-cam" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="3"/></svg></span>
                            </span>
                            <span class="pf-drop-title">ลากรูปมาวาง หรือ <u>กดเพื่อเลือก</u></span>
                            <span class="pf-drop-hint">JPG · PNG · GIF · WEBP · ย่อเหลือ 400px อัตโนมัติ</span>
                            <input type="file" name="profile_pic" id="pfFile" accept="image/png, image/jpeg, image/gif, image/webp">
                        </label>
                        <div class="pf-file-row">
                            <span class="pf-file-name" id="pfFileName"></span>
                            <button type="button" class="pf-clear" id="pfClearImg" hidden>ยกเลิกรูปที่เลือก</button>
                        </div>
                        <span class="pf-err pf-avatar-err" role="alert"><?= $h($errors['profile_pic'] ?? '') ?></span>
                    </section>

                    <div class="pf-col">
                        <!-- ข้อมูลผู้ใช้งาน -->
                        <section class="oe-panel pf-card oe-rise" style="--i:2;">
                            <h2 class="pf-h2"><?= ic('edit', 18) ?> ข้อมูลผู้ใช้งาน</h2>
                            <div class="pf-fields">
                                <?= $field('firstname', 'ชื่อจริง *', $txtInput('firstname', 'text', 'ชื่อจริง', 100, true, 'given-name')) ?>
                                <?= $field('lastname', 'นามสกุล *', $txtInput('lastname', 'text', 'นามสกุล', 100, true, 'family-name')) ?>
                                <?= $field('username', 'ชื่อผู้ใช้งาน (Username) *', $txtInput('username', 'text', 'ใช้สำหรับเข้าสู่ระบบ', 50, true, 'username')) ?>
                                <?= $field('email', 'อีเมล', $txtInput('email', 'email', 'example@up.ac.th', 255, false, 'email')) ?>
                            </div>
                        </section>

                        <!-- เปลี่ยนรหัสผ่าน (พับได้) -->
                        <section class="oe-panel pf-card pf-pw oe-rise" style="--i:3;" data-open="<?= $pwOpen ? '1' : '0' ?>">
                            <button type="button" class="pf-pw-toggle" aria-expanded="false" aria-controls="pfPwBody">
                                <span class="pf-h2"><?= ic('key', 18) ?> เปลี่ยนรหัสผ่าน</span>
                                <span class="pf-pw-hint">ไม่ต้องการเปลี่ยน ปล่อยว่างไว้</span>
                                <span class="oe-chev" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></span>
                            </button>
                            <div class="pf-pw-body" id="pfPwBody">
                                <div class="pf-fields">
                                    <div class="pf-span2"><?= $field('old_password', 'รหัสผ่านเดิม', $pwInput('old_password', 'รหัสผ่านปัจจุบัน', 'current-password')) ?></div>
                                    <?= $field('password', 'รหัสผ่านใหม่', $pwInput('password', 'รหัสผ่านใหม่', 'new-password'),
                                        '<span class="pf-meter" aria-hidden="true"><span class="pf-meter-bar"></span></span><span class="pf-hint" data-pf-len>0 / ' . PROFILE_PW_MAX . '</span>') ?>
                                    <?= $field('password_confirm', 'ยืนยันรหัสผ่านใหม่', $pwInput('password_confirm', 'พิมพ์รหัสผ่านใหม่อีกครั้ง', 'new-password'),
                                        '<span class="pf-match" data-pf-match hidden></span>') ?>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="oe-dock pf-dock">
                    <div class="oe-dock-info">
                        <span class="pf-dock-text">แก้ไขแล้วกดบันทึก · รูปและข้อมูลอัปเดตพร้อมกัน</span>
                        <span class="oe-dirty" data-pf-dirty hidden>● ยังไม่บันทึก</span>
                        <span class="oe-msg" data-pf-msg role="alert"></span>
                    </div>
                    <div class="oe-actions">
                        <button type="button" class="oe-btn oe-btn-ghost" data-pf-reset>ยกเลิกการแก้ไข</button>
                        <button type="submit" class="oe-btn oe-btn-success" data-pf-save><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><span>บันทึกการเปลี่ยนแปลง</span></button>
                    </div>
                </div>
            </form>
        </div>

        <script src="<?= $root ?>assets/js/data-entry.js<?= asset_v('assets/js/data-entry.js') ?>"></script>
        <script src="<?= $root ?>assets/js/profile.js<?= asset_v('assets/js/profile.js') ?>"></script>
        <script>
            // SPA รันสคริปต์ซ้ำ: เรียกผ่านฟังก์ชันบน window เท่านั้น (สคริปต์ src โหลดก่อน — รอให้พร้อม)
            (function run(n) {
                if (window.pfInit) pfInit(document.getElementById('pfForm'));
                else if (n < 50) setTimeout(function () { run(n + 1); }, 40);
            })(0);
        </script>
    </main>
</body>
</html>
