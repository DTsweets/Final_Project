<?php
/**
 * DEAN — หน้าพิมพ์รายงาน GHG (สั่งพิมพ์/บันทึกเป็น PDF ผ่านเบราว์เซอร์ รองรับฟอนต์ไทย)
 * params: view=system|faculty, year=<id>
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);

$pdo = getDB();
$affil_id = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
// dean พิมพ์ได้เฉพาะคณะตัวเอง → บังคับ faculty (กันเลี่ยงผ่าน ?view=system)
$view = (($_SESSION['role'] ?? '') === 'dean')
    ? 'faculty'
    : (($_GET['view'] ?? 'system') === 'faculty' ? 'faculty' : 'system');
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$year_label = '';
foreach (ghg_years($pdo) as $y) { if ($y['year_id'] == $year) { $year_label = $y['year']; break; } }

$scope = ghg_scope_totals($pdo, $year, $view === 'faculty' ? $affil_id : null);
$total = $scope[1] + $scope[2] + $scope[3];
$removal = $view === 'faculty' ? removal_activity_total($pdo, $year, $affil_id) : removal_total($pdo, $year);
$rows  = $view === 'faculty' ? ghg_affil_detail($pdo, $affil_id, $year) : ghg_by_affiliation($pdo, $year);
// แถวรวมท้ายตารางรายคณะ = บวกจากแถวที่แสดงจริง (ไม่ใช่ $total ซึ่งรวม survey/event ที่ไม่ได้อยู่ในตาราง)
$rows_total = $view === 'faculty' ? 0.0 : ghg_affil_sum($rows);
$event_rows = $removal_rows = []; $event_total = 0.0;
if ($view === 'faculty') {
    $event_rows   = event_emission_list($pdo, $year, $affil_id);
    $removal_rows = removal_activity_list($pdo, $year, $affil_id);
    foreach ($event_rows as $er) $event_total += (float) $er['emission'];
}
// ยอดปล่อยรวม (gross) รวมกิจกรรม + Net (ปล่อยทั้งหมด − ดูดกลับ)
$gross_scope = $scope;
foreach ($event_rows as $er) { $sc = (int)$er['scope']; if (isset($gross_scope[$sc])) $gross_scope[$sc] += (float)$er['emission']; }
$gross_total = $gross_scope[1] + $gross_scope[2] + $gross_scope[3];
$net = $gross_total - $removal;
$scopeName = $view === 'faculty' ? ('คณะ ' . $affil_name) : 'ทั้งระบบ (ทุกคณะ)';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์รายงาน GHG — <?= htmlspecialchars($year_label) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Sarabun', sans-serif; color: #1F2937; margin: 32px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #6B7280; margin-bottom: 18px; }
        .summary { display: flex; gap: 24px; margin-bottom: 20px; flex-wrap: wrap; }
        .summary div { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #62368B; color: #fff; padding: 8px 10px; text-align: left; }
        td { padding: 7px 10px; border-bottom: 1px solid #E5E7EB; }
        td.num, th.num { text-align: right; }
        td.c, th.c { text-align: center; }
        tr.total td { font-weight: 700; background: #F3EAFF; }
        .toolbar { margin-bottom: 16px; }
        .toolbar button { background:#62368B;color:#fff;border:none;padding:10px 22px;border-radius:8px;cursor:pointer;font-family:inherit;font-size:14px; }
        @media print { .toolbar { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="toolbar"><button onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;"><?= ic('print',16) ?> พิมพ์ / บันทึกเป็น PDF</button></div>

    <h1>รายงานการปล่อยก๊าซเรือนกระจก (GHG)</h1>
    <div class="sub"><?= htmlspecialchars($scopeName) ?> · ปีงบประมาณ <?= htmlspecialchars($year_label) ?> · พิมพ์เมื่อ <?= date('d/m/Y H:i') ?></div>

    <div class="summary">
        <div style="color:#F97316;">ขอบเขต 1: <?= number_format($gross_scope[1], 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#EC4899;">ขอบเขต 2: <?= number_format($gross_scope[2], 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#3B82F6;">ขอบเขต 3: <?= number_format($gross_scope[3], 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#62368B;">รวมการปล่อย<?= $view==='faculty' ? ' (ดำเนินงาน '.number_format($total,2).' + กิจกรรม '.number_format($event_total,2).')' : '' ?>: <?= number_format($gross_total, 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#166534;">ดูดกลับ<?= $view==='faculty'?' (คณะ)':' (มหาวิทยาลัย)' ?>: <?= number_format($removal, 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#111827;font-weight:700;">สุทธิ (Net = ปล่อยทั้งหมด − ดูดกลับ): <?= number_format($net, 2, '.', ',') ?> tCO₂e</div>
    </div>

    <table>
        <?php if ($view === 'faculty'): ?>
            <thead><tr><th class="c">#</th><th class="c">ขอบเขต</th><th>รายการ</th><th>หน่วย</th><th class="num">จำนวน</th><th class="num">tCO₂e</th></tr></thead>
            <tbody>
                <?php $i=1; foreach ($rows as $r): ?>
                    <tr>
                        <td class="c"><?= $i++ ?></td>
                        <td class="c">ขอบเขต <?= (int)$r['scope'] ?></td>
                        <td><?= htmlspecialchars($r['name_tiem']) ?></td>
                        <td><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                        <td class="num"><?= qty_fmt($r['vol']) ?></td>
                        <td class="num"><?= number_format((float)$r['emission'], 4, '.', ',') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?><tr><td colspan="6" class="c">ยังไม่มีข้อมูล</td></tr><?php endif; ?>
            </tbody>
        <?php else: ?>
            <thead><tr><th class="c">#</th><th>คณะ/หน่วยงาน</th><th class="num">tCO₂e</th></tr></thead>
            <tbody>
                <?php $i=1; foreach ($rows as $r): ?>
                    <tr>
                        <td class="c"><?= $i++ ?></td>
                        <td><?= htmlspecialchars($r['affiliation_item']) ?></td>
                        <td class="num"><?= number_format((float)$r['total_emission'], 2, '.', ',') ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total"><td></td><td>รวมทุกคณะ (จากการดำเนินงาน)</td><td class="num"><?= number_format((float)$rows_total, 2, '.', ',') ?></td></tr>
            </tbody>
        <?php endif; ?>
    </table>

    <?php if ($view === 'faculty' && !empty($event_rows)): ?>
    <h2 style="font-size:1rem;margin:1.5rem 0 .5rem;color:#374151;">การปล่อยจากกิจกรรมที่คณะจัด <span style="font-weight:400;color:#8A8194;font-size:.85rem;">(แยกจากยอดหลัก · รวม <?= number_format($event_total,4) ?> tCO₂e)</span></h2>
    <table>
        <thead><tr><th>กิจกรรม</th><th class="c">ขอบเขต</th><th>รายการ</th><th>หน่วย</th><th class="num">จำนวน</th><th class="num">tCO₂e</th></tr></thead>
        <tbody>
            <?php foreach ($event_rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['event_name'] ?? '-') ?></td>
                    <td class="c">ขอบเขต <?= (int)$r['scope'] ?></td>
                    <td><?= htmlspecialchars($r['name_tiem']) ?></td>
                    <td><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                    <td class="num"><?= qty_fmt($r['qty']) ?></td>
                    <td class="num"><?= number_format((float)$r['emission'], 4, '.', ',') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($view === 'faculty' && !empty($removal_rows)): ?>
    <h2 style="font-size:1rem;margin:1.5rem 0 .5rem;color:#374151;">การดูดกลับจากกิจกรรมที่คณะจัด <span style="font-weight:400;color:#8A8194;font-size:.85rem;">(รวม <?= number_format($removal,4) ?> tCO₂e)</span></h2>
    <table>
        <thead><tr><th>กิจกรรม</th><th>รายการดูดกลับ</th><th>หน่วย</th><th class="num">ค่าดูดกลับ (kgCO₂e/หน่วย)</th><th class="num">ปริมาณ</th><th class="num">tCO₂e</th></tr></thead>
        <tbody>
            <?php foreach ($removal_rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['event_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($r['name_tiem']) ?></td>
                    <td><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                    <td class="num"><?= number_format((float)$r['factor'], 4, '.', ',') ?></td>
                    <td class="num"><?= qty_fmt($r['qty']) ?></td>
                    <td class="num"><?= number_format((float)$r['emission'], 4, '.', ',') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</body>
</html>
