<?php
/**
 * migrate_password_hash.php — เปลี่ยนรหัสผ่านใน users.password จาก plaintext เป็น hash (เขียน 18 ก.ย. 2569)
 * รัน: C:\xampp\php\php.exe database\migrate_password_hash.php
 *
 * ✅ รันลงฐานข้อมูลแล้ว 18 ก.ย. 2569 (สำรองไว้ที่ database/backup_20260918_before_password_hash.sql)
 * ‼️ รันซ้ำได้ — ข้ามบัญชีที่เป็น hash แล้ว
 * ‼️ หลังรันแล้วดูรหัสผ่านเดิมจากฐานข้อมูลไม่ได้อีก (ทุกคนยัง login ด้วยรหัสเดิมได้)
 *
 * ขั้นตอน:
 *   1) users.password varchar(50) → varchar(255) (hash ของ bcrypt ยาว 60 ตัว — varchar(50) ตัดทิ้งเงียบ ๆ)
 *   2) เข้ารหัสทุกบัญชีที่ยังเป็น plaintext ด้วย user_password_hash() ใน transaction เดียว
 * ฐานที่กู้จาก upnetzero.sql / ไฟล์สำรองที่ยังเป็น plaintext: login.php เข้ารหัสให้เองตอน login (หรือรันไฟล์นี้อีกครั้ง)
 *
 * ‼️ สำรองฐานข้อมูลก่อนรัน:
 *    C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -u root --default-character-set=utf8mb4 upnetzero > database\backup_YYYYMMDD_before_password_hash.sql
 * ตรวจหลังรัน: C:\xampp\php\php.exe tests\password_test.php (W2 ชนิดคอลัมน์ / ไม่มี plaintext)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/password.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$count = fn() => $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
echo "ก่อนรัน: users " . $count() . " บัญชี · คอลัมน์ "
    . $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'")->fetchColumn() . "\n";

// 1) ขยายคอลัมน์ (DDL — commit เอง ทำก่อนเริ่ม transaction)
$pdo->exec('ALTER TABLE users MODIFY `password` VARCHAR(255) NOT NULL');

// 2) เข้ารหัสบัญชีที่ยังเป็น plaintext
$rows = $pdo->query('SELECT id, password FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$up = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
$done = 0; $skip = 0;
$pdo->beginTransaction();
try {
    foreach ($rows as $id => $pw) {
        $pw = (string) $pw;
        if (user_password_is_hash($pw)) { $skip++; continue; }
        $hash = user_password_hash($pw);
        if (!password_verify($pw, $hash)) throw new Exception("เข้ารหัสบัญชี id $id ไม่สำเร็จ");
        $up->execute([$hash, $id]);
        $done++;
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'ยกเลิกทั้งหมด: ' . $e->getMessage() . "\n");
    exit(1);
}

$plain = array_filter($pdo->query('SELECT id, password FROM users')->fetchAll(PDO::FETCH_KEY_PAIR), fn($p) => !user_password_is_hash((string) $p));
echo "หลังรัน: users " . $count() . " บัญชี · เข้ารหัสใหม่ $done · เป็น hash อยู่แล้ว $skip · เหลือ plaintext " . count($plain) . "\n";
exit($plain ? 1 : 0);
