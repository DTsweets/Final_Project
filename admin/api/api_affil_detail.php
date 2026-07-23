<?php
/**
 * api_affil_detail.php
 * ดึงรายละเอียดรายการที่คณะกรอกข้อมูลในปีที่เลือก
 * ส่งคืน JSON array
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

$pdo      = getDB();
$affilId  = isset($_GET['affil_id']) ? (int)$_GET['affil_id'] : 0;
$yearId   = isset($_GET['year_id'])  ? (int)$_GET['year_id']  : 0;
$source   = $_GET['source'] ?? '';   // 'survey' | 'event' → รวมทุกคณะตาม source; ไม่ระบุ = คณะ (officer)

if (!$yearId) { echo json_encode([]); exit; }

$baseSelect = '
    SELECT
        ui.id            AS user_item_id,
        ai.id            AS admin_item_id,
        ag.name_tiem     AS activity_type,
        ag.order_num     AS activity_order,
        ai.name_tiem,
        ag.scope,
        ai.unit,
        ui.Vol          AS vol,
        (ui.Vol * ai.AD)/1000 AS emission,
        (SELECT COUNT(*) FROM evidence e WHERE e.entity_type=\'user_item\' AND e.entity_id = ui.id) AS ev_count
    FROM user_item ui
    JOIN admin_item    ai ON ai.id  = ui.admin_item_id
    JOIN admin_g       ag ON ag.id  = ai.scope
';
$orderBy = ' ORDER BY ag.scope ASC, ag.order_num ASC, ai.name_tiem ASC';

if ($source === 'survey') {
    // แบบสอบถาม — ดึงค่าดิบจาก survey_summary (จำนวนผู้ตอบ + เฉลี่ย/คน) ให้ตรงกับหน้ากรอก
    $sql = '
        SELECT ai.name_tiem,
               ag.scope,
               q.id        AS questionnaire_id,
               q.audience,
               q.affiliation_id AS maker_id,
               qa.affiliation_item AS maker_name,
               ss.respondents,
               ss.avg_value,
               ai.unit,
               (ss.respondents * ss.avg_value * ai.AD)/1000 AS emission,
               (SELECT COUNT(*) FROM evidence e2 WHERE e2.entity_type=\'questionnaire\' AND e2.entity_id = q.id) AS ev_count
        FROM survey_summary ss
        JOIN admin_item        ai ON ai.id = ss.admin_item_id
        JOIN admin_g           ag ON ag.id = ai.scope
        JOIN questionnaire_item qi ON qi.id = ss.questionnaire_item_id
        JOIN questionnaire      q  ON q.id = qi.questionnaire_id
        LEFT JOIN affiliation_id qa ON qa.id = q.affiliation_id
        WHERE ss.year_id = :year_id
        ORDER BY ag.scope ASC, qa.affiliation_item ASC, q.audience ASC, ai.name_tiem ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':year_id' => $yearId]);
} elseif ($source === 'event') {
    // กิจกรรม — ทุกกิจกรรม + รายการทั้งปล่อย (event_item) และดูดกลับ (removal_event_item)
    $sql = '
        SELECT event_id, event_name, event_date, event_end_date, organizer,
               name_tiem, unit, itype, scope, vol, emission, ev_count
        FROM (
            /* รายการปล่อยคาร์บอน */
            SELECT e.id AS event_id, e.name AS event_name, e.event_date, e.event_end_date,
                   COALESCE(e.organizer_name, a.affiliation_item) AS organizer,
                   ai.name_tiem, ai.unit, \'emit\' AS itype, ag.scope AS scope,
                   ei.Vol AS vol, (ei.Vol * ai.AD)/1000 AS emission,
                   (SELECT COUNT(*) FROM evidence e2 WHERE e2.entity_type=\'event\' AND e2.entity_id = e.id) AS ev_count,
                   e.event_date AS sort_date, e.id AS sort_eid, ai.name_tiem AS sort_name
            FROM event e
            JOIN event_item ei ON ei.event_id = e.id
            JOIN admin_item  ai ON ai.id = ei.admin_item_id
            JOIN admin_g     ag ON ag.id = ai.scope
            LEFT JOIN affiliation_id a ON a.id = e.affiliation_id
            WHERE e.year_id = :y1
            UNION ALL
            /* รายการดูดกลับคาร์บอน */
            SELECT e.id, e.name, e.event_date, e.event_end_date,
                   COALESCE(e.organizer_name, a.affiliation_item),
                   rei.name_tiem, rei.unit, \'rmv\', NULL,
                   rei.qty, rei.qty * rei.factor/1000,
                   (SELECT COUNT(*) FROM evidence e2 WHERE e2.entity_type=\'event\' AND e2.entity_id = e.id),
                   e.event_date, e.id, rei.name_tiem
            FROM event e
            JOIN removal_event_item rei ON rei.event_id = e.id
            LEFT JOIN affiliation_id a ON a.id = e.affiliation_id
            WHERE e.year_id = :y2
            UNION ALL
            /* กิจกรรมที่ยังไม่มีรายการ (ให้ยังโชว์ในลิสต์) */
            SELECT e.id, e.name, e.event_date, e.event_end_date,
                   COALESCE(e.organizer_name, a.affiliation_item),
                   NULL, NULL, \'none\', NULL, NULL, 0,
                   (SELECT COUNT(*) FROM evidence e2 WHERE e2.entity_type=\'event\' AND e2.entity_id = e.id),
                   e.event_date, e.id, \'\'
            FROM event e
            LEFT JOIN affiliation_id a ON a.id = e.affiliation_id
            WHERE e.year_id = :y3
              AND NOT EXISTS (SELECT 1 FROM event_item ei2 WHERE ei2.event_id = e.id)
              AND NOT EXISTS (SELECT 1 FROM removal_event_item r2 WHERE r2.event_id = e.id)
        ) t
        ORDER BY sort_date ASC, sort_eid ASC, itype ASC, sort_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':y1' => $yearId, ':y2' => $yearId, ':y3' => $yearId]);
} else {
    // คณะ — เฉพาะ officer
    if (!$affilId) { echo json_encode([]); exit; }
    $sql = $baseSelect . ' WHERE ui.affiliation_id = :affil_id AND ui.year_id = :year_id AND ui.source = \'officer\'' . $orderBy;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':affil_id' => $affilId, ':year_id' => $yearId]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
