<?php
/**
 * Unit Test — หน้าตั้งค่าผู้ใช้งาน admin/settings.php + includes/admin_users_entry.php + admin/export_users.php
 * รัน: C:\xampp\php\php.exe tests\admin_users_test.php
 *
 * การทดสอบที่แก้ข้อมูลทำใน transaction แล้ว rollback · ไฟล์รูปทดสอบอยู่ในโฟลเดอร์ชั่วคราว
 * ล็อกกฎ:
 *   - ไม่ส่งรหัสผ่านไปที่หน้าเว็บ (ตาราง / data-* / ค่าในฟอร์ม)
 *   - อีเมลว่าง = NULL, ต้องเลือกสิทธิ์และหน่วยงานที่มีจริง, รหัสผ่านยืนยันต้องตรง, แก้ไขโดยเว้นรหัสว่าง = ใช้รหัสเดิม
 *   - ห้ามลดสิทธิ์/ลบบัญชีตัวเอง และต้องเหลือผู้ดูแลระบบอย่างน้อย 1 คน
 *   - ลบผู้ใช้แล้วลบรูปโปรไฟล์ด้วย · ส่งไม่ผ่าน → เปิดหน้าต่างเดิมพร้อมค่าที่กรอก (ไม่มีรหัสผ่าน) และไม่แตะฐานข้อมูล
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_users_entry.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('SET SESSION group_concat_max_len = 100000000');
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
function in_tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try { return $fn(); } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
}
$fingerprint = fn() => $pdo->query("SELECT
    (SELECT MD5(GROUP_CONCAT(CONCAT_WS(':', id, username, password, firstname, lastname, IFNULL(email,'-'), role, Affiliation, IFNULL(profile_image,'-')) ORDER BY id)) FROM users),
    (SELECT MD5(GROUP_CONCAT(CONCAT(id, ':', affiliation_item) ORDER BY id)) FROM affiliation_id)")->fetch(PDO::FETCH_NUM);
$profilesDir = __DIR__ . '/../assets/images/profiles';
$profilesBefore = scandir($profilesDir);
$before = $fingerprint();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$affil = (int) $pdo->query('SELECT id FROM affiliation_id ORDER BY id LIMIT 1')->fetchColumn();
$ok = ['firstname' => 'ทดสอบ', 'lastname' => 'ระบบ', 'username' => 'au_test_user', 'email' => '', 'role' => 'officer',
       'affiliation' => (string) $affil, 'password' => 'secret12', 'password_confirm' => 'secret12'];

// ── ตรวจข้อมูล ──
[$e1] = admin_user_validate($pdo, ['firstname' => ' ', 'lastname' => '', 'username' => 'a b', 'email' => 'bad', 'role' => 'root', 'affiliation' => '', 'password' => '', 'password_confirm' => 'x'], true);
ck('V1 เพิ่มใหม่: ชื่อ/นามสกุลว่าง, username มีช่องว่าง, อีเมลผิด, สิทธิ์ไม่มีจริง, ไม่เลือกหน่วยงาน, ไม่มีรหัส, ยืนยันไม่ตรง',
    array_keys($e1) == ['firstname', 'lastname', 'username', 'email', 'role', 'affiliation', 'password', 'password_confirm'], json_encode($e1, JSON_UNESCAPED_UNICODE));
[$e2, $d2] = admin_user_validate($pdo, ['password' => '', 'password_confirm' => ''] + $ok, false);
[$e3] = admin_user_validate($pdo, ['password' => str_repeat('a', 51), 'password_confirm' => str_repeat('a', 51), 'affiliation' => '999999'] + $ok, true);
ck('V2 แก้ไขเว้นรหัสว่าง → ผ่าน (password = null), อีเมลว่าง = NULL · รหัสเกิน 50 / หน่วยงานไม่มีจริง → ข้อความ',
    $e2 === [] && $d2['password'] === null && $d2['email'] === null && $d2['affiliation'] === $affil
    && isset($e3['password'], $e3['affiliation']));
[$e6] = admin_user_validate($pdo, ['password' => 'ab12cd7', 'password_confirm' => 'ab12cd7'] + $ok, true);
[$e7] = admin_user_validate($pdo, ['password' => 'ab12cd78', 'password_confirm' => 'ab12cd78'] + $ok, true);
ck('V2b รหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร: 7 ตัว → ข้อความ · 8 ตัวพอดี → ผ่าน', ($e6['password'] ?? '') === 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร' && $e7 === [], json_encode([$e6, $e7], JSON_UNESCAPED_UNICODE));
[$e4] = admin_user_validate($pdo, ['affiliation' => '__new__', 'new_affiliation' => '  '] + $ok, true);
[$e5, $d5] = admin_user_validate($pdo, ['affiliation' => '__new__', 'new_affiliation' => ' หน่วยงานทดสอบ '] + $ok, true);
ck('V3 หน่วยงานใหม่: ชื่อว่าง → ข้อความ, มีชื่อ → เก็บชื่อไว้สร้างตอนบันทึก',
    ($e4['affiliation'] ?? '') === 'กรุณาพิมพ์ชื่อหน่วยงานใหม่' && $e5 === [] && $d5['new_affiliation'] === 'หน่วยงานทดสอบ' && $d5['affiliation'] === null);

// ── กฎความปลอดภัย ──
ck('G1 ห้ามลดสิทธิ์ตัวเอง / ผู้ดูแลคนสุดท้าย · ผู้ใช้อื่นหรือยังเป็น admin → ผ่าน', in_tx($pdo, function () use ($pdo, $adminId, $ok) {
    $pdo->exec("UPDATE users SET role = 'officer' WHERE role = 'admin' AND id <> $adminId");   // เหลือ admin คนเดียว
    $selfBlock = admin_user_role_guard($pdo, $adminId, 'officer', $adminId) === 'ไม่สามารถเปลี่ยนสิทธิ์ของบัญชีตัวเองได้';
    $lastBlock = admin_user_role_guard($pdo, $adminId, 'dean', 0) === 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน';
    $stayAdmin = admin_user_role_guard($pdo, $adminId, 'admin', $adminId) === null;
    [, $d] = admin_user_validate($pdo, ['role' => 'admin', 'username' => 'au_admin2'] + $ok, true);
    $id2 = admin_user_save($pdo, null, $d);
    $canNow = admin_user_role_guard($pdo, $adminId, 'officer', 0) === null && admin_user_role_guard($pdo, $id2, 'officer', $adminId) === null;
    return $selfBlock && $lastBlock && $stayAdmin && $canNow;
}));
ck('G2 ลบ: บัญชีตัวเอง / ผู้ดูแลคนสุดท้าย / ไม่มีจริง → ข้อความ', in_tx($pdo, function () use ($pdo, $adminId) {
    $pdo->exec("UPDATE users SET role = 'officer' WHERE role = 'admin' AND id <> $adminId");
    $other = (int) $pdo->query("SELECT id FROM users WHERE id <> $adminId LIMIT 1")->fetchColumn();
    return admin_user_delete_check($pdo, $adminId, $adminId) === 'ไม่สามารถลบบัญชีตัวเองได้'
        && admin_user_delete_check($pdo, $adminId, $other) === 'ต้องมีผู้ดูแลระบบอย่างน้อย 1 คน'
        && admin_user_delete_check($pdo, 999999, $adminId) === 'ไม่พบผู้ใช้งาน'
        && admin_user_delete_check($pdo, $other, $adminId) === null;
}));

// ── บันทึก ──
ck('S1 เพิ่มผู้ใช้: อีเมลว่างเก็บเป็น NULL คนที่สองที่ไม่มีอีเมลก็เพิ่มได้ (เดิมชน unique) · username ซ้ำ → ข้อความ', in_tx($pdo, function () use ($pdo, $ok) {
    [, $d] = admin_user_validate($pdo, $ok, true);
    $id = admin_user_save($pdo, null, $d);
    [, $d2] = admin_user_validate($pdo, ['username' => 'au_test_user2'] + $ok, true);
    $id2 = admin_user_save($pdo, null, $d2);
    $row = $pdo->query("SELECT email, role, Affiliation, password FROM users WHERE id = $id")->fetch();
    $dupMsg = profile_duplicates($pdo, 0, $d)['username'] ?? '';
    $dupThrow = false;
    try { admin_user_save($pdo, null, $d); } catch (Exception $e) { $dupThrow = $e->getMessage() === 'ชื่อผู้ใช้งานหรืออีเมลซ้ำกับผู้ใช้อื่น'; }
    return $id2 > $id && $row['email'] === null && $row['role'] === 'officer' && (int) $row['Affiliation'] === (int) $ok['affiliation']
        && $row['password'] !== 'secret12' && password_verify('secret12', $row['password']) && $dupMsg === 'ชื่อผู้ใช้งานนี้มีผู้ใช้แล้ว' && $dupThrow;
}));
ck('S2 แก้ไข: เว้นรหัสว่างรหัสเดิมไม่เปลี่ยน · กรอกรหัสใหม่ → เปลี่ยน (เก็บเป็น hash)', in_tx($pdo, function () use ($pdo, $ok) {
    [, $d] = admin_user_validate($pdo, $ok, true);
    $id = admin_user_save($pdo, null, $d);
    [, $e] = admin_user_validate($pdo, ['firstname' => 'แก้แล้ว', 'role' => 'dean', 'password' => '', 'password_confirm' => ''] + $ok, false);
    admin_user_save($pdo, $id, $e);
    $keep = $pdo->query("SELECT firstname, role, password FROM users WHERE id = $id")->fetch();
    $stored = $pdo->query("SELECT password FROM users WHERE id = $id")->fetchColumn();
    [, $e2] = admin_user_validate($pdo, ['password' => 'newpass88', 'password_confirm' => 'newpass88'] + $ok, false);
    admin_user_save($pdo, $id, $e2);
    $new = (string) $pdo->query("SELECT password FROM users WHERE id = $id")->fetchColumn();
    return $keep['firstname'] === 'แก้แล้ว' && $keep['role'] === 'dean' && $keep['password'] === $stored && password_verify('secret12', $keep['password'])
        && $new !== 'newpass88' && password_verify('newpass88', $new);
}));
ck('S3 หน่วยงานใหม่: สร้างครั้งแรก, ชื่อซ้ำใช้ id เดิม', in_tx($pdo, function () use ($pdo, $ok) {
    [, $d] = admin_user_validate($pdo, ['affiliation' => '__new__', 'new_affiliation' => 'หน่วยงานทดสอบ AU'] + $ok, true);
    $id = admin_user_save($pdo, null, $d);
    [, $d2] = admin_user_validate($pdo, ['username' => 'au_test_user3', 'affiliation' => '__new__', 'new_affiliation' => 'หน่วยงานทดสอบ AU'] + $ok, true);
    $id2 = admin_user_save($pdo, null, $d2);
    $a1 = (int) $pdo->query("SELECT Affiliation FROM users WHERE id = $id")->fetchColumn();
    $a2 = (int) $pdo->query("SELECT Affiliation FROM users WHERE id = $id2")->fetchColumn();
    return $a1 > 0 && $a1 === $a2 && (int) $pdo->query("SELECT COUNT(*) FROM affiliation_id WHERE affiliation_item = 'หน่วยงานทดสอบ AU'")->fetchColumn() === 1;
}));
$tmpDir = sys_get_temp_dir() . '/au_profiles_' . getmypid();
@mkdir($tmpDir);
ck('X1 ลบผู้ใช้: แถวหาย + ลบรูปทั้งชื่อที่เก็บและชื่อ .webp (ไฟล์อื่นไม่แตะ)', in_tx($pdo, function () use ($pdo, $ok, $tmpDir) {
    [, $d] = admin_user_validate($pdo, $ok, true);
    $id = admin_user_save($pdo, null, $d);
    $pdo->exec("UPDATE users SET profile_image = 'au_$id.jpg' WHERE id = $id");
    foreach (["au_$id.jpg", "au_$id.webp", 'keep.webp'] as $f) file_put_contents("$tmpDir/$f", 'x');
    $n = admin_user_delete($pdo, $id, $tmpDir);
    return $n === 1 && !admin_user_find($pdo, $id) && !is_file("$tmpDir/au_$id.jpg") && !is_file("$tmpDir/au_$id.webp") && is_file("$tmpDir/keep.webp")
        && admin_user_delete($pdo, 999999, $tmpDir) === 0;
}));
array_map('unlink', glob("$tmpDir/*")); @rmdir($tmpDir);

$list = admin_user_list($pdo);
$counts = admin_role_counts($list);
ck('L1 รายชื่อ: ครบทุกคน ไม่มีคีย์รหัสผ่าน, นับตามสิทธิ์ตรง, จำนวนงานที่สร้างตรง',
    count($list) === (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() && !array_key_exists('password', $list[0])
    && $counts['admin'] === (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn()
    && array_sum(array_column($list, 'works')) === (int) $pdo->query('SELECT (SELECT COUNT(*) FROM event WHERE created_by IS NOT NULL AND created_by IN (SELECT id FROM users)) + (SELECT COUNT(*) FROM questionnaire WHERE created_by IS NOT NULL AND created_by IN (SELECT id FROM users))')->fetchColumn());
ck('L2 ข้อความยืนยันลบบอกชื่อ/username และผลต่องานที่สร้าง',
    str_contains(admin_user_delete_text(['firstname' => 'ก', 'lastname' => 'ข', 'username' => 'u', 'works' => 3]), 'กิจกรรม/แบบสอบถาม 3 รายการ')
    && str_contains(admin_user_delete_text(['firstname' => 'ก', 'lastname' => 'ข', 'username' => 'u', 'works' => 0]), 'ยังไม่เคยสร้าง'));

// ── หน้าเว็บ (render ผ่าน CLI) ──
$php   = PHP_BINARY;
$probe = sys_get_temp_dir() . '/admin_users_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export(dirname(__DIR__), true) . ';
[$_, $page, $qs, $method, $uid] = $argv; parse_str(base64_decode($qs), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/admin/$page"; $_SERVER["REQUEST_URI"] = "/admin/$page";
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($method === "post") $_POST = $q; else $_GET = $q;
session_start();
$_SESSION = ["user_id"=>(int)$uid,"role"=>"admin","affiliation_id"=>1,"affiliation_name"=>"ศ","firstname"=>"ผู้","lastname"=>"ดูแล","username"=>"admin","last_activity"=>time()];
// CSRF: session มี token และ POST ส่ง token ให้อัตโนมัติ (เทสต์ที่ส่ง csrf_token มาเองไม่ถูกทับ)
$_SESSION["csrf_token"] = "probe-token"; if ($_SERVER["REQUEST_METHOD"] === "POST") $_POST += ["csrf_token" => "probe-token"];
ob_start(); require "$root/admin/$page"; echo ob_get_clean();
');
$render = fn(string $page, string $query = '', string $method = 'get') => (string) shell_exec(sprintf('%s %s %s %s %s %d 2>&1',
    escapeshellarg($php), escapeshellarg($probe), escapeshellarg($page), escapeshellarg(base64_encode($query)), $method, $adminId));
$noErr = fn(string $h) => $h !== '' && !preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $h);

$h1 = $render('settings.php', 'msg=' . urlencode('ทดสอบข้อความ') . '&hl=' . $adminId);
$passwords = $pdo->query('SELECT password FROM users WHERE CHAR_LENGTH(password) >= 6')->fetchAll(PDO::FETCH_COLUMN);
ck('P1 ไม่มีรหัสผ่านในหน้าเว็บ: ไม่มีคอลัมน์/data-password และไม่พบรหัสผ่านจริงของผู้ใช้คนใดใน HTML', $noErr($h1)
    && !str_contains($h1, 'data-password') && !str_contains($h1, '<th>Password</th>')
    && !array_filter($passwords, fn($p) => str_contains($h1, $p)), substr($h1, 0, 300));
ck('P1b ตรวจรหัสผ่านสั้นกว่า 8 ตัวในหน้าต่างก่อนส่ง (ข้อความเดียวกับฝั่งเซิร์ฟเวอร์)', str_contains($h1, "e.password = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'"));
$rows = preg_match_all('#<tr class="au-row[^"]*" data-id="(\d+)" data-role="(\w+)"#', $h1, $m);
ck('P2 ตารางครบทุกคน สิทธิ์ตรง, การ์ดสรุปนับตรง, toast + แถวที่เพิ่งบันทึกถูกไฮไลต์', $rows === count($list)
    && str_contains($h1, 'data-au-count="officer">' . $counts['officer'] . ' <small>')
    && str_contains($h1, 'ทดสอบข้อความ') && preg_match('#<tr class="au-row is-hl" data-id="' . $adminId . '"#', $h1));
ck('P3 บัญชีตัวเอง: ป้าย "คุณ" + ปุ่มลบปิด + ล็อกสิทธิ์ · คนอื่นลบผ่าน confirmDelete พร้อมข้อความผลกระทบ', (function () use ($h1, $adminId, $list) {
    if (!preg_match('#data-id="' . $adminId . '".*?</tr>#s', $h1, $row)) return false;
    $other = current(array_filter($list, fn($u) => $u['id'] !== $adminId));
    return str_contains($row[0], 'au-self">คุณ</span>') && str_contains($row[0], 'class="oe-icon-btn oe-icon-del" disabled') && str_contains($row[0], 'data-lock-role="self"')
        && str_contains($h1, 'data-msg="' . htmlspecialchars(admin_user_delete_text($other), ENT_QUOTES) . '" onclick="auDelete(this)"')
        && str_contains($h1, "confirmDelete({ title: 'ลบบัญชีผู้ใช้?'");
})());
ck('P4 ไม่เหลือโค้ดเก่า: onmouseover, alert(, อัปโหลดรูปที่ไม่มีช่อง, custom-select, toast ซ้ำ, CSS inline',
    stripos($h1, 'onmouseover') === false
    && preg_match('#<script>((?:(?!</script>).)*window\.auOpen(?:(?!</script>).)*)</script>#s', $h1, $js) && !str_contains($js[1], 'alert(')
    && !str_contains($h1, 'resizeProfileImage') && !str_contains($h1, 'initCustomSelects') && !str_contains($h1, 'figma-table')
    && substr_count($h1, 'id="toast-notification"') <= 1 && str_contains($h1, 'assets/css/admin-users.css'));

$usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$h2 = $render('settings.php', 'action=add&firstname=' . urlencode('สมชาย') . '&lastname=&username=' . urlencode('a b') . "&email=bad&role=officer&affiliation=$affil&password=topsecret99&password_confirm=topsecret99", 'post');
ck('P5 ส่งไม่ผ่าน: หน้าต่างเปิดค้าง ค่าเดิมยังอยู่ ข้อความรายช่อง ไม่มีรหัสผ่านที่กรอกในหน้า และไม่เพิ่มผู้ใช้', $noErr($h2)
    && str_contains($h2, 'class="modal-overlay open" id="auModal" style="display:flex;"') && str_contains($h2, 'value="สมชาย"')
    && str_contains($h2, 'data-au-err="lastname">กรุณากรอกนามสกุล<') && str_contains($h2, 'data-au-err="username">ชื่อผู้ใช้งานต้องไม่มีช่องว่าง<')
    && !str_contains($h2, 'topsecret99') && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBefore);
$existing = (string) $pdo->query("SELECT username FROM users WHERE id <> $adminId LIMIT 1")->fetchColumn();
$h3 = $render('settings.php', "action=add&firstname=a&lastname=b&username=" . urlencode($existing) . "&role=officer&affiliation=$affil&password=longenough1&password_confirm=longenough1", 'post');
$h4 = $render('settings.php', "action=edit&user_id=$adminId&firstname=a&lastname=b&username=admin&role=officer&affiliation=$affil", 'post');
$h5 = $render('settings.php', "action=delete&user_id=$adminId", 'post');
ck('P6 username ซ้ำ → ข้อความที่ช่อง · ลดสิทธิ์ตัวเอง → ข้อความที่ช่องสิทธิ์ · ลบตัวเอง → ไม่ลบ (ฐานข้อมูลไม่เปลี่ยน)',
    str_contains($h3, 'data-au-err="username">ชื่อผู้ใช้งานนี้มีผู้ใช้แล้ว<') && str_contains($h4, 'data-au-err="role">ไม่สามารถเปลี่ยนสิทธิ์ของบัญชีตัวเองได้<')
    && $noErr($h5 . ' ') && $fingerprint() === $before);

$x1 = $render('export_users.php', 'cols=oops&user_ids=nope', 'post');
$x2 = $render('export_users.php', "cols[]=col_username&cols[]=col_role&user_ids[]=$adminId", 'post');
ck('E1 ส่งออก: cols/user_ids ไม่ใช่ array ไม่พัง (ใช้ค่าเริ่มต้น) · เลือกคอลัมน์/ผู้ใช้ได้ · ไม่มีรหัสผ่านในไฟล์',
    $noErr($x1) && str_contains($x1, '<Workbook') && $noErr($x2) && substr_count($x2, '<Row') === 2 && !str_contains($x2, 'Email')
    && !array_filter($passwords, fn($p) => str_contains($x1, $p)), substr($x1, 0, 200));

ck('F1 ลบหน้าเก่า admin/users.php แล้ว และเมนูไม่อ้างถึง', !is_file(__DIR__ . '/../admin/users.php')
    && !str_contains((string) file_get_contents(__DIR__ . '/../admin/includes/sidebar.php'), 'users.php'));

$css = (string) file_get_contents(__DIR__ . '/../assets/css/admin-users.css');
ck('C1 แอนิเมชัน: แถวลอยขึ้นตอนกรอง, ไฮไลต์แถวที่บันทึก, การ์ดกรองมีเส้นยืด, ปิดเมื่อ prefers-reduced-motion',
    str_contains($css, '.au-in { animation: oeRise') && str_contains($css, '@keyframes auFlash') && str_contains($css, '.au-kpi[aria-pressed="true"]::after { transform: scaleX(1); }')
    && (bool) preg_match('#prefers-reduced-motion: reduce\) \{\s*\.au-in, \.au-row\.is-hl#', $css));

ck('Z ตาราง users / หน่วยงาน และโฟลเดอร์รูปโปรไฟล์เท่าเดิม', $fingerprint() === $before && scandir($profilesDir) === $profilesBefore);

@unlink($probe);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
