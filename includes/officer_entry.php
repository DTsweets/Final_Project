<?php
/**
 * ข้อมูลของหน้ากรอกข้อมูลเจ้าหน้าที่ (officer/items.php → data_entry.php → data_entry_items.php)
 * ------------------------------------------------------------------------------------------
 * ทุกฟังก์ชันแตะเฉพาะ user_item.source = 'officer' (ข้อมูลการดำเนินงานที่เจ้าหน้าที่กรอก)
 * แถวของกิจกรรม (event) และแบบสอบถาม (survey) อยู่ในตารางเดียวกัน แต่ผูกกับตาราง event / ชุดแบบสอบถาม
 * ของปีนั้น — ห้ามลบ/ย้ายตามปีของหน้านี้ (เดิมลบ/ย้ายไปด้วย ทำให้ข้อมูลกิจกรรมหาย/ยอดสองปีผิด)
 */

const OFFICER_VOL_MAX = 1000000.0;   // ปริมาณสูงสุดต่อรายการ (ตรงกับ max ของช่องกรอก)

/** แปลงค่าที่กรอก → ปริมาณ: ว่าง/ไม่ใช่ตัวเลข/ติดลบ = 0, เกินเพดาน = เพดาน, คั่นหลักพันได้ (ตรวจซ้ำฝั่งเซิร์ฟเวอร์ กัน POST ตรง) */
function officer_clean_vol($raw): float
{
    $raw = str_replace(',', '', trim((string) $raw));
    if (!is_numeric($raw)) return 0.0;
    $v = (float) $raw;
    if (!is_finite($v) || $v < 0) return 0.0;
    return min($v, OFFICER_VOL_MAX);
}

/**
 * ตรวจค่าที่กรอก (กฎเดียวกับ parseVol ใน assets/js/data-entry.js) — ว่าง = ยังไม่กรอก ไม่ผิด
 * officer_clean_vol แปลงค่าผิดเป็น 0 / ตัดเหลือเพดาน เงียบ ๆ → ทุกคำสั่งบันทึกต้องตรวจด้วยฟังก์ชันนี้ก่อน (กัน POST ตรง / JS ไม่ทำงาน)
 * @return string|null 'ไม่ใช่ตัวเลข' | 'ติดลบ' | 'เกิน 1,000,000' | null = ใช้ได้
 */
function officer_vol_error($raw): ?string
{
    $raw = str_replace(',', '', trim((string) $raw));
    if ($raw === '') return null;
    if (!is_numeric($raw)) return 'ไม่ใช่ตัวเลข';
    $v = (float) $raw;
    if ($v < 0) return 'ติดลบ';
    if (!is_finite($v) || $v > OFFICER_VOL_MAX) return 'เกิน 1,000,000';
    return null;
}

/** ทศนิยมสูงสุดของ EF / ค่าดูดกลับ — คอลัมน์ decimal(13,6) (admin_item.AD, removal_item.factor, removal_event_item.factor) */
const OFFICER_EF_DECIMALS = 6;

/**
 * ตรวจค่า EF / ค่าดูดกลับ: กฎเดียวกับ officer_vol_error + ทศนิยมไม่เกิน 6 ตำแหน่ง
 * (คอลัมน์ decimal(13,6) ปัดทศนิยมที่เกินทิ้งเงียบ ๆ · ศูนย์ท้ายไม่นับ เช่น 2.7076000)
 * @return string|null ข้อความจาก officer_vol_error | 'ทศนิยมเกิน 6 ตำแหน่ง' | null = ใช้ได้
 */
function officer_ef_error($raw): ?string
{
    if ($e = officer_vol_error($raw)) return $e;
    $s = str_replace(',', '', trim((string) $raw));
    if ($s === '') return null;
    if (preg_match('/^[+-]?\d*\.(\d+)$/', $s, $m)) {
        if (strlen(rtrim($m[1], '0')) > OFFICER_EF_DECIMALS) return 'ทศนิยมเกิน 6 ตำแหน่ง';
    } elseif (round((float) $s, OFFICER_EF_DECIMALS) != (float) $s) {   // รูปแบบ 1e-7
        return 'ทศนิยมเกิน 6 ตำแหน่ง';
    }
    return null;
}

/**
 * EF / ค่าดูดกลับ สำหรับใส่ใน data-* และช่องแก้ไข — ตัดศูนย์ท้ายโดยไม่ปัดเศษ ("2.707600" → "2.7076", "5.000000" → "5")
 * คอลัมน์ decimal ถูกอ่านออกมาเป็น string ที่มีศูนย์ครบ 6 ตำแหน่ง
 */
function ef_input_val($v): string
{
    if ($v === null || $v === '') return '';
    $s = is_string($v) ? trim($v) : number_format((float) $v, OFFICER_EF_DECIMALS, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** EF / ค่าดูดกลับ ในตาราง: ทศนิยม 4 ตำแหน่งคงที่เหมือนเดิม · ค่าที่ละเอียดกว่านั้นแสดงครบ (ไม่เกิน 6) ไม่ปัดจนดูเหมือนค่าอื่น */
function ef_fmt($v): string
{
    $frac = explode('.', ef_input_val($v) ?: '0')[1] ?? '';
    return number_format((float) $v, max(4, min(OFFICER_EF_DECIMALS, strlen($frac))));
}

/**
 * ตรวจทุกค่าก่อนบันทึก — มีช่องผิดแม้ช่องเดียว → Exception (ไม่บันทึกทั้งฟอร์ม) บอกเหตุผลและจำนวนช่อง
 * @param array  $raws ค่าที่กรอก
 * @param string $what ชื่อช่องในข้อความ เช่น 'ปริมาณ', 'ค่า EF'
 * @param bool   $ef   true = ตรวจแบบ EF / ค่าดูดกลับ (officer_ef_error: ทศนิยมไม่เกิน 6 ตำแหน่งด้วย)
 */
function officer_require_valid_vols(array $raws, string $what = 'ปริมาณ', bool $ef = false): void
{
    $why = [];
    foreach ($raws as $raw) if ($e = $ef ? officer_ef_error($raw) : officer_vol_error($raw)) $why[$e] = ($why[$e] ?? 0) + 1;
    if (!$why) return;
    $parts = [];
    foreach ($why as $e => $n) $parts[] = "$e $n";
    throw new Exception("$what กรอกไม่ถูกต้อง " . array_sum($why) . ' ช่อง (' . implode(', ', $parts) . ') จึงยังไม่บันทึก — ต้องเป็นตัวเลข ไม่ติดลบ และไม่เกิน 1,000,000');
}

/**
 * การ์ดปีของหน้าเลือกปี: เฉพาะปีที่คณะมีข้อมูลการดำเนินงาน
 * @return array [['id','year','operation_total','filled','total'], ...] ปีใหม่ → เก่า
 */
function officer_year_cards(PDO $pdo, int $affil): array
{
    $stmt = $pdo->prepare("
        SELECT y.id, y.year,
               (SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id
                 WHERE ui.year_id = y.id AND ui.affiliation_id = :a1 AND ui.source = 'officer') AS operation_total,
               (SELECT COUNT(*) FROM admin_item ai WHERE ai.year_id = y.id AND ai.data_source = 'officer') AS total,
               (SELECT COUNT(*) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = y.id AND ai.data_source = 'officer'
                 WHERE ui.year_id = y.id AND ui.affiliation_id = :a2 AND ui.source = 'officer' AND ui.Vol > 0) AS filled
        FROM admin_year y
        WHERE EXISTS (SELECT 1 FROM user_item u WHERE u.year_id = y.id AND u.affiliation_id = :a3 AND u.source = 'officer')
        ORDER BY y.year DESC");
    $stmt->execute([':a1' => $affil, ':a2' => $affil, ':a3' => $affil]);
    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'year' => $r['year'], 'operation_total' => (float) $r['operation_total'],
        'filled' => (int) $r['filled'], 'total' => (int) $r['total'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * ปีที่สร้างได้: ยังไม่มีข้อมูลการดำเนินงานของคณะ (ปีที่มีแต่กิจกรรม/แบบสอบถามก็สร้างได้)
 * @return array [['id','year','items'], ...] items = จำนวนรายการที่ admin กำหนด (0 = ยังสร้างไม่ได้)
 */
function officer_available_years(PDO $pdo, int $affil): array
{
    $stmt = $pdo->prepare("
        SELECT y.id, y.year, (SELECT COUNT(*) FROM admin_item ai WHERE ai.year_id = y.id AND ai.data_source = 'officer') AS items
        FROM admin_year y
        WHERE NOT EXISTS (SELECT 1 FROM user_item u WHERE u.year_id = y.id AND u.affiliation_id = :a AND u.source = 'officer')
        ORDER BY y.year DESC");
    $stmt->execute([':a' => $affil]);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'year' => $r['year'], 'items' => (int) $r['items']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** คณะมีข้อมูลการดำเนินงานในปีนี้หรือยัง */
function officer_has_year(PDO $pdo, int $affil, int $year): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM user_item WHERE affiliation_id = ? AND year_id = ? AND source = 'officer' LIMIT 1");
    $stmt->execute([$affil, $year]);
    return (bool) $stmt->fetchColumn();
}

/** ลบข้อมูลการดำเนินงานของปี (ไม่แตะกิจกรรม/แบบสอบถาม) — คืนจำนวนแถวที่ลบ */
function officer_delete_year(PDO $pdo, int $affil, int $year): int
{
    $stmt = $pdo->prepare("DELETE FROM user_item WHERE affiliation_id = ? AND year_id = ? AND source = 'officer'");
    $stmt->execute([$affil, $year]);
    return $stmt->rowCount();
}

/**
 * คู่รายการการดำเนินงานระหว่างสองปี — รายการแม่บท (admin_item) แยก id ตามปี
 * จับคู่ด้วย หมวด + ชื่อรายการ + หน่วย (กฎเดียวกับ officer_copy_year) · ปลายทางชื่อซ้ำ → ใช้ id น้อยสุด
 * @return array [from_item_id => to_item_id]
 */
function officer_item_map(PDO $pdo, int $from, int $to): array
{
    $stmt = $pdo->prepare("
        SELECT sa.id, MIN(ta.id)
        FROM admin_item sa
        JOIN admin_item ta ON ta.year_id = :to AND ta.data_source = 'officer'
                          AND ta.scope = sa.scope AND ta.name_tiem = sa.name_tiem AND ta.unit <=> sa.unit
        WHERE sa.year_id = :from AND sa.data_source = 'officer'
        GROUP BY sa.id");
    $stmt->execute([':to' => $to, ':from' => $from]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $a => $b) $map[(int) $a] = (int) $b;
    return $map;
}

/**
 * ตรวจก่อนย้ายข้อมูลการดำเนินงานของคณะจากปี $from ไปปี $to
 * ไม่ให้ย้ายเมื่อ ปีปลายทางยังไม่มีรายการที่ Admin กำหนด
 *            หรือ มีรายการที่กรอกค่าไว้ / แนบหลักฐานไว้ แต่ปีปลายทางไม่มีรายการคู่ (ย้ายแล้วข้อมูลจะหายโดยไม่รู้ตัว)
 * @return string|null ข้อความที่แจ้งผู้ใช้ / null = ย้ายได้
 */
function officer_year_move_check(PDO $pdo, int $affil, int $from, int $to): ?string
{
    $st = $pdo->prepare("SELECT y.year, (SELECT COUNT(*) FROM admin_item ai WHERE ai.year_id = y.id AND ai.data_source = 'officer') FROM admin_year y WHERE y.id = ?");
    $st->execute([$to]);
    [$label, $items] = $st->fetch(PDO::FETCH_NUM) ?: ['', 0];
    if ((int) $items === 0) return "ปีงบประมาณ $label ยังไม่มีรายการที่ Admin กำหนด จึงย้ายข้อมูลไปไม่ได้";

    $map = officer_item_map($pdo, $from, $to);
    $st = $pdo->prepare("
        SELECT ui.admin_item_id, ai.name_tiem
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id
        WHERE ui.affiliation_id = ? AND ui.year_id = ? AND ui.source = 'officer'
          AND (ui.Vol > 0 OR EXISTS (SELECT 1 FROM evidence ev WHERE ev.entity_type = 'user_item' AND ev.entity_id = ui.id))
        ORDER BY ai.id");
    $st->execute([$affil, $from]);
    $lost = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => !isset($map[(int) $r['admin_item_id']])));
    if (!$lost) return null;
    $names = implode(', ', array_slice(array_column($lost, 'name_tiem'), 0, 3)) . (count($lost) > 3 ? ' ฯลฯ' : '');
    return 'ปีงบประมาณ ' . $label . ' ไม่มีรายการที่กรอกข้อมูลไว้ ' . count($lost) . " รายการ ($names) จึงย้ายไม่ได้ — แจ้ง Admin ให้เพิ่มรายการในปีนั้นก่อน";
}

/**
 * ย้ายแถวการดำเนินงาน (ปี $from, source $source) ไปปี $to: แก้แถวเดิมให้ชี้รายการคู่ของปีใหม่ + source กลับเป็น officer
 * แถวที่ไม่มีคู่ถูกลบ — เรียกหลัง officer_year_move_check ผ่านแล้วเท่านั้น (เหลือแต่แถวว่างที่ไม่มีหลักฐาน)
 * ไม่ลบ-เพิ่มแถวใหม่ เพราะหลักฐาน (evidence.entity_id) ผูกกับ id ของแถว
 * @return int จำนวนแถวที่ย้าย
 */
function officer_retarget_rows(PDO $pdo, int $affil, int $from, int $to, string $source): int
{
    $map  = officer_item_map($pdo, $from, $to);
    $rows = $pdo->prepare('SELECT id, admin_item_id FROM user_item WHERE affiliation_id = ? AND year_id = ? AND source = ?');
    $rows->execute([$affil, $from, $source]);
    $up  = $pdo->prepare("UPDATE user_item SET year_id = ?, admin_item_id = ?, source = 'officer' WHERE id = ?");
    $del = $pdo->prepare('DELETE FROM user_item WHERE id = ?');
    $n = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $target = $map[(int) $r['admin_item_id']] ?? null;
        if ($target === null) { $del->execute([$r['id']]); continue; }
        $up->execute([$to, $target, $r['id']]);
        $n++;
    }
    return $n;
}

/**
 * ย้ายข้อมูลการดำเนินงานไปอีกปี — ผู้เรียกคุม transaction
 * ปีปลายทางต้องยังไม่มีข้อมูล (ตรวจด้วย officer_has_year ก่อน) · ย้ายไม่ได้ → Exception พร้อมเหตุผล
 * เดิมเปลี่ยนแค่ year_id: แถวยังชี้รายการของปีเดิม → หน้ากรอก/รายงาน/Dashboard (กรองปีของรายการ) เห็นยอด 0 แต่การ์ดปี/อันดับยังนับ
 */
function officer_move_year(PDO $pdo, int $affil, int $from, int $to): int
{
    if ($err = officer_year_move_check($pdo, $affil, $from, $to)) throw new Exception($err);
    return officer_retarget_rows($pdo, $affil, $from, $to, 'officer');
}

/**
 * สลับข้อมูลการดำเนินงานของสองปี — ผู้เรียกคุม transaction · ตรวจทั้งสองทิศก่อนแก้แถวใด ๆ (ไม่ผ่าน → Exception)
 * พักแถวของปีแรกไว้ด้วย source ชั่วคราว (source อยู่ใน unique key และไม่มี foreign key)
 *   เดิมพักไว้ที่ปี -1 ซึ่งชน foreign key ไป admin_year → สลับไม่ได้เลย
 *   UPDATE คำสั่งเดียวแบบ CASE ก็ชน unique key เมื่อรายการเดียวกันอยู่ทั้งสองปี
 * แต่ละแถวเปลี่ยนไปชี้รายการคู่ของปีใหม่ด้วย (officer_retarget_rows)
 */
function officer_swap_years(PDO $pdo, int $affil, int $id1, int $id2): void
{
    if ($id1 === $id2) return;
    if ($err = officer_year_move_check($pdo, $affil, $id1, $id2)) throw new Exception($err);
    if ($err = officer_year_move_check($pdo, $affil, $id2, $id1)) throw new Exception($err);
    $pdo->prepare("UPDATE user_item SET source = 'officer_swap' WHERE affiliation_id = ? AND year_id = ? AND source = 'officer'")
        ->execute([$affil, $id1]);                                        // พักปีแรก
    officer_retarget_rows($pdo, $affil, $id2, $id1, 'officer');           // ปีสอง → ปีแรก
    officer_retarget_rows($pdo, $affil, $id1, $id2, 'officer_swap');      // ที่พักไว้ → ปีสอง
}

/**
 * คัดลอกปริมาณจากปีต้นทางมาปีปลายทาง (เฉพาะการดำเนินงาน)
 * รายการแม่บท (admin_item) แยก id ตามปี → จับคู่ด้วย หมวด + ชื่อรายการ + หน่วย (เดิมจับคู่ด้วย id จึงคัดลอกข้ามปีไม่ได้เลย)
 * รายการของปลายทางที่ไม่มีคู่ในต้นทางคงค่าเดิม — ผู้เรียกคุม transaction
 *
 * @return int จำนวนรายการที่คัดลอก
 */
function officer_copy_year(PDO $pdo, int $affil, int $source, int $target): int
{
    $stmt = $pdo->prepare("
        SELECT ta.id AS target_item, ui.Vol
        FROM user_item ui
        JOIN admin_item sa ON sa.id = ui.admin_item_id AND sa.year_id = :src1 AND sa.data_source = 'officer'
        JOIN admin_item ta ON ta.year_id = :tgt AND ta.data_source = 'officer'
                          AND ta.scope = sa.scope AND ta.name_tiem = sa.name_tiem AND ta.unit <=> sa.unit
        WHERE ui.affiliation_id = :a AND ui.year_id = :src2 AND ui.source = 'officer'");
    $stmt->execute([':src1' => $source, ':src2' => $source, ':tgt' => $target, ':a' => $affil]);
    $up = $pdo->prepare("INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, source)
                         VALUES (?, ?, ?, ?, 'officer') ON DUPLICATE KEY UPDATE Vol = VALUES(Vol)");
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $up->execute([(int) $r['target_item'], $affil, $target, (float) $r['Vol']]);
        $n++;
    }
    return $n;
}

/**
 * สถิติรายหมวด (admin_g) ของปี: จำนวนรายการ / กรอกแล้ว / ยอด tCO₂e — ใช้บนการ์ดหน้าเลือกหมวด
 * @return array [group_id => ['items'=>int, 'filled'=>int, 'emission'=>float]] ครบทุกหมวดใน admin_g
 */
function officer_group_stats(PDO $pdo, int $affil, int $year): array
{
    $stmt = $pdo->prepare("
        SELECT ag.id, COUNT(ai.id) AS items,
               COALESCE(SUM(CASE WHEN ui.Vol > 0 THEN 1 ELSE 0 END), 0) AS filled,
               COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS emission
        FROM admin_g ag
        LEFT JOIN admin_item ai ON ai.scope = ag.id AND ai.year_id = :y1 AND ai.data_source = 'officer'
        LEFT JOIN user_item ui ON ui.admin_item_id = ai.id AND ui.affiliation_id = :a AND ui.year_id = :y2 AND ui.source = 'officer'
        GROUP BY ag.id");
    $stmt->execute([':y1' => $year, ':y2' => $year, ':a' => $affil]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['id']] = ['items' => (int) $r['items'], 'filled' => (int) $r['filled'], 'emission' => (float) $r['emission']];
    }
    return $out;
}

/**
 * บันทึกปริมาณ (upsert) ของหน่วยงาน/ปี — ใช้ร่วมระหว่าง officer และ admin
 * รับเฉพาะรายการการดำเนินงานของปีนี้ (กัน id ปลอม/ข้ามปี) · ปริมาณผ่าน officer_clean_vol · ผู้เรียกคุม transaction
 * @param array $vols [admin_item_id => ค่าที่กรอก]
 * @return int จำนวนรายการที่บันทึก
 */
function officer_save_vols(PDO $pdo, int $affil, int $year, array $vols): int
{
    if (!$vols) return 0;
    $in  = implode(',', array_fill(0, count($vols), '?'));
    $chk = $pdo->prepare("SELECT id FROM admin_item WHERE year_id = ? AND data_source = 'officer' AND id IN ($in)");
    $chk->execute(array_merge([$year], array_map('intval', array_keys($vols))));
    $ids = array_flip(array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN)));
    // ตรวจเฉพาะรายการของปีนี้ (id ปลอมถูกข้ามอยู่แล้ว ไม่ทำให้ทั้งฟอร์มถูกปฏิเสธ) ก่อนเขียนแถวใด ๆ
    officer_require_valid_vols(array_filter($vols, fn($id) => isset($ids[(int) $id]), ARRAY_FILTER_USE_KEY));
    $stmt = $pdo->prepare("INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year, source)
                           VALUES (:ai, :aff, :y, :v1, CURDATE(), 'officer')
                           ON DUPLICATE KEY UPDATE Vol = :v2, create_year = CURDATE()");
    $n = 0;
    foreach ($vols as $item_id => $raw) {
        if (!isset($ids[(int) $item_id])) continue;
        $val = officer_clean_vol($raw);
        $stmt->execute([':ai' => (int) $item_id, ':aff' => $affil, ':y' => $year, ':v1' => $val, ':v2' => $val]);
        $n++;
    }
    return $n;
}

/** สีและชื่อขอบเขต — ชุดเดียวกับ Dashboard */
function officer_scope_meta(): array
{
    return [
        1 => ['name' => 'การปล่อยโดยตรง',       'color' => '#F97316', 'soft' => '#FFF7ED', 'ink' => '#9A3412'],
        2 => ['name' => 'พลังงานไฟฟ้าที่ซื้อ',     'color' => '#EC4899', 'soft' => '#FDF2F8', 'ink' => '#9D174D'],
        3 => ['name' => 'การปล่อยทางอ้อมอื่น ๆ', 'color' => '#3B82F6', 'soft' => '#EFF6FF', 'ink' => '#1E40AF'],
    ];
}

/**
 * แถบขั้นตอน ① เลือกปี → ② เลือกหมวด → ③ กรอกปริมาณ (ขั้นที่ผ่านแล้วเป็นลิงก์)
 * $extra = query ต่อท้ายลิงก์ขั้น ② เช่น '&affil=3' (admin เลือกหน่วยงาน)
 */
function officer_entry_steps(int $current, int $year = 0, string $extra = ''): string
{
    $steps = [1 => ['เลือกปี', 'items.php'], 2 => ['เลือกหมวด', $year ? 'data_entry.php?year=' . $year . $extra : ''], 3 => ['กรอกปริมาณ', '']];
    $html = '<nav class="oe-steps" aria-label="ขั้นตอนการกรอกข้อมูล">';
    foreach ($steps as $n => [$label, $href]) {
        $state = $n < $current ? 'done' : ($n === $current ? 'current' : 'todo');
        $inner = '<span class="oe-step-no">' . ($n < $current ? '✓' : $n) . '</span><span class="oe-step-label">' . $label . '</span>';
        $html .= $n > 1 ? '<span class="oe-step-sep" aria-hidden="true"></span>' : '';
        $html .= ($state === 'done' && $href)
            ? '<a class="oe-step is-done" href="' . htmlspecialchars($href) . '">' . $inner . '</a>'
            : '<span class="oe-step is-' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '>' . $inner . '</span>';
    }
    return $html . '</nav>';
}
