<?php
/**
 * SHARED — หน้า GHG Removal (การดูดกลับก๊าซเรือนกระจก) ระดับส่วนกลาง/มหาวิทยาลัย
 * ผู้เรียกกำหนดก่อน include: $pdo, $root, $SIDEBAR, $HEADER
 * (สิทธิ์ตรวจแล้วจากผู้เรียก: admin หรือ officer ของศูนย์สิ่งแวดล้อม affil=1 เท่านั้น)
 */
require_once __DIR__ . '/ghg_report.php';

$years = $pdo->query("SELECT id AS year_id, year FROM admin_year ORDER BY year DESC")->fetchAll();
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : ($years[0]['year_id'] ?? 0);

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pyear  = (int) ($_POST['year_id'] ?? $selected_year);
    $redir  = "?year=$pyear";
    try {
        if ($action === 'add_removal_item') {
            $name = trim($_POST['name'] ?? ''); $unit = trim($_POST['unit'] ?? ''); $factor = (float) ($_POST['factor'] ?? 0);
            if ($name === '') throw new Exception('กรุณาระบุชื่อรายการ');
            try {
                $pdo->prepare("INSERT INTO removal_item (year_id,name_tiem,unit,factor) VALUES (?,?,?,?)")
                    ->execute([$pyear, $name, $unit, $factor]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') throw new Exception('รายการนี้มีอยู่แล้วในปีนี้');
                throw $e;
            }
            header("Location: $redir&msg=" . urlencode('เพิ่มรายการดูดกลับแล้ว')); exit;
        }
        if ($action === 'edit_removal_item') {
            $id = (int) $_POST['item_id']; $name = trim($_POST['name'] ?? ''); $unit = trim($_POST['unit'] ?? ''); $factor = (float) ($_POST['factor'] ?? 0);
            if ($name === '') throw new Exception('กรุณาระบุชื่อรายการ');
            $pdo->prepare("UPDATE removal_item SET name_tiem=?, unit=?, factor=? WHERE id=?")->execute([$name, $unit, $factor, $id]);
            header("Location: $redir&msg=" . urlencode('แก้ไขรายการแล้ว')); exit;
        }
        if ($action === 'delete_removal_item') {
            $pdo->prepare("DELETE FROM removal_item WHERE id=?")->execute([(int) $_POST['item_id']]);
            header("Location: $redir&msg=" . urlencode('ลบรายการแล้ว')); exit;
        }
        if ($action === 'save_removal') {
            $qty = $_POST['qty'] ?? [];
            $up  = $pdo->prepare("INSERT INTO removal_entry (removal_item_id,year_id,qty,create_year) VALUES (?,?,?,CURDATE())
                                  ON DUPLICATE KEY UPDATE qty=VALUES(qty), create_year=CURDATE()");
            $del = $pdo->prepare("DELETE FROM removal_entry WHERE removal_item_id=? AND year_id=?");
            $ids = $pdo->prepare("SELECT id FROM removal_item WHERE year_id=?"); $ids->execute([$pyear]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                $q = (float) ($qty[$iid] ?? 0);
                if ($q > 0) $up->execute([$iid, $pyear, $q]);
                else        $del->execute([$iid, $pyear]);
            }
            header("Location: $redir&msg=" . urlencode('บันทึกปริมาณดูดกลับแล้ว')); exit;
        }
    } catch (Exception $e) {
        header("Location: $redir&msg=" . urlencode('เกิดข้อผิดพลาด: ' . $e->getMessage()) . "&msg_type=danger"); exit;
    }
}

$flash   = $_GET['msg'] ?? '';
$flash_t = (($_GET['msg_type'] ?? '') === 'danger') ? 'danger' : 'success';
$rows        = removal_items_list($pdo, $selected_year);
$year_total  = removal_total($pdo, $selected_year);
$page_title  = 'กรอกข้อมูล';
$page_title2 = 'GHG Removal';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHG Removal — UP Net Zero</title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/dashboard.css<?= asset_v('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
</head>
<body style="background:#F6F4F9;">
    <?php include $SIDEBAR; ?>
    <main class="main-content">
        <?php include $HEADER; ?>
        <style>
            .co { padding:26px 30px 70px; max-width:1000px; }
            .co h1 { font-size:1.4rem; font-weight:800; color:#2A2233; margin:0 0 12px; }
            .flash { display:flex; align-items:center; gap:10px; border-radius:12px; padding:12px 16px; font-weight:600; margin-bottom:16px; }
            .flash svg { flex-shrink:0; }
            .flash.success{background:#DCFCE7;color:#166534;} .flash.danger{background:#FEE2E2;color:#B91C1C;}
            .card { background:#fff; border:1px solid #E7E3EC; border-radius:16px; padding:20px 22px; margin-bottom:16px; }
            .card h2 { font-size:1.05rem; font-weight:800; margin:0 0 14px; color:#2A2233; }
            .row-top{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
            .rgrid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end;}
            .fld label{display:block;font-size:.8rem;font-weight:600;color:#4B4155;margin:0 0 5px;}
            .fld input{width:100%;}
            table.t{width:100%;border-collapse:collapse;}
            table.t th{text-align:center;font-size:.75rem;color:#6B7280;padding:8px 10px;border-bottom:1px solid #E7E3EC;}
            table.t td{padding:10px;border-bottom:1px solid #F1EEF5;font-size:.92rem;}
            table.t td.num,table.t th.num{text-align:right;}
            .ti-input{border:1px solid #E5E7EB;border-radius:10px;padding:9px 12px;font-family:inherit;font-size:.95rem;}
            .icobtn{border:none;border-radius:9px;padding:7px;cursor:pointer;color:#fff;transition:all 0.2s;}
            .icobtn.edit{background:#3B82F6;box-shadow:0 4px 10px rgba(59,130,246,0.2);}
            .icobtn.del{background:#EF4444;box-shadow:0 4px 10px rgba(239,68,68,0.2);}
            .icobtn.edit:hover{background:#2563EB;transform:translateY(-2px);}
            .icobtn.del:hover{background:#DC2626;transform:translateY(-2px);}
            #rmEditModal .ti-input{ background:#fff !important; }
            .rm-cancel{ background:#fff;border:1.5px solid #E5E7EB;border-radius:999px;padding:10px 24px;font-weight:700;color:#6B7280;cursor:pointer;transition:all .2s; }
            .rm-cancel:hover{ background:#F3F4F6;border-color:#D1D5DB;color:#4B5563; }
            .muted{color:#8A8194;}
            .foot{text-align:right;margin-top:12px;}
            /* ลดความสูง input (component) แต่คงองศาโค้ง border-radius:18px เดิม */
            .ti-sm .ti-input{ height:44px; padding:0 1rem; background:#fff !important; }
            .ti-qty{ height:44px !important; min-height:0 !important; padding:0 1rem !important; text-align:center; background:#fff !important; }
        </style>

        <div class="co">
            <h1>🌱 GHG Removal — การดูดกลับก๊าซเรือนกระจก (ระดับมหาวิทยาลัย)</h1>

            <?php $toast_msg = $flash; $toast_type = $flash_t; include __DIR__ . '/../components/toast.php'; ?>

            <div class="row-top">
                <div class="db-year-select-wrap">
                    <span class="db-year-label">ผลรวมของปี</span>
                    <?php $dd_id='rmYear';$dd_name='year_nav';$dd_options=array_map(fn($y)=>['value'=>$y['year_id'],'label'=>(string)$y['year']],$years);
                        $dd_selected=$selected_year;$dd_required=false;$dd_class='dd-pill';$dd_placeholder='เลือกปี';
                        include __DIR__.'/../components/dropdown.php'; ?>
                </div>
                <span class="muted" style="margin-left:auto;">รวมการดูดกลับ: <span style="color:#166534;font-weight:800;"><?= number_format($year_total,3) ?></span> tCO₂e</span>
            </div>

            <!-- เพิ่มรายการดูดกลับ -->
            <div class="card">
                <h2>＋ เพิ่มรายการดูดกลับ</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add_removal_item"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                    <div class="rgrid">
                        <div class="fld"><label>ชื่อรายการ</label>
                            <?php $ti_id='rmName';$ti_name='name';$ti_required=true;$ti_placeholder='เช่น ต้นไม้ยืนต้น / พื้นที่ป่า';$ti_wrap_class='ti-sm';$ti_wrap_style='width:100%;';include __DIR__.'/../components/text_input.php'; ?></div>
                        <div class="fld"><label>หน่วย</label>
                            <?php $ti_id='rmUnit';$ti_name='unit';$ti_required=true;$ti_placeholder='ต้น / ไร่';$ti_wrap_class='ti-sm';include __DIR__.'/../components/text_input.php'; ?></div>
                        <div class="fld"><label>ค่าดูดกลับ (kgCO₂e/หน่วย/ปี)</label>
                            <?php $ti_id='rmFactor';$ti_name='factor';$ti_type='number';$ti_required=true;$ti_step='0.0001';$ti_min=0;$ti_placeholder='0.0000';$ti_wrap_class='ti-sm';include __DIR__.'/../components/text_input.php'; ?></div>
                        <div><?php $btn_label='เพิ่มรายการ';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                    </div>
                </form>
            </div>

            <!-- รายการ + กรอกปริมาณ -->
            <div class="card">
                <h2>รายการดูดกลับ</h2>
                <?php if (empty($rows)): ?>
                    <p class="muted">ยังไม่มีรายการดูดกลับในปีนี้ — เพิ่มด้านบน</p>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="save_removal"><input type="hidden" name="year_id" value="<?= $selected_year ?>">
                    <table class="t">
                        <thead><tr><th style="text-align:left;">รายการ</th><th style="text-align:center;">หน่วย</th><th class="num">ค่าดูดกลับ (kgCO₂e/หน่วย)</th><th class="num">ปริมาณ</th><th class="num">tCO₂e</th><th style="text-align:center;">จัดการ</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($r['name_tiem']) ?></td>
                                <td style="text-align:center;color:#6B7280;"><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                                <td class="num"><?= number_format((float)$r['factor'],4) ?></td>
                                <td class="num"><input class="ti-input ti-qty" style="width:130px;" type="number" min="0" step="0.0001" name="qty[<?= (int)$r['id'] ?>]" value="<?= (float)$r['qty']!=0 ? htmlspecialchars(rtrim(rtrim(number_format((float)$r['qty'],4,'.',''),'0'),'.')) : '' ?>" placeholder="0"></td>
                                <td class="num" style="font-weight:700;color:#166534;"><?= number_format((float)$r['emission'],4) ?></td>
                                <td style="white-space:nowrap;text-align:center;">
                                    <button type="button" class="icobtn edit" title="แก้ไข"
                                        data-id="<?= (int)$r['id'] ?>" data-name="<?= htmlspecialchars($r['name_tiem'],ENT_QUOTES) ?>"
                                        data-unit="<?= htmlspecialchars((string)$r['unit'],ENT_QUOTES) ?>" data-factor="<?= htmlspecialchars((string)$r['factor'],ENT_QUOTES) ?>"
                                        onclick="rmEdit(this)">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                    </button>
                                    <button type="button" class="icobtn del" title="ลบ" onclick="rmDelete(<?= (int)$r['id'] ?>)">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="foot"><?php $btn_label='บันทึกปริมาณ';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?></div>
                </form>

                <?php foreach ($rows as $r): ?>
                <form method="POST" id="delRm<?= (int)$r['id'] ?>" style="display:none;">
                    <input type="hidden" name="action" value="delete_removal_item"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>">
                </form>
                <?php endforeach; ?>

                <!-- Modal แก้ไข -->
                <div id="rmEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100;align-items:center;justify-content:center;">
                    <div style="background:#fff;border-radius:16px;padding:22px;max-width:520px;width:92%;">
                        <h2 style="margin:0 0 14px;font-size:1.1rem;font-weight:800;">✏️ แก้ไขรายการดูดกลับ</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_removal_item"><input type="hidden" name="year_id" value="<?= $selected_year ?>"><input type="hidden" name="item_id" id="re_id">
                            <div class="fld" style="margin-bottom:10px;"><label>ชื่อรายการ</label><input class="ti-input" style="width:100%;" name="name" id="re_name" required></div>
                            <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                                <div class="fld" style="flex:1;min-width:120px;"><label>หน่วย</label><input class="ti-input" style="width:100%;" name="unit" id="re_unit"></div>
                                <div class="fld" style="width:180px;"><label>ค่าดูดกลับ</label><input class="ti-input" style="width:100%;" type="number" step="0.0001" min="0" name="factor" id="re_factor" required></div>
                            </div>
                            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
                                <button type="button" class="rm-cancel" onclick="document.getElementById('rmEditModal').style.display='none'">ยกเลิก</button>
                                <?php $btn_label='บันทึกการแก้ไข';$btn_variant='primary';$btn_type='submit';include __DIR__.'/../components/button.php'; ?>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
            document.getElementById('rmYear')?.addEventListener('dd:change', function(e){
                if (String(e.detail.value) === '<?= $selected_year ?>') return;
                location.href='?year='+e.detail.value;
            });
            function rmEdit(b){
                document.getElementById('re_id').value=b.dataset.id;
                document.getElementById('re_name').value=b.dataset.name;
                document.getElementById('re_unit').value=b.dataset.unit;
                document.getElementById('re_factor').value=b.dataset.factor;
                document.getElementById('rmEditModal').style.display='flex';
            }
            function rmDelete(id){ if(confirm('ลบรายการนี้ (รวมปริมาณที่กรอก)?')) document.getElementById('delRm'+id).submit(); }
            // จำกัดทศนิยมไม่เกิน 4 ตำแหน่ง (ค่าดูดกลับ + ปริมาณ)
            document.addEventListener('input', function(e){
                var el = e.target;
                if (el.matches && el.matches('input.ti-qty, #rmFactor_input')) {
                    var v = el.value;
                    var dot = v.indexOf('.');
                    if (dot >= 0 && v.length - dot - 1 > 4) {
                        el.value = v.slice(0, dot + 5);
                    }
                }
            });
            document.getElementById('rmEditModal')?.addEventListener('click',function(e){ if(e.target===this) this.style.display='none'; });
        </script>
    </main>
</body>
</html>
