<?php

function qty_fmt($v): string
{
    return rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
}

function ghg_years(PDO $pdo): array
{
    return $pdo->query('SELECT id AS year_id, year FROM admin_year ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function ghg_scope_totals(PDO $pdo, int $year, ?int $affil = null): array
{
    $affilCond = $affil !== null ? " AND ui.affiliation_id = :aff AND ui.source = 'officer'" : '';
    $sql = "
        SELECT ag.scope, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS e
        FROM admin_g ag
        LEFT JOIN admin_item ai ON ai.scope = ag.id AND ai.year_id = :y1
        LEFT JOIN user_item  ui ON ui.admin_item_id = ai.id AND ui.year_id = :y2 $affilCond
        GROUP BY ag.scope
        ORDER BY ag.scope
    ";
    $stmt = $pdo->prepare($sql);
    $params = [':y1' => $year, ':y2' => $year];
    if ($affil !== null) $params[':aff'] = $affil;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [1 => (float)($rows[1] ?? 0), 2 => (float)($rows[2] ?? 0), 3 => (float)($rows[3] ?? 0)];
}

/**
 * ยอดกิจกรรม / แบบสอบถาม ทั้งมหาวิทยาลัย (ขอบเขต 3) — บรรทัด "ในจำนวนนี้" ใต้แถบขอบเขต 3 มุมมองทั้งมหาวิทยาลัย
 * อ่าน user_item ชุดเดียวกับที่ ghg_scope_totals($pdo, $year, null) รวมไว้แล้ว → เป็นส่วนหนึ่งของยอดขอบเขต 3 เสมอ
 * (มุมมองคณะใช้ event_total / survey_total จาก ghg_report_summary() แทน)
 *
 * @return array ['event'=>float, 'survey'=>float]
 */
function ghg_indirect_source_totals(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare("
        SELECT ui.source, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = :y1
        JOIN admin_g    ag ON ag.id = ai.scope
        WHERE ui.year_id = :y2 AND ui.source IN ('event', 'survey') AND ag.scope = 3
        GROUP BY ui.source");
    $stmt->execute([':y1' => $year, ':y2' => $year]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['event' => (float) ($rows['event'] ?? 0), 'survey' => (float) ($rows['survey'] ?? 0)];
}

function ghg_total(PDO $pdo, int $year, ?int $affil = null): float
{
    $s = ghg_scope_totals($pdo, $year, $affil);
    return $s[1] + $s[2] + $s[3];
}

function ghg_by_affiliation(PDO $pdo, ?int $year = null): array
{
    if ($year === null) {
        $sql = '
            SELECT a.id AS affil_id, a.affiliation_item,
                   COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
            FROM affiliation_id a
            LEFT JOIN user_item  ui ON ui.affiliation_id = a.id AND ui.source = \'officer\'
            LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
            GROUP BY a.id, a.affiliation_item
            ORDER BY total_emission DESC';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('
        SELECT a.id AS affil_id, a.affiliation_item,
               COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
        FROM affiliation_id a
        LEFT JOIN user_item  ui ON ui.affiliation_id = a.id AND ui.year_id = :y AND ui.source = \'officer\'
        LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
        GROUP BY a.id, a.affiliation_item
        ORDER BY total_emission DESC');
    $stmt->execute([':y' => $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ghg_affil_detail(PDO $pdo, int $affil, int $year): array
{
    $stmt = $pdo->prepare('
        SELECT ag.name_tiem AS activity_type, ag.scope,
               ai.name_tiem, ai.unit,
               ui.Vol AS vol, (ui.Vol * ai.AD)/1000 AS emission
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id
        JOIN admin_g    ag ON ag.id = ai.scope
        WHERE ui.affiliation_id = :aff AND ui.year_id = :y AND ui.source = \'officer\'
        ORDER BY ag.scope ASC, ag.order_num ASC, ai.name_tiem ASC');
    $stmt->execute([':aff' => $affil, ':y' => $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ghg_affil_yearly(PDO $pdo, int $affil): array
{
    $stmt = $pdo->prepare('
        SELECT y.id AS year_id, y.year,
               COUNT(DISTINCT ui.id) AS entry_count,
               COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
        FROM admin_year y
        LEFT JOIN user_item  ui ON ui.year_id = y.id AND ui.affiliation_id = :aff AND ui.source = \'officer\'
        LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
        GROUP BY y.id, y.year
        ORDER BY y.year DESC');
    $stmt->execute([':aff' => $affil]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ghg_affil_name(PDO $pdo, int $affil): string
{
    $stmt = $pdo->prepare('SELECT affiliation_item FROM affiliation_id WHERE id = :id');
    $stmt->execute([':id' => $affil]);
    return (string)($stmt->fetchColumn() ?: '-');
}

function removal_central_total(PDO $pdo, int $year): float
{
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(ri.qty * ri.factor)/1000, 0)
        FROM removal_item ri
        WHERE ri.year_id = :y');
    $stmt->execute([':y' => $year]);
    return (float) $stmt->fetchColumn();
}

function removal_activity_total(PDO $pdo, int $year, ?int $affil = null): float
{
    $sql = 'SELECT COALESCE(SUM(rei.qty * rei.factor)/1000, 0)
            FROM removal_event_item rei
            JOIN event e ON e.id = rei.event_id
            WHERE e.year_id = :y';
    $params = [':y' => $year];
    if ($affil !== null) { $sql .= ' AND e.affiliation_id = :aff'; $params[':aff'] = $affil; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

function removal_total(PDO $pdo, int $year): float
{
    return removal_central_total($pdo, $year) + removal_activity_total($pdo, $year);
}

function removal_activity_list(PDO $pdo, int $year, ?int $affil = null): array
{
    $sql = '
        SELECT rei.id, rei.name_tiem, rei.unit, rei.factor,
               rei.qty,
               rei.qty * rei.factor / 1000 AS emission,
               e.id AS event_id, e.name AS event_name, e.event_date, e.event_end_date,
               e.organizer_name, e.affiliation_id AS affil_id,
               COALESCE(NULLIF(e.organizer_name, \'\'), a.affiliation_item) AS affil_name
        FROM removal_event_item rei
        JOIN event e ON e.id = rei.event_id
        LEFT JOIN affiliation_id a ON a.id = e.affiliation_id
        WHERE e.year_id = :y';
    $params = [':y' => $year];
    if ($affil !== null) { $sql .= ' AND e.affiliation_id = :aff'; $params[':aff'] = $affil; }
    $sql .= ' ORDER BY e.affiliation_id ASC, e.id ASC, rei.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function event_emission_list(PDO $pdo, int $year, ?int $affil = null): array
{
    $sql = '
        SELECT ei.id, ai.name_tiem, ai.unit, ai.AD AS factor, ei.Vol AS qty,
               ei.Vol * ai.AD / 1000 AS emission,
               ag.scope AS scope, ag.name_tiem AS activity_type, \'emit\' AS itype,
               e.id AS event_id, e.name AS event_name, e.event_date, e.event_end_date,
               e.organizer_name, e.affiliation_id AS affil_id,
               COALESCE(NULLIF(e.organizer_name, \'\'), a.affiliation_item) AS affil_name
        FROM event_item ei
        JOIN event e ON e.id = ei.event_id
        JOIN admin_item ai ON ai.id = ei.admin_item_id
        JOIN admin_g ag ON ag.id = ai.scope
        LEFT JOIN affiliation_id a ON a.id = e.affiliation_id
        WHERE e.year_id = :y';
    $params = [':y' => $year];
    if ($affil !== null) { $sql .= ' AND e.affiliation_id = :aff'; $params[':aff'] = $affil; }
    $sql .= ' ORDER BY e.affiliation_id ASC, e.id ASC, ei.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ยอดปล่อยจากกิจกรรมของคณะในปีนั้น (tCO₂e)
 * อ่านจาก event_item ชุดเดียวกับ event_emission_list() → ตัวเลขตรงกับตารางกิจกรรมเสมอ
 */
function ghg_event_total(PDO $pdo, int $year, ?int $affil = null): float
{
    $sql = 'SELECT COALESCE(SUM(ei.Vol * ai.AD)/1000, 0)
            FROM event_item ei
            JOIN event e       ON e.id  = ei.event_id
            JOIN admin_item ai ON ai.id = ei.admin_item_id
            WHERE e.year_id = :y';
    $params = [':y' => $year];
    if ($affil !== null) { $sql .= ' AND e.affiliation_id = :aff'; $params[':aff'] = $affil; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) $stmt->fetchColumn();
}

/**
 * รายการแบบสอบถามของคณะ (user_item source='survey') — นับเข้ายอดคณะเฉพาะขอบเขต 3
 * แบบสอบถามเก็บการเดินทางของบุคลากร/นิสิต ซึ่งเป็นการปล่อยทางอ้อม จึงไม่นับเข้าขอบเขต 1/2 แม้แม่บทจะผิดหมวด
 *
 * @return array [['name_tiem','unit','qty','emission','scope'=>3,'activity_type'], ...]
 */
function survey_emission_list(PDO $pdo, int $year, int $affil): array
{
    $stmt = $pdo->prepare("
        SELECT ui.id, ai.name_tiem, ai.unit, ui.Vol AS qty, ui.Vol * ai.AD / 1000 AS emission,
               ag.scope AS scope, ag.name_tiem AS activity_type
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id
        JOIN admin_g    ag ON ag.id = ai.scope
        WHERE ui.year_id = :y AND ui.affiliation_id = :aff AND ui.source = 'survey' AND ag.scope = 3
        ORDER BY ag.order_num ASC, ui.id ASC");
    $stmt->execute([':y' => $year, ':aff' => $affil]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ยอดปล่อยรวม (gross) = การดำเนินงาน + กิจกรรม + แบบสอบถาม (ขอบเขต 3) — ใช้ตัวเดียวกันทุกการ์ด/โดนัท/กราฟ
 *
 * สำคัญ: มุมมองทั้งระบบ ($affil = null) ghg_total() ไม่กรอง source อยู่แล้ว
 *        จึงรวมกิจกรรม (user_item.source='event') มาให้ครบแล้ว — ห้ามบวกซ้ำ
 *        ส่วนรายคณะกรอง source='officer' จึงต้องบวก event เพิ่ม
 */
function ghg_gross_total(PDO $pdo, int $year, ?int $affil = null): float
{
    if ($affil === null) return ghg_total($pdo, $year, null);
    return ghg_total($pdo, $year, $affil) + ghg_event_total($pdo, $year, $affil)
        + array_sum(array_column(survey_emission_list($pdo, $year, $affil), 'emission'));
}

/**
 * ผลรวมของตารางรายคณะ (แถว "รวม" ท้ายตาราง)
 * ต้องบวกจากแถวที่แสดงจริงเท่านั้น — ห้ามใช้ ghg_total() เพราะนั่นรวม survey/event
 * ที่ไม่ได้อยู่ในตาราง ทำให้ยอดรวมไม่ตรงกับที่ผู้อ่านบวกเองได้
 * @param array $rows ผลลัพธ์จาก ghg_by_affiliation()
 */
function ghg_affil_sum(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $r) $sum += (float) ($r['total_emission'] ?? 0);
    return $sum;
}

/**
 * จัดข้อมูลรายการของคณะให้พร้อมวาดโดนัทแยกราย Scope
 * -------------------------------------------------
 * - รวมรายการชื่อซ้ำ "ที่อยู่หมวดย่อยเดียวกัน" เข้าด้วยกัน (คณะอาจกรอกรายการเดียวกันหลายครั้ง)
 *   แต่ถ้าชื่อเดียวกันอยู่คนละหมวดย่อย ห้ามรวม — ตามแนวทาง TGO เชื้อเพลิงชนิดเดียวกัน
 *   มีค่า EF ต่างกันตามหมวด เช่น ดีเซลใน "การเผาไหม้อยู่กับที่" (2.7078) กับ
 *   "การเผาไหม้เคลื่อนที่ On Road" (2.7406) เป็นคนละค่า ถ้ารวมเป็นชิ้นเดียว
 *   คณบดีจะไม่รู้ว่าการปล่อยมาจากหมวดใด และย้อนกลับไปตรวจสอบไม่ได้
 *   → ต่อท้ายชื่อหมวดเฉพาะกรณีที่ชื่อนั้นปรากฏมากกว่า 1 หมวดในขอบเขตเดียวกัน
 *     (ถ้าไม่ซ้ำก็แสดงชื่อเปล่า ๆ ไม่ให้ป้ายโดนัทยาวเกินจำเป็น)
 * - เรียงมาก -> น้อย แล้วยุบรายการที่เกิน $topN เป็น "อื่นๆ" ก้อนเดียว
 *   (โดนัท 30 ชิ้นอ่านไม่ออก แต่ยอดรวมต้องไม่หาย)
 * - ตัดรายการที่ยอด <= 0 ทิ้ง เพราะวาดเป็นชิ้นโดนัทไม่ได้
 * - กิจกรรมที่คณะจัด / แบบสอบถาม (ถ้าส่งมา) เป็นการปล่อยทางอ้อม → ต่อท้ายโดนัทขอบเขต 3 อย่างละ 1 ชิ้น
 *   ไม่นับรวมใน $topN และไม่ถูกยุบเป็น "อื่นๆ" (แหล่งที่มาต่างจากการดำเนินงาน ต้องเห็นเสมอ)
 *   ระบบบังคับให้รายการของทั้งสองแหล่งอยู่ขอบเขต 3 ตอนกรอก (resolve_admin_g … INDIRECT_SCOPE)
 *
 * @param array $detail     ผลลัพธ์จาก ghg_affil_detail()
 * @param int   $topN       จำนวนชิ้นสูงสุดก่อนยุบเป็น "อื่นๆ"
 * @param array $eventRows  ผลจาก event_emission_list()  (ไม่ส่ง = ไม่มีชิ้นกิจกรรม)
 * @param array $surveyRows ผลจาก survey_emission_list() (ไม่ส่ง = ไม่มีชิ้นแบบสอบถาม)
 * @return array [1 => ['items' => [['name'=>..,'value'=>float, 'source'=>'event'|'survey' (เฉพาะชิ้นต่อท้าย)], ...], 'total' => float], 2 => ..., 3 => ...]
 */
/**
 * ย่อชื่อหมวดย่อยสำหรับใช้เป็นป้ายกำกับในโดนัท
 * ตัดวงเล็บภาษาอังกฤษท้ายชื่อออก เพราะป้ายโดนัทกว้างจำกัด
 * เช่น "การเผาไหม้ที่มีการเคลื่อนที่ Off Road (Mobile Combustion : Off Road)"
 *   →  "การเผาไหม้ที่มีการเคลื่อนที่ Off Road"
 * ชื่อเต็มยังอยู่ใน title ของแต่ละบรรทัดในหน้ารายงาน จึงไม่สูญหาย
 */
function ghg_group_label(string $group): string
{
    $short = preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($group));
    return $short !== '' ? $short : $group;
}

function ghg_scope_item_breakdown(array $detail, int $topN = 8, array $eventRows = [], array $surveyRows = []): array
{
    $out = [1 => ['items' => [], 'total' => 0.0], 2 => ['items' => [], 'total' => 0.0], 3 => ['items' => [], 'total' => 0.0]];

    // รอบแรก: หาว่าชื่อรายการใดปรากฏในหลายหมวดย่อยของขอบเขตเดียวกัน (ต้องกำกับหมวดเพื่อแยกให้ออก)
    $groupsOfName = [1 => [], 2 => [], 3 => []];
    foreach ($detail as $r) {
        $s = (int) ($r['scope'] ?? 0);
        if (!isset($groupsOfName[$s])) continue;
        $name = (string) ($r['name_tiem'] ?? '-');
        $grp  = (string) ($r['activity_type'] ?? '');
        $groupsOfName[$s][$name][$grp] = true;
    }

    // รอบสอง: รวมยอดโดยแยกตาม (ชื่อ, หมวดย่อย) แล้วตั้งป้ายกำกับ
    $byScope = [1 => [], 2 => [], 3 => []];
    foreach ($detail as $r) {
        $s = (int) ($r['scope'] ?? 0);
        if (!isset($byScope[$s])) continue;                 // scope นอก 1-3 ไม่ควรมี แต่กันไว้
        $name = (string) ($r['name_tiem'] ?? '-');
        $grp  = (string) ($r['activity_type'] ?? '');
        // ชื่อซ้ำข้ามหมวด → ต่อท้ายชื่อหมวดให้แยกออกจากกัน
        $label = ($grp !== '' && count($groupsOfName[$s][$name] ?? []) > 1)
            ? $name . ' · ' . ghg_group_label($grp)
            : $name;
        $byScope[$s][$label] = ($byScope[$s][$label] ?? 0.0) + (float) ($r['emission'] ?? 0);
    }

    foreach ($byScope as $s => $items) {
        $out[$s]['total'] = array_sum($items);
        $items = array_filter($items, fn($v) => $v > 0);
        arsort($items);

        // ยุบเมื่อเกินอย่างน้อย 2 รายการ — ถ้าเกินแค่ 1 การแสดง "อื่นๆ (1 รายการ)" ไม่ได้ช่วยให้อ่านง่ายขึ้น แสดงรายการนั้นตรง ๆ แทน
        if (count($items) > $topN + 1) {
            $keep  = array_slice($items, 0, $topN, true);
            $restN = count($items) - $topN;
            $rest  = array_sum(array_slice($items, $topN, null, true));
            foreach ($keep as $n => $v) $out[$s]['items'][] = ['name' => $n, 'value' => $v];
            $out[$s]['items'][] = ['name' => "อื่นๆ ($restN รายการ)", 'value' => $rest];
        } else {
            foreach ($items as $n => $v) $out[$s]['items'][] = ['name' => $n, 'value' => $v];
        }
    }

    // ชิ้นต่อท้ายขอบเขต 3: กิจกรรม / แบบสอบถาม อย่างละชิ้น (ยอดรวมของแหล่งนั้นทั้งก้อน)
    foreach (['event' => [$eventRows, 'กิจกรรมที่คณะจัด'], 'survey' => [$surveyRows, 'แบบสอบถาม']] as $src => [$rows, $label]) {
        $v = array_sum(array_map(fn($r) => (float) ($r['emission'] ?? 0), $rows));
        if (!$rows) continue;
        $out[3]['total'] += $v;
        if ($v > 0) $out[3]['items'][] = ['name' => $label, 'value' => $v, 'source' => $src];
    }
    return $out;
}

/**
 * รวมรายการดูดกลับทั้งหมดให้เป็นตารางเดียว สำหรับโดนัท GHG Removal บน dashboard
 * ---------------------------------------------------------------------------
 * ใช้กติกาเดียวกับตาราง TOTAL EMISSION ($year_breakdown ใน admin/index.php):
 *   - รายการดูดกลับส่วนกลาง แถวละ 1 รายการ
 *   - การดูดกลับจากกิจกรรมของทุกคณะ ยุบเหลือแถวเดียวชื่อ "กิจกรรม"
 *   - เรียงมาก -> น้อย
 * ทำให้ % ของทุกแถวรวมกันได้ 100 ของยอดดูดกลับทั้งหมด อ่านเทียบกันได้ในตารางเดียว
 *
 * เดิมแยกเป็น 2 ตาราง (ส่วนกลาง / รายกิจกรรม) โดยตารางกิจกรรมไม่มี % เลย
 * ทำให้เทียบสัดส่วนข้ามกลุ่มไม่ได้ และ % ของตารางส่วนกลางคิดจากยอดส่วนกลาง
 * ไม่ใช่ยอดรวม จึงดูขัดกับตัวเลขบนการ์ด
 *
 * @param array $centralRows  ผลจาก removal_items_list()
 * @param float $activitySum  ผลจาก removal_activity_total()
 * @return array [['name'=>string,'unit'=>string,'factor'=>float,'qty'=>float,
 *                 'emission'=>float,'kind'=>'central'|'event'], ...] เรียงมาก -> น้อย
 */
function removal_breakdown(array $centralRows, float $activitySum): array
{
    $rows = array_map(fn($r) => [
        'name'     => (string) ($r['name_tiem'] ?? '-'),
        'unit'     => (string) ($r['unit'] ?? '-'),
        'factor'   => (float) ($r['factor'] ?? 0),
        'qty'      => (float) ($r['qty'] ?? 0),
        'emission' => (float) ($r['emission'] ?? 0),
        'kind'     => 'central',
    ], $centralRows);

    // แถวรวมของกิจกรรม — ไม่มีหน่วย/ค่าดูดกลับ/ปริมาณ เพราะยุบมาจากหลายรายการหลายหน่วย
    $rows[] = [
        'name'     => 'กิจกรรม',
        'unit'     => '-',
        'factor'   => 0.0,
        'qty'      => 0.0,
        'emission' => $activitySum,
        'kind'     => 'event',
    ];

    usort($rows, fn($a, $b) => $b['emission'] <=> $a['emission']);
    return $rows;
}

/**
 * เพดานสเกลเริ่มต้น (tCO₂e) ของกราฟแหล่งปล่อยตามขอบเขต และกราฟประวัติ ในมุมมองทั้งมหาวิทยาลัย
 * ยอดทั้งมหาวิทยาลัยหลักหมื่น — ถ้าเทียบกับค่าสูงสุดของปีนั้น แท่งปีที่ข้อมูลยังน้อยกับปีที่ข้อมูลครบจะดูไม่สมส่วน
 * ยอดเกินเพดาน → ขยับเพดานทีละ GHG_SYSTEM_SCALE_STEP (ดู ghg_scale_ceiling())
 */
const GHG_SYSTEM_SCALE_MAX  = 15000.0;
const GHG_SYSTEM_SCALE_STEP = 10000.0;
const GHG_SYSTEM_TICK_STEP  = 5000.0;
// มุมมองคณะ: ยอดคณะหลักร้อย → เริ่มเพดาน 1,000 ขยับทีละ 1,000, เส้นสเกลทุก 250
const GHG_FACULTY_SCALE_MAX  = 1000.0;
const GHG_FACULTY_SCALE_STEP = 1000.0;
const GHG_FACULTY_TICK_STEP  = 250.0;

/**
 * เพดานสเกลแบบขั้น: ไม่เกินเพดานเริ่มต้น → ใช้เพดานเริ่มต้น, เกิน → ขยับทีละ $step จนครอบค่าสูงสุด
 * เช่น 10,490 → 15,000 · 15,000 → 15,000 · 18,200 → 25,000 · 25,001 → 35,000
 */
function ghg_scale_ceiling(float $max, float $base = GHG_SYSTEM_SCALE_MAX, float $step = GHG_SYSTEM_SCALE_STEP): float
{
    if ($max <= $base) return $base;
    return $base + ceil(($max - $base) / $step) * $step;
}

/**
 * เส้นบอกสเกลของกราฟประวัติ: ทุก 5,000 ตั้งแต่ 0 ถึงเพดาน (เกิน 7 เส้น → เว้นระยะเป็น 10,000, 20,000, …)
 * เส้นบนสุดคือเพดานเสมอ แม้เพดานไม่ลงตัวกับระยะ (เช่น 35,000 ที่ระยะ 10,000)
 *
 * @return array [['value'=>float, 'pct'=>0-100], ...] เรียง 0 → เพดาน
 */
function ghg_scale_ticks(float $ceiling, float $step = GHG_SYSTEM_TICK_STEP): array
{
    if ($ceiling <= 0 || $step <= 0) return [];
    while ($ceiling / $step > 7) $step *= 2;
    $out = [];
    for ($v = 0.0; $v < $ceiling - 1e-9; $v += $step) $out[] = ['value' => $v, 'pct' => $v / $ceiling * 100];
    $out[] = ['value' => $ceiling, 'pct' => 100.0];
    return $out;
}

/**
 * เพดานสเกลและเส้นบอกสเกลของกราฟ "แหล่งปล่อยตามขอบเขต" + "ประวัติข้อมูลย้อนหลัง" ตามมุมมอง
 * system  → เริ่ม 15,000 ขยับทีละ 10,000 เส้นทุก 5,000
 * faculty → เริ่ม 1,000 ขยับทีละ 1,000 เส้นทุก 250
 * สองกราฟใช้เพดานเดียวกัน → ส่งค่าสูงสุดของทุกปีในกราฟประวัติ (รวมปีที่เลือกแล้ว)
 *
 * @return array ['ceiling'=>float, 'ticks'=>ผลของ ghg_scale_ticks()]
 */
function ghg_view_scale(string $view, float $max): array
{
    [$base, $step, $tick] = $view === 'faculty'
        ? [GHG_FACULTY_SCALE_MAX, GHG_FACULTY_SCALE_STEP, GHG_FACULTY_TICK_STEP]
        : [GHG_SYSTEM_SCALE_MAX, GHG_SYSTEM_SCALE_STEP, GHG_SYSTEM_TICK_STEP];
    $ceiling = ghg_scale_ceiling($max, $base, $step);
    return ['ceiling' => $ceiling, 'ticks' => ghg_scale_ticks($ceiling, $tick)];
}

/**
 * ความยาวแท่ง (%) ของกราฟแท่งแนวนอนรายขอบเขต
 * เทียบกับขอบเขตที่มากที่สุด (ไม่ใช่เทียบยอดรวม) เพื่อให้เห็นความต่างชัด
 * $scaleMax > 0 → ใช้สเกลคงที่แทน (ถ้ามีค่าเกินสเกล ขยายตามค่าจริง แท่งไม่ล้นกรอบ)
 * ถ้าไม่มีข้อมูลเลย คืน 0 ทั้งหมด — ไม่หารด้วยศูนย์
 *
 * @param array $scope    [1=>float, 2=>float, 3=>float]
 * @param float $scaleMax สเกลสูงสุด (0 = ใช้ค่าสูงสุดของข้อมูล)
 * @return array [1=>float, 2=>float, 3=>float] ค่า 0-100
 */
function ghg_scope_bar_percents(array $scope, float $scaleMax = 0.0): array
{
    $vals = [1 => (float) ($scope[1] ?? 0), 2 => (float) ($scope[2] ?? 0), 3 => (float) ($scope[3] ?? 0)];
    $max  = max(max($vals), $scaleMax);
    if ($max <= 0) return [1 => 0.0, 2 => 0.0, 3 => 0.0];
    return array_map(fn($v) => $v > 0 ? $v / $max * 100 : 0.0, $vals);
}

/**
 * ยอดปล่อยรวม (gross) แยกรายขอบเขต = การดำเนินงาน + กิจกรรม
 * ใช้กติกาเดียวกับ ghg_gross_total() : มุมมองทั้งระบบรวมกิจกรรมมาแล้ว ห้ามบวกซ้ำ
 *
 * @return array [1=>float, 2=>float, 3=>float]
 */
function ghg_gross_scope_totals(PDO $pdo, int $year, ?int $affil = null): array
{
    $s = ghg_scope_totals($pdo, $year, $affil);
    if ($affil === null) return $s;
    foreach (event_emission_list($pdo, $year, $affil) as $r) {
        $k = (int) $r['scope'];
        if (isset($s[$k])) $s[$k] += (float) $r['emission'];
    }
    foreach (survey_emission_list($pdo, $year, $affil) as $r) $s[3] += (float) $r['emission'];
    return $s;
}

/**
 * ประวัติย้อนหลังรายปี แยกรายขอบเขต (สำหรับกราฟแท่งแนวตั้ง)
 * เรียงเก่า -> ใหม่ เพื่อให้กราฟไล่จากซ้ายไปขวาตามเวลา
 *
 * @param array $years ผลจาก ghg_years() (ใหม่ -> เก่า)
 * @return array [['year'=>string, 's1'=>float, 's2'=>float, 's3'=>float], ...]
 */
function ghg_scope_history(PDO $pdo, array $years, ?int $affil = null): array
{
    $out = [];
    foreach (array_reverse($years) as $y) {
        $s = ghg_gross_scope_totals($pdo, (int) $y['year_id'], $affil);
        $out[] = ['year' => (string) $y['year'], 's1' => $s[1], 's2' => $s[2], 's3' => $s[3]];
    }
    return $out;
}

function removal_items_list(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare('
        SELECT ri.id, ri.name_tiem, ri.unit, ri.factor,
               ri.qty AS qty,
               ri.qty * ri.factor / 1000 AS emission
        FROM removal_item ri
        WHERE ri.year_id = :y
        ORDER BY ri.id ASC');
    $stmt->execute([':y' => $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}

/**
 * เลือกมุมมองรายงาน: 'system' (ทั้งมหาวิทยาลัย) หรือ 'faculty' (คณะ/หน่วยงานของตัวเอง)
 * ---------------------------------------------------------------------------------
 * ใช้ร่วมกัน 3 หน้า — reports.php (หน้าเว็บ), export_report.php (Excel), report_print.php (PDF)
 * ถ้าแยกกันเขียน 3 ที่ จะเกิดกรณีหน้าเว็บโชว์ "ทั้งระบบ" แต่กดดาวน์โหลดแล้วได้ไฟล์ของคณะตัวเอง
 *
 * ค่าเริ่มต้นต่างกันตาม role:
 *   dean  → 'faculty'  เปิดมาเห็นคณะตัวเองก่อน แล้วสลับไปดูภาพรวมได้
 *   admin → 'system'   ไม่มีคณะสังกัด จึงเริ่มที่ภาพรวม
 *
 * ค่า $requested ที่ไม่ใช่ 'system'/'faculty' (เช่น ?view=xxx) จะถอยไปใช้ค่าเริ่มต้นของ role
 *
 * @param string      $role      $_SESSION['role']
 * @param string|null $requested $_GET['view'] (null = ไม่ได้ระบุ)
 * @return string 'system' | 'faculty'
 */
function ghg_resolve_view(string $role, ?string $requested): string
{
    $default = $role === 'dean' ? 'faculty' : 'system';
    if ($requested === 'faculty' || $requested === 'system') return $requested;
    return $default;
}

/**
 * รวมยอดสรุปของรายงาน (ปล่อย / ดูดกลับ / Net) จากข้อมูลดิบ — ไม่แตะฐานข้อมูล
 * ---------------------------------------------------------------------------
 * เดิมคำนวณซ้ำ 3 ที่ (reports.php / export_report.php / report_print.php)
 * ถ้าแก้สูตรไม่ครบทุกไฟล์ หน้าเว็บกับไฟล์ดาวน์โหลดจะได้ตัวเลขไม่ตรงกัน
 *
 * @param array $operation  ผลจาก ghg_scope_totals() [1=>float, 2=>float, 3=>float]
 * @param array $eventRows  ผลจาก event_emission_list() (มุมมองทั้งระบบส่ง [] เพราะรวมมาแล้ว)
 * @param float $removal    ยอดดูดกลับของมุมมองนั้น
 * @param array $surveyRows ผลจาก survey_emission_list() — นับเข้าขอบเขต 3 เท่านั้น (ทั้งระบบส่ง [] เพราะรวมมาแล้ว)
 * @return array ['operation'=>[1,2,3], 'operation_total'=>float, 'event_total'=>float, 'survey_total'=>float,
 *                'scope'=>[1,2,3] (gross), 'gross'=>float, 'removal'=>float, 'net'=>float]
 */
function ghg_combine_totals(array $operation, array $eventRows, float $removal, array $surveyRows = []): array
{
    $op = [1 => (float) ($operation[1] ?? 0), 2 => (float) ($operation[2] ?? 0), 3 => (float) ($operation[3] ?? 0)];
    $scope = $op;
    $eventTotal = 0.0;
    foreach ($eventRows as $r) {
        $e = (float) ($r['emission'] ?? 0);
        $eventTotal += $e;
        $s = (int) ($r['scope'] ?? 0);
        if (isset($scope[$s])) $scope[$s] += $e;
    }
    $surveyTotal = 0.0;
    foreach ($surveyRows as $r) {
        if ((int) ($r['scope'] ?? 0) !== 3) continue;
        $surveyTotal += (float) ($r['emission'] ?? 0);
    }
    $scope[3] += $surveyTotal;
    $gross = $scope[1] + $scope[2] + $scope[3];
    return [
        'operation'       => $op,
        'operation_total' => $op[1] + $op[2] + $op[3],
        'event_total'     => $eventTotal,
        'survey_total'    => $surveyTotal,
        'scope'           => $scope,
        'gross'           => $gross,
        'removal'         => $removal,
        'net'             => $gross - $removal,
    ];
}

/**
 * ยอดสรุปของรายงานตามมุมมอง — ใช้ร่วมกันทั้งหน้าเว็บ, Excel และ PDF
 * $affil = null → ทั้งมหาวิทยาลัย (ghg_scope_totals ไม่กรอง source จึงรวมกิจกรรมมาแล้ว ห้ามบวกซ้ำ)
 * $affil = id   → คณะ (การดำเนินงาน + กิจกรรม + แบบสอบถามขอบเขต 3 ของคณะ, ดูดกลับจากกิจกรรมของคณะ)
 *
 * @return array ผลของ ghg_combine_totals() + 'event_rows', 'survey_rows', 'removal_rows'
 */
function ghg_report_summary(PDO $pdo, int $year, ?int $affil): array
{
    $eventRows   = $affil !== null ? event_emission_list($pdo, $year, $affil) : [];
    $surveyRows  = $affil !== null ? survey_emission_list($pdo, $year, $affil) : [];
    $removalRows = $affil !== null ? removal_activity_list($pdo, $year, $affil) : [];
    $removal     = $affil !== null ? removal_activity_total($pdo, $year, $affil) : removal_total($pdo, $year);

    $sum = ghg_combine_totals(ghg_scope_totals($pdo, $year, $affil), $eventRows, $removal, $surveyRows);
    $sum['event_rows']   = $eventRows;
    $sum['survey_rows']  = $surveyRows;
    $sum['removal_rows'] = $removalRows;
    return $sum;
}

/**
 * ปีก่อนหน้าของปีที่เลือก (ใช้เปรียบเทียบในบทสรุปผู้บริหาร)
 * เลือกจาก "ปี พ.ศ. ที่น้อยกว่าและใกล้ที่สุด" ไม่ใช่ year_id - 1 เพราะ id ไม่เรียงต่อกัน (14, 15, 25)
 *
 * @param array $years ผลจาก ghg_years()
 * @return array|null ['year_id'=>.., 'year'=>..] หรือ null ถ้าไม่มีปีก่อนหน้า/ไม่พบปีที่เลือก
 */
function ghg_prev_year(array $years, int $yearId): ?array
{
    $cur = null;
    foreach ($years as $y) if ((int) $y['year_id'] === $yearId) { $cur = (int) $y['year']; break; }
    if ($cur === null) return null;

    $prev = null;
    foreach ($years as $y) {
        $yy = (int) $y['year'];
        if ($yy < $cur && ($prev === null || $yy > (int) $prev['year'])) $prev = $y;
    }
    return $prev;
}

/**
 * การเปลี่ยนแปลงเทียบปีก่อน
 * - ไม่มีปีก่อน ($prev = null)  → diff/pct = null
 * - ปีก่อนเป็น 0               → มี diff แต่ pct = null (หารด้วยศูนย์ไม่ได้ ไม่ใช่ "เพิ่ม 100%")
 * - หารด้วย |prev| เพื่อให้ Net ที่ติดลบยังบอกทิศทางถูก
 *
 * @return array ['diff'=>?float, 'pct'=>?float]
 */
function ghg_change(float $cur, ?float $prev): array
{
    if ($prev === null) return ['diff' => null, 'pct' => null];
    $diff = $cur - $prev;
    $pct  = abs($prev) < 1e-9 ? null : $diff / abs($prev) * 100;
    return ['diff' => $diff, 'pct' => $pct];
}

/**
 * หาค่าที่มากที่สุดและสัดส่วนต่อผลรวม (เช่น ขอบเขตหลัก / หมวดที่ปล่อยมากสุด)
 * ค่าเท่ากัน → เลือกตัวที่มาก่อน, ผลรวม <= 0 → null (ยังไม่มีข้อมูล ไม่มี "แหล่งหลัก")
 *
 * @param array $values [key => float]
 * @return array|null ['key'=>mixed, 'value'=>float, 'pct'=>float]
 */
function ghg_top_share(array $values): ?array
{
    $total = 0.0;
    $top   = null;
    foreach ($values as $k => $v) {
        $v = (float) $v;
        $total += $v;
        if ($top === null || $v > $top['value']) $top = ['key' => $k, 'value' => $v];
    }
    if ($top === null || $total <= 0 || $top['value'] <= 0) return null;
    $top['pct'] = $top['value'] / $total * 100;
    return $top;
}

/**
 * ยอดปล่อยแยกตามหมวดแหล่งปล่อย (admin_g) — ตาราง "แหล่งปล่อย × ขอบเขต" ในรายงาน PDF
 * คืนครบทุกหมวดแม้ยอดเป็น 0 เพื่อให้รายงานบอกได้ว่าหมวดใดอยู่ในขอบเขตการประเมิน
 * ใช้นิยามเดียวกับ ghg_gross_scope_totals(): คณะ = officer + กิจกรรม + แบบสอบถาม (ขอบเขต 3) / ทั้งระบบ = ทุก source (ไม่บวกซ้ำ)
 *
 * @return array [['id'=>int, 'scope'=>int, 'name'=>string, 'value'=>float], ...] เรียงตามขอบเขต → order_num
 */
function ghg_category_totals(PDO $pdo, int $year, ?int $affil = null): array
{
    $affilCond = $affil !== null ? " AND ui.affiliation_id = :aff AND ui.source = 'officer'" : '';
    $stmt = $pdo->prepare("
        SELECT ag.id, ag.scope, ag.name_tiem, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS e
        FROM admin_g ag
        LEFT JOIN admin_item ai ON ai.scope = ag.id AND ai.year_id = :y1
        LEFT JOIN user_item  ui ON ui.admin_item_id = ai.id AND ui.year_id = :y2 $affilCond
        GROUP BY ag.id, ag.scope, ag.name_tiem, ag.order_num
        ORDER BY ag.scope, ag.order_num, ag.id
    ");
    $params = [':y1' => $year, ':y2' => $year];
    if ($affil !== null) $params[':aff'] = $affil;
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['id']] = ['id' => (int) $r['id'], 'scope' => (int) $r['scope'], 'name' => (string) $r['name_tiem'], 'value' => (float) $r['e']];
    }

    if ($affil !== null) {
        // กิจกรรมของคณะ — เงื่อนไขเดียวกับ event_emission_list()
        $ev = $pdo->prepare('
            SELECT ai.scope AS gid, COALESCE(SUM(ei.Vol * ai.AD)/1000, 0)
            FROM event_item ei
            JOIN event e       ON e.id  = ei.event_id
            JOIN admin_item ai ON ai.id = ei.admin_item_id
            WHERE e.year_id = :y AND e.affiliation_id = :aff
            GROUP BY ai.scope');
        $ev->execute([':y' => $year, ':aff' => $affil]);
        foreach ($ev->fetchAll(PDO::FETCH_KEY_PAIR) as $gid => $v) {
            if (isset($out[(int) $gid])) $out[(int) $gid]['value'] += (float) $v;
        }
        // แบบสอบถามของคณะ — เงื่อนไขเดียวกับ survey_emission_list() (ขอบเขต 3 เท่านั้น)
        $sv = $pdo->prepare("
            SELECT ai.scope AS gid, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)
            FROM user_item ui
            JOIN admin_item ai ON ai.id = ui.admin_item_id
            JOIN admin_g    ag ON ag.id = ai.scope
            WHERE ui.year_id = :y AND ui.affiliation_id = :aff AND ui.source = 'survey' AND ag.scope = 3
            GROUP BY ai.scope");
        $sv->execute([':y' => $year, ':aff' => $affil]);
        foreach ($sv->fetchAll(PDO::FETCH_KEY_PAIR) as $gid => $v) {
            if (isset($out[(int) $gid])) $out[(int) $gid]['value'] += (float) $v;
        }
    }
    return array_values($out);
}

/**
 * จำนวนรายการการดำเนินงาน (user_item source='officer') ที่บันทึกในปีนั้น
 * ใช้วัด "ความครบของข้อมูล" ก่อนเปรียบเทียบปีต่อปี
 */
function ghg_entry_count(PDO $pdo, int $year, ?int $affil = null): int
{
    $sql = "SELECT COUNT(*) FROM user_item WHERE year_id = :y AND source = 'officer'";
    $params = [':y' => $year];
    if ($affil !== null) { $sql .= ' AND affiliation_id = :aff'; $params[':aff'] = $affil; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * สถานะการเปรียบเทียบกับปีก่อน — กัน % เปลี่ยนแปลงหลักพัน/หมื่นจากการเทียบกับปีที่ยังไม่มีข้อมูล
 * -----------------------------------------------------------------------------------
 * 'none'   ไม่มีปีก่อนหน้าในระบบ
 * 'nodata' ปีก่อนไม่มีรายการการดำเนินงานเลย → ไม่เปรียบเทียบ (เช่น 2568 มีแค่กิจกรรม/แบบสอบถามไม่กี่แถว)
 * 'low'    ปีก่อนมีรายการน้อยกว่าครึ่งของปีนี้ → เทียบได้ แต่ต้องเตือนว่าข้อมูลไม่ครบเท่ากัน
 * 'ok'     เปรียบเทียบได้ตามปกติ
 */
function ghg_compare_state(bool $hasPrevYear, int $curCount, int $prevCount): string
{
    if (!$hasPrevYear) return 'none';
    if ($prevCount <= 0) return 'nodata';
    if ($prevCount < $curCount * 0.5) return 'low';
    return 'ok';
}

/**
 * ข้อมูลเปรียบเทียบกับปีก่อนหน้า (ใช้ร่วมกันทั้งหน้าเว็บและ PDF)
 * 'prev' = ยอดสรุปของปีก่อน เฉพาะเมื่อ state เป็น ok/low เท่านั้น (อื่น ๆ = null → ไม่แสดง %)
 *
 * @return array ['prev_year'=>?array, 'prev'=>?array, 'state'=>string, 'cur_count'=>int, 'prev_count'=>int]
 */
function ghg_year_comparison(PDO $pdo, array $years, int $year, ?int $affil): array
{
    $prevYear  = ghg_prev_year($years, $year);
    $curCount  = ghg_entry_count($pdo, $year, $affil);
    $prevCount = $prevYear ? ghg_entry_count($pdo, (int) $prevYear['year_id'], $affil) : 0;
    $state     = ghg_compare_state($prevYear !== null, $curCount, $prevCount);
    $prev      = in_array($state, ['ok', 'low'], true) ? ghg_report_summary($pdo, (int) $prevYear['year_id'], $affil) : null;
    return ['prev_year' => $prevYear, 'prev' => $prev, 'state' => $state, 'cur_count' => $curCount, 'prev_count' => $prevCount];
}

/**
 * ปีสำหรับกราฟประวัติย้อนหลัง = ปีที่เลือก + ย้อนหลัง $back ปี (ตามปี พ.ศ. ไม่ใช่ year_id)
 * ไม่รวมปีที่ใหม่กว่าปีที่เลือก — ดูรายงานปี 2568 ต้องไม่เห็นข้อมูลปี 2569
 *
 * @param array $years ผลจาก ghg_years()
 * @return array ปีที่เลือกและปีย้อนหลัง เรียงใหม่ -> เก่า (รูปแบบเดียวกับ ghg_years() ส่งต่อให้ ghg_scope_history() ได้เลย)
 *               ไม่พบปีที่เลือก → []
 */
function ghg_history_years(array $years, int $yearId, int $back = 1): array
{
    $cur = null;
    foreach ($years as $y) if ((int) $y['year_id'] === $yearId) { $cur = (int) $y['year']; break; }
    if ($cur === null) return [];

    $out = array_values(array_filter($years, fn($y) => (int) $y['year'] <= $cur));
    usort($out, fn($a, $b) => (int) $b['year'] <=> (int) $a['year']);
    return array_slice($out, 0, $back + 1);
}

/**
 * ป้ายการเปลี่ยนแปลงเทียบปีก่อน (ข้อความ + โทนสี) — ใช้ร่วมกันหน้าเว็บ/PDF
 * $upGood = ค่าเพิ่มขึ้นเป็นเรื่องดีหรือไม่ (ดูดกลับ = ดี / ปล่อย, Net = ไม่ดี)
 *
 * @return array ['text'=>string, 'tone'=>'good'|'bad'|'flat']
 */
function ghg_change_badge(float $cur, ?float $prev, bool $upGood = false): array
{
    $c = ghg_change($cur, $prev);
    if ($c['pct'] === null) return ['text' => '—', 'tone' => 'flat'];
    if (abs($c['pct']) < 0.05) return ['text' => 'คงที่', 'tone' => 'flat'];
    $up = $c['pct'] > 0;
    return [
        'text' => ($up ? '▲ +' : '▼ −') . number_format(abs($c['pct']), 1) . '%',
        'tone' => $up === $upGood ? 'good' : 'bad',
    ];
}

/**
 * สัดส่วนที่การดูดกลับชดเชยการปล่อยได้ (%) — ใช้แสดงความคืบหน้าสู่ Net Zero
 * 100% = Net เป็นศูนย์พอดี, เกิน 100% = ดูดกลับมากกว่าปล่อย (Net ติดลบ) — คืนค่าจริงไม่ตัดทิ้ง ให้หน้าจอเลือกวิธีแสดงเอง
 * ไม่มีการปล่อย → null (ยังไม่มีอะไรให้ชดเชย ไม่ใช่ "ชดเชยได้ 0%")
 */
function ghg_offset_pct(float $gross, float $removal): ?float
{
    if ($gross <= 0) return null;
    return max(0.0, $removal) / $gross * 100;
}

/**
 * จัดหมวดแหล่งปล่อยเป็นกลุ่มตามขอบเขต พร้อมยอดรวมของแต่ละขอบเขต (ตารางผลการคำนวณใน PDF)
 * @param array $categories ผลจาก ghg_category_totals()
 * @return array [1 => ['total'=>float, 'items'=>[...]], 2 => ..., 3 => ...] ครบ 3 ขอบเขตเสมอ
 */
function ghg_categories_by_scope(array $categories): array
{
    $out = [1 => ['total' => 0.0, 'items' => []], 2 => ['total' => 0.0, 'items' => []], 3 => ['total' => 0.0, 'items' => []]];
    foreach ($categories as $c) {
        $s = (int) ($c['scope'] ?? 0);
        if (!isset($out[$s])) continue;
        $out[$s]['items'][] = $c;
        $out[$s]['total']  += (float) ($c['value'] ?? 0);
    }
    return $out;
}

/**
 * ความสูงแท่ง (%) ของกราฟประวัติแบบ HTML — ทุกปีใช้สเกลเดียวกัน (เทียบกับค่าสูงสุดของทุกปีทุกขอบเขต)
 * $scaleMax > 0 → ใช้สเกลคงที่แทน (ถ้ามีค่าเกินสเกล ขยายตามค่าจริง แท่งไม่ล้นกรอบ)
 * ไม่มีข้อมูลเลย → 0 ทั้งหมด (ไม่หารด้วยศูนย์)
 *
 * @param array $history  ผลจาก ghg_scope_history()
 * @param float $scaleMax สเกลสูงสุด (0 = ใช้ค่าสูงสุดของข้อมูล)
 * @return array [['year'=>string, 's1'=>float, 's2'=>float, 's3'=>float, 'h1'=>0-100, 'h2'=>.., 'h3'=>.., 'total'=>float], ...]
 */
function ghg_history_bars(array $history, float $scaleMax = 0.0): array
{
    $max = $scaleMax;
    foreach ($history as $h) $max = max($max, (float) $h['s1'], (float) $h['s2'], (float) $h['s3']);
    return array_map(function ($h) use ($max) {
        $row = ['year' => (string) $h['year'], 'total' => 0.0];
        foreach ([1, 2, 3] as $s) {
            $v = (float) $h['s' . $s];
            $row['s' . $s] = $v;
            $row['h' . $s] = $max > 0 ? $v / $max * 100 : 0.0;
            $row['total'] += $v;
        }
        return $row;
    }, $history);
}

/**
 * แถวของตารางบทสรุป (ใช้ร่วมกัน PDF / Excel ให้ลำดับและคำเรียกตรงกัน)
 * ไม่มีแถวขอบเขต 1/2/3 — รายงานแสดงยอดรายขอบเขตในส่วน "ผลการคำนวณแยกตามแหล่งปล่อย" แล้ว
 *
 * @param string $view 'faculty' | 'system'
 * @return array [['label'=>string, 'desc'=>string, 'kind'=>'strong'|'sub'|'total'|'', 'key'=>คีย์ใน ghg_report_summary(),
 *                 'up_good'=>bool|null (null = ไม่แสดงการเปลี่ยนแปลง)], ...]
 */
function ghg_summary_lines(string $view): array
{
    $lines   = [['label' => 'รวมการปล่อยทั้งหมด', 'desc' => '', 'kind' => 'strong', 'key' => 'gross', 'up_good' => false]];
    if ($view === 'faculty') {
        $lines[] = ['label' => 'จากการดำเนินงานของคณะ', 'desc' => '', 'kind' => 'sub', 'key' => 'operation_total', 'up_good' => null];
        $lines[] = ['label' => 'จากกิจกรรมที่คณะจัด',   'desc' => '', 'kind' => 'sub', 'key' => 'event_total',     'up_good' => null];
        $lines[] = ['label' => 'จากแบบสอบถาม',        'desc' => '', 'kind' => 'sub', 'key' => 'survey_total', 'up_good' => null];
    }
    $lines[] = ['label' => 'การดูดกลับ', 'desc' => $view === 'faculty' ? 'จากกิจกรรมของคณะ' : 'ระดับมหาวิทยาลัยและกิจกรรม',
                'kind' => '', 'key' => 'removal', 'up_good' => true];
    $lines[] = ['label' => 'การปล่อยสุทธิ (Net = ปล่อยทั้งหมด − ดูดกลับ)', 'desc' => '', 'kind' => 'total', 'key' => 'net', 'up_good' => false];
    return $lines;
}

/**
 * รายการที่กรอกทั้งหมดของคณะ จัดกลุ่มตามขอบเขต (แผ่นงาน "รายการ" ใน Excel)
 * รวมรายการจากกิจกรรมและแบบสอบถามไว้ในขอบเขตเดียวกัน → ยอดรวมแต่ละกลุ่ม = ยอดรายขอบเขตของ ghg_report_summary()
 *
 * @param array $operationRows ผลจาก ghg_affil_detail()   (เรียงขอบเขต → หมวด → ชื่อ อยู่แล้ว)
 * @param array $eventRows     ผลจาก event_emission_list()
 * @param array $surveyRows    ผลจาก survey_emission_list() (ขอบเขต 3 เท่านั้น)
 * @return array [1 => ['total'=>float, 'rows'=>[['source'=>'officer'|'event'|'survey', 'event'=>string, 'category'=>string,
 *                'name'=>string, 'unit'=>string, 'qty'=>float, 'emission'=>float], ...]], 2 => ..., 3 => ...]
 */
function ghg_detail_by_scope(array $operationRows, array $eventRows, array $surveyRows = []): array
{
    $out = [1 => ['total' => 0.0, 'rows' => []], 2 => ['total' => 0.0, 'rows' => []], 3 => ['total' => 0.0, 'rows' => []]];
    $add = function (array $r, string $source, float $qty, string $event) use (&$out) {
        $s = (int) ($r['scope'] ?? 0);
        if (!isset($out[$s])) return;
        $e = (float) ($r['emission'] ?? 0);
        $out[$s]['rows'][] = [
            'source' => $source, 'event' => $event, 'category' => ghg_group_label((string) ($r['activity_type'] ?? '')),
            'name' => (string) ($r['name_tiem'] ?? '-'), 'unit' => (string) ($r['unit'] ?? '-'), 'qty' => $qty, 'emission' => $e,
        ];
        $out[$s]['total'] += $e;
    };
    foreach ($operationRows as $r) $add($r, 'officer', (float) ($r['vol'] ?? 0), '');
    foreach ($eventRows as $r)     $add($r, 'event', (float) ($r['qty'] ?? 0), (string) ($r['event_name'] ?? ''));
    foreach ($surveyRows as $r)    if ((int) ($r['scope'] ?? 0) === 3) $add($r, 'survey', (float) ($r['qty'] ?? 0), '');
    return $out;
}

/**
 * ยอดปล่อยรายหน่วยงาน แยกรายขอบเขต (เฉพาะการดำเนินงาน — นิยามเดียวกับ ghg_by_affiliation())
 * @return array [affiliation_id => [1=>float, 2=>float, 3=>float]] เฉพาะหน่วยงานที่มีข้อมูล
 */
function ghg_scope_by_affiliation(PDO $pdo, int $year): array
{
    $stmt = $pdo->prepare("
        SELECT ui.affiliation_id AS aff, ag.scope, SUM(ui.Vol * ai.AD)/1000 AS e
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id
        JOIN admin_g    ag ON ag.id = ai.scope
        WHERE ui.year_id = :y AND ui.source = 'officer'
        GROUP BY ui.affiliation_id, ag.scope");
    $stmt->execute([':y' => $year]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = (int) $r['aff'];
        $out[$a] ??= [1 => 0.0, 2 => 0.0, 3 => 0.0];
        if (isset($out[$a][(int) $r['scope']])) $out[$a][(int) $r['scope']] += (float) $r['e'];
    }
    return $out;
}

/**
 * อันดับการปล่อยรายหน่วยงาน สำหรับหน้ารายงานมุมมองทั้งมหาวิทยาลัย (ไม่แตะฐานข้อมูล)
 * - ตัดหน่วยงานที่ยังไม่มีข้อมูล, เรียงมาก → น้อย, อันดับเท่ากันเมื่อยอดเท่ากันไม่จำเป็น (ใช้ลำดับจริง)
 * - bar = ความยาวแถบเทียบกับหน่วยงานที่ปล่อยมากที่สุด (0–100)
 * - scope_pct = สัดส่วนขอบเขต 1/2/3 ภายในหน่วยงานนั้น (รวมกัน 100) ใช้แบ่งสีในแถบ
 *
 * @param array $byAffil      ผลจาก ghg_by_affiliation()
 * @param array $scopeByAffil ผลจาก ghg_scope_by_affiliation()
 * @return array [['rank'=>int, 'affil_id'=>int, 'name'=>string, 'total'=>float, 'share'=>float (% ของทุกหน่วยงาน),
 *                 'bar'=>float, 'scope_pct'=>[1=>..,2=>..,3=>..]], ...]
 */
function ghg_affiliation_ranking(array $byAffil, array $scopeByAffil): array
{
    $rows = array_values(array_filter($byAffil, fn($r) => (float) ($r['total_emission'] ?? 0) > 0));
    usort($rows, fn($a, $b) => (float) $b['total_emission'] <=> (float) $a['total_emission']);
    $sum = ghg_affil_sum($rows);
    $max = $rows ? (float) $rows[0]['total_emission'] : 0.0;

    $out = [];
    foreach ($rows as $i => $r) {
        $t  = (float) $r['total_emission'];
        $sc = $scopeByAffil[(int) $r['affil_id']] ?? [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $st = $sc[1] + $sc[2] + $sc[3];
        $out[] = [
            'rank'      => $i + 1,
            'affil_id'  => (int) $r['affil_id'],
            'name'      => (string) $r['affiliation_item'],
            'total'     => $t,
            'share'     => $sum > 0 ? $t / $sum * 100 : 0.0,
            'bar'       => $max > 0 ? $t / $max * 100 : 0.0,
            'scope_pct' => array_map(fn($v) => $st > 0 ? $v / $st * 100 : 0.0, $sc),
        ];
    }
    return $out;
}

/**
 * แถวของหน้าต่างรายละเอียดบน Dashboard คณบดี — รวมรายการจากกิจกรรม และจากแบบสอบถาม อย่างละแถวเดียว (ไม่แตะฐานข้อมูล)
 * - รายการการดำเนินงานคงเดิมตามลำดับ ต่อท้ายด้วยแถว "กิจกรรมที่คณะจัด (รวมทุกกิจกรรม)" แล้ว "แบบสอบถาม (รวมทุกชุด)"
 * - vol = null (ต่างหน่วยกัน รวมปริมาณไม่ได้), emission = ผลรวม → ผลรวมทั้งตารางเท่าเดิม
 * - แหล่งใดไม่มีรายการ → ไม่เพิ่มแถวของแหล่งนั้น
 * - ใช้ได้ทั้งหน้าต่างรวมและรายขอบเขต (กรองขอบเขตก่อนส่งเข้ามา) — แถวรวมใช้ขอบเขตของรายการที่รวม ถ้าปนหลายขอบเขตเป็น 0
 *
 * @param array $items แถว ['scope', 'name', 'unit', 'vol', 'emission', 'source'=>'officer'|'event'|'survey', 'event'=>ชื่องาน]
 * @return array
 */
function ghg_detail_collapse_events(array $items): array
{
    $labels = ['event' => 'กิจกรรมที่คณะจัด (รวมทุกกิจกรรม)', 'survey' => 'แบบสอบถาม (รวมทุกชุด)'];
    $out = []; $group = [];
    foreach ($items as $it) {
        $src = (string) ($it['source'] ?? '');
        if (!isset($labels[$src])) { $out[] = $it; continue; }
        $group[$src] ??= ['sum' => 0.0, 'scopes' => []];
        $group[$src]['sum'] += (float) ($it['emission'] ?? 0);
        $group[$src]['scopes'][(int) ($it['scope'] ?? 0)] = true;
    }
    foreach ($labels as $src => $name) {
        if (!isset($group[$src])) continue;
        $scopes = array_keys($group[$src]['scopes']);
        $out[] = ['scope' => count($scopes) === 1 ? $scopes[0] : 0, 'name' => $name, 'unit' => '-', 'vol' => null,
                  'emission' => $group[$src]['sum'], 'source' => $src . '_total'];
    }
    return $out;
}

/**
 * ความครบถ้วนของการกรอกข้อมูลการดำเนินงาน รายขอบเขต (การ์ดขอบเขตบน Dashboard คณบดี — ไม่แตะฐานข้อมูล)
 * - นับเฉพาะรายการ source 'officer' (รายการในแม่บทที่คณะต้องกรอก) — รายการจากกิจกรรมไม่นับ
 * - filled = รายการที่กรอกปริมาณมากกว่า 0, total = รายการทั้งหมดในแม่บทของขอบเขตนั้น
 *
 * @param array $items แถว ['scope'=>int, 'vol'=>float, 'source'=>'officer'|'event', ...]
 * @return array [1=>['filled'=>int,'total'=>int], 2=>..., 3=>...] ครบ 3 ขอบเขตเสมอ
 */
function ghg_item_fill(array $items): array
{
    $out = [1 => ['filled' => 0, 'total' => 0], 2 => ['filled' => 0, 'total' => 0], 3 => ['filled' => 0, 'total' => 0]];
    foreach ($items as $it) {
        $s = (int) ($it['scope'] ?? 0);
        if (!isset($out[$s]) || ($it['source'] ?? '') !== 'officer') continue;
        $out[$s]['total']++;
        if ((float) ($it['vol'] ?? 0) > 0) $out[$s]['filled']++;
    }
    return $out;
}
