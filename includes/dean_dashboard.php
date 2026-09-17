<?php
/**
 * ข้อมูลของ Dashboard คณบดี (dean/index.php) — เฉพาะคณะของตนเอง
 * ------------------------------------------------------------------
 * ยอดหลักมาจาก ghg_report_summary($pdo, $year, $affil) ตัวเดียวกับหน้ารายงาน GHG (มุมมองคณะ) / Excel / PDF
 * หน้าตาใช้ชุดเดียวกับ Dashboard admin (admin-dashboard.css / admin-dashboard.js โหมด 'faculty')
 * ข้อมูลของหน้าต่างรายละเอียดฝังในหน้าทั้งหมด (ไม่เรียก API) และรวมกันได้เท่าการ์ดเสมอ (มีเทสต์ล็อกไว้)
 */

require_once __DIR__ . '/admin_dashboard.php';

/** admin เปิด Dashboard คณบดี → ส่งไป Dashboard ของ admin (ภาพรวมทั้งมหาวิทยาลัยอยู่ที่นั่น) · role อื่น → null */
function dean_dash_redirect(string $role, int $year): ?string
{
    if ($role !== 'admin') return null;
    return '../admin/index.php' . ($year > 0 ? '?year=' . $year : '');
}

/**
 * รายการของคณะในปี: การดำเนินงาน (ทุกรายการในแม่บท แม้ยังไม่กรอก) + กิจกรรมที่คณะจัด + แบบสอบถาม
 * ผลรวม emission = $summary['gross'] (ใช้ event_rows / survey_rows จาก ghg_report_summary() ชุดเดียวกับการ์ด)
 *
 * @return array [['scope','name','unit','vol','emission','source'=>'officer'|'event'|'survey', 'event'?], ...]
 */
function dean_dash_items(PDO $pdo, int $affil, int $year, array $summary): array
{
    if ($affil <= 0) return [];
    $items = [];
    $stmt = $pdo->prepare("
        SELECT ag.scope AS scope_no, ai.name_tiem, ai.unit,
               COALESCE(ui.Vol,0) AS vol, (COALESCE(ui.Vol,0)*ai.AD)/1000 AS emission
        FROM admin_item ai
        JOIN admin_g ag ON ai.scope = ag.id
        LEFT JOIN user_item ui ON ui.admin_item_id = ai.id AND ui.affiliation_id = :aff AND ui.year_id = :y AND ui.source = 'officer'
        WHERE ai.year_id = :y2 AND ai.data_source = 'officer'
        ORDER BY ag.scope ASC, ai.id ASC");
    $stmt->execute([':aff' => $affil, ':y' => $year, ':y2' => $year]);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = ['scope' => (int) $r['scope_no'], 'name' => $r['name_tiem'], 'unit' => $r['unit'], 'vol' => (float) $r['vol'], 'emission' => (float) $r['emission'], 'source' => 'officer'];
    }
    foreach ($summary['event_rows'] as $r) {
        $items[] = ['scope' => (int) $r['scope'], 'name' => $r['name_tiem'], 'unit' => $r['unit'], 'vol' => (float) $r['qty'], 'emission' => (float) $r['emission'],
                    'source' => 'event', 'event' => (string) ($r['event_name'] ?? '')];
    }
    foreach ($summary['survey_rows'] as $r) {
        $items[] = ['scope' => (int) $r['scope'], 'name' => $r['name_tiem'], 'unit' => $r['unit'], 'vol' => (float) $r['qty'], 'emission' => (float) $r['emission'], 'source' => 'survey'];
    }
    return $items;
}

/** แถวของหน้าต่างรายละเอียด [0 = ทั้งหมด, 1/2/3 = รายขอบเขต] — กิจกรรม/แบบสอบถามรวมเป็นอย่างละแถว */
function dean_dash_detail_rows(array $items): array
{
    $out = [0 => ghg_detail_collapse_events($items)];
    foreach ([1, 2, 3] as $s) {
        $out[$s] = ghg_detail_collapse_events(array_values(array_filter($items, fn($it) => (int) $it['scope'] === $s)));
    }
    return $out;
}

/**
 * จัดกลุ่มรายการปล่อย / ดูดกลับตามกิจกรรม — ผลรวม emit = event_total, ผลรวม removal = ยอดดูดกลับของคณะ
 * @return array [['id','name','date','end_date','emit'=>float,'removal'=>float,'emit_rows'=>[],'removal_rows'=>[]], ...]
 *               เรียงยอดปล่อย + ดูดกลับ มาก → น้อย แล้วตามชื่อ
 */
function dean_dash_event_groups(array $eventRows, array $removalRows): array
{
    $g = [];
    $add = function (array $r, string $kind) use (&$g) {
        $id = (int) ($r['event_id'] ?? 0);
        $g[$id] ??= ['id' => $id, 'name' => (string) ($r['event_name'] ?? ''), 'date' => (string) ($r['event_date'] ?? ''),
                     'end_date' => (string) ($r['event_end_date'] ?? ''), 'emit' => 0.0, 'removal' => 0.0, 'emit_rows' => [], 'removal_rows' => []];
        $row = ['name' => (string) ($r['name_tiem'] ?? ''), 'unit' => (string) ($r['unit'] ?? ''), 'factor' => (float) ($r['factor'] ?? 0),
                'qty' => (float) ($r['qty'] ?? 0), 'emission' => (float) ($r['emission'] ?? 0)];
        if ($kind === 'emit') { $row['scope'] = (int) ($r['scope'] ?? 0); $g[$id]['emit_rows'][] = $row; $g[$id]['emit'] += $row['emission']; }
        else { $g[$id]['removal_rows'][] = $row; $g[$id]['removal'] += $row['emission']; }
    };
    foreach ($eventRows as $r) $add($r, 'emit');
    foreach ($removalRows as $r) $add($r, 'removal');
    $out = array_values($g);
    usort($out, fn($a, $b) => [$b['emit'] + $b['removal'], $a['name']] <=> [$a['emit'] + $a['removal'], $b['name']]);
    return $out;
}

/** แถวของคณะตัวเองในอันดับ (ghg_affiliation_ranking()) · ยังไม่มีข้อมูลการดำเนินงาน → null */
function dean_dash_own_rank(array $ranking, int $affil): ?array
{
    foreach ($ranking as $r) if ((int) $r['affil_id'] === $affil) return $r;
    return null;
}

/**
 * ส่วนบนของการ์ดอันดับ: เทียบคณะตัวเองกับค่าเฉลี่ยและอันดับ 1 + ตำแหน่งจุดบนแถบ (ไม่แตะฐานข้อมูล)
 * - avg        = ค่าเฉลี่ยของหน่วยงานที่มีข้อมูล (การดำเนินงาน)
 * - diff_avg   = ยอดคณะ − ค่าเฉลี่ย (ติดลบ = ต่ำกว่าค่าเฉลี่ย) · ไม่มีข้อมูลของคณะ → null
 * - below_top  = คณะปล่อยน้อยกว่าอันดับ 1 กี่ % · คณะเป็นอันดับ 1 / ไม่มีข้อมูล → null
 * - dots       = จุดละหน่วยงานเรียงตามอันดับ (มาก → น้อย) · pos = ตำแหน่งกึ่งกลางจุด (% ของแถบ) · own_pos = ตำแหน่งจุดของคณะ
 *
 * @return array ['avg'=>float, 'diff_avg'=>?float, 'below_top'=>?float, 'dots'=>[['rank','name','own'=>bool,'pos'=>float], ...], 'own_pos'=>?float]
 */
function dean_dash_rank_hero(array $ranking, ?array $own): array
{
    $n = count($ranking);
    $avg = $n ? array_sum(array_column($ranking, 'total')) / $n : 0.0;
    $dots = []; $ownPos = null;
    foreach ($ranking as $k => $r) {
        $pos = round(($k + 0.5) / $n * 100, 2);
        $isOwn = $own !== null && (int) $r['affil_id'] === (int) $own['affil_id'];
        if ($isOwn) $ownPos = $pos;
        $dots[] = ['rank' => (int) $r['rank'], 'name' => (string) $r['name'], 'own' => $isOwn, 'pos' => $pos];
    }
    $top = $n ? (float) $ranking[0]['total'] : 0.0;
    return [
        'avg'       => $avg,
        'diff_avg'  => $own !== null ? (float) $own['total'] - $avg : null,
        'below_top' => ($own !== null && (int) $own['rank'] > 1 && $top > 0) ? (1 - (float) $own['total'] / $top) * 100 : null,
        'dots'      => $dots,
        'own_pos'   => $ownPos,
    ];
}

/** การปล่อยสะสมทุกปี (ผลรวม gross รายปี) · $affil = null → ทั้งมหาวิทยาลัย */
function dean_dash_cumulative(PDO $pdo, array $years, ?int $affil): float
{
    $t = 0.0;
    foreach ($years as $y) $t += ghg_report_summary($pdo, (int) $y['year_id'], $affil)['gross'];
    return $t;
}

/**
 * ภาพรวมทั้งหน้า — รวมทุก query ไว้ที่เดียว
 * @return array summary, offset, compare, badge, items, detail_rows, fill, event_groups, event_count, history, cumulative, ranking, own_rank,
 *               uni_history, uni_cumulative, affil_total
 */
function dean_dash_overview(PDO $pdo, array $years, int $year, int $affil): array
{
    $summary = ghg_report_summary($pdo, $year, $affil);
    $cmp     = ghg_year_comparison($pdo, $years, $year, $affil);
    $items   = dean_dash_items($pdo, $affil, $year, $summary);
    $evc = $pdo->prepare('SELECT COUNT(*) FROM event WHERE affiliation_id = :a AND year_id = :y');
    $evc->execute([':a' => $affil, ':y' => $year]);
    // อันดับรายคณะ/หน่วยงาน ชุดเดียวกับหน้ารายงาน GHG มุมมองทั้งมหาวิทยาลัย (คณบดีเปิดแท็บนั้นได้อยู่แล้ว)
    $ranking = ghg_affiliation_ranking(ghg_by_affiliation($pdo, $year), ghg_scope_by_affiliation($pdo, $year));
    return [
        'summary'      => $summary,
        'offset'       => ghg_offset_pct($summary['gross'], $summary['removal']),
        'compare'      => $cmp,
        'badge'        => ghg_change_badge($summary['gross'], $cmp['prev']['gross'] ?? null),
        'items'        => $items,
        'detail_rows'  => dean_dash_detail_rows($items),
        'fill'         => ghg_item_fill($items),
        'event_groups' => dean_dash_event_groups($summary['event_rows'], $summary['removal_rows']),
        'event_count'  => (int) $evc->fetchColumn(),
        'history'      => ghg_scope_history($pdo, $years, $affil),
        'cumulative'   => dean_dash_cumulative($pdo, $years, $affil),
        'ranking'      => $ranking,
        'own_rank'     => dean_dash_own_rank($ranking, $affil),
        // ปุ่มสลับกราฟ "ทั้งมหาวิทยาลัย" — ฟังก์ชันเดียวกับ Dashboard admin / หน้ารายงาน
        'uni_history'    => ghg_scope_history($pdo, $years, null),
        'uni_cumulative' => dean_dash_cumulative($pdo, $years, null),
        'affil_total'    => (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn(),
    ];
}
