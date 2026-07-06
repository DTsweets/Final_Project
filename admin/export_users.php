<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$pdo = getDB();

// ===== รับ parameters จาก Modal =====
$allowedCols = ['col_no', 'col_firstname', 'col_lastname', 'col_username', 'col_email', 'col_affiliation', 'col_role'];
$selectedCols = isset($_POST['cols']) ? array_intersect($_POST['cols'], $allowedCols) : $allowedCols;
// col_no is always included
if (!in_array('col_no', $selectedCols)) array_unshift($selectedCols, 'col_no');
$selectedCols = array_values($selectedCols);

$userIds = isset($_POST['user_ids']) ? $_POST['user_ids'] : ['all'];
$filterAll = (count($userIds) === 0 || $userIds === ['all'] || in_array('all', $userIds));

// ===== Query =====
$sql = "
    SELECT 
        u.id,
        u.firstname,
        u.lastname,
        u.username,
        u.email,
        COALESCE(a.affiliation_item, '-') AS affiliation_item,
        u.role
    FROM users u
    LEFT JOIN affiliation_id a ON u.Affiliation = a.id
";

if (!$filterAll) {
    $ids = array_map('intval', $userIds);
    $ids = array_filter($ids); // remove zeros
    if (empty($ids)) { http_response_code(400); exit('No valid IDs'); }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql .= " WHERE u.id IN ($placeholders)";
    $stmt = $pdo->prepare($sql . ' ORDER BY u.id ASC');
    $stmt->execute($ids);
} else {
    $stmt = $pdo->prepare($sql . ' ORDER BY u.id ASC');
    $stmt->execute();
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== Column Definitions =====
$roleLabel = [
    'admin'   => 'ผู้ดูแลระบบ',
    'user'    => 'เจ้าหน้าที่บันทึกข้อมูล',
    'user_n'  => 'บุคลากร/คณบดี',
];
$colDef = [
    'col_no'          => ['header' => '#',                  'width' => 40,  'key' => null,              'type' => 'Number', 'style' => 'sNum'],
    'col_firstname'   => ['header' => 'ชื่อ',               'width' => 120, 'key' => 'firstname',       'type' => 'String', 'style' => 'sData'],
    'col_lastname'    => ['header' => 'นามสกุล',            'width' => 120, 'key' => 'lastname',        'type' => 'String', 'style' => 'sData'],
    'col_username'    => ['header' => 'Username',           'width' => 120, 'key' => 'username',        'type' => 'String', 'style' => 'sData'],
    'col_email'       => ['header' => 'Email',              'width' => 180, 'key' => 'email',           'type' => 'String', 'style' => 'sData'],
    'col_affiliation' => ['header' => 'หน่วยงาน/สังกัด',   'width' => 200, 'key' => 'affiliation_item','type' => 'String', 'style' => 'sData'],
    'col_role'        => ['header' => 'สิทธิ์การใช้งาน',   'width' => 150, 'key' => 'role',            'type' => 'String', 'style' => 'sRole'],
];

$filename = 'users_export_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

  <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
    <Title>รายการผู้ใช้งานระบบ</Title>
    <Author>UP Net Zero Admin</Author>
    <Created><?= date('Y-m-d') ?>T<?= date('H:i:s') ?>Z</Created>
  </DocumentProperties>

  <Styles>
    <Style ss:ID="Default" ss:Name="Normal">
      <Font ss:FontName="Angsana New" ss:Size="14"/>
      <Alignment ss:Vertical="Center"/>
    </Style>
    <!-- Header: พื้นน้ำเงิน, ตัวอักษรขาว, ตัวหนา -->
    <Style ss:ID="sHeader">
      <Font ss:FontName="Angsana New" ss:Size="14" ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#4B8BF5" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3A78E0"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3A78E0"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3A78E0"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#3A78E0"/>
      </Borders>
    </Style>
    <!-- Data cell -->
    <Style ss:ID="sData">
      <Font ss:FontName="Angsana New" ss:Size="13"/>
      <Interior ss:Color="#FAFAFA" ss:Pattern="Solid"/>
      <Alignment ss:Vertical="Center" ss:WrapText="1"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
      </Borders>
    </Style>
    <!-- Number cell (centered) -->
    <Style ss:ID="sNum">
      <Font ss:FontName="Angsana New" ss:Size="13"/>
      <Interior ss:Color="#FAFAFA" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/>
      </Borders>
    </Style>
    <!-- Role cell -->
    <Style ss:ID="sRole">
      <Font ss:FontName="Angsana New" ss:Size="13" ss:Bold="1" ss:Color="#374151"/>
      <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
      <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
      <Borders>
        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
        <Border ss:Position="Left"   ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
        <Border ss:Position="Right"  ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
        <Border ss:Position="Top"    ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      </Borders>
    </Style>
  </Styles>

  <Worksheet ss:Name="ผู้ใช้งาน">
    <Table ss:DefaultRowHeight="22">

      <!-- Column widths (only for selected cols) -->
      <?php foreach ($selectedCols as $colKey): ?>
      <Column ss:Width="<?= $colDef[$colKey]['width'] ?>"/>
      <?php endforeach; ?>

      <!-- Header Row -->
      <Row ss:Height="28">
        <?php foreach ($selectedCols as $colKey): ?>
        <Cell ss:StyleID="sHeader"><Data ss:Type="String"><?= htmlspecialchars($colDef[$colKey]['header'], ENT_XML1) ?></Data></Cell>
        <?php endforeach; ?>
      </Row>

      <!-- Data Rows -->
      <?php $i = 1; foreach ($users as $u): ?>
      <Row ss:Height="22">
        <?php foreach ($selectedCols as $colKey):
            $def = $colDef[$colKey];
            if ($colKey === 'col_no'):
        ?>
        <Cell ss:StyleID="sNum"><Data ss:Type="Number"><?= $i ?></Data></Cell>
        <?php elseif ($colKey === 'col_role'): ?>
        <Cell ss:StyleID="sRole"><Data ss:Type="String"><?= htmlspecialchars($roleLabel[$u['role']] ?? strtoupper($u['role'] ?? ''), ENT_XML1) ?></Data></Cell>
        <?php else: ?>
        <Cell ss:StyleID="sData"><Data ss:Type="String"><?= htmlspecialchars($u[$def['key']] ?? '', ENT_XML1) ?></Data></Cell>
        <?php endif; endforeach; ?>
      </Row>
      <?php $i++; endforeach; ?>

    </Table>

    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
      <FreezePanes/>
      <FrozenNoSplit/>
      <SplitHorizontal>1</SplitHorizontal>
      <TopRowBottomPane>1</TopRowBottomPane>
      <ActivePane>2</ActivePane>
      <Print>
        <FitWidth>1</FitWidth>
        <Landscape/>
        <PaperSizeIndex>9</PaperSizeIndex>
      </Print>
    </WorksheetOptions>
  </Worksheet>
</Workbook>
