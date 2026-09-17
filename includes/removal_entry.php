<?php
/**
 * ข้อมูลของหน้า GHG Removal ส่วนกลาง (includes/removal_page.php — admin / officer ศูนย์สิ่งแวดล้อม)
 * รายการดูดกลับแยกตามปี (removal_item.year_id) ชื่อซ้ำในปีเดียวกันไม่ได้ (uq_removal_year_name)
 * ทุกคำสั่งแก้/ลบจำกัดด้วยปีที่เลือก — เดิมใช้ id อย่างเดียว ส่ง id ของปีอื่นมาก็แก้/ลบได้
 * แยกออกมาให้ทดสอบได้ (tests/removal_page_test.php)
 */
require_once __DIR__ . '/officer_entry.php';   // officer_clean_vol: กฎตรวจค่าเดียวกับหน้ากรอกข้อมูลอื่น

/** แปลงข้อผิดพลาดชื่อซ้ำของฐานข้อมูลเป็นข้อความที่ผู้ใช้เข้าใจ */
function removal_run_unique(callable $fn)
{
    try {
        return $fn();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new Exception('มีรายการชื่อนี้อยู่แล้วในปีนี้');
        throw $e;
    }
}

/** ตรวจชื่อ/หน่วย → [name, unit, factor] (ชื่อและหน่วยต้องกรอก ทั้งตอนเพิ่มและแก้ไข) */
function removal_clean_fields(array $in): array
{
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 255);
    $unit = mb_substr(trim((string) ($in['unit'] ?? '')), 0, 255);
    if ($name === '') throw new Exception('กรุณาระบุชื่อรายการ');
    if ($unit === '') throw new Exception('กรุณาระบุหน่วย');
    officer_require_valid_vols([$in['factor'] ?? ''], 'ค่าดูดกลับ', true);   // removal_item.factor decimal(13,6)
    return [$name, $unit, officer_clean_vol($in['factor'] ?? '')];
}

function removal_add_item(PDO $pdo, int $year, array $in): int
{
    [$name, $unit, $factor] = removal_clean_fields($in);
    return removal_run_unique(function () use ($pdo, $year, $name, $unit, $factor) {
        $pdo->prepare('INSERT INTO removal_item (year_id, name_tiem, unit, factor) VALUES (?, ?, ?, ?)')->execute([$year, $name, $unit, $factor]);
        return (int) $pdo->lastInsertId();
    });
}

/** แก้ไขชื่อ/หน่วย/ค่าดูดกลับ เฉพาะรายการของปีนี้ → จำนวนแถวที่พบ (0 = ไม่ใช่รายการของปีนี้) */
function removal_update_item(PDO $pdo, int $year, int $id, array $in): int
{
    [$name, $unit, $factor] = removal_clean_fields($in);
    return removal_run_unique(function () use ($pdo, $year, $id, $name, $unit, $factor) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM removal_item WHERE id = ? AND year_id = ?');
        $chk->execute([$id, $year]);
        if (!(int) $chk->fetchColumn()) return 0;
        $pdo->prepare('UPDATE removal_item SET name_tiem = ?, unit = ?, factor = ? WHERE id = ? AND year_id = ?')->execute([$name, $unit, $factor, $id, $year]);
        return 1;
    });
}

function removal_delete_item(PDO $pdo, int $year, int $id): int
{
    $st = $pdo->prepare('DELETE FROM removal_item WHERE id = ? AND year_id = ?');
    $st->execute([$id, $year]);
    return $st->rowCount();
}

/** บันทึกปริมาณทุกรายการของปี (ช่องที่ไม่ส่งมา = 0 เหมือนเดิม — หน้าเว็บส่งครบทุกแถว) */
function removal_save_qty(PDO $pdo, int $year, array $qty): void
{
    $up  = $pdo->prepare('UPDATE removal_item SET qty = ? WHERE id = ? AND year_id = ?');
    $ids = $pdo->prepare('SELECT id FROM removal_item WHERE year_id = ?');
    $ids->execute([$year]);
    $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
    officer_require_valid_vols(array_map(fn($id) => $qty[$id] ?? '', $ids));   // มีช่องผิด → ไม่บันทึกทั้งชุด
    foreach ($ids as $id) {
        $up->execute([officer_clean_vol($qty[$id] ?? ''), $id, $year]);
    }
}

/**
 * คัดลอกรายการจากปีอื่น: ชื่อ / หน่วย / ค่าดูดกลับ · ปริมาณเริ่มที่ 0 · ข้ามชื่อที่มีอยู่แล้วในปีปลายทาง
 * @return int จำนวนรายการที่คัดลอก
 */
function removal_copy_items(PDO $pdo, int $from, int $to): int
{
    if ($from === $to) return 0;
    $st = $pdo->prepare('INSERT INTO removal_item (year_id, name_tiem, unit, factor, qty)
        SELECT ?, s.name_tiem, s.unit, s.factor, 0 FROM removal_item s
        WHERE s.year_id = ? AND NOT EXISTS (SELECT 1 FROM removal_item t WHERE t.year_id = ? AND t.name_tiem = s.name_tiem)
        ORDER BY s.id');
    $st->execute([$to, $from, $to]);
    return $st->rowCount();
}

/** ปีอื่นที่มีรายการดูดกลับ (ต้นทางของการคัดลอก) → [['year_id','year','items'], ...] ปีใหม่ → เก่า */
function removal_copy_sources(PDO $pdo, int $exclude): array
{
    $st = $pdo->prepare('SELECT y.id AS year_id, y.year, COUNT(ri.id) AS items
        FROM admin_year y JOIN removal_item ri ON ri.year_id = y.id
        WHERE y.id <> ? GROUP BY y.id, y.year ORDER BY y.year DESC');
    $st->execute([$exclude]);
    return array_map(fn($r) => ['year_id' => (int) $r['year_id'], 'year' => (string) $r['year'], 'items' => (int) $r['items']], $st->fetchAll());
}
