<?php
/**
 * Test: event_emission_list() — รายการปล่อยจากกิจกรรมรายกิจกรรม (dashboard officer/dean)
 * รัน:  C:\xampp\php\php.exe tests\event_emission_list_test.php
 * (อ่านอย่างเดียว — ไม่แก้ข้อมูล)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/ghg_report.php';

$pdo = getDB();
const EPS = 1e-6;
$pass = 0; $fail = 0; $fails = [];
function check($name, $cond) {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  \xE2\x9C\x94 PASS  $name\n"; }
    else { $fail++; $fails[] = $name; echo "  \xE2\x9C\x98 FAIL  $name\n"; }
}

$years = $pdo->query('SELECT id AS year_id, year FROM admin_year ORDER BY year DESC')->fetchAll();

foreach ($years as $y) {
    $yid = (int)$y['year_id'];
    $rows = event_emission_list($pdo, $yid);
    $listSum = array_sum(array_map(fn($r) => (float)$r['emission'], $rows));

    // [1] ผลรวม emission ในลิสต์ = ยอด event รวมของปี (user_item source='event') — กันนับซ้ำ/ตกหล่น
    $srcTotal = (float)$pdo->query("SELECT COALESCE(SUM(ui.Vol*ai.AD)/1000,0)
        FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id
        WHERE ui.year_id=$yid AND ui.source='event'")->fetchColumn();
    check("ปี {$y['year']} [1] ผลรวม list = ยอด event รวม (".number_format($srcTotal,4).")", abs($listSum - $srcTotal) < EPS);

    // [2] แต่ละแถวคำนวณถูก: emission = qty(Vol) * factor(AD) / 1000
    $rowMathOk = true;
    foreach ($rows as $r) {
        if (abs((float)$r['emission'] - ((float)$r['qty'] * (float)$r['factor'] / 1000)) > EPS) { $rowMathOk = false; break; }
    }
    check("ปี {$y['year']} [2] ทุกแถว emission = qty×factor/1000", $rowMathOk);
}

// [3] กรองรายคณะ: ผลรวมของทุกคณะ (แยกเรียก) = ผลรวมทั้งระบบ (ไม่ระบุคณะ) ของปีล่าสุด
$yid = (int)$years[0]['year_id'];
$allSum = array_sum(array_map(fn($r) => (float)$r['emission'], event_emission_list($pdo, $yid)));
$affils = $pdo->query('SELECT id FROM affiliation_id')->fetchAll(PDO::FETCH_COLUMN);
$perAffilSum = 0.0;
foreach ($affils as $aid) {
    $perAffilSum += array_sum(array_map(fn($r) => (float)$r['emission'], event_emission_list($pdo, $yid, (int)$aid)));
}
check("[3] ผลรวมแยกรายคณะ = ผลรวมทั้งระบบ (ปีล่าสุด)", abs($perAffilSum - $allSum) < EPS);

echo "\n═══════════════════════════════\nPASS=$pass  FAIL=$fail\n";
if ($fail > 0) { echo "FAILED:\n  - " . implode("\n  - ", $fails) . "\n"; exit(1); }
echo "event_emission_list ทำงานถูกต้อง \xE2\x9C\x85\n";
