<?php
/**
 * API for Managing Activity Evidence (Images + Documents)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_role(['officer']);

header('Content-Type: application/json');

$pdo = getDB();
$action = $_GET['action'] ?? '';
$affiliation_id = (int)($_SESSION['affiliation_id'] ?? 0);

/**
 * Utility: Resize and convert to WebP
 */
function processEvidenceImage($sourcePath, $targetPath, $inputExt) {
    if (!file_exists($sourcePath)) return false;
    list($origWidth, $origHeight) = @getimagesize($sourcePath);
    if (!$origWidth || !$origHeight) return false;

    $maxWidth = 1200;
    $maxHeight = 1200;
    $ratio = min($maxWidth / $origWidth, $maxHeight / $origHeight);
    $newWidth  = ($ratio >= 1) ? $origWidth  : (int)($origWidth  * $ratio);
    $newHeight = ($ratio >= 1) ? $origHeight : (int)($origHeight * $ratio);

    $imageP = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($imageP, false);
    imagesavealpha($imageP, true);
    $transparent = imagecolorallocatealpha($imageP, 255, 255, 255, 127);
    imagefilledrectangle($imageP, 0, 0, $newWidth, $newHeight, $transparent);

    switch (strtolower($inputExt)) {
        case 'jpeg': case 'jpg': $image = @imagecreatefromjpeg($sourcePath); break;
        case 'png':  $image = @imagecreatefrompng($sourcePath);  break;
        case 'gif':  $image = @imagecreatefromgif($sourcePath);  break;
        case 'webp': $image = @imagecreatefromwebp($sourcePath); break;
        default: return false;
    }
    if (!$image) return false;

    imagecopyresampled($imageP, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    $success = imagewebp($imageP, $targetPath, 80);
    imagedestroy($imageP);
    imagedestroy($image);
    return $success;
}

try {
    switch ($action) {

        // ── List ────────────────────────────────────────────────────────────
        case 'list':
            $admin_item_id_l = (int)($_GET['admin_item_id'] ?? 0);
            $year_id_l       = (int)($_GET['year_id']       ?? 0);
            if (!$admin_item_id_l || !$year_id_l) throw new Exception("Missing parameters for list");

            $stmt = $pdo->prepare("SELECT id FROM user_item WHERE admin_item_id = ? AND affiliation_id = ? AND year_id = ?");
            $stmt->execute([$admin_item_id_l, $affiliation_id, $year_id_l]);
            $user_item_l = $stmt->fetch();

            if (!$user_item_l) {
                echo json_encode(['success' => true, 'data' => []]);
                break;
            }

            $stmt = $pdo->prepare("SELECT id, file_path, file_type, original_name, created_at FROM user_item_evidence WHERE user_item_id = ? ORDER BY created_at DESC");
            $stmt->execute([$user_item_l['id']]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        // ── Upload (images OR documents) ────────────────────────────────────
        case 'upload':
            $admin_item_id = (int)($_POST['admin_item_id'] ?? 0);
            $year_id       = (int)($_POST['year_id']       ?? 0);
            if (!$admin_item_id || !$year_id) throw new Exception("Missing parameters");

            // 1. Find or create user_item
            $stmt = $pdo->prepare("SELECT id FROM user_item WHERE admin_item_id = ? AND affiliation_id = ? AND year_id = ?");
            $stmt->execute([$admin_item_id, $affiliation_id, $year_id]);
            $user_item = $stmt->fetch();

            if (!$user_item) {
                $stmt = $pdo->prepare("INSERT INTO user_item (admin_item_id, affiliation_id, year_id, Vol, create_year) VALUES (?, ?, ?, 0, CURDATE())");
                $stmt->execute([$admin_item_id, $affiliation_id, $year_id]);
                $user_item_id = $pdo->lastInsertId();
            } else {
                $user_item_id = $user_item['id'];
            }

            // 2. Detect file key: images[] or documents[]
            $file_key = isset($_FILES['images']) ? 'images' : (isset($_FILES['documents']) ? 'documents' : null);
            if (!$file_key) throw new Exception("No files uploaded");

            $files   = $_FILES[$file_key];
            $uploaded = [];
            $img_dir  = __DIR__ . '/../../assets/images/evidence/';
            $doc_dir  = $img_dir . 'docs/';
            if (!is_dir($img_dir)) mkdir($img_dir, 0777, true);
            if (!is_dir($doc_dir)) mkdir($doc_dir, 0777, true);

            $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $doc_exts   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
            $mime_map   = [
                'pdf'  => 'application/pdf',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls'  => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt'  => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ];

            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                $tmp_name = $files['tmp_name'][$i];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if (in_array($ext, $image_exts)) {
                    // --- Image upload ---
                    $gd_ok = function_exists('imagecreatetruecolor') && function_exists('imagewebp');

                    if ($gd_ok) {
                        // มี GD → ย่อ + แปลงเป็น WebP
                        $new_filename = 'ev_' . $user_item_id . '_' . time() . '_' . $i . '.webp';
                        $upload_path  = $img_dir . $new_filename;
                        if (move_uploaded_file($tmp_name, $upload_path)) {
                            if (processEvidenceImage($upload_path, $upload_path, $ext)) {
                                $stmt = $pdo->prepare("INSERT INTO user_item_evidence (user_item_id, file_path, file_type, original_name) VALUES (?, ?, ?, ?)");
                                $stmt->execute([$user_item_id, $new_filename, 'image/webp', $name]);
                                $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $new_filename, 'type' => 'image'];
                            }
                        }
                    } else {
                        // ไม่มี GD → เก็บไฟล์ต้นฉบับ (ไม่ย่อ/ไม่แปลง) เพื่อให้อัปโหลดได้
                        $new_filename = 'ev_' . $user_item_id . '_' . time() . '_' . $i . '.' . $ext;
                        $upload_path  = $img_dir . $new_filename;
                        if (move_uploaded_file($tmp_name, $upload_path)) {
                            $img_mime = ($ext === 'jpg') ? 'image/jpeg' : 'image/' . $ext;
                            $stmt = $pdo->prepare("INSERT INTO user_item_evidence (user_item_id, file_path, file_type, original_name) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$user_item_id, $new_filename, $img_mime, $name]);
                            $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $new_filename, 'type' => 'image'];
                        }
                    }

                } elseif (in_array($ext, $doc_exts)) {
                    // --- Document upload ---
                    $safe_name    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
                    $new_filename = 'docs/doc_' . $user_item_id . '_' . time() . '_' . $i . '_' . $safe_name . '.' . $ext;
                    $upload_path  = $img_dir . $new_filename;

                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        $mime = $mime_map[$ext] ?? 'application/octet-stream';
                        $stmt = $pdo->prepare("INSERT INTO user_item_evidence (user_item_id, file_path, file_type, original_name) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$user_item_id, $new_filename, $mime, $name]);
                        $uploaded[] = ['id' => $pdo->lastInsertId(), 'path' => $new_filename, 'type' => 'document'];
                    }
                }
            }

            echo json_encode(['success' => true, 'uploaded' => $uploaded, 'user_item_id' => $user_item_id]);
            break;

        // ── Delete single ───────────────────────────────────────────────────
        case 'delete':
            $evidence_id = (int)($_POST['evidence_id'] ?? 0);
            if (!$evidence_id) throw new Exception("Invalid Evidence ID");

            $stmt = $pdo->prepare("SELECT e.id, e.file_path FROM user_item_evidence e
                                   JOIN user_item i ON i.id = e.user_item_id
                                   WHERE e.id = ? AND i.affiliation_id = ?");
            $stmt->execute([$evidence_id, $affiliation_id]);
            $evidence = $stmt->fetch();

            if ($evidence) {
                $base_dir = realpath(__DIR__ . '/../../assets/images/evidence');
                if ($base_dir) {
                    $fp = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $evidence['file_path']);
                    if (file_exists($fp)) {
                        @unlink($fp);
                    }
                }
                $pdo->prepare("DELETE FROM user_item_evidence WHERE id = ?")->execute([$evidence_id]);
                echo json_encode(['success' => true]);
            } else {
                throw new Exception("Evidence not found or access denied");
            }
            break;

        // ── Delete all for a user_item ──────────────────────────────────────
        case 'delete_all':
            $admin_item_id_da = (int)($_POST['admin_item_id'] ?? 0);
            $year_id_da       = (int)($_POST['year_id']       ?? 0);
            if (!$admin_item_id_da || !$year_id_da) throw new Exception("Missing parameters");

            $stmt = $pdo->prepare("SELECT id FROM user_item WHERE admin_item_id = ? AND affiliation_id = ? AND year_id = ?");
            $stmt->execute([$admin_item_id_da, $affiliation_id, $year_id_da]);
            $user_item_da = $stmt->fetch();

            if ($user_item_da) {
                $stmt = $pdo->prepare("SELECT file_path FROM user_item_evidence WHERE user_item_id = ?");
                $stmt->execute([$user_item_da['id']]);
                $base_dir = realpath(__DIR__ . '/../../assets/images/evidence');
                foreach ($stmt->fetchAll() as $f) {
                    if ($base_dir) {
                        $fp = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $f['file_path']);
                        if (file_exists($fp)) {
                            @unlink($fp);
                        }
                    }
                }
                $pdo->prepare("DELETE FROM user_item_evidence WHERE user_item_id = ?")->execute([$user_item_da['id']]);
            }
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception("Invalid action");
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
