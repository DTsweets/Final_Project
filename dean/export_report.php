<?php
/**
 * DEAN — Export GHG report เป็น Excel (SpreadsheetML) รองรับไทยด้วยฟอนต์ Angsana New
 * params: view=system|faculty, year=<id>
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);

$pdo = getDB();
$affil_id = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
// dean ดาวน์โหลดได้เฉพาะคณะตัวเอง → บังคับ faculty (กันเลี่ยงผ่าน ?view=system)
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
    foreach ($event_rows as $er) $event_total += (float)$er['emission'];
}
// ยอดปล่อยรวม (gross) รวมกิจกรรม + Net (ปล่อยทั้งหมด − ดูดกลับ)
$gross_scope = $scope;
foreach ($event_rows as $er) { $sc = (int)$er['scope']; if (isset($gross_scope[$sc])) $gross_scope[$sc] += (float)$er['emission']; }
$gross_total = $gross_scope[1] + $gross_scope[2] + $gross_scope[3];
$net = $gross_total - $removal;

$scopeName = $view === 'faculty' ? ('คณะ ' . $affil_name) : 'ทั้งระบบ';
$filename = 'ghg_report_' . $view . '_' . $year_label . '_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF";
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
  <Styles>
    <Style ss:ID="Default"><Font ss:FontName="Angsana New" ss:Size="14"/><Alignment ss:Vertical="Center"/></Style>
    <Style ss:ID="sTitle"><Font ss:FontName="Angsana New" ss:Size="18" ss:Bold="1" ss:Color="#1F2937"/></Style>
    <Style ss:ID="sHeader">
      <Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#62368B" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    </Style>
    <Style ss:ID="sData"><Font ss:FontName="Angsana New" ss:Size="13"/>
      <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders></Style>
    <Style ss:ID="sNum"><Font ss:FontName="Angsana New" ss:Size="13"/><Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.00"/>
      <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders></Style>
    <Style ss:ID="sNum4"><Font ss:FontName="Angsana New" ss:Size="13"/><Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.0000"/>
      <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders></Style>
    <Style ss:ID="sQty"><Font ss:FontName="Angsana New" ss:Size="13"/><Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.##"/>
      <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders></Style>
    <Style ss:ID="sCenter"><Font ss:FontName="Angsana New" ss:Size="13"/><Alignment ss:Horizontal="Center"/></Style>
    <Style ss:ID="sTotal"><Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1"/><Interior ss:Color="#F3EAFF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.00"/></Style>
  </Styles>

  <Worksheet ss:Name="รายงาน GHG">
    <Table ss:DefaultRowHeight="22">
      <Row ss:Height="30"><Cell ss:StyleID="sTitle"><Data ss:Type="String">รายงานการปล่อยก๊าซเรือนกระจก — <?= htmlspecialchars($scopeName, ENT_XML1) ?> (ปี <?= htmlspecialchars($year_label, ENT_XML1) ?>)</Data></Cell></Row>
      <Row><Cell ss:StyleID="sData"><Data ss:Type="String">ขอบเขต 1: <?= number_format($gross_scope[1],2) ?> | ขอบเขต 2: <?= number_format($gross_scope[2],2) ?> | ขอบเขต 3: <?= number_format($gross_scope[3],2) ?> | รวมการปล่อย<?= $view==='faculty' ? ' (ดำเนินงาน '.number_format($total,2).' + กิจกรรม '.number_format($event_total,2).')' : '' ?>: <?= number_format($gross_total,2) ?> tCO₂e</Data></Cell></Row>
      <Row><Cell ss:StyleID="sData"><Data ss:Type="String">ดูดกลับ<?= $view==='faculty'?' (คณะ)':' (มหาวิทยาลัย)' ?>: <?= number_format($removal,2) ?> tCO₂e | สุทธิ (Net = ปล่อยทั้งหมด − ดูดกลับ): <?= number_format($net,2) ?> tCO₂e</Data></Cell></Row>
      <Row></Row>

      <?php if ($view === 'faculty'): ?>
      <Row ss:Height="26">
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">#</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">ขอบเขต</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">รายการ</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">หน่วย</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">จำนวน</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">tCO2e</Data></Cell>
      </Row>
      <?php $i=1; foreach ($rows as $r): ?>
      <Row>
        <Cell ss:StyleID="sCenter"><Data ss:Type="Number"><?= $i ?></Data></Cell>
        <Cell ss:StyleID="sCenter"><Data ss:Type="String">ขอบเขต <?= (int)$r['scope'] ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['name_tiem'], ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['unit'] ?? '-', ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sQty"><Data ss:Type="Number"><?= (float)$r['vol'] ?></Data></Cell>
        <Cell ss:StyleID="sNum4"><Data ss:Type="Number"><?= (float)$r['emission'] ?></Data></Cell>
      </Row>
      <?php $i++; endforeach; ?>

      <?php if (!empty($event_rows)): ?>
      <Row></Row>
      <Row ss:Height="26"><Cell ss:StyleID="sTitle"><Data ss:Type="String">การปล่อยจากกิจกรรมที่คณะจัด (แยกจากยอดหลัก)</Data></Cell></Row>
      <Row ss:Height="26">
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">กิจกรรม</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">ขอบเขต</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">รายการ</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">หน่วย</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">จำนวน</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">tCO2e</Data></Cell>
      </Row>
      <?php foreach ($event_rows as $r): ?>
      <Row>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['event_name'] ?? '-', ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sCenter"><Data ss:Type="String">ขอบเขต <?= (int)$r['scope'] ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['name_tiem'], ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['unit'] ?? '-', ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sQty"><Data ss:Type="Number"><?= (float)$r['qty'] ?></Data></Cell>
        <Cell ss:StyleID="sNum4"><Data ss:Type="Number"><?= (float)$r['emission'] ?></Data></Cell>
      </Row>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($removal_rows)): ?>
      <Row></Row>
      <Row ss:Height="26"><Cell ss:StyleID="sTitle"><Data ss:Type="String">การดูดกลับจากกิจกรรมที่คณะจัด</Data></Cell></Row>
      <Row ss:Height="26">
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">กิจกรรม</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">รายการดูดกลับ</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">หน่วย</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">ค่าดูดกลับ (kgCO2e/หน่วย)</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">ปริมาณ</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">tCO2e</Data></Cell>
      </Row>
      <?php foreach ($removal_rows as $r): ?>
      <Row>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['event_name'] ?? '-', ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['name_tiem'], ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['unit'] ?? '-', ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sNum4"><Data ss:Type="Number"><?= (float)$r['factor'] ?></Data></Cell>
        <Cell ss:StyleID="sQty"><Data ss:Type="Number"><?= (float)$r['qty'] ?></Data></Cell>
        <Cell ss:StyleID="sNum4"><Data ss:Type="Number"><?= (float)$r['emission'] ?></Data></Cell>
      </Row>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php else: ?>
      <Row ss:Height="26">
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">#</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">คณะ/หน่วยงาน</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">tCO2e</Data></Cell>
      </Row>
      <?php $i=1; foreach ($rows as $r): ?>
      <Row>
        <Cell ss:StyleID="sCenter"><Data ss:Type="Number"><?= $i ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['affiliation_item'], ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sNum"><Data ss:Type="Number"><?= (float)$r['total_emission'] ?></Data></Cell>
      </Row>
      <?php $i++; endforeach; ?>
      <Row>
        <Cell ss:StyleID="sTotal"><Data ss:Type="String"></Data></Cell>
        <Cell ss:StyleID="sTotal"><Data ss:Type="String">รวมทุกคณะ (จากการดำเนินงาน)</Data></Cell>
        <Cell ss:StyleID="sTotal"><Data ss:Type="Number"><?= (float)$rows_total ?></Data></Cell>
      </Row>
      <?php endif; ?>
    </Table>
  </Worksheet>
</Workbook>
