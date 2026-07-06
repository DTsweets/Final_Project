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

if (!$affilId || !$yearId) {
    echo json_encode([]);
    exit;
}

$sql = '
    SELECT
        ui.id            AS user_item_id,
        ai.id            AS admin_item_id,
        ag.name_tiem     AS activity_type,
        ag.order_num     AS activity_order,
        ai.name_tiem,
        ag.scope,
        ai.unit,
        ui.Vol          AS vol,
        (ui.Vol * ai.AD) AS emission
    FROM user_item ui
    JOIN admin_item    ai ON ai.id  = ui.admin_item_id
    JOIN admin_g       ag ON ag.id  = ai.scope
    WHERE ui.affiliation_id = :affil_id
      AND ui.year_id         = :year_id
    ORDER BY ag.scope ASC, ag.order_num ASC, ai.name_tiem ASC
';

$stmt = $pdo->prepare($sql);
$stmt->execute([':affil_id' => $affilId, ':year_id' => $yearId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
