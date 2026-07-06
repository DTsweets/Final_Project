<?php
/**
 * Database Configuration
 * ----------------------
 * เชื่อมต่อฐานข้อมูล upnetzero ผ่าน PDO
 * Host: 127.0.0.1 | DB: upnetzero | Charset: utf8mb4
 */

$env_path = __DIR__ . '/../.env';
if (file_exists($env_path)) {
    // ใช้ parse_ini_file เพื่ออ่านไฟล์ .env แบบดั้งเดิมโดยไม่ต้องลง Package
    $env = parse_ini_file($env_path) ?: [];
    define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
    define('DB_PORT', $env['DB_PORT'] ?? '3306');
    define('DB_NAME', $env['DB_NAME'] ?? 'upnetzero');
    define('DB_USER', $env['DB_USER'] ?? 'root');
    define('DB_PASS', $env['DB_PASS'] ?? '');
} else {
    // ค่าเริ่มต้นหากไม่พบไฟล์ .env
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', '3306');
    define('DB_NAME', 'upnetzero');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}
define('DB_CHARSET', 'utf8mb4');

/**
 * สร้าง PDO connection (Singleton)
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // ไม่แสดง error ดิบในหน้าเว็บ (production security)
            error_log('DB Connection Error: ' . $e->getMessage());
            throw new Exception('กรุณาตรวจสอบการตั้งค่าหรือติดต่อผู้ดูแลระบบ');
        }
    }

    return $pdo;
}
