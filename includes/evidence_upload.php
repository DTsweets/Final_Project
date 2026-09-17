<?php
/**
 * อัปโหลดไฟล์หลักฐาน — ตรวจไฟล์ + บันทึกทั้งชุด (ใช้โดย officer/api/manage_evidence.php, components/evidence_modal.php)
 * เทสต์: tests/evidence_upload_test.php
 *
 * กฎ: ไฟล์ละไม่เกิน 10 MB · ครั้งละไม่เกิน 5 ไฟล์ · ตรวจเนื้อไฟล์จริง (ไม่เชื่อแค่นามสกุล)
 *     มีไฟล์ไม่ผ่านแม้ไฟล์เดียว → ไม่บันทึกทั้งชุด
 */

const EVIDENCE_MAX_BYTES  = 10 * 1024 * 1024;
const EVIDENCE_MAX_FILES  = 5;
const EVIDENCE_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const EVIDENCE_DOC_EXTS   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

/** mime ของรูปที่รองรับ → นามสกุลที่ใช้ตั้งชื่อไฟล์ */
const EVIDENCE_IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

const EVIDENCE_DOC_MIMES = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

/** post_max_size เป็นไบต์ (ขนาดรวมสูงสุดต่อครั้งที่เซิร์ฟเวอร์รับ — เกินแล้ว PHP ทิ้ง $_POST/$_FILES ทั้งหมด) */
function evidence_post_max_bytes(): int
{
    $v = trim((string) ini_get('post_max_size'));
    $n = (int) $v;
    $mul = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper(substr($v, -1))] ?? 1;
    return $n > 0 ? $n * $mul : PHP_INT_MAX;
}

/** mime ของรูปจากเนื้อไฟล์ (null = ไม่ใช่รูปที่รองรับ / อ่านไม่ได้) */
function evidence_image_mime(string $path): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!isset(EVIDENCE_IMAGE_MIMES[$mime])) return null;
    $info = @getimagesize($path);
    return ($info && $info[0] > 0 && $info[1] > 0) ? $mime : null;
}

/** ลายเซ็นเอกสาร: PDF = %PDF- · docx/xlsx/pptx = zip (PK) · doc/xls/ppt = OLE */
function evidence_doc_signature_ok(string $path, string $ext): bool
{
    $head = (string) @file_get_contents($path, false, null, 0, 1024);
    if ($ext === 'pdf') return str_contains($head, '%PDF-');
    if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) return str_starts_with($head, "PK\x03\x04");
    return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
}

/**
 * ตรวจไฟล์ 1 ไฟล์ (รูปแบบ $_FILES เดี่ยว: name, tmp_name, size, error)
 * @return string|null ข้อความเหตุผลที่ไม่ผ่าน · null = ผ่าน
 */
function evidence_file_error(array $file): ?string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'ใหญ่เกิน 10 MB';
    if ($err !== UPLOAD_ERR_OK) return 'อัปโหลดไม่สำเร็จ';

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') return 'ไม่รองรับไฟล์ชนิดนี้';
    if (!in_array($ext, EVIDENCE_IMAGE_EXTS, true) && !in_array($ext, EVIDENCE_DOC_EXTS, true)) return "ไม่รองรับไฟล์ .$ext";

    $tmp  = (string) ($file['tmp_name'] ?? '');
    $real = is_file($tmp) ? (int) filesize($tmp) : 0;
    if (max((int) ($file['size'] ?? 0), $real) > EVIDENCE_MAX_BYTES) return 'ใหญ่เกิน 10 MB';
    if ($real === 0) return 'ไฟล์ว่าง';

    if (in_array($ext, EVIDENCE_IMAGE_EXTS, true)) return evidence_image_mime($tmp) ? null : 'เนื้อไฟล์ไม่ใช่รูปภาพจริง';
    return evidence_doc_signature_ok($tmp, $ext) ? null : 'เนื้อไฟล์ไม่ใช่ ' . strtoupper($ext) . ' จริง';
}

/** $_FILES['x'] (หลายไฟล์หรือไฟล์เดียว) → list ของไฟล์เดี่ยว · ตัดช่องที่ไม่ได้เลือกไฟล์ */
function evidence_normalize_files(array $files): array
{
    $out = [];
    foreach ((array) ($files['name'] ?? []) as $i => $name) {
        $one = [
            'name'     => (string) $name,
            'tmp_name' => (string) (is_array($files['tmp_name'] ?? null) ? $files['tmp_name'][$i] ?? '' : $files['tmp_name'] ?? ''),
            'size'     => (int) (is_array($files['size'] ?? null) ? $files['size'][$i] ?? 0 : $files['size'] ?? 0),
            'error'    => (int) (is_array($files['error'] ?? null) ? $files['error'][$i] ?? UPLOAD_ERR_NO_FILE : $files['error'] ?? UPLOAD_ERR_NO_FILE),
        ];
        if ($one['error'] !== UPLOAD_ERR_NO_FILE) $out[] = $one;
    }
    return $out;
}

/** ย่อ + แปลงเป็น WebP (ใช้เมื่อมี GD) */
function processEvidenceImage($sourcePath, $targetPath, $inputExt)
{
    if (!file_exists($sourcePath)) return false;
    list($ow, $oh) = @getimagesize($sourcePath);
    if (!$ow || !$oh) return false;
    $ratio = min(1200 / $ow, 1200 / $oh);
    $nw = ($ratio >= 1) ? $ow : (int) ($ow * $ratio);
    $nh = ($ratio >= 1) ? $oh : (int) ($oh * $ratio);
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    switch (strtolower($inputExt)) {
        case 'jpeg': case 'jpg': $src = @imagecreatefromjpeg($sourcePath); break;
        case 'png':  $src = @imagecreatefrompng($sourcePath);  break;
        case 'gif':  $src = @imagecreatefromgif($sourcePath);  break;
        case 'webp': $src = @imagecreatefromwebp($sourcePath); break;
        default: return false;
    }
    if (!$src) return false;
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    $ok = imagewebp($dst, $targetPath, 80);
    imagedestroy($dst); imagedestroy($src);
    return $ok;
}

/**
 * ตรวจทุกไฟล์ก่อน → ผ่านครบจึงย้ายไฟล์ + INSERT evidence
 * @param array    $files  $_FILES['images'] หรือ $_FILES['documents']
 * @param string   $dir    โฟลเดอร์หลักฐาน (ลงท้าย /) — เอกสารเก็บใน docs/
 * @param callable $mover  ย้ายไฟล์ (หน้าเว็บ = move_uploaded_file, เทสต์ = copy)
 * @return array รายการที่บันทึก [id, path, type]
 * @throws Exception ไม่มีไฟล์ / เกินจำนวน / มีไฟล์ไม่ผ่าน (ไม่บันทึกทั้งชุด) / บันทึกไม่ได้สักไฟล์
 */
function evidence_save_uploads(PDO $pdo, string $type, int $id, array $files, $uid, string $dir, callable $mover): array
{
    $list = evidence_normalize_files($files);
    if (!$list) throw new Exception('ไม่พบไฟล์ที่อัปโหลด');
    if (count($list) > EVIDENCE_MAX_FILES) throw new Exception('อัปโหลดได้ครั้งละไม่เกิน ' . EVIDENCE_MAX_FILES . ' ไฟล์');

    $bad = [];
    foreach ($list as $f) {
        $e = evidence_file_error($f);
        if ($e !== null) $bad[] = $f['name'] . " ($e)";
    }
    if ($bad) throw new Exception('ไฟล์ไม่ถูกต้อง ' . count($bad) . ' ไฟล์ จึงยังไม่บันทึก: ' . implode(', ', $bad));

    $doc_dir = $dir . 'docs/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_dir($doc_dir)) mkdir($doc_dir, 0777, true);

    $ins = $pdo->prepare("INSERT INTO evidence (entity_type,entity_id,kind,file_path,file_type,original_name,created_by)
                          VALUES (?,?,'file',?,?,?,?)");
    $tag = $type . '_' . $id;
    $gd  = function_exists('imagecreatetruecolor') && function_exists('imagewebp');
    $uploaded = [];
    foreach ($list as $i => $f) {
        $name = $f['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($ext, EVIDENCE_IMAGE_EXTS, true)) {
            $mime = evidence_image_mime($f['tmp_name']);
            $real = EVIDENCE_IMAGE_MIMES[$mime];              // ตั้งนามสกุลตามเนื้อไฟล์จริง
            if ($gd) {
                $fn = 'ev_' . $tag . '_' . time() . '_' . $i . '.webp';
                $up = $dir . $fn;
                if ($mover($f['tmp_name'], $up) && processEvidenceImage($up, $up, $real)) {
                    $ins->execute([$type, $id, $fn, 'image/webp', $name, $uid]);
                    $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $fn, 'type' => 'image'];
                }
            } else {
                $fn = 'ev_' . $tag . '_' . time() . '_' . $i . '.' . $real;
                $up = $dir . $fn;
                if ($mover($f['tmp_name'], $up)) {
                    $ins->execute([$type, $id, $fn, $mime, $name, $uid]);
                    $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $fn, 'type' => 'image'];
                }
            }
        } else {
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
            $fn = 'docs/doc_' . $tag . '_' . time() . '_' . $i . '_' . $safe . '.' . $ext;
            if ($mover($f['tmp_name'], $dir . $fn)) {
                $ins->execute([$type, $id, $fn, EVIDENCE_DOC_MIMES[$ext], $name, $uid]);
                $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $fn, 'type' => 'document'];
            }
        }
    }
    if (!$uploaded) throw new Exception('บันทึกไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    return $uploaded;
}
