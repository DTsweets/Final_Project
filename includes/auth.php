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

/**
 * ตรวจสอบ CSRF token (POST)
 */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('<h1>403 - Invalid CSRF Token</h1>');
    }
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
