<?php
/**
 * api_affil_yearly.php
 * ดึงรายละเอียดการปล่อยก๊าซเรือนกระจกของคณะ (แยกตามปี)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

$pdo      = getDB();
$affilId  = isset($_GET['affil_id']) ? (int)$_GET['affil_id'] : 0;
$source   = $_GET['source'] ?? '';   // 'survey' | 'event' → รวมทุกคณะตาม source; ไม่ระบุ = คณะ (officer)

if ($source === 'survey' || $source === 'event') {
    // ยอดรายปีของแบบสอบถาม/กิจกรรม (รวมทุกคณะ กรองด้วย source)
    $sql = '
        SELECT y.id AS year_id, y.year,
               COUNT(DISTINCT ui.id) AS entry_count,
               COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
        FROM admin_year y
        LEFT JOIN user_item ui ON ui.year_id = y.id AND ui.source = :src
        LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
        GROUP BY y.id, y.year
        ORDER BY y.year DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':src' => $source]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$affilId) {
    echo json_encode([]);
    exit;
}

// หาการปล่อยสะสมและรายการที่กรอก แยกตามปี (คณะ = officer)
$sql = '
    SELECT
        y.id AS year_id,
        y.year,
        COUNT(DISTINCT ui.id) AS entry_count,
        COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM admin_year y
    LEFT JOIN user_item ui ON ui.year_id = y.id AND ui.affiliation_id = :affil_id AND ui.source = \'officer\'
    LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY y.id, y.year
    ORDER BY y.year DESC
';

$stmt = $pdo->prepare($sql);
$stmt->execute([':affil_id' => $affilId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
