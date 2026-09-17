<?php
/**
 * Unit Test — ความถูกต้องของแม่บทรายการปล่อย (admin_item / admin_g)
 * รัน: C:\xampp\php\php.exe tests\admin_item_integrity_test.php
 *
 * ล็อกกฎ 5 ข้อ:
 *   A. ไม่มีรายการชื่อขยะ (อักษรซ้ำติดกันเกิน 3 ตัว)
 *   B. เชื้อเพลิงชนิดเดียวกันต้องใช้หน่วยเดียวกันทุกหมวด
 *   C. ไม่มีรายการซ้ำภายในหมวดเดียวกัน (ซ้ำข้ามหมวดถือว่าถูกต้องตาม TGO)
 *   D. ค่า EF หลักต้องตรงกับที่ อบก. (TGO) ประกาศ
 *   E. ทุกรายการต้องผูกกับหมวด (admin_g) ที่มีอยู่จริง และ AD ต้องไม่ติดลบ
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';

$pdo  = getDB();
$pass = $fail = 0;

function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

echo "=== admin_item integrity ===\n\n";

// ── A. ชื่อขยะ ────────────────────────────────────────────────
echo "A. ชื่อรายการต้องไม่ใช่ข้อความทดสอบ\n";
$junk = $pdo->query(
    "SELECT id, name_tiem FROM admin_item
     WHERE name_tiem REGEXP 'กกกก|ะะะะ|ลลลล|งงงง|ยยยย|ทททท|ssss|aaaa|1111'"
)->fetchAll();
ck('A1 ไม่มีชื่อที่มีอักษรซ้ำติดกันเกิน 3 ตัว', count($junk) === 0,
    implode(' | ', array_map(fn($r) => "id={$r['id']} {$r['name_tiem']}", $junk)));

// ── B. หน่วยของเชื้อเพลิงชนิดเดียวกัน ───────────────────────
echo "\nB. เชื้อเพลิงชนิดเดียวกันต้องใช้หน่วยเดียวกันทุกหมวด\n";
// หน่วยต่างกันได้ถ้าค่า EF ต่างกันด้วย (เช่น ก๊าซชีวภาพ 0.0084/kg กับ 0.0011/Nm3 — TGO ประกาศทั้งคู่)
// ที่ผิดคือ "ค่า EF เท่ากันเป๊ะ แต่ป้ายหน่วยคนละอย่าง" → ป้ายใดป้ายหนึ่งผิดแน่นอน
$mixed = $pdo->query(
    "SELECT name_tiem, AD, GROUP_CONCAT(DISTINCT unit) units, COUNT(DISTINCT unit) n
     FROM admin_item WHERE data_source = 'officer'
     GROUP BY name_tiem, AD HAVING n > 1"
)->fetchAll();
ck('B1 ไม่มีเชื้อเพลิงที่ค่า EF เท่ากันแต่หน่วยขัดกัน', count($mixed) === 0,
    implode(' | ', array_map(fn($r) => "{$r['name_tiem']} AD={$r['AD']} => {$r['units']}", $mixed)));

// ── C. ซ้ำภายในหมวดเดียวกัน ──────────────────────────────────
// หมายเหตุ: ดีเซลมี 3 แถวเป็นเรื่องถูกต้อง — คนละหมวดย่อยของขอบเขต 1
//           (Stationary / Mobile On-Road / Mobile Off-Road) และ TGO ให้ EF ต่างกัน
echo "\nC. ไม่มีรายการซ้ำภายในหมวดเดียวกัน\n";
$dup = $pdo->query(
    "SELECT year_id, scope, name_tiem, affiliation_id, COUNT(*) n
     FROM admin_item GROUP BY year_id, scope, name_tiem, affiliation_id HAVING n > 1"
)->fetchAll();
ck('C1 ไม่มีชื่อซ้ำใน (ปี, หมวด, สังกัด) เดียวกัน', count($dup) === 0,
    implode(' | ', array_map(fn($r) => "yr={$r['year_id']} g={$r['scope']} {$r['name_tiem']} x{$r['n']}", $dup)));

// ── D. ค่า EF ต้องตรงกับ TGO ─────────────────────────────────
// แหล่งอ้างอิง: อบก. — Emission Factor CFO ฉบับ UPDATE กุมภาพันธ์ 2569 (บังคับใช้ 1 ม.ค. 2569)
//   https://thaicarbonlabel.tgo.or.th  →  เมนู Emission Factor (CFO)
// ตรวจทานกับเอกสารต้นฉบับแล้ว — ค่าที่ยังไม่ตรงฉบับล่าสุดถูกแยกไปรายงานในหมวด G
//
// [ชื่อรายการ, หมวด admin_g.id, EF ที่ อบก. ประกาศ, หน่วย]
echo "\nD. ค่า EF ต้องตรงกับที่ อบก. (TGO) ประกาศ\n";
$tgo = [
    ['ไฟฟ้าที่ซื้อจากระบบสายส่ง (MEA/PEA)', 4, 0.4750, 'kWh'],  // grid mix 2022-2024
    ['ดีเซล (Diesel)',                       1, 2.7076, 'L'],   // Stationary
    ['ดีเซล (Diesel)',                       2, 2.7403, 'L'],   // Mobile On-Road
    ['ดีเซล (Diesel)',                       3, 2.9790, 'L'],   // Mobile Off-Road
    ['เบนซิน (Gasoline)',                    1, 2.1892, 'L'],
    ['แอลพีจี (LPG)',                        3, 3.2057, 'kg'],   // Off-road ต่างจาก Stationary (3.1133)
];
$st = $pdo->prepare("SELECT unit, AD FROM admin_item WHERE name_tiem = ? AND scope = ? LIMIT 1");
foreach ($tgo as [$name, $gid, $ef, $unit]) {
    $st->execute([$name, $gid]);
    $row = $st->fetch();
    if (!$row) { ck("D $name (หมวด $gid)", false, 'ไม่พบรายการนี้ในฐานข้อมูล'); continue; }
    ck("D $name (หมวด $gid) = $ef $unit",
        abs((float) $row['AD'] - $ef) < 1e-4 && $row['unit'] === $unit,
        sprintf('พบ AD=%s unit=%s', $row['AD'], $row['unit']));
}

// ── F. แบบสอบถาม/กิจกรรม ต้องอยู่ขอบเขต 3 เท่านั้น ───────────
// เหตุผล: แหล่งปล่อยที่องค์กรไม่ได้ควบคุม (การเดินทางของนิสิต/ผู้ร่วมงาน วัสดุที่ซื้อ ของเสีย)
//         TGO CFO จัดเป็นขอบเขต 3 — และถ้าหลุดไปขอบเขต 1/2 ยอดจะถูกบวกทับของเจ้าหน้าที่ (นับซ้ำ)
echo "
F. แบบสอบถาม/กิจกรรม ต้องอยู่ขอบเขต 3
";
$wrongScope = $pdo->query(
    "SELECT ai.id, ai.data_source, g.scope, ai.name_tiem
     FROM admin_item ai JOIN admin_g g ON g.id = ai.scope
     WHERE ai.data_source IN ('survey','event') AND g.scope <> 3"
)->fetchAll();
ck('F1 ไม่มีรายการ survey/event อยู่นอกขอบเขต 3', count($wrongScope) === 0,
    implode(' | ', array_map(fn($r) => "id={$r['id']} {$r['data_source']} S{$r['scope']} {$r['name_tiem']}", $wrongScope)));

// หมวดการเดินทางต้องมีอยู่ ไม่งั้นรายการเดินทางจะถูกยัดเข้าหมวด "การซื้อวัตถุดิบและบริการ"
$commute = (int) $pdo->query(
    "SELECT COUNT(*) FROM admin_g WHERE scope = 3 AND name_tiem LIKE '%เดินทาง%'"
)->fetchColumn();
ck('F2 มีหมวดการเดินทางในขอบเขต 3', $commute > 0, 'ไม่พบ — รายการเดินทางจะถูกจัดผิดหมวด');

// รายการที่ชื่อเกี่ยวกับการเดินทาง ต้องไม่ค้างอยู่ในหมวดจัดซื้อ/ของเสีย
$misfiled = $pdo->query(
    "SELECT ai.id, ai.name_tiem, g.name_tiem AS gname
     FROM admin_item ai JOIN admin_g g ON g.id = ai.scope
     WHERE ai.data_source IN ('survey','event')
       AND (ai.name_tiem LIKE '%เดินทาง%' OR ai.name_tiem LIKE '%ระยะทาง%')
       AND g.name_tiem NOT LIKE '%เดินทาง%'"
)->fetchAll();
ck('F3 รายการเดินทางอยู่ในหมวดการเดินทาง ไม่ใช่หมวดจัดซื้อ', count($misfiled) === 0,
    implode(' | ', array_map(fn($r) => "id={$r['id']} {$r['name_tiem']} → {$r['gname']}", $misfiled)));

// ── E. ความสมบูรณ์เชิงโครงสร้าง ─────────────────────────────
echo "\nE. ความสมบูรณ์เชิงโครงสร้าง\n";
$orphan = (int) $pdo->query(
    "SELECT COUNT(*) FROM admin_item ai LEFT JOIN admin_g g ON g.id = ai.scope WHERE g.id IS NULL"
)->fetchColumn();
ck('E1 ทุกรายการผูกกับหมวดที่มีอยู่จริง', $orphan === 0, "กำพร้า $orphan แถว");

$neg = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE AD < 0")->fetchColumn();
ck('E2 ไม่มีค่า EF ติดลบ', $neg === 0, "ติดลบ $neg แถว");

$noUnit = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE unit IS NULL OR unit = ''")->fetchColumn();
ck('E3 ทุกรายการมีหน่วยกำกับ', $noUnit === 0, "ไม่มีหน่วย $noUnit แถว");

// EF / ค่าดูดกลับ เดิมเป็น float (แม่น ~7 หลัก): PDO อ่านได้ 6 หลัก แต่ SQL คูณด้วยค่าเต็ม → หน้ากรอก (JS) กับรายงาน (SQL) ต่างกัน
$types = $pdo->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME), COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'admin_item' AND COLUMN_NAME = 'AD')
      OR (TABLE_NAME IN ('removal_item', 'removal_event_item') AND COLUMN_NAME = 'factor'))")->fetchAll(PDO::FETCH_KEY_PAIR);
ck('E4 EF (admin_item.AD) และค่าดูดกลับ (removal_item / removal_event_item.factor) เป็น decimal(13,6) ไม่ใช่ float',
    count($types) === 3 && !array_diff($types, ['decimal(13,6)']), json_encode($types));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->beginTransaction();
try {
    $row = $pdo->query("SELECT ui.id, ui.admin_item_id, ui.Vol FROM user_item ui WHERE ui.Vol > 0 ORDER BY ui.id LIMIT 1")->fetch();
    $pdo->prepare('UPDATE admin_item SET AD = ? WHERE id = ?')->execute(['3.141593', $row['admin_item_id']]);
    $back = (string) $pdo->query("SELECT AD FROM admin_item WHERE id = {$row['admin_item_id']}")->fetchColumn();
    $sql  = (string) $pdo->query("SELECT ui.Vol * ai.AD / 1000 FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.id = {$row['id']}")->fetchColumn();
    $php  = (float) $row['Vol'] * 3.141593 / 1000;
    ck('E5 EF 6 ตำแหน่ง (3.141593) อ่านกลับได้ตรงตัว และ ปริมาณ × EF ใน SQL = ใน PHP/JS', (float) $back === 3.141593 && abs((float) $sql - $php) < 1e-9,
        "อ่านกลับ=$back sql=$sql php=$php");
} finally {
    $pdo->rollBack();
}

// ── G. ค่าที่ยังไม่ตรงเอกสาร อบก. ฉบับล่าสุด (เตือน ไม่นับเป็น fail) ────
// แยกจากหมวด D เพราะเป็น "ข้อมูลล้าสมัย" ไม่ใช่ "ข้อมูลผิด" — การเปลี่ยน EF กระทบ
// ตัวเลขย้อนหลังทุกปีและต้องตัดสินใจร่วมกับปีฐานที่ใช้รายงาน จึงไม่ควรแก้เงียบ ๆ
echo "
G. รายการที่เอกสาร อบก. ให้หลายค่า ต้องเลือกก่อน (เตือนเท่านั้น)
";
$drift = [
    // [ชื่อ, หมวด admin_g.id, ค่าในเอกสารฉบับล่าสุด, หมายเหตุ]
    // เอกสารให้ค่าแยกตามชนิดยานพาหนะ/ระบบควบคุมไอเสีย ซึ่งระบบยังไม่ได้แยก
    // จึงเลือกค่าใดค่าหนึ่งแทนผู้ใช้ไม่ได้ ต้องให้ผู้ดูแลตัดสินใจก่อน
    ['เบนซิน (Gasoline)',                   2, 2.2325, 'On-road: uncontrolled 2.2373 | oxidation catalyst 2.2703 | low mileage 1995+ 2.2325'],
    ['แก๊สโซฮอล์ E10 (Gasohol 91/95)',       2, 2.0103, 'On-road: 2.0444 หรือ 2.0103 ตามระบบควบคุมไอเสีย'],
    ['แก๊สโซฮอล์ E20 (Gasohol: E20)',        2, 1.7881, 'On-road: 1.8184 หรือ 1.7881'],
    ['แก๊สโซฮอล์ E85 (Gasohol: E85)',        2, 0.3439, 'On-road: 0.3496 หรือ 0.3439'],
    // Off-road: เลือกแถว "อุตสาหกรรม 4 จังหวะ" (ผู้ใช้ยืนยัน 17 ก.ย. 2569) — เอกสารมี เกษตร 2.0506 / ป่าไม้ 1.9634 / อุตฯ 2.0242 / ครัวเรือน 2.0859
    ['แก๊สโซฮอล์ E10 (Gasohol 91/95)',       3, 2.0242, 'Off-road: ใช้แถวอุตสาหกรรม 4 จังหวะ (เกษตร 2.0506 / ป่าไม้ 1.9634 / อุตฯ 2.0242 / ครัวเรือน 2.0859)'],
    ['ก๊าซชีวภาพ (Biogas)',                  2, 0.0000, 'เอกสารไม่มี Biogas ในหมวด On-road — ต้องยืนยันว่ารายการนี้ถูกต้องหรือไม่'],
];
$stale = 0;
$st2 = $pdo->prepare("SELECT AD FROM admin_item WHERE name_tiem = ? AND scope = ? LIMIT 1");
foreach ($drift as [$dname, $dgid, $def, $dnote]) {
    $st2->execute([$dname, $dgid]);
    $cur = $st2->fetchColumn();
    if ($cur === false) continue;
    if (abs((float) $cur - $def) >= 1e-4) {
        $stale++;
        printf("  [เตือน] %s (หมวด %d): ระบบ=%s  เอกสาร=%s
           %s
", $dname, $dgid, $cur, $def, $dnote);
    }
}
if ($stale === 0) echo "  ตรงกับเอกสารฉบับล่าสุดทุกค่า
";
else echo "  → ยังไม่อัปเดต $stale ค่า (ไม่นับเป็น fail — ต้องเลือกชนิดยานพาหนะก่อน)
";

echo "
─────────────────────────
ผ่าน $pass | ไม่ผ่าน $fail" . ($stale ? " | เตือน $stale" : "") . "
";
exit($fail > 0 ? 1 : 0);
