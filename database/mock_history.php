<?php
/**
 * mock_history.php — สร้างข้อมูลทดสอบ (mock) การดำเนินงานปี 2567 และ 2568 ของทุกคณะ/หน่วยงาน
 * ═══════════════════════════════════════════════════════════════════════════════
 * รัน:  C:\xampp\php\php.exe database\mock_history.php   → เขียน database\mock_history.sql (ยังไม่แตะฐานข้อมูล)
 *
 * กติกา (ผู้ใช้กำหนด 18 ก.ย. 2569)
 *   - ใช้ "วิธีคละรายการ" ชุดเดียวกับปี 2569 — เรียก mock_2569_picks() ซ้ำ แต่เปลี่ยน seed รายปี
 *     จึงได้ส่วนผสมรายการที่คละกันทั้งรายหน่วยงานและรายปี (ไม่ซ้ำหน้าตาปี 2569)
 *   - เพดาน "ยอดรวมทั้งมหาวิทยาลัย" ต่อปี: 2568 ≤ 20,000 · 2567 ≤ 19,000 tCO₂e
 *     ตั้งเป้าที่ ~97% ของเพดาน และตรวจก่อนเขียนไฟล์ (นับรวมกิจกรรม/แบบสอบถามที่มีอยู่เดิมด้วย)
 *   - สัดส่วนขอบเขตต่างกันเล็กน้อยรายปี ให้ดูเป็นธรรมชาติ: 2567 = 58/16/26 → 2568 = 60/14/26 → 2569 = 61/13/26
 *     (เล่าเรื่องว่าหน่วยงานทยอยเปลี่ยนไปใช้ไฟฟ้า/โซลาร์ สัดส่วนขอบเขต 2 จึงลดลง)
 *   - แทนที่เฉพาะข้อมูลที่เจ้าหน้าที่กรอก (user_item.source='officer') ของปี 2567/2568
 *     ข้อมูลกิจกรรม/แบบสอบถาม/การดูดกลับ และข้อมูลปี 2569 ไม่แตะ
 *
 * หมายเหตุ: รายการแม่บท (admin_item) แยกกันคนละชุดในแต่ละปี — ไฟล์นี้จับคู่ด้วย (ชื่อรายการ + หมวด)
 *           จากแม่บทปี 2569 ไปยังแม่บทของปีเป้าหมาย ถ้าจับคู่ไม่ครบจะไม่เขียนไฟล์
 */

require_once __DIR__ . '/mock_2569.php';   // ใช้ซ้ำ: โปรไฟล์หน่วยงาน, การสุ่มคละรายการ, การปรับเทียบยอด

const MOCK_HIST_FILL = 0.97;   // ตั้งเป้ายอดรวมที่ 97% ของเพดาน

/**
 * ตั้งค่ารายปี
 *   year_id => [ปี พ.ศ., ปี ค.ศ. (ใช้เป็นวันที่กรอก), เพดานยอดรวม tCO₂e, seed, สัดส่วนขอบเขต]
 */
function mock_hist_years(): array
{
    return [
        14 => ['year' => 2567, 'ce' => 2024, 'cap' => 19000.0, 'seed' => 2567, 'share' => [1 => 0.58, 2 => 0.16, 3 => 0.26]],
        15 => ['year' => 2568, 'ce' => 2025, 'cap' => 20000.0, 'seed' => 2568, 'share' => [1 => 0.60, 2 => 0.14, 3 => 0.26]],
    ];
}

/**
 * จับคู่รายการแม่บทปี 2569 → ปีเป้าหมาย ด้วย (ชื่อรายการ + หมวด admin_g)
 * @param array $cat_2569 [id => ['name'=>string,'g'=>int]] แม่บทปี 2569
 * @param array $cat_year [id => ['name'=>string,'g'=>int]] แม่บทปีเป้าหมาย
 * @return array [admin_item_id ปี 2569 => admin_item_id ปีเป้าหมาย]
 */
function mock_hist_item_map(array $cat_2569, array $cat_year): array
{
    $by_key = [];
    foreach ($cat_year as $id => $c) $by_key[$c['name'] . '|' . $c['g']] = $id;

    $map = [];
    foreach ($cat_2569 as $id => $c) {
        $k = $c['name'] . '|' . $c['g'];
        if (isset($by_key[$k])) $map[$id] = $by_key[$k];
    }
    return $map;
}

/**
 * เป้ายอดรายหน่วยงาน: ใช้รูปแบบเดียวกับปี 2569 (ผูกกับขนาดหน่วยงาน × สุ่ม ±20%)
 * แล้วปรับสเกลทั้งชุดให้ยอดรวมเท่ากับ $total — สัดส่วนระหว่างหน่วยงานจึงยังคละกันเหมือนเดิม
 * @return array [affiliation_id => tCO₂e]
 */
function mock_hist_targets(float $total): array
{
    $raw = [];
    foreach (mock_2569_profiles() as $aff => [, $sz]) $raw[$aff] = mock_2569_unit_target($sz);

    $sum = array_sum($raw);
    if ($sum <= 0) throw new RuntimeException('เป้ายอดรายหน่วยงานรวมเป็นศูนย์');

    $out = [];
    foreach ($raw as $aff => $v) $out[$aff] = round($v * $total / $sum, 2);
    return $out;
}

/**
 * สร้างแถว mock ของปีหนึ่ง (ไม่แตะฐานข้อมูล — ทดสอบได้)
 * @param array $cfg     ค่าจาก mock_hist_years()
 * @param array $catalog แม่บทปีเป้าหมาย [id => ['ad'=>float,'scope'=>int]]
 * @param array $map     ผลจาก mock_hist_item_map()
 * @return array [['admin_item_id'=>int,'affiliation_id'=>int,'vol'=>float,'date'=>'Y-m-d'], ...]
 */
function mock_hist_rows(array $cfg, array $catalog, array $map): array
{
    $picks = mock_2569_picks($cfg['seed']);

    // แปลง id รายการจากแม่บทปี 2569 → แม่บทปีเป้าหมาย
    $mapped = [];
    foreach ($picks as $aff => $pick) {
        $p = [];
        foreach ($pick as $id => $vol) {
            if (!isset($map[$id])) throw new RuntimeException("จับคู่รายการ $id ไปปี {$cfg['year']} ไม่ได้");
            $p[$map[$id]] = $vol;
        }
        ksort($p);
        $mapped[$aff] = $p;
    }

    $targets = mock_hist_targets($cfg['cap'] * MOCK_HIST_FILL);
    $mapped  = mock_2569_calibrate($mapped, $catalog, $targets, $cfg['share']);

    return mock_2569_rows_from_picks($mapped, $cfg['ce'], 1, 12);
}

/**
 * ตรวจแถว mock ตามกติกา (ไม่แตะฐานข้อมูล)
 *   - ทุกหน่วยงานใน mock_2569_profiles() มีข้อมูลครบ 3 ขอบเขต
 *   - สัดส่วนขอบเขตของทุกหน่วยงานคลาดจากที่กำหนดไม่เกิน 0.5 จุด
 *   - ยอดรวมของปี (mock + กิจกรรม/แบบสอบถามเดิม) ไม่เกินเพดาน และไม่ต่ำกว่า 90% ของเพดาน
 * @return array [ข้อความผิดพลาด, ...] ว่าง = ผ่าน
 */
function mock_hist_check(array $rows, array $catalog, array $cfg, float $other = 0.0): array
{
    $by = [];
    foreach ($rows as $r) {
        $c = $catalog[$r['admin_item_id']] ?? null;
        if (!$c) return ["ปี {$cfg['year']}: รายการ {$r['admin_item_id']} ไม่อยู่ในแม่บทของปีนี้"];
        $by[$r['affiliation_id']] ??= [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $by[$r['affiliation_id']][$c['scope']] += $r['vol'] * $c['ad'] / 1000;
    }

    $err   = [];
    $total = $other;
    foreach (array_keys(mock_2569_profiles()) as $aff) {
        $sc = $by[$aff] ?? [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $t  = array_sum($sc);
        $total += $t;
        if (min($sc) <= 0) { $err[] = "ปี {$cfg['year']} หน่วยงาน $aff ไม่ครบ 3 ขอบเขต"; continue; }
        foreach ($cfg['share'] as $s => $share) {
            if (abs($sc[$s] / $t - $share) > 0.005) {
                $err[] = sprintf('ปี %d หน่วยงาน %d ขอบเขต %d = %.2f%% (ต้องการ %.0f%%)', $cfg['year'], $aff, $s, $sc[$s] / $t * 100, $share * 100);
            }
        }
    }
    if ($total > $cfg['cap'])        $err[] = sprintf('ปี %d ยอดรวม %.2f เกินเพดาน %.0f', $cfg['year'], $total, $cfg['cap']);
    if ($total < $cfg['cap'] * 0.90) $err[] = sprintf('ปี %d ยอดรวม %.2f ต่ำกว่า 90%% ของเพดาน %.0f', $cfg['year'], $total, $cfg['cap']);
    return $err;
}

/** ยอดปล่อยรวม (tCO₂e) ของแถว mock ตามค่า EF ในแม่บท */
function mock_hist_total(array $rows, array $catalog): float
{
    $t = 0.0;
    foreach ($rows as $r) $t += $r['vol'] * (float) ($catalog[$r['admin_item_id']]['ad'] ?? 0) / 1000;
    return $t;
}

/**
 * สร้างสคริปต์ SQL (ลบของเดิม + ใส่ mock ของทั้งสองปี ในทรานแซกชันเดียว)
 * @param array $per_year [year_id => ['cfg'=>array, 'rows'=>array]]
 */
function mock_hist_sql(array $per_year): string
{
    $ids   = implode(', ', array_keys($per_year));
    $years = implode(', ', array_map(fn($p) => $p['cfg']['year'], $per_year));
    $n     = array_sum(array_map(fn($p) => count($p['rows']), $per_year));

    $sql = "-- ═══════════════════════════════════════════════════════════════════════\n"
        . "-- mock_history.sql — สร้างอัตโนมัติจาก database/mock_history.php (อย่าแก้มือ ให้แก้ที่ตัวสร้างแล้วรันใหม่)\n"
        . "-- ข้อมูลทดสอบที่เจ้าหน้าที่กรอก (source='officer') ปี $years — ปี 2569 ไม่แตะ\n"
        . "-- ‼️ สำรองฐานข้อมูลก่อนรัน · ตรวจผล: ยอดรวมรายปีไม่เกินเพดาน (2568 ≤ 20,000 · 2567 ≤ 19,000 tCO2e)\n"
        . "-- ═══════════════════════════════════════════════════════════════════════\n"
        . "SET NAMES utf8mb4;\n"
        . "START TRANSACTION;\n\n"
        . "-- 1) หลักฐานแนบของรายการเดิมในปีเหล่านี้ (ลบเฉพาะแถวในฐานข้อมูล ไฟล์บนดิสก์ไม่แตะ)\n"
        . "DELETE e FROM evidence e JOIN user_item ui ON ui.id = e.entity_id\n"
        . "WHERE e.entity_type = 'user_item' AND ui.source = 'officer' AND ui.year_id IN ($ids);\n\n"
        . "-- 2) รายการที่เจ้าหน้าที่กรอกเดิมของปีเหล่านี้ (กิจกรรม/แบบสอบถาม ไม่แตะ)\n"
        . "DELETE FROM user_item WHERE source = 'officer' AND year_id IN ($ids);\n\n"
        . "-- 3) ข้อมูลทดสอบรวม $n แถว\n";

    foreach ($per_year as $year_id => $p) {
        $values = array_map(fn($r) => sprintf("(%d, %d, %d, %s, '%s', 'officer')",
            $r['admin_item_id'], $r['affiliation_id'], $year_id, number_format($r['vol'], 4, '.', ''), $r['date']), $p['rows']);

        $sql .= sprintf("\n-- ปี %d (year_id = %d) — %d แถว\n", $p['cfg']['year'], $year_id, count($p['rows']))
            . "INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year, source) VALUES\n"
            . implode(",\n", $values) . ";\n";
    }

    return $sql . "\nCOMMIT;\n";
}

// ── รันจากบรรทัดคำสั่ง: อ่านแม่บทจากฐานข้อมูล ตรวจกติกา แล้วเขียนไฟล์ SQL ──
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo = getDB();

    /** อ่านแม่บทรายการของเจ้าหน้าที่ในปีหนึ่ง */
    $load = function (int $year_id) use ($pdo): array {
        $st = $pdo->prepare('SELECT ai.id, ai.name_tiem, ai.scope AS g, ai.AD, ag.scope
            FROM admin_item ai JOIN admin_g ag ON ag.id = ai.scope
            WHERE ai.year_id = ? AND ai.data_source = \'officer\'');
        $st->execute([$year_id]);
        $out = [];
        foreach ($st as $r) {
            $out[(int) $r['id']] = ['name' => $r['name_tiem'], 'g' => (int) $r['g'], 'ad' => (float) $r['AD'], 'scope' => (int) $r['scope']];
        }
        return $out;
    };

    $cat_2569 = $load(MOCK_YEAR_ID);
    if (!$cat_2569) { fwrite(STDERR, "ไม่พบแม่บทรายการปี 2569\n"); exit(1); }

    $per_year = [];
    foreach (mock_hist_years() as $year_id => $cfg) {
        $catalog = $load($year_id);
        if (!$catalog) { fwrite(STDERR, "ไม่พบแม่บทรายการปี {$cfg['year']} (year_id $year_id)\n"); exit(1); }

        $map  = mock_hist_item_map($cat_2569, $catalog);
        $rows = mock_hist_rows($cfg, $catalog, $map);

        // ยอดรวมของปี = mock + กิจกรรม/แบบสอบถามที่มีอยู่เดิม (ไม่ถูกลบ)
        $st = $pdo->prepare('SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui
            JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.year_id = ? AND ui.source <> \'officer\'');
        $st->execute([$year_id]);
        $other = (float) $st->fetchColumn();

        if ($errors = mock_hist_check($rows, $catalog, $cfg, $other)) {
            fwrite(STDERR, "ไม่ผ่านกติกา — ไม่เขียนไฟล์\n  " . implode("\n  ", $errors) . "\n");
            exit(1);
        }

        $mock = mock_hist_total($rows, $catalog);
        printf("ปี %d: mock %d แถว = %.2f tCO2e | กิจกรรม/แบบสอบถาม %.2f | รวม %.2f (เพดาน %.0f)\n",
            $cfg['year'], count($rows), $mock, $other, $mock + $other, $cfg['cap']);

        $per_year[$year_id] = ['cfg' => $cfg, 'rows' => $rows];
    }

    file_put_contents(__DIR__ . '/mock_history.sql', mock_hist_sql($per_year));
    echo "เขียน database/mock_history.sql แล้ว\n";
}
