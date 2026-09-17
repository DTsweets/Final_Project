<?php
/**
 * ข้อมูลของ Dashboard admin (admin/index.php) — ทั้งมหาวิทยาลัย
 * ------------------------------------------------------------------
 * ยอดหลักมาจาก ghg_report_summary($pdo, $year, null) ตัวเดียวกับหน้ารายงาน GHG / Excel / PDF / Dashboard คณบดี
 * (เดิมหน้านี้เขียน SQL เองแยกอีกชุด ~180 บรรทัด)
 * ฟังก์ชันในไฟล์นี้จัดข้อมูลสำหรับหน้าต่างรายละเอียด โดยผลรวมต้องเท่ากับยอดหลักเสมอ (มีเทสต์ล็อกไว้)
 */

require_once __DIR__ . '/ghg_report.php';

/** ชื่อและสีขอบเขต — ชุดเดียวกับหน้ากรอกข้อมูล / Dashboard คณบดี (deep = สีเข้มสำหรับหัวหน้าต่าง) */
function admin_dash_scope_meta(): array
{
    return [
        1 => ['name' => 'การปล่อยโดยตรง',       'color' => '#F97316', 'deep' => '#C2410C'],
        2 => ['name' => 'พลังงานไฟฟ้าที่ซื้อ',     'color' => '#EC4899', 'deep' => '#BE185D'],
        3 => ['name' => 'การปล่อยทางอ้อมอื่น ๆ', 'color' => '#3B82F6', 'deep' => '#1D4ED8'],
    ];
}

/** ยอดของแบบสอบถาม / กิจกรรม ในปี (ทุกขอบเขต) → ['survey' => float, 'event' => float] */
function admin_dash_source_totals(PDO $pdo, int $year): array
{
    $st = $pdo->prepare("
        SELECT ui.source, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = :y1
        WHERE ui.year_id = :y2 AND ui.source IN ('survey', 'event')
        GROUP BY ui.source");
    $st->execute([':y1' => $year, ':y2' => $year]);
    $r = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    return ['survey' => (float) ($r['survey'] ?? 0), 'event' => (float) ($r['event'] ?? 0)];
}

/**
 * แยกตามผู้ให้ข้อมูลของปี: ทุกหน่วยงาน (การดำเนินงาน) + แบบสอบถาม + กิจกรรม — ผลรวม = gross
 * @return array [['kind'=>'faculty'|'survey'|'event', 'id'=>?int, 'name'=>string, 'value'=>float], ...] มาก → น้อย
 */
function admin_dash_year_breakdown(PDO $pdo, int $year): array
{
    $rows = array_map(fn($r) => ['kind' => 'faculty', 'id' => (int) $r['affil_id'], 'name' => (string) $r['affiliation_item'], 'value' => (float) $r['total_emission']],
        ghg_by_affiliation($pdo, $year));
    $src = admin_dash_source_totals($pdo, $year);
    $rows[] = ['kind' => 'survey', 'id' => null, 'name' => 'แบบสอบถาม', 'value' => $src['survey']];
    $rows[] = ['kind' => 'event',  'id' => null, 'name' => 'กิจกรรม',   'value' => $src['event']];
    return admin_dash_sort($rows);
}

/** เรียงมาก → น้อย แล้วตามชื่อ (ลำดับคงที่ ไม่สลับไปมาเมื่อยอดเท่ากัน) */
function admin_dash_sort(array $rows): array
{
    usort($rows, fn($a, $b) => [$b['value'], $a['name']] <=> [$a['value'], $b['name']]);
    return $rows;
}

/**
 * แยกตามผู้ให้ข้อมูลรายขอบเขต — ผลรวมของขอบเขต = ghg_report_summary()['scope'][n]
 * @return array [1 => rows, 2 => rows, 3 => rows] (รูปแบบแถวเดียวกับ admin_dash_year_breakdown)
 */
function admin_dash_scope_breakdown(PDO $pdo, int $year): array
{
    $names = array_column(ghg_by_affiliation($pdo, $year), 'affiliation_item', 'affil_id');
    $byAffil = ghg_scope_by_affiliation($pdo, $year);
    $st = $pdo->prepare("
        SELECT ag.scope, ui.source, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id AND ai.year_id = :y1
        JOIN admin_g ag ON ag.id = ai.scope
        WHERE ui.year_id = :y2 AND ui.source IN ('survey', 'event')
        GROUP BY ag.scope, ui.source");
    $st->execute([':y1' => $year, ':y2' => $year]);
    $src = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$sc, $source, $v]) $src[(int) $sc][$source] = (float) $v;

    $out = [];
    foreach ([1, 2, 3] as $sc) {
        $rows = [];
        foreach ($names as $id => $name) $rows[] = ['kind' => 'faculty', 'id' => (int) $id, 'name' => (string) $name, 'value' => (float) ($byAffil[(int) $id][$sc] ?? 0)];
        $rows[] = ['kind' => 'survey', 'id' => null, 'name' => 'แบบสอบถาม', 'value' => $src[$sc]['survey'] ?? 0.0];
        $rows[] = ['kind' => 'event',  'id' => null, 'name' => 'กิจกรรม',   'value' => $src[$sc]['event'] ?? 0.0];
        $out[$sc] = admin_dash_sort($rows);
    }
    return $out;
}

/**
 * สะสมทุกปี แยกตามผู้ให้ข้อมูล — ผลรวม = ผลรวม gross ของทุกปี
 * @return array rows (รูปแบบเดียวกับ admin_dash_year_breakdown)
 */
function admin_dash_cumulative(PDO $pdo, array $years): array
{
    $acc = [];
    foreach ($years as $y) {
        foreach (admin_dash_year_breakdown($pdo, (int) $y['year_id']) as $r) {
            $k = $r['kind'] . ':' . $r['id'];
            if (isset($acc[$k])) $acc[$k]['value'] += $r['value'];
            else $acc[$k] = $r;
        }
    }
    return admin_dash_sort(array_values($acc));
}

/**
 * ส่วนย่อยของยอดปล่อยทั้งมหาวิทยาลัย: ดำเนินงาน / กิจกรรม / แบบสอบถาม (ผลรวม = gross)
 * ghg_report_summary(null) ไม่แยก event_total / survey_total (มุมมองทั้งระบบรวมมาในยอดขอบเขตแล้ว → เป็น 0)
 * จึงแยกจาก user_item.source ชุดเดียวกับหน้าต่าง "แยกตามผู้ให้ข้อมูล" แล้วให้ดำเนินงาน = gross − กิจกรรม − แบบสอบถาม
 *
 * @return array ['operation' => float, 'event' => float, 'survey' => float]
 */
function admin_dash_gross_parts(PDO $pdo, int $year, float $gross): array
{
    $src = admin_dash_source_totals($pdo, $year);
    return ['operation' => $gross - $src['event'] - $src['survey'], 'event' => $src['event'], 'survey' => $src['survey']];
}

/**
 * จำนวนแบบสอบถาม (ชุด) และกิจกรรม (งาน) ของปี บนแถบแบบสอบถาม/กิจกรรม
 * นับแบบเดียวกับหน้าต่างที่ปุ่มเปิด (admin/api/api_affil_detail.php) ไม่งั้นแถบกับหน้าต่างบอกจำนวนไม่ตรงกัน:
 *   แบบสอบถามที่มีผลสรุป (survey_summary) · กิจกรรมที่มีรายการปล่อย (event_item)
 * @return array ['survey' => int, 'event' => int]
 */
function admin_dash_source_counts(PDO $pdo, int $year): array
{
    $sv = $pdo->prepare('SELECT COUNT(DISTINCT qi.questionnaire_id) FROM survey_summary ss JOIN questionnaire_item qi ON qi.id = ss.questionnaire_item_id WHERE ss.year_id = :y');
    $sv->execute([':y' => $year]);
    $ev = $pdo->prepare('SELECT COUNT(DISTINCT ei.event_id) FROM event_item ei JOIN event e ON e.id = ei.event_id WHERE e.year_id = :y');
    $ev->execute([':y' => $year]);
    return ['survey' => (int) $sv->fetchColumn(), 'event' => (int) $ev->fetchColumn()];
}

/**
 * จำนวนครั้งที่รายงาน = จำนวนคู่ (ผู้ให้ข้อมูล, ปี) ที่มีข้อมูล
 * ผู้ให้ข้อมูล = หน่วยงาน (การดำเนินงาน) / แบบสอบถาม / กิจกรรม — สูตรเดียวกับหน้าเดิม
 */
function admin_dash_report_count(PDO $pdo): int
{
    return (int) $pdo->query("
        SELECT COUNT(DISTINCT CONCAT(CASE WHEN source = 'officer' THEN CONCAT('aff', affiliation_id) ELSE source END, '-', year_id))
        FROM user_item")->fetchColumn();
}

/**
 * ภาพรวมทั้งหน้า — รวมทุก query ไว้ที่เดียว
 * @return array summary, offset, compare, ranking, breakdown, scope_breakdown, removal (rows/central/activity), cumulative, cumulative_total,
 *               history, report_count, affil_total, affils_with_data, source_counts, parts
 */
function admin_dash_overview(PDO $pdo, array $years, int $year): array
{
    $summary = ghg_report_summary($pdo, $year, null);
    $cmp     = ghg_year_comparison($pdo, $years, $year, null);
    $central = removal_central_total($pdo, $year);
    $activity = removal_activity_total($pdo, $year);
    $cumul   = admin_dash_cumulative($pdo, $years);
    $ranking = ghg_affiliation_ranking(ghg_by_affiliation($pdo, $year), ghg_scope_by_affiliation($pdo, $year));
    return [
        'summary'          => $summary,
        'offset'           => ghg_offset_pct($summary['gross'], $summary['removal']),
        'compare'          => $cmp,
        'badge'            => ghg_change_badge($summary['gross'], $cmp['prev']['gross'] ?? null),
        'ranking'          => $ranking,
        'breakdown'        => admin_dash_year_breakdown($pdo, $year),
        'scope_breakdown'  => admin_dash_scope_breakdown($pdo, $year),
        'removal_rows'     => removal_breakdown(removal_items_list($pdo, $year), $activity),
        'removal_central'  => $central,
        'removal_activity' => $activity,
        'cumulative'       => $cumul,
        'cumulative_total' => array_sum(array_column($cumul, 'value')),
        'history'          => ghg_scope_history($pdo, $years),
        'report_count'     => admin_dash_report_count($pdo),
        'affil_total'      => (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn(),
        'affils_with_data' => count($ranking),
        'source_counts'    => admin_dash_source_counts($pdo, $year),
        'parts'            => admin_dash_gross_parts($pdo, $year, $summary['gross']),
    ];
}
