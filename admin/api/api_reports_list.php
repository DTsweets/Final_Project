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
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_role(['admin']);

header('Content-Type: application/json; charset=utf-8');

$pdo  = getDB();
$mode = $_GET['mode'] ?? 'list';

if ($mode === 'years') {
    // ───── โหมด: ดูว่าผู้ให้ข้อมูลนี้กรอกปีไหนบ้าง ─────
    $source = $_GET['source'] ?? '';
    if ($source === 'event') {
        // กิจกรรม — นับ "ทุกกิจกรรม" ต่อปี (ทั้งปล่อย/ดูดกลับ) + ยอดปล่อย
        $sql = '
            SELECT ay.id AS year_id, ay.year AS year_label,
                   (SELECT COUNT(DISTINCT e.id)
                    FROM event e JOIN event_item ei ON ei.event_id = e.id
                    WHERE e.year_id = ay.id) AS item_count,
                   COALESCE((
                       SELECT SUM(ei.Vol * ai.AD)/1000
                       FROM event e JOIN event_item ei ON ei.event_id = e.id
                       JOIN admin_item ai ON ai.id = ei.admin_item_id
                       WHERE e.year_id = ay.id
                   ), 0) AS total_emission
            FROM admin_year ay
            WHERE EXISTS (SELECT 1 FROM event e JOIN event_item ei ON ei.event_id = e.id WHERE e.year_id = ay.id)
            ORDER BY ay.year DESC';
        $stmt = $pdo->query($sql);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($source === 'survey') {
        // แบบสอบถาม = รวมทุกคณะ
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

// 2) แบบสอบถาม (รวมทุกคณะ จาก user_item source=survey)
$src_stmt = $pdo->prepare('
    SELECT COUNT(DISTINCT ui.year_id) AS year_count,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
    WHERE ui.source = :src
');
$src_stmt->execute([':src' => 'survey']);
$sv = $src_stmt->fetch(PDO::FETCH_ASSOC);
$rows[] = [
    'affil_id' => null, 'affil_name' => 'แบบสอบถาม',
    'year_count' => (int) $sv['year_count'], 'total_emission' => (float) $sv['total_emission'],
    'kind' => 'survey', 'source' => 'survey',
];

// 3) กิจกรรม — นับปีจากตาราง event (ครอบคลุมทั้งปล่อย/ดูดกลับ) + ยอดปล่อย
$ev = $pdo->query('
    SELECT COUNT(DISTINCT e.year_id) AS year_count,
           COALESCE((SELECT SUM(ei2.Vol * ai.AD)/1000
                     FROM event e2 JOIN event_item ei2 ON ei2.event_id = e2.id
                     JOIN admin_item ai ON ai.id = ei2.admin_item_id), 0) AS total_emission
    FROM event e JOIN event_item ei ON ei.event_id = e.id
')->fetch(PDO::FETCH_ASSOC);
$rows[] = [
    'affil_id' => null, 'affil_name' => 'กิจกรรม',
    'year_count' => (int) $ev['year_count'], 'total_emission' => (float) $ev['total_emission'],
    'kind' => 'event', 'source' => 'event',
];

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
