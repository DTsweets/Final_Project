<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
/**
 * Unit Test — CSRF token (includes/auth.php + ทุกฟอร์ม POST + fetch + officer/api/manage_evidence.php + login.php)
 * รัน: C:\xampp\php\php.exe tests\csrf_test.php
 *
 * ล็อกกฎ:
 *   - ทุก POST ของหน้าที่ต้อง login ตรวจ token ที่ศูนย์กลาง (require_login) — ไม่มี / ผิด → 403 ไม่แตะข้อมูล
 *     token รับได้ทั้งช่องฟอร์ม csrf_token และ header X-CSRF-Token (fetch) · API ตอบ JSON
 *   - ทุก <form method="post"> ที่เรนเดอร์มีช่อง csrf_token ค่าเดียวกับ session · fetch POST ส่ง token
 *   - API หลักฐาน: คำสั่งที่เขียนข้อมูลต้องเป็น POST (เดิม GET ?action=delete_all ลบหลักฐานได้จากลิงก์)
 *   - login: POST ไม่มี token → ไม่ login · login สำเร็จแล้วเปลี่ยน token ใหม่
 * POST จริงที่ยิงในเทสต์ถูกบล็อกหลังแก้ — ถ้าข้อมูลเปลี่ยน (ก่อนแก้) คืนค่าจากไฟล์สำรองที่ dump ไว้ตอนเริ่ม
 */
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
const TOK = 'csrf-test-token-0123456789';

// ── สถานะข้อมูลที่ POST ในเทสต์อาจแตะ (ใช้ยืนยันว่าถูกบล็อก และคืนค่าถ้าหลุด) ──
$pdo->exec('SET SESSION group_concat_max_len = 100000000');
$fingerprint = fn() => $pdo->query("SELECT
    (SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, admin_item_id, affiliation_id, year_id, Vol, source) ORDER BY id)) FROM user_item),
    (SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, qty, factor, name_tiem) ORDER BY id)) FROM removal_item),
    (SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, qty, factor) ORDER BY id)) FROM removal_event_item),
    (SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, username, firstname, lastname, role, Affiliation, email) ORDER BY id)) FROM users),
    (SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, entity_type, entity_id, kind, url) ORDER BY id)) FROM evidence),
    (SELECT COUNT(*) FROM affiliation_id)")->fetch(PDO::FETCH_NUM);
$before = $fingerprint();
$dump = sys_get_temp_dir() . '/csrf_test_backup.sql';
shell_exec(sprintf('"C:\\xampp\\mysql\\bin\\mysqldump.exe" -h 127.0.0.1 -u root --default-character-set=utf8mb4 upnetzero > %s', escapeshellarg($dump)));
$restoreIfChanged = function () use ($pdo, $fingerprint, $before, $dump) {
    if ($fingerprint() == $before) return false;
    shell_exec(sprintf('cmd /c ""C:\\xampp\\mysql\\bin\\mysql.exe" -h 127.0.0.1 -u root --default-character-set=utf8mb4 upnetzero < "%s""', $dump));
    return true;
};

// ── probe: เรนเดอร์หน้าผ่าน CLI ด้วย session / POST / header ที่กำหนด ──
$probe = sys_get_temp_dir() . '/csrf_probe.php';
file_put_contents($probe, '<?php
$root = ' . var_export($root, true) . ';
[$_, $page, $sess, $b64, $method, $hdr] = $argv + [5 => "get", 6 => ""];
parse_str(base64_decode($b64), $q);
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["PHP_SELF"] = "/" . $page; $_SERVER["REQUEST_URI"] = "/" . $page; $_SERVER["SCRIPT_NAME"] = "/" . $page;
$_SERVER["REQUEST_METHOD"] = $method === "post" ? "POST" : "GET";
if ($hdr !== "") $_SERVER["HTTP_X_CSRF_TOKEN"] = $hdr;
if ($method === "post") { $_GET = $q["__get"] ?? []; unset($q["__get"]); $_POST = $q; } else { $_GET = $q; }
$_REQUEST = array_merge($_GET, $_POST);
session_start();
$_SESSION = json_decode(base64_decode($sess), true);
if (isset($_SESSION["user_id"])) $_SESSION["last_activity"] = time();
register_shutdown_function(function () { echo "\n[[SESSION]]" . base64_encode(json_encode($_SESSION ?? [])); });
chdir(dirname("$root/$page"));
ob_start(); require "$root/$page"; echo ob_get_clean();
');
$sess = fn(string $role, int $aff, int $uid, bool $tok = true) => ['user_id' => $uid, 'role' => $role, 'affiliation_id' => $aff, 'affiliation_name' => 'ทดสอบ',
    'firstname' => 'ท', 'lastname' => 'ส', 'username' => 't'] + ($tok ? ['csrf_token' => TOK] : []);
$render = fn(string $page, array $session, array $data = [], string $method = 'get', string $hdr = '') => (string) shell_exec(sprintf('%s %s %s %s %s %s %s 2>&1',
    escapeshellarg(PHP_BINARY), escapeshellarg($probe), escapeshellarg($page), escapeshellarg(base64_encode(json_encode($session))),
    escapeshellarg(base64_encode(http_build_query($data))), $method, escapeshellarg($hdr === '' ? '' : $hdr)));
$blocked = fn(string $out) => str_contains($out, 'หมดอายุ');
$noErr = fn(string $h) => !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $h);

// ── C1 ฟังก์ชันตรวจ token ──
require_once $root . '/includes/auth.php';
ck('C1 csrf_valid: ไม่มี token / token ผิด / session ไม่มี token → ไม่ผ่าน · ช่องฟอร์มหรือ header ตรง → ผ่าน · csrf_field ใส่ค่าที่ escape แล้ว', (function () {
    if (!function_exists('csrf_valid') || !function_exists('csrf_field')) return false;
    $_SESSION = ['csrf_token' => TOK]; $_POST = []; unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $none = csrf_valid();
    $_POST = ['csrf_token' => 'ผิด']; $wrong = csrf_valid();
    $_POST = ['csrf_token' => TOK]; $okPost = csrf_valid();
    $_POST = []; $_SERVER['HTTP_X_CSRF_TOKEN'] = TOK; $okHdr = csrf_valid();
    $_SESSION = []; $_POST = ['csrf_token' => '']; unset($_SERVER['HTTP_X_CSRF_TOKEN']); $emptySess = csrf_valid();
    $_SESSION = ['csrf_token' => TOK];
    $field = csrf_field();
    return !$none && !$wrong && $okPost && $okHdr && !$emptySess && $field === '<input type="hidden" name="csrf_token" value="' . TOK . '">';
})());

// ── C2 POST ผ่านหน้าจริงโดยไม่มี token → 403 ไม่แตะข้อมูล ──
$year = (int) $pdo->query("SELECT year_id FROM user_item WHERE affiliation_id = 1 AND source = 'officer' GROUP BY year_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$ui   = $pdo->query("SELECT admin_item_id, scope FROM user_item u JOIN admin_item a ON a.id = u.admin_item_id WHERE u.affiliation_id = 1 AND u.year_id = $year AND u.source = 'officer' ORDER BY u.id LIMIT 1")->fetch();
$rm   = (int) $pdo->query("SELECT id FROM removal_item WHERE year_id = $year ORDER BY id LIMIT 1")->fetchColumn();
$rei  = $pdo->query("SELECT r.id, r.event_id, e.affiliation_id FROM removal_event_item r JOIN event e ON e.id = r.event_id ORDER BY r.id LIMIT 1")->fetch();
$emptyYear = (int) $pdo->query("SELECT y.id FROM admin_year y WHERE NOT EXISTS (SELECT 1 FROM user_item u WHERE u.year_id = y.id AND u.affiliation_id = 1 AND u.source = 'officer')
    AND EXISTS (SELECT 1 FROM admin_item a WHERE a.year_id = y.id AND a.data_source = 'officer') ORDER BY y.year DESC LIMIT 1")->fetchColumn();
$cases = [
    'officer กรอกปริมาณ'      => ['officer/data_entry_items.php', $sess('officer', 1, 5, true), ['action' => 'save', 'year' => $year, 'scope_groups' => [$ui['scope']], 'vol' => [$ui['admin_item_id'] => '4321']]],
    'officer ดูดกลับกิจกรรม'   => ['officer/collect.php', $sess('officer', (int) $rei['affiliation_id'], 5, true), ['action' => 'save_event_removal', 'tab' => 'event', 'year_id' => $year, 'event_id' => $rei['event_id'], 'qty' => [$rei['id'] => '777']]],
    'officer GHG Removal'      => ['officer/ghg.php', $sess('officer', 1, 5, true), ['action' => 'save_removal', 'year_id' => $year, 'qty' => [$rm => '999']]],
    'officer สร้างปี'          => ['officer/items.php', $sess('officer', 1, 5, true), ['action' => 'create_user_year', 'year_id' => $emptyYear]],
    'admin เพิ่มผู้ใช้'         => ['admin/settings.php', $sess('admin', 1, 1, true), ['action' => 'add', 'firstname' => 'ซีเอส', 'lastname' => 'อาร์เอฟ', 'username' => 'csrf_probe_user', 'email' => '',
                                    'role' => 'officer', 'affiliation' => '1', 'password' => 'x1', 'password_confirm' => 'x1']],
    'officer แก้โปรไฟล์'       => ['officer/profile.php', $sess('officer', 1, 5, true), ['firstname' => 'CSRFชื่อใหม่', 'lastname' => 'สกุล', 'username' => (string) $pdo->query('SELECT username FROM users WHERE id = 5')->fetchColumn(), 'email' => '']],
];
try {
    foreach ($cases as $label => [$page, $s, $data]) {
        $f0 = $fingerprint();
        $noTok = $render($page, $s, $data, 'post');
        $wrong = $render($page, $s, $data + ['csrf_token' => 'ผิด'], 'post');
        ck("C2 $label: POST ไม่มี token / token ผิด → 403 หน้าหมดอายุ · ข้อมูลไม่เปลี่ยน", $blocked($noTok) && $blocked($wrong) && $fingerprint() == $f0,
            'noTok=' . substr(preg_replace('/\s+/', ' ', strip_tags($noTok)), 0, 120));
        $restoreIfChanged();
    }
} finally {
    $restoreIfChanged();
}

// ── C3 ทุกฟอร์ม POST ที่เรนเดอร์มีช่อง csrf_token ค่าเดียวกับ session ──
$q  = $pdo->query("SELECT audience FROM questionnaire WHERE affiliation_id = 1 AND year_id = $year ORDER BY id LIMIT 1")->fetchColumn();
$ev = (int) $pdo->query("SELECT id FROM event WHERE affiliation_id = 1 AND year_id = $year ORDER BY id LIMIT 1")->fetchColumn();
$pages = [
    ['admin/items.php', $sess('admin', 1, 1), ['groups' => 1]],
    ['admin/settings.php', $sess('admin', 1, 1), []],
    ['admin/data_entry_items.php', $sess('admin', 1, 1), ['year' => $year, 'affil' => 1, 'scope_groups' => [$ui['scope']]]],
    ['admin/ghg.php', $sess('admin', 1, 1), ['year' => $year]],
    ['admin/collect.php', $sess('admin', 1, 1), ['year' => $year, 'tab' => 'event', 'affil' => 1, 'event' => $ev]],
    ['officer/items.php', $sess('officer', 1, 5), []],
    ['officer/data_entry_items.php', $sess('officer', 1, 5), ['year' => $year, 'scope_groups' => [$ui['scope']]]],
    ['officer/collect.php', $sess('officer', 1, 5), ['year' => $year, 'tab' => 'survey', 'group' => (string) $q]],
    ['officer/collect.php', $sess('officer', 1, 5), ['year' => $year, 'tab' => 'event', 'event' => $ev]],
    ['officer/ghg.php', $sess('officer', 1, 5), ['year' => $year]],
    ['officer/profile.php', $sess('officer', 1, 5), []],
    ['dean/profile.php', $sess('dean', 1, 23), []],
    ['login.php', ['csrf_token' => TOK], []],
];
foreach ($pages as [$page, $s, $get]) {
    $h = $render($page, $s, $get);
    $forms  = preg_match_all('/<form\b[^>]*\bmethod="post"/i', $h);
    $fields = substr_count($h, '<input type="hidden" name="csrf_token" value="' . TOK . '">');
    ck("C3 $page " . ($get ? '(' . http_build_query($get) . ')' : '') . ": ฟอร์ม POST $forms ฟอร์ม มีช่อง csrf_token ครบ", $noErr($h) && $forms > 0 && $fields === $forms, "forms=$forms fields=$fields");
}

// ── C4 API หลักฐาน ──
$evNo = $pdo->query("SELECT e.id, e.affiliation_id FROM event e WHERE NOT EXISTS (SELECT 1 FROM evidence v WHERE v.entity_type = 'event' AND v.entity_id = e.id) ORDER BY e.id LIMIT 1")->fetch();
$mark = 'https://csrf-test.example/' . bin2hex(random_bytes(4));
try {
    $pdo->prepare("INSERT INTO evidence (entity_type, entity_id, kind, url, label) VALUES ('event', ?, 'link', ?, 'csrf-test')")->execute([$evNo['id'], $mark]);
    $count = fn() => (int) $pdo->query("SELECT COUNT(*) FROM evidence WHERE entity_type = 'event' AND entity_id = {$evNo['id']}")->fetchColumn();
    $api = 'officer/api/manage_evidence.php';
    $admin = $sess('admin', 1, 1);
    $get = $render($api, $admin, ['action' => 'delete_all', 'entity_type' => 'event', 'entity_id' => $evNo['id']]);   // query → $_GET (action) — resolve_entity อ่าน $_REQUEST
    $jGet = json_decode(trim(explode('[[SESSION]]', $get)[0]), true);
    ck('C4 GET ?action=delete_all (เช่น กดลิงก์จากเว็บอื่น) → ถูกปฏิเสธ (ต้องเป็น POST) หลักฐานไม่หาย', $count() === 1 && is_array($jGet) && empty($jGet['success']), substr($get, 0, 200));
    $postNo = $render($api . '', $admin, ['__get' => ['action' => 'add_link'], 'entity_type' => 'event', 'entity_id' => $evNo['id'], 'url' => $mark . '/x'], 'post');
    $jNo = json_decode(trim(explode('[[SESSION]]', $postNo)[0]), true);
    ck('C4b POST add_link ไม่มี token → JSON success=false พร้อมข้อความ · ไม่มีลิงก์เพิ่ม', is_array($jNo) && empty($jNo['success']) && str_contains((string) ($jNo['message'] ?? ''), 'หมดอายุ') && $count() === 1,
        substr($postNo, 0, 200));
    $list = $render($api, $admin + [], ['action' => 'list', 'entity_type' => 'event', 'entity_id' => $evNo['id']]);
    $jList = json_decode(trim(explode('[[SESSION]]', $list)[0]), true);
    ck('C4c GET list ยังใช้ได้ (อ่านอย่างเดียว)', is_array($jList) && !empty($jList['success']) && count($jList['data'] ?? []) === 1, substr($list, 0, 200));
} finally {
    $pdo->prepare("DELETE FROM evidence WHERE url LIKE ?")->execute([$mark . '%']);
}

// ── C5 fetch POST ส่ง token ──
$evm = (string) file_get_contents($root . '/components/evidence_modal.php');
$ai  = (string) file_get_contents($root . '/admin/items.php');
ck('C5 fetch POST ทุกจุดส่ง token: หลักฐาน 4 คำสั่ง (header X-CSRF-Token) · เลื่อนหมวด (csrf_token ใน FormData)',
    substr_count($evm, "method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd") === 4 && substr_count($evm, "method:'POST'") === 4
    && str_contains($evm, 'var CSRF=<?= json_encode(csrf_token()) ?>;')
    && str_contains($ai, "fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);"));

// ── C6 login ──
$lg = $render('login.php', ['csrf_token' => TOK], ['username' => 'ไม่มีผู้ใช้นี้แน่นอน', 'password' => 'x'], 'post');
ck('C6 login POST ไม่มี token → ขึ้น "หน้านี้หมดอายุ" ไม่ตรวจรหัสผ่าน · ไม่ได้ session ผู้ใช้', str_contains($lg, 'หมดอายุ') && !str_contains($lg, 'ไม่พบชื่อผู้ใช้งานนี้ในระบบ')
    && !str_contains(base64_decode((string) (explode('[[SESSION]]', $lg)[1] ?? '')), 'user_id'), substr(strip_tags($lg), 0, 200));
$lgSrc = (string) file_get_contents($root . '/login.php');
ck('C6b login สำเร็จแล้วเปลี่ยน token ใหม่ (ต่อจาก session_regenerate_id) · หน้า login ใช้ auth.php (cookie httponly/samesite ชุดเดียวกับทุกหน้า)',
    (bool) preg_match('/session_regenerate_id\(true\);\s*unset\(\$_SESSION\[\'csrf_token\'\]\);/', $lgSrc) && str_contains($lgSrc, "require_once __DIR__ . '/includes/auth.php';"));

@unlink($probe); @unlink($dump);
ck('C7 ข้อมูลจริงเท่าเดิมหลังทดสอบทั้งหมด', $fingerprint() == $before);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
