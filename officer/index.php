<?php
/**
 * USER DASHBOARD — index.php (view-only)
 * สิทธิ์: user — แสดงข้อมูลเฉพาะคณะของตนเอง (dean ดูผ่านโซน /dean/)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['officer']);

$pdo  = getDB();
$root = '../';
$affil_id   = (int)$_SESSION['affiliation_id'];
$affil_name = $_SESSION['affiliation_name'];
$page_title = "Dashboard";

// ปีที่มีข้อมูลของคณะนี้ (สำหรับ dropdown เลือกปี)
$stmt_years = $pdo->prepare("
    SELECT y.id AS year_id, y.year,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM admin_year y
    INNER JOIN user_item ui ON ui.year_id = y.id
    INNER JOIN admin_item ai ON ai.id = ui.admin_item_id
    WHERE ui.affiliation_id = :affil AND ui.source = 'officer'
    GROUP BY y.id, y.year
    ORDER BY y.year DESC
");
$stmt_years->execute([':affil' => $affil_id]);
$year_data = $stmt_years->fetchAll();

$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($year_data[0]['year_id'] ?? 0);
$year_label = '';
foreach ($year_data as $y) {
    if ($y['year_id'] == $selected_year) { $year_label = $y['year']; break; }
}

// รายการทั้งหมดของปีที่เลือก (ไว้คำนวณ Scope + ใช้ใน modal)
$stmt_detail = $pdo->prepare("
    SELECT ag.scope AS scope_no, ai.name_tiem, ai.unit,
           COALESCE(ui.Vol, 0) AS vol,
           (COALESCE(ui.Vol, 0) * ai.AD)/1000 AS emission
    FROM admin_item ai
    JOIN admin_g ag ON ai.scope = ag.id
    LEFT JOIN user_item ui
           ON ui.admin_item_id = ai.id AND ui.affiliation_id = :affil AND ui.year_id = :year AND ui.source = 'officer'
    WHERE ai.year_id = :year2 AND ai.data_source = 'officer'
    ORDER BY ag.scope ASC, ai.id ASC
");
$stmt_detail->execute([':affil' => $affil_id, ':year' => $selected_year, ':year2' => $selected_year]);
$detail_rows = $stmt_detail->fetchAll();

$scope1 = $scope2 = $scope3 = 0;
$items = [];
foreach ($detail_rows as $r) {
    $sc = (int)$r['scope_no'];
    if ($sc === 1) $scope1 += $r['emission'];
    elseif ($sc === 2) $scope2 += $r['emission'];
    elseif ($sc === 3) $scope3 += $r['emission'];
    $items[] = [
        'scope' => $sc,
        'name'  => $r['name_tiem'],
        'unit'  => $r['unit'],
        'vol'   => (float)$r['vol'],
        'emission' => (float)$r['emission'],
    ];
}
$total_emission = $scope1 + $scope2 + $scope3;

// ── ยอด "กิจกรรม" ที่คณะตนเองจัด (source='event') — แยกจากยอดหลัก (officer) ──
$ev_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
    WHERE ui.affiliation_id = :a AND ui.year_id = :y AND ui.source = 'event'
");
$ev_stmt->execute([':a' => $affil_id, ':y' => $selected_year]);
$event_emission = (float) $ev_stmt->fetchColumn();

$evc_stmt = $pdo->prepare("SELECT COUNT(*) FROM event WHERE affiliation_id = :a AND year_id = :y");
$evc_stmt->execute([':a' => $affil_id, ':y' => $selected_year]);
$event_count = (int) $evc_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css<?= asset_v('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="preload" href="<?= $root ?>assets/images/island_bg_opt.webp" as="image">
    <link rel="preload" href="<?= $root ?>assets/images/logol.webp" as="image">
</head>
<body class="light-theme">

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="page-content">

            <?php if (empty($year_data)): ?>
                <div style="text-align:center; padding:4rem 2rem; background:#fff; border-radius:20px; border:2px dashed #E5E7EB;">
                    <h3 style="color:#374151; font-size:1.25rem; font-weight:700;">ยังไม่มีข้อมูลการปล่อยก๊าซเรือนกระจกของหน่วยงาน</h3>
                    <p style="color:#6B7280; margin-top:.5rem;">เมื่อมีการกรอกข้อมูลแล้ว ระบบจะแสดงสรุปที่นี่</p>
                </div>
            <?php else: ?>

            <!-- Header + year dropdown -->
            <div class="db-topbar">
                <h2 class="db-title">ภาพรวมการปล่อยก๊าซเรือนกระจก (<?= htmlspecialchars($affil_name) ?>)</h2>
                <div class="db-year-select-wrap">
                    <span class="db-year-label">ผลรวมของปี</span>
                    <?php
                        // ใช้ component dropdown กลาง (components/dropdown.php) แทน dropdown เฉพาะกิจ
                        $dd_id       = 'yearSelect';
                        $dd_name     = 'year';
                        $dd_options  = array_map(fn($yd) => ['value' => $yd['year_id'], 'label' => $yd['year']], $year_data);
                        $dd_selected = $selected_year;
                        $dd_required = false;
                        $dd_class    = 'dd-pill';
                        include __DIR__ . '/../components/dropdown.php';
                    ?>
                </div>
            </div>

            <div class="db-section-label">การปล่อยและดูดกลับก๊าซเรือนกระจก ปี <?= htmlspecialchars($year_label) ?></div>

            <div class="db-row2">
                <!-- Total Emission -->
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($total_emission, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-card-desc">การปล่อยก๊าซเรือนกระจกทั้งหมด</div>
                            <div class="db-card-subdesc">TOTAL EMISSION</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <ellipse cx="36" cy="44" rx="24" ry="14" fill="#B3E5FC" />
                                <ellipse cx="28" cy="34" rx="14" ry="10" fill="#81D4FA" />
                                <ellipse cx="44" cy="30" rx="16" ry="12" fill="#90CAF9" />
                                <ellipse cx="36" cy="26" rx="18" ry="13" fill="#E1F5FE" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openDetailModal(0)" class="db-card-btn db-btn-green" style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                </div>

                <!-- GHG Removal -->
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num">0</div>
                            <div class="db-card-desc">GHG Removal</div>
                            <div class="db-card-subdesc">การดูดกลับก๊าซเรือนกระจกทั้งหมด</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <path d="M36 60 C36 60 14 46 14 30 C14 20 24 12 36 16 C48 12 58 20 58 30 C58 46 36 60 36 60Z" fill="#C8E6C9" />
                                <path d="M36 52 C36 52 20 42 20 30 C20 22 28 16 36 19 C44 16 52 22 52 30 C52 42 36 52 36 52Z" fill="#81C784" />
                                <circle cx="36" cy="30" r="8" fill="#4CAF50" />
                                <path d="M32 30 L36 24 L40 30" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" />
                            </svg>
                        </div>
                    </div>
                    <span class="db-card-btn db-btn-green" style="opacity:.85;">0 tCO₂e</span>
                </div>
            </div>

            <?php if ($event_count > 0): ?>
            <!-- กิจกรรมของคณะ (แยกจากยอดหลัก) -->
            <div class="db-section-label">กิจกรรมของคณะ ปี <?= htmlspecialchars($year_label) ?></div>
            <div style="margin-bottom:1.5rem;">
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($event_emission, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-card-desc">การปล่อยจากกิจกรรมที่คณะจัด (<?= $event_count ?> กิจกรรม)</div>
                            <div class="db-card-subdesc">ACTIVITIES — แยกจากยอดหลักด้านบน</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <rect x="14" y="18" width="44" height="40" rx="6" fill="#FDE68A" />
                                <rect x="14" y="18" width="44" height="12" rx="6" fill="#F59E0B" opacity=".55" />
                                <rect x="22" y="12" width="4" height="12" rx="2" fill="#B45309" />
                                <rect x="46" y="12" width="4" height="12" rx="2" fill="#B45309" />
                                <circle cx="27" cy="42" r="3" fill="#F59E0B" />
                                <circle cx="36" cy="42" r="3" fill="#F59E0B" />
                                <circle cx="45" cy="42" r="3" fill="#F59E0B" />
                            </svg>
                        </div>
                    </div>
                    <a href="collect.php?tab=event&year=<?= $selected_year ?>" class="db-card-btn db-btn-green" style="text-decoration:none;">
                        ดูกิจกรรม
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Scope Cards -->
            <div class="db-scope-row">
                <!-- Scope 1 -->
                <div class="db-card db-card-scope1">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num db-num-s1"><?= number_format($scope1, 0) ?></div>
                            <div class="db-scope-label db-scope-s1">Scope 1</div>
                        </div>
                    </div>
                    <button onclick="openDetailModal(1)" class="db-card-btn db-btn-scope1" style="border:none;cursor:pointer;">
                        ดูรายละเอียด Scope 1
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                </div>
                <!-- Scope 2 -->
                <div class="db-card db-card-scope2">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num db-num-s2"><?= number_format($scope2, 0) ?></div>
                            <div class="db-scope-label db-scope-s2">Scope 2</div>
                        </div>
                    </div>
                    <button onclick="openDetailModal(2)" class="db-card-btn db-btn-scope2" style="border:none;cursor:pointer;">
                        ดูรายละเอียด Scope 2
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                </div>
                <!-- Scope 3 -->
                <div class="db-card db-card-scope3">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num db-num-s3"><?= number_format($scope3, 0) ?></div>
                            <div class="db-scope-label db-scope-s3">Scope 3</div>
                        </div>
                    </div>
                    <button onclick="openDetailModal(3)" class="db-card-btn db-btn-scope3" style="border:none;cursor:pointer;">
                        ดูรายละเอียด Scope 3
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                </div>
            </div>

            <?php endif; ?>
        </div>
        
        <!-- ── View-only Detail Modal (อยู่ใน main-content เพื่อให้ SPA re-run) ── -->
            <div class="modal-overlay" id="detailModal" onclick="if(event.target===this)closeDetailModal()">
                <div class="modal-box" style="max-width:780px; padding:0; overflow:hidden;">
                    <div id="detailModalHeader" style="padding:2rem 2.5rem; color:#fff;">
                        <button class="modal-close-btn" onclick="closeDetailModal()" style="position:absolute; top:1.1rem; right:1.1rem; background:rgba(255,255,255,0.2); border:none; color:#fff; width:38px; height:38px; border-radius:10px; cursor:pointer; font-size:1.4rem; line-height:1;">&times;</button>
                        <div style="font-size:.8rem; opacity:.8; text-transform:uppercase; letter-spacing:.05em;">รายละเอียดการปล่อยก๊าซเรือนกระจก</div>
                        <h3 id="detailModalTitle" style="font-size:1.5rem; font-weight:800; margin:.25rem 0 0;">—</h3>
                    </div>
                    <div style="padding:1.5rem 2.5rem; max-height:58vh; overflow-y:auto;">
                        <table class="data-table" style="width:100%;">
                            <thead><tr>
                                <th>รายการ</th><th>หน่วย</th>
                                <th style="text-align:right;">จำนวน</th>
                                <th style="text-align:right;">tCO₂e</th>
                            </tr></thead>
                            <tbody id="detailModalBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
                window.DETAIL_ITEMS = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
                window.SCOPE_BG = {
                    0: 'linear-gradient(135deg, var(--clr-primary), #8B5CF6)',
                    1: 'linear-gradient(135deg, #F97316, #EA580C)',
                    2: 'linear-gradient(135deg, #EC4899, #BE185D)',
                    3: 'linear-gradient(135deg, #3B82F6, #1D4ED8)'
                };

                // component dropdown ยิง event 'dd:change' เมื่อเลือกปี → โหลดหน้าใหม่ตาม ?year=
                document.getElementById('yearSelect')?.addEventListener('dd:change', function (e) {
                    window.location = '?year=' + e.detail.value;
                });

                window.openDetailModal = function (scope) {
                    const title = scope === 0 ? 'การปล่อยก๊าซเรือนกระจกทั้งหมด' : ('Scope ' + scope);
                    document.getElementById('detailModalTitle').textContent = title;
                    document.getElementById('detailModalHeader').style.background = window.SCOPE_BG[scope];
                    const rows = window.DETAIL_ITEMS.filter(it => scope === 0 || it.scope === scope);
                    const body = document.getElementById('detailModalBody');
                    if (rows.length === 0) {
                        body.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:24px; color:#9CA3AF;">ไม่มีข้อมูล</td></tr>';
                    } else {
                        body.innerHTML = rows.map(it => `
                            <tr>
                                <td>${it.name}</td>
                                <td>${it.unit ?? '-'}</td>
                                <td style="text-align:right;">${Number(it.vol).toLocaleString('th-TH', {maximumFractionDigits:4})}</td>
                                <td style="text-align:right; font-weight:700; color:var(--clr-primary);">${Number(it.emission).toLocaleString('th-TH', {maximumFractionDigits:2})}</td>
                            </tr>`).join('');
                    }
                    document.getElementById('detailModal').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                };

                window.closeDetailModal = function () {
                    document.getElementById('detailModal').style.display = 'none';
                    document.body.style.overflow = '';
                };

                // ผูก listener ระดับ document ครั้งเดียว (กันซ้อนตอน SPA สลับหน้า)
                if (!window.__userDashBound) {
                    window.__userDashBound = true;
                    document.addEventListener('keydown', e => { if (e.key === 'Escape') window.closeDetailModal(); });
                }
            </script>
    </main>

</body>
</html>