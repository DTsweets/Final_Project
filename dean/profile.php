<?php
/**
 * DEAN — โปรไฟล์ส่วนตัว (หน้าจริงอยู่ที่ includes/profile_page.php ใช้ร่วมทุก role)
 * โซน dean ไม่มีแถบหัว (header) → $HEADER = null
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['dean']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = null;
require __DIR__ . '/../includes/profile_page.php';
