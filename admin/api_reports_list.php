<?php
/**
 * api_reports_list.php
 * ดึงสรุปจำนวนรายงานต่อ "ผู้ให้ข้อมูล" (แยกตามผู้ให้ข้อมูล — กันนับซ้ำ)
 *   - คณะ = เฉพาะ source='officer'
 *   - แบบสอบถาม = source='survey' (รวมทุกกลุ่ม, ระดับมหาวิทยาลัย)
 *   - กิจกรรม = source='event'
 * mode=list  → คืน [{affil_id, affil_name, year_count, total_emission, kind, source}]
 * mode=years → คืน [{year_id, year_label, item_count, total_emission}]
 *              ระบุคณะด้วย affil_id (officer) หรือระบุ source=survey|event
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

$pdo  = getDB();
$mode = $_GET['mode'] ?? 'list';

if ($mode === 'years') {
    // ───── โหมด: ดูว่าผู้ให้ข้อมูลนี้กรอกปีไหนบ้าง ─────
    $source = $_GET['source'] ?? '';
    if ($source === 'survey' || $source === 'event') {
        // แบบสอบถาม/กิจกรรม = รวมทุกคณะ กรองด้วย source
        $sql = '
            SELECT ay.id AS year_id, ay.year AS year_label,
                   COUNT(DISTINCT ui.id) AS item_count,
                   COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
            FROM user_item ui
            JOIN admin_year ay ON ay.id = ui.year_id
            JOIN admin_item ai ON ai.id = ui.admin_item_id
            WHERE ui.source = :src
            GROUP BY ay.id, ay.year
            ORDER BY ay.year DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':src' => $source]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $affilId = isset($_GET['affil_id']) ? (int)$_GET['affil_id'] : 0;
    if (!$affilId) { echo json_encode([]); exit; }

    $sql = '
        SELECT ay.id AS year_id, ay.year AS year_label,
               COUNT(DISTINCT ui.id) AS item_count,
               COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
        FROM user_item ui
        JOIN admin_year ay ON ay.id  = ui.year_id
        JOIN admin_item ai ON ai.id  = ui.admin_item_id
        WHERE ui.affiliation_id = :affil_id AND ui.source = \'officer\'
        GROUP BY ay.id, ay.year
        ORDER BY ay.year DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':affil_id' => $affilId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// ───── โหมดปกติ: สรุปรายผู้ให้ข้อมูล ─────
// 1) คณะ (เฉพาะ officer)
$sql = '
    SELECT
        a.id                                                 AS affil_id,
        a.affiliation_item                                   AS affil_name,
        COUNT(DISTINCT ui.year_id)                           AS year_count,
        COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)                AS total_emission
    FROM affiliation_id a
    LEFT JOIN user_item  ui ON ui.affiliation_id = a.id AND ui.source = \'officer\'
    LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY a.id, a.affiliation_item
    ORDER BY year_count DESC, a.affiliation_item ASC
';
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) { $r['kind'] = 'faculty'; $r['source'] = null; }
unset($r);

// 2) แบบสอบถาม + กิจกรรม (รวมทุกคณะ แยกตาม source)
$src_stmt = $pdo->prepare('
    SELECT COUNT(DISTINCT ui.year_id) AS year_count,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
    WHERE ui.source = :src
');
foreach (['survey' => 'แบบสอบถาม', 'event' => 'กิจกรรม'] as $src => $label) {
    $src_stmt->execute([':src' => $src]);
    $row = $src_stmt->fetch(PDO::FETCH_ASSOC);
    $rows[] = [
        'affil_id'       => null,
        'affil_name'     => $label,
        'year_count'     => (int) $row['year_count'],
        'total_emission' => (float) $row['total_emission'],
        'kind'           => $src,
        'source'         => $src,
    ];
}

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
