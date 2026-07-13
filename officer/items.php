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

require_role(['officer']);

$pdo = getDB();
$root = '../';
$affil_id = (int) ($_SESSION['affiliation_id'] ?? 0);
$role = $_SESSION['role'];
$msg = $_GET['msg'] ?? '';
$msg_type = $_GET['msg_type'] ?? 'success';

if ($affil_id <= 0) {
    $msg = "บัญชีของคุณยังไม่ได้ระบุหน่วยงาน (Affiliation) กรุณาติดต่อ Admin เพื่อระบุหน่วยงานก่อนเริ่มใช้งาน";
    $msg_type = 'danger';
}

$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$affiliation_name = $_SESSION['affiliation_name'] ?? 'หน่วยงานทั่วไป';

// ── Handle POST for Creating Year (Must be BEFORE Fetching data to avoid stale view) ─────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user_year') {
    if ($affil_id <= 0) {
        header("Location: items.php?msg=" . urlencode("ไม่สามารถสร้างปีได้ เนื่องจากบัญชีของคุณไม่มีหน่วยงาน") . "&msg_type=danger");
        exit;
    }

    $year_to_create = (int) $_POST['year_id'];

    // Check if items exist in Admin settings for this year
    $check_stmt = $pdo->prepare('SELECT COUNT(*) FROM admin_item WHERE year_id = ? AND data_source = \x27officer\x27');
    $check_stmt->execute([$year_to_create]);
    $item_count = (int) $check_stmt->fetchColumn();

    if ($item_count === 0) {
        header("Location: items.php?msg=" . urlencode("ไม่พบรายการกิจกรรมที่ Admin กำหนดในปีนี้ กรุณาแจ้ง Admin ให้เพิ่มรายการก่อน") . "&msg_type=danger");
        exit;
    }

    // Pull data
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO user_item (admin_item_id, affiliation_id, year_id, Vol)
        SELECT id, :affil, :year_id1, 0 
        FROM admin_item 
        WHERE year_id = :year_id2 AND data_source = \x27officer\x27
    ');
    $stmt->execute([':affil' => $affil_id, ':year_id1' => $year_to_create, ':year_id2' => $year_to_create]);

    header("Location: items.php?year=$year_to_create&created_success=1&count=$item_count");
    exit;
}

// ── Handle POST for Editing User Year ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user_year') {
    $target_id = (int) $_POST['year_id'];     // The year ID being edited (current)
    $new_year_id = (int) $_POST['new_year_id']; // The ID of the year we want to change it to

    // Check if the user already has data for the NEW year
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_item WHERE year_id = :y AND affiliation_id = :affil');
    $stmt->execute([':y' => $new_year_id, ':affil' => $affil_id]);
    $has_existing = (int) $stmt->fetchColumn();

    if ($has_existing > 0) {
        // Trigger Swap Flow (Redirect with swap params)
        $y1_val = $pdo->query("SELECT year FROM admin_year WHERE id = $target_id")->fetchColumn();
        $y2_val = $pdo->query("SELECT year FROM admin_year WHERE id = $new_year_id")->fetchColumn();
        header("Location: items.php?msg=duplicate_year&id1=$target_id&id2=$new_year_id&y1=$y1_val&y2=$y2_val");
        exit;
    } else {
        // Simple Update
        $stmt = $pdo->prepare('UPDATE user_item SET year_id = :new_y WHERE year_id = :old_y AND affiliation_id = :affil');
        $stmt->execute([':new_y' => $new_year_id, ':old_y' => $target_id, ':affil' => $affil_id]);
        header("Location: items.php?year=$new_year_id&msg=" . urlencode("แก้ไขปีงบประมาณเรียบร้อยแล้ว"));
        exit;
    }
}

// ── Handle POST for Swapping User Years ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swap_user_years') {
    $id1 = (int) $_POST['id1'];
    $id2 = (int) $_POST['id2'];

    try {
        $pdo->beginTransaction();
        // Step 1: Set Year 1 to a temp value (-1)
        $stmt1 = $pdo->prepare('UPDATE user_item SET year_id = -1 WHERE year_id = :id AND affiliation_id = :affil');
        $stmt1->execute([':id' => $id1, ':affil' => $affil_id]);

        // Step 2: Set Year 2 to Year 1
        $stmt2 = $pdo->prepare('UPDATE user_item SET year_id = :y1 WHERE year_id = :id2 AND affiliation_id = :affil');
        $stmt2->execute([':y1' => $id1, ':id2' => $id2, ':affil' => $affil_id]);

        // Step 3: Set temp (-1) to Year 2
        $stmt3 = $pdo->prepare('UPDATE user_item SET year_id = :y2 WHERE year_id = -1 AND affiliation_id = :affil');
        $stmt3->execute([':y2' => $id2, ':affil' => $affil_id]);

        $pdo->commit();
        header("Location: items.php?year=$id1&msg=" . urlencode("สลับข้อมูลปีงบประมาณเรียบร้อยแล้ว"));
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: items.php?msg=" . urlencode("เกิดข้อผิดพลาด: " . $e->getMessage()) . "&msg_type=danger");
        exit;
    }
}

// ── Handle POST for Deleting User Year ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user_year') {
    $year_to_delete = (int) $_POST['year_id'];

    // Delete all records for this affiliation and year
    $stmt = $pdo->prepare('DELETE FROM user_item WHERE affiliation_id = ? AND year_id = ?');
    $stmt->execute([$affil_id, $year_to_delete]);

    header("Location: items.php?msg=" . urlencode("ลบข้อมูลปีงบประมาณเรียบร้อยแล้ว") . "&msg_type=success");
    exit;
}

// ── Handle POST for Copying User Year Data ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_user_year') {
    $target_year_id = (int) $_POST['target_year_id']; // ปีที่จะรับข้อมูล (ปลายทาง)
    $source_year_id = (int) $_POST['source_year_id']; // ปีที่คัดลอกจาก (ต้นทาง)

    if ($target_year_id <= 0 || $source_year_id <= 0 || $target_year_id === $source_year_id) {
        header("Location: items.php?msg=" . urlencode("ข้อมูลไม่ถูกต้อง") . "&msg_type=danger");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // ดึง admin_item_id และ Vol ของปีต้นทาง
        $src_stmt = $pdo->prepare('
            SELECT ui.admin_item_id, ui.Vol
            FROM user_item ui
            WHERE ui.year_id = :src_year AND ui.affiliation_id = :affil
        ');
        $src_stmt->execute([':src_year' => $source_year_id, ':affil' => $affil_id]);
        $src_rows = $src_stmt->fetchAll();

        // อัปเดต Vol ของปีปลายทาง โดยจับคู่ผ่าน admin_item_id ที่ตรงกัน
        $upd_stmt = $pdo->prepare('
            UPDATE user_item
            SET Vol = :vol
            WHERE affiliation_id = :affil AND year_id = :tgt_year AND admin_item_id = :item_id
        ');

        $copied = 0;
        foreach ($src_rows as $row) {
            $upd_stmt->execute([
                ':vol' => $row['Vol'],
                ':affil' => $affil_id,
                ':tgt_year' => $target_year_id,
                ':item_id' => $row['admin_item_id'],
            ]);
            $copied += $upd_stmt->rowCount();
        }

        $pdo->commit();
        header("Location: items.php?year=$target_year_id&msg=" . urlencode("คัดลอกข้อมูลสำเร็จ ($copied รายการ)") . "&msg_type=success");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: items.php?msg=" . urlencode("เกิดข้อผิดพลาด: " . $e->getMessage()) . "&msg_type=danger");
        exit;
    }
}

// ── Fetching Data (Moved below POST handling to ensure fresh data) ──────────

// Available Years for Creation (Admin years not yet active for this user)
$avail_years_sql = '
    SELECT id, year FROM admin_year 
    WHERE id NOT IN (SELECT DISTINCT year_id FROM user_item WHERE affiliation_id = :affil)
    ORDER BY year DESC
';
$stmt_avail = $pdo->prepare($avail_years_sql);
$stmt_avail->execute([':affil' => $affil_id]);
$available_years = $stmt_avail->fetchAll();

// Only show years that have at least one record in user_item for this affiliation
$fetch_years_sql = "
    SELECT y.id, y.year, 
           (SELECT COALESCE(SUM(ui.Vol * ai.AD)/1000, 0) 
            FROM user_item ui 
            JOIN admin_item ai ON ai.id = ui.admin_item_id 
            WHERE ui.year_id = y.id AND ui.affiliation_id = :affil1) AS total_emission
    FROM admin_year y
    WHERE EXISTS (SELECT 1 FROM user_item WHERE year_id = y.id AND affiliation_id = :affil2)
    ORDER BY y.year DESC
";
$stmt_years = $pdo->prepare($fetch_years_sql);
$stmt_years->execute([':affil1' => $affil_id, ':affil2' => $affil_id]);
$years = $stmt_years->fetchAll();

// Auto-select first year if none selected
if ($selected_year <= 0 && !empty($years)) {
    $selected_year = $years[0]['id'];
}


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
    <title>จัดการ Scope Items — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
</head>

<body>

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content" style="background-color: transparent;">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="page-content" style="padding-top: 1rem;">
            <?php if ($msg && $msg_type === 'danger'): ?>
                <div
                    style="padding: 1rem; margin-bottom: 1.5rem; border-radius: 12px; font-weight: 600; background-color: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between;">
                    <div><?= htmlspecialchars($msg) ?></div>
                    <button onclick="this.parentElement.style.display='none'"
                        style="background: none; border: none; color: inherit; cursor: pointer; font-size: 1.25rem;">&times;</button>
                </div>
            <?php endif; ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <div style="color: var(--text-primary); font-weight: 800; font-size: 1.25rem;">
                    ชื่อหน่วยงาน : <?= htmlspecialchars($affiliation_name ?? 'ADMIN(คณะ)') ?>
                </div>

                <button onclick="openModal('modal-create-year')" class="btn-primary"
                    style="background: var(--clr-success); box-shadow: 0 6px 15px rgba(16,185,129,0.2);">
                    สร้างปี
                </button>


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
                            style="border: 1px solid #E1CBAF; border-radius: 20px; padding: 32px; text-align: center; background: #FFFFFF; box-shadow: 0 4px 12px rgba(0,0,0,0.03); position: relative;">

                            <!-- Action Buttons (Top Right) -->
                            <div style="position: absolute; top: 12px; right: 12px; display: flex; gap: 8px;">
                                <!-- Edit Year Button (Change/Swap) -->
                                <button onclick="openEditYearModal(<?= $y['id'] ?>, '<?= $y['year'] ?>')"
                                    style="background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); color: white; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s;"
                                    title="เปลี่ยนปีงบประมาณของข้อมูล"
                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 14px rgba(245,158,11,0.4)'"
                                    onmouseout="this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                        <path
                                            d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
                                    </svg>
                                </button>
                                <!-- Delete Year Button -->
                                <form method="POST" style="margin:0;" id="deleteYearForm_<?= $y['id'] ?>"
                                    action="items.php">
                                    <input type="hidden" name="action" value="delete_user_year">
                                    <input type="hidden" name="year_id" value="<?= $y['id'] ?>">
                                    <button type="button"
                                        onclick="openConfirmDeleteByYear(<?= $y['id'] ?>, '<?= $y['year'] ?>')"
                                        style="background: #F87171; color: white; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s;"
                                        title="ลบข้อมูลปีนี้"
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
                                style="color: #6B7280; font-size: 1.1rem; font-weight: 500; margin-bottom: 8px; margin-top: 10px;">
                                การปล่อยก๊าซเรือนกระจก</div>
                            <div style="color: #374151; font-size: 1.5rem; font-weight: 700; margin-bottom: 20px;">
                                ปี <?= htmlspecialchars($y['year']) ?>
                            </div>

                            <div
                                style="background: #F5F1EE; border-radius: 12px; padding: 20px; color: #374151; font-weight: 700; font-size: 1.5rem; margin-bottom: 24px;">
                                <?= rtrim(rtrim(number_format($y['total_emission'] ?? 0, 4, '.', ','), '0'), '.') ?>
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

        <!-- Modal: สร้างปีงบประมาณ (Create Year) -->
        <div class="modal-overlay" id="modal-create-year">
            <div class="modal-box" style="max-width: 450px;">
                <div class="modal-title">➕ สร้างปีงบประมาณจัดทำ</div>
                <form action="items.php" method="POST">
                    <input type="hidden" name="action" value="create_user_year">
                    <div class="form-group-dark" style="margin-bottom:1.5rem;">
                        <label class="form-label-dark">เลือกปีงบประมาณ (จากระบบ Admin) *</label>
                        <?php
                        // ใช้ component dropdown กลาง (components/dropdown.php) แทน <select> เดิม
                        $dd_id          = 'createYearSelect';
                        $dd_name        = 'year_id';
                        $dd_options     = array_map(fn($ay) => ['value' => $ay['id'], 'label' => $ay['year']], $available_years);
                        $dd_selected    = '';
                        $dd_placeholder = empty($available_years) ? '-- ไม่มีปีงบประมาณใหม่ให้เพิ่ม --' : '-- เลือกปีงบประมาณ --';
                        $dd_required    = true;
                        $dd_class       = 'dd-field';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary"
                            onclick="closeModal('modal-create-year')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" <?= empty($available_years) ? 'disabled' : '' ?>>สร้างปีงบประมาณ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: สร้างสำเร็จ (Success Popup) -->
        <?php if (isset($_GET['created_success'])): ?>
            <div class="modal-overlay" id="modal-success" style="display: flex;">
                <div class="modal-box" style="max-width: 400px; text-align: center; padding: 2.5rem;">
                    <div
                        style="background: #DCFCE7; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; animation: successScale 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
                        <svg viewBox="0 0 24 24" width="40" height="40" stroke="#16A34A" stroke-width="3" fill="none">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                    <h2 style="font-size: 1.5rem; font-weight: 800; color: #111827; margin-bottom: 0.5rem;">
                        สร้างข้อมูลสำเร็จ!</h2>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="data_entry.php?year=<?= (int) $_GET['year'] ?>" class="btn-primary"
                            style="text-decoration: none; padding: 12px;">เริ่มกรอกข้อมูลเลย</a>
                        <button type="button" class="btn-secondary"
                            onclick="closeModal('modal-success')">ปิดหน้าต่าง</button>
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

        <!-- Modal: แก้ไขปี (Change/Swap) -->
        <div id="modalEditYear" class="modal-overlay">
            <div class="modal-box" style="max-width: 450px;">
                <div class="modal-title">✏️ เปลี่ยนปีงบประมาณของข้อมูล</div>
                <form action="items.php" method="POST">
                    <input type="hidden" name="action" value="edit_user_year">
                    <input type="hidden" name="year_id" id="edit_target_year_id">

                    <div class="form-group-dark" style="margin-bottom:1.5rem;">
                        <label class="form-label-dark">ต้องการเปลี่ยนข้อมูลของปี (<span
                                id="edit_target_year_label">-</span>) เป็นปี: *</label>
                        <?php
                        // ใช้ component dropdown กลาง (components/dropdown.php) แทน <select> เดิม
                        $all_admin_years = $pdo->query('SELECT id, year FROM admin_year ORDER BY year DESC')->fetchAll();
                        $dd_id          = 'editYearSelect';
                        $dd_name        = 'new_year_id';
                        $dd_options     = array_map(fn($ay) => ['value' => $ay['id'], 'label' => 'ปีงบประมาณ ' . $ay['year']], $all_admin_years);
                        $dd_selected    = '';
                        $dd_placeholder = '-- เลือกปีงบประมาณใหม่ --';
                        $dd_required    = true;
                        $dd_class       = 'dd-field';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>

                    <p style="font-size: 0.85rem; color: #6B7280; margin-bottom: 1.5rem; line-height: 1.4;">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                            stroke-width="2" style="vertical-align: middle; margin-right: 4px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        หากคุณเลือกปีที่มีข้อมูลอยู่แล้ว ระบบจะถามเพื่อทำการสลับ (Swap) ข้อมูลระหว่างสองปีนั้น
                    </p>

                    <div class="modal-footer">
                        <button type="button" class="btn-secondary"
                            onclick="closeModal('modalEditYear')">ยกเลิก</button>
                        <button type="submit" class="btn-primary">บันทึกการเปลี่ยนปี</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Confirm Swap -->
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'duplicate_year'): ?>
            <div class="modal-overlay" id="modalConfirmSwap" style="display: flex;">
                <div class="modal-box" style="max-width: 450px; text-align: center;">
                    <div
                        style="background: #FEF3C7; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="#F59E0B">
                            <path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z" />
                        </svg>
                    </div>
                    <h3 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem; color: #111827;">
                        สลับข้อมูลปีงบประมาณ?</h3>
                    <p style="color: #6B7280; margin-bottom: 2rem;">
                        ตรวจพบว่าคุณมีข้อมูลของปี <strong><?= htmlspecialchars($_GET['y2']) ?></strong> อยู่แล้ว <br>
                        ต้องการสลับข้อมูลกับปี <strong><?= htmlspecialchars($_GET['y1']) ?></strong> หรือไม่?
                    </p>
                    <form action="items.php" method="POST">
                        <input type="hidden" name="action" value="swap_user_years">
                        <input type="hidden" name="id1" value="<?= (int) $_GET['id1'] ?>">
                        <input type="hidden" name="id2" value="<?= (int) $_GET['id2'] ?>">
                        <div style="display: flex; gap: 12px;">
                            <button type="button" class="btn-secondary" onclick="closeModal('modalConfirmSwap')"
                                style="flex: 1;">ยกเลิก</button>
                            <button type="submit" class="btn-primary"
                                style="flex: 1; background: #F59E0B; border-color: #F59E0B;">ยืนยันการสลับปี</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modal: Confirm Delete User Year -->
        <div id="modalConfirmDeleteYear" class="modal-overlay">
            <div class="modal-box" style="max-width: 400px; text-align: center;">
                <div
                    style="background: #FEE2E2; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                    <svg viewBox="0 0 24 24" width="32" height="32" fill="#EF4444">
                        <path
                            d="M11 15h2v2h-2zm0-8h2v6h-2zm.99-5C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z" />
                    </svg>
                </div>
                <h3 style="font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem; color: #111827;">ยืนยันการลบ?
                </h3>
                <p id="confirmDeleteMsg"
                    style="color: #6B7280; margin-bottom: 2rem; font-size: 0.95rem; line-height: 1.5;">
                    ข้อมูลทั้งหมดของปี <strong id="deleteYearName"></strong> จะถูกลบถาวรและไม่สามารถกู้คืนได้
                </p>

                <div style="display: flex; gap: 12px;">
                    <button type="button" class="btn-secondary" onclick="closeModal('modalConfirmDeleteYear')"
                        style="flex: 1;">ยกเลิก</button>
                    <button type="button" id="confirmDeleteBtn" class="btn-danger" style="flex: 1;"
                        onclick="submitDeleteYear()">ลบข้อมูลปีนี้</button>
                </div>
            </div>
        </div>

        <!-- Modal: คัดลอกข้อมูลจากปีอื่น (Copy Year Data) -->
        <div id="modalCopyYear" class="modal-overlay">
            <div class="modal-box" style="max-width: 480px;">
                <div class="modal-title">📋 คัดลอกข้อมูลจากปีอื่น</div>
                <form action="items.php" method="POST">
                    <input type="hidden" name="action" value="copy_user_year">
                    <input type="hidden" name="target_year_id" id="copy_target_year_id">

                    <div
                        style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 10px; padding: 14px 16px; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 10px;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="#3B82F6"
                            style="flex-shrink:0; margin-top:1px;">
                            <path
                                d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" />
                        </svg>
                        <p style="color: #1D4ED8; font-size: 0.85rem; line-height: 1.5; margin: 0;">
                            ระบบจะ<strong>คัดลอกค่าปริมาณ (Vol)</strong> ทุกรายการจากปีต้นทางมายังปี <strong
                                id="copy_target_year_label">-</strong><br>
                            ข้อมูลเดิมในปีปลายทางจะถูก<strong>แทนที่</strong>เฉพาะรายการที่มีอยู่ในปีต้นทาง
                        </p>
                    </div>

                    <div class="form-group-dark" style="margin-bottom:1.5rem;">
                        <label class="form-label-dark">เลือกปีที่ต้องการคัดลอกข้อมูลมา (ต้นทาง) *</label>
                        <select name="source_year_id" id="copy_source_year_select" class="form-control-dark" required>
                            <option value="" disabled selected>-- เลือกปีต้นทาง --</option>
                            <?php foreach ($years as $cy): ?>
                                <option value="<?= $cy['id'] ?>" data-year="<?= htmlspecialchars($cy['year']) ?>">
                                    ปีงบประมาณ <?= htmlspecialchars($cy['year']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn-secondary"
                            onclick="closeModal('modalCopyYear')">ยกเลิก</button>
                        <button type="submit" class="btn-primary"
                            style="background: linear-gradient(135deg, #3B82F6, #2563EB); border-color: #2563EB;">คัดลอกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- No management modals in user portal -->

        <script>
            function openModal(id) { document.getElementById(id).classList.add('open'); document.getElementById(id).style.display = 'flex'; }
            function closeModal(id) { document.getElementById(id).classList.remove('open'); document.getElementById(id).style.display = 'none'; }

            function openEditYearModal(id, yearName) {
                document.getElementById('edit_target_year_id').value = id;
                document.getElementById('edit_target_year_label').textContent = yearName;

                // รีเซ็ต component dropdown กลับเป็น placeholder
                const wrap  = document.getElementById('editYearSelect');
                const input = document.getElementById('editYearSelect_input');
                const label = document.getElementById('editYearSelect_label');
                if (input) input.value = '';
                if (label) {
                    label.textContent = wrap.dataset.emptyLabel || '-- เลือกปีงบประมาณใหม่ --';
                    label.style.color = '#9CA3AF';
                }

                // ซ่อนตัวเลือก "ปีปัจจุบัน" ออกจากรายการ
                wrap.querySelectorAll('.dd-option').forEach(opt => {
                    const isCurrent = String(opt.dataset.value) === String(id);
                    opt.style.display = isCurrent ? 'none' : '';
                    opt.classList.remove('active');
                });

                openModal('modalEditYear');
            }

            var yearToDelete = null;   // var เพื่อให้ SPA re-run script ได้โดยไม่ error ประกาศซ้ำ
            function openConfirmDeleteByYear(id, name) {
                yearToDelete = id;
                document.getElementById('deleteYearName').textContent = name;
                openModal('modalConfirmDeleteYear');
            }

            function submitDeleteYear() {
                if (yearToDelete) {
                    document.getElementById('deleteYearForm_' + yearToDelete).submit();
                }
            }

            function openCopyYearModal(targetId, targetYear) {
                document.getElementById('copy_target_year_id').value = targetId;
                document.getElementById('copy_target_year_label').textContent = targetYear;

                // ซ่อนปีปลายทางออกจาก dropdown ต้นทาง
                const select = document.getElementById('copy_source_year_select');
                select.value = '';
                Array.from(select.options).forEach(opt => {
                    if (opt.value == targetId) {
                        opt.hidden = true;
                        opt.disabled = true;
                    } else {
                        opt.hidden = false;
                        opt.disabled = false;
                    }
                });

                openModal('modalCopyYear');
            }

            document.querySelectorAll('.modal-overlay').forEach(el => {
                el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
            });
        </script>


    </main>

    <script src="<?= $root ?>assets/js/session-timer.js<?= asset_v('assets/js/session-timer.js') ?>"></script>
</body>

</html>