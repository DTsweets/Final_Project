<?php
/**
 * Unit Test — มุมมองรายงานของ dean (ghg_resolve_view)
 * รัน: C:\xampp\php\php.exe tests\dean_view_scope_test.php
 *
 * ล็อกกฎ:
 *   A. dean เลือกได้ทั้ง "ทั้งมหาวิทยาลัย" และ "คณะของฉัน" (เดิมถูกบังคับเป็น faculty เสมอ)
 *   B. ค่าเริ่มต้นต่างกันตาม role — dean เปิดมาเห็นคณะตัวเองก่อน, admin เห็นภาพรวม
 *   C. ทั้ง 3 หน้า (เว็บ / Excel / PDF) ต้องใช้ตัวตัดสินใจตัวเดียวกัน
 *      ไม่งั้นหน้าเว็บโชว์ "ทั้งมหาวิทยาลัย" แต่กดดาวน์โหลดได้ไฟล์ของคณะตัวเอง
 *   D. มุมมองที่เลือก ต้องส่งผลถึงตัวเลขจริง (system ≠ faculty เมื่อมีหลายหน่วยงาน)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';

$pdo  = getDB();
$pass = $fail = 0;

function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

// ═══ A. dean เลือกมุมมองได้ ════════════════════════════════
echo "A. dean เลือกมุมมองได้ (บั๊กเดิม: ถูกบังคับเป็น faculty เสมอ)\n";
ck('A1 dean ขอ system → ได้ system', ghg_resolve_view('dean', 'system') === 'system',
    'ได้ ' . ghg_resolve_view('dean', 'system'));
ck('A2 dean ขอ faculty → ได้ faculty', ghg_resolve_view('dean', 'faculty') === 'faculty');

// ═══ B. ค่าเริ่มต้นตาม role ═════════════════════════════════
echo "\nB. ค่าเริ่มต้นเมื่อไม่ได้ระบุ ?view=\n";
ck('B1 dean → faculty (เปิดมาเห็นคณะตัวเองก่อน)', ghg_resolve_view('dean', null) === 'faculty');
ck('B2 admin → system (ไม่มีคณะสังกัด)',        ghg_resolve_view('admin', null) === 'system');
ck('B3 role อื่น → system',                      ghg_resolve_view('', null) === 'system');

echo "\nB4-B6 ค่า ?view= ที่ไม่ถูกต้อง ต้องถอยไปค่าเริ่มต้นของ role\n";
foreach (['xxx', '', 'SYSTEM', 'Faculty'] as $bad) {
    ck("B4 dean + view='$bad' → faculty",  ghg_resolve_view('dean', $bad) === 'faculty');
    ck("B5 admin + view='$bad' → system",  ghg_resolve_view('admin', $bad) === 'system');
}

// ═══ C. 3 หน้าใช้ตัวตัดสินใจเดียวกัน ═══════════════════════
echo "\nC. หน้าเว็บ / Excel / PDF ต้องใช้ ghg_resolve_view() ตัวเดียวกัน\n";
$files = ['dean/reports.php', 'dean/export_report.php', 'dean/report_print.php'];
foreach ($files as $f) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    ck("C1 $f เรียก ghg_resolve_view()", str_contains($src, 'ghg_resolve_view('), 'ไม่พบการเรียกใช้');
    // ต้องกำหนด $view ที่เดียวในไฟล์ — ที่มาของบั๊กเดิมคือแต่ละไฟล์ตัดสินเอง แล้วหลุดจากกัน
    // ใช้ single quote รอบ regex ไม่งั้น PHP แทนค่า $view ในสตริงจนตรวจไม่เจอ
    // (?!=) กัน $view === 'faculty' ที่เป็นการ 'เทียบ' ไม่ใช่ 'กำหนดค่า'
    $assigns = preg_match_all('/\$view\s*=(?!=)/', $src);
    ck("C2 $f กำหนด \$view ที่เดียว", $assigns === 1, "พบ $assigns จุด");
    ck("C3 $f ไม่มีเงื่อนไขบังคับ view ตาม role หลงเหลือ",
        !preg_match('/\$view\s*=(?!=)[^;]*role[^;]*dean/s', $src),
        'ยังมีการตัดสินมุมมองจาก role ในไฟล์');
}

// ═══ D. มุมมองส่งผลถึงตัวเลขจริง ═══════════════════════════
echo "\nD. มุมมองที่เลือกส่งผลถึงตัวเลขจริง\n";
$years = ghg_years($pdo);
$deans = $pdo->query("SELECT DISTINCT Affiliation FROM users WHERE role='dean' AND Affiliation > 0")->fetchAll();
$checked = 0;
foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    $sys = ghg_scope_totals($pdo, $yid, null);          // มุมมองทั้งมหาวิทยาลัย
    $sysTotal = $sys[1] + $sys[2] + $sys[3];
    if ($sysTotal <= 0) continue;
    foreach ($deans as $d) {
        $aff = (int) $d['Affiliation'];
        $fac = ghg_scope_totals($pdo, $yid, $aff);      // มุมมองคณะ
        $facTotal = $fac[1] + $fac[2] + $fac[3];
        ck("D1 ปี {$y['year']} คณะ $aff: ยอดคณะ <= ยอดทั้งมหาวิทยาลัย",
            $facTotal <= $sysTotal + 1e-9,
            sprintf('คณะ=%.4f ระบบ=%.4f', $facTotal, $sysTotal));
        $checked++;
    }
}
ck('D2 ได้ตรวจกับข้อมูลจริงอย่างน้อย 1 เคส', $checked > 0);

// D3 — พิสูจน์ว่าสองมุมมองต่างกันจริง ไม่ใช่ผ่านเพราะข้อมูลเท่ากันพอดี
$cur = (int) ($years[0]['year_id'] ?? 0);
if ($cur && $deans) {
    $aff = (int) $deans[0]['Affiliation'];
    $s = ghg_scope_totals($pdo, $cur, null);
    $f = ghg_scope_totals($pdo, $cur, $aff);
    ck('D3 สองมุมมองให้ตัวเลขต่างกันจริง (มีหลายหน่วยงานในระบบ)',
        abs(($s[1]+$s[2]+$s[3]) - ($f[1]+$f[2]+$f[3])) > 1e-6,
        'ถ้าเท่ากัน แปลว่ามีหน่วยงานเดียว เทสต์ D1 พิสูจน์อะไรไม่ได้');
}

echo "\n─────────────────────────\nผ่าน $pass | ไม่ผ่าน $fail\n";
exit($fail > 0 ? 1 : 0);
