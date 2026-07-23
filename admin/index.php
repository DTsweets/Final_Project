<?php
/**
 * ADMIN DASHBOARD — index.php
 * สิทธิ์: admin
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';

require_role(['admin']);

$pdo = getDB();
$root = '../';
$role = $_SESSION['role'];
$name = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
$remaining = session_remaining();
$page_title = "Dashboard";

// ── ดึงรายการปีทั้งหมด ──────────────────────────────
$sql_years = 'SELECT id AS year_id, year FROM admin_year ORDER BY year DESC';
$year_data = $pdo->query($sql_years)->fetchAll();

// ปีที่เลือก
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : ($year_data[0]['year_id'] ?? 0);
$year_label = '';
foreach ($year_data as $y) {
    if ($y['year_id'] == $selected_year) {
        $year_label = $y['year'];
        break;
    }
}

// ── จำนวนรายงานทั้งหมด — นับแยกผู้ให้ข้อมูล: คณะ(officer) รายคณะ + แบบสอบถาม + กิจกรรม ────────────
$total_entries = (int) $pdo->query('
    SELECT COUNT(DISTINCT CONCAT(
        CASE WHEN source = "officer" THEN CONCAT("aff", affiliation_id) ELSE source END,
        "-", year_id))
    FROM user_item
')->fetchColumn();

// ── total emission ของปีที่เลือก ─────────────────────────────────────────
$summary = $pdo->prepare('
    SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
    WHERE ui.year_id = :y
');
$summary->execute([':y' => $selected_year]);
$sum = $summary->fetch();

// ── total emission สะสมทุกปี ──────────────────────────────────────────────
$cumulative_emission = (float) $pdo->query('
    SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0)
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
')->fetchColumn();
$sum['total_entries'] = $total_entries;

// ── GHG Removal (การดูดกลับ ระดับส่วนกลาง) ──
$removal = removal_total($pdo, $selected_year);
$removal_items = removal_items_list($pdo, $selected_year);          // ส่วนกลาง (read-only)
$removal_activity = removal_activity_list($pdo, $selected_year);    // จากกิจกรรมคณะ (read-only)
$removal_central = removal_central_total($pdo, $selected_year);
$removal_activity_sum = removal_activity_total($pdo, $selected_year);

// ── สะสมรายคณะ (ทุกปี) — คณะ = officer เท่านั้น (กันนับซ้ำ) ──────────────────────
$cumul_affil_rows = $pdo->query('
    SELECT a.id AS affil_id,
           a.affiliation_item,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM affiliation_id a
    LEFT JOIN user_item ui  ON ui.affiliation_id  = a.id AND ui.source = "officer"
    LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY a.id, a.affiliation_item
    ORDER BY total_emission DESC
')->fetchAll();

// ยอดสะสม (ทุกปี) แยกตาม source — ใช้เติมสไลซ์ แบบสอบถาม/กิจกรรม ในโดนัทสะสม
$src_cumul = $pdo->query('
    SELECT ui.source, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS e
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY ui.source
')->fetchAll(PDO::FETCH_KEY_PAIR);

// รวมเป็น breakdown สะสม: คณะ(officer) + แบบสอบถาม + กิจกรรม
$cumul_breakdown = array_map(fn($r) => [
    'affil_id' => $r['affil_id'],
    'name'     => $r['affiliation_item'],
    'emission' => (float) $r['total_emission'],
], $cumul_affil_rows);
$cumul_breakdown[] = ['affil_id' => null, 'name' => 'แบบสอบถาม', 'emission' => (float) ($src_cumul['survey'] ?? 0), 'source' => 'survey'];
$cumul_breakdown[] = ['affil_id' => null, 'name' => 'กิจกรรม',   'emission' => (float) ($src_cumul['event'] ?? 0), 'source' => 'event'];
usort($cumul_breakdown, fn($a, $b) => $b['emission'] <=> $a['emission']);

// ── Scope 1, 2, 3 ────────────────────────────────────
$scope_sql = '
    SELECT ag.scope,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM admin_g ag
    LEFT JOIN admin_item ai ON ai.scope = ag.id AND ai.year_id = :y1
    LEFT JOIN user_item ui ON ui.admin_item_id = ai.id AND ui.year_id = :y2
    GROUP BY ag.scope
    ORDER BY ag.scope
';
$stmt_scope = $pdo->prepare($scope_sql);
$stmt_scope->execute([':y1' => $selected_year, ':y2' => $selected_year]);
$scope_rows = $stmt_scope->fetchAll(PDO::FETCH_KEY_PAIR);   // scope => emission

$scope1 = $scope_rows[1] ?? 0;
$scope2 = $scope_rows[2] ?? 0;
$scope3 = $scope_rows[3] ?? 0;

// ── แยกตามแหล่งข้อมูล (source) ปีที่เลือก — JOIN admin_item 1:1 ไม่นับซ้ำ ──
$src_stmt = $pdo->prepare('
    SELECT ui.source, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM user_item ui
    JOIN admin_item ai ON ai.id = ui.admin_item_id
    WHERE ui.year_id = :y
    GROUP BY ui.source
');
$src_stmt->execute([':y' => $selected_year]);
$src_map = $src_stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // source => emission (officer/survey/event)

// ── รายคณะ แยกตาม Scope (รวมคณะที่ยังไม่กรอก) ───────────
$scope_affil_sql = '
    SELECT ag.scope,
           a.id          AS affil_id,
           a.affiliation_item,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission
    FROM   affiliation_id a
    CROSS  JOIN admin_g ag
    LEFT   JOIN admin_item  ai ON ai.scope = ag.id AND ai.year_id = :y1
    LEFT   JOIN user_item   ui ON ui.admin_item_id = ai.id
                               AND ui.affiliation_id = a.id
                               AND ui.year_id = :y2
                               AND ui.source = \'officer\'
    GROUP  BY ag.scope, a.id, a.affiliation_item
    ORDER  BY ag.scope ASC, total_emission DESC, a.affiliation_item ASC
';
$stmt_sa = $pdo->prepare($scope_affil_sql);
$stmt_sa->execute([':y1' => $selected_year, ':y2' => $selected_year]);
$scope_affil_rows = $stmt_sa->fetchAll();
// จัด index: scope_affil_by_scope[1] = [...rows...] (คณะ = officer เท่านั้น)
$scope_affil_by_scope = [];
foreach ($scope_affil_rows as $r) {
    $scope_affil_by_scope[(int)$r['scope']][] = [
        'affil_id' => $r['affil_id'],
        'name'     => $r['affiliation_item'],
        'emission' => (float) $r['total_emission'],
    ];
}
// เติมสไลซ์ แบบสอบถาม/กิจกรรม ต่อ Scope (ยอดของ survey/event แยกตาม scope ของปีที่เลือก)
$scope_src_stmt = $pdo->prepare('
    SELECT ag.scope, ui.source, COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS e
    FROM admin_g ag
    JOIN admin_item ai ON ai.scope = ag.id AND ai.year_id = :y1
    JOIN user_item  ui ON ui.admin_item_id = ai.id AND ui.year_id = :y2 AND ui.source IN (\'survey\', \'event\')
    GROUP BY ag.scope, ui.source
');
$scope_src_stmt->execute([':y1' => $selected_year, ':y2' => $selected_year]);
$SRC_SLICE_LABELS = ['survey' => 'แบบสอบถาม', 'event' => 'กิจกรรม'];
$scope_src_tmp = [];   // [scope][source] = e
foreach ($scope_src_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $scope_src_tmp[(int)$r['scope']][$r['source']] = (float) $r['e'];
}
foreach ([1, 2, 3] as $sc) {
    foreach (['survey', 'event'] as $srcKey) {
        $scope_affil_by_scope[$sc][] = [
            'affil_id' => null,
            'name'     => $SRC_SLICE_LABELS[$srcKey],
            'emission' => (float) ($scope_src_tmp[$sc][$srcKey] ?? 0),
        ];
    }
    // เรียงมาก→น้อยในแต่ละ scope
    usort($scope_affil_by_scope[$sc], fn($a, $b) => $b['emission'] <=> $a['emission']);
}

// ── รายคณะ (เฉพาะข้อมูลที่คณะกรอกเอง source='officer' — กันนับซ้ำกับแบบสอบถาม/กิจกรรม) ──
$affil_sql = '
    SELECT a.id AS affil_id,
           a.affiliation_item,
           COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) AS total_emission,
           COUNT(DISTINCT ui.id)             AS entry_count
    FROM affiliation_id a
    LEFT JOIN user_item ui  ON ui.affiliation_id  = a.id AND ui.year_id = :y AND ui.source = \'officer\'
    LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY a.id, a.affiliation_item
    ORDER BY total_emission DESC, a.affiliation_item ASC
';
$stmt_affil = $pdo->prepare($affil_sql);
$stmt_affil->execute([':y' => $selected_year]);
$affil_rows = $stmt_affil->fetchAll();

// ── รวมเป็น "แยกตามผู้ให้ข้อมูล": คณะ (officer) + แบบสอบถาม + กิจกรรม (กันนับซ้ำ, รวม = Total Emission) ──
$year_breakdown = array_map(fn($r) => [
    'name'     => $r['affiliation_item'],
    'emission' => (float) $r['total_emission'],
    'kind'     => 'faculty',
], $affil_rows);
$year_breakdown[] = ['name' => 'แบบสอบถาม', 'emission' => (float) ($src_map['survey'] ?? 0), 'kind' => 'survey'];
$year_breakdown[] = ['name' => 'กิจกรรม',   'emission' => (float) ($src_map['event'] ?? 0), 'kind' => 'event'];
usort($year_breakdown, fn($a, $b) => $b['emission'] <=> $a['emission']);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="preload" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>" as="style">
    <link rel="preload" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>" as="style">
    <link rel="preload" href="<?= $root ?>assets/css/dashboard.css<?= asset_v('assets/css/dashboard.css') ?>" as="style">
    <link rel="preload" href="<?= $root ?>assets/images/island_bg_opt.webp" as="image">
    <link rel="preload" href="<?= $root ?>assets/images/logol.webp" as="image">
    <?php if(!empty($_SESSION['profile_image'])): ?>
    <link rel="preload" href="<?= $root ?>assets/images/profiles/<?= htmlspecialchars(pathinfo($_SESSION['profile_image'], PATHINFO_FILENAME) . '.webp') ?>" as="image">
    <?php endif; ?>

    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css<?= asset_v('assets/css/dashboard.css') ?>">
</head>

<body class="light-theme">

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="page-content">

            <!-- ── Dashboard Header ── -->
            <div class="db-topbar">
                <h2 class="db-title">Dashboard</h2>
                <div class="db-year-select-wrap">
                    <span class="db-year-label">ผลรวมของปี</span>
                    <div class="db-year-dropdown" id="yearDropdownWrap">
                        <button class="db-year-btn" onclick="toggleYearDrop(event)">
                            <?= $year_label ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </button>
                        <div class="db-year-menu" id="yearMenu">
                            <?php foreach ($year_data as $yd): ?>
                                <a href="?year=<?= $yd['year_id'] ?>"
                                    class="db-year-option <?= $yd['year_id'] == $selected_year ? 'active' : '' ?>">
                                    <?= $yd['year'] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Row 1: สองการ์ดบนสุด ── -->
            <div class="db-row2">

                <!-- จำนวนครั้งที่รายงาน -->
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($sum['total_entries']) ?> <span
                                    class="db-big-unit">ครั้ง</span></div>
                            <div class="db-card-desc">จำนวนครั้งที่รายงาน</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <rect x="10" y="8" width="52" height="56" rx="8" fill="#FFF3CD" />
                                <rect x="18" y="20" width="36" height="5" rx="2.5" fill="#FBB03B" />
                                <rect x="18" y="31" width="28" height="4" rx="2" fill="#FBB03B" opacity=".5" />
                                <rect x="18" y="41" width="22" height="4" rx="2" fill="#FBB03B" opacity=".3" />
                                <rect x="24" y="6" width="24" height="8" rx="4" fill="#FBB03B" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openReportsModal()" class="db-card-btn db-btn-yellow"
                        style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

                <!-- การปล่อยก๊าซสะสม (ทุกปี) -->
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($cumulative_emission, 0) ?> <span
                                    class="db-big-unit">tCO₂e</span></div>
                            <div class="db-card-desc">การปล่อยก๊าซเรือนกระจกสะสม (ทุกปี)</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <circle cx="36" cy="36" r="30" fill="#E8F5E9" />
                                <ellipse cx="36" cy="36" rx="20" ry="20" fill="#A5D6A7" />
                                <ellipse cx="36" cy="36" rx="12" ry="12" fill="#4CAF50" />
                                <path d="M36 20 Q44 28 36 36 Q28 44 36 52" stroke="white" stroke-width="2.5"
                                    fill="none" />
                                <path d="M20 36 Q28 28 36 36 Q44 44 52 36" stroke="white" stroke-width="2.5"
                                    fill="none" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openCumulativeModal()" class="db-card-btn db-btn-green"
                        style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

            </div>

            <!-- ── Section: การปล่อยและดูดกลับ ── -->
            <div class="db-section-label">การปล่อยและดูดกลับก๊าซเรือนกระจก ปี <?= $year_label ?></div>

            <div class="db-row2">

                <!-- Total Emission -->
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($sum['total_emission'], 2) ?> <span
                                    class="db-big-unit">tCO₂e</span></div>
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
                    <button onclick="openYearEmissionModal()" class="db-card-btn db-btn-green"
                        style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

                <!-- GHG Removal -->
                <div class="db-card db-card-white">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num"><?= number_format($removal, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-card-desc">GHG Removal</div>
                            <div class="db-card-subdesc">การดูดกลับก๊าซเรือนกระจกทั้งหมด</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="72" height="72" viewBox="0 0 72 72" fill="none">
                                <path
                                    d="M36 60 C36 60 14 46 14 30 C14 20 24 12 36 16 C48 12 58 20 58 30 C58 46 36 60 36 60Z"
                                    fill="#C8E6C9" />
                                <path
                                    d="M36 52 C36 52 20 42 20 30 C20 22 28 16 36 19 C44 16 52 22 52 30 C52 42 36 52 36 52Z"
                                    fill="#81C784" />
                                <circle cx="36" cy="30" r="8" fill="#4CAF50" />
                                <path d="M32 30 L36 24 L40 30" stroke="white" stroke-width="2" fill="none"
                                    stroke-linecap="round" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openRemovalModal()" class="db-card-btn db-btn-green"
                        style="border:none;cursor:pointer;">
                        ดูรายละเอียด
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

            </div>

            <!-- ── Scope Cards ── -->
            <div class="db-scope-row">

                <!-- Scope 1 -->
                <div class="db-card db-card-scope1">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num db-num-s1"><?= number_format($scope1, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-scope-label db-scope-s1">Scope 1</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="64" height="64" viewBox="0 0 64 64" fill="none">
                                <rect x="8" y="30" width="26" height="22" rx="4" fill="#F97316" opacity=".15" />
                                <rect x="14" y="36" width="14" height="14" rx="2" fill="#F97316" opacity=".4" />
                                <path d="M18 36 L18 22 Q18 16 24 16 L28 16" stroke="#F97316" stroke-width="3"
                                    stroke-linecap="round" fill="none" />
                                <ellipse cx="32" cy="14" rx="6" ry="8" fill="#FED7AA" />
                                <ellipse cx="32" cy="14" rx="4" ry="5" fill="#F97316" opacity=".6" />
                                <path d="M36 28 L44 28 L50 36 L50 52 L36 52 Z" fill="#FB923C" opacity=".3" />
                                <rect x="38" y="40" width="8" height="10" rx="1" fill="#F97316" opacity=".5" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openScopeModal(1)" class="db-card-btn db-btn-scope1" style="border:none;cursor:pointer;">
                        ดูรายละเอียด Scope 1
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

                <!-- Scope 2 -->
                <div class="db-card db-card-scope2">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num db-num-s2"><?= number_format($scope2, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-scope-label db-scope-s2">Scope 2</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="64" height="64" viewBox="0 0 64 64" fill="none">
                                <rect x="16" y="20" width="32" height="28" rx="4" fill="#EC4899" opacity=".15" />
                                <rect x="22" y="14" width="20" height="8" rx="2" fill="#EC4899" opacity=".3" />
                                <path d="M32 8 L32 14" stroke="#EC4899" stroke-width="3" stroke-linecap="round" />
                                <path d="M24 26 L32 18 L40 26" stroke="#DB2777" stroke-width="2.5" fill="none"
                                    stroke-linecap="round" />
                                <rect x="22" y="34" width="8" height="12" rx="2" fill="#EC4899" opacity=".5" />
                                <rect x="34" y="34" width="8" height="12" rx="2" fill="#EC4899" opacity=".5" />
                                <path d="M28 26 L28 34" stroke="#DB2777" stroke-width="2" stroke-linecap="round" />
                                <path d="M36 26 L36 34" stroke="#DB2777" stroke-width="2" stroke-linecap="round" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openScopeModal(2)" class="db-card-btn db-btn-scope2" style="border:none;cursor:pointer;">
                        ดูรายละเอียด Scope 2
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

                <!-- Scope 3 -->
                <div class="db-card db-card-scope3">
                    <div class="db-card-inner">
                        <div class="db-card-text">
                            <div class="db-big-num db-num-s3"><?= number_format($scope3, 2) ?> <span class="db-big-unit">tCO₂e</span></div>
                            <div class="db-scope-label db-scope-s3">Scope 3</div>
                        </div>
                        <div class="db-card-illus">
                            <svg width="64" height="64" viewBox="0 0 64 64" fill="none">
                                <ellipse cx="32" cy="38" rx="22" ry="8" fill="#3B82F6" opacity=".15" />
                                <path d="M10 38 Q16 18 32 22 Q36 22 40 20 L54 26 Q58 28 54 32 L42 38 Q36 42 20 40 Z"
                                    fill="#93C5FD" opacity=".5" />
                                <path d="M12 38 Q18 20 32 24 Q38 24 42 22 L54 28" stroke="#3B82F6" stroke-width="2"
                                    fill="none" stroke-linecap="round" />
                                <circle cx="46" cy="32" r="4" fill="#3B82F6" opacity=".4" />
                                <path d="M42 36 L50 36" stroke="#3B82F6" stroke-width="2" stroke-linecap="round" />
                            </svg>
                        </div>
                    </div>
                    <button onclick="openScopeModal(3)" class="db-card-btn db-btn-scope3" style="border:none;cursor:pointer;">
                        ดูรายละเอียด Scope 3
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </button>
                </div>

            </div>

            <!-- ── Detail Modal (รายละเอียดรายการคณะ) ── -->
            <div class="modal-overlay" id="detailModal" onclick="if(event.target===this)closeDetail()">
                <div class="modal-box detail-modal-box">
                    <button class="modal-close-btn" onclick="closeDetail()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div id="detailBreadcrumb" class="modal-breadcrumb" style="display:none;"></div>
                    <div class="detail-modal-header">
                        <div class="detail-icon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                            </svg>
                        </div>
                        <div>
                            <div class="detail-modal-label">รายละเอียดการกรอกข้อมูล</div>
                            <h3 class="detail-modal-title" id="detailModalTitle">—</h3>
                        </div>
                    </div>
                    <div id="detailModalBody" class="detail-modal-body">
                        <div class="detail-loading">
                            <div class="spinner"></div><span>กำลังโหลดข้อมูล...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Reports Modal (จำนวนครั้งที่รายงาน) ── -->
            <div class="modal-overlay" id="reportsModal" onclick="if(event.target===this)closeReportsModal()">
                <div class="modal-box detail-modal-box">
                    <button class="modal-close-btn" onclick="closeReportsModal()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div class="detail-modal-header">
                        <div class="detail-icon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <line x1="16" y1="2" x2="16" y2="6" />
                                <line x1="8" y1="2" x2="8" y2="6" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                        </div>
                        <div>
                            <div class="detail-modal-label">สรุปการรายงานทั้งหมด</div>
                            <h3 class="detail-modal-title" id="reportsModalTitle">จำนวนครั้งที่รายงาน</h3>
                        </div>
                    </div>
                    <!-- breadcrumb -->
                    <div id="reportsBreadcrumb" class="modal-breadcrumb" style="display:none;">
                        <span class="back-btn-pill" onclick="backToReportsList()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            กลับรายการคณะ
                        </span>
                    </div>
                    <div id="reportsModalBody" class="detail-modal-body">
                        <div class="detail-loading">
                            <div class="spinner"></div><span>กำลังโหลดข้อมูล...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Cumulative Emission Modal (รายคณะ ทุกปี) ── -->
            <div class="modal-overlay" id="cumulativeModal" onclick="if(event.target===this)closeCumulativeModal()">
                <div class="modal-box detail-modal-box cumulative-modal-box">
                    <button class="modal-close-btn" onclick="closeCumulativeModal()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div class="detail-modal-header-level1 cumul-sky-header" id="cumulativeModalHeader">
                        <!-- เมฆก้อนที่ 3 -->
                        <div class="cumul-cloud-3"></div>
                        <div class="detail-icon" style="background:linear-gradient(135deg,#4CAF50,#2E7D32); position:relative; z-index:1;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                                <path d="M12 8v4l3 3" />
                            </svg>
                        </div>
                        <div style="position:relative; z-index:1;">
                            <div class="detail-modal-label">สะสมทุกปีที่บันทึก</div>
                            <h3 class="detail-modal-title">การปล่อยก๊าซเรือนกระจกสะสม — รายคณะ</h3>
                        </div>
                    </div>
                    <div id="cumulativeModalBody" class="detail-modal-body">
                        <!-- filled by JS -->
                    </div>
                </div>
            </div>

            <!-- ── Item Detail Modal (Level 4: ชื่อ/จำนวน/ไฟล์) ── -->
            <div class="modal-overlay" id="itemDetailModal" onclick="if(event.target===this)closeItemDetail()">
                <div class="modal-box detail-modal-box">
                    <button class="modal-close-btn" onclick="closeItemDetail()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div class="detail-modal-header" id="itemDetailHeader">
                        <div class="detail-icon" id="itemDetailIcon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                        </div>
                        <div>
                            <div class="detail-modal-label">รายละเอียดรายการ</div>
                            <h3 class="detail-modal-title" id="itemDetailTitle">—</h3>
                        </div>
                    </div>
                    <div id="itemDetailBody" class="detail-modal-body">
                        <div class="detail-loading">
                            <div class="spinner"></div><span>กำลังโหลดข้อมูล...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Year Emission Modal (รายคณะ ปีที่เลือก) ── -->
            <div class="modal-overlay" id="yearEmissionModal" style="display:none;"
                onclick="if(event.target===this)closeYearEmissionModal()">
                <div class="modal-box detail-modal-box cumulative-modal-box">
                    <button class="modal-close-btn" onclick="closeYearEmissionModal()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div class="detail-modal-header-level1 cumul-sky-header">
                        <div class="cumul-cloud-3"></div>
                        <div class="detail-icon" style="background:linear-gradient(135deg,#62368B,#9B51E0); position:relative; z-index:1;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 20V10" />
                                <path d="M12 20V4" />
                                <path d="M6 20v-6" />
                            </svg>
                        </div>
                        <div style="position:relative; z-index:1;">
                            <div class="detail-modal-label">การปล่อยก๊าซเรือนกระจก — แยกตามผู้ให้ข้อมูล (คณะ / แบบสอบถาม / กิจกรรม)</div>
                            <h3 class="detail-modal-title" id="yearEmissionTitle">—</h3>
                        </div>
                    </div>
                    <div id="yearEmissionModalBody" class="detail-modal-body"></div>
                </div>
            </div>

            <!-- ── Removal Modal (การดูดกลับ — read-only) ── -->
            <div class="modal-overlay" id="removalModal" style="display:none;"
                onclick="if(event.target===this)closeRemovalModal()">
                <div class="modal-box detail-modal-box cumulative-modal-box">
                    <button class="modal-close-btn" onclick="closeRemovalModal()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div class="detail-modal-header-level1 cumul-sky-header">
                        <div class="cumul-cloud-3"></div>
                        <div class="detail-icon" style="background:linear-gradient(135deg,#2E7D32,#66BB6A); position:relative; z-index:1;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" />
                                <path d="M2 21c0-3 1.85-5.36 5.08-6" />
                            </svg>
                        </div>
                        <div style="position:relative; z-index:1;">
                            <div class="detail-modal-label">การดูดกลับก๊าซเรือนกระจก — รายการดูดกลับ (ระดับมหาวิทยาลัย)</div>
                            <h3 class="detail-modal-title" id="removalTitle">—</h3>
                        </div>
                    </div>
                    <div id="removalModalBody" class="detail-modal-body"></div>
                </div>
            </div>

            <!-- ── Removal Activity Detail Modal (รายละเอียดต่อกิจกรรม — ซ้อนบน removalModal) ── -->
            <div class="modal-overlay" id="removalActModal" style="display:none;z-index:4000;"
                onclick="if(event.target===this)closeRemovalActAll()">
                <div class="modal-box detail-modal-box cumulative-modal-box">
                    <div class="modal-breadcrumb">
                        <span class="back-btn-pill" onclick="closeRemovalActDetail()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                            ย้อนกลับหน้ารายการดูดกลับ
                        </span>
                    </div>
                    <button class="modal-close-btn" onclick="closeRemovalActAll()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>
                    </button>
                    <div class="detail-modal-header-level1 cumul-sky-header">
                        <div class="detail-icon" style="background:linear-gradient(135deg,#2E7D32,#66BB6A);position:relative;z-index:1;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z" /><path d="M2 21c0-3 1.85-5.36 5.08-6" /></svg>
                        </div>
                        <div style="position:relative;z-index:1;min-width:0;">
                            <div class="detail-modal-label">รายละเอียดการดูดกลับของกิจกรรม</div>
                            <h3 class="detail-modal-title" id="removalActTitle" style="overflow-wrap:anywhere;word-break:break-word;">—</h3>
                        </div>
                    </div>
                    <div id="removalActBody" class="detail-modal-body"></div>
                </div>
            </div>

            <!-- ── Scope Modal (รายคณะ แยก Scope) ── -->
            <div class="modal-overlay" id="scopeModal" style="display:none;" onclick="if(event.target===this)closeScopeModal()">
                <div class="modal-box detail-modal-box cumulative-modal-box">
                    <button class="modal-close-btn" onclick="closeScopeModal()">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <div class="detail-modal-header-level1" id="scopeModalHeader">
                        <div class="detail-icon" id="scopeModalIcon">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 20V10" /><path d="M12 20V4" /><path d="M6 20v-6" />
                            </svg>
                        </div>
                        <div>
                            <div class="detail-modal-label" id="scopeModalLabel">การปล่อยก๊าซเรือนกระจก — รายคณะ/หน่วยงาน</div>
                            <h3 class="detail-modal-title" id="scopeModalTitle">—</h3>
                        </div>
                    </div>
                    <div id="scopeModalBody" class="detail-modal-body"></div>
                </div>
            </div>

            <!-- ── Lightbox (Evidence Gallery Popup) ── -->
            <div id="lightboxOverlay" onclick="if(event.target===this)closeLightbox()"
                style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">

                <div
                    style="background:white; width:90%; max-width:850px; height:80vh; border-radius:24px; display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(0,0,0,0.2); overflow:hidden; position:relative;">
                    <!-- header bar -->
                    <div
                        style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;flex-shrink:0;border-bottom:1px solid #F3F4F6;">
                        <button id="lbBackBtn" onclick="showLbGallery()"
                            style="display:none;background:rgba(98,54,139,0.08);border:none;color:var(--clr-primary);padding:8px 16px;border-radius:10px;cursor:pointer;font-family:'Kanit',sans-serif;font-size:0.95rem;font-weight:600;align-items:center;gap:6px;"
                            onmouseover="this.style.background='rgba(98,54,139,0.15)'"
                            onmouseout="this.style.background='rgba(98,54,139,0.08)'">
                            ← แกลเลอรี
                        </button>
                        <div id="lbCounter"
                            style="color:var(--text-secondary);font-size:0.95rem;font-weight:600;font-family:'Kanit',sans-serif;">
                        </div>
                        <button onclick="closeLightbox()"
                            style="background:rgba(0,0,0,0.05);border:none;color:var(--text-primary);width:38px;height:38px;border-radius:10px;cursor:pointer;font-size:1.4rem;display:flex;align-items:center;justify-content:center;transition:all 0.2s;"
                            onmouseover="this.style.background='rgba(239,68,68,0.1)';this.style.color='#EF4444';"
                            onmouseout="this.style.background='rgba(0,0,0,0.05)';this.style.color='var(--text-primary)';">&times;</button>
                    </div>

                    <!-- gallery view -->
                    <div id="lbGallery"
                        style="flex:1;overflow-y:auto;padding:1.5rem;display:flex;flex-wrap:wrap;gap:1rem;align-content:flex-start;justify-content:center;">
                    </div>

                    <!-- single view -->
                    <div id="lbSingle"
                        style="display:none;flex:1;position:relative;align-items:center;justify-content:center;background:#F9FAFB;padding:2rem;">
                        <button id="lbPrev" onclick="prevFile()"
                            style="position:absolute;left:1.5rem;background:white;border:1px solid var(--border);color:var(--clr-primary);width:48px;height:48px;border-radius:50%;cursor:pointer;font-size:1.8rem;display:flex;align-items:center;justify-content:center;z-index:2;box-shadow:0 4px 12px rgba(0,0,0,0.05);transition:all 0.2s;"
                            onmouseover="this.style.background='var(--clr-primary)';this.style.color='white';this.style.transform='scale(1.05)'"
                            onmouseout="this.style.background='white';this.style.color='var(--clr-primary)';this.style.transform='scale(1)'">&lsaquo;</button>
                        <div id="lbContent"
                            style="width:100%;max-height:55vh;display:flex;align-items:center;justify-content:center;">
                        </div>
                        <button id="lbNext" onclick="nextFile()"
                            style="position:absolute;right:1.5rem;background:white;border:1px solid var(--border);color:var(--clr-primary);width:48px;height:48px;border-radius:50%;cursor:pointer;font-size:1.8rem;display:flex;align-items:center;justify-content:center;z-index:2;box-shadow:0 4px 12px rgba(0,0,0,0.05);transition:all 0.2s;"
                            onmouseover="this.style.background='var(--clr-primary)';this.style.color='white';this.style.transform='scale(1.05)'"
                            onmouseout="this.style.background='white';this.style.color='var(--clr-primary)';this.style.transform='scale(1)'">&rsaquo;</button>
                    </div>

                    <!-- footer download -->
                    <div id="lbFooter"
                        style="text-align:center;padding:1rem;flex-shrink:0;background:#F9FAFB;border-top:1px solid #F3F4F6;">
                    </div>
                </div>
            </div>

            <script>
                /* Year dropdown toggle */
                function toggleYearDrop(e) {
                    e.stopPropagation();
                    document.getElementById('yearMenu').classList.toggle('open');
                    document.getElementById('yearDropdownWrap').classList.toggle('open');
                }
                document.addEventListener('click', () => {
                    document.getElementById('yearMenu').classList.remove('open');
                    document.getElementById('yearDropdownWrap').classList.remove('open');
                });

                /* ═══ Scroll lock helpers — prevents layout shift from scrollbar disappearing ═══ */
                function lockScroll() {
                    const sb = window.innerWidth - document.documentElement.clientWidth;
                    document.body.style.paddingRight = sb + 'px';
                    document.body.style.overflow = 'hidden';
                }
                function unlockScroll() {
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }

                /* ═══ Year Emission data (รายคณะ ปีที่เลือก) ═══ */
                const _yearEmissionData = <?= json_encode($year_breakdown, JSON_UNESCAPED_UNICODE) ?>;
                const _yearLabel = <?= json_encode($year_label) ?>;
                const _selectedYear = <?= (int) $selected_year ?>;
                const _yearTotalEmission = <?= (float) $sum['total_emission'] ?>;

                /* ═══ Removal data (รายการดูดกลับ ปีที่เลือก — read-only) ═══ */
                const _removalItems = <?= json_encode($removal_items, JSON_UNESCAPED_UNICODE) ?>;
                const _removalActivity = <?= json_encode($removal_activity, JSON_UNESCAPED_UNICODE) ?>;
                const _removalTotal = <?= (float) $removal ?>;
                const _removalCentral = <?= (float) $removal_central ?>;
                const _removalActivitySum = <?= (float) $removal_activity_sum ?>;

                function openYearEmissionModal() {
                    document.getElementById('yearEmissionTitle').textContent = 'ปี ' + _yearLabel;
                    const body = document.getElementById('yearEmissionModalBody');
                    const data = _yearEmissionData;
                    const total = _yearTotalEmission;

                    /* ─ Top 5 สำหรับ Pie Chart ─ */
                    const withData = data.filter(r => r.emission > 0);
                    const top5 = withData.slice(0, 5);
                    const othersSum = withData.slice(5).reduce((s, r) => s + r.emission, 0);
                    const pieData = [...top5];
                    if (othersSum > 0) pieData.push({ name: 'อื่นๆ', emission: othersSum });

                    /* ─ Legend (Top 5 + อื่นๆ) ─ */
                    let legendHtml = '<div style="display:flex;flex-direction:column;gap:12px;">';
                    pieData.forEach((r, i) => {
                        const pct = total > 0 ? parseFloat((r.emission / total * 100).toFixed(2)) : 0;
                        const color = getFacultyColor(r.name, i);
                        legendHtml += `<div style="display:flex;align-items:center;gap:14px;font-size:0.95rem;min-width:0;padding:8px 12px;background:#FFFFFF;border:1px solid #F3F4F6;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                    <div style="width:34px;height:34px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="width:14px;height:14px;border-radius:5vh;background:${color};display:inline-block;"></span>
                    </div>
                    <span style="color:#1F2937;flex:1;font-weight:600;white-space:nowrap;">${r.name}</span>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="color:var(--clr-primary);font-weight:800;font-size:1.05rem;line-height:1;">${pct}%</div>
                        <div style="color:var(--text-muted);font-weight:600;font-size:0.75rem;margin-top:4px;">${r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} tCO₂e</div>
                    </div>
                </div>`;
                    });
                    legendHtml += '</div>';

                    /* ─ Table rows (ทุกคณะ) ─ */
                    let rowsHtml = '';
                    if (data.length === 0) {
                        rowsHtml = `<tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted);">ยังไม่มีข้อมูลในปีนี้</td></tr>`;
                    } else {
                        data.forEach((r, i) => {
                            const pct = total > 0 ? (r.emission / total * 100) : 0;
                            const pctDisplay = total > 0 ? parseFloat(pct.toFixed(2)) : 0;
                            const hasEmission = r.emission > 0;
                            const color = hasEmission ? getFacultyColor(r.name, i) : _GRAY;
                            rowsHtml += `<tr>
                        <td style="color:var(--text-muted);font-weight:600;">${i + 1}</td>
                        <td style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:5vh;background:${color};margin-right:6px;vertical-align:middle;"></span>
                            ${r.name}
                        </td>
                        <td style="text-align:right;font-weight:700;color:${hasEmission ? 'var(--clr-primary)' : '#9CA3AF'};">
                            ${hasEmission
                                    ? r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
                                    : '<span style="font-size:0.82rem;font-weight:500;">ไม่มีข้อมูล</span>'}
                        </td>
                        <td style="text-align:right;">
                            <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                                <div style="flex:1;min-width:60px;height:8px;background:#F3F4F6;border-radius:999px;overflow:hidden;">
                                    <div style="width:${pct.toFixed(1)}%;height:100%;background:${color};border-radius:999px;"></div>
                                </div>
                                <span style="font-size:0.82rem;color:var(--text-muted);font-weight:600;min-width:54px;">${pctDisplay}%</span>
                            </div>
                        </td>
                    </tr>`;
                        });
                    }

                    body.innerHTML = `
            <div class="detail-summary">
                <div class="detail-stat">
                    <div class="detail-stat-label">จำนวนรายการ (คณะ + แบบสอบถาม + กิจกรรม)</div>
                    <div class="detail-stat-value">${data.length} รายการ</div>
                </div>
                <div class="detail-stat">
                    <div class="detail-stat-label">รวมปี ${_yearLabel} (tCO₂e)</div>
                    <div class="detail-stat-value">${total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                </div>
            </div>

            <!-- Pie Chart + Legend -->
            <div style="display:flex;align-items:center;gap:3rem;padding:2rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:24px;margin-bottom:2rem;flex-wrap:wrap;justify-content:center;">
                <div style="flex-shrink:0;position:relative;">
                    <canvas id="yearPieChart" width="240" height="240"></canvas>
                </div>
                <div style="flex:1;min-width:350px;">
                    <div style="font-size:0.9rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-secondary);margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
                        สัดส่วนการปล่อยก๊าซ (5 อันดับแรก)
                    </div>
                    ${pieData.length > 0 ? legendHtml : '<p style="color:#9CA3AF;font-size:0.9rem;">ยังไม่มีข้อมูล</p>'}
                </div>
            </div>

            <table class="detail-table" style="table-layout:fixed;width:100%;">
                <colgroup>
                    <col style="width:2.5rem;">
                    <col>
                    <col style="width:185px;">
                    <col style="width:150px;">
                </colgroup>
                <thead><tr>
                    <th>#</th>
                    <th>รายการ (คณะ / แบบสอบถาม / กิจกรรม)</th>
                    <th style="text-align:right;">Total Emission (tCO₂e)</th>
                    <th style="text-align:right;">สัดส่วน</th>
                </tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>`;

                    document.getElementById('yearEmissionModal').style.display = 'flex';
                    lockScroll();

                    /* วาด Pie Chart หลัง DOM render */
                    requestAnimationFrame(() => {
                        const canvas = document.getElementById('yearPieChart');
                        if (!canvas) return;
                        const ctx = canvas.getContext('2d');
                        const W = canvas.width, H = canvas.height;
                        const cx = W / 2, cy = H / 2, R = Math.min(cx, cy) - 12;
                        const chartTotal = pieData.reduce((s, d) => s + d.emission, 0);
                        ctx.clearRect(0, 0, W, H);
                        if (chartTotal === 0) {
                            ctx.beginPath(); ctx.arc(cx, cy, R, 0, 2 * Math.PI);
                            ctx.fillStyle = _GRAY; ctx.fill();
                        } else {
                            let ang = -Math.PI / 2;
                            pieData.forEach((d, i) => {
                                if (d.emission <= 0) return;
                                const slice = (d.emission / chartTotal) * 2 * Math.PI;
                                ctx.beginPath(); ctx.moveTo(cx, cy);
                                ctx.arc(cx, cy, R, ang, ang + slice); ctx.closePath();
                                ctx.fillStyle = getFacultyColor(d.name, i); ctx.fill();
                                ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();
                                ang += slice;
                            });
                        }
                        /* donut hole */
                        ctx.beginPath(); ctx.arc(cx, cy, R * 0.48, 0, 2 * Math.PI);
                        ctx.fillStyle = '#fff'; ctx.fill();
                        /* text กลาง */
                        ctx.fillStyle = '#374151'; ctx.font = 'bold 13px Kanit, sans-serif';
                        ctx.textAlign = 'center'; ctx.fillText('tCO₂e', cx, cy - 4);
                        ctx.font = '11px Kanit, sans-serif'; ctx.fillStyle = '#6B7280';
                        ctx.fillText('ปี ' + _yearLabel, cx, cy + 14);
                    });
                }
                function closeYearEmissionModal() {
                    document.getElementById('yearEmissionModal').style.display = 'none';
                    unlockScroll();
                }

                /* ═══ Removal Modal (รายการดูดกลับ ปีที่เลือก — read-only) ═══ */
                function openRemovalModal() {
                    document.getElementById('removalTitle').textContent = 'ปี ' + _yearLabel;
                    const body = document.getElementById('removalModalBody');
                    const data = _removalItems.map(r => ({
                        name: r.name_tiem,
                        unit: r.unit || '-',
                        factor: parseFloat(r.factor) || 0,
                        qty: parseFloat(r.qty) || 0,
                        emission: parseFloat(r.emission) || 0
                    }));
                    const total = _removalCentral; /* pie/table ส่วนกลางอิงยอดส่วนกลาง (% รวมได้ 100 ภายในกลุ่ม) */

                    /* ─ Top 5 สำหรับ Pie Chart ─ */
                    const withData = data.filter(r => r.emission > 0).sort((a, b) => b.emission - a.emission);
                    /* สีผูกกับชื่อรายการตามลำดับ tCO₂e (มาก→น้อย) ให้ legend/pie/ตาราง ใช้สีเดียวกัน */
                    const rankColor = {};
                    withData.forEach((r, i) => { rankColor[r.name] = getFacultyColor(r.name, i); });
                    const top5 = withData.slice(0, 5);
                    const othersSum = withData.slice(5).reduce((s, r) => s + r.emission, 0);
                    const pieData = [...top5];
                    if (othersSum > 0) pieData.push({ name: 'อื่นๆ', emission: othersSum });

                    /* ─ Legend (Top 5 + อื่นๆ) ─ */
                    let legendHtml = '<div style="display:flex;flex-direction:column;gap:12px;">';
                    pieData.forEach((r, i) => {
                        const pct = total > 0 ? parseFloat((r.emission / total * 100).toFixed(2)) : 0;
                        const color = getFacultyColor(r.name, i);
                        legendHtml += `<div style="display:flex;align-items:center;gap:14px;font-size:0.95rem;min-width:0;padding:8px 12px;background:#FFFFFF;border:1px solid #F3F4F6;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                    <div style="width:34px;height:34px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="width:14px;height:14px;border-radius:5vh;background:${color};display:inline-block;"></span>
                    </div>
                    <span style="color:#1F2937;flex:1;font-weight:600;white-space:nowrap;">${r.name}</span>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="color:#166534;font-weight:800;font-size:1.05rem;line-height:1;">${pct}%</div>
                        <div style="color:var(--text-muted);font-weight:600;font-size:0.75rem;margin-top:4px;">${r.emission.toLocaleString('th-TH', { maximumFractionDigits: 4 })} tCO₂e</div>
                    </div>
                </div>`;
                    });
                    legendHtml += '</div>';

                    /* ─ Table rows (ทุกรายการ) ─ */
                    let rowsHtml = '';
                    if (data.length === 0) {
                        rowsHtml = `<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">ยังไม่มีรายการดูดกลับในปีนี้</td></tr>`;
                    } else {
                        data.forEach((r, i) => {
                            const pct = total > 0 ? (r.emission / total * 100) : 0;
                            const pctDisplay = total > 0 ? parseFloat(pct.toFixed(2)) : 0;
                            const has = r.emission > 0;
                            const color = has ? (rankColor[r.name] || getFacultyColor(r.name, i)) : _GRAY;
                            rowsHtml += `<tr>
                        <td style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:5vh;background:${color};margin-right:6px;vertical-align:middle;"></span>
                            ${r.name}
                        </td>
                        <td style="text-align:center;color:var(--text-muted);">${r.unit}</td>
                        <td style="text-align:right;color:var(--text-muted);">${r.factor.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</td>
                        <td style="text-align:right;font-weight:600;">${r.qty.toLocaleString('th-TH', { maximumFractionDigits: 4 })}</td>
                        <td style="text-align:right;font-weight:700;color:${has ? '#166534' : '#9CA3AF'};">
                            ${has ? r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) : '<span style="font-size:0.82rem;font-weight:500;">ไม่มีข้อมูล</span>'}
                        </td>
                        <td style="text-align:right;">
                            <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                                <div style="flex:1;min-width:60px;height:8px;background:#F3F4F6;border-radius:999px;overflow:hidden;">
                                    <div style="width:${pct.toFixed(1)}%;height:100%;background:${color};border-radius:999px;"></div>
                                </div>
                                <span style="font-size:0.82rem;color:var(--text-muted);font-weight:600;min-width:54px;">${pctDisplay}%</span>
                            </div>
                        </td>
                    </tr>`;
                        });
                    }

                    /* ─ การดูดกลับจากกิจกรรมคณะ (read-only) — 1 แถว/กิจกรรม + ปุ่มรายละเอียด ─ */
                    let activityHtml = '';
                    if (_removalActivity && _removalActivity.length) {
                        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                        const f4 = n => (parseFloat(n)||0).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
                        // จัดกลุ่มตามกิจกรรม
                        const groups = {};
                        _removalActivity.forEach(a => {
                            const k = a.event_id;
                            if (!groups[k]) groups[k] = { eid: k, name: a.event_name || '-', affil: a.affil_name || '-', sub: 0 };
                            groups[k].sub += parseFloat(a.emission) || 0;
                        });
                        let arows = '';
                        Object.values(groups).forEach((g, i) => {
                            arows += `<tr>
                        <td style="color:var(--text-muted);font-weight:600;">${i + 1}</td>
                        <td style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;" title="${esc(g.name)}">${esc(g.name)}</td>
                        <td style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;color:var(--text-muted);" title="${esc(g.affil)}">${esc(g.affil)}</td>
                        <td style="text-align:right;font-weight:700;color:#166534;">${f4(g.sub)}</td>
                        <td style="text-align:center;"><button class="btn-detail" onclick="openRemovalActDetail(${g.eid})">รายละเอียด</button></td>
                    </tr>`;
                        });
                        activityHtml = `
            <div style="font-size:0.95rem;font-weight:800;color:var(--text-secondary);margin:2rem 0 0.75rem;">🌱 การดูดกลับจากกิจกรรม</div>
            <table class="detail-table" style="table-layout:fixed;width:100%;">
                <colgroup><col style="width:2.5rem;"><col><col style="width:200px;"><col style="width:130px;"><col style="width:150px;"></colgroup>
                <thead><tr><th>#</th><th>กิจกรรม</th><th>ผู้จัด</th><th style="text-align:right;">รวม (tCO₂e)</th><th style="text-align:center;">รายละเอียด</th></tr></thead>
                <tbody>${arows}</tbody>
            </table>`;
                    }

                    body.innerHTML = `
            <div class="detail-summary">
                <div class="detail-stat">
                    <div class="detail-stat-label">ส่วนกลาง (tCO₂e)</div>
                    <div class="detail-stat-value">${_removalCentral.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</div>
                </div>
                <div class="detail-stat">
                    <div class="detail-stat-label">จากกิจกรรมคณะ (tCO₂e)</div>
                    <div class="detail-stat-value">${_removalActivitySum.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</div>
                </div>
                <div class="detail-stat">
                    <div class="detail-stat-label">รวมการดูดกลับ ปี ${_yearLabel} (tCO₂e)</div>
                    <div class="detail-stat-value" style="color:#166534;">${_removalTotal.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</div>
                </div>
            </div>

            <!-- Pie Chart + Legend -->
            <div style="display:flex;align-items:center;gap:3rem;padding:2rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:24px;margin-bottom:2rem;flex-wrap:wrap;justify-content:center;">
                <div style="flex-shrink:0;position:relative;">
                    <canvas id="removalPieChart" width="240" height="240"></canvas>
                </div>
                <div style="flex:1;min-width:350px;">
                    <div style="font-size:0.9rem;font-weight:800;letter-spacing:0.08em;color:var(--text-secondary);margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
                        สัดส่วนการดูดกลับ (5 อันดับแรก)
                    </div>
                    ${pieData.length > 0 ? legendHtml : '<p style="color:#9CA3AF;font-size:0.9rem;">ยังไม่มีข้อมูล</p>'}
                </div>
            </div>

            <div style="font-size:0.95rem;font-weight:800;color:var(--text-secondary);margin:0 0 0.75rem;">🏛️ รายการดูดกลับส่วนกลาง</div>
            <table class="detail-table" style="table-layout:fixed;width:100%;">
                <colgroup>
                    <col>
                    <col style="width:80px;">
                    <col style="width:200px;">
                    <col style="width:110px;">
                    <col style="width:120px;">
                    <col style="width:130px;">
                </colgroup>
                <thead><tr style="vertical-align:bottom;">
                    <th style="text-align:left;">รายการดูดกลับ</th>
                    <th style="text-align:center;">หน่วย</th>
                    <th style="text-align:right;">ค่าดูดกลับ (kgCO₂e/หน่วย)</th>
                    <th style="text-align:right;">ปริมาณ</th>
                    <th style="text-align:right;">tCO₂e</th>
                    <th style="text-align:right;">สัดส่วน</th>
                </tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>
            ${activityHtml}`;

                    document.getElementById('removalModal').style.display = 'flex';
                    lockScroll();

                    /* วาด Pie Chart หลัง DOM render */
                    requestAnimationFrame(() => {
                        const canvas = document.getElementById('removalPieChart');
                        if (!canvas) return;
                        const ctx = canvas.getContext('2d');
                        const W = canvas.width, H = canvas.height;
                        const cx = W / 2, cy = H / 2, R = Math.min(cx, cy) - 12;
                        const chartTotal = pieData.reduce((s, d) => s + d.emission, 0);
                        ctx.clearRect(0, 0, W, H);
                        if (chartTotal === 0) {
                            ctx.beginPath(); ctx.arc(cx, cy, R, 0, 2 * Math.PI);
                            ctx.fillStyle = _GRAY; ctx.fill();
                        } else {
                            let ang = -Math.PI / 2;
                            pieData.forEach((d, i) => {
                                if (d.emission <= 0) return;
                                const slice = (d.emission / chartTotal) * 2 * Math.PI;
                                ctx.beginPath(); ctx.moveTo(cx, cy);
                                ctx.arc(cx, cy, R, ang, ang + slice); ctx.closePath();
                                ctx.fillStyle = getFacultyColor(d.name, i); ctx.fill();
                                ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();
                                ang += slice;
                            });
                        }
                        /* donut hole */
                        ctx.beginPath(); ctx.arc(cx, cy, R * 0.48, 0, 2 * Math.PI);
                        ctx.fillStyle = '#fff'; ctx.fill();
                        /* text กลาง */
                        ctx.fillStyle = '#374151'; ctx.font = 'bold 13px Kanit, sans-serif';
                        ctx.textAlign = 'center'; ctx.fillText('tCO₂e', cx, cy - 4);
                        ctx.font = '11px Kanit, sans-serif'; ctx.fillStyle = '#6B7280';
                        ctx.fillText('ปี ' + _yearLabel, cx, cy + 14);
                    });
                }
                function closeRemovalModal() {
                    document.getElementById('removalModal').style.display = 'none';
                    unlockScroll();
                }
                // เปิดรายละเอียดการดูดกลับของกิจกรรม (นับซ้อนบน removalModal)
                function openRemovalActDetail(eid) {
                    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                    const f4 = n => (parseFloat(n)||0).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
                    const items = _removalActivity.filter(a => String(a.event_id) === String(eid));
                    if (!items.length) return;
                    const g = items[0];
                    const sub = items.reduce((s, a) => s + (parseFloat(a.emission) || 0), 0);
                    let rows = '';
                    items.forEach(a => {
                        rows += `<tr>
                        <td style="font-weight:600;">${esc(a.name_tiem)}</td>
                        <td style="text-align:center;color:var(--text-muted);">${esc(a.unit || '-')}</td>
                        <td style="text-align:right;">${f4(a.factor)}</td>
                        <td style="text-align:right;">${(parseFloat(a.qty)||0).toLocaleString('th-TH', { maximumFractionDigits: 4 })}</td>
                        <td style="text-align:right;font-weight:700;color:#166534;">${f4(a.emission)}</td>
                    </tr>`;
                    });
                    document.getElementById('removalActTitle').textContent = g.event_name || '-';
                    document.getElementById('removalActBody').innerHTML = `
                        <div class="detail-summary">
                            <div class="detail-stat"><div class="detail-stat-label">ผู้จัด</div><div class="detail-stat-value" style="font-size:1.05rem;">${esc(g.affil_name || '-')}</div></div>
                            <div class="detail-stat"><div class="detail-stat-label">รวมการดูดกลับ (tCO₂e)</div><div class="detail-stat-value" style="color:#166534;">${f4(sub)}</div></div>
                        </div>
                        <table class="detail-table" style="width:100%;">
                            <thead><tr><th style="text-align:left;">รายการดูดกลับ</th><th style="text-align:center;">หน่วย</th><th style="text-align:right;">ค่าดูดกลับ (kgCO₂e/หน่วย)</th><th style="text-align:right;">ปริมาณ</th><th style="text-align:right;">tCO₂e</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>`;
                    document.getElementById('removalActModal').style.display = 'flex';
                    lockScroll();
                }
                function closeRemovalActDetail() { // ← กลับไปลิสต์ (removalModal ยังเปิด)
                    document.getElementById('removalActModal').style.display = 'none';
                    if (document.getElementById('removalModal').style.display !== 'flex') unlockScroll();
                }
                function closeRemovalActAll() { // X = ปิดทั้งหมด
                    document.getElementById('removalActModal').style.display = 'none';
                    document.getElementById('removalModal').style.display = 'none';
                    unlockScroll();
                }

                /* ═══ Cumulative Modal (การปล่อยสะสมรายคณะ ทุกปี) ═══ */
                const _cumulData = <?= json_encode($cumul_breakdown, JSON_UNESCAPED_UNICODE) ?>;

                /* สี Pie Chart จำแนกตามคณะ */
                const _FACULTY_COLORS = {
                    'แบบสอบถาม': '#7C3AED',
                    'กิจกรรม': '#F59E0B',
                    'วิทยาศาสตร์': '#1E88E5',
                    'วิศวกรรมศาสตร์': '#F4511E',
                    'บริหารธุรกิจ': '#2E7D32',
                    'ศิลปศาสตร์': '#8E24AA',
                    'นิติศาสตร์': '#C62828',
                    'แพทยศาสตร์': '#00ACC1',
                    'พยาบาลศาสตร์': '#EC407A',
                    'เกษตรศาสตร์': '#7CB342',
                    'เทคโนโลยีสารสนเทศ': '#3949AB',
                    'สาธารณสุข': '#26A69A',
                    'เศรษฐศาสตร์': '#FDD835',
                    'การท่องเที่ยว': '#29B6F6',
                    'อุตสาหกรรม': '#546E7A',
                    'สถาปัตย์': '#6D4C41',
                    'นิเทศศาสตร์': '#D81B60'
                };

                const _DEFAULT_COLORS = ['#9C27B0', '#009688', '#FF9800', '#795548', '#607D8B'];
                const _GRAY = '#E5E7EB';

                function getFacultyColor(name, index) {
                    if (name === 'อื่นๆ') return '#9CA3AF'; // สีเทาสำหรับกลุ่มอื่นๆ
                    for (const [key, color] of Object.entries(_FACULTY_COLORS)) {
                        if (name.includes(key)) return color;
                    }
                    return _DEFAULT_COLORS[index % _DEFAULT_COLORS.length];
                }

                function drawPieChart(canvasId, data) {
                    const canvas = document.getElementById(canvasId);
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    const W = canvas.width, H = canvas.height;
                    const cx = W / 2, cy = H / 2, R = Math.min(cx, cy) - 12;
                    const total = data.reduce((s, d) => s + d.emission, 0);

                    ctx.clearRect(0, 0, W, H);

                    if (total === 0) {
                        /* ไม่มีข้อมูลเลย — วงกลมสีเทา */
                        ctx.beginPath();
                        ctx.arc(cx, cy, R, 0, 2 * Math.PI);
                        ctx.fillStyle = _GRAY;
                        ctx.fill();
                        return;
                    }

                    let startAngle = -Math.PI / 2;
                    data.forEach((d, i) => {
                        if (d.emission <= 0) return;
                        const slice = (d.emission / total) * 2 * Math.PI;
                        ctx.beginPath();
                        ctx.moveTo(cx, cy);
                        ctx.arc(cx, cy, R, startAngle, startAngle + slice);
                        ctx.closePath();
                        ctx.fillStyle = getFacultyColor(d.name, i);
                        ctx.fill();
                        ctx.strokeStyle = '#fff';
                        ctx.lineWidth = 2;
                        ctx.stroke();
                        startAngle += slice;
                    });

                    /* วงกลมกลาง (donut hole) */
                    ctx.beginPath();
                    ctx.arc(cx, cy, R * 0.48, 0, 2 * Math.PI);
                    ctx.fillStyle = '#fff';
                    ctx.fill();

                    /* ตัวเลขกลาง */
                    ctx.fillStyle = '#374151';
                    ctx.font = 'bold 13px Kanit, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.fillText('tCO₂e', cx, cy - 4);
                    ctx.font = '11px Kanit, sans-serif';
                    ctx.fillStyle = '#6B7280';
                    ctx.fillText('สะสม', cx, cy + 14);
                }

                function openCumulativeModal() {
                    const body = document.getElementById('cumulativeModalBody');
                    const total = _cumulData.reduce((s, r) => s + r.emission, 0);

                    /* ─ Top 5 สำหรับ Pie Chart ─ */
                    const withData = _cumulData.filter(r => r.emission > 0);
                    const top5 = withData.slice(0, 5);
                    const othersSum = withData.slice(5).reduce((s, r) => s + r.emission, 0);
                    const pieData = [...top5];
                    if (othersSum > 0) pieData.push({ name: 'อื่นๆ', emission: othersSum });

                    /* ─ Legend (Top 5 + อื่นๆ) ─ */
                    let legendHtml = '<div style="display:flex;flex-direction:column;gap:12px;">';
                    pieData.forEach((r, i) => {
                        const pct = total > 0 ? parseFloat((r.emission / total * 100).toFixed(2)) : 0;
                        const color = getFacultyColor(r.name, i);
                        legendHtml += `<div style="display:flex;align-items:center;gap:14px;font-size:0.95rem;min-width:0;padding:8px 12px;background:#FFFFFF;border:1px solid #F3F4F6;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,0.02);transition:transform 0.2s;">
                    <div style="width:34px;height:34px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="width:14px;height:14px;border-radius:5vh;background:${color};display:inline-block;"></span>
                    </div>
                    <span style="color:#1F2937;flex:1;font-weight:600;white-space:nowrap;">${r.name}</span>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="color:var(--clr-primary);font-weight:800;font-size:1.05rem;line-height:1;">${pct}%</div>
                        <div style="color:var(--text-muted);font-weight:600;font-size:0.75rem;margin-top:4px;">${r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} tCO₂e</div>
                    </div>
                </div>`;
                    });
                    legendHtml += '</div>';

                    /* ─ Table rows (ทุกคณะ) ─ */
                    let rowsHtml = '';
                    _cumulData.forEach((r, i) => {
                        const pct = total > 0 ? (r.emission / total * 100) : 0;
                        const pctDisplay = total > 0 ? parseFloat(pct.toFixed(2)) : 0;
                        const hasEmission = r.emission > 0;
                        const color = hasEmission ? getFacultyColor(r.name, i) : _GRAY;
                        rowsHtml += `<tr>
                    <td style="color:var(--text-muted);font-weight:600;">${i + 1}</td>
                    <td style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:5vh;background:${color};margin-right:6px;vertical-align:middle;"></span>
                        ${r.name}
                    </td>
                    <td style="text-align:right;font-weight:700;color:${hasEmission ? 'var(--clr-primary)' : '#9CA3AF'};">
                        ${hasEmission
                                ? r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
                                : '<span style="font-size:0.82rem;font-weight:500;">ไม่มีข้อมูล</span>'}
                    </td>
                    <td style="text-align:right;">
                        <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                            <div style="flex:1;min-width:60px;height:8px;background:#F3F4F6;border-radius:999px;overflow:hidden;">
                                <div style="width:${pct.toFixed(1)}%;height:100%;background:${color};border-radius:999px;"></div>
                            </div>
                            <span style="font-size:0.82rem;color:var(--text-muted);font-weight:600;min-width:54px;">${pctDisplay}%</span>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        ${r.affil_id !== null
                                ? `<button class="btn-detail" onclick="closeCumulativeModal(); openAffilYearly(${r.affil_id}, '${r.name}')">รายละเอียด</button>`
                                : (r.source
                                    ? `<button class="btn-detail" onclick="closeCumulativeModal(); openAffilYearly(null, '${r.name}', '${r.source}')">รายละเอียด</button>`
                                    : '<span style="color:#9CA3AF;font-size:0.8rem;">—</span>')}
                    </td>
                </tr>`;
                    });

                    body.innerHTML = `
            <div class="detail-summary">
                <div class="detail-stat">
                    <div class="detail-stat-label">จำนวนรายการ (คณะ + แบบสอบถาม + กิจกรรม)</div>
                    <div class="detail-stat-value">${_cumulData.length} รายการ</div>
                </div>
                <div class="detail-stat">
                    <div class="detail-stat-label">รวมทุกปี (tCO₂e)</div>
                    <div class="detail-stat-value">${total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                </div>
            </div>

            <!-- Pie Chart + Legend -->
            <div style="display:flex;align-items:center;gap:3rem;padding:2rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:24px;margin-bottom:2rem;flex-wrap:wrap;justify-content:center;">
                <div style="flex-shrink:0;position:relative;">
                    <canvas id="cumulPieChart" width="240" height="240"></canvas>
                </div>
                <div style="flex:1;min-width:350px;">
                    <div style="font-size:0.9rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-secondary);margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
                        สัดส่วนการปล่อยก๊าซ (5 อันดับแรก)
                    </div>
                    ${pieData.length > 0 ? legendHtml : '<p style="color:#9CA3AF;font-size:0.9rem;">ยังไม่มีข้อมูล</p>'}
                </div>
            </div>

            <table class="detail-table" style="table-layout:fixed;width:100%;">
                <colgroup>
                    <col style="width:2.5rem;">
                    <col>
                    <col style="width:185px;">
                    <col style="width:150px;">
                    <col style="width:150px;">
                </colgroup>
                <thead><tr>
                    <th>#</th>
                    <th>คณะ / หน่วยงาน</th>
                    <th style="text-align:right;">Total Emission (tCO₂e)</th>
                    <th style="text-align:right;">สัดส่วน</th>
                    <th style="text-align:center;">รายละเอียด</th>
                </tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>`;

                    document.getElementById('cumulativeModal').style.display = 'flex';
                    lockScroll();

                    /* วาด chart (top5 + อื่นๆ) หลัง DOM render */
                    requestAnimationFrame(() => drawPieChart('cumulPieChart', pieData));
                }

                function closeCumulativeModal() {
                    document.getElementById('cumulativeModal').style.display = 'none';
                    unlockScroll();
                }

                /* ═══ Scope Modal (รายคณะ แยก Scope) ═══ */
                const _scopeAffilData = <?= json_encode($scope_affil_by_scope, JSON_UNESCAPED_UNICODE) ?>;
                const _scopeMeta = {
                    1: { label: 'Scope 1 — การปล่อยก๊าซเรือนกระจกตรง',   color: 'linear-gradient(135deg,#F97316,#EA580C)', total: <?= (float)$scope1 ?> },
                    2: { label: 'Scope 2 — การปล่อยก๊าซเรือนกระจกทางอ้อม (พลังงาน)', color: 'linear-gradient(135deg,#EC4899,#DB2777)', total: <?= (float)$scope2 ?> },
                    3: { label: 'Scope 3 — การปล่อยก๊าซเรือนกระจกทางอ้อมอื่น ๆ',     color: 'linear-gradient(135deg,#3B82F6,#2563EB)', total: <?= (float)$scope3 ?> },
                };

                function openScopeModal(scopeNum) {
                    const meta  = _scopeMeta[scopeNum];
                    const data  = _scopeAffilData[scopeNum] || [];
                    const total = meta.total;

                    document.getElementById('scopeModalIcon').style.background = meta.color;
                    document.getElementById('scopeModalHeader').style.background = meta.color;
                    document.getElementById('scopeModalTitle').textContent = 'ปี ' + _yearLabel + ' — Scope ' + scopeNum;
                    document.getElementById('scopeModalLabel').textContent  = meta.label;

                    const body = document.getElementById('scopeModalBody');

                    /* ─ Top 5 Pie ─ */
                    const withData  = data.filter(r => r.emission > 0);
                    const top5      = withData.slice(0, 5);
                    const othersSum = withData.slice(5).reduce((s, r) => s + r.emission, 0);
                    const pieData   = [...top5];
                    if (othersSum > 0) pieData.push({ name: 'อื่นๆ', emission: othersSum });

                    /* ─ Legend ─ */
                    let legendHtml = '<div style="display:flex;flex-direction:column;gap:12px;">';
                    pieData.forEach((r, i) => {
                        const pct   = total > 0 ? parseFloat((r.emission / total * 100).toFixed(2)) : 0;
                        const color = getFacultyColor(r.name, i);
                        legendHtml += `<div style="display:flex;align-items:center;gap:14px;font-size:0.95rem;min-width:0;padding:8px 12px;background:#FFFFFF;border:1px solid #F3F4F6;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                            <div style="width:34px;height:34px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <span style="width:14px;height:14px;border-radius:5vh;background:${color};display:inline-block;"></span>
                            </div>
                            <span style="color:#1F2937;flex:1;font-weight:600;white-space:nowrap;">${r.name}</span>
                            <div style="text-align:right;flex-shrink:0;">
                                <div style="color:var(--clr-primary);font-weight:800;font-size:1.05rem;line-height:1;">${pct}%</div>
                                <div style="color:var(--text-muted);font-weight:600;font-size:0.75rem;margin-top:4px;">${r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })} tCO₂e</div>
                            </div>
                        </div>`;
                    });
                    legendHtml += '</div>';

                    /* ─ Table rows ─ */
                    let rowsHtml = '';
                    if (data.length === 0) {
                        rowsHtml = `<tr><td colspan="4" style="text-align:center;padding:40px;color:var(--text-muted);">ยังไม่มีข้อมูลในปีนี้</td></tr>`;
                    } else {
                        data.forEach((r, i) => {
                            const pct        = total > 0 ? (r.emission / total * 100) : 0;
                            const pctDisplay = total > 0 ? parseFloat(pct.toFixed(2)) : 0;
                            const hasEmission = r.emission > 0;
                            const color      = hasEmission ? getFacultyColor(r.name, i) : _GRAY;
                            rowsHtml += `<tr>
                                <td style="color:var(--text-muted);font-weight:600;">${i + 1}</td>
                                <td style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:0;">
                                    <span style="display:inline-block;width:10px;height:10px;border-radius:5vh;background:${color};margin-right:6px;vertical-align:middle;"></span>
                                    ${r.name}
                                </td>
                                <td style="text-align:right;font-weight:700;color:${hasEmission ? 'var(--clr-primary)' : '#9CA3AF'};">
                                    ${hasEmission
                                        ? r.emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
                                        : '<span style="font-size:0.82rem;font-weight:500;">ไม่มีข้อมูล</span>'}
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                                        <div style="flex:1;min-width:60px;height:8px;background:#F3F4F6;border-radius:999px;overflow:hidden;">
                                            <div style="width:${pct.toFixed(1)}%;height:100%;background:${color};border-radius:999px;"></div>
                                        </div>
                                        <span style="font-size:0.82rem;color:var(--text-muted);font-weight:600;min-width:54px;">${pctDisplay}%</span>
                                    </div>
                                </td>
                            </tr>`;
                        });
                    }

                    body.innerHTML = `
                    <div class="detail-summary">
                        <div class="detail-stat">
                            <div class="detail-stat-label">จำนวนรายการ (คณะ + แบบสอบถาม + กิจกรรม)</div>
                            <div class="detail-stat-value">${data.length} รายการ</div>
                        </div>
                        <div class="detail-stat">
                            <div class="detail-stat-label">Scope ${scopeNum} — ปี ${_yearLabel} (tCO₂e)</div>
                            <div class="detail-stat-value">${total.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:3rem;padding:2rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:24px;margin-bottom:2rem;flex-wrap:wrap;justify-content:center;">
                        <div style="flex-shrink:0;position:relative;">
                            <canvas id="scopePieChart" width="240" height="240"></canvas>
                        </div>
                        <div style="flex:1;min-width:350px;">
                            <div style="font-size:0.9rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-secondary);margin-bottom:1rem;display:flex;align-items:center;gap:8px;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 20v-6M6 20V10M18 20V4"/></svg>
                                สัดส่วนการปล่อยก๊าซ (5 อันดับแรก)
                            </div>
                            ${pieData.length > 0 ? legendHtml : '<p style="color:#9CA3AF;font-size:0.9rem;">ยังไม่มีข้อมูล</p>'}
                        </div>
                    </div>

                    <table class="detail-table" style="table-layout:fixed;width:100%;">
                        <colgroup>
                            <col style="width:2.5rem;">
                            <col>
                            <col style="width:185px;">
                            <col style="width:150px;">
                        </colgroup>
                        <thead><tr>
                            <th>#</th>
                            <th>คณะ / หน่วยงาน</th>
                            <th style="text-align:right;">Total Emission (tCO₂e)</th>
                            <th style="text-align:right;">สัดส่วน</th>
                        </tr></thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>`;

                    document.getElementById('scopeModal').style.display = 'flex';
                    lockScroll();

                    /* วาด Pie */
                    requestAnimationFrame(() => {
                        const canvas = document.getElementById('scopePieChart');
                        if (!canvas) return;
                        const ctx = canvas.getContext('2d');
                        const W = canvas.width, H = canvas.height;
                        const cx = W / 2, cy = H / 2, R = Math.min(cx, cy) - 12;
                        const chartTotal = pieData.reduce((s, d) => s + d.emission, 0);
                        ctx.clearRect(0, 0, W, H);
                        if (chartTotal === 0) {
                            ctx.beginPath(); ctx.arc(cx, cy, R, 0, 2 * Math.PI);
                            ctx.fillStyle = _GRAY; ctx.fill();
                        } else {
                            let ang = -Math.PI / 2;
                            pieData.forEach((d, i) => {
                                if (d.emission <= 0) return;
                                const slice = (d.emission / chartTotal) * 2 * Math.PI;
                                ctx.beginPath(); ctx.moveTo(cx, cy);
                                ctx.arc(cx, cy, R, ang, ang + slice); ctx.closePath();
                                ctx.fillStyle = getFacultyColor(d.name, i); ctx.fill();
                                ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();
                                ang += slice;
                            });
                        }
                        ctx.beginPath(); ctx.arc(cx, cy, R * 0.48, 0, 2 * Math.PI);
                        ctx.fillStyle = '#fff'; ctx.fill();
                        ctx.fillStyle = '#374151'; ctx.font = 'bold 13px Kanit, sans-serif';
                        ctx.textAlign = 'center'; ctx.fillText('tCO₂e', cx, cy - 4);
                        ctx.font = '11px Kanit, sans-serif'; ctx.fillStyle = '#6B7280';
                        ctx.fillText('Scope ' + scopeNum, cx, cy + 14);
                    });
                }
                function closeScopeModal() {
                    document.getElementById('scopeModal').style.display = 'none';
                    unlockScroll();
                }

                function openDetail(affilId, affilName, yearId, fromYearly = false) {
                    replayDetailPop();
                    document.getElementById('detailModalTitle').textContent = affilName;

                    if (fromYearly) {
                        document.getElementById('detailBreadcrumb').style.display = 'block';
                        document.getElementById('detailBreadcrumb').innerHTML = `
                    <span class="back-btn-pill" onclick="openAffilYearly(${affilId}, '${affilName.replace(/'/g, "\\'")}')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        ย้อนกลับหน้ารายปี
                    </span>
                `;
                    } else {
                        document.getElementById('detailBreadcrumb').style.display = 'none';
                    }

                    document.getElementById('detailModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';
                    document.getElementById('detailModal').style.display = 'flex';
                    lockScroll();

                    fetch(`api/api_affil_detail.php?affil_id=${affilId}&year_id=${yearId}`)
                        .then(r => r.json())
                        .then(data => renderDetail(data))
                        .catch(() => {
                            document.getElementById('detailModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
                        });
                }

                function renderDetail(data) {
                    if (!data || data.length === 0) {
                        document.getElementById('detailModalBody').innerHTML =
                            '<p class="no-data-msg">ยังไม่มีข้อมูลที่กรอกสำหรับคณะนี้</p>';
                        return;
                    }
                    let totalEmission = data.reduce((s, r) => s + parseFloat(r.emission), 0);

                    // Group by activity_type (admin_g)
                    const seen = new Set();
                    const unique = [];
                    _itemsCache = {}; // Clear and populate global cache for level 4
                    data.forEach(r => {
                        if (!seen.has(r.activity_type)) {
                            seen.add(r.activity_type);
                            unique.push({
                                activity_type: r.activity_type,
                                scope: r.scope,
                                total_emission: 0,
                                count: 0
                            });
                            _itemsCache[r.activity_type] = [];
                        }
                        const group = unique.find(g => g.activity_type === r.activity_type);
                        group.total_emission += parseFloat(r.emission);
                        group.count += 1;
                        _itemsCache[r.activity_type].push(r);
                    });

                    let html = `
        <div class="detail-summary">
            <div class="detail-stat">
                <div class="detail-stat-label">รายการทั้งหมด</div>
                <div class="detail-stat-value">${data.length} รายการ</div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat-label">Total Emission</div>
                <div class="detail-stat-value">${totalEmission.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} <small style="font-size:.9rem;opacity:.5;">tCO₂e</small></div>
            </div>
        </div>
        <div class="modal-search-wrap" style="margin-bottom:1rem;">
            <input type="text" class="modal-search-input" placeholder="ค้นหาประเภทกิจกรรม..." oninput="filterTable(this,'detail-tbody-l3')">
        </div>
        <table class="detail-table">
            <thead><tr>
                <th style="text-align:center;">Scope</th>
                <th>ประเภทกิจกรรม (กลุ่ม)</th>

                <th style="text-align:right;">Emission (tCO₂e)</th>
                <th style="text-align:center;">ดูรายการย่อย</th>
            </tr></thead>
            <tbody id="detail-tbody-l3">`;
                    unique.forEach(r => {
                        const sc = r.scope == 1 ? 's1' : (r.scope == 2 ? 's2' : 's3');
                        html += `<tr>
                <td style="text-align:center;"><span class="scope-pill ${sc}">Scope ${r.scope}</span></td>
                <td style="font-weight:600;white-space:nowrap;">${r.activity_type}</td>
                <td style="text-align:right;font-weight:700;color:var(--clr-primary);">${r.total_emission.toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</td>
                <td style="text-align:center;">
                    <button class="btn-detail" onclick="openItemDetailGroup('${r.activity_type.replace(/'/g, "\\'")}', true)">ดูรายการย่อย</button>
                </td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('detailModalBody').innerHTML = html;
                }

                function closeDetail() {
                    document.getElementById('detailModal').style.display = 'none';
                    unlockScroll();
                }

                /* ═══ รายละเอียดรายปี (คลิกจากตารางสะสม) ═══ */
                function openAffilYearly(affilId, affilName, source = null) {
                    replayDetailPop();
                    document.getElementById('detailModalTitle').textContent = affilName;

                    document.getElementById('detailBreadcrumb').style.display = 'block';
                    document.getElementById('detailBreadcrumb').innerHTML = `
                <span class="back-btn-pill" onclick="closeDetail(); document.getElementById('cumulativeModal').style.display='flex'; lockScroll();">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                    ย้อนกลับหน้ารวมทุกคณะ
                </span>
            `;

                    document.getElementById('detailModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';
                    document.getElementById('detailModal').style.display = 'flex';
                    lockScroll();

                    const url = source
                        ? `api/api_affil_yearly.php?source=${source}`
                        : `api/api_affil_yearly.php?affil_id=${affilId}`;
                    fetch(url)
                        .then(r => r.json())
                        .then(data => renderAffilYearly(data, affilId, affilName, source))
                        .catch(() => {
                            document.getElementById('detailModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
                        });
                }

                function renderAffilYearly(data, affilId, affilName, source = null) {
                    if (!data || data.length === 0) {
                        document.getElementById('detailModalBody').innerHTML =
                            '<p class="no-data-msg">ยังไม่มีข้อมูลการปล่อยก๊าซสำหรับคณะนี้</p>';
                        return;
                    }
                    let totalEmission = data.reduce((s, r) => s + parseFloat(r.total_emission), 0);
                    let html = `
        <div class="detail-summary">
            <div class="detail-stat">
                <div class="detail-stat-label">ปีที่มีข้อมูล</div>
                <div class="detail-stat-value">${data.length} ปี</div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat-label">รวมทุกปี (tCO₂e)</div>
                <div class="detail-stat-value">${totalEmission.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
            </div>
        </div>
        <table class="detail-table">
            <thead><tr>
                <th>ปี</th>
                <th style="text-align:center;">จำนวนรายการ</th>
                <th style="text-align:right;">Emission (tCO₂e)</th>
                <th style="text-align:center;">ดูรายการย่อย</th>
            </tr></thead>
            <tbody>`;
                    data.forEach((r, i) => {
                        const hasData = parseInt(r.entry_count) > 0;
                        let btn;
                        if (source) {
                            btn = hasData
                                ? `<button class="btn-detail" onclick="openSourceYearDetail('${source}', ${r.year_id}, '${r.year}', '${affilName.replace(/'/g, "\\'")}')">ดูรายการ</button>`
                                : '<span style="color:#9CA3AF;font-size:0.8rem;">—</span>';
                        } else {
                            btn = `<button class="btn-detail" onclick="openDetail(${affilId}, '${affilName.replace(/'/g, "\\'")}', ${r.year_id}, true)">รายการกิจกรรม</button>`;
                        }
                        html += `<tr>
                <td style="font-weight:700;">${r.year}</td>
                <td style="text-align:center;"><span class="badge">${r.entry_count} รายการ</span></td>
                <td style="text-align:right;font-weight:700;color:var(--clr-primary);">${parseFloat(r.total_emission).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}</td>
                <td style="text-align:center;">${btn}</td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('detailModalBody').innerHTML = html;
                }

                // รายการย่อยของ แบบสอบถาม/กิจกรรม ในปีที่เลือก (drill จากหน้ายอดรายปีในโมดัลสะสม)
                let _symEvents = [];
                let _symName = '', _symSource = '', _symYearLabel = '';

                let _symSurveys = [];
                // ปุ่มหลักฐานทั่วไป (คืน '-' ถ้าไม่มี)
                function _evBtnHtml(onclickExpr, cnt) {
                    cnt = Number(cnt || 0);
                    if (cnt <= 0) return '<span style="color:var(--text-muted);">-</span>';
                    return `<button class="btn-detail" style="background:#EEF2FF;color:#4F46E5;" onclick="${onclickExpr}">📎 ไฟล์ <span style="background:#EF4444;color:#fff;font-size:.65rem;font-weight:800;border-radius:999px;padding:1px 6px;margin-left:2px;">${cnt}</span></button>`;
                }
                function openSourceYearDetail(source, yearId, yearLabel, sourceName) {
                    replayDetailPop();
                    _symName = sourceName; _symSource = source; _symYearLabel = yearLabel;
                    document.getElementById('detailModalTitle').textContent = sourceName + ' — ' + yearLabel;
                    document.getElementById('detailBreadcrumb').style.display = 'block';
                    document.getElementById('detailModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';

                    fetch(`api/api_affil_detail.php?source=${source}&year_id=${yearId}`)
                        .then(r => r.json())
                        .then(data => {
                            const body = document.getElementById('detailModalBody');
                            if (!data || data.length === 0) { body.innerHTML = '<p class="no-data-msg">ยังไม่มีข้อมูล</p>'; return; }
                            if (source === 'survey') {
                                // แบบสอบถาม → 2 ระดับ: กลุ่ม (แยกตามคณะผู้จัดทำ) → คำถาม
                                _symSurveys = [];
                                const sidx = {};
                                data.forEach(r => {
                                    const key = (r.questionnaire_id != null) ? ('q' + r.questionnaire_id) : ('a:' + (r.audience ?? '-'));
                                    if (!(key in sidx)) {
                                        sidx[key] = _symSurveys.length;
                                        _symSurveys.push({ audience: r.audience ?? '-', maker: r.maker_name ?? '-', qid: r.questionnaire_id, ev_count: Number(r.ev_count || 0), items: [] });
                                    }
                                    _symSurveys[sidx[key]].items.push(r);
                                });
                                renderSymSurveyList();
                                return;
                            }
                            // กิจกรรม → จัดกลุ่มตาม event แล้วโชว์ "รายการกิจกรรม" ก่อน
                            _symEvents = [];
                            const idx = {};
                            data.forEach(r => {
                                if (!(r.event_id in idx)) {
                                    idx[r.event_id] = _symEvents.length;
                                    _symEvents.push({ name: r.event_name, organizer: r.organizer, event_date: r.event_date, event_end_date: r.event_end_date, event_id: r.event_id, ev_count: Number(r.ev_count || 0), items: [] });
                                }
                                _symEvents[idx[r.event_id]].items.push(r);
                            });
                            // แสดงเฉพาะกิจกรรมที่มีรายการปล่อย
                            _symEvents = _symEvents.filter(ev => ev.items.some(it => it.itype === 'emit'));
                            renderSymEventList();
                        })
                        .catch(() => {
                            document.getElementById('detailModalBody').innerHTML = '<p class="no-data-msg">เกิดข้อผิดพลาด</p>';
                        });
                }

                // ปุ่มกลับ "ยอดรายปี"
                function _symYearlyBackPill() {
                    return `<span class="back-btn-pill" onclick="openAffilYearly(null, '${_symName.replace(/'/g, "\\'")}', '${_symSource}')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับยอดรายปี</span>`;
                }

                // กิจกรรม ระดับ 1: รายการกิจกรรม
                function renderSymEventList() {
                    document.getElementById('detailModalTitle').textContent = _symName + ' — ' + _symYearLabel;
                    document.getElementById('detailBreadcrumb').innerHTML = _symYearlyBackPill();
                    let html = `<div class="modal-search-wrap"><input type="text" class="modal-search-input" placeholder="ค้นหากิจกรรม..." oninput="filterTable(this,'tbody-sy')"></div>
        <table class="detail-table" style="table-layout:fixed;"><thead><tr>
            <th>กิจกรรม</th>
            <th style="text-align:center;width:13rem;">วันที่</th><th style="text-align:right;width:10rem;">Emission (tCO₂e)</th><th style="text-align:center;width:8rem;">ดูรายละเอียด</th>
        </tr></thead><tbody id="tbody-sy">`;
                    _symEvents.forEach((ev, i) => {
                        const emit = ev.items.reduce((s, it) => s + (it.itype === 'emit' ? Number(it.emission || 0) : 0), 0);
                        html += `<tr>
            <td style="font-weight:600;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${(ev.name || '').replace(/"/g, '&quot;')}">${ev.name}</div></td>
            <td style="text-align:center;white-space:nowrap;">${_dateRange(ev)}</td>
            <td style="text-align:right;font-weight:700;color:var(--clr-primary);">${emit.toLocaleString('th-TH', { minimumFractionDigits: 3, maximumFractionDigits: 3 })}</td>
            <td style="text-align:center;"><button class="btn-detail" onclick="openSymEventDetail(${i})">ดูรายละเอียด</button></td>
        </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('detailModalBody').innerHTML = html;
                }

                // กิจกรรม ระดับ 2: รายการย่อยของกิจกรรมนั้น
                function openSymEventDetail(i) {
                    const ev = _symEvents[i];
                    if (!ev) return;
                    replayDetailPop();
                    document.getElementById('detailModalTitle').innerHTML = String(ev.name ?? '').replace(/</g, '&lt;') + ' <span style="font-weight:400;opacity:.9;">· ผู้จัด: ' + String(ev.organizer ?? '-').replace(/</g, '&lt;') + '</span>';
                    document.getElementById('detailBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="renderSymEventList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับรายการกิจกรรม</span>`;
                    let html = `<div class="modal-search-wrap"><input type="text" class="modal-search-input" placeholder="ค้นหารายการ..." oninput="filterTable(this,'tbody-sy')"></div>
        <table class="detail-table"><thead><tr>
            <th>รายการ</th><th style="text-align:center;width:8rem;">ประเภท</th><th style="text-align:center;width:6.5rem;">Scope</th><th style="text-align:center;width:7rem;">หน่วย</th>
            <th style="text-align:right;width:7rem;">จำนวน</th><th style="text-align:right;width:7rem;">tCO₂e</th>
        </tr></thead><tbody id="tbody-sy">`;
                    ev.items.filter(r => r.itype !== 'none').forEach(r => {
                        const vol = Number(r.vol).toLocaleString('th-TH', { maximumFractionDigits: 4 });
                        const emi = Number(r.emission).toLocaleString('th-TH', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
                        html += `<tr><td style="font-weight:600;">${r.name_tiem}</td><td style="text-align:center;">${_evTypeBadge(r.itype)}</td><td style="text-align:center;">${_evScopeCell(r)}</td><td style="text-align:center;color:var(--text-muted);">${r.unit ?? '-'}</td><td style="text-align:right;font-weight:700;">${vol}</td><td style="text-align:right;font-weight:700;color:${r.itype === 'rmv' ? '#166534' : 'var(--clr-primary)'};">${emi}</td></tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('detailModalBody').innerHTML = html;
                }

                // แบบสอบถาม ระดับ 1: รายการกลุ่ม (แยกตามคณะผู้จัดทำ) — กลุ่ม / ผู้จัดทำ / จำนวนผู้ตอบ / หลักฐาน / ดูรายละเอียด
                function renderSymSurveyList() {
                    document.getElementById('detailModalTitle').textContent = _symName + ' — ' + _symYearLabel;
                    document.getElementById('detailBreadcrumb').innerHTML = _symYearlyBackPill();
                    let html = `<div class="modal-search-wrap"><input type="text" class="modal-search-input" placeholder="ค้นหากลุ่ม..." oninput="filterTable(this,'tbody-sy')"></div>
        <table class="detail-table" style="table-layout:fixed;"><thead><tr>
            <th>กลุ่ม</th><th style="text-align:right;width:9rem;">จำนวนผู้ตอบ</th>
            <th style="text-align:center;width:8rem;">ดูรายละเอียด</th>
        </tr></thead><tbody id="tbody-sy">`;
                    _symSurveys.forEach((g, i) => {
                        const resp = Math.max(0, ...g.items.map(it => Number(it.respondents) || 0)).toLocaleString('th-TH');
                        html += `<tr>
            <td style="font-weight:600;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${(g.audience || '').replace(/"/g, '&quot;')}">${g.audience}</div></td>
            <td style="text-align:right;font-weight:700;">${resp}</td>
            <td style="text-align:center;"><button class="btn-detail" onclick="openSymSurveyDetail(${i})">ดูรายละเอียด</button></td>
        </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('detailModalBody').innerHTML = html;
                }
                function openSymSurveyEvidence(i) {
                    const g = _symSurveys[i]; if (!g || g.qid == null) return;
                    openEntityEvidence('questionnaire', g.qid, g.audience + ' · ' + (g.maker || ''));
                }
                function openSymEventEvidence(i) {
                    const ev = _symEvents[i]; if (!ev || ev.event_id == null) return;
                    openEntityEvidence('event', ev.event_id, ev.name);
                }
                // แบบสอบถาม ระดับ 2: คำถาม / เฉลี่ย/คน / หน่วย / tCO₂e + ปุ่มกลับแบบสอบถาม
                function openSymSurveyDetail(i) {
                    const g = _symSurveys[i];
                    if (!g) return;
                    replayDetailPop();
                    document.getElementById('detailModalTitle').innerHTML = String(g.audience ?? '').replace(/</g, '&lt;') + ' <span style="font-weight:400;opacity:.9;">· ผู้จัดทำ: ' + String(g.maker ?? '-').replace(/</g, '&lt;') + '</span>';
                    document.getElementById('detailBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="renderSymSurveyList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับแบบสอบถาม</span>`;
                    let html = `<div class="modal-search-wrap"><input type="text" class="modal-search-input" placeholder="ค้นหาคำถาม..." oninput="filterTable(this,'tbody-sy')"></div>
        <table class="detail-table"><thead><tr>
            <th>คำถาม</th><th style="text-align:right;width:6rem;">เฉลี่ย/คน</th>
            <th style="text-align:center;width:5rem;">หน่วย</th><th style="text-align:right;width:7rem;">tCO₂e</th>
        </tr></thead><tbody id="tbody-sy">`;
                    g.items.forEach(r => {
                        const avg = Number(r.avg_value).toLocaleString('th-TH', { maximumFractionDigits: 4 });
                        const emi = Number(r.emission).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
                        html += `<tr><td style="font-weight:600;">${r.name_tiem}</td><td style="text-align:right;">${avg}</td><td style="text-align:center;color:var(--text-muted);">${r.unit ?? '-'}</td><td style="text-align:right;font-weight:700;color:var(--clr-primary);">${emi}</td></tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('detailModalBody').innerHTML = html;
                }

                /* ═══ Reports Modal (จำนวนครั้งที่รายงาน — รายคณะ) ═══ */
                function openReportsModal() {
                    document.getElementById('reportsModalTitle').textContent = 'จำนวนครั้งที่รายงาน';
                    document.getElementById('reportsBreadcrumb').style.display = 'none';
                    document.getElementById('reportsModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';
                    document.getElementById('reportsModal').style.display = 'flex';
                    lockScroll();


                    fetch('api/api_reports_list.php?mode=list')
                        .then(r => r.json())
                        .then(data => renderReportsList(data))
                        .catch(() => {
                            document.getElementById('reportsModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>';
                        });
                }

                /* ═══ Search helper ═══ */
                function filterTable(inputEl, tbodyId) {
                    const q = inputEl.value.toLowerCase();
                    document.querySelectorAll('#' + tbodyId + ' tr').forEach(tr => {
                        const text = tr.textContent.toLowerCase();
                        tr.style.display = text.includes(q) ? '' : 'none';
                    });
                }

                function renderReportsList(data) {
                    if (!data || data.length === 0) {
                        document.getElementById('reportsModalBody').innerHTML =
                            '<p class="no-data-msg">ยังไม่มีข้อมูลการรายงาน</p>';
                        return;
                    }
                    let totalReports = data.reduce((s, r) => s + parseInt(r.year_count || 0), 0);
                    let html = `
        <div class="detail-summary">
            <div class="detail-stat">
                <div class="detail-stat-label">จำนวนผู้ให้ข้อมูล (คณะ + แบบสอบถาม + กิจกรรม)</div>
                <div class="detail-stat-value">${data.length} รายการ</div>
            </div>
            <div class="detail-stat">
                <div class="detail-stat-label">รายงานทั้งหมด (ผู้ให้ข้อมูล × ปี)</div>
                <div class="detail-stat-value">${totalReports} ครั้ง</div>
            </div>
        </div>
        <div class="modal-search-wrap">
            <input type="text" class="modal-search-input" placeholder="ค้นหาชื่อคณะ..." oninput="filterTable(this,'tbody-l1')">
        </div>
        <table class="detail-table">
            <thead><tr>
                <th>ผู้ให้ข้อมูล (คณะ / แบบสอบถาม / กิจกรรม)</th>
                <th style="text-align:center;">จำนวนปีที่รายงาน</th>
                <th style="text-align:center;">รายละเอียด</th>
            </tr></thead>
            <tbody id="tbody-l1">`;
                    data.forEach((r, i) => {
                        const cnt = parseInt(r.year_count || 0);
                        const badgeColor = cnt > 0 ? 'var(--clr-primary)' : '#9CA3AF';
                        html += `<tr>
                <td style="font-weight:600;">${r.affil_name}</td>
                <td style="text-align:center;">
                    <span style="display:inline-block;padding:4px 14px;border-radius:999px;font-size:0.82rem;font-weight:700;background:${cnt > 0 ? '#F3EAFF' : '#F3F4F6'};color:${badgeColor};">${cnt} ปี</span>
                </td>
                <td style="text-align:center;">
                    ${cnt > 0
                                ? `<button class="btn-detail" onclick="openReportsYears(${r.affil_id === null ? 'null' : r.affil_id}, '${r.affil_name.replace(/'/g, "\\'")}', ${r.source ? `'${r.source}'` : 'null'})">ดูปีที่กรอก</button>`
                                : `<span style="color:#9CA3AF;font-size:0.85rem;">ไม่มีข้อมูล</span>`
                            }
                </td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }

                function replayReportsPop() {
                    const box = document.querySelector('#reportsModal .modal-box');
                    if (!box) return;
                    box.style.animation = 'none';
                    void box.offsetWidth;
                    box.style.animation = 'modalPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1)';
                }

                function replayDetailPop() {
                    const box = document.querySelector('#detailModal .modal-box');
                    if (!box) return;
                    box.style.animation = 'none';
                    void box.offsetWidth;   // force reflow เพื่อรีสตาร์ท animation
                    box.style.animation = 'modalPop 0.5s cubic-bezier(0.34, 1.56, 0.64, 1)';
                }

                function openReportsYears(affilId, affilName, source = null) {
                    replayReportsPop();
                    document.getElementById('reportsModalTitle').textContent = affilName;
                    document.getElementById('reportsBreadcrumb').style.display = 'block';
                    // breadcrumb ระดับ "ปีที่รายงาน" → ปุ่มกลับไปรายการผู้ให้ข้อมูล (list)
                    document.getElementById('reportsBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="backToReportsList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับรายการคณะ</span>`;
                    document.getElementById('reportsModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';

                    const url = source
                        ? `api/api_reports_list.php?mode=years&source=${source}`
                        : `api/api_reports_list.php?mode=years&affil_id=${affilId}`;
                    fetch(url)
                        .then(r => r.json())
                        .then(data => renderReportsYears(data, affilId, source))
                        .catch(() => {
                            document.getElementById('reportsModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาด</p>';
                        });
                }

                function renderReportsYears(data, affilId, source = null) {
                    if (!data || data.length === 0) {
                        document.getElementById('reportsModalBody').innerHTML =
                            '<p class="no-data-msg">ยังไม่มีข้อมูลที่กรอกสำหรับคณะนี้</p>';
                        return;
                    }
                    let html = `
        <div class="detail-summary">
            <div class="detail-stat">
                <div class="detail-stat-label">จำนวนปีที่รายงาน</div>
                <div class="detail-stat-value">${data.length} ปี</div>
            </div>
        </div>
        <table class="detail-table">
            <thead><tr>
                <th>ปีงบประมาณ</th>
                <th style="text-align:center;">จำนวนรายการที่บันทึก</th>
                <th style="text-align:center;">ดูรายละเอียด</th>
            </tr></thead>
            <tbody>`;
                    data.forEach((r, i) => {
                        html += `<tr>
                <td style="font-weight:700;">${r.year_label}</td>
                <td style="text-align:center;">
                    <span style="display:inline-block;padding:4px 14px;border-radius:999px;font-size:0.82rem;font-weight:700;background:#F3EAFF;color:var(--clr-primary);">${r.item_count} รายการ</span>
                </td>
                <td style="text-align:center;">
                    <button class="btn-detail" onclick="openReportsItems(${affilId === null ? 'null' : affilId}, document.getElementById('reportsModalTitle').textContent, ${r.year_id}, '${r.year_label}', ${source ? `'${source}'` : 'null'})">${source ? 'ดูรายการ' : 'ดูประเภท'}</button>
                </td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }

                function openDetailFromReports(affilId, affilName, yearId) {
                    closeReportsModal();
                    openDetail(affilId, affilName, yearId);
                }

                // Level 3: แสดงประเภทที่กรอกในปีนั้น
                let _prevAffilId = null, _prevAffilName = null;
                function openReportsItems(affilId, affilName, yearId, yearLabel, source = null) {
                    replayReportsPop();
                    _prevAffilId = affilId;
                    _prevAffilName = affilName;
                    document.getElementById('reportsModalTitle').textContent = affilName + ' — ' + yearLabel;
                    document.getElementById('reportsBreadcrumb').style.display = 'block';
                    document.getElementById('reportsBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="openReportsYears(${affilId === null ? 'null' : affilId},'${affilName.replace(/'/g, "\\'")}', ${source ? `'${source}'` : 'null'})"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับปีที่รายงาน</span>`;
                    document.getElementById('reportsModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';

                    const url = source
                        ? `api/api_affil_detail.php?source=${source}&year_id=${yearId}`
                        : `api/api_affil_detail.php?affil_id=${affilId}&year_id=${yearId}`;
                    fetch(url)
                        .then(r => r.json())
                        .then(data => renderReportsItems(data, source))
                        .catch(() => {
                            document.getElementById('reportsModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาด</p>';
                        });
                }

                // cache สำหรับ Level 4
                let _itemsCache = {};

                /* ═══ กิจกรรม 2 ระดับ (รายการกิจกรรม → รายละเอียดต่อกิจกรรม) ═══ */
                let _eventCache = [];
                let _eventListTitle = '';
                let _eventYearsBreadcrumb = '';
                const _dmy = iso => { const m = (iso || '').match(/^(\d{4})-(\d{2})-(\d{2})$/); return m ? m[3] + '/' + m[2] + '/' + m[1] : '-'; };
                const _dateRange = ev => ev.event_end_date ? `${_dmy(ev.event_date)} - ${_dmy(ev.event_end_date)}` : _dmy(ev.event_date);

                // ระดับ 1: รายการกิจกรรม — Scope / กิจกรรม / ผู้จัด / วันที่ / ดูรายละเอียด
                function renderEventList() {
                    document.getElementById('reportsModalTitle').textContent = _eventListTitle;
                    document.getElementById('reportsBreadcrumb').innerHTML = _eventYearsBreadcrumb;
                    let html = `
        <div class="modal-search-wrap">
            <input type="text" class="modal-search-input" placeholder="ค้นหากิจกรรม..." oninput="filterTable(this,'tbody-l3')">
        </div>
        <table class="detail-table" style="table-layout:fixed;">
            <thead><tr>
                <th>กิจกรรม</th>
                <th style="text-align:center;width:13rem;">วันที่</th>
                <th style="text-align:right;width:10rem;">Emission (tCO₂e)</th>
                <th style="text-align:center;width:8rem;">ดูรายละเอียด</th>
                <th style="text-align:center;width:7rem;">ไฟล์</th>
            </tr></thead>
            <tbody id="tbody-l3">`;
                    _eventCache.forEach((ev, i) => {
                        const emit = ev.items.reduce((s, it) => s + (it.itype === 'emit' ? Number(it.emission || 0) : 0), 0);
                        html += `<tr>
                <td style="font-weight:600;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${(ev.name || '').replace(/"/g, '&quot;')}">${ev.name}</div></td>
                <td style="text-align:center;white-space:nowrap;">${_dateRange(ev)}</td>
                <td style="text-align:right;font-weight:700;color:var(--clr-primary);">${emit.toLocaleString('th-TH', { minimumFractionDigits: 3, maximumFractionDigits: 3 })}</td>
                <td style="text-align:center;">
                    <button class="btn-detail" onclick="openEventDetail(${i})">ดูรายละเอียด</button>
                </td>
                <td style="text-align:center;">${_evViewBtn('event', i, ev.ev_count)}</td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }

                // ระดับ 2: รายละเอียดต่อกิจกรรม — รายการ / จำนวน / หน่วย / tCO₂e
                function openEventDetail(i) {
                    const ev = _eventCache[i];
                    if (!ev) return;
                    replayReportsPop();
                    document.getElementById('reportsModalTitle').innerHTML = String(ev.name ?? '').replace(/</g, '&lt;') + ' <span style="font-weight:400;opacity:.9;">· ผู้จัด: ' + String(ev.organizer ?? '-').replace(/</g, '&lt;') + '</span>';
                    document.getElementById('reportsBreadcrumb').style.display = 'block';
                    document.getElementById('reportsBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="backToEventList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับรายการกิจกรรม</span>`;
                    let html = `
        <div class="modal-search-wrap">
            <input type="text" class="modal-search-input" placeholder="ค้นหารายการ..." oninput="filterTable(this,'tbody-l3')">
        </div>
        <table class="detail-table">
            <thead><tr>
                <th>รายการ</th>
                <th style="text-align:center;width:8rem;">ประเภท</th>
                <th style="text-align:center;width:6.5rem;">Scope</th>
                <th style="text-align:center;width:7rem;">หน่วย</th>
                <th style="text-align:right;width:7rem;">จำนวน</th>
                <th style="text-align:right;width:7rem;">tCO₂e</th>
            </tr></thead>
            <tbody id="tbody-l3">`;
                    ev.items.filter(r => r.itype !== 'none').forEach(r => {
                        const vol = Number(r.vol).toLocaleString('th-TH', { maximumFractionDigits: 4 });
                        const emi = Number(r.emission).toLocaleString('th-TH', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
                        html += `<tr>
                <td style="font-weight:600;">${r.name_tiem}</td>
                <td style="text-align:center;">${_evTypeBadge(r.itype)}</td>
                <td style="text-align:center;">${_evScopeCell(r)}</td>
                <td style="text-align:center;color:var(--text-muted);">${r.unit ?? '-'}</td>
                <td style="text-align:right;font-weight:700;">${vol}</td>
                <td style="text-align:right;font-weight:700;color:${r.itype === 'rmv' ? '#166534' : 'var(--clr-primary)'};">${emi}</td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }
                // ป้ายประเภทรายการ (ปล่อย/ดูดกลับ)
                function _evTypeBadge(itype) {
                    return itype === 'rmv'
                        ? '<span style="font-size:.72rem;font-weight:700;color:#166534;background:#DCFCE7;border-radius:999px;padding:2px 10px;white-space:nowrap;">🌱 ดูดกลับ</span>'
                        : '<span style="font-size:.72rem;font-weight:700;color:#92400E;background:#FEF3C7;border-radius:999px;padding:2px 10px;white-space:nowrap;">🏭 ปล่อย</span>';
                }
                // ช่อง Scope: ปล่อย → pill Scope N · ดูดกลับ/ไม่มี → -
                function _evScopeCell(r) {
                    if (r.itype === 'rmv' || r.scope == null) return '<span style="color:var(--text-muted);">-</span>';
                    const s = Number(r.scope);
                    return `<span class="scope-pill s${s}" style="white-space:nowrap;">Scope ${s}</span>`;
                }

                function backToEventList() {
                    replayReportsPop();
                    renderEventList();
                }

                /* ═══ แบบสอบถาม 2 ระดับ (รายการกลุ่ม → รายละเอียดต่อกลุ่ม) ═══ */
                let _surveyCache = [];
                let _surveyListTitle = '';
                let _surveyYearsBreadcrumb = '';

                // ระดับ 1: รายการกลุ่ม — Scope / กลุ่ม / ดูรายละเอียด
                function renderSurveyList() {
                    document.getElementById('reportsModalTitle').textContent = _surveyListTitle;
                    document.getElementById('reportsBreadcrumb').innerHTML = _surveyYearsBreadcrumb;
                    let html = `
        <div class="modal-search-wrap">
            <input type="text" class="modal-search-input" placeholder="ค้นหากลุ่ม..." oninput="filterTable(this,'tbody-l3')">
        </div>
        <table class="detail-table" style="table-layout:fixed;">
            <thead><tr>
                <th style="text-align:center;width:6.5rem;">Scope</th>
                <th>กลุ่ม</th>
                <th style="text-align:center;width:8rem;">ดูรายละเอียด</th>
                <th style="text-align:center;width:7rem;">ไฟล์</th>
            </tr></thead>
            <tbody id="tbody-l3">`;
                    _surveyCache.forEach((g, i) => {
                        const scopes = [...new Set(g.items.map(it => Number(it.scope)))].sort();
                        const pills = scopes.map(s => `<span class="scope-pill s${s}" style="white-space:nowrap;">Scope ${s}</span>`).join(' ');
                        html += `<tr>
                <td style="text-align:center;">${pills}</td>
                <td style="font-weight:600;"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${(g.audience || '').replace(/"/g, '&quot;')}">${g.audience}</div></td>
                <td style="text-align:center;">
                    <button class="btn-detail" onclick="openSurveyDetail(${i})">ดูรายละเอียด</button>
                </td>
                <td style="text-align:center;">${_evViewBtn('survey', i, g.ev_count)}</td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }

                // ระดับ 2: รายละเอียดต่อกลุ่ม — คำถาม / จำนวนผู้ตอบ / เฉลี่ย/คน / หน่วย / tCO₂e
                function openSurveyDetail(i) {
                    const g = _surveyCache[i];
                    if (!g) return;
                    replayReportsPop();
                    document.getElementById('reportsModalTitle').innerHTML = String(g.audience ?? '').replace(/</g, '&lt;') + ' <span style="font-weight:400;opacity:.9;">· ผู้จัดทำ: ' + String(g.maker ?? '-').replace(/</g, '&lt;') + '</span>';
                    document.getElementById('reportsBreadcrumb').style.display = 'block';
                    document.getElementById('reportsBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="backToSurveyList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับรายการกลุ่ม</span>`;
                    let html = `
        <div class="modal-search-wrap">
            <input type="text" class="modal-search-input" placeholder="ค้นหาคำถาม..." oninput="filterTable(this,'tbody-l3')">
        </div>
        <table class="detail-table">
            <thead><tr>
                <th>คำถาม</th>
                <th style="text-align:right;width:7rem;">จำนวนผู้ตอบ</th>
                <th style="text-align:right;width:6rem;">เฉลี่ย/คน</th>
                <th style="text-align:center;width:5rem;">หน่วย</th>
                <th style="text-align:right;width:7rem;">tCO₂e</th>
            </tr></thead>
            <tbody id="tbody-l3">`;
                    g.items.forEach(r => {
                        const resp = Number(r.respondents).toLocaleString('th-TH');
                        const avg = Number(r.avg_value).toLocaleString('th-TH', { maximumFractionDigits: 4 });
                        const emi = Number(r.emission).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
                        html += `<tr>
                <td style="font-weight:600;">${r.name_tiem}</td>
                <td style="text-align:right;font-weight:700;">${resp}</td>
                <td style="text-align:right;">${avg}</td>
                <td style="text-align:center;color:var(--text-muted);">${r.unit ?? '-'}</td>
                <td style="text-align:right;font-weight:700;color:var(--clr-primary);">${emi}</td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }

                function backToSurveyList() {
                    replayReportsPop();
                    renderSurveyList();
                }

                function renderReportsItems(data, source = null) {
                    if (!data || data.length === 0) {
                        document.getElementById('reportsModalBody').innerHTML =
                            '<p class="no-data-msg">ยังไม่มีข้อมูลที่กรอก</p>';
                        return;
                    }

                    // แบบสอบถาม → 2 ระดับ: (1) รายการกลุ่ม (2) รายละเอียดต่อกลุ่ม
                    if (source === 'survey') {
                        _surveyCache = [];
                        const idxOf = {};
                        data.forEach(r => {
                            // แยกตาม questionnaire (คณะผู้จัดทำ) — ชื่อซ้ำข้ามคณะได้
                            const key = (r.questionnaire_id != null) ? ('q' + r.questionnaire_id) : ('a:' + (r.audience ?? '-'));
                            if (!(key in idxOf)) {
                                idxOf[key] = _surveyCache.length;
                                _surveyCache.push({ audience: r.audience ?? '-', maker: r.maker_name ?? '-', qid: r.questionnaire_id, ev_count: Number(r.ev_count || 0), items: [] });
                            }
                            _surveyCache[idxOf[key]].items.push(r);
                        });
                        _surveyListTitle = document.getElementById('reportsModalTitle').textContent;
                        _surveyYearsBreadcrumb = document.getElementById('reportsBreadcrumb').innerHTML;
                        renderSurveyList();
                        return;
                    }

                    // กิจกรรม → 2 ระดับ: (1) รายการกิจกรรม (2) รายละเอียดต่อกิจกรรม
                    if (source === 'event') {
                        // จัดกลุ่มรายการตาม event
                        _eventCache = [];
                        const idxOf = {};
                        data.forEach(r => {
                            if (!(r.event_id in idxOf)) {
                                idxOf[r.event_id] = _eventCache.length;
                                _eventCache.push({
                                    name: r.event_name, organizer: r.organizer,
                                    event_date: r.event_date, event_end_date: r.event_end_date,
                                    event_id: r.event_id, ev_count: Number(r.ev_count || 0), items: []
                                });
                            }
                            _eventCache[idxOf[r.event_id]].items.push(r);
                        });
                        // หน้าการปล่อย → แสดงเฉพาะกิจกรรมที่มีรายการปล่อย (ดูดกลับล้วนไปดูที่ GHG Removal)
                        _eventCache = _eventCache.filter(ev => ev.items.some(it => it.itype === 'emit'));
                        // จำ breadcrumb "กลับปีที่รายงาน" ไว้ใช้ตอนกลับจากหน้ารายละเอียด
                        _eventListTitle = document.getElementById('reportsModalTitle').textContent;
                        _eventYearsBreadcrumb = document.getElementById('reportsBreadcrumb').innerHTML;
                        renderEventList();
                        return;
                    }

                    // คณะ (officer) → เหมือนเดิม: จัดกลุ่มตามประเภท + ปุ่มดูรายละเอียด (มีไฟล์)
                    const seen = new Set();
                    const unique = [];
                    _itemsCache = {};
                    data.forEach(r => {
                        if (!seen.has(r.activity_type)) {
                            seen.add(r.activity_type);
                            unique.push(r);
                            _itemsCache[r.activity_type] = [];
                        }
                        _itemsCache[r.activity_type].push(r);
                    });

                    let html = `
        <div class="modal-search-wrap">
            <input type="text" class="modal-search-input" placeholder="ค้นหาประเภทกิจกรรม..." oninput="filterTable(this,'tbody-l3')">
        </div>
        <table class="detail-table">
            <thead><tr>
                <th style="text-align:center;">Scope</th>
                <th>ประเภทกิจกรรม</th>
                <th style="text-align:center;">ดูรายละเอียด</th>
            </tr></thead>
            <tbody id="tbody-l3">`;
                    unique.forEach(r => {
                        const sc = r.scope == 1 ? 's1' : (r.scope == 2 ? 's2' : 's3');
                        html += `<tr>
                <td style="text-align:center;"><span class="scope-pill ${sc}">Scope ${r.scope}</span></td>
                <td>${r.activity_type}</td>
                <td style="text-align:center;">
                    <button class="btn-detail" onclick="openItemDetailGroup('${r.activity_type.replace(/'/g, "\\'")}')">ดูรายละเอียด</button>
                </td>
            </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('reportsModalBody').innerHTML = html;
                }

                /* ═══ Level 4: Item Detail (Vol + Files) ═══ */

                // เปิด detail จาก group (ประเภทกิจกรรม) — แสดงรายการย่อยทั้งหมดในกลุ่มนั้น
                function openItemDetailGroup(activityType, hideFiles = false) {
                    const items = _itemsCache[activityType] || [];
                    document.getElementById('itemDetailTitle').textContent = activityType;

                    // Set scope color
                    if (items.length > 0) {
                        const scope = items[0].scope;
                        let headerBg = 'linear-gradient(135deg, var(--clr-primary), #8B5CF6)';
                        if (scope == 1) headerBg = 'linear-gradient(135deg, #F97316, #EA580C)';
                        if (scope == 2) headerBg = 'linear-gradient(135deg, #EC4899, #BE185D)';
                        if (scope == 3) headerBg = 'linear-gradient(135deg, #3B82F6, #1D4ED8)';
                        document.getElementById('itemDetailHeader').style.background = headerBg;
                        document.getElementById('itemDetailIcon').style.background = 'rgba(255, 255, 255, 0.2)';
                    } else {
                        document.getElementById('itemDetailHeader').style.background = 'linear-gradient(135deg, var(--clr-primary), #8B5CF6)';
                        document.getElementById('itemDetailIcon').style.background = 'rgba(255, 255, 255, 0.2)';
                    }

                    document.getElementById('itemDetailModal').style.display = 'flex';
                    lockScroll();

                    if (items.length === 0) {
                        document.getElementById('itemDetailBody').innerHTML =
                            '<p class="no-data-msg">ไม่มีข้อมูล</p>';
                        return;
                    }

                    // แสดงตารางรายการย่อย (name_tiem, Vol, emission) + ลิงก์ไฟล์ต่อแถว
                    let html = `
            <div class="modal-search-wrap">
                <input type="text" class="modal-search-input" placeholder="ค้นหารายการ..." oninput="filterTable(this,'tbody-l4')">
            </div>
            <table class="detail-table">
                <thead><tr>
                    <th>รายการ</th>     
                    <th>หน่วย</th>
                    <th style="text-align:right;">จำนวน</th>
                    ${!hideFiles ? `<th style="text-align:center;">ไฟล์</th>` : ''}
                </tr></thead>
                <tbody id="tbody-l4">`;
                    items.forEach((r, i) => {
                        html += `<tr>
                    <td>${r.name_tiem}</td>
                    <td>${r.unit}</td>
                    <td style="text-align:right;">${parseFloat(r.vol).toLocaleString('th-TH', { maximumFractionDigits: 4 })}</td>
                    ${!hideFiles ? `
                    <td style="text-align:center;">
                        <button class="btn-detail" style="position:relative;" onclick="openFilesLightbox(${r.user_item_id}, '${r.name_tiem.replace(/'/g, "\'")}')">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            ไฟล์
                            ${Number(r.ev_count) > 0 ? `<span style="position:absolute;top:-7px;right:-7px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#EF4444;color:#fff;font-size:0.68rem;font-weight:800;line-height:18px;box-shadow:0 1px 3px rgba(0,0,0,.25);">${Number(r.ev_count)}</span>` : ''}
                        </button>
                    </td>` : ''}
                </tr>`;
                    });
                    html += '</tbody></table>';
                    document.getElementById('itemDetailBody').innerHTML = html;
                }

                function openItemDetail(userItemId, itemName) {
                    document.getElementById('itemDetailTitle').textContent = itemName;
                    document.getElementById('itemDetailBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';
                    document.getElementById('itemDetailModal').style.display = 'flex';
                    lockScroll();

                    fetch(`api/api_item_detail.php?user_item_id=${userItemId}`)
                        .then(r => r.json())
                        .then(res => renderItemDetail(res))
                        .catch(() => {
                            document.getElementById('itemDetailBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาด</p>';
                        });
                }

                function renderItemDetail(res) {
                    if (!res.success || !res.item) {
                        document.getElementById('itemDetailBody').innerHTML =
                            '<p class="no-data-msg">ไม่พบข้อมูล</p>';
                        return;
                    }
                    const it = res.item;
                    const sc = it.scope == 1 ? 's1' : (it.scope == 2 ? 's2' : 's3');
                    const files = res.files || [];

                    // store files globally for lightbox
                    window._lbFiles = files;

                    const filesBtnHtml = files.length === 0
                        ? `<div style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:12px;color:var(--text-muted);font-size:0.9rem;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                    ไม่มีไฟล์แนบ
                  </div>`
                        : `<button class="btn-detail" onclick="openLightbox(0)" style="gap:10px;">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    ดูไฟล์หลักฐาน <span style="background:rgba(255,255,255,0.25);border-radius:999px;padding:1px 9px;font-size:0.82rem;">${files.length}</span>
                  </button>`;

                    document.getElementById('itemDetailBody').innerHTML = `
                <div class="detail-summary" style="margin-bottom:1.25rem;">
                    <div class="detail-stat">
                        <div class="detail-stat-label">Scope</div>
                        <div><span class="scope-pill ${sc}" style="font-size:0.95rem;">Scope ${it.scope}</span></div>
                    </div>
                    <div class="detail-stat">
                        <div class="detail-stat-label">จำนวน (Vol)</div>
                        <div class="detail-stat-value">${parseFloat(it.Vol).toLocaleString('th-TH', { maximumFractionDigits: 4 })} <small style="font-size:.85rem;opacity:.5;">${it.unit}</small></div>
                    </div>
                    <div class="detail-stat">
                        <div class="detail-stat-label">ค่า Emission</div>
                        <div class="detail-stat-value">${parseFloat(it.emission).toLocaleString('th-TH', { maximumFractionDigits: 4 })} <small style="font-size:.85rem;opacity:.5;">tCO₂e</small></div>
                    </div>
                </div>
                <div style="margin-top:1rem;">
                    <div style="font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:0.8rem;">ไฟล์หลักฐาน (${files.length} ไฟล์)</div>
                    ${filesBtnHtml}
                </div>`;
                }

                /* ═══ Lightbox (Gallery + Single mode) ═══ */
                let _lbIdx = 0;
                let _lbMode = 'gallery'; // 'gallery' | 'single'

                function openFilesLightbox(userItemId, itemName) {
                    fetch(`api/api_item_detail.php?user_item_id=${userItemId}`)
                        .then(r => r.json())
                        .then(res => {
                            if (!res.success) return;
                            window._lbFiles = res.files || [];
                            window._lbTitle = itemName || '';
                            openLightbox();
                        })
                        .catch(() => alert('โหลดไฟล์ไม่ได้'));
                }

                // ── ปุ่ม "ไฟล์" ในตาราง survey/event — สไตล์เดียวกับ scope entry (แสดงทุกแถว + badge ถ้ามี) ──
                function _evViewBtn(kind, i, cnt) {
                    cnt = Number(cnt || 0);
                    const fn = kind === 'survey' ? 'openSurveyEvidence' : 'openEventEvidence';
                    const badge = cnt > 0 ? `<span style="position:absolute;top:-7px;right:-7px;min-width:18px;height:18px;padding:0 4px;border-radius:9px;background:#EF4444;color:#fff;font-size:0.68rem;font-weight:800;line-height:18px;box-shadow:0 1px 3px rgba(0,0,0,.25);">${cnt}</span>` : '';
                    return `<button class="btn-detail" style="position:relative;" onclick="${fn}(${i})"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="vertical-align:-2px;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg> ไฟล์${badge}</button>`;
                }
                function openSurveyEvidence(i) {
                    const g = _surveyCache[i]; if (!g || g.qid == null) return;
                    openEntityEvidence('questionnaire', g.qid, g.audience + ' · ' + (g.maker || ''));
                }
                function openEventEvidence(i) {
                    const ev = _eventCache[i]; if (!ev || ev.event_id == null) return;
                    openEntityEvidence('event', ev.event_id, ev.name);
                }
                // โหลดหลักฐาน (ไฟล์+ลิงก์) ของ entity แล้วเปิด lightbox เดียวกับ scope entry
                function openEntityEvidence(entityType, entityId, title) {
                    fetch(`../officer/api/manage_evidence.php?action=list&entity_type=${encodeURIComponent(entityType)}&entity_id=${entityId}`)
                        .then(r => r.json())
                        .then(res => {
                            window._lbFiles = (res && res.success ? res.data : []) || [];
                            window._lbTitle = title || '';
                            openLightbox();
                        })
                        .catch(() => alert('โหลดหลักฐานไม่ได้'));
                }

                function openLightbox(startIdx) {
                    document.getElementById('lightboxOverlay').style.display = 'flex';
                    if (typeof startIdx === 'number') {
                        _lbIdx = startIdx;
                        showLbSingle();
                    } else {
                        showLbGallery();
                    }
                }

                function closeLightbox() {
                    document.getElementById('lightboxOverlay').style.display = 'none';
                }

                /* -- Gallery view -- */
                function showLbGallery() {
                    _lbMode = 'gallery';
                    const files = window._lbFiles || [];
                    document.getElementById('lbBackBtn').style.display = 'none';
                    document.getElementById('lbGallery').style.display = 'flex';
                    document.getElementById('lbSingle').style.display = 'none';
                    document.getElementById('lbFooter').innerHTML = '';

                    if (files.length === 0) {
                        document.getElementById('lbCounter').textContent = '';
                        document.getElementById('lbGallery').innerHTML = `
                    <div style="width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4rem;gap:1rem;">
                        <div style="font-size:4.5rem;">📂</div>
                        <div style="color:var(--text-muted);font-size:1.1rem;font-weight:600;font-family:'Kanit',sans-serif;">ไม่มีไฟล์แนบ</div>
                    </div>`;
                        return;
                    }

                    document.getElementById('lbCounter').textContent = `ไฟล์ทั้งหมด ${files.length} ไฟล์`;

                    let galleryHtml = '';
                    files.forEach((f, i) => {
                        if (f.kind === 'link') {
                            const lbl = String(f.label || f.url || '').replace(/</g, '&lt;');
                            galleryHtml += `
                        <div onclick="showLbSingle(${i})" style="cursor:pointer;border-radius:16px;border:2px solid #E5E7EB;background:#F9FAFB;width:160px;height:140px;flex-shrink:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.5rem;transition:all 0.2s;box-shadow:0 2px 8px rgba(0,0,0,0.04);padding:0 10px;"
                            onmouseover="this.style.borderColor='var(--clr-primary)';this.style.transform='translateY(-4px)';this.style.boxShadow='0 12px 24px rgba(98,54,139,0.15)'"
                            onmouseout="this.style.borderColor='#E5E7EB';this.style.transform='translateY(0)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                            <div style="font-size:2.5rem;">🔗</div>
                            <div style="color:var(--text-muted);font-size:0.75rem;font-weight:600;text-align:center;word-break:break-all;">${lbl.substring(0, 50)}</div>
                        </div>`;
                            return;
                        }
                        const isImg = f.file_type && f.file_type.startsWith('image');
                        const filePath = `../assets/images/evidence/${f.file_path}`;
                        const ext = f.file_path.split('.').pop().toUpperCase();
                        if (isImg) {
                            galleryHtml += `
                        <div onclick="showLbSingle(${i})" style="cursor:pointer;border-radius:16px;overflow:hidden;border:2px solid #E5E7EB;transition:all 0.2s;width:180px;height:140px;flex-shrink:0;background:#F9FAFB;box-shadow:0 2px 8px rgba(0,0,0,0.04);"
                            onmouseover="this.style.borderColor='var(--clr-primary)';this.style.transform='translateY(-4px)';this.style.boxShadow='0 12px 24px rgba(98,54,139,0.15)'"
                            onmouseout="this.style.borderColor='#E5E7EB';this.style.transform='translateY(0)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                            <img src="${filePath}" style="width:100%;height:100%;object-fit:cover;display:block;"
                                onerror="this.parentElement.innerHTML='<div style=\'width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9CA3AF;font-size:0.8rem;font-weight:600;\'>โหลดไม่ได้</div>'">
                        </div>`;
                        } else {
                            galleryHtml += `
                        <div onclick="showLbSingle(${i})" style="cursor:pointer;border-radius:16px;border:2px solid #E5E7EB;background:#F9FAFB;width:160px;height:140px;flex-shrink:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.5rem;transition:all 0.2s;box-shadow:0 2px 8px rgba(0,0,0,0.04);"
                            onmouseover="this.style.borderColor='var(--clr-primary)';this.style.transform='translateY(-4px)';this.style.boxShadow='0 12px 24px rgba(98,54,139,0.15)'"
                            onmouseout="this.style.borderColor='#E5E7EB';this.style.transform='translateY(0)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.04)'">
                            <div style="font-size:2.5rem;color:var(--clr-primary);">📄</div>
                            <div style="color:var(--text-primary);font-weight:800;font-size:0.9rem;">${ext}</div>
                            <div style="color:var(--text-muted);font-size:0.75rem;font-weight:600;text-align:center;padding:0 12px;word-break:break-all;">${(f.original_name || f.file_path.split('/').pop()).substring(0, 40)}</div>
                        </div>`;
                        }
                    });
                    document.getElementById('lbGallery').innerHTML = galleryHtml;
                }

                /* -- Single view -- */
                function showLbSingle(idx) {
                    if (typeof idx === 'number') _lbIdx = idx;
                    _lbMode = 'single';
                    const files = window._lbFiles || [];
                    const f = files[_lbIdx];
                    const total = files.length;
                    document.getElementById('lbGallery').style.display = 'none';
                    document.getElementById('lbSingle').style.display = 'flex';
                    document.getElementById('lbBackBtn').style.display = total > 1 ? 'flex' : 'none';
                    document.getElementById('lbPrev').style.display = total > 1 ? 'flex' : 'none';
                    document.getElementById('lbNext').style.display = total > 1 ? 'flex' : 'none';
                    document.getElementById('lbCounter').textContent = total > 1 ? `${_lbIdx + 1} / ${total}` : '';

                    if (f.kind === 'link') {
                        const url = String(f.url || '');
                        const safeUrl = url.replace(/"/g, '&quot;');
                        const lbl = String(f.label || url).replace(/</g, '&lt;');
                        document.getElementById('lbContent').innerHTML = `
                        <div style="background:white;border-radius:24px;padding:3rem 4rem;text-align:center;box-shadow:0 32px 80px rgba(0,0,0,0.5);max-width:520px;">
                            <div style="font-size:4rem;margin-bottom:1rem;">🔗</div>
                            <div style="font-size:1.1rem;font-weight:700;color:#111;margin-bottom:0.4rem;">${lbl}</div>
                            <div style="font-size:0.82rem;color:#6B7280;margin-bottom:1.5rem;word-break:break-all;max-width:400px;">${url.replace(/</g, '&lt;')}</div>
                            <a href="${safeUrl}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;background:var(--clr-primary);color:white;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:700;font-family:'Kanit',sans-serif;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                เปิดลิงก์
                            </a>
                        </div>`;
                        document.getElementById('lbFooter').innerHTML = '';
                        return;
                    }

                    const filePath = `../assets/images/evidence/${f.file_path}`;
                    const isImg = f.file_type && f.file_type.startsWith('image');
                    const ext = f.file_path.split('.').pop().toUpperCase();

                    if (isImg) {
                        document.getElementById('lbContent').innerHTML =
                            `<img src="${filePath}" style="max-width:100%;max-height:55vh;border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,0.1);border:1px solid #E5E7EB;object-fit:contain;" onerror="this.alt='โหลดไม่ได้'">`;
                        document.getElementById('lbFooter').innerHTML =
                            `<a href="${filePath}" download="${f.original_name || ''}" target="_blank" style="display:inline-flex;align-items:center;gap:8px;color:white;text-decoration:none;font-size:0.9rem;font-weight:700;font-family:'Kanit',sans-serif;padding:10px 24px;background:var(--clr-primary);border-radius:12px;box-shadow:0 4px 12px rgba(98,54,139,0.25);transition:all 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        ดาวน์โหลดภาพ
                     </a>`;
                    } else {
                        document.getElementById('lbContent').innerHTML = `
                    <div style="background:white;border-radius:24px;padding:3rem 4rem;text-align:center;box-shadow:0 32px 80px rgba(0,0,0,0.5);">
                        <div style="font-size:4rem;margin-bottom:1rem;">📄</div>
                        <div style="font-size:1.2rem;font-weight:700;color:#111;margin-bottom:0.4rem;">${ext} ไฟล์</div>
                        <div style="font-size:0.82rem;color:#6B7280;margin-bottom:1.5rem;word-break:break-all;max-width:300px;">${f.original_name || f.file_path.split('/').pop()}</div>
                        <a href="${filePath}" download="${f.original_name || ''}" target="_blank" style="display:inline-flex;align-items:center;gap:8px;background:var(--clr-primary);color:white;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:700;font-family:'Kanit',sans-serif;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            ดาวน์โหลด
                        </a>
                    </div>`;
                        document.getElementById('lbFooter').innerHTML = '';
                    }
                }

                function prevFile() { _lbIdx = (_lbIdx - 1 + (window._lbFiles || []).length) % (window._lbFiles || []).length; showLbSingle(); }
                function nextFile() { _lbIdx = (_lbIdx + 1) % (window._lbFiles || []).length; showLbSingle(); }

                document.getElementById('lightboxOverlay').addEventListener('click', function (e) {
                    if (e.target === this) closeLightbox();
                });

                function closeItemDetail() {
                    document.getElementById('itemDetailModal').style.display = 'none';
                    unlockScroll();
                }
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') { closeDetail(); closeReportsModal(); closeItemDetail(); }
                });

                function backToReportsYears(affilId, affilName) {
                    replayReportsPop();
                    document.getElementById('reportsModalTitle').textContent = affilName;
                    document.getElementById('reportsBreadcrumb').innerHTML =
                        `<span class="back-btn-pill" onclick="backToReportsList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> กลับรายการคณะ</span>`;
                    document.getElementById('reportsModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';
                    fetch(`api/api_reports_list.php?mode=years&affil_id=${affilId}`)
                        .then(r => r.json())
                        .then(data => renderReportsYears(data, affilId))
                        .catch(() => {
                            document.getElementById('reportsModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาด</p>';
                        });
                }

                function backToReportsList() {
                    replayReportsPop();
                    document.getElementById('reportsModalTitle').textContent = 'จำนวนครั้งที่รายงาน';
                    document.getElementById('reportsBreadcrumb').style.display = 'none';
                    document.getElementById('reportsModalBody').innerHTML =
                        '<div class="detail-loading"><div class="spinner"></div><span>กำลังโหลดข้อมูล...</span></div>';
                    fetch('api/api_reports_list.php?mode=list')
                        .then(r => r.json())
                        .then(data => renderReportsList(data))
                        .catch(() => {
                            document.getElementById('reportsModalBody').innerHTML =
                                '<p class="no-data-msg">เกิดข้อผิดพลาด</p>';
                        });
                }

                function closeReportsModal() {
                    document.getElementById('reportsModal').style.display = 'none';
                    unlockScroll();
                }

                document.addEventListener('keydown', e => {
                    const lb = document.getElementById('lightboxOverlay');
                    if (lb && lb.style.display !== 'none') {
                        if (e.key === 'Escape') { closeLightbox(); return; }
                        if (e.key === 'ArrowLeft') { prevFile(); return; }
                        if (e.key === 'ArrowRight') { nextFile(); return; }
                    }
                    if (e.key === 'Escape') {
                        document.getElementById('itemDetailModal').style.display = 'none';
                        document.getElementById('reportsModal').style.display = 'none';
                        document.getElementById('detailModal').style.display = 'none';
                        unlockScroll();
                    }
                });
            </script>

</body>

</html>