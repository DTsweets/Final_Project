<?php
/**
 * mock_2569.php — สร้างข้อมูลทดสอบ (mock) การดำเนินงานปี 2569 ของทุกคณะ/หน่วยงาน
 * ═══════════════════════════════════════════════════════════════════════════════
 * รัน:  C:\xampp\php\php.exe database\mock_2569.php   → เขียน database\mock_2569.sql (ยังไม่แตะฐานข้อมูล)
 *
 * กติกา
 *   - แทนที่ข้อมูลที่เจ้าหน้าที่กรอก (user_item.source='officer') ทั้งหมด + หลักฐานแนบของแถวเหล่านั้น
 *     ข้อมูลกิจกรรม/แบบสอบถาม/การดูดกลับ ไม่แตะ
 *   - ทุกหน่วยงานกรอกครบ 3 ขอบเขต แต่ "คละรายการ" ไม่เหมือนกัน (สุ่มแบบกำหนด seed → รันซ้ำได้ผลเดิม)
 *   - ยอดปล่อยรวมปี 2569 ทั้งมหาวิทยาลัยต้องไม่เกิน 31,000 tCO₂e (ตรวจก่อนเขียนไฟล์)
 *   - แต่ละหน่วยงานปล่อย (การดำเนินงาน) ไม่เกิน 1,200 tCO₂e และคละขนาดกัน — ไม่ให้หน่วยงานใดโดดเด่นเกินไป (ผู้ใช้กำหนด 17 ก.ย. 2569)
 *   - ไม่ใช้รายการ id 52 (ก๊าซชีวภาพ On-road) — pending_ef_2569.sql จะลบรายการนี้แบบ CASCADE
 *
 * สัดส่วนที่ผู้ใช้กำหนด: ขอบเขต 1 = 61% / ขอบเขต 2 = 13% / ขอบเขต 3 = 26% "ของทุกหน่วยงาน" (ประมาณการ ไม่ใช่ข้อมูลจริงของ ม.พะเยา)
 *   → ภาพรวมทั้งมหาวิทยาลัยได้สัดส่วนเดียวกันโดยอัตโนมัติ — ปรับเทียบด้วย mock_2569_calibrate()
 *   ⚠️ มหาวิทยาลัยไทยส่วนใหญ่ไฟฟ้า (ขอบเขต 2) เป็นแหล่งหลัก — ชุดนี้สมมติสถานการณ์ที่ทำให้ขอบเขต 1 สูงขึ้นแทน
 *   - ขอบเขต 1: รถรับส่งนิสิต/ยานพาหนะส่วนกลาง (ดีเซล ~30–45 L ต่อ MWh ของหน่วยงาน), หม้อไอน้ำโรงพยาบาลใช้น้ำมันเตา C ~1 ML,
 *              LPG โรงอาหาร/หอพัก, เครื่องปั่นไฟ, รถแทรกเตอร์คณะเกษตร
 *   - ขอบเขต 2: ซื้อไฟจากสายส่งส่วนน้อย (หลายคณะผลิตโซลาร์ใช้เอง ซึ่ง EF = 0)
 *   - ขอบเขต 3: ขยะฝังกลบ, น้ำประปา, น้ำเสีย, กระดาษ, ครุภัณฑ์
 */

const MOCK_YEAR_ID   = 25;       // admin_year.year = 2569
const MOCK_CAP_TCO2E = 31000.0;  // เพดานยอดปล่อยรวมทั้งมหาวิทยาลัย
const MOCK_UNIT_CAP  = 1200.0;   // เพดานยอดปล่อย (การดำเนินงาน) ต่อหน่วยงาน
const MOCK_UNIT_MAX_TARGET = 1180.0;  // เป้าหมายสูงสุดที่สุ่มได้ — เผื่อผลจากการปัดปริมาณ ให้ยอดจริงไม่เกิน MOCK_UNIT_CAP
const MOCK_SEED      = 2569;

/**
 * โปรไฟล์หน่วยงาน: [ไฟฟ้า MWh/ปี, ตัวคูณขนาด (คณะทั่วไป = 1.0), แท็กลักษณะงาน]
 * แท็ก: hospital, agri, energy, env, it, lab, art, office
 */
function mock_2569_profiles(): array
{
    return [
        1  => [450,   0.35, ['env']],        // ศูนย์สิ่งแวดล้อม
        2  => [2600,  1.1,  ['it']],         // เทคโนโลยีสารสนเทศและการสื่อสาร
        3  => [2300,  1.2,  ['agri']],       // เกษตรศาสตร์และทรัพยากรธรรมชาติ
        4  => [700,   0.5,  []],             // นิติศาสตร์
        5  => [1500,  0.9,  ['energy']],     // พลังงานและสิ่งแวดล้อม
        6  => [1100,  0.7,  []],             // พยาบาลศาสตร์
        7  => [3400,  1.4,  ['lab']],        // วิศวกรรมศาสตร์
        8  => [1300,  0.8,  []],             // สาธารณสุขศาสตร์
        9  => [1400,  0.9,  []],             // บริหารธุรกิจและนิเทศศาสตร์
        10 => [12500, 1.8,  ['hospital']],   // แพทยศาสตร์ (โรงพยาบาลมหาวิทยาลัย)
        11 => [3300,  1.4,  ['lab']],        // วิทยาศาสตร์
        12 => [1500,  0.9,  ['art']],        // สถาปัตยกรรมศาสตร์และศิลปกรรมศาสตร์
        13 => [1300,  1.0,  []],             // ศิลปศาสตร์
        14 => [2200,  0.9,  ['lab']],        // ทันตแพทยศาสตร์ (คลินิก)
        15 => [1800,  0.9,  ['lab']],        // เภสัชศาสตร์
        16 => [2400,  1.1,  ['lab']],        // วิทยาศาสตร์การแพทย์
        17 => [1500,  0.9,  []],             // สหเวชศาสตร์
        18 => [900,   0.6,  []],             // รัฐศาสตร์และสังคมศาสตร์
        22 => [600,   0.3,  ['office']],     // กองการเจ้าหน้าที่
    ];
}

/** สุ่มจำนวนเต็มในช่วง [min,max] × ตัวคูณ แล้วปัดให้ดูเหมือนตัวเลขที่กรอกจริง */
function mock_2569_amount(float $min, float $max, float $mul = 1.0): float
{
    $v = mt_rand((int) round($min), (int) round($max)) * $mul;
    return $v >= 1000 ? round($v / 10) * 10 : round($v);
}

/** สุ่มว่าจะใส่รายการหรือไม่ ตามความน่าจะเป็น 0–1 */
function mock_2569_chance(float $p): bool
{
    return mt_rand(1, 1000) <= (int) round($p * 1000);
}

/**
 * สร้างแถว user_item ของทุกหน่วยงาน (ไม่แตะฐานข้อมูล — ทดสอบได้)
 * @param array $catalog แม่บทรายการ [admin_item_id => ['ad' => float, 'scope' => 1|2|3]] ใช้ปรับเทียบสัดส่วน
 * @return array [['admin_item_id'=>int, 'affiliation_id'=>int, 'vol'=>float, 'date'=>'Y-m-d'], ...]
 */
function mock_2569_rows(array $catalog): array
{
    mt_srand(MOCK_SEED);
    $picks = [];                                          // affiliation_id => [admin_item_id => vol]

    foreach (mock_2569_profiles() as $aff => [$mwh, $sz, $tags]) {
        $has  = fn(string $t) => in_array($t, $tags, true);
        $hosp = $has('hospital');
        $pick = [];                                       // admin_item_id => vol

        // ── ขอบเขต 1: การเผาไหม้ ──
        $pick[32] = $hosp ? mock_2569_amount(60000, 80000) : mock_2569_amount(8000, 25000, $sz);  // ดีเซลเครื่องปั่นไฟ (อยู่กับที่)
        // ยานพาหนะ: อย่างน้อย 1 ชนิดเชื้อเพลิงหลัก แล้วคละชนิดอื่น (รวมรถรับส่งนิสิตที่คณะรับผิดชอบ)
        // ดีเซล On-road: ทุกหน่วยงานมีรถส่วนกลาง ปริมาณผูกกับขนาดหน่วยงาน (33–48 ลิตร ต่อความต้องการไฟฟ้า 1 MWh)
        $pick[44] = $hosp ? mock_2569_amount(60000, 80000) : round($mwh * mt_rand(33, 48) / 10) * 10;
        if (mock_2569_chance(0.5)) $pick[45] = mock_2569_amount(20000, 50000, $sz); // E10 On-road
        if (mock_2569_chance(0.35)) $pick[46] = mock_2569_amount(5000, 15000, $sz);                        // E20 On-road
        if (mock_2569_chance(0.25)) $pick[48] = mock_2569_amount(1000, 5000, $sz);                         // เบนซิน On-road
        if ($hosp || $has('lab') || mock_2569_chance(0.5)) {
            $pick[35] = $hosp ? mock_2569_amount(150000, 200000) : mock_2569_amount(10000, 40000, $sz);    // LPG ห้องปฏิบัติการ/โรงอาหาร/หอพัก
        }
        if ($hosp)            $pick[39] = mock_2569_amount(900000, 1100000);                              // น้ำมันเตา C หม้อไอน้ำโรงพยาบาล
        if ($has('agri'))     $pick[53] = mock_2569_amount(150000, 250000);                               // ดีเซล Off-road รถแทรกเตอร์/เครื่องจักรฟาร์ม
        if ($has('env'))      $pick[53] = mock_2569_amount(18000, 26000);                                 // ดีเซล Off-road รถดูแลภูมิทัศน์ทั้งวิทยาเขต
        if ($has('agri'))     $pick[40] = mock_2569_amount(800, 3000);                                    // ไม้ฟืน
        if ($has('energy'))   $pick[36] = mock_2569_amount(400, 1500);                                    // ไบโอดีเซล (ทดลองเครื่องยนต์)
        if ($has('agri') || $has('env') || $has('office')) $pick[54] = mock_2569_amount(150, 700);        // E10 Off-road เครื่องตัดหญ้า

        // ── ขอบเขต 2: ไฟฟ้า ──
        // ไฟฟ้าจากสายส่ง (kWh) — ค่าตั้งต้นตามขนาดหน่วยงาน แล้วถูกปรับเทียบให้ได้สัดส่วนใน mock_2569_calibrate()
        $pick[57] = round($mwh * 108 * mt_rand(92, 108) / 100 / 10) * 10;
        if ($has('energy'))                       $pick[58] = mock_2569_amount(900000, 1200000);          // โซลาร์ผลิตเอง (EF = 0)
        elseif ($has('env') || $has('lab') || mock_2569_chance(0.4)) $pick[58] = round($mwh * mt_rand(300, 600) / 10) * 10; // โซลาร์ผลิตเอง (kWh)

        // ── ขอบเขต 3: ทางอ้อมอื่น ๆ ──
        $pick[65] = $hosp ? mock_2569_amount(450000, 580000) : mock_2569_amount(54000, 108000, $sz);      // น้ำประปาภูมิภาค (m³)
        $pick[62] = mock_2569_amount(120000, 350000, $sz);                                                // กระดาษไม่เคลือบผิว (แผ่น)
        if ($has('art') || mock_2569_chance(0.4)) $pick[63] = mock_2569_amount(5000, 25000, $has('art') ? 2.0 : $sz); // กระดาษเคลือบผิว
        if (mock_2569_chance(0.6)) $pick[66] = mock_2569_amount(60000, 300000, $sz);                      // อุปกรณ์สำนักงาน (บาท)
        if (mock_2569_chance(0.4)) $pick[67] = mock_2569_amount(80000, 600000, $sz);                      // เฟอร์นิเจอร์ (บาท)
        if ($has('it'))            $pick[68] = mock_2569_amount(2000000, 3500000);                        // คอมพิวเตอร์ (บาท)
        elseif (mock_2569_chance(0.6)) $pick[68] = mock_2569_amount(300000, 1500000, $sz);
        if ($hosp || mock_2569_chance(0.9)) {
            $pick[107] = $hosp ? mock_2569_amount(1080000, 1440000) : mock_2569_amount(109000, 250000, $sz);  // ขยะฝังกลบถูกหลักสุขาภิบาล (kg)
        }
        if (mock_2569_chance(0.5))  $pick[104] = mock_2569_amount(109000, 250000, $sz);                     // เก็บขนขยะ (kg)
        if (mock_2569_chance(0.35)) $pick[105] = mock_2569_amount(3000, 12000, $sz);                      // คัดแยกขยะ (kg)
        if ($has('agri') || $has('env')) $pick[110] = mock_2569_amount(8000, 25000);                      // ปุ๋ยหมักจากมูลฝอยสด (kg)
        if ($hosp || mock_2569_chance(0.5)) {
            $pick[114] = $hosp ? mock_2569_amount(360000, 470000) : mock_2569_amount(45000, 90000, $sz);  // รวบรวม+บำบัดน้ำเสีย (m³)
        }

        ksort($pick);
        $picks[$aff] = $pick;
    }

    $targets = [];
    foreach (mock_2569_profiles() as $aff => [, $sz]) $targets[$aff] = mock_2569_unit_target($sz);
    $picks = mock_2569_calibrate($picks, $catalog, $targets);

    $rows = [];
    foreach ($picks as $aff => $pick) {
        foreach ($pick as $item => $vol) {
            // วันที่กรอก: กระจายช่วง ม.ค.–ส.ค. 2569 (ค.ศ. 2026) เหมือนเจ้าหน้าที่ทยอยกรอก
            $date = sprintf('2026-%02d-%02d', mt_rand(1, 8), mt_rand(1, 28));
            $rows[] = ['admin_item_id' => $item, 'affiliation_id' => $aff, 'vol' => (float) $vol, 'date' => $date];
        }
    }
    return $rows;
}

/** สัดส่วนเป้าหมายรายขอบเขต (ผู้ใช้กำหนด 17 ก.ย. 2569) — ใช้กับทุกหน่วยงาน */
const MOCK_SCOPE_SHARE = [1 => 0.61, 2 => 0.13, 3 => 0.26];

/**
 * ยอดเป้าหมาย (tCO₂e) ของหน่วยงาน: ผูกกับขนาดหน่วยงาน × สุ่ม ±20% แล้วไม่เกิน MOCK_UNIT_MAX_TARGET
 * ศูนย์/กองขนาดเล็ก ≈ 400–600, คณะทั่วไป ≈ 650–950, คณะขนาดใหญ่ ≈ 1,000–1,180
 */
function mock_2569_unit_target(float $size): float
{
    $t = (300 + 480 * $size) * mt_rand(80, 120) / 100;
    return min(MOCK_UNIT_MAX_TARGET, round($t, 2));
}

/**
 * ปรับปริมาณให้แต่ละหน่วยงานได้ยอดเป้าหมาย และสัดส่วน 61 / 13 / 26 ภายในหน่วยงาน
 * ------------------------------------------------------------------------
 * ขอบเขต s ของหน่วยงาน × (เป้าหมาย × สัดส่วน s ÷ ยอดขอบเขต s เดิม) — รายการในขอบเขตเดียวกันคูณตัวเดียวกัน
 * สัดส่วนระหว่างรายการ (ซึ่งคละตามลักษณะหน่วยงาน) จึงคงเดิม
 * ปริมาณถูกปัดเหมือนตัวเลขที่กรอกจริง สัดส่วนสุดท้ายจึงคลาดได้เล็กน้อย (ตรวจใน mock_2569_check ±0.5%)
 *
 * @param array $picks   [affiliation_id => [admin_item_id => vol]]
 * @param array $catalog [admin_item_id => ['ad' => float, 'scope' => int]]
 * @param array $targets [affiliation_id => tCO₂e]
 */
function mock_2569_calibrate(array $picks, array $catalog, array $targets): array
{
    foreach ($picks as $aff => &$pick) {
        $cur = [1 => 0.0, 2 => 0.0, 3 => 0.0];
        foreach ($pick as $id => $vol) {
            if (!isset($catalog[$id])) throw new RuntimeException("ไม่พบรายการ $id ในแม่บทปี 2569");
            $cur[$catalog[$id]['scope']] += $vol * $catalog[$id]['ad'] / 1000;
        }
        foreach ($pick as $id => &$vol) {
            $s = $catalog[$id]['scope'];
            // เฉพาะรายการที่มีค่าการปล่อย — โซลาร์ (EF = 0) ไม่ต้องปรับ
            if ($catalog[$id]['ad'] <= 0 || $cur[$s] <= 0) continue;
            $v   = $vol * $targets[$aff] * MOCK_SCOPE_SHARE[$s] / $cur[$s];
            $vol = $v >= 1000 ? round($v / 10) * 10 : max(1.0, round($v));
        }
        unset($vol);
    }
    unset($pick);
    return $picks;
}

/**
 * ตรวจแถว mock ตามกติกาของผู้ใช้ (ไม่แตะฐานข้อมูล) — CLI ไม่เขียนไฟล์ถ้ามีข้อผิดพลาด
 * - ทุกหน่วยงานใน mock_2569_profiles() มีข้อมูลครบ 3 ขอบเขต
 * - ยอดต่อหน่วยงาน ≤ MOCK_UNIT_CAP
 * - สัดส่วนขอบเขตในทุกหน่วยงานคลาดจาก MOCK_SCOPE_SHARE ไม่เกิน 0.5 จุด
 *
 * @return array [ข้อความผิดพลาด, ...] ว่าง = ผ่าน
 */
function mock_2569_check(array $rows, array $catalog): array
{
    $by = [];
    foreach ($rows as $r) {
        $c = $catalog[$r['admin_item_id']];
        $by[$r['affiliation_id']] ??= [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $by[$r['affiliation_id']][$c['scope']] += $r['vol'] * $c['ad'] / 1000;
    }
    $err = [];
    foreach (array_keys(mock_2569_profiles()) as $aff) {
        $sc = $by[$aff] ?? [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $t  = array_sum($sc);
        if (min($sc) <= 0) { $err[] = "หน่วยงาน $aff ไม่ครบ 3 ขอบเขต"; continue; }
        if ($t > MOCK_UNIT_CAP) $err[] = sprintf('หน่วยงาน %d ยอด %.2f เกิน %.0f', $aff, $t, MOCK_UNIT_CAP);
        foreach (MOCK_SCOPE_SHARE as $s => $share) {
            if (abs($sc[$s] / $t - $share) > 0.005) $err[] = sprintf('หน่วยงาน %d ขอบเขต %d = %.2f%%', $aff, $s, $sc[$s] / $t * 100);
        }
    }
    return $err;
}

/**
 * ยอดปล่อยรวม (tCO₂e) ของแถว mock ตามค่า EF ในแม่บท
 * @param array $ef [admin_item_id => AD]
 */
function mock_2569_total(array $rows, array $ef): float
{
    $t = 0.0;
    foreach ($rows as $r) $t += $r['vol'] * (float) ($ef[$r['admin_item_id']] ?? 0) / 1000;
    return $t;
}

/** สร้างสคริปต์ SQL (ลบของเดิม + ใส่ mock ในทรานแซกชันเดียว) */
function mock_2569_sql(array $rows): string
{
    $values = array_map(fn($r) => sprintf("(%d, %d, %d, %s, '%s', 'officer')",
        $r['admin_item_id'], $r['affiliation_id'], MOCK_YEAR_ID, number_format($r['vol'], 4, '.', ''), $r['date']), $rows);

    return "-- ═══════════════════════════════════════════════════════════════════════\n"
        . "-- mock_2569.sql — สร้างอัตโนมัติจาก database/mock_2569.php (อย่าแก้มือ ให้แก้ที่ตัวสร้างแล้วรันใหม่)\n"
        . "-- แทนที่ข้อมูลที่เจ้าหน้าที่กรอก (source='officer') ทั้งหมดด้วยข้อมูลทดสอบปี 2569\n"
        . "-- ‼️ สำรองฐานข้อมูลก่อนรัน · ตรวจผล: ทุกหน่วยงาน ≤ 1,200 tCO2e และสัดส่วนขอบเขต 61 / 13 / 26\n"
        . "-- ═══════════════════════════════════════════════════════════════════════\n"
        . "SET NAMES utf8mb4;\n"
        . "START TRANSACTION;\n\n"
        . "-- 1) หลักฐานแนบของรายการเดิม (ลบเฉพาะแถวในฐานข้อมูล ไฟล์บนดิสก์ไม่แตะ)\n"
        . "DELETE e FROM evidence e JOIN user_item ui ON ui.id = e.entity_id\n"
        . "WHERE e.entity_type = 'user_item' AND ui.source = 'officer';\n\n"
        . "-- 2) รายการที่เจ้าหน้าที่กรอกเดิมทั้งหมด (กิจกรรม/แบบสอบถาม ไม่แตะ)\n"
        . "DELETE FROM user_item WHERE source = 'officer';\n\n"
        . "-- 3) ข้อมูลทดสอบ " . count($rows) . " แถว\n"
        . "INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year, source) VALUES\n"
        . implode(",\n", $values) . ";\n\n"
        . "COMMIT;\n";
}

// ── รันจากบรรทัดคำสั่ง: ตรวจกับฐานข้อมูลแล้วเขียนไฟล์ SQL ──
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require_once __DIR__ . '/../config/db.php';
    $pdo  = getDB();
    $catalog = [];
    foreach ($pdo->query("SELECT ai.id, ai.AD, ag.scope FROM admin_item ai JOIN admin_g ag ON ag.id = ai.scope
        WHERE ai.year_id = " . MOCK_YEAR_ID . " AND ai.data_source = 'officer'") as $r) {
        $catalog[(int) $r['id']] = ['ad' => (float) $r['AD'], 'scope' => (int) $r['scope']];
    }
    $rows = mock_2569_rows($catalog);

    // รายการที่ใช้ต้องเป็นแม่บทปี 2569 ของเจ้าหน้าที่ และไม่ใช่ id 52
    $ids = array_values(array_unique(array_column($rows, 'admin_item_id')));
    $st  = $pdo->prepare('SELECT id, AD FROM admin_item WHERE year_id = ? AND data_source = \'officer\' AND id IN (' . implode(',', $ids) . ')');
    $st->execute([MOCK_YEAR_ID]);
    $ef = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $missing = array_diff($ids, array_keys($ef));
    if ($missing || in_array(52, $ids, true)) {
        fwrite(STDERR, 'รายการไม่ถูกต้อง: ' . implode(',', $missing ?: [52]) . "\n");
        exit(1);
    }

    // ยอดรวมทั้งมหาวิทยาลัย = mock + กิจกรรม/แบบสอบถามที่มีอยู่ (ไม่ถูกลบ)
    $other = (float) $pdo->query("SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id WHERE ui.year_id = " . MOCK_YEAR_ID . " AND ui.source <> 'officer'")->fetchColumn();
    if ($errors = mock_2569_check($rows, $catalog)) {
        fwrite(STDERR, "ไม่ผ่านกติกา — ไม่เขียนไฟล์\n  " . implode("\n  ", $errors) . "\n");
        exit(1);
    }
    $mock  = mock_2569_total($rows, $ef);
    printf("mock %d แถว = %.2f tCO2e | กิจกรรม/แบบสอบถาม %.2f | รวม %.2f (เพดาน %.0f)\n",
        count($rows), $mock, $other, $mock + $other, MOCK_CAP_TCO2E);
    if ($mock + $other > MOCK_CAP_TCO2E) {
        fwrite(STDERR, "ยอดรวมเกินเพดาน — ไม่เขียนไฟล์\n");
        exit(1);
    }

    file_put_contents(__DIR__ . '/mock_2569.sql', mock_2569_sql($rows));
    echo "เขียน database/mock_2569.sql แล้ว\n";
}
