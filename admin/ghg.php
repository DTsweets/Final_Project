<?php
/**
 * ADMIN — GHG Removal (การดูดกลับ) : จัดการรายการ + กรอกปริมาณ (ระดับส่วนกลาง)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
require __DIR__ . '/../includes/removal_page.php';
