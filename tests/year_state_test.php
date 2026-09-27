<?php
/**
 * Unit Test — ปีงบประมาณที่เลือกไว้ ใช้ต่อข้ามหน้า (includes/year_state.php)
 * รัน: C:\xampp\php\php.exe tests\year_state_test.php
 *
 * ล็อกกฎ:
 *   Y1 เลือกปีจาก URL → ใช้ปีนั้นและจำไว้
 *   Y2 ไม่ได้ส่งปีมา → ใช้ปีที่จำไว้ (นี่คือหัวใจของฟีเจอร์: เลือก 2568 ที่ Dashboard แล้วหน้าอื่นต้องเป็น 2568)
 *   Y3 ไม่เคยเลือก → ปีล่าสุด · ปีที่ส่งมา/จำไว้ไม่มีในระบบ → ไม่ error ใช้ปีถัดไปตามลำดับ
 *   Y4 ghg_year_url เติม year ให้ลิงก์เมนู โดยไม่ทับของเดิมและไม่ทำ query/#hash พัง
 *   Y5 ทุกหน้าที่มีตัวเลือกปีเรียกใช้ตัวกลางนี้ (ไม่อ่าน $_GET['year'] เอง) และ logout ล้างปีที่จำไว้
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/year_state.php';

$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

$years = [['year_id' => 25, 'year' => 2569], ['year_id' => 15, 'year' => 2568], ['year_id' => 14, 'year' => 2567]];

// Y1 — เลือกจาก URL
$s = [];
ck('Y1 เลือกปีจาก URL → ใช้ปีนั้น และจำลง session', ghg_pick_year($years, '15', $s) === 15 && $s['year_id'] === 15);
ck('Y1b ส่งมาเป็น int ก็ได้ · เลือกปีใหม่ทับปีที่จำไว้เดิม', ghg_pick_year($years, 14, $s) === 14 && $s['year_id'] === 14);

// Y2 — หน้าถัดไปไม่ได้ส่งปีมา ต้องได้ปีเดิม
$s = ['year_id' => 15];
ck('Y2 ไม่ส่งปีมา → ใช้ปีที่จำไว้ (เลือก 2568 ที่หน้าหนึ่ง หน้าอื่นก็ 2568)',
    ghg_pick_year($years, null, $s) === 15 && ghg_pick_year($years, '', $s) === 15 && ghg_pick_year($years, '0', $s) === 15);

// Y3 — ค่าตั้งต้น + ค่าที่ใช้ไม่ได้
$s = [];
ck('Y3 ยังไม่เคยเลือก → ปีล่าสุด และจำไว้ให้เลย', ghg_pick_year($years, null, $s) === 25 && $s['year_id'] === 25);
$s = ['year_id' => 99];
ck('Y3b ปีที่จำไว้ถูกลบไปแล้ว → ปีล่าสุด', ghg_pick_year($years, null, $s) === 25 && $s['year_id'] === 25);
$s = ['year_id' => 15];
ck('Y3c ?year= ไม่มีในระบบ/ไม่ใช่ตัวเลข → ไม่ทับปีที่จำไว้ ไม่ error',
    ghg_pick_year($years, '999', $s) === 15 && ghg_pick_year($years, 'abc', $s) === 15 && ghg_pick_year($years, '-1', $s) === 15 && $s['year_id'] === 15);
$s = ['year_id' => 15];
ck('Y3d ไม่มีปีในระบบเลย → 0 และล้างค่าที่จำไว้', ghg_pick_year([], '15', $s) === 0 && !isset($s['year_id']));
ck('Y3e ghg_year_label คืนปี พ.ศ. ของ year_id · ไม่พบ → ว่าง',
    ghg_year_label($years, 15) === '2568' && ghg_year_label($years, 99) === '');

// Y4 — เติมปีให้ลิงก์
ck('Y4 ghg_year_url เติม year ให้ลิงก์เมนู (ทั้งแบบมี/ไม่มี query เดิม)',
    ghg_year_url('items.php', 15) === 'items.php?year=15'
    && ghg_year_url('reports.php?view=faculty', 15) === 'reports.php?view=faculty&year=15'
    && ghg_year_url('../admin/index.php', 25) === '../admin/index.php?year=25');
ck('Y4b ไม่ทับ year ที่ลิงก์ระบุมาเอง · ปี 0 → ไม่เติม · #hash ยังอยู่ท้าย URL',
    ghg_year_url('items.php?year=14', 15) === 'items.php?year=14'
    && ghg_year_url('reports.php?view=faculty&year=14', 15) === 'reports.php?view=faculty&year=14'
    && ghg_year_url('items.php', 0) === 'items.php'
    && ghg_year_url('collect.php#tab-event', 15) === 'collect.php?year=15#tab-event');

// Y5 — หน้าต่าง ๆ ต้องใช้ตัวกลางนี้ ไม่อ่าน $_GET['year'] เอง
ck('Y5 หน้าที่มีตัวเลือกปีใช้ ghg_pick_year() ไม่อ่าน $_GET[\'year\'] เอง', (function () {
    $root = dirname(__DIR__);
    $pages = ['admin/index.php', 'officer/index.php', 'dean/index.php', 'dean/reports.php'];
    foreach ($pages as $p) {
        $src = (string) file_get_contents("$root/$p");
        // $_GET['year'] ใช้ได้ที่เดียว คือส่งเข้า ghg_pick_year() — ห้ามหน้าไหนตีความปีเอง
        $uses = preg_match_all('/\$_GET\[[\'"]year[\'"]\]/', $src);
        $via  = substr_count($src, "ghg_pick_year(\$years, \$_GET['year'] ?? null, \$_SESSION)");
        if ($via !== 1 || $uses !== 1) return false;
    }
    return true;
})());
ck('Y5c หน้าที่ใช้ร่วมกัน (แบบสอบถาม/กิจกรรม · ดูดกลับ · เลือกหมวด · กรอกปริมาณ) ก็ใช้ปีที่จำไว้', (function () {
    $root = dirname(__DIR__);
    foreach (['includes/collect_page.php', 'includes/removal_page.php', 'includes/entry_groups_page.php', 'includes/entry_items_page.php'] as $p) {
        $src = (string) file_get_contents("$root/$p");
        if (strpos($src, 'ghg_pick_year(') === false) return false;
        // ห้ามเหลือรูปแบบเดิมที่ตีความ ?year= เอง แล้ว fallback เป็นปีล่าสุด/0
        if (preg_match('/isset\(\$_GET\[[\'"]year[\'"]\]\)\s*\?/', $src)) return false;
    }
    return true;
})());
ck('Y5d ลิงก์เมนูข้างทั้ง 3 role พาปีที่เลือกไปด้วย (ตัวสลับหน้าแบบ SPA ใช้ href ตรง ๆ ทั้ง fetch และ pushState)', (function () {
    $root = dirname(__DIR__);
    foreach (['admin', 'officer', 'dean'] as $r) {
        $src = (string) file_get_contents("$root/$r/includes/sidebar.php");
        if (strpos($src, '$nav = fn(string $href) => ghg_year_url($href, $nav_year);') === false) return false;
        // ทุกลิงก์ที่ชี้ไปหน้าของ role นั้นต้องผ่าน $nav() — ยกเว้นโปรไฟล์ (ไม่มีเรื่องปี) และ logout
        if (preg_match('#href="<\?= \$root \?\? \'\.\./\' \?>' . $r . '/(?!profile\.php)[a-z_]+\.php"#', $src)) return false;
        if (substr_count($src, '$nav((') < 2) return false;   // dean มี 2 ลิงก์ (Dashboard, รายงาน) · admin/officer มากกว่านั้น
    }
    return true;
})());
ck('Y5b logout ล้าง session ทั้งก้อน (ปีที่จำไว้จึงไม่ค้างข้ามผู้ใช้)', (function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/logout.php');
    return preg_match('/session_destroy\s*\(/', $src) && preg_match('/\$_SESSION\s*=\s*\[\s*\]|session_unset\s*\(/', $src);
})());

// ── Y6/Y7 เรนเดอร์หน้าจริง: เปิดหน้าโดยไม่ส่ง ?year= ต้องได้ปีที่จำไว้ ──
$root  = dirname(__DIR__);
$probe = sys_get_temp_dir() . '/year_state_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export($root, true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "GET";
$_GET = $argv[4] === "-" ? [] : ["year" => (int) $argv[4]];
if ($argv[5] !== "-") $_GET["view"] = $argv[5];
session_start();
$_SESSION = ["user_id"=>1,"role"=>$argv[2],"affiliation_id"=>(int)$argv[3],"affiliation_name"=>"หน่วยงานทดสอบ","last_activity"=>time()];
if ($argv[6] !== "-") $_SESSION["year_id"] = (int) $argv[6];
ob_start(); require $root . "/" . $argv[1]; $out = ob_get_clean();
echo $out . "\n<!--SESSION_YEAR:" . ($_SESSION["year_id"] ?? "none") . "-->";
');
/** @return array [html, ปีที่ session จำไว้หลังเรนเดอร์] */
$render = function (string $page, string $role, int $aff, string $getYear, string $sessYear, string $view = '-') use ($probe): array {
    $out = (string) shell_exec(sprintf('%s %s %s %s %d %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe),
        escapeshellarg($page), escapeshellarg($role), $aff, escapeshellarg($getYear), escapeshellarg($view), escapeshellarg($sessYear)));
    preg_match('/<!--SESSION_YEAR:(\w+)-->/', $out, $m);
    return [$out, $m[1] ?? 'none'];
};

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
$pdo   = getDB();
$all   = ghg_years($pdo);
$cur   = (int) $all[0]['year_id'];
$older = (int) ($all[1]['year_id'] ?? 0);
$olderLabel = ghg_year_label($all, $older);
$aff   = (int) $pdo->query("SELECT Affiliation FROM users WHERE role = 'officer' AND Affiliation > 0 LIMIT 1")->fetchColumn();

ck('Y6 มีปีให้ทดสอบอย่างน้อย 2 ปี และมีหน่วยงานของเจ้าหน้าที่', $older > 0 && $aff > 0);

$pages = [
    ['officer/index.php', 'officer', $aff, '-'],
    ['dean/index.php',    'dean',    $aff, '-'],
    ['admin/index.php',   'admin',   0,    '-'],
    ['dean/reports.php',  'dean',    $aff, 'faculty'],
    ['officer/collect.php', 'officer', $aff, '-'],
    ['officer/items.php',   'officer', $aff, '-'],
];
$bad = [];
foreach ($pages as [$p, $role, $a, $view]) {
    [$html, $sess] = $render($p, $role, $a, '-', (string) $older, $view);   // ไม่ส่ง ?year= แต่ session จำ $older ไว้
    if ($sess !== (string) $older) { $bad[] = "$p (session=$sess)"; continue; }
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning:') !== false) { $bad[] = "$p (error)"; continue; }
    // หน้าที่โชว์ปีที่เลือกต้องเห็นปีนั้นบนหน้า
    if (in_array($p, ['officer/index.php', 'dean/index.php', 'admin/index.php', 'dean/reports.php'], true)
        && strpos($html, (string) $olderLabel) === false) $bad[] = "$p (ไม่เห็นปี $olderLabel)";
}
ck("Y6b เปิดหน้าอื่นโดยไม่ส่ง ?year= → ใช้ปีที่จำไว้ ($olderLabel) ครบทุก role", $bad === [], implode(', ', $bad));

[, $sessAfter] = $render('officer/index.php', 'officer', $aff, (string) $cur, (string) $older);
ck('Y7 เลือกปีใหม่จาก URL → ปีที่จำไว้เปลี่ยนตาม (หน้าอื่นจะใช้ปีใหม่ต่อ)', $sessAfter === (string) $cur, "session=$sessAfter");

@unlink($probe);

echo "\nสรุป: PASS $pass / FAIL $fail\n";
exit($fail > 0 ? 1 : 0);
