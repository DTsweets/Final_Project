<?php
/**
 * Unit Test — พื้นหลังหน้า login
 * รัน: C:\xampp\php\php.exe tests\login_bg_test.php
 *
 * ผู้ใช้เปลี่ยนรูปพื้นหลังเป็น sky_bg.webp (28 ก.ย. 2569) แทน island_bg.webp เดิม
 *
 * ล็อกกฎ:
 *   B1 ไฟล์รูปที่ CSS อ้างถึงต้องมีอยู่จริง — พิมพ์ชื่อผิดแล้วพื้นหลังหายเงียบ ๆ ไม่มีใครรู้
 *   B2 บรรทัด preload ใน login.php ต้องชี้ไฟล์เดียวกับใน login.css
 *      (ถ้าไม่ตรง เบราว์เซอร์โหลดรูปที่ไม่ได้ใช้ทิ้งเปล่า แล้วค่อยโหลดรูปจริงอีกรอบ ช้าสองเท่า)
 *   B3 มีสีพื้นสำรอง + background-size: cover — ระหว่างรูปยังโหลดไม่เสร็จ/จอสัดส่วนแปลก ต้องไม่เห็นพื้นขาว
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

$css = (string) file_get_contents("$root/assets/css/login.css");
$php = (string) file_get_contents("$root/login.php");

// ── B1 รูปที่ CSS อ้างถึงมีอยู่จริง ──
preg_match("/body\s*\{[^}]*background:[^;]*url\('\.\.\/images\/([^']+)'\)/s", $css, $m);
$cssImg = $m[1] ?? '';
ck('B1 body ของ login.css อ้างรูปพื้นหลัง และไฟล์นั้นมีอยู่จริงใน assets/images/',
    $cssImg !== '' && is_file("$root/assets/images/$cssImg"), "อ้างถึง: '$cssImg'");

// ── B2 preload ชี้ไฟล์เดียวกัน ──
preg_match('/<link\s+rel="preload"\s+as="image"\s+href="assets\/images\/([^"]+)"/', $php, $m2);
$preImg = $m2[1] ?? '';
ck('B2 preload ใน login.php ชี้รูปเดียวกับใน login.css (ไม่โหลดรูปที่ไม่ได้ใช้ทิ้งเปล่า)',
    $preImg !== '' && $preImg === $cssImg, "css='$cssImg' preload='$preImg'");

// ── B3 สีพื้นสำรอง + cover ──
ck('B3 มีสีพื้นสำรองและ background-size: cover (ไม่เห็นพื้นขาวตอนรูปยังไม่มา/จอสัดส่วนแปลก)',
    preg_match('/body\s*\{[^}]*background:\s*#[0-9A-Fa-f]{3,6}\s+url\(/s', $css) === 1
    && preg_match('/body\s*\{[^}]*background-size:\s*cover/s', $css) === 1);

echo "\nสรุป: PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
