<?php
/**
 * ADMIN — Data Entry Step 2: Items Input (data_entry_items.php)
 * -----------------------------------------------------------
 * กรอกข้อมูลกิจกรรม แยกตามขอบเขต (Step 2)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['admin']);

$pdo = getDB();
$root = '../';
$affiliation_id = $_SESSION['Affiliation'] ?? 0;

$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$scope_groups_ids = isset($_GET['scope_groups']) ? $_GET['scope_groups'] : [];

if ($selected_year <= 0 || empty($scope_groups_ids)) {
    header('Location: data_entry.php?year=' . $selected_year);
    exit;
}

// Fetch year name
$stmt = $pdo->prepare('SELECT year FROM admin_year WHERE id = ?');
$stmt->execute([$selected_year]);
$year_data = $stmt->fetch();
$year_name = $year_data ? $year_data['year'] : 'ไม่ระบุ';

// Fetch ALL available scope groups for the selection modal
$all_available_groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();

// Fetch scope groups details for current session
if (!empty($scope_groups_ids)) {
    $in = str_repeat('?,', count($scope_groups_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT * FROM admin_g WHERE id IN ($in) ORDER BY scope, order_num ASC, id ASC");
    $stmt->execute($scope_groups_ids);
    $groups = $stmt->fetchAll();
} else {
    $groups = [];
}

// Fetch items (Emission Factors) for these groups
$items_by_group = [];
if (!empty($scope_groups_ids)) {
    $in = str_repeat('?,', count($scope_groups_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT ai.*, ag.id as group_id 
                           FROM admin_item ai 
                           JOIN admin_g ag ON ag.id = ai.scope
                           WHERE ai.year_id = ? AND ai.scope IN ($in) 
                           ORDER BY ai.id ASC");
    $stmt->execute(array_merge([$selected_year], $scope_groups_ids));
    $all_items = $stmt->fetchAll();

    foreach ($all_items as $item) {
        $items_by_group[$item['group_id']][] = $item;
    }
}

// Fetch existing user data
$existing_data = [];
$stmt = $pdo->prepare('SELECT admin_item_id, Vol FROM user_item WHERE year_id = ? AND affiliation_id = ?');
$stmt->execute([$selected_year, $affiliation_id]);
$user_items = $stmt->fetchAll();
foreach ($user_items as $ui) {
    $existing_data[$ui['admin_item_id']] = $ui['Vol'];
}

$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
$page_title3 = "เลือกขอบเขตกิจกรรม $year_name";
$page_title4 = "กรอกข้อมูลขอบเขตกิจกรรม $year_name";

// Handle Form Submission (Save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $vols = $_POST['vol'] ?? [];

    try {
        $pdo->beginTransaction();
        foreach ($vols as $item_id => $val) {
            $val = (float) $val;
            // Upsert user_item
            $stmt = $pdo->prepare('INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol) 
                                   VALUES (:ai, :aff, :y, :v) 
                                   ON DUPLICATE KEY UPDATE Vol = :v');
            $stmt->execute([
                ':ai' => (int) $item_id,
                ':aff' => $affiliation_id,
                ':y' => $selected_year,
                ':v' => $val
            ]);
        }
        $pdo->commit();
        header("Location: data_entry_items.php?year=$selected_year&" . http_build_query(['scope_groups' => $scope_groups_ids]) . "&msg=บันทึกข้อมูลสำเร็จ");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_msg = "เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage();
    }
}

// NEW: Handle adding custom item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_custom_item') {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD)
             VALUES (:y, :s, :n, :u, :a)'
        );
        $stmt->execute([
            ':y' => $selected_year,
            ':s' => (int) $_POST['scope_id'],
            ':n' => trim($_POST['name_tiem']),
            ':u' => trim($_POST['unit']),
            ':a' => (float) $_POST['AD'],
        ]);
        header("Location: data_entry_items.php?year=$selected_year&" . http_build_query(['scope_groups' => $scope_groups_ids]) . "&msg=เพิ่มรายการใหม่สำเร็จ");
        exit;
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาดในการเพิ่มรายการ: " . $e->getMessage();
    }
}

// Handle Delete Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    try {
        $stmt = $pdo->prepare('DELETE FROM admin_item WHERE id = ?');
        $stmt->execute([(int) $_POST['item_id']]);
        header("Location: data_entry_items.php?year=$selected_year&" . http_build_query(['scope_groups' => $scope_groups_ids]) . "&msg=ลบรายการสำเร็จ");
        exit;
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
    }
}

// Handle Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_item') {
    try {
        $stmt = $pdo->prepare(
            'UPDATE admin_item 
             SET scope = :s, name_tiem = :n, unit = :u, AD = :a
             WHERE id = :id'
        );
        $stmt->execute([
            ':s' => (int) $_POST['scope_id'],
            ':n' => trim($_POST['name_tiem']),
            ':u' => trim($_POST['unit']),
            ':a' => (float) $_POST['AD'],
            ':id' => (int) $_POST['item_id']
        ]);
        header("Location: data_entry_items.php?year=$selected_year&" . http_build_query(['scope_groups' => $scope_groups_ids]) . "&msg=แก้ไขรายการสำเร็จ");
        exit;
    } catch (PDOException $e) {
        $error_msg = "เกิดข้อผิดพลาดในการแก้ไข: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกข้อมูลไอเทม — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css?v=3">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css">
    <style>
        :root {
            --bg-page: #F8FAFC;
        }

        body {
            background-color: var(--bg-page);
        }

        .data-entry-items-container {
            width: 100%;
            margin: 0 auto;
            padding: 2rem;
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .main-heading {
            font-size: 1.5rem;
            font-weight: 700;
            color: #4B5563;
        }

        .action-btns {
            display: flex;
            gap: 12px;
        }

        .btn-action {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            color: white;
            text-decoration: none;
        }

        .btn-add-activity {
            background-color: #3B82F6;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.2);
        }

        .btn-define-activity {
            background-color: #4B5563;
            box-shadow: 0 4px 10px rgba(75, 85, 99, 0.2);
        }

        .btn-calculate {
            background-color: #84CC16;
            box-shadow: 0 4px 10px rgba(132, 204, 22, 0.2);
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .btn-add-activity:hover {
            box-shadow: 0 15px 30px;
        }

        .btn-define-activity:hover {
            box-shadow: 0 15px 30px;
        }

        .btn-calculate:hover {
            box-shadow: 0 15px 30px;
        }

        /* Accordion Styling */
        .accordion-section {
            background: #FFFFFF;
            border-radius: 20px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            border: 1px solid #E5E7EB;
        }

        .accordion-header {
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid transparent;
            transition: background 0.2s;
        }

        .accordion-header:hover {
            background-color: #F9FAFB;
        }

        .accordion-section.active .accordion-header {
            border-bottom-color: #F3F4F6;
        }

        .accordion-title-box {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .accordion-title {
            font-size: 1.15rem;
            font-weight: 700;
        }

        .scope-1-theme .accordion-title {
            color: #F97316;
        }

        .scope-2-theme .accordion-title {
            color: #DB2777;
        }

        .scope-3-theme .accordion-title {
            color: #2563EB;
        }

        .accordion-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .btn-delete-section {
            background-color: #EF4444;
            color: white;
            border: none;
            padding: 6px 16px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-delete-section:hover {
            background-color: #DC2626;
            transform: translateY(-2px);
        }

        .toggle-icon {
            width: 32px;
            height: 32px;
            background: #4B5563;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            transition: transform 0.3s;
        }

        .accordion-section.active .toggle-icon {
            transform: rotate(180deg);
        }

        .accordion-content {
            display: none;
            padding: 20px 30px 40px 30px;
        }

        .accordion-section.active .accordion-content {
            display: block;
        }

        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .items-table th {
            text-align: left;
            padding: 10px 15px;
            color: #9CA3AF;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .item-row td {
            padding: 12px 15px;
            background-color: #FFF9F3;
        }

        .item-row td:first-child {
            border-radius: 12px 0 0 12px;
            color: #4B5563;
            font-weight: 500;
        }

        .item-row td:last-child {
            border-radius: 0 12px 12px 0;
        }

        .scope-1-theme .item-row td {
            background-color: #FFF9F3;
        }

        .scope-2-theme .item-row td {
            background-color: #FFF0F6;
        }

        .scope-3-theme .item-row td {
            background-color: #EFF6FF;
        }

        .vol-input {
            width: 100px;
            height: 40px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 0 12px;
            text-align: center;
            font-family: inherit;
            font-weight: 600;
        }

        .total-cell {
            background-color: #F3F4F6 !important;
            color: #374151;
            font-weight: 700;
            text-align: right;
            width: 120px;
        }

        .action-cell {
            text-align: center;
            display: flex;
            gap: 8px;
            justify-content: center;
            align-items: center;
        }

        .btn-row-action {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            color: white;
        }

        .btn-edit-row {
            background-color: #3B82F6;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.2);
        }

        .btn-edit-row:hover {
            background-color: #2563EB;
            transform: translateY(-2px);
        }

        .btn-delete-row {
            background-color: #EF4444;
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);
        }

        .btn-delete-row:hover {
            background-color: #DC2626;
            transform: translateY(-2px);
        }

        .btn-add-item-row {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #3B82F6;
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        /* Modal Select Scope New Activity */
        .scope-select-row:hover {
            background-color: #F9FAFB;
        }

        .scope-checkbox:checked+.checkbox-custom {
            background-color: #949494;
        }

        .scope-checkbox:checked+.checkbox-custom svg {
            display: block;
        }

        .checkbox-custom {
            border: 2px solid transparent;
        }

        .checkbox-custom svg {
            display: none;
        }

        .scope-checkbox:not(:checked)+.checkbox-custom {
            border-color: #D1D5DB;
            background-color: white;
        }
    </style>
</head>

<body>

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="data-entry-items-container">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem;">
                <a href="items.php"
                    style="display: inline-flex; align-items: center; gap: 8px; color: #6B7280; text-decoration: none; font-weight: 600; transition: all 0.2s; font-size: 0.95rem;"
                    onmouseover="this.style.color='var(--clr-primary)'; this.style.transform='translateX(-4px)';"
                    onmouseout="this.style.color='#6B7280'; this.style.transform='none'">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    กลับไปหน้าเลือกปี
                </a>
            </div>
            <?php if (isset($_GET['msg'])): ?>
                <div style="display:flex; align-items:center; gap:12px; padding:16px; border-radius:8px; margin-bottom:24px; font-weight:500; background-color:#ECFDF5; color:#065F46; border:1px solid #A7F3D0;">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <?= htmlspecialchars($_GET['msg']) ?>
                </div>
            <?php endif; ?>

            <div class="header-actions">
                <h1 class="main-heading">กรอกข้อมูลขอบเขตกิจกรรม</h1>
                <div class="action-btns">
                    <button type="button" onclick="openModal('modal-add-new-scope')"
                        class="btn-action btn-add-activity">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        เพิ่มกิจกรรม
                    </button>
                    <button type="button" onclick="openModal('modal-custom-item')"
                        class="btn-action btn-define-activity">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        กำหนดกิจกรรม
                    </button>
                    <button type="button" onclick="document.getElementById('main-save-form').submit();"
                        class="btn-action btn-calculate">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        </svg>
                        คำนวณผลกิจกรรม
                    </button>
                </div>
            </div>

            <form id="main-save-form" method="POST">
                <input type="hidden" name="action" value="save">
                <?php foreach ($groups as $index => $group): ?>
                    <?php
                    $items = $items_by_group[$group['id']] ?? [];
                    $theme_class = "scope-" . $group['scope'] . "-theme";
                    $scope_label = "ขอบเขตที่ " . $group['scope'] . ": " . htmlspecialchars($group['name_tiem']);
                    ?>
                    <div class="accordion-section <?= $theme_class ?>" id="section-<?= $group['id'] ?>">
                        <div class="accordion-header" onclick="toggleAccordion('section-<?= $group['id'] ?>')">
                            <div class="accordion-title-box">
                                <span class="accordion-title"><?= $scope_label ?></span>
                                <div
                                    style="height: 2px; width: 100%; background: linear-gradient(to right, currentColor, transparent); opacity: 0.3;">
                                </div>
                            </div>
                            <div class="accordion-actions">
                                <button type="button" class="btn-delete-section">ลบกิจกรรม</button>
                                <div class="toggle-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="3">
                                        <path d="m6 9 6 6 6-6" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-content">
                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>รายการ</th>
                                        <th>หน่วย</th>
                                        <th style="text-align: right;">ปริมาณก๊าซเรือนกระจก<br>(kgCO2e/หน่วย)</th>
                                        <th style="text-align: center;">ปริมาณ / ปี</th>
                                        <th style="text-align: right;">ปริมาณก๊าซเรือนกระจก<br>(tCO2e/ต่อปี)</th>
                                        <th style="text-align: center;">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                        $vol = $existing_data[$item['id']] ?? 0;
                                        $total = ($vol * $item['AD']) / 1000;
                                        ?>
                                        <tr class="item-row">
                                            <td><?= htmlspecialchars($item['name_tiem']) ?></td>
                                            <td><?= htmlspecialchars($item['unit']) ?></td>
                                            <td style="text-align: right;" data-ef="<?= $item['AD'] ?>">
                                                <?= number_format($item['AD'], 4) ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <input type="number" step="any" name="vol[<?= $item['id'] ?>]" class="vol-input"
                                                    value="<?= $vol ?>" oninput="calculateRow(this)">
                                            </td>
                                            <td class="total-cell"><?= number_format($total, 3) ?></td>
                                            <td class="action-cell">
                                                <button type="button" class="btn-row-action btn-edit-row"
                                                    onclick="openEditModal(<?= $item['id'] ?>, <?= $group['id'] ?>, '<?= addslashes($item['name_tiem']) ?>', '<?= addslashes($item['unit']) ?>', <?= $item['AD'] ?>)">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z">
                                                        </path>
                                                    </svg>
                                                </button>
                                                <button type="button" class="btn-row-action btn-delete-row"
                                                    onclick="deleteItem(<?= $item['id'] ?>)">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                                        stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6"></polyline>
                                                        <path
                                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                        </path>
                                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>
        </div>
    </main>

    <!-- Modal: เลือกกิจกรรมขอบเขตใหม่ -->
    <div class="modal-overlay" id="modal-add-new-scope">
        <div class="modal-box" style="max-width: 1200px; padding: 2rem; border-radius: 20px;">
            <button type="button" onclick="closeModal('modal-add-new-scope')"
                style="position: absolute; top: 10px; right: 10px; background: #FF4747; color: white; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>

            <h3 style="font-size: 1.25rem; font-weight: 700; color: #4B5563; margin-bottom: 2rem;">
                เลือกกิจกรรมขอบเขตใหม่</h3>

            <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 20px;">
                <span style="color: #6B7280; font-weight: 500;">Search :</span>
                <input type="text" id="scope-search" placeholder="..."
                    style="border: 1px solid #E5E7EB; padding: 8px 15px; border-radius: 12px; flex: 1;">
                <div style="margin-left: auto; color: #6B7280;">Show <select
                        style="border: 1px solid #E5E7EB; border-radius: 8px;">
                        <option>10</option>
                    </select> Entries</div>
            </div>

            <div style="max-height: 400px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: separate; border-spacing: 0 10px;">
                    <thead>
                        <tr style="text-align: left;">
                            <th style="padding: 0 15px; color: #9CA3AF; font-weight: 500; width: 80px;">เลือก</th>
                            <th
                                style="padding: 0 15px; color: #9CA3AF; font-weight: 500; width: 150px; text-align: center;">
                                กลุ่มขอบเขต
                            </th>
                            <th style="padding: 0 15px; color: #9CA3AF; font-weight: 500;">ชื่อกิจกรรม</th>
                        </tr>
                    </thead>
                    <tbody id="scope-list-body">
                        <?php foreach ($all_available_groups as $ag): ?>
                            <?php
                            $is_checked = in_array($ag['id'], $scope_groups_ids) ? 'checked' : '';
                            $theme_color = "";
                            if ($ag['scope'] == 1)
                                $theme_color = "#F97316";
                            elseif ($ag['scope'] == 2)
                                $theme_color = "#DB2777";
                            elseif ($ag['scope'] == 3)
                                $theme_color = "#2563EB";
                            $bg_color = $theme_color . '10'; // 10% opacity
                            ?>
                            <tr class="scope-select-row" data-name="<?= htmlspecialchars($ag['name_tiem']) ?>">
                                <td style="padding: 12px 15px;">
                                    <label
                                        style="display: block; width: 32px; height: 32px; border-radius: 10px; cursor: pointer; position: relative;">
                                        <input type="checkbox" name="modal_scopes[]" value="<?= $ag['id'] ?>" <?= $is_checked ?> class="scope-checkbox" style="opacity: 0; position: absolute;">
                                        <div class="checkbox-custom"
                                            style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: inherit; transition: all 0.2s;">
                                            <svg viewBox="0 0 24 24" width="20" height="20" stroke="white" stroke-width="4"
                                                fill="none">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        </div>
                                    </label>
                                </td>
                                <td style="padding: 12px 15px; text-align: center;">
                                    <span
                                        style="display: inline-block; padding: 5px 12px; background: white; border: 1.5px solid <?= $theme_color ?>; color: <?= $theme_color ?>; border-radius: 8px; font-weight: 600; font-size: 0.8rem;">
                                        กลุ่มขอบเขตที่ <?= $ag['scope'] ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 15px;">
                                    <div
                                        style="background: <?= $bg_color ?>; padding: 10px 20px; border-radius: 10px; color: #4B5563; font-weight: 600;">
                                        <?= htmlspecialchars($ag['name_tiem']) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 30px;">
                <button type="button" onclick="saveNewScopes()"
                    style="background: #4B5563; color: white; border: none; padding: 12px 40px; border-radius: 999px; font-weight: 700; cursor: pointer;">บันทึก</button>
            </div>
        </div>
    </div>

    <!-- Modal: กำหนดกิจกรรม (Custom Item) -->
    <!-- Modal: แก้ไขกิจกรรม (Edit Item) -->
    <div class="modal-overlay" id="modal-edit-item">
        <div class="modal-box" style="max-width: 500px; padding: 2.5rem; border-radius: 20px;">
            <button type="button" onclick="closeModal('modal-edit-item')"
                style="position: absolute; top: 10px; right: 10px; background: #FF4747; color: white; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <h3
                style="font-size: 1.5rem; font-weight: 700; color: #4B5563; margin-bottom: 2rem; border-bottom: 2px solid #3B82F6; padding-bottom: 10px;">
                แก้ไขรายละเอียดขอบเขต</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="form-group-dark" style="margin-bottom: 1.5rem;">
                    <label class="form-label-dark">ขอบเขต :</label>
                    <select name="scope_id" id="edit_scope_id" class="form-control-dark" required>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>">ขอบเขตที่ <?= $g['scope'] ?>
                                <?= htmlspecialchars($g['name_tiem']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group-dark" style="margin-bottom: 1.5rem;">
                    <label class="form-label-dark">รายการ :</label>
                    <input type="text" name="name_tiem" id="edit_name_tiem" class="form-control-dark" required>
                </div>
                <div class="form-group-dark" style="margin-bottom: 1.5rem;">
                    <label class="form-label-dark">หน่วย :</label>
                    <input type="text" name="unit" id="edit_unit" class="form-control-dark" required>
                </div>
                <div class="form-group-dark" style="margin-bottom: 2rem;">
                    <label class="form-label-dark">kgCO2e / หน่วย :</label>
                    <input type="number" step="0.0001" name="AD" id="edit_AD" class="form-control-dark" required
                        placeholder="0.0000">
                </div>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn-primary"
                        style="background: #3B82F6; padding: 10px 30px; border-radius: 999px;">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-overlay" id="modal-custom-item">
        <div class="modal-box" style="max-width: 500px; padding: 2.5rem; border-radius: 20px;">
            <button type="button" onclick="closeModal('modal-custom-item')"
                style="position: absolute; top: 10px; right: 10px; background: #FF4747; color: white; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="3">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <h3
                style="font-size: 1.5rem; font-weight: 700; color: #4B5563; margin-bottom: 2rem; border-bottom: 2px solid #62368B; padding-bottom: 10px;">
                เพิ่มรายละเอียดสำหรับขอบเขต</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_custom_item">
                <div class="form-group-dark" style="margin-bottom: 1.5rem;">
                    <label class="form-label-dark">ขอบเขต :</label>
                    <select name="scope_id" class="form-control-dark" required>
                        <option value="" disabled selected>เลือกขอบเขต</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>">ขอบเขตที่ <?= $g['scope'] ?>
                                <?= htmlspecialchars($g['name_tiem']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group-dark" style="margin-bottom: 1.5rem;">
                    <label class="form-label-dark">รายการ :</label>
                    <input type="text" name="name_tiem" class="form-control-dark" required>
                </div>
                <div class="form-group-dark" style="margin-bottom: 1.5rem;">
                    <label class="form-label-dark">หน่วย :</label>
                    <input type="text" name="unit" class="form-control-dark" required>
                </div>
                <div class="form-group-dark" style="margin-bottom: 2rem;">
                    <label class="form-label-dark">kgCO2e / หน่วย :</label>
                    <input type="number" step="0.0001" name="AD" class="form-control-dark" required
                        placeholder="0.0000">
                </div>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn-primary"
                        style="background: #3B82F6; padding: 10px 30px; border-radius: 999px;">บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleAccordion(id) {
            const section = document.getElementById(id);
            section.classList.toggle('active');
        }

        function calculateRow(input) {
            const row = input.closest('tr');
            const ef_cell = row.querySelector('[data-ef]');
            if (!ef_cell) return;
            const ef = parseFloat(ef_cell.dataset.ef) || 0;
            const vol = parseFloat(input.value) || 0;
            const totalCell = row.querySelector('.total-cell');
            const total = (vol * ef) / 1000;
            totalCell.textContent = total.toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
        }

        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditModal(id, scopeId, name, unit, ad) {
            document.getElementById('edit_item_id').value = id;
            document.getElementById('edit_scope_id').value = scopeId;
            document.getElementById('edit_name_tiem').value = name;
            document.getElementById('edit_unit').value = unit;
            document.getElementById('edit_AD').value = ad;
            openModal('modal-edit-item');
        }

        function deleteItem(id) {
            if (confirm('คุณแน่ใจหรือไม่ว่าต้องการลบรายการนี้? การลบจะมีผลต่อการคำนวณทั้งหมดที่ใช้รายการนี้')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'delete_item';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.name = 'item_id';
                idInput.value = id;
                form.appendChild(idInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Modal Scope Search
        const scopeSearch = document.getElementById('scope-search');
        if (scopeSearch) {
            scopeSearch.addEventListener('input', function (e) {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('.scope-select-row').forEach(row => {
                    const text = row.dataset.name.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                });
            });
        }

        // Click Row to Tick Checkbox
        document.querySelectorAll('.scope-select-row').forEach(row => {
            row.addEventListener('click', function (e) {
                // If the user clicked directly on the checkbox or label, don't do anything here
                // as the browser handles it naturally.
                if (e.target.closest('.scope-checkbox') || e.target.closest('label')) return;

                const checkbox = this.querySelector('.scope-checkbox');
                if (checkbox) {
                    checkbox.checked = !checkbox.checked;
                    // Trigger change event if needed
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
            row.style.cursor = 'pointer';
        });

        function saveNewScopes() {
            const checked = document.querySelectorAll('.scope-checkbox:checked');
            const ids = Array.from(checked).map(cb => cb.value);
            if (ids.length === 0) {
                alert('กรุณาเลือกอย่างน้อย 1 กิจกรรม');
                return;
            }
            const params = new URLSearchParams(window.location.search);
            params.delete('scope_groups[]');
            ids.forEach(id => params.append('scope_groups[]', id));
            window.location.href = 'data_entry_items.php?' + params.toString();
        }

        window.onclick = function (event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>

</html>