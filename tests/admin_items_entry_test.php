<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * Unit Test — หน้ากรอกข้อมูลของ admin ① items.php ② data_entry.php ③ data_entry_items.php + includes/admin_items_entry.php
 * รัน: C:\xampp\php\php.exe tests\admin_items_entry_test.php
 *
 * การทดสอบฟังก์ชันที่แก้ข้อมูล ทำใน transaction แล้ว rollback ทุกครั้ง
 * การบันทึกผ่านหน้าเว็บ (หน้า commit เอง) ใช้แถวที่มีอยู่แล้ว จำค่าเดิม แล้วคืนค่าใน finally
 * ล็อกกฎ:
 *   - ปี: เลขปีต้องเป็น พ.ศ., ห้ามซ้ำ (ซ้ำ → ถามสลับ), สลับอ่านเลขปีจากฐานข้อมูล, ลบปีบอกจำนวนข้อมูลที่จะหาย
 *   - คัดลอกรายการเฉพาะการดำเนินงาน ข้ามชื่อซ้ำ · การ์ดปีนับเฉพาะ source = 'officer'
 *   - หมวด: เลื่อนได้แม้เลขลำดับซ้ำ, ลบไม่ได้เมื่อยังมีรายการใช้
 *   - รายการ EF: แก้/ลบได้เฉพาะของปีนั้น, ชื่อซ้ำ → ข้อความอ่านรู้เรื่อง
 *   - admin เลือกหน่วยงานได้ (ไม่มีจริง → หน่วยงานของตัวเอง) และบันทึกได้จริง (เดิมพัง) · officer ไม่รับ affil จาก URL
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_items_entry.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
/** รันในทรานแซกชันแล้ว rollback เสมอ */
function in_tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try { return $fn(); } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
}
function throws(callable $fn, string $needle = ''): bool {
    try { $fn(); return false; } catch (Exception $e) { return $needle === '' || str_contains($e->getMessage(), $needle); }
}
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
/** สถานะข้อมูลอ้างอิงกลาง — ใช้ยืนยันว่าเทสต์ไม่ทิ้งร่องรอย */
$fingerprint = fn() => $pdo->query("SELECT
    (SELECT MD5(GROUP_CONCAT(CONCAT(id,':',year) ORDER BY id)) FROM admin_year),
    (SELECT MD5(GROUP_CONCAT(CONCAT(id,':',scope,':',name_tiem,':',order_num) ORDER BY id)) FROM admin_g),
    (SELECT MD5(GROUP_CONCAT(CONCAT(id,':',year_id,':',scope,':',name_tiem,':',IFNULL(unit,''),':',AD) ORDER BY id)) FROM admin_item),
    (SELECT MD5(GROUP_CONCAT(CONCAT(id,':',admin_item_id,':',affiliation_id,':',year_id,':',Vol,':',source,':',IFNULL(create_year,'')) ORDER BY id)) FROM user_item)")->fetch(PDO::FETCH_NUM);
$pdo->exec('SET SESSION group_concat_max_len = 100000000');
$before = $fingerprint();

// ปีที่มีรายการการดำเนินงาน (ต้นทาง) และปีที่ยังไม่มี (ปลายทาง)
// ต้นทาง = ปีที่หน่วยงานกรอกข้อมูลมากที่สุด (ไม่ใช่ปีที่มีรายการมากที่สุด: ปีที่เพิ่งคัดลอกรายการมายังไม่มีใครกรอก → หน่วยงานทดสอบได้ 0)
$full  = (int) $one("SELECT ai.year_id FROM admin_item ai LEFT JOIN user_item ui ON ui.admin_item_id = ai.id AND ui.source = 'officer' AND ui.Vol > 0 AND ui.affiliation_id <> 1
    WHERE ai.data_source = 'officer' GROUP BY ai.year_id ORDER BY COUNT(DISTINCT ui.affiliation_id) DESC, COUNT(ui.id) DESC, ai.year_id DESC LIMIT 1");
$bare  = (int) $one("SELECT id FROM admin_year y WHERE NOT EXISTS (SELECT 1 FROM admin_item a WHERE a.year_id = y.id AND a.data_source = 'officer') ORDER BY year DESC LIMIT 1");
$fullY = admin_year_label($pdo, $full);
$bareY = admin_year_label($pdo, $bare);
// หน่วยงานที่มีข้อมูลการดำเนินงานในปีนั้น แต่ไม่ใช่หน่วยงานของ admin (1)
$aff   = (int) $one("SELECT affiliation_id FROM user_item WHERE year_id = $full AND source = 'officer' AND Vol > 0 AND affiliation_id <> 1 GROUP BY affiliation_id ORDER BY COUNT(*) DESC LIMIT 1");
echo "ปีมีรายการ id $full ($fullY) · ปีว่าง id $bare ($bareY) · หน่วยงานทดสอบ $aff\n\n";

// ── ปีงบประมาณ ──
ck('Y1 ปีที่เพิ่มได้: ปีหน้าถึงย้อนหลัง 10 ปี และตัดปีที่มีแล้ว',
    admin_year_options([2569, 2560], 2569) === array_values(array_diff(range(2570, 2559), [2569, 2560])));
ck('Y2 เพิ่มปี: ไม่ใช่ พ.ศ. / ปีซ้ำ → ข้อความ, ปีใหม่ → ได้ id', in_tx($pdo, function () use ($pdo, $fullY) {
    $id = admin_add_year($pdo, 2601);
    return throws(fn() => admin_add_year($pdo, 2026), 'พ.ศ.') && throws(fn() => admin_add_year($pdo, $fullY), 'มีอยู่ในระบบแล้ว')
        && admin_year_label($pdo, $id) === 2601;
}));
ck('Y3 เปลี่ยนเลขปี: ชนปีที่มี → คืน id ของปีนั้น (ไม่เปลี่ยน), เลขว่าง → เปลี่ยน, id ไม่มีจริง → ข้อความ', in_tx($pdo, function () use ($pdo, $full, $bare, $fullY, $bareY) {
    $dup = admin_rename_year($pdo, $bare, $fullY);
    $same = admin_year_label($pdo, $bare) === $bareY;
    $ok = admin_rename_year($pdo, $bare, 2602) === null && admin_year_label($pdo, $bare) === 2602;
    return $dup === $full && $same && $ok && throws(fn() => admin_rename_year($pdo, 999999, 2603), 'ไม่พบ');
}));
ck('Y4 สลับปี: สลับตามเลขในฐานข้อมูล ข้อมูลที่ผูกกับ id ไม่ย้าย', in_tx($pdo, function () use ($pdo, $full, $bare, $fullY, $bareY) {
    $items = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE year_id = $full")->fetchColumn();
    admin_swap_years($pdo, $full, $bare);
    return admin_year_label($pdo, $full) === $bareY && admin_year_label($pdo, $bare) === $fullY
        && (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE year_id = $full")->fetchColumn() === $items
        && throws(fn() => admin_swap_years($pdo, $full, 999999), 'ไม่พบ');
}));
$im = admin_year_impact($pdo, $full);
ck('Y5 ผลกระทบของการลบปี = จำนวนจริงในทุกตาราง และข้อความบอกครบ',
    $im['items'] === (int) $one("SELECT COUNT(*) FROM admin_item WHERE year_id = $full")
    && $im['rows'] === (int) $one("SELECT COUNT(*) FROM user_item WHERE year_id = $full AND Vol > 0")
    && $im['affils'] === (int) $one("SELECT COUNT(DISTINCT affiliation_id) FROM user_item WHERE year_id = $full AND Vol > 0")
    && $im['events'] === (int) $one("SELECT COUNT(*) FROM event WHERE year_id = $full")
    && $im['surveys'] === (int) $one("SELECT COUNT(*) FROM questionnaire WHERE year_id = $full")
    && str_contains(admin_year_impact_text($fullY, $im), "จาก {$im['affils']} หน่วยงาน")
    && str_contains(admin_year_impact_text(2600, array_fill_keys(['items', 'rows', 'affils', 'events', 'surveys', 'removals'], 0)), 'ยังไม่มีข้อมูล'),
    json_encode($im));
ck('Y6 ลบปี: ข้อมูลทุกตารางของปีนั้นหาย (cascade) id ไม่มีจริง → 0', in_tx($pdo, function () use ($pdo, $full) {
    return admin_delete_year($pdo, $full) === 1 && admin_delete_year($pdo, 999999) === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE year_id = $full")->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE year_id = $full")->fetchColumn() === 0;
}));
ck('Y7 คัดลอกรายการ: เฉพาะการดำเนินงาน ครั้งที่สองชื่อซ้ำหมด → 0, ปีเดียวกัน → ข้อความ', in_tx($pdo, function () use ($pdo, $full, $bare) {
    $src = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE year_id = $full AND data_source = 'officer'")->fetchColumn();
    $otherQ = "SELECT COUNT(*) FROM admin_item WHERE year_id = $bare AND data_source <> 'officer'";
    $otherBefore = (int) $pdo->query($otherQ)->fetchColumn();
    $n1 = admin_copy_items($pdo, $full, $bare);
    $n2 = admin_copy_items($pdo, $full, $bare);
    return $n1 === $src && $n2 === 0 && (int) $pdo->query($otherQ)->fetchColumn() === $otherBefore
        && (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE year_id = $bare AND data_source = 'officer'")->fetchColumn() === $src && throws(fn() => admin_copy_items($pdo, $full, $full));
}));
$cards = admin_year_cards($pdo);
$c = current(array_filter($cards, fn($x) => $x['id'] === $full));
ck('Y8 การ์ดปี: ทุกปี, ยอด/หน่วยงานนับเฉพาะการดำเนินงาน, จำนวนรายการตรง',
    count($cards) === (int) $one('SELECT COUNT(*) FROM admin_year')
    && abs($c['total'] - (float) $one("SELECT COALESCE(SUM(ui.Vol*ai.AD)/1000,0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.year_id = $full AND ui.source = 'officer'")) < 1e-6
    && $c['affils'] === (int) $one("SELECT COUNT(DISTINCT affiliation_id) FROM user_item WHERE year_id = $full AND source = 'officer' AND Vol > 0")
    && $c['items'] === (int) $one("SELECT COUNT(*) FROM admin_item WHERE year_id = $full AND data_source = 'officer'"),
    json_encode($c));

// ── หมวด ──
ck('G1 เพิ่มหมวด: ขอบเขตผิด / ชื่อว่าง / ชื่อซ้ำ → ข้อความ, สำเร็จ → ต่อท้ายขอบเขต', in_tx($pdo, function () use ($pdo) {
    $dupName = (string) $pdo->query('SELECT name_tiem FROM admin_g LIMIT 1')->fetchColumn();
    $max = (int) $pdo->query('SELECT MAX(order_num) FROM admin_g WHERE scope = 2')->fetchColumn();
    $id = admin_add_group($pdo, 2, '  หมวดทดสอบ  ');
    $row = $pdo->query("SELECT scope, name_tiem, order_num FROM admin_g WHERE id = $id")->fetch();
    return throws(fn() => admin_add_group($pdo, 4, 'x'), 'ขอบเขต') && throws(fn() => admin_add_group($pdo, 1, ' '), 'ชื่อ')
        && throws(fn() => admin_add_group($pdo, 1, $dupName), 'มีหมวดชื่อนี้')
        && (int) $row['scope'] === 2 && $row['name_tiem'] === 'หมวดทดสอบ' && (int) $row['order_num'] === $max + 1;
}));
ck('G2 เลื่อนหมวด: เลขลำดับซ้ำก็เลื่อนได้, บนสุดเลื่อนขึ้น → false', in_tx($pdo, function () use ($pdo) {
    $scope = (int) $pdo->query('SELECT scope FROM admin_g GROUP BY scope HAVING COUNT(*) >= 2 ORDER BY scope LIMIT 1')->fetchColumn();
    $pdo->exec("UPDATE admin_g SET order_num = 0 WHERE scope = $scope");   // จำลองเลขซ้ำ (บั๊กเดิม: สลับแล้วไม่ขยับ)
    $order = fn() => array_map('intval', $pdo->query("SELECT id FROM admin_g WHERE scope = $scope ORDER BY order_num, id")->fetchAll(PDO::FETCH_COLUMN));
    $ids = $order();
    $moved = admin_move_group($pdo, $ids[0], 'down');
    $after = $order();
    return $moved && $after[0] === $ids[1] && $after[1] === $ids[0] && admin_move_group($pdo, $after[0], 'up') === false
        && throws(fn() => admin_move_group($pdo, 999999, 'up'), 'ไม่พบ');
}));
ck('G3 ลบหมวด: ยังมีรายการใช้ → ข้อความบอกจำนวน, หมวดว่าง → ลบได้', in_tx($pdo, function () use ($pdo) {
    $used = (int) $pdo->query('SELECT scope FROM admin_item GROUP BY scope LIMIT 1')->fetchColumn();
    $id = admin_add_group($pdo, 3, 'หมวดว่างทดสอบ');
    admin_delete_group($pdo, $id);
    return throws(fn() => admin_delete_group($pdo, $used), 'ยังมีรายการ Emission Factor ใช้อยู่')
        && !$pdo->query("SELECT 1 FROM admin_g WHERE id = $id")->fetchColumn();
}));
$groupsList = admin_groups($pdo);
ck('G4 รายชื่อหมวด: ครบทุกหมวด + จำนวนรายการที่ใช้หมวดนั้นทุกปี',
    count($groupsList) === (int) $one('SELECT COUNT(*) FROM admin_g')
    && array_sum(array_column($groupsList, 'items')) === (int) $one('SELECT COUNT(*) FROM admin_item WHERE scope IS NOT NULL'));

// ── รายการ Emission Factor ──
[$e1] = admin_item_clean(['name_tiem' => ' ', 'unit' => '', 'AD' => 'abc', 'group_id' => 0]);
[$e2] = admin_item_clean(['name_tiem' => 'x', 'unit' => 'L', 'AD' => '-1', 'group_id' => 1]);
[$e3, $d3] = admin_item_clean(['name_tiem' => ' ดีเซล ', 'unit' => ' ลิตร ', 'AD' => '2,7406', 'group_id' => '2']);
ck('I1 ตรวจค่าฟอร์ม: ช่องว่าง/ไม่ใช่ตัวเลข/ติดลบ → ข้อความ, คั่นหลักได้ + ตัดช่องว่าง',
    count($e1) === 4 && ($e2['AD'] ?? '') === 'ค่าการปล่อยต้องไม่ติดลบ' && $e3 === []
    && $d3 === ['group' => 2, 'name' => 'ดีเซล', 'unit' => 'ลิตร', 'ad' => 27406.0]);
[$e4] = admin_item_clean(['name_tiem' => 'x', 'unit' => 'L', 'AD' => '0.1234567', 'group_id' => 1]);
[$e5, $d5] = admin_item_clean(['name_tiem' => 'x', 'unit' => 'L', 'AD' => '0.123456', 'group_id' => 1]);
ck('I1b EF ทศนิยมเกิน 6 ตำแหน่ง → ข้อความ (คอลัมน์ decimal(13,6) ปัดทิ้งเงียบ ๆ) · 6 ตำแหน่งพอดีผ่าน',
    str_contains($e4['AD'] ?? '', 'ทศนิยมเกิน 6 ตำแหน่ง') && $e5 === [] && $d5['ad'] === 0.123456, json_encode([$e4, $e5]));
ck('I2 เพิ่ม/แก้/ลบ รายการ: เฉพาะของปีนั้น, ชื่อซ้ำในหมวด → ข้อความอ่านรู้เรื่อง, หมวดไม่มีจริง → ข้อความ', in_tx($pdo, function () use ($pdo, $full, $bare) {
    $g = (int) $pdo->query("SELECT scope FROM admin_item WHERE year_id = $full AND data_source = 'officer' LIMIT 1")->fetchColumn();
    $in = ['group_id' => $g, 'name_tiem' => 'รายการทดสอบ', 'unit' => 'หน่วย', 'AD' => '1.5'];
    $id = admin_item_add($pdo, $full, $in);
    $dup = throws(fn() => admin_item_add($pdo, $full, $in), 'มีรายการชื่อนี้ในหมวดเดียวกัน');
    $noGroup = throws(fn() => admin_item_add($pdo, $full, ['group_id' => 999999] + $in), 'ไม่พบหมวด');
    $wrongYear = admin_item_update($pdo, $bare, $id, $in) === 0 && admin_item_delete($pdo, $bare, $id) === 0;
    $upd = admin_item_update($pdo, $full, $id, ['AD' => '3.25', 'name_tiem' => 'รายการทดสอบ 2'] + $in) === 1;
    $row = $pdo->query("SELECT name_tiem, AD, data_source FROM admin_item WHERE id = $id")->fetch();
    $del = admin_item_delete($pdo, $full, $id) === 1;
    return $dup && $noGroup && $wrongYear && $upd && $row['name_tiem'] === 'รายการทดสอบ 2' && abs($row['AD'] - 3.25) < 1e-6
        && $row['data_source'] === 'officer' && $del;
}));
$usage = admin_item_usage($pdo, $full);
$someItem = (int) $one("SELECT admin_item_id FROM user_item WHERE year_id = $full AND Vol > 0 GROUP BY admin_item_id ORDER BY COUNT(DISTINCT affiliation_id) DESC LIMIT 1");
ck('I3 จำนวนหน่วยงานที่กรอกรายการ (แสดงตอนยืนยันลบ) ตรงกับฐานข้อมูล',
    ($usage[$someItem] ?? -1) === (int) $one("SELECT COUNT(DISTINCT affiliation_id) FROM user_item WHERE year_id = $full AND Vol > 0 AND admin_item_id = $someItem"));

$affils = admin_affiliations($pdo);
ck('A1 เลือกหน่วยงาน: มีจริง → ใช้ค่านั้น, ไม่มีจริง/ไม่ส่ง → หน่วยงานของ admin',
    admin_pick_affiliation($affils, (string) $aff, 1)['id'] === $aff && admin_pick_affiliation($affils, '999999', 1)['id'] === 1
    && admin_pick_affiliation($affils, null, 1)['id'] === 1 && admin_pick_affiliation($affils, 'x', 1)['name'] !== '');

// ── หน้าเว็บ (render ผ่าน CLI) ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/admin_entry_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
[$_, $role, $page, $qs, $method] = $argv; parse_str(base64_decode($qs), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/$role/$page"; $_SERVER["REQUEST_URI"] = "/$role/$page";
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($method === "post") $_POST = $q; else $_GET = $q;
session_start();
$_SESSION = ["user_id"=>1,"role"=>$role,"affiliation_id"=>1,"affiliation_name"=>"หน่วยงานของผู้ใช้","firstname"=>"ท","lastname"=>"ส","username"=>"t","last_activity"=>time()];
// CSRF: session มี token และ POST ส่ง token ให้อัตโนมัติ (เทสต์ที่ส่ง csrf_token มาเองไม่ถูกทับ)
$_SESSION["csrf_token"] = "probe-token"; if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST += ["csrf_token" => "probe-token"];
ob_start(); require "$root/$role/$page"; echo ob_get_clean();
');
// query ส่งเป็น base64: escapeshellarg บน Windows แปลง % เป็นช่องว่าง
$render = fn(string $page, string $query = '', string $method = 'get', string $role = 'admin') => (string) shell_exec(sprintf('%s %s %s %s %s %s 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $role, escapeshellarg($page), escapeshellarg(base64_encode($query)), $method));
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $h);
/**
 * สคริปต์ของหน้าใน <main> ต้องไม่มี let/const ระดับบนสุด (SPA รันซ้ำแล้ว error ประกาศซ้ำ)
 * ข้ามสคริปต์ของ component กลาง (dropdown / confirm / evidence) ซึ่ง const อยู่ในฟังก์ชัน
 */
$topLevelDecl = function (string $h): bool {
    $from = (int) strpos($h, '<main');
    preg_match_all('#<script>(.*?)</script>#s', substr($h, $from, (int) strpos($h, '</main>') - $from), $m);
    foreach ($m[1] as $js) {
        if (preg_match('/function ddToggle|window\.cfmForm|window\.openEvidence/', $js)) continue;
        if (preg_match('/^\s{0,12}(const|let)\s/m', $js)) return true;
    }
    return false;
};

$h1 = $render('items.php', 'msg=' . urlencode('ทดสอบข้อความ'));
ck('P1 ① items.php เรนเดอร์ได้ + แถบขั้นตอน + ข้อความหลัง redirect แสดงเป็น toast (เดิมหาย)', $noErr($h1)
    && str_contains($h1, 'aria-current="step"><span class="oe-step-no">1</span>') && str_contains($h1, 'ทดสอบข้อความ'), substr($h1, 0, 300));
ck('P1b การ์ดครบทุกปี แสดงยอด/รายการ/หน่วยงาน ตรงกับ admin_year_cards และปีที่ไม่มีรายการชวนคัดลอก', (function () use ($h1, $cards, $affils, $bare) {
    if (preg_match_all('#<div class="oe-year oe-rise[^"]*" style="--i:\d+;" data-year-id="(\d+)">#', $h1) !== count($cards)) return false;
    foreach ($cards as $c) {
        $re = '#data-year-id="' . $c['id'] . '">.*?oe-year-total">' . preg_quote(number_format($c['total'], 2), '#') . ' <small>.*?class="ai-items">' . $c['items']
            . '</b>.*?class="ai-affils">' . $c['affils'] . ' / ' . count($affils) . '</b>#s';
        if (!preg_match($re, $h1)) return false;
    }
    return (bool) preg_match('#data-year-id="' . $bare . '">.*?คัดลอกรายการจากปีอื่น</button>#s', $h1);
})());
ck('P1c ยืนยันลบปีบอกจำนวนข้อมูลที่จะหาย + ใช้ confirmDelete (ไม่มี onmouseover / let-const ระดับบนสุด / ddReset ที่ไม่มีจริง)',
    str_contains($h1, htmlspecialchars(admin_year_impact_text($fullY, admin_year_impact($pdo, $full)), ENT_QUOTES))
    && str_contains($h1, 'confirmDelete({ title: \'ลบปีงบประมาณ?\'') && stripos($h1, 'onmouseover') === false
    && !$topLevelDecl($h1) && !str_contains($h1, 'ddReset(') && str_contains($h1, "id=\"cfmModal\""));
ck('P1d ไม่เหลือโค้ดเพิ่ม/แก้รายการที่ไม่มีปุ่มเรียก (modal-add / modal-edit / openEditModal)',
    !str_contains($h1, 'id="modal-add"') && !str_contains($h1, 'id="modal-edit"') && !str_contains($h1, 'openEditModal'));
$hs = $render('items.php', "swap=1&id1=$bare&id2=$full&y1=1111&y2=2222");
ck('P1e หน้าต่างสลับปีอ่านเลขปีจากฐานข้อมูล (ไม่ใช้ y1/y2 จาก URL) และส่งแค่ id',
    $noErr($hs) && str_contains($hs, "ปี $fullY มีอยู่แล้ว") && !str_contains($hs, '1111') && !str_contains($hs, 'name="y1"'));
ck('P1f หน้าต่างจัดการหมวดแสดงครบ ปุ่มลบหมวดที่มีรายการถูกปิด', (function () use ($h1, $groupsList) {
    foreach ($groupsList as $g) {
        if (!preg_match('#<div class="oe-gm-row" data-id="' . $g['id'] . '">.*?<button type="button" class="oe-gm-btn is-del" ([^>]*)>#s', $h1, $m)) return false;
        if (($g['items'] > 0) !== str_contains($m[1], 'disabled')) return false;
    }
    return true;
})());

$h2 = $render('data_entry.php', "year=$full&affil=$aff");
$stats2 = officer_group_stats($pdo, $aff, $full);
$emptyG = array_keys(array_filter($stats2, fn($s) => $s['items'] === 0));
ck('P2 ② admin เลือกหน่วยงาน: dropdown เลือกไว้ถูก, ลิงก์ขั้น ③ ส่ง affil ต่อ, สถิติเป็นของหน่วยงานนั้น', $noErr($h2)
    && str_contains($h2, 'id="oeAffil_input"') && preg_match('#id="oeAffil_input"\s+value="' . $aff . '"#', $h2)
    && str_contains($h2, '<input type="hidden" name="affil" value="' . $aff . '">')
    && (function () use ($h2, $stats2) {
        foreach ($stats2 as $s) if ($s['items'] > 0 && !str_contains($h2, 'กรอกแล้ว ' . $s['filled'] . ' / ' . $s['items'] . ' รายการ')) return false;
        return true;
    })(), substr($h2, 0, 300));
ck('P2b ② admin เลือกหมวดที่ยังไม่มีรายการได้ (เพื่อไปเพิ่มรายการ) · officer ยังเลือกไม่ได้', (function () use ($h2, $emptyG, $render, $full) {
    if (!$emptyG) return true;
    $ho = $render('data_entry.php', "year=$full", 'get', 'officer');
    foreach ($emptyG as $g) {
        if (!str_contains($h2, 'value="' . $g . '">')) return false;
        if (!str_contains($ho, 'value="' . $g . '" disabled>')) return false;
    }
    return true;
})());
$h2x = $render('data_entry.php', "year=$full&affil=999999");
ck('P2c affil ไม่มีจริง → ใช้หน่วยงานของ admin (id 1)', $noErr($h2x) && (bool) preg_match('#id="oeAffil_input"\s+value="1"#', $h2x));

$gids = array_keys(array_filter($stats2, fn($s) => $s['items'] > 0));
$qs3  = "year=$full&affil=$aff&" . implode('&', array_map(fn($g) => "scope_groups[]=$g", $gids));
$h3   = $render('data_entry_items.php', $qs3);
$filled = (int) $one("SELECT COUNT(*) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = $full AND ai.data_source = 'officer'
    WHERE ui.affiliation_id = $aff AND ui.year_id = $full AND ui.source = 'officer' AND ui.Vol > 0");
$total = (int) $one("SELECT COUNT(*) FROM admin_item WHERE year_id = $full AND data_source = 'officer'");
ck('P3 ③ admin: ปริมาณที่แสดงเป็นของหน่วยงานที่เลือก (เดิมอ่าน session ผิดคีย์ ได้หน่วยงาน 0)', $noErr($h3)
    && str_contains($h3, '<span data-de-filled>' . $filled . ' / ' . $total . '</span>')
    && str_contains($h3, 'action="data_entry_items.php?year=' . $full . '&amp;affil=' . $aff . '&amp;scope_groups[]='), substr($h3, 0, 300));
ck('P3b ③ admin: ปุ่มแก้/ลบทุกแถว (ข้อมูลผ่าน data-* ไม่ใช่ onclick+addslashes) + ปุ่มเพิ่มรายการทุกหมวด + หน้าต่างรายการ',
    preg_match_all('#class="oe-icon-btn oe-icon-edit" title="แก้ไขรายการ"#', $h3) === $total
    && preg_match_all('#class="oe-icon-btn oe-icon-del" title="ลบรายการ"#', $h3) === $total
    && substr_count($h3, 'class="oe-add-btn"') === count($gids)
    && str_contains($h3, 'id="oeItemModal"') && !str_contains($h3, 'addslashes') && !$topLevelDecl($h3));
ck('P3e ③ admin (ไฟล์ร่วมกับเจ้าหน้าที่): หน้าต่างเลือกหมวดเป็น co-modal · ซ่อนหมวดใช้ confirmDelete · ปุ่มยกเลิกหน้าต่างรายการใช้ data-close (ไม่มี onclick closeModal)',
    str_contains($h3, '<div class="modal-box co-modal oe-scope-modal">') && !str_contains($h3, 'modal-confirm-delete-section')
    && str_contains($h3, "confirmText: 'ซ่อนหมวด'") && !str_contains($h3, 'onclick="closeModal(')
    && str_contains($h3, '<button type="button" class="btn-secondary" data-close>ยกเลิก</button>'));
$u = $usage[$someItem] ?? 0;
ck('P3c ③ ยืนยันลบรายการบอกจำนวนหน่วยงานที่กรอกไว้', str_contains($h3, htmlspecialchars("ปริมาณที่กรอกไว้ของ $u หน่วยงานจะถูกลบไปด้วย", ENT_QUOTES)));
ck('P3d ③ หลักฐาน: แถวที่มีข้อมูลอ้างอิงแถวของหน่วยงานที่เลือกตรง ๆ, ยังไม่มีข้อมูล → ปุ่มปิด (ไม่แนบผิดหน่วยงาน)', (function () use ($h3, $pdo, $aff, $full, $total) {
    $rows = (int) $pdo->query("SELECT COUNT(*) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = $full AND ai.data_source = 'officer'
        WHERE ui.affiliation_id = $aff AND ui.year_id = $full AND ui.source = 'officer'")->fetchColumn();
    return preg_match_all('#onclick="openEvidence\(\{type:\'user_item\', id:\d+#', $h3) === $rows
        && substr_count($h3, 'class="ev-open-btn" disabled') === $total - $rows && !str_contains($h3, 'data-ev="user_item_legacy');
})());

// P4 — admin บันทึกจริงผ่านหน้าเว็บให้หน่วยงานอื่น: ใช้แถวที่มีอยู่แล้ว จำค่าเดิม คืนค่าใน finally
$rows = $pdo->query("SELECT ui.id, ui.admin_item_id, ui.Vol, ui.create_year FROM user_item ui
    WHERE ui.affiliation_id = $aff AND ui.year_id = $full AND ui.source = 'officer' AND ui.Vol > 0 ORDER BY ui.id LIMIT 2")->fetchAll();
$foreign = (int) $one("SELECT id FROM admin_item WHERE data_source <> 'officer' OR year_id <> $full ORDER BY id LIMIT 1");
$countOf = fn() => (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE affiliation_id IN ($aff, 1) AND year_id = $full")->fetchColumn();
$cBefore = $countOf();
try {
    $newVol = (float) $rows[0]['Vol'] + 111;
    $post = "action=save&year=$full&affil=$aff&scope_groups[]={$gids[0]}"
        . '&vol[' . $rows[0]['admin_item_id'] . ']=' . urlencode(number_format($newVol, 2, '.', ','))
        . '&vol[' . $rows[1]['admin_item_id'] . ']='
        . "&vol[$foreign]=9";
    $out = $render('data_entry_items.php', $post, 'post');
    $st = $pdo->prepare('SELECT Vol FROM user_item WHERE id = ?');
    $st->execute([$rows[0]['id']]); $v0 = (float) $st->fetchColumn();
    $st->execute([$rows[1]['id']]); $v1 = (float) $st->fetchColumn();
    ck('P4 admin บันทึกปริมาณให้หน่วยงานที่เลือกได้จริง (เดิมพัง) · ช่องว่าง → 0 (ค่าติดลบถูกปฏิเสธทั้งฟอร์ม — officer_data_entry_test E1c/P5c) · รายการต่างปีถูกข้าม · ไม่สร้างแถวให้หน่วยงานของ admin',
        $noErr($out . ' ') && abs($v0 - $newVol) < 1e-6 && $v1 === 0.0 && $countOf() === $cBefore, "v0=$v0 v1=$v1 out=" . substr($out, 0, 200));
} finally {
    $restore = $pdo->prepare('UPDATE user_item SET Vol = ?, create_year = ? WHERE id = ?');
    foreach ($rows as $r) $restore->execute([$r['Vol'], $r['create_year'], $r['id']]);
}

$itemsBefore = (int) $one('SELECT COUNT(*) FROM admin_item');
$render('data_entry_items.php', "action=add_item&year=$full&affil=$aff&scope_groups[]={$gids[0]}&group_id={$gids[0]}&name_tiem=%20&unit=L&AD=1", 'post');
$render('data_entry_items.php', "action=edit_item&year=$bare&affil=$aff&scope_groups[]={$gids[0]}&group_id={$gids[0]}&item_id={$someItem}&name_tiem=x&unit=L&AD=1", 'post');
$render('items.php', 'action=add_year&new_year=12', 'post');
$render('items.php', 'action=delete_group&group_id=' . $gids[0], 'post');
ck('P5 POST ที่ไม่ผ่านการตรวจ (ชื่อว่าง / แก้รายการข้ามปี / ปีผิด / ลบหมวดที่ใช้อยู่) ไม่แตะฐานข้อมูล',
    (int) $one('SELECT COUNT(*) FROM admin_item') === $itemsBefore && $fingerprint() === $before);

$ho3 = $render('data_entry_items.php', "year=$full&affil=$aff&" . implode('&', array_map(fn($g) => "scope_groups[]=$g", $gids)), 'get', 'officer');
$ownFilled = (int) $one("SELECT COUNT(*) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = $full AND ai.data_source = 'officer'
    WHERE ui.affiliation_id = 1 AND ui.year_id = $full AND ui.source = 'officer' AND ui.Vol > 0");
ck('P6 officer ใส่ affil ใน URL ไม่มีผล (ยังเป็นหน่วยงานตัวเอง) และไม่มีเครื่องมือแก้ Emission Factor', $noErr($ho3)
    && str_contains($ho3, '<span data-de-filled>' . $ownFilled . ' / ' . $total . '</span>')
    && !str_contains($ho3, 'oeItemModal') && !str_contains($ho3, 'oeAffil') && !str_contains($ho3, 'oe-icon-del'));

// ── CSS ──
$css = (string) file_get_contents(__DIR__ . '/../assets/css/officer-entry.css');
ck('C1 ปุ่มเพิ่มรายการ/ปุ่มหมวดมี hover ลอย + กดยุบ และปิดเมื่อ prefers-reduced-motion',
    str_contains($css, '.oe-add-btn:hover, .oe-add-btn:focus-visible {') && str_contains($css, '.oe-add-btn:active { transform: scale(.96); }')
    && (bool) preg_match('#prefers-reduced-motion: reduce\) \{[^}]*\.ai-swap-ic[^}]*animation: none;.*?\.oe-add-btn, \.oe-gm-btn, \.oe-gm-row \{ transition: none; \}#s', $css));

ck('Z ข้อมูลอ้างอิงกลางและปริมาณทุกแถวเท่าเดิมหลังเทสต์', $fingerprint() === $before);

@unlink($probe);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
