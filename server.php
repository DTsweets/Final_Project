<?php
/**
 * ตัวรับ request ของเซิร์ฟเวอร์ทดสอบ (php -S) — กันไฟล์ลับที่ .htaccess กันไม่ได้
 * รัน: C:\xampp\php\php.exe -S localhost:8000 server.php
 *
 * php -S ไม่อ่าน .htaccess ถ้ารันแบบไม่มีไฟล์นี้ จะเปิด /database/backup_*.sql, /.env, /tests/*.php ได้จาก URL
 * request ที่ไม่ถูกปิด → return false = ให้เซิร์ฟเวอร์จัดการตามปกติ (รัน .php / ส่งไฟล์ static เหมือนเดิม)
 * เทสต์: tests/web_exposure_test.php
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

require_once __DIR__ . '/includes/blocked_paths.php';

if (is_blocked_path($_SERVER['REQUEST_URI'] ?? '')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden";
    return true;
}

return false;
