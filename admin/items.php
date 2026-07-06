<?php
/**
 * ADMIN — Scope Items Management (items.php)
 * -------------------------------------------
 * สิทธิ์: admin, admin_c
 * จัดการ admin_item (รายการ emission factor)
 * ดู/เพิ่ม/แก้ไข/ลบ admin_item ตามปีและ scope
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

require_role(['admin']);

$pdo = getDB();
$root = '../';
$role = $_SESSION['role'];
$msg = '';
$msg_type = 'success';

// ── Masters ───────────────────────────────────────
$fetch_years_sql = '
    SELECT y.id, y.year, COALESCE(SUM(ui.Vol * ai.AD), 0) AS total_emission
    FROM admin_year y
    LEFT JOIN user_item ui ON ui.id = (SELECT id FROM user_item WHERE year_id = y.id AND affiliation_id = ui.affiliation_id LIMIT 1) -- Fixed JOIN to prevent duplication if multiple affiliations exist? Wait, no.
';
// Actually, the user wants the GLOBAL total emission on the cards.
$fetch_years_sql = '
    SELECT y.id, y.year, COALESCE(SUM(ui.Vol * ai.AD), 0) AS total_emission
    FROM admin_year y
    LEFT JOIN user_item ui ON ui.year_id = y.id
    LEFT JOIN admin_item ai ON ai.id = ui.admin_item_id
    GROUP BY y.id, y.year
    ORDER BY y.year DESC
';
$years = $pdo->query($fetch_years_sql)->fetchAll();
$groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();

$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : ($years[0]['id'] ?? 0);

$affiliation_name = $_SESSION['affiliation_name'] ?? 'ADMIN(คณะ)';


// ── Handle POST (admin เท่านั้นแก้ไข) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin') {
    $action = $_POST['action'] ?? '';
    $redirect_url = 'items.php?year=' . $selected_year;

    // เพิ่ม item
    if ($action === 'add') {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO admin_item (year_id, scope, name_tiem, unit, AD)
                 VALUES (:y, :s, :n, :u, :a)'
            );
            $stmt->execute([
                ':y' => (int) $_POST['year_id'],
                ':s' => (int) $_POST['scope'],
                ':n' => trim($_POST['name_tiem']),
                ':u' => trim($_POST['unit']),
                ':a' => (float) $_POST['AD'],
            ]);
            $msg = 'เพิ่มรายการสำเร็จ';
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = $e->getCode() === '23000'
                ? 'รายการนี้มีอยู่แล้วในปีและ scope เดียวกัน'
                : 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }

    // แก้ไข item
    if ($action === 'edit') {
        try {
            $stmt = $pdo->prepare(
                'UPDATE admin_item SET scope=:s, name_tiem=:n, unit=:u, AD=:a WHERE id=:id'
            );
            $stmt->execute([
                ':s' => (int) $_POST['scope'],
                ':n' => trim($_POST['name_tiem']),
                ':u' => trim($_POST['unit']),
                ':a' => (float) $_POST['AD'],
                ':id' => (int) $_POST['item_id'],
            ]);
            $msg = 'แก้ไขรายการสำเร็จ';
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }

    // ลบ item
    if ($action === 'delete') {
        try {
            $pdo->prepare('DELETE FROM admin_item WHERE id=:id')
                ->execute([':id' => (int) $_POST['item_id']]);
            $msg = 'ลบรายการสำเร็จ';
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = 'ไม่สามารถลบได้ มีข้อมูล user_item อ้างอิงอยู่';
        }
    }

    // เพิ่มปีใหม่
    if ($action === 'add_year') {
        try {
            $pdo->prepare('INSERT INTO admin_year (year) VALUES (:y)')
                ->execute([':y' => (int) $_POST['new_year']]);
            $new_id = $pdo->lastInsertId();
            header("Location: items.php?year=$new_id&msg=เพิ่มปีสำเร็จ");
            exit;
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = 'ปีนี้มีอยู่แล้วในระบบ';
        }
    }

    // แก้ไขปี
    if ($action === 'edit_year') {
        $target_id = (int) $_POST['year_id'];
        $new_year_val = (int) $_POST['year_val'];

        // ตรวจสอบว่ามีปีนี้อยู่แล้วหรือไม่ (ภายใต้ ID ที่ไม่ใช่ตัวเอง)
        $stmt = $pdo->prepare('SELECT id, year FROM admin_year WHERE year = :y AND id != :id');
        $stmt->execute([':y' => $new_year_val, ':id' => $target_id]);
        $duplicate = $stmt->fetch();

        if ($duplicate) {
            // ถ้ามีอยู่แล้ว ส่งกลับไปให้กดยืนยันการสลับปี
            $other_id = $duplicate['id'];
            $target_year = $pdo->query("SELECT year FROM admin_year WHERE id = $target_id")->fetchColumn();

            // ใช้ PRG เพื่อเตรียมแสดง Modal สลับปี
            header("Location: items.php?year=$selected_year&msg=duplicate_year&id1=$target_id&id2=$other_id&y1=$target_year&y2=$new_year_val");
            exit;
        } else {
            try {
                $pdo->prepare('UPDATE admin_year SET year = :y WHERE id = :id')
                    ->execute([':y' => $new_year_val, ':id' => $target_id]);
                header("Location: items.php?year=$target_id&msg=แก้ไขปีสำเร็จ");
                exit;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
            }
        }
    }

    // สลับปี
    if ($action === 'swap_years') {
        $id1 = (int) $_POST['id1'];
        $id2 = (int) $_POST['id2'];
        $y1 = (int) $_POST['y1'];
        $y2 = (int) $_POST['y2'];

        try {
            $pdo->beginTransaction();
            // Step 1: Temp value for ID 2
            $pdo->prepare('UPDATE admin_year SET year = -1 WHERE id = :id')->execute([':id' => $id2]);
            // Step 2: Set ID 1 to Year 2
            $pdo->prepare('UPDATE admin_year SET year = :y WHERE id = :id')->execute([':y' => $y2, ':id' => $id1]);
            // Step 3: Set ID 2 to Year 1
            $pdo->prepare('UPDATE admin_year SET year = :y WHERE id = :id')->execute([':y' => $y1, ':id' => $id2]);
            $pdo->commit();
            header("Location: items.php?year=$id1&msg=สลับข้อมูลปีเรียบร้อยแล้ว");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = "เกิดข้อผิดพลาดในการสลับปี: " . $e->getMessage();
        }
    }

    // ลบปี
    if ($action === 'delete_year') {
        try {
            $pdo->prepare('DELETE FROM admin_year WHERE id = :id')
                ->execute([':id' => (int) $_POST['year_id']]);
            header("Location: items.php?msg=ลบปีสำเร็จ");
            exit;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
        }
    }

    // คัดลอก admin_item จากปีต้นทางมายังปีปลายทาง
    if ($action === 'copy_year') {
        $target_year_id = (int) $_POST['target_year_id'];
        $source_year_id = (int) $_POST['source_year_id'];

        if ($target_year_id <= 0 || $source_year_id <= 0 || $target_year_id === $source_year_id) {
            $msg = 'ข้อมูลไม่ถูกต้อง';
            $msg_type = 'danger';
        } else {
            try {
                // INSERT IGNORE: คัดลอกรายการที่ยังไม่มีในปีปลายทาง (จับคู่ด้วย scope + name_tiem)
                $copy_stmt = $pdo->prepare('
                    INSERT IGNORE INTO admin_item (year_id, scope, name_tiem, unit, AD)
                    SELECT :tgt_year, scope, name_tiem, unit, AD
                    FROM admin_item
                    WHERE year_id = :src_year
                ');
                $copy_stmt->execute([':tgt_year' => $target_year_id, ':src_year' => $source_year_id]);
                $copied = $copy_stmt->rowCount();

                header("Location: items.php?year=$target_year_id&msg=" . urlencode("คัดลอกรายการสำเร็จ ($copied รายการ)"));
                exit;
            } catch (PDOException $e) {
                $msg = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
                $msg_type = 'danger';
            }
        }
    }

    // เพิ่มขอบเขต (Scope) ใหม่
    if ($action === 'add_scope') {
        try {
            $stmt = $pdo->prepare('SELECT MAX(order_num) FROM admin_g WHERE scope = :s');
            $stmt->execute([':s' => (int) $_POST['scope']]);
            $max_order = (int) $stmt->fetchColumn();

            $pdo->prepare('INSERT INTO admin_g (scope, name_tiem, order_num) VALUES (:s, :n, :o)')
                ->execute([
                    ':s' => (int) $_POST['scope'],
                    ':n' => trim($_POST['name_tiem']),
                    ':o' => $max_order + 1
                ]);
            $msg = 'เพิ่มขอบเขตสำเร็จ';
            // Refresh groups
            $groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = 'เกิดข้อผิดพลาด: ขอบเขตนี้อาจมีอยู่แล้ว หรือ ' . $e->getMessage();
        }
    }

    // เลื่อนขอบเขต (Scope)
    if ($action === 'move_scope') {
        try {
            $scope_id = (int) $_POST['scope_id'];
            $direction = $_POST['direction']; // 'up' or 'down'

            $stmt = $pdo->prepare('SELECT * FROM admin_g WHERE id = ?');
            $stmt->execute([$scope_id]);
            $current = $stmt->fetch();

            if ($current) {
                $scope_group = $current['scope'];
                $current_order = $current['order_num'];

                if ($direction === 'up') {
                    $stmt = $pdo->prepare('SELECT * FROM admin_g WHERE scope = ? AND order_num < ? ORDER BY order_num DESC, id DESC LIMIT 1');
                } else {
                    $stmt = $pdo->prepare('SELECT * FROM admin_g WHERE scope = ? AND order_num > ? ORDER BY order_num ASC, id ASC LIMIT 1');
                }
                $stmt->execute([$scope_group, $current_order]);
                $swap = $stmt->fetch();

                if ($swap) {
                    $pdo->prepare('UPDATE admin_g SET order_num = ? WHERE id = ?')->execute([$swap['order_num'], $current['id']]);
                    $pdo->prepare('UPDATE admin_g SET order_num = ? WHERE id = ?')->execute([$current['order_num'], $swap['id']]);
                    $msg = 'เลื่อนลำดับสำเร็จ';
                    $groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();
                } else {
                    $msg_type = 'danger';
                    $msg = 'ไม่สามารถเลื่อนได้แล้ว';
                }
            }
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => ($msg_type === 'success'), 'msg' => $msg]);
            exit;
        }
    }

    // ลบขอบเขต (Scope)
    if ($action === 'delete_scope') {
        try {
            $pdo->prepare('DELETE FROM admin_g WHERE id=:id')
                ->execute([':id' => (int) $_POST['scope_id']]);
            $msg = 'ลบขอบเขตสำเร็จ';
            // Refresh groups
            $groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();
        } catch (PDOException $e) {
            $msg_type = 'danger';
            $msg = 'ไม่สามารถลบขอบเขตได้ เนื่องจากอาจมีข้อมูล Emission Factor ที่อ้างอิงอยู่';
        }
    }
}

// ── ดึง admin_item ตามปี ─────────────────────────
$items_sql = '
    SELECT ai.*, ag.scope AS scope_group, ag.name_tiem AS group_name
    FROM admin_item ai
    JOIN admin_g ag ON ag.id = ai.scope
    WHERE ai.year_id = :year
    ORDER BY ai.scope, ai.id
';
$stmt = $pdo->prepare($items_sql);
$stmt->execute([':year' => $selected_year]);
$items = $stmt->fetchAll();

// scope CSS
$scope_css = [1 => 's1', 2 => 's2', 3 => 's3'];
$remaining = session_remaining();
$fullname = $_SESSION['firstname'] . ' ' . $_SESSION['lastname'];
$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการ Scope Items — UP Net Zero Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css?v=2">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css">
</head>

<body>

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content" style="background-color: transparent;">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="page-content" style="padding-top: 1rem;">
            <?php if ($msg): ?>
                <div
                    style="padding: 1rem; margin-bottom: 1.5rem; border-radius: 12px; font-weight: 600; <?php echo $msg_type === 'success' ? 'background-color: #ECFDF5; color: #047857; border: 1px solid #A7F3D0;' : 'background-color: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA;'; ?> box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between;">
                    <div><?= htmlspecialchars($msg) ?></div>
                    <button onclick="this.parentElement.style.display='none'"
                        style="background: none; border: none; color: inherit; cursor: pointer; font-size: 1.25rem;">&times;</button>
                </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <div style="color: var(--text-primary); font-weight: 800; font-size: 1.25rem;">
                    ชื่อหน่วยงาน : <?= htmlspecialchars($affiliation_name ?? 'ADMIN(คณะ)') ?>
                </div>

                <?php if ($role === 'admin'): ?>
                    <div style="display: flex; gap: 12px;">
                        <button onclick="openModal('modal-add-year')" class="btn-primary"
                            style="background: #10B981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); display: flex; align-items: center; gap: 8px; border: none;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <line x1="12" y1="14" x2="12" y2="18"></line>
                                <line x1="10" y1="16" x2="14" y2="16"></line>
                            </svg>
                            เพิ่มปี
                        </button>
                        <button onclick="openModal('modal-add-scope')" class="btn-primary"
                            style="background: #6B7280; box-shadow: 0 4px 12px rgba(107, 114, 128, 0.2); display: flex; align-items: center; gap: 8px; border: none;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                            </svg>
                            เพิ่มขอบเขต
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Big Card -->
            <div
                style="background: #FFFFFF; border: 1px solid #D8B4E2; border-radius: 16px; padding: 32px; box-shadow: 0 4px 10px rgba(0,0,0,0.02);">
                <h3
                    style="color: #6B7280; font-size: 1.25rem; font-weight: 600; margin-bottom: 24px; border-bottom: 2px solid #F3F4F6; padding-bottom: 16px;">
                    รายงานการปล่อยและการดูดกลับก๊าซเรือนกระจก
                </h3>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;">
                    <?php foreach ($years as $y): ?>
                        <!-- Sub Card -->
                        <div
                            style="border: 1px solid #E1CBAF; border-radius: 20px; padding: 24px; text-align: center; background: #FFFFFF; box-shadow: 0 4px 12px rgba(0,0,0,0.03); position: relative;">

                            <!-- Action Buttons (Top Right) -->
                            <div style="position: absolute; top: 12px; right: 12px; display: flex; gap: 8px;">
                                <!-- Copy Year Button -->
                                <button onclick="openCopyYearModal(<?= $y['id'] ?>, '<?= $y['year'] ?>')"
                                    style="background: linear-gradient(135deg, #60A5FA 0%, #3B82F6 100%); color: white; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s;"
                                    title="คัดลอกข้อมูลจากปีอื่น"
                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 14px rgba(59,130,246,0.4)'"
                                    onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                        <path
                                            d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z" />
                                    </svg>
                                </button>
                                <!-- Edit Year Button -->
                                <button onclick="openEditYearModal(<?= $y['id'] ?>, '<?= $y['year'] ?>')"
                                    style="background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); color: white; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s;"
                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 14px rgba(245,158,11,0.4)'"
                                    onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                        <path
                                            d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
                                    </svg>
                                </button>
                                <!-- Delete Year Button -->
                                <form method="POST" style="margin:0;" id="deleteYearForm_<?= $y['id'] ?>">
                                    <input type="hidden" name="action" value="delete_year">
                                    <input type="hidden" name="year_id" value="<?= $y['id'] ?>">
                                    <button type="button"
                                        onclick="openConfirmDelete(document.getElementById('deleteYearForm_<?= $y['id'] ?>'), 'ยืนยันการลบปีงบประมาณ <?= $y['year'] ?>? \nรายการและข้อมูลทั้งหมดในปีนี้จะถูกลบถาวร!')"
                                        style="background: #F87171; color: white; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s;"
                                        onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 14px rgba(248,113,113,0.45)'"
                                        onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                            <path
                                                d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" />
                                        </svg>
                                    </button>
                                </form>
                            </div>

                            <div
                                style="color: #6B7280; font-size: 1.1rem; font-weight: 500; margin-bottom: 8px; margin-top: 30px;">
                                การปล่อยก๊าซเรือนกระจก</div>
                            <div style="color: #374151; font-size: 1.5rem; font-weight: 700; margin-bottom: 20px;">
                                ปี <?= htmlspecialchars($y['year']) ?>
                            </div>

                            <div
                                style="background: #F5F1EE; border-radius: 12px; padding: 20px; color: #374151; font-weight: 700; font-size: 1.5rem; margin-bottom: 24px;">
                                <?= number_format($y['total_emission'] ?? 0, 2) ?>
                                <span style="font-size: 1rem; font-weight: 500; color: #6B7280;">tCO2e</span>
                            </div>

                            <a href="data_entry.php?year=<?= $y['id'] ?>"
                                style="display: inline-block; background: #FBB03B; color: #FFFFFF; text-decoration: none; font-weight: 700; padding: 12px 48px; border-radius: 9999px; font-size: 1rem; box-shadow: 0 4px 6px rgba(251, 191, 36, 0.2); transition: all 0.2s;"
                                onmouseover="this.style.background='#F59E0B'; this.style.transform='translateY(-2px)'"
                                onmouseout="this.style.background='#FBB03B'; this.style.transform='none'">
                                แก้ไขข้อมูล
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Modal: เพิ่ม item -->
        <div class="modal-overlay" id="modal-add">
            <div class="modal-box" style="max-width: 600px; height: auto;">
                <div class="modal-title">➕ เพิ่มรายการ Emission Factor</div>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="form-group-dark">
                            <label class="form-label-dark">ปี *</label>
                            <select name="year_id" class="form-control-dark" required>
                                <?php foreach ($years as $y): ?>
                                    <option value="<?= $y['id'] ?>" <?= $y['id'] === $selected_year ? 'selected' : '' ?>>ปี
                                        <?= $y['year'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group-dark">
                            <label class="form-label-dark">Scope Group *</label>
                            <select name="scope" class="form-control-dark" required>
                                <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>">[Scope <?= $g['scope'] ?>]
                                        <?= htmlspecialchars($g['name_tiem']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group-dark" style="margin-bottom:0.75rem;">
                        <label class="form-label-dark">ชื่อรายการ *</label>
                        <input type="text" name="name_tiem" class="form-control-dark" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group-dark">
                            <label class="form-label-dark">หน่วย</label>
                            <input type="text" name="unit" class="form-control-dark" placeholder="เช่น kWh, L">
                        </div>
                        <div class="form-group-dark">
                            <label class="form-label-dark">Activity Data (AD) *</label>
                            <input type="number" name="AD" step="0.0001" class="form-control-dark" required
                                placeholder="0.0000">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('modal-add')"
                            style="background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                        <button type="submit" class="btn-primary"
                            style="background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: เพิ่มปี -->
        <div class="modal-overlay" id="modal-add-year">
            <div class="modal-box">
                <div class="modal-title">➕ เพิ่มปีงบประมาณ</div>

                <form method="POST" id="formAddYear">
                    <input type="hidden" name="action" value="add_year">
                    <div class="form-group-dark" style="margin-bottom:0.75rem;">
                        <label class="form-label-dark">ปี (พ.ศ.) *</label>

                        <?php
                        $current_year_th = (int) date('Y') + 543;
                        $existing_years = array_column($years, 'year');

                        $available_years = [];
                        for ($i = $current_year_th; $i >= $current_year_th - 10; $i--) {
                            if (!in_array($i, $existing_years)) {
                                $available_years[] = $i;
                            }
                        }

                        // ── เรียกใช้ Dropdown component ──
                        $dd_id = 'yearSelectAddYear';
                        $dd_name = 'new_year';
                        $dd_options = $available_years; // [2567, 2568, ...]
                        $dd_selected = '';
                        $dd_placeholder = '-- เลือกปีงบประมาณ --';
                        $dd_required = true;
                        $dd_disabled = count($available_years) === 0;
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('modal-add-year')"
                            style="background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                        <button type="submit" class="btn-primary" <?= count($available_years) > 0 ? '' : 'disabled' ?>
                            style="background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">บันทึก</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: เพิ่ม Scope -->
        <div class="modal-overlay" id="modal-add-scope">
            <div class="modal-box" style="max-width: 800px;">
                <div class="modal-title">➕ เพิ่มขอบเขต</div>

                <div style="margin-bottom: 1.5rem; max-height: 240px; overflow-y: auto; padding-right: 6px;"
                    class="scope-list-container" id="scope-list-wrapper">
                    <style>
                        .scope-list-container::-webkit-scrollbar {
                            width: 6px;
                        }

                        .scope-list-container::-webkit-scrollbar-track {
                            background: #F3F4F6;
                            border-radius: 4px;
                        }

                        .scope-list-container::-webkit-scrollbar-thumb {
                            background: #D1D5DB;
                            border-radius: 4px;
                        }

                        .scope-list-container::-webkit-scrollbar-thumb:hover {
                            background: #9CA3AF;
                        }
                    </style>
                    <div style="font-weight: 600; color: #6B7280; font-size: 0.95rem; margin-bottom: 0.75rem;">
                        ขอบเขตที่มีอยู่แล้ว</div>
                    <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                        <?php foreach ($groups as $g): ?>
                            <?php
                            $bg = '#F3F4F6';
                            if ($g['scope'] == 1)
                                $bg = '#FFF8EB';
                            elseif ($g['scope'] == 2)
                                $bg = '#FDF0F4';
                            elseif ($g['scope'] == 3)
                                $bg = '#ECF3F9';
                            ?>
                            <div class="scope-row"
                                style="background-color: <?= $bg ?>; padding: 0.6rem 1rem; border-radius: 6px; font-size: 0.95rem; color: #4B5563; border: 1px solid rgba(0,0,0,0.02); display: flex; justify-content: space-between; align-items: center; position: relative;">
                                <div>ขอบเขตที่ <?= htmlspecialchars($g['scope']) ?>
                                    <?= htmlspecialchars($g['name_tiem']) ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <form method="POST" class="move-form"
                                        style="margin: 0; display: flex; align-items: center;">
                                        <input type="hidden" name="action" value="move_scope">
                                        <input type="hidden" name="scope_id" value="<?= $g['id'] ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit"
                                            style="background: none; border: none; color: #4B5563; cursor: pointer; padding: 4px; border-radius: 4px; transition: background 0.2s; display: flex; align-items: center; justify-content: center;"
                                            onmouseover="this.style.backgroundColor='rgba(0,0,0,0.05)'"
                                            onmouseout="this.style.backgroundColor='transparent'" title="เลื่อนขึ้น">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m5 12 7-7 7 7" />
                                                <path d="M12 19V5" />
                                            </svg>
                                        </button>
                                    </form>
                                    <form method="POST" class="move-form"
                                        style="margin: 0; display: flex; align-items: center;">
                                        <input type="hidden" name="action" value="move_scope">
                                        <input type="hidden" name="scope_id" value="<?= $g['id'] ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit"
                                            style="background: none; border: none; color: #4B5563; cursor: pointer; padding: 4px; border-radius: 4px; transition: background 0.2s; display: flex; align-items: center; justify-content: center;"
                                            onmouseover="this.style.backgroundColor='rgba(0,0,0,0.05)'"
                                            onmouseout="this.style.backgroundColor='transparent'" title="เลื่อนลง">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 5v14" />
                                                <path d="m19 12-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </form>
                                    <div style="width: 1px; height: 16px; background-color: #D1D5DB; margin: 0 4px;"></div>
                                    <form method="POST" style="margin:0;" id="deleteScopeGroupForm_<?= $g['id'] ?>">
                                        <input type="hidden" name="action" value="delete_scope">
                                        <input type="hidden" name="scope_id" value="<?= $g['id'] ?>">
                                        <button type="button" class="btn-icon-delete"
                                            onclick="openConfirmDelete(document.getElementById('deleteScopeGroupForm_<?= $g['id'] ?>'), 'ยืนยันการลบกลุ่มขอบเขต: <?= htmlspecialchars($g['name_tiem']) ?>?')"
                                            title="ลบกลุ่มนี้">
                                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <path
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($groups)): ?>
                            <div style="text-align: center; color: #9CA3AF; padding: 1rem 0; font-size: 0.9rem;">
                                ยังไม่มีข้อมูลขอบเขต</div>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" id="ajax-add-scope-form"
                    style="border-top: 1px dashed #D1D5DB; padding-top: 1.5rem;">
                    <input type="hidden" name="action" value="add_scope">
                    <div class="form-group-dark" style="margin-bottom:0.75rem;">
                        <label class="form-label-dark">เลือกขอบเขต *</label>
                        <?php
                        $dd_id = 'addScopeDropdown';
                        $dd_name = 'scope';
                        $dd_options = [
                            ['value' => 1, 'label' => 'ขอบเขต 1'],
                            ['value' => 2, 'label' => 'ขอบเขต 2'],
                            ['value' => 3, 'label' => 'ขอบเขต 3'],
                        ];
                        $dd_selected = '';
                        $dd_placeholder = '-- เลือกขอบเขต --';
                        $dd_required = true;
                        $dd_disabled = false;
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="form-group-dark" style="margin-bottom:0.75rem;">
                        <label class="form-label-dark">ชื่อประเภทการปล่อยก๊าซ *</label>
                        <input type="text" name="name_tiem" class="form-control-dark" required
                            placeholder="เช่น ไฟฟ้า, น้ำมัน">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('modal-add-scope')"
                            style="background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                        <button type="submit" class="btn-primary"
                            style="background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">บันทึก</button>
                    </div>
                </form>

                <script>
                    function attachScopeListeners() {
                        document.querySelectorAll('.move-form').forEach(form => {
                            if (form.dataset.bound) return;
                            form.dataset.bound = 'true';
                            form.addEventListener('submit', async function (e) {
                                e.preventDefault();
                                const formData = new FormData(this);
                                const direction = formData.get('direction');

                                const currentRow = this.closest('.scope-row');
                                const targetRow = direction === 'up' ? currentRow.previousElementSibling : currentRow.nextElementSibling;

                                if (!targetRow || !targetRow.classList.contains('scope-row')) return;

                                const currentRect = currentRow.getBoundingClientRect();
                                const targetRect = targetRow.getBoundingClientRect();
                                const delta = targetRect.top - currentRect.top;

                                if (direction === 'up') {
                                    currentRow.parentNode.insertBefore(currentRow, targetRow);
                                } else {
                                    currentRow.parentNode.insertBefore(targetRow, currentRow);
                                }

                                currentRow.style.transition = 'none';
                                targetRow.style.transition = 'none';
                                currentRow.style.transform = `translateY(${-delta}px)`;
                                targetRow.style.transform = `translateY(${delta}px)`;

                                currentRow.offsetHeight;

                                currentRow.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)';
                                targetRow.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)';
                                currentRow.style.transform = '';
                                targetRow.style.transform = '';

                                formData.append('ajax', '1');
                                await fetch(location.href, { method: 'POST', body: formData });
                            });
                        });
                    }

                    function updateScopeListWrapper(html) {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const wrapperId = 'scope-list-wrapper';
                        const newWrapper = doc.getElementById(wrapperId);
                        const currentWrapper = document.getElementById(wrapperId);

                        if (newWrapper && currentWrapper) {
                            currentWrapper.innerHTML = newWrapper.innerHTML;
                            attachScopeListeners();
                        }
                    }

                    attachScopeListeners();

                    const addForm = document.getElementById('ajax-add-scope-form');
                    if (addForm) {
                        addForm.addEventListener('submit', async function (e) {
                            e.preventDefault();

                            const scopeInput = document.getElementById('addScopeDropdown_input');
                            if (scopeInput && !scopeInput.value) {
                                alert('กรุณาเลือกขอบเขต');
                                return;
                            }

                            const btn = this.querySelector('button[type="submit"]');
                            if (btn) btn.disabled = true;

                            const formData = new FormData(this);
                            const response = await fetch(location.href, { method: 'POST', body: formData });
                            const html = await response.text();

                            updateScopeListWrapper(html);
                            this.reset();
                            ddReset('addScopeDropdown');

                            if (btn) btn.disabled = false;

                            setTimeout(() => {
                                const wrapper = document.getElementById('scope-list-wrapper');
                                if (wrapper) wrapper.scrollTop = wrapper.scrollHeight;
                            }, 50);
                        });
                    }
                </script>
            </div>
        </div>

        <!-- Modal: แก้ไข item -->
        <div class="modal-overlay" id="modal-edit">
            <div class="modal-box">
                <div class="modal-title">✏️ แก้ไขรายการ Emission Factor</div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="item_id" id="edit-item-id">
                    <div class="form-group-dark" style="margin-bottom:0.75rem;">
                        <label class="form-label-dark">Scope Group *</label>
                        <select name="scope" id="edit-scope" class="form-control-dark" required>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>">[Scope <?= $g['scope'] ?>]
                                    <?= htmlspecialchars($g['name_tiem']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-dark" style="margin-bottom:0.75rem;">
                        <label class="form-label-dark">ชื่อรายการ *</label>
                        <input type="text" name="name_tiem" id="edit-name" class="form-control-dark" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group-dark">
                            <label class="form-label-dark">หน่วย</label>
                            <input type="text" name="unit" id="edit-unit" class="form-control-dark">
                        </div>
                        <div class="form-group-dark">
                            <label class="form-label-dark">Activity Data (AD) *</label>
                            <input type="number" name="AD" id="edit-ad" step="0.0001" class="form-control-dark"
                                required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('modal-edit')"
                            style="background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                        <button type="submit" class="btn-primary"
                            style="background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">บันทึกการเปลี่ยนแปลง</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Edit Year -->
        <div id="modalEditYear" class="modal-overlay">
            <div class="modal-box">
                <div class="modal-title">✏️ แก้ไขปีงบประมาณ</div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_year">
                    <input type="hidden" name="year_id" id="edit_year_id">
                    <div class="form-group-dark">
                        <label class="form-label-dark">ปีงบประมาณ (พ.ศ.)</label>
                        <?php
                        $current_year_th = (int) date('Y') + 543;

                        // สร้างรายการปีที่เลือกได้ (ปีปัจจุบัน+2 ถึง ปีปัจจุบัน-10)
                        // หมายเหตุ: ไม่กรอง $existing_years ออก เพราะปีปัจจุบันของแถวที่กำลังแก้ไข
                        // ต้องอยู่ในลิสต์ด้วยเสมอ (ระบบจัดการปีซ้ำด้วย modal "สลับปี" อยู่แล้ว)
                        $edit_year_options = [];
                        for ($i = $current_year_th + 2; $i >= $current_year_th - 10; $i--) {
                            $edit_year_options[] = $i;
                        }

                        // ── เรียกใช้ Dropdown component ──
                        $dd_id = 'editYearDropdown';
                        $dd_name = 'year_val';
                        $dd_options = $edit_year_options;
                        $dd_selected = '';
                        $dd_placeholder = '-- เลือกปีงบประมาณ --';
                        $dd_required = true;
                        $dd_disabled = false;
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('modalEditYear')"
                            style="background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                        <button type="submit" class="btn-primary"
                            style="background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Confirm Delete -->
        <div id="modalConfirmDelete" class="modal-overlay">
            <div class="modal-box" style="max-width: 400px; text-align: center;">
                <div
                    style="background: #FEE2E2; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                    <svg viewBox="0 0 24 24" width="32" height="32" fill="#EF4444">
                        <path
                            d="M11 15h2v2h-2zm0-8h2v6h-2zm.99-5C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z" />
                    </svg>
                </div>
                <h3
                    style="font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem; color: #111827; letter-spacing: -0.025em;">
                    ยืนยันการลบ?
                </h3>
                <p id="confirmDeleteMsg"
                    style="color: #6B7280; margin-bottom: 2rem; font-size: 0.95rem; line-height: 1.5;">
                    รายการนี้จะถูกลบออกถาวรและไม่สามารถกู้คืนได้</p>

                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('modalConfirmDelete')"
                        style="flex: 1; background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                    <button type="button" id="confirmDeleteBtn" class="btn-danger" style="flex: 1;">ลบรายการ</button>
                </div>
            </div>
        </div>

        <!-- Modal: Confirm Swap Years -->
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'duplicate_year'): ?>
            <div id="modalConfirmSwap" class="modal-overlay" style="display: flex;">
                <div class="modal-box" style="max-width: 450px; text-align: center;">
                    <div
                        style="background: #FEF3C7; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="#F59E0B">
                            <path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z" />
                        </svg>
                    </div>
                    <div class="modal-title" style="justify-content: center; border: none; margin-bottom: 0.5rem;">
                        ปีงบประมาณซ้ำ</div>
                    <p style="color: #6B7280; margin-bottom: 2rem;">
                        ปี <strong><?= htmlspecialchars($_GET['y2']) ?></strong> มีอยู่ในระบบแล้ว <br>
                        คุณต้องการทำการ <strong>"สลับข้อมูล"</strong> ระหว่างปี <br>
                        <strong><?= htmlspecialchars($_GET['y1']) ?></strong> กับ
                        <strong><?= htmlspecialchars($_GET['y2']) ?></strong> หรือไม่?
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="swap_years">
                        <input type="hidden" name="id1" value="<?= (int) $_GET['id1'] ?>">
                        <input type="hidden" name="id2" value="<?= (int) $_GET['id2'] ?>">
                        <input type="hidden" name="y1" value="<?= (int) $_GET['y1'] ?>">
                        <input type="hidden" name="y2" value="<?= (int) $_GET['y2'] ?>">
                        <div style="display: flex; gap: 12px;">
                            <button type="button" class="btn-secondary"
                                onclick="location.href='items.php?year=<?= $selected_year ?>'"
                                style="flex: 1; background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                            <button type="submit" class="btn-primary"
                                style="flex: 1; background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">ใช่,
                                สลับข้อมูลปี</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal: คัดลอก admin_item จากปีอื่น -->
        <div id="modalCopyYear" class="modal-overlay">
            <div class="modal-box" style="max-width: 480px;">
                <div class="modal-title">📋 คัดลอกรายการจากปีอื่น</div>
                <form method="POST">
                    <input type="hidden" name="action" value="copy_year">
                    <input type="hidden" name="target_year_id" id="copy_target_year_id">

                    <div
                        style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 10px; padding: 14px 16px; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 10px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="#3B82F6"
                            style="flex-shrink:0; margin-top:1px;">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" />
                        </svg>
                        <p style="color: #1D4ED8; font-size: 0.85rem; line-height: 1.5; margin: 0;">
                            ระบบจะ<strong>คัดลอกรายการ Emission Factor ทั้งหมด</strong> จากปีต้นทางมายังปี <strong
                                id="copy_target_year_label">-</strong><br>
                            รายการที่มีอยู่แล้วในปีปลายทางจะ<strong>ไม่ถูกเขียนทับ</strong> (INSERT IGNORE)
                        </p>
                    </div>

                    <div class="form-group-dark" style="margin-bottom:1.5rem;">
                        <label class="form-label-dark">เลือกปีที่ต้องการคัดลอกรายการมาจาก (ต้นทาง) *</label>
                        <?php
                        $dd_id = 'copySourceYear';
                        $dd_name = 'source_year_id';
                        $dd_placeholder = '-- เลือกปีต้นทาง --';
                        $dd_options = [];
                        foreach ($years as $cy) {
                            $dd_options[] = ['value' => $cy['id'], 'label' => 'ปีงบประมาณ ' . $cy['year']];
                        }
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('modalCopyYear')"
                            style="background: transparent; border: 2px solid #6B7280; color: #6B7280;">ยกเลิก</button>
                        <button type="submit" class="btn-primary"
                            style="background: #FBB03B; color: white; border: none; box-shadow: 0 4px 12px rgba(251, 176, 59, 0.2);">คัดลอกรายการ</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            var formToSubmit = null;   // var (ไม่ใช่ let) เพื่อให้ SPA re-run script ได้โดยไม่ error ประกาศซ้ำ

            function openConfirmDelete(form, msg) {
                formToSubmit = form;
                if (msg) document.getElementById('confirmDeleteMsg').innerText = msg;
                document.getElementById('modalConfirmDelete').style.display = 'flex';
            }

            document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
                if (formToSubmit) formToSubmit.submit();
            });

            function openEditYearModal(id, year) {
                document.getElementById('edit_year_id').value = id;
                ddSetValue('editYearDropdown', year, year);
                document.getElementById('modalEditYear').style.display = 'flex';
            }

            function openCopyYearModal(targetId, targetYear) {
                document.getElementById('copy_target_year_id').value = targetId;
                document.getElementById('copy_target_year_label').textContent = targetYear;

                // reset ค่า dropdown ต้นทาง กลับเป็น placeholder
                const ddInput = document.getElementById('copySourceYear_input');
                const ddLabel = document.getElementById('copySourceYear_label');
                if (ddInput) ddInput.value = '';
                if (ddLabel) {
                    ddLabel.textContent = '-- เลือกปีต้นทาง --';
                    ddLabel.style.color = '#9CA3AF';
                }

                // ซ่อนปีปลายทางออกจากตัวเลือก + ล้างสถานะ active
                document.querySelectorAll('#copySourceYear_menu .dd-option').forEach(opt => {
                    opt.classList.remove('active');
                    opt.style.display = (opt.dataset.value == targetId) ? 'none' : '';
                });

                openModal('modalCopyYear');
            }

            function openModal(id) { document.getElementById(id).classList.add('open'); document.getElementById(id).style.display = 'flex'; }
            function closeModal(id) { document.getElementById(id).classList.remove('open'); document.getElementById(id).style.display = 'none'; }
            document.querySelectorAll('.modal-overlay').forEach(el => {
                el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
            });
            function openEditModal(item) {
                document.getElementById('edit-item-id').value = item.id;
                document.getElementById('edit-scope').value = item.scope;
                document.getElementById('edit-name').value = item.name_tiem;
                document.getElementById('edit-unit').value = item.unit || '';
                document.getElementById('edit-ad').value = item.AD;
                openModal('modal-edit');
            }
        </script>

    </main>

    <script src="<?= $root ?>assets/js/session-timer.js"></script>
</body>

</html>