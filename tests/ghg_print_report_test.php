<?php
/**
 * Unit Test — รายงาน PDF ฉบับผู้บริหาร (dean/report_print.php) + ฟังก์ชันสรุปกลาง
 * รัน: C:\xampp\php\php.exe tests\ghg_print_report_test.php
 *
 * ล็อกกฎ:
 *   A. ghg_combine_totals() / ghg_report_summary() ให้ตัวเลขเดียวกับสูตรเดิมของหน้าเว็บ
 *   B. ghg_prev_year() เลือกปี พ.ศ. ก่อนหน้า (ไม่ใช่ year_id - 1)
 *   C. ghg_change() ไม่หารด้วยศูนย์ / ไม่มีปีก่อน → null
 *   D. ghg_top_share() หาแหล่งหลักถูก และคืน null เมื่อไม่มีข้อมูล
 *   E. ghg_category_totals() ผลรวมรายหมวด == ยอดรายขอบเขตของรายงาน (ทั้งคณะและทั้งระบบ)
 *   F. เรนเดอร์ report_print.php ได้ทั้ง 2 มุมมอง และตัวเลขตรงกับฟังก์ชันสรุป
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
$deans = $pdo->query("SELECT id, username, Affiliation FROM users WHERE role = 'dean' AND Affiliation > 0")->fetchAll();

// ═══ A. ยอดสรุป ═══
echo "A. ghg_combine_totals / ghg_report_summary\n";
$c = ghg_combine_totals([1 => 1.0, 2 => 2.0, 3 => 3.0],
    [['scope' => 1, 'emission' => 0.5], ['scope' => 3, 'emission' => 1.5], ['scope' => 9, 'emission' => 4.0]], 2.0);
ck('A1 gross รายขอบเขต = ดำเนินงาน + กิจกรรม', $c['scope'] === [1 => 1.5, 2 => 2.0, 3 => 4.5]);
ck('A1b event_total นับทุกแถว (รวม scope นอก 1-3) เหมือนการ์ดเดิม', feq($c['event_total'], 6.0));
ck('A1c gross / net ถูกต้อง', feq($c['gross'], 8.0) && feq($c['net'], 6.0) && feq($c['operation_total'], 6.0));
ck('A1d ข้อมูลว่าง → 0 ทั้งหมด', ghg_combine_totals([], [], 0.0)['net'] === 0.0);
$cs = ghg_combine_totals([1 => 1.0, 2 => 2.0, 3 => 3.0], [], 0.0,
    [['scope' => 3, 'emission' => 0.25], ['scope' => 3, 'emission' => 0.75], ['scope' => 1, 'emission' => 9.0]]);
ck('A1e แบบสอบถามนับเข้าขอบเขต 3 เท่านั้น (แถวขอบเขตอื่นไม่นับ)', $cs['scope'] === [1 => 1.0, 2 => 2.0, 3 => 4.0]
    && feq($cs['survey_total'], 1.0) && feq($cs['gross'], 7.0) && ghg_combine_totals([], [], 0.0)['survey_total'] === 0.0);

// A2 — เทียบกับสูตรเดิมของหน้าเว็บ (คัดลอกมาจาก reports.php ก่อน refactor) บนข้อมูลจริง
$checked = 0;
foreach ($deans as $d) {
    $aff = (int) $d['Affiliation'];
    foreach ($years as $y) {
        $yid = (int) $y['year_id'];
        $sum = ghg_report_summary($pdo, $yid, $aff);

        $scope = ghg_scope_totals($pdo, $yid, $aff);
        $ev    = event_emission_list($pdo, $yid, $aff);
        $gross = $scope; $evt = 0.0;
        foreach ($ev as $r) { $evt += (float)$r['emission']; $gross[(int)$r['scope']] += (float)$r['emission']; }
        // แบบสอบถามของคณะ นับเฉพาะขอบเขต 3
        $svq = $pdo->prepare("SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id
                              JOIN admin_g ag ON ag.id = ai.scope WHERE ui.year_id = ? AND ui.affiliation_id = ? AND ui.source = 'survey' AND ag.scope = 3");
        $svq->execute([$yid, $aff]);
        $gross[3] += (float) $svq->fetchColumn();
        $gt  = $gross[1] + $gross[2] + $gross[3];
        $rem = removal_activity_total($pdo, $yid, $aff);

        $ok = feq($sum['gross'], $gt) && feq($sum['event_total'], $evt) && feq($sum['removal'], $rem)
           && feq($sum['net'], $gt - $rem) && feq($sum['gross'], ghg_gross_total($pdo, $yid, $aff));
        ck("A2 {$d['username']} ปี {$y['year']}: summary == สูตรเดิม", $ok,
            sprintf('gross %.6f/%.6f net %.6f/%.6f', $sum['gross'], $gt, $sum['net'], $gt - $rem));
        $checked++;
    }
}
foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    $sum = ghg_report_summary($pdo, $yid, null);
    ck("A3 ทั้งระบบ ปี {$y['year']}: ไม่บวกกิจกรรมซ้ำ + ดูดกลับระดับมหาวิทยาลัย",
        $sum['scope'] === ghg_scope_totals($pdo, $yid, null)
        && feq($sum['removal'], removal_total($pdo, $yid)) && $sum['event_rows'] === []);
}
ck('A4 มีเคสข้อมูลจริงให้ทดสอบ', $checked > 0);

// ═══ B. ปีก่อนหน้า ═══
echo "\nB. ghg_prev_year\n";
$fakeYears = [['year_id' => 25, 'year' => 2569], ['year_id' => 15, 'year' => 2568], ['year_id' => 14, 'year' => 2567]];
ck('B1 id ไม่ต่อเนื่อง (25 → 15) ยังได้ปีก่อนหน้าถูก', (ghg_prev_year($fakeYears, 25)['year'] ?? null) === 2568);
ck('B2 ปีแรกสุด → null', ghg_prev_year($fakeYears, 14) === null);
ck('B3 ไม่พบปีที่เลือก → null', ghg_prev_year($fakeYears, 999) === null);
ck('B4 ไม่ขึ้นกับลำดับ array', (ghg_prev_year(array_reverse($fakeYears), 25)['year'] ?? null) === 2568);
ck('B5 ข้ามปีที่หายไป (2569 → 2567)', (ghg_prev_year([$fakeYears[0], $fakeYears[2]], 25)['year'] ?? null) === 2567);

// ═══ C. การเปลี่ยนแปลง ═══
echo "\nC. ghg_change\n";
ck('C1 เพิ่มขึ้น 50%', feq(ghg_change(15, 10)['pct'], 50) && feq(ghg_change(15, 10)['diff'], 5));
ck('C2 ลดลง 25%', feq(ghg_change(7.5, 10)['pct'], -25));
ck('C3 ไม่มีปีก่อน → null ทั้งคู่', ghg_change(5, null) === ['diff' => null, 'pct' => null]);
ck('C4 ปีก่อนเป็น 0 → pct null (ไม่หารศูนย์)', ghg_change(5, 0.0)['pct'] === null && feq(ghg_change(5, 0.0)['diff'], 5));
ck('C5 Net ติดลบ: -10 → -5 = ดีขึ้น (+50%)', feq(ghg_change(-5, -10)['pct'], 50));

// ═══ D. แหล่งหลัก ═══
echo "\nD. ghg_top_share\n";
$t = ghg_top_share([1 => 2.0, 2 => 6.0, 3 => 2.0]);
ck('D1 เลือกค่ามากสุด + สัดส่วน', $t['key'] === 2 && feq($t['pct'], 60));
ck('D2 ค่าเท่ากัน → ตัวแรก', ghg_top_share([1 => 3.0, 2 => 3.0, 3 => 0.0])['key'] === 1);
ck('D3 ไม่มีข้อมูล → null', ghg_top_share([1 => 0, 2 => 0, 3 => 0]) === null && ghg_top_share([]) === null);

// ═══ E. หมวดแหล่งปล่อย ═══
echo "\nE. ghg_category_totals\n";
$nGroups = (int) $pdo->query('SELECT COUNT(*) FROM admin_g')->fetchColumn();
$cases = [];
foreach ($deans as $d) $cases[] = [(int) $d['Affiliation'], $d['username']];
$cases[] = [null, 'ทั้งระบบ'];
$nonZero = 0;
foreach ($cases as [$aff, $label]) {
    foreach ($years as $y) {
        $yid  = (int) $y['year_id'];
        $cats = ghg_category_totals($pdo, $yid, $aff);
        $bySc = [1 => 0.0, 2 => 0.0, 3 => 0.0];
        foreach ($cats as $c) $bySc[$c['scope']] += $c['value'];
        $sum = ghg_report_summary($pdo, $yid, $aff);
        if ($sum['gross'] > 0) $nonZero++;
        ck("E1 $label ปี {$y['year']}: ผลรวมรายหมวด == ยอดรายขอบเขต",
            feq($bySc[1], $sum['scope'][1]) && feq($bySc[2], $sum['scope'][2]) && feq($bySc[3], $sum['scope'][3]),
            sprintf('S1 %.6f/%.6f S2 %.6f/%.6f S3 %.6f/%.6f', $bySc[1], $sum['scope'][1], $bySc[2], $sum['scope'][2], $bySc[3], $sum['scope'][3]));
        ck("E2 $label ปี {$y['year']}: คืนครบทุกหมวด ($nGroups)", count($cats) === $nGroups);
    }
}
ck('E3 มีเคสที่ยอด > 0 (ไม่ใช่ผ่านเพราะว่าง)', $nonZero > 0);

// ═══ F. เรนเดอร์หน้า PDF ═══
echo "\nF. เรนเดอร์ report_print.php\n";
$probe = sys_get_temp_dir() . '/print_render_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["view" => $argv[2], "year" => (int)$argv[3]];
session_start();
$_SESSION = ["user_id"=>(int)$argv[5],"role"=>$argv[1],"affiliation_id"=>(int)$argv[4],"affiliation_name"=>"คณะทดสอบ","last_activity"=>time()];
ob_start(); require $root . "/dean/report_print.php"; echo ob_get_clean();
');
$render = fn(string $role, string $view, int $year, int $aff, int $uid) => (string) shell_exec(sprintf('%s %s %s %s %d %d %d 2>&1',
    escapeshellarg(PHP_BINARY), escapeshellarg($probe), $role, $view, $year, $aff, $uid));
$fmt = fn($v) => number_format((float) $v, 2, '.', ',');

$d0    = $deans[0];
$aff0  = (int) $d0['Affiliation'];
$cur   = (int) $years[0]['year_id'];          // ปีล่าสุด (มีปีก่อนหน้า)
$first = (int) end($years)['year_id'];        // ปีแรก (ไม่มีปีก่อนหน้า)

foreach ([['dean', 'faculty', $aff0], ['admin', 'system', $aff0]] as [$role, $view, $aff]) {
    $html = $render($role, $view, $cur, $aff, (int) $d0['id']);
    $sum  = ghg_report_summary($pdo, $cur, $view === 'faculty' ? $aff : null);

    ck("F1 [$view] เรนเดอร์ได้ ไม่มี PHP error/warning",
        $html !== '' && stripos($html, 'Fatal error') === false && stripos($html, 'Warning:') === false && stripos($html, 'Notice:') === false,
        substr($html, 0, 300));
    ck("F2 [$view] มีปก + หัวข้อหลักครบ", (function () use ($html, $view) {
        $need = ['รายงานคาร์บอนฟุตพริ้นท์ขององค์กร', 'บทสรุปผู้บริหาร', 'ผลการคำนวณแยกตามแหล่งปล่อย', 'วิธีการคำนวณ', 'ผู้จัดทำ', 'ผู้ตรวจสอบ'];
        if ($view === 'system') $need[] = 'การปล่อยรายหน่วยงาน';
        foreach ($need as $s) if (strpos($html, $s) === false) return false;
        return true;
    })());
    ck("F3 [$view] ตัวเลขปีนี้ (gross/ดูดกลับ/Net) ตรงกับ summary",
        strpos($html, $fmt($sum['gross'])) !== false && strpos($html, $fmt($sum['removal'])) !== false
        && strpos($html, $fmt($sum['net'])) !== false);
    // F4 — เปรียบเทียบตามกติกา ghg_year_comparison(): ปีก่อนไม่มีข้อมูลการดำเนินงาน → ไม่มีคอลัมน์ปีก่อน/% และไม่มีหมายเหตุ (ผู้ใช้ขอเอาออก)
    $cmpT = ghg_year_comparison($pdo, $years, $cur, $view === 'faculty' ? $aff : null);
    if ($cmpT['prev'] === null) {
        ck("F4 [$view] ไม่เทียบ ({$cmpT['state']}) → ไม่มีคอลัมน์เปลี่ยนแปลง/ป้าย % และไม่มีหมายเหตุ",
            strpos($html, '<th class="num">เปลี่ยนแปลง</th>') === false && strpos($html, 'class="chg ') === false
            && strpos($html, 'cmp-note') === false && strpos($html, 'ไม่เปรียบเทียบกับปี') === false);
    } else {
        ck("F4 [$view] เทียบได้ → มีตัวเลข Net ปีก่อน", strpos($html, $fmt($cmpT['prev']['net'])) !== false);
    }
    ck("F5 [$view] ตารางแหล่งปล่อยมีครบทุกหมวด", substr_count($html, 'class="cat-row') === $nGroups,
        'พบ ' . substr_count($html, 'class="cat-row'));
    ck("F5b [$view] จัดกลุ่มตามขอบเขต 3 กลุ่ม + ยอดย่อยตรงกับ summary", (function () use ($html, $sum, $fmt) {
        if (substr_count($html, 'class="scope-row"') !== 3) return false;
        preg_match_all('#<tr class="scope-row">.*?<td class="num">([^<]+)</td>#s', $html, $m);
        return $m[1] === [$fmt($sum['scope'][1]), $fmt($sum['scope'][2]), $fmt($sum['scope'][3])];
    })());
    ck("F5c [$view] หน้า 1 มีการ์ดสัดส่วนขอบเขต 3 ใบ", substr_count($html, 'class="scope-tile"') === 3);
    ck("F5e [$view] ตารางสรุปไม่มีแถวขอบเขตซ้ำกับการ์ด แต่ยังมีรวม/ดูดกลับ/Net", (function () use ($html, $view) {
        if (!preg_match('#<table class="summary-table">.*?</table>#s', $html, $t)) return false;
        $rows = substr_count($t[0], '<tr class=');
        return strpos($t[0], 'ขอบเขต') === false
            && strpos($t[0], 'รวมการปล่อยทั้งหมด') !== false && strpos($t[0], 'การดูดกลับ') !== false && strpos($t[0], 'การปล่อยสุทธิ') !== false
            && $rows === ($view === 'faculty' ? 6 : 3);
    })());
    $offT = ghg_offset_pct($sum['gross'], $sum['removal']);
    ck("F5d [$view] แถบดูดกลับชดเชยได้ตรงกับ ghg_offset_pct()", $offT === null
        ? strpos($html, 'class="offset"') === false
        : strpos($html, $offT >= 100 ? 'ครบ 100%' : number_format($offT, 1) . '%</b>') !== false);
    ck("F6 [$view] ไม่เหลือคำว่า \"แยกจากยอดหลัก\"", strpos($html, 'แยกจากยอดหลัก') === false);
    ck("F7 [$view] ชื่อผู้จัดทำมาจากฐานข้อมูล", (function () use ($html, $pdo, $d0) {
        $u = $pdo->query('SELECT firstname, lastname FROM users WHERE id = ' . (int) $d0['id'])->fetch();
        return strpos($html, htmlspecialchars(trim($u['firstname'] . ' ' . $u['lastname']), ENT_QUOTES)) !== false;
    })());
    if ($view === 'faculty') {
        ck('F8 [faculty] แยกบรรทัดดำเนินงาน/กิจกรรม ตรงกับ summary',
            strpos($html, 'จากกิจกรรมที่คณะจัด') !== false && strpos($html, $fmt($sum['event_total'])) !== false
            && strpos($html, $fmt($sum['operation_total'])) !== false);
    } else {
        $rows = array_filter(ghg_by_affiliation($pdo, $cur), fn($r) => (float) $r['total_emission'] > 0);
        ck('F8 [system] แถวรวมรายหน่วยงาน == ghg_affil_sum()', strpos($html, $fmt(ghg_affil_sum($rows))) !== false);
    }
}

$htmlFirst = $render('dean', 'faculty', $first, $aff0, (int) $d0['id']);
ck('F9 ปีแรก: ไม่พัง ไม่มีคอลัมน์เปรียบเทียบ และไม่มีหมายเหตุ',
    stripos($htmlFirst, 'Warning:') === false && stripos($htmlFirst, 'Fatal error') === false
    && strpos($htmlFirst, '<th class="num">เปลี่ยนแปลง</th>') === false
    && strpos($htmlFirst, 'ไม่มีปีก่อนหน้าให้เปรียบเทียบ') === false);
$htmlBad = $render('dean', 'faculty', 999999, $aff0, (int) $d0['id']);
ck('F10 ปีที่ไม่มีอยู่ → ใช้ปีล่าสุด (หัวรายงานไม่ว่าง)',
    strpos($htmlBad, '<b>' . $years[0]['year'] . '</b>') !== false);
ck('F10b วันที่จัดทำเป็นเวลาไทย (Asia/Bangkok)', (function () use ($htmlBad) {
    $th = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    return strpos($htmlBad, '· ' . $th->format('d/m/Y')) !== false;
})());
$htmlNoAff = $render('dean', 'faculty', $cur, 0, (int) $d0['id']);
ck('F11 dean ไม่มีสังกัด → มีคำเตือน', strpos($htmlNoAff, 'ยังไม่ได้ผูกกับคณะ') !== false);
@unlink($probe);

// ═══ H. กติกาการเปรียบเทียบปีต่อปี ═══
echo "\nH. การเปรียบเทียบกับปีก่อน\n";
ck('H1 ไม่มีปีก่อน → none', ghg_compare_state(false, 700, 0) === 'none');
ck('H2 ปีก่อนไม่มีรายการดำเนินงาน → nodata (ไม่เทียบ)', ghg_compare_state(true, 703, 0) === 'nodata');
ck('H3 ปีก่อนมีน้อยกว่าครึ่ง → low (เทียบแต่เตือน)', ghg_compare_state(true, 700, 100) === 'low');
ck('H3b ครึ่งพอดี → ok', ghg_compare_state(true, 700, 350) === 'ok');
ck('H4 ปีนี้ไม่มีข้อมูลแต่ปีก่อนมี → ok (ลดลงจริง ต้องเห็น)', ghg_compare_state(true, 0, 50) === 'ok');

ck('H6 ป้ายเพิ่มขึ้น (ปล่อย) = bad', ghg_change_badge(12, 10) === ['text' => '▲ +20.0%', 'tone' => 'bad']);
ck('H6b ป้ายเพิ่มขึ้น (ดูดกลับ) = good', ghg_change_badge(12, 10, true)['tone'] === 'good');
ck('H6c ป้ายลดลง (ปล่อย) = good', ghg_change_badge(8, 10) === ['text' => '▼ −20.0%', 'tone' => 'good']);
ck('H6d ปีก่อน 0 → —', ghg_change_badge(5, 0.0)['text'] === '—');

// H7 — ข้อมูลจริง: ghg_entry_count ตรงกับการนับตรง ๆ และปีที่เทียบไม่ได้ต้องไม่มียอดปีก่อน
foreach ($years as $y) {
    $yid = (int) $y['year_id'];
    $direct = (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE year_id = $yid AND source = 'officer'")->fetchColumn();
    ck("H7 ปี {$y['year']}: ghg_entry_count(ทั้งระบบ) == COUNT ตรง", ghg_entry_count($pdo, $yid) === $direct);
    $c = ghg_year_comparison($pdo, $years, $yid, $aff0);
    ck("H7b ปี {$y['year']} ({$c['state']}): มียอดปีก่อนเฉพาะ ok/low",
        ($c['prev'] !== null) === in_array($c['state'], ['ok', 'low'], true));
}
// H8 — ปีของกราฟประวัติ: ปีที่เลือก + ย้อนหลัง 1 ปี (ข้อมูลสังเคราะห์ id ไม่เรียง)
$hy = fn(array $ys, int $id, int $back = 1) => array_column(ghg_history_years($ys, $id, $back), 'year');
ck('H8 ปีล่าสุด → ปีนี้ + ปีก่อน (ใหม่ -> เก่า)', $hy($fakeYears, 25) === [2569, 2568]);
ck('H8b เลือกปีกลาง → ไม่รวมปีที่ใหม่กว่า', $hy($fakeYears, 15) === [2568, 2567]);
ck('H8c ปีแรก → มีปีเดียว', $hy($fakeYears, 14) === [2567]);
ck('H8d ไม่ขึ้นกับลำดับ array', $hy(array_reverse($fakeYears), 25) === [2569, 2568]);
ck('H8e ไม่พบปี → []', $hy($fakeYears, 999) === []);
ck('H8f back=2 → 3 ปี', $hy($fakeYears, 25, 2) === [2569, 2568, 2567]);

// H9 — ดูดกลับชดเชยได้ (%)
ck('H9 ชดเชยได้บางส่วน', feq(ghg_offset_pct(1.5, 1.03), 1.03 / 1.5 * 100));
ck('H9b ไม่มีการปล่อย → null', ghg_offset_pct(0.0, 5.0) === null);
ck('H9c ดูดกลับเกินการปล่อย → เกิน 100 (ไม่ตัดทิ้ง)', feq(ghg_offset_pct(2.0, 3.0), 150));
ck('H9d ดูดกลับติดลบ (ข้อมูลผิด) → 0', feq(ghg_offset_pct(2.0, -1.0), 0));

// H10 — จัดหมวดตามขอบเขต
$grp = ghg_categories_by_scope([
    ['id' => 1, 'scope' => 1, 'name' => 'a', 'value' => 1.0], ['id' => 2, 'scope' => 1, 'name' => 'b', 'value' => 2.0],
    ['id' => 3, 'scope' => 3, 'name' => 'c', 'value' => 0.5], ['id' => 9, 'scope' => 7, 'name' => 'x', 'value' => 9.0],
]);
ck('H10 ครบ 3 ขอบเขตเสมอ + ยอดย่อยถูก', array_keys($grp) === [1, 2, 3] && feq($grp[1]['total'], 3.0)
    && feq($grp[2]['total'], 0.0) && feq($grp[3]['total'], 0.5));
ck('H10b คงลำดับเดิมในกลุ่ม และทิ้งขอบเขตนอก 1-3', array_column($grp[1]['items'], 'name') === ['a', 'b']
    && count($grp[1]['items']) + count($grp[2]['items']) + count($grp[3]['items']) === 3);

// H11 — ความสูงแท่งกราฟประวัติ
$hb = ghg_history_bars([['year' => '2568', 's1' => 0, 's2' => 0.5, 's3' => 0], ['year' => '2569', 's1' => 2.0, 's2' => 1.0, 's3' => 0.25]]);
ck('H11 ทุกปีใช้สเกลเดียวกัน (ค่าสูงสุด = 100%)', feq($hb[1]['h1'], 100) && feq($hb[0]['h2'], 25) && feq($hb[1]['h3'], 12.5));
ck('H11b ยอดรวมรายปีถูก', feq($hb[0]['total'], 0.5) && feq($hb[1]['total'], 3.25));
ck('H11c ไม่มีข้อมูลเลย → 0 ไม่หารศูนย์', ghg_history_bars([['year' => '1', 's1' => 0, 's2' => 0, 's3' => 0]])[0]['h1'] === 0.0);
$hs = ghg_history_bars([['year' => '2568', 's1' => 0, 's2' => 0, 's3' => 0.14], ['year' => '2569', 's1' => 10500.0, 's2' => 2250.0, 's3' => 30000.0]], 15000.0);
ck('H11d สเกลคงที่: ความสูง = ค่า ÷ สเกล, ค่าเกินสเกลขยายตามค่าจริง', feq($hs[1]['h3'], 100) && feq($hs[1]['h1'], 35) && feq($hs[0]['h3'], 0.14 / 300));
ck('H11e ไม่ส่งสเกล → เหมือนเดิม', ghg_history_bars([['year' => '1', 's1' => 2, 's2' => 1, 's3' => 0]]) === ghg_history_bars([['year' => '1', 's1' => 2, 's2' => 1, 's3' => 0]], 0.0));

// ═══ W. หน้าเว็บมุมมองคณะ (reports.php) ═══
echo "\nW. หน้าเว็บมุมมองคณะ\n";
$probeW = sys_get_temp_dir() . '/reports_faculty_probe.php';
file_put_contents($probeW, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["view" => $argv[2], "year" => (int)$argv[3]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>$argv[1],"affiliation_id"=>(int)$argv[4],"affiliation_name"=>"คณะทดสอบ","last_activity"=>time()];
ob_start(); require $root . "/dean/reports.php"; echo ob_get_clean();
');
$web = (string) shell_exec(sprintf('%s %s dean faculty %d %d 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probeW), $cur, $aff0));
$sumW = ghg_report_summary($pdo, $cur, $aff0);
$cmpW = ghg_year_comparison($pdo, $years, $cur, $aff0);
ck('W1 เรนเดอร์ได้ ไม่มี PHP error', $web !== '' && stripos($web, 'Fatal error') === false && stripos($web, 'Warning:') === false);
ck('W2 KPI 3 ใบ (ปล่อย/ดูดกลับ/Net) — ไม่มีการ์ดแหล่งปล่อยหลักแล้ว', substr_count($web, ' rpf-kpi oe-rise"') === 3 && strpos($web, 'แหล่งปล่อยหลัก') === false);
$barsW = ghg_history_bars(ghg_scope_history($pdo, ghg_history_years($years, $cur, 1), $aff0));
ck('W2b กราฟประวัติเป็น HTML (ไม่ใช่ canvas ที่ตัวอักษรเล็ก) ครบทุกปี × 3 ขอบเขต',
    strpos($web, 'id="scopeHistory"') === false
    && preg_match_all('/class="rpf-hist-year(?: is-cur)?"/', $web) === count($barsW)
    && substr_count($web, 'class="rpf-hist-bar"') === count($barsW) * 3);
ck('W2c ป้ายยอดรวมรายปีตรงกับข้อมูล', (function () use ($web, $barsW) {
    foreach ($barsW as $b) if (strpos($web, 'รวม ' . number_format($b['total'], 2)) === false) return false;
    return true;
})());
$offW = ghg_offset_pct(ghg_report_summary($pdo, $cur, $aff0)['gross'], ghg_report_summary($pdo, $cur, $aff0)['removal']);
ck('W2d การ์ด Net มีแถบดูดกลับชดเชยได้ ตรงกับ ghg_offset_pct()', $offW === null
    ? strpos($web, 'rpf-offset') === false
    : strpos($web, $offW >= 100 ? 'ครบ 100%' : number_format($offW, 1) . '%</b>') !== false);
ck('W2e กลางโดนัทเป็นยอดรวมของขอบเขต (data-center)', (function () use ($web, $pdo, $aff0, $cur) {
    $sm = ghg_report_summary($pdo, $cur, $aff0);   // ขอบเขต 3 รวมกิจกรรม/แบบสอบถาม เหมือนหน้าเว็บ
    $bd = ghg_scope_item_breakdown(ghg_affil_detail($pdo, $aff0, $cur), 8, $sm['event_rows'], $sm['survey_rows']);
    foreach ([1, 2, 3] as $s) {
        if (strpos($web, 'id="scopeItemDonut' . $s . '" width="200" height="200" data-center="' . number_format($bd[$s]['total'], 2, '.', ',') . '"') === false) return false;
    }
    return true;
})());
ck('W2f คำอธิบายโดนัทแสดงเป็น % (ค่า tCO₂e เต็มอยู่ใน title)', (function () use ($web) {
    preg_match_all('#class="rpf-legend" title="[^"]*tCO₂e">.*?<b>([0-9.]+)%</b>#s', $web, $m);
    return count($m[1]) > 0 && substr_count($web, 'class="rpf-legend"') === count($m[1]);
})());
ck('W3 ยอดปล่อยทั้งหมด/Net ตรงกับ summary',
    strpos($web, $fmt($sumW['gross'])) !== false && strpos($web, $fmt($sumW['net'])) !== false);
ck('W4 ป้าย % แสดงเฉพาะเมื่อเทียบได้', ($cmpW['prev'] !== null) === (strpos($web, 'rpf-chip') !== false));
ck('W5 หน้าเว็บไม่แสดงหมายเหตุการเปรียบเทียบ (ผู้ใช้ขอเอาออก)',
    strpos($web, 'rpf-note') === false && strpos($web, 'ไม่เปรียบเทียบกับปี') === false);
ck('W6 หมวดแหล่งปล่อยครบทุกหมวด', substr_count($web, 'class="rpf-cat"') === $nGroups, 'พบ ' . substr_count($web, 'class="rpf-cat"'));
ck('W7 สไตล์ของหน้ารายงานอยู่ใน assets/css/reports.css ที่โหลดใน <head> (SPA router โหลด stylesheet ใหม่ให้) · ไม่มี <style> ของหน้าใน <main>', (function () use ($web) {
    $h = strpos($web, '</head>'); $css = strpos($web, 'assets/css/reports.css');
    return $h !== false && $css !== false && $css < $h && strpos($web, '.rpf-kpis {') === false
        && is_file(__DIR__ . '/../assets/css/reports.css');
})());
@unlink($probeW);

// ═══ X. ไฟล์ Excel (export_report.php) จัดกลุ่มตามขอบเขตแบบ PDF ═══
echo "\nX. ไฟล์ Excel\n";

// X0 — ฟังก์ชันกลาง (ไม่แตะฐานข้อมูล)
$lf = ghg_summary_lines('faculty'); $ls = ghg_summary_lines('system');
ck('X0 บทสรุปคณะ: รวม → ดำเนินงาน → กิจกรรม → แบบสอบถาม → ดูดกลับ → Net',
    array_column($lf, 'key') === ['gross', 'operation_total', 'event_total', 'survey_total', 'removal', 'net']);
ck('X0b บทสรุปทั้งระบบไม่มีแถวดำเนินงาน/กิจกรรม', array_column($ls, 'key') === ['gross', 'removal', 'net']);
$dg = ghg_detail_by_scope(
    [['scope' => 1, 'activity_type' => 'การเผาไหม้อยู่กับที่ (Stationary Combustion)', 'name_tiem' => 'ดีเซล', 'unit' => 'L', 'vol' => 10, 'emission' => 2.0]],
    [['scope' => 1, 'activity_type' => 'x', 'name_tiem' => 'LPG', 'unit' => 'kg', 'qty' => 5, 'emission' => 0.5, 'event_name' => 'งาน A'],
     ['scope' => 9, 'name_tiem' => 'นอกขอบเขต', 'qty' => 1, 'emission' => 99.0, 'event_name' => 'งาน B']]);
$dgs = ghg_detail_by_scope([], [], [['scope' => 3, 'activity_type' => 'x', 'name_tiem' => 'เดินทาง', 'unit' => 'km', 'qty' => 500, 'emission' => 0.4],
                                  ['scope' => 1, 'name_tiem' => 'ผิดขอบเขต', 'qty' => 1, 'emission' => 9.0]]);
ck('X0e แบบสอบถามอยู่ในกลุ่มขอบเขต 3 เท่านั้น', count($dgs[3]['rows']) === 1 && $dgs[3]['rows'][0]['source'] === 'survey'
    && feq($dgs[3]['total'], 0.4) && !$dgs[1]['rows']);
ck('X0c รวมรายการกิจกรรมไว้ในขอบเขตเดียวกัน + ยอดย่อยถูก', count($dg[1]['rows']) === 2 && feq($dg[1]['total'], 2.5)
    && $dg[1]['rows'][1]['source'] === 'event' && $dg[1]['rows'][1]['event'] === 'งาน A' && feq($dg[1]['rows'][1]['qty'], 5));
ck('X0d ครบ 3 ขอบเขต, ทิ้งขอบเขตนอก 1-3, ย่อชื่อหมวด', array_keys($dg) === [1, 2, 3] && $dg[3]['total'] === 0.0
    && $dg[1]['rows'][0]['category'] === 'การเผาไหม้อยู่กับที่');

// X1+ — เรนเดอร์ไฟล์จริง แล้วอ่านเป็น XML (Excel เปิดไม่ได้ถ้า XML เสีย)
$probeX = sys_get_temp_dir() . '/export_render_probe.php';
file_put_contents($probeX, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = ["view" => $argv[2], "year" => (int)$argv[3]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>$argv[1],"affiliation_id"=>(int)$argv[4],"affiliation_name"=>"คณะทดสอบ","last_activity"=>time()];
ob_start(); require $root . "/dean/export_report.php"; echo ob_get_clean();
');
/** อ่านแผ่นงานเป็นแถวของเซลล์ [['v'=>ค่า, 't'=>ชนิด, 's'=>style], ...] */
$sheetRows = function (SimpleXMLElement $ws): array {
    $out = [];
    foreach ($ws->children('urn:schemas-microsoft-com:office:spreadsheet')->Table->Row as $row) {
        $cells = [];
        foreach ($row->Cell as $c) {
            $a = $c->attributes('urn:schemas-microsoft-com:office:spreadsheet');
            $d = $c->Data;
            $cells[] = ['v' => $d !== null ? (string) $d : '', 't' => $d !== null ? (string) $d->attributes('urn:schemas-microsoft-com:office:spreadsheet')['Type'] : '', 's' => (string) $a['StyleID']];
        }
        $out[] = $cells;
    }
    return $out;
};
foreach ([['dean', 'faculty', $aff0], ['admin', 'system', $aff0]] as [$role, $view, $aff]) {
    $raw  = (string) shell_exec(sprintf('%s %s %s %s %d %d 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probeX), $role, $view, $cur, $aff));
    $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $xml  = @simplexml_load_string($raw);
    ck("X1 [$view] เป็น XML ที่ถูกต้อง (Excel เปิดได้) ไม่มี PHP error", $xml !== false && stripos($raw, 'Warning:') === false,
        substr($raw, 0, 200));
    if ($xml === false) continue;
    $sheets = $xml->children('urn:schemas-microsoft-com:office:spreadsheet')->Worksheet;
    $names  = [];
    foreach ($sheets as $ws) $names[] = (string) $ws->attributes('urn:schemas-microsoft-com:office:spreadsheet')['Name'];
    ck("X2 [$view] แผ่นงาน", $names === ($view === 'faculty' ? ['สรุป', 'รายการ'] : ['สรุป']), implode(',', $names));

    $sumX = ghg_report_summary($pdo, $cur, $view === 'faculty' ? $aff : null);
    $rowsS = $sheetRows($sheets[0]);
    $find  = function (string $label) use ($rowsS) { foreach ($rowsS as $r) if (($r[0]['v'] ?? '') === $label) return $r; return null; };
    // X3 — บทสรุป: ตัวเลขเป็นชนิด Number และตรงกับ summary
    $okLines = true;
    foreach (ghg_summary_lines($view) as $l) {
        $r = $find($l['label'] . ($l['desc'] !== '' ? ' · ' . $l['desc'] : ''));
        $last = $r ? end($r) : null;
        // ไม่มีคอลัมน์เปรียบเทียบ (ข้อมูลทดสอบปีก่อนไม่มีการดำเนินงาน) → เซลล์สุดท้ายคือปีนี้
        if (!$r || $last['t'] !== 'Number' || !feq($last['v'], $sumX[$l['key']])) { $okLines = false; break; }
    }
    ck("X3 [$view] บทสรุปครบทุกแถว ตัวเลขเป็น Number และตรงกับ ghg_report_summary()", $okLines);

    // X4 — ผลแยกตามแหล่งปล่อย: หัวกลุ่มขอบเขต 3 แถว ยอดย่อยตรง, หมวดครบ, สัดส่วนเป็นเศษส่วน (แสดงเป็น %)
    $scopeRows = array_values(array_filter($rowsS, fn($r) => ($r[0]['s'] ?? '') === 'sScope'));
    ck("X4 [$view] หัวกลุ่มขอบเขต 3 แถว ยอดย่อย == ยอดรายขอบเขต", count($scopeRows) === 3
        && feq($scopeRows[0][1]['v'], $sumX['scope'][1]) && feq($scopeRows[1][1]['v'], $sumX['scope'][2]) && feq($scopeRows[2][1]['v'], $sumX['scope'][3]));
    ck("X4b [$view] แถวหมวดครบทุกหมวด", count(array_filter($rowsS, fn($r) => ($r[0]['s'] ?? '') === 'sIndent')) === $nGroups);
    ck("X4c [$view] สัดส่วนรวมของขอบเขต = 100% (เก็บเป็น 0–1)",
        feq((float) $scopeRows[0][2]['v'] + (float) $scopeRows[1][2]['v'] + (float) $scopeRows[2][2]['v'], $sumX['gross'] > 0 ? 1 : 0));

    if ($view === 'faculty') {
        // X5 — แผ่นงานรายการ: กลุ่มขอบเขตยอดตรง, จำนวนแถว = การดำเนินงาน + กิจกรรม, การดูดกลับครบ
        $rowsD = $sheetRows($sheets[1]);
        $grpD  = array_values(array_filter($rowsD, fn($r) => ($r[0]['s'] ?? '') === 'sScope'));
        ck('X5 [faculty] รายการ: ยอดกลุ่มขอบเขต == ยอดรายขอบเขต (รวมกิจกรรม)', count($grpD) === 3
            && feq(end($grpD[0])['v'], $sumX['scope'][1]) && feq(end($grpD[1])['v'], $sumX['scope'][2]) && feq(end($grpD[2])['v'], $sumX['scope'][3]));
        $nItem = count(array_filter($rowsD, fn($r) => in_array($r[0]['s'] ?? '', ['sIndent', 'sEvent'], true) && count($r) === 6));
        $nExpect = count(ghg_affil_detail($pdo, $aff, $cur)) + count($sumX['event_rows']) + count($sumX['survey_rows']);
        ck('X5b [faculty] จำนวนแถวรายการ == การดำเนินงาน + กิจกรรม + แบบสอบถาม', $nItem === $nExpect, "$nItem vs $nExpect");
        ck('X5d [faculty] รายการแบบสอบถามติดป้าย "แบบสอบถาม"', count(array_filter($rowsD, fn($r) => ($r[0]['s'] ?? '') === 'sEvent'
            && $r[0]['v'] === 'แบบสอบถาม')) === count($sumX['survey_rows']));
        ck('X5c [faculty] รายการจากกิจกรรมระบุชื่อกิจกรรม', count(array_filter($rowsD, fn($r) => ($r[0]['s'] ?? '') === 'sEvent'
            && str_starts_with($r[0]['v'], 'กิจกรรม: '))) === count($sumX['event_rows']));
    } else {
        $tot = $find('รวมทุกหน่วยงาน (จากการดำเนินงาน)');
        $rowsSys = array_filter(ghg_by_affiliation($pdo, $cur), fn($r) => (float) $r['total_emission'] > 0);
        ck('X5 [system] แถวรวมรายหน่วยงาน == ghg_affil_sum()', $tot && feq($tot[1]['v'], ghg_affil_sum($rowsSys)));
    }
}
@unlink($probeX);

// ═══ G. หน้าเว็บ / Excel ใช้ฟังก์ชันกลาง ═══
echo "\nG. ใช้สูตรกลางทุกไฟล์\n";
foreach (['reports.php', 'export_report.php', 'report_print.php'] as $f) {
    $s = file_get_contents(__DIR__ . '/../dean/' . $f);
    ck("G1 $f เรียก ghg_report_summary()", strpos($s, 'ghg_report_summary(') !== false);
    ck("G2 $f ไม่คำนวณ Net เอง", strpos($s, '$gross_total - $removal') === false);
    ck("G3 $f ไม่มีคำว่า \"แยกจากยอดหลัก\"", strpos($s, 'แยกจากยอดหลัก') === false);
}

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail > 0 ? 1 : 0);
