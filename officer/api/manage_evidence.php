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
require_once __DIR__ . '/../../includes/evidence_upload.php';

require_role(['admin', 'officer']);
header('Content-Type: application/json');

$pdo = getDB();
$action         = $_GET['action'] ?? '';
$is_admin       = (($_SESSION['role'] ?? '') === 'admin');
$affiliation_id = (int) ($_SESSION['affiliation_id'] ?? 0);
$uid            = $_SESSION['user_id'] ?? null;

// คำสั่งที่เขียนข้อมูลต้องเป็น POST (require_role ตรวจ CSRF token ของ POST แล้ว)
// เดิมรับ GET ได้ทุกคำสั่ง: กดลิงก์ ?action=delete_all&entity_type=…&entity_id=… ก็ลบหลักฐานทั้งหมดได้
if ($action !== 'list' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'คำสั่งนี้ต้องส่งแบบ POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

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
 * รองรับ legacy (admin_item_id + year_id → user_item ของรายการ officer ปีนั้น; สร้าง user_item ถ้ายังไม่มี เมื่อ $forUpload — ผู้เรียกต้องเปิด transaction)
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
        // ต้องเป็นรายการการดำเนินงาน (officer) ของปีนั้นจริง — เดิมไม่ตรวจ: ส่ง id ปีอื่น / รายการแบบสอบถาม ก็สร้างแถว officer ได้
        $chk = $pdo->prepare("SELECT 1 FROM admin_item WHERE id=? AND year_id=? AND data_source='officer'");
        $chk->execute([$aid, $yid]);
        if (!$chk->fetchColumn() || $affiliation_id <= 0) throw new Exception('ไม่พบรายการ');
        $st = $pdo->prepare("SELECT id FROM user_item WHERE admin_item_id=? AND affiliation_id=? AND year_id=? AND source='officer'");
        $st->execute([$aid, $affiliation_id, $yid]);
        $ui = $st->fetchColumn();
        if (!$ui) {
            if (!$forUpload) return ['user_item', 0];   // ยังไม่มีรายการ → ไม่มีหลักฐาน
            // สร้างใน transaction ของคำสั่ง (upload / add_link) — ไฟล์/ลิงก์ไม่ผ่าน → rollback แถวนี้ด้วย ไม่มีแถว Vol 0 ค้าง
            $pdo->prepare("INSERT INTO user_item (admin_item_id,affiliation_id,year_id,Vol,create_year,source) VALUES (?,?,?,0,CURDATE(),'officer')")
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
        // ตรวจขนาด / ชนิดจริง / จำนวน ใน includes/evidence_upload.php — มีไฟล์ไม่ผ่านแม้ไฟล์เดียว → ไม่บันทึกทั้งชุด
        case 'upload': {
            // POST ใหญ่เกิน post_max_size → PHP ทิ้งทั้ง $_POST และ $_FILES (entity หายด้วย) ต้องเช็คก่อน resolve_entity
            if (!$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > evidence_post_max_bytes())
                throw new Exception('ไฟล์รวมใหญ่เกิน ' . round(evidence_post_max_bytes() / 1048576) . ' MB ต่อครั้ง กรุณาแบ่งอัปโหลด');
            $file_key = isset($_FILES['images']) ? 'images' : (isset($_FILES['documents']) ? 'documents' : null);
            if (!$file_key) throw new Exception('ไม่พบไฟล์ที่อัปโหลด');
            $pdo->beginTransaction();   // แบบเก่าอาจสร้าง user_item — ไฟล์ไม่ผ่าน → rollback ใน catch
            [$type, $id] = resolve_entity($pdo, true, $is_admin, $affiliation_id);
            $uploaded = evidence_save_uploads($pdo, $type, $id, $_FILES[$file_key], $uid, evidence_dir(), 'move_uploaded_file');
            $pdo->commit();
            echo json_encode(['success' => true, 'uploaded' => $uploaded, 'entity_type' => $type, 'entity_id' => $id]);
            break;
        }

        // ── Add link ─────────────────────────────────────────────────────────
        case 'add_link': {
            $pdo->beginTransaction();   // แบบเก่าอาจสร้าง user_item — ลิงก์ไม่ผ่าน → rollback ใน catch
            [$type, $id] = resolve_entity($pdo, true, $is_admin, $affiliation_id);
            $url   = trim((string) ($_POST['url'] ?? ''));
            $label = trim((string) ($_POST['label'] ?? ''));
            if ($url === '') throw new Exception('กรุณาระบุลิงก์');
            if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;   // เติม scheme ให้
            if (!filter_var($url, FILTER_VALIDATE_URL)) throw new Exception('รูปแบบลิงก์ไม่ถูกต้อง');
            if (mb_strlen($url) > 1000) throw new Exception('ลิงก์ยาวเกินไป');
            $stmt = $pdo->prepare("INSERT INTO evidence (entity_type,entity_id,kind,url,label,created_by) VALUES (?,?,'link',?,?,?)");
            $stmt->execute([$type, $id, $url, ($label !== '' ? $label : null), $uid]);
            $newId = $pdo->lastInsertId();
            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $newId, 'url' => $url, 'label' => $label]);
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
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => safe_error_message($e)]);
}
