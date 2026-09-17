<?php
/**
 * Unit Test — Dashboard admin (admin/index.php + includes/admin_dashboard.php) — อ่านอย่างเดียว ไม่แตะข้อมูล
 * รัน: C:\xampp\php\php.exe tests\admin_dashboard_test.php
 *
 * ล็อกกฎ:
 *   - ยอดบนการ์ดมาจาก ghg_report_summary($year, null) ตัวเดียวกับหน้ารายงาน GHG / Dashboard คณบดี
 *   - ข้อมูลของหน้าต่างรายละเอียดรวมกันได้เท่ายอดหลักเสมอ (แยกผู้ให้ข้อมูล / รายขอบเขต / สะสมทุกปี)
 *   - จำนวนครั้งที่รายงานใช้สูตรเดิม (คู่ ผู้ให้ข้อมูล × ปี)
 *   - หน้าไม่มีสคริปต์ inline ก้อนใหญ่ / onclick / const ระดับบนสุด / ตัวดักของ document ที่ไม่มี guard (เดิม error ในหน้าอื่นหลังสลับหน้า)
 *   - มีแอนิเมชันและปิดได้เมื่อผู้ใช้ตั้งค่าลดการเคลื่อนไหว
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_dashboard.php';

$pdo = getDB();
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
$near = fn(float $a, float $b) => abs($a - $b) < 1e-6;
$n2 = fn(float $v) => number_format($v, 2);

$years = ghg_years($pdo);
$year  = (int) $years[0]['year_id'];
$sum   = ghg_report_summary($pdo, $year, null);
$o     = admin_dash_overview($pdo, $years, $year);
echo "ปี {$years[0]['year']} gross={$sum['gross']}\n\n";

// ── ข้อมูล ──
$bd = $o['breakdown'];
ck('D1 แยกตามผู้ให้ข้อมูล: ครบทุกหน่วยงาน + แบบสอบถาม + กิจกรรม, รวมเท่ายอดปล่อยทั้งหมด, เรียงมาก → น้อย',
    count($bd) === (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn() + 2
    && $near(array_sum(array_column($bd, 'value')), $sum['gross'])
    && $near(current(array_filter($bd, fn($r) => $r['kind'] === 'survey'))['value'], (float) $pdo->query("SELECT COALESCE(SUM(ui.Vol*ai.AD)/1000,0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.year_id = $year AND ui.source = 'survey'")->fetchColumn())
    && array_column($bd, 'value') === (function ($v) { rsort($v); return $v; })(array_column($bd, 'value')),
    'sum=' . array_sum(array_column($bd, 'value')));
ck('D2 รายขอบเขต: ผลรวมของแต่ละขอบเขต = ยอดขอบเขตของฟังก์ชันกลาง', (function () use ($o, $sum, $near) {
    foreach ([1, 2, 3] as $s) if (!$near(array_sum(array_column($o['scope_breakdown'][$s], 'value')), $sum['scope'][$s])) return false;
    return true;
})());
ck('D3 สะสมทุกปี = ผลรวมยอดปล่อยทั้งหมดของทุกปี และแต่ละหน่วยงาน = ผลรวมรายปีของหน่วยงานนั้น', (function () use ($pdo, $years, $o, $near) {
    $gross = 0.0; $per = [];
    foreach ($years as $y) {
        $gross += ghg_report_summary($pdo, (int) $y['year_id'], null)['gross'];
        foreach (admin_dash_year_breakdown($pdo, (int) $y['year_id']) as $r) $per[$r['kind'] . ':' . $r['id']] = ($per[$r['kind'] . ':' . $r['id']] ?? 0) + $r['value'];
    }
    foreach ($o['cumulative'] as $r) if (!$near($r['value'], $per[$r['kind'] . ':' . $r['id']])) return false;
    return $near($o['cumulative_total'], $gross) && count($o['cumulative']) === count($per);
})());
ck('D4 จำนวนครั้งที่รายงาน = จำนวนคู่ (ผู้ให้ข้อมูล, ปี) ที่มีข้อมูล (สูตรเดิม)', (function () use ($pdo, $o) {
    $pairs = [];
    foreach ($pdo->query('SELECT source, affiliation_id, year_id FROM user_item')->fetchAll(PDO::FETCH_NUM) as [$src, $aff, $y])
        $pairs[($src === 'officer' ? "aff$aff" : $src) . "-$y"] = true;
    return $o['report_count'] === count($pairs);
})());
ck('D5 % ชดเชย / อันดับ / ประวัติ มาจากฟังก์ชันกลาง',
    $o['offset'] === ghg_offset_pct($sum['gross'], $sum['removal'])
    && $o['ranking'] === ghg_affiliation_ranking(ghg_by_affiliation($pdo, $year), ghg_scope_by_affiliation($pdo, $year))
    && count($o['history']) === count($years) && $near($o['removal_central'] + $o['removal_activity'], $sum['removal']));

// ── หน้าเว็บ ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/admin_index_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["PHP_SELF"] = "/admin/index.php";
if ($argv[1] !== "") $_GET = ["year" => $argv[1]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>"admin","affiliation_id"=>1,"affiliation_name"=>"ศ","firstname"=>"ผู้","lastname"=>"ดูแล","username"=>"admin","last_activity"=>time()];
ob_start(); require $root . "/admin/index.php"; echo ob_get_clean();
');
$render = fn(string $y = '') => (string) shell_exec(sprintf('%s %s %s 2>&1', escapeshellarg($php), escapeshellarg($probe), escapeshellarg($y === '' ? '""' : $y)));
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $h);
$html = $render((string) $year);
$main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
// เนื้อหาของหน้าเอง: ตั้งแต่การ์ดหลักถึงท้าย <main> (ข้ามแถบหัวและ component dropdown ที่มี style/script ของมันเอง)
$own  = substr($main, (int) strpos($main, '<div class="ad-hero">'));

ck('P1 เรนเดอร์ได้ ไม่มี PHP error', $noErr($html), substr($html, 0, 300));
ck('P2 การ์ดปล่อย / ดูดกลับ / Net และยอดแยกย่อย ตรงกับ ghg_report_summary()',
    str_contains($html, 'class="ad-gross" data-count="' . number_format($sum['gross'], 2, '.', '') . '">' . $n2($sum['gross']) . '</b>')
    && str_contains($html, 'class="ad-removal" data-count="' . number_format($sum['removal'], 2, '.', '') . '">' . $n2($sum['removal']) . '</b>')
    && str_contains($html, 'class="ad-net" data-count="' . number_format($sum['net'], 2, '.', '') . '">' . $n2($sum['net']) . '</b>')
    && str_contains($html, 'class="ad-part-op">' . $n2($o['parts']['operation']) . '<') && str_contains($html, 'class="ad-part-ev">' . $n2($o['parts']['event']) . '<')
    && str_contains($html, 'class="ad-part-sv">' . $n2($o['parts']['survey']) . '<'));
ck('P2b ส่วนย่อยของยอดปล่อย (ทั้งมหาวิทยาลัย): รวมกัน = ยอดปล่อยทั้งหมด · ดำเนินงาน = ผลรวมทุกหน่วยงาน · กิจกรรม/แบบสอบถาม > 0 (เดิมแสดง 0.00 เพราะ ghg_report_summary(null) ไม่แยก)', (function () use ($o, $sum, $near, $pdo, $year) {
    $p = $o['parts'];
    $fac = array_sum(array_column(array_filter($o['breakdown'], fn($r) => $r['kind'] === 'faculty'), 'value'));
    return $near($p['operation'] + $p['event'] + $p['survey'], $sum['gross']) && $near($p['operation'], $fac)
        && $p['event'] > 0 && $p['survey'] > 0 && $p === admin_dash_gross_parts($pdo, $year, $sum['gross']);
})());
ck('P3 การ์ดขอบเขต 1/2/3 + สะสมทุกปี + จำนวนครั้งที่รายงาน ตรงกับข้อมูล', (function () use ($html, $sum, $o, $n2) {
    foreach ([1, 2, 3] as $s) if (!preg_match('#data-scope-val="' . $s . '" data-count="[\d.]+">' . preg_quote($n2($sum['scope'][$s]), '#') . '</b>#', $html)) return false;
    return str_contains($html, 'class="ad-cumul" data-count="' . number_format($o['cumulative_total'], 2, '.', '') . '">')
        && str_contains($html, 'class="ad-reports" data-count="' . $o['report_count'] . '" data-digits="0">');
})());
ck('P4 การ์ดอันดับ: 6 อันดับแรกตามลำดับ (ชื่อถูก escape, กดดูหน่วยงานได้) + ปุ่มดูทั้งหมดบอกจำนวน · ข้อมูลหน้าต่างมีอันดับครบทุกหน่วยงาน', (function () use ($html, $o) {
    preg_match_all('#data-ad-affil="(\d+)" data-name="([^"]*)"#', $html, $m);
    $top = array_slice($o['ranking'], 0, 6);
    $js = preg_match('#<script type="application/json" id="adData">(.*?)</script>#s', $html, $jm) ? json_decode($jm[1], true) : [];
    return count($o['ranking']) > 5 && array_map('intval', $m[1]) === array_column($top, 'affil_id')
        && $m[2] === array_map(fn($r) => htmlspecialchars($r['name'], ENT_QUOTES), $top)
        && str_contains($html, 'data-ad-open="ranking">ดูอันดับทั้งหมด (' . $o['affils_with_data'] . ' หน่วยงาน)')
        && ($js['ranking'] ?? null) == $o['ranking'] && ($js['affilTotal'] ?? null) === $o['affil_total']
        && str_contains($html, 'class="ad-grid is-even"');
})());
ck('P4b แถบแบบสอบถาม / กิจกรรม: จำนวนชุด/งาน = จำนวนในหน้าต่างที่ปุ่มเปิด + ยอดตรงส่วนย่อยของยอดปล่อย + ปุ่มเปิดรายละเอียดเดิม · ไม่มีปุ่มเดิมท้ายการ์ดอันดับ', (function () use ($html, $o, $pdo, $year) {
    // นับแบบเดียวกับหน้าต่างที่ปุ่มเปิด: กลุ่มแบบสอบถาม / กิจกรรมที่มีการปล่อย ใน api_affil_detail.php
    $api = function (string $src) use ($year) {
        $f = sys_get_temp_dir() . '/admin_src_probe.php';
        file_put_contents($f, '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_GET=["source"=>$argv[1],"year_id"=>(int)$argv[2]]; session_start(); $_SESSION=["user_id"=>1,"role"=>"admin","last_activity"=>time()]; chdir(' . var_export(dirname(__DIR__) . '/admin/api', true) . '); require "api_affil_detail.php";');
        $rows = json_decode((string) shell_exec(sprintf('%s %s %s %d 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($f), $src, $year)), true) ?: [];
        @unlink($f);
        return $src === 'survey' ? count(array_unique(array_column($rows, 'questionnaire_id')))
                                 : count(array_unique(array_column(array_filter($rows, fn($r) => $r['itype'] === 'emit'), 'event_id')));
    };
    $c = fn(string $t) => $api($t === 'questionnaire' ? 'survey' : 'event');
    preg_match_all('#<b>(แบบสอบถาม|กิจกรรมที่มีการปล่อย) <span class="ad-src-count">(\d+)</span> (?:ชุด|งาน) · <span class="ad-src-total" data-count="[\d.]+" data-digits="4">([\d,.]+)</span> tCO₂e</b>#u', $html, $m);
    return $o['source_counts'] === ['survey' => $c('questionnaire'), 'event' => $c('event')]
        && $m[1] === ['แบบสอบถาม', 'กิจกรรมที่มีการปล่อย'] && (int) $m[2][0] === $o['source_counts']['survey'] && (int) $m[2][1] === $o['source_counts']['event']
        && $m[3][0] === number_format($o['parts']['survey'], 4) && $m[3][1] === number_format($o['parts']['event'], 4)
        && str_contains($html, 'class="ad-evbar-btn" data-ad-source="survey">') && str_contains($html, 'class="ad-evbar-btn" data-ad-source="event">')
        && !str_contains($html, 'class="ad-sources"') && str_contains($html, 'class="ad-evbar is-survey oe-rise"');
})());
$json = preg_match('#<script type="application/json" id="adData">(.*?)</script>#s', $html, $jm) ? json_decode($jm[1], true) : null;
ck('P5 ข้อมูลของหน้าต่างส่งเป็น JSON (กันแท็กด้วย JSON_HEX_TAG) และยอดตรงกับการ์ด',
    is_array($json) && !str_contains($jm[1], '<') && abs($json['gross'] - $sum['gross']) < 1e-6
    && count($json['breakdown']) === count($o['breakdown']) && abs($json['cumulativeTotal'] - $o['cumulative_total']) < 1e-6);
ck('P6 ไม่เหลือโค้ดเก่า: สคริปต์ inline ก้อนใหญ่, onclick/onmouseover, const/let ระดับบนสุด, <style> ในหน้า, หน้าต่างเดิม 7 อัน, ตัวดักคลิกของ document',
    strlen($own) > 1000 && !preg_match('/\son(click|mouseover|mouseout)=/i', $own) && !str_contains($own, '<style')
    && !preg_match('#<script>\s*(const|let)\s#', $own) && !str_contains($main, 'yearEmissionModal') && !str_contains($main, 'lightboxOverlay')
    && !str_contains($own, 'document.addEventListener') && substr_count($main, 'id="adModal"') === 1
    && str_contains($html, 'assets/js/admin-dashboard.js') && str_contains($html, 'assets/js/ghg-charts.js') && !str_contains($html, 'activity-modal.js')
    && (function () use ($own) { preg_match_all('#<script>(.*?)</script>#s', $own, $m); return strlen(implode('', $m[1])) < 1500; })());   // เดิม ~1,870 บรรทัด
ck('P7 ปุ่มบนการ์ดไม่มี inline style (hover ใน CSS ทำงานได้) และหน้าต่างมี role="dialog"',
    !preg_match('#<button[^>]*data-ad-(open|affil|source)[^>]*style=#', $html) && str_contains($html, 'id="adModal" role="dialog" aria-modal="true"'));

$h2 = $render('999999');
$prev = count($years) > 1 ? (int) $years[1]['year_id'] : $year;
$sumPrev = ghg_report_summary($pdo, $prev, null);
$h3 = $render((string) $prev);
ck('P8 ปีไม่มีจริง → ใช้ปีล่าสุด · ปีก่อนหน้าแสดงยอดของปีนั้น', $noErr($h2) && str_contains($h2, 'class="ad-gross" data-count="' . number_format($sum['gross'], 2, '.', '') . '"')
    && $noErr($h3) && str_contains($h3, 'class="ad-gross" data-count="' . number_format($sumPrev['gross'], 2, '.', '') . '"'));

// ── ลิงก์รายงาน GHG ฉบับเต็ม ──
ck('R1 ปุ่ม "รายงาน GHG ฉบับเต็ม" ไปมุมมองทั้งมหาวิทยาลัยของปีที่เลือก',
    str_contains($html, 'class="oe-btn oe-btn-soft ad-report-link" href="../dean/reports.php?view=system&amp;year=' . $year . '">รายงาน GHG ฉบับเต็ม')
    && str_contains($h3, 'href="../dean/reports.php?view=system&amp;year=' . $prev . '"'));
$rprobe = sys_get_temp_dir() . '/admin_report_probe.php';
file_put_contents($rprobe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["PHP_SELF"] = "/dean/reports.php";
$_GET = ["view" => "system", "year" => (int) $argv[2]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>$argv[1],"affiliation_id"=>1,"affiliation_name"=>"ศ","firstname"=>"ผู้","lastname"=>"ใช้","username"=>"u","last_activity"=>time()];
ob_start(); require $root . "/dean/reports.php"; echo ob_get_clean();
');
$rep = fn(string $role) => (string) shell_exec(sprintf('%s %s %s %d 2>&1', escapeshellarg($php), escapeshellarg($rprobe), $role, $year));
$ra = $rep('admin'); $rd = $rep('dean');
ck('R2 หน้ารายงาน: admin เห็นเมนูข้าง/แถบหัวของ admin (เมนู Dashboard ไม่ไฮไลต์ — เมนูที่ไฮไลต์กดแล้วไม่เปลี่ยนหน้า) · dean ยังเห็นเมนูของคณบดีเหมือนเดิม',
    $noErr($ra) && str_contains($ra, 'admin/settings.php') && !str_contains($ra, 'dean/profile.php')
    && (bool) preg_match('#admin/index.php"\s+class="nav-item "#', $ra) && !str_contains($ra, 'class="nav-item active"') && str_contains($ra, '<title>รายงาน GHG — UP Net Zero</title>')
    && $noErr($rd) && str_contains($rd, 'dean/profile.php') && !str_contains($rd, 'admin/settings.php') && str_contains($rd, '<title>รายงาน GHG (คณบดี) — UP Net Zero</title>'));
@unlink($rprobe);

// ── JS / CSS ──
$js = (string) file_get_contents(__DIR__ . '/../assets/js/admin-dashboard.js');
ck('J1 admin-dashboard.js: ห่อ IIFE, ตัวดักของ document/window ผูกครั้งเดียว, ไม่มี onclick ในสตริง, ใช้ API เดิม',
    str_starts_with(ltrim(preg_replace('#^/\*\*.*?\*/#s', '', $js)), '(function (w) {')
    && preg_match_all('/(?<![\w.])d\.addEventListener\(/', $js, $dm, PREG_OFFSET_CAPTURE) === 1 && str_contains($js, "if (!w.__adBound) {")
    && strpos($js, 'if (!w.__adBound) {') < $dm[0][0][1] && !preg_match('/(?<![\w.])w\.addEventListener\(/', $js)
    && !str_contains($js, 'onclick=') && str_contains($js, 'api_reports_list.php') && str_contains($js, 'api_affil_detail.php') && str_contains($js, 'api_item_detail.php'));
$css = (string) file_get_contents(__DIR__ . '/../assets/css/admin-dashboard.css');
ck('C1 แอนิเมชัน: แถบยืด, วงลอย, อันดับลอยขึ้นทีละแถว, หน้าต่างเลื่อนเข้า/ย้อนกลับ, hover การ์ด และปิดเมื่อ prefers-reduced-motion',
    str_contains($css, '@keyframes adGrow') && str_contains($css, '@keyframes adFloat') && str_contains($css, '.ad-rank-item { animation: oeRise')
    && str_contains($css, '.ad-view-in   { animation: adSlideIn') && str_contains($css, '.ad-view-back { animation: adSlideBack')
    && str_contains($css, '.ad-kpi:hover { transform: translateY(-4px);')
    && (bool) preg_match('#prefers-reduced-motion: reduce\) \{\s*\.ad-kpi::before, \.ad-kpi::after, \.ad-fill, \.ad-rank-item#', $css)
    // รอบนี้: แถบแบบสอบถาม (ม่วง) ใช้ท่าเดียวกับแถบกิจกรรม, วางคู่กันและซ้อนเมื่อพื้นที่แคบ, การ์ดล่างสูงเท่ากัน
    && (bool) preg_match('#\.ad-evbar\.is-survey \.ad-evbar-btn:hover, \.ad-evbar\.is-survey \.ad-evbar-btn:focus-visible \{#', $css)
    && (bool) preg_match('#\.ad-srcbars \{ display: grid; grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);#', $css)
    && (bool) preg_match('#@container \(max-width: 700px\) \{\s*\.ad-srcbars \{ grid-template-columns: minmax\(0, 1fr\); \}#', $css)
    && str_contains($css, '.ad-grid.is-even { align-items: stretch; }') && str_contains($css, '.oe-btn.ad-rank-more { margin-top: auto;'));

@unlink($probe);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
