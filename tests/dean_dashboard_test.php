<?php
/**
 * Unit Test — Dashboard คณบดี (dean/index.php + includes/dean_dashboard.php) — อ่านอย่างเดียว ไม่แตะข้อมูล
 * รัน: C:\xampp\php\php.exe tests\dean_dashboard_test.php
 *
 * ล็อกกฎ:
 *   - ยอดบนการ์ดมาจาก ghg_report_summary($year, $affil) ตัวเดียวกับหน้ารายงาน GHG มุมมองคณะ
 *   - ข้อมูลของหน้าต่างรายละเอียดรวมกันได้เท่าการ์ดเสมอ (รายการ / รายขอบเขต / รายกิจกรรม)
 *   - คณบดีเห็นเฉพาะคณะตัวเอง · admin ถูกส่งไป Dashboard admin
 *   - หน้าใช้ชุดเดียวกับ Dashboard admin: ไม่มีสคริปต์ inline ก้อนใหญ่ / onclick / <style> ใน <main>
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
$near = fn(float $a, float $b) => abs($a - $b) < 1e-6;
$n2 = fn(float $v) => number_format($v, 2);

$years = ghg_years($pdo);
$year  = (int) $years[0]['year_id'];
// คณะที่มีทั้งกิจกรรมปล่อยและดูดกลับในปีล่าสุด (เคสไม่ผ่านเพราะยอด 0)
$affil = (int) $pdo->query("SELECT e.affiliation_id FROM event e JOIN removal_event_item r ON r.event_id = e.id
                            JOIN event_item ei ON ei.event_id = e.id WHERE e.year_id = $year ORDER BY e.affiliation_id LIMIT 1")->fetchColumn();
$sum = ghg_report_summary($pdo, $year, $affil);
$o   = dean_dash_overview($pdo, $years, $year, $affil);
echo "ปี {$years[0]['year']} คณะ $affil gross={$sum['gross']} event={$sum['event_total']} removal={$sum['removal']}\n\n";

// ── ข้อมูล ──
ck('D0 เคสทดสอบมียอดจริง (ดำเนินงาน / กิจกรรม / ดูดกลับ > 0)', $affil > 0 && $sum['operation_total'] > 0 && $sum['event_total'] > 0 && $sum['removal'] > 0);
ck('D1 overview ใช้ยอดจาก ghg_report_summary() ของคณะ และ % ชดเชย / badge มาจากฟังก์ชันกลาง',
    $o['summary'] == $sum && $o['offset'] === ghg_offset_pct($sum['gross'], $sum['removal'])
    && $o['badge'] === ghg_change_badge($sum['gross'], $o['compare']['prev']['gross'] ?? null)
    && $o['compare'] == ghg_year_comparison($pdo, $years, $year, $affil));
ck('D2 รายการรวมเท่ายอดปล่อยทั้งหมด · หน้าต่างรายละเอียด 0/1/2/3 เท่าการ์ด · กิจกรรม/แบบสอบถามรวมอย่างละ ≤ 1 แถว', (function () use ($o, $sum, $near) {
    if (!$near(array_sum(array_column($o['items'], 'emission')), $sum['gross'])) return false;
    $expect = [0 => $sum['gross'], 1 => $sum['scope'][1], 2 => $sum['scope'][2], 3 => $sum['scope'][3]];
    foreach ($expect as $k => $v) {
        $rows = $o['detail_rows'][$k];
        $src = array_count_values(array_column($rows, 'source'));
        if (isset($src['event']) || isset($src['survey']) || ($src['event_total'] ?? 0) > 1 || ($src['survey_total'] ?? 0) > 1) return false;
        if (!$near(array_sum(array_column($rows, 'emission')), $v)) return false;
    }
    return count(array_filter($o['items'], fn($it) => $it['source'] === 'event')) === count($sum['event_rows']);
})());
ck('D3 กลุ่มกิจกรรม: ปล่อยรวม = ยอดกิจกรรม, ดูดกลับรวม = ยอดดูดกลับ, 1 กลุ่มต่อกิจกรรม, เรียงมาก → น้อย', (function () use ($o, $sum, $near) {
    $g = $o['event_groups'];
    $ids = array_unique(array_merge(array_column($sum['event_rows'], 'event_id'), array_column($sum['removal_rows'], 'event_id')));
    $tot = array_map(fn($x) => $x['emit'] + $x['removal'], $g);
    $sorted = $tot; rsort($sorted);
    foreach ($g as $x) if (!$near($x['emit'], array_sum(array_column($x['emit_rows'], 'emission'))) || $x['name'] === '') return false;
    return $near(array_sum(array_column($g, 'emit')), $sum['event_total'])
        && $near(array_sum(array_column($g, 'removal')), $sum['removal'])
        && count($g) === count($ids) && $tot === $sorted && $o['event_count'] >= count($g);
})());
ck('D3b dean_dash_event_groups(): แยกรายการปล่อย/ดูดกลับเข้ากิจกรรมเดียวกัน, ไม่มีข้อมูล → []', (function () use ($near) {
    $g = dean_dash_event_groups(
        [['event_id' => 7, 'event_name' => 'ก', 'name_tiem' => 'ดีเซล', 'unit' => 'ลิตร', 'factor' => 2, 'qty' => 10, 'emission' => 0.02, 'scope' => 1]],
        [['event_id' => 7, 'event_name' => 'ก', 'name_tiem' => 'ปลูกต้นไม้', 'unit' => 'ต้น', 'factor' => 9, 'qty' => 5, 'emission' => 0.045],
         ['event_id' => 9, 'event_name' => 'ข', 'name_tiem' => 'ปลูกต้นไม้', 'unit' => 'ต้น', 'factor' => 9, 'qty' => 1, 'emission' => 0.009]]);
    return count($g) === 2 && $g[0]['id'] === 7 && $near($g[0]['emit'], 0.02) && $near($g[0]['removal'], 0.045)
        && count($g[0]['emit_rows']) === 1 && $g[0]['emit_rows'][0]['scope'] === 1 && count($g[1]['emit_rows']) === 0
        && dean_dash_event_groups([], []) === [];
})());
ck('D4 ประวัติรายปีของคณะ: ปีที่เลือก s1+s2+s3 = ยอดปล่อยทั้งหมด · สะสม = ผลรวมยอดทุกปี', (function () use ($pdo, $years, $affil, $o, $sum, $near, $year) {
    $last = end($o['history']);
    $gross = 0.0;
    foreach ($years as $y) $gross += ghg_report_summary($pdo, (int) $y['year_id'], $affil)['gross'];
    return count($o['history']) === count($years) && $last['year'] === (string) $years[0]['year']
        && $near($last['s1'] + $last['s2'] + $last['s3'], $sum['gross']) && $near($o['cumulative'], $gross);
})());
ck('D5 ความครบถ้วน = ghg_item_fill() ของรายการ และนับเฉพาะรายการแม่บท',
    $o['fill'] === ghg_item_fill($o['items']) && $o['fill'][1]['total'] + $o['fill'][2]['total'] + $o['fill'][3]['total'] === count(array_filter($o['items'], fn($it) => $it['source'] === 'officer')));
ck('D6 dean_dash_redirect(): admin → Dashboard admin ปีเดิม, คณบดี/อื่น ๆ → null',
    dean_dash_redirect('admin', 25) === '../admin/index.php?year=25' && dean_dash_redirect('admin', 0) === '../admin/index.php'
    && dean_dash_redirect('dean', 25) === null && dean_dash_redirect('', 25) === null);
ck('D7 ไม่มีสังกัด (affil 0): ไม่มีรายการ ไม่ error', (function () use ($pdo, $years, $year) {
    $x = dean_dash_overview($pdo, $years, $year, 0);
    return $x['items'] === [] && $x['event_groups'] === [] && $x['detail_rows'][0] === [];
})());
ck('D10 อันดับรายคณะ = ghg_affiliation_ranking() ชุดเดียวกับหน้ารายงาน · แถวคณะตัวเองถูกต้อง · ไม่มีข้อมูล → null', (function () use ($pdo, $o, $affil, $year) {
    $rank = ghg_affiliation_ranking(ghg_by_affiliation($pdo, $year), ghg_scope_by_affiliation($pdo, $year));
    return $o['ranking'] === $rank && count($rank) > 1 && $o['own_rank'] !== null && $o['own_rank']['affil_id'] === $affil
        && $o['own_rank'] === current(array_filter($rank, fn($r) => $r['affil_id'] === $affil))
        && dean_dash_own_rank($rank, 999999) === null && dean_dash_own_rank([], $affil) === null;
})());
ck('D11 dean_dash_rank_hero(): ค่าเฉลี่ย, ต่าง/น้อยกว่าอันดับ 1, จุดตามอันดับ + ตำแหน่งจุดของคณะ · คณะอันดับ 1 → below_top null · ไม่มีข้อมูลของคณะ → null ไม่มีจุดไฮไลต์ · ไม่มีข้อมูลเลย', (function () use ($near) {
    $r = [['rank' => 1, 'affil_id' => 7, 'name' => 'ก', 'total' => 100.0], ['rank' => 2, 'affil_id' => 8, 'name' => 'ข', 'total' => 60.0],
          ['rank' => 3, 'affil_id' => 9, 'name' => 'ค', 'total' => 20.0], ['rank' => 4, 'affil_id' => 10, 'name' => 'ง', 'total' => 20.0]];
    $a = dean_dash_rank_hero($r, $r[2]); $b = dean_dash_rank_hero($r, $r[0]); $c = dean_dash_rank_hero($r, null); $z = dean_dash_rank_hero([], null);
    return $near($a['avg'], 50.0) && $near($a['diff_avg'], -30.0) && $near($a['below_top'], 80.0)
        && array_column($a['dots'], 'pos') === [12.5, 37.5, 62.5, 87.5] && array_column($a['dots'], 'own') === [false, false, true, false] && $a['own_pos'] === 62.5
        && $b['below_top'] === null && $near($b['diff_avg'], 50.0)
        && $c['diff_avg'] === null && $c['below_top'] === null && $c['own_pos'] === null && !in_array(true, array_column($c['dots'], 'own'), true)
        && $z['avg'] === 0.0 && $z['dots'] === [];
})());
ck('D12 ข้อมูลกราฟทั้งมหาวิทยาลัย = ghg_scope_history(null) · ยอดสะสม = ผลรวมยอดปล่อยทุกปีของทั้งมหาวิทยาลัย · จำนวนหน่วยงานทั้งหมด', (function () use ($pdo, $years, $o, $near) {
    $g = 0.0; foreach ($years as $y) $g += ghg_report_summary($pdo, (int) $y['year_id'], null)['gross'];
    return $o['uni_history'] == ghg_scope_history($pdo, $years, null) && $near($o['uni_cumulative'], $g)
        && $o['affil_total'] === (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn();
})());
ck('D8 คณะที่มีแบบสอบถาม: ยอดขอบเขต 3 = ดำเนินงาน + กิจกรรม + แบบสอบถาม และหน้าต่างขอบเขต 3 มีแถวแบบสอบถามแถวเดียวยอดตรง', (function () use ($pdo, $years, $near) {
    $row = $pdo->query("SELECT ui.affiliation_id, ui.year_id FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id JOIN admin_g ag ON ag.id = ai.scope
                        WHERE ui.source = 'survey' AND ag.scope = 3 LIMIT 1")->fetch();
    if (!$row) return false;
    [$a, $y] = [(int) $row['affiliation_id'], (int) $row['year_id']];
    $x = dean_dash_overview($pdo, $years, $y, $a);
    $s = $x['summary'];
    $ev3 = array_sum(array_map(fn($r) => (int) $r['scope'] === 3 ? (float) $r['emission'] : 0.0, $s['event_rows']));
    $sv = array_values(array_filter($x['detail_rows'][3], fn($r) => $r['source'] === 'survey_total'));
    return $s['survey_total'] > 0 && $near($s['scope'][3], ghg_scope_totals($pdo, $y, $a)[3] + $ev3 + $s['survey_total'])
        && count($sv) === 1 && $near($sv[0]['emission'], $s['survey_total'])
        && !array_filter($x['detail_rows'][1], fn($r) => $r['source'] === 'survey_total');
})());

// ── หน้า (เรนเดอร์จริงในโหมด CLI) ──
$probe = sys_get_temp_dir() . '/dean_dash_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = $argv[2] === "-" ? [] : ["year" => (int) $argv[2]];
session_start();
$_SESSION = ["user_id"=>1,"role"=>"dean","affiliation_id"=>(int)$argv[1],"affiliation_name"=>"คณะ<ทดสอบ>","last_activity"=>time()];
ob_start(); require $root . "/dean/index.php"; echo ob_get_clean();
');
$render = fn(int $a, string $y) => (string) shell_exec(sprintf('%s %s %d %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe), $a, escapeshellarg($y)));
$html = $render($affil, (string) $year);
$data = preg_match('#<script type="application/json" id="adData">(.*?)</script>#s', $html, $mj) ? json_decode($mj[1], true) : null;

ck('R1 เรนเดอร์ได้ ไม่มี PHP error / warning', $html !== '' && !preg_match('/(Fatal error|Warning|Notice|Deprecated):/i', $html), substr($html, 0, 300));
ck('R1b การ์ดหลัก / ขอบเขต / ส่วนย่อยของยอดปล่อย ตรง ghg_report_summary() ของคณะ', (function () use ($html, $sum, $n2) {
    foreach (['ad-gross' => $sum['gross'], 'ad-removal' => $sum['removal'], 'ad-net' => $sum['net'], 'ad-part-op' => $sum['operation_total'], 'ad-part-ev' => $sum['event_total'], 'ad-part-sv' => $sum['survey_total']] as $cls => $v)
        if (!preg_match('#class="' . $cls . '"[^>]*>([0-9,.\-]+)<#', $html, $m) || $m[1] !== $n2($v)) return false;
    foreach ([1, 2, 3] as $s) if (!preg_match('#data-scope-val="' . $s . '"[^>]*>([0-9,.\-]+)<#', $html, $m) || $m[1] !== $n2($sum['scope'][$s])) return false;
    return true;
})());
ck('R1c "กรอกแล้ว a / b" บนการ์ดขอบเขตตรง ghg_item_fill() และ % ขอบเขตรวม ≈ 100', (function () use ($html, $o) {
    if (!preg_match_all('#class="ad-scope-fill">กรอกแล้ว <b>(\d+) / (\d+)</b>#u', $html, $mf) || count($mf[1]) !== 3) return false;
    foreach ([1, 2, 3] as $i => $s) if ((int) $mf[1][$i] !== $o['fill'][$s]['filled'] || (int) $mf[2][$i] !== $o['fill'][$s]['total']) return false;
    return preg_match_all('#class="ad-scope-share">([0-9.]+)%#', $html, $ms) === 3 && abs(array_sum(array_map('floatval', $ms[1])) - 100) < 0.2;
})());
ck('R1d ลิงก์รายงานฉบับเต็มมุมมองคณะของปีที่เลือก · ไม่มีการ์ดยอดทั้งมหาวิทยาลัย / ปุ่มที่เรียก API ของ admin',
    strpos($html, 'href="reports.php?view=faculty&amp;year=' . $year . '"') !== false
    && strpos($html, 'data-ad-affil') === false && strpos($html, 'data-ad-source') === false && strpos($html, 'data-ad-open="breakdown"') === false);
ck('R1e ข้อมูลของหน้าต่าง: mode faculty, ยอดตรงการ์ด, รายการ/กิจกรรมตรง overview, ชื่อที่มี < ถูก escape ใน JSON', is_array($data)
    && $data['mode'] === 'faculty' && $near($data['gross'], $sum['gross']) && $near($data['removal'], $sum['removal'])
    && $data['detailRows'] == $o['detail_rows'] && $data['eventGroups'] == $o['event_groups'] && $data['eventCount'] === $o['event_count']
    && $data['affilName'] === 'คณะ<ทดสอบ>' && strpos($mj[1] ?? '', '<ทดสอบ') === false);
ck('R1f แถบกิจกรรม: แถบเดียว บอกจำนวนงาน + ยอดปล่อยตรงการ์ด + ปุ่มเปิดหน้าต่างกิจกรรม · ไม่แสดงรายชื่องานบนหน้า', (function () use ($html, $sum, $o) {
    return substr_count($html, 'class="ad-evbar oe-rise"') === 1
        && preg_match('#class="ad-evbar-count">(\d+)<#', $html, $c) && (int) $c[1] === $o['event_count']
        && preg_match('#class="ad-evbar-total"[^>]*>([0-9,.]+)<#', $html, $t) && $t[1] === number_format($sum['event_total'], 4)
        && strpos($html, 'นับรวมในยอดปล่อยทั้งหมดด้านบนแล้ว') !== false
        && preg_match('#<button type="button" class="ad-evbar-btn" data-ad-open="events">ดูรายละเอียดกิจกรรม#u', $html)
        && strpos($html, 'ad-ev-row') === false && strpos($html, 'data-ad-event') === false && strpos($html, 'ad-ev-list') === false;
})());
ck('R1g แถบกิจกรรม: animation (เข้า + ไอคอนเอียง + ปุ่มลอย/ลูกศรเลื่อน/ยุบตอนกด) ปิดได้เมื่อลดการเคลื่อนไหว · จอแคบปุ่มเต็มแถว · ไม่มีกิจกรรม → ไม่แสดงแถบ', (function () use ($pdo, $year, $render) {
    $css = (string) file_get_contents(__DIR__ . '/../assets/css/admin-dashboard.css');
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*)\}\s*$/s', $css, $rm);
    $r = $rm[1] ?? '';
    $noEv = (int) $pdo->query("SELECT a.id FROM affiliation_id a WHERE NOT EXISTS (SELECT 1 FROM event e WHERE e.affiliation_id = a.id AND e.year_id = $year) ORDER BY a.id LIMIT 1")->fetchColumn();
    $h2 = $noEv ? $render($noEv, (string) $year) : '';
    return preg_match('/\.ad-evbar-btn \{[^}]*transition:/', $css) && preg_match('/\.ad-evbar-btn:hover, \.ad-evbar-btn:focus-visible \{[^}]*translateY\(-2px\)/', $css)
        && preg_match('/\.ad-evbar-btn:active \{[^}]*scale\(/', $css) && preg_match('/\.ad-evbar-btn:hover \.oe-arrow \{[^}]*translateX\(/', $css)
        && preg_match('/\.ad-evbar:hover \.ad-evbar-ic \{[^}]*rotate\(/', $css)
        && strpos($r, '.ad-evbar-btn,') !== false && strpos($r, '.ad-evbar-btn:hover,') !== false && strpos($r, '.ad-evbar:hover .ad-evbar-ic') !== false
        && preg_match('/@container \(max-width: 520px\) \{[^@]*\.ad-evbar \{ flex-wrap: wrap; \}\s*\.ad-evbar-btn \{ width: 100%;/', $css)
        && $noEv > 0 && strpos($h2, 'id="adData"') !== false && strpos($h2, 'class="ad-evbar') === false;
})());
ck('R1h การ์ดอันดับ: อันดับตัวใหญ่นับขึ้น + ยอด/สัดส่วน/เทียบค่าเฉลี่ยและอันดับ 1 ตรง · จุดครบทุกหน่วยงาน จุดคณะตัวเองอยู่ตำแหน่งตามอันดับ + ป้าย · 3 อันดับแรก · ปุ่มดูทั้งหมด', (function () use ($html, $o, $n2) {
    $rank = $o['ranking']; $own = $o['own_rank']; $hero = dean_dash_rank_hero($rank, $own);
    $card = substr($html, (int) strpos($html, 'class="oe-panel ad-rank ad-rank-card'), 30000);
    $card = substr($card, 0, (int) strpos($card, '</section>'));
    preg_match_all('#<span class="ad-rank-val">([0-9,.]+)</span>#', $card, $mv);
    preg_match_all('#<i class="ad-rank-dot( is-own)?"#', $card, $md);
    return strpos($card, '<div class="ad-rank-big"><b data-count="' . $own['rank'] . '" data-digits="0">' . $own['rank'] . '</b><span>/ ' . count($rank) . '</span>') !== false
        && strpos($card, '<b>' . $n2($own['total']) . '</b> tCO₂e · ' . number_format($own['share'], 1) . '% ของทุกหน่วยงาน') !== false
        && strpos($card, ($hero['diff_avg'] <= 0 ? 'ต่ำกว่า' : 'สูงกว่า') . 'ค่าเฉลี่ย ' . $n2(abs($hero['diff_avg']))) !== false
        && strpos($card, 'น้อยกว่าอันดับ 1 อยู่ ' . number_format($hero['below_top'], 1) . '%') !== false
        && count($md[0]) === count($rank) && array_keys(array_filter($md[1], fn($x) => $x !== '')) === [$own['rank'] - 1]
        && strpos($card, '--pos:' . number_format($hero['own_pos'], 2, '.', '') . '%;') !== false && substr_count($card, 'class="ad-rank-pin">คณะของฉัน<') === 1
        && $mv[1] === array_map(fn($r) => $n2($r['total']), array_slice($rank, 0, 3))
        && strpos($card, 'data-ad-open="items"') === false && strpos($card, 'ad-own-rank') === false
        && strpos($card, 'data-ad-open="ranking">ดูอันดับทั้งหมด (' . count($rank) . ' หน่วยงาน)') !== false;
})());
ck('R1j ข้อมูลหน้าต่างอันดับ + กราฟสลับ: JSON มีอันดับครบ, รหัสคณะตัวเอง, ประวัติทั้งมหาวิทยาลัย · ปุ่มสลับ 2 ปุ่ม (คณะเลือกไว้ก่อน) · กล่องตัวเลขทั้งมหาวิทยาลัยซ่อนไว้และยอดตรง', (function () use ($html, $data, $o, $affil, $n2, $years) {
    return $data['ranking'] == $o['ranking'] && $data['ownAffil'] === $affil && $data['uniHistory'] == $o['uni_history'] && count($data['uniHistory']) === count($years)
        && preg_match('#<button type="button" class="ad-seg-btn is-on" data-ad-hist="faculty" aria-pressed="true">คณะของฉัน</button>\s*<button type="button" class="ad-seg-btn" data-ad-hist="uni" aria-pressed="false">ทั้งมหาวิทยาลัย</button>#u', $html) === 1
        && preg_match('#<div class="ad-mini" data-hist-panel="uni" hidden>#', $html) === 1 && preg_match('#<div class="ad-mini" data-hist-panel="faculty">#', $html) === 1
        && preg_match('#class="ad-uni-cumul">([0-9,.]+)<#', $html, $mc) && $mc[1] === $n2($o['uni_cumulative'])
        && strpos($html, 'class="ad-uni-affils">' . count($o['ranking']) . ' / ' . $o['affil_total'] . '<') !== false;
})());
ck('R1i animation: อันดับ (แถวขึ้น + แถบโต + จุดเด้ง + ป้ายเลื่อน/กะพริบ) · ปุ่มสลับกราฟ (พื้นเลื่อน + ตัวเลขขึ้นใหม่) · ปิดได้เมื่อลดการเคลื่อนไหว · การ์ดสูงเท่ากัน', (function () {
    $css = (string) file_get_contents(__DIR__ . '/../assets/css/admin-dashboard.css');
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*)\}\s*$/s', $css, $rm);
    return preg_match('/\.ad-rank-item \{[^}]*animation: oeRise/', $css) && preg_match('/\.ad-rank-fill \{[^}]*animation: adGrow/', $css)
        && preg_match('/\.ad-own-tag \{[^}]*animation: adPulse [^;]* 2;/', $css) && strpos($rm[1] ?? '', '.ad-own-tag') !== false
        && preg_match('/\.ad-rank-row\.is-static:hover \{ background: none; transform: none; \}/', $css)
        && preg_match('/\.ad-rank-item\.is-own \.ad-rank-row \{[^}]*inset 4px 0 0/', $css)
        && preg_match('/\.ad-rank-name-text \{[^}]*text-overflow: ellipsis/', $css) && preg_match('/\.ad-own-tag \{ flex-shrink: 0;/', $css)   // ชื่อยาวไม่ดันป้ายหาย
        // การ์ดสูงเท่ากัน ปุ่มชิดก้น · ปุ่มสลับ: พื้นเลื่อน (transition) + กล่องตัวเลขขึ้นใหม่ + ซ่อนจริง (ad-mini เป็น grid) · ปิดได้เมื่อลดการเคลื่อนไหว
        && preg_match('/\.ad-grid\.is-even \{ align-items: stretch; \}/', $css) && preg_match('/\.oe-btn\.ad-rank-more \{ margin-top: auto;/', $css)
        && preg_match('/\.ad-seg-thumb \{[^}]*transition: transform/', $css) && preg_match('/\.ad-seg\[data-on="uni"\] \.ad-seg-thumb \{ transform: translateX\(100%\); \}/', $css)
        && preg_match('/\.ad-mini\.ad-swap > div \{ animation: oeRise/', $css) && preg_match('/\.ad-mini\[hidden\] \{ display: none; \}/', $css)
        && strpos($rm[1] ?? '', '.ad-seg-thumb') !== false && strpos($rm[1] ?? '', '.ad-mini.ad-swap > div') !== false && strpos($rm[1] ?? '', '.ad-rank-pin') !== false && strpos($rm[1] ?? '', '.ad-rank-dot.is-own') !== false
        // แถบตำแหน่ง: จุดเด้งขึ้นทีละจุด, จุดของคณะกะพริบ, ป้ายเลื่อนไปหยุดใต้จุด (ไม่ล้นขอบ), จอแคบย่ออันดับใหญ่
        && preg_match('/\.ad-rank-dot \{[^}]*animation: adDotIn/', $css) && preg_match('/\.ad-rank-dot\.is-own \{[^}]*adPulse/', $css)
        && preg_match('/\.ad-rank-pin \{[^}]*left: clamp\(34px, var\(--pos\), calc\(100% - 34px\)\)[^}]*animation: adPinSlide/', $css)
        && preg_match('/@container \(max-width: 520px\) \{\s*\.ad-rank-hero/', $css);
})());
ck('R2 admin ถูกส่งไป Dashboard admin: เรียก dean_dash_redirect() ก่อน query ข้อมูล', (function () {
    $src = (string) file_get_contents(__DIR__ . '/../dean/index.php');
    $r = strpos($src, 'dean_dash_redirect('); $q = strpos($src, 'dean_dash_overview(');
    return $r !== false && $q !== false && $r < $q && preg_match('/header\(\'Location: \' \. \$redirect\); exit;/', $src) === 1;
})());
ck('R3 ใช้ชุดเดียวกับ Dashboard admin: ไม่มี <style> / onclick / alert( / สคริปต์ inline ก้อนใหญ่ / dashboard.css / activity-modal.js', (function () use ($html) {
    // ตรวจเฉพาะเนื้อของหน้านี้ (หลังแถบหัว) — dropdown component มี <style>/onclick ของตัวเอง
    $own = substr($html, (int) strpos($html, 'class="ad-hero"'));
    preg_match_all('#<script>(.*?)</script>#s', $own, $ms);
    foreach ($ms[1] as $js) if (strlen($js) > 600 || strpos($js, 'alert(') !== false) return false;
    return strpos($own, '<style') === false && strpos($own, 'onclick=') === false
        && strpos($html, 'css/dashboard.css') === false && strpos($html, 'activity-modal.js') === false
        && strpos($html, 'admin-dashboard.css') !== false && strpos($html, 'admin-dashboard.js') !== false && strpos($own, 'id="adModal"') !== false;
})());
ck('R3b แอนิเมชัน: การ์ด oe-rise + ตัวเลขนับขึ้น (data-count) + แถบโต · CSS ใหม่ปิดได้เมื่อผู้ใช้ลดการเคลื่อนไหว', (function () use ($html) {
    $css = (string) file_get_contents(__DIR__ . '/../assets/css/admin-dashboard.css');
    preg_match('/@media \(prefers-reduced-motion: reduce\) \{(.*)\}\s*$/s', $css, $rm);
    return substr_count($html, 'oe-rise') >= 9 && substr_count($html, 'data-count=') >= 8 && strpos($html, 'ad-fill') !== false
        && strpos($html, 'class="ad-evbar oe-rise"') !== false && strpos($rm[1] ?? '', '.ad-evbar-btn') !== false;
})());
ck('R4 ไม่มีสังกัด → ข้อความแจ้ง ไม่ error · ปีที่ไม่มีในระบบ → ใช้ปีล่าสุด', (function () use ($render, $affil, $year) {
    $a = $render(0, (string) $year);
    $b = $render($affil, '999999');
    return strpos($a, 'ยังไม่ได้ผูกกับคณะ') !== false && !preg_match('/(Fatal error|Warning):/i', $a) && strpos($a, 'id="adData"') === false
        && strpos($b, 'reports.php?view=faculty&amp;year=' . $year . '"') !== false && !preg_match('/(Fatal error|Warning):/i', $b);
})());
@unlink($probe);

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
