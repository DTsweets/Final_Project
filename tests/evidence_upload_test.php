<?php
/**
 * Unit Test — อัปโหลดไฟล์หลักฐาน (includes/evidence_upload.php + officer/api/manage_evidence.php + components/evidence_modal.php)
 * รัน: C:\xampp\php\php.exe tests\evidence_upload_test.php
 *
 * ล็อกกฎ:
 *   - ไฟล์ละไม่เกิน 10 MB · ครั้งละไม่เกิน 5 ไฟล์
 *   - ตรวจเนื้อไฟล์จริง ไม่เชื่อแค่นามสกุล: รูป = finfo เป็นรูปที่รองรับ + getimagesize อ่านได้ · PDF / DOCX… / DOC… = ลายเซ็นไฟล์
 *   - มีไฟล์ไม่ผ่านแม้ไฟล์เดียว → ไม่บันทึกทั้งชุด (ไม่มีแถว evidence ไม่มีไฟล์ถูกเขียน) พร้อมข้อความบอกชื่อไฟล์ + เหตุผล
 *   - modal ตรวจขนาด / นามสกุล / จำนวนก่อนส่ง (เซิร์ฟเวอร์ตรวจซ้ำเสมอ)
 * ไฟล์ตัวอย่างสร้างในโฟลเดอร์ temp แล้วลบทิ้ง · เขียนฐานใน transaction แล้ว rollback
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
$hasLib = is_file("$root/includes/evidence_upload.php");
if ($hasLib) require_once "$root/includes/evidence_upload.php";

// ── ไฟล์ตัวอย่าง ──
$tmpDir = sys_get_temp_dir() . '/ev_upload_test_' . getmypid();
@mkdir($tmpDir, 0777, true);
$mk = function (string $name, string $bytes) use ($tmpDir): string { $p = "$tmpDir/src_$name"; file_put_contents($p, $bytes); return $p; };
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$jpgBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
$f = [
    'png'     => $mk('a.png', $pngBytes),
    'jpg'     => $mk('b.jpg', $jpgBytes),
    'pdf'     => $mk('c.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"),
    'docx'    => $mk('d.docx', "PK\x03\x04" . str_repeat("\0", 100)),
    'xls'     => $mk('e.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 600)),
    'fakepng' => $mk('f.png', 'hello — not an image'),
    'fakepdf' => $mk('g.pdf', '<?php echo "x";'),
    'fakedoc' => $mk('h.docx', '%PDF-1.4 pdf renamed'),
    'php'     => $mk('i.php', '<?php echo 1;'),
    'empty'   => $mk('j.pdf', ''),
];
/** จำลอง 1 ไฟล์ของ $_FILES */
$one = fn(string $name, string $path, ?int $size = null, int $err = UPLOAD_ERR_OK) => ['name' => $name, 'tmp_name' => $path, 'size' => $size ?? (int) @filesize($path), 'error' => $err];
/** จำลอง $_FILES['images'] (รูปแบบ array หลายไฟล์) */
$multi = function (array $list): array {
    $o = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($list as $x) { $o['name'][] = $x['name']; $o['type'][] = 'application/octet-stream'; $o['tmp_name'][] = $x['tmp_name']; $o['error'][] = $x['error']; $o['size'][] = $x['size']; }
    return $o;
};

// ── U1 ไฟล์จริงผ่าน ──
ck('U1 evidence_file_error: png / jpg จริง, PDF, DOCX (zip), XLS (OLE) → ผ่าน (null) · ขนาดพอดี 10 MB ผ่าน', $hasLib && (function () use ($f, $one) {
    return EVIDENCE_MAX_BYTES === 10 * 1024 * 1024 && EVIDENCE_MAX_FILES === 5
        && evidence_file_error($one('a.png', $f['png'])) === null
        && evidence_file_error($one('รูป.JPG', $f['jpg'])) === null
        && evidence_file_error($one('c.pdf', $f['pdf'])) === null
        && evidence_file_error($one('d.docx', $f['docx'])) === null
        && evidence_file_error($one('e.xls', $f['xls'])) === null
        && evidence_file_error($one('c.pdf', $f['pdf'], EVIDENCE_MAX_BYTES)) === null;
})());

// ── U2 ไฟล์ไม่ผ่าน + ข้อความ ──
$errs = $hasLib ? [
    'fakepng' => evidence_file_error($one('f.png', $f['fakepng'])),
    'fakepdf' => evidence_file_error($one('g.pdf', $f['fakepdf'])),
    'fakedoc' => evidence_file_error($one('h.docx', $f['fakedoc'])),
    'php'     => evidence_file_error($one('i.php', $f['php'])),
    'noext'   => evidence_file_error($one('README', $f['pdf'])),
    'big'     => evidence_file_error($one('c.pdf', $f['pdf'], EVIDENCE_MAX_BYTES + 1)),
    'ini'     => evidence_file_error($one('c.pdf', '', 0, UPLOAD_ERR_INI_SIZE)),
    'partial' => evidence_file_error($one('c.pdf', '', 0, UPLOAD_ERR_PARTIAL)),
    'empty'   => evidence_file_error($one('j.pdf', $f['empty'])),
] : [];
ck('U2 ไม่ผ่านพร้อมเหตุผล: รูปปลอม / PDF ที่เนื้อเป็น PHP / PDF ตั้งชื่อ .docx / .php / ไม่มีนามสกุล / เกิน 10 MB / PHP ตัดเพราะใหญ่ / อัปโหลดไม่ครบ / ไฟล์ว่าง',
    $hasLib
    && $errs['fakepng'] === 'เนื้อไฟล์ไม่ใช่รูปภาพจริง'
    && $errs['fakepdf'] === 'เนื้อไฟล์ไม่ใช่ PDF จริง'
    && $errs['fakedoc'] === 'เนื้อไฟล์ไม่ใช่ DOCX จริง'
    && $errs['php'] === 'ไม่รองรับไฟล์ .php'
    && $errs['noext'] === 'ไม่รองรับไฟล์ชนิดนี้'
    && $errs['big'] === 'ใหญ่เกิน 10 MB' && $errs['ini'] === 'ใหญ่เกิน 10 MB'
    && $errs['partial'] === 'อัปโหลดไม่สำเร็จ'
    && $errs['empty'] === 'ไฟล์ว่าง',
    json_encode($errs, JSON_UNESCAPED_UNICODE));

// ── U3 บันทึกทั้งชุด / ปฏิเสธทั้งชุด ──
$evId = (int) $pdo->query('SELECT id FROM event ORDER BY id LIMIT 1')->fetchColumn();
$outDir = "$tmpDir/out/";
$filesIn = fn() => is_dir($outDir) ? count(array_filter(array_merge(glob($outDir . '*') ?: [], glob($outDir . 'docs/*') ?: []), 'is_file')) : 0;
$evCount = fn() => (int) $pdo->query("SELECT COUNT(*) FROM evidence WHERE entity_type = 'event' AND entity_id = $evId")->fetchColumn();
$before = $evCount();
$u3 = ['bad' => null, 'bad_files' => null, 'bad_rows' => null, 'many' => null, 'none' => null, 'ok' => null, 'rows' => [], 'files' => null];
if ($hasLib) {
    $pdo->beginTransaction();
    try {
        // ชุดที่มีไฟล์เสีย 1 ไฟล์
        try {
            evidence_save_uploads($pdo, 'event', $evId, $multi([$one('a.png', $f['png']), $one('g.pdf', $f['fakepdf']), $one('i.php', $f['php'])]), null, $outDir, 'copy');
        } catch (Exception $e) { $u3['bad'] = $e->getMessage(); }
        $u3['bad_files'] = $filesIn(); $u3['bad_rows'] = $evCount();
        // เกิน 5 ไฟล์
        try {
            evidence_save_uploads($pdo, 'event', $evId, $multi(array_fill(0, 6, $one('c.pdf', $f['pdf']))), null, $outDir, 'copy');
        } catch (Exception $e) { $u3['many'] = $e->getMessage(); }
        // ไม่มีไฟล์ (ช่องว่าง)
        try {
            evidence_save_uploads($pdo, 'event', $evId, $multi([$one('', '', 0, UPLOAD_ERR_NO_FILE)]), null, $outDir, 'copy');
        } catch (Exception $e) { $u3['none'] = $e->getMessage(); }
        // ชุดที่ถูกทั้งหมด (สูงสุด 5)
        $u3['ok'] = evidence_save_uploads($pdo, 'event', $evId, $multi([
            $one('a.png', $f['png']), $one('ภาพถ่าย.jpg', $f['jpg']), $one('c.pdf', $f['pdf']), $one('d.docx', $f['docx']), $one('e.xls', $f['xls']),
        ]), null, $outDir, 'copy');
        $u3['rows'] = $pdo->query("SELECT file_path, file_type, original_name FROM evidence WHERE entity_type = 'event' AND entity_id = $evId ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $u3['files'] = $filesIn();
    } finally {
        $pdo->rollBack();
    }
}
$types = array_column(array_reverse($u3['rows']), 'file_type', 'original_name');
$pathsOk = array_reduce($u3['rows'], fn($c, $r) => $c && is_file($outDir . $r['file_path']), true);
ck('U3a ชุดที่มีไฟล์เสีย → ไม่บันทึกทั้งชุด (ไม่มีแถว / ไม่มีไฟล์) · ข้อความบอกจำนวน + ชื่อไฟล์ + เหตุผล',
    $u3['bad'] === 'ไฟล์ไม่ถูกต้อง 2 ไฟล์ จึงยังไม่บันทึก: g.pdf (เนื้อไฟล์ไม่ใช่ PDF จริง), i.php (ไม่รองรับไฟล์ .php)'
    && $u3['bad_files'] === 0 && $u3['bad_rows'] === $before,
    json_encode($u3, JSON_UNESCAPED_UNICODE));
ck('U3b เกิน 5 ไฟล์ → ปฏิเสธ · ไม่มีไฟล์ → ปฏิเสธ (ข้อความไทย)',
    $u3['many'] === 'อัปโหลดได้ครั้งละไม่เกิน 5 ไฟล์' && $u3['none'] === 'ไม่พบไฟล์ที่อัปโหลด',
    json_encode([$u3['many'], $u3['none']], JSON_UNESCAPED_UNICODE));
ck('U3c ชุดที่ถูกทั้งหมด → บันทึกครบ 5 แถว + 5 ไฟล์ · file_type ตามเนื้อไฟล์ · หลัง rollback ฐานไม่เปลี่ยน',
    is_array($u3['ok']) && count($u3['ok']) === 5 && $u3['files'] === 5 && $pathsOk
    && $types === ['a.png' => 'image/png', 'ภาพถ่าย.jpg' => 'image/jpeg', 'c.pdf' => 'application/pdf',
        'd.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'e.xls' => 'application/vnd.ms-excel']
    && $evCount() === $before,
    json_encode(['types' => $types, 'files' => $u3['files'], 'after' => $evCount(), 'before' => $before], JSON_UNESCAPED_UNICODE));

// ── U4 API ผ่านหน้าจริง (ถูกปฏิเสธก่อนเขียน — ไม่แตะข้อมูล) ──
$probe = "$tmpDir/api_probe.php";
file_put_contents($probe, '<?php
$root = ' . var_export($root, true) . ';
[$_, $b64files, $b64post, $clen, $action, $role, $affil] = $argv + [3 => "0", 4 => "upload", 5 => "admin", 6 => "1"];
$_SERVER["DOCUMENT_ROOT"] = $root; $_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["PHP_SELF"] = $_SERVER["SCRIPT_NAME"] = "/officer/api/manage_evidence.php";
$_SERVER["REQUEST_URI"] = "/officer/api/manage_evidence.php?action=" . $action;
$_SERVER["CONTENT_LENGTH"] = $clen; $_SERVER["HTTP_X_CSRF_TOKEN"] = "ev-test-token";
$_GET = ["action" => $action]; parse_str(base64_decode($b64post), $_POST);
$_FILES = json_decode(base64_decode($b64files), true); $_REQUEST = array_merge($_GET, $_POST);
session_start();
$_SESSION = ["user_id" => 1, "role" => $role, "affiliation_id" => (int) $affil, "username" => "t", "firstname" => "ท", "lastname" => "ส", "csrf_token" => "ev-test-token", "last_activity" => time()];
chdir($root . "/officer/api");
require $root . "/officer/api/manage_evidence.php";
');
$api = fn(array $files, array $post, int $clen = 100, string $action = 'upload', string $role = 'admin', int $affil = 1) => json_decode(trim((string) shell_exec(sprintf('%s %s %s %s %d %s %s %d 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe),
    escapeshellarg(base64_encode(json_encode($files))), escapeshellarg(base64_encode(http_build_query($post))), $clen, $action, $role, $affil))), true);
$evFilesBefore = count(glob("$root/assets/images/evidence/{*,docs/*}", GLOB_BRACE) ?: []);
$rBad = $api(['documents' => $multi([$one('g.pdf', $f['fakepdf'])])], ['entity_type' => 'event', 'entity_id' => $evId]);
$rBig = $api([], [], 60 * 1024 * 1024);
ck('U4 API upload: ไฟล์ปลอม → success=false พร้อมเหตุผล · POST ใหญ่เกิน post_max_size ($_FILES ว่าง) → ข้อความไทย · ไม่มีแถว/ไฟล์เพิ่ม',
    is_array($rBad) && empty($rBad['success']) && str_contains((string) ($rBad['message'] ?? ''), 'g.pdf (เนื้อไฟล์ไม่ใช่ PDF จริง)')
    && is_array($rBig) && empty($rBig['success']) && str_contains((string) ($rBig['message'] ?? ''), 'ไฟล์รวมใหญ่เกิน')
    && $evCount() === $before && count(glob("$root/assets/images/evidence/{*,docs/*}", GLOB_BRACE) ?: []) === $evFilesBefore,
    json_encode([$rBad, $rBig], JSON_UNESCAPED_UNICODE));

// ── L แบบเก่า (scope entry ของเจ้าหน้าที่ส่ง admin_item_id + year_id) ──
//   ต้องเป็นรายการการดำเนินงาน (officer) ของปีนั้นจริง · สร้าง user_item (source officer) เฉพาะเมื่อไฟล์/ลิงก์บันทึกได้จริง
//   ใช้ชุด (รายการ, หน่วยงาน, ปี) ที่ยังไม่มีแถวเลย แล้วลบทุกอย่างที่เกิดจากเทสต์ใน finally
$affs = array_map('intval', $pdo->query('SELECT id FROM affiliation_id ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$free = function (string $src, ?int $notYear = null) use ($pdo, $affs): ?array {
    $st = $pdo->prepare("SELECT ai.id, ai.year_id FROM admin_item ai WHERE ai.data_source = ? " . ($notYear ? 'AND ai.year_id <> ' . (int) $notYear : '') . ' ORDER BY ai.id');
    $st->execute([$src]);
    $has = $pdo->prepare('SELECT COUNT(*) FROM user_item WHERE admin_item_id = ? AND affiliation_id = ? AND year_id = ?');
    foreach ($st->fetchAll() as $ai) foreach ($affs as $a) {
        if ((int) $ai['id'] === 52) continue;
        $has->execute([$ai['id'], $a, $ai['year_id']]);
        if (!$has->fetchColumn()) return ['aid' => (int) $ai['id'], 'year' => (int) $ai['year_id'], 'aff' => $a];
    }
    return null;
};
$ok  = $free('officer');
$oth = $ok ? $free('officer', $ok['year']) : null;          // รายการของปีอื่น
$sv  = $free('survey');
$rowsOf = function (int $aid, int $aff, int $year) use ($pdo): array {
    $st = $pdo->prepare('SELECT id, source, Vol FROM user_item WHERE admin_item_id = ? AND affiliation_id = ? AND year_id = ?');
    $st->execute([$aid, $aff, $year]); return $st->fetchAll(PDO::FETCH_ASSOC);
};
$L = [];
try {
    $o = fn(array $post, string $action = 'upload', array $files = []) => $api($files, $post, 100, $action, 'officer', $ok['aff']);
    $legacy = ['admin_item_id' => $ok['aid'], 'year_id' => $ok['year']];
    $L['bad_file']  = $o($legacy, 'upload', ['documents' => $multi([$one('g.pdf', $f['fakepdf'])])]);
    $L['rows1']     = $rowsOf($ok['aid'], $ok['aff'], $ok['year']);
    $L['bad_link']  = $o($legacy + ['url' => ''], 'add_link');
    $L['rows2']     = $rowsOf($ok['aid'], $ok['aff'], $ok['year']);
    $L['wrong_yr']  = $o(['admin_item_id' => $oth['aid'], 'year_id' => $ok['year'], 'url' => 'https://example.com/ev-test'], 'add_link');
    $L['rows_wy']   = $rowsOf($oth['aid'], $ok['aff'], $ok['year']);
    $L['survey']    = $api([], ['admin_item_id' => $sv['aid'], 'year_id' => $sv['year'], 'url' => 'https://example.com/ev-test'], 100, 'add_link', 'officer', $sv['aff']);
    $L['rows_sv']   = $rowsOf($sv['aid'], $sv['aff'], $sv['year']);
    $L['no_item']   = $o(['admin_item_id' => 99999999, 'year_id' => $ok['year'], 'url' => 'https://example.com/ev-test'], 'add_link');
    $L['good']      = $o($legacy + ['url' => 'https://example.com/ev-test', 'label' => 'ev-legacy-test'], 'add_link');
    $L['rows3']     = $rowsOf($ok['aid'], $ok['aff'], $ok['year']);
    $L['list']      = $api([], $legacy, 100, 'list', 'officer', $ok['aff']);
} finally {
    foreach ([[$ok['aid'] ?? 0, $ok['aff'] ?? 0, $ok['year'] ?? 0], [$oth['aid'] ?? 0, $ok['aff'] ?? 0, $ok['year'] ?? 0], [$sv['aid'] ?? 0, $sv['aff'] ?? 0, $sv['year'] ?? 0]] as [$a, $af, $y]) {
        foreach ($rowsOf($a, $af, $y) as $r) {
            $pdo->prepare("DELETE FROM evidence WHERE entity_type = 'user_item' AND entity_id = ?")->execute([$r['id']]);
            $pdo->prepare('DELETE FROM user_item WHERE id = ?')->execute([$r['id']]);
        }
    }
}
$fail_ = fn($r, string $msg) => is_array($r) && empty($r['success']) && str_contains((string) ($r['message'] ?? ''), $msg);
ck('L1 แบบเก่า: ไฟล์ไม่ผ่าน / ลิงก์ว่าง → ไม่สร้างแถว user_item ค้าง (เดิมสร้างแถว Vol 0 ก่อนตรวจ)',
    $ok && $fail_($L['bad_file'], 'เนื้อไฟล์ไม่ใช่ PDF จริง') && $L['rows1'] === [] && $fail_($L['bad_link'], 'กรุณาระบุลิงก์') && $L['rows2'] === [],
    json_encode(array_intersect_key($L, array_flip(['bad_file', 'rows1', 'bad_link', 'rows2'])), JSON_UNESCAPED_UNICODE));
ck('L2 แบบเก่า: รายการของปีอื่น / รายการแบบสอบถาม / id ที่ไม่มี → "ไม่พบรายการ" ไม่สร้างแถว',
    $oth && $sv && $fail_($L['wrong_yr'], 'ไม่พบรายการ') && $L['rows_wy'] === [] && $fail_($L['survey'], 'ไม่พบรายการ') && $L['rows_sv'] === [] && $fail_($L['no_item'], 'ไม่พบรายการ'),
    json_encode(array_intersect_key($L, array_flip(['wrong_yr', 'rows_wy', 'survey', 'rows_sv', 'no_item'])), JSON_UNESCAPED_UNICODE));
ck('L3 แบบเก่า: ลิงก์ถูกต้อง → สร้างแถว source officer Vol 0 แถวเดียว + หลักฐานผูกแถวนั้น · list แบบเก่าเห็น 1 รายการ · หลังเทสต์ลบหมด',
    !empty($L['good']['success']) && count($L['rows3']) === 1 && $L['rows3'][0]['source'] === 'officer' && (float) $L['rows3'][0]['Vol'] === 0.0
    && !empty($L['list']['success']) && count($L['list']['data'] ?? []) === 1 && ($L['list']['data'][0]['label'] ?? '') === 'ev-legacy-test'
    && $rowsOf($ok['aid'], $ok['aff'], $ok['year']) === [],
    json_encode(array_intersect_key($L, array_flip(['good', 'rows3', 'list'])), JSON_UNESCAPED_UNICODE));

// ── U5 โค้ด: API ใช้ฟังก์ชันกลาง · modal ตรวจก่อนส่ง ──
$apiSrc = (string) file_get_contents("$root/officer/api/manage_evidence.php");
$modal  = (string) file_get_contents("$root/components/evidence_modal.php");
ck('U5 API เรียก evidence_save_uploads(..., move_uploaded_file) ไม่มีรายการนามสกุลซ้ำในไฟล์ · modal ตรวจขนาด/นามสกุล/จำนวนจากค่าคงที่เดียวกัน + บอก 10 MB',
    str_contains($apiSrc, "evidence_save_uploads(\$pdo, \$type, \$id, \$_FILES[\$file_key], \$uid, evidence_dir(), 'move_uploaded_file')")
    && !str_contains($apiSrc, '$image_exts') && !str_contains($apiSrc, 'function processEvidenceImage')
    && str_contains($modal, 'EVIDENCE_MAX_BYTES') && str_contains($modal, 'EVIDENCE_MAX_FILES') && str_contains($modal, 'EVIDENCE_IMAGE_EXTS') && str_contains($modal, 'EVIDENCE_DOC_EXTS')
    && str_contains($modal, 'function uevCheckFile(') && str_contains($modal, 'ไม่เกิน 10 MB ต่อไฟล์'));

// ── U5b สคริปต์ modal ไวยากรณ์ถูก (แทนแท็ก PHP ด้วย 0 แล้ว node --check) ──
preg_match('~<script>(.*?)</script>~s', $modal, $m);
$js = "$tmpDir/modal_check.js";
file_put_contents($js, preg_replace('~<\?=.*?\?>~s', '0', $m[1] ?? 'syntax error('));
$nodeOut = (string) shell_exec('node --check ' . escapeshellarg($js) . ' 2>&1');
ck('U5b สคริปต์ใน evidence_modal.php ไม่มี syntax error', ($m[1] ?? '') !== '' && trim($nodeOut) === '', substr($nodeOut, 0, 300));

// ── เก็บกวาด ──
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $p)
    $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname());
@rmdir($tmpDir);
echo "\n==== PASS=$pass  FAIL=$fail ====\n";
exit($fail ? 1 : 0);
