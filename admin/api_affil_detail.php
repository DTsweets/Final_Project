<?php
/**
 * api_affil_detail.php
 * ดึงรายละเอียดรายการที่คณะกรอกข้อมูลในปีที่เลือก
 * ส่งคืน JSON array
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

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
        (SELECT COUNT(*) FROM user_item_evidence e WHERE e.user_item_id = ui.id) AS ev_count
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
               q.audience,
               ss.respondents,
               ss.avg_value,
               ai.unit,
               (ss.respondents * ss.avg_value * ai.AD)/1000 AS emission
        FROM survey_summary ss
        JOIN admin_item        ai ON ai.id = ss.admin_item_id
        JOIN admin_g           ag ON ag.id = ai.scope
        JOIN questionnaire_item qi ON qi.id = ss.questionnaire_item_id
        JOIN questionnaire      q  ON q.id = qi.questionnaire_id
        WHERE ss.year_id = :year_id
        ORDER BY ag.scope ASC, q.audience ASC, ai.name_tiem ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':year_id' => $yearId]);
} elseif ($source === 'event') {
    // กิจกรรม — แจกแจงราย event + รายการ (ผู้จัด/วันที่/tCO₂e) จาก event + event_item
    $sql = '
        SELECT e.id AS event_id,
               e.name AS event_name,
               e.event_date,
               e.event_end_date,
               COALESCE(e.organizer_name, a.affiliation_item) AS organizer,
               ai.name_tiem,
               ag.scope,
               ai.unit,
               ei.Vol AS vol,
               (ei.Vol * ai.AD)/1000 AS emission
        FROM event e
        JOIN event_item ei ON ei.event_id = e.id
        JOIN admin_item  ai ON ai.id = ei.admin_item_id
        JOIN admin_g     ag ON ag.id = ai.scope
        LEFT JOIN affiliation_id a ON a.id = e.affiliation_id
        WHERE e.year_id = :year_id
        ORDER BY e.event_date ASC, e.name ASC, ag.scope ASC, ai.name_tiem ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':year_id' => $yearId]);
} else {
    // คณะ — เฉพาะ officer
    if (!$affilId) { echo json_encode([]); exit; }
    $sql = $baseSelect . ' WHERE ui.affiliation_id = :affil_id AND ui.year_id = :year_id AND ui.source = \'officer\'' . $orderBy;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':affil_id' => $affilId, ':year_id' => $yearId]);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
