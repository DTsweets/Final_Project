<?php
/**
 * api_item_detail.php
 * ดึงรายละเอียด user_item เดี่ยว: ชื่อ, จำนวน, ค่า emission + ไฟล์แนบ
 * params: user_item_id
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

$pdo        = getDB();
$userItemId = isset($_GET['user_item_id']) ? (int)$_GET['user_item_id'] : 0;

if (!$userItemId) {
    echo json_encode(['success' => false, 'message' => 'Missing user_item_id']);
    exit;
}

// ── ดึงข้อมูลหลัก ──────────────────────────────────────────────────────────
$sql = '
    SELECT
        ui.id            AS user_item_id,
        ai.name_tiem,
        ag.scope,
        ai.unit,
        ai.AD            AS emission_factor,
        ui.Vol,
        (ui.Vol * ai.AD)/1000 AS emission,
        a.affiliation_item,
        ay.year          AS year_label
    FROM user_item ui
    JOIN admin_item    ai ON ai.id  = ui.admin_item_id
    JOIN admin_g       ag ON ag.id  = ai.scope
    JOIN affiliation_id a  ON a.id  = ui.affiliation_id
    JOIN admin_year    ay ON ay.id  = ui.year_id
    WHERE ui.id = :uid
';
$stmt = $pdo->prepare($sql);
$stmt->execute([':uid' => $userItemId]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

// ── ดึงไฟล์แนบ ─────────────────────────────────────────────────────────────
$stmtEv = $pdo->prepare('
    SELECT id, file_path, file_type, created_at
    FROM user_item_evidence
    WHERE user_item_id = :uid
    ORDER BY created_at DESC
');
$stmtEv->execute([':uid' => $userItemId]);
$files = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'item'    => $item,
    'files'   => $files,
], JSON_UNESCAPED_UNICODE);
