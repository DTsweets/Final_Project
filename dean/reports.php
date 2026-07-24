<?php
/**
 * DEAN REPORTS — dean/reports.php (ดูอย่างเดียว + ดาวน์โหลด Excel/PDF)
 * มุมมอง: ทั้งระบบ (system) หรือ คณะของฉัน (faculty)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);

$pdo  = getDB();
$root = '../';
$affil_id   = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
$page_title = "รายงาน GHG";

$years = ghg_years($pdo);
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($years[0]['year_id'] ?? 0);
$year_label = '';
foreach ($years as $y) { if ($y['year_id'] == $selected_year) { $year_label = $y['year']; break; } }

$view = ($_GET['view'] ?? 'system') === 'faculty' ? 'faculty' : 'system';

// ── ข้อมูลตามมุมมอง ──
$scope = ghg_scope_totals($pdo, $selected_year, $view === 'faculty' ? $affil_id : null);
$total = $scope[1] + $scope[2] + $scope[3];

// ── การดูดกลับ + สุทธิ (มุมมองคณะ = กิจกรรมของคณะ / ทั้งระบบ = ระดับมหาวิทยาลัย) ──
$removal = $view === 'faculty'
    ? removal_activity_total($pdo, $selected_year, $affil_id)
    : removal_total($pdo, $selected_year);
$net = $total - $removal;

// ตารางรายละเอียด
if ($view === 'faculty') {
    $detail = ghg_affil_detail($pdo, $affil_id, $selected_year); // by activity
} else {
    $detail = ghg_by_affiliation($pdo, $selected_year);          // by faculty
}

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
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css<?= asset_v('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
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
                    <a href="?view=system&year=<?= $selected_year ?>" class="tab-item <?= $view==='system'?'active':'' ?>" style="padding:8px 20px;">ทั้งระบบ</a>
                    <a href="?view=faculty&year=<?= $selected_year ?>" class="tab-item <?= $view==='faculty'?'active':'' ?>" style="padding:8px 20px;">คณะของฉัน</a>
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

            <!-- summary: scope donut + numbers + year bar -->
            <div style="display:grid;grid-template-columns:300px 1fr;gap:1.5rem;align-items:start;margin-bottom:1.5rem;">
                <div class="db-card db-card-white" style="text-align:center;">
                    <div style="font-size:.9rem;color:#6B7280;font-weight:600;">รวม</div>
                    <div class="db-big-num" style="margin:.25rem 0 1rem;"><?= number_format($total, 2, '.', ',') ?> <span class="db-big-unit">tCO₂e</span></div>
                    <canvas id="scopeDonut" width="200" height="200" style="max-width:100%;"></canvas>
                    <div style="display:flex;justify-content:center;gap:12px;margin-top:10px;font-size:.85rem;">
                        <span style="color:#F97316;font-weight:700;">■ S1 <?= number_format($scope[1],2) ?></span>
                        <span style="color:#EC4899;font-weight:700;">■ S2 <?= number_format($scope[2],2) ?></span>
                        <span style="color:#3B82F6;font-weight:700;">■ S3 <?= number_format($scope[3],2) ?></span>
                    </div>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #F1EEF5;font-size:.9rem;text-align:left;">
                        <div style="display:flex;justify-content:space-between;"><span style="color:#6B7280;">การปล่อย</span><span style="font-weight:700;"><?= number_format($total,2,'.',',') ?></span></div>
                        <div style="display:flex;justify-content:space-between;margin-top:4px;"><span style="color:#166534;"><?= ic('leaf',15) ?> ดูดกลับ<?= $view==='faculty'?' (คณะ)':' (มหาวิทยาลัย)' ?></span><span style="font-weight:700;color:#166534;"><?= number_format($removal,2,'.',',') ?></span></div>
                        <div style="display:flex;justify-content:space-between;margin-top:6px;padding-top:6px;border-top:1px dashed #E7E3EC;"><span style="color:#374151;font-weight:700;">สุทธิ (Net)</span><span style="font-weight:800;color:var(--clr-primary);"><?= number_format($net,2,'.',',') ?></span></div>
                    </div>
                </div>
                <div class="db-card db-card-white">
                    <div style="font-size:.95rem;color:#374151;font-weight:700;margin-bottom:.5rem;">แนวโน้มรายปี (tCO₂e)</div>
                    <canvas id="yearBar" width="640" height="240" style="max-width:100%;"></canvas>
                </div>
            </div>

            <!-- detail table -->
            <div class="admin-table-container" style="padding:1.5rem;">
                <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:1rem;">
                    <?= $view==='faculty' ? 'รายละเอียดตามประเภทกิจกรรม' : 'การปล่อยรายคณะ' ?>
                </h3>
                <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;">
                    <?php if ($view === 'faculty'): ?>
                        <thead><tr><th>Scope</th><th>รายการ</th><th>หน่วย</th><th style="text-align:right;">จำนวน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                            <?php if (empty($detail)): ?><tr><td colspan="5" style="text-align:center;padding:24px;color:#9CA3AF;">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
                            <?php foreach ($detail as $r): ?>
                                <tr>
                                    <td style="text-align:center;"><span class="badge">Scope <?= (int)$r['scope'] ?></span></td>
                                    <td><?= htmlspecialchars($r['name_tiem']) ?></td>
                                    <td><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                                    <td style="text-align:right;"><?= number_format((float)$r['vol'], 4, '.', ',') ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--clr-primary);"><?= number_format((float)$r['emission'], 2, '.', ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php else: ?>
                        <thead><tr><th>คณะ/หน่วยงาน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                            <?php foreach ($detail as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['affiliation_item']) ?></td>
                                    <td style="text-align:right;font-weight:700;color:var(--clr-primary);"><?= number_format((float)$r['total_emission'], 2, '.', ',') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
                </div>
            </div>
        </div>

        <script src="<?= $root ?>assets/js/ghg-charts.js<?= asset_v('assets/js/ghg-charts.js') ?>"></script>
        <script>
            window.__SCOPE = <?= json_encode([$scope[1], $scope[2], $scope[3]]) ?>;
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
