<?php
/**
 * ADMIN DASHBOARD — admin/index.php (ทั้งมหาวิทยาลัย)
 * ---------------------------------------------------------
 * การ์ดหลัก: ปล่อยทั้งหมด / ดูดกลับ / Net (+ % ชดเชย, เทียบปีก่อน) → ขอบเขต 1/2/3 → แถบแบบสอบถาม / กิจกรรม
 *   → อันดับรายหน่วยงาน 6 อันดับแรก (ทั้งหมดในหน้าต่าง) + ภาพรวมทุกปี (กราฟมีตัวเลขกำกับแท่ง)
 * ยอดทั้งหมดมาจาก ghg_report_summary() ตัวเดียวกับหน้ารายงาน GHG / Dashboard คณบดี (ข้อมูลจัดใน includes/admin_dashboard.php)
 * หน้าต่างรายละเอียดทุกชนิดเป็นหน้าต่างเดียวแบบย้อนกลับได้ — logic อยู่ที่ assets/js/admin-dashboard.js · สไตล์ admin-dashboard.css (ad-)
 *
 * เดิม: SQL ของตัวเอง ~180 บรรทัด, สคริปต์ inline ~1,870 บรรทัด (โดนัท 3 ชุด, const ระดับบนสุด, ตัวดักคลิกของ document
 *       ที่ค้างหลังเปลี่ยนหน้าผ่านเมนู → error ทุกคลิกในหน้าอื่น), ชื่อจากผู้ใช้ใส่ innerHTML / onclick โดยไม่ escape, ไม่มี Net
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_dashboard.php';

require_role(['admin']);

$pdo  = getDB();
$root = '../';
$page_title = 'Dashboard';

$years = ghg_years($pdo);
$selected_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) ($years[0]['year_id'] ?? 0);
$year_label = '';
foreach ($years as $y) if ((int) $y['year_id'] === $selected_year) $year_label = (string) $y['year'];
if ($year_label === '' && $years) { $selected_year = (int) $years[0]['year_id']; $year_label = (string) $years[0]['year']; }

$o   = $years ? admin_dash_overview($pdo, $years, $selected_year) : null;
$sum = $o['summary'] ?? null;

$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$n2  = fn(float $v) => number_format($v, 2);
$pct = fn(float $v) => number_format($v, 2, '.', '');
$scope_meta = admin_dash_scope_meta();
$arrow = '<span class="oe-arrow"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></span>';

// ข้อมูลของหน้าต่างรายละเอียด (อ่านใน admin-dashboard.js) — JSON_HEX_TAG กันชื่อที่มี </script>
$ad_data = $o ? [
    'year' => $selected_year, 'yearLabel' => $year_label,
    'gross' => $sum['gross'], 'scope' => $sum['scope'], 'removal' => $sum['removal'],
    'removalCentral' => $o['removal_central'], 'removalActivity' => $o['removal_activity'],
    'breakdown' => $o['breakdown'], 'scopeBreakdown' => $o['scope_breakdown'], 'removalRows' => $o['removal_rows'],
    'cumulative' => $o['cumulative'], 'cumulativeTotal' => $o['cumulative_total'],
    'history' => $o['history'], 'scopeMeta' => $scope_meta,
    'ranking' => $o['ranking'], 'affilTotal' => $o['affil_total'],
] : null;
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — UP Net Zero Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css<?= asset_v('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css<?= asset_v('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/officer-entry.css<?= asset_v('assets/css/officer-entry.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/collect.css<?= asset_v('assets/css/collect.css') ?>">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin-dashboard.css<?= asset_v('assets/css/admin-dashboard.css') ?>">
</head>

<body>
    <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include_once __DIR__ . '/includes/header.php'; ?>

        <div class="oe-page ad-page">
            <div class="oe-head oe-rise">
                <div>
                    <h1 class="oe-title">Dashboard</h1>
                    <div class="oe-sub">ภาพรวมการปล่อยและดูดกลับก๊าซเรือนกระจก มหาวิทยาลัยพะเยา (ทุกหน่วยงาน)</div>
                </div>
                <?php if ($years): ?>
                <div class="oe-actions">
                    <span class="co-year-label">ปีงบประมาณ</span>
                    <?php
                    $dd_id = 'adYear'; $dd_name = 'year_nav'; $dd_selected = $selected_year; $dd_required = false; $dd_class = 'dd-field'; $dd_style = 'width:120px;';
                    $dd_options = array_map(fn($y) => ['value' => $y['year_id'], 'label' => (string) $y['year']], $years); $dd_placeholder = 'เลือกปี';
                    include __DIR__ . '/../components/dropdown.php';
                    ?>
                    <a class="oe-btn oe-btn-soft ad-report-link" href="../dean/reports.php?view=system&amp;year=<?= $selected_year ?>">รายงาน GHG ฉบับเต็ม <?= $arrow ?></a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$o): ?>
            <div class="oe-empty oe-rise">
                <h3>ยังไม่มีปีงบประมาณในระบบ</h3>
                <p>เริ่มจากเพิ่มปีงบประมาณและรายการ Emission Factor ที่หน้ากรอกข้อมูล</p>
                <a class="oe-btn" href="items.php">ไปหน้ากรอกข้อมูล <?= $arrow ?></a>
            </div>
            <?php else: ?>

            <!-- ── การ์ดหลัก: ปล่อย / ดูดกลับ / Net ── -->
            <div class="ad-hero">
                <section class="ad-kpi ad-k-gross oe-rise" style="--i:1;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('factory', 22) ?></span><span class="ad-kpi-label">การปล่อยทั้งหมด ปี <?= $h($year_label) ?></span></div>
                    <div class="ad-kpi-val"><b class="ad-gross" data-count="<?= $pct($sum['gross']) ?>"><?= $n2($sum['gross']) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts">
                        <span>ดำเนินงาน <b class="ad-part-op"><?= $n2($o['parts']['operation']) ?></b></span>
                        <span>กิจกรรม <b class="ad-part-ev"><?= $n2($o['parts']['event']) ?></b></span>
                        <span>แบบสอบถาม <b class="ad-part-sv"><?= $n2($o['parts']['survey']) ?></b></span>
                    </div>
                    <div class="ad-kpi-foot">
                        <?php if (in_array($o['compare']['state'], ['ok', 'low'], true)): ?>
                        <span class="ad-badge is-<?= $o['badge']['tone'] ?>" title="เทียบกับปี <?= $h($o['compare']['prev_year']['year']) ?>"><?= $h($o['badge']['text']) ?> จากปี <?= $h($o['compare']['prev_year']['year']) ?></span>
                        <?php else: ?>
                        <span class="ad-badge is-flat">ยังไม่มีข้อมูลปีก่อนให้เทียบ</span>
                        <?php endif; ?>
                        <button type="button" class="oe-btn ad-btn" data-ad-open="breakdown">แยกตามผู้ให้ข้อมูล <?= $arrow ?></button>
                    </div>
                </section>

                <section class="ad-kpi ad-k-removal oe-rise" style="--i:2;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('leaf', 22) ?></span><span class="ad-kpi-label">การดูดกลับ</span></div>
                    <div class="ad-kpi-val"><b class="ad-removal" data-count="<?= $pct($sum['removal']) ?>"><?= $n2($sum['removal']) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts">
                        <span>ส่วนกลาง <b><?= $n2($o['removal_central']) ?></b></span>
                        <span>จากกิจกรรม <b><?= $n2($o['removal_activity']) ?></b></span>
                    </div>
                    <div class="ad-kpi-foot">
                        <span class="ad-badge is-flat"><?= count($o['removal_rows']) ?> รายการ</span>
                        <button type="button" class="oe-btn ad-btn" data-ad-open="removal">ดูรายการดูดกลับ <?= $arrow ?></button>
                    </div>
                </section>

                <section class="ad-kpi ad-k-net oe-rise" style="--i:3;">
                    <div class="ad-kpi-top"><span class="ad-kpi-ic"><?= ic('globe', 22) ?></span><span class="ad-kpi-label">สุทธิ (Net) · ติดตาม Net Zero</span></div>
                    <div class="ad-kpi-val"><b class="ad-net" data-count="<?= $pct($sum['net']) ?>"><?= $n2($sum['net']) ?></b> <small>tCO₂e</small></div>
                    <div class="ad-parts"><span>= ปล่อยทั้งหมด − ดูดกลับ</span></div>
                    <div class="ad-offset">
                        <?php $off = $o['offset']; ?>
                        <div class="ad-offset-row"><span>ดูดกลับชดเชยได้</span><b class="ad-offset-val"><?= $off === null ? '—' : ($off >= 100 ? 'ครบ 100% (Net ติดลบ)' : number_format($off, 2) . '%') ?></b></div>
                        <div class="ad-track"><span class="ad-fill ad-fill-green" style="width:<?= $pct(min((float) $off, 100)) ?>%;"></span></div>
                    </div>
                </section>
            </div>

            <!-- ── ขอบเขต 1/2/3 ── -->
            <div class="ad-scopes">
                <?php foreach ([1, 2, 3] as $s):
                    $share = $sum['gross'] > 0 ? $sum['scope'][$s] / $sum['gross'] * 100 : 0.0;
                    $m = $scope_meta[$s]; ?>
                <button type="button" class="ad-scope oe-rise" style="--i:<?= $s + 3 ?>;--sc:<?= $m['color'] ?>;--sc-deep:<?= $m['deep'] ?>;" data-ad-open="scope" data-scope="<?= $s ?>">
                    <span class="ad-scope-head"><span class="ad-scope-name">ขอบเขต <?= $s ?></span><span class="ad-scope-share"><?= number_format($share, 1) ?>%</span></span>
                    <span class="ad-scope-desc"><?= $m['name'] ?></span>
                    <span class="ad-scope-val"><b class="ad-scope-num" data-scope-val="<?= $s ?>" data-count="<?= $pct($sum['scope'][$s]) ?>"><?= $n2($sum['scope'][$s]) ?></b> <small>tCO₂e</small></span>
                    <span class="ad-track"><span class="ad-fill" style="width:<?= $pct($share) ?>%;"></span></span>
                    <span class="ad-scope-go">ดูรายหน่วยงาน <?= $arrow ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- ── แบบสอบถาม / กิจกรรม: แถบบรรทัดเดียว (ย้ายออกจากท้ายการ์ดอันดับ) ── -->
            <?php $sc = $o['source_counts']; ?>
            <div class="ad-srcbars">
                <div class="ad-evbar is-survey oe-rise" style="--i:7;">
                    <span class="ad-evbar-ic"><?= ic('survey', 20) ?></span>
                    <div class="ad-evbar-text">
                        <b>แบบสอบถาม <span class="ad-src-count"><?= $sc['survey'] ?></span> ชุด · <span class="ad-src-total" data-count="<?= number_format($o['parts']['survey'], 4, '.', '') ?>" data-digits="4"><?= number_format($o['parts']['survey'], 4) ?></span> tCO₂e</b>
                        <small>การเดินทางของบุคลากร/นิสิต · นับรวมในขอบเขต 3 แล้ว</small>
                    </div>
                    <button type="button" class="ad-evbar-btn" data-ad-source="survey">ดูรายละเอียด <?= $arrow ?></button>
                </div>
                <div class="ad-evbar oe-rise" style="--i:8;">
                    <span class="ad-evbar-ic"><?= ic('note', 20) ?></span>
                    <div class="ad-evbar-text">
                        <b>กิจกรรมที่มีการปล่อย <span class="ad-src-count"><?= $sc['event'] ?></span> งาน · <span class="ad-src-total" data-count="<?= number_format($o['parts']['event'], 4, '.', '') ?>" data-digits="4"><?= number_format($o['parts']['event'], 4) ?></span> tCO₂e</b>
                        <small>นับรวมในยอดปล่อยทั้งหมดด้านบนแล้ว</small>
                    </div>
                    <button type="button" class="ad-evbar-btn" data-ad-source="event">ดูรายละเอียด <?= $arrow ?></button>
                </div>
            </div>

            <div class="ad-grid is-even">
                <!-- ── อันดับรายหน่วยงาน (6 อันดับแรก — ทั้งหมดเปิดในหน้าต่าง) ── -->
                <section class="oe-panel ad-rank ad-rank-card oe-rise" style="--i:9;">
                    <div class="co-panel-head">
                        <h2 class="co-h2">อันดับการปล่อยรายหน่วยงาน <span class="co-count"><?= $o['affils_with_data'] ?> / <?= $o['affil_total'] ?></span></h2>
                        <span class="ad-legend"><?php foreach ([1, 2, 3] as $s): ?><i style="--c:<?= $scope_meta[$s]['color'] ?>;">ขอบเขต <?= $s ?></i><?php endforeach; ?></span>
                    </div>
                    <p class="oe-muted ad-rank-note">6 อันดับแรก · เฉพาะการดำเนินงาน · กดที่หน่วยงานเพื่อดูรายการที่กรอก</p>
                    <?php if (!$o['ranking']): ?>
                    <div class="oe-empty co-empty"><h3>ยังไม่มีข้อมูลการดำเนินงานในปีนี้</h3><p>เมื่อหน่วยงานกรอกข้อมูลแล้ว อันดับจะแสดงที่นี่</p></div>
                    <?php else: ?>
                    <ol class="ad-rank-list">
                        <?php foreach (array_slice($o['ranking'], 0, 6) as $k => $rk): ?>
                        <li class="ad-rank-item" style="--i:<?= min($k, 12) ?>;">
                            <button type="button" class="ad-rank-row" data-ad-affil="<?= $rk['affil_id'] ?>" data-name="<?= $h($rk['name']) ?>">
                                <span class="ad-rank-no<?= $rk['rank'] <= 3 ? ' is-top' : '' ?>"><?= $rk['rank'] ?></span>
                                <span class="ad-rank-name" title="<?= $h($rk['name']) ?>"><?= $h($rk['name']) ?></span>
                                <span class="ad-rank-bar"><span class="ad-rank-fill" style="width:<?= $pct(max($rk['bar'], 1)) ?>%;"><?php foreach ([1, 2, 3] as $s): ?><i style="width:<?= $pct($rk['scope_pct'][$s]) ?>%;background:<?= $scope_meta[$s]['color'] ?>;"></i><?php endforeach; ?></span></span>
                                <span class="ad-rank-val"><?= $n2($rk['total']) ?></span>
                                <span class="ad-rank-pct"><?= number_format($rk['share'], 1) ?>%</span>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                    <button type="button" class="oe-btn oe-btn-soft ad-rank-more" data-ad-open="ranking">ดูอันดับทั้งหมด (<?= $o['affils_with_data'] ?> หน่วยงาน) <?= $arrow ?></button>
                    <?php endif; ?>
                </section>

                <!-- ── ภาพรวมทุกปี ── -->
                <section class="oe-panel ad-side oe-rise" style="--i:10;">
                    <h2 class="co-h2">ภาพรวมทุกปี</h2>
                    <div class="ad-mini">
                        <div><span>การปล่อยสะสม</span><b class="ad-cumul" data-count="<?= $pct($o['cumulative_total']) ?>"><?= $n2($o['cumulative_total']) ?></b><small>tCO₂e · <?= count($years) ?> ปี</small></div>
                        <div><span>จำนวนครั้งที่รายงาน</span><b class="ad-reports" data-count="<?= $o['report_count'] ?>" data-digits="0"><?= number_format($o['report_count']) ?></b><small>ครั้ง (ผู้ให้ข้อมูล × ปี)</small></div>
                    </div>
                    <div class="ad-chart">
                        <canvas id="adHistory" height="190" aria-label="กราฟการปล่อยรายปีแยกขอบเขต" role="img"></canvas>
                        <span class="ad-legend"><?php foreach ([1, 2, 3] as $s): ?><i style="--c:<?= $scope_meta[$s]['color'] ?>;">ขอบเขต <?= $s ?></i><?php endforeach; ?></span>
                    </div>
                    <div class="ad-side-btns">
                        <button type="button" class="oe-btn oe-btn-soft" data-ad-open="cumulative">สะสมรายผู้ให้ข้อมูล <?= $arrow ?></button>
                        <button type="button" class="oe-btn oe-btn-ghost" data-ad-open="reports">ประวัติการรายงาน <?= $arrow ?></button>
                    </div>
                </section>
            </div>
            <?php endif; ?>
        </div>

        <!-- หน้าต่างรายละเอียด (หน้าต่างเดียว ย้อนกลับได้หลายชั้น) -->
        <div class="modal-overlay" id="adModal" role="dialog" aria-modal="true" aria-labelledby="adModalTitle">
            <div class="modal-box ad-modal">
                <div class="ad-mh" id="adModalHead">
                    <button type="button" class="ad-mh-back" id="adBack" hidden aria-label="ย้อนกลับ"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg><span id="adBackLabel">ย้อนกลับ</span></button>
                    <button type="button" class="ad-mh-close" id="adClose" aria-label="ปิด">&times;</button>
                    <div class="ad-mh-label" id="adModalLabel"></div>
                    <h3 class="ad-mh-title" id="adModalTitle"></h3>
                </div>
                <div class="ad-mb" id="adModalBody"></div>
            </div>
        </div>

        <!-- ดูไฟล์หลักฐาน (อ่านอย่างเดียว) -->
        <div class="ad-lightbox" id="adLightbox" hidden>
            <button type="button" class="ad-lb-close" id="adLbClose" aria-label="ปิด">&times;</button>
            <button type="button" class="ad-lb-nav is-prev" id="adLbPrev" aria-label="ไฟล์ก่อนหน้า">&lsaquo;</button>
            <figure class="ad-lb-fig"><div id="adLbContent"></div><figcaption id="adLbCaption"></figcaption></figure>
            <button type="button" class="ad-lb-nav is-next" id="adLbNext" aria-label="ไฟล์ถัดไป">&rsaquo;</button>
        </div>

        <?php if ($ad_data): ?>
        <script type="application/json" id="adData"><?= json_encode($ad_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <?php endif; ?>
        <script src="<?= $root ?>assets/js/ghg-charts.js<?= asset_v('assets/js/ghg-charts.js') ?>"></script>
        <script src="<?= $root ?>assets/js/admin-dashboard.js<?= asset_v('assets/js/admin-dashboard.js') ?>"></script>
        <script>
        // สคริปต์ src โหลดแบบไม่รอกันเมื่อ SPA สลับหน้า → รอจน adInit / drawGhgGroupedBars พร้อม
        (function run(n) {
            if (!window.adInit || !window.drawGhgGroupedBars) { if (n < 200) setTimeout(function () { run(n + 1); }, 25); return; }
            var el = document.getElementById('adData');
            window.adInit(el ? JSON.parse(el.textContent) : null);
        })(0);
        </script>
    </main>
</body>

</html>
