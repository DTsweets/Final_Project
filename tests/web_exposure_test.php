<?php
/**
 * Unit Test — ไฟล์ที่ไม่ควรเปิดผ่านเว็บ (includes/blocked_paths.php + server.php + .htaccess + ตัวกัน CLI)
 * รัน: C:\xampp\php\php.exe tests\web_exposure_test.php
 *
 * ล็อกกฎ:
 *   - ไฟล์สำรอง/ดัมป์ (.sql), .env, .git, เอกสาร .md, โฟลเดอร์ database/ tests/ config/ เปิดจาก URL ไม่ได้
 *   - สคริปต์ที่แก้ข้อมูล (init.php, tests/*.php) รันได้จาก command line เท่านั้น เปิดผ่านเว็บ → 403 ไม่ทำงาน
 *   - หน้าเว็บปกติ (login.php, assets, โมเดล 3 มิติ) ยังเปิดได้
 * S2 เปิดเซิร์ฟเวอร์ทดสอบที่พอร์ตว่างแยกจากของจริง แล้วปิดเมื่อจบ
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}
$hasLib = is_file("$root/includes/blocked_paths.php");
if ($hasLib) require_once "$root/includes/blocked_paths.php";

// ── S1 กฎกันเส้นทาง ──
$blocked = ['/database/backup_20260918_before_password_hash.sql', '/database/upnetzero.sql', '/database/', '/DATABASE/backup.sql',
    '/database./backup.sql', '/tests/password_test.php', '/tests/', '/config/db.php', '/.env', '/.git/config', '/.gitignore',
    '/CLAUDE.md', '/README.md', '/assets/x.sql', '/database/backup.sql::$DATA', '/a/../database/backup.sql', '/%2Eenv', '/.claude/launch.json'];
$allowed = ['/', '/login.php', '/index.php', '/router.php', '/admin/index.php', '/officer/api/manage_evidence.php?action=list',
    '/assets/css/login.css', '/assets/images/up-logo.webp', '/assets/images/evidence/docs/doc_event_1.pdf', '/model/models/Room_Portfolio.glb', '/favicon.ico'];
$badBlocked = $hasLib ? array_values(array_filter($blocked, fn($u) => !is_blocked_path($u))) : $blocked;
$badAllowed = $hasLib ? array_values(array_filter($allowed, fn($u) => is_blocked_path($u))) : $allowed;
ck('S1 is_blocked_path: ปิด .sql / .env / .git / .md / database, tests, config (รวมตัวพิมพ์ใหญ่, จุดต่อท้าย, ..) · ไม่ปิดหน้าเว็บกับ assets',
    $hasLib && !$badBlocked && !$badAllowed, 'ควรปิดแต่ไม่ปิด: ' . implode(' ', $badBlocked) . ' | ควรผ่านแต่ปิด: ' . implode(' ', $badAllowed));

// ── S2 เซิร์ฟเวอร์จริง (php -S ... server.php) ──
$port = 8099;
$srv = is_file("$root/server.php")
    ? proc_open([PHP_BINARY, '-S', "localhost:$port", 'server.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root)
    : null;
$get = function (string $path) use ($port): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $body = @file_get_contents("http://localhost:$port$path", false, $ctx);
    preg_match('~\s(\d{3})\s~', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), (string) $body];
};
if ($srv) {
    for ($i = 0; $i < 50 && $get('/login.php')[0] === 0; $i++) usleep(100000);
    $codes = [];
    foreach (['/database/backup_20260918_before_password_hash.sql', '/database/upnetzero.sql', '/.env', '/tests/password_test.php',
        '/CLAUDE.md', '/login.php', '/assets/css/login.css'] as $p) $codes[$p] = $get($p)[0];
    $leak = $get('/database/backup_20260918_before_password_hash.sql')[1];
    ck('S2 เปิดผ่านเซิร์ฟเวอร์จริง: ไฟล์สำรอง/.env/tests/.md → 403 และไม่มีเนื้อไฟล์หลุด · login.php กับ css ยังได้ 200',
        $codes['/database/backup_20260918_before_password_hash.sql'] === 403 && $codes['/database/upnetzero.sql'] === 403
        && $codes['/.env'] === 403 && $codes['/tests/password_test.php'] === 403 && $codes['/CLAUDE.md'] === 403
        && $codes['/login.php'] === 200 && $codes['/assets/css/login.css'] === 200
        && !str_contains($leak, 'INSERT INTO') && !str_contains($leak, 'DB_PASS'),
        json_encode($codes));
    foreach ($pipes as $p) fclose($p);
    proc_terminate($srv); proc_close($srv);
} else {
    ck('S2 เปิดผ่านเซิร์ฟเวอร์จริง: ไฟล์สำรอง/.env/tests/.md → 403 และไม่มีเนื้อไฟล์หลุด · login.php กับ css ยังได้ 200', false, 'ไม่มีไฟล์ server.php');
}

// ── S3 สคริปต์ที่แก้ข้อมูลต้องรันจาก command line เท่านั้น ──
$cgi   = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
$files = array_merge(glob("$root/tests/*.php") ?: [], ["$root/init.php"]);
$noGuard = $ran = [];
foreach ($files as $file) {
    $head = (string) file_get_contents($file, false, null, 0, 3000);
    // ต้องมีตัวกันก่อนคำสั่งแรกที่ทำงานจริง (require / เชื่อมฐานข้อมูล)
    $guardAt = strpos($head, "PHP_SAPI !== 'cli'");
    $firstReq = strpos($head, 'require');
    if ($guardAt === false || ($firstReq !== false && $firstReq < $guardAt)) { $noGuard[] = basename($file); continue; }
    $env = ['REDIRECT_STATUS' => '200', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $file,
        'SCRIPT_NAME' => '/' . basename($file), 'SystemRoot' => getenv('SystemRoot'), 'TEMP' => sys_get_temp_dir()];
    $p = proc_open([$cgi], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    if (!str_contains((string) $out, '403') || !str_contains((string) $out, 'CLI only')) $ran[] = basename($file) . ': ' . substr(strip_tags((string) $out), 0, 60);
}
ck('S3 init.php และ tests/*.php ทุกไฟล์: มีตัวกันก่อน require และเปิดผ่านเว็บได้ 403 (ไม่รันจริง)',
    !$noGuard && !$ran, 'ไม่มีตัวกัน: ' . implode(' ', $noGuard) . ' | ยังทำงาน: ' . implode(' | ', $ran));

// ── S4 .htaccess (ฝั่ง Apache) ──
$ht = fn(string $dir) => is_file("$root/$dir/.htaccess") ? (string) file_get_contents("$root/$dir/.htaccess") : '';
$rootHt = (string) @file_get_contents("$root/.htaccess");
ck('S4 .htaccess: โฟลเดอร์ database, tests, config ปิดทั้งหมด · ไฟล์ .sql/.md ปิดที่ root · .git เข้าไม่ได้',
    str_contains($ht('database'), 'Require all denied') && str_contains($ht('tests'), 'Require all denied') && str_contains($ht('config'), 'Require all denied')
    && (bool) preg_match('/FilesMatch\s+"[^"]*\bsql\b[^"]*"/i', $rootHt) && (bool) preg_match('/FilesMatch\s+"[^"]*\bmd\b[^"]*"/i', $rootHt)
    && (bool) preg_match('/\.git/i', $rootHt));

// ── S5 คำสั่งรันในเอกสารและ launch.json ใช้ server.php ──
$md = (string) @file_get_contents("$root/CLAUDE.md") . (string) @file_get_contents("$root/README.md");
$launch = (string) @file_get_contents("$root/.claude/launch.json");
ck('S5 คำสั่งรันเว็บใช้ server.php ทั้งใน CLAUDE.md / README.md และ .claude/launch.json',
    !preg_match('/-S\s+(localhost|0\.0\.0\.0):8000\s*$/m', $md) && substr_count($md, 'server.php') >= 2
    && str_contains($launch, 'server.php'));

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
