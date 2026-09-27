<?php
/**
 * Unit Test — ขนาดตั้งต้นของเว็บ = 90% ทุกหน้า
 * รัน: C:\xampp\php\php.exe tests\zoom_default_test.php
 *
 * ผู้ใช้กำหนด (28 ก.ย. 2569): เปิดเบราว์เซอร์ที่ 100% แล้วต้องได้ภาพเท่ากับตอนซูมเอง 90%
 *
 * ล็อกกฎ:
 *   Z1 ทุกหน้าที่เรนเดอร์ HTML ได้ zoom 0.9 — จาก CSS ที่โหลด (admin.css / login.css) หรือจาก <style> ของหน้าเอง
 *   Z2 ทุกที่ที่ตั้ง zoom ต้องมีการตั้งกลับเป็น 1 ตอนพิมพ์ (@media print) ไม่งั้น PDF/กระดาษจะเล็กลง 10%
 *   Z3 ต้องใช้ zoom ไม่ใช่ transform: scale (scale ทำให้ fixed/sticky และตำแหน่งเมนูที่คำนวณด้วย JS เพี้ยน)
 *   Z4 index.php (ตัวครอบ iframe) ต้องไม่ตั้ง zoom — หน้าในกรอบตั้งไว้แล้ว ถ้าตั้งซ้ำจะได้ 0.81
 *   Z5 หน้า login ที่เรนเดอร์จริงต้องโหลด CSS ที่มีกฎนี้
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

// รับได้ 2 แบบ: ตั้งตรง ๆ (หน้าที่มีสไตล์ของตัวเอง) หรือผ่านตัวแปร --app-zoom (ไฟล์ CSS หลัก ที่ต้องเอาค่าไปหาร 100vh ต่อ)
const ZOOM_RULE  = '/:root\s*\{[^}]*zoom:\s*(?:0\.9|var\(--app-zoom\))[^}]*\}/';
const PRINT_RULE = '/@media\s+print\s*\{\s*:root\s*\{[^}]*zoom:\s*1;?[^}]*\}\s*\}/';

/** ไฟล์ .php ทั้งหมดที่เรนเดอร์หน้าเว็บ (มี <html>) */
function html_pages(string $root): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (strpos($rel, 'tests/') === 0 || strpos($rel, 'vendor/') === 0) continue;
        if (stripos((string) file_get_contents($f->getPathname()), '<html') !== false) $out[] = $rel;
    }
    sort($out);
    return $out;
}

$pages = html_pages($root);
ck('Z0 หาไฟล์หน้าเว็บเจอ (อย่างน้อย 10 หน้า)', count($pages) >= 10, implode(' ', $pages));

// ── Z1 ทุกหน้าได้ 90% ──
$missing = [];
foreach ($pages as $p) {
    if ($p === 'index.php') continue;                       // ตัวครอบ iframe — ตรวจแยกใน Z4
    $src = (string) file_get_contents("$root/$p");
    if (preg_match(ZOOM_RULE, $src)) continue;              // ตั้งเองในหน้า
    $ok = false;
    foreach (['assets/css/admin.css', 'assets/css/login.css'] as $css) {           // หรือได้จาก CSS ที่โหลด
        if (strpos($src, $css) !== false && preg_match(ZOOM_RULE, (string) file_get_contents("$root/$css"))) { $ok = true; break; }
    }
    if (!$ok) $missing[] = $p;
}
ck('Z1 ทุกหน้าได้ขนาดตั้งต้น 90% (จาก admin.css / login.css หรือสไตล์ของหน้าเอง)', $missing === [], implode(', ', $missing));

// ── Z2 ตั้งกลับเป็น 1 ตอนพิมพ์ ──
$noPrint = [];
foreach (array_merge($pages, ['assets/css/admin.css', 'assets/css/login.css']) as $p) {
    $src = (string) file_get_contents("$root/$p");
    if (preg_match(ZOOM_RULE, $src) && !preg_match(PRINT_RULE, $src)) $noPrint[] = $p;
}
ck('Z2 ทุกที่ที่ตั้ง zoom มี @media print ตั้งกลับเป็น 1 (พิมพ์/บันทึก PDF ได้ขนาดเต็ม)', $noPrint === [], implode(', ', $noPrint));

// ── Z3 ใช้ zoom ไม่ใช่ transform: scale ──
$scaled = [];
foreach (glob("$root/assets/css/*.css") as $css) {
    $src = (string) file_get_contents($css);
    if (preg_match('/(:root|html|body)\s*\{[^}]*transform:\s*scale\(/', $src)) $scaled[] = basename($css);
}
ck('Z3 ไม่มีการย่อทั้งหน้าด้วย transform: scale (ใช้ zoom เท่านั้น)', $scaled === [], implode(', ', $scaled));

// ── Z4 ตัวครอบ iframe ต้องไม่ตั้ง zoom ──
$wrapper = (string) file_get_contents("$root/index.php");
ck('Z4 index.php (กรอบ iframe) ไม่ตั้ง zoom ซ้ำ — ไม่งั้นได้ 0.81 และมีคำเตือนกำกับไว้',
    !preg_match('/zoom:\s*0?\.9/', $wrapper) && strpos($wrapper, 'ห้ามตั้ง zoom ที่นี่') !== false);

// ── Z6 ความสูงเต็มจอต้องหารด้วย zoom ──
// zoom: 0.9 ไม่ย่อหน่วย vh ด้วย → 100vh กินแค่ 90% ของจอจริง เนื้อหาที่จัดกลางแนวตั้งจะลอยไปชิดขอบบน
$rawVh = [];
foreach (glob("$root/assets/css/*.css") as $css) {
    $src = (string) file_get_contents($css);
    if (!preg_match(ZOOM_RULE, $src) && !preg_match('/zoom:\s*var\(--app-zoom\)/', $src)) continue;   // ตรวจเฉพาะไฟล์ที่ตั้ง zoom
    if (preg_match('/(?<!\/ )(?:min-)?height:\s*100vh\s*;/', $src)) $rawVh[] = basename($css);
}
ck('Z6 ไฟล์ที่ตั้ง zoom ไม่ใช้ 100vh ดิบ — ต้องเป็น calc(100vh / var(--app-zoom)) ไม่งั้นเนื้อหาชิดขอบบน', $rawVh === [], implode(', ', $rawVh));
ck('Z6b login.css / admin.css ใช้ความสูงเต็มจอแบบหาร zoom แล้ว', (function () use ($root) {
    $l = (string) file_get_contents("$root/assets/css/login.css");
    $a = (string) file_get_contents("$root/assets/css/admin.css");
    return strpos($l, 'height: calc(100vh / var(--app-zoom))') !== false
        && strpos($a, 'min-height: calc(100vh / var(--app-zoom))') !== false
        && preg_match('/--app-zoom:\s*0\.9/', $l) && preg_match('/--app-zoom:\s*0\.9/', $a)
        && preg_match('/@media\s+print\s*\{\s*:root\s*\{\s*--app-zoom:\s*1;\s*zoom:\s*1;?\s*\}\s*\}/', $l);
})());
ck('Z6c ฉากทึบตอนสลับหน้า (SPA) คลุมเต็มจอ — ใช้ inset:0 ไม่ใช่ 100vh', (function () use ($root) {
    $s = (string) file_get_contents("$root/officer/includes/sidebar.php");
    return strpos($s, 'position:fixed;inset:0;') !== false && strpos($s, 'height:100vh') === false;
})());
ck('Z6d เมนูข้างสูงจากขอบบนถึงขอบล่างพอดี (จอเต็ม − margin บน/ล่าง) และหาร --app-zoom', (function () use ($root) {
    $css = (string) file_get_contents("$root/assets/css/sidebar.css");
    // margin ของกล่อง (20px ทั้งสี่ด้าน) ต้องตรงกับตัวเลขที่หักออกจากความสูง (40px = บน+ล่าง)
    return preg_match('/height:\s*calc\(100vh\s*\/\s*var\(--app-zoom[^)]*\)\s*-\s*40px\)/', $css)
        && preg_match('/\.sidebar-body\s*\{[^{]*margin:\s*20px/s', $css)
        && !preg_match('/height:\s*95vh/', $css);   // ค่าเดิมที่ทำให้เมนูสั้นกว่าจอ
})());

// ── Z7 ทุกหน้าต้องโหลดตัวปรับขนาดตามจอ ──
$noJs = [];
foreach ($pages as $p) {
    if ($p === 'index.php') continue;                      // กรอบ iframe — หน้าในกรอบจัดการเอง
    if (strpos((string) file_get_contents("$root/$p"), 'app-zoom.js') === false) $noJs[] = $p;
}
ck('Z7 ทุกหน้าโหลด assets/js/app-zoom.js (ปรับขนาดตามความกว้างจอ)', $noJs === [], implode(', ', $noJs));
ck('Z7b app-zoom.js ตั้งทั้ง --app-zoom และ zoom · ทำงานทันทีไม่รอโหลดเสร็จ (กันภาพกระโดด) · ตามจอตอนย่อ-ขยายหน้าต่าง', (function () use ($root) {
    $js = (string) file_get_contents("$root/assets/js/app-zoom.js");
    return strpos($js, "setProperty('--app-zoom'") !== false && strpos($js, 'root.style.zoom = z') !== false
        && strpos($js, "addEventListener('resize', apply)") !== false
        && strpos($js, 'DOMContentLoaded') === false;
})());

// ── Z5 หน้า login ที่เรนเดอร์จริง ──
$probe = sys_get_temp_dir() . '/zoom_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export($root, true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["SCRIPT_NAME"] = "/login.php";
$_GET = [];
ob_start(); require $root . "/login.php"; echo ob_get_clean();
');
$html = (string) shell_exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe)));
@unlink($probe);
ck('Z5 หน้า login เรนเดอร์แล้วโหลด login.css (พร้อมเลขเวอร์ชันกัน cache) ซึ่งมีกฎ 90%',
    strpos($html, 'assets/css/login.css?v=') !== false && strpos($html, 'Fatal error') === false,
    substr($html, 0, 200));

echo "\nสรุป: PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
