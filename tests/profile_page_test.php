<?php
/**
 * Unit Test — หน้าโปรไฟล์ (includes/profile_page.php + includes/profile_entry.php) · admin / officer / dean
 * รัน: C:\xampp\php\php.exe tests\profile_page_test.php
 *
 * ล็อกกฎ:
 *   - ตรวจข้อมูลครบก่อนแตะไฟล์ · เปลี่ยนรหัสต้องมีรหัสเดิมถูก + ยืนยันตรง + ไม่เกิน 50 ตัว
 *   - รูป: ต้องเป็นรูปจริง (ไม่ดูแค่นามสกุล), ไม่เกิน 2 MB
 *   - บันทึกสำเร็จจึงลบรูปเก่า / ไม่สำเร็จลบรูปใหม่ รูปเก่าอยู่ครบ
 *   - อีเมลว่าง = NULL (หลายคนเว้นว่างได้) · username / email ซ้ำแจ้งแยกช่อง
 *   - ทุก role เปิดหน้าของตัวเองได้ · admin/profile.php เปิดได้เฉพาะ admin
 * การทดสอบที่แก้ข้อมูลใช้ผู้ใช้ชั่วคราวใน transaction แล้ว rollback · ไฟล์รูปทดสอบอยู่ในโฟลเดอร์ชั่วคราวและลบทิ้ง
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/profile_entry.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
function in_tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try { return $fn(); } finally { $pdo->rollBack(); }
}
$usersSnap = fn() => md5(json_encode($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()));
$before = $usersSnap();
$profilesDir = __DIR__ . '/../assets/images/profiles';
$filesBefore = scandir($profilesDir);

$user = ['password' => 'old1', 'profile_image' => null];
$base = ['firstname' => 'สมชาย', 'lastname' => 'ใจดี', 'username' => 'somchai', 'email' => 'a@up.ac.th'];

// ── V ตรวจข้อมูล ──
[$e] = profile_validate(['firstname' => ' ', 'lastname' => '', 'username' => 'a b', 'email' => 'not-mail'], $user);
ck('V1 ชื่อ/นามสกุลว่าง, username มีช่องว่าง, อีเมลผิดรูปแบบ → แจ้งแยกช่อง',
    isset($e['firstname'], $e['lastname'], $e['email']) && $e['username'] === 'ชื่อผู้ใช้งานต้องไม่มีช่องว่าง', json_encode($e, JSON_UNESCAPED_UNICODE));
[$e, $d] = profile_validate($base + ['old_password' => '', 'password' => '', 'password_confirm' => ''], $user);
[, $d2] = profile_validate(['email' => ' '] + $base, $user);
ck('V2 ข้อมูลถูกต้อง ไม่เปลี่ยนรหัส → ไม่มีข้อผิดพลาด, password = null, อีเมลว่าง = NULL', !$e && $d['password'] === null && $d['email'] === 'a@up.ac.th' && $d2['email'] === null);
$pw = fn(array $x) => profile_validate($base + $x, $user)[0];
ck('V3 เปลี่ยนรหัส: ไม่ใส่รหัสเดิม / รหัสเดิมผิด / ยืนยันไม่ตรง / ยาวเกิน 50 / สั้นกว่า 8 → ผิด',
    ($pw(['password' => 'n', 'password_confirm' => 'n'])['old_password'] ?? '') === 'กรุณากรอกรหัสผ่านเดิม'
    && ($pw(['old_password' => 'x', 'password' => 'n', 'password_confirm' => 'n'])['old_password'] ?? '') === 'รหัสผ่านเดิมไม่ถูกต้อง'
    && isset($pw(['old_password' => 'old1', 'password' => 'n', 'password_confirm' => 'm'])['password_confirm'])
    && isset($pw(['old_password' => 'old1', 'password' => str_repeat('a', 51), 'password_confirm' => str_repeat('a', 51)])['password'])
    && isset($pw(['old_password' => 'old1', 'password' => '', 'password_confirm' => 'x'])['password'])
    && ($pw(['old_password' => 'old1', 'password' => 'short7x', 'password_confirm' => 'short7x'])['password'] ?? '') === 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'
    && !$pw(['old_password' => 'old1', 'password' => 'eight888', 'password_confirm' => 'eight888']));
ck('V3b ตรวจรหัสเดิมกับบัญชีที่เก็บเป็น hash แล้ว: ถูก → ผ่าน · ผิด → ข้อความ', (function () use ($base) {
    $hashed = ['password' => password_hash('old1', PASSWORD_DEFAULT), 'profile_image' => null];
    return !profile_validate($base + ['old_password' => 'old1', 'password' => 'newpass88', 'password_confirm' => 'newpass88'], $hashed)[0]
        && (profile_validate($base + ['old_password' => 'old2', 'password' => 'newpass88', 'password_confirm' => 'newpass88'], $hashed)[0]['old_password'] ?? '') === 'รหัสผ่านเดิมไม่ถูกต้อง';
})());
[$e, $d] = profile_validate($base + ['old_password' => 'old1', 'password' => str_repeat('a', 50), 'password_confirm' => str_repeat('a', 50)], $user);
ck('V4 เปลี่ยนรหัสถูกต้อง (50 ตัวพอดี) → password = รหัสใหม่', !$e && $d['password'] === str_repeat('a', 50));

// ── I ไฟล์รูป ──
$tmp = sys_get_temp_dir() . '/pf_test_' . getmypid();
@mkdir($tmp);
$png = $tmp . '/real.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII='));
$fake = $tmp . '/fake.jpg';
file_put_contents($fake, '<?php echo "not an image";');
ck('I1 รูปจริงผ่าน / ไฟล์ที่ตั้งนามสกุลเป็นรูปแต่ไม่ใช่รูป → ผิด / ใหญ่เกิน 2 MB → ผิด / ไม่ได้เลือกไฟล์ → ผ่าน',
    profile_check_image(['error' => UPLOAD_ERR_OK, 'size' => filesize($png), 'tmp_name' => $png]) === null
    && profile_check_image(['error' => UPLOAD_ERR_OK, 'size' => filesize($fake), 'tmp_name' => $fake]) === 'ไฟล์ไม่ใช่รูปภาพที่รองรับ (JPG, PNG, GIF, WEBP)'
    && profile_check_image(['error' => UPLOAD_ERR_OK, 'size' => PROFILE_IMG_MAX + 1, 'tmp_name' => $png]) === 'ไฟล์รูปใหญ่เกิน 2 MB'
    && profile_check_image(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '']) === 'ไฟล์รูปใหญ่เกิน 2 MB'
    && profile_check_image(['error' => UPLOAD_ERR_NO_FILE]) === null && profile_check_image(null) === null);

// ── A บันทึก (ผู้ใช้ชั่วคราวใน transaction) ──
$mkUser = function (string $un, ?string $img = null) use ($pdo) {
    $pdo->prepare("INSERT INTO users (username, password, firstname, lastname, role, profile_image, Affiliation, email) VALUES (?, 'old1', 'ท', 'ส', 'officer', ?, 1, ?)")
        ->execute([$un, $img, $un . '@test.local']);
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([(int) $pdo->lastInsertId()]);
    return $st->fetch();
};
$imgDir = $tmp . '/profiles';
@mkdir($imgDir);
ck('A1 เปลี่ยนรูปสำเร็จ: DB ชี้รูปใหม่, ไฟล์ใหม่มีอยู่, รูปเก่าถูกลบ', in_tx($pdo, function () use ($pdo, $mkUser, $imgDir, $png) {
    file_put_contents("$imgDir/old_a1.webp", 'old');
    $u = $mkUser('pf_test_a1', 'old_a1.webp');
    [, $d] = profile_validate(['firstname' => 'ใหม่', 'lastname' => 'ส', 'username' => 'pf_test_a1', 'email' => ''], $u);
    $name = profile_apply($pdo, (int) $u['id'], $u, $d, ['tmp_name' => $png], $imgDir, 'copy');
    $row = $pdo->query('SELECT firstname, profile_image, email, password FROM users WHERE id = ' . (int) $u['id'])->fetch();
    return $row['profile_image'] === $name && preg_match('/^user_\d+_\d+_[0-9a-f]{6}\.webp$/', $name) && is_file("$imgDir/$name")
        && !is_file("$imgDir/old_a1.webp") && $row['firstname'] === 'ใหม่' && $row['email'] === null && $row['password'] === 'old1';
}));
ck('A2 บันทึกไม่สำเร็จ (username ซ้ำ): ลบรูปใหม่ทิ้ง รูปเก่าอยู่ครบ ข้อมูลไม่เปลี่ยน', in_tx($pdo, function () use ($pdo, $mkUser, $imgDir, $png) {
    file_put_contents("$imgDir/old_a2.webp", 'old');
    $other = $mkUser('pf_test_other');
    $u = $mkUser('pf_test_a2', 'old_a2.webp');
    $filesBefore = scandir($imgDir);
    [, $d] = profile_validate(['firstname' => 'ใหม่', 'lastname' => 'ส', 'username' => 'pf_test_other', 'email' => ''], $u);
    $dup = profile_duplicates($pdo, (int) $u['id'], $d);
    $msg = '';
    try { profile_apply($pdo, (int) $u['id'], $u, $d, ['tmp_name' => $png], $imgDir, 'copy'); } catch (Exception $e) { $msg = $e->getMessage(); }
    $row = $pdo->query('SELECT firstname, profile_image FROM users WHERE id = ' . (int) $u['id'])->fetch();
    return ($dup['username'] ?? '') === 'ชื่อผู้ใช้งานนี้มีผู้ใช้แล้ว' && $msg === 'ชื่อผู้ใช้งานหรืออีเมลซ้ำกับผู้ใช้อื่น'
        && scandir($imgDir) == $filesBefore && is_file("$imgDir/old_a2.webp") && $row['firstname'] === 'ท' && $row['profile_image'] === 'old_a2.webp';
}));
ck('A3 อีเมลว่างหลายคน → เก็บ NULL ไม่ชนกัน / อีเมลซ้ำผู้ใช้อื่น → แจ้งที่ช่องอีเมล', in_tx($pdo, function () use ($pdo, $mkUser, $imgDir) {
    $ok = true;
    foreach (['pf_test_e1', 'pf_test_e2'] as $un) {
        $u = $mkUser($un);
        [, $d] = profile_validate(['firstname' => 'ท', 'lastname' => 'ส', 'username' => $un, 'email' => ''], $u);
        try { profile_apply($pdo, (int) $u['id'], $u, $d, null, $imgDir, 'copy'); } catch (Exception $e) { $ok = false; }
    }
    [, $d] = profile_validate(['firstname' => 'ท', 'lastname' => 'ส', 'username' => 'pf_test_e2', 'email' => 'pf_test_other@test.local'], $u);
    $mkUser('pf_test_other');
    return $ok && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username IN ('pf_test_e1','pf_test_e2') AND email IS NULL")->fetchColumn() === 2
        && (profile_duplicates($pdo, (int) $u['id'], $d)['email'] ?? '') === 'อีเมลนี้มีผู้ใช้แล้ว';
}));
ck('A4 เปลี่ยนรหัสผ่าน → บันทึกรหัสใหม่เป็น hash / ไม่เปลี่ยน → รหัสเดิมคงอยู่ · ไม่เปลี่ยนรูป → รูปเดิมคงอยู่', in_tx($pdo, function () use ($pdo, $mkUser, $imgDir) {
    file_put_contents("$imgDir/keep_a4.webp", 'keep');
    $u = $mkUser('pf_test_a4', 'keep_a4.webp');
    [, $d] = profile_validate(['firstname' => 'ท', 'lastname' => 'ส', 'username' => 'pf_test_a4', 'old_password' => 'old1', 'password' => 'newpass22', 'password_confirm' => 'newpass22'], $u);
    $img = profile_apply($pdo, (int) $u['id'], $u, $d, null, $imgDir, 'copy');
    $p1 = $pdo->query('SELECT password FROM users WHERE id = ' . (int) $u['id'])->fetchColumn();
    [, $d] = profile_validate(['firstname' => 'ท', 'lastname' => 'ส', 'username' => 'pf_test_a4'], $u);
    profile_apply($pdo, (int) $u['id'], $u, $d, null, $imgDir, 'copy');
    $p2 = $pdo->query('SELECT password FROM users WHERE id = ' . (int) $u['id'])->fetchColumn();
    return $p1 !== 'newpass22' && password_verify('newpass22', (string) $p1) && $p2 === $p1 && $img === 'keep_a4.webp' && is_file("$imgDir/keep_a4.webp");
}));
ck('A5 ลบไฟล์เฉพาะชื่อไฟล์ล้วน (กันชื่อที่ออกนอกโฟลเดอร์)', (function () use ($tmp, $imgDir) {
    file_put_contents("$tmp/outside.webp", 'x');
    profile_unlink($imgDir, '../outside.webp');
    return is_file("$tmp/outside.webp");
})());
ck('A6 rollback ครบ: ตาราง users เท่าเดิม', $usersSnap() === $before);

// ── R หน้าเว็บ (render ผ่าน CLI) ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/profile_page_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
[$_, $zone, $role, $uid, $b64, $method] = $argv + [6 => ""]; parse_str(base64_decode($b64), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/$zone/profile.php"; $_SERVER["REQUEST_URI"] = "/$zone/profile.php";
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($method === "post") { $_POST = $q; $_GET = []; } else { $_GET = $q; }
session_start();
$_SESSION = ["user_id"=>(int)$uid,"role"=>$role,"affiliation_id"=>1,"affiliation_name"=>"ท","firstname"=>"ท","lastname"=>"ส","username"=>"t","last_activity"=>time()];
// CSRF: session มี token และ POST ส่ง token ให้อัตโนมัติ (เทสต์ที่ส่ง csrf_token มาเองไม่ถูกทับ)
$_SESSION["csrf_token"] = "probe-token"; if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST += ["csrf_token" => "probe-token"];
ob_start(); require "$root/$zone/profile.php"; echo ob_get_clean();
');
$render = fn(string $zone, string $role, int $uid, string $query = '', string $method = 'get') => (string) shell_exec(sprintf('%s %s %s %s %d %s %s 2>&1',
    escapeshellarg($php), escapeshellarg($probe), $zone, $role, $uid, escapeshellarg(base64_encode($query)), $method));
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $h);
$u5 = $pdo->query('SELECT * FROM users WHERE id = 5')->fetch();

$hO = $render('officer', 'officer', 5);
ck('R1 officer: เรนเดอร์ได้ + โหลด profile.css / officer-entry.css / profile.js / data-entry.js + ค่าเดิมในช่อง + ส่วนรหัสผ่านพับไว้', $noErr($hO)
    && strpos($hO, 'assets/css/profile.css') !== false && strpos($hO, 'assets/js/profile.js') !== false && strpos($hO, 'assets/js/data-entry.js') !== false
    && strpos($hO, 'name="username" value="' . htmlspecialchars($u5['username'], ENT_QUOTES) . '"') !== false
    && strpos($hO, '<span class="pf-role">เจ้าหน้าที่</span>') !== false && strpos($hO, 'data-open="0"') !== false && strpos($hO, 'name="password_confirm"') !== false,
    substr(preg_replace('/\s+/', ' ', $hO), 0, 400));
$hD = $render('dean', 'dean', 23);
$hA = $render('admin', 'admin', 1);
ck('R2 dean มีหน้าโปรไฟล์ (ไม่มีแถบหัว) + เมนูมีลิงก์ / admin เรนเดอร์ได้', $noErr($hD) && $noErr($hA)
    && strpos($hD, '<span class="pf-role">บุคลากร/คณบดี</span>') !== false && substr_count($hD, 'dean/profile.php') >= 2
    && strpos($hA, '<span class="pf-role">ผู้ดูแลระบบ</span>') !== false, substr(preg_replace('/\s+/', ' ', $hD), 0, 300));
$h403 = $render('admin', 'officer', 5);
ck('R3 admin/profile.php เปิดด้วย officer → 403 (เดิมเปิดได้ทุก role)', strpos($h403, 'id="pfForm"') === false && stripos($h403, '403') !== false);

// POST ที่ต้องไม่แก้ข้อมูล: รหัสเดิมผิด / ช่องว่าง
$hBad = $render('officer', 'officer', 5, http_build_query(['firstname' => 'เปลี่ยนชื่อทดสอบ', 'lastname' => $u5['lastname'], 'username' => $u5['username'], 'email' => $u5['email'],
    'old_password' => 'ผิดแน่นอน', 'password' => 'x1', 'password_confirm' => 'x1']), 'post');
ck('R4 POST รหัสเดิมผิด: ไม่บันทึก, แสดงข้อความที่ช่อง, กางส่วนรหัสผ่าน, คงชื่อที่พิมพ์ไว้', $noErr($hBad) && $usersSnap() === $before
    && strpos($hBad, 'data-server="1"><label class="pf-label" for="pf_old_password">') !== false && strpos($hBad, 'รหัสผ่านเดิมไม่ถูกต้อง') !== false
    && strpos($hBad, 'data-open="1"') !== false && strpos($hBad, 'value="เปลี่ยนชื่อทดสอบ"') !== false);
$hEmpty = $render('officer', 'officer', 5, http_build_query(['firstname' => '', 'lastname' => 'x', 'username' => 'มี ช่องว่าง', 'email' => 'bad']), 'post');
ck('R5 POST ข้อมูลไม่ครบ/ผิดรูปแบบ: ไม่บันทึก แจ้ง 3 ช่อง', $usersSnap() === $before && substr_count($hEmpty, 'data-server="1"') === 3);

$css = file_get_contents(__DIR__ . '/../assets/css/profile.css');
ck('C1 CSS ใช้คลาส pf- (ไม่ชน .form-grid / .btn-upload ของหน้าอื่นตอนสลับหน้า) + แอนิเมชัน + ปิดเมื่อตั้งลดการเคลื่อนไหว',
    !preg_match('/^\.(form-grid|btn-upload|form-control|alert-message)\b/m', $css) && strpos($css, '@keyframes pfPop') !== false
    && strpos($css, '.pf-pw.is-open .pf-pw-body { display: block; animation: oeOpen') !== false && strpos($css, '@media (prefers-reduced-motion: reduce)') !== false);

// เก็บกวาด
foreach (glob("$imgDir/*") as $f) @unlink($f);
@rmdir($imgDir);
foreach (glob("$tmp/*") as $f) @unlink($f);
@rmdir($tmp);
ck('Z ไฟล์ในโฟลเดอร์รูปโปรไฟล์จริงไม่เปลี่ยน + ตาราง users เท่าเดิม', scandir($profilesDir) == $filesBefore && $usersSnap() === $before);

@unlink($probe);   // ไฟล์ probe ชั่วคราว
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
