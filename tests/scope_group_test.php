<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * Unit Test — การผูกรายการเข้า "หมวดย่อยตามขอบเขต" (admin_g) และการแยกหมวดในรายงาน
 * รัน: C:\xampp\php\php.exe tests\scope_group_test.php
 *
 * ล็อกกฎ 2 ชุด:
 *   A. resolve_admin_g() ต้องผูกรายการเข้าหมวดที่ผู้ใช้เลือกจริง
 *      (บั๊กเดิม: "WHERE scope=N ORDER BY id LIMIT 1" หยิบหมวดแรกเสมอ
 *       → รายการเดินทางถูกยัดเข้า "การซื้อวัตถุดิบและบริการ" ทั้งหมด)
 *   B. ghg_scope_item_breakdown() ต้องไม่ยุบรายการชื่อเดียวกันที่อยู่คนละหมวด
 *      (บั๊กเดิม: ดีเซล 3 หมวด รวมเป็นชิ้นโดนัทเดียว คณบดีย้อนกลับไปตรวจสอบไม่ได้)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_g.php';
require_once __DIR__ . '/../includes/ghg_report.php';

$pdo  = getDB();
$pass = $fail = 0;

function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

// ═══ A. resolve_admin_g() ═══════════════════════════════════
echo "A. การเลือกหมวดย่อย (resolve_admin_g)\n";

$groups = $pdo->query("SELECT id, scope, name_tiem FROM admin_g ORDER BY scope, order_num, id")->fetchAll();
ck('A0 มีหมวดย่อยในฐานข้อมูล', count($groups) > 0);

// A1 — พิสูจน์ว่าบั๊กเดิม "มีผลจริง": ต้องมีขอบเขตที่มีหมวดย่อยมากกว่า 1
$perScope = [];
foreach ($groups as $g) $perScope[(int)$g['scope']][] = (int)$g['id'];
$multi = array_filter($perScope, fn($ids) => count($ids) > 1);
ck('A1 มีขอบเขตที่มีหมวดย่อยหลายหมวด (บั๊ก LIMIT 1 จึงมีผลจริง)', count($multi) > 0,
    'ถ้าทุกขอบเขตมีหมวดเดียว เทสต์นี้พิสูจน์อะไรไม่ได้');

// A2 — เลือกหมวดไหน ต้องได้หมวดนั้น (ทดสอบทุกหมวด ไม่ใช่แค่หมวดแรก)
$wrong = [];
foreach ($groups as $g) {
    [$gid, $sc] = resolve_admin_g($pdo, (int)$g['id'], 0);
    if ($gid !== (int)$g['id'] || $sc !== (int)$g['scope']) {
        $wrong[] = "ส่ง {$g['id']} ได้ $gid (scope $sc)";
    }
}
ck('A2 ส่ง group_id ใดก็ได้หมวดนั้นเสมอ ทุกหมวด', count($wrong) === 0, implode(' | ', $wrong));

// A3 — หมวดที่ "ไม่ใช่หมวดแรก" ต้องเลือกได้จริง (เจาะจงจุดที่บั๊กเดิมทำพัง)
foreach ($multi as $sc => $ids) {
    $last = end($ids);
    [$gid] = resolve_admin_g($pdo, $last, $sc);
    ck("A3 ขอบเขต $sc เลือกหมวดสุดท้าย (id=$last) ได้ ไม่ถูกบังคับเป็นหมวดแรก (id={$ids[0]})",
        $gid === $last, "ได้ $gid");
}

// A4 — ไม่ส่ง group_id → ถอยไปใช้หมวดแรกของขอบเขตนั้น (ของเดิมต้องไม่พัง)
foreach ($perScope as $sc => $ids) {
    [$gid] = resolve_admin_g($pdo, 0, $sc);
    ck("A4 ขอบเขต $sc ไม่ส่ง group_id → ได้หมวดแรก", $gid === $ids[0], "ได้ $gid ควรเป็น {$ids[0]}");
}

// A5 — group_id ที่ไม่มีอยู่จริง ต้องโยน Exception ไม่ใช่บันทึกมั่ว
$threw = false;
try { resolve_admin_g($pdo, 999999, 1); } catch (Exception $e) { $threw = true; }
ck('A5 group_id ที่ไม่มีอยู่จริง → โยน Exception', $threw);

// ═══ A6-A9. บังคับขอบเขต 3 สำหรับแบบสอบถาม/กิจกรรม ═════════
// แบบสอบถามถามการเดินทางของนิสิต/บุคลากร และกิจกรรมนับการเดินทางของผู้เข้าร่วม
// ล้วนเป็นแหล่งที่องค์กรไม่ได้ควบคุม → TGO CFO จัดเป็นขอบเขต 3
// และถ้าปล่อยให้ตั้งเป็นขอบเขต 1/2 ได้ ยอดจะไปบวกทับของเจ้าหน้าที่ (นับซ้ำ)
echo "
A6-A9. บังคับขอบเขต 3 สำหรับแบบสอบถาม/กิจกรรม
";

ck('A6 ค่าคงที่ INDIRECT_SCOPE = 3', INDIRECT_SCOPE === 3, 'ได้ ' . INDIRECT_SCOPE);

// หมวดของขอบเขต 1 และ 2 ต้องถูกปฏิเสธ แม้ POST ส่งมาตรง ๆ (ไม่ใช่แค่ซ่อนใน dropdown)
$rejected = $accepted = [];
foreach ($groups as $g) {
    try {
        resolve_admin_g($pdo, (int)$g['id'], 0, INDIRECT_SCOPE);
        $accepted[] = (int)$g['scope'];
    } catch (Exception $e) {
        $rejected[] = (int)$g['scope'];
    }
}
ck('A7 หมวดขอบเขต 1/2 ถูกปฏิเสธทั้งหมด',
    count(array_filter($rejected, fn($s) => $s === 3)) === 0 && count($rejected) > 0,
    'ปฏิเสธขอบเขต: ' . implode(',', $rejected));
ck('A8 หมวดขอบเขต 3 ผ่านทั้งหมด',
    count($accepted) > 0 && count(array_filter($accepted, fn($s) => $s !== 3)) === 0,
    'ผ่านขอบเขต: ' . implode(',', $accepted));

// ไม่ส่ง group_id → ต้องถอยไปหมวดแรกของขอบเขต 3 ไม่ใช่ขอบเขตที่ส่งมา
[$gid, $sc] = resolve_admin_g($pdo, 0, 1, INDIRECT_SCOPE);
ck('A9 ไม่ส่ง group_id + บังคับขอบเขต 3 → ได้หมวดของขอบเขต 3 (ไม่ใช่ 1)', $sc === 3, "ได้ scope $sc");

// dropdown ต้องเหลือเฉพาะตัวเลือกขอบเขต 3
$opt3 = admin_g_options($groups, INDIRECT_SCOPE);
$optAll = admin_g_options($groups);
ck('A10 admin_g_options กรองเหลือเฉพาะขอบเขต 3',
    count($opt3) > 0 && count($opt3) < count($optAll)
        && count(array_filter($opt3, fn($o) => !str_starts_with($o['label'], 'ขอบเขต 3'))) === 0,
    count($opt3) . '/' . count($optAll) . ' ตัวเลือก');

// ═══ B. ghg_scope_item_breakdown() ══════════════════════════
echo "\nB. การแยกหมวดในโดนัทรายงานคณบดี (ghg_scope_item_breakdown)\n";

// ข้อมูลจำลอง: ดีเซลชื่อเดียวกัน อยู่ 3 หมวดของขอบเขต 1 + ไฟฟ้าหมวดเดียวในขอบเขต 2
$detail = [
    ['scope' => 1, 'activity_type' => 'การเผาไหม้อยู่กับที่',   'name_tiem' => 'ดีเซล (Diesel)', 'emission' => 1.0],
    ['scope' => 1, 'activity_type' => 'การเผาไหม้เคลื่อนที่ On Road',  'name_tiem' => 'ดีเซล (Diesel)', 'emission' => 2.0],
    ['scope' => 1, 'activity_type' => 'การเผาไหม้เคลื่อนที่ Off Road', 'name_tiem' => 'ดีเซล (Diesel)', 'emission' => 3.0],
    ['scope' => 2, 'activity_type' => 'การใช้ไฟฟ้า', 'name_tiem' => 'ไฟฟ้าสายส่ง', 'emission' => 5.0],
    ['scope' => 2, 'activity_type' => 'การใช้ไฟฟ้า', 'name_tiem' => 'ไฟฟ้าสายส่ง', 'emission' => 1.0],
];
$bd = ghg_scope_item_breakdown($detail);

ck('B1 ดีเซล 3 หมวด แยกเป็น 3 ชิ้น ไม่ยุบรวม', count($bd[1]['items']) === 3,
    'ได้ ' . count($bd[1]['items']) . ' ชิ้น: ' . implode(', ', array_column($bd[1]['items'], 'name')));

$names = array_column($bd[1]['items'], 'name');
ck('B2 ป้ายชิ้นบอกหมวดย่อยกำกับ', count(array_filter($names, fn($n) => str_contains($n, ' · '))) === 3,
    implode(' | ', $names));

// B2b — ชื่อหมวดในป้ายต้องถูกย่อ (ตัดวงเล็บอังกฤษออก) ไม่งั้นป้ายโดนัทยาวจนอ่านไม่ออก
ck('B2b ตัดวงเล็บอังกฤษท้ายชื่อหมวดออกจากป้าย',
    ghg_group_label('การเผาไหม้ที่มีการเคลื่อนที่ Off Road (Mobile Combustion : Off Road)')
        === 'การเผาไหม้ที่มีการเคลื่อนที่ Off Road',
    ghg_group_label('การเผาไหม้ที่มีการเคลื่อนที่ Off Road (Mobile Combustion : Off Road)'));
ck('B2c ชื่อหมวดที่ไม่มีวงเล็บ ต้องไม่ถูกแตะ',
    ghg_group_label('การใช้ไฟฟ้า') === 'การใช้ไฟฟ้า');

ck('B3 ยอดรวมขอบเขต 1 ไม่เปลี่ยน (1+2+3)', abs($bd[1]['total'] - 6.0) < 1e-9, "ได้ {$bd[1]['total']}");

ck('B4 ชื่อซ้ำในหมวดเดียวกัน ยังยุบรวมเหมือนเดิม', count($bd[2]['items']) === 1,
    'ได้ ' . count($bd[2]['items']) . ' ชิ้น');
ck('B5 ชื่อที่ไม่ซ้ำข้ามหมวด ไม่ต้องต่อท้ายชื่อหมวด (ป้ายไม่ยาวเกินจำเป็น)',
    ($bd[2]['items'][0]['name'] ?? '') === 'ไฟฟ้าสายส่ง', $bd[2]['items'][0]['name'] ?? '(ว่าง)');
ck('B6 ยอดรวมขอบเขต 2 ไม่เปลี่ยน (5+1)', abs($bd[2]['total'] - 6.0) < 1e-9, "ได้ {$bd[2]['total']}");

// B7 — ข้อมูลจริงจากฐานข้อมูล: ยอดรวมของโดนัทต้องเท่ากับผลรวม detail เสมอ
$deans = $pdo->query("SELECT DISTINCT Affiliation FROM users WHERE role='dean' AND Affiliation > 0")->fetchAll();
$years = ghg_years($pdo);
$checked = 0;
foreach ($deans as $d) {
    foreach ($years as $y) {
        $det = ghg_affil_detail($pdo, (int)$d['Affiliation'], (int)$y['year_id']);
        if (!$det) continue;
        $b   = ghg_scope_item_breakdown($det);
        $sum = array_sum(array_map(fn($r) => (float)$r['emission'], $det));
        $tot = $b[1]['total'] + $b[2]['total'] + $b[3]['total'];
        ck("B7 คณะ {$d['Affiliation']} ปี {$y['year']} ยอดโดนัท = ยอด detail",
            abs($tot - $sum) < 1e-6, sprintf('โดนัท=%.6f detail=%.6f', $tot, $sum));
        $checked++;
    }
}
ck('B7x ได้ตรวจกับข้อมูลจริงอย่างน้อย 1 เคส', $checked > 0, 'ไม่มีข้อมูลให้ตรวจ');

echo "\n─────────────────────────\nผ่าน $pass | ไม่ผ่าน $fail\n";
exit($fail > 0 ? 1 : 0);
