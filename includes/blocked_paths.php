<?php
/**
 * กันไฟล์ที่ไม่ควรเปิดผ่านเว็บ — ใช้โดย server.php (ตอนรันด้วย php -S)
 * ฝั่ง Apache ใช้ .htaccess ของแต่ละโฟลเดอร์ (php -S ไม่อ่าน .htaccess จึงต้องมีที่นี่ด้วย)
 * เทสต์: tests/web_exposure_test.php
 *
 * ปิด: ไฟล์สำรอง/ดัมป์ (.sql) · .env · .git · เอกสาร .md · โฟลเดอร์ database/ tests/ config/
 * เหตุผล: ไฟล์สำรองมีข้อมูลผู้ใช้ทั้งฐาน · tests/ เขียนข้อมูลจริงได้ · config/ กับ .env มีรหัสเชื่อมต่อฐาน
 */

const BLOCKED_DIRS = ['database', 'tests', 'config'];
const BLOCKED_EXTS = ['sql', 'md', 'env', 'log', 'bak', 'ini', 'tmp'];

/** true = ห้ามเปิดผ่านเว็บ */
function is_blocked_path(string $uri): bool
{
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?? $uri);
    $path = str_replace('\\', '/', urldecode($path));

    foreach (explode('/', $path) as $seg) {
        if ($seg === '') continue;
        if (str_contains($seg, ':')) return true;                 // NTFS alternate data stream เช่น backup.sql::$DATA
        $seg = strtolower(rtrim($seg, " ."));                     // Windows ตัดจุด/ช่องว่างท้ายชื่อทิ้ง (database. = database)
        if ($seg === '' || $seg === '..') return true;            // ".." หรือชื่อที่เหลือแต่จุด
        if ($seg[0] === '.') return true;                         // .env .git .gitignore .htaccess .claude
        if (in_array($seg, BLOCKED_DIRS, true)) return true;
        if (in_array(pathinfo($seg, PATHINFO_EXTENSION), BLOCKED_EXTS, true)) return true;
    }
    return false;
}
