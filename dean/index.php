<?php
/**
 * DEAN DASHBOARD — dean/index.php (คณบดี — เฉพาะคณะของตนเอง ดูอย่างเดียว)
 * ---------------------------------------------------------
 * หน้าตาชุดเดียวกับ Dashboard admin: การ์ดหลัก ปล่อย / ดูดกลับ / Net (+ % ชดเชย, เทียบปีก่อน) → ขอบเขต 1/2/3 (+ ความครบถ้วน)
 *   → แถบกิจกรรมที่คณะจัด → อันดับของคณะ + แถบตำแหน่ง + 3 อันดับแรก (ทั้งหมดในหน้าต่าง) + ภาพรวมทุกปี (สลับ คณะ / ทั้งมหาวิทยาลัย)
 * ยอดทั้งหมดมาจาก ghg_report_summary($year, $affil) ตัวเดียวกับหน้ารายงาน GHG มุมมองคณะ (ข้อมูลจัดใน includes/dean_dashboard.php)
 * หน้าต่างรายละเอียดใช้ assets/js/admin-dashboard.js โหมด 'faculty' (ข้อมูลฝังในหน้า ไม่เรียก API) · สไตล์ admin-dashboard.css (ad-)
 * admin ที่เปิดหน้านี้ถูกส่งไป Dashboard admin (ภาพรวมทั้งมหาวิทยาลัยอยู่ที่นั่น)
 *
 * เดิม: dashboard.css + inline style + <style> ใน <main>, หน้าต่างแยก 3 อัน, ส่วนทั้งมหาวิทยาลัยซ้ำกับ Dashboard admin
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/dean_dashboard.php';
require_role(['admin', 'dean']);

$redirect = dean_dash_redirect((string) ($_SESSION['role'] ?? ''), (int) ($_GET['year'] ?? 0));
if ($redirect !== null) { header('Location: ' . $redirect); exit; }

$pdo  = getDB();
$root = '../';
$page_title = 'Dashboard';
$affil_id   = (int) ($_SESSION['affiliation_id'] ?? 0);
$affil_name = (string) ($_SESSION['affiliation_name'] ?? '-');

$years = ghg_years($pdo);
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) ($years[0]['year_id'] ?? 0);
$year_label = '';
foreach ($years as $y) if ((int) $y['year_id'] === $selected_year) $year_label = (string) $y['year'];
if ($year_label === '' && $years) { $selected_year = (int) $years[0]['year_id']; $year_label = (string) $years[0]['year']; }

$o   = ($years && $affil_id > 0) ? dean_dash_overview($pdo, $years, $selected_year, $affil_id) : null;
$sum = $o['summary'] ?? null;

$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$n2  = fn(float $v) => number_format($v, 2);
$pct = fn(float $v) => number_format($v, 2, '.', '');
$scope_meta = admin_dash_scope_meta();
$arrow = '<span class="oe-arrow"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></span>';

// ข้อมูลของหน้าต่างรายละเอียด (อ่านใน admin-dashboard.js) — JSON_HEX_TAG กันชื่อที่มี </script>
$ad_data = $o ? [
    'mode' => 'faculty', 'year' => $selected_year, 'yearLabel' => $year_label, 'affilName' => $affil_name,
    'gross' => $sum['gross'], 'scope' => $sum['scope'], 'removal' => $sum['removal'],
    'eventTotal' => $sum['event_total'], 'eventCount' => $o['event_count'],
    'detailRows' => $o['detail_rows'], 'eventGroups' => $o['event_groups'],
    'history' => $o['history'], 'uniHistory' => $o['uni_history'], 'scopeMeta' => $scope_meta,
    'ranking' => $o['ranking'], 'ownAffil' => $affil_id,
] : null;
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard (คณบดี) — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin-dashboard.css<?= asset_v('assets/css/admin-dashboard.css') ?>">
</head>

<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/../officer/includes/header.php'; ?>

        <div class="oe-page ad-page">
            <div class="oe-head oe-rise">
                <div>
                    <h1 class="oe-title">Dashboard</h1>
                    <div class="oe-sub">ภาพรวมการปล่อยและดูดกลับก๊าซเรือนกระจกของ <?= $h($affil_name) ?></div>
                </div>
                <?php if ($years): ?>
                <div class="oe-actions">
                    <span class="co-year-label">ปีงบประมาณ</span>
                    <?php
                    $dd_id = 'adYear'; $dd_name = 'year_nav'; $dd_selected = $selected_year; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:120px;';
                    $dd_options = array_map(fn($y) => ['value' => $y['year_id'], 'label' => (string) $y['year']], $years); $dd_placeholder = 'เลือกปี';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
                    <a class="oe-btn oe-btn-soft ad-report-link" href="reports.php?view=faculty&amp;year=<?= $selected_year ?>">รายงาน GHG ฉบับเต็ม <?= $arrow ?></a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$o): ?>
            <div class="oe-empty oe-rise">
                <?php if (!$years): ?>
                <h3>ยังไม่มีปีงบประมาณในระบบ</h3>
                <p>เมื่อผู้ดูแลระบบเพิ่มปีงบประมาณและหน่วยงานกรอกข้อมูลแล้ว ภาพรวมจะแสดงที่นี่</p>
                <?php else: ?>
                <h3>บัญชีนี้ยังไม่ได้ผูกกับคณะ/หน่วยงาน</h3>
                <p>กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดสังกัด</p>
                <?php endif; ?>
            </div>
            <?php else: ?>

            <?php include __DIR__ . '/../includes/faculty_dashboard_body.php'; ?>
            <?php endif; ?>
        </div>

        <!-- หน้าต่างรายละเอียด (หน้าต่างเดียว ย้อนกลับได้หลายชั้น) -->
        <div class="modal-overlay" id="adModal" role="dialog" aria-modal="true" aria-labelledby="adModalTitle">
            <div class="modal-box ad-modal">
                <div class="ad-mh" id="adModalHead">
                    <button type="button" class="ad-mh-back" id="adBack" hidden aria-label="ย้อนกลับ"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg><span id="adBackLabel">ย้อนกลับ</span></button>
                    <button type="button" class="ad-mh-close" id="adClose" aria-label="ปิด">&times;</button>
                    <div class="ad-mh-label" id="adModalLabel"></div>
                    <h3 class="ad-mh-title" id="adModalTitle"></h3>
                </div>
                <div class="ad-mb" id="adModalBody"></div>
            </div>
        </div>

        <?php if ($ad_data): ?>
        <script type="application/json" id="adData"><?= json_encode($ad_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <?php endif; ?>
        <script src="<?= $root ?>assets/js/ghg-charts.js<?= asset_v('assets/js/ghg-charts.js') ?>"></script>
        <script src="<?= $root ?>assets/js/admin-dashboard.js<?= asset_v('assets/js/admin-dashboard.js') ?>"></script>
        <script>
        // สคริปต์ src โหลดแบบไม่รอกันเมื่อ SPA สลับหน้า → รอจน adInit / drawGhgGroupedBars พร้อม
        (function run(n) {
            if (!window.adInit || !window.drawGhgGroupedBars) { if (n < 200) setTimeout(function () { run(n + 1); }, 25); return; }
            var el = document.getElementById('adData');
            window.adInit(el ? JSON.parse(el.textContent) : null);
        })(0);
        </script>
    </main>
</body>

</html>
