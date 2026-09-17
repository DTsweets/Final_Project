<?php
/**
 * DEAN REPORTS — dean/reports.php (ดูอย่างเดียว + ดาวน์โหลด Excel/PDF)
 * มุมมอง: ทั้งระบบ (system) หรือ คณะของฉัน (faculty)
 *
 * หน้าตาชุดเดียวกับ Dashboard: oe-head + dropdown ปี (component) · แท็บ co-tabs · การ์ด ad-kpi · แผง oe-panel · สไตล์ assets/css/reports.css (ไม่มี inline style / <style> / onclick)
 * เลย์เอาต์เดียวกันทั้งสองมุมมอง: KPI 3 ใบ → แหล่งปล่อยตามขอบเขต + ประวัติย้อนหลัง → ส่วนเฉพาะมุมมอง
 *   คณะ          — โดนัทสัดส่วนรายการในแต่ละขอบเขต
 *   ทั้งมหาวิทยาลัย — อันดับการปล่อยรายหน่วยงาน (แถบแบ่งสีตามขอบเขต)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);
// กัน browser cache HTML เก่า (แก้ inline <style> แล้วบางทีไม่โหลดใหม่)
header('Cache-Control: no-cache, no-store, must-revalidate');

$pdo  = getDB();
$root = '../';
$affil_id   = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
// บัญชี dean ที่ยังไม่ผูกคณะ → ทุก query จะคืนค่าว่าง ต้องบอกสาเหตุ ไม่ใช่ปล่อยให้เข้าใจผิดว่า "ยังไม่มีข้อมูล"
$affil_missing = (($_SESSION['role'] ?? '') === 'dean') && $affil_id === 0;
// admin เปิดจากลิงก์บน Dashboard admin → ใช้เมนูข้าง/แถบหัวของ admin (ถ้าใช้ของคณบดี admin จะหลุดไปอยู่ในเมนูคณบดี)
$is_admin   = ($_SESSION['role'] ?? '') === 'admin';
$page_title = "รายงาน GHG";

$years = ghg_years($pdo);
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($years[0]['year_id'] ?? 0);
$year_label = '';
foreach ($years as $y) { if ($y['year_id'] == $selected_year) { $year_label = $y['year']; break; } }

// มุมมองรายงาน — ใช้ตัวเดียวกับ export_report.php / report_print.php (กันหน้าเว็บกับไฟล์ไม่ตรงกัน)
$view = ghg_resolve_view($_SESSION['role'] ?? '', $_GET['view'] ?? null);
$aff  = $view === 'faculty' ? $affil_id : null;          // null = ทั้งมหาวิทยาลัย

// ── ยอดสรุปตามมุมมอง — ฟังก์ชันกลางตัวเดียวกับ Excel/PDF (กันสูตรแยกกันแล้วตัวเลขไม่ตรง) ──
// การดูดกลับ: มุมมองคณะ = กิจกรรมของคณะ / ทั้งระบบ = ระดับมหาวิทยาลัย
// ยอดปล่อยรวม (gross) รวมการปล่อยจากกิจกรรมด้วย, Net = ปล่อยทั้งหมด − ดูดกลับ
$sum          = ghg_report_summary($pdo, $selected_year, $aff);
$total        = $sum['operation_total'];
$removal      = $sum['removal'];
$event_total  = $sum['event_total'];
$gross_scope  = $sum['scope'];
$gross_total  = $sum['gross'];
$net          = $sum['net'];
$offset       = ghg_offset_pct($gross_total, $removal);          // ดูดกลับชดเชยการปล่อยได้กี่ %

// เทียบปีก่อนเฉพาะเมื่อปีก่อนมีข้อมูลการดำเนินงานจริง (กัน % หลักหมื่นจากปีที่ยังไม่ได้กรอก) — ไม่เทียบก็แค่ไม่มีป้าย %
$cmp        = ghg_year_comparison($pdo, $years, (int) $selected_year, $aff);
$prev       = $cmp['prev'];
$categories = ghg_category_totals($pdo, (int) $selected_year, $aff);
// "ในจำนวนนี้" ใต้แถบขอบเขต 3: ส่วนที่มาจากกิจกรรม / แบบสอบถาม (ทั้งสองแหล่งอยู่ขอบเขต 3 เสมอ)
$indirect   = $view === 'faculty'
    ? ['event' => $sum['event_total'], 'survey' => $sum['survey_total']]
    : ghg_indirect_source_totals($pdo, (int) $selected_year);
// ประวัติย้อนหลัง: ปีที่เลือก + ย้อนหลัง 1 ปี (ทั้งสองมุมมอง)
$history    = ghg_scope_history($pdo, ghg_history_years($years, (int) $selected_year, 1), $aff);
// สเกลกราฟแหล่งปล่อย/ประวัติ: เพดานแบบขั้น (ทั้งมหาวิทยาลัย 15,000 → 25,000 → … / คณะ 1,000 → 2,000 → …)
// คิดจากค่าสูงสุดของทุกปีในกราฟประวัติ (รวมปีที่เลือกแล้ว) → สองกราฟใช้เพดานเดียวกัน ปีย้อนหลังดูสมส่วน
$hist_max   = 0.0;
foreach ($history as $h) $hist_max = max($hist_max, (float) $h['s1'], (float) $h['s2'], (float) $h['s3']);
$scale      = ghg_view_scale($view, $hist_max);
$bar_scale  = $scale['ceiling'];
$hist_ticks = $scale['ticks'];

// อันดับรายหน่วยงาน (เฉพาะการดำเนินงาน) — บอกอันดับของคณะในมุมมองคณะ
// (ตารางอันดับทั้งหมดย้ายไปอยู่ที่ Dashboard คณบดี / Dashboard admin แล้ว — หน้ารายงานไม่แสดงซ้ำ)
$ranking  = ghg_affiliation_ranking(ghg_by_affiliation($pdo, (int) $selected_year), ghg_scope_by_affiliation($pdo, (int) $selected_year));
$own_rank = null;
foreach ($ranking as $rk) if ($rk['affil_id'] === $affil_id) { $own_rank = $rk; break; }

// มุมมองคณะ: โดนัทแยกราย Scope (แต่ละชิ้น = 1 รายการที่คณะกรอก)
// ขอบเขต 3 มีชิ้น "กิจกรรมที่คณะจัด" และ "แบบสอบถาม" ต่อท้าย → ยอดกลางโดนัทเท่ากับยอดขอบเขตบนการ์ด
$scope_items = $view === 'faculty'
    ? ghg_scope_item_breakdown(ghg_affil_detail($pdo, $affil_id, (int) $selected_year), 8, $sum['event_rows'], $sum['survey_rows'])
    : [];
// จานสีไล่เฉดในตระกูลสีของแต่ละ Scope (S1 ส้ม / S2 ชมพู / S3 ฟ้า) — วนซ้ำถ้ารายการเยอะ
$scope_palette = [
    1 => ['#F97316', '#FB923C', '#FDBA74', '#EA580C', '#C2410C', '#FED7AA', '#9A3412', '#FFEDD5', '#7C2D12'],
    2 => ['#EC4899', '#F472B6', '#F9A8D4', '#DB2777', '#BE185D', '#FBCFE8', '#9D174D', '#FCE7F3', '#831843'],
    3 => ['#3B82F6', '#60A5FA', '#93C5FD', '#2563EB', '#1D4ED8', '#BFDBFE', '#1E40AF', '#DBEAFE', '#1E3A8A'],
];
$scope_color = [1 => '#F97316', 2 => '#EC4899', 3 => '#3B82F6'];
// สีชิ้นโดนัท: กิจกรรม (เหลืองอำพัน) / แบบสอบถาม (เขียวอมฟ้า) ใช้สีคงที่ ไม่ปนกับโทนฟ้าของขอบเขต 3
$slice_color = fn(int $s, int $k, array $it): string
    => ['event' => '#F59E0B', 'survey' => '#14B8A6'][$it['source'] ?? ''] ?? $scope_palette[$s][$k % count($scope_palette[$s])];

// ป้ายเปลี่ยนแปลงเทียบปีก่อน → ad-badge (สีจาก class ไม่ใช่ inline style)
$chip = function (float $cur, ?float $prv, bool $up_good = false) use ($cmp): string {
    if ($prv === null) return '';
    $b = ghg_change_badge($cur, $prv, $up_good);
    return '<span class="rp-chips"><span class="ad-badge rpf-chip is-' . $b['tone'] . '">' . $b['text'] . '</span> เทียบปี '
        . htmlspecialchars((string) ($cmp['prev_year']['year'] ?? '')) . '</span>';
};
$h   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$n2  = fn(float $v) => number_format($v, 2);
$pct = fn(float $v) => number_format($v, 2, '.', '');

// ข้อความใต้การ์ด KPI ตามมุมมอง (ตัวเลขเป็น <b> ให้อ่านง่าย)
$gross_parts = $view === 'faculty'
    ? ['ดำเนินงาน <b>' . $n2($total) . '</b>', 'กิจกรรม <b>' . $n2($event_total) . '</b>', 'แบบสอบถาม <b>' . $n2($sum['survey_total']) . '</b>']
    : ['จาก <b>' . count($ranking) . '</b> หน่วยงานที่มีข้อมูล', 'รวมแบบสอบถามและกิจกรรม'];
$removal_parts = $view === 'faculty'
    ? ['จากกิจกรรมที่คณะจัด']
    : ['ส่วนกลาง <b>' . $n2(removal_central_total($pdo, (int) $selected_year)) . '</b>', 'กิจกรรม <b>' . $n2(removal_activity_total($pdo, (int) $selected_year)) . '</b>'];

$dl = 'view=' . $view . '&year=' . $selected_year;
$svg_dl = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
$svg_pdf = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงาน GHG<?= $is_admin ? '' : ' (คณบดี)' ?> — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin-dashboard.css<?= asset_v('assets/css/admin-dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/reports.css<?= asset_v('assets/css/reports.css') ?>">
</head>
<body>

    <?php include $is_admin ? __DIR__ . '/../admin/includes/sidebar.php' : __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include $is_admin ? __DIR__ . '/../admin/includes/header.php' : __DIR__ . '/../officer/includes/header.php'; ?>

        <div class="oe-page ad-page rp-page" id="rpPage" data-view="<?= $view ?>">
            <div class="oe-head oe-rise">
                <div>
                    <h1 class="oe-title">รายงานการปล่อยก๊าซเรือนกระจก</h1>
                    <div class="oe-sub">สรุปการปล่อย การดูดกลับ และประวัติย้อนหลัง · ดาวน์โหลดเป็น Excel / PDF ได้</div>
                </div>
                <div class="oe-actions rp-head-actions">
                    <?php if ($years): ?>
                    <span class="co-year-label">ปีงบประมาณ</span>
                    <?php
                    $dd_id = 'rpYear'; $dd_name = 'year_nav'; $dd_selected = $selected_year; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:120px;';
                    $dd_options = array_map(fn($y) => ['value' => $y['year_id'], 'label' => (string) $y['year']], $years); $dd_placeholder = 'เลือกปี';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
                    <?php endif; ?>
                    <a href="export_report.php?<?= $h($dl) ?>" class="oe-btn rp-btn-excel"><?= $svg_dl ?> ดาวน์โหลด Excel</a>
                    <a href="report_print.php?<?= $h($dl) ?>" target="_blank" rel="noopener" class="oe-btn rp-btn-pdf"><?= $svg_pdf ?> ดาวน์โหลด PDF</a>
                </div>
            </div>

            <!-- มุมมอง: ทั้งมหาวิทยาลัย / คณะ (พื้นเลื่อนก่อนเปลี่ยนหน้า) -->
            <nav class="co-tabs rp-tabs oe-rise" style="--i:1;" data-tab="<?= $view ?>" aria-label="เลือกมุมมองรายงาน">
                <span class="co-tab-ind" aria-hidden="true"></span>
                <a class="co-tab<?= $view === 'system' ? ' is-on' : '' ?>" href="?view=system&amp;year=<?= $selected_year ?>" data-tab="system"<?= $view === 'system' ? ' aria-current="page"' : '' ?>>ทั้งมหาวิทยาลัย</a>
                <a class="co-tab<?= $view === 'faculty' ? ' is-on' : '' ?>" href="?view=faculty&amp;year=<?= $selected_year ?>" data-tab="faculty"<?= $view === 'faculty' ? ' aria-current="page"' : '' ?>><?= ($_SESSION['role'] ?? '') === 'dean' ? 'คณะของฉัน' : 'รายคณะ' ?></a>
            </nav>

            <?php if ($affil_missing && $view === 'faculty'): /* มุมมองทั้งมหาวิทยาลัยไม่ได้ใช้สังกัด จึงไม่ต้องเตือน */ ?>
            <div class="rp-alert oe-rise" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>บัญชีของคุณยังไม่ได้ผูกกับคณะ/หน่วยงาน — รายงานจึงแสดงค่าเป็น 0 ทั้งหมด
                <small>กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดสังกัดให้บัญชีนี้ (ไม่ใช่ว่ายังไม่มีการกรอกข้อมูล)</small></span>
            </div>
            <?php endif; ?>

            <h2 class="rp-section oe-rise" style="--i:2;">
                <?= $view === 'faculty' ? 'คณะของฉัน — ' . $h($affil_name) : 'ทั้งมหาวิทยาลัย (ทุกคณะ/หน่วยงาน)' ?> · ปี <?= $h($year_label) ?>
                <?php if ($view === 'faculty' && $own_rank): ?>
                <span class="rpf-own-rank rp-own-rank">อันดับ <?= $own_rank['rank'] ?> จาก <?= count($ranking) ?> หน่วยงาน (การดำเนินงาน)</span>
                <?php endif; ?>
            </h2>

            <!-- KPI: ปล่อยทั้งหมด / ดูดกลับ / Net (ตัวเลขนับขึ้น) -->
            <div class="ad-hero rp-kpis">
                <section class="ad-kpi ad-k-gross rpf-kpi oe-rise" style="--i:3;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('factory', 22) ?></span><span class="ad-kpi-label">การปล่อยทั้งหมด</span></div>
                    <div class="ad-kpi-val"><b class="rp-gross" data-count="<?= $pct($gross_total) ?>"><?= $n2($gross_total) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts"><?php foreach ($gross_parts as $p): ?><span><?= $p ?></span><?php endforeach; ?></div>
                    <?php if ($prev): ?><div class="ad-kpi-foot"><?= $chip($gross_total, $prev['gross']) ?></div><?php endif; ?>
                </section>
                <section class="ad-kpi ad-k-removal rpf-kpi oe-rise" style="--i:4;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('leaf', 22) ?></span><span class="ad-kpi-label">การดูดกลับ</span></div>
                    <div class="ad-kpi-val"><b class="rp-removal" data-count="<?= $pct($removal) ?>"><?= $n2($removal) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts"><?php foreach ($removal_parts as $p): ?><span><?= $p ?></span><?php endforeach; ?></div>
                    <?php if ($prev): ?><div class="ad-kpi-foot"><?= $chip($removal, $prev['removal'], true) ?></div><?php endif; ?>
                </section>
                <section class="ad-kpi ad-k-net rpf-kpi oe-rise" style="--i:5;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('globe', 22) ?></span><span class="ad-kpi-label">สุทธิ (Net) · ติดตาม Net Zero</span></div>
                    <div class="ad-kpi-val"><b class="rp-net" data-count="<?= $pct($net) ?>"><?= $n2($net) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts"><span>= ปล่อยทั้งหมด − ดูดกลับ</span></div>
                    <?php if ($offset !== null): ?>
                    <!-- ความคืบหน้าสู่ Net Zero: ดูดกลับชดเชยการปล่อยได้กี่ % -->
                    <div class="ad-offset rpf-offset">
                        <div class="ad-offset-row"><span>ดูดกลับชดเชยได้</span><b><?= $offset >= 100 ? 'ครบ 100% (Net ติดลบ)' : number_format($offset, 1) . '%' ?></b></div>
                        <div class="ad-track"><span class="ad-fill ad-fill-green" style="width:<?= $pct(min($offset, 100)) ?>%;"></span></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($prev): ?><div class="ad-kpi-foot"><?= $chip($net, $prev['net']) ?></div><?php endif; ?>
                </section>
            </div>

            <div class="rp-row">
                <!-- แหล่งปล่อย: แท่งรายขอบเขต + หมวดย่อยใต้แต่ละขอบเขต (ความกว้าง = ยอด ÷ เพดานสเกลเดียวกับกราฟประวัติ) -->
                <section class="oe-panel oe-rise" style="--i:6;">
                    <div class="co-panel-head"><h2 class="co-h2">แหล่งปล่อยตามขอบเขต <span class="rp-unit">(ปล่อยทั้งหมด · tCO₂e)</span></h2></div>
                    <?php
                    $bar_pct = ghg_scope_bar_percents($gross_scope, $bar_scale);
                    foreach ([1, 2, 3] as $s):
                        $share = $gross_total > 0 ? $gross_scope[$s] / $gross_total * 100 : 0; ?>
                    <div class="rp-scope" style="--sc:<?= $scope_color[$s] ?>;--i:<?= $s - 1 ?>;">
                        <div class="hbar-row">
                            <span class="hbar-label">ขอบเขต <?= $s ?></span>
                            <div class="hbar-track"><span class="hbar-fill" style="--w:<?= $pct($bar_pct[$s]) ?>%;"></span></div>
                            <span class="hbar-val"><?= number_format($gross_scope[$s], 2, '.', ',') ?> <small><?= number_format($share, 1) ?>%</small></span>
                        </div>
                        <?php if ($s === 3 && ($indirect['event'] > 0 || $indirect['survey'] > 0)): ?>
                        <div class="rpf-indirect">
                            ในจำนวนนี้: <i class="rp-dot" style="--c:#F59E0B;"></i> กิจกรรม <b><?= $n2($indirect['event']) ?></b>
                            · <i class="rp-dot" style="--c:#14B8A6;"></i> แบบสอบถาม <b><?= $n2($indirect['survey']) ?></b>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($categories as $cat): if ($cat['scope'] !== $s) continue;
                            $cp = $gross_scope[$s] > 0 ? $cat['value'] / $gross_scope[$s] * 100 : 0; ?>
                        <div class="rpf-cat<?= $cat['value'] > 0 ? '' : ' is-zero' ?>">
                            <span class="rp-cat-name" title="<?= $h($cat['name']) ?>"><?= $h(ghg_group_label($cat['name'])) ?></span>
                            <span class="rp-cat-track"><span class="rp-cat-fill" style="--w:<?= $pct(min($cp, 100)) ?>%;"></span></span>
                            <span class="rp-cat-val"><?= $n2($cat['value']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </section>

                <!-- ประวัติย้อนหลัง: ปีที่เลือก + ย้อนหลัง 1 ปี (HTML — canvas ถูกย่อตามความกว้างการ์ด ตัวอักษรบนแกนเล็กจนอ่านไม่ออก) -->
                <section class="oe-panel oe-rise" style="--i:7;">
                    <div class="co-panel-head"><h2 class="co-h2">ประวัติข้อมูลย้อนหลัง <span class="rp-unit">(ปล่อยทั้งหมด · tCO₂e)</span></h2></div>
                    <div class="rpf-hist">
                        <?php if ($hist_ticks): ?>
                        <!-- แกนสเกล: โครงเดียวกับคอลัมน์ปี + ป้ายปีแบบซ่อน → ระดับตรงกับแท่งพอดี -->
                        <div class="rpf-hist-axis">
                            <div class="rp-axis-plot"><div class="rp-axis-scale">
                                <?php foreach ($hist_ticks as $t): ?>
                                <span class="rpf-hist-tick" style="--b:<?= $pct($t['pct']) ?>%;"><?= number_format($t['value']) ?></span>
                                <?php endforeach; ?>
                            </div></div>
                            <div class="rp-hist-foot is-ghost" aria-hidden="true"><b>ปี</b><span>รวม</span></div>
                        </div>
                        <?php endif; ?>
                        <?php $hist_bars = ghg_history_bars($history, $bar_scale); $bi = 0;
                        foreach ($hist_bars as $hi => $hb): $is_cur = $hb['year'] === (string) $year_label; ?>
                        <div class="rpf-hist-year<?= $is_cur ? ' is-cur' : '' ?>">
                            <div class="rp-hist-plot">
                                <?php if ($hist_ticks): /* เส้นบอกสเกล (ต่อเนื่องข้ามช่องว่างระหว่างปี) — อยู่หลังแท่ง */ ?>
                                <div class="rpf-hist-grid<?= $hi === 0 ? ' is-first' : '' ?><?= $hi === count($hist_bars) - 1 ? ' is-last' : '' ?>">
                                    <?php foreach ($hist_ticks as $t): if ($t['value'] <= 0) continue; ?>
                                    <span class="rpf-hist-line" style="--b:<?= $pct($t['pct']) ?>%;"></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php foreach ([1, 2, 3] as $s): ?>
                                <div class="rp-hist-col" style="--sc:<?= $scope_color[$s] ?>;--i:<?= $bi++ ?>;">
                                    <span class="rp-hist-num"><?= $n2($hb['s' . $s]) ?></span>
                                    <div class="rpf-hist-bar" title="ขอบเขต <?= $s ?> · <?= number_format($hb['s' . $s], 4) ?> tCO₂e" style="--h:<?= $pct($hb['h' . $s]) ?>%;"></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="rp-hist-foot"><b>ปี <?= $h($hb['year']) ?></b><span>รวม <?= $n2($hb['total']) ?></span></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="rp-legend">
                        <?php foreach ([1, 2, 3] as $s): ?><span style="--sc:<?= $scope_color[$s] ?>;"><i></i>ขอบเขต <?= $s ?></span><?php endforeach; ?>
                    </div>
                </section>
            </div>

            <?php if ($view === 'faculty'): ?>
            <!-- โดนัทแยกราย Scope: แต่ละชิ้น = 1 รายการที่คณะกรอก -->
            <section class="oe-panel rp-donut-panel oe-rise" style="--i:8;">
                <div class="co-panel-head"><h2 class="co-h2">สัดส่วนการปล่อยรายกิจกรรม แยกตามขอบเขต</h2></div>
                <p class="rp-donut-sub">แต่ละชิ้นคือรายการการดำเนินงานของคณะ · ขอบเขต 3 รวมกิจกรรมที่คณะจัดและแบบสอบถาม · หน่วย tCO₂e · รายการเต็มดูได้ในไฟล์ Excel</p>
                <div class="rpf-donuts">
                    <?php foreach ([1, 2, 3] as $s):
                        $items = $scope_items[$s]['items'];
                        $stot  = $scope_items[$s]['total']; ?>
                    <div class="rp-donut" style="--i:<?= $s - 1 ?>;">
                        <span class="rp-scope-badge is-s<?= $s ?>">ขอบเขต <?= $s ?></span>
                        <!-- ยอดรวมของขอบเขตอยู่กลางโดนัท (data-center) -->
                        <canvas id="scopeItemDonut<?= $s ?>" width="200" height="200" data-center="<?= number_format($stot, 2, '.', ',') ?>"></canvas>
                        <div class="rp-donut-unit">tCO₂e</div>
                        <?php if (empty($items)): ?>
                        <div class="rp-donut-empty">ยังไม่มีข้อมูล</div>
                        <?php else: ?>
                        <!-- รายการ: ชื่อ + สัดส่วน % ในขอบเขต (ค่า tCO₂e เต็มอยู่ใน title) -->
                        <div class="rp-legend-list">
                            <?php foreach ($items as $k => $it): $ip = $stot > 0 ? $it['value'] / $stot * 100 : 0; ?>
                            <div class="rpf-legend" title="<?= $h($it['name']) ?> · <?= number_format($it['value'], 4) ?> tCO₂e">
                                <i style="--c:<?= $slice_color($s, $k, $it) ?>;"></i><span><?= $h($it['name']) ?></span><b><?= number_format($ip, 1) ?>%</b>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <script src="<?= $root ?>assets/js/ghg-charts.js<?= asset_v('assets/js/ghg-charts.js') ?>"></script>
        <script>
            window.__SCOPE_ITEMS = <?= json_encode(
                array_map(
                    fn($s) => array_map(
                        fn($it, $k) => ['label' => $it['name'], 'value' => $it['value'], 'color' => $slice_color($s, $k, $it)],
                        $scope_items[$s]['items'] ?? [],
                        array_keys($scope_items[$s]['items'] ?? [])
                    ),
                    [1 => 1, 2 => 2, 3 => 3]
                ),
                JSON_UNESCAPED_UNICODE
            ) ?>;
            window.__HISTORY = <?= json_encode($history, JSON_UNESCAPED_UNICODE) ?>;
            // IIFE — SPA รันสคริปต์ซ้ำทุกครั้งที่เข้าหน้านี้ (ไม่มี let/const ระดับบนสุด) · ผูกกับ element ของหน้านี้เท่านั้น
            (function () {
                var d = document, page = d.getElementById('rpPage');
                if (!page) return;
                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                // เปลี่ยนปี (dropdown component) → คงมุมมองเดิม
                var yd = d.getElementById('rpYear');
                if (yd) yd.addEventListener('dd:change', function (e) {
                    location.href = '?view=' + encodeURIComponent(page.dataset.view) + '&year=' + encodeURIComponent(e.detail.value);
                });

                // แท็บมุมมอง: พื้นเลื่อนไปก่อนแล้วค่อยเปลี่ยนหน้า
                page.querySelectorAll('.rp-tabs .co-tab').forEach(function (a) {
                    a.addEventListener('click', function (e) {
                        if (a.classList.contains('is-on') || e.ctrlKey || e.metaKey || e.shiftKey) return;
                        e.preventDefault();
                        var nav = a.closest('.co-tabs');
                        nav.dataset.tab = a.dataset.tab;
                        nav.querySelectorAll('.co-tab').forEach(function (t) { t.classList.toggle('is-on', t === a); });
                        setTimeout(function () { location.href = a.href; }, reduce ? 0 : 220);
                    });
                });

                // ตัวเลขการ์ดนับขึ้น (ค่าจริงอยู่ในหน้าแล้ว — ไม่มีเฟรม = ค้างที่ค่าจริง)
                if (!reduce) page.querySelectorAll('[data-count]').forEach(function (el) {
                    var to = Number(el.dataset.count) || 0, t0 = null;
                    window.requestAnimationFrame(function frame(ts) {
                        if (!el.isConnected) return;
                        if (t0 === null) t0 = ts;
                        var p = Math.min(1, (ts - t0) / 900), v = to * (1 - Math.pow(1 - p, 3));
                        el.textContent = v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        if (p < 1) window.requestAnimationFrame(frame);
                    });
                });

                // โดนัทแยกราย Scope — เฉพาะมุมมองคณะ (มุมมองทั้งมหาวิทยาลัยไม่มี canvas)
                if (window.drawGhgDonut && window.__SCOPE_ITEMS) {
                    [1, 2, 3].forEach(function (s) {
                        var el = d.getElementById('scopeItemDonut' + s);
                        if (el) window.drawGhgDonut(el, window.__SCOPE_ITEMS[s] || [], el.dataset.center || String(s));
                    });
                }
            })();
        </script>
    </main>
</body>
</html>
