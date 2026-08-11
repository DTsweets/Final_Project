<?php
/**
 * DEAN REPORTS — dean/reports.php (ดูอย่างเดียว + ดาวน์โหลด Excel/PDF)
 * มุมมอง: ทั้งระบบ (system) หรือ คณะของฉัน (faculty)
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
$page_title = "รายงาน GHG";

$years = ghg_years($pdo);
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($years[0]['year_id'] ?? 0);
$year_label = '';
foreach ($years as $y) { if ($y['year_id'] == $selected_year) { $year_label = $y['year']; break; } }

// dean เห็นเฉพาะคณะตัวเอง → บังคับ view=faculty (admin เลือกได้ทั้งสองมุมมอง)
$view = (($_SESSION['role'] ?? '') === 'dean')
    ? 'faculty'
    : (($_GET['view'] ?? 'system') === 'faculty' ? 'faculty' : 'system');

// ── ข้อมูลตามมุมมอง ──
$scope = ghg_scope_totals($pdo, $selected_year, $view === 'faculty' ? $affil_id : null);
$total = $scope[1] + $scope[2] + $scope[3];

// ── การดูดกลับ (มุมมองคณะ = กิจกรรมของคณะ / ทั้งระบบ = ระดับมหาวิทยาลัย) — รายงานแยกตามมาตรฐาน ──
$removal = $view === 'faculty'
    ? removal_activity_total($pdo, $selected_year, $affil_id)
    : removal_total($pdo, $selected_year);

// ตารางรายละเอียด
if ($view === 'faculty') {
    $detail = ghg_affil_detail($pdo, $affil_id, $selected_year); // การดำเนินงาน (officer)
} else {
    $detail = ghg_by_affiliation($pdo, $selected_year);          // by faculty
}

// เฉพาะมุมมองคณะ: การปล่อยจากกิจกรรม + การดูดกลับจากกิจกรรม
$event_rows = $removal_rows = []; $event_total = 0.0;
if ($view === 'faculty') {
    $event_rows   = event_emission_list($pdo, $selected_year, $affil_id);
    $removal_rows = removal_activity_list($pdo, $selected_year, $affil_id);
    foreach ($event_rows as $er) $event_total += (float) $er['emission'];
}

// ── ยอดปล่อยรวม (gross) แยก Scope — รวมการปล่อยจากกิจกรรมด้วย (Scope ครอบคลุมทุกการปล่อย) ──
$gross_scope = $scope;
foreach ($event_rows as $er) {
    $sc = (int) $er['scope'];
    if (isset($gross_scope[$sc])) $gross_scope[$sc] += (float) $er['emission'];
}
$gross_total = $gross_scope[1] + $gross_scope[2] + $gross_scope[3];   // officer + กิจกรรม
// สุทธิ (Net) เพื่อติดตาม Net Zero = ปล่อยทั้งหมด − ดูดกลับทั้งหมด (removal ยังรายงานแยกด้านบน)
$net = $gross_total - $removal;

// badge Scope สีตามหลัก (S1 ส้ม / S2 ชมพู / S3 ฟ้า) เหมือน admin
$scope_badge = function (int $s): string {
    $c = [1 => ['#FFEDD5', '#C2410C'], 2 => ['#FCE7F3', '#BE185D'], 3 => ['#DBEAFE', '#1D4ED8']];
    [$bg, $fg] = $c[$s] ?? ['#F3F4F6', '#6B7280'];
    return '<span style="display:inline-block;font-weight:700;font-size:.8rem;padding:4px 14px;border-radius:999px;white-space:nowrap;background:' . $bg . ';color:' . $fg . ';">Scope ' . $s . '</span>';
};

// per-year trend
$series = [];
foreach (array_reverse($years) as $yy) {
    $series[] = ['year' => $yy['year'], 'value' => ghg_total($pdo, (int)$yy['year_id'], $view === 'faculty' ? $affil_id : null)];
}
$dl = 'view=' . $view . '&year=' . $selected_year;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงาน GHG (คณบดี) — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css<?= asset_v('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <style>
        /* หัวตารางไม่ต้องแปลงเป็นตัวพิมพ์ใหญ่ → "tCO₂e" คงตัวพิมพ์เดิม (ไม่กลายเป็น TCO₂E) */
        .admin-table-container .data-table th { text-transform: none; }
        /* table-layout:fixed + colgroup → คุมความกว้างคอลัมน์ ชื่อยาวตัดคำ ไม่ล้นแนวนอน */
        .admin-table-container .data-table { table-layout: fixed; width: 100%; }
        /* ชื่อยาว: ตัดคำลงบรรทัดใหม่ ไม่ล้นคอลัมน์ (ภายใต้ table-layout:fixed) — รวมถึงสตริงยาวไม่มีเว้นวรรค */
        .admin-table-container .data-table .ell {
            display: block; white-space: normal; overflow-wrap: anywhere; word-break: break-word;
        }
    </style>
</head>
<body class="light-theme">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/../officer/includes/header.php'; ?>

        <div class="page-content">
            <div class="db-topbar">
                <h2 class="db-title">รายงานการปล่อยก๊าซเรือนกระจก</h2>
                <div class="db-year-select-wrap">
                    <span class="db-year-label">ปี</span>
                    <div class="db-year-dropdown" id="yearDropdownWrap">
                        <button class="db-year-btn" onclick="toggleYearDrop(event)">
                            <?= htmlspecialchars($year_label) ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9" /></svg>
                        </button>
                        <div class="db-year-menu" id="yearMenu">
                            <?php foreach ($years as $yd): ?>
                                <a href="?view=<?= $view ?>&year=<?= $yd['year_id'] ?>" class="db-year-option <?= $yd['year_id'] == $selected_year ? 'active' : '' ?>"><?= htmlspecialchars($yd['year']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- View toggle + downloads -->
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:1.25rem;">
                <div style="display:flex;gap:8px;">
                    <?php if (($_SESSION['role'] ?? '') !== 'dean'): /* dean ไม่มีสวิตช์มุมมอง — เห็นเฉพาะคณะตัวเอง */ ?>
                    <a href="?view=system&year=<?= $selected_year ?>" class="tab-item <?= $view==='system'?'active':'' ?>" style="padding:8px 20px;">ทั้งระบบ</a>
                    <a href="?view=faculty&year=<?= $selected_year ?>" class="tab-item <?= $view==='faculty'?'active':'' ?>" style="padding:8px 20px;">คณะของฉัน</a>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;">
                    <a href="export_report.php?<?= $dl ?>" class="f-btn" style="background:#4B8BF5;color:#fff;padding:9px 18px;border-radius:12px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        ดาวน์โหลด Excel
                    </a>
                    <a href="report_print.php?<?= $dl ?>" target="_blank" class="f-btn" style="background:#EF4444;color:#fff;padding:9px 18px;border-radius:12px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        ดาวน์โหลด PDF
                    </a>
                </div>
            </div>

            <div class="db-section-label"><?= $view==='faculty' ? 'คณะของฉัน — '.htmlspecialchars($affil_name) : 'ทั้งระบบ (ทุกคณะ)' ?> · ปี <?= htmlspecialchars($year_label) ?></div>

            <!-- KPI tiles -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem;margin-bottom:1.25rem;">
                <div class="db-card db-card-white" style="border-left:5px solid #F97316;">
                    <div style="font-size:.85rem;color:#6B7280;font-weight:600;"><?= $view==='faculty' ? 'การปล่อยจากการดำเนินงาน' : 'การปล่อยรวมทั้งระบบ' ?></div>
                    <div class="db-big-num" style="margin-top:.25rem;color:#EA580C;"><?= number_format($total,2,'.',',') ?> <span class="db-big-unit">tCO₂e</span></div>
                </div>
                <?php if ($view === 'faculty'): ?>
                <div class="db-card db-card-white" style="border-left:5px solid #F59E0B;">
                    <div style="font-size:.85rem;color:#6B7280;font-weight:600;">การปล่อยจากกิจกรรม</div>
                    <div class="db-big-num" style="margin-top:.25rem;color:#B45309;"><?= number_format($event_total,2,'.',',') ?> <span class="db-big-unit">tCO₂e</span></div>
                </div>
                <?php endif; ?>
                <div class="db-card db-card-white" style="border-left:5px solid #16A34A;">
                    <div style="font-size:.85rem;color:#6B7280;font-weight:600;"><?= ic('leaf',14) ?> การดูดกลับ<?= $view==='faculty'?'จากกิจกรรม':' (มหาวิทยาลัย)' ?></div>
                    <div class="db-big-num" style="margin-top:.25rem;color:#166534;"><?= number_format($removal,2,'.',',') ?> <span class="db-big-unit">tCO₂e</span></div>
                </div>
                <div class="db-card db-card-white" style="border-left:5px solid #62368B;">
                    <div style="font-size:.85rem;color:#6B7280;font-weight:600;">สุทธิ (Net) · ติดตาม Net Zero</div>
                    <div class="db-big-num" style="margin-top:.25rem;color:var(--clr-primary);"><?= number_format($net,2,'.',',') ?> <span class="db-big-unit">tCO₂e</span></div>
                    <div style="font-size:.7rem;color:#9CA3AF;margin-top:2px;">= ปล่อยทั้งหมด (<?= number_format($gross_total,2) ?>) − ดูดกลับ (<?= number_format($removal,2) ?>)</div>
                </div>
            </div>

            <!-- donut + trend -->
            <div style="display:grid;grid-template-columns:300px 1fr;gap:1.5rem;align-items:start;margin-bottom:1.5rem;">
                <div class="db-card db-card-white" style="text-align:center;">
                    <div style="font-size:.95rem;color:#374151;font-weight:700;margin-bottom:.5rem;">สัดส่วนตาม Scope <span style="font-weight:400;font-size:.78rem;color:#9CA3AF;">(ปล่อยทั้งหมด)</span></div>
                    <canvas id="scopeDonut" width="200" height="200" style="max-width:100%;"></canvas>
                    <div style="display:flex;justify-content:center;gap:12px;margin-top:10px;font-size:.85rem;flex-wrap:wrap;">
                        <span style="color:#F97316;font-weight:700;">■ S1 <?= number_format($gross_scope[1],2) ?></span>
                        <span style="color:#EC4899;font-weight:700;">■ S2 <?= number_format($gross_scope[2],2) ?></span>
                        <span style="color:#3B82F6;font-weight:700;">■ S3 <?= number_format($gross_scope[3],2) ?></span>
                    </div>
                </div>
                <div class="db-card db-card-white">
                    <div style="font-size:.95rem;color:#374151;font-weight:700;margin-bottom:.5rem;">แนวโน้มรายปี (tCO₂e)</div>
                    <canvas id="yearBar" width="640" height="240" style="max-width:100%;"></canvas>
                </div>
            </div>

            <?php if ($view === 'faculty'): ?>
                <!-- ตาราง 1: การปล่อยจากการดำเนินงาน (officer) -->
                <div class="admin-table-container" style="padding:1.5rem;margin-bottom:1.25rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:1rem;">การปล่อยจากการดำเนินงานของคณะ</h3>
                    <div style="overflow-x:auto;">
                    <table class="data-table" style="width:100%;table-layout:fixed;">
                        <colgroup><col style="width:14%;"><col style="width:44%;"><col style="width:10%;"><col style="width:16%;"><col style="width:16%;"></colgroup>
                        <thead><tr><th style="text-align:center;">Scope</th><th>รายการ</th><th>หน่วย</th><th style="text-align:right;">จำนวน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                            <?php if (empty($detail)): ?><tr><td colspan="5" style="text-align:center;padding:24px;color:#9CA3AF;">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
                            <?php foreach ($detail as $r): ?>
                                <tr>
                                    <td style="text-align:center;"><?= $scope_badge((int)$r['scope']) ?></td>
                                    <td><div class="ell" title="<?= htmlspecialchars($r['name_tiem'],ENT_QUOTES) ?>"><?= htmlspecialchars($r['name_tiem']) ?></div></td>
                                    <td style="text-align:center;"><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                                    <td style="text-align:right;"><?= qty_fmt($r['vol']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--clr-primary);"><?= number_format((float)$r['emission'], 4, '.', ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <!-- ตาราง 2: การปล่อยจากกิจกรรม (event) -->
                <?php if (!empty($event_rows)): ?>
                <div class="admin-table-container" style="padding:1.5rem;margin-bottom:1.25rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:1rem;">การปล่อยจากกิจกรรมที่คณะจัด
                        <span style="font-weight:500;font-size:.85rem;color:#8A8194;">· แยกจากยอดหลัก · รวม <?= number_format($event_total,4) ?> tCO₂e</span></h3>
                    <div style="overflow-x:auto;">
                    <table class="data-table" style="width:100%;table-layout:fixed;">
                        <colgroup><col style="width:20%;"><col style="width:11%;"><col style="width:27%;"><col style="width:8%;"><col style="width:16%;"><col style="width:18%;"></colgroup>
                        <thead><tr><th>กิจกรรม</th><th style="text-align:center;">Scope</th><th>รายการ</th><th>หน่วย</th><th style="text-align:right;">จำนวน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                            <?php foreach ($event_rows as $r): ?>
                                <tr>
                                    <td><div class="ell" title="<?= htmlspecialchars($r['event_name'] ?? '-',ENT_QUOTES) ?>"><?= htmlspecialchars($r['event_name'] ?? '-') ?></div></td>
                                    <td style="text-align:center;"><?= $scope_badge((int)$r['scope']) ?></td>
                                    <td><div class="ell" title="<?= htmlspecialchars($r['name_tiem'],ENT_QUOTES) ?>"><?= htmlspecialchars($r['name_tiem']) ?></div></td>
                                    <td style="text-align:center;"><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                                    <td style="text-align:right;"><?= qty_fmt($r['qty']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--clr-primary);"><?= number_format((float)$r['emission'], 4, '.', ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ตาราง 3: การดูดกลับจากกิจกรรม (event) -->
                <?php if (!empty($removal_rows)): ?>
                <div class="admin-table-container" style="padding:1.5rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:1rem;"><?= ic('leaf',16) ?> การดูดกลับจากกิจกรรมที่คณะจัด
                        <span style="font-weight:500;font-size:.85rem;color:#8A8194;">· รวม <?= number_format($removal,4) ?> tCO₂e</span></h3>
                    <div style="overflow-x:auto;">
                    <table class="data-table" style="width:100%;table-layout:fixed;">
                        <colgroup><col style="width:18%;"><col style="width:26%;"><col style="width:8%;"><col style="width:18%;"><col style="width:14%;"><col style="width:16%;"></colgroup>
                        <thead><tr><th>กิจกรรม</th><th>รายการดูดกลับ</th><th>หน่วย</th><th style="text-align:right;">ค่าดูดกลับ<br>(kgCO₂e/หน่วย)</th><th style="text-align:right;">ปริมาณ</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                            <?php foreach ($removal_rows as $r): ?>
                                <tr>
                                    <td><div class="ell" title="<?= htmlspecialchars($r['event_name'] ?? '-',ENT_QUOTES) ?>"><?= htmlspecialchars($r['event_name'] ?? '-') ?></div></td>
                                    <td><div class="ell" title="<?= htmlspecialchars($r['name_tiem'],ENT_QUOTES) ?>"><?= htmlspecialchars($r['name_tiem']) ?></div></td>
                                    <td style="text-align:center;"><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                                    <td style="text-align:right;"><?= number_format((float)$r['factor'], 4, '.', ',') ?></td>
                                    <td style="text-align:right;"><?= qty_fmt($r['qty']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:#166534;"><?= number_format((float)$r['emission'], 4, '.', ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- system: การปล่อยรายคณะ -->
                <div class="admin-table-container" style="padding:1.5rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:1rem;">การปล่อยรายคณะ</h3>
                    <div style="overflow-x:auto;">
                    <table class="data-table" style="width:100%;table-layout:fixed;">
                        <colgroup><col><col style="width:10rem;"></colgroup>
                        <thead><tr><th>คณะ/หน่วยงาน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                            <?php foreach ($detail as $r): ?>
                                <tr>
                                    <td><div class="ell" title="<?= htmlspecialchars($r['affiliation_item'],ENT_QUOTES) ?>"><?= htmlspecialchars($r['affiliation_item']) ?></div></td>
                                    <td style="text-align:right;font-weight:700;color:var(--clr-primary);"><?= number_format((float)$r['total_emission'], 2, '.', ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script src="<?= $root ?>assets/js/ghg-charts.js<?= asset_v('assets/js/ghg-charts.js') ?>"></script>
        <script>
            window.__SCOPE = <?= json_encode([$gross_scope[1], $gross_scope[2], $gross_scope[3]]) ?>;
            window.__SERIES = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;
            window.toggleYearDrop = function (e){ e.stopPropagation(); document.getElementById('yearMenu').classList.toggle('open'); document.getElementById('yearDropdownWrap').classList.toggle('open'); };
            (function(){
                if (window.drawGhgDonut) drawGhgDonut(document.getElementById('scopeDonut'), [
                    {label:'S1',value:__SCOPE[0],color:'#F97316'},{label:'S2',value:__SCOPE[1],color:'#EC4899'},{label:'S3',value:__SCOPE[2],color:'#3B82F6'}
                ], 'tCO₂e');
                if (window.drawGhgBars) drawGhgBars(document.getElementById('yearBar'), __SERIES.map(s => ({label:s.year, value:s.value, color:'#62368B'})));
            })();
            if (!window.__deanRepBound){ window.__deanRepBound = true;
                document.addEventListener('click', () => { document.getElementById('yearMenu')?.classList.remove('open'); document.getElementById('yearDropdownWrap')?.classList.remove('open'); });
            }
        </script>
    </main>
</body>
</html>
