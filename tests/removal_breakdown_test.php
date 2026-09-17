<?php
/**
 * Unit Test — ตารางการดูดกลับบน dashboard (removal_breakdown)
 * รัน: C:\xampp\php\php.exe tests\removal_breakdown_test.php
 *
 * ล็อกกฎ:
 *   A. ยุบการดูดกลับจากกิจกรรมทั้งหมดเป็นแถวเดียวชื่อ "กิจกรรม"
 *      (กติกาเดียวกับ $year_breakdown ของการ์ด TOTAL EMISSION)
 *   B. เรียงมาก -> น้อย
 *   C. ยอดรวมของทุกแถว = ยอดดูดกลับทั้งหมด → % รวมกันได้ 100
 *   D. ตรงกับข้อมูลจริงในฐานข้อมูล
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

// ═══ ข้อมูลจำลอง ═══════════════════════════════════════════
$central = [
    ['name_tiem' => 'ต้นไม้ยืนต้น', 'unit' => 'ต้น', 'factor' => 9.5,  'qty' => 1000, 'emission' => 9.5],
    ['name_tiem' => 'พื้นที่สีเขียว', 'unit' => 'ไร่', 'factor' => 2.0,  'qty' => 500,  'emission' => 1.0],
    ['name_tiem' => 'ยังไม่กรอก',    'unit' => 'ต้น', 'factor' => 1.0,  'qty' => 0,    'emission' => 0.0],
];
$activitySum = 3.0;

echo "A. ยุบกิจกรรมเป็นแถวเดียว\n";
$bd = removal_breakdown($central, $activitySum);
ck('A1 ได้ 4 แถว (ส่วนกลาง 3 + กิจกรรม 1)', count($bd) === 4, 'ได้ ' . count($bd));

$eventRows = array_values(array_filter($bd, fn($r) => $r['kind'] === 'event'));
ck('A2 มีแถว kind=event เพียงแถวเดียว', count($eventRows) === 1, 'ได้ ' . count($eventRows));
ck('A3 แถวกิจกรรมชื่อ "กิจกรรม"', ($eventRows[0]['name'] ?? '') === 'กิจกรรม', $eventRows[0]['name'] ?? '(ว่าง)');
ck('A4 ยอดแถวกิจกรรม = ยอดรวมจากกิจกรรม', abs(($eventRows[0]['emission'] ?? 0) - 3.0) < 1e-9);

// แถวกิจกรรมไม่มีหน่วย/ค่าดูดกลับ/ปริมาณ เพราะยุบมาจากหลายรายการหลายหน่วย
ck('A5 แถวกิจกรรมไม่มี factor/qty (กันแสดงตัวเลขที่ไม่มีความหมาย)',
    ($eventRows[0]['factor'] ?? -1) == 0.0 && ($eventRows[0]['qty'] ?? -1) == 0.0);

echo "\nB. การเรียงลำดับ\n";
$emissions = array_column($bd, 'emission');
$sorted = $emissions; rsort($sorted);
ck('B1 เรียงมาก -> น้อย', $emissions === $sorted, implode(', ', $emissions));
ck('B2 อันดับ 1 คือ ต้นไม้ยืนต้น (9.5)', $bd[0]['name'] === 'ต้นไม้ยืนต้น', $bd[0]['name']);
ck('B3 อันดับ 2 คือ กิจกรรม (3.0)', $bd[1]['name'] === 'กิจกรรม', $bd[1]['name']);

echo "\nC. ยอดรวม / ฐานของ %\n";
$sum = array_sum($emissions);
ck('C1 ยอดรวมทุกแถว = ส่วนกลาง + กิจกรรม', abs($sum - (10.5 + 3.0)) < 1e-9, "ได้ $sum");
$pctSum = 0.0;
foreach ($bd as $r) $pctSum += $sum > 0 ? $r['emission'] / $sum * 100 : 0;
ck('C2 % ของทุกแถวรวมกันได้ 100', abs($pctSum - 100.0) < 1e-9, "ได้ $pctSum");

// ไม่มีข้อมูลเลย ต้องไม่หารด้วยศูนย์ / ไม่ล้ม
$empty = removal_breakdown([], 0.0);
ck('C3 ไม่มีข้อมูลเลย → ยังได้แถวกิจกรรม 1 แถว ยอด 0', count($empty) === 1 && $empty[0]['emission'] == 0.0);

echo "\nD. เทียบกับข้อมูลจริงในฐานข้อมูล\n";
$years = ghg_years($pdo);
$checked = 0;
foreach ($years as $y) {
    $yid   = (int) $y['year_id'];
    $items = removal_items_list($pdo, $yid);
    $asum  = removal_activity_total($pdo, $yid);
    $total = removal_total($pdo, $yid);
    $rows  = removal_breakdown($items, (float) $asum);
    $rsum  = array_sum(array_column($rows, 'emission'));

    ck("D1 ปี {$y['year']}: ยอดรวมตาราง = removal_total()",
        abs($rsum - (float) $total) < 1e-6, sprintf('ตาราง=%.6f removal_total=%.6f', $rsum, $total));

    // ไม่มีรายการดูดกลับส่วนกลางตกหล่น
    ck("D2 ปี {$y['year']}: จำนวนแถว = ส่วนกลาง " . count($items) . " + กิจกรรม 1",
        count($rows) === count($items) + 1, 'ได้ ' . count($rows));
    $checked++;
}
ck('D3 ได้ตรวจกับข้อมูลจริงอย่างน้อย 1 ปี', $checked > 0);

echo "\n─────────────────────────\nผ่าน $pass | ไม่ผ่าน $fail\n";
exit($fail > 0 ? 1 : 0);
