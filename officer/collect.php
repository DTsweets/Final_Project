<?php
/**
 * OFFICER — กรอกข้อมูล (3 แท็บ: นักศึกษา/บุคลากร/กิจกรรม) : เฉพาะคณะตนเอง
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['officer']);

$pdo  = getDB();
$root = '../';
$is_admin   = false;
$lock_affil = (int) ($_SESSION['affiliation_id'] ?? 0);
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
require __DIR__ . '/../includes/collect_page.php';
