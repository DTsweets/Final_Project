<?php
/**
 * OFFICER (ศูนย์สิ่งแวดล้อม เท่านั้น) — GHG Removal (การดูดกลับ) ระดับส่วนกลาง
 * เฉพาะ officer ของ affiliation_id = 1; role อื่น/คณะอื่น → 403
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['officer']);

// gate: เฉพาะศูนย์สิ่งแวดล้อม (affil 1)
if ((int) ($_SESSION['affiliation_id'] ?? 0) !== 1) {
    http_response_code(403);
    require __DIR__ . '/../includes/403.php';
    exit;
}

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
require __DIR__ . '/../includes/removal_page.php';
