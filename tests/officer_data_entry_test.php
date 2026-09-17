<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * Unit Test — หน้ากรอกข้อมูลเจ้าหน้าที่ ① items.php ② data_entry.php ③ data_entry_items.php + includes/officer_entry.php
 * รัน: C:\xampp\php\php.exe tests\officer_data_entry_test.php
 *
 * การทดสอบที่แก้ข้อมูล (ลบ/ย้าย/สลับ/คัดลอกปี) ทำใน transaction แล้ว rollback ทุกครั้ง → ข้อมูลจริงไม่เปลี่ยน
 * ล็อกกฎ:
 *   - ลบ / ย้าย / สลับ / คัดลอกปี แตะเฉพาะ source = 'officer' (กิจกรรม/แบบสอบถามต้องอยู่ครบ)
 *   - คัดลอกข้ามปีจับคู่รายการด้วย หมวด + ชื่อ + หน่วย (id ของรายการแยกตามปี)
 *   - ปริมาณที่บันทึก: ไม่ติดลบ, ไม่เกิน 1,000,000, คั่นหลักพันได้
 *   - หน้าแสดงยอด/จำนวนที่กรอกตรงกับฐานข้อมูล, หมวดว่างเลือกไม่ได้, ไม่เหลือฟอร์มแก้ Emission Factor ของ admin
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/officer_entry.php';
require_once __DIR__ . '/../includes/ghg_report.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
/** จำนวนแถว user_item แยกตาม source ของคณะ/ปี */
function src_counts(PDO $pdo, int $aff, int $year): array {
    $st = $pdo->prepare('SELECT source, COUNT(*) FROM user_item WHERE affiliation_id = ? AND year_id = ? GROUP BY source');
    $st->execute([$aff, $year]);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR) + ['officer' => 0, 'event' => 0, 'survey' => 0];
}
/** รันในทรานแซกชันแล้ว rollback เสมอ */
function in_tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try { return $fn(); } finally { $pdo->rollBack(); }
}

// หน่วยงานที่มีทั้ง officer และกิจกรรม/แบบสอบถามในปีเดียวกัน
$case = $pdo->query("SELECT o.affiliation_id AS aff, o.year_id AS y FROM user_item o
    WHERE o.source = 'officer' AND EXISTS (SELECT 1 FROM user_item x WHERE x.affiliation_id = o.affiliation_id AND x.year_id = o.year_id AND x.source <> 'officer')
    GROUP BY o.affiliation_id, o.year_id ORDER BY o.affiliation_id LIMIT 1")->fetch();
$aff  = (int) $case['aff'];
$year = (int) $case['y'];
// ปีอื่นไว้เป็นปลายทางของการย้าย/สลับ/คัดลอก — เลือกปีที่ยังไม่มีรายการการดำเนินงานก่อน (ปีที่คัดลอกรายการมาแล้วชื่อชนกับรายการที่ E5 สร้าง)
$other = (int) $pdo->query("SELECT y.id FROM admin_year y WHERE y.id <> $year
    ORDER BY EXISTS (SELECT 1 FROM admin_item a WHERE a.year_id = y.id AND a.data_source = 'officer'), y.year DESC LIMIT 1")->fetchColumn();
$before = src_counts($pdo, $aff, $year);
$beforeOther = src_counts($pdo, $aff, $other);
echo "หน่วยงาน $aff ปี id $year " . json_encode($before) . " · ปีอื่น id $other " . json_encode($beforeOther) . "\n\n";

// ── E1 ค่าปริมาณ ──
ck('E1 officer_clean_vol: ว่าง/ตัวอักษร/ติดลบ = 0, คั่นหลักพันได้, เกิน 1,000,000 = 1,000,000',
    officer_clean_vol('') === 0.0 && officer_clean_vol('abc') === 0.0 && officer_clean_vol('-5') === 0.0
    && officer_clean_vol(' 1,250.5 ') === 1250.5 && officer_clean_vol('2000000') === 1000000.0 && officer_clean_vol('0.25') === 0.25);

// ค่าที่กรอกผิด (ไม่ใช่ตัวเลข / ติดลบ / เกิน 1,000,000) ต้องถูกปฏิเสธก่อนบันทึก — เดิม officer_clean_vol แปลงเป็น 0 / ตัดเหลือเพดาน เงียบ ๆ แล้วแจ้ง "บันทึกสำเร็จ"
ck('E1b officer_vol_error (กฎเดียวกับ data-entry.js): ว่าง / ตัวเลข / คั่นหลักพัน / เท่าเพดาน = ผ่าน · ไม่ใช่ตัวเลข / ติดลบ / เกิน 1,000,000 = ผิด',
    function_exists('officer_vol_error')
    && officer_vol_error('') === null && officer_vol_error('  ') === null && officer_vol_error('0') === null && officer_vol_error('1,000,000') === null && officer_vol_error(' 1,250.5 ') === null
    && officer_vol_error('abc') === 'ไม่ใช่ตัวเลข' && officer_vol_error('12a') === 'ไม่ใช่ตัวเลข'
    && officer_vol_error('-5') === 'ติดลบ' && officer_vol_error('-0.01') === 'ติดลบ'
    && officer_vol_error('1000000.01') === 'เกิน 1,000,000' && officer_vol_error('2,000,000') === 'เกิน 1,000,000' && officer_vol_error('1e400') === 'เกิน 1,000,000');
ck('E1d officer_ef_error (EF / ค่าดูดกลับ เก็บ decimal(13,6)): กฎเดียวกับปริมาณ + ทศนิยมเกิน 6 ตำแหน่ง = ผิด · ef_input_val ตัดศูนย์ท้ายโดยไม่ปัดเศษ',
    function_exists('officer_ef_error') && function_exists('ef_input_val')
    && officer_ef_error('0.123456') === null && officer_ef_error('1,000,000') === null && officer_ef_error('2.70') === null && officer_ef_error('') === null
    && officer_ef_error('0.1234567') === 'ทศนิยมเกิน 6 ตำแหน่ง' && officer_ef_error('-1') === 'ติดลบ' && officer_ef_error('abc') === 'ไม่ใช่ตัวเลข'
    && officer_ef_error('2000000') === 'เกิน 1,000,000'
    && ef_input_val('2.707600') === '2.7076' && ef_input_val('0.000123') === '0.000123' && ef_input_val('5.000000') === '5'
    && ef_input_val('0.000000') === '0' && ef_input_val(null) === '' && ef_input_val(1.25) === '1.25'
    && function_exists('ef_fmt') && ef_fmt('2.707600') === '2.7076' && ef_fmt('0.500000') === '0.5000' && ef_fmt('0.000123') === '0.000123' && ef_fmt('1234.123450') === '1,234.12345');
ck('E1c officer_save_vols: มีช่องผิดแม้ช่องเดียว (ไม่ใช่ตัวเลข / ติดลบ / เกิน) → ไม่บันทึกทั้งฟอร์ม แจ้งเหตุผลและจำนวนช่อง', (function () use ($pdo, $aff, $year) {
    $rows = $pdo->query("SELECT id, admin_item_id, Vol FROM user_item WHERE affiliation_id = $aff AND year_id = $year AND source = 'officer' ORDER BY id LIMIT 2")->fetchAll();
    $vol = $pdo->prepare('SELECT Vol FROM user_item WHERE id = ?');
    foreach (['abc' => 'ไม่ใช่ตัวเลข', '-9' => 'ติดลบ', '2000000' => 'เกิน 1,000,000'] as $bad => $why) {
        $ok = in_tx($pdo, function () use ($pdo, $aff, $year, $rows, $vol, $bad, $why) {
            try { officer_save_vols($pdo, $aff, $year, [$rows[0]['admin_item_id'] => '12,345', $rows[1]['admin_item_id'] => $bad]); return false; }
            catch (Exception $e) {
                if (!str_contains($e->getMessage(), $why) || !str_contains($e->getMessage(), '1 ช่อง')) return false;
                foreach ($rows as $r) { $vol->execute([$r['id']]); if ((float) $vol->fetchColumn() !== (float) $r['Vol']) return false; }
                return true;
            }
        });
        if (!$ok) return false;
    }
    return true;
})());

// ── E2–E5 คำสั่งของปี แตะเฉพาะ officer ──
ck('E2 ลบปี: ข้อมูลการดำเนินงานหมด แต่กิจกรรม/แบบสอบถามอยู่ครบ', in_tx($pdo, function () use ($pdo, $aff, $year, $before) {
    $n = officer_delete_year($pdo, $aff, $year);
    $c = src_counts($pdo, $aff, $year);
    return $n === (int) $before['officer'] && $c['officer'] === 0 && $c['event'] === $before['event'] && $c['survey'] === $before['survey'];
}));
ck('E2b rollback แล้วข้อมูลจริงเท่าเดิม', src_counts($pdo, $aff, $year) == $before);

// ── ย้าย / สลับปี: รายการแม่บทแยก id ตามปี → แถวต้องชี้รายการของปีใหม่ด้วย ──
// เดิมเปลี่ยนแค่ year_id: หน้ากรอก/รายงาน/Dashboard (กรองปีของรายการ) เห็นยอด 0 แต่การ์ดปี/อันดับยังนับ
/** หา/สร้างรายการคู่ (หมวด+ชื่อ+หน่วย) ของทุกรายการการดำเนินงานปี $from ในปี $to (ยกเว้น $skip) → [from_id => to_id] — ใช้ใน transaction */
function pair_items(PDO $pdo, int $from, int $to, array $skip = []): array {
    $find = $pdo->prepare("SELECT MIN(id) FROM admin_item WHERE year_id = ? AND data_source = 'officer' AND scope = ? AND name_tiem = ? AND unit <=> ?");
    $ins  = $pdo->prepare("INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD, data_source) VALUES (?, ?, ?, ?, ?, 'officer')");
    $map = [];
    foreach ($pdo->query("SELECT id, scope, name_tiem, unit, AD FROM admin_item WHERE year_id = $from AND data_source = 'officer' ORDER BY id")->fetchAll() as $r) {
        if (in_array((int) $r['id'], $skip, true)) continue;
        $find->execute([$to, $r['scope'], $r['name_tiem'], $r['unit']]);
        $id = (int) $find->fetchColumn();
        if (!$id) { $ins->execute([$to, $r['scope'], $r['name_tiem'], $r['unit'], $r['AD']]); $id = (int) $pdo->lastInsertId(); }
        $map[(int) $r['id']] = $id;
    }
    return $map;
}
/** ยอดการดำเนินงานของคณะ/ปี จากทุกจุดที่แสดง: รายงาน (= หน้ากรอก/Dashboard) · การ์ดปี · การ์ดหมวด · อันดับ */
function op_views(PDO $pdo, int $aff, int $y): array {
    $card = current(array_filter(officer_year_cards($pdo, $aff), fn($c) => $c['id'] === $y));
    $rank = current(array_filter(ghg_by_affiliation($pdo, $y), fn($r) => (int) $r['affil_id'] === $aff));
    return ['report' => ghg_report_summary($pdo, $y, $aff)['operation_total'], 'card' => $card ? $card['operation_total'] : 0.0,
            'groups' => array_sum(array_column(officer_group_stats($pdo, $aff, $y), 'emission')), 'rank' => $rank ? (float) $rank['total_emission'] : 0.0];
}
$allEq = fn(array $v, float $x) => max(array_map(fn($a) => abs($a - $x), $v)) < 1e-6;
/** แถวการดำเนินงานของคณะที่ปีไม่ตรงกับปีของรายการแม่บท */
$mismatch = fn() => (int) $pdo->query("SELECT COUNT(*) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.affiliation_id = $aff AND ui.source = 'officer' AND ui.year_id <> ai.year_id")->fetchColumn();
/** สถานะแถวการดำเนินงานของคณะทั้งสองปี (id → ปี/รายการ/ปริมาณ) */
$snap = fn() => $pdo->query("SELECT id, year_id, admin_item_id, Vol FROM user_item WHERE affiliation_id = $aff AND source = 'officer' AND year_id IN ($year, $other) ORDER BY id")->fetchAll();

ck('E3 ย้ายปี: แถวเดิม (id เดิม → หลักฐานไม่หลุด) ชี้รายการคู่ของปีใหม่ ปริมาณเท่าเดิม · ยอดปีใหม่ = ยอดเดิม ตรงกันทั้ง รายงาน/การ์ดปี/การ์ดหมวด/อันดับ · กิจกรรม/แบบสอบถามไม่ขยับ',
    in_tx($pdo, function () use ($pdo, $aff, $year, $other, $before, $beforeOther, $allEq, $mismatch) {
    if ($beforeOther['officer'] > 0) officer_delete_year($pdo, $aff, $other);
    $map  = pair_items($pdo, $year, $other);
    $rows = $pdo->query("SELECT id, admin_item_id, Vol FROM user_item WHERE affiliation_id = $aff AND year_id = $year AND source = 'officer'")->fetchAll();
    $op   = op_views($pdo, $aff, $year)['report'];
    officer_move_year($pdo, $aff, $year, $other);
    $st = $pdo->prepare('SELECT year_id, admin_item_id, Vol FROM user_item WHERE id = ?');
    foreach ($rows as $r) {
        $st->execute([$r['id']]); $x = $st->fetch();
        if (!$x || (int) $x['year_id'] !== $other || (int) $x['admin_item_id'] !== $map[(int) $r['admin_item_id']] || (float) $x['Vol'] !== (float) $r['Vol']) return false;
    }
    $a = src_counts($pdo, $aff, $year); $b = src_counts($pdo, $aff, $other);
    return $a['officer'] === 0 && $a['event'] === $before['event'] && $a['survey'] === $before['survey']
        && $b['officer'] === (int) $before['officer'] && $b['event'] === $beforeOther['event'] && $b['survey'] === $beforeOther['survey']
        && $op > 0 && $allEq(op_views($pdo, $aff, $other), $op) && $mismatch() === 0;
}));

ck('E3b ย้ายไปปีที่ Admin ยังไม่กำหนดรายการ → ไม่ให้ย้าย (แจ้งเหตุผล) ข้อมูลไม่เปลี่ยน', in_tx($pdo, function () use ($pdo, $aff, $year, $snap) {
    $pdo->exec('INSERT INTO admin_year (year) VALUES (2699)');
    $empty = (int) $pdo->lastInsertId();
    $s = $snap();
    try { officer_move_year($pdo, $aff, $year, $empty); return false; }
    catch (Exception $e) { return str_contains($e->getMessage(), 'ยังไม่มีรายการ') && $snap() == $s && officer_year_move_check($pdo, $aff, $year, $empty) !== null; }
}));

ck('E3c รายการที่กรอกค่า หรือแนบหลักฐาน แต่ปีใหม่ไม่มีคู่ → ไม่ให้ย้าย (ข้อมูลไม่หายเงียบ) · ถ้าว่างจริง → ย้ายได้ และแถวว่างนั้นถูกลบ', in_tx($pdo, function () use ($pdo, $aff, $year, $other, $beforeOther, $snap) {
    if ($beforeOther['officer'] > 0) officer_delete_year($pdo, $aff, $other);
    $row = $pdo->query("SELECT ui.id, ui.admin_item_id, ai.name_tiem FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id
        WHERE ui.affiliation_id = $aff AND ui.year_id = $year AND ui.source = 'officer' AND ui.Vol > 0
          AND NOT EXISTS (SELECT 1 FROM evidence ev WHERE ev.entity_type = 'user_item' AND ev.entity_id = ui.id) ORDER BY ui.id LIMIT 1")->fetch();
    pair_items($pdo, $year, $other, [(int) $row['admin_item_id']]);
    $s = $snap();
    try { officer_move_year($pdo, $aff, $year, $other); return false; }                       // กรอกค่าไว้
    catch (Exception $e) { if (!str_contains($e->getMessage(), $row['name_tiem']) || $snap() != $s) return false; }
    $pdo->exec("UPDATE user_item SET Vol = 0 WHERE id = {$row['id']}");
    $pdo->exec("INSERT INTO evidence (entity_type, entity_id, kind, url, label) VALUES ('user_item', {$row['id']}, 'link', 'https://example.com', 't')");
    $s = $snap();
    try { officer_move_year($pdo, $aff, $year, $other); return false; }                       // ว่างแต่มีหลักฐาน
    catch (Exception $e) { if ($snap() != $s) return false; }
    $pdo->exec("DELETE FROM evidence WHERE entity_type = 'user_item' AND entity_id = {$row['id']} AND url = 'https://example.com'");
    $n = count($s);
    officer_move_year($pdo, $aff, $year, $other);                                                   // ว่างจริง → ย้ายได้
    return (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE id = {$row['id']}")->fetchColumn() === 0
        && src_counts($pdo, $aff, $other)['officer'] === $n - 1;
}));

ck('E4 สลับปี: ยอดสองปีสลับกันจริง ตรงกันทุกจุดที่แสดง · id แถวเดิม · ไม่มีแถวที่ปีไม่ตรงรายการ · กิจกรรม/แบบสอบถามไม่ขยับ',
    in_tx($pdo, function () use ($pdo, $aff, $year, $other, $before, $beforeOther, $allEq, $mismatch) {
    if ($beforeOther['officer'] > 0) officer_delete_year($pdo, $aff, $other);
    $map = pair_items($pdo, $year, $other);
    // ปีอื่นมีข้อมูล 2 รายการ (คนละค่ากับปีหลัก)
    $ins = $pdo->prepare("INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year, source) VALUES (?, ?, ?, ?, CURDATE(), 'officer')");
    $k = 0; foreach (array_slice($map, 0, 2, true) as $to) $ins->execute([$to, $aff, $other, 7 + $k++]);
    $idsYear  = $pdo->query("SELECT id FROM user_item WHERE affiliation_id = $aff AND year_id = $year AND source = 'officer' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $idsOther = $pdo->query("SELECT id FROM user_item WHERE affiliation_id = $aff AND year_id = $other AND source = 'officer' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $opY = op_views($pdo, $aff, $year)['report']; $opO = op_views($pdo, $aff, $other)['report'];
    officer_swap_years($pdo, $aff, $year, $other);
    $a = src_counts($pdo, $aff, $year); $b = src_counts($pdo, $aff, $other);
    return $opY > 0 && $opO > 0 && $allEq(op_views($pdo, $aff, $year), $opO) && $allEq(op_views($pdo, $aff, $other), $opY) && $mismatch() === 0
        && $pdo->query("SELECT id FROM user_item WHERE affiliation_id = $aff AND year_id = $other AND source = 'officer' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) == $idsYear
        && $pdo->query("SELECT id FROM user_item WHERE affiliation_id = $aff AND year_id = $year AND source = 'officer' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) == $idsOther
        && $a['event'] === $before['event'] && $a['survey'] === $before['survey'] && $b['event'] === $beforeOther['event'] && $b['survey'] === $beforeOther['survey'];
}));

ck('E4b สลับปีที่ทิศใดทิศหนึ่งมีรายการกรอกไว้แต่ไม่มีคู่ → ไม่ให้สลับ ข้อมูลทั้งสองปีไม่เปลี่ยนเลย (ตรวจก่อนแก้แถวใด ๆ)', in_tx($pdo, function () use ($pdo, $aff, $year, $other, $beforeOther, $snap) {
    if ($beforeOther['officer'] > 0) officer_delete_year($pdo, $aff, $other);
    pair_items($pdo, $year, $other);
    $pdo->prepare("INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD, data_source) VALUES (?, (SELECT MIN(id) FROM admin_g), 'รายการเฉพาะปีทดสอบ', 'หน่วย', 1, 'officer')")->execute([$other]);
    $pdo->prepare("INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year, source) VALUES (?, ?, ?, 5, CURDATE(), 'officer')")->execute([(int) $pdo->lastInsertId(), $aff, $other]);
    $s = $snap();
    try { officer_swap_years($pdo, $aff, $year, $other); return false; }
    catch (Exception $e) { return str_contains($e->getMessage(), 'รายการเฉพาะปีทดสอบ') && $snap() == $s; }
}));

ck('E5 คัดลอกปี: จับคู่ด้วย หมวด+ชื่อ+หน่วย (id ต่างปี) ได้ปริมาณเดิม และไม่แตะกิจกรรม/แบบสอบถาม', in_tx($pdo, function () use ($pdo, $aff, $year, $other, $beforeOther) {
    // ปีต้นทาง ($other) : ข้อมูลการดำเนินงานเดิมของคณะออกก่อน (นับผลคัดลอกได้ตรง 2)
    if ($beforeOther['officer'] > 0) officer_delete_year($pdo, $aff, $other);
    // รายการแม่บท 2 รายการของปี $year ในปี $other (id ใหม่) — มีอยู่แล้ว (คัดลอกรายการมาแล้ว) → ใช้ของเดิม, ไม่มี → สร้าง + ปริมาณ
    $src = $pdo->query("SELECT id, scope, name_tiem, unit, AD FROM admin_item WHERE year_id = $year AND data_source = 'officer' ORDER BY id LIMIT 2")->fetchAll();
    $findItem = $pdo->prepare("SELECT id FROM admin_item WHERE year_id = ? AND data_source = 'officer' AND scope = ? AND name_tiem = ? AND unit <=> ? LIMIT 1");
    $insItem = $pdo->prepare("INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD, data_source) VALUES (?, ?, ?, ?, ?, 'officer')");
    $insUi   = $pdo->prepare("INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, source) VALUES (?, ?, ?, ?, 'officer')");
    $expect = [];
    foreach ($src as $k => $r) {
        $findItem->execute([$other, $r['scope'], $r['name_tiem'], $r['unit']]);
        $newId = (int) $findItem->fetchColumn();
        if (!$newId) { $insItem->execute([$other, $r['scope'], $r['name_tiem'], $r['unit'], $r['AD']]); $newId = (int) $pdo->lastInsertId(); }
        $insUi->execute([$newId, $aff, $other, 111 + $k]);
        $expect[(int) $r['id']] = 111.0 + $k;
    }
    $n = officer_copy_year($pdo, $aff, $other, $year);
    $st = $pdo->prepare("SELECT Vol FROM user_item WHERE admin_item_id = ? AND affiliation_id = ? AND year_id = ? AND source = 'officer'");
    foreach ($expect as $id => $v) { $st->execute([$id, $aff, $year]); if ((float) $st->fetchColumn() !== $v) return false; }
    $c = src_counts($pdo, $aff, $other);
    return $n === 2 && $c['event'] === $beforeOther['event'] && $c['survey'] === $beforeOther['survey'];
}));
ck('E5b หลังเทสต์ทั้งหมด ข้อมูลจริงเท่าเดิม (rollback ครบ)', src_counts($pdo, $aff, $year) == $before && src_counts($pdo, $aff, $other) == $beforeOther);

// ── E6–E7 ข้อมูลที่แสดง ──
$cards = officer_year_cards($pdo, $aff);
$card  = current(array_filter($cards, fn($c) => $c['id'] === $year));
$opTotal = (float) $pdo->query("SELECT COALESCE(SUM(ui.Vol*ai.AD)/1000,0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.affiliation_id = $aff AND ui.year_id = $year AND ui.source = 'officer'")->fetchColumn();
$filled  = (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE affiliation_id = $aff AND year_id = $year AND source = 'officer' AND Vol > 0")->fetchColumn();
$total   = (int) $pdo->query("SELECT COUNT(*) FROM admin_item WHERE year_id = $year AND data_source = 'officer'")->fetchColumn();
ck('E6 การ์ดปี: ยอด = เฉพาะการดำเนินงาน, กรอกแล้ว/ทั้งหมด ตรงกับฐานข้อมูล',
    $card && abs($card['operation_total'] - $opTotal) < 1e-9 && $card['filled'] === $filled && $card['total'] === $total,
    json_encode($card) . " op=$opTotal filled=$filled total=$total");
$eventOnly = $pdo->query("SELECT DISTINCT year_id FROM user_item x WHERE affiliation_id = $aff AND source <> 'officer'
    AND NOT EXISTS (SELECT 1 FROM user_item o WHERE o.affiliation_id = $aff AND o.year_id = x.year_id AND o.source = 'officer')")->fetchAll(PDO::FETCH_COLUMN);
ck('E6b ปีที่มีแต่กิจกรรม/แบบสอบถาม: ไม่ขึ้นเป็นการ์ด แต่ขึ้นในรายการปีที่สร้างได้', (function () use ($pdo, $aff, $cards, $eventOnly) {
    $avail = array_column(officer_available_years($pdo, $aff), 'id');
    foreach ($eventOnly as $y) if (in_array((int) $y, array_column($cards, 'id'), true) || !in_array((int) $y, $avail, true)) return false;
    return true;
})(), 'event-only years=' . json_encode($eventOnly));

$stats = officer_group_stats($pdo, $aff, $year);
ck('E7 สถิติรายหมวด: รวมจำนวนรายการ = รายการแม่บทของปี, รวมกรอกแล้ว = ที่กรอก, ยอดรวม = ยอดการดำเนินงาน',
    array_sum(array_column($stats, 'items')) === $total && array_sum(array_column($stats, 'filled')) === $filled
    && abs(array_sum(array_column($stats, 'emission')) - $opTotal) < 1e-9);

// ── หน้าเว็บ (render ผ่าน CLI) ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/officer_entry_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
$page = $argv[1]; parse_str(base64_decode($argv[3] ?? ""), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/officer/" . $page; $_SERVER["REQUEST_URI"] = "/officer/" . $page;
$_SERVER["REQUEST_METHOD"] = ($argv[4] ?? "") === "post" ? "POST" : "GET";
if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST = $q; else $_GET = $q;
session_start();
$_SESSION = ["user_id"=>1,"role"=>"officer","affiliation_id"=>(int)$argv[2],"affiliation_name"=>"หน่วยงานทดสอบ","firstname"=>"ท","lastname"=>"ส","username"=>"t","last_activity"=>time()];
// CSRF: session มี token และ POST ส่ง token ให้อัตโนมัติ (เทสต์ที่ส่ง csrf_token มาเองไม่ถูกทับ)
$_SESSION["csrf_token"] = "probe-token"; if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST += ["csrf_token" => "probe-token"];
ob_start(); require $root . "/officer/" . $page; echo ob_get_clean();
');
$render = fn(string $page, string $query = '', string $method = 'get') => (string) shell_exec(sprintf('%s %s %s %d %s %s 2>&1',
    escapeshellarg($php), escapeshellarg($probe), escapeshellarg($page), $aff, escapeshellarg(base64_encode($query)), $method));
// query ส่งเป็น base64: escapeshellarg บน Windows แปลง % เป็นช่องว่าง (ค่าที่ urlencode แล้วเพี้ยน)
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $h);

$h1 = $render('items.php');
ck('P1 ① items.php เรนเดอร์ได้ มีแถบขั้นตอน (ขั้น 1) และโหลด officer-entry.css', $noErr($h1)
    && strpos($h1, 'oe-step is-current" aria-current="step"><span class="oe-step-no">1</span>') !== false
    && strpos($h1, 'assets/css/officer-entry.css') !== false, substr($h1, 0, 300));
ck('P1b การ์ดปีแสดงยอดการดำเนินงาน + "กรอกแล้ว a / b" + ลิงก์ไปขั้น 2', (function () use ($h1, $card, $year) {
    return preg_match('#data-year-id="' . $year . '".*?oe-year-total">' . preg_quote(number_format($card['operation_total'], 2), '#') . ' <small>#s', $h1)
        && strpos($h1, 'oe-year-fill">' . $card['filled'] . ' / ' . $card['total'] . ' รายการ</b>') !== false
        && strpos($h1, 'href="data_entry.php?year=' . $year . '" class="oe-btn oe-btn-amber"') !== false;
})());
ck('P1c ปุ่มคัดลอก/หน้าต่างคัดลอกแสดงเมื่อมีมากกว่า 1 ปี · ปุ่มบนการ์ดส่ง data-id/data-year ครบทุกปี (ไม่มี onclick ต่อสตริง) · ลบใช้ confirmDelete ของ component กลาง', (function () use ($h1, $cards) {
    $multi = count($cards) > 1;
    foreach ($cards as $c) {
        $y = htmlspecialchars((string) $c['year'], ENT_QUOTES);
        if (strpos($h1, 'data-oe-year="edit" data-id="' . $c['id'] . '" data-year="' . $y . '"') === false) return false;
        if ($multi !== (strpos($h1, 'data-oe-year="copy" data-id="' . $c['id'] . '" data-year="' . $y . '"') !== false)) return false;
        if (strpos($h1, 'data-oe-year="delete" data-id="' . $c['id'] . '"') === false || strpos($h1, 'id="oeDelYear' . $c['id'] . '" hidden>') === false) return false;
    }
    return $multi === (strpos($h1, 'id="modalCopyYear"') !== false)
        // onclick ที่เหลือมีเฉพาะของ component กลาง (เมนูข้าง / dropdown)
        && !preg_match('/onclick="(?!ddToggle|ddSelect|toggleSidebar|closeSidebar)/', $h1) && strpos($h1, 'id="cfmModal"') !== false
        && strpos($h1, "confirmDelete({ title: 'ลบข้อมูลปีนี้?'") !== false && strpos($h1, 'modalConfirmDeleteYear') === false;
})());
ck('P1g หน้าต่างเปลี่ยนปี: ให้เลือกเฉพาะปีที่ Admin กำหนดรายการแล้ว · edit_user_year ทำใน transaction และแจ้งเหตุผลเมื่อย้ายไม่ได้', (function () use ($h1, $pdo) {
    $box = substr($h1, (int) strpos($h1, 'id="modalEditYear"'));
    $box = substr($box, 0, (int) strpos($box, '</form>'));
    preg_match_all('/data-value="(\d+)"/', $box, $m);
    $ready = array_map('intval', $pdo->query("SELECT y.id FROM admin_year y WHERE EXISTS (SELECT 1 FROM admin_item ai WHERE ai.year_id = y.id AND ai.data_source = 'officer') ORDER BY y.year DESC")->fetchAll(PDO::FETCH_COLUMN));
    $src = (string) file_get_contents(__DIR__ . '/../officer/items.php');
    $edit = substr($src, (int) strpos($src, "if (\$action === 'edit_user_year')"));
    $edit = substr($edit, 0, (int) strpos($edit, "if (\$action === 'swap_user_years')"));
    return array_map('intval', $m[1]) === $ready
        && preg_match('/beginTransaction\(\);\s*officer_move_year\(.*?\);\s*\$pdo->commit\(\);/s', $edit)
        // ข้อความ error ผ่าน safe_error_message: เหตุผลที่ย้ายไม่ได้ยังแสดงเหมือนเดิม แต่ error ฐานข้อมูลไม่หลุด SQL (tests/error_message_test.php)
        && preg_match('/catch \(Exception \$e\) \{\s*if \(\$pdo->inTransaction\(\)\) \$pdo->rollBack\(\);\s*\$back\(\'msg=\' \. urlencode\(safe_error_message\(\$e\)\) \. \'&msg_type=danger\'\);/', $edit);
})(), 'ready years must equal dropdown options');
ck('P1dไม่มี onmouseover/onmouseout (hover อยู่ใน CSS) และข้อความลบบอกว่าไม่ลบกิจกรรม/แบบสอบถาม',
    stripos($h1, 'onmouseover') === false && strpos($h1, 'ข้อมูลกิจกรรมและแบบสอบถามของปีนี้ไม่ถูกลบ') !== false);
ck('P1e หน้าต่างชุดเดียวกับ admin: co-modal (โหลด collect.css) · ปีเลือกผ่าน dropdown component (ไม่มี <select>) · ตรวจค่าว่างด้วยข้อความในหน้าต่าง · ไม่มี inline style ในหน้าต่าง · สคริปต์ไม่มี let/const ระดับบนสุด', (function () use ($h1) {
    // ช่วงหน้าต่างทั้งหมด: ตั้งแต่หน้าต่างแรกถึงสคริปต์ของหน้า (dropdown component มี <script> ของตัวเองคั่นอยู่)
    $from = (int) strpos($h1, '<!-- Modal: สร้างปีงบประมาณ -->');
    $modals = substr($h1, $from, (int) strpos($h1, '// IIFE', $from) - $from);
    preg_match_all('#<div class="modal-overlay" id="([^"]+)"#', $modals, $ids);
    preg_match_all('#<div class="modal-box co-modal#', $modals, $boxes);
    // style ที่เหลือมาจาก component กลางเท่านั้น (ความกว้าง dropdown / สีป้าย dropdown / ไอคอน ic())
    $styles = preg_match_all('#style="(?!width:100%;|color:\#9CA3AF;|vertical-align:-\.15em;)#', $modals);
    preg_match('#<script>\s*// IIFE(.*?)</script>#s', $h1, $js);
    return strpos($h1, 'assets/css/collect.css') !== false && count($ids[1]) >= 2 && count($ids[1]) === count($boxes[0])
        && strpos($modals, '<select') === false && strpos($modals, 'id="createYearSelect"') !== false && strpos($modals, 'id="editYearSelect"') !== false
        && substr_count($modals, 'class="oe-msg co-modal-msg"') >= 2 && $styles === 0
        && isset($js[1]) && !preg_match('/^\s{0,12}(const|let)\s/m', $js[1]) && strpos($js[1], "'กรุณาเลือกปีงบประมาณ'") !== false
        && strpos($h1, 'style="background-color: transparent;"') === false;
})());

$h2 = $render('data_entry.php', "year=$year");
ck('P2 ② data_entry.php เรนเดอร์ได้ แถบขั้นตอนขั้น 2 (ขั้น 1 เป็นลิงก์)', $noErr($h2)
    && strpos($h2, '<a class="oe-step is-done" href="items.php">') !== false && strpos($h2, 'aria-current="step"><span class="oe-step-no">2</span>') !== false, substr($h2, 0, 300));
ck('P2b การ์ดหมวดเป็น <label> ครอบ checkbox (คลิกครั้งเดียวติ๊ก) ไม่มี onclick ของแถว', (function () use ($h2, $stats) {
    $n = preg_match_all('#<label class="oe-group[^"]*" data-scope="\d"[^>]*>\s*<input type="checkbox" name="scope_groups\[\]"#', $h2);
    return $n === count($stats) && strpos($h2, "cb.checked = !cb.checked") === false;
})());
ck('P2c หมวดที่ไม่มีรายการในปีนี้ถูก disable, หมวดที่มีแสดง "กรอกแล้ว a / b"', (function () use ($h2, $stats) {
    foreach ($stats as $gid => $s) {
        $re = '#<input type="checkbox" name="scope_groups\[\]" value="' . $gid . '"( disabled)?>#';
        if (!preg_match($re, $h2, $m)) return false;
        if (($s['items'] === 0) !== !empty($m[1])) return false;
        if ($s['items'] > 0 && strpos($h2, 'กรอกแล้ว ' . $s['filled'] . ' / ' . $s['items'] . ' รายการ') === false) return false;
    }
    return true;
})());
ck('P2d ไม่เลือกหมวด → ข้อความเตือนในหน้า (ไม่ใช้ alert) + สคริปต์อยู่ใน <main>',
    strpos($h2, 'alert(') === false && strpos($h2, 'กรุณาเลือกอย่างน้อย 1 หมวด') !== false
    && strpos(substr($h2, 0, strpos($h2, '</main>')), 'window.oePickScope') !== false);

$gids = array_keys(array_filter($stats, fn($s) => $s['items'] > 0));
$qs   = "year=$year&" . implode('&', array_map(fn($g) => "scope_groups[]=$g", $gids));
$h3   = $render('data_entry_items.php', $qs);
ck('P3 ③ data_entry_items.php เรนเดอร์ได้ แถบขั้นตอนขั้น 3 + โหลด data-entry.js', $noErr($h3)
    && strpos($h3, 'aria-current="step"><span class="oe-step-no">3</span>') !== false && strpos($h3, 'assets/js/data-entry.js') !== false
    && strpos($h3, "deInit(document.getElementById('main-save-form'))") !== false, substr($h3, 0, 300));
ck('P3h EF ในหน้ากรอก (data-ef / data-ad) ไม่มีศูนย์ต่อท้ายจากคอลัมน์ decimal เช่น "2.707600"', !preg_match('/data-(?:ef|ad)="\d+\.\d*0"/', $h3)
    && preg_match('/data-ef="\d/', $h3), (preg_match('/data-(?:ef|ad)="\d+\.\d*0"/', $h3, $mz) ? $mz[0] : ''));
ck('P3b ช่องกรอกครบทุกรายการ มี data-group/data-ef และยอดรวมเริ่มต้น = ยอดการดำเนินงาน', (function () use ($h3, $total, $filled, $opTotal) {
    return preg_match_all('#<input type="text" inputmode="decimal" autocomplete="off" name="vol\[\d+\]" class="vol-input oe-input"\s+data-group="\d+" data-ef="[0-9.eE+-]+"#', $h3) === $total
        && strpos($h3, '<span data-de-filled>' . $filled . ' / ' . $total . '</span>') !== false
        && strpos($h3, '<span data-de-total>' . ($opTotal == 0 ? '-' : number_format($opTotal, 2)) . '</span>') !== false;
})());
ck('P3c ไม่เหลือฟอร์ม/โค้ดแก้ Emission Factor ของ admin และปุ่ม "ลบกิจกรรม" เปลี่ยนเป็น "ซ่อนหมวดนี้"',
    strpos($h3, 'modal-edit-item') === false && strpos($h3, 'modal-custom-item') === false && strpos($h3, 'openEditModal') === false
    && strpos($h3, '>ลบกิจกรรม<') === false && strpos($h3, '>ซ่อนหมวดนี้</button>') !== false
    && strpos($h3, 'Show <select') === false);
ck('P3d แถบสรุปติดจอ: สถานะยังไม่บันทึก (ซ่อนไว้ก่อน) + ปุ่มบันทึกผ่าน deSave() + หน้าต่างอยู่ใน <main>',
    strpos($h3, 'data-de-dirty hidden>') !== false && strpos($h3, 'onclick="deSave()"') !== false
    && strpos(substr($h3, 0, strpos($h3, '</main>')), 'id="modal-add-new-scope"') !== false);
ck('P3f หน้าต่างของ ③: เลือกหมวด / บันทึกสำเร็จเป็น co-modal ไม่มี inline style/onclick · ซ่อนหมวดใช้ confirmDelete (ไม่มีหน้าต่างทำเอง) · รายการหมวดสูงตามจอ', (function () use ($h3) {
    $pick = substr($h3, (int) strpos($h3, '<!-- Modal: เพิ่ม / เปลี่ยนหมวด -->'));
    $pick = substr($pick, 0, (int) strpos($pick, '<script'));
    $css  = (string) file_get_contents(__DIR__ . '/../assets/css/officer-entry.css');
    return strpos($pick, '<div class="modal-box co-modal oe-scope-modal">') !== false && strpos($pick, 'onclick=') === false
        && !preg_match('#style="(?!--sc|vertical-align:-\.15em;)#', $pick) && strpos($h3, 'assets/css/collect.css') !== false
        && strpos($h3, 'data-open="modal-add-new-scope"') !== false && substr_count($h3, 'class="oe-hide-btn" data-hide-group="') >= 1
        && strpos($h3, 'modal-confirm-delete-section') === false && strpos($h3, "confirmText: 'ซ่อนหมวด', icon: ICON_HIDE") !== false && strpos($h3, 'id="cfmModal"') !== false
        && preg_match('/\.oe-modal-groups \{ max-height: max\(160px, calc\(100dvh - 360px\)\);/', $css)
        && preg_match('/\.oe-done-ic \{[^}]*animation: oeRise/', $css) && preg_match('/prefers-reduced-motion: reduce\) \{[^}]*\.oe-done-ic/', $css);
})());
ck('P3g บันทึกสำเร็จ (หลัง redirect): หน้าต่าง co-modal เปิดเองด้วย data-open-on-load + ปุ่มปิด data-close', (function () use ($render, $qs) {
    $h = $render('data_entry_items.php', $qs . '&msg=' . urlencode('บันทึกข้อมูลสำเร็จ'));
    return (bool) preg_match('#<div class="modal-overlay" id="modal-save-success" data-open-on-load>\s*<div class="modal-box co-modal is-center">\s*<div class="oe-done-ic">#', $h)
        && strpos($h, '<button type="button" class="oe-btn oe-btn-success" data-close>ตกลง, กรอกต่อ</button>') !== false
        && strpos($h, "if (el.hasAttribute('data-open-on-load')) openModal(el.id);") !== false;
})());
ck('P3e query ของหมวดใช้ scope_groups[]= (ไม่ใช่ scope_groups[0]=)',
    strpos($h3, 'scope_groups[]=' . $gids[0]) !== false && strpos($h3, 'scope_groups%5B0%5D') === false);

// P5 — บันทึกจริงผ่านหน้าเว็บ: หน้าเปิด/commit transaction เอง จึงครอบ rollback จากภายนอกไม่ได้
//      → ใช้แถวที่มีอยู่แล้วเท่านั้น (ไม่สร้างแถวใหม่) จำค่าเดิมไว้ แล้วคืนค่าใน finally เสมอ
$rows = $pdo->query("SELECT ui.id, ui.admin_item_id, ui.Vol, ui.create_year FROM user_item ui
    WHERE ui.affiliation_id = $aff AND ui.year_id = $year AND ui.source = 'officer' AND ui.Vol > 0 ORDER BY ui.id LIMIT 2")->fetchAll();
$foreign = (int) $pdo->query("SELECT id FROM admin_item WHERE data_source <> 'officer' OR year_id <> $year ORDER BY id LIMIT 1")->fetchColumn();
$foreignBefore = (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE admin_item_id = $foreign AND affiliation_id = $aff AND year_id = $year AND source = 'officer'")->fetchColumn();
try {
    $newVol = (float) $rows[0]['Vol'] + 1234;
    $post = "action=save&year=$year&scope_groups[]={$gids[0]}"
        . '&vol[' . $rows[0]['admin_item_id'] . ']=' . urlencode(number_format($newVol, 2, '.', ','))   // มีคั่นหลักพัน
        . '&vol[' . $rows[1]['admin_item_id'] . ']='                                                 // ว่าง → 0
        . '&vol[' . $foreign . ']=55';                                                               // รายการที่ไม่ใช่การดำเนินงานของปีนี้ → ข้าม
    $out = $render('data_entry_items.php', $post, 'post');
    $vol = $pdo->prepare('SELECT Vol FROM user_item WHERE id = ?');
    $vol->execute([$rows[0]['id']]); $v0 = (float) $vol->fetchColumn();
    $vol->execute([$rows[1]['id']]); $v1 = (float) $vol->fetchColumn();
    $foreignAfter = (int) $pdo->query("SELECT COUNT(*) FROM user_item WHERE admin_item_id = $foreign AND affiliation_id = $aff AND year_id = $year AND source = 'officer'")->fetchColumn();
    ck('P5 บันทึก: รับตัวเลขคั่นหลักพัน, ช่องว่างเป็น 0, ข้ามรายการที่ไม่ใช่การดำเนินงานของปีนี้',
        !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $out) && abs($v0 - $newVol) < 1e-6 && $v1 === 0.0 && $foreignAfter === $foreignBefore,
        "v0=$v0 expect=$newVol v1=$v1 foreign={$foreignBefore}->{$foreignAfter} out=" . substr($out, 0, 200));
} finally {
    $restore = $pdo->prepare('UPDATE user_item SET Vol = ?, create_year = ? WHERE id = ?');
    foreach ($rows as $r) $restore->execute([$r['Vol'], $r['create_year'], $r['id']]);
}
ck('P5b คืนค่าเดิมหลังทดสอบบันทึกครบ', src_counts($pdo, $aff, $year) == $before && (function () use ($pdo, $rows) {
    $st = $pdo->prepare('SELECT Vol, create_year FROM user_item WHERE id = ?');
    foreach ($rows as $r) { $st->execute([$r['id']]); $x = $st->fetch(); if ((float) $x['Vol'] !== (float) $r['Vol'] || $x['create_year'] !== $r['create_year']) return false; }
    return true;
})());

// P5c — ส่ง POST ตรงที่มีช่องติดลบ: ไม่บันทึกทั้งฟอร์ม (ช่องปกติก็ไม่ถูกบันทึก) และไม่มี error ดิบ (catch Exception ไม่ใช่แค่ PDOException)
try {
    $out = $render('data_entry_items.php', "action=save&year=$year&scope_groups[]={$gids[0]}"
        . '&vol[' . $rows[0]['admin_item_id'] . ']=4321&vol[' . $rows[1]['admin_item_id'] . ']=-9', 'post');
    $st = $pdo->prepare('SELECT Vol FROM user_item WHERE id = ?');
    $same = true;
    foreach ($rows as $r) { $st->execute([$r['id']]); if ((float) $st->fetchColumn() !== (float) $r['Vol']) $same = false; }
    ck('P5c POST ตรงมีช่องติดลบ → ไม่บันทึกทั้งฟอร์ม ไม่มี error ดิบ', $same && !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $out), substr($out, 0, 300));
} finally {
    $restore = $pdo->prepare('UPDATE user_item SET Vol = ?, create_year = ? WHERE id = ?');
    foreach ($rows as $r) $restore->execute([$r['Vol'], $r['create_year'], $r['id']]);
}

$h403 = $render('data_entry_items.php', "action=delete_item&item_id=1&year=$year&scope_groups[]={$gids[0]}", 'post');
ck('P4 POST แก้ Emission Factor (delete_item) ยังถูกบล็อก 403', stripos($h403, '403') !== false && src_counts($pdo, $aff, $year) == $before);

ck('P6 ไม่เหลือหน้ากรอกรุ่นเก่า officer/submit.php (บันทึกได้โดยไม่ผ่านการตรวจชุดใหม่) และ user-new.css ที่ใช้เฉพาะหน้านั้น',
    !file_exists(__DIR__ . '/../officer/submit.php') && !file_exists(__DIR__ . '/../assets/css/user-new.css')
    && strpos((string) file_get_contents(__DIR__ . '/../officer/includes/sidebar.php'), 'submit.php') === false);

// ── CSS แอนิเมชัน ──
$css = (string) file_get_contents(__DIR__ . '/../assets/css/officer-entry.css');
ck('C1 แอนิเมชันเข้าหน้าใช้ fill backwards (ไม่ค้าง transform ทับ :hover) + แถบยืด + accordion เปิดนุ่ม',
    strpos($css, '.oe-rise { animation: oeRise .5s cubic-bezier(.22,1,.36,1) backwards;') !== false
    && strpos($css, '@keyframes oeGrow { from { transform: scaleX(0); }') !== false
    && strpos($css, '.accordion-section.active .oe-sec-body { display: block; animation: oeOpen') !== false);
ck('C2 ปุ่มลอย/ลูกศรเลื่อน/ยุบตอนกด และปิดแอนิเมชันเมื่อ prefers-reduced-motion',
    strpos($css, '.oe-btn:hover .oe-arrow, .oe-btn:focus-visible .oe-arrow { transform: translateX(4px); }') !== false
    && strpos($css, '.oe-btn:active { transform: translateY(0) scale(.97); }') !== false
    && (bool) preg_match('#prefers-reduced-motion: reduce\) \{\s*\.oe-rise, \.oe-bar#', $css));

@unlink($probe);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
