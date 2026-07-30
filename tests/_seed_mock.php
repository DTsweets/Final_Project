<?php
/**
 * Mock-data seeder (สำหรับทดสอบ dashboard_numbers_test.php)
 * -------------------------------------------------------
 * สร้างข้อมูลจำลองใน "ปีแยก 2999" เพื่อไม่กระทบตัวเลขจริง แล้วลบคืนได้สะอาด
 *
 *   seed :  C:\xampp\php\php.exe tests\_seed_mock.php seed
 *   clean:  C:\xampp\php\php.exe tests\_seed_mock.php clean
 */
require __DIR__ . '/../config/db.php';
$pdo = getDB();
$MOCK_YEAR = 2999;
$mode = $argv[1] ?? '';

/** ลบข้อมูล mock ทั้งหมดของปี 2999 (ลูกก่อนแม่ ตาม FK) */
function clean(PDO $pdo, int $Y): void {
    $yid = (int)($pdo->query("SELECT id FROM admin_year WHERE year=$Y")->fetchColumn() ?: 0);
    if ($yid) {
        $pdo->exec("DELETE rei FROM removal_event_item rei JOIN event e ON e.id=rei.event_id WHERE e.year_id=$yid");
        $pdo->exec("DELETE FROM user_item WHERE year_id=$yid");
        $pdo->exec("DELETE FROM removal_item WHERE year_id=$yid");
        $pdo->exec("DELETE FROM admin_item WHERE year_id=$yid");
        $pdo->exec("DELETE FROM event WHERE year_id=$yid");
        $pdo->exec("DELETE FROM admin_year WHERE id=$yid");
    }
}

if ($mode === 'clean') { clean($pdo, $MOCK_YEAR); echo "cleaned mock year $MOCK_YEAR\n"; exit; }
if ($mode !== 'seed')  { fwrite(STDERR, "usage: _seed_mock.php seed|clean\n"); exit(2); }

// เริ่มใหม่เสมอ (กันซ้ำ)
clean($pdo, $MOCK_YEAR);

// scope id (admin_g) ของ scope 1/2/3
$ag = $pdo->query("SELECT scope, MIN(id) id FROM admin_g WHERE scope IN (1,2,3) GROUP BY scope")->fetchAll(PDO::FETCH_KEY_PAIR);
// สองคณะแรกในระบบ (ไว้ทดสอบ breakdown รายคณะ officer)
$affs = $pdo->query("SELECT id FROM affiliation_id ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$AF1 = (int)$affs[0]; $AF2 = (int)($affs[1] ?? $affs[0]);

$pdo->beginTransaction();

// ปี mock
$pdo->prepare("INSERT INTO admin_year (year) VALUES (?)")->execute([$MOCK_YEAR]);
$yid = (int)$pdo->lastInsertId();

// admin_item 3 ตัว (คนละ scope, คนละ source, AD รู้ค่าแน่นอน)
$insAI = $pdo->prepare("INSERT INTO admin_item (year_id,scope,name_tiem,unit,AD,data_source,affiliation_id) VALUES (?,?,?,?,?,?,?)");
$insAI->execute([$yid, $ag[1], 'MOCK officer item', 'kg', 2.0, 'officer', $AF1]);  $ai_off = (int)$pdo->lastInsertId();
$insAI->execute([$yid, $ag[2], 'MOCK survey item',  'kg', 3.0, 'survey',  1]);      $ai_sur = (int)$pdo->lastInsertId();
$insAI->execute([$yid, $ag[3], 'MOCK event item',   'kg', 4.0, 'event',   $AF1]);    $ai_evt = (int)$pdo->lastInsertId();

// user_item — ค่าที่คำนวณ tCO2e = Vol*AD/1000
$insUI = $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year,source) VALUES (?,?,?,?,CURDATE(),?)");
$insUI->execute([$ai_off, $AF1, $yid, 100, 'officer']);  // 100*2/1000 = 0.2  (คณะ AF1)
$insUI->execute([$ai_off, $AF2, $yid,  50, 'officer']);  //  50*2/1000 = 0.1  (คณะ AF2)
$insUI->execute([$ai_sur, 1,    $yid,  10, 'survey']);   //  10*3/1000 = 0.03
$insUI->execute([$ai_evt, $AF1, $yid,   5, 'event']);    //   5*4/1000 = 0.02
// รวม total ปี = 0.35 tCO2e

// removal ส่วนกลาง: qty*factor/1000 = 1000*0.5/1000 = 0.5
$pdo->prepare("INSERT INTO removal_item (year_id,name_tiem,unit,factor,qty) VALUES (?,?,?,?,?)")
    ->execute([$yid, 'MOCK ต้นไม้', 'ต้น', 0.5, 1000]);

// removal จากกิจกรรม: event + removal_event_item = 200*1.0/1000 = 0.2
$pdo->prepare("INSERT INTO event (name,kind,affiliation_id,year_id,event_date) VALUES (?,?,?,?,CURDATE())")
    ->execute(['MOCK กิจกรรมปลูกป่า', 'general', $AF1, $yid]);
$eid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO removal_event_item (event_id,name_tiem,unit,factor,qty) VALUES (?,?,?,?,?)")
    ->execute([$eid, 'MOCK พื้นที่ป่า', 'ไร่', 1.0, 200]);

$pdo->commit();

echo "seeded mock year $MOCK_YEAR (year_id=$yid)\n";
echo "  expect: total=0.35, officer=0.30 (AF1=0.2/AF2=0.1), survey=0.03, event=0.02\n";
echo "  expect: removal central=0.5, activity=0.2, total=0.7\n";
