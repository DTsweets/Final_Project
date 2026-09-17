<?php
/**
 * Unit Test — หน้า แบบสอบถาม & กิจกรรม (includes/collect_page.php + includes/collect_entry.php)
 * รัน: C:\xampp\php\php.exe tests\collect_page_test.php
 *
 * ล็อกกฎ:
 *   - ค่าที่บันทึก: ไม่ติดลบ, ไม่เกิน 1,000,000, คั่นหลักพันได้, จำนวนผู้ตอบเป็นจำนวนเต็ม
 *   - officer เห็นเฉพาะหน่วยงานตัวเอง / admin เห็นทุกหน่วยงาน · ยอดการ์ดสรุป = ผลรวมรายการ
 *   - ไม่มีคำสั่ง add_item / delete_item (เดิมลบรายการของกิจกรรมหน่วยงานอื่นได้)
 *   - หน้าแสดงผลได้ทั้ง 2 แท็บ ทั้ง admin และ officer ตารางกรอกผูก data-entry.js
 * การบันทึกจริง (ดูดกลับ) คืนค่าเดิมใน finally → ข้อมูลจริงไม่เปลี่ยน
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/collect_entry.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

// กรณีทดสอบ: หน่วยงานที่มีกิจกรรมซึ่งมีทั้งรายการปล่อยและดูดกลับ + แบบสอบถามที่มีหัวข้อ
$ev = $pdo->query("SELECT e.id, e.affiliation_id, e.year_id FROM event e
    WHERE EXISTS (SELECT 1 FROM event_item ei WHERE ei.event_id = e.id) AND EXISTS (SELECT 1 FROM removal_event_item r WHERE r.event_id = e.id)
    ORDER BY e.id LIMIT 1")->fetch();
$aff = (int) $ev['affiliation_id']; $year = (int) $ev['year_id']; $eid = (int) $ev['id'];
$qq = $pdo->query("SELECT q.audience FROM questionnaire q WHERE q.affiliation_id = $aff AND q.year_id = $year
    AND EXISTS (SELECT 1 FROM questionnaire_item qi WHERE qi.questionnaire_id = q.id) ORDER BY q.id LIMIT 1")->fetchColumn();
// รายการของกิจกรรม "หน่วยงานอื่น" — ใช้ทดสอบว่าลบข้ามหน่วยงานไม่ได้
$foreign = $pdo->query("SELECT ei.id, ei.event_id FROM event_item ei JOIN event e ON e.id = ei.event_id WHERE e.affiliation_id <> $aff LIMIT 1")->fetch();
echo "หน่วยงาน $aff ปี id $year กิจกรรม $eid · แบบสอบถาม \"" . mb_substr((string) $qq, 0, 30) . "\" · รายการหน่วยงานอื่น " . json_encode($foreign) . "\n\n";

// ── K ฟังก์ชันข้อมูล ──
ck('K1 จำนวนผู้ตอบ: จำนวนเต็ม (ปัดลง), ติดลบ/ตัวอักษร = 0, คั่นหลักพันได้, เพดาน 1,000,000',
    collect_clean_count('12') === 12 && collect_clean_count('12.7') === 12 && collect_clean_count('-3') === 0
    && collect_clean_count('abc') === 0 && collect_clean_count('1,200') === 1200 && collect_clean_count('5000000') === 1000000);
ck('K6 ผู้ตอบ × ค่าเฉลี่ย เกินความจุ user_item.Vol decimal(13,4) (999,999,999.9999) → ตรวจพบ · เท่าขอบ/ค่าปกติไม่ถือว่าเกิน',
    function_exists('collect_survey_over_max') && defined('COLLECT_SURVEY_VOL_MAX') && COLLECT_SURVEY_VOL_MAX === 999999999.9999
    && collect_survey_over_max(1000000, 5000.0) && collect_survey_over_max(1000000, 1000.0)
    && !collect_survey_over_max(1000000, 999.9999) && !collect_survey_over_max(1200, 2.5) && !collect_survey_over_max(0, 0.0));
ck('K2 รูปแบบตัวเลข/วันที่: 0 = "-", ช่องกรอกตัดศูนย์ท้าย, ช่วงวันที่ dd/mm/yyyy',
    collect_fmt(0.0) === '-' && collect_fmt(1234.5) === '1,234.500' && collect_fmt(0.12345, 4) === '0.1235'
    && collect_input_val(0.0) === '' && collect_input_val(12.5) === '12.5' && collect_input_val(100.0) === '100'
    && collect_date_range('2026-07-01', '2026-07-03') === '01/07/2026 – 03/07/2026' && collect_date_range(null, null) === 'ไม่ระบุวันที่');

$sOff = collect_survey_list($pdo, $year, $aff);
$eOff = collect_event_list($pdo, $year, $aff);
$eAll = collect_event_list($pdo, $year, null);
$nEvAll = (int) $pdo->query("SELECT COUNT(*) FROM event WHERE year_id = $year")->fetchColumn();
ck('K3 officer เห็นเฉพาะหน่วยงานตัวเอง / admin เห็นทุกหน่วยงาน',
    count($eOff) === (int) $pdo->query("SELECT COUNT(*) FROM event WHERE year_id = $year AND affiliation_id = $aff")->fetchColumn()
    && !array_filter($eOff, fn($e) => (int) $e['affiliation_id'] !== $aff) && !array_filter($sOff, fn($q) => (int) $q['affid'] !== $aff)
    && count($eAll) === $nEvAll);
$t = collect_totals($sOff, $eOff);
$dbEmit = (float) $pdo->query("SELECT COALESCE(SUM(ei.Vol*ai.AD)/1000,0) FROM event_item ei JOIN event e ON e.id=ei.event_id JOIN admin_item ai ON ai.id=ei.admin_item_id WHERE e.year_id=$year AND e.affiliation_id=$aff")->fetchColumn();
$dbRmv  = (float) $pdo->query("SELECT COALESCE(SUM(r.qty*r.factor)/1000,0) FROM removal_event_item r JOIN event e ON e.id=r.event_id WHERE e.year_id=$year AND e.affiliation_id=$aff")->fetchColumn();
$dbSurv = (float) $pdo->query("SELECT COALESCE(SUM(ss.respondents*ss.avg_value*ai.AD)/1000,0) FROM survey_summary ss JOIN questionnaire_item qi ON qi.id=ss.questionnaire_item_id JOIN questionnaire q ON q.id=qi.questionnaire_id JOIN admin_item ai ON ai.id=qi.admin_item_id WHERE q.year_id=$year AND q.affiliation_id=$aff AND ss.affiliation_id=q.affiliation_id AND ss.year_id=$year")->fetchColumn();
ck('K4 ยอดการ์ดสรุป = ฐานข้อมูล (แบบสอบถาม / กิจกรรมปล่อย / ดูดกลับ)',
    abs($t['survey'] - $dbSurv) < 1e-9 && abs($t['emit'] - $dbEmit) < 1e-9 && abs($t['removal'] - $dbRmv) < 1e-9, json_encode($t) . " db=$dbSurv/$dbEmit/$dbRmv");
$filledDb = (int) $pdo->query("SELECT COUNT(*) FROM survey_summary ss JOIN questionnaire_item qi ON qi.id=ss.questionnaire_item_id JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE q.year_id=$year AND q.affiliation_id=$aff AND ss.affiliation_id=q.affiliation_id AND ss.year_id=$year AND ss.respondents>0 AND ss.avg_value>0")->fetchColumn();
ck('K5 จำนวนหัวข้อที่กรอกแล้วบนการ์ดแบบสอบถาม = ฐานข้อมูล', array_sum(array_column($sOff, 'filled_count')) == $filledDb);

// ── หน้าเว็บ (render ผ่าน CLI) ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/collect_page_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
[$_, $role, $aff, $b64, $method] = $argv + [5 => ""]; parse_str(base64_decode($b64), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/$role/collect.php"; $_SERVER["REQUEST_URI"] = "/$role/collect.php";
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($method === "post") { $_POST = $q; $_GET = []; } else { $_GET = $q; }
session_start();
$_SESSION = ["user_id"=>1,"role"=>$role,"affiliation_id"=>(int)$aff,"affiliation_name"=>"หน่วยงานทดสอบ","firstname"=>"ท","lastname"=>"ส","username"=>"t","last_activity"=>time()];
// CSRF: session มี token และ POST ส่ง token ให้อัตโนมัติ (เทสต์ที่ส่ง csrf_token มาเองไม่ถูกทับ)
$_SESSION["csrf_token"] = "probe-token"; if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST += ["csrf_token" => "probe-token"];
ob_start(); require "$root/$role/collect.php"; echo ob_get_clean();
');
// query ส่งเป็น base64: escapeshellarg บน Windows แปลง % เป็นช่องว่าง
// $asAff: หน่วยงานของผู้ใช้ (ค่าเริ่มต้น = หน่วยงานของกรณีทดสอบกิจกรรม)
$render = fn(string $role, string $query = '', string $method = 'get', ?int $asAff = null) => (string) shell_exec(sprintf('%s %s %s %d %s %s 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $role, $asAff ?? $aff, escapeshellarg(base64_encode($query)), $method));
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $h);

$hS = $render('officer', "year=$year&tab=survey&group=" . urlencode((string) $qq));
ck('R1 officer แท็บแบบสอบถาม เรนเดอร์ได้ + โหลด collect.css / officer-entry.css / data-entry.js', $noErr($hS)
    && strpos($hS, 'assets/css/collect.css') !== false && strpos($hS, 'assets/css/officer-entry.css') !== false && strpos($hS, 'assets/js/data-entry.js') !== false,
    substr(preg_replace('/\s+/', ' ', $hS), 0, 400));
ck('R2 การ์ดสรุปแสดงยอดตรงกับฐานข้อมูล + ตัวเลขบนแท็บ = จำนวนรายการ',
    strpos($hS, 'data-co-kpi="survey">' . collect_fmt($t['survey'])) !== false && strpos($hS, 'data-co-kpi="emit">' . collect_fmt($t['emit'])) !== false
    && strpos($hS, 'data-co-kpi="removal">' . collect_fmt($t['removal'])) !== false
    && substr_count($hS, '<article class="co-card') === count($sOff)
    && preg_match('#data-tab="event" data-de-guard>\s*กิจกรรม <span class="co-tab-n">' . count($eOff) . '</span>#', $hS));
$qRows = (int) $pdo->query("SELECT COUNT(*) FROM questionnaire_item qi JOIN questionnaire q ON q.id=qi.questionnaire_id WHERE q.year_id=$year AND q.affiliation_id=$aff AND q.audience=" . $pdo->quote($qq))->fetchColumn();
ck('R3 เปิดแบบสอบถาม: ตารางกรอกมีแถวครบ, ช่องผู้ตอบเป็นจำนวนเต็ม, แถบบันทึกเรียก deSave(this), การ์ดที่เลือกถูกไฮไลต์',
    strpos($hS, 'id="co-detail"') !== false && substr_count($hS, 'data-int="1" data-group="survey"') === $qRows
    && substr_count($hS, 'name="avg[') === $qRows && strpos($hS, 'onclick="deSave(this)"') !== false
    && substr_count($hS, 'co-card oe-rise is-selected') === 1 && strpos($hS, 'id="coTopicModal"') !== false);

$hE = $render('officer', "year=$year&tab=event&event=$eid");
$nEf = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE data_source='event' AND event_id=$eid")->fetchColumn();
$nRm = (int) $pdo->query("SELECT COUNT(*) FROM removal_event_item WHERE event_id=$eid")->fetchColumn();
ck('R4 officer แท็บกิจกรรม: ส่วนปล่อย + ส่วนดูดกลับ แยกฟอร์ม แยกแถบบันทึก แถวครบ', $noErr($hE)
    && strpos($hE, 'id="coEventForm"') !== false && strpos($hE, 'id="coRemovalForm"') !== false
    && substr_count($hE, 'data-group="emit"') === $nEf && substr_count($hE, 'data-group="removal"') === $nRm
    && substr_count($hE, 'class="oe-dock"') === 2 && substr_count($hE, 'data-de-scope data-de-digits="3"') === 2,
    substr(preg_replace('/\s+/', ' ', $hE), 0, 400));
ck('R5 ไม่มีฟอร์มเพิ่มแบบเก่าค้างในหน้า (ย้ายไปอยู่ในหน้าต่าง) และปุ่มยกเลิกไม่ใช้สไตล์ปุ่มลบ',
    strpos($hE, '＋ เพิ่มกิจกรรม</h2>') === false && strpos($hE, 'id="coEventModal"') !== false && strpos($hE, 'id="coRemovalModal"') !== false
    && strpos($hE, 'class="del" onclick="document.getElementById(') === false && strpos($hS, 'class="del" onclick="document.getElementById(') === false
    && strpos($hS, "class=\"btn-secondary\" onclick=\"closeModal('coTopicModal')\">ยกเลิก") !== false);

ck('R5b ช่อง EF / ค่าดูดกลับในหน้าต่างรับทศนิยมได้ถึง 6 ตำแหน่ง (step="any" — เดิม step 0.0001 เบราว์เซอร์ไม่ยอมตำแหน่งที่ 5–6) · ข้อมูลในปุ่มแก้ไขไม่มีศูนย์ต่อท้าย',
    preg_match('/id="coTopicAd" name="ad" type="number" step="any"/', $hS) && preg_match('/id="coEvTopicAd" name="ad" type="number" step="any"/', $hE)
    && preg_match('/id="coRemovalFactor" name="factor" type="number" step="any"/', $hE)
    && !preg_match('/data-(?:ad|factor|ef)="\d+\.\d*0"/', $hS . $hE));
$hA = $render('admin', "year=$year&tab=event");
$hA2 = $render('admin', "year=$year&tab=survey");
ck('R6 admin เรนเดอร์ได้ทั้ง 2 แท็บ: เห็นกิจกรรมทุกหน่วยงาน + เลือกผู้จัด/ผู้จัดทำได้ในหน้าต่าง', $noErr($hA) && $noErr($hA2)
    && substr_count($hA, '<article class="co-card') === $nEvAll && strpos($hA, 'id="addOrg"') !== false && strpos($hA2, 'id="coSurveyMaker"') !== false,
    substr(preg_replace('/\s+/', ' ', $hA . $hA2), 0, 400));

// ── S การบันทึก / ความปลอดภัย ──
$fBefore = (int) $pdo->query("SELECT COUNT(*) FROM event_item WHERE id = " . (int) $foreign['id'])->fetchColumn();
$render('officer', "action=delete_item&tab=event&year_id=$year&event_id=$eid&item_id=" . (int) $foreign['id'], 'post');
$render('officer', "action=add_item&tab=event&year_id=$year&event_id=$eid&admin_item_id=1&vol=5", 'post');
ck('S1 คำสั่ง delete_item / add_item ถูกเอาออก: ส่ง POST ตรงแล้วรายการของหน่วยงานอื่นยังอยู่ และไม่มีรายการเพิ่ม',
    $fBefore === 1 && (int) $pdo->query("SELECT COUNT(*) FROM event_item WHERE id = " . (int) $foreign['id'])->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM event_item WHERE event_id = $eid AND admin_item_id = 1")->fetchColumn() === 0);

// บันทึกดูดกลับอัปเดต "ทุกแถว" ของกิจกรรม (ช่องที่ไม่ส่งมา = 0) → เก็บค่าเดิมทุกแถว ส่งค่าเดิมของแถวอื่นไปด้วย และคืนค่าทุกแถว
$rm = $pdo->query("SELECT id, qty FROM removal_event_item WHERE event_id = $eid ORDER BY id")->fetchAll();
try {
    $post = "action=save_event_removal&tab=event&year_id=$year&event_id=$eid&qty[{$rm[0]['id']}]=" . urlencode('1,500') . "&qty[{$rm[1]['id']}]=";
    foreach (array_slice($rm, 2) as $r) $post .= "&qty[{$r['id']}]=" . $r['qty'];
    $render('officer', $post, 'post');
    $st = $pdo->prepare('SELECT qty FROM removal_event_item WHERE id = ?');
    $st->execute([$rm[0]['id']]); $q0 = (float) $st->fetchColumn();
    $st->execute([$rm[1]['id']]); $q1 = (float) $st->fetchColumn();
    ck('S2 บันทึกดูดกลับจริง: "1,500" → 1500, ว่าง → 0', $q0 === 1500.0 && $q1 === 0.0, "q0=$q0 q1=$q1");
} finally {
    $up = $pdo->prepare('UPDATE removal_event_item SET qty = ? WHERE id = ? AND event_id = ?');
    foreach ($rm as $r) $up->execute([$r['qty'], $r['id'], $eid]);
}
$restored = $pdo->query("SELECT id, qty FROM removal_event_item WHERE event_id = $eid ORDER BY id")->fetchAll();
ck('S2b คืนค่าเดิมครบทุกแถวของกิจกรรม', $restored == $rm, json_encode($restored));

// S4 — บันทึกแบบสอบถามที่ ผู้ตอบ × ค่าเฉลี่ย เกินความจุ ผ่านหน้าเว็บจริง: ต้องแจ้งเหตุผลและไม่บันทึกอะไรเลย (ทั้งฟอร์ม)
//      หน้าเปิด/commit transaction เอง → จำแถวเดิมของ survey_summary + user_item(survey) ไว้ แล้วคืนใน finally ถ้าเปลี่ยน
$sq = $pdo->query("SELECT q.id, q.affiliation_id AS aff, q.year_id AS y, q.audience FROM questionnaire q
    WHERE (SELECT COUNT(*) FROM questionnaire_item qi WHERE qi.questionnaire_id = q.id) >= 2 ORDER BY q.id LIMIT 1")->fetch();
$sqItems = $pdo->query("SELECT id FROM questionnaire_item WHERE questionnaire_id = {$sq['id']} ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$ssSnap = fn() => $pdo->query("SELECT * FROM survey_summary WHERE affiliation_id = {$sq['aff']} AND year_id = {$sq['y']} ORDER BY id")->fetchAll();
$uiSnap = fn() => $pdo->query("SELECT * FROM user_item WHERE affiliation_id = {$sq['aff']} AND year_id = {$sq['y']} AND source = 'survey' ORDER BY id")->fetchAll();
$ss0 = $ssSnap(); $ui0 = $uiSnap();
$restoreRows = function (string $table, string $where, array $rows) use ($pdo) {
    $pdo->exec("DELETE FROM $table WHERE $where");
    foreach ($rows as $r) {
        $cols = array_keys($r);
        $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
    }
};
try {
    $post = 'action=save_survey&tab=survey&year_id=' . $sq['y'] . '&maker=' . $sq['aff'] . '&group=' . urlencode($sq['audience'])
        . "&resp[{$sqItems[0]}]=1000000&avg[{$sqItems[0]}]=5000"      // ผลคูณ 5,000,000,000 → เกิน
        . "&resp[{$sqItems[1]}]=10&avg[{$sqItems[1]}]=2";             // ค่าปกติในฟอร์มเดียวกัน → ต้องไม่ถูกบันทึกด้วย
    $out = $render('officer', $post, 'post', (int) $sq['aff']);
    ck('S4 บันทึกแบบสอบถามที่ ผู้ตอบ × ค่าเฉลี่ย เกิน 999,999,999 → แจ้งเหตุผล (สีแดง) · survey_summary / user_item(survey) ไม่เปลี่ยน · แถวปกติในฟอร์มเดียวกันก็ไม่ถูกบันทึก',
        $noErr($out) && str_contains($out, 'เกิน 999,999,999') && $ssSnap() == $ss0 && $uiSnap() == $ui0,
        'msg=' . (preg_match('/ผิดพลาด:[^<"]*/u', $out, $mm) ? $mm[0] : '-') . ' ss=' . count($ssSnap()) . '/' . count($ss0));
} finally {
    if ($ssSnap() != $ss0 || $uiSnap() != $ui0) {
        $restoreRows('user_item', "affiliation_id = {$sq['aff']} AND year_id = {$sq['y']} AND source = 'survey'", $ui0);
        $restoreRows('survey_summary', "affiliation_id = {$sq['aff']} AND year_id = {$sq['y']}", $ss0);
    }
}
ck('S4b ข้อมูลแบบสอบถามจริงเท่าเดิมหลังทดสอบ', $ssSnap() == $ss0 && $uiSnap() == $ui0);

// S5 — เพิ่มรายการ EF ของกิจกรรม: ปีของรายการต้องเป็นปีของกิจกรรม ไม่ใช่ year_id ที่ฟอร์มส่งมา
//      เดิมใช้ปีจากฟอร์ม → ปีไม่ตรง: รายงานคณะนับ (event_emission_list ไม่กรองปีรายการ) แต่รายงานทั้ง มพ. ไม่นับ → ผลรวมทุกคณะ ≠ ทั้ง มพ.
//      หน้าเว็บ commit เอง → ลบรายการที่สร้างใน finally (ยังไม่มีปริมาณ ไม่กระทบ event_item / user_item)
$wrongYear = (int) $pdo->query("SELECT id FROM admin_year WHERE id <> $year ORDER BY year DESC LIMIT 1")->fetchColumn();
$efCount = fn() => (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE data_source = 'event' AND event_id = $eid")->fetchColumn();
$ef0 = $efCount();
$label = 'รายการทดสอบปีกิจกรรม S5';
try {
    $out = $render('officer', "action=add_event_topic&tab=event&year_id=$wrongYear&event_id=$eid&label=" . urlencode($label)
        . '&unit=kWh&ad=0.5&group_id=' . (int) $pdo->query("SELECT id FROM admin_g WHERE scope = 3 ORDER BY id LIMIT 1")->fetchColumn(), 'post');
    $made = $pdo->prepare("SELECT year_id, event_id FROM admin_item WHERE data_source = 'event' AND event_id = ? AND name_tiem = ?");
    $made->execute([$eid, $label]); $made = $made->fetch();
    ck('S5 เพิ่มรายการ EF ของกิจกรรมโดยฟอร์มส่งปีอื่น → รายการเก็บปีของกิจกรรม ผูกกิจกรรมถูก ไม่มี error',
        // บันทึกสำเร็จ → redirect (ไม่มีเนื้อหา) จึงตรวจแค่ว่าไม่มีข้อความ error
        !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|ผิดพลาด)/', $out) && $made && (int) $made['year_id'] === $year && (int) $made['event_id'] === $eid,
        "wrongYear=$wrongYear eventYear=$year made=" . json_encode($made));
} finally {
    $pdo->prepare("DELETE FROM admin_item WHERE data_source = 'event' AND event_id = ? AND name_tiem = ?")->execute([$eid, $label]);
}
ck('S5b ลบรายการทดสอบแล้ว จำนวนรายการ EF ของกิจกรรมเท่าเดิม', $efCount() === $ef0);

// S6 — ค่าผิด (ติดลบ / ไม่ใช่ตัวเลข / เกิน 1,000,000) ผ่าน POST ตรง: ต้องแจ้งเหตุผลและไม่บันทึกทั้งฟอร์ม (เดิมแปลงเป็น 0 / ตัดเหลือเพดาน เงียบ ๆ)
$evRows = fn() => $pdo->query("SELECT * FROM event_item WHERE event_id = $eid ORDER BY id")->fetchAll();
$evUi   = fn() => $pdo->query("SELECT * FROM user_item WHERE affiliation_id = $aff AND year_id = $year AND source = 'event' ORDER BY id")->fetchAll();
$ev0 = $evRows(); $evUi0 = $evUi();
$evItems = $pdo->query("SELECT id FROM admin_item WHERE data_source = 'event' AND event_id = $eid ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
try {
    $post = "action=save_event_items&tab=event&year_id=$year&event_id=$eid";
    foreach ($evItems as $k => $id) $post .= "&vol[$id]=" . ($k === 0 ? '2000000' : '5');
    $out = $render('officer', $post, 'post');
    ck('S6 บันทึกปริมาณกิจกรรมที่มีช่องเกิน 1,000,000 → แจ้ง "เกิน 1,000,000" · event_item / user_item(event) ไม่เปลี่ยน (ช่องปกติก็ไม่ถูกบันทึก)',
        $evItems && $noErr($out) && str_contains($out, 'เกิน 1,000,000') && $evRows() == $ev0 && $evUi() == $evUi0,
        'items=' . count($evItems) . ' msg=' . (preg_match('/ผิดพลาด:[^<"]*/u', $out, $mm) ? $mm[0] : '-'));
} finally {
    if ($evRows() != $ev0 || $evUi() != $evUi0) {
        $restoreRows('user_item', "affiliation_id = $aff AND year_id = $year AND source = 'event'", $evUi0);
        $restoreRows('event_item', "event_id = $eid", $ev0);
    }
}
try {
    $post = 'action=save_survey&tab=survey&year_id=' . $sq['y'] . '&maker=' . $sq['aff'] . '&group=' . urlencode($sq['audience'])
        . "&resp[{$sqItems[0]}]=10&avg[{$sqItems[0]}]=-2&resp[{$sqItems[1]}]=abc&avg[{$sqItems[1]}]=3";
    $out = $render('officer', $post, 'post', (int) $sq['aff']);
    ck('S6b บันทึกแบบสอบถามที่ค่าเฉลี่ยติดลบ + ผู้ตอบไม่ใช่ตัวเลข → แจ้งเหตุผล (2 ช่อง) · ข้อมูลไม่เปลี่ยน',
        $noErr($out) && str_contains($out, '2 ช่อง') && str_contains($out, 'ติดลบ') && $ssSnap() == $ss0 && $uiSnap() == $ui0,
        'msg=' . (preg_match('/ผิดพลาด:[^<"]*/u', $out, $mm) ? $mm[0] : '-'));
} finally {
    if ($ssSnap() != $ss0 || $uiSnap() != $ui0) {
        $restoreRows('user_item', "affiliation_id = {$sq['aff']} AND year_id = {$sq['y']} AND source = 'survey'", $ui0);
        $restoreRows('survey_summary', "affiliation_id = {$sq['aff']} AND year_id = {$sq['y']}", $ss0);
    }
}
$efBad = 'รายการทดสอบ EF ผิด S6c';
try {
    $out = $render('officer', "action=add_event_topic&tab=event&year_id=$year&event_id=$eid&label=" . urlencode($efBad) . '&unit=kWh&ad=abc&group_id='
        . (int) $pdo->query("SELECT id FROM admin_g WHERE scope = 3 ORDER BY id LIMIT 1")->fetchColumn(), 'post');
    $made = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE event_id = $eid AND name_tiem = " . $pdo->quote($efBad))->fetchColumn();
    ck('S6c เพิ่มรายการ EF ของกิจกรรมที่ค่า EF ไม่ใช่ตัวเลข → แจ้งเหตุผล ไม่มีรายการเพิ่ม', $made === 0 && str_contains($out, 'ไม่ใช่ตัวเลข'),
        'made=' . $made . ' msg=' . (preg_match('/ผิดพลาด:[^<"]*/u', $out, $mm) ? $mm[0] : '-'));
} finally {
    $pdo->prepare("DELETE FROM admin_item WHERE data_source = 'event' AND event_id = ? AND name_tiem = ?")->execute([$eid, $efBad]);
}
ck('S6d ทุกคำสั่งที่รับค่าตัวเลขตรวจค่าก่อนเขียนฐานข้อมูล (officer_require_valid_vols มาก่อน INSERT/UPDATE/DELETE/ensure_questionnaire แรกของคำสั่ง)', (function () {
    $src = (string) file_get_contents(__DIR__ . '/../includes/collect_page.php');
    foreach (['add_topic', 'edit_topic', 'save_survey', 'add_event_topic', 'edit_event_topic', 'save_event_items', 'save_event_removal', 'add_removal_item', 'edit_event_removal'] as $act) {
        $start = strpos($src, "if (\$action === '$act')");
        if ($start === false) return false;
        $end = strpos($src, 'if ($action ===', $start + 10);
        $blk = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
        $chk = strpos($blk, 'officer_require_valid_vols(');
        preg_match('/INSERT INTO|UPDATE |DELETE FROM|ensure_questionnaire\(/', $blk, $w, PREG_OFFSET_CAPTURE);
        if ($chk === false || !isset($w[0]) || $chk > $w[0][1]) { echo "         ไม่ผ่าน: $act\n"; return false; }
    }
    return true;
})());

$src = file_get_contents(__DIR__ . '/../includes/collect_page.php');
ck('S3บันทึกแบบสอบถาม/กิจกรรมปล่อย ผ่านตัวตรวจค่าเดียวกัน (ไม่ใช่ (int)/(float) ตรง ๆ)',
    strpos($src, "\$r=collect_clean_count(\$resp[\$qiid]??'')") !== false && strpos($src, "\$a=officer_clean_vol(\$avg[\$qiid]??'')") !== false
    && strpos($src, '$v=officer_clean_vol($v)') !== false && strpos($src, "\$action === 'delete_item'") === false && strpos($src, "\$action === 'add_item'") === false);

// ── C สไตล์/แอนิเมชัน ──
$css = file_get_contents(__DIR__ . '/../assets/css/collect.css');
ck('C1 แถบแท็บเลื่อน + การ์ดลอยเมื่อชี้ + ตารางเป็นการ์ดตามพื้นที่เนื้อหา + ปิดแอนิเมชันเมื่อผู้ใช้ตั้งลดการเคลื่อนไหว',
    strpos($css, '.co-tabs[data-tab="event"] .co-tab-ind { transform: translateX(100%); }') !== false
    && strpos($css, '.co-card:hover { transform: translateY(-3px);') !== false
    && strpos($css, '@container (max-width: 860px)') !== false && strpos($css, '@media (prefers-reduced-motion: reduce)') !== false);

@unlink($probe);   // ไฟล์ probe ชั่วคราว
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
