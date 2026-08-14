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
               ag.scope AS scope, \'emit\' AS itype,
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
 * ยอดปล่อยรวม (gross) = การดำเนินงาน + กิจกรรม — ใช้ตัวเดียวกันทุกการ์ด/โดนัท/กราฟ
 *
 * สำคัญ: มุมมองทั้งระบบ ($affil = null) ghg_total() ไม่กรอง source อยู่แล้ว
 *        จึงรวมกิจกรรม (user_item.source='event') มาให้ครบแล้ว — ห้ามบวกซ้ำ
 *        ส่วนรายคณะกรอง source='officer' จึงต้องบวก event เพิ่ม
 */
function ghg_gross_total(PDO $pdo, int $year, ?int $affil = null): float
{
    if ($affil === null) return ghg_total($pdo, $year, null);
    return ghg_total($pdo, $year, $affil) + ghg_event_total($pdo, $year, $affil);
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
 * - รวมรายการชื่อซ้ำเข้าด้วยกัน (คณะอาจกรอกรายการเดียวกันหลายครั้ง)
 * - เรียงมาก -> น้อย แล้วยุบรายการที่เกิน $topN เป็น "อื่นๆ" ก้อนเดียว
 *   (โดนัท 30 ชิ้นอ่านไม่ออก แต่ยอดรวมต้องไม่หาย)
 * - ตัดรายการที่ยอด <= 0 ทิ้ง เพราะวาดเป็นชิ้นโดนัทไม่ได้
 *
 * @param array $detail ผลลัพธ์จาก ghg_affil_detail()
 * @param int   $topN   จำนวนชิ้นสูงสุดก่อนยุบเป็น "อื่นๆ"
 * @return array [1 => ['items' => [['name'=>..,'value'=>float], ...], 'total' => float], 2 => ..., 3 => ...]
 */
function ghg_scope_item_breakdown(array $detail, int $topN = 8): array
{
    $out = [1 => ['items' => [], 'total' => 0.0], 2 => ['items' => [], 'total' => 0.0], 3 => ['items' => [], 'total' => 0.0]];

    $byScope = [1 => [], 2 => [], 3 => []];
    foreach ($detail as $r) {
        $s = (int) ($r['scope'] ?? 0);
        if (!isset($byScope[$s])) continue;                 // scope นอก 1-3 ไม่ควรมี แต่กันไว้
        $name = (string) ($r['name_tiem'] ?? '-');
        $byScope[$s][$name] = ($byScope[$s][$name] ?? 0.0) + (float) ($r['emission'] ?? 0);
    }

    foreach ($byScope as $s => $items) {
        $out[$s]['total'] = array_sum($items);
        $items = array_filter($items, fn($v) => $v > 0);
        arsort($items);

        if (count($items) > $topN) {
            $keep  = array_slice($items, 0, $topN, true);
            $restN = count($items) - $topN;
            $rest  = array_sum(array_slice($items, $topN, null, true));
            foreach ($keep as $n => $v) $out[$s]['items'][] = ['name' => $n, 'value' => $v];
            $out[$s]['items'][] = ['name' => "อื่นๆ ($restN รายการ)", 'value' => $rest];
        } else {
            foreach ($items as $n => $v) $out[$s]['items'][] = ['name' => $n, 'value' => $v];
        }
    }
    return $out;
}

/**
 * รวมรายการปล่อย + ดูดกลับ ของกิจกรรม ให้เป็น "การ์ดต่อ 1 กิจกรรม"
 * ------------------------------------------------------------------
 * กิจกรรมหนึ่งอาจมีเฉพาะการปล่อย หรือเฉพาะการดูดกลับ อย่างใดอย่างหนึ่ง
 * จึงต้อง union จากทั้งสองรายการ ไม่ใช่วนจากฝั่งใดฝั่งเดียว (ไม่งั้นกิจกรรมหาย)
 *
 * @param array $emitRows    ผลจาก event_emission_list()
 * @param array $removalRows ผลจาก removal_activity_list()
 * @return array [['event_id'=>int,'event_name'=>string,'event_date'=>?string,'event_end_date'=>?string,
 *                 'emit'=>[...], 'removal'=>[...], 'emit_total'=>float,'removal_total'=>float,'net'=>float], ...]
 *               เรียงตามวันที่จัด (ใหม่ -> เก่า)
 */
function ghg_event_cards(array $emitRows, array $removalRows): array
{
    $cards = [];

    $touch = function (array $r) use (&$cards): int {
        $id = (int) ($r['event_id'] ?? 0);
        if (!isset($cards[$id])) {
            $cards[$id] = [
                'event_id'       => $id,
                'event_name'     => (string) ($r['event_name'] ?? '-'),
                'event_date'     => $r['event_date'] ?? null,
                'event_end_date' => $r['event_end_date'] ?? null,
                'emit'           => [],
                'removal'        => [],
                'emit_total'     => 0.0,
                'removal_total'  => 0.0,
                'net'            => 0.0,
            ];
        }
        return $id;
    };

    foreach ($emitRows as $r) {
        $id = $touch($r);
        $cards[$id]['emit'][]    = $r;
        $cards[$id]['emit_total'] += (float) ($r['emission'] ?? 0);
    }
    foreach ($removalRows as $r) {
        $id = $touch($r);
        $cards[$id]['removal'][]     = $r;
        $cards[$id]['removal_total'] += (float) ($r['emission'] ?? 0);
    }
    foreach ($cards as &$c) $c['net'] = $c['emit_total'] - $c['removal_total'];
    unset($c);

    // ใหม่ -> เก่า; กิจกรรมที่ไม่ระบุวันที่ให้ไปท้ายสุด แล้วเรียงตาม id
    uasort($cards, function ($a, $b) {
        $da = $a['event_date'] ?: '';
        $db = $b['event_date'] ?: '';
        if ($da !== $db) return strcmp($db, $da);
        return $b['event_id'] <=> $a['event_id'];
    });
    return array_values($cards);
}

/**
 * ความยาวแท่ง (%) ของกราฟแท่งแนวนอนรายขอบเขต
 * เทียบกับขอบเขตที่มากที่สุด (ไม่ใช่เทียบยอดรวม) เพื่อให้เห็นความต่างชัด
 * ถ้าไม่มีข้อมูลเลย คืน 0 ทั้งหมด — ไม่หารด้วยศูนย์
 *
 * @param array $scope [1=>float, 2=>float, 3=>float]
 * @return array [1=>float, 2=>float, 3=>float] ค่า 0-100
 */
function ghg_scope_bar_percents(array $scope): array
{
    $vals = [1 => (float) ($scope[1] ?? 0), 2 => (float) ($scope[2] ?? 0), 3 => (float) ($scope[3] ?? 0)];
    $max  = max($vals);
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