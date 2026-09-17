<?php
/**
 * ข้อมูลของหน้าโปรไฟล์ (includes/profile_page.php — admin / officer / dean)
 * ---------------------------------------------------------------------
 * ลำดับการบันทึก (เดิมย้ายรูปและลบรูปเก่าก่อนตรวจรหัสผ่าน/ก่อนอัปเดต DB → รูปโปรไฟล์เสียเมื่อบันทึกไม่สำเร็จ):
 *   1) ตรวจข้อมูลทั้งหมด (profile_validate + profile_check_image) — ยังไม่แตะไฟล์
 *   2) เก็บรูปใหม่ → อัปเดต DB → สำเร็จจึงลบรูปเก่า / ไม่สำเร็จลบรูปใหม่ทิ้ง (profile_apply)
 * รหัสผ่านเก็บเป็น hash (includes/password.php) · ตรวจรหัสเดิมด้วย user_password_verify
 * แยกออกมาให้ทดสอบได้ (tests/profile_page_test.php)
 */

require_once __DIR__ . '/password.php';

const PROFILE_IMG_MAX   = 2 * 1024 * 1024;   // 2 MB (ฝั่งหน้าเว็บย่อรูปเหลือ 400px ก่อนส่ง ปกติไม่ถึง 100 KB)
const PROFILE_PW_MAX    = 50;                // เพดานรหัสผ่าน (bcrypt ใช้ได้ไม่เกิน 72 ไบต์ · ตรงกับ PW_MAX ใน profile.js)
const PROFILE_PW_MIN    = 8;                 // รหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร (ตรงกับ PW_MIN ใน profile.js / admin/settings.php)
const PROFILE_IMG_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

/** ชื่อบทบาทสำหรับแสดง */
function profile_role_label(string $role): string
{
    return ['admin' => 'ผู้ดูแลระบบ', 'officer' => 'เจ้าหน้าที่', 'dean' => 'บุคลากร/คณบดี'][$role] ?? $role;
}

/**
 * ตรวจข้อมูลที่ส่งมา (กฎเดียวกับ assets/js/profile.js)
 * @return array [errors (ชื่อช่อง => ข้อความ), data (ค่าที่ผ่านการตรวจ: firstname, lastname, username, email|null, password|null)]
 */
function profile_validate(array $in, array $user): array
{
    $e = [];
    $firstname = trim((string) ($in['firstname'] ?? ''));
    $lastname  = trim((string) ($in['lastname'] ?? ''));
    $username  = trim((string) ($in['username'] ?? ''));
    $email     = trim((string) ($in['email'] ?? ''));
    $old       = (string) ($in['old_password'] ?? '');
    $new       = (string) ($in['password'] ?? '');
    $confirm   = (string) ($in['password_confirm'] ?? '');

    if ($firstname === '') $e['firstname'] = 'กรุณากรอกชื่อจริง';
    elseif (mb_strlen($firstname) > 100) $e['firstname'] = 'ชื่อจริงยาวเกิน 100 ตัวอักษร';
    if ($lastname === '') $e['lastname'] = 'กรุณากรอกนามสกุล';
    elseif (mb_strlen($lastname) > 100) $e['lastname'] = 'นามสกุลยาวเกิน 100 ตัวอักษร';
    if ($username === '') $e['username'] = 'กรุณากรอกชื่อผู้ใช้งาน';
    elseif (mb_strlen($username) > 50) $e['username'] = 'ชื่อผู้ใช้งานยาวเกิน 50 ตัวอักษร';
    elseif (preg_match('/\s/u', $username)) $e['username'] = 'ชื่อผู้ใช้งานต้องไม่มีช่องว่าง';
    if ($email !== '' && (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) $e['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';

    // เปลี่ยนรหัสผ่านเมื่อกรอกช่องใดช่องหนึ่งของรหัสใหม่
    $changePw = $new !== '' || $confirm !== '';
    if ($changePw) {
        if ($old === '') $e['old_password'] = 'กรุณากรอกรหัสผ่านเดิม';
        elseif (!user_password_verify((string) ($user['password'] ?? ''), $old)) $e['old_password'] = 'รหัสผ่านเดิมไม่ถูกต้อง';
        if ($new === '') $e['password'] = 'กรุณากรอกรหัสผ่านใหม่';
        elseif (strlen($new) > PROFILE_PW_MAX) $e['password'] = 'รหัสผ่านยาวเกิน ' . PROFILE_PW_MAX . ' ตัวอักษร';
        elseif (mb_strlen($new) < PROFILE_PW_MIN) $e['password'] = 'รหัสผ่านต้องมีอย่างน้อย ' . PROFILE_PW_MIN . ' ตัวอักษร';
        if ($confirm !== $new) $e['password_confirm'] = 'รหัสผ่านยืนยันไม่ตรงกัน';
    }

    return [$e, [
        'firstname' => $firstname, 'lastname' => $lastname, 'username' => $username,
        'email'     => $email === '' ? null : $email,   // ว่าง = NULL (คอลัมน์ unique: '' ซ้ำกันไม่ได้ แต่ NULL ซ้ำได้)
        'password'  => $changePw ? $new : null,
    ]];
}

/**
 * ตรวจไฟล์รูปที่อัปโหลด: เป็นรูปจริง (อ่านหัวไฟล์ ไม่ดูแค่นามสกุล), ชนิดที่รองรับ, ไม่เกิน 2 MB
 * @return string|null ข้อความผิดพลาด / null = ใช้ได้ หรือไม่ได้เลือกไฟล์
 */
function profile_check_image(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $err = (int) $file['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'ไฟล์รูปใหญ่เกิน 2 MB';
    if ($err !== UPLOAD_ERR_OK) return 'อัปโหลดรูปไม่สำเร็จ (รหัส ' . $err . ')';
    if ((int) $file['size'] > PROFILE_IMG_MAX) return 'ไฟล์รูปใหญ่เกิน 2 MB';
    $info = @getimagesize((string) $file['tmp_name']);
    if (!$info || !in_array($info[2], PROFILE_IMG_TYPES, true)) return 'ไฟล์ไม่ใช่รูปภาพที่รองรับ (JPG, PNG, GIF, WEBP)';
    return null;
}

/** ชื่อ username / email ซ้ำกับผู้ใช้อื่น → ข้อความ (แยกให้รู้ว่าช่องไหนซ้ำ) */
function profile_duplicates(PDO $pdo, int $uid, array $data): array
{
    $e = [];
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
    $st->execute([$data['username'], $uid]);
    if ((int) $st->fetchColumn()) $e['username'] = 'ชื่อผู้ใช้งานนี้มีผู้ใช้แล้ว';
    if ($data['email'] !== null) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $st->execute([$data['email'], $uid]);
        if ((int) $st->fetchColumn()) $e['email'] = 'อีเมลนี้มีผู้ใช้แล้ว';
    }
    return $e;
}

/** ลบไฟล์รูปในโฟลเดอร์โปรไฟล์อย่างปลอดภัย (ชื่อไฟล์ล้วน ไม่ออกนอกโฟลเดอร์) */
function profile_unlink(string $dir, ?string $name): void
{
    if (!$name || basename($name) !== $name) return;
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) @unlink($path);
}

/**
 * บันทึกโปรไฟล์ (เรียกหลังตรวจผ่านแล้ว)
 * @param array|null $file   ไฟล์ที่ผ่าน profile_check_image แล้ว (null = ไม่เปลี่ยนรูป)
 * @param callable   $mover  ย้ายไฟล์ (หน้าเว็บ = move_uploaded_file, เทสต์ = copy)
 * @return string|null ชื่อไฟล์รูปปัจจุบันหลังบันทึก
 * @throws Exception ข้อมูลซ้ำ / เก็บรูปไม่สำเร็จ — ไฟล์รูปใหม่ถูกลบทิ้ง รูปเก่าอยู่ครบ
 */
function profile_apply(PDO $pdo, int $uid, array $user, array $data, ?array $file, string $dir, callable $mover): ?string
{
    $old = $user['profile_image'] ?? null;
    $new = null;
    if ($file) {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        // ใช้ .webp เสมอ: เมนูด้านข้างทุก role แสดงรูปด้วยชื่อ .webp (หน้าเว็บแปลงเป็น WebP ก่อนส่ง)
        $new = 'user_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.webp';
        if (!$mover((string) $file['tmp_name'], rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $new)) throw new Exception('บันทึกรูปภาพไม่สำเร็จ');
    }
    try {
        $sql = 'UPDATE users SET firstname = ?, lastname = ?, username = ?, email = ?, profile_image = ?' . ($data['password'] !== null ? ', password = ?' : '') . ' WHERE id = ?';
        $params = [$data['firstname'], $data['lastname'], $data['username'], $data['email'], $new ?? $old];
        if ($data['password'] !== null) $params[] = user_password_hash($data['password']);
        $params[] = $uid;
        $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        if ($new) profile_unlink($dir, $new);
        if ($e->getCode() === '23000') throw new Exception('ชื่อผู้ใช้งานหรืออีเมลซ้ำกับผู้ใช้อื่น');
        throw $e;
    }
    if ($new && $old && $old !== $new) profile_unlink($dir, $old);
    return $new ?? $old;
}
