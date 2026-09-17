<?php
/**
 * SHARED — ③ กรอกปริมาณ (data_entry_items.php) ใช้ร่วม officer / admin
 * -----------------------------------------------------------
 * ผู้เรียกกำหนดก่อน include: $pdo, $root, $SIDEBAR, $HEADER, $ENTRY_ADMIN (bool) — สิทธิ์ตรวจแล้วจากผู้เรียก
 * กรอกปริมาณรายการการดำเนินงานของหมวดที่เลือก (user_item.source = 'officer')
 * ยอดรายแถว / รายหมวด / รวม คำนวณสดด้วย assets/js/data-entry.js และเตือนเมื่อออกจากหน้าก่อนบันทึก
 *
 * officer: หน่วยงานของตัวเอง · กรอกได้แค่ปริมาณ + หลักฐาน (POST แก้ Emission Factor → 403)
 * admin  : เลือกหน่วยงานได้ (?affil=) + เพิ่ม/แก้/ลบรายการ Emission Factor ของปีนี้
 */
require_once __DIR__ . '/officer_entry.php';
require_once __DIR__ . '/admin_items_entry.php';

$ENTRY_ADMIN = !empty($ENTRY_ADMIN);

$selected_year    = (int) ($_POST['year'] ?? $_GET['year'] ?? 0);
$scope_groups_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['scope_groups'] ?? $_GET['scope_groups'] ?? [])))));
// query ของหมวดแบบ scope_groups[]=1&scope_groups[]=2 — ไม่ใช้ http_build_query (ได้ scope_groups[0]=1 ซึ่ง JS ที่อ่าน 'scope_groups[]' หาไม่เจอ)
$groups_qs = implode('&', array_map(fn($id) => 'scope_groups[]=' . $id, $scope_groups_ids));

if ($ENTRY_ADMIN) {
    $affils     = admin_affiliations($pdo);
    $picked     = admin_pick_affiliation($affils, $_POST['affil'] ?? $_GET['affil'] ?? 0, (int) ($_SESSION['affiliation_id'] ?? 0));
    $affiliation_id = $picked['id'];
    $affil_name = $picked['name'];
    $aq = '&affil=' . $affiliation_id;
} else {
    $affiliation_id = (int) ($_SESSION['affiliation_id'] ?? 0);
    $affil_name = $_SESSION['affiliation_name'] ?? '...';
    $aq = '';
}
$self_qs  = 'year=' . $selected_year . $aq . '&' . $groups_qs;
$redirect = fn(string $msg, string $type = 'success', string $hash = '', string $extra = '') => header('Location: data_entry_items.php?' . $self_qs . $extra
    . '&msg=' . urlencode($msg) . ($type === 'danger' ? '&msg_type=danger' : '') . $hash);

$ef_actions = ['add_item', 'edit_item', 'delete_item', 'add_custom_item'];
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

// ── การแก้ไข "ข้อมูลอ้างอิงกลาง" (admin_item) เป็นสิทธิ์ของ admin เท่านั้น ──
// เจ้าหน้าที่กรอกได้แค่ปริมาณ (save) และแนบหลักฐาน — บล็อก POST ตรงที่พยายามเพิ่ม/แก้/ลบ Emission Factor
if (!$ENTRY_ADMIN && in_array($action, $ef_actions, true)) {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

if ($selected_year <= 0 || empty($scope_groups_ids)) {
    header('Location: data_entry.php?year=' . $selected_year . $aq);
    exit;
}

// ── บันทึก: upsert เฉพาะรายการการดำเนินงานของปีนี้ (กัน id ปลอม/ข้ามปี), ปริมาณผ่าน officer_clean_vol ──
if ($action === 'save') {
    try {
        $pdo->beginTransaction();
        officer_save_vols($pdo, $affiliation_id, $selected_year, (array) ($_POST['vol'] ?? []));
        $pdo->commit();
        $redirect('บันทึกข้อมูลสำเร็จ');
    } catch (Exception $e) {   // PDOException + ค่าที่กรอกไม่ถูกต้อง (officer_require_valid_vols)
        if ($pdo->inTransaction()) $pdo->rollBack();
        $redirect('เกิดข้อผิดพลาด: ' . safe_error_message($e), 'danger');
    }
    exit;
}

// ── admin: เพิ่ม / แก้ / ลบ รายการ Emission Factor ของปีนี้ (ตรวจว่ารายการเป็นของปีนี้ทุกครั้ง) ──
if ($ENTRY_ADMIN && in_array($action, ['add_item', 'edit_item', 'delete_item'], true)) {
    $gid = (int) ($_POST['group_id'] ?? 0);
    $show_group = fn(int $g) => in_array($g, $scope_groups_ids, true) ? '' : '&scope_groups[]=' . $g;
    try {
        if ($action === 'add_item') {
            admin_item_add($pdo, $selected_year, $_POST);
            // หมวดที่เพิ่มรายการต้องแสดงในหน้านี้ด้วย
            $redirect('เพิ่มรายการ "' . trim((string) $_POST['name_tiem']) . '" แล้ว', 'success', '#section-' . $gid, $show_group($gid));
        } elseif ($action === 'edit_item') {
            if (!admin_item_update($pdo, $selected_year, (int) ($_POST['item_id'] ?? 0), $_POST)) throw new Exception('ไม่พบรายการในปีนี้');
            $redirect('แก้ไขรายการแล้ว', 'success', '#section-' . $gid, $show_group($gid));
        } else {
            if (!admin_item_delete($pdo, $selected_year, (int) ($_POST['item_id'] ?? 0))) throw new Exception('ไม่พบรายการในปีนี้');
            $redirect('ลบรายการแล้ว');
        }
    } catch (Exception $e) {
        $redirect('เกิดข้อผิดพลาด: ' . safe_error_message($e), 'danger');
    }
    exit;
}

$stmt = $pdo->prepare('SELECT year FROM admin_year WHERE id = ?');
$stmt->execute([$selected_year]);
$year_name = (string) ($stmt->fetchColumn() ?: 'ไม่ระบุ');

$all_available_groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();
$stats = officer_group_stats($pdo, $affiliation_id, $selected_year);
$meta  = officer_scope_meta();

$in = implode(',', array_fill(0, count($scope_groups_ids), '?'));
$stmt = $pdo->prepare("SELECT * FROM admin_g WHERE id IN ($in) ORDER BY scope, order_num ASC, id ASC");
$stmt->execute($scope_groups_ids);
$groups = $stmt->fetchAll();

// รายการ (Emission Factor) ของหมวดที่เลือก
$items_by_group = [];
$stmt = $pdo->prepare("SELECT ai.*, ag.id AS group_id
                       FROM admin_item ai JOIN admin_g ag ON ag.id = ai.scope
                       WHERE ai.year_id = ? AND ai.scope IN ($in) AND ai.data_source = 'officer'
                       ORDER BY ai.id ASC");
$stmt->execute(array_merge([$selected_year], $scope_groups_ids));
foreach ($stmt->fetchAll() as $item) $items_by_group[$item['group_id']][] = $item;

// ปริมาณที่กรอกไว้ + หลักฐาน (เฉพาะการดำเนินงาน — กิจกรรม/แบบสอบถามอยู่หน้าของมันเอง)
$existing_data = $evidence_counts = $user_item_ids = [];
$stmt = $pdo->prepare("
    SELECT ui.id, ui.admin_item_id, ui.Vol,
           (SELECT COUNT(*) FROM evidence WHERE entity_type = 'user_item' AND entity_id = ui.id) AS ev_count
    FROM user_item ui
    WHERE ui.year_id = ? AND ui.affiliation_id = ? AND ui.source = 'officer'");
$stmt->execute([$selected_year, $affiliation_id]);
foreach ($stmt->fetchAll() as $ui) {
    $existing_data[$ui['admin_item_id']]   = $ui['Vol'];
    $evidence_counts[$ui['admin_item_id']] = (int) $ui['ev_count'];
    $user_item_ids[$ui['admin_item_id']]   = (int) $ui['id'];
}
$usage = $ENTRY_ADMIN ? admin_item_usage($pdo, $selected_year) : [];

// ยอดเริ่มต้น (JS คำนวณซ้ำทันทีที่โหลด — ค่าจาก PHP กันตัวเลขกะพริบ)
$sum_total = 0.0; $sum_filled = 0; $sum_count = 0; $group_sum = [];
foreach ($groups as $g) {
    $gs = ['total' => 0.0, 'filled' => 0, 'count' => 0];
    foreach ($items_by_group[$g['id']] ?? [] as $it) {
        $v = (float) ($existing_data[$it['id']] ?? 0);
        $gs['count']++; $gs['total'] += $v * $it['AD'] / 1000;
        if ($v > 0) $gs['filled']++;
    }
    $group_sum[$g['id']] = $gs;
    $sum_total += $gs['total']; $sum_filled += $gs['filled']; $sum_count += $gs['count'];
}

$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
$page_title3 = "เลือกขอบเขตกิจกรรม $year_name";
$page_title4 = "กรอกข้อมูลขอบเขตกิจกรรม $year_name";
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
// tCO₂e รายแถว: ทศนิยม 4 ตำแหน่งคงที่ ตรงกับที่ data-entry.js แสดงตอนพิมพ์ (ตัวเลขไม่เปลี่ยนรูปเมื่อเริ่มแก้)
$fmt4 = fn(float $n) => $n == 0 ? '-' : number_format($n, 4);
$ef_txt = fn($ad) => rtrim(rtrim(number_format((float) $ad, OFFICER_EF_DECIMALS, '.', ','), '0'), '.');   // EF ตัดศูนย์ท้าย แสดงได้ถึง 6 ตำแหน่ง (decimal(13,6))
$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
$svg_edit  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
$svg_del   = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
$svg_plus  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กรอกปริมาณ — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
</head>

<body>

    <?php include_once $SIDEBAR; ?>

    <main class="main-content">
        <?php include_once $HEADER; ?>

        <div class="oe-page">
            <a href="data_entry.php?year=<?= $selected_year . $aq ?>" class="oe-back">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                ย้อนกลับไปหน้าเลือกหมวด
            </a>
            <?php
                $toast_msg  = $_GET['msg'] ?? '';
                $toast_type = (($_GET['msg_type'] ?? '') === 'danger' || str_contains($toast_msg, 'ผิดพลาด')) ? 'danger' : 'success';
                if (str_contains($toast_msg, 'บันทึกข้อมูลสำเร็จ')) $toast_msg = '';   // มีหน้าต่างสำเร็จแล้ว ไม่ต้องซ้ำด้วย toast
                include __DIR__ . '/../components/toast.php';
            ?>
            <?= officer_entry_steps(3, $selected_year, $aq) ?>

            <div class="oe-head">
                <div>
                    <h1 class="oe-title">กรอกปริมาณการดำเนินงาน</h1>
                    <div class="oe-sub">หน่วยงาน: <?= htmlspecialchars($affil_name) ?> · ปีงบประมาณ <?= htmlspecialchars($year_name) ?> · กรอกเป็นปริมาณต่อปี ช่องว่าง = ยังไม่กรอก</div>
                </div>
                <div class="oe-actions">
                    <?php if ($ENTRY_ADMIN): ?>
                    <span class="oe-affil-label">หน่วยงาน</span>
                    <?php
                    $dd_id          = 'oeAffil';
                    $dd_name        = 'affil_nav';
                    $dd_options     = array_map(fn($a) => ['value' => $a['id'], 'label' => $a['name']], $affils);
                    $dd_selected    = $affiliation_id;
                    $dd_placeholder = 'เลือกหน่วยงาน';
                    $dd_required    = false;
                    $dd_class       = 'dd-field';
                    $dd_style       = 'width:280px;max-width:100%;';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
                    <?php endif; ?>
                    <button type="button" class="oe-btn oe-btn-ghost" data-open="modal-add-new-scope">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                        เพิ่ม / เปลี่ยนหมวด
                    </button>
                </div>
            </div>

            <form id="main-save-form" method="POST" action="data_entry_items.php?<?= htmlspecialchars($self_qs) ?>"><?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="year" value="<?= $selected_year ?>">
                <?php if ($ENTRY_ADMIN): ?><input type="hidden" name="affil" value="<?= $affiliation_id ?>"><?php endif; ?>
                <?php foreach ($scope_groups_ids as $sgid): ?>
                    <input type="hidden" name="scope_groups[]" value="<?= $sgid ?>">
                <?php endforeach; ?>

                <?php foreach ($groups as $index => $group):
                    $gid   = (int) $group['id'];
                    $sc    = (int) $group['scope'];
                    $m     = $meta[$sc] ?? $meta[3];
                    $items = $items_by_group[$gid] ?? [];
                    $gs    = $group_sum[$gid]; ?>
                <div class="accordion-section oe-sec oe-rise" id="section-<?= $gid ?>" style="--i:<?= $index ?>;--sc:<?= $m['color'] ?>;--sc-soft:<?= $m['soft'] ?>;">
                    <div class="oe-sec-head" onclick="toggleAccordion('section-<?= $gid ?>')" role="button" tabindex="0"
                         onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();toggleAccordion('section-<?= $gid ?>');}">
                        <div class="oe-sec-title">
                            <span class="oe-sec-badge">ขอบเขต <?= $sc ?></span>
                            <div class="oe-sec-name"><?= htmlspecialchars($group['name_tiem']) ?></div>
                        </div>
                        <div class="oe-sec-stats">
                            <div class="oe-sec-stat"><span>กรอกแล้ว</span><b data-de-group-filled="<?= $gid ?>"><?= $gs['filled'] ?> / <?= $gs['count'] ?></b></div>
                            <div class="oe-sec-stat"><span>tCO₂e</span><b data-de-group-total="<?= $gid ?>"><?= $gs['total'] == 0 ? '-' : number_format($gs['total'], 2) ?></b></div>
                            <?php if ($ENTRY_ADMIN): ?>
                            <button type="button" class="oe-add-btn" data-group="<?= $gid ?>" onclick="event.stopPropagation(); oeItemOpen(this);" title="เพิ่มรายการ Emission Factor ในหมวดนี้"><?= $svg_plus ?> เพิ่มรายการ</button>
                            <?php endif; ?>
                            <button type="button" class="oe-hide-btn" data-hide-group="<?= $gid ?>" title="ซ่อนหมวดนี้จากหน้านี้ (ไม่ลบข้อมูลที่บันทึกไว้)">ซ่อนหมวดนี้</button>
                            <span class="oe-chev"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m6 9 6 6 6-6"/></svg></span>
                        </div>
                    </div>
                    <div class="oe-sec-body">
                        <?php if (!$items): ?>
                        <div class="oe-empty" style="margin-top:16px;padding:2rem 1rem;">
                            <p style="margin:0;">ปีนี้ยังไม่มีรายการให้กรอกในหมวดนี้</p>
                            <?php if ($ENTRY_ADMIN): ?>
                            <button type="button" class="oe-btn oe-btn-success" style="margin-top:1rem;" data-group="<?= $gid ?>" onclick="oeItemOpen(this)"><?= $svg_plus ?> เพิ่มรายการแรก</button>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <table class="oe-table">
                            <thead>
                                <tr>
                                    <th>รายการ</th>
                                    <th style="width: 90px;">หน่วย</th>
                                    <th style="width: 150px;">ค่าการปล่อย<br>(kgCO₂e/หน่วย)</th>
                                    <th style="width: 160px;">ปริมาณ / ปี</th>
                                    <th style="width: 150px;">ก๊าซเรือนกระจก<br>(tCO₂e/ปี)</th>
                                    <th style="width: 80px;">หลักฐาน</th>
                                    <?php if ($ENTRY_ADMIN): ?><th style="width: 86px;">จัดการ</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item):
                                    $iid   = (int) $item['id'];
                                    $vol   = (float) ($existing_data[$iid] ?? 0);
                                    $total = $vol * $item['AD'] / 1000;
                                    $ev_count = $evidence_counts[$iid] ?? 0;
                                    $ev_title = htmlspecialchars(json_encode('หลักฐาน: ' . $item['name_tiem'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>
                                <tr class="item-row<?= $vol > 0 ? ' is-filled' : '' ?>">
                                    <td><?= htmlspecialchars($item['name_tiem']) ?></td>
                                    <td class="oe-c" data-label="หน่วย"><?= htmlspecialchars($item['unit']) ?></td>
                                    <td class="oe-c oe-ef" data-label="kgCO₂e/หน่วย" data-ef="<?= ef_input_val($item['AD']) ?>"><?= $ef_txt($item['AD']) ?></td>
                                    <td class="oe-vol" data-label="ปริมาณ / ปี (<?= htmlspecialchars($item['unit']) ?>)">
                                        <input type="text" inputmode="decimal" autocomplete="off" name="vol[<?= $iid ?>]" class="vol-input oe-input"
                                            data-group="<?= $gid ?>" data-ef="<?= ef_input_val($item['AD']) ?>" aria-label="ปริมาณต่อปี: <?= htmlspecialchars($item['name_tiem']) ?>"
                                            value="<?= $vol != 0 ? htmlspecialchars(rtrim(rtrim(number_format($vol, 4, '.', ''), '0'), '.')) : '' ?>" placeholder="0">
                                        <span class="de-err" aria-live="polite"></span>
                                    </td>
                                    <td data-label="tCO₂e/ปี"><span class="total-pill"><?= $fmt4($total) ?></span></td>
                                    <td class="oe-c" data-label="หลักฐาน">
                                        <?php if (!$ENTRY_ADMIN): ?>
                                        <button type="button" class="ev-open-btn"
                                            data-ev="user_item_legacy:<?= $iid ?>:<?= $selected_year ?>"
                                            onclick="openEvidence({adminItemId:<?= $iid ?>, yearId:<?= $selected_year ?>, title:<?= $ev_title ?>})"
                                            title="แนบไฟล์หลักฐาน">
                                        <?php elseif (isset($user_item_ids[$iid])): /* admin: อ้างอิงแถวของหน่วยงานที่เลือกตรง ๆ (แบบ legacy ใช้หน่วยงานของ admin เอง) */ ?>
                                        <button type="button" class="ev-open-btn"
                                            data-ev="user_item:<?= $user_item_ids[$iid] ?>"
                                            onclick="openEvidence({type:'user_item', id:<?= $user_item_ids[$iid] ?>, title:<?= $ev_title ?>})"
                                            title="แนบไฟล์หลักฐาน">
                                        <?php else: ?>
                                        <button type="button" class="ev-open-btn" disabled title="บันทึกปริมาณของหน่วยงานนี้ก่อน จึงแนบหลักฐานได้">
                                        <?php endif; ?>
                                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                            <?php if ($ev_count > 0): ?><span class="ev-badge"><?= $ev_count ?></span><?php endif; ?>
                                        </button>
                                    </td>
                                    <?php if ($ENTRY_ADMIN):
                                        $used = $usage[$iid] ?? 0;
                                        $del_msg = 'ลบรายการ "' . $item['name_tiem'] . '" ออกจากปี ' . $year_name . '?'
                                            . ($used ? "\nปริมาณที่กรอกไว้ของ $used หน่วยงานจะถูกลบไปด้วย" : "\nยังไม่มีหน่วยงานกรอกปริมาณรายการนี้"); ?>
                                    <td class="oe-c" data-label="จัดการ">
                                        <span class="oe-tools">
                                            <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขรายการ" aria-label="แก้ไข <?= $h($item['name_tiem']) ?>"
                                                data-id="<?= $iid ?>" data-group="<?= $gid ?>" data-name="<?= $h($item['name_tiem']) ?>" data-unit="<?= $h($item['unit']) ?>" data-ad="<?= ef_input_val($item['AD']) ?>"
                                                onclick="oeItemOpen(this)"><?= $svg_edit ?></button>
                                            <button type="button" class="oe-icon-btn oe-icon-del" title="ลบรายการ" aria-label="ลบ <?= $h($item['name_tiem']) ?>"
                                                data-id="<?= $iid ?>" data-msg="<?= $h($del_msg) ?>" onclick="oeItemDelete(this)"><?= $svg_del ?></button>
                                        </span>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- แถบสรุปติดจอ: ยอดรวมสด + สถานะยังไม่บันทึก + ปุ่มบันทึก -->
                <div class="oe-dock">
                    <div class="oe-dock-info">
                        <div class="oe-dock-stat"><span>ยอดรวมหมวดที่เลือก</span><b class="is-purple"><span data-de-total><?= $sum_total == 0 ? '-' : number_format($sum_total, 2) ?></span> tCO₂e</b></div>
                        <div class="oe-dock-stat"><span>กรอกแล้ว</span><b><span data-de-filled><?= $sum_filled ?> / <?= $sum_count ?></span> รายการ</b></div>
                        <span class="oe-dirty" data-de-dirty hidden>● ยังไม่บันทึก</span>
                        <span class="oe-msg" data-de-msg role="alert"></span>
                    </div>
                    <button type="button" class="oe-btn oe-btn-success" onclick="deSave()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        บันทึกข้อมูล
                    </button>
                </div>
            </form>
        </div>

        <!-- Modal: เพิ่ม / เปลี่ยนหมวด -->
        <div class="modal-overlay" id="modal-add-new-scope">
            <div class="modal-box co-modal oe-scope-modal">
                <button type="button" class="oe-modal-x" data-close aria-label="ปิด"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                <div class="modal-title"><?= ic('folder', 22) ?><span>เลือกหมวดที่ต้องการกรอก</span></div>
                <p class="oe-modal-lead">ติ๊กหมวดที่ต้องการแสดงในหน้านี้</p>
                <input type="search" id="scope-search" class="oe-search" placeholder="ค้นหาชื่อหมวด..." aria-label="ค้นหาชื่อหมวด">
                <div class="oe-modal-groups">
                    <?php foreach ($all_available_groups as $ag):
                        $sc = (int) $ag['scope']; $m = $meta[$sc] ?? $meta[3];
                        $st = $stats[(int) $ag['id']] ?? ['items' => 0, 'filled' => 0];
                        $empty = $st['items'] === 0;
                        $lock  = $empty && !$ENTRY_ADMIN; ?>
                    <label class="oe-group scope-select-row<?= $empty ? ' is-empty' : '' ?><?= $lock ? '' : ' is-pickable' ?>" data-name="<?= htmlspecialchars($ag['name_tiem']) ?>" style="--sc:<?= $m['color'] ?>;--sc-soft:<?= $m['soft'] ?>;--sc-line:<?= $m['color'] ?>66;">
                        <input type="checkbox" class="scope-checkbox" value="<?= (int) $ag['id'] ?>" <?= in_array((int) $ag['id'], $scope_groups_ids, true) ? 'checked' : '' ?><?= $lock ? ' disabled' : '' ?>>
                        <span class="oe-check"><?= $check_svg ?></span>
                        <span class="oe-group-body">
                            <span class="oe-sec-badge">ขอบเขต <?= $sc ?></span>
                            <span class="oe-group-name"><?= htmlspecialchars($ag['name_tiem']) ?></span>
                            <span class="oe-group-meta"><span><?= $empty ? 'ยังไม่มีรายการในปีนี้' : 'กรอกแล้ว ' . $st['filled'] . ' / ' . $st['items'] . ' รายการ' ?></span></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <span class="oe-msg co-modal-msg" id="scope-modal-msg" role="alert"></span>
                    <button type="button" class="btn-secondary" data-close>ยกเลิก</button>
                    <button type="button" class="btn-primary" id="oeScopeApply">แสดงหมวดที่เลือก</button>
                </div>
            </div>
        </div>


        <?php if ($ENTRY_ADMIN): ?>
        <!-- Modal (admin): เพิ่ม / แก้ไข รายการ Emission Factor -->
        <div class="modal-overlay" id="oeItemModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มรายการ Emission Factor</span></div>
                <form method="POST" action="data_entry_items.php?<?= htmlspecialchars($self_qs) ?>" id="oeItemForm" novalidate><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_item" data-co-action>
                    <input type="hidden" name="year" value="<?= $selected_year ?>">
                    <input type="hidden" name="affil" value="<?= $affiliation_id ?>">
                    <input type="hidden" name="item_id" id="oeItemId">
                    <?php foreach ($scope_groups_ids as $sgid): ?><input type="hidden" name="scope_groups[]" value="<?= $sgid ?>"><?php endforeach; ?>
                    <div class="form-group-dark"><label class="form-label-dark">หมวด *</label>
                        <?php
                        $dd_id          = 'oeItemGroup';
                        $dd_name        = 'group_id';
                        $dd_options     = array_map(fn($g) => ['value' => $g['id'], 'label' => 'ขอบเขต ' . $g['scope'] . ' · ' . $g['name_tiem']], $all_available_groups);
                        $dd_selected    = '';
                        $dd_placeholder = 'เลือกหมวด';
                        $dd_required    = false;   // ตรวจเองใน submit (required ของ component ใช้ alert)
                        $dd_class       = 'dd-field';
                        $dd_style       = 'width:100%;';
                        include __DIR__ . '/../components/dropdown.php';
                        ?>
                    </div>
                    <div class="form-group-dark"><label class="form-label-dark" for="oeItemName">ชื่อรายการ *</label>
                        <input class="form-control-dark" id="oeItemName" name="name_tiem" required maxlength="255" placeholder="เช่น น้ำมันดีเซล (รถยนต์)" autocomplete="off"></div>
                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark" for="oeItemUnit">หน่วย *</label>
                            <input class="form-control-dark" id="oeItemUnit" name="unit" required maxlength="255" placeholder="ลิตร / kWh" autocomplete="off"></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="oeItemAd">ค่าการปล่อย (kgCO₂e/หน่วย) *</label>
                            <input class="form-control-dark" id="oeItemAd" name="AD" type="number" step="any" min="0" max="1000000" required placeholder="0.0000"></div>
                    </div>
                    <div class="oe-note">รายการนี้ใช้กับ<strong>ทุกหน่วยงาน</strong>ในปี <?= htmlspecialchars($year_name) ?> · ชื่อซ้ำกับรายการอื่นในหมวดเดียวกันไม่ได้ · ค่าการปล่อยควรอ้างอิงค่ามาตรฐาน (เช่น TGO)</div>
                    <div class="modal-footer">
                        <span class="oe-msg co-modal-msg" role="alert"></span>
                        <button type="button" class="btn-secondary" data-close>ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มรายการ</button>
                    </div>
                </form>
            </div>
        </div>
        <form method="POST" action="data_entry_items.php?<?= htmlspecialchars($self_qs) ?>" id="oeItemDelForm" hidden><?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_item"><input type="hidden" name="year" value="<?= $selected_year ?>">
            <input type="hidden" name="affil" value="<?= $affiliation_id ?>"><input type="hidden" name="item_id" value="">
            <?php foreach ($scope_groups_ids as $sgid): ?><input type="hidden" name="scope_groups[]" value="<?= $sgid ?>"><?php endforeach; ?>
        </form>
        <?php endif; ?>

        <?php if (str_contains($_GET['msg'] ?? '', 'บันทึกข้อมูลสำเร็จ')): ?>
        <!-- Modal: บันทึกสำเร็จ (เปิดทันทีหลัง redirect) -->
        <div class="modal-overlay" id="modal-save-success" data-open-on-load>
            <div class="modal-box co-modal is-center">
                <div class="oe-done-ic"><svg viewBox="0 0 24 24" width="40" height="40" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                <h3 class="ai-swap-title">บันทึกข้อมูลสำเร็จ!</h3>
                <p class="oe-modal-text">กรอกแล้ว <?= $sum_filled ?> / <?= $sum_count ?> รายการในหมวดที่เลือก</p>
                <div class="oe-modal-stack">
                    <button type="button" class="oe-btn oe-btn-success" data-close>ตกลง, กรอกต่อ</button>
                    <a href="index.php" class="oe-btn oe-btn-ghost">กลับหน้า Dashboard</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <script src="<?= $root ?>assets/js/data-entry.js<?= asset_v('assets/js/data-entry.js') ?>"></script>
        <script>
            // ประกาศผ่าน window / var — ไม่มี let/const ระดับบนสุด (กันประกาศซ้ำเมื่อสคริปต์ถูกรันซ้ำ)
            window.openModal = function (id) { var m = document.getElementById(id); if (m) { m.classList.add('open'); m.style.display = 'flex'; var b = m.querySelector('.modal-box'); if (b) { b.style.animation = 'none'; void b.offsetWidth; b.style.animation = ''; } } };
            window.closeModal = function (id) { var m = document.getElementById(id); if (m) { m.classList.remove('open'); m.style.display = 'none'; } };

            // accordion: จำหมวดที่เปิดไว้ข้ามการ reload หลังบันทึก — ครั้งแรกเปิดหมวดแรกให้เลย
            var accKey = 'accOpen:' + location.pathname;
            window.toggleAccordion = function (id) {
                document.getElementById(id).classList.toggle('active');
                try {
                    var open = [];
                    document.querySelectorAll('.accordion-section.active').forEach(function (s) { open.push(s.id); });
                    sessionStorage.setItem(accKey, JSON.stringify(open));
                } catch (e) {}
            };
            (function () {
                var restored = false;
                try {
                    var raw = sessionStorage.getItem(accKey);
                    if (raw) JSON.parse(raw).forEach(function (id) { var s = document.getElementById(id); if (s) { s.classList.add('active'); restored = true; } });
                } catch (e) {}
                // กลับมาจากการเพิ่ม/แก้รายการ (#section-N) → เปิดหมวดนั้นและเลื่อนไปหา
                var target = location.hash && document.getElementById(location.hash.slice(1));
                if (target && target.classList.contains('accordion-section')) { target.classList.add('active'); restored = true; setTimeout(function () { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 80); }
                if (!restored) { var first = document.querySelector('.accordion-section'); if (first) first.classList.add('active'); }
            })();

            deInit(document.getElementById('main-save-form'));

            // ค้นหาหมวดในหน้าต่าง
            document.getElementById('scope-search').addEventListener('input', function (e) {
                var term = e.target.value.trim().toLowerCase();
                document.querySelectorAll('.scope-select-row').forEach(function (row) {
                    row.style.display = row.dataset.name.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
                });
            });

            // แสดงหมวดที่เลือก (การ์ดเป็น <label> ครอบ checkbox → คลิกตรงไหนก็ติ๊กได้) · ไม่เลือก → ข้อความ + สั่น
            document.getElementById('oeScopeApply').addEventListener('click', function () {
                var ids = Array.prototype.map.call(document.querySelectorAll('.scope-checkbox:checked'), function (cb) { return cb.value; });
                if (ids.length === 0) {
                    document.getElementById('scope-modal-msg').textContent = 'กรุณาเลือกอย่างน้อย 1 หมวด';
                    var box = this.closest('.modal-box');
                    box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                    return;
                }
                var params = new URLSearchParams(window.location.search);
                ['scope_groups[]', 'msg', 'msg_type'].forEach(function (k) { params.delete(k); });
                ids.forEach(function (id) { params.append('scope_groups[]', id); });
                window.location.href = 'data_entry_items.php?' + params.toString();
            });

            // ซ่อนหมวด (ไม่ลบข้อมูล) — ยืนยันด้วย confirmDelete · ปุ่มอยู่บนหัว accordion จึงหยุด event ไม่ให้หมวดยุบ/กาง
            var ICON_HIDE = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            document.querySelectorAll('[data-hide-group]').forEach(function (b) {
                b.addEventListener('click', function (e) {
                    e.stopPropagation();
                    confirmDelete({ title: 'ซ่อนหมวดนี้?', message: 'หมวดจะหายจากหน้านี้เท่านั้น ข้อมูลที่บันทึกไว้แล้วยังอยู่ครบ เลือกกลับมาแสดงได้จากปุ่ม "เพิ่ม / เปลี่ยนหมวด"', confirmText: 'ซ่อนหมวด', icon: ICON_HIDE }).then(function (ok) {
                        if (!ok) return;
                        var params = new URLSearchParams(window.location.search);
                        var groups = params.getAll('scope_groups[]');
                        ['scope_groups[]', 'msg', 'msg_type'].forEach(function (k) { params.delete(k); });
                        groups.forEach(function (id) { if (id !== b.dataset.hideGroup) params.append('scope_groups[]', id); });
                        // ซ่อนหมวดสุดท้าย → กลับไปเลือกหมวดใหม่
                        window.location.href = params.getAll('scope_groups[]').length ? 'data_entry_items.php?' + params.toString() : 'data_entry.php?year=<?= $selected_year . $aq ?>';
                    });
                });
            });

            // เปิด: [data-open] · ปิด: [data-close] / คลิกฉากหลัง · หน้าต่างผลการบันทึกเปิดทันที
            document.querySelectorAll('[data-open]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var msg = document.getElementById('scope-modal-msg'); if (msg) msg.textContent = '';
                    openModal(b.dataset.open);
                });
            });
            document.querySelectorAll('main .modal-overlay').forEach(function (el) {
                el.addEventListener('click', function (e) { if (e.target === el || e.target.closest('[data-close]')) closeModal(el.id); });
                if (el.hasAttribute('data-open-on-load')) openModal(el.id);
            });
            <?php if ($ENTRY_ADMIN): ?>

            // ── admin: รายการ Emission Factor ──
            (function () {
                var ICON_ADD = <?= json_encode(ic('add', 22)) ?>, ICON_EDIT = <?= json_encode(ic('edit', 22)) ?>;
                var GROUP_LABEL = <?= json_encode(array_column(array_map(fn($g) => ['id' => (int) $g['id'], 'l' => 'ขอบเขต ' . $g['scope'] . ' · ' . $g['name_tiem']], $all_available_groups), 'l', 'id'), JSON_UNESCAPED_UNICODE) ?>;
                var m = document.getElementById('oeItemModal'), f = document.getElementById('oeItemForm');
                var msg = f.querySelector('.co-modal-msg');

                window.oeItemOpen = function (b) {
                    var edit = !!b.dataset.id;
                    f.reset(); msg.textContent = '';
                    f.querySelector('[data-co-action]').value = edit ? 'edit_item' : 'add_item';
                    m.querySelector('[data-co-title]').textContent = edit ? 'แก้ไขรายการ Emission Factor' : 'เพิ่มรายการ Emission Factor';
                    m.querySelector('[data-co-icon]').innerHTML = edit ? ICON_EDIT : ICON_ADD;
                    m.querySelector('[data-co-submit]').textContent = edit ? 'บันทึกการแก้ไข' : 'เพิ่มรายการ';
                    document.getElementById('oeItemId').value = edit ? b.dataset.id : '';
                    document.getElementById('oeItemName').value = edit ? b.dataset.name : '';
                    document.getElementById('oeItemUnit').value = edit ? b.dataset.unit : '';
                    document.getElementById('oeItemAd').value = edit ? b.dataset.ad : '';
                    ddSetValue('oeItemGroup', b.dataset.group, GROUP_LABEL[b.dataset.group] || '');
                    openModal('oeItemModal');
                    setTimeout(function () { document.getElementById('oeItemName').focus(); }, 60);
                };

                // ตรวจก่อนส่ง (กฎเดียวกับ admin_item_clean) — ผิดแล้วสั่นหน้าต่าง ไม่ใช้ alert
                f.addEventListener('submit', function (e) {
                    var err = '';
                    var ad = document.getElementById('oeItemAd').value.trim();
                    if (!document.getElementById('oeItemGroup_input').value) err = 'กรุณาเลือกหมวด';
                    else if (!document.getElementById('oeItemName').value.trim()) err = 'กรุณากรอกชื่อรายการ';
                    else if (!document.getElementById('oeItemUnit').value.trim()) err = 'กรุณากรอกหน่วย';
                    else if (ad === '' || isNaN(Number(ad))) err = 'กรุณากรอกค่าการปล่อยเป็นตัวเลข';
                    else if (Number(ad) < 0) err = 'ค่าการปล่อยต้องไม่ติดลบ';
                    else if (Number(ad) > 1000000) err = 'ค่าการปล่อยเกิน 1,000,000';
                    if (!err) return;
                    e.preventDefault();
                    msg.textContent = err;
                    var box = m.querySelector('.modal-box');
                    box.classList.remove('oe-shake'); void box.offsetWidth; box.classList.add('oe-shake');
                });

                window.oeItemDelete = function (b) {
                    confirmDelete({ title: 'ลบรายการ Emission Factor?', message: b.dataset.msg, confirmText: 'ลบรายการ' }).then(function (ok) {
                        if (!ok) return;
                        var d = document.getElementById('oeItemDelForm');
                        d.querySelector('[name="item_id"]').value = b.dataset.id;
                        d.submit();
                    });
                };

                // เปลี่ยนหน่วยงาน → โหลดหน้าเดิม (หมวดเดิม) ของหน่วยงานนั้น — ถ้ายังไม่บันทึก เบราว์เซอร์จะถามก่อน
                document.getElementById('oeAffil').addEventListener('dd:change', function (e) {
                    if (String(e.detail.value) === '<?= $affiliation_id ?>') return;
                    var params = new URLSearchParams(window.location.search);
                    ['affil', 'msg', 'msg_type'].forEach(function (k) { params.delete(k); });
                    params.set('affil', e.detail.value);
                    location.href = 'data_entry_items.php?' + params.toString();
                });
            })();
            <?php endif; ?>
        </script>

        <!-- Evidence modal (component กลาง) — รูปภาพ/เอกสาร (ไม่มีลิงก์) -->
        <?php $ev_show_link = false; include __DIR__ . '/../components/evidence_modal.php'; ?>
        <?php include __DIR__ . '/../components/confirm_modal.php'; ?>
    </main>

</body>

</html>
