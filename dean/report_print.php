<?php
/**
 * DEAN — รายงานคาร์บอนฟุตพริ้นท์ขององค์กร (ฉบับผู้บริหาร) สำหรับพิมพ์/บันทึกเป็น PDF ผ่านเบราว์เซอร์
 * params: view=system|faculty, year=<id>
 *
 * โครงสร้าง (A4): ปก + บทสรุปผู้บริหาร (เทียบปีก่อนหน้า) → ผลแยกตามแหล่งปล่อย → [ทั้งระบบ] รายหน่วยงาน → วิธีการคำนวณ + ลงนาม
 * รายการละเอียดทีละบรรทัดอยู่ในไฟล์ Excel (export_report.php) — ฉบับนี้เน้นอ่านเร็วสำหรับการตัดสินใจ
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);

$pdo = getDB();
$affil_id   = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
// มุมมองต้องตรงกับหน้า reports.php เสมอ — ใช้ฟังก์ชันกลางตัวเดียวกัน
$view = ghg_resolve_view($_SESSION['role'] ?? '', $_GET['view'] ?? null);
$aff  = $view === 'faculty' ? $affil_id : null;

// ปีที่รายงาน — ไม่ระบุ/ไม่พบ → ใช้ปีล่าสุด (หัวรายงานแสดงปีที่ใช้จริงเสมอ)
$years = ghg_years($pdo);
$year  = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$year_label = '';
foreach ($years as $y) { if ((int)$y['year_id'] === $year) { $year_label = (string)$y['year']; break; } }
if ($year_label === '' && $years) { $year = (int)$years[0]['year_id']; $year_label = (string)$years[0]['year']; }

// ── ตัวเลขปีนี้ + ปีก่อนหน้า (นิยามเดียวกับหน้าเว็บ) ──
$sum  = ghg_report_summary($pdo, $year, $aff);
// เทียบปีก่อนเฉพาะเมื่อปีก่อนมีข้อมูลการดำเนินงานจริง — เดิมเทียบกับปีที่ยังไม่ได้กรอก ได้ % หลักหมื่นที่ไม่มีความหมาย
$cmp       = ghg_year_comparison($pdo, $years, $year, $aff);
$prev_year = $cmp['prev_year'];
$prev      = $cmp['prev'];                       // null = ซ่อนคอลัมน์ปีก่อน/เปลี่ยนแปลง (ไม่แสดงหมายเหตุ — ตรงกับหน้าเว็บ)

$categories = ghg_category_totals($pdo, $year, $aff);
$cat_groups = ghg_categories_by_scope($categories);
$scope_pct  = array_map(fn($v) => $sum['gross'] > 0 ? $v / $sum['gross'] * 100 : 0.0, $sum['scope']);
$offset     = ghg_offset_pct($sum['gross'], $sum['removal']);   // ดูดกลับชดเชยการปล่อยได้กี่ %
$top_scope  = ghg_top_share($sum['scope']);
$top_cat    = ghg_top_share(array_column($categories, 'value'));
$top_cat_name = $top_cat ? ghg_group_label($categories[$top_cat['key']]['name']) : '';

// มุมมองทั้งระบบ: ตารางรายหน่วยงาน (เฉพาะการดำเนินงาน) — แถวรวมบวกจากแถวที่แสดงจริง
$rows = $view === 'system' ? array_values(array_filter(ghg_by_affiliation($pdo, $year), fn($r) => (float)$r['total_emission'] > 0)) : [];
$rows_total = ghg_affil_sum($rows);

// ผู้จัดทำ = ผู้ที่สั่งออกรายงาน
$preparer = null;
if (!empty($_SESSION['user_id'])) {
    $st = $pdo->prepare('SELECT firstname, lastname, position FROM users WHERE id = :id');
    $st->execute([':id' => (int)$_SESSION['user_id']]);
    $preparer = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$preparer_name = $preparer ? trim($preparer['firstname'] . ' ' . $preparer['lastname']) : '-';
$preparer_pos  = $preparer['position'] ?? '';

$org_unit = $view === 'faculty' ? $affil_name : 'ทั้งมหาวิทยาลัย (ทุกคณะ/หน่วยงาน)';
$affil_missing = $view === 'faculty' && $affil_id === 0;

// วันที่จัดทำต้องเป็นเวลาไทย (XAMPP ตั้งค่าเริ่มต้นเป็น Europe/Berlin → วันที่อาจเลื่อนไป 1 วัน)
date_default_timezone_set('Asia/Bangkok');
$thai_months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$today = (int)date('j') . ' ' . $thai_months[(int)date('n')] . ' ' . ((int)date('Y') + 543);

$scope_color = [1 => '#F97316', 2 => '#EC4899', 3 => '#3B82F6'];
$scope_desc  = [1 => 'การปล่อยโดยตรง', 2 => 'การปล่อยทางอ้อมจากการใช้พลังงาน', 3 => 'การปล่อยทางอ้อมอื่น ๆ'];

$n = fn($v) => number_format((float)$v, 2, '.', ',');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
// ป้ายการเปลี่ยนแปลง — $up_good: ค่าเพิ่มขึ้นเป็นเรื่องดีหรือไม่ (ดูดกลับ = ดี, ปล่อย/Net = ไม่ดี)
$chg = function (float $cur, ?float $prv, bool $up_good = false): string {
    $b = ghg_change_badge($cur, $prv, $up_good);
    return '<span class="chg ' . $b['tone'] . '">' . $b['text'] . '</span>';
};

// ── ข้อความสรุปผล: สร้างจากตัวเลขจริงเท่านั้น ──
$prev_label = $prev_year ? (string)$prev_year['year'] : '';
if ($sum['gross'] <= 0) {
    $finding = 'ปี ' . $year_label . ' ยังไม่มีข้อมูลการปล่อยก๊าซเรือนกระจกที่บันทึกในระบบ';
} else {
    $finding = 'ปี ' . $year_label . ' ' . $org_unit . ' ปล่อยก๊าซเรือนกระจกรวม <b>' . $n($sum['gross']) . ' tCO₂e</b>';
    $gc = ghg_change($sum['gross'], $prev ? $prev['gross'] : null);
    if ($gc['pct'] !== null) {
        $finding .= ' ' . ($gc['pct'] >= 0 ? 'เพิ่มขึ้น' : 'ลดลง') . ' ' . number_format(abs($gc['pct']), 1) . '% จากปี ' . $prev_label;
    }
    $finding .= ' แหล่งปล่อยหลักคือ <b>ขอบเขต ' . $top_scope['key'] . '</b> (' . number_format($top_scope['pct'], 1) . '%)';
    if ($top_cat) $finding .= ' โดยหมวดที่ปล่อยมากที่สุดคือ <b>' . $h($top_cat_name) . '</b> (' . number_format($top_cat['pct'], 1) . '% ของการปล่อยทั้งหมด)';
    $finding .= ' เมื่อหักการดูดกลับ ' . $n($sum['removal']) . ' tCO₂e คงเหลือการปล่อยสุทธิ <b>' . $n($sum['net']) . ' tCO₂e</b>';
}

// แถวตารางสรุป — นิยามกลางใน ghg_summary_lines() ใช้ร่วมกับไฟล์ Excel (ลำดับและคำเรียกตรงกันเสมอ)
// แปลงเป็น [ป้าย(HTML), class, ฟังก์ชันดึงค่าจาก summary, ค่าเพิ่มขึ้นดีไหม (null = ไม่แสดงเปลี่ยนแปลง)]
$summary_rows = array_map(fn($l) => [
    ($l['key'] === 'removal' ? '<span class="dot" style="background:#16A34A"></span>' : '') . $h($l['label'])
        . ($l['desc'] !== '' ? ' <span class="muted">· ' . $h($l['desc']) . '</span>' : ''),
    $l['kind'], fn($x) => $x[$l['key']], $l['up_good'],
], ghg_summary_lines($view));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานคาร์บอนฟุตพริ้นท์ขององค์กร — <?= $h($org_unit) ?> ปี <?= $h($year_label) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary:#62368B; --primary-soft:#F3EAFF; --ink:#1F2937; --muted:#6B7280; --line:#E5E7EB; --good:#15803D; --bad:#DC2626; }
        * { box-sizing:border-box; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        @page { size:A4; margin:14mm 14mm 16mm; }
        body { font-family:'Sarabun',sans-serif; color:var(--ink); margin:0; background:#EDEAF1; font-size:14px; line-height:1.55; }
        .toolbar { position:sticky; top:0; z-index:5; display:flex; justify-content:center; align-items:center; gap:14px; padding:12px; background:#fff; border-bottom:1px solid var(--line); }
        .toolbar button { background:var(--primary); color:#fff; border:none; padding:10px 22px; border-radius:8px; cursor:pointer; font-family:inherit; font-size:14px; display:inline-flex; align-items:center; gap:6px; }
        .toolbar span { color:var(--muted); font-size:13px; }
        .sheet { width:210mm; min-height:297mm; margin:18px auto; padding:14mm; background:#fff; box-shadow:0 2px 12px rgba(0,0,0,.08); }

        /* ปก */
        .cover { border-radius:14px; overflow:hidden; border:1px solid var(--line); margin-bottom:22px; }
        .cover-band { background:linear-gradient(120deg,#4A2470,#62368B 60%,#8B5CB8); color:#fff; padding:22px 24px; display:flex; gap:18px; align-items:center; }
        .cover-band img { width:64px; height:64px; object-fit:contain; background:#fff; border-radius:12px; padding:6px; }
        .cover-org { font-size:13px; opacity:.85; letter-spacing:.3px; }
        .cover h1 { font-size:24px; margin:2px 0 0; line-height:1.3; }
        .cover-unit { font-size:16px; font-weight:600; margin-top:2px; }
        .cover-meta { display:grid; grid-template-columns:repeat(4,1fr); }
        .cover-meta div { padding:10px 16px; border-right:1px solid var(--line); }
        .cover-meta div:last-child { border-right:none; }
        .cover-meta small { display:block; color:var(--muted); font-size:11.5px; }
        .cover-meta b { font-size:14px; }

        h2 { font-size:17px; color:var(--primary); margin:0 0 10px; padding-bottom:6px; border-bottom:2px solid var(--primary-soft); display:flex; align-items:baseline; gap:8px; }
        h2 .no { background:var(--primary); color:#fff; border-radius:6px; font-size:13px; padding:0 8px; }
        h2 small { color:var(--muted); font-weight:400; font-size:12px; margin-left:auto; }
        section { margin-bottom:22px; }

        /* KPI */
        .kpis { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:12px; }
        .kpi { border:1px solid var(--line); border-top:4px solid var(--c); border-radius:10px; padding:10px 14px; }
        .kpi small { color:var(--muted); font-size:12px; font-weight:600; }
        .kpi .v { font-size:24px; font-weight:800; color:var(--c); line-height:1.25; }
        .kpi .v span { font-size:12px; font-weight:500; color:var(--muted); }
        .kpi .sub { font-size:11.5px; color:var(--muted); }
        .finding { background:#FAF7FD; border-left:4px solid var(--primary); border-radius:6px; padding:10px 14px; margin-bottom:12px; font-size:14px; }

        /* ตาราง */
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { background:var(--primary); color:#fff; padding:7px 10px; text-align:left; font-weight:600; }
        td { padding:6px 10px; border-bottom:1px solid var(--line); vertical-align:middle; }
        .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .c { text-align:center; }
        tr.total td { font-weight:800; background:var(--primary-soft); border-bottom:none; }
        tr.strong td { font-weight:700; }
        tr.sub td { color:var(--muted); font-size:12px; padding-top:2px; padding-bottom:4px; }
        tr.sub td:first-child { padding-left:28px; }
        tr { break-inside:avoid; }
        .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; vertical-align:middle; }
        .muted { color:#9CA3AF; }
        .chg { font-weight:700; font-size:12px; white-space:nowrap; }
        .chg.good { color:var(--good); } .chg.bad { color:var(--bad); } .chg.flat { color:#9CA3AF; }

        /* แท่งสัดส่วน */
        .stack { display:flex; height:22px; border-radius:999px; overflow:hidden; background:#F3F1F6; margin:4px 0 8px; }
        .stack div { height:100%; }
        .scope-tiles { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:6px 0 14px; }
        .scope-tile { border:1px solid var(--line); border-left:4px solid var(--c); border-radius:8px; padding:7px 12px; }
        .st-head { font-size:13px; font-weight:700; display:flex; align-items:center; }
        .st-head b { margin-left:auto; color:var(--c); }
        .st-desc { font-size:11px; color:var(--muted); }
        .st-val { font-size:17px; font-weight:800; color:var(--c); }
        .st-val span { font-size:11px; font-weight:500; color:var(--muted); }
        .offset { margin-top:6px; }
        .offset-lbl { display:flex; justify-content:space-between; font-size:11px; color:var(--muted); }
        .offset-lbl b { color:var(--good); }
        .offset-track { height:5px; background:#EDE9F3; border-radius:999px; overflow:hidden; margin-top:2px; }
        .offset-track i { display:block; height:100%; background:#16A34A; border-radius:999px; }
        .cat-table tr.scope-row td { background:#FAF8FC; font-weight:700; border-bottom:1px solid #E9E4EF; }
        .cat-table tr.cat-row td:first-child { padding-left:28px; color:#374151; }
        .cat-table tr.cat-row.zero td { color:#9CA3AF; }
        .mini { display:flex; align-items:center; gap:6px; justify-content:flex-end; }
        .mini i { display:block; height:8px; border-radius:999px; background:var(--primary); min-width:1px; }
        .mini-track { width:70px; background:#F3F1F6; border-radius:999px; }

        .method { display:grid; grid-template-columns:1fr; gap:6px; }
        .formula { background:#F9FAFB; border:1px dashed #D1D5DB; border-radius:8px; padding:8px 14px; font-size:13.5px; }
        .method ul { margin:4px 0 0; padding-left:20px; font-size:13px; }
        .method li { margin-bottom:2px; }

        .sign { display:grid; grid-template-columns:1fr 1fr; gap:40px; margin-top:28px; break-inside:avoid; }
        .sign div { text-align:center; font-size:13px; }
        .sign .line { border-bottom:1px dotted #9CA3AF; height:36px; margin-bottom:6px; }
        .warn { background:#FEF3C7; border:1px solid #FCD34D; color:#92400E; padding:10px 14px; border-radius:8px; margin-bottom:14px; font-weight:600; }
        .foot { margin-top:18px; font-size:11px; color:#9CA3AF; text-align:center; }

        @media print {
            body { background:#fff; }
            .toolbar { display:none; }
            .sheet { width:auto; min-height:0; margin:0; padding:0; box-shadow:none; }
            .sheet + .sheet { break-before:page; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()"><?= ic('print',16) ?> พิมพ์ / บันทึกเป็น PDF</button>
        <span>แนะนำ: กระดาษ A4 · เปิด "กราฟิกพื้นหลัง" · รายการละเอียดดูในไฟล์ Excel</span>
    </div>

    <!-- ═══ หน้า 1: ปก + บทสรุปผู้บริหาร ═══ -->
    <div class="sheet">
        <div class="cover">
            <div class="cover-band">
                <img src="../assets/images/up-logo.webp" alt="">
                <div>
                    <div class="cover-org">มหาวิทยาลัยพะเยา · UP NET ZERO</div>
                    <h1>รายงานคาร์บอนฟุตพริ้นท์ขององค์กร</h1>
                    <div class="cover-unit"><?= $h($org_unit) ?></div>
                </div>
            </div>
            <div class="cover-meta">
                <div><small>ปีที่รายงาน</small><b><?= $h($year_label) ?></b></div>
                <div><small>เปรียบเทียบกับ</small><b><?= $prev ? 'ปี ' . $h($prev_label) : 'ไม่เปรียบเทียบ' ?></b></div>
                <div><small>ผู้จัดทำ</small><b><?= $h($preparer_name) ?></b></div>
                <div><small>วันที่จัดทำ</small><b><?= $h($today) ?></b></div>
            </div>
        </div>

        <?php if ($affil_missing): ?>
        <div class="warn">บัญชีนี้ยังไม่ได้ผูกกับคณะ/หน่วยงาน — ตัวเลขทั้งหมดจึงเป็น 0 กรุณาติดต่อผู้ดูแลระบบ</div>
        <?php endif; ?>

        <section>
            <h2><span class="no">1</span> บทสรุปผู้บริหาร</h2>

            <div class="kpis">
                <div class="kpi" style="--c:#EA580C;">
                    <small>การปล่อยทั้งหมด</small>
                    <div class="v"><?= $n($sum['gross']) ?> <span>tCO₂e</span></div>
                    <?php if ($prev): ?><div class="sub">เทียบปี <?= $h($prev_label) ?> <?= $chg($sum['gross'], $prev['gross']) ?></div><?php endif; ?>
                </div>
                <div class="kpi" style="--c:#15803D;">
                    <small>การดูดกลับ</small>
                    <div class="v"><?= $n($sum['removal']) ?> <span>tCO₂e</span></div>
                    <?php if ($prev): ?><div class="sub">เทียบปี <?= $h($prev_label) ?> <?= $chg($sum['removal'], $prev['removal'], true) ?></div><?php endif; ?>
                </div>
                <div class="kpi" style="--c:#62368B;">
                    <small>การปล่อยสุทธิ (Net)</small>
                    <div class="v"><?= $n($sum['net']) ?> <span>tCO₂e</span></div>
                    <?php if ($prev): ?><div class="sub">เทียบปี <?= $h($prev_label) ?> <?= $chg($sum['net'], $prev['net']) ?></div><?php endif; ?>
                    <?php if ($offset !== null): ?>
                    <!-- ความคืบหน้าสู่ Net Zero (กติกาเดียวกับหน้าเว็บ) -->
                    <div class="offset">
                        <div class="offset-lbl"><span>ดูดกลับชดเชยได้</span><b><?= $offset >= 100 ? 'ครบ 100%' : number_format($offset, 1) . '%' ?></b></div>
                        <div class="offset-track"><i style="width:<?= number_format(min($offset, 100), 2, '.', '') ?>%"></i></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="finding"><?= $finding ?></div>

            <!-- สัดส่วนตามขอบเขต — ย้ายมาหน้า 1 (เดิมหน้า 1 ว่างครึ่งหน้า หน้า 2 แน่น) -->
            <div class="stack">
                <?php foreach ([1, 2, 3] as $s): ?>
                <div style="width:<?= number_format($scope_pct[$s], 2, '.', '') ?>%;background:<?= $scope_color[$s] ?>;"></div>
                <?php endforeach; ?>
            </div>
            <div class="scope-tiles">
                <?php foreach ([1, 2, 3] as $s): ?>
                <div class="scope-tile" style="--c:<?= $scope_color[$s] ?>;">
                    <div class="st-head"><span class="dot" style="background:<?= $scope_color[$s] ?>"></span>ขอบเขต <?= $s ?> <b><?= number_format($scope_pct[$s], 1) ?>%</b></div>
                    <div class="st-desc"><?= $scope_desc[$s] ?></div>
                    <div class="st-val"><?= $n($sum['scope'][$s]) ?> <span>tCO₂e</span></div>
                </div>
                <?php endforeach; ?>
            </div>

            <table class="summary-table">
                <colgroup><col><?php if ($prev): ?><col style="width:17%"><?php endif; ?><col style="width:17%"><?php if ($prev): ?><col style="width:14%"><?php endif; ?></colgroup>
                <thead><tr>
                    <th>รายการ</th>
                    <?php if ($prev): ?><th class="num">ปี <?= $h($prev_label) ?> (tCO₂e)</th><?php endif; ?>
                    <th class="num">ปี <?= $h($year_label) ?> (tCO₂e)</th>
                    <?php if ($prev): ?><th class="num">เปลี่ยนแปลง</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($summary_rows as [$label, $cls, $get, $up_good]): ?>
                    <tr class="<?= $cls ?>">
                        <td><?= $label ?></td>
                        <?php if ($prev): ?><td class="num"><?= $n($get($prev)) ?></td><?php endif; ?>
                        <td class="num"><?= $n($get($sum)) ?></td>
                        <?php if ($prev): ?><td class="num"><?= $up_good === null ? '' : $chg($get($sum), $get($prev), $up_good) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <!-- ═══ หน้า 2: ผลแยกตามแหล่งปล่อย + วิธีคำนวณ ═══ -->
    <div class="sheet">
        <section>
            <h2><span class="no">2</span> ผลการคำนวณแยกตามแหล่งปล่อย <small>ปี <?= $h($year_label) ?> · tCO₂e</small></h2>

            <!-- จัดกลุ่มตามขอบเขต + ยอดรวมย่อย — เดิมมีคอลัมน์ขอบเขต 1/2/3 ที่ว่างเกือบทั้งหมด ทำให้ชื่อหมวดตกบรรทัด -->
            <table class="cat-table">
                <colgroup><col><col style="width:18%"><col style="width:26%"></colgroup>
                <thead><tr>
                    <th>แหล่งปล่อย</th><th class="num">tCO₂e</th><th class="num">สัดส่วนของการปล่อยทั้งหมด</th>
                </tr></thead>
                <tbody>
                <?php foreach ($cat_groups as $s => $grp): ?>
                    <tr class="scope-row">
                        <td><span class="dot" style="background:<?= $scope_color[$s] ?>"></span>ขอบเขต <?= $s ?> <span class="muted">· <?= $scope_desc[$s] ?></span></td>
                        <td class="num"><?= $n($grp['total']) ?></td>
                        <td class="num"><?= number_format($scope_pct[$s], 1) ?>%</td>
                    </tr>
                    <?php foreach ($grp['items'] as $cat):
                        $p = $sum['gross'] > 0 ? $cat['value'] / $sum['gross'] * 100 : 0; ?>
                    <tr class="cat-row<?= $cat['value'] > 0 ? '' : ' zero' ?>">
                        <td><?= $h(ghg_group_label($cat['name'])) ?></td>
                        <td class="num"><?= $n($cat['value']) ?></td>
                        <td class="num"><div class="mini"><div class="mini-track"><i style="width:<?= number_format(min($p, 100), 2, '.', '') ?>%;background:<?= $scope_color[$s] ?>"></i></div><?= number_format($p, 1) ?>%</div></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                    <tr class="total">
                        <td>รวมการปล่อยทั้งหมด</td>
                        <td class="num"><?= $n($sum['gross']) ?></td>
                        <td class="num"><?= $sum['gross'] > 0 ? '100%' : '—' ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <?php $sec = 3; if ($view === 'system'): ?>
        <section>
            <h2><span class="no"><?= $sec++ ?></span> การปล่อยรายหน่วยงาน <small>เฉพาะการดำเนินงาน · tCO₂e</small></h2>
            <table>
                <colgroup><col style="width:7%"><col><col style="width:18%"><col style="width:18%"></colgroup>
                <thead><tr><th class="c">#</th><th>คณะ/หน่วยงาน</th><th class="num">tCO₂e</th><th class="num">สัดส่วน</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $r):
                    $p = $rows_total > 0 ? (float)$r['total_emission'] / $rows_total * 100 : 0; ?>
                    <tr>
                        <td class="c"><?= $i + 1 ?></td>
                        <td><?= $h($r['affiliation_item']) ?></td>
                        <td class="num"><?= $n($r['total_emission']) ?></td>
                        <td class="num"><div class="mini"><div class="mini-track"><i style="width:<?= number_format(min($p, 100), 2, '.', '') ?>%"></i></div><?= number_format($p, 1) ?>%</div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="4" class="c muted">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
                    <tr class="total"><td></td><td>รวมทุกหน่วยงาน (จากการดำเนินงาน)</td><td class="num"><?= $n($rows_total) ?></td><td class="num"><?= $rows_total > 0 ? '100%' : '—' ?></td></tr>
                </tbody>
            </table>
            <div class="muted" style="font-size:12px;margin-top:6px;">* ยอดรวมตารางนี้ไม่รวมข้อมูลจากแบบสอบถามและกิจกรรม จึงน้อยกว่าการปล่อยทั้งหมดในหัวข้อที่ 1–2 · แสดงเฉพาะหน่วยงานที่มีข้อมูล</div>
        </section>
        <?php endif; ?>

        <section class="method">
            <h2><span class="no"><?= $sec ?></span> วิธีการคำนวณ</h2>
            <div class="formula"><b>การปล่อย (tCO₂e)</b> = ข้อมูลกิจกรรม × ค่าการปล่อยก๊าซเรือนกระจก (kgCO₂e/หน่วย) ÷ 1,000</div>
            <div class="formula"><b>การดูดกลับ (tCO₂e)</b> = ปริมาณ × ค่าการดูดกลับ (kgCO₂e/หน่วย) ÷ 1,000 &nbsp;·&nbsp; <b>สุทธิ</b> = ปล่อยทั้งหมด − ดูดกลับ</div>
            <ul>
                <?php if ($view === 'faculty'): ?>
                <li>ขอบเขตข้อมูล: การดำเนินงานที่คณะบันทึกในระบบ กิจกรรมที่คณะจัด (ทั้งการปล่อยและการดูดกลับ) และแบบสอบถามของคณะ (นับในขอบเขต 3)</li>
                <?php else: ?>
                <li>ขอบเขตข้อมูล: ทุกคณะ/หน่วยงาน ครอบคลุมการดำเนินงาน แบบสอบถาม และกิจกรรม รวมถึงการดูดกลับระดับมหาวิทยาลัยและจากกิจกรรม</li>
                <li>ข้อมูลจากแบบสอบถาม (เช่น การเดินทาง) เป็นค่าประมาณจากกลุ่มผู้ตอบ</li>
                <?php endif; ?>
                <li>ค่าการปล่อยของแต่ละรายการกำหนดโดยผู้ดูแลระบบแยกตามปี · หมวดแหล่งปล่อยทั้งหมดที่ประเมินแสดงในหัวข้อที่ 2 (ค่า 0.00 = ยังไม่มีข้อมูลในปีนี้)</li>
                <li>ตัวเลขแสดงทศนิยม 2 ตำแหน่ง คำนวณจากค่าเต็มก่อนปัดเศษ ผลรวมจึงอาจต่างจากการบวกตัวเลขที่แสดงเล็กน้อย</li>
                <li>รายการ ปริมาณ และหน่วยของข้อมูลทุกบรรทัด ดูได้ในไฟล์ Excel ของรายงานฉบับนี้</li>
            </ul>
        </section>

        <div class="sign">
            <div><div class="line"></div>(<?= $h($preparer_name) ?>)<br><?= $preparer_pos !== '' ? $h($preparer_pos) . '<br>' : '' ?>ผู้จัดทำ</div>
            <div><div class="line"></div>(....................................................)<br>ผู้ตรวจสอบ/ผู้อนุมัติ</div>
        </div>

        <div class="foot">สร้างจากระบบ UP NET ZERO · <?= date('d/m/Y H:i') ?></div>
    </div>
</body>
</html>
