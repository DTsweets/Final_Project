<?php
/**
 * ADMIN — Data Entry Step 1: Scope Selection (data_entry.php)
 * ---------------------------------------------------------
 * หน้าเลือกขอบเขตกิจกรรม (Step 1) ตามรูปต้นฉบับ
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['officer']);

$pdo = getDB();
$root = '../';
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;

if ($selected_year <= 0) {
    header('Location: items.php');
    exit;
}

// Fetch year name
$stmt = $pdo->prepare('SELECT year FROM admin_year WHERE id = ?');
$stmt->execute([$selected_year]);
$year_data = $stmt->fetch();
$year_name = $year_data ? $year_data['year'] : 'ไม่ระบุ';

// Fetch scope groups
$groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();

$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
$page_title3 = "เลือกขอบเขตกิจกรรม $year_name";
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูล — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
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

            .data-entry-container {
                width: 100%;
                margin: 0 auto;
                padding: 2rem;
            }

            .main-heading {
                font-size: 1.5rem;
                font-weight: 700;
                color: #4B5563;
                margin-bottom: 2rem;
            }

            .entry-card {
                background: #FFFFFF;
                border-radius: 24px;
                padding: 40px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
                border: 1px solid #E5E7EB;
            }

            .entry-card-title {
                font-size: 1.25rem;
                font-weight: 700;
                color: #374151;
                margin-bottom: 1.5rem;
                border-bottom: 1px solid #E5E7EB;
                padding-bottom: 1rem;
            }

            .scope-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0 12px;
            }

            .scope-table th {
                text-align: left;
                padding: 0 20px 10px 20px;
                color: #6B7280;
                font-weight: 600;
                font-size: 0.95rem;
            }

            .scope-row {
                transition: all 0.2s;
                cursor: pointer;
            }

            .scope-row td {
                padding: 16px 20px;
                background: #FFFFFF;
            }

            .scope-row td:first-child {
                border-radius: 5vh;
                width: 80px;
                text-align: center;
            }

            .scope-row td:last-child {
                border-radius: 1vh;
            }

            /* Scope Colors */
            .scope-1-row td:last-child {
                background-color: #FFF7ED;
                color: #9A3412;
                font-weight: 600;
            }

            .scope-2-row td:last-child {
                background-color: #FDF2F8;
                color: #9D174D;
                font-weight: 600;
            }

            .scope-3-row td:last-child {
                background-color: #EFF6FF;
                color: #1E40AF;
                font-weight: 600;
            }

            .scope-row:hover {
                transform: scale(1.01);
            }

            /* Custom Radio Styling */
            .radio-container {
                display: inline-block;
                position: relative;
                width: 40px;
                height: 40px;
            }

            .radio-container input {
                position: absolute;
                opacity: 0;
                cursor: pointer;
                height: 0;
                width: 0;
            }

            .checkmark {
                position: absolute;
                top: 0;
                left: 0;
                height: 40px;
                width: 40px;
                background-color: #D1D5DB;
                border-radius: 10px;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .checkmark svg {
                display: none;
                color: white;
                width: 30px;
                height: 30px;
            }

            .radio-container input:checked~.checkmark {
                background-color: #949494;
            }

            .radio-container input:checked~.checkmark svg {
                display: block;
            }

            /* Next Button */
            .btn-next {
                display: flex;
                align-items: center;
                gap: 12px;
                background: #4B4B4B;
                color: white;
                border: none;
                padding: 14px 32px;
                border-radius: 999px;
                font-weight: 600;
                font-size: 1rem;
                cursor: pointer;
                transition: all 0.3s;
                margin-top: 2rem;
                float: right;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            }

            .btn-next:hover {
                background: #333333;
                transform: translateX(4px);
            }

            .btn-next svg {
                width: 18px;
                height: 18px;
            }

            .footer-actions {
                display: flex;
                justify-content: flex-end;
                margin-top: 30px;
            }

            .clear {
                clear: both;
            }
        </style>
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="data-entry-container">
            <a href="items.php"
                style="display: inline-flex; align-items: center; gap: 8px; color: #6B7280; text-decoration: none; font-weight: 600; margin-bottom: 1.5rem; transition: all 0.2s; font-size: 0.95rem;"
                onmouseover="this.style.color='var(--clr-primary)'; this.style.transform='translateX(-4px)';"
                onmouseout="this.style.color='#6B7280'; this.style.transform='none'">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                ย้อนกลับไปหน้าเลือกปีงบประมาณ
            </a>
            <h1 class="main-heading">เลือกขอบเขตกิจกรรม</h1>

            <div class="entry-card">
                <div class="entry-card-title">ขอบเขตกิจกรรม</div>

                <form action="data_entry_items.php" method="GET">
                    <input type="hidden" name="year" value="<?= $selected_year ?>">

                    <table class="scope-table">
                        <thead>
                            <tr>
                                <th style="width: 100px; display: flex; justify-content: center;">เลือก</th>
                                <th>ขอบเขต</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $g): ?>
                                <?php
                                $scope_class = "scope-" . $g['scope'] . "-row";
                                $scope_prefix = "ขอบเขตที่ " . $g['scope'];
                                ?>
                                <tr class="scope-row <?= $scope_class ?>"
                                    onclick="const cb = this.querySelector('input'); cb.checked = !cb.checked;">
                                    <td>
                                        <label class="radio-container">
                                            <input type="checkbox" name="scope_groups[]" value="<?= $g['id'] ?>">
                                            <span class="checkmark">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4"
                                                    stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="20 6 9 17 4 12"></polyline>
                                                </svg>
                                            </span>
                                        </label>
                                    </td>
                                    <td>
                                        <?= $scope_prefix ?>     <?= htmlspecialchars($g['name_tiem']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="footer-actions">
                        <button type="submit" class="btn-next">
                            ถัดไป
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </button>
                    </div>
                    <div class="clear"></div>
                </form>
            </div>
        </div>
    </main>

    <script>
        document.querySelector('form').addEventListener('submit', function (e) {
            const checkboxes = document.querySelectorAll('input[name="scope_groups[]"]:checked');
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('กรุณาเลือกอย่างน้อย 1 ขอบเขตกิจกรรม');
            }
        });
    </script>

</body>

</html>