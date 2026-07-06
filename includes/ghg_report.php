<?php
/**
 * GHG Report Query Helpers (ใช้ซ้ำระหว่าง admin / dean)
 * -----------------------------------------------------
 * ทุกฟังก์ชันคำนวณ emission = SUM(user_item.Vol * admin_item.AD)
 * สำคัญ: admin_item.scope เป็น FK -> admin_g.id  (เลข Scope 1/2/3 อยู่ที่ admin_g.scope)
 *        จึง JOIN ผ่าน admin_g เสมอ
 */

/** รายการปีทั้งหมด (ใหม่ -> เก่า) : [['year_id'=>.., 'year'=>..], ...] */
function ghg_years(PDO $pdo): array
{
    return $pdo->query('SELECT id AS year_id, year FROM admin_year ORDER BY year DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * ผลรวมแยกตาม Scope (1/2/3) ของปีที่เลือก
 * $affil = null -> ทั้งระบบ, มีค่า -> เฉพาะคณะนั้น
 * คืน [1=>float, 2=>float, 3=>float]
 */
function ghg_scope_totals(PDO $pdo, int $year, ?int $affil = null): array
{
    $affilCond = $affil !== null ? ' AND ui.affiliation_id = :aff' : '';
    $sql = "
        SELECT ag.scope, COALESCE(SUM(ui.Vol * ai.AD), 0) AS e
        FROM admin_g ag
        LEFT JOIN admin_item ai ON ai.scope = ag.id AND ai.year_id = :y1
        LEFT JOIN user_item  ui ON ui.admin_item_id = ai.id AND ui.year_id = :y2 $affilCond
        GROUP BY ag.scope
        ORDER BY ag.scope
    ";
    $stmt = $pdo->prepare($sql);
    $params = [':y1' => $year, ':y2' => $year];
    if ($affil !== null) $params[':aff'] = $affil;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    return [1 => (float)($rows[1] ?? 0), 2 => (float)($rows[2] ?? 0), 3 => (float)($rows[3] ?? 0)];
}

/** ผลรวมทั้งหมดของปี (ทั้งระบบ หรือเฉพาะคณะ) */
function ghg_total(PDO $pdo, int $year, ?int $affil = null): float
{
    $s = ghg_scope_totals($pdo, $year, $affil);
    return $s[1] + $s[2] + $s[3];
}

/**
 * ผลรวมรายคณะ ของปีที่เลือก (แสดงทุกคณะแม้ยังไม่กรอก)
 * $year = null -> สะสมทุกปี
 * คืน [['affil_id'=>.., 'affiliation_item'=>.., 'total_emission'=>float], ...] เรียงมาก->น้อย
 */
function ghg_by_affiliation(PDO $pdo, ?int $year = null): array
{
    if ($year === null) {
        $sql = '
            SELECT a.id AS affil_id, a.affiliation_item,
                   COALESCE(SUM(ui.Vol * ai.AD), 0) AS total_emission
            FROM affiliation_id a
            LEFT JOIN user_item  ui ON ui.affiliation_id = a.id
            LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
            GROUP BY a.id, a.affiliation_item
            ORDER BY total_emission DESC';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('
        SELECT a.id AS affil_id, a.affiliation_item,
               COALESCE(SUM(ui.Vol * ai.AD), 0) AS total_emission
        FROM affiliation_id a
        LEFT JOIN user_item  ui ON ui.affiliation_id = a.id AND ui.year_id = :y
        LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
        GROUP BY a.id, a.affiliation_item
        ORDER BY total_emission DESC');
    $stmt->execute([':y' => $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * รายการที่คณะกรอกในปีนั้น (ชื่อ/หน่วย/Vol/emission/scope) — reuse จาก api_affil_detail
 */
function ghg_affil_detail(PDO $pdo, int $affil, int $year): array
{
    $stmt = $pdo->prepare('
        SELECT ag.name_tiem AS activity_type, ag.scope,
               ai.name_tiem, ai.unit,
               ui.Vol AS vol, (ui.Vol * ai.AD) AS emission
        FROM user_item ui
        JOIN admin_item ai ON ai.id = ui.admin_item_id
        JOIN admin_g    ag ON ag.id = ai.scope
        WHERE ui.affiliation_id = :aff AND ui.year_id = :y
        ORDER BY ag.scope ASC, ag.order_num ASC, ai.name_tiem ASC');
    $stmt->execute([':aff' => $affil, ':y' => $year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** ผลรวมรายปีของคณะ (ใหม่->เก่า) — reuse จาก api_affil_yearly */
function ghg_affil_yearly(PDO $pdo, int $affil): array
{
    $stmt = $pdo->prepare('
        SELECT y.id AS year_id, y.year,
               COUNT(DISTINCT ui.id) AS entry_count,
               COALESCE(SUM(ui.Vol * ai.AD), 0) AS total_emission
        FROM admin_year y
        LEFT JOIN user_item  ui ON ui.year_id = y.id AND ui.affiliation_id = :aff
        LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
        GROUP BY y.id, y.year
        ORDER BY y.year DESC');
    $stmt->execute([':aff' => $affil]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** ชื่อคณะจาก id */
function ghg_affil_name(PDO $pdo, int $affil): string
{
    $stmt = $pdo->prepare('SELECT affiliation_item FROM affiliation_id WHERE id = :id');
    $stmt->execute([':id' => $affil]);
    return (string)($stmt->fetchColumn() ?: '-');
}
