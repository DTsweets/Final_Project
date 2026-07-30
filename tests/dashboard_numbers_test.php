<?php
/**
 * Dashboard Numbers — Verification Test
 * -------------------------------------
 * ตรวจว่าตัวเลขที่ dashboard (admin/index.php) นำมาแสดง "ถูกต้อง" โดย:
 *   1) คำนวณใหม่อย่างอิสระจากตารางดิบ (independent recompute)
 *   2) ตรวจความสอดคล้องภายใน (ผลรวมส่วนย่อย = ยอดรวม ฯลฯ)
 *
 * รัน:  C:\xampp\php\php.exe tests\dashboard_numbers_test.php
 *
 * หมายเหตุ: อ่านอย่างเดียว (SELECT) ไม่แก้ข้อมูลใดๆ
 */

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/ghg_report.php';

$pdo = getDB();
const EPS = 1e-6;   // ค่าคลาดเคลื่อนที่ยอมรับได้ (float)

$pass = 0; $fail = 0; $fails = [];
function check(string $name, $a, $b) {
    global $pass, $fail, $fails;
    $ok = is_numeric($a) && is_numeric($b) ? (abs($a - $b) < EPS) : ($a === $b);
    if ($ok) { $pass++; echo "  \xE2\x9C\x94 PASS  $name\n"; }
    else {
        $fail++; $fails[] = $name;
        echo "  \xE2\x9C\x98 FAIL  $name\n";
        echo "        expected: " . var_export($b, true) . "\n";
        echo "        actual:   " . var_export($a, true) . "\n";
    }
}
function f($n){ return number_format((float)$n, 6); }

// สูตรกลางที่ dashboard ใช้ทุกที่: SUM(Vol*AD)/1000
$EMIT = 'SUM(ui.Vol * ai.AD)/1000';

// ─────────────────────────────────────────────────────────────
// ดึงปีทั้งหมด
$years = $pdo->query('SELECT id AS year_id, year FROM admin_year ORDER BY year DESC')->fetchAll();
echo "== ปีที่ทดสอบ: " . implode(', ', array_map(fn($y)=>$y['year'], $years)) . " ==\n\n";

$sum_of_year_totals = 0.0;

foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    echo "── ปี {$y['year']} (year_id=$yid) ─────────────────────────\n";

    // (dashboard) total emission ของปี
    $total = (float) $pdo->query("SELECT COALESCE($EMIT,0) FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id WHERE ui.year_id=$yid")->fetchColumn();
    $sum_of_year_totals += $total;
    echo "  total emission = " . f($total) . " tCO2e\n";

    // [1] breakdown แยก source: officer(รายคณะ) + survey + event  ต้องรวม = total
    //     (ตรวจว่าไม่นับซ้ำ/ไม่ตกหล่น source)
    $officer = (float) $pdo->query("SELECT COALESCE($EMIT,0) FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id WHERE ui.year_id=$yid AND ui.source='officer'")->fetchColumn();
    $survey  = (float) $pdo->query("SELECT COALESCE($EMIT,0) FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id WHERE ui.year_id=$yid AND ui.source='survey'")->fetchColumn();
    $event   = (float) $pdo->query("SELECT COALESCE($EMIT,0) FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id WHERE ui.year_id=$yid AND ui.source='event'")->fetchColumn();
    check("[1] officer+survey+event = total (ไม่นับซ้ำ/ไม่ตกหล่น)", $officer + $survey + $event, $total);

    // [1b] มี source แปลกปลอมนอกเหนือ officer/survey/event ไหม
    $other = (float) $pdo->query("SELECT COALESCE($EMIT,0) FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id WHERE ui.year_id=$yid AND ui.source NOT IN ('officer','survey','event')")->fetchColumn();
    check("[1b] ไม่มี source นอกเหนือ 3 ชนิด", $other, 0.0);

    // [2] scope1+scope2+scope3 = total (dashboard คิด scope รวมทุก source)
    $scope = $pdo->query("
        SELECT ag.scope, COALESCE($EMIT,0) e
        FROM admin_g ag
        LEFT JOIN admin_item ai ON ai.scope=ag.id AND ai.year_id=$yid
        LEFT JOIN user_item ui ON ui.admin_item_id=ai.id AND ui.year_id=$yid
        GROUP BY ag.scope")->fetchAll(PDO::FETCH_KEY_PAIR);
    $s1 = (float)($scope[1] ?? 0); $s2 = (float)($scope[2] ?? 0); $s3 = (float)($scope[3] ?? 0);
    check("[2] scope1+scope2+scope3 = total", $s1 + $s2 + $s3, $total);

    // [3] independent recompute: group by admin_item แล้วรวม ต้องเท่ากับ total
    $regroup = (float) $pdo->query("
        SELECT COALESCE(SUM(sub.e),0) FROM (
            SELECT ui.admin_item_id, SUM(ui.Vol*ai.AD)/1000 e
            FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id
            WHERE ui.year_id=$yid GROUP BY ui.admin_item_id
        ) sub")->fetchColumn();
    check("[3] recompute (group-by-item) = total", $regroup, $total);

    // [4] รายคณะ officer: ผลรวมทุกคณะ = officer total
    $affil_sum = (float) $pdo->query("
        SELECT COALESCE(SUM(x.e),0) FROM (
            SELECT a.id, COALESCE($EMIT,0) e
            FROM affiliation_id a
            LEFT JOIN user_item ui ON ui.affiliation_id=a.id AND ui.year_id=$yid AND ui.source='officer'
            LEFT JOIN admin_item ai ON ai.id=ui.admin_item_id
            GROUP BY a.id
        ) x")->fetchColumn();
    check("[4] ผลรวมรายคณะ(officer) = officer total", $affil_sum, $officer);

    // [5] GHG Removal: removal_total = central + activity (ตามนิยาม + ตรวจ component)
    $central  = removal_central_total($pdo, $yid);
    $activity = removal_activity_total($pdo, $yid);
    $rtotal   = removal_total($pdo, $yid);
    check("[5] removal_total = central + activity", $central + $activity, $rtotal);

    // [5b] central ตรงกับสูตรดิบ SUM(qty*factor)/1000
    $central_raw = (float) $pdo->query("SELECT COALESCE(SUM(ri.qty*ri.factor)/1000,0) FROM removal_item ri WHERE ri.year_id=$yid")->fetchColumn();
    check("[5b] removal_central = SUM(qty*factor)/1000", $central, $central_raw);

    // [5c] activity list: ผลรวม emission ในลิสต์ = activity total
    $act_list = removal_activity_list($pdo, $yid);
    $act_list_sum = array_sum(array_map(fn($r)=>(float)$r['emission'], $act_list));
    check("[5c] ผลรวม emission ใน activity_list = activity total", $act_list_sum, $activity);

    echo "\n";
}

// ─────────────────────────────────────────────────────────────
echo "── สะสมทุกปี (cumulative) ─────────────────────────\n";

// [6] cumulative = SUM ทุกปี (dashboard headline)
$cumulative = (float) $pdo->query("SELECT COALESCE($EMIT,0) FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id")->fetchColumn();
echo "  cumulative = " . f($cumulative) . " tCO2e\n";
check("[6] cumulative = ผลรวม total ของทุกปี", $sum_of_year_totals, $cumulative);

// [7] cumul_breakdown: officer(รายคณะ สะสม) + survey + event = cumulative
$cum_officer = (float) $pdo->query("
    SELECT COALESCE(SUM(x.e),0) FROM (
        SELECT a.id, COALESCE($EMIT,0) e
        FROM affiliation_id a
        LEFT JOIN user_item ui ON ui.affiliation_id=a.id AND ui.source='officer'
        LEFT JOIN admin_item ai ON ai.id=ui.admin_item_id
        GROUP BY a.id
    ) x")->fetchColumn();
$cum_src = $pdo->query("SELECT ui.source, COALESCE($EMIT,0) e FROM user_item ui JOIN admin_item ai ON ai.id=ui.admin_item_id GROUP BY ui.source")->fetchAll(PDO::FETCH_KEY_PAIR);
$cum_survey = (float)($cum_src['survey'] ?? 0);
$cum_event  = (float)($cum_src['event'] ?? 0);
check("[7] cumul breakdown (คณะ+แบบสอบถาม+กิจกรรม) = cumulative", $cum_officer + $cum_survey + $cum_event, $cumulative);

// [8] จำนวนรายงานทั้งหมด — เทียบสูตร dashboard กับการนับใน PHP อิสระ
$dash_entries = (int) $pdo->query('
    SELECT COUNT(DISTINCT CONCAT(
        CASE WHEN source="officer" THEN CONCAT("aff",affiliation_id) ELSE source END, "-", year_id))
    FROM user_item')->fetchColumn();
$rowsAll = $pdo->query('SELECT source, affiliation_id, year_id FROM user_item')->fetchAll(PDO::FETCH_ASSOC);
$keys = [];
foreach ($rowsAll as $r) {
    $k = ($r['source'] === 'officer' ? 'aff' . $r['affiliation_id'] : $r['source']) . '-' . $r['year_id'];
    $keys[$k] = true;
}
check("[8] จำนวนรายงาน (dashboard) = นับอิสระใน PHP", $dash_entries, count($keys));

echo "\n═══════════════════════════════════════════════\n";
echo "ผลรวม: PASS=$pass  FAIL=$fail\n";
if ($fail > 0) { echo "รายการที่ FAIL:\n  - " . implode("\n  - ", $fails) . "\n"; exit(1); }
echo "ตัวเลขบน dashboard สอดคล้อง/ถูกต้องทุกจุดที่ตรวจ \xE2\x9C\x85\n";
exit(0);
