<?php
/**
 * Test: create_or_get_affiliation() — เพิ่มหน่วยงานใหม่จากฟอร์มเพิ่มผู้ใช้
 * รัน:  C:\xampp\php\php.exe tests\affiliation_add_test.php
 * (อ่าน/เขียนเฉพาะแถวทดสอบที่ขึ้นต้น "MOCK_AFF_" แล้วลบคืนท้ายเทสต์)
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/affiliation.php';

$pdo = getDB();
$pass = 0; $fail = 0; $fails = [];
function check($name, $cond) {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  \xE2\x9C\x94 PASS  $name\n"; }
    else { $fail++; $fails[] = $name; echo "  \xE2\x9C\x98 FAIL  $name\n"; }
}

$NAME = 'MOCK_AFF_กองการเจ้าหน้าที่';

// เริ่มสะอาด
$pdo->prepare('DELETE FROM affiliation_id WHERE affiliation_item = ?')->execute([$NAME]);
$countBefore = (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn();

// [1] ชื่อใหม่ → สร้างแถวใหม่ คืน id
$id1 = create_or_get_affiliation($pdo, $NAME);
check('[1] ชื่อใหม่ → ได้ id > 0', $id1 > 0);
$exists = (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id WHERE affiliation_item = ' . $pdo->quote($NAME))->fetchColumn();
check('[1b] มีแถวใหม่ใน affiliation_id จริง', $exists === 1);
check('[1c] จำนวนแถวเพิ่มขึ้น 1', (int)$pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn() === $countBefore + 1);

// [2] ชื่อซ้ำ (นโยบาย ก) → ใช้ id เดิม ไม่สร้างซ้ำ
$id2 = create_or_get_affiliation($pdo, $NAME);
check('[2] ชื่อซ้ำ → คืน id เดิม', $id1 === $id2);
check('[2b] ไม่มีแถวซ้ำเพิ่ม', (int)$pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn() === $countBefore + 1);

// [3] ชื่อซ้ำแต่มีช่องว่างหน้า-หลัง → ยัง match ตัวเดิม (เพราะ trim)
$id3 = create_or_get_affiliation($pdo, "   $NAME   ");
check('[3] trim ช่องว่างแล้ว match id เดิม', $id1 === $id3);
check('[3b] ยังไม่มีแถวเพิ่ม', (int)$pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn() === $countBefore + 1);

// [4] ชื่อว่าง → โยน InvalidArgumentException
$threw = false;
try { create_or_get_affiliation($pdo, '   '); } catch (InvalidArgumentException $e) { $threw = true; }
check('[4] ชื่อว่าง → throw InvalidArgumentException', $threw);

// cleanup
$pdo->prepare('DELETE FROM affiliation_id WHERE affiliation_item = ?')->execute([$NAME]);
$countAfter = (int) $pdo->query('SELECT COUNT(*) FROM affiliation_id')->fetchColumn();
check('[5] ลบข้อมูลทดสอบคืน → จำนวนแถวเท่าเดิม', $countAfter === $countBefore);

echo "\n═══════════════════════════════\nPASS=$pass  FAIL=$fail\n";
if ($fail > 0) { echo "FAILED:\n  - " . implode("\n  - ", $fails) . "\n"; exit(1); }
echo "create_or_get_affiliation ทำงานถูกต้อง \xE2\x9C\x85\n";
