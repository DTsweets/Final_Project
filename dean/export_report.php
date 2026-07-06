<?php
/**
 * DEAN — Export GHG report เป็น Excel (SpreadsheetML) รองรับไทยด้วยฟอนต์ Angsana New
 * params: view=system|faculty, year=<id>
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'user_n']);

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
    <Style ss:ID="sCenter"><Font ss:FontName="Angsana New" ss:Size="13"/><Alignment ss:Horizontal="Center"/></Style>
    <Style ss:ID="sTotal"><Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1"/><Interior ss:Color="#F3EAFF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.00"/></Style>
  </Styles>

  <Worksheet ss:Name="รายงาน GHG">
    <Table ss:DefaultRowHeight="22">
      <Row ss:Height="30"><Cell ss:StyleID="sTitle"><Data ss:Type="String">รายงานการปล่อยก๊าซเรือนกระจก — <?= htmlspecialchars($scopeName, ENT_XML1) ?> (ปี <?= htmlspecialchars($year_label, ENT_XML1) ?>)</Data></Cell></Row>
      <Row><Cell ss:StyleID="sData"><Data ss:Type="String">Scope 1: <?= number_format($scope[1],2) ?> | Scope 2: <?= number_format($scope[2],2) ?> | Scope 3: <?= number_format($scope[3],2) ?> | รวม: <?= number_format($total,2) ?> tCO₂e</Data></Cell></Row>
      <Row></Row>

      <?php if ($view === 'faculty'): ?>
      <Row ss:Height="26">
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">#</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">Scope</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">รายการ</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">หน่วย</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">จำนวน</Data></Cell>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String">tCO2e</Data></Cell>
      </Row>
      <?php $i=1; foreach ($rows as $r): ?>
      <Row>
        <Cell ss:StyleID="sCenter"><Data ss:Type="Number"><?= $i ?></Data></Cell>
        <Cell ss:StyleID="sCenter"><Data ss:Type="String">Scope <?= (int)$r['scope'] ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['name_tiem'], ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($r['unit'] ?? '-', ENT_XML1) ?></Data></Cell>
        <Cell ss:StyleID="sNum"><Data ss:Type="Number"><?= (float)$r['vol'] ?></Data></Cell>
        <Cell ss:StyleID="sNum"><Data ss:Type="Number"><?= (float)$r['emission'] ?></Data></Cell>
      </Row>
      <?php $i++; endforeach; ?>
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
        <Cell ss:StyleID="sTotal"><Data ss:Type="String">รวมทั้งระบบ</Data></Cell>
        <Cell ss:StyleID="sTotal"><Data ss:Type="Number"><?= (float)$total ?></Data></Cell>
      </Row>
      <?php endif; ?>
    </Table>
  </Worksheet>
</Workbook>
