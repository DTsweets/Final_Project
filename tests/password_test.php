<?php
/**
 * Unit Test — การเก็บรหัสผ่าน (includes/password.php + login.php + database/migrate_password_hash.php)
 * รัน: C:\xampp\php\php.exe tests\password_test.php
 *
 * ล็อกกฎ:
 *   - users.password เก็บเป็น hash (password_hash) ไม่มีบัญชีไหนเป็น plaintext · คอลัมน์ varchar(255)
 *   - ตรวจรหัสด้วย password_verify · ค่าเก่าที่ยังเป็น plaintext (กู้ฐานจากไฟล์สำรอง) ยัง login ได้ และถูกเข้ารหัสทันทีที่ login
 *   - "จำชื่อผู้ใช้" เก็บแค่ username (cookie httponly) — ไม่เก็บ / ไม่แสดงรหัสผ่านจากคุกกี้ และลบคุกกี้ rm_password เดิม
 * login จริงผ่านหน้าเว็บใช้บัญชีทดสอบชั่วคราว ลบทิ้งใน finally
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
$root = dirname(__DIR__);
$hasLib = is_file("$root/includes/password.php");
if ($hasLib) require_once "$root/includes/password.php";

// ── W1 ฟังก์ชันกลาง ──
ck('W1 user_password_hash / verify / needs_upgrade: hash ≠ รหัสจริง, ตรวจถูก/ผิด, plaintext เก่ายังตรวจได้แต่ต้องอัปเกรด, ค่าว่าง → ไม่ผ่าน', $hasLib && (function () {
    $h = user_password_hash('Secret#123');
    return $h !== 'Secret#123' && password_get_info($h)['algo'] !== null
        && user_password_verify($h, 'Secret#123') && !user_password_verify($h, 'secret#123') && !user_password_verify($h, '')
        && user_password_verify('Legacy#123', 'Legacy#123') && !user_password_verify('Legacy#123', 'legacy#123')
        && !user_password_verify('', '') && !user_password_verify('', 'x')
        && user_password_needs_upgrade('Legacy#123') && !user_password_needs_upgrade($h);
})());

// ── W2 ฐานข้อมูล ──
$col = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password'")->fetchColumn();
$plain = array_filter($pdo->query('SELECT id, password FROM users')->fetchAll(PDO::FETCH_KEY_PAIR), fn($p) => password_get_info((string) $p)['algo'] === null);
ck('W2 users.password เป็น varchar(255) และทุกบัญชีเก็บเป็น hash (ไม่มี plaintext)', $col === 'varchar(255)' && !$plain,
    "column=$col plaintext ids=" . implode(',', array_keys($plain)));

// ── probe: เรนเดอร์ login.php ผ่าน CLI (session / POST / cookie) ──
$probe = sys_get_temp_dir() . '/password_login_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export($root, true) . ';
[$_, $b64, $method, $cookie] = $argv + [2 => "get", 3 => ""];
parse_str(base64_decode($b64), $q); parse_str(base64_decode($cookie), $c);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = $_SERVER["SCRIPT_NAME"] = $_SERVER["REQUEST_URI"] = "/login.php";
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($method === "post") $_POST = $q + ["csrf_token" => "pw-test-token"]; else $_GET = $q;
$_COOKIE = $c;
session_start();
$_SESSION = ["csrf_token" => "pw-test-token"];
register_shutdown_function(function () { echo "\n[[SESSION]]" . base64_encode(json_encode($_SESSION ?? [])); });
chdir($root);
ob_start(); require "$root/login.php"; echo ob_get_clean();
');
$login = function (array $post = [], string $method = 'post', array $cookie = []) use ($probe) {
    $out = (string) shell_exec(sprintf('%s %s %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe),
        escapeshellarg(base64_encode(http_build_query($post))), $method, escapeshellarg(base64_encode(http_build_query($cookie)))));
    [$body, $sess] = explode('[[SESSION]]', $out) + [1 => ''];
    return [$body, json_decode(base64_decode(trim($sess)), true) ?: []];
};

// ── W3 login ผ่านหน้าจริงด้วยบัญชีที่ยังเก็บ plaintext ──
$un = 'pw_test_legacy_' . getmypid();
try {
    $pdo->prepare("INSERT INTO users (username, password, firstname, lastname, role, Affiliation) VALUES (?, 'Legacy#123', 'ท', 'ส', 'officer', 1)")->execute([$un]);
    $uid = (int) $pdo->lastInsertId();
    $stored = fn() => (string) $pdo->query("SELECT password FROM users WHERE id = $uid")->fetchColumn();
    [$b1, $s1] = $login(['username' => $un, 'password' => 'Legacy#123']);
    $h1 = $stored();
    [$b2, $s2] = $login(['username' => $un, 'password' => 'Legacy#123']);
    [$b3, $s3] = $login(['username' => $un, 'password' => 'legacy#123']);
    ck('W3 บัญชี plaintext เดิม login ได้ → รหัสในฐานกลายเป็น hash ทันที · login ซ้ำด้วย hash ได้ · รหัสผิด (ตัวพิมพ์ต่าง) ไม่ได้',
        (int) ($s1['user_id'] ?? 0) === $uid && password_get_info($h1)['algo'] !== null && password_verify('Legacy#123', $h1)
        && (int) ($s2['user_id'] ?? 0) === $uid && $stored() === $h1
        && !isset($s3['user_id']) && str_contains($b3, 'รหัสผ่านไม่ถูกต้อง'),
        'stored=' . substr($h1, 0, 7) . ' s1=' . json_encode($s1) . ' b1=' . substr(strip_tags($b1), 0, 150));
} finally {
    $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$un]);
}

// ── W4 จำชื่อผู้ใช้ (ไม่จำรหัสผ่าน) ──
[$page] = $login([], 'get', ['rm_username' => 'remembered_user', 'rm_password' => 'CookieSecret99']);
$src = (string) file_get_contents("$root/login.php");
ck('W4 หน้า login: รหัสผ่านจากคุกกี้เดิมไม่โผล่ในหน้า · ชื่อผู้ใช้ที่จำไว้ยังเติมให้ · ป้าย "จำชื่อผู้ใช้"',
    !str_contains($page, 'CookieSecret99') && str_contains($page, 'value="remembered_user"') && str_contains($page, 'จำชื่อผู้ใช้') && !str_contains($page, 'จำรหัสผ่าน'),
    substr(strip_tags($page), 0, 200));
ck('W4b โค้ด login: ไม่เขียนรหัสผ่านลงคุกกี้ · ลบคุกกี้ rm_password เดิม · คุกกี้ rm_username เป็น httponly · ตรวจรหัสด้วย user_password_verify',
    !preg_match("/setcookie\('rm_password',\s*\\\$password/", $src) && !preg_match('/=\s*\$_COOKIE\[\'rm_password\'\]/', $src) && !str_contains($src, '$cookie_pass')
    && (bool) preg_match("/setcookie\('rm_password', '', \[/", $src)
    && str_contains($src, "setcookie('rm_username', \$username, \$cookie_opts(")
    && str_contains($src, "\$cookie_opts = fn(int \$expires) => ['expires' => \$expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];")
    && str_contains($src, 'user_password_verify(') && !str_contains($src, "\$user['password'] !== \$password"));

@unlink($probe);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
