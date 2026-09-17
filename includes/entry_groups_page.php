<?php
/**
 * SHARED — ② เลือกหมวด (data_entry.php) ใช้ร่วม officer / admin
 * ---------------------------------------------------------
 * ผู้เรียกกำหนดก่อน include: $pdo, $root, $SIDEBAR, $HEADER, $ENTRY_ADMIN (bool) — สิทธิ์ตรวจแล้วจากผู้เรียก
 * การ์ดหมวด (admin_g) แยกตามขอบเขต พร้อมจำนวนรายการ / ที่กรอกแล้ว — เลือกได้หลายหมวดแล้วไป ③ กรอกปริมาณ
 * หมวดที่ปีนี้ไม่มีรายการให้กรอก เลือกไม่ได้ (เดิมเลือกได้แล้วเจอหมวดว่าง)
 *
 * officer: หน่วยงาน = ของตัวเองเสมอ (ไม่รับ affil จาก URL)
 * admin  : เลือกหน่วยงานได้ (?affil=) ค่าเริ่มต้น = หน่วยงานของ admin
 */
require_once __DIR__ . '/officer_entry.php';
require_once __DIR__ . '/admin_items_entry.php';

$ENTRY_ADMIN = !empty($ENTRY_ADMIN);
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : 0;
if ($selected_year <= 0) {
    header('Location: items.php');
    exit;
}

if ($ENTRY_ADMIN) {
    $affils   = admin_affiliations($pdo);
    $picked   = admin_pick_affiliation($affils, $_GET['affil'] ?? 0, (int) ($_SESSION['affiliation_id'] ?? 0));
    $affil_id = $picked['id'];
    $affil_name = $picked['name'];
    $aq = '&affil=' . $affil_id;
} else {
    $affil_id   = (int) ($_SESSION['affiliation_id'] ?? 0);
    $affil_name = $_SESSION['affiliation_name'] ?? '...';
    $aq = '';
}

$stmt = $pdo->prepare('SELECT year FROM admin_year WHERE id = ?');
$stmt->execute([$selected_year]);
$year_name = (string) ($stmt->fetchColumn() ?: 'ไม่ระบุ');

$groups = $pdo->query('SELECT * FROM admin_g ORDER BY scope, order_num ASC, id ASC')->fetchAll();
$stats  = officer_group_stats($pdo, $affil_id, $selected_year);
$meta   = officer_scope_meta();
$by_scope = [];
foreach ($groups as $g) $by_scope[(int) $g['scope']][] = $g;
$no_items = array_sum(array_column($stats, 'items')) === 0;

$page_title = "กรอกข้อมูล";
$page_title2 = "UP Net Zero";
$page_title3 = "เลือกขอบเขตกิจกรรม $year_name";
$check_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เลือกหมวด — UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
</head>

<body>

    <?php include_once $SIDEBAR; ?>

    <main class="main-content">
        <?php include_once $HEADER; ?>

        <div class="oe-page">
            <a href="items.php" class="oe-back">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                ย้อนกลับไปหน้าเลือกปีงบประมาณ
            </a>
            <?= officer_entry_steps(2, $selected_year, $aq) ?>

            <div class="oe-head">
                <div>
                    <h1 class="oe-title">เลือกหมวดที่ต้องการกรอก</h1>
                    <div class="oe-sub"><?= $ENTRY_ADMIN ? 'หน่วยงาน: ' . htmlspecialchars($affil_name) . ' · ' : '' ?>ปีงบประมาณ <?= htmlspecialchars($year_name) ?> · เลือกได้หลายหมวด</div>
                </div>
                <?php if ($ENTRY_ADMIN): ?>
                <div class="oe-actions oe-affil">
                    <span class="oe-affil-label">กรอกแทนหน่วยงาน</span>
                    <?php
                    $dd_id          = 'oeAffil';
                    $dd_name        = 'affil_nav';
                    $dd_options     = array_map(fn($a) => ['value' => $a['id'], 'label' => $a['name']], $affils);
                    $dd_selected    = $affil_id;
                    $dd_placeholder = 'เลือกหน่วยงาน';
                    $dd_required    = false;
                    $dd_class       = 'dd-field';
                    $dd_style       = 'width:300px;max-width:100%;';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($ENTRY_ADMIN && $no_items): ?>
            <div class="oe-note oe-rise">ปี <?= htmlspecialchars($year_name) ?> ยังไม่มีรายการ Emission Factor — กลับไปหน้าเลือกปีแล้วกด "คัดลอกรายการจากปีอื่น" หรือเลือกหมวดเพื่อเพิ่มรายการในขั้นถัดไป</div>
            <?php endif; ?>

            <form action="data_entry_items.php" method="GET" id="oe-group-form" novalidate>
                <input type="hidden" name="year" value="<?= $selected_year ?>">
                <?php if ($ENTRY_ADMIN): ?><input type="hidden" name="affil" value="<?= $affil_id ?>"><?php endif; ?>

                <?php $i = 0; foreach ([1, 2, 3] as $sc): if (empty($by_scope[$sc])) continue; $m = $meta[$sc]; ?>
                <section class="oe-scope-block oe-rise" style="--i:<?= $i++ ?>;--sc:<?= $m['color'] ?>;--sc-soft:<?= $m['soft'] ?>;--sc-line:<?= $m['color'] ?>66;">
                    <div class="oe-scope-head">
                        <div class="oe-scope-name">ขอบเขต <?= $sc ?> · <?= $m['name'] ?></div>
                        <button type="button" class="oe-pick-all" data-scope="<?= $sc ?>" onclick="oePickScope(<?= $sc ?>)">เลือกทั้งหมด</button>
                    </div>
                    <div class="oe-groups">
                        <?php foreach ($by_scope[$sc] as $g):
                            $st    = $stats[(int) $g['id']] ?? ['items' => 0, 'filled' => 0, 'emission' => 0.0];
                            $empty = $st['items'] === 0;
                            // admin เลือกหมวดว่างได้ เพื่อไปเพิ่มรายการในขั้น ③
                            $lock  = $empty && !$ENTRY_ADMIN;
                            $pct   = $empty ? 0 : $st['filled'] / $st['items'] * 100; ?>
                        <label class="oe-group<?= $empty ? ' is-empty' : '' ?><?= $lock ? '' : ' is-pickable' ?>" data-scope="<?= $sc ?>"<?= $lock ? ' title="ปีนี้ยังไม่มีรายการให้กรอกในหมวดนี้"' : '' ?>>
                            <input type="checkbox" name="scope_groups[]" value="<?= (int) $g['id'] ?>"<?= $lock ? ' disabled' : '' ?>>
                            <span class="oe-check"><?= $check_svg ?></span>
                            <span class="oe-group-body">
                                <span class="oe-group-name"><?= htmlspecialchars($g['name_tiem']) ?></span>
                                <?php if ($empty): ?>
                                <span class="oe-group-meta"><span><?= $ENTRY_ADMIN ? 'ยังไม่มีรายการ · เลือกเพื่อเพิ่มรายการ' : 'ยังไม่มีรายการในปีนี้' ?></span></span>
                                <?php else: ?>
                                <span class="oe-group-meta"><span class="oe-group-fill">กรอกแล้ว <?= $st['filled'] ?> / <?= $st['items'] ?> รายการ</span><b><?= number_format($st['emission'], 2) ?> tCO₂e</b></span>
                                <span class="oe-track" style="display:block;"><span class="oe-bar" style="display:block;width:<?= number_format($pct, 2, '.', '') ?>%;"></span></span>
                                <?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>

                <div class="oe-dock">
                    <div class="oe-dock-info">
                        <div class="oe-dock-stat"><span>เลือกแล้ว</span><b class="is-purple"><span id="oe-picked">0</span> หมวด</b></div>
                        <span class="oe-msg" id="oe-pick-msg" role="alert"></span>
                    </div>
                    <button type="submit" class="oe-btn">ถัดไป: กรอกปริมาณ <span class="oe-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></span></button>
                </div>
            </form>
        </div>

        <script>
            // อยู่ใน <main> ให้ SPA รันซ้ำได้ — ประกาศผ่าน window / IIFE (ไม่มี let/const ระดับบนสุด)
            (function () {
                var form = document.getElementById('oe-group-form');
                if (!form) return;
                var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('input[name="scope_groups[]"]:not(:disabled)')); };

                // นับจำนวนที่เลือก + ป้ายปุ่ม "เลือกทั้งหมด/ยกเลิกทั้งหมด" ของแต่ละขอบเขต
                function refresh() {
                    var all = boxes();
                    document.getElementById('oe-picked').textContent = all.filter(function (b) { return b.checked; }).length;
                    form.querySelectorAll('.oe-pick-all').forEach(function (btn) {
                        var mine = all.filter(function (b) { return b.closest('.oe-group').dataset.scope === btn.dataset.scope; });
                        btn.textContent = mine.length && mine.every(function (b) { return b.checked; }) ? 'ยกเลิกทั้งหมด' : 'เลือกทั้งหมด';
                        btn.hidden = mine.length === 0;
                    });
                    if (all.some(function (b) { return b.checked; })) document.getElementById('oe-pick-msg').textContent = '';
                }
                // การ์ดเป็น <label> ครอบ checkbox → คลิกตรงไหนก็สลับครั้งเดียว (เดิม onclick ของแถว + label สลับซ้อนจนไม่ติ๊ก)
                form.addEventListener('change', refresh);

                window.oePickScope = function (sc) {
                    var mine = boxes().filter(function (b) { return b.closest('.oe-group').dataset.scope === String(sc); });
                    var on = !mine.every(function (b) { return b.checked; });
                    mine.forEach(function (b) { b.checked = on; });
                    refresh();
                };

                form.addEventListener('submit', function (e) {
                    if (boxes().some(function (b) { return b.checked; })) return;
                    e.preventDefault();
                    var msg = document.getElementById('oe-pick-msg');
                    msg.textContent = 'กรุณาเลือกอย่างน้อย 1 หมวด';
                    var dock = form.querySelector('.oe-dock');
                    dock.classList.remove('oe-shake'); void dock.offsetWidth; dock.classList.add('oe-shake');
                });
                refresh();

                // admin: เปลี่ยนหน่วยงาน → โหลดหน้าเดิมของหน่วยงานนั้น
                var affil = document.getElementById('oeAffil');
                if (affil) affil.addEventListener('dd:change', function (e) {
                    if (String(e.detail.value) === '<?= $affil_id ?>') return;
                    location.href = 'data_entry.php?year=<?= $selected_year ?>&affil=' + encodeURIComponent(e.detail.value);
                });
            })();
        </script>
    </main>

</body>

</html>
