<?php
/**
 * ข้อมูลอ้างอิงกลางของ admin — ปีงบประมาณ (admin_year) / หมวด (admin_g) / รายการ Emission Factor (admin_item)
 * -----------------------------------------------------------------------------------------------------------
 * แยกจาก admin/items.php และ admin/data_entry_items.php เดิม เพื่อให้ unit test เรียกได้โดยไม่ต้องรันทั้งหน้า
 * ข้อผิดพลาดที่ผู้ใช้ควรเห็น → throw Exception พร้อมข้อความภาษาไทย (หน้าเว็บนำไปแสดงใน toast)
 * ผู้เรียกคุม transaction เอง (ยกเว้น admin_swap_years ที่ต้องทำเป็นชุดเดียว)
 */

require_once __DIR__ . '/officer_entry.php';

const ADMIN_YEAR_MIN = 2500;
const ADMIN_YEAR_MAX = 2700;

/**
 * การ์ดปีของหน้า ① : ทุกปีในระบบ
 * total / affils นับเฉพาะข้อมูลการดำเนินงาน (source = 'officer') — เดิมรวมกิจกรรม/แบบสอบถามด้วย ยอดบนการ์ดจึงเกินจริง
 * @return array [['id','year','items','affils','total'], ...] ปีใหม่ → เก่า
 */
function admin_year_cards(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT y.id, y.year,
               (SELECT COUNT(*) FROM admin_item ai WHERE ai.year_id = y.id AND ai.data_source = 'officer') AS items,
               (SELECT COUNT(DISTINCT ui.affiliation_id) FROM user_item ui
                 WHERE ui.year_id = y.id AND ui.source = 'officer' AND ui.Vol > 0) AS affils,
               (SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui JOIN admin_item ai ON ai.id = ui.admin_item_id
                 WHERE ui.year_id = y.id AND ui.source = 'officer') AS total
        FROM admin_year y
        ORDER BY y.year DESC")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'id' => (int) $r['id'], 'year' => (int) $r['year'], 'items' => (int) $r['items'],
        'affils' => (int) $r['affils'], 'total' => (float) $r['total'],
    ], $rows);
}

/** ปี พ.ศ. ที่เพิ่มได้: ปีหน้า ถึงย้อนหลัง 10 ปี ตัดปีที่มีแล้ว */
function admin_year_options(array $existing, ?int $now_th = null): array
{
    $now_th = $now_th ?? ((int) date('Y') + 543);
    $have = array_map('intval', $existing);
    $out = [];
    for ($y = $now_th + 1; $y >= $now_th - 10; $y--) if (!in_array($y, $have, true)) $out[] = $y;
    return $out;
}

function admin_check_year(int $year): void
{
    if ($year < ADMIN_YEAR_MIN || $year > ADMIN_YEAR_MAX) throw new Exception('ปีงบประมาณไม่ถูกต้อง (ต้องเป็น พ.ศ.)');
}

/** เพิ่มปี → id ใหม่ */
function admin_add_year(PDO $pdo, int $year): int
{
    admin_check_year($year);
    $st = $pdo->prepare('SELECT 1 FROM admin_year WHERE year = ?');
    $st->execute([$year]);
    if ($st->fetchColumn()) throw new Exception("ปี $year มีอยู่ในระบบแล้ว");
    $pdo->prepare('INSERT INTO admin_year (year) VALUES (?)')->execute([$year]);
    return (int) $pdo->lastInsertId();
}

/**
 * เปลี่ยนเลขปี
 * @return int|null null = เปลี่ยนแล้ว · int = id ของปีที่ใช้เลขนี้อยู่ (ให้หน้าเว็บถามเพื่อสลับ)
 */
function admin_rename_year(PDO $pdo, int $id, int $year): ?int
{
    admin_check_year($year);
    if (admin_year_label($pdo, $id) === null) throw new Exception('ไม่พบปีงบประมาณ');
    $st = $pdo->prepare('SELECT id FROM admin_year WHERE year = ? AND id <> ?');
    $st->execute([$year, $id]);
    $dup = $st->fetchColumn();
    if ($dup) return (int) $dup;
    $pdo->prepare('UPDATE admin_year SET year = ? WHERE id = ?')->execute([$year, $id]);
    return null;
}

/** เลขปีของ id (null = ไม่พบ) */
function admin_year_label(PDO $pdo, int $id): ?int
{
    $st = $pdo->prepare('SELECT year FROM admin_year WHERE id = ?');
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/**
 * สลับเลขปีของสอง id — อ่านเลขปีจากฐานข้อมูลเอง (เดิมเชื่อค่า y1/y2 ที่ส่งมากับฟอร์ม)
 * year มี unique key → พักแถวแรกไว้ที่ค่าติดลบชั่วคราว
 */
function admin_swap_years(PDO $pdo, int $id1, int $id2): void
{
    $y1 = admin_year_label($pdo, $id1);
    $y2 = admin_year_label($pdo, $id2);
    if ($y1 === null || $y2 === null || $id1 === $id2) throw new Exception('ไม่พบปีงบประมาณที่จะสลับ');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE admin_year SET year = ? WHERE id = ?');
        $st->execute([-$id1, $id1]);
        $st->execute([$y1, $id2]);
        $st->execute([$y2, $id1]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * ข้อมูลที่จะหายเมื่อลบปี (foreign key ของทุกตารางเป็น ON DELETE CASCADE)
 * @return array ['items','rows','affils','events','surveys','removals']
 */
function admin_year_impact(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM admin_item WHERE year_id = ?),
        (SELECT COUNT(*) FROM user_item WHERE year_id = ? AND Vol > 0),
        (SELECT COUNT(DISTINCT affiliation_id) FROM user_item WHERE year_id = ? AND Vol > 0),
        (SELECT COUNT(*) FROM event WHERE year_id = ?),
        (SELECT COUNT(*) FROM questionnaire WHERE year_id = ?),
        (SELECT COUNT(*) FROM removal_item WHERE year_id = ?)");
    $st->execute(array_fill(0, 6, $id));
    $r = array_map('intval', $st->fetch(PDO::FETCH_NUM));
    return array_combine(['items', 'rows', 'affils', 'events', 'surveys', 'removals'], $r);
}

/** ข้อความยืนยันการลบปี (บอกจำนวนข้อมูลที่จะหาย) */
function admin_year_impact_text(int $year, array $im): string
{
    $parts = [];
    if ($im['items'])    $parts[] = "รายการ Emission Factor {$im['items']} รายการ";
    if ($im['rows'])     $parts[] = "ปริมาณที่กรอก {$im['rows']} รายการจาก {$im['affils']} หน่วยงาน";
    if ($im['events'])   $parts[] = "กิจกรรม {$im['events']} งาน";
    if ($im['surveys'])  $parts[] = "แบบสอบถาม {$im['surveys']} ชุด";
    if ($im['removals']) $parts[] = "รายการดูดกลับ {$im['removals']} รายการ";
    return "ลบปีงบประมาณ $year ออกจากระบบ?\n"
        . ($parts ? 'ข้อมูลของทุกหน่วยงานในปีนี้จะถูกลบถาวร: ' . implode(' · ', $parts) : 'ปีนี้ยังไม่มีข้อมูล');
}

/** ลบปี → จำนวนแถวที่ลบ (0 = ไม่พบ) */
function admin_delete_year(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('DELETE FROM admin_year WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount();
}

/** คัดลอกรายการ Emission Factor (การดำเนินงาน) จากปีต้นทาง — ข้ามรายการที่หมวด+ชื่อซ้ำกับปลายทาง → จำนวนที่คัดลอก */
function admin_copy_items(PDO $pdo, int $from, int $to): int
{
    if ($from === $to || admin_year_label($pdo, $from) === null || admin_year_label($pdo, $to) === null)
        throw new Exception('กรุณาเลือกปีต้นทางที่ไม่ใช่ปีเดียวกัน');
    $st = $pdo->prepare("
        INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD, data_source)
        SELECT ?, s.scope, s.name_tiem, s.unit, s.AD, 'officer'
        FROM admin_item s
        WHERE s.year_id = ? AND s.data_source = 'officer'
          AND NOT EXISTS (SELECT 1 FROM admin_item t WHERE t.year_id = ? AND t.scope = s.scope AND t.name_tiem = s.name_tiem AND t.affiliation_id = 0)
        ORDER BY s.id");
    $st->execute([$to, $from, $to]);
    return $st->rowCount();
}

// ── หมวด (admin_g) ─────────────────────────────────────────────

/** หมวดทั้งหมด + จำนวนรายการ EF ทุกปีที่ใช้หมวดนี้ (ลบได้เมื่อ = 0) */
function admin_groups(PDO $pdo): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'scope' => (int) $r['scope'], 'name_tiem' => $r['name_tiem'], 'items' => (int) $r['items']],
        $pdo->query('SELECT g.id, g.scope, g.name_tiem, (SELECT COUNT(*) FROM admin_item ai WHERE ai.scope = g.id) AS items
                     FROM admin_g g ORDER BY g.scope, g.order_num, g.id')->fetchAll(PDO::FETCH_ASSOC));
}

function admin_add_group(PDO $pdo, int $scope, string $name): int
{
    $name = trim($name);
    if (!in_array($scope, [1, 2, 3], true)) throw new Exception('กรุณาเลือกขอบเขต 1–3');
    if ($name === '') throw new Exception('กรุณากรอกชื่อหมวด');
    if (mb_strlen($name) > 255) throw new Exception('ชื่อหมวดยาวเกิน 255 ตัวอักษร');
    $st = $pdo->prepare('SELECT 1 FROM admin_g WHERE name_tiem = ?');
    $st->execute([$name]);
    if ($st->fetchColumn()) throw new Exception('มีหมวดชื่อนี้อยู่แล้ว');
    $st = $pdo->prepare('SELECT COALESCE(MAX(order_num), 0) + 1 FROM admin_g WHERE scope = ?');
    $st->execute([$scope]);
    $pdo->prepare('INSERT INTO admin_g (scope, name_tiem, order_num) VALUES (?, ?, ?)')->execute([$scope, $name, (int) $st->fetchColumn()]);
    return (int) $pdo->lastInsertId();
}

/**
 * เลื่อนหมวดขึ้น/ลงภายในขอบเขตเดียวกัน → false = เลื่อนต่อไม่ได้
 * จัดเลขลำดับใหม่ 1..n ก่อนสลับ (เดิมสลับ order_num ตรง ๆ หมวดที่เลขซ้ำกันจึงกดแล้วไม่ขยับ)
 */
function admin_move_group(PDO $pdo, int $id, string $dir): bool
{
    $st = $pdo->prepare('SELECT scope FROM admin_g WHERE id = ?');
    $st->execute([$id]);
    $scope = $st->fetchColumn();
    if ($scope === false) throw new Exception('ไม่พบหมวด');
    $st = $pdo->prepare('SELECT id FROM admin_g WHERE scope <=> ? ORDER BY order_num, id');
    $st->execute([$scope]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $i = array_search($id, $ids, true);
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($ids)) return false;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $up = $pdo->prepare('UPDATE admin_g SET order_num = ? WHERE id = ?');
    foreach ($ids as $n => $gid) $up->execute([$n + 1, $gid]);
    return true;
}

/** ลบหมวด — ห้ามลบเมื่อยังมีรายการ EF ใช้หมวดนี้ (foreign key RESTRICT) */
function admin_delete_group(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM admin_item WHERE scope = ?');
    $st->execute([$id]);
    $n = (int) $st->fetchColumn();
    if ($n > 0) throw new Exception("ลบหมวดนี้ไม่ได้ ยังมีรายการ Emission Factor ใช้อยู่ $n รายการ");
    $st = $pdo->prepare('DELETE FROM admin_g WHERE id = ?');
    $st->execute([$id]);
    if (!$st->rowCount()) throw new Exception('ไม่พบหมวด');
}

// ── รายการ Emission Factor (admin_item) ─────────────────────────

/**
 * ตรวจค่าจากฟอร์ม → [errors, data]
 * data = ['group' => int, 'name' => string, 'unit' => string, 'ad' => float]
 */
function admin_item_clean(array $in): array
{
    $err  = [];
    $name = trim((string) ($in['name_tiem'] ?? ''));
    $unit = trim((string) ($in['unit'] ?? ''));
    $raw  = str_replace(',', '', trim((string) ($in['AD'] ?? '')));
    if ($name === '') $err['name_tiem'] = 'กรุณากรอกชื่อรายการ';
    elseif (mb_strlen($name) > 255) $err['name_tiem'] = 'ชื่อรายการยาวเกิน 255 ตัวอักษร';
    if ($unit === '') $err['unit'] = 'กรุณากรอกหน่วย';
    elseif (mb_strlen($unit) > 255) $err['unit'] = 'หน่วยยาวเกิน 255 ตัวอักษร';
    if ($raw === '' || !is_numeric($raw)) $err['AD'] = 'กรุณากรอกค่าการปล่อยเป็นตัวเลข';
    elseif ((float) $raw < 0) $err['AD'] = 'ค่าการปล่อยต้องไม่ติดลบ';
    elseif ((float) $raw > OFFICER_VOL_MAX) $err['AD'] = 'ค่าการปล่อยเกิน 1,000,000';
    elseif (officer_ef_error($raw) !== null) $err['AD'] = 'ค่าการปล่อยมีทศนิยมเกิน 6 ตำแหน่ง';   // admin_item.AD decimal(13,6)
    $group = (int) ($in['group_id'] ?? 0);
    if ($group <= 0) $err['group_id'] = 'กรุณาเลือกหมวด';
    return [$err, ['group' => $group, 'name' => $name, 'unit' => $unit, 'ad' => (float) $raw]];
}

/** throw ข้อความแรกของ admin_item_clean + ตรวจว่าหมวดมีจริง */
function admin_item_require(PDO $pdo, array $in): array
{
    [$err, $data] = admin_item_clean($in);
    if ($err) throw new Exception(reset($err));
    $st = $pdo->prepare('SELECT 1 FROM admin_g WHERE id = ?');
    $st->execute([$data['group']]);
    if (!$st->fetchColumn()) throw new Exception('ไม่พบหมวดที่เลือก');
    return $data;
}

/** แปลง unique key (ปี+หมวด+ชื่อ) ชน → ข้อความที่อ่านรู้เรื่อง */
function admin_item_run(callable $fn)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new Exception('มีรายการชื่อนี้ในหมวดเดียวกันของปีนี้อยู่แล้ว');
        throw $e;
    }
}

/** เพิ่มรายการ → id ใหม่ */
function admin_item_add(PDO $pdo, int $year, array $in): int
{
    if (admin_year_label($pdo, $year) === null) throw new Exception('ไม่พบปีงบประมาณ');
    $d = admin_item_require($pdo, $in);
    return admin_item_run(function () use ($pdo, $year, $d) {
        $pdo->prepare("INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD, data_source) VALUES (?, ?, ?, ?, ?, 'officer')")
            ->execute([$year, $d['group'], $d['name'], $d['unit'], $d['ad']]);
        return (int) $pdo->lastInsertId();
    });
}

/** แก้รายการ (เฉพาะรายการการดำเนินงานของปีนี้) → 1 = พบ, 0 = ไม่พบในปีนี้ */
function admin_item_update(PDO $pdo, int $year, int $id, array $in): int
{
    $d = admin_item_require($pdo, $in);
    if (!admin_item_find($pdo, $year, $id)) return 0;
    admin_item_run(fn() => $pdo->prepare("UPDATE admin_item SET scope = ?, name_tiem = ?, unit = ?, AD = ? WHERE id = ?")
        ->execute([$d['group'], $d['name'], $d['unit'], $d['ad'], $id]));
    return 1;
}

function admin_item_find(PDO $pdo, int $year, int $id): bool
{
    $st = $pdo->prepare("SELECT 1 FROM admin_item WHERE id = ? AND year_id = ? AND data_source = 'officer'");
    $st->execute([$id, $year]);
    return (bool) $st->fetchColumn();
}

/** จำนวนหน่วยงานที่กรอกปริมาณรายการนี้ไว้ (จะหายไปด้วยเมื่อลบ) — [admin_item_id => affils] ของทั้งปี */
function admin_item_usage(PDO $pdo, int $year): array
{
    $st = $pdo->prepare("SELECT admin_item_id, COUNT(DISTINCT affiliation_id) FROM user_item
                         WHERE year_id = ? AND Vol > 0 GROUP BY admin_item_id");
    $st->execute([$year]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** ลบรายการ (เฉพาะของปีนี้) → จำนวนที่ลบ */
function admin_item_delete(PDO $pdo, int $year, int $id): int
{
    $st = $pdo->prepare("DELETE FROM admin_item WHERE id = ? AND year_id = ? AND data_source = 'officer'");
    $st->execute([$id, $year]);
    return $st->rowCount();
}

// ── หน่วยงานที่ admin เลือกกรอก ──────────────────────────────

/** รายชื่อหน่วยงานทั้งหมด [['id','name'], ...] */
function admin_affiliations(PDO $pdo): array
{
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['affiliation_item']],
        $pdo->query('SELECT id, affiliation_item FROM affiliation_id ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}

/** หน่วยงานจากค่าที่ส่งมา — ไม่มีจริง/ไม่ส่ง → หน่วยงานของ admin เอง */
function admin_pick_affiliation(array $affils, $raw, int $fallback): array
{
    $want = (int) $raw;
    foreach ([$want, $fallback] as $id) {
        foreach ($affils as $a) if ($a['id'] === $id) return $a;
    }
    return $affils[0] ?? ['id' => 0, 'name' => '-'];
}
