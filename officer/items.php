<?php
/**
 * OFFICER — ① เลือกปี (items.php)
 * -------------------------------------------
 * สิทธิ์: officer (เจ้าหน้าที่คณะ)
 * การ์ดปีที่คณะมีข้อมูลการดำเนินงาน + สร้าง / เปลี่ยน / สลับ / คัดลอก / ลบ ข้อมูลของปี
 * ทุกคำสั่งแตะเฉพาะ user_item.source = 'officer' (includes/officer_entry.php) — ไม่กระทบกิจกรรม/แบบสอบถาม
 * หน้าต่างชุดเดียวกับ admin/items.php: co-modal (collect.css) + dropdown component + confirmDelete · ปุ่มส่งข้อมูลผ่าน data-* (ไม่มี onclick / inline style)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/officer_entry.php';

require_role(['officer']);

$pdo = getDB();
$root = '../';
$affil_id = (int) ($_SESSION['affiliation_id'] ?? 0);
$msg = $_GET['msg'] ?? '';
$msg_type = $_GET['msg_type'] ?? 'success';

if ($affil_id <= 0) {
    $msg = "บัญชีของคุณยังไม่ได้ระบุหน่วยงาน (Affiliation) กรุณาติดต่อ Admin เพื่อระบุหน่วยงานก่อนเริ่มใช้งาน";
    $msg_type = 'danger';
}

$affiliation_name = $_SESSION['affiliation_name'] ?? 'หน่วยงานทั่วไป';
$back = fn(string $q) => header('Location: items.php?' . $q);
$year_label = function (int $id) use ($pdo): string {
    $st = $pdo->prepare('SELECT year FROM admin_year WHERE id = ?');
    $st->execute([$id]);
    return (string) $st->fetchColumn();
};

// ── POST (ก่อนดึงข้อมูล เพื่อให้หน้าแสดงค่าล่าสุด) ─────────────────────────────
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

if ($action === 'create_user_year') {
    if ($affil_id <= 0) { $back('msg=' . urlencode('ไม่สามารถสร้างปีได้ เนื่องจากบัญชีของคุณไม่มีหน่วยงาน') . '&msg_type=danger'); exit; }
    $year_to_create = (int) $_POST['year_id'];
    $avail = array_column(officer_available_years($pdo, $affil_id), 'items', 'id');
    if (!isset($avail[$year_to_create])) { $back('msg=' . urlencode('ปีนี้มีข้อมูลอยู่แล้ว หรือไม่มีในระบบ') . '&msg_type=danger'); exit; }
    if ($avail[$year_to_create] === 0) { $back('msg=' . urlencode('ไม่พบรายการกิจกรรมที่ Admin กำหนดในปีนี้ กรุณาแจ้ง Admin ให้เพิ่มรายการก่อน') . '&msg_type=danger'); exit; }

    // สร้างแถวการดำเนินงานของทุกรายการในแม่บท (ปริมาณ 0 = ยังไม่กรอก)
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO user_item (admin_item_id, affiliation_id, year_id, Vol, source)
        SELECT id, :affil, :year_id1, 0, 'officer' FROM admin_item WHERE year_id = :year_id2 AND data_source = 'officer'");
    $stmt->execute([':affil' => $affil_id, ':year_id1' => $year_to_create, ':year_id2' => $year_to_create]);
    $back("year=$year_to_create&created_success=1&count={$avail[$year_to_create]}");
    exit;
}

if ($action === 'edit_user_year') {
    $target_id   = (int) $_POST['year_id'];
    $new_year_id = (int) $_POST['new_year_id'];
    if ($new_year_id <= 0 || $new_year_id === $target_id || $year_label($new_year_id) === '') { $back('msg=' . urlencode('กรุณาเลือกปีงบประมาณใหม่') . '&msg_type=danger'); exit; }

    if (officer_has_year($pdo, $affil_id, $new_year_id)) {
        // ปีปลายทางมีข้อมูลแล้ว → ถามเพื่อสลับ
        $back('msg=duplicate_year&id1=' . $target_id . '&id2=' . $new_year_id . '&y1=' . urlencode($year_label($target_id)) . '&y2=' . urlencode($year_label($new_year_id)));
        exit;
    }
    try {
        $pdo->beginTransaction();
        officer_move_year($pdo, $affil_id, $target_id, $new_year_id);
        $pdo->commit();
        $back("year=$new_year_id&msg=" . urlencode('แก้ไขปีงบประมาณเรียบร้อยแล้ว'));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $back('msg=' . urlencode(safe_error_message($e)) . '&msg_type=danger');
    }
    exit;
}

if ($action === 'swap_user_years') {
    try {
        $pdo->beginTransaction();
        officer_swap_years($pdo, $affil_id, (int) $_POST['id1'], (int) $_POST['id2']);
        $pdo->commit();
        $back('year=' . (int) $_POST['id1'] . '&msg=' . urlencode('สลับข้อมูลปีงบประมาณเรียบร้อยแล้ว'));
    } catch (Exception $e) {
        $pdo->rollBack();
        $back('msg=' . urlencode('เกิดข้อผิดพลาด: ' . safe_error_message($e)) . '&msg_type=danger');
    }
    exit;
}

if ($action === 'delete_user_year') {
    officer_delete_year($pdo, $affil_id, (int) $_POST['year_id']);
    $back('msg=' . urlencode('ลบข้อมูลการดำเนินงานของปีเรียบร้อยแล้ว') . '&msg_type=success');
    exit;
}

if ($action === 'copy_user_year') {
    $target_year_id = (int) $_POST['target_year_id'];
    $source_year_id = (int) $_POST['source_year_id'];
    if ($target_year_id <= 0 || $source_year_id <= 0 || $target_year_id === $source_year_id) {
        $back('msg=' . urlencode('กรุณาเลือกปีต้นทาง') . '&msg_type=danger');
        exit;
    }
    try {
        $pdo->beginTransaction();
        $copied = officer_copy_year($pdo, $affil_id, $source_year_id, $target_year_id);
        $pdo->commit();
        $back("year=$target_year_id&msg=" . urlencode($copied ? "คัดลอกข้อมูลสำเร็จ ($copied รายการ)" : 'ไม่พบรายการที่ตรงกันระหว่างสองปี จึงไม่ได้คัดลอก') . '&msg_type=' . ($copied ? 'success' : 'danger'));
    } catch (Exception $e) {
        $pdo->rollBack();
        $back('msg=' . urlencode('เกิดข้อผิดพลาด: ' . safe_error_message($e)) . '&msg_type=danger');
    }
    exit;
}

// ── ข้อมูลของหน้า ─────────────────────────────────────────
$years           = officer_year_cards($pdo, $affil_id);
$available_years = officer_available_years($pdo, $affil_id);
// ปีปลายทางของ "เปลี่ยนปี": เฉพาะปีที่ Admin กำหนดรายการแล้ว (ปีว่างย้ายไปไม่ได้ — officer_year_move_check)
$all_admin_years = $pdo->query("SELECT y.id, y.year FROM admin_year y
    WHERE EXISTS (SELECT 1 FROM admin_item ai WHERE ai.year_id = y.id AND ai.data_source = 'officer') ORDER BY y.year DESC")->fetchAll();
$creatable       = array_values(array_filter($available_years, fn($y) => $y['items'] > 0));

$remaining = session_remaining();
$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$svg_plus = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
$arrow = '<span class="oe-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></span>';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกข้อมูล — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
</head>

<body>

    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="oe-page" id="oeYearsPage">
            <?php $toast_msg = $msg === 'duplicate_year' ? '' : $msg; $toast_type = $msg_type; include __DIR__ . '/../components/toast.php'; ?>

            <?= officer_entry_steps(1) ?>

            <div class="oe-head">
                <div>
                    <h1 class="oe-title">กรอกข้อมูลการดำเนินงาน</h1>
                    <div class="oe-sub">หน่วยงาน: <?= $h($affiliation_name) ?> · เลือกปีงบประมาณที่ต้องการกรอก</div>
                </div>
                <div class="oe-actions">
                    <button type="button" class="oe-btn oe-btn-success" data-oe-year="create"><?= $svg_plus ?> สร้างปี</button>
                </div>
            </div>

            <?php if (empty($years)): ?>
                <div class="oe-empty oe-rise">
                    <h3>ยังไม่มีปีงบประมาณที่กรอกข้อมูล</h3>
                    <p>เริ่มจากสร้างปี ระบบจะเตรียมรายการที่ต้องกรอกตามที่ Admin กำหนดไว้ให้</p>
                    <button type="button" class="oe-btn oe-btn-success" data-oe-year="create">สร้างปีงบประมาณ <?= $arrow ?></button>
                </div>
            <?php else: ?>
            <div class="oe-panel oe-rise">
                <h2 class="oe-panel-title">รายงานการปล่อยก๊าซเรือนกระจกจากการดำเนินงาน</h2>
                <div class="oe-years">
                    <?php foreach ($years as $i => $y):
                        $pct  = $y['total'] > 0 ? $y['filled'] / $y['total'] * 100 : 0.0;
                        $done = $y['total'] > 0 && $y['filled'] >= $y['total'];
                        $yl   = $h($y['year']); ?>
                    <div class="oe-year oe-rise" style="--i:<?= $i + 1 ?>;" data-year-id="<?= $y['id'] ?>">
                        <div class="oe-year-tools">
                            <button type="button" class="oe-icon-btn oe-icon-edit" data-oe-year="edit" data-id="<?= $y['id'] ?>" data-year="<?= $yl ?>"
                                title="เปลี่ยนปีงบประมาณของข้อมูล" aria-label="เปลี่ยนปีงบประมาณ <?= $yl ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                            <?php if (count($years) > 1): ?>
                            <button type="button" class="oe-icon-btn oe-icon-copy" data-oe-year="copy" data-id="<?= $y['id'] ?>" data-year="<?= $yl ?>"
                                title="คัดลอกปริมาณจากปีอื่น" aria-label="คัดลอกข้อมูลจากปีอื่นมาปี <?= $yl ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="oe-icon-btn oe-icon-del" data-oe-year="delete" data-id="<?= $y['id'] ?>"
                                data-msg="<?= $h('ข้อมูลการดำเนินงานทั้งหมดของปี ' . $y['year'] . ' จะถูกลบถาวรและกู้คืนไม่ได้ (ข้อมูลกิจกรรมและแบบสอบถามของปีนี้ไม่ถูกลบ)') ?>"
                                title="ลบข้อมูลปีนี้" aria-label="ลบข้อมูลปี <?= $yl ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                            </button>
                            <form method="POST" action="items.php" id="oeDelYear<?= $y['id'] ?>" hidden><?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_user_year">
                                <input type="hidden" name="year_id" value="<?= $y['id'] ?>">
                            </form>
                        </div>

                        <div>
                            <div class="oe-year-label">ปีงบประมาณ</div>
                            <div class="oe-year-no"><?= $yl ?></div>
                        </div>
                        <div class="oe-year-total"><?= number_format($y['operation_total'], 2) ?> <small>tCO₂e</small></div>
                        <div>
                            <div class="oe-fill-row"><span><?= $done ? 'กรอกครบทุกรายการแล้ว' : 'กรอกแล้ว' ?></span><b class="oe-year-fill"><?= $y['filled'] ?> / <?= $y['total'] ?> รายการ</b></div>
                            <div class="oe-track"><div class="oe-bar<?= $done ? ' is-done' : '' ?>" style="width:<?= number_format($pct, 2, '.', '') ?>%;"></div></div>
                        </div>
                        <a href="data_entry.php?year=<?= $y['id'] ?>" class="oe-btn oe-btn-amber"><?= $y['filled'] > 0 ? 'แก้ไขข้อมูล' : 'เริ่มกรอกข้อมูล' ?> <?= $arrow ?></a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Modal: สร้างปีงบประมาณ -->
        <div class="modal-overlay" id="modal-create-year">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('add', 22) ?><span>สร้างปีงบประมาณ</span></div>
                <form action="items.php" method="POST" id="oeCreateYearForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_user_year">
                    <div class="form-group-dark"><label class="form-label-dark">ปีงบประมาณ (จากที่ Admin กำหนด) *</label>
                        <?php
                        $dd_id = 'createYearSelect'; $dd_name = 'year_id';
                        $dd_options = array_map(fn($ay) => ['value' => $ay['id'], 'label' => $ay['year'] . ' (' . $ay['items'] . ' รายการ)'], $creatable);
                        $dd_selected = ''; $dd_placeholder = empty($creatable) ? '-- ไม่มีปีงบประมาณใหม่ให้เพิ่ม --' : '-- เลือกปีงบประมาณ --';
                        $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="oe-note">ระบบเตรียมรายการที่ต้องกรอกตามที่ Admin กำหนดไว้ในปีนั้น<?= count($available_years) > count($creatable) ? ' · ปีที่ Admin ยังไม่กำหนดรายการจะยังสร้างไม่ได้' : '' ?></div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" data-close>ยกเลิก</button>
                        <button type="submit" class="btn-primary"<?= empty($creatable) ? ' disabled' : '' ?>>สร้างปีงบประมาณ</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($_GET['created_success'])): ?>
        <!-- Modal: สร้างสำเร็จ (เปิดทันทีหลัง redirect) -->
        <div class="modal-overlay" id="modal-success" data-open-on-load>
            <div class="modal-box co-modal is-center">
                <div class="oe-done-ic"><svg viewBox="0 0 24 24" width="40" height="40" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                <h3 class="ai-swap-title">สร้างข้อมูลสำเร็จ!</h3>
                <p class="oe-modal-text">เตรียมรายการที่ต้องกรอกไว้ <?= (int) ($_GET['count'] ?? 0) ?> รายการ</p>
                <div class="oe-modal-stack">
                    <a href="data_entry.php?year=<?= (int) $_GET['year'] ?>" class="oe-btn">เริ่มกรอกข้อมูลเลย <?= $arrow ?></a>
                    <button type="button" class="oe-btn oe-btn-ghost" data-close>ปิดหน้าต่าง</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Modal: เปลี่ยนปี -->
        <div class="modal-overlay" id="modalEditYear">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('edit', 22) ?><span>เปลี่ยนปีงบประมาณของข้อมูล</span></div>
                <form action="items.php" method="POST" id="oeEditYearForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_user_year">
                    <input type="hidden" name="year_id" id="edit_target_year_id">
                    <div class="form-group-dark"><label class="form-label-dark">เปลี่ยนข้อมูลของปี <b id="edit_target_year_label">-</b> เป็นปี *</label>
                        <?php
                        $dd_id = 'editYearSelect'; $dd_name = 'new_year_id';
                        $dd_options = array_map(fn($ay) => ['value' => $ay['id'], 'label' => 'ปีงบประมาณ ' . $ay['year']], $all_admin_years);
                        $dd_selected = ''; $dd_placeholder = '-- เลือกปีงบประมาณใหม่ --'; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="oe-note">หากเลือกปีที่มีข้อมูลอยู่แล้ว ระบบจะถามเพื่อสลับข้อมูลระหว่างสองปี · ย้ายเฉพาะข้อมูลการดำเนินงาน ไม่กระทบกิจกรรมและแบบสอบถาม · เลือกได้เฉพาะปีที่ Admin กำหนดรายการแล้ว</div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" data-close>ยกเลิก</button>
                        <button type="submit" class="btn-primary">บันทึกการเปลี่ยนปี</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($msg === 'duplicate_year'): ?>
        <!-- Modal: ยืนยันการสลับปี (เปิดทันทีหลัง redirect · คลิกฉากหลังไม่ปิด ต้องเลือกเอง) -->
        <div class="modal-overlay" id="modalConfirmSwap" data-open-on-load>
            <div class="modal-box co-modal is-center">
                <div class="ai-swap-ic"><svg viewBox="0 0 24 24" width="32" height="32" fill="#F59E0B"><path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z"/></svg></div>
                <h3 class="ai-swap-title">สลับข้อมูลปีงบประมาณ?</h3>
                <p class="oe-modal-text">คุณมีข้อมูลของปี <b><?= $h($_GET['y2'] ?? '') ?></b> อยู่แล้ว<br>ต้องการสลับข้อมูลกับปี <b><?= $h($_GET['y1'] ?? '') ?></b> หรือไม่?</p>
                <form action="items.php" method="POST"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="swap_user_years">
                    <input type="hidden" name="id1" value="<?= (int) ($_GET['id1'] ?? 0) ?>">
                    <input type="hidden" name="id2" value="<?= (int) ($_GET['id2'] ?? 0) ?>">
                    <div class="modal-footer is-center">
                        <button type="button" class="btn-secondary" data-close>ยกเลิก</button>
                        <button type="submit" class="btn-primary is-amber">ยืนยันการสลับปี</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($years) > 1): ?>
        <!-- Modal: คัดลอกข้อมูลจากปีอื่น -->
        <div class="modal-overlay" id="modalCopyYear">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('copy', 22) ?><span>คัดลอกข้อมูลจากปีอื่น</span></div>
                <form action="items.php" method="POST" id="oeCopyYearForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="copy_user_year">
                    <input type="hidden" name="target_year_id" id="copy_target_year_id">
                    <div class="form-group-dark"><label class="form-label-dark">คัดลอกปริมาณจากปี → ปี <b id="copy_target_year_label">-</b> *</label>
                        <?php
                        $dd_id = 'copySourceYear'; $dd_name = 'source_year_id';
                        $dd_options = array_map(fn($cy) => ['value' => $cy['id'], 'label' => 'ปี ' . $cy['year'] . ' (กรอกแล้ว ' . $cy['filled'] . ' รายการ)'], $years);
                        $dd_selected = ''; $dd_placeholder = '-- เลือกปีต้นทาง --'; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="oe-note">จับคู่รายการที่ชื่อและหน่วยตรงกัน · ค่าเดิมของรายการที่จับคู่ได้จะถูก<b>แทนที่</b> · เฉพาะข้อมูลการดำเนินงาน</div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" data-close>ยกเลิก</button>
                        <button type="submit" class="btn-primary">คัดลอกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <script>
            // IIFE — SPA รันสคริปต์ซ้ำทุกครั้งที่เข้าหน้านี้ (ไม่มี let/const ระดับบนสุด) · ผูกกับ element ของหน้านี้ จึงไม่ซ้อนเมื่อกลับมาหน้าเดิม
            (function () {
                var d = document;
                function $(id) { return d.getElementById(id); }
                var page = $('oeYearsPage');
                if (!page) return;

                window.openModal = function (id) { var m = $(id); if (!m) return; m.classList.add('open'); m.style.display = 'flex'; var b = m.querySelector('.modal-box'); if (b) { b.style.animation = 'none'; void b.offsetWidth; b.style.animation = ''; } };
                window.closeModal = function (id) { var m = $(id); if (m) { m.classList.remove('open'); m.style.display = 'none'; } };

                // ปิด: ปุ่ม [data-close] / คลิกฉากหลัง (ยกเว้นหน้าต่างสลับปี) / Esc
                d.querySelectorAll('#oeYearsPage ~ .modal-overlay').forEach(function (m) {
                    m.addEventListener('click', function (e) {
                        if (e.target.closest('[data-close]') || (e.target === m && m.id !== 'modalConfirmSwap')) closeModal(m.id);
                    });
                    if (m.hasAttribute('data-open-on-load')) openModal(m.id);
                });
                if (!window.__coEsc) {
                    window.__coEsc = true;
                    d.addEventListener('keydown', function (e) {
                        if (e.key !== 'Escape') return;
                        d.querySelectorAll('.modal-overlay.open').forEach(function (m) { closeModal(m.id); });
                    });
                }

                // dropdown กลับเป็นค่าว่าง + ซ่อนตัวเลือกของปีตัวเอง
                function ddClear(id, hide) {
                    var wrap = $(id); if (!wrap) return;
                    $(id + '_input').value = '';
                    var label = $(id + '_label');
                    label.textContent = wrap.dataset.emptyLabel; label.style.color = '#9CA3AF';
                    wrap.querySelectorAll('.dd-option').forEach(function (o) {
                        o.classList.remove('active');
                        o.style.display = hide && String(o.dataset.value) === String(hide) ? 'none' : '';
                    });
                }
                // ส่งฟอร์มไม่ได้ → ข้อความในหน้าต่าง + สั่น (ไม่ใช้ alert)
                function guard(form, check) {
                    if (!form) return;
                    form.addEventListener('submit', function (e) {
                        var err = check(), msg = form.querySelector('.co-modal-msg');
                        if (msg) msg.textContent = err;
                        if (!err) { var btn = form.querySelector('[type="submit"]'); if (btn) btn.disabled = true; return; }
                        e.preventDefault();
                        var box = form.closest('.modal-box');
                        box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                    });
                }
                function show(id, formId, ddId, hide) {
                    ddClear(ddId, hide);
                    var f = $(formId); if (f) f.querySelector('.co-modal-msg').textContent = '';
                    openModal(id);
                }
                guard($('oeCreateYearForm'), function () { return $('createYearSelect_input').value ? '' : 'กรุณาเลือกปีงบประมาณ'; });
                guard($('oeEditYearForm'), function () { return $('editYearSelect_input').value ? '' : 'กรุณาเลือกปีงบประมาณใหม่'; });
                guard($('oeCopyYearForm'), function () { return $('copySourceYear_input').value ? '' : 'กรุณาเลือกปีต้นทาง'; });

                // ปุ่มบนหน้า (สร้าง / เปลี่ยน / คัดลอก / ลบ) — ข้อมูลอยู่ใน data-* ไม่ต่อสตริงลง onclick
                page.addEventListener('click', function (e) {
                    var b = e.target.closest('[data-oe-year]');
                    if (!b) return;
                    var act = b.dataset.oeYear;
                    if (act === 'create') show('modal-create-year', 'oeCreateYearForm', 'createYearSelect');
                    else if (act === 'edit') {
                        $('edit_target_year_id').value = b.dataset.id;
                        $('edit_target_year_label').textContent = b.dataset.year;
                        show('modalEditYear', 'oeEditYearForm', 'editYearSelect', b.dataset.id);
                    } else if (act === 'copy' && $('modalCopyYear')) {
                        $('copy_target_year_id').value = b.dataset.id;
                        $('copy_target_year_label').textContent = b.dataset.year;
                        show('modalCopyYear', 'oeCopyYearForm', 'copySourceYear', b.dataset.id);
                    } else if (act === 'delete') {
                        confirmDelete({ title: 'ลบข้อมูลปีนี้?', message: b.dataset.msg, confirmText: 'ลบข้อมูลปีนี้' }).then(function (ok) {
                            if (ok && $('oeDelYear' + b.dataset.id)) $('oeDelYear' + b.dataset.id).submit();
                        });
                    }
                });
            })();
        </script>
        <?php include __DIR__ . '/../components/confirm_modal.php'; ?>
    </main>

    <script src="<?= $root ?>assets/js/session-timer.js<?= asset_v('assets/js/session-timer.js') ?>"></script>
</body>

</html>
