<?php
/**
 * OFFICER — Data Entry Step 2: Items Input (data_entry_items.php)
 * -----------------------------------------------------------
 * กรอกข้อมูลกิจกรรม แยกตามขอบเขต (Step 2)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['officer']);

$pdo = getDB();
$root = '../';
$affiliation_id = (int) ($_SESSION['affiliation_id'] ?? 0);

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
                           WHERE ai.year_id = ? AND ai.scope IN ($in) AND ai.data_source = 'officer' 
                           ORDER BY ai.id ASC");
    $stmt->execute(array_merge([$selected_year], $scope_groups_ids));
    $all_items = $stmt->fetchAll();

    foreach ($all_items as $item) {
        $items_by_group[$item['group_id']][] = $item;
    }
}

// Fetch existing user data
$existing_data = [];
$evidence_counts = [];
$evidence_thumbs = [];
$stmt = $pdo->prepare('
    SELECT ui.admin_item_id, ui.Vol, ui.id as user_item_id,
           (SELECT COUNT(*) FROM evidence WHERE entity_type=\'user_item\' AND entity_id = ui.id) as ev_count,
           (SELECT file_path FROM evidence WHERE entity_type=\'user_item\' AND entity_id = ui.id AND kind=\'file\' ORDER BY created_at ASC LIMIT 1) as ev_thumb
    FROM user_item ui 
    WHERE ui.year_id = ? AND ui.affiliation_id = ?
');
$stmt->execute([$selected_year, $affiliation_id]);
$user_items = $stmt->fetchAll();
foreach ($user_items as $ui) {
    $existing_data[$ui['admin_item_id']] = $ui['Vol'];
    $evidence_counts[$ui['admin_item_id']] = $ui['ev_count'];
    $evidence_thumbs[$ui['admin_item_id']] = $ui['ev_thumb'];
}

$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
$page_title3 = "เลือกขอบเขตกิจกรรม $year_name";
$page_title4 = "กรอกข้อมูลขอบเขตกิจกรรม $year_name";

// Handle Form Submission (Save)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $vols = $_POST['vol'] ?? [];
    // Ensure we have current context for redirect
    $selected_year = isset($_POST['year']) ? (int) $_POST['year'] : $selected_year;
    $scope_groups_ids = isset($_POST['scope_groups']) ? $_POST['scope_groups'] : $scope_groups_ids;

    try {
        $pdo->beginTransaction();
        foreach ($vols as $item_id => $val) {
            $val = (float) $val;
            // Upsert user_item
            // Use unique named parameters to prevent issues with some PDO configurations
            $stmt = $pdo->prepare('INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year) 
                                   VALUES (:ai, :aff, :y, :v1, CURDATE()) 
                                   ON DUPLICATE KEY UPDATE Vol = :v2, create_year = CURDATE()');
            $stmt->execute([
                ':ai' => (int) $item_id,
                ':aff' => $affiliation_id,
                ':y' => $selected_year,
                ':v1' => $val,
                ':v2' => $val
            ]);
        }
        $pdo->commit();
        header("Location: data_entry_items.php?year=$selected_year&" . http_build_query(['scope_groups' => $scope_groups_ids]) . "&msg=บันทึกข้อมูลสำเร็จ");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        header("Location: data_entry_items.php?year=$selected_year&" . http_build_query(['scope_groups' => $scope_groups_ids]) . "&msg=" . urlencode("เกิดข้อผิดพลาด: " . $e->getMessage()) . "&msg_type=danger");
        exit;
    }
}

// ── บล็อกการแก้ไข "ข้อมูลอ้างอิงกลาง" (admin_item) — เป็นสิทธิ์ของ admin เท่านั้น ──
// role user มีสิทธิ์แค่กรอกปริมาณ (save) และแนบหลักฐาน; การเพิ่ม/แก้/ลบ Emission Factor
// กระทบทุกคณะในปีนั้น จึงต้องทำผ่านหน้า admin เท่านั้น (กันการ POST ตรงเข้ามา bypass UI)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['add_custom_item', 'edit_item', 'delete_item'], true)) {
    http_response_code(403);
    require __DIR__ . '/../includes/403.php';
    exit;
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
    <title>กรอกรายละเอียด — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <style>
        :root {
            --bg-page: #F8FAFC;
        }

        /* Hide number input spinners */
        input::-webkit-outer-spin-button,
        input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type=number] {
            -moz-appearance: textfield;
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

        /* hover effect เหมือนฝั่ง admin (ยกขึ้น + เงาเข้มตามสีปุ่ม) */
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
            color: #F6BB43;
        }

        .scope-2-theme .accordion-title {
            color: #FF339A;
        }

        .scope-3-theme .accordion-title {
            color: #0066CB;
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
            border-spacing: 0 10px;
            table-layout: fixed;
        }

        .items-table th {
            text-align: left;
            padding: 8px 5px;
            vertical-align: middle;
        }

        .header-pill {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 54px;
            padding: 5px 15px;
            background: #FFFFFF;
            border: 1.5px solid #E5E7EB;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #4B5563;
            text-align: center;
            line-height: 1.2;
            transition: all 0.2s;
        }

        .item-row td {
            padding: 4px 5px;
            vertical-align: middle;
            background: transparent !important;
            /* Remove old bg */
        }

        .cell-pill {
            display: flex;
            align-items: center;
            height: 48px;
            padding: 0 15px;
            background: #FFFFFF;
            border: 1.5px solid #E5E7EB;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #374151;
            transition: all 0.2s;
        }

        /* Scope Theme Colors for Pills */
        .scope-1-theme .info-pill {
            background: #FFF9F3;
            border-color: #F6BB4340;
        }

        .scope-2-theme .info-pill {
            background: #FFF0F6;
            border-color: #FF339A40;
        }

        .scope-3-theme .info-pill {
            background: #EFF6FF;
            border-color: #0066CB40;
        }

        .vol-input {
            width: 100%;
            height: 44px;
            background: #FFFFFF;
            border: 1.5px solid #E5E7EB;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            text-align: center;
            transition: all 0.2s;
            -moz-appearance: textfield;
        }

        .vol-input:focus {
            outline: none;
            border-color: #62368B;
            box-shadow: 0 0 0 1px #62368B, 0 0 0 4px rgba(98, 54, 139, 0.15);
        }

        .total-pill {
            background-color: #F3F4F6;
            border-color: #E5E7EB;
            color: #111827;
            font-weight: 700;
            justify-content: flex-end;
        }

        .action-cell {
            text-align: center;
            vertical-align: middle;
            display: table-cell;
            /* ensure td behavior */
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

        /* Evidence Button States */
        .ev-btn-empty {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #F5F3FF;
            color: #A78BFA;
            border: 1.5px dashed #C4B5FD;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .ev-btn-empty:hover {
            background: #EDE9FE;
            border-color: #A78BFA;
            color: #7C3AED;
        }

        .ev-btn-filled {
            display: inline-flex;
            align-items: center;
            gap: 0;
            background: white;
            border: 2px solid #A78BFA;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(167, 139, 250, 0.25);
            position: relative;
        }

        .ev-btn-filled:hover {
            border-color: #7C3AED;
            box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
            transform: translateY(-1px);
        }

        .ev-btn-filled .ev-thumb {
            width: 36px;
            height: 36px;
            object-fit: cover;
            display: block;
            flex-shrink: 0;
        }

        .ev-btn-filled .ev-label {
            padding: 0 10px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #7C3AED;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .ev-btn-filled .ev-count-dot {
            background: #7C3AED;
            color: white;
            border-radius: 999px;
            padding: 1px 7px;
            font-size: 0.72rem;
            font-weight: 800;
        }

        /* Evidence Modal Styles */
        .evidence-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .evidence-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #E5E7EB;
            group: hover;
        }

        .evidence-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .evidence-actions {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .evidence-item:hover .evidence-actions {
            opacity: 1;
        }

        .btn-ev-delete {
            background: #EF4444;
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
        }

        .upload-zone {
            border: 2px dashed #D1D5DB;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .upload-zone:hover {
            border-color: #A78BFA;
            background: #F5F3FF;
        }
    </style>
</head>

<body>

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
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
                transition: background 0.2s;
            }

            .accordion-header:hover {
                background: #F9FAFB;
            }

            .accordion-title-box {
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .scope-badge {
                padding: 6px 16px;
                border-radius: 999px;
                font-size: 0.85rem;
                font-weight: 700;
            }

            .scope-1-badge {
                background: #FFF7ED;
                color: #F6BB43;
            }

            .scope-2-badge {
                background: #FDF2F8;
                color: #FF339A;
            }

            .scope-3-badge {
                background: #EFF6FF;
                color: #0066CB;
            }

            .group-name {
                font-size: 1.1rem;
                font-weight: 600;
                color: #1F2937;
            }

            .accordion-arrow {
                transition: transform 0.3s;
                color: #9CA3AF;
            }

            .expanded .accordion-arrow {
                transform: rotate(180deg);
            }

            .accordion-content {
                display: none;
                padding: 20px 30px 40px 30px;
                border-top: 1px solid #F3F4F6;
            }

            .expanded .accordion-content {
                display: block;
            }

            /* Items Grid */
            .items-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 24px;
            }

            .item-card {
                background: #F9FAFB;
                border-radius: 16px;
                padding: 20px;
                border: 1px solid #F3F4F6;
                transition: all 0.2s;
            }

            .item-card:hover {
                border-color: #E5E7EB;
                transform: translateY(-2px);
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02);
            }

            .item-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 15px;
            }

            .item-label {
                font-size: 0.95rem;
                font-weight: 600;
                color: #374151;
                line-height: 1.4;
            }

            .item-input-group {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            /* Consolidated styles moved to main block */

            .unit-label {
                font-size: 0.9rem;
                color: #6B7280;
                font-weight: 500;
                min-width: 60px;
            }

            /* Action Modal Button Styling */
            .btn-entry-action {
                width: 36px;
                height: 36px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                border: none;
                cursor: pointer;
                transition: all 0.2s;
                margin-left: 8px;
            }

            .btn-edit {
                background-color: #F3F4F6;
                color: #4B5563;
            }

            .btn-edit:hover {
                background-color: #E5E7EB;
                color: #1F2937;
            }

            .btn-delete {
                background-color: #FFF1F2;
                color: #E11D48;
            }

            .btn-delete:hover {
                background-color: #FFE4E6;
                color: #BE123C;
            }

            /* Success/Error Messages */
            .alert {
                padding: 16px 24px;
                border-radius: 16px;
                margin-bottom: 24px;
                font-weight: 500;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .alert-success {
                background: #ECFDF5;
                color: #065F46;
                border: 1px solid #D1FAE5;
            }

            .alert-danger {
                background: #FEF2F2;
                color: #991B1B;
                border: 1px solid #FEE2E2;
            }

            /* Footer */
            .page-footer {
                margin-top: 40px;
                padding-top: 30px;
                border-top: 1px solid #E5E7EB;
                display: flex;
                justify-content: flex-end;
                gap: 16px;
            }

            /* Modal Specific Styling */
            .modal-body-content {
                padding: 10px 0;
            }

            .form-row {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 20px;
            }

            .full-width {
                grid-column: span 2;
            }

            /* Scope Selection Grid in Modal */
            .scope-selection-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 15px;
                max-height: 400px;
                overflow-y: auto;
                padding: 10px;
                background: #F9FAFB;
                border-radius: 16px;
                border: 1px solid #E5E7EB;
            }

            .scope-card-option {
                background: white;
                padding: 15px;
                border-radius: 12px;
                border: 2px solid transparent;
                cursor: pointer;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                gap: 12px;
                position: relative;
            }

            .scope-card-option:hover {
                border-color: #E5E7EB;
                background: #FAFAFA;
            }

            .scope-card-option.checked {
                border-color: #10B981;
                background: #F0FDF4;
            }

            .checkbox-custom {
                width: 24px;
                height: 24px;
                border: 2px solid #D1D5DB;
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s;
            }

            .checked .checkbox-custom {
                background-color: #10B981;
                border-color: #10B981;
            }

            .checkbox-custom svg {
                display: none;
                color: white;
                width: 16px;
                height: 16px;
            }

            .checked .checkbox-custom svg {
                display: block;
            }

            .scope-card-info {
                flex: 1;
            }

            .scope-card-tag {
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
                margin-bottom: 2px;
                display: block;
            }

            .scope-card-name {
                font-size: 0.95rem;
                font-weight: 600;
                color: #374151;
            }
        </style>
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
            <?php
                $toast_msg  = $_GET['msg'] ?? ($error_msg ?? '');
                $toast_type = ((($_GET['msg_type'] ?? '') === 'danger') || (isset($error_msg) && $error_msg !== '') || (isset($_GET['msg']) && str_contains($_GET['msg'], 'ผิดพลาด'))) ? 'danger' : 'success';
                include __DIR__ . '/../components/toast.php';
            ?>

            <div class="header-actions">
                <div>
                    <h1 class="main-heading">กรอกรายละเอียดกิจกรรม</h1>
                    <p style="color: #6B7280; font-size: 0.95rem; margin-top: 4px;">หน่วยงาน:
                        <?= htmlspecialchars($_SESSION['affiliation_name'] ?? '...') ?> | ปี:
                        <?= htmlspecialchars($year_name) ?>
                    </p>
                </div>
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
                    <button type="button"
                        onclick="if(confirm('คุณต้องการยืนยันการบันทึกข้อมูลใช่หรือไม่?')) document.getElementById('main-save-form').submit();"
                        class="btn-action btn-calculate"
                        style="background-color: #10B981; box-shadow: 0 4px 10px rgba(16,185,129,0.2);">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        บันทึกข้อมูล
                    </button>
                    <?php /* ปุ่ม "เพิ่มรายการกิจกรรม" ถูกนำออก:
                             การเพิ่ม/แก้ไข Emission Factor (admin_item) เป็นสิทธิ์ของ admin เท่านั้น
                             (ดูการบล็อก action add_custom_item/edit_item/delete_item ด้านบนของไฟล์) */ ?>
                </div>
            </div>

            <form id="main-save-form" method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="year" value="<?= $selected_year ?>">
                <?php foreach ($scope_groups_ids as $sgid): ?>
                    <input type="hidden" name="scope_groups[]" value="<?= htmlspecialchars($sgid) ?>">
                <?php endforeach; ?>

                <?php foreach ($groups as $index => $group): ?>
                    <?php
                    $items = $items_by_group[$group['id']] ?? [];
                    $theme_class = "scope-" . $group['scope'] . "-theme";
                    $scope_label = "ขอบเขตที่ " . $group['scope'] . ": " . htmlspecialchars($group['name_tiem']);
                    ?>
                    <div class="accordion-section <?= $theme_class ?>" id="section-<?= $group['id'] ?>">
                        <div class="accordion-header" onclick="toggleAccordion('section-<?= $group['id'] ?>')">
                            <div class="accordion-title-box"
                                style="flex: 1; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; margin-right: 30px;">
                                <?php
                                $theme_color = "#4B5563";
                                if ($group['scope'] == 1)
                                    $theme_color = "#F6BB43";
                                elseif ($group['scope'] == 2)
                                    $theme_color = "#FF339A";
                                elseif ($group['scope'] == 3)
                                    $theme_color = "#0066CB";
                                ?>
                                <span class="accordion-title"
                                    style="color: <?= $theme_color ?>; font-size: 1.25rem; font-weight: 700; margin: 0; padding: 0; text-align: left;"><?= $scope_label ?></span>
                                <div style="height: 1.5px; width: 100%; background: <?= $theme_color ?>; opacity: 0.4;">
                                </div>
                            </div>
                            <div class="accordion-actions">
                                <button type="button" class="btn-delete-section"
                                    onclick="event.stopPropagation(); removeScopeGroup(<?= $group['id'] ?>);">ลบกิจกรรม</button>
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
                                    <?php
                                    $info_border = $theme_color;
                                    $data_border = "#D1D5DB";
                                    $action_border = "#3B82F6";
                                    ?>
                                    <tr>
                                        <th>
                                            <div class="header-pill"
                                                style="border-color: <?= $info_border ?>; text-align: left; justify-content: flex-start;">
                                                รายการ</div>
                                        </th>
                                        <th style="width: 100px;">
                                            <div class="header-pill" style="border-color: <?= $info_border ?>;">หน่วย</div>
                                        </th>
                                        <th style="width: 180px;">
                                            <div class="header-pill" style="border-color: <?= $info_border ?>;">
                                                ปริมาณก๊าซเรือนกระจก<br>(kgCO2e/หน่วย)</div>
                                        </th>
                                        <th style="width: 150px;">
                                            <div class="header-pill" style="border-color: <?= $data_border ?>;">ปริมาณ / ปี
                                            </div>
                                        </th>
                                        <th style="width: 180px;">
                                            <div class="header-pill" style="border-color: <?= $data_border ?>;">
                                                ปริมาณก๊าซเรือนกระจก<br>(tCO2e/ต่อปี)</div>
                                        </th>
                                        <th style="width: 80px;">
                                            <div class="header-pill"
                                                style="border-color: <?= $action_border ?>; color: <?= $action_border ?>;">
                                                แนบไฟล์</div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                        $vol = $existing_data[$item['id']] ?? 0;
                                        $total = ($vol * $item['AD']) / 1000;
                                        ?>
                                        <tr class="item-row">
                                            <td>
                                                <div class="cell-pill info-pill" style="justify-content: flex-start;">
                                                    <?= htmlspecialchars($item['name_tiem']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="cell-pill info-pill" style="justify-content: center;">
                                                    <?= htmlspecialchars($item['unit']) ?>
                                                </div>
                                            </td>
                                            <td data-ef="<?= $item['AD'] ?>">
                                                <div class="cell-pill info-pill" style="justify-content: center;">
                                                    <?= rtrim(rtrim(number_format($item['AD'], 4, '.', ','), '0'), '.') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="cell-pill" style="padding: 0; border-color: #E5E7EB;">
                                                    <input type="number" step="any" name="vol[<?= $item['id'] ?>]"
                                                        class="vol-input" style="font-family: 'Kanit', sans-serif;"
                                                        value="<?= (float) number_format($vol, 4, '.', '') ?>" max="1000000"
                                                        oninput="calculateRow(this)">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="cell-pill total-pill">
                                                    <?= rtrim(rtrim(number_format($total, 4, '.', ','), '0'), '.') ?>
                                                </div>
                                            </td>
                                            <td class="action-cell">
                                                <div
                                                    style="display: flex; justify-content: center; align-items: center; height: 48px;">
                                                    <?php
                                                    $ev_count = $evidence_counts[$item['id']] ?? 0;
                                                    $ev_thumb = $evidence_thumbs[$item['id']] ?? null;
                                                    ?>
                                                    <button type="button"
                                                        onclick="openEvidenceModal(<?= $item['id'] ?>, '<?= addslashes($item['name_tiem']) ?>')"
                                                        style="position: relative; width: 36px; height: 36px; border-radius: 50%; background: #3B82F6; color: white; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 3px 8px rgba(59,130,246,0.35); transition: all 0.2s;"
                                                        onmouseover="this.style.background='#2563EB'; this.style.transform='translateY(-2px)'"
                                                        onmouseout="this.style.background='#3B82F6'; this.style.transform='none'"
                                                        title="แนบไฟล์หลักฐาน">
                                                        <?php if ($ev_count > 0): ?>
                                                            <!-- + icon -->
                                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                                                <path
                                                                    d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z" />
                                                            </svg>
                                                            <!-- Count badge -->
                                                            <span
                                                                style="position: absolute; top: -5px; right: -5px; background: #EF4444; color: white; font-size: 0.65rem; font-weight: 800; min-width: 18px; height: 18px; border-radius: 999px; display: flex; align-items: center; justify-content: center; padding: 0 4px; border: 2px solid white; line-height: 1;"><?= $ev_count ?></span>
                                                        <?php else: ?>
                                                            <!-- + icon -->
                                                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                                                                stroke="currentColor" stroke-width="3">
                                                                <line x1="12" y1="5" x2="12" y2="19" />
                                                                <line x1="5" y1="12" x2="19" y2="12" />
                                                            </svg>
                                                        <?php endif; ?>
                                                    </button>
                                                </div>
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
            <button type="button" class="modal-close-btn" onclick="closeModal('modal-add-new-scope')"
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
                                $theme_color = "#F6BB43";
                            elseif ($ag['scope'] == 2)
                                $theme_color = "#FF339A";
                            elseif ($ag['scope'] == 3)
                                $theme_color = "#0066CB";
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
            <button type="button" class="modal-close-btn" onclick="closeModal('modal-edit-item')"
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
                    <?php
                    // ใช้ component dropdown กลาง (components/dropdown.php) แทน <select> เดิม
                    $dd_id          = 'editScopeDropdown';
                    $dd_name        = 'scope_id';
                    $dd_options     = array_map(fn($g) => ['value' => $g['id'], 'label' => 'ขอบเขตที่ ' . $g['scope'] . ' ' . $g['name_tiem']], $groups);
                    $dd_selected    = '';
                    $dd_placeholder = 'เลือกขอบเขต';
                    $dd_required    = true;
                    $dd_class       = 'dd-field';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
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
            <button type="button" class="modal-close-btn" onclick="closeModal('modal-custom-item')"
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
                    <?php
                    // ใช้ component dropdown กลาง (components/dropdown.php) แทน <select> เดิม
                    $dd_id          = 'customScopeDropdown';
                    $dd_name        = 'scope_id';
                    $dd_options     = array_map(fn($g) => ['value' => $g['id'], 'label' => 'ขอบเขตที่ ' . $g['scope'] . ' ' . $g['name_tiem']], $groups);
                    $dd_selected    = '';
                    $dd_placeholder = 'เลือกขอบเขต';
                    $dd_required    = true;
                    $dd_class       = 'dd-field';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
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
            saveAccordionState();
        }

        // จำ accordion ที่เปิดอยู่ (ข้าม reload หลังบันทึก/แก้ไข/ลบ)
        function saveAccordionState() {
            try {
                var open = [];
                document.querySelectorAll('.accordion-section.active').forEach(function (s) { open.push(s.id); });
                sessionStorage.setItem('accOpen:' + location.pathname, JSON.stringify(open));
            } catch (e) {}
        }
        // เปิด accordion กลับทันที (ทำงานตอน parse ก่อน event load → หน้าสูงเท่าเดิม scroll-keep เลื่อนกลับได้)
        (function () {
            try {
                var raw = sessionStorage.getItem('accOpen:' + location.pathname);
                if (raw) JSON.parse(raw).forEach(function (id) {
                    var s = document.getElementById(id);
                    if (s) s.classList.add('active');
                });
            } catch (e) {}
        })();

        function calculateRow(input) {
            if (parseFloat(input.value) > 1000000) input.value = 1000000;
            const row = input.closest('tr');
            const ef_cell = row.querySelector('[data-ef]');
            if (!ef_cell) return;
            const ef = parseFloat(ef_cell.dataset.ef) || 0;
            const vol = parseFloat(input.value) || 0;
            const totalPill = row.querySelector('.total-pill');
            const total = (vol * ef) / 1000;
            totalPill.textContent = total.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 4 });
        }

        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditModal(id, scopeId, name, unit, ad) {
            document.getElementById('edit_item_id').value = id;
            const _opt = document.querySelector('#editScopeDropdown_menu .dd-option[data-value="' + scopeId + '"]');
            ddSetValue('editScopeDropdown', scopeId, _opt ? _opt.textContent.trim() : '');
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

        let sectionIdToDelete = null;

        function removeScopeGroup(idToRemove) {
            sectionIdToDelete = idToRemove;
            openModal('modal-confirm-delete-section');
        }

        function executeRemoveScopeGroup() {
            if (!sectionIdToDelete) return;
            const params = new URLSearchParams(window.location.search);
            const groups = params.getAll('scope_groups[]');
            params.delete('scope_groups[]');
            groups.forEach(id => {
                if (id != sectionIdToDelete) params.append('scope_groups[]', id);
            });
            window.location.href = 'data_entry_items.php?' + params.toString();
        }

        window.onclick = function (event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        }

        // --- Evidence Management JS ---
        let currentEvidenceItemId = null;
        let evEditMode = false;
        let stagedFiles = null;

        function openEvidenceModal(itemId, itemName) {
            currentEvidenceItemId = itemId;
            evEditMode = false;
            stagedFiles = null;

            // Reset title (clear cached baseName so it re-reads fresh)
            const titleEl = document.getElementById('ev-modal-title');
            titleEl.textContent = 'หลักฐาน: ' + itemName;
            titleEl.dataset.baseName = 'หลักฐาน: ' + itemName;

            // Reset upload areas
            document.getElementById('ev-staged-preview').innerHTML = '';
            document.getElementById('ev-edit-staged-preview').innerHTML = '';
            document.getElementById('ev-file-input').value = '';
            document.getElementById('ev-file-edit-input').value = '';
            document.getElementById('ev-sticky-save').style.display = 'none';
            document.getElementById('ev-main-upload-section').style.display = 'none';
            document.getElementById('ev-edit-upload-section').style.display = 'none';

            // Reset header buttons
            document.getElementById('ev-btn-edit').style.display = 'none';
            document.getElementById('ev-btn-delete-all').style.display = 'none';
            const editBtn = document.getElementById('ev-btn-edit');
            editBtn.style.background = '#6366F1';
            editBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> แก้ไข';

            // Reset doc inputs
            const docInput = document.getElementById('ev-doc-input');
            if (docInput) docInput.value = '';
            const docPreview = document.getElementById('ev-doc-staged-preview');
            if (docPreview) docPreview.innerHTML = '';
            const docSave = document.getElementById('ev-doc-sticky-save');
            if (docSave) docSave.style.display = 'none';
            // Reset doc list
            const docList = document.getElementById('ev-doc-list');
            if (docList) docList.innerHTML = '';
            document.getElementById('ev-img-count-badge').textContent = '0';
            document.getElementById('ev-doc-count-badge').textContent = '0';
            // Default tab = images
            switchEvTab('images');

            openModal('modal-manage-evidence');
            loadEvidence(itemId);
        }

        function loadEvidence(itemId) {
            const grid = document.getElementById('ev-grid');
            const loading = document.getElementById('ev-loading');
            const btnEdit = document.getElementById('ev-btn-edit');
            const btnDeleteAll = document.getElementById('ev-btn-delete-all');
            const titleEl = document.getElementById('ev-modal-title');
            loading.style.display = 'block';
            grid.innerHTML = '';

            fetch(`api/manage_evidence.php?action=list&admin_item_id=${itemId}&year_id=<?= $selected_year ?>`)
                .then(res => res.json())
                .then(res => {
                    loading.style.display = 'none';
                    if (res.success) {
                        const images = res.data.filter(f => !f.file_type.startsWith('application/') && !f.file_type.includes('msword') && !f.file_type.includes('spreadsheet') && !f.file_type.includes('presentation'));
                        const docs = res.data.filter(f => f.file_type.startsWith('application/') || f.file_type.includes('msword') || f.file_type.includes('spreadsheet') || f.file_type.includes('presentation'));

                        const imgCount = images.length;
                        const docCount = docs.length;

                        document.getElementById('ev-img-count-badge').textContent = imgCount;
                        document.getElementById('ev-doc-count-badge').textContent = docCount;

                        // Preserve base name
                        // Preserve base name
                        const baseName = titleEl.dataset.baseName || titleEl.textContent.trim();
                        if (!titleEl.dataset.baseName) titleEl.dataset.baseName = baseName;
                        titleEl.innerHTML = baseName + (imgCount + docCount > 0
                            ? ` <span style="font-size:0.7rem; font-weight:700; background:#EDE9FE; color:#7C3AED; border-radius:999px; padding:2px 10px;">${imgCount + docCount} ไฟล์</span>`
                            : ` <span style="font-size:0.7rem; font-weight:500; background:#F3F4F6; color:#9CA3AF; border-radius:999px; padding:2px 10px;">ไม่มีไฟล์</span>`);

                        if (imgCount === 0) {
                            btnEdit.style.display = 'none';
                            btnDeleteAll.style.display = 'none';
                            showUploadZone();
                            document.getElementById('ev-edit-upload-section').style.display = 'none';
                            grid.innerHTML = '';
                        } else {
                            btnEdit.style.display = 'inline-flex';
                            btnDeleteAll.style.display = 'inline-flex';
                            renderEvidenceGrid(images);
                        }

                        renderDocList(docs);
                        updateRowButton(itemId, imgCount + docCount);
                    } else {
                        grid.innerHTML = '<div style="color:#EF4444; text-align:center; padding:20px; grid-column:1/-1;">เกิดข้อผิดพลาด: ' + (res.message || '') + '</div>';
                    }
                })
                .catch(() => { loading.style.display = 'none'; });
        }

        function updateRowButton(itemId, count) {
            const btn = document.querySelector(`button[onclick*="openEvidenceModal(${itemId},"]`);
            if (!btn) return;
            if (count > 0) {
                let badge = btn.querySelector('span');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.style.cssText = 'position:absolute; top:-5px; right:-5px; background:#EF4444; color:white; font-size:0.65rem; font-weight:800; min-width:18px; height:18px; border-radius:999px; display:flex; align-items:center; justify-content:center; padding:0 4px; border:2px solid white; line-height:1;';
                    btn.style.position = 'relative';
                    const svgEl = btn.querySelector('svg');
                    if (svgEl) svgEl.remove();
                    btn.insertAdjacentHTML('afterbegin', '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 3c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm7 13H5v-.23c0-.62.28-1.2.76-1.58C7.47 15.82 9.64 15 12 15s4.53.82 6.24 2.19c.48.38.76.97.76 1.58V19z"/></svg>');
                    btn.appendChild(badge);
                }
                badge.textContent = count;
            } else {
                btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            }
        }


        function renderEvidenceGrid(data) {
            const grid = document.getElementById('ev-grid');
            grid.innerHTML = data.map(ev => `
                <div class="evidence-item" id="ev-item-${ev.id}">
                    <img src="../assets/images/evidence/${ev.file_path}" alt="Evidence" loading="lazy"
                         onclick="openLightbox('../assets/images/evidence/${ev.file_path}')" style="cursor:zoom-in;">
                    <div class="evidence-actions" id="ev-actions-${ev.id}" style="opacity:0; pointer-events:none;">
                        <button type="button" title="ดูขนาดเต็ม" onclick="openLightbox('../assets/images/evidence/${ev.file_path}')"
                            style="background:#6366F1; color:white; border:none; width:32px; height:32px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                <line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/>
                            </svg>
                        </button>
                        <button type="button" title="ลบรูปนี้" class="btn-ev-delete" onclick="deleteEvidence(${ev.id})">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `).join('');

            // apply edit mode if active
            if (evEditMode) applyEditModeToGrid();
        }

        function applyEditModeToGrid() {
            document.querySelectorAll('[id^="ev-actions-"]').forEach(el => {
                el.style.opacity = '1';
                el.style.pointerEvents = 'auto';
            });
        }

        function toggleEvidenceEditMode() {
            evEditMode = !evEditMode;
            const btn = document.getElementById('ev-btn-edit');
            const uploadSection = document.getElementById('ev-edit-upload-section');

            if (evEditMode) {
                btn.style.background = '#10B981';
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> เสร็จสิ้น';
                uploadSection.style.display = 'block';
                applyEditModeToGrid();
            } else {
                btn.style.background = '#6366F1';
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> แก้ไข';
                uploadSection.style.display = 'none';
                // hide overlays
                document.querySelectorAll('[id^="ev-actions-"]').forEach(el => {
                    el.style.opacity = '0';
                    el.style.pointerEvents = 'none';
                });
                // reset staged
                stagedFiles = null;
                document.getElementById('ev-edit-staged-preview').innerHTML = '';
                document.getElementById('ev-sticky-save').style.display = 'none';
                document.getElementById('ev-file-edit-input').value = '';
            }
        }

        function showUploadZone() {
            document.getElementById('ev-main-upload-section').style.display = 'block';
        }

        function hideEditUploadZone() {
            document.getElementById('ev-edit-upload-section').style.display = 'none';
        }

        // Stage files (preview before saving)
        function stageFiles(input, previewId, fileType) {
            if (!input.dt || input._lastProcessedFiles !== input.files) {
                input.dt = new DataTransfer();
                if (input.files) {
                    for (let i = 0; i < input.files.length; i++) {
                        input.dt.items.add(input.files[i]);
                    }
                }
                input._lastProcessedFiles = input.files;
            }

            // Sync files
            input.files = input.dt.files;
            const files = input.files;

            const preview = document.getElementById(previewId);
            preview.innerHTML = '';

            if (!files || files.length === 0) {
                const stickyBar = fileType === 'documents' ? document.getElementById('ev-doc-sticky-save') : document.getElementById('ev-sticky-save');
                if (stickyBar) stickyBar.style.display = 'none';
                return;
            }

            stagedFiles = input;

            if (fileType === 'documents') {
                // Show filename chips for documents
                Array.from(files).forEach((file, index) => {
                    const ext = file.name.split('.').pop().toLowerCase();
                    const iconColor = { pdf: '#EF4444', doc: '#3B82F6', docx: '#3B82F6', xls: '#10B981', xlsx: '#10B981', ppt: '#F97316', pptx: '#F97316' }[ext] || '#6B7280';
                    const chip = document.createElement('div');
                    chip.style.cssText = 'display:inline-flex; align-items:center; gap:6px; background:#F9FAFB; border:1px solid #E5E7EB; border-radius:8px; padding:6px 12px; font-size:0.8rem; font-weight:600; color:#374151; max-width:240px; overflow:hidden; position:relative;';
                    chip.innerHTML = `<span style="color:${iconColor}; font-weight:800; text-transform:uppercase; font-size:0.7rem;">${ext}</span> <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1;">${file.name}</span>
                    <button type="button" onclick="event.stopPropagation(); removeStagedFile('${input.id}', ${index}, '${previewId}', '${fileType}')" style="background:none; border:none; color:#EF4444; cursor:pointer; padding:0 2px; font-size:1.2rem; line-height:1; display:flex; align-items:center; justify-content:center;">&times;</button>`;
                    preview.appendChild(chip);
                });
                const stickyBar = document.getElementById('ev-doc-sticky-save');
                if (stickyBar) stickyBar.style.display = 'flex';
            } else {
                // Image thumbnails
                Array.from(files).forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = e => {
                        const div = document.createElement('div');
                        div.style.cssText = 'display:inline-block; position:relative; margin:4px;';
                        div.innerHTML = `<img src="${e.target.result}" style="width:70px; height:70px; object-fit:cover; border-radius:8px; border:2px solid #A78BFA;">
                        <button type="button" onclick="event.stopPropagation(); removeStagedFile('${input.id}', ${index}, '${previewId}', '${fileType}')" style="position:absolute; top:-6px; right:-6px; background:#EF4444; color:white; border:none; border-radius:50%; width:20px; height:20px; font-size:14px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 2px 4px rgba(0,0,0,0.2);">&times;</button>`;
                        preview.appendChild(div);
                    };
                    reader.readAsDataURL(file);
                });
                const stickyBar = document.getElementById('ev-sticky-save');
                stickyBar.dataset.inputId = input.id;
                stickyBar.style.display = 'flex';
            }
        }

        function removeStagedFile(inputId, index, previewId, fileType) {
            const input = document.getElementById(inputId);
            if (!input || !input.dt) return;

            const newDt = new DataTransfer();
            for (let i = 0; i < input.dt.files.length; i++) {
                if (i !== index) {
                    newDt.items.add(input.dt.files[i]);
                }
            }
            input.dt = newDt;
            input.files = newDt.files;
            input._lastProcessedFiles = input.files;

            stageFiles(input, previewId, fileType);
        }

        function resetEditMode() {
            if (evEditMode) {
                evEditMode = false;
                const btn = document.getElementById('ev-btn-edit');
                const uploadSection = document.getElementById('ev-edit-upload-section');
                if (btn) {
                    btn.style.background = '#6366F1';
                    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> แก้ไข';
                }
                if (uploadSection) uploadSection.style.display = 'none';
            }
            document.getElementById('ev-edit-staged-preview').innerHTML = '';
            document.getElementById('ev-sticky-save').style.display = 'none';
            const editInput = document.getElementById('ev-file-edit-input');
            if (editInput) editInput.value = '';
        }

        function commitUpload(inputId, fileType) {
            const input = document.getElementById(inputId);
            if (!input || !input.files || input.files.length === 0) return;

            const formData = new FormData();
            const fieldName = (fileType === 'documents') ? 'documents' : 'images';
            for (let i = 0; i < input.files.length; i++) {
                formData.append(fieldName + '[]', input.files[i]);
            }
            formData.append('admin_item_id', currentEvidenceItemId);
            formData.append('year_id', <?= $selected_year ?>);

            const loading = document.getElementById(fileType === 'documents' ? 'ev-doc-loading' : 'ev-loading');
            loading.style.display = 'block';
            document.getElementById('ev-sticky-save').style.display = 'none';
            const docBar = document.getElementById('ev-doc-sticky-save');
            if (docBar) docBar.style.display = 'none';

            fetch('api/manage_evidence.php?action=upload', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    loading.style.display = 'none';
                    if (res.success) {
                        input.value = '';
                        if (inputId === 'ev-file-input') {
                            document.getElementById('ev-staged-preview').innerHTML = '';
                            document.getElementById('ev-main-upload-section').style.display = 'none';
                        }
                        if (inputId === 'ev-doc-input') {
                            document.getElementById('ev-doc-staged-preview').innerHTML = '';
                        }
                        resetEditMode();
                        loadEvidence(currentEvidenceItemId);
                    } else {
                        alert('อัปโหลดล้มเหลว: ' + res.message);
                    }
                })
                .catch(() => { loading.style.display = 'none'; alert('เกิดข้อผิดพลาด'); });
        }

        function deleteEvidence(evId) {
            if (!confirm('ลบรูปภาพนี้ใช่หรือไม่?')) return;
            const formData = new FormData();
            formData.append('evidence_id', evId);
            fetch('api/manage_evidence.php?action=delete', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        const el = document.getElementById('ev-item-' + evId);
                        if (el) el.remove();
                        // Always reload to sync title badge + row button in table
                        loadEvidence(currentEvidenceItemId);
                    } else { alert('ลบล้มเหลว: ' + res.message); }
                });
        }

        function deleteAllEvidence() {
            if (!confirm('ลบรูปภาพทั้งหมดในรายการนี้ใช่หรือไม่?')) return;
            const formData = new FormData();
            formData.append('admin_item_id', currentEvidenceItemId);
            formData.append('year_id', <?= $selected_year ?>);
            document.getElementById('ev-loading').style.display = 'block';
            fetch('api/manage_evidence.php?action=delete_all', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(res => {
                    document.getElementById('ev-loading').style.display = 'none';
                    if (res.success) {
                        evEditMode = false;
                        loadEvidence(currentEvidenceItemId);
                    } else { alert('เกิดข้อผิดพลาด: ' + res.message); }
                });
        }

        // Lightbox
        function openLightbox(src) {
            document.getElementById('ev-lightbox-img').src = src;
            document.getElementById('ev-lightbox').style.display = 'flex';
        }
        function closeLightbox() {
            document.getElementById('ev-lightbox').style.display = 'none';
            document.getElementById('ev-lightbox-img').src = '';
        }

        // Tab switching
        let currentEvTab = 'images';
        function switchEvTab(tab) {
            currentEvTab = tab;
            const panelImg = document.getElementById('ev-tab-panel-images');
            const panelDoc = document.getElementById('ev-tab-panel-docs');
            const tabImg = document.getElementById('ev-tab-images');
            const tabDoc = document.getElementById('ev-tab-docs');

            if (tab === 'images') {
                panelImg.style.display = 'block';
                panelDoc.style.display = 'none';
                tabImg.style.color = '#3B82F6'; tabImg.style.borderBottomColor = '#3B82F6'; tabImg.style.fontWeight = '700';
                tabDoc.style.color = '#9CA3AF'; tabDoc.style.borderBottomColor = 'transparent'; tabDoc.style.fontWeight = '600';
            } else {
                panelImg.style.display = 'none';
                panelDoc.style.display = 'block';
                tabDoc.style.color = '#3B82F6'; tabDoc.style.borderBottomColor = '#3B82F6'; tabDoc.style.fontWeight = '700';
                tabImg.style.color = '#9CA3AF'; tabImg.style.borderBottomColor = 'transparent'; tabImg.style.fontWeight = '600';
            }
        }

        // Render document list
        function renderDocList(docs) {
            const list = document.getElementById('ev-doc-list');
            const empty = document.getElementById('ev-doc-empty');
            const loading = document.getElementById('ev-doc-loading');
            if (loading) loading.style.display = 'none';

            if (!docs || docs.length === 0) {
                list.innerHTML = '';
                empty.style.display = 'block';
                return;
            }
            empty.style.display = 'none';

            const extColor = { pdf: '#EF4444', doc: '#3B82F6', docx: '#3B82F6', xls: '#10B981', xlsx: '#10B981', ppt: '#F97316', pptx: '#F97316' };
            const extBg = { pdf: '#FEF2F2', doc: '#EFF6FF', docx: '#EFF6FF', xls: '#ECFDF5', xlsx: '#ECFDF5', ppt: '#FFF7ED', pptx: '#FFF7ED' };

            list.innerHTML = docs.map(doc => {
                const diskName = doc.file_path.split('/').pop();
                const filename = doc.original_name || diskName;   // แสดงชื่อไฟล์ต้นฉบับ (รองรับไทย)
                const ext = filename.split('.').pop().toLowerCase();
                const color = extColor[ext] || '#6B7280';
                const bg = extBg[ext] || '#F3F4F6';
                return `
                <div style="display:flex; align-items:center; gap:12px; background:${bg}; border-radius:12px; padding:12px 16px; border:1px solid rgba(0,0,0,0.04);">
                    <div style="width:40px; height:40px; border-radius:10px; background:${color}20; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <span style="font-size:0.65rem; font-weight:900; color:${color}; text-transform:uppercase;">${ext}</span>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:600; color:#1F2937; font-size:0.875rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${filename}">${filename}</div>
                        <div style="font-size:0.75rem; color:#9CA3AF; margin-top:2px;">${new Date(doc.created_at).toLocaleDateString('th-TH')}</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <a href="../assets/images/evidence/${doc.file_path}" download="${filename}" title="ดาวน์โหลด"
                            style="width:34px; height:34px; border-radius:8px; background:#3B82F6; color:white; display:flex; align-items:center; justify-content:center; text-decoration:none;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        </a>
                        <button type="button" onclick="deleteEvidence(${doc.id})" title="ลบ"
                            style="width:34px; height:34px; border-radius:8px; background:#FEF2F2; color:#EF4444; border:none; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>`;
            }).join('');
        }
    </script>

    <!-- Lightbox -->
    <div id="ev-lightbox" onclick="closeLightbox()"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.88); z-index:99999; align-items:center; justify-content:center; cursor:zoom-out;">
        <img id="ev-lightbox-img" src="" alt="Evidence Full"
            style="max-width:90vw; max-height:90vh; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.5);"
            onclick="event.stopPropagation();">
        <button onclick="closeLightbox()"
            style="position:absolute; top:20px; right:20px; background:rgba(255,255,255,0.15); color:white; border:none; width:44px; height:44px; border-radius:50%; font-size:1.4rem; cursor:pointer; backdrop-filter:blur(4px);">✕</button>
    </div>

    <!-- Modal: จัดการหลักฐาน (Manage Evidence) -->
    <div class="modal-overlay" id="modal-manage-evidence">
        <div class="modal-box" style="max-width: 720px; padding: 2rem 2.5rem; border-radius: 24px; position: relative;">
            <!-- Close -->
            <button type="button" class="modal-close-btn" onclick="closeModal('modal-manage-evidence')"
                style="position: absolute; top: 12px; right: 12px; background: #FF4747; color: white; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index:10;">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>

            <!-- Header -->
            <div
                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; padding-right:30px; flex-wrap:wrap; gap:10px;">
                <h3 id="ev-modal-title" style="font-size:1.25rem; font-weight:700; color:#1F2937; margin:0;">
                    จัดการหลักฐาน</h3>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button id="ev-btn-edit" type="button" onclick="toggleEvidenceEditMode()"
                        style="display:none; align-items:center; gap:6px; background:#6366F1; color:white; border:none; padding:8px 16px; border-radius:999px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.2s;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                        </svg> แก้ไข
                    </button>
                    <button id="ev-btn-delete-all" type="button" onclick="deleteAllEvidence()"
                        style="display:none; align-items:center; gap:6px; background:#FEF2F2; color:#EF4444; border:1px solid #FCA5A5; padding:8px 16px; border-radius:999px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:all 0.2s;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                        </svg> ลบทั้งหมด
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div style="display:flex; gap:0; border-bottom:2px solid #F3F4F6; margin-bottom:1.25rem;">
                <button id="ev-tab-images" onclick="switchEvTab('images')"
                    style="padding:8px 24px; border:none; background:none; font-weight:700; font-size:0.9rem; color:#3B82F6; border-bottom:3px solid #3B82F6; margin-bottom:-2px; cursor:pointer; transition:all 0.2s;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        style="vertical-align:middle; margin-right:5px;">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                        <circle cx="12" cy="13" r="3" />
                    </svg>
                    รูปภาพ <span id="ev-img-count-badge"
                        style="background:#EDE9FE; color:#7C3AED; border-radius:999px; padding:1px 8px; font-size:0.75rem; margin-left:4px;">0</span>
                </button>
                <button id="ev-tab-docs" onclick="switchEvTab('docs')"
                    style="padding:8px 24px; border:none; background:none; font-weight:600; font-size:0.9rem; color:#9CA3AF; border-bottom:3px solid transparent; margin-bottom:-2px; cursor:pointer; transition:all 0.2s;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        style="vertical-align:middle; margin-right:5px;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                    </svg>
                    เอกสาร <span id="ev-doc-count-badge"
                        style="background:#EFF6FF; color:#3B82F6; border-radius:999px; padding:1px 8px; font-size:0.75rem; margin-left:4px;">0</span>
                </button>
            </div>

            <!-- Tab: รูปภาพ -->
            <div id="ev-tab-panel-images">
                <!-- Main upload zone (no images yet) -->
                <div id="ev-main-upload-section" style="display:none;">
                    <div class="upload-zone" onclick="document.getElementById('ev-file-input').click()">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#A78BFA" stroke-width="2"
                            style="margin-bottom:8px;">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                        </svg>
                        <div style="font-weight:600; color:#4B5563;">คลิกเพื่อเลือกรูปภาพหลักฐาน</div>
                        <div style="font-size:0.8rem; color:#9CA3AF; margin-top:4px;">JPG, PNG, WebP (เลือกได้หลายไฟล์)
                        </div>
                        <input type="file" id="ev-file-input" multiple accept="image/*" style="display:none;"
                            onchange="stageFiles(this, 'ev-staged-preview', 'images')">
                    </div>
                    <div id="ev-staged-preview" style="margin-top:12px; display:flex; flex-wrap:wrap; gap:4px;"></div>
                </div>

                <!-- Edit-mode upload (has images) -->
                <div id="ev-edit-upload-section"
                    style="display:none; margin-bottom:16px; border:1px dashed #C4B5FD; border-radius:16px; padding:16px;">
                    <div style="font-size:0.85rem; font-weight:600; color:#7C3AED; margin-bottom:10px;">เพิ่มรูปภาพใหม่
                    </div>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <label for="ev-file-edit-input"
                            style="display:inline-flex; align-items:center; gap:6px; background:#F5F3FF; color:#7C3AED; border:1.5px solid #C4B5FD; padding:8px 16px; border-radius:999px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <polyline points="17 8 12 3 7 8" />
                                <line x1="12" y1="3" x2="12" y2="15" />
                            </svg>
                            เลือกรูปภาพ
                        </label>
                        <input type="file" id="ev-file-edit-input" multiple accept="image/*" style="display:none;"
                            onchange="stageFiles(this, 'ev-edit-staged-preview', 'images')">
                        <div id="ev-edit-staged-preview" style="display:flex; flex-wrap:wrap; gap:4px;"></div>
                    </div>
                </div>

                <!-- Loading -->
                <div id="ev-loading" style="display:none; text-align:center; margin:20px 0;">
                    <div
                        style="display:inline-block; width:30px; height:30px; border:3px solid #f3f3f3; border-top:3px solid #A78BFA; border-radius:50%; animation:spin 1s linear infinite;">
                    </div>
                    <div style="color:#9CA3AF; font-size:0.85rem; margin-top:8px;">กำลังโหลด...</div>
                </div>

                <!-- Image Grid -->
                <div class="evidence-grid" id="ev-grid" style="padding-bottom: 60px;"></div>

                <!-- Sticky Save -->
                <div id="ev-sticky-save" data-input-id=""
                    style="display:none; position:sticky; bottom:0; left:0; right:0; padding:12px 0 0; margin-top:8px; background:linear-gradient(to top, white 70%, transparent); justify-content:flex-end;">
                    <button type="button"
                        onclick="commitUpload(document.getElementById('ev-sticky-save').dataset.inputId, 'images')"
                        style="display:inline-flex; align-items:center; gap:8px; background:#7C3AED; color:white; border:none; padding:12px 32px; border-radius:999px; font-weight:700; font-size:0.95rem; cursor:pointer; box-shadow:0 4px 20px rgba(124,58,237,0.45); transition:all 0.2s;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                            <polyline points="17 21 17 13 7 13 7 21" />
                            <polyline points="7 3 7 8 15 8" />
                        </svg>
                        บันทึกรูปภาพ
                    </button>
                </div>
            </div>

            <!-- Tab: เอกสาร -->
            <div id="ev-tab-panel-docs" style="display:none;">
                <!-- Doc upload zone -->
                <div style="border:2px dashed #BFDBFE; border-radius:16px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; margin-bottom:16px;"
                    onclick="document.getElementById('ev-doc-input').click()"
                    onmouseover="this.style.borderColor='#3B82F6'; this.style.background='#EFF6FF';"
                    onmouseout="this.style.borderColor='#BFDBFE'; this.style.background='';">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2"
                        style="margin-bottom:8px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="17 8 12 3 7 8" />
                        <line x1="12" y1="3" x2="12" y2="15" />
                    </svg>
                    <div style="font-weight:600; color:#1D4ED8;">คลิกเพื่อแนบไฟล์เอกสาร</div>
                    <div style="font-size:0.8rem; color:#9CA3AF; margin-top:4px;">PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX
                        (เลือกได้หลายไฟล์)</div>
                    <input type="file" id="ev-doc-input" multiple
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        style="display:none;" onchange="stageFiles(this, 'ev-doc-staged-preview', 'documents')">
                </div>
                <!-- Staged doc preview -->
                <div id="ev-doc-staged-preview" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
                </div>
                <!-- Sticky Save for docs -->
                <div id="ev-doc-sticky-save" data-input-id="ev-doc-input"
                    style="display:none; justify-content:flex-end; margin-bottom:16px;">
                    <button type="button" onclick="commitUpload('ev-doc-input', 'documents')"
                        style="display:inline-flex; align-items:center; gap:8px; background:#3B82F6; color:white; border:none; padding:10px 28px; border-radius:999px; font-weight:700; font-size:0.9rem; cursor:pointer; box-shadow:0 4px 14px rgba(59,130,246,0.35);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                            <polyline points="17 21 17 13 7 13 7 21" />
                            <polyline points="7 3 7 8 15 8" />
                        </svg>
                        บันทึกเอกสาร
                    </button>
                </div>

                <!-- Document list -->
                <div id="ev-doc-loading" style="display:none; text-align:center; margin:10px 0;">
                    <div
                        style="display:inline-block; width:24px; height:24px; border:3px solid #f3f3f3; border-top:3px solid #3B82F6; border-radius:50%; animation:spin 1s linear infinite;">
                    </div>
                </div>
                <div id="ev-doc-list" style="display:flex; flex-direction:column; gap:10px;"></div>
                <div id="ev-doc-empty"
                    style="display:none; text-align:center; color:#9CA3AF; padding:30px 0; font-size:0.95rem;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5"
                        style="display:block; margin:0 auto 10px;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                    </svg>
                    ยังไม่มีเอกสารแนบ
                </div>
            </div>
        </div>
    </div>



    <!-- Modal: บันทึกสำเร็จ (Success Popup) -->
    <?php if (isset($_GET['msg']) && str_contains($_GET['msg'], 'บันทึกข้อมูลสำเร็จ')): ?>
        <div class="modal-overlay" id="modal-save-success" style="display: flex;">
            <div class="modal-box" style="max-width: 400px; text-align: center; padding: 2.5rem;">
                <div
                    style="background: #DCFCE7; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; animation: successScale 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
                    <svg viewBox="0 0 24 24" width="40" height="40" stroke="#16A34A" stroke-width="3" fill="none">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
                <h2 style="font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 0.5rem;">บันทึกข้อมูลสำเร็จ!
                </h2>
                <p style="color: #6B7280; margin-bottom: 2rem;">ข้อมูลปริมาณก๊าซเรือนกระจกของคุณถูกจัดเก็บเรียบร้อยแล้ว</p>

                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <button type="button" class="btn-primary" onclick="closeModal('modal-save-success')"
                        style="padding: 12px; width: 100%;">ตกลง, บันทึกต่อ</button>
                    <a href="index.php" class="btn-secondary"
                        style="text-decoration: none; padding: 12px; display: block; width: 100%;">กลับหน้า Dashboard</a>
                </div>
            </div>
        </div>
        <style>
            @keyframes successScale {
                from {
                    transform: scale(0);
                    opacity: 0;
                }

                to {
                    transform: scale(1);
                    opacity: 1;
                }
            }
        </style>
    <?php endif; ?>

    <!-- Modal: ยืนยันการลบกิจกรรม (Confirm Delete Section) -->
    <div class="modal-overlay" id="modal-confirm-delete-section">
        <div class="modal-box"
            style="max-width: 400px; text-align: center; padding: 2.5rem; border-radius: 24px; background: white; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
            <div
                style="background: #FEE2E2; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                <svg viewBox="0 0 24 24" width="40" height="40" stroke="#EF4444" stroke-width="2.5" fill="none">
                    <path
                        d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6">
                    </path>
                </svg>
            </div>
            <h2 style="font-size: 1.4rem; font-weight: 800; color: #111827; margin-bottom: 0.5rem;">ลบกิจกรรมนี้?</h2>
            <p style="color: #6B7280; margin-bottom: 2rem; font-size: 0.95rem;">
                คุณแน่ใจหรือไม่ว่าต้องการลบกิจกรรมนี้ออกจากรายการ?</p>

            <div style="display: flex; gap: 10px;">
                <button type="button" onclick="closeModal('modal-confirm-delete-section')"
                    style="flex: 1; padding: 12px; border-radius: 12px; border: 1px solid #E5E7EB; background: white; color: #4B5563; font-weight: 600; cursor: pointer; transition: all 0.2s;"
                    onmouseover="this.style.background='#F3F4F6'"
                    onmouseout="this.style.background='white'">ยกเลิก</button>
                <button type="button" onclick="executeRemoveScopeGroup()"
                    style="flex: 1; padding: 12px; border-radius: 12px; border: none; background: #EF4444; color: white; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(239,68,68,0.25);"
                    onmouseover="this.style.transform='translateY(-2px)'"
                    onmouseout="this.style.transform='translateY(0)'">ลบกิจกรรม</button>
            </div>
        </div>
    </div>

</body>

</html>