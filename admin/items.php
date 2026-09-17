<?php
/**
 * ADMIN — ① เลือกปี / จัดการข้อมูลอ้างอิงกลาง (items.php)
 * ---------------------------------------------------------
 * การ์ดปีงบประมาณทุกปี (ยอดการดำเนินงานรวมทุกหน่วยงาน / จำนวนรายการ Emission Factor / หน่วยงานที่กรอก)
 * เพิ่ม · เปลี่ยนเลขปี (ซ้ำ → ถามสลับ) · คัดลอกรายการจากปีอื่น · ลบปี (บอกจำนวนข้อมูลที่จะหาย) · จัดการหมวด (admin_g)
 * logic อยู่ที่ includes/admin_items_entry.php · ขั้น ② ③ ใช้หน้ากลางร่วมกับ officer
 *
 * เดิม: ข้อความหลัง redirect ไม่เคยแสดง (ไม่อ่าน $_GET['msg']), ยอดบนการ์ดรวมกิจกรรม/แบบสอบถาม,
 *       สลับปีเชื่อเลขปีจากฟอร์ม, สคริปต์ const ระดับบนสุดพังเมื่อ SPA โหลดซ้ำ, โค้ดเพิ่ม/แก้รายการที่ไม่มีปุ่มเรียก
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_items_entry.php';

require_role(['admin']);

$pdo  = getDB();
$root = '../';

// ── POST → ทำงาน → redirect พร้อมข้อความ (รีเฟรชแล้วไม่ส่งซ้ำ) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $go = function (string $msg, string $type = 'success', string $extra = '') {
        header('Location: items.php?msg=' . urlencode($msg) . ($type === 'danger' ? '&msg_type=danger' : '') . $extra);
        exit;
    };

    // เลื่อนหมวด (AJAX) → JSON
    if ($action === 'move_group') {
        header('Content-Type: application/json');
        try {
            $ok = admin_move_group($pdo, (int) ($_POST['group_id'] ?? 0), ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down');
            echo json_encode(['ok' => $ok, 'msg' => $ok ? '' : 'เลื่อนต่อไม่ได้แล้ว'], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => safe_error_message($e)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    try {
        switch ($action) {
            case 'add_year':
                $year = (int) ($_POST['new_year'] ?? 0);
                admin_add_year($pdo, $year);
                $go("เพิ่มปีงบประมาณ $year แล้ว");
                // no break — $go จบการทำงาน
            case 'edit_year':
                $id   = (int) ($_POST['year_id'] ?? 0);
                $year = (int) ($_POST['year_val'] ?? 0);
                $dup  = admin_rename_year($pdo, $id, $year);
                if ($dup !== null) { header("Location: items.php?swap=1&id1=$id&id2=$dup"); exit; }
                $go("เปลี่ยนเป็นปีงบประมาณ $year แล้ว");
            case 'swap_years':
                admin_swap_years($pdo, (int) ($_POST['id1'] ?? 0), (int) ($_POST['id2'] ?? 0));
                $go('สลับปีงบประมาณเรียบร้อยแล้ว');
            case 'delete_year':
                $id = (int) ($_POST['year_id'] ?? 0);
                $label = admin_year_label($pdo, $id);
                if (!admin_delete_year($pdo, $id)) throw new Exception('ไม่พบปีงบประมาณ');
                $go("ลบปีงบประมาณ $label แล้ว");
            case 'copy_items':
                $to = (int) ($_POST['target_year_id'] ?? 0);
                $n  = admin_copy_items($pdo, (int) ($_POST['source_year_id'] ?? 0), $to);
                $go($n > 0 ? "คัดลอกรายการ Emission Factor มาปี " . admin_year_label($pdo, $to) . " แล้ว $n รายการ" : 'ไม่มีรายการใหม่ให้คัดลอก (ชื่อซ้ำกับปีปลายทางทั้งหมด)');
            case 'add_group':
                admin_add_group($pdo, (int) ($_POST['scope'] ?? 0), (string) ($_POST['name_tiem'] ?? ''));
                $go('เพิ่มหมวดแล้ว', 'success', '&groups=1');
            case 'delete_group':
                admin_delete_group($pdo, (int) ($_POST['group_id'] ?? 0));
                $go('ลบหมวดแล้ว', 'success', '&groups=1');
        }
    } catch (Exception $e) {
        $go('เกิดข้อผิดพลาด: ' . safe_error_message($e), 'danger', in_array($action, ['add_group', 'delete_group'], true) ? '&groups=1' : '');
    }
    $go('คำสั่งไม่ถูกต้อง', 'danger');
}

// ── ข้อมูลของหน้า ──
$years       = admin_year_cards($pdo);
$groups      = admin_groups($pdo);
$affil_total = (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn();
$year_opts   = admin_year_options(array_column($years, 'year'));
$edit_opts   = array_values(array_unique(array_merge(admin_year_options([]), array_column($years, 'year'))));
rsort($edit_opts);
$copy_ready  = array_values(array_filter($years, fn($y) => $y['items'] > 0));   // ปีที่เป็นต้นทางคัดลอกได้
$latest      = $years[0] ?? null;
$meta        = officer_scope_meta();

// เปลี่ยนเลขปีไปชนปีที่มีอยู่ → ถามสลับ (อ่านเลขปีจากฐานข้อมูล ไม่ใช้ค่าจาก URL)
$swap = null;
if (isset($_GET['swap'])) {
    $s1 = admin_year_label($pdo, (int) ($_GET['id1'] ?? 0));
    $s2 = admin_year_label($pdo, (int) ($_GET['id2'] ?? 0));
    if ($s1 !== null && $s2 !== null) $swap = ['id1' => (int) $_GET['id1'], 'id2' => (int) $_GET['id2'], 'y1' => $s1, 'y2' => $s2];
}

$flash   = (string) ($_GET['msg'] ?? '');
$flash_t = (($_GET['msg_type'] ?? '') === 'danger') ? 'danger' : 'success';
$page_title  = 'กรอกข้อมูล';
$page_title2 = 'UP Net Zero';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$arrow = '<span class="oe-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></span>';
$svg_edit = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>';
$svg_copy = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
$svg_del  = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>';
$svg_layers = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกข้อมูล — UP Net Zero Admin</title>
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

    <main class="main-content" style="background-color: transparent;">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="oe-page ai-page">
            <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

            <?= officer_entry_steps(1) ?>

            <div class="oe-head">
                <div>
                    <h1 class="oe-title">กรอกข้อมูลการดำเนินงาน</h1>
                    <div class="oe-sub">จัดการปีงบประมาณ หมวด และรายการ Emission Factor · เลือกปีเพื่อกรอกข้อมูลแทนหน่วยงาน</div>
                </div>
                <div class="oe-actions">
                    <button type="button" class="oe-btn oe-btn-ghost" onclick="openModal('modal-groups')"><?= $svg_layers ?> จัดการหมวด</button>
                    <button type="button" class="oe-btn oe-btn-success" onclick="aiAddYear()" <?= $year_opts ? '' : 'disabled title="เพิ่มครบทุกปีในช่วงที่เลือกได้แล้ว"' ?>>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> เพิ่มปี
                    </button>
                </div>
            </div>

            <?php if ($latest): ?>
            <!-- การ์ดสรุปของปีล่าสุด -->
            <div class="co-kpis">
                <div class="co-kpi oe-rise" style="--i:1;">
                    <span class="co-kpi-ic"><?= ic('factory', 22) ?></span>
                    <div><span class="co-kpi-label">การดำเนินงานปี <?= $h($latest['year']) ?></span>
                        <b class="co-kpi-val"><?= number_format($latest['total'], 2) ?> <small>tCO₂e</small></b>
                        <span class="co-kpi-sub">รวมทุกหน่วยงาน</span></div>
                </div>
                <div class="co-kpi oe-rise" style="--i:2;">
                    <span class="co-kpi-ic"><?= ic('building', 22) ?></span>
                    <div><span class="co-kpi-label">หน่วยงานที่กรอกแล้ว</span>
                        <b class="co-kpi-val"><?= $latest['affils'] ?> <small>/ <?= $affil_total ?> หน่วยงาน</small></b>
                        <span class="co-kpi-sub">ปีงบประมาณ <?= $h($latest['year']) ?></span></div>
                </div>
                <div class="co-kpi oe-rise" style="--i:3;">
                    <span class="co-kpi-ic"><?= ic('doc', 22) ?></span>
                    <div><span class="co-kpi-label">รายการ Emission Factor</span>
                        <b class="co-kpi-val"><?= $latest['items'] ?> <small>รายการ</small></b>
                        <span class="co-kpi-sub"><?= count($groups) ?> หมวด · ปี <?= $h($latest['year']) ?></span></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($years)): ?>
                <div class="oe-empty oe-rise oe-kpis-gap">
                    <h3>ยังไม่มีปีงบประมาณในระบบ</h3>
                    <p>เพิ่มปีงบประมาณก่อน แล้วกำหนดรายการ Emission Factor ให้หน่วยงานกรอก</p>
                    <button type="button" onclick="aiAddYear()" class="oe-btn oe-btn-success">เพิ่มปีงบประมาณ <?= $arrow ?></button>
                </div>
            <?php else: ?>
            <div class="oe-panel oe-rise oe-kpis-gap" style="--i:4;">
                <h2 class="oe-panel-title">ปีงบประมาณทั้งหมด</h2>
                <div class="oe-years">
                    <?php foreach ($years as $i => $y):
                        $pct  = $affil_total > 0 ? min(100, $y['affils'] / $affil_total * 100) : 0.0;
                        $bare = $y['items'] === 0;
                        $srcs = array_values(array_filter($copy_ready, fn($c) => $c['id'] !== $y['id']));
                        $del_msg = admin_year_impact_text($y['year'], admin_year_impact($pdo, $y['id'])); ?>
                    <div class="oe-year oe-rise<?= $bare ? ' is-bare' : '' ?>" style="--i:<?= $i + 5 ?>;" data-year-id="<?= $y['id'] ?>">
                        <div class="oe-year-tools">
                            <button type="button" class="oe-icon-btn oe-icon-edit" title="เปลี่ยนเลขปีงบประมาณ" aria-label="เปลี่ยนเลขปี <?= $y['year'] ?>"
                                data-id="<?= $y['id'] ?>" data-year="<?= $y['year'] ?>" onclick="aiEditYear(this)"><?= $svg_edit ?></button>
                            <?php if ($srcs): ?>
                            <button type="button" class="oe-icon-btn oe-icon-copy" title="คัดลอกรายการ Emission Factor จากปีอื่น" aria-label="คัดลอกรายการมาปี <?= $y['year'] ?>"
                                data-id="<?= $y['id'] ?>" data-year="<?= $y['year'] ?>" onclick="aiCopyYear(this)"><?= $svg_copy ?></button>
                            <?php endif; ?>
                            <button type="button" class="oe-icon-btn oe-icon-del" title="ลบปีงบประมาณ" aria-label="ลบปี <?= $y['year'] ?>"
                                data-id="<?= $y['id'] ?>" data-msg="<?= $h($del_msg) ?>" onclick="aiDeleteYear(this)"><?= $svg_del ?></button>
                        </div>

                        <div>
                            <div class="oe-year-label">ปีงบประมาณ</div>
                            <div class="oe-year-no"><?= $y['year'] ?></div>
                        </div>
                        <div class="oe-year-total"><?= number_format($y['total'], 2) ?> <small>tCO₂e</small></div>
                        <div class="oe-year-stats">
                            <div class="oe-year-stat"><span>รายการ EF</span><b class="ai-items"><?= $y['items'] ?></b></div>
                            <div class="oe-year-stat"><span>หน่วยงานที่กรอก</span><b class="ai-affils"><?= $y['affils'] ?> / <?= $affil_total ?></b></div>
                        </div>
                        <div class="oe-track"><div class="oe-bar<?= $affil_total && $y['affils'] >= $affil_total ? ' is-done' : '' ?>" style="width:<?= number_format($pct, 2, '.', '') ?>%;"></div></div>
                        <?php if (!$bare): ?>
                        <a href="data_entry.php?year=<?= $y['id'] ?>" class="oe-btn oe-btn-amber">กรอกข้อมูล <?= $arrow ?></a>
                        <?php elseif ($srcs): ?>
                        <button type="button" class="oe-btn" data-id="<?= $y['id'] ?>" data-year="<?= $y['year'] ?>" onclick="aiCopyYear(this)"><?= $svg_copy ?> คัดลอกรายการจากปีอื่น</button>
                        <?php else: ?>
                        <a href="data_entry.php?year=<?= $y['id'] ?>" class="oe-btn">เพิ่มรายการ Emission Factor <?= $arrow ?></a>
                        <?php endif; ?>
                        <form method="POST" action="items.php" id="aiDelYear<?= $y['id'] ?>" hidden><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_year"><input type="hidden" name="year_id" value="<?= $y['id'] ?>">
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Modal: เพิ่มปี -->
        <div class="modal-overlay" id="modal-add-year">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('add', 22) ?><span>เพิ่มปีงบประมาณ</span></div>
                <form method="POST" action="items.php" id="aiAddYearForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_year">
                    <div class="form-group-dark"><label class="form-label-dark">ปีงบประมาณ (พ.ศ.) *</label>
                        <?php
                        $dd_id = 'aiNewYear'; $dd_name = 'new_year'; $dd_options = $year_opts; $dd_selected = '';
                        $dd_placeholder = '-- เลือกปีงบประมาณ --'; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="oe-note">ปีใหม่ยังไม่มีรายการ Emission Factor — คัดลอกจากปีก่อนได้จากปุ่มบนการ์ดของปีนั้น</div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" onclick="closeModal('modal-add-year')">ยกเลิก</button>
                        <button type="submit" class="btn-primary">เพิ่มปีงบประมาณ</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: เปลี่ยนเลขปี -->
        <div class="modal-overlay" id="modalEditYear">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('edit', 22) ?><span>เปลี่ยนเลขปีงบประมาณ</span></div>
                <form method="POST" action="items.php" id="aiEditYearForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_year">
                    <input type="hidden" name="year_id" id="aiEditYearId">
                    <div class="form-group-dark"><label class="form-label-dark">เปลี่ยนปี <b id="aiEditYearFrom">-</b> เป็นปี *</label>
                        <?php
                        $dd_id = 'aiEditYear'; $dd_name = 'year_val'; $dd_options = $edit_opts; $dd_selected = '';
                        $dd_placeholder = '-- เลือกปีงบประมาณ --'; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="oe-note">ข้อมูลทั้งหมดของปีนี้ย้ายตามไปด้วย · ถ้าเลือกปีที่มีอยู่แล้ว ระบบจะถามเพื่อสลับเลขปีของทั้งสองปี</div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" onclick="closeModal('modalEditYear')">ยกเลิก</button>
                        <button type="submit" class="btn-primary">บันทึกการเปลี่ยนปี</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($swap): ?>
        <!-- Modal: ยืนยันสลับปี -->
        <div class="modal-overlay open" id="modalConfirmSwap" style="display:flex;">
            <div class="modal-box co-modal" style="text-align:center;">
                <div class="ai-swap-ic"><svg viewBox="0 0 24 24" width="32" height="32" fill="#F59E0B"><path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z"/></svg></div>
                <h3 class="ai-swap-title">ปี <?= $swap['y2'] ?> มีอยู่แล้ว</h3>
                <p class="oe-muted" style="font-size:.95rem;margin:0 0 1.5rem;">ต้องการสลับเลขปีระหว่างปี <b><?= $swap['y1'] ?></b> กับ <b><?= $swap['y2'] ?></b> หรือไม่?<br>ข้อมูลของแต่ละปีจะย้ายตามเลขปีใหม่</p>
                <form method="POST" action="items.php"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="swap_years">
                    <input type="hidden" name="id1" value="<?= $swap['id1'] ?>"><input type="hidden" name="id2" value="<?= $swap['id2'] ?>">
                    <div class="modal-footer" style="justify-content:center;">
                        <a class="btn-secondary" href="items.php" style="text-decoration:none;">ยกเลิก</a>
                        <button type="submit" class="btn-primary" style="background:#F59E0B;border-color:#F59E0B;">สลับเลขปี</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($copy_ready): ?>
        <!-- Modal: คัดลอกรายการจากปีอื่น -->
        <div class="modal-overlay" id="modalCopyYear">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('copy', 22) ?><span>คัดลอกรายการจากปีอื่น</span></div>
                <form method="POST" action="items.php" id="aiCopyForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="copy_items">
                    <input type="hidden" name="target_year_id" id="aiCopyTarget">
                    <div class="form-group-dark"><label class="form-label-dark">คัดลอกจากปี → ปี <b id="aiCopyTargetLabel">-</b> *</label>
                        <?php
                        $dd_id = 'aiCopySrc'; $dd_name = 'source_year_id';
                        $dd_options = array_map(fn($c) => ['value' => $c['id'], 'label' => 'ปี ' . $c['year'] . ' (' . $c['items'] . ' รายการ)'], $copy_ready);
                        $dd_selected = ''; $dd_placeholder = '-- เลือกปีต้นทาง --'; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="oe-note">คัดลอกชื่อ หน่วย และค่าการปล่อยของทุกหมวด · รายการที่ชื่อซ้ำในหมวดเดียวกันของปีปลายทางจะถูกข้าม · ปริมาณที่หน่วยงานกรอกไม่ถูกคัดลอก</div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" onclick="closeModal('modalCopyYear')">ยกเลิก</button>
                        <button type="submit" class="btn-primary">คัดลอกรายการ</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Modal: จัดการหมวด -->
        <div class="modal-overlay" id="modal-groups">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><?= $svg_layers ?><span>จัดการหมวด</span></div>
                <div class="oe-gm-list" id="aiGroupList">
                    <?php foreach ([1, 2, 3] as $sc): $m = $meta[$sc];
                        $mine = array_values(array_filter($groups, fn($g) => $g['scope'] === $sc)); ?>
                    <div class="ai-gm-block" style="--sc:<?= $m['color'] ?>;--sc-soft:<?= $m['soft'] ?>;--sc-line:<?= $m['color'] ?>66;">
                        <h4 class="oe-gm-scope">ขอบเขต <?= $sc ?> · <?= $m['name'] ?></h4>
                        <?php if (!$mine): ?><p class="oe-muted" style="margin:0 0 6px 16px;">ยังไม่มีหมวด</p><?php endif; ?>
                        <?php foreach ($mine as $g): ?>
                        <div class="oe-gm-row" data-id="<?= $g['id'] ?>">
                            <span class="oe-gm-name"><?= $h($g['name_tiem']) ?></span>
                            <span class="oe-gm-count"><?= $g['items'] ? $g['items'] . ' รายการ' : 'ยังไม่ใช้' ?></span>
                            <button type="button" class="oe-gm-btn oe-gm-up" title="เลื่อนขึ้น" aria-label="เลื่อน <?= $h($g['name_tiem']) ?> ขึ้น" onclick="aiMoveGroup(this, 'up')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m18 15-6-6-6 6"/></svg></button>
                            <button type="button" class="oe-gm-btn oe-gm-down" title="เลื่อนลง" aria-label="เลื่อน <?= $h($g['name_tiem']) ?> ลง" onclick="aiMoveGroup(this, 'down')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg></button>
                            <button type="button" class="oe-gm-btn is-del" <?= $g['items'] ? 'disabled title="ลบไม่ได้ ยังมีรายการ Emission Factor ' . $g['items'] . ' รายการใช้หมวดนี้"' : 'title="ลบหมวด"' ?>
                                aria-label="ลบ <?= $h($g['name_tiem']) ?>" data-id="<?= $g['id'] ?>" data-name="<?= $h($g['name_tiem']) ?>" onclick="aiDeleteGroup(this)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form method="POST" action="items.php" class="oe-gm-add" id="aiGroupForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_group">
                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark">ขอบเขต *</label>
                            <?php
                            $dd_id = 'aiGroupScope'; $dd_name = 'scope';
                            $dd_options = array_map(fn($sc) => ['value' => $sc, 'label' => 'ขอบเขต ' . $sc . ' · ' . $meta[$sc]['name']], [1, 2, 3]);
                            $dd_selected = ''; $dd_placeholder = '-- เลือกขอบเขต --'; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:100%;';
                            include __DIR__ . '/../components/dropdown.php';
                            ?>
                        </div>
                        <div class="form-group-dark"><label class="form-label-dark" for="aiGroupName">ชื่อหมวดใหม่ *</label>
                            <input class="form-control-dark" id="aiGroupName" name="name_tiem" maxlength="255" placeholder="เช่น การเผาไหม้อยู่กับที่" autocomplete="off"></div>
                    </div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" onclick="closeModal('modal-groups')">ปิด</button>
                        <button type="submit" class="btn-primary">เพิ่มหมวด</button>
                    </div>
                </form>
                <form method="POST" action="items.php" id="aiGroupDelForm" hidden><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_group"><input type="hidden" name="group_id" value="">
                </form>
            </div>
        </div>

        <script>
            // ประกาศผ่าน window / IIFE — SPA รันสคริปต์ซ้ำทุกครั้งที่เข้าหน้านี้ (let/const ระดับบนสุดจะ error ประกาศซ้ำ)
            (function () {
                var d = document;
                function $(id) { return d.getElementById(id); }

                window.openModal = function (id) { var m = $(id); if (!m) return; m.classList.add('open'); m.style.display = 'flex'; var b = m.querySelector('.modal-box'); if (b) { b.style.animation = 'none'; void b.offsetWidth; b.style.animation = ''; } };
                window.closeModal = function (id) { var m = $(id); if (m) { m.classList.remove('open'); m.style.display = 'none'; } };
                d.querySelectorAll('.ai-page ~ .modal-overlay').forEach(function (el) {
                    el.addEventListener('click', function (e) { if (e.target === el && el.id !== 'modalConfirmSwap') closeModal(el.id); });
                });
                if (!window.__coEsc) {
                    window.__coEsc = true;
                    d.addEventListener('keydown', function (e) {
                        if (e.key !== 'Escape') return;
                        d.querySelectorAll('.modal-overlay.open').forEach(function (m) { closeModal(m.id); });
                    });
                }

                // ── dropdown: กลับเป็นค่าว่าง + ซ่อนตัวเลือกที่ไม่ควรเลือก ──
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
                    if (!form || form.dataset.bound) return;
                    form.dataset.bound = '1';
                    form.addEventListener('submit', function (e) {
                        var err = check();
                        var msg = form.querySelector('.co-modal-msg');
                        if (msg) msg.textContent = err || '';
                        if (!err) { var btn = form.querySelector('[type="submit"]'); if (btn) btn.disabled = true; return; }
                        e.preventDefault();
                        var box = form.closest('.modal-box');
                        box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                    });
                }
                function val(id) { var el = $(id); return el ? el.value.trim() : ''; }

                window.aiAddYear = function () {
                    ddClear('aiNewYear'); $('aiAddYearForm').querySelector('.co-modal-msg').textContent = '';
                    openModal('modal-add-year');
                };
                guard($('aiAddYearForm'), function () { return val('aiNewYear_input') ? '' : 'กรุณาเลือกปีงบประมาณ'; });

                window.aiEditYear = function (b) {
                    $('aiEditYearId').value = b.dataset.id;
                    $('aiEditYearFrom').textContent = b.dataset.year;
                    ddClear('aiEditYear', b.dataset.year); $('aiEditYearForm').querySelector('.co-modal-msg').textContent = '';
                    openModal('modalEditYear');
                };
                guard($('aiEditYearForm'), function () { return val('aiEditYear_input') ? '' : 'กรุณาเลือกปีงบประมาณใหม่'; });

                window.aiCopyYear = function (b) {
                    if (!$('modalCopyYear')) return;
                    $('aiCopyTarget').value = b.dataset.id;
                    $('aiCopyTargetLabel').textContent = b.dataset.year;
                    ddClear('aiCopySrc', b.dataset.id); $('aiCopyForm').querySelector('.co-modal-msg').textContent = '';
                    openModal('modalCopyYear');
                };
                guard($('aiCopyForm'), function () { return val('aiCopySrc_input') ? '' : 'กรุณาเลือกปีต้นทาง'; });

                window.aiDeleteYear = function (b) {
                    confirmDelete({ title: 'ลบปีงบประมาณ?', message: b.dataset.msg, confirmText: 'ลบปีงบประมาณ' }).then(function (ok) {
                        if (ok && $('aiDelYear' + b.dataset.id)) $('aiDelYear' + b.dataset.id).submit();
                    });
                };

                // ── หมวด ──
                guard($('aiGroupForm'), function () {
                    if (!val('aiGroupScope_input')) return 'กรุณาเลือกขอบเขต';
                    if (!val('aiGroupName')) return 'กรุณากรอกชื่อหมวด';
                    return '';
                });
                window.aiDeleteGroup = function (b) {
                    if (b.disabled) return;
                    confirmDelete({ title: 'ลบหมวด?', message: 'ลบหมวด "' + b.dataset.name + '" ออกจากระบบ?', confirmText: 'ลบหมวด' }).then(function (ok) {
                        if (!ok) return;
                        var f = $('aiGroupDelForm');
                        f.querySelector('[name="group_id"]').value = b.dataset.id;
                        f.submit();
                    });
                };
                // เลื่อนลำดับ: สลับแถวทันทีพร้อมแอนิเมชัน (FLIP) แล้วบันทึกเบื้องหลัง — ไม่สำเร็จ → สลับกลับ
                window.aiMoveGroup = function (btn, dir) {
                    var row = btn.closest('.oe-gm-row');
                    var other = dir === 'up' ? row.previousElementSibling : row.nextElementSibling;
                    if (!other || !other.classList.contains('oe-gm-row')) return;
                    function swap(a, b, up) {
                        var ra = a.getBoundingClientRect().top, rb = b.getBoundingClientRect().top;
                        if (up) a.parentNode.insertBefore(a, b); else a.parentNode.insertBefore(b, a);
                        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                        if (reduce) return;
                        [[a, ra], [b, rb]].forEach(function (p) {
                            var dy = p[1] - p[0].getBoundingClientRect().top;
                            p[0].style.transition = 'none'; p[0].style.transform = 'translateY(' + dy + 'px)';
                            void p[0].offsetHeight;
                            p[0].style.transition = 'transform .3s cubic-bezier(.2,.8,.2,1)'; p[0].style.transform = '';
                        });
                    }
                    swap(row, other, dir === 'up');
                    var fd = new FormData();
                    fd.append('action', 'move_group'); fd.append('group_id', row.dataset.id); fd.append('direction', dir);
                    fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
                    fetch('items.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (res) { if (!res.ok) throw new Error(res.msg); })
                        .catch(function () { swap(row, other, dir !== 'up'); });
                };

                <?php if (isset($_GET['groups'])): ?>openModal('modal-groups');<?php endif; ?>
            })();
        </script>
        <?php include __DIR__ . '/../components/confirm_modal.php'; ?>
    </main>

    <script src="<?= $root ?>assets/js/session-timer.js<?= asset_v('assets/js/session-timer.js') ?>"></script>
</body>

</html>
