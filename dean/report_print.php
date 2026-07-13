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
$view = ($_GET['view'] ?? 'system') === 'faculty' ? 'faculty' : 'system';
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$year_label = '';
foreach (ghg_years($pdo) as $y) { if ($y['year_id'] == $year) { $year_label = $y['year']; break; } }

$scope = ghg_scope_totals($pdo, $year, $view === 'faculty' ? $affil_id : null);
$total = $scope[1] + $scope[2] + $scope[3];
$rows  = $view === 'faculty' ? ghg_affil_detail($pdo, $affil_id, $year) : ghg_by_affiliation($pdo, $year);
$scopeName = $view === 'faculty' ? ('คณะ ' . $affil_name) : 'ทั้งระบบ (ทุกคณะ)';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์รายงาน GHG — <?= htmlspecialchars($year_label) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    <div class="toolbar"><button onclick="window.print()">🖨️ พิมพ์ / บันทึกเป็น PDF</button></div>

    <h1>รายงานการปล่อยก๊าซเรือนกระจก (GHG)</h1>
    <div class="sub"><?= htmlspecialchars($scopeName) ?> · ปีงบประมาณ <?= htmlspecialchars($year_label) ?> · พิมพ์เมื่อ <?= date('d/m/Y H:i') ?></div>

    <div class="summary">
        <div style="color:#F97316;">Scope 1: <?= number_format($scope[1], 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#EC4899;">Scope 2: <?= number_format($scope[2], 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#3B82F6;">Scope 3: <?= number_format($scope[3], 2, '.', ',') ?> tCO₂e</div>
        <div style="color:#62368B;">รวม: <?= number_format($total, 2, '.', ',') ?> tCO₂e</div>
    </div>

    <table>
        <?php if ($view === 'faculty'): ?>
            <thead><tr><th class="c">#</th><th class="c">Scope</th><th>รายการ</th><th>หน่วย</th><th class="num">จำนวน</th><th class="num">tCO₂e</th></tr></thead>
            <tbody>
                <?php $i=1; foreach ($rows as $r): ?>
                    <tr>
                        <td class="c"><?= $i++ ?></td>
                        <td class="c">Scope <?= (int)$r['scope'] ?></td>
                        <td><?= htmlspecialchars($r['name_tiem']) ?></td>
                        <td><?= htmlspecialchars($r['unit'] ?? '-') ?></td>
                        <td class="num"><?= number_format((float)$r['vol'], 4, '.', ',') ?></td>
                        <td class="num"><?= number_format((float)$r['emission'], 2, '.', ',') ?></td>
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
                <tr class="total"><td></td><td>รวมทั้งระบบ</td><td class="num"><?= number_format((float)$total, 2, '.', ',') ?></td></tr>
            </tbody>
        <?php endif; ?>
    </table>
</body>
</html>
