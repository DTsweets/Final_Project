<?php
/**
 * SHARED — หน้า GHG Removal (การดูดกลับก๊าซเรือนกระจก) ระดับส่วนกลาง/มหาวิทยาลัย
 * ผู้เรียกกำหนดก่อน include: $pdo, $root, $SIDEBAR, $HEADER
 * (สิทธิ์ตรวจแล้วจากผู้เรียก: admin หรือ officer ของศูนย์สิ่งแวดล้อม affil=1 เท่านั้น)
 *
 * หน้าตา: การ์ดสรุป 3 ใบ (ส่วนกลาง / จากกิจกรรม / รวม) → ตารางรายการ (คำนวณสด + แถบสัดส่วน + แถบบันทึกติดขอบล่าง)
 * เพิ่ม/แก้ไข/คัดลอกจากปีอื่น อยู่ในหน้าต่าง · สไตล์: officer-entry.css + collect.css · ตาราง: data-entry.js
 */
require_once __DIR__ . '/ghg_report.php';
require_once __DIR__ . '/removal_entry.php';

$years = $pdo->query("SELECT id AS year_id, year FROM admin_year ORDER BY year DESC")->fetchAll();
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : ($years[0]['year_id'] ?? 0);

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pyear  = (int) ($_POST['year_id'] ?? $selected_year);
    $redir  = "?year=$pyear";
    $back   = fn(string $msg) => "Location: $redir&msg=" . urlencode($msg) . '#rm-items';
    try {
        if ($action === 'add_removal_item') {
            removal_add_item($pdo, $pyear, $_POST);
            header($back('เพิ่มรายการดูดกลับแล้ว')); exit;
        }
        if ($action === 'edit_removal_item') {
            if (!removal_update_item($pdo, $pyear, (int) ($_POST['item_id'] ?? 0), $_POST)) throw new Exception('ไม่พบรายการในปีนี้');
            header($back('แก้ไขรายการแล้ว')); exit;
        }
        if ($action === 'delete_removal_item') {
            if (!removal_delete_item($pdo, $pyear, (int) ($_POST['item_id'] ?? 0))) throw new Exception('ไม่พบรายการในปีนี้');
            header($back('ลบรายการแล้ว')); exit;
        }
        if ($action === 'save_removal') {
            // อัปเดต qty ตรงบน removal_item (รวม removal_entry เข้ามาแล้ว) เฉพาะรายการของปีนั้น
            removal_save_qty($pdo, $pyear, $_POST['qty'] ?? []);
            header($back('บันทึกปริมาณดูดกลับแล้ว')); exit;
        }
        if ($action === 'copy_removal_items') {
            $from = (int) ($_POST['source_year_id'] ?? 0);
            $src  = current(array_filter(removal_copy_sources($pdo, $pyear), fn($s) => $s['year_id'] === $from));
            if (!$src) throw new Exception('กรุณาเลือกปีที่มีรายการดูดกลับ');
            $n = removal_copy_items($pdo, $from, $pyear);
            header($back($n > 0 ? "คัดลอก $n รายการจากปี {$src['year']} แล้ว" : "ไม่มีรายการใหม่ให้คัดลอก (ชื่อซ้ำกับปีนี้ทั้งหมด)")); exit;
        }
    } catch (Exception $e) {
        header("Location: $redir&msg=" . urlencode('เกิดข้อผิดพลาด: ' . safe_error_message($e)) . "&msg_type=danger"); exit;
    }
}

$flash   = $_GET['msg'] ?? '';
$flash_t = (($_GET['msg_type'] ?? '') === 'danger') ? 'danger' : 'success';
$rows           = removal_items_list($pdo, $selected_year);        // ส่วนกลาง (แก้ไขได้)
$central_total  = removal_central_total($pdo, $selected_year);
$activity_total = removal_activity_total($pdo, $selected_year);   // จากกิจกรรม (แก้ที่หน้า แบบสอบถาม & กิจกรรม)
$copy_sources   = removal_copy_sources($pdo, $selected_year);
$filled         = count(array_filter($rows, fn($r) => (float) $r['qty'] > 0));
$year_label     = '';
foreach ($years as $y) if ((int) $y['year_id'] === $selected_year) $year_label = (string) $y['year'];
$page_title  = 'กรอกข้อมูล';
$page_title2 = 'GHG Removal';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$fmt = fn(float $n, int $d = 3) => $n == 0 ? '-' : number_format($n, $d);   // ตรงกับ deFormat ใน data-entry.js
$pct = fn(float $part) => $central_total > 0 ? round($part / $central_total * 100, 1) : 0;
$svg_edit = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
$svg_del  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
$svg_save = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>';
$arrow = '<span class="oe-arrow"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg></span>';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHG Removal — UP Net Zero</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
</head>
<body style="background:#F6F4F9;">
    <?php include $SIDEBAR; ?>
    <main class="main-content">
        <?php include $HEADER; ?>
        <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

        <div class="oe-page co-page rm-page">
            <div class="oe-head oe-rise">
                <div>
                    <h1 class="oe-title">GHG Removal</h1>
                    <div class="oe-sub">การดูดกลับก๊าซเรือนกระจกส่วนกลางของมหาวิทยาลัยพะเยา เช่น ต้นไม้ยืนต้น พื้นที่ป่า</div>
                </div>
                <div class="oe-actions">
                    <span class="co-year-label">ปีงบประมาณ</span>
                    <?php $dd_id='rmYear';$dd_name='year_nav';$dd_options=array_map(fn($y)=>['value'=>$y['year_id'],'label'=>(string)$y['year']],$years);
                        $dd_selected=$selected_year;$dd_required=false;$dd_class='dd-field';$dd_placeholder='เลือกปี';$dd_style='width:120px;';
                        include __DIR__.'/../components/dropdown.php'; ?>
                </div>
            </div>

            <!-- การ์ดสรุป: ส่วนกลาง (หน้านี้) / จากกิจกรรม (ดูอย่างเดียว) / รวม (ตัวเลขเดียวกับ Dashboard) -->
            <div class="co-kpis">
                <div class="co-kpi co-kpi-green oe-rise" style="--i:1;">
                    <span class="co-kpi-ic"><?= ic('leaf', 22) ?></span>
                    <div><span class="co-kpi-label">ดูดกลับส่วนกลาง</span>
                        <b class="co-kpi-val"><span data-rm-kpi="central"><?= $fmt($central_total) ?></span> <small>tCO₂e</small></b>
                        <span class="co-kpi-sub"><?= count($rows) ?> รายการ · กรอกในหน้านี้</span></div>
                </div>
                <div class="co-kpi co-kpi-green oe-rise" style="--i:2;">
                    <span class="co-kpi-ic"><?= ic('note', 22) ?></span>
                    <div><span class="co-kpi-label">ดูดกลับจากกิจกรรม</span>
                        <b class="co-kpi-val"><span data-rm-kpi="activity"><?= $fmt($activity_total) ?></span> <small>tCO₂e</small></b>
                        <a class="co-kpi-sub rm-kpi-link" href="collect.php?year=<?= $selected_year ?>&amp;tab=event" data-de-guard>แก้ที่หน้า แบบสอบถาม &amp; กิจกรรม <?= $arrow ?></a></div>
                </div>
                <div class="co-kpi rm-kpi-total oe-rise" style="--i:3;">
                    <span class="co-kpi-ic"><?= ic('globe', 22) ?></span>
                    <div><span class="co-kpi-label">รวมการดูดกลับทั้งหมด</span>
                        <b class="co-kpi-val"><span data-rm-kpi="total"><?= $fmt($central_total + $activity_total) ?></span> <small>tCO₂e</small></b>
                        <span class="co-kpi-sub">ยอดที่ใช้หักลบบน Dashboard</span></div>
                </div>
            </div>

            <section class="oe-panel co-list rm-panel oe-rise" style="--i:4;" id="rm-items">
                <div class="co-panel-head">
                    <h2 class="co-h2">รายการดูดกลับส่วนกลาง<?= $year_label !== '' ? ' ปี ' . $h($year_label) : '' ?> <span class="co-count"><?= count($rows) ?></span></h2>
                    <div class="oe-actions">
                        <?php if ($copy_sources): ?><button type="button" class="oe-btn oe-btn-ghost" onclick="rmCopyOpen()"><?= ic('copy', 16) ?> คัดลอกจากปีอื่น</button><?php endif; ?>
                        <button type="button" class="oe-btn oe-btn-success" onclick="rmItemOpen(null)"><?= ic('add', 16) ?> เพิ่มรายการ</button>
                    </div>
                </div>

                <?php if (empty($rows)): ?>
                    <div class="oe-empty co-empty">
                        <h3>ยังไม่มีรายการดูดกลับในปีนี้</h3>
                        <p><?= $copy_sources ? 'คัดลอกรายการจากปี ' . $h($copy_sources[0]['year']) . ' (' . $copy_sources[0]['items'] . ' รายการ) หรือเพิ่มรายการใหม่' : 'เพิ่มรายการ เช่น ต้นไม้ยืนต้น พร้อมหน่วยและค่าดูดกลับ' ?></p>
                        <div class="oe-actions" style="justify-content:center;">
                            <?php if ($copy_sources): ?><button type="button" class="oe-btn" onclick="rmCopyOpen()"><?= ic('copy', 16) ?> คัดลอกจากปีอื่น</button><?php endif; ?>
                            <button type="button" class="oe-btn oe-btn-success" onclick="rmItemOpen(null)"><?= ic('add', 16) ?> เพิ่มรายการแรก</button>
                        </div>
                    </div>
                <?php else: ?>
                <div class="co-sec co-sec-green" data-de-scope data-de-digits="3">
                    <form method="POST" id="rmForm"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_removal"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                        <table class="oe-table">
                            <colgroup><col><col style="width:130px;"><col style="width:160px;"><col style="width:112px;"><col style="width:86px;"></colgroup>
                            <thead><tr><th>รายการ · สัดส่วน</th><th>ค่าดูดกลับ<br><span class="oe-muted">kgCO₂e/หน่วย/ปี</span></th><th>ปริมาณ</th><th>tCO₂e</th><th>จัดการ</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $k => $r): $q = (float) $r['qty']; $p = $pct((float) $r['emission']); ?>
                                <tr class="item-row<?= $q > 0 ? ' is-filled' : '' ?>">
                                    <td>
                                        <?= $h($r['name_tiem']) ?><span class="co-row-sub">หน่วย: <?= $h($r['unit'] ?: '-') ?></span>
                                        <span class="rm-share" aria-hidden="true"><span class="rm-share-track"><span class="rm-share-bar" style="width:<?= $p ?>%;--i:<?= min($k, 10) ?>;"></span></span><span class="rm-share-pct"><?= $p ?>%</span></span>
                                    </td>
                                    <td class="oe-c oe-ef" data-label="ค่าดูดกลับ (kgCO₂e/หน่วย/ปี)"><?= ef_fmt($r['factor']) ?></td>
                                    <td class="oe-vol" data-label="ปริมาณ (<?= $h($r['unit'] ?: 'หน่วย') ?>)"><input type="text" inputmode="decimal" autocomplete="off" class="vol-input oe-input" data-group="central" data-ef="<?= (float) $r['factor'] ?>"
                                        name="qty[<?= (int) $r['id'] ?>]" value="<?= $q == 0 ? '' : rtrim(rtrim(number_format($q, 4, '.', ''), '0'), '.') ?>" placeholder="0" aria-label="ปริมาณ: <?= $h($r['name_tiem']) ?>"><span class="de-err" role="alert"></span></td>
                                    <td data-label="tCO₂e"><span class="total-pill"><?= $fmt((float) $r['emission'], 4) ?></span></td>
                                    <td class="oe-c co-tools-cell">
                                        <button type="button" class="oe-icon-btn oe-icon-edit" title="แก้ไขรายการ"
                                            data-id="<?= (int) $r['id'] ?>" data-name="<?= $h($r['name_tiem']) ?>" data-unit="<?= $h($r['unit']) ?>" data-factor="<?= ef_input_val($r['factor']) ?>"
                                            onclick="rmItemOpen(this)"><?= $svg_edit ?></button>
                                        <button type="button" class="oe-icon-btn oe-icon-del" title="ลบรายการ" data-msg="<?= $h('ลบรายการ "' . $r['name_tiem'] . '" พร้อมปริมาณที่กรอกไว้?') ?>"
                                            onclick="rmDelete(<?= (int) $r['id'] ?>, this.dataset.msg)"><?= $svg_del ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="oe-dock">
                            <div class="oe-dock-info">
                                <div class="oe-dock-stat"><span>ยอดดูดกลับส่วนกลาง</span><b class="is-purple"><span data-de-total><?= $fmt($central_total) ?></span> tCO₂e</b></div>
                                <div class="oe-dock-stat"><span>กรอกแล้ว</span><b><span data-de-filled><?= $filled ?> / <?= count($rows) ?></span> รายการ</b></div>
                                <span class="oe-dirty" data-de-dirty hidden>● ยังไม่บันทึก</span>
                                <span class="oe-msg" data-de-msg role="alert"></span>
                            </div>
                            <button type="button" class="oe-btn oe-btn-success" onclick="deSave(this)"><?= $svg_save ?> บันทึกปริมาณ</button>
                        </div>
                    </form>
                    <?php foreach ($rows as $r): ?>
                    <form method="POST" id="delRm<?= (int) $r['id'] ?>" hidden><?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_removal_item"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="item_id" value="<?= (int) $r['id'] ?>">
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- รายการดูดกลับ: เพิ่ม / แก้ไข -->
        <div class="modal-overlay" id="rmItemModal">
            <div class="modal-box co-modal co-modal-wide">
                <div class="modal-title"><span data-co-icon></span><span data-co-title>เพิ่มรายการดูดกลับ</span></div>
                <form method="POST" data-co-form><?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_removal_item" data-co-action><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="item_id" id="rmItemId">
                    <div class="form-group-dark"><label class="form-label-dark" for="rmItemName">ชื่อรายการ *</label>
                        <input class="form-control-dark" id="rmItemName" name="name" required maxlength="255" placeholder="เช่น ต้นไม้ยืนต้น / พื้นที่ป่า" autocomplete="off"></div>
                    <div class="co-form-grid">
                        <div class="form-group-dark"><label class="form-label-dark" for="rmItemUnit">หน่วย *</label>
                            <input class="form-control-dark" id="rmItemUnit" name="unit" required maxlength="255" placeholder="ต้น / ไร่" autocomplete="off"></div>
                        <div class="form-group-dark"><label class="form-label-dark" for="rmItemFactor">ค่าดูดกลับ (kgCO₂e/หน่วย/ปี) *</label>
                            <input class="form-control-dark" id="rmItemFactor" name="factor" type="number" step="any" min="0" max="1000000" required placeholder="0.0000"></div>
                    </div>
                    <div class="oe-note">ค่าดูดกลับควรอ้างอิงค่ามาตรฐาน (เช่น TGO) · ชื่อรายการซ้ำกับรายการอื่นในปีเดียวกันไม่ได้</div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('rmItemModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary" data-co-submit>เพิ่มรายการดูดกลับ</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($copy_sources): ?>
        <!-- คัดลอกรายการจากปีอื่น -->
        <div class="modal-overlay" id="rmCopyModal">
            <div class="modal-box co-modal">
                <div class="modal-title"><?= ic('copy', 22) ?><span>คัดลอกจากปีอื่น</span></div>
                <form method="POST" id="rmCopyForm"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="copy_removal_items"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                    <div class="form-group-dark"><label class="form-label-dark">คัดลอกรายการจากปี → ปี <?= $h($year_label) ?> *</label>
                        <?php $dd_id='rmCopySrc';$dd_name='source_year_id';$dd_options=array_map(fn($s)=>['value'=>$s['year_id'],'label'=>'ปี '.$s['year'].' ('.$s['items'].' รายการ)'],$copy_sources);
                            $dd_selected=$copy_sources[0]['year_id'];$dd_required=true;$dd_class='dd-field';$dd_placeholder='เลือกปีต้นทาง';$dd_style='width:100%;';
                            include __DIR__.'/../components/dropdown.php'; ?>
                    </div>
                    <div class="oe-note">คัดลอกชื่อ หน่วย และค่าดูดกลับ · ปริมาณเริ่มที่ 0 ให้กรอกใหม่ · รายการที่มีชื่อซ้ำกับปีนี้จะถูกข้าม</div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary" onclick="closeModal('rmCopyModal')">ยกเลิก</button>
                        <button type="submit" class="btn-primary">คัดลอกรายการ</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <script src="<?= $root ?>assets/js/data-entry.js<?= asset_v('assets/js/data-entry.js') ?>"></script>
        <script>
        // ประกาศผ่าน window / var / IIFE — SPA รันสคริปต์ซ้ำทุกครั้งที่เข้าหน้านี้
        (function () {
            var d = document;
            var ICON_ADD = <?= json_encode(ic('add', 22)) ?>, ICON_EDIT = <?= json_encode(ic('edit', 22)) ?>;
            var ACTIVITY = <?= json_encode($activity_total) ?>;
            function $(id) { return d.getElementById(id); }

            window.openModal = function (id) { var m = $(id); if (m) { m.classList.add('open'); m.style.display = 'flex'; var box = m.querySelector('.modal-box'); if (box) { box.style.animation = 'none'; void box.offsetWidth; box.style.animation = ''; } } };
            window.closeModal = function (id) { var m = $(id); if (m) { m.classList.remove('open'); m.style.display = 'none'; } };
            d.querySelectorAll('.rm-page ~ .modal-overlay').forEach(function (el) {
                el.addEventListener('click', function (e) { if (e.target === el) closeModal(el.id); });
            });
            if (!window.__coEsc) {
                window.__coEsc = true;
                d.addEventListener('keydown', function (e) {
                    if (e.key !== 'Escape') return;
                    d.querySelectorAll('.modal-overlay.open').forEach(function (m) { closeModal(m.id); });
                });
            }

            window.rmItemOpen = function (b) {
                var m = $('rmItemModal'), f = m.querySelector('[data-co-form]');
                f.reset();
                f.querySelector('[data-co-action]').value = b ? 'edit_removal_item' : 'add_removal_item';
                m.querySelector('[data-co-title]').textContent = b ? 'แก้ไขรายการดูดกลับ' : 'เพิ่มรายการดูดกลับ';
                m.querySelector('[data-co-icon]').innerHTML = b ? ICON_EDIT : ICON_ADD;
                m.querySelector('[data-co-submit]').textContent = b ? 'บันทึกการแก้ไข' : 'เพิ่มรายการดูดกลับ';
                $('rmItemId').value = b ? b.dataset.id : '';
                $('rmItemName').value = b ? b.dataset.name : '';
                $('rmItemUnit').value = b ? b.dataset.unit : '';
                $('rmItemFactor').value = b ? b.dataset.factor : '';
                openModal('rmItemModal');
                setTimeout(function () { $('rmItemName').focus(); }, 60);
            };
            window.rmCopyOpen = function () { openModal('rmCopyModal'); };
            window.rmDelete = function (id, message) {
                confirmDelete({ message: message }).then(function (ok) { if (ok && $('delRm' + id)) $('delRm' + id).submit(); });
            };

            // ── ตารางกรอก + แถบสัดส่วน / การ์ดสรุป อัปเดตตามที่พิมพ์ ──
            var form = $('rmForm');
            if (form) {
                form.addEventListener('de:refresh', function (e) {
                    var total = e.detail.total;
                    form.querySelectorAll('.item-row').forEach(function (tr) {
                        var inp = tr.querySelector('input.vol-input'), p = deParseVol(inp.value);
                        var em = p.error ? 0 : deRowEmission(p.value, inp.dataset.ef);
                        var pct = total > 0 ? Math.round(em / total * 1000) / 10 : 0;
                        tr.querySelector('.rm-share-bar').style.width = pct + '%';
                        tr.querySelector('.rm-share-pct').textContent = pct + '%';
                    });
                    var c = d.querySelector('[data-rm-kpi="central"]'), t = d.querySelector('[data-rm-kpi="total"]');
                    if (c) c.textContent = deFormat(total, 3);
                    if (t) t.textContent = deFormat(total + ACTIVITY, 3);
                });
                deInit(form);
            }

            // ── เปลี่ยนปี ──
            var yearDd = $('rmYear');
            if (yearDd) yearDd.addEventListener('dd:change', function (e) {
                if (String(e.detail.value) === '<?= (int) $selected_year ?>') return;
                location.href = '?year=' + encodeURIComponent(e.detail.value);
            });
        })();
        </script>
        <?php include __DIR__ . '/../components/confirm_modal.php'; ?>
    </main>
</body>
</html>
