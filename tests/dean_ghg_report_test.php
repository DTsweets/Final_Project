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
            sprintf('trend=%.6f  gross=%.6f  (ต่างกัน = ยอดกิจกรรม %.6f)',
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
    // F3 — $net ต้องถูกกำหนดครั้งเดียว (เดิมเขียนทับตัวเอง อ่านแล้วสับสน)
    ck("F3 $f กำหนด \$net ครั้งเดียว", substr_count($s, '$net =') === 1,
        'พบ ' . substr_count($s, '$net =') . ' ครั้ง');
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

// I4 — HTML: ตารางกิจกรรมเดิม 2 ตารางหายไป และการ์ดขึ้นครบตามจำนวนกิจกรรม
$nCards = (int) ($deans ? count(ghg_event_cards(
    event_emission_list($pdo, $curYear, $deanAff),
    removal_activity_list($pdo, $curYear, $deanAff)
)) : 0);
ck('I4 ไม่เหลือหัวข้อตารางกิจกรรมเดิมใน HTML',
    strpos((string) $html, 'การปล่อยจากกิจกรรมที่คณะจัด') === false
    && strpos((string) $html, 'การดูดกลับจากกิจกรรมที่คณะจัด') === false);
ck('I4b มีหัวข้อการ์ดรายกิจกรรมแทน', $nCards === 0 || strpos((string) $html, 'กิจกรรมที่คณะจัด') !== false);
ck('I4c ทุกกิจกรรมปรากฏในตาราง', (function () use ($html, $pdo, $curYear, $deanAff) {
    foreach (ghg_event_cards(event_emission_list($pdo, $curYear, $deanAff),
                             removal_activity_list($pdo, $curYear, $deanAff)) as $c) {
        if (strpos($html, htmlspecialchars($c['event_name'])) === false) return false;
    }
    return true;
})());

// I5 — ตารางกิจกรรมต้องมี "แค่กิจกรรม" ไม่มีรายละเอียดรายการปนมา
$rmNames = array_column(removal_activity_list($pdo, $curYear, $deanAff), 'name_tiem');
$leaked  = array_values(array_filter($rmNames, fn($n) => strpos((string) $html, htmlspecialchars($n)) !== false));
ck('I5 ไม่มีรายการดูดกลับรายบรรทัดโผล่ในหน้า', empty($leaked),
    'หลุดมา: ' . implode(' | ', array_slice($leaked, 0, 3)));
ck('I5b ไม่เหลือโครง accordion (chev/detail/aria-expanded)',
    strpos((string) $html, 'evc-detail') === false
    && strpos((string) $html, 'evc-chev') === false
    && strpos((string) $html, 'aria-expanded') === false);

// I5c — คณะที่มีหลายกิจกรรม ต้องขึ้นครบทุกกิจกรรมเช่นกัน
$multiAff = null; $multiN = 0;
foreach ($deans as $d) {
    $n = count(ghg_event_cards(
        event_emission_list($pdo, $curYear, (int) $d['Affiliation']),
        removal_activity_list($pdo, $curYear, (int) $d['Affiliation'])
    ));
    if ($n > 1) { $multiAff = (int) $d['Affiliation']; $multiN = $n; break; }
}
if ($multiAff === null) {
    ck('I5c (ข้าม) ไม่มีคณะที่มีหลายกิจกรรมในปีล่าสุด — ทดสอบสาขานี้ไม่ได้', false);
} else {
    $htmlM = (string) shell_exec(sprintf('%s %s dean faculty %d %d 2>&1',
        escapeshellarg($php), escapeshellarg($probe), $curYear, $multiAff));
    $allIn = true;
    foreach (ghg_event_cards(event_emission_list($pdo, $curYear, $multiAff),
                             removal_activity_list($pdo, $curYear, $multiAff)) as $c) {
        if (strpos($htmlM, htmlspecialchars($c['event_name'])) === false) $allIn = false;
    }
    ck("I5c คณะ $multiAff มี $multiN กิจกรรม → ขึ้นครบทุกกิจกรรม", $allIn);
}

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
    preg_match_all('/class="hbar-fill" style="width:([0-9.]+)%/', $html, $m);
    if (count($m[1]) !== 3) return false;
    foreach ($m[1] as $w) if ((float) $w < 0 || (float) $w > 100) return false;
    return true;
})());

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

// K4 — HTML: มี canvas กราฟประวัติ + ข้อมูล + ตัววาดใน ghg-charts.js
ck('K4 มี canvas scopeHistory', strpos((string) $html, 'id="scopeHistory"') !== false);
ck('K4b ส่งข้อมูล __HISTORY ครบทุกปี',
    preg_match('/window\.__HISTORY = (\[.*?\]);/s', (string) $html, $mh) === 1
    && count(json_decode($mh[1], true) ?: []) === count($years));
ck('K4c ghg-charts.js มี drawGhgGroupedBars',
    strpos((string) file_get_contents(__DIR__ . '/../assets/js/ghg-charts.js'), 'drawGhgGroupedBars') !== false);
ck('K4d มี legend ขอบเขตครบ 3 สี', substr_count((string) $html, '■ ขอบเขต') === 3);

// J1 — คำว่า Scope ต้องถูกแทนด้วย ขอบเขต
ck('J1 badge ใช้ "ขอบเขต" แทน "Scope"',
    strpos((string) $html, 'ขอบเขต 1') !== false && !preg_match('/>\s*Scope\s*\d/', (string) $html));

// I4d — คอลัมน์ tCO2e ต้องมีไอคอนโรงงาน/ใบไม้ กำกับตัวเลข (เหมือน admin)
ck('I4d ตัวเลขปล่อย/ดูดกลับมี svg นำหน้าทั้งคู่',
    $nCards === 0 || (preg_match('/title="การปล่อย \(tCO₂e\)"[^>]*>\s*<svg/s', (string) $html)
                   && preg_match('/title="การดูดกลับ \(tCO₂e\)"[^>]*>\s*<svg/s', (string) $html)));

// มุมมองทั้งระบบต้องไม่มีโดนัทชุดนี้ (ไม่มีข้อมูลรายการของคณะ) และต้องไม่พัง
$htmlSys = shell_exec(sprintf('%s %s admin system %d %d 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $curYear, $deanAff));
ck('H5e มุมมองทั้งระบบเรนเดอร์ได้ และไม่มีโดนัทราย Scope',
    $htmlSys && stripos($htmlSys, 'Fatal error') === false && substr_count($htmlSys, 'id="scopeItemDonut') === 0);
@unlink($probe);

// I — การ์ดรายกิจกรรม (รวมตารางปล่อย + ดูดกลับ)
echo "\n--- การ์ดรายกิจกรรม: ghg_event_cards() ---\n";

// I1 ข้อมูลสังเคราะห์: กิจกรรมที่มีเฉพาะฝั่งเดียวต้องไม่หาย
$emitFake = [
    ['event_id' => 10, 'event_name' => 'งาน A', 'event_date' => '2026-03-01', 'emission' => 2.0],
    ['event_id' => 10, 'event_name' => 'งาน A', 'event_date' => '2026-03-01', 'emission' => 1.0],
    ['event_id' => 11, 'event_name' => 'งาน B', 'event_date' => '2026-05-01', 'emission' => 4.0],
];
$rmFake = [
    ['event_id' => 10, 'event_name' => 'งาน A', 'event_date' => '2026-03-01', 'emission' => 0.5],
    ['event_id' => 12, 'event_name' => 'งาน C', 'event_date' => '2026-01-01', 'emission' => 7.0], // ปลูกต้นไม้อย่างเดียว
];
$cards = ghg_event_cards($emitFake, $rmFake);
ck('I1 union ครบทุกกิจกรรม (รวมกิจกรรมที่มีแต่ดูดกลับ)', count($cards) === 3, 'ได้ ' . count($cards) . ' การ์ด');
$byId = [];
foreach ($cards as $c) $byId[$c['event_id']] = $c;
ck('I1b รวมหลายรายการในกิจกรรมเดียวกัน', feq($byId[10]['emit_total'], 3.0) && feq($byId[10]['removal_total'], 0.5));
ck('I1c net = ปล่อย - ดูดกลับ', feq($byId[10]['net'], 2.5) && feq($byId[12]['net'], -7.0),
    'งาน C ที่มีแต่ดูดกลับ ต้องได้ net ติดลบ');
ck('I1d กิจกรรมที่ไม่มีการปล่อย ยังมีการ์ด', isset($byId[12]) && count($byId[12]['emit']) === 0);
ck('I1e เรียงวันที่ใหม่ -> เก่า', array_column($cards, 'event_id') === [11, 10, 12],
    'ได้ลำดับ ' . implode(',', array_column($cards, 'event_id')));

// I2 กิจกรรมไม่ระบุวันที่ต้องไปท้ายสุด ไม่ทำให้ลำดับพัง
$noDate = ghg_event_cards([['event_id' => 99, 'event_name' => 'ไม่ระบุวัน', 'event_date' => null, 'emission' => 1.0]], $rmFake);
ck('I2 กิจกรรมไม่ระบุวันที่ไปอยู่ท้ายสุด', end($noDate)['event_id'] === 99);

// I3 ข้อมูลจริง: ยอดรวมจากการ์ดต้องเท่ากับ KPI ด้านบนของหน้า
foreach ($deans as $d) {
    $aff = (int) $d['Affiliation'];
    foreach ($years as $y) {
        $yid   = (int) $y['year_id'];
        $ev    = event_emission_list($pdo, $yid, $aff);
        $rm    = removal_activity_list($pdo, $yid, $aff);
        if (!$ev && !$rm) continue;
        $cs    = ghg_event_cards($ev, $rm);
        $sumE  = array_sum(array_column($cs, 'emit_total'));
        $sumR  = array_sum(array_column($cs, 'removal_total'));
        $expE  = 0.0; foreach ($ev as $r) $expE += (float) $r['emission'];
        ck("I3 {$d['username']} ปี {$y['year']}: ยอดรวมการ์ด == KPI (ปล่อย/ดูดกลับ)",
            feq($sumE, $expE) && feq($sumR, removal_activity_total($pdo, $yid, $aff)),
            sprintf('ปล่อย %.6f/%.6f  ดูดกลับ %.6f/%.6f', $sumE, $expE, $sumR, removal_activity_total($pdo, $yid, $aff)));
        ck("I3b {$d['username']} ปี {$y['year']}: ไม่มีรายการตกหล่นจากการ์ด",
            count($ev) === array_sum(array_map(fn($c) => count($c['emit']), $cs))
            && count($rm) === array_sum(array_map(fn($c) => count($c['removal']), $cs)));
    }
}

// E — กันเทสต์ผ่านแบบว่างเปล่า: ต้องมีอย่างน้อย 1 เคสที่คณะมีกิจกรรมจริง
echo "\n--- ความครอบคลุม ---\n";
ck('E1 มีเคสที่คณะมีกิจกรรม (ไม่ใช่ผ่านเพราะยอด=0)', $cases_with_event > 0,
    "พบ $cases_with_event เคส — ถ้า 0 แปลว่า A0/A1 ไม่ได้ทดสอบอะไรเลย");

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
