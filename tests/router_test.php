<?php
/**
 * Unit Test — ทางเข้าเว็บ (router.php)
 * รัน: C:\xampp\php\php.exe tests\router_test.php
 *
 * ล็อกกฎ:
 *   - ยังไม่ login → ไปหน้า login.php ตรง ๆ (ถอดหน้า landing 3D ออกจากทางเข้า — ไฟล์ landing.php ยังเก็บไว้)
 *   - login แล้ว → ไปโซนตาม role (admin/ · dean/ · officer/)
 * รันผ่าน php-cgi เพื่ออ่าน header Location จริง
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);
$cgi  = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

$tmp = sys_get_temp_dir() . '/router_test_' . getmypid();
@mkdir($tmp);
$pre = "$tmp/prepend.php";
/** เรียก router.php ผ่าน php-cgi · $session = null → ไม่มี session */
$location = function (?array $session) use ($root, $cgi, $pre, $tmp): string {
    file_put_contents($pre, '<?php session_start(); $_SESSION = ' . var_export($session ?? [], true) . ';');
    $env = ['REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => "$root/router.php",
        'SCRIPT_NAME' => '/router.php', 'REQUEST_URI' => '/router.php', 'SystemRoot' => getenv('SystemRoot'), 'TEMP' => sys_get_temp_dir()];
    $p = proc_open([$cgi, '-d', 'auto_prepend_file=' . $pre, '-d', 'session.save_path=' . $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return preg_match('/^Location:\s*(\S+)/mi', (string) $out, $m) ? $m[1] : '(no location) ' . substr((string) $out, 0, 120);
};

$guest = $location(null);
ck('R1 ยังไม่ login → login.php (ไม่ผ่านหน้า landing)', $guest === 'login.php', $guest);
$roles = ['admin' => 'admin/', 'dean' => 'dean/', 'officer' => 'officer/'];
$got = [];
foreach ($roles as $r => $_) $got[$r] = $location(['user_id' => 1, 'role' => $r]);
ck('R2 login แล้ว → โซนตาม role', $got === $roles, json_encode($got));
ck('R3 ไฟล์ landing.php ยังอยู่ (ถอดออกจากทางเข้าเท่านั้น ไม่ลบ)', is_file("$root/landing.php"));

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
