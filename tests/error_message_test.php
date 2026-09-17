<?php
/**
 * Unit Test — ข้อความ error ที่ส่งให้ผู้ใช้ (safe_error_message ใน includes/auth.php + ทุกหน้าที่ catch)
 * รัน: C:\xampp\php\php.exe tests\error_message_test.php
 *
 * ล็อกกฎ:
 *   - error ของฐานข้อมูล (PDOException) ไม่โชว์ SQL / ชื่อตาราง / ชื่อคอลัมน์ ให้ผู้ใช้ — โชว์ข้อความกลางแล้วเขียนลง error log
 *   - error ที่เราตั้งข้อความเอง (เช่น "ปริมาณ กรอกไม่ถูกต้อง") ยังแสดงตามเดิม
 * E3 ยิง API จริงให้เกิด error ฐานข้อมูล (created_by ชี้ผู้ใช้ที่ไม่มี) — คำสั่งล้มเหลว ไม่มีข้อมูลถูกเขียน
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/db.php';
$root = dirname(__DIR__);
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pass = $fail = 0;
function ck(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $name\n"; }
    else     { $fail++; echo "  [FAIL] $name" . ($detail ? "\n         $detail" : '') . "\n"; }
}

// ── E1 ฟังก์ชันกลาง ──
$log = sys_get_temp_dir() . '/err_msg_test_' . getmypid() . '.log';
ini_set('error_log', $log);
require_once "$root/includes/auth.php";
$ok = function_exists('safe_error_message');
$pdoMsg = $ownMsg = $logged = '';
if ($ok) {
    try { $pdo->query('SELECT * FROM ตารางที่ไม่มีจริง'); } catch (PDOException $e) { $pdoMsg = safe_error_message($e); }
    $ownMsg = safe_error_message(new Exception('ปริมาณ กรอกไม่ถูกต้อง 1 ช่อง'));
    $logged = (string) @file_get_contents($log);
}
ck('E1 safe_error_message: PDOException → ข้อความกลาง (ไม่มี SQLSTATE / ชื่อตาราง) และเขียนลง error log · Exception ของเราเอง → ข้อความเดิม',
    $ok && $pdoMsg === 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง' && $ownMsg === 'ปริมาณ กรอกไม่ถูกต้อง 1 ช่อง'
    && str_contains($logged, 'SQLSTATE') && str_contains($logged, 'ตารางที่ไม่มีจริง'),
    "pdo=$pdoMsg own=$ownMsg log=" . substr($logged, 0, 120));
@unlink($log);

// ── E2 ไม่มีหน้าไหนส่ง getMessage() ดิบให้ผู้ใช้ ──
$raw = [];
foreach (['admin', 'dean', 'officer', 'includes', 'components'] as $dir)
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir")) as $f)
        if ($f->getExtension() === 'php' && $f->getFilename() !== 'auth.php') {
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match_all('/\$e->getMessage\(\)/', $src, $m)) $raw[] = $f->getFilename() . ' × ' . count($m[0]);
        }
foreach (['login.php', 'logout.php', 'router.php'] as $f)
    if (str_contains((string) @file_get_contents("$root/$f"), '$e->getMessage()')) $raw[] = $f;
ck('E2 ทุกหน้าใช้ safe_error_message แทน $e->getMessage() ดิบ', !$raw, implode(' · ', $raw));

// ── E3 API จริง: error ฐานข้อมูล → ข้อความกลาง ไม่มี SQL หลุด ──
$evId = (int) $pdo->query('SELECT id FROM event ORDER BY id LIMIT 1')->fetchColumn();
$evBefore = (int) $pdo->query("SELECT COUNT(*) FROM evidence WHERE entity_type = 'event' AND entity_id = $evId")->fetchColumn();
$probe = sys_get_temp_dir() . '/err_api_probe_' . getmypid() . '.php';
file_put_contents($probe, '<?php
$root = ' . var_export($root, true) . ';
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["PHP_SELF"] = $_SERVER["SCRIPT_NAME"] = "/officer/api/manage_evidence.php";
$_SERVER["REQUEST_URI"] = "/officer/api/manage_evidence.php?action=add_link";
$_SERVER["HTTP_X_CSRF_TOKEN"] = "err-test-token";
$_GET = ["action" => "add_link"];
$_POST = ["entity_type" => "event", "entity_id" => ' . $evId . ', "url" => "https://example.com/err-test"];
$_REQUEST = array_merge($_GET, $_POST);
session_start();
$_SESSION = ["user_id" => 99999999, "role" => "admin", "affiliation_id" => 1, "username" => "t", "firstname" => "ท", "lastname" => "ส", "csrf_token" => "err-test-token", "last_activity" => time()];
chdir($root . "/officer/api");
require $root . "/officer/api/manage_evidence.php";
');
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe));
$json = json_decode(trim($out), true);
$msg = (string) ($json['message'] ?? '');
ck('E3 API หลักฐาน: คำสั่งที่ทำให้ฐานข้อมูล error → success=false ข้อความกลาง ไม่มี SQLSTATE / ชื่อตาราง / ชื่อ constraint · ไม่มีหลักฐานเพิ่ม',
    is_array($json) && empty($json['success']) && $msg === 'บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'
    && !str_contains($out, 'SQLSTATE') && !str_contains($out, 'FOREIGN KEY') && !str_contains($out, 'upnetzero')
    && (int) $pdo->query("SELECT COUNT(*) FROM evidence WHERE entity_type = 'event' AND entity_id = $evId")->fetchColumn() === $evBefore,
    substr($out, 0, 220));
@unlink($probe);

echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
