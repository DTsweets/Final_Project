<?php
/**
 * ข้อมูลของหน้า แบบสอบถาม & กิจกรรม (includes/collect_page.php — ใช้ร่วม admin / officer)
 * แยกออกมาให้ทดสอบได้ (tests/collect_page_test.php)
 */
require_once __DIR__ . '/officer_entry.php';   // officer_clean_vol: กฎตรวจค่าเดียวกับหน้ากรอกข้อมูลการดำเนินงาน

/** จำนวนผู้ตอบ: จำนวนเต็ม ไม่ติดลบ ไม่เกินเพดาน (ทศนิยมปัดลง) */
function collect_clean_count($raw): int
{
    return (int) floor(officer_clean_vol($raw));
}

/** ผลรวมแบบสอบถาม (ผู้ตอบ × ค่าเฉลี่ย) เก็บใน user_item.Vol decimal(13,4) → สูงสุด 999,999,999.9999 */
const COLLECT_SURVEY_VOL_MAX = 999999999.9999;

/**
 * ผู้ตอบ × ค่าเฉลี่ย เกินความจุหรือไม่ — แต่ละช่องไม่เกิน 1,000,000 แต่ผลคูณไปได้ถึง 1e12
 * เดิมไม่ตรวจ: MySQL (ไม่ strict) ตัดค่าเหลือเพดานเงียบ ๆ → การ์ดหน้าแบบสอบถาม (survey_summary) ไม่ตรงกับรายงาน (user_item)
 */
function collect_survey_over_max(int $resp, float $avg): bool
{
    return $resp * $avg > COLLECT_SURVEY_VOL_MAX;
}

/**
 * แบบสอบถามทั้งหมดของปี + จำนวนหัวข้อ + ยอด tCO₂e (ตามคณะเจ้าของ)
 * @param int|null $affil null = ทุกคณะ (admin)
 */
function collect_survey_list(PDO $pdo, int $year, ?int $affil): array
{
    $stmt = $pdo->prepare("
        SELECT q.id AS qid, q.audience AS name, q.affiliation_id AS affid, a.affiliation_item AS maker_name,
               COUNT(DISTINCT qi.id) AS item_count,
               COUNT(DISTINCT CASE WHEN ss.respondents > 0 AND ss.avg_value > 0 THEN qi.id END) AS filled_count,
               (SELECT COUNT(*) FROM evidence ev WHERE ev.entity_type='questionnaire' AND ev.entity_id=q.id) AS ev_count,
               COALESCE(SUM(COALESCE(ss.respondents,0)*COALESCE(ss.avg_value,0)*ai.AD)/1000, 0) AS tco2e
        FROM questionnaire q
        JOIN affiliation_id a ON a.id = q.affiliation_id
        LEFT JOIN questionnaire_item qi ON qi.questionnaire_id = q.id
        LEFT JOIN admin_item ai ON ai.id = qi.admin_item_id
        LEFT JOIN survey_summary ss ON ss.questionnaire_item_id = qi.id AND ss.affiliation_id = q.affiliation_id AND ss.year_id = :y
        WHERE q.year_id = :y2" . ($affil === null ? '' : " AND q.affiliation_id = :aff") . "
        GROUP BY q.id, q.audience, q.affiliation_id, a.affiliation_item
        ORDER BY " . ($affil === null ? "a.affiliation_item, " : "") . "q.audience");
    $p = [':y' => $year, ':y2' => $year];
    if ($affil !== null) $p[':aff'] = $affil;
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/**
 * กิจกรรมทั้งหมดของปี + ยอดปล่อย / ดูดกลับ (แยกกัน คนละหมวด) + จำนวนรายการ
 * @param int|null $affil null = ทุกคณะ (admin)
 */
function collect_event_list(PDO $pdo, int $year, ?int $affil): array
{
    $stmt = $pdo->prepare("SELECT e.id, e.name, e.kind, e.event_date, e.event_end_date, e.affiliation_id, e.organizer_name,
            COALESCE(e.organizer_name, a.affiliation_item) AS org_label,
            COALESCE(SUM(ei.Vol*ai.AD)/1000,0) AS tco2e, COUNT(ei.id) AS item_count,
            (SELECT COUNT(*) FROM admin_item x WHERE x.data_source='event' AND x.event_id=e.id) AS emit_topics,
            (SELECT COALESCE(SUM(rei.qty*rei.factor)/1000,0) FROM removal_event_item rei WHERE rei.event_id=e.id) AS removal_tco2e,
            (SELECT COUNT(*) FROM removal_event_item rei WHERE rei.event_id=e.id) AS removal_count,
            (SELECT COUNT(*) FROM evidence ev WHERE ev.entity_type='event' AND ev.entity_id=e.id) AS ev_count
        FROM event e JOIN affiliation_id a ON a.id=e.affiliation_id
        LEFT JOIN event_item ei ON ei.event_id=e.id LEFT JOIN admin_item ai ON ai.id=ei.admin_item_id
        WHERE e.year_id=:y" . ($affil === null ? '' : " AND e.affiliation_id=:aff") . " GROUP BY e.id ORDER BY e.event_date DESC, e.id DESC");
    $p = [':y' => $year];
    if ($affil !== null) $p[':aff'] = $affil;
    $stmt->execute($p);
    return $stmt->fetchAll();
}

/** ยอดบนการ์ดสรุป: แบบสอบถาม / กิจกรรมปล่อย / กิจกรรมดูดกลับ */
function collect_totals(array $surveys, array $events): array
{
    return [
        'survey'  => array_sum(array_map(fn($q) => (float) $q['tco2e'], $surveys)),
        'emit'    => array_sum(array_map(fn($e) => (float) $e['tco2e'], $events)),
        'removal' => array_sum(array_map(fn($e) => (float) $e['removal_tco2e'], $events)),
    ];
}

/** ตัวเลข tCO₂e บนหน้านี้: 0 = "-", ทศนิยมตามที่กำหนด (ตรงกับ deFormat ใน assets/js/data-entry.js) */
function collect_fmt(float $n, int $digits = 3): string
{
    return $n == 0 ? '-' : number_format($n, $digits);
}

/** ค่าในช่องกรอก: 0 = ว่าง, ตัดศูนย์ท้ายทศนิยม */
function collect_input_val(float $v): string
{
    return $v == 0 ? '' : rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
}

/** ช่วงวันที่ dd/mm/yyyy (ใน DB เก็บ yyyy-mm-dd) */
function collect_date_range(?string $start, ?string $end): string
{
    $dmy = fn($iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
    $s = $dmy($start);
    if ($s === '') return 'ไม่ระบุวันที่';
    return $end ? $s . ' – ' . $dmy($end) : $s;
}
