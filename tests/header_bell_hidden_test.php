<?php
/**
 * Unit Test — ไอคอนกระดิ่ง (แจ้งเตือน) บน header ต้องถูก "ซ่อน" ไม่ใช่ "ลบ"
 * รัน: C:\xampp\php\php.exe tests\header_bell_hidden_test.php
 *
 * ล็อกกฎ:
 *   A. โค้ด svg กระดิ่งยังอยู่ในไฟล์ (เก็บไว้เปิดใช้ทีหลัง)
 *   B. แท็ก svg ของกระดิ่งมี display:none — ไม่แสดงบนหน้าจอ
 *   C. ครบทุก role: header 2 ไฟล์นี้ครอบคลุมทุกหน้าที่มีกระดิ่ง
 *      (officer/dean ใช้ officer/includes/header.php, admin ใช้ admin/includes/header.php)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$pass = $fail = 0;

function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

// ลายเซ็นของ path กระดิ่ง (ใช้จับแท็ก svg ที่ถูกต้อง)
const BELL_PATH = 'M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2z';

$headers = [
    'officer/includes/header.php' => 'officer + dean',
    'admin/includes/header.php'   => 'admin',
];

echo "== ไอคอนกระดิ่งบน header ==\n";

foreach ($headers as $rel => $who) {
    $path = $root . '/' . $rel;
    $src  = is_file($path) ? file_get_contents($path) : '';

    ck("$rel — อ่านไฟล์ได้", $src !== '', $path);
    if ($src === '') { continue; }

    // A. โค้ดกระดิ่งยังอยู่
    $has_bell = strpos($src, BELL_PATH) !== false;
    ck("$rel — โค้ด svg กระดิ่งยังอยู่ (ซ่อน ไม่ได้ลบ)", $has_bell);
    if (!$has_bell) { continue; }

    // B. แท็ก <svg> ที่ครอบ path กระดิ่ง ต้องมี display:none
    //    ตัดข้อความตั้งแต่ <svg ตัวสุดท้ายก่อน path กระดิ่ง จนถึง > ที่ปิดแท็กเปิด
    $bell_at  = strpos($src, BELL_PATH);
    $svg_at   = strrpos(substr($src, 0, $bell_at), '<svg');
    $open_tag = $svg_at === false ? '' : substr($src, $svg_at, strpos($src, '>', $svg_at) - $svg_at + 1);

    ck(
        "$rel — แท็ก svg กระดิ่งมี display:none ($who)",
        (bool) preg_match('/display\s*:\s*none/i', $open_tag),
        'open tag: ' . trim(preg_replace('/\s+/', ' ', $open_tag))
    );
}

// C. ไม่มีไฟล์อื่นที่ยังโชว์กระดิ่งนี้อยู่
$others = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') { continue; }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (isset($headers[$rel]) || strpos($rel, 'tests/') === 0) { continue; }
    if (strpos(file_get_contents($f->getPathname()), BELL_PATH) !== false) { $others[] = $rel; }
}
ck('ไม่มีไฟล์อื่นวาดกระดิ่งนี้ซ้ำ', $others === [], implode(', ', $others));

echo "\nสรุป: PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
