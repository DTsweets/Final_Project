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
// บัญชี dean ที่ยังไม่ผูกคณะ → ทุก query จะคืนค่าว่าง ต้องบอกสาเหตุ ไม่ใช่ปล่อยให้เข้าใจผิดว่า "ยังไม่มีข้อมูล"
$affil_missing = (($_SESSION['role'] ?? '') === 'dean') && $affil_id === 0;
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

// มุมมองคณะ: แทนตารางรายการด้วยโดนัทแยกราย Scope (แต่ละชิ้น = 1 รายการ)
$scope_items = $view === 'faculty' ? ghg_scope_item_breakdown($detail) : [];
// จานสีไล่เฉดในตระกูลสีของแต่ละ Scope (S1 ส้ม / S2 ชมพู / S3 ฟ้า) — วนซ้ำถ้ารายการเยอะ
$scope_palette = [
    1 => ['#F97316', '#FB923C', '#FDBA74', '#EA580C', '#C2410C', '#FED7AA', '#9A3412', '#FFEDD5', '#7C2D12'],
    2 => ['#EC4899', '#F472B6', '#F9A8D4', '#DB2777', '#BE185D', '#FBCFE8', '#9D174D', '#FCE7F3', '#831843'],
    3 => ['#3B82F6', '#60A5FA', '#93C5FD', '#2563EB', '#1D4ED8', '#BFDBFE', '#1E40AF', '#DBEAFE', '#1E3A8A'],
];

// เฉพาะมุมมองคณะ: การปล่อยจากกิจกรรม + การดูดกลับจากกิจกรรม
$event_rows = $removal_rows = []; $event_total = 0.0;
if ($view === 'faculty') {
    $event_rows   = event_emission_list($pdo, $selected_year, $affil_id);
    $removal_rows = removal_activity_list($pdo, $selected_year, $affil_id);
    foreach ($event_rows as $er) $event_total += (float) $er['emission'];
}
// รวมปล่อย+ดูดกลับของแต่ละกิจกรรมเป็นการ์ดเดียว (แทน 2 ตารางแยก)
$event_cards = $view === 'faculty' ? ghg_event_cards($event_rows, $removal_rows) : [];

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
    return '<span style="display:inline-block;font-weight:700;font-size:.8rem;padding:4px 14px;border-radius:999px;white-space:nowrap;background:' . $bg . ';color:' . $fg . ';">ขอบเขต ' . $s . '</span>';
};

// ประวัติย้อนหลังรายปี แยกขอบเขต (กราฟแท่งกลุ่ม)
$history = ghg_scope_history($pdo, $years, $view === 'faculty' ? $affil_id : null);

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
        /* ตารางกิจกรรม: หัวตารางคงตัวพิมพ์เดิม (tCO₂e ไม่กลายเป็นตัวใหญ่) */
        .evc-table th { text-transform:none; }
        /* กราฟแท่งแนวนอนรายขอบเขต — วาดด้วย CSS ล้วน ไม่ง้อ canvas (ยืดตามความกว้างจอ) */
        .hbar-row { display:flex; align-items:center; gap:14px; margin-bottom:12px; }
        .hbar-row:last-child { margin-bottom:0; }
        .hbar-label { flex:0 0 78px; font-size:.85rem; font-weight:700; color:#4B5563; }
        .hbar-track { flex:1; min-width:0; height:22px; background:#F3F1F6; border-radius:999px; overflow:hidden; }
        .hbar-fill  { height:100%; border-radius:999px; min-width:2px; transition:width .3s; }
        .hbar-val   { flex:0 0 96px; text-align:right; font-size:.9rem; font-weight:800; }
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

            <?php if ($affil_missing): ?>
            <div style="display:flex;align-items:flex-start;gap:10px;background:#FEF3C7;border:1px solid #FCD34D;color:#92400E;padding:14px 18px;border-radius:12px;margin-bottom:1.25rem;font-weight:600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span>บัญชีของคุณยังไม่ได้ผูกกับคณะ/หน่วยงาน — รายงานจึงแสดงค่าเป็น 0 ทั้งหมด<br>
                <span style="font-weight:500;">กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดสังกัดให้บัญชีนี้ (ไม่ใช่ว่ายังไม่มีการกรอกข้อมูล)</span></span>
            </div>
            <?php endif; ?>

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

            <!-- ยอดปล่อยรายขอบเขต: กราฟแท่งแนวนอน (เทียบกับขอบเขตที่สูงสุด) -->
            <div class="db-card db-card-white" style="margin-bottom:1.5rem;">
                <div style="font-size:.95rem;color:#374151;font-weight:700;margin-bottom:1rem;">สัดส่วนตามขอบเขต <span style="font-weight:400;font-size:.78rem;color:#9CA3AF;">(ปล่อยทั้งหมด · tCO₂e)</span></div>
                <?php
                $bar_pct   = ghg_scope_bar_percents($gross_scope);
                $bar_color = [1 => '#F97316', 2 => '#EC4899', 3 => '#3B82F6'];
                foreach ([1, 2, 3] as $s): ?>
                <div class="hbar-row">
                    <span class="hbar-label">ขอบเขต <?= $s ?></span>
                    <div class="hbar-track">
                        <div class="hbar-fill" style="width:<?= number_format($bar_pct[$s], 2, '.', '') ?>%;background:<?= $bar_color[$s] ?>;"></div>
                    </div>
                    <span class="hbar-val" style="color:<?= $bar_color[$s] ?>;"><?= number_format($gross_scope[$s], 4, '.', ',') ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ประวัติข้อมูลย้อนหลัง: แท่งกลุ่มรายปี แยกสีตามขอบเขต -->
            <div class="db-card db-card-white" style="margin-bottom:1.5rem;">
                <div style="font-size:.95rem;color:#374151;font-weight:700;margin-bottom:1rem;">ประวัติข้อมูลย้อนหลัง <span style="font-weight:400;font-size:.78rem;color:#9CA3AF;">(ปล่อยทั้งหมด · tCO₂e)</span></div>
                <canvas id="scopeHistory" width="900" height="280" style="width:100%;max-width:100%;height:auto;"></canvas>
                <div style="display:flex;justify-content:center;gap:18px;margin-top:8px;font-size:.85rem;flex-wrap:wrap;">
                    <?php foreach ([1, 2, 3] as $s): ?>
                    <span style="color:<?= $bar_color[$s] ?>;font-weight:700;">■ ขอบเขต <?= $s ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($view === 'faculty'): ?>
                <!-- โดนัทแยกราย Scope: แต่ละชิ้น = 1 รายการที่คณะกรอก (แทนตารางรายการเดิม) -->
                <div class="admin-table-container" style="padding:1.5rem;margin-bottom:1.25rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:.25rem;">สัดส่วนการปล่อยรายกิจกรรม แยกตามขอบเขต</h3>
                    <div style="font-size:.82rem;color:#9CA3AF;margin-bottom:1.25rem;">จากการดำเนินงานของคณะ · หน่วย tCO₂e · ดูตัวเลขรายการเต็มได้ในไฟล์ Excel / PDF</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem;">
                        <?php foreach ([1, 2, 3] as $s):
                            $items = $scope_items[$s]['items'];
                            $stot  = $scope_items[$s]['total'];
                            $pal   = $scope_palette[$s]; ?>
                        <div style="text-align:center;">
                            <div style="margin-bottom:.5rem;"><?= $scope_badge($s) ?></div>
                            <canvas id="scopeItemDonut<?= $s ?>" width="200" height="200" style="max-width:100%;"></canvas>
                            <div style="font-weight:800;color:var(--clr-primary);margin-top:.35rem;"><?= number_format($stot, 4, '.', ',') ?> <span style="font-weight:500;font-size:.78rem;color:#9CA3AF;">tCO₂e</span></div>
                            <?php if (empty($items)): ?>
                                <div style="font-size:.82rem;color:#9CA3AF;margin-top:.5rem;">ยังไม่มีข้อมูล</div>
                            <?php else: ?>
                                <div style="margin-top:.75rem;text-align:left;font-size:.8rem;line-height:1.7;">
                                    <?php foreach ($items as $k => $it): ?>
                                        <div style="display:flex;align-items:flex-start;gap:6px;">
                                            <span style="flex-shrink:0;color:<?= $pal[$k % count($pal)] ?>;font-weight:700;">■</span>
                                            <span style="flex:1;min-width:0;overflow-wrap:anywhere;color:#4B5563;" title="<?= htmlspecialchars($it['name'], ENT_QUOTES) ?>"><?= htmlspecialchars($it['name']) ?></span>
                                            <span style="flex-shrink:0;font-weight:700;color:#374151;"><?= number_format($it['value'], 4, '.', ',') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- การ์ดรายกิจกรรม: ปล่อยคู่ดูดกลับ (แทนตารางแยก 2 ตาราง) -->
                <?php if (!empty($event_cards)): ?>
                <div class="admin-table-container" style="padding:1.5rem;margin-bottom:1.25rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:.25rem;">กิจกรรมที่คณะจัด
                        <span style="font-weight:500;font-size:.85rem;color:#8A8194;">· แยกจากยอดหลัก · ปล่อยรวม <?= number_format($event_total,4) ?> · ดูดกลับรวม <?= number_format($removal,4) ?> tCO₂e</span></h3>
                    <div style="font-size:.82rem;color:#9CA3AF;margin-bottom:1.25rem;">เรียงตามวันที่จัด (ใหม่ → เก่า)</div>

                    <table class="data-table evc-table" style="width:100%;table-layout:fixed;">
                        <colgroup><col><col style="width:170px;"><col style="width:130px;"></colgroup>
                        <thead><tr><th>กิจกรรม</th><th style="text-align:center;">วันที่จัด</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody>
                        <?php foreach ($event_cards as $c):
                            $fmtDMY  = fn($iso) => $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
                            $d1      = $fmtDMY($c['event_date']);
                            $d2      = $fmtDMY($c['event_end_date']);
                            $dateTxt = $d1 === '' ? '—' : ($d2 !== '' && $d2 !== $d1 ? "$d1 - $d2" : $d1); ?>
                        <tr>
                            <td>
                                <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700;color:#2A2233;"
                                     title="<?= htmlspecialchars($c['event_name'], ENT_QUOTES) ?>"><?= htmlspecialchars($c['event_name']) ?></div>
                            </td>
                            <td style="text-align:center;white-space:nowrap;color:#6B7280;"><?= htmlspecialchars($dateTxt) ?></td>
                            <td style="text-align:right;white-space:nowrap;">
                                <div style="display:inline-flex;flex-direction:column;align-items:flex-end;gap:3px;">
                                    <?php if (!empty($c['emit'])): ?>
                                    <span style="display:inline-flex;align-items:center;gap:5px;color:#62368B;font-weight:700;" title="การปล่อย (tCO₂e)">
                                        <?= ic('factory',15) ?><?= number_format($c['emit_total'],4,'.',',') ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['removal'])): ?>
                                    <span style="display:inline-flex;align-items:center;gap:5px;color:#166534;font-weight:700;" title="การดูดกลับ (tCO₂e)">
                                        <?= ic('leaf',15) ?><?= number_format($c['removal_total'],4,'.',',') ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
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
            window.__SCOPE_ITEMS = <?= json_encode(
                array_map(
                    fn($s) => array_map(
                        fn($it, $k) => ['label' => $it['name'], 'value' => $it['value'], 'color' => $scope_palette[$s][$k % count($scope_palette[$s])]],
                        $scope_items[$s]['items'] ?? [],
                        array_keys($scope_items[$s]['items'] ?? [])
                    ),
                    [1 => 1, 2 => 2, 3 => 3]
                ),
                JSON_UNESCAPED_UNICODE
            ) ?>;
            window.toggleYearDrop = function (e){ e.stopPropagation(); document.getElementById('yearMenu').classList.toggle('open'); document.getElementById('yearDropdownWrap').classList.toggle('open'); };
            window.__HISTORY = <?= json_encode($history, JSON_UNESCAPED_UNICODE) ?>;
            (function(){
                // ประวัติย้อนหลัง: แท่งกลุ่มรายปี แยกสีตามขอบเขต
                const hEl = document.getElementById('scopeHistory');
                if (window.drawGhgGroupedBars && hEl) {
                    drawGhgGroupedBars(hEl,
                        __HISTORY.map(h => ({label: 'ปี ' + h.year, values: [h.s1, h.s2, h.s3]})),
                        [{label:'ขอบเขต 1',color:'#F97316'},{label:'ขอบเขต 2',color:'#EC4899'},{label:'ขอบเขต 3',color:'#3B82F6'}],
                        'tCO₂e');
                }
                // โดนัทแยกราย Scope — วาดเฉพาะมุมมองคณะ (มุมมองทั้งระบบไม่มี canvas เหล่านี้)
                if (window.drawGhgDonut && window.__SCOPE_ITEMS) {
                    [1,2,3].forEach(s => {
                        const el = document.getElementById('scopeItemDonut' + s);
                        // ตรงกลางโดนัทใส่แค่เลขขอบเขต (คำเต็มอยู่บน badge เหนือโดนัทแล้ว)
                        if (el) drawGhgDonut(el, __SCOPE_ITEMS[s] || [], String(s));
                    });
                }
            })();
            if (!window.__deanRepBound){ window.__deanRepBound = true;
                document.addEventListener('click', () => { document.getElementById('yearMenu')?.classList.remove('open'); document.getElementById('yearDropdownWrap')?.classList.remove('open'); });
            }
        </script>
    </main>
</body>
</html>
