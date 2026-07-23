<?php
/**
 * API จัดการหลักฐาน (Evidence) — รองรับทั้ง "ไฟล์" และ "ลิงก์" หลายอัน
 * ผูกได้หลาย entity: user_item (กรอกขอบเขต) | questionnaire (แบบสอบถาม) | event (กิจกรรม)
 *
 * actions: list | upload | add_link | delete | delete_all
 * entity: ส่ง entity_type + entity_id  (legacy: admin_item_id + year_id → user_item)
 * สิทธิ์: admin = ทุกคณะ · officer = เฉพาะ entity ของคณะตัวเอง
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_role(['admin', 'officer']);
header('Content-Type: application/json');

$pdo = getDB();
$action         = $_GET['action'] ?? '';
$is_admin       = (($_SESSION['role'] ?? '') === 'admin');
$affiliation_id = (int) ($_SESSION['affiliation_id'] ?? 0);
$uid            = $_SESSION['user_id'] ?? null;

/** คืน affiliation_id เจ้าของ entity (null = ไม่พบ) */
function entity_owner_affil(PDO $pdo, string $type, int $id): ?int
{
    $sql = [
        'user_item'     => "SELECT affiliation_id FROM user_item WHERE id=?",
        'questionnaire' => "SELECT affiliation_id FROM questionnaire WHERE id=?",
        'event'         => "SELECT affiliation_id FROM event WHERE id=?",
    ][$type] ?? null;
    if (!$sql) return null;
    $s = $pdo->prepare($sql); $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === false ? null : (int) $v;
}

/**
 * แปลง request → [entity_type, entity_id]
 * รองรับ legacy (admin_item_id + year_id → user_item; สร้าง user_item ถ้ายังไม่มี เมื่อ $forUpload)
 * พร้อมตรวจสิทธิ์เจ้าของ (officer = คณะตัวเอง)
 */
function resolve_entity(PDO $pdo, bool $forUpload, bool $is_admin, int $affiliation_id): array
{
    $type = $_REQUEST['entity_type'] ?? '';

    // ── legacy: scope entry ส่ง admin_item_id + year_id ──
    if ($type === '') {
        $aid = (int) ($_REQUEST['admin_item_id'] ?? 0);
        $yid = (int) ($_REQUEST['year_id'] ?? 0);
        if (!$aid || !$yid) throw new Exception('Missing entity parameters');
        $st = $pdo->prepare("SELECT id FROM user_item WHERE admin_item_id=? AND affiliation_id=? AND year_id=?");
        $st->execute([$aid, $affiliation_id, $yid]);
        $ui = $st->fetchColumn();
        if (!$ui) {
            if (!$forUpload) return ['user_item', 0];   // ยังไม่มีรายการ → ไม่มีหลักฐาน
            $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year) VALUES (?,?,?,0,CURDATE())")
                ->execute([$aid, $affiliation_id, $yid]);
            $ui = $pdo->lastInsertId();
        }
        return ['user_item', (int) $ui];
    }

    // ── entity ตรง ──
    $id = (int) ($_REQUEST['entity_id'] ?? 0);
    if (!in_array($type, ['user_item', 'questionnaire', 'event'], true) || !$id)
        throw new Exception('Invalid entity');

    // ตรวจสิทธิ์เจ้าของ (officer เท่านั้น; admin ผ่านหมด)
    if (!$is_admin) {
        $owner = entity_owner_affil($pdo, $type, $id);
        if ($owner === null) throw new Exception('ไม่พบรายการ');
        if ($owner !== $affiliation_id) throw new Exception('ไม่มีสิทธิ์');
    }
    return [$type, $id];
}

/** ตรวจว่า evidence row นี้เป็นของ entity ที่ officer มีสิทธิ์ไหม (สำหรับ delete) */
function assert_evidence_owned(PDO $pdo, array $ev, bool $is_admin, int $affiliation_id): void
{
    if ($is_admin) return;
    $owner = entity_owner_affil($pdo, $ev['entity_type'], (int) $ev['entity_id']);
    if ($owner === null || $owner !== $affiliation_id) throw new Exception('ไม่มีสิทธิ์');
}

function evidence_dir(): string { return __DIR__ . '/../../assets/images/evidence/'; }

/** ย่อ + แปลงเป็น WebP */
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

try {
    switch ($action) {

        // ── List ────────────────────────────────────────────────────────────
        case 'list': {
            [$type, $id] = resolve_entity($pdo, false, $is_admin, $affiliation_id);
            if (!$id) { echo json_encode(['success' => true, 'data' => []]); break; }
            $stmt = $pdo->prepare("SELECT id, kind, file_path, file_type, original_name, url, label, created_at
                                   FROM evidence WHERE entity_type=? AND entity_id=? ORDER BY created_at DESC");
            $stmt->execute([$type, $id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;
        }

        // ── Upload (ไฟล์: images[] หรือ documents[]) ─────────────────────────
        case 'upload': {
            [$type, $id] = resolve_entity($pdo, true, $is_admin, $affiliation_id);
            $file_key = isset($_FILES['images']) ? 'images' : (isset($_FILES['documents']) ? 'documents' : null);
            if (!$file_key) throw new Exception('No files uploaded');
            $files = $_FILES[$file_key];

            $img_dir = evidence_dir(); $doc_dir = $img_dir . 'docs/';
            if (!is_dir($img_dir)) mkdir($img_dir, 0777, true);
            if (!is_dir($doc_dir)) mkdir($doc_dir, 0777, true);

            $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $doc_exts   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
            $mime_map   = [
                'pdf' => 'application/pdf', 'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ];
            $ins = $pdo->prepare("INSERT INTO evidence (entity_type,entity_id,kind,file_path,file_type,original_name,created_by)
                                  VALUES (?,?,'file',?,?,?,?)");
            $uploaded = [];
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp = $files['tmp_name'][$i];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $tag = $type . '_' . $id;

                if (in_array($ext, $image_exts)) {
                    $gd = function_exists('imagecreatetruecolor') && function_exists('imagewebp');
                    if ($gd) {
                        $fn = 'ev_' . $tag . '_' . time() . '_' . $i . '.webp';
                        $up = $img_dir . $fn;
                        if (move_uploaded_file($tmp, $up) && processEvidenceImage($up, $up, $ext)) {
                            $ins->execute([$type, $id, $fn, 'image/webp', $name, $uid]);
                            $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $fn, 'type' => 'image'];
                        }
                    } else {
                        $fn = 'ev_' . $tag . '_' . time() . '_' . $i . '.' . $ext;
                        $up = $img_dir . $fn;
                        if (move_uploaded_file($tmp, $up)) {
                            $mime = ($ext === 'jpg') ? 'image/jpeg' : 'image/' . $ext;
                            $ins->execute([$type, $id, $fn, $mime, $name, $uid]);
                            $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $fn, 'type' => 'image'];
                        }
                    }
                } elseif (in_array($ext, $doc_exts)) {
                    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                    $fn = 'docs/doc_' . $tag . '_' . time() . '_' . $i . '_' . $safe . '.' . $ext;
                    $up = $img_dir . $fn;
                    if (move_uploaded_file($tmp, $up)) {
                        $ins->execute([$type, $id, $fn, $mime_map[$ext] ?? 'application/octet-stream', $name, $uid]);
                        $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $fn, 'type' => 'document'];
                    }
                }
            }
            echo json_encode(['success' => true, 'uploaded' => $uploaded, 'entity_type' => $type, 'entity_id' => $id]);
            break;
        }

        // ── Add link ─────────────────────────────────────────────────────────
        case 'add_link': {
            [$type, $id] = resolve_entity($pdo, true, $is_admin, $affiliation_id);
            $url   = trim((string) ($_POST['url'] ?? ''));
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($url === '') throw new Exception('กรุณาระบุลิงก์');
            if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;   // เติม scheme ให้
            if (!filter_var($url, FILTER_VALIDATE_URL)) throw new Exception('รูปแบบลิงก์ไม่ถูกต้อง');
            if (mb_strlen($url) > 1000) throw new Exception('ลิงก์ยาวเกินไป');
            $stmt = $pdo->prepare("INSERT INTO evidence (entity_type,entity_id,kind,url,label,created_by) VALUES (?,?,'link',?,?,?)");
            $stmt->execute([$type, $id, $url, ($label !== '' ? $label : null), $uid]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'url' => $url, 'label' => $label]);
            break;
        }

        // ── Delete single ─────────────────────────────────────────────────────
        case 'delete': {
            $eid = (int) ($_POST['evidence_id'] ?? 0);
            if (!$eid) throw new Exception('Invalid Evidence ID');
            $stmt = $pdo->prepare("SELECT id, entity_type, entity_id, kind, file_path FROM evidence WHERE id=?");
            $stmt->execute([$eid]); $ev = $stmt->fetch();
            if (!$ev) throw new Exception('Evidence not found');
            assert_evidence_owned($pdo, $ev, $is_admin, $affiliation_id);
            if ($ev['kind'] === 'file' && !empty($ev['file_path'])) {
                $base = realpath(evidence_dir());
                if ($base) {
                    $fp = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ev['file_path']);
                    if (file_exists($fp)) @unlink($fp);
                }
            }
            $pdo->prepare("DELETE FROM evidence WHERE id=?")->execute([$eid]);
            echo json_encode(['success' => true]);
            break;
        }

        // ── Delete all for an entity ───────────────────────────────────────────
        case 'delete_all': {
            [$type, $id] = resolve_entity($pdo, false, $is_admin, $affiliation_id);
            if ($id) {
                $stmt = $pdo->prepare("SELECT file_path FROM evidence WHERE entity_type=? AND entity_id=? AND kind='file'");
                $stmt->execute([$type, $id]);
                $base = realpath(evidence_dir());
                foreach ($stmt->fetchAll() as $f) {
                    if ($base && !empty($f['file_path'])) {
                        $fp = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f['file_path']);
                        if (file_exists($fp)) @unlink($fp);
                    }
                }
                $pdo->prepare("DELETE FROM evidence WHERE entity_type=? AND entity_id=?")->execute([$type, $id]);
            }
            echo json_encode(['success' => true]);
            break;
        }

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
