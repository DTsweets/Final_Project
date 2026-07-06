<?php
/**
 * api_reports_list.php
 * ดึงสรุปจำนวนรายงานต่อคณะ (1 รายงาน = 1 คณะ × 1 ปี)
 * mode=list  → คืน [{affil_id, affil_name, year_count}]
 * mode=years → คืน [{year_id, year_label}] ของคณะที่เลือก (affil_id)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

$pdo  = getDB();
$mode = $_GET['mode'] ?? 'list';

if ($mode === 'years') {
    // ───── โหมด: ดูว่าคณะนี้กรอกปีไหนไปบ้าง ─────
    $affilId = isset($_GET['affil_id']) ? (int)$_GET['affil_id'] : 0;
    if (!$affilId) { echo json_encode([]); exit; }

    $sql = '
        SELECT DISTINCT
            ay.id   AS year_id,
            ay.year AS year_label,
            COUNT(DISTINCT ui.id) AS item_count,
            COALESCE(SUM(ui.Vol * ai.AD), 0) AS total_emission
        FROM user_item ui
        JOIN admin_year ay ON ay.id  = ui.year_id
        JOIN admin_item ai ON ai.id  = ui.admin_item_id
        WHERE ui.affiliation_id = :affil_id
        GROUP BY ay.id, ay.year
        ORDER BY ay.year DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':affil_id' => $affilId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// ───── โหมดปกติ: สรุปรายคณะ (จำนวนปีที่รายงาน) ─────
$sql = '
    SELECT
        a.id                                                AS affil_id,
        a.affiliation_item                                  AS affil_name,
        COUNT(DISTINCT ui.year_id)                          AS year_count,
        GROUP_CONCAT(DISTINCT ay.year ORDER BY ay.year DESC) AS years_list,
        COALESCE(SUM(ui.Vol * ai.AD), 0)                    AS total_emission
    FROM affiliation_id a
    LEFT JOIN user_item  ui ON ui.affiliation_id = a.id
    LEFT JOIN admin_year ay ON ay.id = ui.year_id
    LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY a.id, a.affiliation_item
    ORDER BY year_count DESC, a.affiliation_item ASC
';
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
