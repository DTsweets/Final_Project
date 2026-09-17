<?php
/**
 * DEAN — Export GHG report เป็น Excel (SpreadsheetML) รองรับไทยด้วยฟอนต์ Angsana New
 * params: view=system|faculty, year=<id>
 *
 * โครงสร้างตามรายงาน PDF (report_print.php):
 *   แผ่นงาน "สรุป"   — บทสรุป (เทียบปีก่อนเมื่อเทียบได้) → ผลแยกตามแหล่งปล่อย จัดกลุ่มตามขอบเขต → [ทั้งระบบ] รายหน่วยงาน
 *   แผ่นงาน "รายการ" — [คณะ] รายการที่กรอกทั้งหมด จัดกลุ่มตามขอบเขต (รวมรายการจากกิจกรรม) + การดูดกลับจากกิจกรรม
 * ตัวเลขเก็บเป็นชนิด Number (ไม่ใช่ข้อความ) เพื่อให้ผู้ใช้นำไปคำนวณต่อใน Excel ได้
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/ghg_report.php';
require_role(['admin', 'dean']);

$pdo = getDB();
$affil_id = (int)($_SESSION['affiliation_id'] ?? 0);
$affil_name = $_SESSION['affiliation_name'] ?? '-';
// มุมมองต้องตรงกับหน้า reports.php เสมอ — ใช้ฟังก์ชันกลางตัวเดียวกัน
$view = ghg_resolve_view($_SESSION['role'] ?? '', $_GET['view'] ?? null);
$aff  = $view === 'faculty' ? $affil_id : null;

// ปีที่รายงาน — ไม่ระบุ/ไม่พบ → ใช้ปีล่าสุด (เหมือน PDF)
$years = ghg_years($pdo);
$year  = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$year_label = '';
foreach ($years as $y) { if ((int)$y['year_id'] === $year) { $year_label = (string)$y['year']; break; } }
if ($year_label === '' && $years) { $year = (int)$years[0]['year_id']; $year_label = (string)$years[0]['year']; }

// ── ตัวเลข: ฟังก์ชันกลางตัวเดียวกับหน้าเว็บและ PDF ──
$sum  = ghg_report_summary($pdo, $year, $aff);
$cmp  = ghg_year_comparison($pdo, $years, $year, $aff);
$prev = $cmp['prev'];                                                  // null = ไม่มีคอลัมน์ปีก่อน/เปลี่ยนแปลง
$prev_label = $cmp['prev_year']['year'] ?? '';
$lines      = ghg_summary_lines($view);
$cat_groups = ghg_categories_by_scope(ghg_category_totals($pdo, $year, $aff));
$scope_desc = [1 => 'การปล่อยโดยตรง', 2 => 'การปล่อยทางอ้อมจากการใช้พลังงาน', 3 => 'การปล่อยทางอ้อมอื่น ๆ'];
$share      = fn(float $v) => $sum['gross'] > 0 ? $v / $sum['gross'] : 0.0;   // สัดส่วน (0–1) แสดงผลเป็น % ด้วย NumberFormat

// มุมมองคณะ: รายการทั้งหมดจัดกลุ่มตามขอบเขต (ยอดแต่ละกลุ่ม = ยอดรายขอบเขตของ $sum)
$detail = $view === 'faculty' ? ghg_detail_by_scope(ghg_affil_detail($pdo, $affil_id, $year), $sum['event_rows'], $sum['survey_rows']) : [];
// มุมมองทั้งระบบ: ตารางรายหน่วยงาน (เฉพาะการดำเนินงาน) — แถวรวมบวกจากแถวที่แสดงจริง
$rows       = $view === 'system' ? array_values(array_filter(ghg_by_affiliation($pdo, $year), fn($r) => (float)$r['total_emission'] > 0)) : [];
$rows_total = ghg_affil_sum($rows);

date_default_timezone_set('Asia/Bangkok');
$org_unit = $view === 'faculty' ? $affil_name : 'ทั้งมหาวิทยาลัย (ทุกคณะ/หน่วยงาน)';
$filename = 'ghg_report_' . $view . '_' . $year_label . '_' . date('Ymd_His') . '.xls';

// ── ตัวช่วยสร้างเซลล์ ──
$x   = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$str = fn($v, string $style = 'sData', int $across = 0) => '<Cell ss:StyleID="' . $style . '"' . ($across ? ' ss:MergeAcross="' . $across . '"' : '')
                                      . '><Data ss:Type="String">' . $x($v) . '</Data></Cell>';
$num = fn($v, string $style = 'sNum') => '<Cell ss:StyleID="' . $style . '"><Data ss:Type="Number">' . (float)$v . '</Data></Cell>';
$blank = fn(string $style = 'sData') => '<Cell ss:StyleID="' . $style . '"/>';

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
    <Style ss:ID="sTitle"><Font ss:FontName="Angsana New" ss:Size="20" ss:Bold="1" ss:Color="#62368B"/></Style>
    <Style ss:ID="sSection"><Font ss:FontName="Angsana New" ss:Size="16" ss:Bold="1" ss:Color="#62368B"/></Style>
    <Style ss:ID="sMetaKey"><Font ss:FontName="Angsana New" ss:Size="13" ss:Color="#6B7280"/></Style>
    <Style ss:ID="sMeta"><Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1"/></Style>
    <Style ss:ID="sHeader">
      <Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#62368B" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    </Style>
    <Style ss:ID="sData"><Font ss:FontName="Angsana New" ss:Size="13"/>
      <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders></Style>
    <Style ss:ID="sIndent" ss:Parent="sData"><Alignment ss:Indent="2"/></Style>
    <Style ss:ID="sNum" ss:Parent="sData"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.00"/></Style>
    <Style ss:ID="sNum4" ss:Parent="sData"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.0000"/></Style>
    <Style ss:ID="sQty" ss:Parent="sData"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.##"/></Style>
    <Style ss:ID="sPct" ss:Parent="sData"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="0.0%"/></Style>
    <Style ss:ID="sCenter" ss:Parent="sData"><Alignment ss:Horizontal="Center"/></Style>
    <!-- แถวหัวกลุ่มขอบเขต (ยอดย่อย) -->
    <Style ss:ID="sScope"><Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1"/><Interior ss:Color="#FAF8FC" ss:Pattern="Solid"/>
      <Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E9E4EF"/></Borders></Style>
    <Style ss:ID="sScopeNum" ss:Parent="sScope"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.00"/></Style>
    <Style ss:ID="sScopeNum4" ss:Parent="sScope"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.0000"/></Style>
    <Style ss:ID="sScopePct" ss:Parent="sScope"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="0.0%"/></Style>
    <!-- แถวรวม / ตัวหนา -->
    <Style ss:ID="sTotal"><Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1"/><Interior ss:Color="#F3EAFF" ss:Pattern="Solid"/></Style>
    <Style ss:ID="sTotalNum" ss:Parent="sTotal"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="#,##0.00"/></Style>
    <Style ss:ID="sTotalPct" ss:Parent="sTotal"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="0.0%"/></Style>
    <Style ss:ID="sStrong" ss:Parent="sData"><Font ss:FontName="Angsana New" ss:Size="13" ss:Bold="1"/></Style>
    <Style ss:ID="sStrongNum" ss:Parent="sNum"><Font ss:FontName="Angsana New" ss:Size="13" ss:Bold="1"/></Style>
    <Style ss:ID="sSubLabel" ss:Parent="sData"><Font ss:FontName="Angsana New" ss:Size="12" ss:Color="#6B7280"/><Alignment ss:Indent="2"/></Style>
    <Style ss:ID="sSubNum" ss:Parent="sNum"><Font ss:FontName="Angsana New" ss:Size="12" ss:Color="#6B7280"/></Style>
    <Style ss:ID="sEvent" ss:Parent="sData"><Font ss:FontName="Angsana New" ss:Size="13" ss:Color="#B45309"/></Style>
  </Styles>

  <!-- ═══ แผ่นงาน 1: สรุป ═══ -->
  <Worksheet ss:Name="สรุป">
    <Table ss:DefaultRowHeight="22">
      <Column ss:Width="300"/><Column ss:Width="110"/><Column ss:Width="110"/><Column ss:Width="110"/>
      <Row ss:Height="32"><?= $str('รายงานคาร์บอนฟุตพริ้นท์ขององค์กร', 'sTitle', 3) ?></Row>
      <Row><?= $str('หน่วยงาน', 'sMetaKey') . $str($org_unit, 'sMeta', 2) ?></Row>
      <Row><?= $str('ปีที่รายงาน', 'sMetaKey') . $str($year_label, 'sMeta', 2) ?></Row>
      <Row><?= $str('เปรียบเทียบกับ', 'sMetaKey') . $str($prev ? 'ปี ' . $prev_label : 'ไม่เปรียบเทียบ', 'sMeta', 2) ?></Row>
      <Row><?= $str('วันที่จัดทำ', 'sMetaKey') . $str(date('d/m/') . ((int)date('Y') + 543) . date(' H:i'), 'sMeta', 2) ?></Row>
      <Row/>

      <Row ss:Height="28"><?= $str('1. บทสรุป (tCO2e)', 'sSection', 3) ?></Row>
      <Row ss:Height="26">
        <?= $str('รายการ', 'sHeader') ?>
        <?php if ($prev): ?><?= $str('ปี ' . $prev_label, 'sHeader') ?><?php endif; ?>
        <?= $str('ปี ' . $year_label, 'sHeader') ?>
        <?php if ($prev): ?><?= $str('เปลี่ยนแปลง', 'sHeader') ?><?php endif; ?>
      </Row>
      <?php foreach ($lines as $l):
          $label = $l['label'] . ($l['desc'] !== '' ? ' · ' . $l['desc'] : '');
          [$ls, $ns] = match ($l['kind']) { 'strong' => ['sStrong', 'sStrongNum'], 'sub' => ['sSubLabel', 'sSubNum'], 'total' => ['sTotal', 'sTotalNum'], default => ['sData', 'sNum'] };
          $pc = $prev ? ghg_change((float)$sum[$l['key']], (float)$prev[$l['key']])['pct'] : null; ?>
      <Row>
        <?= $str($label, $ls) ?>
        <?php if ($prev): ?><?= $num($prev[$l['key']], $ns) ?><?php endif; ?>
        <?= $num($sum[$l['key']], $ns) ?>
        <?php if ($prev): ?><?= ($l['up_good'] === null || $pc === null) ? $blank($l['kind'] === 'total' ? 'sTotal' : 'sData') : $num($pc / 100, $l['kind'] === 'total' ? 'sTotalPct' : 'sPct') ?><?php endif; ?>
      </Row>
      <?php endforeach; ?>
      <Row/>

      <Row ss:Height="28"><?= $str('2. ผลการคำนวณแยกตามแหล่งปล่อย (tCO2e)', 'sSection', 3) ?></Row>
      <Row ss:Height="26"><?= $str('แหล่งปล่อย', 'sHeader') . $str('tCO2e', 'sHeader') . $str('สัดส่วนของการปล่อยทั้งหมด', 'sHeader') ?></Row>
      <?php foreach ($cat_groups as $s => $grp): ?>
      <Row><?= $str('ขอบเขต ' . $s . ' · ' . $scope_desc[$s], 'sScope') . $num($grp['total'], 'sScopeNum') . $num($share($grp['total']), 'sScopePct') ?></Row>
        <?php foreach ($grp['items'] as $cat): ?>
      <Row><?= $str(ghg_group_label($cat['name']), 'sIndent') . $num($cat['value'], 'sNum') . $num($share($cat['value']), 'sPct') ?></Row>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <Row><?= $str('รวมการปล่อยทั้งหมด', 'sTotal') . $num($sum['gross'], 'sTotalNum') . $num($sum['gross'] > 0 ? 1 : 0, 'sTotalPct') ?></Row>

      <?php if ($view === 'system'): ?>
      <Row/>
      <Row ss:Height="28"><?= $str('3. การปล่อยรายหน่วยงาน (เฉพาะการดำเนินงาน · tCO2e)', 'sSection', 3) ?></Row>
      <Row ss:Height="26"><?= $str('คณะ/หน่วยงาน', 'sHeader') . $str('tCO2e', 'sHeader') . $str('สัดส่วน', 'sHeader') ?></Row>
        <?php foreach ($rows as $r): ?>
      <Row><?= $str($r['affiliation_item']) . $num($r['total_emission']) . $num($rows_total > 0 ? (float)$r['total_emission'] / $rows_total : 0, 'sPct') ?></Row>
        <?php endforeach; ?>
      <Row><?= $str('รวมทุกหน่วยงาน (จากการดำเนินงาน)', 'sTotal') . $num($rows_total, 'sTotalNum') . $num($rows_total > 0 ? 1 : 0, 'sTotalPct') ?></Row>
      <Row><?= $str('* ไม่รวมข้อมูลจากแบบสอบถามและกิจกรรม จึงน้อยกว่าการปล่อยทั้งหมดในหัวข้อที่ 1–2', 'sMetaKey', 3) ?></Row>
      <?php endif; ?>
    </Table>
  </Worksheet>

  <?php if ($view === 'faculty'): ?>
  <!-- ═══ แผ่นงาน 2: รายการ (คณะ) ═══ -->
  <Worksheet ss:Name="รายการ">
    <Table ss:DefaultRowHeight="22">
      <Column ss:Width="190"/><Column ss:Width="190"/><Column ss:Width="240"/><Column ss:Width="70"/><Column ss:Width="100"/><Column ss:Width="100"/>
      <Row ss:Height="32"><?= $str('รายการที่บันทึก — ' . $org_unit . ' ปี ' . $year_label, 'sTitle', 5) ?></Row>
      <Row/>
      <Row ss:Height="28"><?= $str('การปล่อย จัดกลุ่มตามขอบเขต (รวมรายการจากกิจกรรมที่คณะจัด และแบบสอบถามในขอบเขต 3)', 'sSection', 5) ?></Row>
      <Row ss:Height="26"><?= $str('แหล่งที่มา', 'sHeader') . $str('หมวด', 'sHeader') . $str('รายการ', 'sHeader') . $str('หน่วย', 'sHeader') . $str('จำนวน', 'sHeader') . $str('tCO2e', 'sHeader') ?></Row>
      <?php foreach ($detail as $s => $grp): ?>
      <Row><?= $str('ขอบเขต ' . $s . ' · ' . $scope_desc[$s], 'sScope', 4) . $num($grp['total'], 'sScopeNum4') ?></Row>
        <?php foreach ($grp['rows'] as $r): ?>
      <Row><?= ($r['source'] === 'event' ? $str('กิจกรรม: ' . $r['event'], 'sEvent') : ($r['source'] === 'survey' ? $str('แบบสอบถาม', 'sEvent') : $str('การดำเนินงาน', 'sIndent')))
             . $str($r['category']) . $str($r['name']) . $str($r['unit'], 'sCenter') . $num($r['qty'], 'sQty') . $num($r['emission'], 'sNum4') ?></Row>
        <?php endforeach; ?>
        <?php if (!$grp['rows']): ?>
      <Row><?= $str('ยังไม่มีข้อมูล', 'sIndent', 5) ?></Row>
        <?php endif; ?>
      <?php endforeach; ?>
      <Row><?= $str('รวมการปล่อยทั้งหมด', 'sTotal', 4) . $num($sum['gross'], 'sTotalNum') ?></Row>

      <?php if (!empty($sum['removal_rows'])): ?>
      <Row/>
      <Row ss:Height="28"><?= $str('การดูดกลับจากกิจกรรมที่คณะจัด', 'sSection', 5) ?></Row>
      <Row ss:Height="26"><?= $str('กิจกรรม', 'sHeader') . $str('รายการดูดกลับ', 'sHeader') . $str('ค่าดูดกลับ (kgCO2e/หน่วย)', 'sHeader') . $str('หน่วย', 'sHeader') . $str('ปริมาณ', 'sHeader') . $str('tCO2e', 'sHeader') ?></Row>
        <?php foreach ($sum['removal_rows'] as $r): ?>
      <Row><?= $str($r['event_name'] ?? '-') . $str($r['name_tiem']) . $num($r['factor'], 'sNum4') . $str($r['unit'] ?? '-', 'sCenter') . $num($r['qty'], 'sQty') . $num($r['emission'], 'sNum4') ?></Row>
        <?php endforeach; ?>
      <Row><?= $str('รวมการดูดกลับ', 'sTotal', 4) . $num($sum['removal'], 'sTotalNum') ?></Row>
      <?php endif; ?>
    </Table>
  </Worksheet>
  <?php endif; ?>
</Workbook>
