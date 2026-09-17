<?php
/**
 * Unit Test — หน้า GHG Removal ส่วนกลาง (includes/removal_page.php + includes/removal_entry.php)
 * รัน: C:\xampp\php\php.exe tests\removal_page_test.php
 *
 * ล็อกกฎ:
 *   - แก้ / ลบ ได้เฉพาะรายการของปีที่เลือก (เดิมใช้ id อย่างเดียว)
 *   - ชื่อซ้ำในปีเดียวกัน → ข้อความเข้าใจง่ายทั้งเพิ่มและแก้ไข · ชื่อและหน่วยต้องกรอก
 *   - ค่าดูดกลับ / ปริมาณ: ไม่ติดลบ, ไม่เกิน 1,000,000, คั่นหลักพันได้
 *   - คัดลอกจากปีอื่น: ชื่อ/หน่วย/ค่าดูดกลับ, ปริมาณ = 0, ข้ามชื่อซ้ำ
 *   - หน้าแสดงยอดตรงกับฐานข้อมูล, ไม่ใช่ศูนย์สิ่งแวดล้อม = 403
 * การทดสอบที่แก้ข้อมูลทำใน transaction แล้ว rollback · POST จริงใช้เฉพาะกรณีที่ต้องไม่เปลี่ยนข้อมูล
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_once __DIR__ . '/../includes/removal_entry.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
function in_tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try { return $fn(); } finally { $pdo->rollBack(); }
}
function throws(callable $fn): string {
    try { $fn(); return ''; } catch (Exception $e) { return $e->getMessage(); }
}
$snapshot = fn() => $pdo->query('SELECT id, year_id, name_tiem, unit, factor, qty FROM removal_item ORDER BY id')->fetchAll();
$before = $snapshot();

// ปีที่มีรายการ (ต้นทาง) และปีว่าง (ปลายทาง)
$year  = (int) $pdo->query('SELECT year_id FROM removal_item GROUP BY year_id ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
$empty = (int) $pdo->query('SELECT y.id FROM admin_year y WHERE NOT EXISTS (SELECT 1 FROM removal_item r WHERE r.year_id = y.id) ORDER BY y.year DESC LIMIT 1')->fetchColumn();
$items = $pdo->query("SELECT id, name_tiem, unit, factor, qty FROM removal_item WHERE year_id = $year ORDER BY id")->fetchAll();
echo "ปีที่มีรายการ id $year (" . count($items) . " รายการ) · ปีว่าง id $empty\n\n";

// ── H ฟังก์ชันข้อมูล ──
ck('H1 ชื่อ/หน่วยต้องกรอก, คั่นหลักพันได้, ว่าง = 0 · ค่าดูดกลับ ติดลบ / ไม่ใช่ตัวเลข / เกิน 1,000,000 → แจ้งเหตุผล (เดิมแปลงเป็น 0 / ตัดเหลือเพดาน เงียบ ๆ)',
    throws(fn() => removal_clean_fields(['name' => ' ', 'unit' => 'ต้น'])) === 'กรุณาระบุชื่อรายการ'
    && throws(fn() => removal_clean_fields(['name' => 'ก', 'unit' => ''])) === 'กรุณาระบุหน่วย'
    && str_contains(throws(fn() => removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => '-1'])), 'ติดลบ')
    && str_contains(throws(fn() => removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => 'abc'])), 'ไม่ใช่ตัวเลข')
    && str_contains(throws(fn() => removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => '2,000,000'])), 'เกิน 1,000,000')
    && removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => ''])[2] === 0.0
    && removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => '1,250.5'])[2] === 1250.5);

ck('H2 แก้ไขได้เฉพาะรายการของปีที่เลือก (ส่งปีอื่นมา = ไม่พบ ข้อมูลไม่เปลี่ยน)', in_tx($pdo, function () use ($pdo, $items, $year, $empty) {
    $id = (int) $items[0]['id'];
    $wrong = removal_update_item($pdo, $empty, $id, ['name' => 'แก้ข้ามปี', 'unit' => 'x', 'factor' => '9']);
    $name1 = $pdo->query("SELECT name_tiem FROM removal_item WHERE id = $id")->fetchColumn();
    $right = removal_update_item($pdo, $year, $id, ['name' => 'แก้ในปี', 'unit' => 'x', 'factor' => '9']);
    $name2 = $pdo->query("SELECT name_tiem FROM removal_item WHERE id = $id")->fetchColumn();
    return $wrong === 0 && $name1 === $items[0]['name_tiem'] && $right === 1 && $name2 === 'แก้ในปี';
}));

ck('H3 แก้ชื่อให้ซ้ำในปีเดียวกัน → "มีรายการชื่อนี้อยู่แล้วในปีนี้" (ไม่ใช่ข้อความ SQL)', in_tx($pdo, function () use ($pdo, $items, $year) {
    $in = ['name' => $items[1]['name_tiem'], 'unit' => 'x', 'factor' => '1'];
    return throws(fn() => removal_update_item($pdo, $year, (int) $items[0]['id'], $in)) === 'มีรายการชื่อนี้อยู่แล้วในปีนี้'
        && throws(fn() => removal_add_item($pdo, $year, $in)) === 'มีรายการชื่อนี้อยู่แล้วในปีนี้';
}));

ck('H4 ลบได้เฉพาะรายการของปีที่เลือก', in_tx($pdo, function () use ($pdo, $items, $year, $empty) {
    $id = (int) $items[0]['id'];
    $wrong = removal_delete_item($pdo, $empty, $id);
    $still = (int) $pdo->query("SELECT COUNT(*) FROM removal_item WHERE id = $id")->fetchColumn();
    return $wrong === 0 && $still === 1 && removal_delete_item($pdo, $year, $id) === 1;
}));

ck('H5 บันทึกปริมาณ: "1,500" → 1500, ว่าง → 0, รายการของปีอื่นไม่ถูกแตะ', in_tx($pdo, function () use ($pdo, $items, $year, $empty) {
    $pdo->prepare('INSERT INTO removal_item (year_id, name_tiem, unit, factor, qty) VALUES (?, ?, ?, ?, ?)')->execute([$empty, 'ปีอื่น', 'ต้น', 1, 77]);
    $other = (int) $pdo->lastInsertId();
    $qty = [];
    foreach ($items as $it) $qty[$it['id']] = $it['qty'];
    $qty[$items[0]['id']] = '1,500'; $qty[$items[1]['id']] = ''; $qty[$other] = '999';
    removal_save_qty($pdo, $year, $qty);
    $q = fn($id) => (float) $pdo->query("SELECT qty FROM removal_item WHERE id = $id")->fetchColumn();
    return $q($items[0]['id']) === 1500.0 && $q($items[1]['id']) === 0.0 && $q($items[2]['id']) === (float) $items[2]['qty'] && $q($other) === 77.0;
}));

ck('H6 คัดลอกจากปีอื่น: ครบทุกรายการ, ชื่อ/หน่วย/ค่าดูดกลับตรง, ปริมาณ = 0, คัดลอกซ้ำ = ข้ามทั้งหมด', in_tx($pdo, function () use ($pdo, $items, $year, $empty) {
    $n = removal_copy_items($pdo, $year, $empty);
    $copied = $pdo->query("SELECT name_tiem, unit, factor, qty FROM removal_item WHERE year_id = $empty ORDER BY id")->fetchAll();
    $same = count($copied) === count($items);
    foreach ($copied as $k => $c) {
        $same = $same && $c['name_tiem'] === $items[$k]['name_tiem'] && $c['unit'] === $items[$k]['unit'] && (float) $c['factor'] === (float) $items[$k]['factor'] && (float) $c['qty'] === 0.0;
    }
    return $n === count($items) && $same && removal_copy_items($pdo, $year, $empty) === 0 && removal_copy_items($pdo, $year, $year) === 0;
}));
ck('H6b คัดลอกบางส่วน: ข้ามเฉพาะชื่อที่มีอยู่แล้วในปีปลายทาง', in_tx($pdo, function () use ($pdo, $items, $year, $empty) {
    $pdo->prepare('INSERT INTO removal_item (year_id, name_tiem, unit, factor) VALUES (?, ?, ?, ?)')->execute([$empty, $items[0]['name_tiem'], 'เดิม', 5]);
    $n = removal_copy_items($pdo, $year, $empty);
    $kept = $pdo->prepare('SELECT unit FROM removal_item WHERE year_id = ? AND name_tiem = ?');
    $kept->execute([$empty, $items[0]['name_tiem']]);
    return $n === count($items) - 1 && $kept->fetchColumn() === 'เดิม';
}));
$src = removal_copy_sources($pdo, $empty);
ck('H7 ปีต้นทางของการคัดลอก: เฉพาะปีที่มีรายการ ไม่รวมปีปัจจุบัน',
    in_array($year, array_column($src, 'year_id'), true) && !in_array($year, array_column(removal_copy_sources($pdo, $year), 'year_id'), true)
    && current(array_filter($src, fn($s) => $s['year_id'] === $year))['items'] === count($items));
ck('H9 บันทึกปริมาณที่มีช่องผิด (ติดลบ / ไม่ใช่ตัวเลข / เกิน 1,000,000) → ไม่บันทึกทั้งชุด ปริมาณทุกรายการเท่าเดิม · เพิ่มรายการค่าดูดกลับผิด → ไม่มีรายการเพิ่ม',
    in_tx($pdo, function () use ($pdo, $items, $year, $snapshot) {
    foreach (['-3' => 'ติดลบ', 'abc' => 'ไม่ใช่ตัวเลข', '1000001' => 'เกิน 1,000,000'] as $bad => $why) {
        $s = $snapshot();
        $qty = [];
        foreach ($items as $it) $qty[$it['id']] = '7';
        $qty[$items[1]['id']] = $bad;
        if (!str_contains(throws(fn() => removal_save_qty($pdo, $year, $qty)), $why) || $snapshot() != $s) return false;
        if (!str_contains(throws(fn() => removal_add_item($pdo, $year, ['name' => 'ทดสอบค่าผิด', 'unit' => 'ต้น', 'factor' => $bad])), $why) || $snapshot() != $s) return false;
    }
    return true;
}));
ck('H8 rollback ครบ ข้อมูลจริงเท่าเดิม', $snapshot() == $before);

// ── หน้าเว็บ (render ผ่าน CLI) ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/removal_page_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
[$_, $role, $aff, $b64, $method] = $argv + [5 => ""]; parse_str(base64_decode($b64), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/$role/ghg.php"; $_SERVER["REQUEST_URI"] = "/$role/ghg.php";
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($method === "post") { $_POST = $q; $_GET = []; } else { $_GET = $q; }
session_start();
$_SESSION = ["user_id"=>1,"role"=>$role,"affiliation_id"=>(int)$aff,"affiliation_name"=>"หน่วยงานทดสอบ","firstname"=>"ท","lastname"=>"ส","username"=>"t","last_activity"=>time()];
// CSRF: session มี token และ POST ส่ง token ให้อัตโนมัติ (เทสต์ที่ส่ง csrf_token มาเองไม่ถูกทับ)
$_SESSION["csrf_token"] = "probe-token"; if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST += ["csrf_token" => "probe-token"];
ob_start(); require "$root/$role/ghg.php"; echo ob_get_clean();
');
// query ส่งเป็น base64: escapeshellarg บน Windows แปลง % เป็นช่องว่าง
$render = fn(string $role, int $aff, string $query = '', string $method = 'get') => (string) shell_exec(sprintf('%s %s %s %d %s %s 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $role, $aff, escapeshellarg(base64_encode($query)), $method));
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $h);
$fmt = fn(float $n) => $n == 0 ? '-' : number_format($n, 3);

$h1 = $render('officer', 1, "year=$year");
$central = removal_central_total($pdo, $year); $activity = removal_activity_total($pdo, $year);
ck('R1 ศูนย์สิ่งแวดล้อม: เรนเดอร์ได้ + โหลด officer-entry.css / collect.css / data-entry.js', $noErr($h1)
    && strpos($h1, 'assets/css/collect.css') !== false && strpos($h1, 'assets/css/officer-entry.css') !== false && strpos($h1, 'assets/js/data-entry.js') !== false,
    substr(preg_replace('/\s+/', ' ', $h1), 0, 400));
ck('R2 การ์ดสรุป: ส่วนกลาง / จากกิจกรรม / รวม ตรงกับฐานข้อมูล (รวม = ตัวเลขเดียวกับ Dashboard)',
    strpos($h1, 'data-rm-kpi="central">' . $fmt($central) . '<') !== false && strpos($h1, 'data-rm-kpi="activity">' . $fmt($activity) . '<') !== false
    && strpos($h1, 'data-rm-kpi="total">' . $fmt(removal_total($pdo, $year)) . '<') !== false);
$filled = count(array_filter($items, fn($i) => (float) $i['qty'] > 0));
ck('R3 ตาราง: แถวครบ + แถบสัดส่วน + แถบบันทึก (กรอกแล้ว a / b) + ผูก data-entry.js',
    substr_count($h1, 'data-group="central"') === count($items) && substr_count($h1, 'class="rm-share-bar"') === count($items)
    && strpos($h1, '<span data-de-filled>' . $filled . ' / ' . count($items) . '</span>') !== false && strpos($h1, "deInit(form)") !== false);
ck('R4 ไม่มีฟอร์มเพิ่มแบบเก่า / CSS accordion ที่ไม่ได้ใช้ ค้างในหน้า',
    strpos($h1, 'rm-act') === false && strpos($h1, '＋ เพิ่มรายการดูดกลับ</h2>') === false && strpos($h1, 'id="rmItemModal"') !== false);
ck('R4b ช่องค่าดูดกลับในหน้าต่างรับทศนิยมได้ถึง 6 ตำแหน่ง (step="any") · ข้อมูลในปุ่มแก้ไขไม่มีศูนย์ต่อท้าย · ค่าดูดกลับทศนิยม 7 ตำแหน่ง → ข้อความ',
    preg_match('/id="rmItemFactor" name="factor" type="number" step="any"/', $h1) && !preg_match('/data-(?:factor|ef)="\d+\.\d*0"/', $h1)
    && str_contains(throws(fn() => removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => '0.1234567'])), 'ทศนิยมเกิน 6 ตำแหน่ง')
    && removal_clean_fields(['name' => 'ก', 'unit' => 'ต้น', 'factor' => '0.123456'])[2] === 0.123456);
$hasOther = (bool) removal_copy_sources($pdo, $year);
ck('R5 ปุ่มคัดลอกแสดงเมื่อมีปีอื่นที่มีรายการ', $hasOther === (strpos($h1, 'onclick="rmCopyOpen()"') !== false));

$h2 = $render('officer', 1, "year=$empty");
ck('R6 ปีว่าง: หน้าว่างพร้อมปุ่มคัดลอกจากปีที่มีรายการ + เพิ่มรายการแรก', $noErr($h2)
    && strpos($h2, 'ยังไม่มีรายการดูดกลับในปีนี้') !== false && strpos($h2, 'id="rmCopyModal"') !== false
    && strpos($h2, 'เพิ่มรายการแรก') !== false && strpos($h2, 'id="rmForm"') === false);

$h403 = $render('officer', 2, "year=$year");
$hA   = $render('admin', 0, "year=$year");
ck('R7 เจ้าหน้าที่หน่วยงานอื่น = 403 / admin เรนเดอร์ได้', stripos($h403, '403') !== false && strpos($h403, 'id="rmForm"') === false
    && $noErr($hA) && substr_count($hA, 'data-group="central"') === count($items));

// POST จริง: ส่งปีผิด → ต้องไม่แก้ / ไม่ลบ
$render('officer', 1, "action=edit_removal_item&year_id=$empty&item_id={$items[0]['id']}&name=" . urlencode('แก้ข้ามปี') . "&unit=x&factor=1", 'post');
$render('officer', 1, "action=delete_removal_item&year_id=$empty&item_id={$items[0]['id']}", 'post');
ck('S1 POST แก้ / ลบ โดยส่งปีอื่น → รายการไม่เปลี่ยน', $snapshot() == $before);

$css = file_get_contents(__DIR__ . '/../assets/css/collect.css');
ck('C1 แถบสัดส่วนยืดตอนเปิดหน้า + ขยับตามที่พิมพ์ + ปิดเมื่อผู้ใช้ตั้งลดการเคลื่อนไหว',
    strpos($css, 'animation: oeGrow .9s cubic-bezier(.22,1,.36,1) backwards; animation-delay: calc(.25s + var(--i, 0) * 60ms);') !== false
    && strpos($css, '.co-detail::before, .rm-share-bar { animation: none; }') !== false);

ck('C2 ตารางรายการเว้นระยะจากการ์ดสรุป (ไม่ติดกัน)', strpos($css, '.rm-panel { margin-top: 1.5rem; }') !== false && strpos($h1, 'class="oe-panel co-list rm-panel') !== false);
ck('C3 ไม่โหลด dashboard.css รุ่นเก่า (ปิดไฟล์นี้ในเบราว์เซอร์แล้ว computed style ทุก element เท่าเดิม ทั้งธีมสว่าง/มืด 3 ขนาดจอ — ไม่มี class ไหนใช้)',
    strpos($h1, 'assets/css/dashboard.css') === false && strpos((string) file_get_contents(__DIR__ . '/../includes/removal_page.php'), 'css/dashboard.css') === false);

@unlink($probe);   // ไฟล์ probe ชั่วคราว
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
