<?php
/**
 * Unit Test — Dashboard เจ้าหน้าที่ (officer/index.php) — อ่านอย่างเดียว ไม่แตะข้อมูล
 * รัน: C:\xampp\php\php.exe tests\officer_dashboard_test.php
 *
 * ล็อกกฎ:
 *   - ใช้หน้าตา/ข้อมูลชุดเดียวกับ Dashboard คณบดี (includes/faculty_dashboard_body.php + dean_dash_overview) → ตัวเลขตรงกันทุกหน้า
 *   - แถบความครบถ้วนของการกรอก (งานหลักของเจ้าหน้าที่) = ghg_item_fill() รวมทุกขอบเขต + ปุ่มไปกรอกข้อมูลของปีที่เลือก
 *   - ไม่มี <style> / onclick / activity-modal.js / dashboard.css เดิม · animation ปิดได้เมื่อผู้ใช้ลดการเคลื่อนไหว
 *   (ข้อมูลของ overview / อันดับ / กราฟ ล็อกไว้แล้วใน dean_dashboard_test.php)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/dean_dashboard.php';

$pdo = getDB();
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
$n2 = fn(float $v) => number_format($v, 2);

$probe = sys_get_temp_dir() . '/officer_index_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["PHP_SELF"] = "/officer/index.php";
$_GET = $argv[2] === "-" ? [] : ["year" => (int) $argv[2]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>"officer","affiliation_id"=>(int)$argv[1],"affiliation_name"=>"หน่วยงาน<ทดสอบ>","firstname"=>"ท","lastname"=>"ส","username"=>"t","last_activity"=>time()];
ob_start(); require $root . "/officer/index.php"; echo ob_get_clean();
');
$render = fn(int $a, string $y) => (string) shell_exec(sprintf('%s %s %d %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe), $a, escapeshellarg($y)));

$years = ghg_years($pdo);
$year  = (int) $years[0]['year_id'];
// หน่วยงานที่มีกิจกรรม (ครอบแถบกิจกรรม) — ไม่มี → หน่วยงาน 1
$aff = (int) ($pdo->query("SELECT affiliation_id FROM event WHERE year_id = $year ORDER BY affiliation_id LIMIT 1")->fetchColumn() ?: 1);
$o    = dean_dash_overview($pdo, $years, $year, $aff);
$sum  = $o['summary'];
$html = $render($aff, (string) $year);
$data = preg_match('#<script type="application/json" id="adData">(.*?)</script>#s', $html, $mj) ? json_decode($mj[1], true) : null;
echo "หน่วยงาน $aff ปี {$years[0]['year']} gross={$sum['gross']}\n\n";

ck('O1 เรนเดอร์ได้ ไม่มี PHP error/warning', $html !== '' && !preg_match('/(Fatal error|Warning|Notice|Deprecated):/i', $html), substr($html, 0, 300));
ck('O2 การ์ดหลัก / ส่วนย่อย / ขอบเขต ตรง ghg_report_summary() ของคณะ (ชุดเดียวกับคณบดี)', (function () use ($html, $sum, $n2) {
    foreach (['ad-gross' => $sum['gross'], 'ad-removal' => $sum['removal'], 'ad-net' => $sum['net'], 'ad-part-op' => $sum['operation_total'], 'ad-part-ev' => $sum['event_total'], 'ad-part-sv' => $sum['survey_total']] as $cls => $v)
        if (!preg_match('#class="' . $cls . '"[^>]*>([0-9,.\-]+)<#', $html, $m) || $m[1] !== $n2($v)) return false;
    foreach ([1, 2, 3] as $s) if (!preg_match('#data-scope-val="' . $s . '"[^>]*>([0-9,.\-]+)<#', $html, $m) || $m[1] !== $n2($sum['scope'][$s])) return false;
    return true;
})());
ck('O3 แถบความครบถ้วน: กรอกแล้ว a / b = ผลรวม ghg_item_fill() · เหลือ / % ตรง · สีเขียวเมื่อครบเท่านั้น', (function () use ($html, $o) {
    $f = array_sum(array_column($o['fill'], 'filled')); $t = array_sum(array_column($o['fill'], 'total'));
    $done = $t > 0 && $f >= $t; $pct = (int) floor($f / max($t, 1) * 100);
    return $t > 0 && strpos($html, 'class="ad-fillbar-count">' . $f . ' / ' . $t . '</span> รายการ') !== false
        && strpos($html, '<div class="ad-evbar is-fill' . ($done ? ' is-done' : '') . ' oe-rise">') !== false
        && ($done || strpos($html, 'เหลืออีก ' . ($t - $f) . ' รายการ') !== false)
        && strpos($html, '<b data-count="' . $pct . '" data-digits="0">' . $pct . '</b>%') !== false;
})());
ck('O3b "กรอกแล้ว a / b" รายขอบเขตบนการ์ดตรง ghg_item_fill()', (function () use ($html, $o) {
    if (preg_match_all('#class="ad-scope-fill">กรอกแล้ว <b>(\d+) / (\d+)</b>#u', $html, $mf) !== 3) return false;
    foreach ([1, 2, 3] as $i => $s) if ((int) $mf[1][$i] !== $o['fill'][$s]['filled'] || (int) $mf[2][$i] !== $o['fill'][$s]['total']) return false;
    return true;
})());
ck('O4 ปุ่ม "กรอก / แก้ไขข้อมูล" ไป items.php ของปีที่เลือก · ตัวเลือกปีครบทุกปี · ไม่มีลิงก์รายงานของคณบดี', (function () use ($html, $year, $years) {
    foreach ($years as $y) if (strpos($html, (string) $y['year']) === false) return false;
    return strpos($html, '<a class="ad-evbar-btn" href="items.php?year=' . $year . '">กรอก / แก้ไขข้อมูล') !== false
        && strpos($html, 'id="adYear"') !== false && strpos($html, 'reports.php') === false;
})());
ck('O5 ส่วนร่วมกับคณบดี: แถบกิจกรรม (มีกิจกรรม) + การ์ดอันดับ + กราฟสลับคณะ/มหาวิทยาลัย · ข้อมูลหน้าต่าง mode faculty ตรง overview + escape ชื่อ', is_array($data)
    && ($o['event_count'] === 0 || strpos($html, 'class="ad-evbar oe-rise"') !== false)
    && strpos($html, 'class="oe-panel ad-rank ad-rank-card') !== false && strpos($html, 'data-ad-hist="uni"') !== false && strpos($html, 'id="adHistory"') !== false
    && $data['mode'] === 'faculty' && $data['ownAffil'] === $aff && $data['ranking'] == $o['ranking'] && $data['detailRows'] == $o['detail_rows']
    && $data['eventGroups'] == $o['event_groups'] && $data['affilName'] === 'หน่วยงาน<ทดสอบ>' && strpos($mj[1] ?? '', '<ทดสอบ') === false);
ck('O6 คณบดีและเจ้าหน้าที่ใช้เนื้อหน้าไฟล์เดียวกัน (ไม่ก็อปโค้ดซ้ำ)', (function () {
    $inc = "include __DIR__ . '/../includes/faculty_dashboard_body.php';";
    $d = (string) file_get_contents(__DIR__ . '/../dean/index.php'); $f = (string) file_get_contents(__DIR__ . '/../officer/index.php');
    return strpos($d, $inc) !== false && strpos($f, $inc) !== false && strpos($f, 'ad-rank-hero') === false && strpos($d, 'ad-rank-hero') === false;
})());
ck('O7 ชุดเดียวกับ admin/คณบดี: ไม่มี <style> / onclick / dashboard.css / activity-modal.js ในเนื้อหน้า', (function () use ($html) {
    $own = substr($html, (int) strpos($html, 'class="ad-evbar is-fill'));
    return strpos($own, '<style') === false && strpos($own, 'onclick=') === false
        && strpos($html, 'css/dashboard.css') === false && strpos($html, 'activity-modal.js') === false
        && strpos($html, 'admin-dashboard.css') !== false && strpos($html, 'admin-dashboard.js') !== false && strpos($own, 'id="adModal"') !== false;
})());
ck('O8 animation แถบกรอก: เข้า (oe-rise) + แถบยืด + % นับขึ้น + ไอคอน/ปุ่มขยับ (ชุด ad-evbar) · เขียวเมื่อครบ · ปิดได้เมื่อลดการเคลื่อนไหว · จอแคบข้อความไม่เบียด', (function () use ($html) {
    $css = (string) file_get_contents(__DIR__ . '/../assets/css/admin-dashboard.css');
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*)\}\s*$/s', $css, $rm);
    return substr_count($html, 'oe-rise') >= 10 && preg_match('/\.ad-fill \{[^}]*animation: adGrow/', $css)
        && preg_match('/\.ad-evbar\.is-fill \{[^}]*background: #FAF5FF/', $css) && preg_match('/\.ad-evbar\.is-fill\.is-done \{[^}]*background: #F0FDF4/', $css)
        && preg_match('/\.ad-evbar\.is-fill\.is-done \.ad-fill \{ background: linear-gradient\(90deg, #22C55E, #16A34A\); \}/', $css)
        && strpos($rm[1] ?? '', '.ad-fill,') !== false && strpos($rm[1] ?? '', '.ad-evbar-btn:hover') !== false
        && preg_match('/@container \(max-width: 520px\) \{[^@]*\.ad-evbar\.is-fill \.ad-evbar-text \{ flex-basis:/', $css);
})());
ck('O9 ไม่มีสังกัด → ข้อความแจ้ง ไม่ error · ปีที่ไม่มีในระบบ → ใช้ปีล่าสุด', (function () use ($render, $aff, $year) {
    $a = $render(0, (string) $year);
    $b = $render($aff, '999999');
    return strpos($a, 'ยังไม่ได้ผูกกับคณะ') !== false && !preg_match('/(Fatal error|Warning):/i', $a) && strpos($a, 'id="adData"') === false
        && strpos($b, 'href="items.php?year=' . $year . '"') !== false && !preg_match('/(Fatal error|Warning):/i', $b);
})());

@unlink($probe);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
