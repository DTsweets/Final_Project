<?php
/**
 * DEAN DASHBOARD — dean/index.php (บุคลากร/คณบดี — ดูอย่างเดียว)
 * แสดง: ข้อมูลคณะของตนเอง + ผลรวมทั้งระบบ
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);

$pdo  = getDB();
$root = '../';
$affil_id   = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
$page_title = "Dashboard";

// ปีทั้งหมดในระบบ (dean ดูภาพรวมทั้งระบบด้วย จึงใช้ทุกปี)
$years = ghg_years($pdo);
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : ($years[0]['year_id'] ?? 0);
$year_label = '';
foreach ($years as $y) { if ($y['year_id'] == $selected_year) { $year_label = $y['year']; break; } }

// ── คณะของตนเอง: รายการ + scope ของปีที่เลือก (สำหรับการ์ด + modal) ──
$items = [];
$scope1 = $scope2 = $scope3 = 0;
if ($affil_id) {
    $stmt = $pdo->prepare("
        SELECT ag.scope AS scope_no, ai.name_tiem, ai.unit,
               COALESCE(ui.Vol,0) AS vol, (COALESCE(ui.Vol,0)*ai.AD)/1000 AS emission
        FROM admin_item ai
        JOIN admin_g ag ON ai.scope = ag.id
        LEFT JOIN user_item ui ON ui.admin_item_id = ai.id AND ui.affiliation_id = :aff AND ui.year_id = :y AND ui.source = 'officer'
        WHERE ai.year_id = :y2
        ORDER BY ag.scope ASC, ai.id ASC");
    $stmt->execute([':aff' => $affil_id, ':y' => $selected_year, ':y2' => $selected_year]);
    foreach ($stmt->fetchAll() as $r) {
        $sc = (int)$r['scope_no'];
        if ($sc === 1) $scope1 += $r['emission']; elseif ($sc === 2) $scope2 += $r['emission']; elseif ($sc === 3) $scope3 += $r['emission'];
        $items[] = ['scope' => $sc, 'name' => $r['name_tiem'], 'unit' => $r['unit'], 'vol' => (float)$r['vol'], 'emission' => (float)$r['emission']];
    }
}
$own_total = $scope1 + $scope2 + $scope3;

// ── ทั้งระบบ: scope รวม + รายคณะ ของปีที่เลือก ──
$sys_scope = ghg_scope_totals($pdo, $selected_year);
$sys_total = $sys_scope[1] + $sys_scope[2] + $sys_scope[3];
$sys_affil = ghg_by_affiliation($pdo, $selected_year);

// ── การดูดกลับ: คณะตน (จากกิจกรรม) + ระดับมหาวิทยาลัย (สำหรับส่วนภาพรวมระบบ) ──
$removal_activity = $affil_id ? removal_activity_total($pdo, $selected_year, $affil_id) : 0;
$removal_rows     = $affil_id ? removal_activity_list($pdo, $selected_year, $affil_id) : [];
$sys_removal      = removal_total($pdo, $selected_year);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard (คณบดี) — UP Net Zero</title>
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

            <!-- Header + year dropdown -->
            <div class="db-topbar">
                <h2 class="db-title">ภาพรวมคณบดี — <?= htmlspecialchars($affil_name) ?></h2>
                <div class="db-year-select-wrap">
                    <span class="db-year-label">ผลรวมของปี</span>
                    <?php
                        // ใช้ component dropdown กลาง (components/dropdown.php) แทน dropdown เฉพาะกิจ
                        $dd_id       = 'yearSelect';
                        $dd_name     = 'year';
                        $dd_options  = array_map(fn($yd) => ['value' => $yd['year_id'], 'label' => $yd['year']], $years);
                        $dd_selected = $selected_year;
                        $dd_required = false;
                        $dd_class    = 'dd-pill';
                        include __DIR__ . '/../components/dropdown.php';
                    ?>
                </div>
            </div>

            <!-- ===== ส่วนที่ 1: คณะของฉัน ===== -->
            <div class="db-section-label">คณะของฉัน — <?= htmlspecialchars($affil_name) ?> (ปี <?= htmlspecialchars($year_label) ?>)</div>
            <div class="db-row2">
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($own_total, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-card-desc">การปล่อยก๊าซเรือนกระจกทั้งหมด (คณะ)</div>
                            <div class="db-card-subdesc">TOTAL EMISSION</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <ellipse cx="36" cy="44" rx="24" ry="14" fill="#B3E5FC" /><ellipse cx="28" cy="34" rx="14" ry="10" fill="#81D4FA" />
                                <ellipse cx="44" cy="30" rx="16" ry="12" fill="#90CAF9" /><ellipse cx="36" cy="26" rx="18" ry="13" fill="#E1F5FE" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openDetailModal(0)" class="db-card-btn db-btn-green" style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                </div>
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($removal_activity, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-card-desc">GHG Removal</div>
                            <div class="db-card-subdesc">การดูดกลับจากกิจกรรมของคณะ</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <path d="M36 60 C36 60 14 46 14 30 C14 20 24 12 36 16 C48 12 58 20 58 30 C58 46 36 60 36 60Z" fill="#C8E6C9" />
                                <circle cx="36" cy="30" r="8" fill="#4CAF50" /><path d="M32 30 L36 24 L40 30" stroke="white" stroke-width="2" fill="none" stroke-linecap="round" />
                            </svg>
                        </div>
                    </div>
                    <?php if (!empty($removal_rows)): ?>
                    <button onclick="openRemovalModal()" class="db-card-btn db-btn-green" style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                    <?php else: ?>
                    <span class="db-card-btn db-btn-green" style="opacity:.7;">ยังไม่มีกิจกรรมดูดกลับ</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="db-scope-row">
                <div class="db-card db-card-scope1">
                    <div class="db-card-inner"><div class="db-card-text">
                        <div class="db-big-num db-num-s1"><?= number_format($scope1, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                        <div class="db-scope-label db-scope-s1">Scope 1</div></div></div>
                    <button onclick="openDetailModal(1)" class="db-card-btn db-btn-scope1" style="border:none;cursor:pointer;">ดูรายละเอียด Scope 1</button>
                </div>
                <div class="db-card db-card-scope2">
                    <div class="db-card-inner"><div class="db-card-text">
                        <div class="db-big-num db-num-s2"><?= number_format($scope2, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                        <div class="db-scope-label db-scope-s2">Scope 2</div></div></div>
                    <button onclick="openDetailModal(2)" class="db-card-btn db-btn-scope2" style="border:none;cursor:pointer;">ดูรายละเอียด Scope 2</button>
                </div>
                <div class="db-card db-card-scope3">
                    <div class="db-card-inner"><div class="db-card-text">
                        <div class="db-big-num db-num-s3"><?= number_format($scope3, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                        <div class="db-scope-label db-scope-s3">Scope 3</div></div></div>
                    <button onclick="openDetailModal(3)" class="db-card-btn db-btn-scope3" style="border:none;cursor:pointer;">ดูรายละเอียด Scope 3</button>
                </div>
            </div>

            <!-- ===== ส่วนที่ 2: ภาพรวมทั้งระบบ ===== -->
            <div class="db-section-label" style="margin-top:2rem;">ผลรวมทั้งระบบ — ทุกคณะ (ปี <?= htmlspecialchars($year_label) ?>)</div>
            <div style="display:grid;grid-template-columns:320px 1fr;gap:1.5rem;align-items:start;">
                <div class="db-card db-card-white" style="text-align:center;">
                    <div style="font-size:.9rem;color:#6B7280;font-weight:600;margin-bottom:.5rem;">รวมทั้งระบบ</div>
                    <div class="db-big-num" style="margin-bottom:1rem;"><?= number_format($sys_total, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                    <canvas id="sysDonut" width="220" height="220" style="max-width:100%;"></canvas>
                    <div style="display:flex;justify-content:center;gap:14px;margin-top:12px;font-size:.85rem;">
                        <span style="color:#F97316;font-weight:700;">■ S1 <?= number_format($sys_scope[1], 2) ?></span>
                        <span style="color:#EC4899;font-weight:700;">■ S2 <?= number_format($sys_scope[2], 2) ?></span>
                        <span style="color:#3B82F6;font-weight:700;">■ S3 <?= number_format($sys_scope[3], 2) ?></span>
                    </div>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #F1EEF5;font-size:.9rem;">
                        <div style="color:#166534;font-weight:700;">🌱 ดูดกลับ (มหาวิทยาลัย): <?= number_format($sys_removal, 2) ?> tCO₂e</div>
                        <div style="color:#6B7280;font-weight:600;margin-top:3px;">สุทธิ (Net = ปล่อย − ดูดกลับ): <?= number_format($sys_total - $sys_removal, 2) ?> tCO₂e</div>
                    </div>
                </div>
                <div class="admin-table-container" style="padding:1.5rem;">
                    <h3 style="font-size:1.05rem;font-weight:700;color:#374151;margin-bottom:1rem;">การปล่อยรายคณะ</h3>
                    <div style="max-height:420px;overflow-y:auto;">
                        <table class="data-table" style="width:100%;">
                            <thead><tr><th>คณะ/หน่วยงาน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                            <tbody>
                                <?php foreach ($sys_affil as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['affiliation_item']) ?></td>
                                        <td style="text-align:right;font-weight:700;color:var(--clr-primary);"><?= number_format($r['total_emission'], 2, '.', ',') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail Modal (คณะของฉัน) -->
        <div class="modal-overlay" id="detailModal" onclick="if(event.target===this)closeDetailModal()">
            <div class="modal-box" style="max-width:780px; padding:0; overflow:hidden;">
                <div id="detailModalHeader" style="padding:2rem 2.5rem; color:#fff;">
                    <button class="modal-close-btn" onclick="closeDetailModal()" style="position:absolute; top:1.1rem; right:1.1rem; background:rgba(255,255,255,0.2); border:none; color:#fff; width:38px; height:38px; border-radius:10px; cursor:pointer; font-size:1.4rem; line-height:1;">&times;</button>
                    <div style="font-size:.8rem; opacity:.8; text-transform:uppercase; letter-spacing:.05em;">รายละเอียดการปล่อยก๊าซเรือนกระจก (คณะ)</div>
                    <h3 id="detailModalTitle" style="font-size:1.5rem; font-weight:800; margin:.25rem 0 0;">—</h3>
                </div>
                <div style="padding:1.5rem 2.5rem; max-height:58vh; overflow-y:auto;">
                    <table class="data-table" style="width:100%;">
                        <thead><tr><th>รายการ</th><th>หน่วย</th><th style="text-align:right;">จำนวน</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                        <tbody id="detailModalBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Removal Modal (การดูดกลับจากกิจกรรมของคณะ — read-only accordion) -->
        <div class="modal-overlay" id="removalModal" onclick="if(event.target===this)closeRemovalModal()">
            <div class="modal-box" style="max-width:820px; padding:0; overflow:hidden;">
                <div style="padding:2rem 2.5rem; color:#fff; background:linear-gradient(135deg,#2E7D32,#66BB6A);">
                    <button class="modal-close-btn" onclick="closeRemovalModal()" style="position:absolute; top:1.1rem; right:1.1rem; background:rgba(255,255,255,0.2); border:none; color:#fff; width:38px; height:38px; border-radius:10px; cursor:pointer; font-size:1.4rem; line-height:1;">&times;</button>
                    <div style="font-size:.8rem; opacity:.85; letter-spacing:.05em;">การดูดกลับจากกิจกรรมของคณะ</div>
                    <h3 style="font-size:1.5rem; font-weight:800; margin:.25rem 0 0;">🌱 รวม <?= number_format($removal_activity, 4) ?> tCO₂e</h3>
                </div>
                <div style="padding:1.5rem 2.5rem; max-height:58vh; overflow-y:auto;">
                    <p class="muted" style="margin:0 0 12px;color:#8A8194;">คลิกที่กิจกรรมเพื่อดูรายละเอียด</p>
                    <div id="removalModalBody"></div>
                </div>
            </div>
        </div>

        <script src="<?= $root ?>assets/js/ghg-charts.js<?= asset_v('assets/js/ghg-charts.js') ?>"></script>
        <script>
            window.REMOVAL_ROWS = <?= json_encode($removal_rows, JSON_UNESCAPED_UNICODE) ?>;
            window.DETAIL_ITEMS = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
            window.SYS_SCOPE = <?= json_encode([$sys_scope[1], $sys_scope[2], $sys_scope[3]]) ?>;
            window.SCOPE_BG = {0:'linear-gradient(135deg, var(--clr-primary), #8B5CF6)',1:'linear-gradient(135deg, #F97316, #EA580C)',2:'linear-gradient(135deg, #EC4899, #BE185D)',3:'linear-gradient(135deg, #3B82F6, #1D4ED8)'};

            // component dropdown ยิง event 'dd:change' เมื่อเลือกปี → โหลดหน้าใหม่ตาม ?year=
            document.getElementById('yearSelect')?.addEventListener('dd:change', function (e) {
                window.location = '?year=' + e.detail.value;
            });
            window.openDetailModal = function (scope) {
                const title = scope === 0 ? 'การปล่อยก๊าซเรือนกระจกทั้งหมด (คณะ)' : ('Scope ' + scope);
                document.getElementById('detailModalTitle').textContent = title;
                document.getElementById('detailModalHeader').style.background = window.SCOPE_BG[scope];
                const rows = window.DETAIL_ITEMS.filter(it => scope === 0 || it.scope === scope);
                const body = document.getElementById('detailModalBody');
                body.innerHTML = rows.length ? rows.map(it => `<tr><td>${it.name}</td><td>${it.unit ?? '-'}</td><td style="text-align:right;">${Number(it.vol).toLocaleString('th-TH',{maximumFractionDigits:4})}</td><td style="text-align:right;font-weight:700;color:var(--clr-primary);">${Number(it.emission).toLocaleString('th-TH',{minimumFractionDigits:4,maximumFractionDigits:4})}</td></tr>`).join('') : '<tr><td colspan="4" style="text-align:center;padding:24px;color:#9CA3AF;">ไม่มีข้อมูล</td></tr>';
                document.getElementById('detailModal').style.display = 'flex'; document.body.style.overflow = 'hidden';
            };
            window.closeDetailModal = function () { document.getElementById('detailModal').style.display = 'none'; document.body.style.overflow = ''; };

            // ── Removal modal (accordion กลุ่มตามกิจกรรม) ──
            window.rmxToggle = function (head) { head.closest('.rmx-act').classList.toggle('open'); };
            window.openRemovalModal = function () {
                const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                const f4 = n => (parseFloat(n)||0).toLocaleString('th-TH', {minimumFractionDigits:4, maximumFractionDigits:4});
                const groups = {};
                (window.REMOVAL_ROWS||[]).forEach(a => { const k=a.event_id; if(!groups[k]) groups[k]={name:a.event_name||'-',items:[],sub:0}; groups[k].items.push(a); groups[k].sub+=parseFloat(a.emission)||0; });
                const chev = '<svg class="rmx-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
                let acc = '';
                Object.values(groups).forEach(g => {
                    let rows = '';
                    g.items.forEach(a => { rows += `<tr><td style="font-weight:600;">${esc(a.name_tiem)}</td><td style="text-align:center;color:#6B7280;">${esc(a.unit||'-')}</td><td style="text-align:right;">${f4(a.factor)}</td><td style="text-align:right;">${(parseFloat(a.qty)||0).toLocaleString('th-TH',{maximumFractionDigits:4})}</td><td style="text-align:right;font-weight:700;color:#166534;">${f4(a.emission)}</td></tr>`; });
                    acc += `<div class="rmx-act"><div class="rmx-head" onclick="rmxToggle(this)"><div class="rmx-title">${chev}<span class="rmx-text">${esc(g.name)}</span></div><div style="color:#166534;font-weight:800;white-space:nowrap;flex-shrink:0;">รวม ${f4(g.sub)} tCO₂e</div></div><div class="rmx-body"><table class="data-table" style="width:100%;"><thead><tr><th style="text-align:left;">รายการดูดกลับ</th><th style="text-align:center;">หน่วย</th><th style="text-align:right;">ค่าดูดกลับ (kgCO₂e/หน่วย)</th><th style="text-align:right;">ปริมาณ</th><th style="text-align:right;">tCO₂e</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
                });
                document.getElementById('removalModalBody').innerHTML = acc || '<p style="color:#9CA3AF;text-align:center;padding:24px;">ยังไม่มีข้อมูล</p>';
                document.getElementById('removalModal').style.display = 'flex'; document.body.style.overflow = 'hidden';
            };
            window.closeRemovalModal = function () { document.getElementById('removalModal').style.display = 'none'; document.body.style.overflow = ''; };

            (function initDeanDash() {
                if (window.drawGhgDonut) {
                    drawGhgDonut(document.getElementById('sysDonut'), [
                        {label:'Scope 1', value: window.SYS_SCOPE[0], color:'#F97316'},
                        {label:'Scope 2', value: window.SYS_SCOPE[1], color:'#EC4899'},
                        {label:'Scope 3', value: window.SYS_SCOPE[2], color:'#3B82F6'}
                    ], 'tCO₂e');
                }
            })();

            if (!window.__deanDashBound) {
                window.__deanDashBound = true;
                document.addEventListener('keydown', e => { if (e.key === 'Escape') window.closeDetailModal(); });
            }
        </script>
    </main>
</body>
</html>
