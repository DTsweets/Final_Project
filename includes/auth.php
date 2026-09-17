<?php
/**
 * Authentication & Session Guard
 * --------------------------------
 * - Session timeout: 1 ชั่วโมง (3600 วินาที)
 * - Role-based access control
 * - CSRF token helpers
 * - Session regeneration ป้องกัน session fixation
 */

define('SESSION_TIMEOUT', 3600); // 1 ชั่วโมง

// เริ่ม session ถ้ายังไม่ได้เริ่ม
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,           // ปิด browser = session หมด
        'path'     => '/',
        'secure'   => false,       // true เมื่อใช้ HTTPS
        'httponly' => true,        // ป้องกัน JS อ่าน cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * ตรวจสอบ session timeout อัตโนมัติ
 * เรียกใช้ทุกครั้งที่โหลดหน้า
 */
function check_session_timeout(): void
{
    if (!isset($_SESSION['user_id'])) {
        return; // ยังไม่ได้ login
    }

    $now = time();
    $last = $_SESSION['last_activity'] ?? $now;

    if (($now - $last) > SESSION_TIMEOUT) {
        // Session หมดอายุ
        $_SESSION = [];
        session_destroy();
        header('Location: ' . get_root_path() . '/login.php?timeout=1');
        exit;
    }

    // อัพเดทเวลาล่าสุด
    $_SESSION['last_activity'] = $now;
}

/**
 * ข้อความ error ที่ปลอดภัยพอจะแสดงให้ผู้ใช้
 * - PDOException: เขียนรายละเอียดลง error log แล้วคืนข้อความกลาง (เดิมโชว์ SQLSTATE / ชื่อตาราง / ชื่อ constraint ให้ผู้ใช้)
 * - Exception ที่เราตั้งข้อความเอง (เช่น "ปริมาณ กรอกไม่ถูกต้อง"): คืนข้อความเดิม เพราะเขียนไว้ให้ผู้ใช้อ่านอยู่แล้ว
 * เทสต์: tests/error_message_test.php
 */
function safe_error_message(Throwable $e, string $fallback = 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'): string
{
    if ($e instanceof PDOException) {
        error_log('[upnetzero] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return $fallback;
    }
    return $e->getMessage();
}

/**
 * บังคับให้ login ก่อน
 * ถ้าไม่มี session → redirect ไป login.php
 */
function require_login(): void
{
    check_session_timeout();

    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . get_root_path() . '/login.php');
        exit;
    }

    // CSRF: ทุก POST ของหน้าที่ต้อง login ตรวจ token ที่นี่จุดเดียว (ทุกหน้าเรียก require_role → require_login ก่อนประมวลผล POST)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') verify_csrf();
}

/**
 * ตรวจสอบสิทธิ์ตาม role
 * @param string|array $roles  role ที่อนุญาต เช่น 'admin' หรือ ['admin','admin_c']
 */
function require_role($roles): void
{
    require_login();

    $roles = (array)$roles;
    $current_role = $_SESSION['role'] ?? '';

    if (!in_array($current_role, $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/403.php';
        exit;
    }
}

/**
 * ตรวจว่า user มี role ที่กำหนดหรือไม่ (ไม่ redirect)
 */
function has_role($roles): bool
{
    $roles = (array)$roles;
    return in_array($_SESSION['role'] ?? '', $roles, true);
}

/**
 * สร้าง CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** ช่อง hidden ของ token — ใส่ในทุก <form method="POST"> */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** token ที่ส่งมา (ช่องฟอร์ม csrf_token หรือ header X-CSRF-Token จาก fetch) ตรงกับ session หรือไม่ */
function csrf_valid(): bool
{
    $sent = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $own  = (string) ($_SESSION['csrf_token'] ?? '');
    return $own !== '' && $sent !== '' && hash_equals($own, $sent);
}

/**
 * ตรวจสอบ CSRF token ของ POST — ไม่ผ่าน → 403 และหยุดทันที (ไม่แตะข้อมูล)
 * API (path มี /api/) ตอบ JSON · หน้าเว็บแสดงหน้า 403 "หน้านี้หมดอายุ"
 * เรียกอัตโนมัติจาก require_login() ทุก POST ของหน้าที่ต้อง login
 */
function verify_csrf(): void
{
    if (csrf_valid()) return;
    http_response_code(403);
    $msg = 'หน้านี้หมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
    if (str_contains((string) ($_SERVER['PHP_SELF'] ?? ''), '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg, 'ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $forbidden_title = 'หน้านี้หมดอายุ';
    $forbidden_desc  = $msg;
    include __DIR__ . '/403.php';
    exit;
}

/**
 * คืนค่า root path สำหรับ redirect (URL path ของโปรเจค)
 * รองรับทั้ง root (/login.php) และ subdirectory (/R1/login.php)
 */
function get_root_path(): string
{
    // ใช้ includes/ เป็น anchor — อยู่ตรงกลางเสมอ
    // __DIR__ = .../R1/includes  → ขึ้น 1 ระดับ = R1 root
    $includes_dir = str_replace('\\', '/', __DIR__);
    $doc_root     = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);

    // หา URL path ของ R1 root (parent ของ includes/)
    $root_fs  = dirname($includes_dir);                          // .../R1
    $url_path = str_replace($doc_root, '', $root_fs);            // /R1
    $url_path = '/' . ltrim($url_path, '/');

    return rtrim($url_path, '/');
}

/**
 * เวลาที่เหลือของ session (วินาที)
 */
function session_remaining(): int
{
    if (!isset($_SESSION['last_activity'])) return 0;
    $elapsed = time() - $_SESSION['last_activity'];
    return max(0, SESSION_TIMEOUT - $elapsed);
}

// ตรวจสอบ timeout ทันทีที่ include ไฟล์นี้
check_session_timeout();
