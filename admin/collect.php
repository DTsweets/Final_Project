<?php
/**
 * ADMIN — กรอกข้อมูล (3 แท็บ: นักศึกษา/บุคลากร/กิจกรรม) : ทุกคณะ
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$pdo  = getDB();
$root = '../';
$is_admin   = true;
$lock_affil = null;
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
require __DIR__ . '/../includes/collect_page.php';
