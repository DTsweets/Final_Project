<?php
/**
 * Unit Test — logic รายงาน GHG ของ role dean (อ่านอย่างเดียว ไม่แตะข้อมูล)
 * รัน: C:\xampp\php\php.exe tests\dean_ghg_report_test.php
 *
 * ล็อกกฎ 3 ข้อ:
 *   A. กราฟแนวโน้มรายปี ต้องใช้นิยามเดียวกับโดนัท/Net (officer + กิจกรรม)
 *   B. มุมมองทั้งระบบต้องไม่นับกิจกรรมซ้ำ
 *   C. ตัวเลขบนการ์ด ต้องเท่ากับผลรวมของตารางที่อยู่ใต้การ์ดนั้น
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';

$pdo = getDB();
$pass = $fail = 0;

function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
function feq($a, $b, float $eps = 1e-6): bool { return abs((float)$a - (float)$b) < $eps; }

$years = ghg_years($pdo);
$deans = $pdo->query("SELECT id, username, Affiliation FROM users WHERE role = 'dean'")->fetchAll();
echo "dean=" . count($deans) . " ปี=" . count($years) . "\n\n";

$cases_with_event = 0;   // นับเคสที่มีกิจกรรมจริง — กันเทสต์ผ่านแบบว่างเปล่า

foreach ($deans as $d) {
    $aff = (int) $d['Affiliation'];
    foreach ($years as $y) {
        $yid = (int) $y['year_id'];
        echo "--- {$d['username']} (คณะ $aff) ปี {$y['year']} ---\n";

        // จำลองการคำนวณของหน้า dean/reports.php (dean ถูกบังคับ view=faculty)
        $scope   = ghg_scope_totals($pdo, $yid, $aff);
        $total   = $scope[1] + $scope[2] + $scope[3];
        $evrows  = event_emission_list($pdo, $yid, $aff);
        $rmrows  = removal_activity_list($pdo, $yid, $aff);
        $removal = removal_activity_total($pdo, $yid, $aff);

        $gross = $scope;
        $event_total = 0.0;
        foreach ($evrows as $r) {
            $event_total += (float) $r['emission'];
            $s = (int) $r['scope'];
            if (isset($gross[$s])) $gross[$s] += (float) $r['emission'];
        }
        // แบบสอบถามของคณะ นับเฉพาะขอบเขต 3 (คำนวณอิสระจาก SQL ตรง ไม่ผ่าน survey_emission_list)
        $svq = $pdo->prepare("SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id
                              JOIN admin_g ag ON ag.id = ai.scope WHERE ui.year_id = ? AND ui.affiliation_id = ? AND ui.source = 'survey' AND ag.scope = 3");
        $svq->execute([$yid, $aff]);
        $gross[3] += (float) $svq->fetchColumn();
        $gross_total = $gross[1] + $gross[2] + $gross[3];
        if ($event_total > 0) $cases_with_event++;

        // A0 — พิสูจน์ว่านิยาม 2 แบบ "ต่างกันจริง" เมื่อคณะมีกิจกรรม
        //      ถ้าไม่ต่าง แปลว่าเคสนี้ทดสอบบั๊กไม่ได้ (ไม่มีกิจกรรม) → ข้าม
        if ($event_total > 0) {
            ck('A0 ghg_total (แบบเก่า) < gross จริง — พิสูจน์ว่ากราฟเดิมต่ำผิด',
                ghg_total($pdo, $yid, $aff) < $gross_total - 1e-9,
                sprintf('officer=%.6f  gross=%.6f', ghg_total($pdo, $yid, $aff), $gross_total));
        }

        // A — แท่งกราฟของปีนี้ ต้องเท่ากับ "ปล่อยทั้งหมด" ที่โชว์ใต้การ์ด Net  (bug เดิม: fail)
        ck('A1 ghg_gross_total == gross ที่หน้าเว็บคำนวณ',
            feq(ghg_gross_total($pdo, $yid, $aff), $gross_total),
            sprintf('trend=%.6f  gross=%.6f  (ยอดกิจกรรม %.6f)',
                    ghg_gross_total($pdo, $yid, $aff), $gross_total, $event_total));

        // A2 — ฟังก์ชันยอดกิจกรรม ต้องตรงกับผลรวมตารางกิจกรรม (กันคนละแหล่งข้อมูล)
        ck('A2 ghg_event_total == SUM(ตารางกิจกรรม)',
            feq(ghg_event_total($pdo, $yid, $aff), $event_total),
            sprintf('%.6f vs %.6f', ghg_event_total($pdo, $yid, $aff), $event_total));

        // C1 — การ์ด "การปล่อยจากการดำเนินงาน" == ผลรวมตารางที่ 1
        $sumDetail = 0.0;
        foreach (ghg_affil_detail($pdo, $aff, $yid) as $r) $sumDetail += (float) $r['emission'];
        ck('C1 การ์ดดำเนินงาน == SUM(ตารางดำเนินงาน)', feq($total, $sumDetail),
            sprintf('%.6f vs %.6f', $total, $sumDetail));

        // C2 — การ์ด "ดูดกลับ" == ผลรวมตารางดูดกลับ
        $sumRm = 0.0;
        foreach ($rmrows as $r) $sumRm += (float) $r['emission'];
        ck('C2 การ์ดดูดกลับ == SUM(ตารางดูดกลับ)', feq($removal, $sumRm),
            sprintf('%.6f vs %.6f', $removal, $sumRm));

        // C3 — ไม่มีข้อมูลคณะอื่นหลุดเข้ามา (dean เห็นได้เฉพาะคณะตัวเอง)
        $leak = 0;
        foreach (array_merge($evrows, $rmrows) as $r) if ((int) $r['affil_id'] !== $aff) $leak++;
        ck('C3 ไม่มีข้อมูลข้ามคณะ', $leak === 0, "หลุดมา $leak แถว");

        // C4 — scope ของแถวกิจกรรมต้องอยู่ใน 1..3 ไม่งั้นยอดหายจากโดนัทเงียบๆ
        $bad = 0;
        foreach ($evrows as $r) if (!in_array((int) $r['scope'], [1, 2, 3], true)) $bad++;
        ck('C4 scope กิจกรรมอยู่ใน 1-3', $bad === 0, "นอกช่วง $bad แถว");
    }
}

// B — มุมมองทั้งระบบต้องไม่นับกิจกรรมซ้ำ (ghg_total(null) รวม source='event' มาแล้ว)
echo "\n--- มุมมองทั้งระบบ (admin) ---\n";
foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    ck("B1 ปี {$y['year']}: gross(ทั้งระบบ) == ghg_total(ทั้งระบบ) ไม่บวกซ้ำ",
        feq(ghg_gross_total($pdo, $yid, null), ghg_total($pdo, $yid, null)));
}

// D — กราฟแท่งแนวนอนรายขอบเขต (แทนโดนัท + กราฟแนวโน้มที่เอาออก)
echo "
--- กราฟแท่งแนวนอน: ghg_scope_bar_percents() ---
";
$bp = ghg_scope_bar_percents([1 => 10.0, 2 => 5.0, 3 => 0.0]);
ck('D1 ขอบเขตที่มากสุดได้ 100%', feq($bp[1], 100.0));
ck('D1b สัดส่วนถูกตามสัดส่วนจริง', feq($bp[2], 50.0));
ck('D1c ค่า 0 ได้แท่งยาว 0', feq($bp[3], 0.0));
ck('D1d ไม่มีข้อมูลเลย → 0 ทั้งหมด (ไม่หารด้วยศูนย์)',
    ghg_scope_bar_percents([1 => 0, 2 => 0, 3 => 0]) === [1 => 0.0, 2 => 0.0, 3 => 0.0]);
ck('D1e ค่าทุกแท่งอยู่ในช่วง 0-100', (function () {
    foreach ([[3, 1, 2], [0.001, 999, 0], [7, 7, 7]] as $t) {
        foreach (ghg_scope_bar_percents([1 => $t[0], 2 => $t[1], 3 => $t[2]]) as $v) {
            if ($v < 0 || $v > 100) return false;
        }
    }
    return true;
})());

// D1f–D1i — สเกลคงที่ (มุมมองทั้งมหาวิทยาลัย)
$bs = ghg_scope_bar_percents([1 => 10490.86, 2 => 2235.77, 3 => 4475.17], GHG_SYSTEM_SCALE_MAX);
ck('D1f สเกลคงที่ 15,000: ความยาว = ค่า ÷ 15,000', feq($bs[1], 10490.86 / 150) && feq($bs[2], 2235.77 / 150) && feq($bs[3], 4475.17 / 150));
ck('D1g ค่าเกินสเกล → ขยายตามค่าจริง แท่งไม่ล้น 100%', (function () {
    $o = ghg_scope_bar_percents([1 => 30000.0, 2 => 15000.0, 3 => 0.0], 15000.0);
    return feq($o[1], 100.0) && feq($o[2], 50.0);
})());
ck('D1h ไม่ส่งสเกล → ทำงานแบบเดิม (เทียบค่าสูงสุด)', ghg_scope_bar_percents([1 => 10.0, 2 => 5.0, 3 => 0.0]) === ghg_scope_bar_percents([1 => 10.0, 2 => 5.0, 3 => 0.0], 0.0)
    && feq(ghg_scope_bar_percents([1 => 10.0, 2 => 5.0, 3 => 0.0])[1], 100.0));
ck('D1i ค่าคงที่ GHG_SYSTEM_SCALE_MAX = 15000', GHG_SYSTEM_SCALE_MAX === 15000.0);

// D1j–D1m — เพดานสเกลแบบขั้น + เส้นบอกสเกล
ck('D1j ghg_scale_ceiling(): 15,000 → ขยับทีละ 10,000', ghg_scale_ceiling(0.0) === 15000.0 && ghg_scale_ceiling(10490.86) === 15000.0
    && ghg_scale_ceiling(15000.0) === 15000.0 && ghg_scale_ceiling(15000.01) === 25000.0 && ghg_scale_ceiling(18200.0) === 25000.0
    && ghg_scale_ceiling(25000.0) === 25000.0 && ghg_scale_ceiling(25001.0) === 35000.0);
ck('D1k ghg_scale_ticks(15000): 0 / 5,000 / 10,000 / 15,000 ที่ 0 / 33.3 / 66.7 / 100%', (function () {
    $t = ghg_scale_ticks(15000.0);
    return array_column($t, 'value') === [0.0, 5000.0, 10000.0, 15000.0]
        && feq($t[1]['pct'], 100 / 3) && feq($t[2]['pct'], 200 / 3) && feq($t[3]['pct'], 100.0);
})());
ck('D1l เส้นเกิน 7 → เว้นระยะกว้างขึ้น และเส้นบนสุดคือเพดานเสมอ', (function () {
    $a = array_column(ghg_scale_ticks(35000.0), 'value');
    $b = array_column(ghg_scale_ticks(45000.0), 'value');
    return $a === [0.0, 5000.0, 10000.0, 15000.0, 20000.0, 25000.0, 30000.0, 35000.0]
        && $b === [0.0, 10000.0, 20000.0, 30000.0, 40000.0, 45000.0];
})());
ck('D1m เพดาน 0 → ไม่มีเส้น', ghg_scale_ticks(0.0) === []);

// F — มุมมองทั้งระบบ: แถว "รวม" ต้องเท่ากับผลบวกในคอลัมน์ที่ตาเห็น
//     ghg_by_affiliation() นับเฉพาะ source='officer' ส่วน ghg_total(null) รวมทุก source
//     ถ้าเอา ghg_total(null) มาวางเป็นแถวรวม ตัวเลขจะไม่ตรงกับที่บวกเองได้
echo "\n--- มุมมองทั้งระบบ: แถวรวมของตาราง ---\n";
foreach ($years as $y) {
    $yid  = (int) $y['year_id'];
    $rows = ghg_by_affiliation($pdo, $yid);
    $sum  = 0.0;
    foreach ($rows as $r) $sum += (float) $r['total_emission'];
    ck("F1 ปี {$y['year']}: ghg_affil_sum() == ผลบวกคอลัมน์", feq(ghg_affil_sum($rows), $sum),
        sprintf('%.6f vs %.6f', ghg_affil_sum($rows), $sum));
}
// F2/F3 — ไฟล์ export/print ต้องไม่เอา $total (ทุก source) มาวางเป็นแถวรวมของตารางรายคณะ
foreach (['export_report.php', 'print' => 'report_print.php'] as $f) {
    $s = file_get_contents(__DIR__ . '/../dean/' . $f);
    ck("F2 $f แถวรวมใช้ \$rows_total ไม่ใช่ \$total",
        strpos($s, '$rows_total') !== false && !preg_match('/รวมทั้งระบบ.*\$total/s', $s));
    // F3 — Net ต้องมาจาก ghg_report_summary() ที่เดียว (เดิมเขียนทับตัวเอง / คำนวณซ้ำหลายไฟล์)
    ck("F3 $f ใช้ Net จาก ghg_report_summary() ไม่คำนวณเอง",
        strpos($s, 'ghg_report_summary(') !== false && preg_match_all('/\$net\s*=(?!=)/', $s) <= 1
        && strpos($s, '$gross_total - $removal') === false,
        'พบการกำหนด $net ' . preg_match_all('/\$net\s*=(?!=)/', $s) . ' ครั้ง');
}

// G — dean ที่ยังไม่ผูกคณะ ต้องเห็นคำเตือน ไม่ใช่รายงานว่างเปล่าเงียบๆ
echo "\n--- dean ที่ไม่มีสังกัด ---\n";
$s = file_get_contents(__DIR__ . '/../dean/reports.php');
ck('G1 reports.php มีคำเตือนเมื่อ affiliation_id = 0', strpos($s, '$affil_missing') !== false);
$noAff = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='dean' AND (Affiliation IS NULL OR Affiliation = 0)")->fetchColumn();
echo "  (ข้อมูลจริง: dean ที่ยังไม่ผูกคณะ = $noAff คน)\n";

// H — โดนัทแยกราย Scope (แทนตารางการดำเนินงานเดิม)
echo "\n--- โดนัทแยกราย Scope: ghg_scope_item_breakdown() ---\n";

// H1-H3 ด้วยข้อมูลสังเคราะห์ (คุมเคสขอบได้ ไม่ขึ้นกับข้อมูลใน DB)
$fake = [
    ['scope' => 1, 'name_tiem' => 'ดีเซล',   'emission' => 5.0],
    ['scope' => 1, 'name_tiem' => 'ดีเซล',   'emission' => 3.0],   // ชื่อซ้ำ -> ต้องรวมกัน = 8
    ['scope' => 1, 'name_tiem' => 'ก๊าซ',    'emission' => 1.0],
    ['scope' => 2, 'name_tiem' => 'ไฟฟ้า',   'emission' => 4.0],
    ['scope' => 3, 'name_tiem' => 'ศูนย์',   'emission' => 0.0],   // 0 -> ต้องไม่กลายเป็นชิ้นโดนัท
];
$b = ghg_scope_item_breakdown($fake);
ck('H1 รวมรายการชื่อซ้ำเข้าด้วยกัน', feq($b[1]['items'][0]['value'], 8.0),
    'ดีเซล = ' . ($b[1]['items'][0]['value'] ?? 'null'));
ck('H1b เรียงมาก -> น้อย', $b[1]['items'][0]['name'] === 'ดีเซล' && $b[1]['items'][1]['name'] === 'ก๊าซ');
ck('H1c ยอดรวมราย scope ถูกต้อง', feq($b[1]['total'], 9.0) && feq($b[2]['total'], 4.0));
ck('H1d ตัดรายการยอด 0 ออกจากชิ้นโดนัท', count($b[3]['items']) === 0);
ck('H1e คืนครบ 3 scope เสมอ แม้ scope นั้นไม่มีข้อมูล', array_keys($b) === [1, 2, 3]);

// H2 — ยุบรายการเกิน topN เป็น "อื่นๆ" โดยยอดรวมต้องไม่หาย
$many = [];
for ($i = 1; $i <= 12; $i++) $many[] = ['scope' => 1, 'name_tiem' => "item$i", 'emission' => (float) $i];
$b2 = ghg_scope_item_breakdown($many, 8);
$sumSlices = 0.0;
foreach ($b2[1]['items'] as $it) $sumSlices += $it['value'];
ck('H2 ชิ้นโดนัทไม่เกิน topN+1', count($b2[1]['items']) === 9, 'ได้ ' . count($b2[1]['items']) . ' ชิ้น');
ck('H2b ผลบวกทุกชิ้น == ยอดรวมจริง (ยอดไม่หายตอนยุบ)', feq($sumSlices, array_sum(range(1, 12))),
    sprintf('%.2f vs %.2f', $sumSlices, array_sum(range(1, 12))));
ck('H2c ชิ้นสุดท้ายคือ "อื่นๆ" พร้อมจำนวนรายการ', str_starts_with($b2[1]['items'][8]['name'], 'อื่นๆ (4 รายการ)'));
ck('H2d ไม่ยุบถ้ารายการไม่เกิน topN', count(ghg_scope_item_breakdown($many, 20)[1]['items']) === 12);
// H2e — เกิน topN แค่ 1 รายการ: แสดงรายการนั้นตรง ๆ ไม่ขึ้น "อื่นๆ (1 รายการ)"
$b9 = ghg_scope_item_breakdown(array_slice($many, 0, 9), 8)[1]['items'];
ck('H2e เกิน topN 1 รายการ → ไม่ยุบเป็น "อื่นๆ (1 รายการ)"', count($b9) === 9
    && !array_filter($b9, fn($it) => str_starts_with($it['name'], 'อื่นๆ')));

// H7 — กิจกรรม/แบบสอบถาม เป็นชิ้นต่อท้ายโดนัทขอบเขต 3 อย่างละชิ้น
$ops = [];
for ($i = 1; $i <= 10; $i++) $ops[] = ['scope' => 3, 'name_tiem' => "op$i", 'emission' => (float) $i];
$ops[] = ['scope' => 1, 'name_tiem' => 'ดีเซล', 'emission' => 2.0];
$evR = [['scope' => 3, 'emission' => 0.25], ['scope' => 3, 'emission' => 0.05]];
$svR = [['scope' => 3, 'emission' => 0.1]];
$b3  = ghg_scope_item_breakdown($ops, 8, $evR, $svR);
$tail = array_slice($b3[3]['items'], -2);
ck('H7 ขอบเขต 3: ต่อท้ายชิ้นกิจกรรมแล้วแบบสอบถาม ยอดรวมแหล่งละก้อน',
    array_column($tail, 'name') === ['กิจกรรมที่คณะจัด', 'แบบสอบถาม'] && array_column($tail, 'source') === ['event', 'survey']
    && feq($tail[0]['value'], 0.3) && feq($tail[1]['value'], 0.1));
ck('H7b ชิ้นต่อท้ายไม่ถูกยุบเป็น "อื่นๆ" (การดำเนินงาน 8 + อื่นๆ 1 + ต่อท้าย 2)', count($b3[3]['items']) === 11
    && str_starts_with($b3[3]['items'][8]['name'], 'อื่นๆ (2 รายการ)'));
ck('H7c ยอดรวมขอบเขต 3 = การดำเนินงาน + กิจกรรม + แบบสอบถาม และผลบวกทุกชิ้นเท่ากัน',
    feq($b3[3]['total'], 55.4) && feq(array_sum(array_column($b3[3]['items'], 'value')), 55.4));
ck('H7d ขอบเขต 1/2 ไม่มีชิ้นกิจกรรม/แบบสอบถาม', !array_filter(array_merge($b3[1]['items'], $b3[2]['items']), fn($it) => isset($it['source'])));
ck('H7e ไม่ส่งกิจกรรม/แบบสอบถาม → ผลเหมือนเดิม', ghg_scope_item_breakdown($ops, 8) === ghg_scope_item_breakdown($ops, 8, [], [])
    && !array_filter(ghg_scope_item_breakdown($ops, 8)[3]['items'], fn($it) => isset($it['source'])));

// R — อันดับรายหน่วยงาน (มุมมองทั้งมหาวิทยาลัย)
echo "\n--- อันดับรายหน่วยงาน ---\n";
$rk = ghg_affiliation_ranking(
    [['affil_id' => 1, 'affiliation_item' => 'A', 'total_emission' => 10], ['affil_id' => 2, 'affiliation_item' => 'B', 'total_emission' => 30],
     ['affil_id' => 3, 'affiliation_item' => 'C', 'total_emission' => 0]],
    [2 => [1 => 15.0, 2 => 9.0, 3 => 6.0]]);
ck('R1 ตัดหน่วยงานที่ไม่มีข้อมูล + เรียงมาก → น้อย + อันดับ', array_column($rk, 'name') === ['B', 'A'] && array_column($rk, 'rank') === [1, 2]);
ck('R1b share = % ของทุกหน่วยงาน, bar เทียบหน่วยงานสูงสุด', feq($rk[0]['share'], 75) && feq($rk[1]['bar'], 100 / 3));
ck('R1c สัดส่วนขอบเขตในหน่วยงานรวม 100 (ไม่มีข้อมูลขอบเขต → 0)', feq(array_sum($rk[0]['scope_pct']), 100)
    && feq($rk[0]['scope_pct'][1], 50) && array_sum($rk[1]['scope_pct']) === 0.0);
ck('R1d ไม่มีข้อมูลเลย → []', ghg_affiliation_ranking([], []) === []);
foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    $sa  = ghg_scope_by_affiliation($pdo, $yid);
    $ok  = true;
    foreach (ghg_by_affiliation($pdo, $yid) as $r) {
        $v = $sa[(int) $r['affil_id']] ?? [1 => 0, 2 => 0, 3 => 0];
        if (!feq($v[1] + $v[2] + $v[3], $r['total_emission'])) { $ok = false; break; }
    }
    ck("R2 ปี {$y['year']}: ผลรวมรายขอบเขตของแต่ละหน่วยงาน == ghg_by_affiliation()", $ok);
}

// H3 — ข้อมูลจริง: ยอดรวมของโดนัทต้องเท่ากับยอดที่การ์ด/ตารางเดิมเคยแสดง
foreach ($deans as $d) {
    $aff = (int) $d['Affiliation'];
    foreach ($years as $y) {
        $yid    = (int) $y['year_id'];
        $detail = ghg_affil_detail($pdo, $aff, $yid);
        if (!$detail) continue;
        $bd  = ghg_scope_item_breakdown($detail);
        $sc  = ghg_scope_totals($pdo, $yid, $aff);
        $okS = feq($bd[1]['total'], $sc[1]) && feq($bd[2]['total'], $sc[2]) && feq($bd[3]['total'], $sc[3]);
        ck("H3 {$d['username']} ปี {$y['year']}: ยอดโดนัทราย scope == ghg_scope_totals()", $okS,
            sprintf('S1 %.6f/%.6f  S2 %.6f/%.6f  S3 %.6f/%.6f',
                $bd[1]['total'], $sc[1], $bd[2]['total'], $sc[2], $bd[3]['total'], $sc[3]));
    }
}

// H4 — หน้าเว็บ: ตารางเดิมถูกลบ และมีโดนัทครบ 3 ตัว
$s = file_get_contents(__DIR__ . '/../dean/reports.php');
ck('H4 ลบตาราง "การปล่อยจากการดำเนินงานของคณะ" แล้ว',
    strpos($s, 'การปล่อยจากการดำเนินงานของคณะ') === false);
ck('H4c หน้าเว็บเรียก ghg_scope_item_breakdown()', strpos($s, 'ghg_scope_item_breakdown(') !== false);

// H5 — เรนเดอร์หน้าจริงในโหมด CLI แล้วตรวจ HTML ที่ออกมา (แข็งแรงกว่าเช็คซอร์ส)
$probe = sys_get_temp_dir() . '/dean_render_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["view" => $argv[2], "year" => (int)$argv[3]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>$argv[1],"affiliation_id"=>(int)$argv[4],"affiliation_name"=>"คณะทดสอบ","last_activity"=>time()];
ob_start(); require $root . "/dean/reports.php"; echo ob_get_clean();
');
$deanAff = (int) ($deans[0]['Affiliation'] ?? 1);
$curYear = (int) ($years[0]['year_id'] ?? 0);
$php = PHP_BINARY;
$html = shell_exec(sprintf('%s %s dean faculty %d %d 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $curYear, $deanAff));

ck('H5 หน้าเรนเดอร์ได้ ไม่มี PHP error', $html && stripos($html, 'Fatal error') === false && stripos($html, 'Warning:') === false,
    substr((string) $html, 0, 200));
$nCanvas = substr_count((string) $html, 'id="scopeItemDonut');
ck('H5b HTML มี canvas โดนัทครบ 3 ตัว', $nCanvas === 3, "พบ $nCanvas ตัว");
ck('H5c ไม่มีตารางรายการเดิมใน HTML แล้ว',
    strpos((string) $html, 'การปล่อยจากการดำเนินงานของคณะ') === false);
ck('H5d ส่งข้อมูลโดนัทไปให้ JS จริง (__SCOPE_ITEMS)',
    strpos((string) $html, '__SCOPE_ITEMS') !== false);

// H6 — หน้าจริง: ยอดกลางโดนัท == ยอดขอบเขตบนการ์ด (รวมกิจกรรม/แบบสอบถามแล้ว) และชิ้นต่อท้ายใช้สีคงที่
$sumH6 = ghg_report_summary($pdo, $curYear, $deanAff);
ck('H6 เคสทดสอบมีทั้งกิจกรรมและแบบสอบถาม (ไม่ใช่ผ่านเพราะว่าง)', $sumH6['event_total'] > 0 && $sumH6['survey_total'] > 0);
ck('H6b ยอดกลางโดนัททั้ง 3 ขอบเขต == ยอดขอบเขตของ ghg_report_summary()', (function () use ($html, $sumH6) {
    foreach ([1, 2, 3] as $sc) {
        if (!preg_match('/id="scopeItemDonut' . $sc . '"[^>]*data-center="([^"]+)"/', (string) $html, $m)) return false;
        if ($m[1] !== number_format($sumH6['scope'][$sc], 2, '.', ',')) return false;
    }
    return true;
})());
ck('H6c ข้อมูลโดนัทขอบเขต 3 มีชิ้นกิจกรรม (#F59E0B) และแบบสอบถาม (#14B8A6)', (function () use ($html, $sumH6) {
    if (!preg_match('/window\.__SCOPE_ITEMS = (\{.*?\});/s', (string) $html, $m)) return false;
    $d = json_decode($m[1], true) ?: [];
    $by = array_column($d[3] ?? [], null, 'label');
    return ($by['กิจกรรมที่คณะจัด']['color'] ?? '') === '#F59E0B' && feq($by['กิจกรรมที่คณะจัด']['value'], $sumH6['event_total'])
        && ($by['แบบสอบถาม']['color'] ?? '') === '#14B8A6' && feq($by['แบบสอบถาม']['value'], $sumH6['survey_total']);
})());

// I4 — HTML มุมมองคณะ: เอาส่วน "กิจกรรมที่คณะจัด" ออกแล้ว (ยอดกิจกรรมยังรวมอยู่ในการ์ดสรุป)
// ชื่อกิจกรรมทั้งหมด (ปล่อย + ดูดกลับ) ของคณะในปีนี้ — อ่านจากรายการตรง ๆ
$evNames = array_unique(array_merge(
    array_column(event_emission_list($pdo, $curYear, $deanAff), 'event_name'),
    array_column(removal_activity_list($pdo, $curYear, $deanAff), 'event_name')
));
ck('I4 ไม่มีตารางกิจกรรมในหน้าเว็บ', strpos((string) $html, 'evc-table') === false
    && strpos((string) $html, 'เรียงตามวันที่จัด') === false);
ck('I4b ไม่มีชื่อกิจกรรมรายการใดโผล่ในหน้า', (function () use ($html, $evNames) {
    foreach ($evNames as $name) if (strpos((string) $html, htmlspecialchars((string) $name)) !== false) return false;
    return true;
})());
ck('I4c ยังมีข้อมูลกิจกรรมให้ทดสอบ (ไม่ใช่ผ่านเพราะว่าง)', count($evNames) > 0);
$sumI = ghg_report_summary($pdo, $curYear, $deanAff);
ck('I4d ยอดกิจกรรมยังแสดงในการ์ดการปล่อยทั้งหมด',
    strpos((string) $html, '<span>กิจกรรม <b>' . number_format($sumI['event_total'], 2) . '</b></span>') !== false);

// D2 — HTML: มีแท่งแนวนอนครบ 3 ขอบเขต และไม่เหลือกราฟเก่า
ck('D2 มีแถวแท่งครบ 3 ขอบเขต', substr_count((string) $html, 'class="hbar-row"') === 3,
    'พบ ' . substr_count((string) $html, 'class="hbar-row"'));
ck('D2b เอากราฟแนวโน้มรายปีออกแล้ว',
    strpos((string) $html, 'yearBar') === false
    && strpos((string) $html, '__SERIES') === false
    && strpos((string) $html, 'แนวโน้มรายปี') === false);
ck('D2c เอาโดนัทรวมออกแล้ว (แทนด้วยแท่งแนวนอน)',
    strpos((string) $html, 'scopeDonut"') === false && strpos((string) $html, '__SCOPE =') === false);
ck('D2d ความกว้างแท่งอยู่ในช่วง 0-100%', (function () use ($html) {
    preg_match_all('/class="hbar-fill" style="--w:([0-9.]+)%;"/', $html, $m);
    if (count($m[1]) !== 3) return false;
    foreach ($m[1] as $w) if ((float) $w < 0 || (float) $w > 100) return false;
    return true;
})());

// D2e/D2f — สไตล์จัดวางอยู่ใน assets/css/reports.css ที่หน้าโหลดใน <head> (ไม่ใช่ inline style / <style> ใน <main>)
$rpCss = (string) file_get_contents(__DIR__ . '/../assets/css/reports.css');
ck('D2e แถวแท่งเป็น flex (reports.css) · หน้าโหลด reports.css · แถวแท่งไม่มี inline style',
    (bool) preg_match('/\.hbar-row \{ display: flex;/', $rpCss) && strpos((string) $html, 'assets/css/reports.css') !== false
    && preg_match_all('/class="hbar-row">/', (string) $html) === 3);
ck('D2f รางแท่งมีพื้นหลัง + ความสูง และแท่งใช้สี/ความกว้างจาก --sc / --w (reports.css)',
    (bool) preg_match('/\.hbar-track \{[^}]*height: 14px; background: #F3F1F6;/', $rpCss)
    && (bool) preg_match('/\.hbar-fill \{[^}]*width: var\(--w, 0%\); background: var\(--sc\);/', $rpCss)
    && preg_match_all('/class="hbar-track"><span class="hbar-fill" style="--w:[0-9.]+%;"><\/span><\/div>/', (string) $html) === 3);

// K — กราฟประวัติย้อนหลัง แยกรายขอบเขต
echo "
--- กราฟประวัติย้อนหลัง ---
";
foreach ($deans as $d) {
    $aff = (int) $d['Affiliation'];
    foreach ($years as $y) {
        $yid = (int) $y['year_id'];
        $gs  = ghg_gross_scope_totals($pdo, $yid, $aff);
        // K1 — ผลรวมรายขอบเขต ต้องเท่ากับยอดรวม gross (นิยามเดียวกัน)
        ck("K1 {$d['username']} ปี {$y['year']}: SUM(gross รายขอบเขต) == ghg_gross_total()",
            feq($gs[1] + $gs[2] + $gs[3], ghg_gross_total($pdo, $yid, $aff)),
            sprintf('%.6f vs %.6f', $gs[1] + $gs[2] + $gs[3], ghg_gross_total($pdo, $yid, $aff)));
    }
}
// K2 — มุมมองทั้งระบบต้องไม่นับกิจกรรมซ้ำ
foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    ck("K2 ปี {$y['year']}: ทั้งระบบไม่บวกกิจกรรมซ้ำ",
        ghg_gross_scope_totals($pdo, $yid, null) === ghg_scope_totals($pdo, $yid, null));
}
// K3 — ประวัติ: ครบทุกปี เรียงเก่า -> ใหม่
$hist = ghg_scope_history($pdo, $years, (int) $deans[0]['Affiliation']);
ck('K3 ประวัติครบทุกปี', count($hist) === count($years));
ck('K3b เรียงเก่า -> ใหม่ (กราฟไล่ซ้ายไปขวา)',
    array_column($hist, 'year') === array_map('strval', array_reverse(array_column($years, 'year'))),
    implode(',', array_column($hist, 'year')));
ck('K3c แต่ละปีมีครบ 3 ขอบเขต', (function () use ($hist) {
    foreach ($hist as $h) {
        if (!isset($h['s1'], $h['s2'], $h['s3'])) return false;
    }
    return true;
})());
// K3d — ตัวเลขในกราฟต้องตรงกับแท่งแนวนอนของปีที่เลือก
$curLabel = (string) $years[0]['year'];
foreach ($hist as $h) {
    if ($h['year'] === $curLabel) {
        $gsNow = ghg_gross_scope_totals($pdo, $curYear, (int) $deans[0]['Affiliation']);
        ck('K3d ปีล่าสุดในกราฟ == แท่งแนวนอนที่แสดงอยู่',
            feq($h['s1'], $gsNow[1]) && feq($h['s2'], $gsNow[2]) && feq($h['s3'], $gsNow[3]));
    }
}

// K4 — HTML มุมมองคณะ: กราฟประวัติ = ปีที่เลือก + ย้อนหลัง 1 ปี (เรียงเก่า -> ใหม่)
$histYears = ghg_history_years($years, $curYear, 1);
// มุมมองคณะวาดกราฟประวัติด้วย HTML แล้ว (canvas ถูกย่อจนตัวอักษรอ่านไม่ออก) — canvas ยังใช้ในมุมมองทั้งระบบ (K4e)
ck('K4 มีกราฟประวัติแบบ HTML', strpos((string) $html, 'class="rpf-hist"') !== false);
ck('K4b ส่ง __HISTORY = ปีที่เลือก + ย้อนหลัง 1 ปี',
    preg_match('/window\.__HISTORY = (\[.*?\]);/s', (string) $html, $mh) === 1
    && array_column(json_decode($mh[1], true) ?: [], 'year') === array_map('strval', array_reverse(array_column($histYears, 'year')))
    && count($histYears) === min(2, count($years)),
    ($mh[1] ?? '') . ' vs ' . implode(',', array_column($histYears, 'year')));
ck('K4c ghg-charts.js มี drawGhgGroupedBars',
    strpos((string) file_get_contents(__DIR__ . '/../assets/js/ghg-charts.js'), 'drawGhgGroupedBars') !== false);
ck('K4d มี legend ขอบเขตครบ 3 สี', (function () use ($html) {
    foreach (['#F97316', '#EC4899', '#3B82F6'] as $c) {
        if (!preg_match('/<span style="--sc:' . preg_quote($c, '/') . ';"><i><\/i>ขอบเขต \d<\/span>/', (string) $html)) return false;
    }
    return true;
})());

// J1 — คำว่า Scope ต้องถูกแทนด้วย ขอบเขต
ck('J1 badge ใช้ "ขอบเขต" แทน "Scope"',
    strpos((string) $html, 'ขอบเขต 1') !== false && !preg_match('/>\s*Scope\s*\d/', (string) $html));

// J2 — หน้า Dashboard ต้องใช้คำเดียวกับหน้ารายงาน (คณบดีสลับสองหน้านี้บ่อย)
$probeIdx = sys_get_temp_dir() . '/dean_index_probe.php';
file_put_contents($probeIdx, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["year" => (int)$argv[2]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>($argv[3] ?? "dean"),"affiliation_id"=>(int)$argv[1],"affiliation_name"=>"คณะทดสอบ","last_activity"=>time()];
ob_start(); require $root . "/dean/index.php"; echo ob_get_clean();
');
$htmlIdx = shell_exec(sprintf('%s %s %d %d 2>&1',
    escapeshellarg($php), escapeshellarg($probeIdx), $deanAff, $curYear));
ck('J2 dean/index.php เรนเดอร์ได้ ไม่มี PHP error',
    $htmlIdx && stripos($htmlIdx, 'Fatal error') === false, substr((string) $htmlIdx, 0, 200));
ck('J2b Dashboard ไม่เหลือคำว่า "Scope N" ที่ผู้ใช้เห็น', (function () use ($htmlIdx) {
    // นับเฉพาะข้อความที่ผู้ใช้เห็น: ในเนื้อ tag หรือใน label ของกราฟ
    return !preg_match('/>\s*Scope\s*\d/', (string) $htmlIdx)
        && !preg_match("/label\s*:\s*'Scope/", (string) $htmlIdx)
        && strpos((string) $htmlIdx, 'ดูรายละเอียด Scope') === false;
})());
ck('J2c Dashboard มี "ขอบเขต 1/2/3" ครบ', (function () use ($htmlIdx) {
    foreach ([1, 2, 3] as $s) if (strpos((string) $htmlIdx, 'ขอบเขต ' . $s) === false) return false;
    return true;
})());

// J3 — Dashboard ต้องนับแบบเดียวกับหน้ารายงาน (ปล่อยทั้งหมด = ดำเนินงาน + กิจกรรม)
//      เดิม Dashboard นับเฉพาะ officer แต่หน้ารายงานรวมกิจกรรม → คณบดีเห็นยอดขอบเขตไม่ตรงกันสองหน้า
$sumD = ghg_report_summary($pdo, $curYear, $deanAff);
$f2   = fn($v) => number_format((float) $v, 2);
ck('J3 เคสทดสอบมีกิจกรรมจริง (ไม่ใช่ผ่านเพราะยอดกิจกรรม = 0)', $sumD['event_total'] > 0);
// J3a–J3e, J3g, DB2–DB6d, DB8, DB9 (ตัวเลขบนการ์ด / หน้าต่างรายละเอียด / ปุ่ม) ย้ายไป tests/dean_dashboard_test.php แล้ว
//   เพราะ Dashboard คณบดีเปลี่ยนเป็นชุดเดียวกับ Dashboard admin (markup ad-, ข้อมูลหน้าต่างเป็น JSON #adData)
ck('J3f ไม่เหลือคำว่า "แยกจากยอดหลัก" และแถบกิจกรรมบอกว่านับรวมแล้ว',
    strpos((string) file_get_contents(__DIR__ . '/../dean/index.php'), 'แยกจากยอดหลัก') === false
    && strpos((string) $htmlIdx, 'นับรวมในยอดปล่อยทั้งหมดด้านบนแล้ว') !== false);
// DB — ฟังก์ชันที่ Dashboard คณบดีใช้ (ไม่ผูกกับ markup)
ck('DB1 ghg_item_fill() นับเฉพาะ officer, vol 0 ไม่นับว่ากรอก, ขอบเขตว่าง = 0/0', ghg_item_fill([
        ['scope' => 1, 'vol' => 5, 'source' => 'officer'], ['scope' => 1, 'vol' => 0, 'source' => 'officer'],
        ['scope' => 2, 'vol' => 3, 'source' => 'officer'], ['scope' => 1, 'vol' => 9, 'source' => 'event'],
        ['scope' => 9, 'vol' => 1, 'source' => 'officer'],
    ]) === [1 => ['filled' => 1, 'total' => 2], 2 => ['filled' => 1, 'total' => 1], 3 => ['filled' => 0, 'total' => 0]]);
ck('DB7 ghg_detail_collapse_events() รวมกิจกรรม/แบบสอบถามเป็นอย่างละแถว ยอดรวมเท่าเดิม', (function () {
    $in = [
        ['scope' => 1, 'name' => 'ดีเซล', 'vol' => 10, 'emission' => 1.5, 'source' => 'officer'],
        ['scope' => 3, 'name' => 'เดินทาง', 'vol' => 500, 'emission' => 0.4, 'source' => 'survey'],
        ['scope' => 1, 'name' => 'น้ำมัน', 'vol' => 100, 'emission' => 0.05, 'source' => 'event', 'event' => 'ปลูกป่า'],
        ['scope' => 3, 'name' => 'กระดาษ', 'vol' => 5, 'emission' => 0.02, 'source' => 'event', 'event' => 'ปลูกป่า'],
        ['scope' => 3, 'name' => 'ระยะทาง', 'vol' => 20, 'emission' => 0.1, 'source' => 'survey'],
    ];
    $out = ghg_detail_collapse_events($in);
    $only3 = ghg_detail_collapse_events(array_values(array_filter($in, fn($it) => $it['scope'] === 3)));
    return count($out) === 3 && $out[0] === $in[0]
        && $out[1]['source'] === 'event_total' && $out[1]['name'] === 'กิจกรรมที่คณะจัด (รวมทุกกิจกรรม)' && $out[1]['vol'] === null
        && feq($out[1]['emission'], 0.07) && $out[1]['scope'] === 0
        && $out[2]['source'] === 'survey_total' && $out[2]['name'] === 'แบบสอบถาม (รวมทุกชุด)' && feq($out[2]['emission'], 0.5) && $out[2]['scope'] === 3
        && count($only3) === 2 && $only3[0]['scope'] === 3 && feq($only3[0]['emission'], 0.02)
        && ghg_detail_collapse_events([$in[0]]) === [$in[0]];
})());


// มุมมองทั้งระบบต้องไม่มีโดนัทชุดนี้ (ไม่มีข้อมูลรายการของคณะ) และต้องไม่พัง
$htmlSys = shell_exec(sprintf('%s %s admin system %d %d 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $curYear, $deanAff));
ck('H5e มุมมองทั้งระบบเรนเดอร์ได้ และไม่มีโดนัทราย Scope',
    $htmlSys && stripos($htmlSys, 'Fatal error') === false && substr_count($htmlSys, 'id="scopeItemDonut') === 0);
// S — มุมมองทั้งมหาวิทยาลัยใช้เลย์เอาต์เดียวกับมุมมองคณะ (เรนเดอร์ในฐานะ dean)
$htmlSysD = (string) shell_exec(sprintf('%s %s dean system %d %d 2>&1', escapeshellarg($php), escapeshellarg($probe), $curYear, $deanAff));
$sumS     = ghg_report_summary($pdo, $curYear, null);
$rankS    = ghg_affiliation_ranking(ghg_by_affiliation($pdo, $curYear), ghg_scope_by_affiliation($pdo, $curYear));
ck('S1 ทั้งระบบ (dean) เรนเดอร์ได้ ไม่มี PHP error', $htmlSysD !== '' && stripos($htmlSysD, 'Fatal error') === false && stripos($htmlSysD, 'Warning:') === false);
ck('S2 KPI 3 ใบ ตัวเลขตรงกับ ghg_report_summary(null)', substr_count($htmlSysD, ' rpf-kpi oe-rise"') === 3
    && preg_match('#class="rp-gross" data-count="[0-9.]+">' . preg_quote(number_format($sumS['gross'], 2), '#') . '</b> <small>tCO₂e</small>#', $htmlSysD)
    && preg_match('#class="rp-removal" data-count="[0-9.]+">' . preg_quote(number_format($sumS['removal'], 2), '#') . '</b>#', $htmlSysD)
    && preg_match('#class="rp-net" data-count="[0-9.]+">' . preg_quote(number_format($sumS['net'], 2), '#') . '</b> <small>tCO₂e</small>#', $htmlSysD));
ck('S2b ดูดกลับแยกส่วนกลาง/กิจกรรม และรวมกันเท่ายอดดูดกลับ',
    strpos($htmlSysD, '<span>ส่วนกลาง <b>' . number_format(removal_central_total($pdo, $curYear), 2) . '</b></span><span>กิจกรรม <b>' . number_format(removal_activity_total($pdo, $curYear), 2) . '</b></span>') !== false
    && feq(removal_central_total($pdo, $curYear) + removal_activity_total($pdo, $curYear), $sumS['removal']));
ck('S3 แหล่งปล่อยตามขอบเขตครบทุกหมวด + ไม่มีทศนิยม 4 ตำแหน่งที่การ์ดขอบเขต', preg_match_all('/class="rpf-cat(?: is-zero)?"/', $htmlSysD) === (int) $pdo->query('SELECT COUNT(*) FROM admin_g')->fetchColumn()
    && !preg_match('/class="hbar-val"[^>]*>[0-9,]+\.\d{4}/', $htmlSysD));
ck('S4 กราฟประวัติเป็น HTML (ปีที่เลือก + ย้อนหลัง 1 ปี) ไม่มี canvas', strpos($htmlSysD, 'id="scopeHistory"') === false
    && preg_match_all('/class="rpf-hist-year(?: is-cur)?"/', $htmlSysD) === count(ghg_history_years($years, $curYear, 1)));
ck('S5 มุมมองทั้งมหาวิทยาลัยไม่มีการ์ดอันดับรายคณะ/หน่วยงานแล้ว (ย้ายไป Dashboard) ทั้ง dean และ admin · เนื้อหาหลักยังอยู่',
    strpos($htmlSysD, 'rpf-rank') === false && strpos($htmlSysD, 'อันดับการปล่อยรายคณะ/หน่วยงาน') === false
    && strpos((string) $htmlSys, 'rpf-rank') === false && strpos((string) $htmlSys, 'อันดับการปล่อยรายคณะ/หน่วยงาน') === false
    && strpos($htmlSysD, 'class="rpf-cat') !== false && strpos($htmlSysD, 'class="rpf-hist-year') !== false);
ck('S6 ป้ายหัวข้อใช้ชื่อเดียวกับแท็บ/PDF ("ทั้งมหาวิทยาลัย")', strpos($htmlSysD, 'ทั้งมหาวิทยาลัย (ทุกคณะ/หน่วยงาน)') !== false
    && strpos($htmlSysD, 'ทั้งระบบ (ทุกคณะ)') === false);
// S7 — มุมมองคณะบอกอันดับของคณะในมหาวิทยาลัย
$ownRk = array_values(array_filter($rankS, fn($r) => $r['affil_id'] === $deanAff));
ck('S7 มุมมองคณะแสดงอันดับของคณะ', !$ownRk || strpos((string) $html, 'อันดับ ' . $ownRk[0]['rank'] . ' จาก ' . count($rankS) . ' หน่วยงาน') !== false);
@unlink($probe);
@unlink($probeIdx);

// IN — บรรทัด "ในจำนวนนี้: กิจกรรม · แบบสอบถาม" ใต้แถบขอบเขต 3 (ทั้งสองมุมมอง)
$inLine = function (string $h): ?array {
    if (!preg_match('#class="rpf-indirect".*?กิจกรรม <b[^>]*>([0-9,.]+)</b>.*?แบบสอบถาม <b[^>]*>([0-9,.]+)</b>#su', $h, $m)) return null;
    return [$m[1], $m[2]];
};
$f2 = fn($v) => number_format((float) $v, 2);
ck('IN1 มุมมองคณะ: ตัวเลขตรง event_total / survey_total ของ ghg_report_summary()', (function () use ($html, $inLine, $f2, $pdo, $curYear, $deanAff) {
    $sm = ghg_report_summary($pdo, $curYear, $deanAff);
    return $inLine((string) $html) === [$f2($sm['event_total']), $f2($sm['survey_total'])] && $sm['survey_total'] > 0;
})());
ck('IN2 มุมมองทั้งมหาวิทยาลัย: ตัวเลขตรง ghg_indirect_source_totals()', (function () use ($htmlSys, $inLine, $f2, $pdo, $curYear) {
    $t = ghg_indirect_source_totals($pdo, $curYear);
    return $inLine((string) $htmlSys) === [$f2($t['event']), $f2($t['survey'])];
})());
ck('IN3 บรรทัดอยู่ใต้แถบขอบเขต 3 เท่านั้น (หลังป้ายขอบเขต 3 และมีบรรทัดเดียว)', (function () use ($html) {
    $h = (string) $html;
    return substr_count($h, 'class="rpf-indirect"') === 1
        && strpos($h, 'class="rpf-indirect"') > strpos($h, '>ขอบเขต 3</span>');
})());
ck('IN4 ghg_indirect_source_totals(): กิจกรรม == ghg_event_total() ทั้งระบบ และทุกปีไม่เกินยอดขอบเขต 3', (function () use ($pdo, $years) {
    foreach ($years as $y) {
        $yid = (int) $y['year_id'];
        $t = ghg_indirect_source_totals($pdo, $yid);
        if (!feq($t['event'], ghg_event_total($pdo, $yid, null))) return false;
        if ($t['event'] + $t['survey'] > ghg_scope_totals($pdo, $yid, null)[3] + 1e-6) return false;
    }
    return true;
})());

// SC — สเกลคงที่ 15,000 เฉพาะมุมมองทั้งมหาวิทยาลัย (แหล่งปล่อยตามขอบเขต + ประวัติ)
$widths = function (string $h): array {
    preg_match_all('#class="hbar-fill" style="--w:([0-9.]+)%;"#', $h, $m);
    return array_map('floatval', $m[1]);
};
$heights = function (string $h): array {
    preg_match_all('#class="rpf-hist-bar"[^>]*style="--h:([0-9.]+)%;"#', $h, $m);
    return array_map('floatval', $m[1]);
};
$sysHist = ghg_scope_history($pdo, ghg_history_years($years, $curYear, 1), null);
$sysMax  = 0.0;
foreach ($sysHist as $h) $sysMax = max($sysMax, $h['s1'], $h['s2'], $h['s3']);
$sysCeil = ghg_scale_ceiling($sysMax);
ck('SC1 ทั้งมหาวิทยาลัย: แถบขอบเขต = ยอด ÷ เพดานสเกล (ปัจจุบัน 15,000)', (function () use ($htmlSys, $widths, $pdo, $curYear, $sysCeil) {
    $g = ghg_report_summary($pdo, $curYear, null)['scope'];
    $w = $widths((string) $htmlSys);
    if (count($w) !== 3) return false;
    foreach ([1, 2, 3] as $i => $sc) if (abs($w[$i] - $g[$sc] / $sysCeil * 100) > 0.01) return false;
    return $sysCeil === 15000.0 && $g[1] < 15000;   // ยอดจริงยังไม่ถึงเพดาน → แถบบนสุดต้องไม่เต็ม 100%
})());
ck('SC2 ทั้งมหาวิทยาลัย: แท่งประวัติทุกปี = ยอด ÷ เพดานเดียวกับแถบขอบเขต', (function () use ($htmlSys, $heights, $sysHist, $sysCeil) {
    $exp = [];
    foreach ($sysHist as $b) foreach ([1, 2, 3] as $sc) $exp[] = $b['s' . $sc] / $sysCeil * 100;
    $got = $heights((string) $htmlSys);
    if (count($got) !== count($exp)) return false;
    foreach ($exp as $i => $v) if (abs($got[$i] - $v) > 0.01) return false;
    return true;
})());
$facHist = ghg_scope_history($pdo, ghg_history_years($years, $curYear, 1), $deanAff);
$facMax  = 0.0;
foreach ($facHist as $h) $facMax = max($facMax, $h['s1'], $h['s2'], $h['s3']);
$facScale = ghg_view_scale('faculty', $facMax);
ck('SC3 มุมมองคณะ: เพดาน 1,000 (ยอดยังไม่ถึง) แถบขอบเขต = ยอด ÷ 1,000 และแท่งประวัติใช้เพดานเดียวกัน', (function () use ($html, $widths, $heights, $pdo, $curYear, $deanAff, $facHist, $facScale) {
    $g = ghg_report_summary($pdo, $curYear, $deanAff)['scope'];
    $w = $widths((string) $html);
    if ($facScale['ceiling'] !== 1000.0 || count($w) !== 3) return false;
    foreach ([1, 2, 3] as $i => $sc) if (abs($w[$i] - $g[$sc] / 10) > 0.01) return false;
    $exp = [];
    foreach ($facHist as $b) foreach ([1, 2, 3] as $sc) $exp[] = $b['s' . $sc] / 10;
    $got = $heights((string) $html);
    if (count($got) !== count($exp)) return false;
    foreach ($exp as $i => $v) if (abs($got[$i] - $v) > 0.01) return false;
    return true;
})());

ck('SC4 ทั้งมหาวิทยาลัย: ป้ายสเกลตรง ghg_scale_ticks() และตำแหน่งถูก', (function () use ($htmlSys, $sysCeil) {
    preg_match_all('#class="rpf-hist-tick" style="--b:([0-9.]+)%;">([0-9,]+)</span>#', (string) $htmlSys, $m);
    $t = ghg_scale_ticks($sysCeil);
    if (count($m[1]) !== count($t)) return false;
    foreach ($t as $i => $tk) {
        if ($m[2][$i] !== number_format($tk['value']) || abs((float) $m[1][$i] - $tk['pct']) > 0.01) return false;
    }
    return true;
})());
ck('SC5 เส้นบอกสเกลทุกปี (ไม่นับเส้น 0 ซึ่งเป็นแกนล่างอยู่แล้ว)', (function () use ($htmlSys, $sysCeil, $sysHist) {
    $perYear = count(ghg_scale_ticks($sysCeil)) - 1;
    return preg_match_all('/class="rpf-hist-grid(?: is-first)?(?: is-last)?"/', (string) $htmlSys) === count($sysHist)
        && substr_count((string) $htmlSys, 'class="rpf-hist-line"') === $perYear * count($sysHist);
})());
ck('SC6 มุมมองคณะ: ป้ายสเกล 0 / 250 / 500 / 750 / 1,000 และเส้นครบทุกปี', (function () use ($html, $facScale, $facHist) {
    preg_match_all('#class="rpf-hist-tick" style="--b:([0-9.]+)%;">([0-9,]+)</span>#', (string) $html, $m);
    return $m[2] === ['0', '250', '500', '750', '1,000'] && array_column($facScale['ticks'], 'value') === [0.0, 250.0, 500.0, 750.0, 1000.0]
        && substr_count((string) $html, 'class="rpf-hist-line"') === 4 * count($facHist);
})());
ck('SC7 ghg_view_scale(): คณะ 1,000 → 2,000 (เส้นทุก 500 เมื่อเกิน 7 เส้น), ทั้งมหาวิทยาลัยคงเดิม', (function () {
    $f1 = ghg_view_scale('faculty', 142.13);
    $f2 = ghg_view_scale('faculty', 1200.0);
    $sy = ghg_view_scale('system', 10490.86);
    return $f1['ceiling'] === 1000.0 && ghg_view_scale('faculty', 1000.0)['ceiling'] === 1000.0
        && $f2['ceiling'] === 2000.0 && array_column($f2['ticks'], 'value') === [0.0, 500.0, 1000.0, 1500.0, 2000.0]
        && ghg_view_scale('faculty', 2000.01)['ceiling'] === 3000.0
        && $sy['ceiling'] === 15000.0 && array_column($sy['ticks'], 'value') === [0.0, 5000.0, 10000.0, 15000.0];
})());

// RP — หน้าตาชุดเดียวกับ Dashboard (oe- / co- / ad-) · ไม่มี inline style ยาว / <style> / onclick
echo "\n--- หน้าตาหน้ารายงาน ---\n";
// เนื้อหน้า (ตัด dropdown component ออก — component มี <style>/onclick ของตัวเอง)
$rpMain = function (string $h): string {
    $a = (int) strpos($h, 'id="rpPage"'); $m = substr($h, $a, (int) strpos($h, '</main>') - $a);
    $d1 = strpos($m, '<span class="co-year-label">'); $d2 = strpos($m, '<a href="export_report.php');
    return $d1 !== false && $d2 !== false ? substr($m, 0, $d1) . substr($m, $d2) : $m;
};
ck('RP1 ไม่มี <style> / onclick / dashboard.css / dropdown ปีทำเอง · inline style ในเนื้อหน้าเหลือแค่ CSS var (+ ความกว้างแถบ % ชดเชย)', (function () use ($html, $htmlSysD, $rpMain) {
    foreach ([(string) $html, $htmlSysD] as $h) {
        $m = $rpMain($h);
        if (strpos($m, '<style') !== false || strpos($m, 'onclick=') !== false || strpos($h, 'css/dashboard.css') !== false) return false;
        if (strpos($m, 'toggleYearDrop') !== false || strpos($m, '__deanRepBound') !== false || strpos($m, 'db-year-') !== false) return false;
        // style ที่เหลือ: CSS var / ไอคอน ic() / ความกว้าง dropdown / แถบ % ชดเชย
        if (preg_match('#style="(?!--|vertical-align:-\.15em;|width:[0-9.]+%;")#', $m)) return false;
    }
    return true;
})());
ck('RP2 หัวหน้า: dropdown ปี (component) · ปุ่มดาวน์โหลด Excel / PDF ลิงก์ view + year ของหน้า (PDF เปิดแท็บใหม่)', (function () use ($html, $htmlSysD, $curYear) {
    return strpos((string) $html, 'id="rpYear"') !== false
        && strpos((string) $html, 'href="export_report.php?view=faculty&amp;year=' . $curYear . '" class="oe-btn rp-btn-excel"') !== false
        && strpos((string) $html, 'href="report_print.php?view=faculty&amp;year=' . $curYear . '" target="_blank" rel="noopener" class="oe-btn rp-btn-pdf"') !== false
        && strpos($htmlSysD, 'href="export_report.php?view=system&amp;year=' . $curYear . '"') !== false
        && strpos($htmlSysD, "location.href = '?view=' + encodeURIComponent(page.dataset.view)") !== false && strpos($htmlSysD, 'data-view="system"') !== false;
})());
ck('RP3 แท็บมุมมอง co-tabs: ลิงก์ view ถูก + แท็บที่เลือก is-on/aria-current + พื้นเลื่อนตามมุมมอง · dean = "คณะของฉัน" / admin = "รายคณะ"', (function () use ($html, $htmlSysD, $htmlSys, $curYear) {
    return (bool) preg_match('#<nav class="co-tabs rp-tabs oe-rise" style="--i:1;" data-tab="faculty"#', (string) $html)
        && strpos((string) $html, '<a class="co-tab is-on" href="?view=faculty&amp;year=' . $curYear . '" data-tab="faculty" aria-current="page">คณะของฉัน</a>') !== false
        && strpos($htmlSysD, '<a class="co-tab is-on" href="?view=system&amp;year=' . $curYear . '" data-tab="system" aria-current="page">ทั้งมหาวิทยาลัย</a>') !== false
        && strpos((string) $htmlSys, 'data-tab="faculty">รายคณะ</a>') !== false;
})());
ck('RP4 การ์ด ad-kpi 3 ใบ: ตัวเลขนับขึ้น (data-count = ค่าจริง) · ป้ายเทียบปีก่อนเป็น ad-badge · แถบ % ชดเชย', (function () use ($html, $pdo, $curYear, $deanAff) {
    $sm = ghg_report_summary($pdo, $curYear, $deanAff);
    return strpos((string) $html, 'class="rp-gross" data-count="' . number_format($sm['gross'], 2, '.', '') . '">' . number_format($sm['gross'], 2) . '</b>') !== false
        && strpos((string) $html, 'class="rp-net" data-count="' . number_format($sm['net'], 2, '.', '') . '">') !== false
        && substr_count((string) $html, 'class="ad-kpi ad-k-') === 3 && strpos((string) $html, 'class="ad-offset rpf-offset"') !== false
        && (strpos((string) $html, 'rpf-chip') === false || preg_match('#<span class="ad-badge rpf-chip is-(good|bad|flat)">#', (string) $html));
})());
ck('RP5 admin เปิดหน้ารายงาน: ยังใช้เมนูข้าง/แถบหัวของ admin และหน้าตาชุดเดียวกัน', strpos((string) $htmlSys, 'admin/index.php') !== false
    && strpos((string) $htmlSys, 'id="rpPage"') !== false && strpos((string) $htmlSys, 'assets/css/reports.css') !== false);
ck('RP6 reports.css: แท่งโต (scaleX / scaleY) + โดนัทเด้ง + ปุ่มดาวน์โหลดขยับ · ปิดได้เมื่อลดการเคลื่อนไหว · container query 880 / 520', (function () use ($rpCss) {
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*)\}\s*$/s', $rpCss, $rm);
    $r = $rm[1] ?? '';
    return preg_match('/\.hbar-fill \{[^}]*animation: adGrow/', $rpCss) && preg_match('/\.rpf-hist-bar \{[^}]*animation: rpRise/', $rpCss)
        && strpos($rpCss, '@keyframes rpRise { from { transform: scaleY(0); }') !== false && preg_match('/\.rp-donut canvas \{[^}]*animation: adZoom/', $rpCss)
        && strpos($r, '.hbar-fill, .rp-cat-fill, .rpf-hist-bar, .rp-hist-num, .rp-donut canvas { animation: none; }') !== false
        && preg_match('/@container \(max-width: 880px\) \{\s*\.rp-row, \.rpf-donuts \{ grid-template-columns: minmax\(0, 1fr\); \}/', $rpCss)
        && preg_match('/@container \(max-width: 520px\) \{\s*\.rp-head-actions \{ width: 100%; \}/', $rpCss)
        && strpos($rpCss, '.rp-row .co-h2 { flex-wrap: wrap; gap: 2px 8px; }') !== false;   // จอแคบ: หน่วยตกบรรทัด ไม่ถูกบีบเป็นคอลัมน์
})());

// E — กันเทสต์ผ่านแบบว่างเปล่า: ต้องมีอย่างน้อย 1 เคสที่คณะมีกิจกรรมจริง
echo "\n--- ความครอบคลุม ---\n";
ck('E1 มีเคสที่คณะมีกิจกรรม (ไม่ใช่ผ่านเพราะยอด=0)', $cases_with_event > 0,
    "พบ $cases_with_event เคส — ถ้า 0 แปลว่า A0/A1 ไม่ได้ทดสอบอะไรเลย");

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
