<?php
/**
 * ADMIN — โปรไฟล์ส่วนตัว (หน้าจริงอยู่ที่ includes/profile_page.php ใช้ร่วมทุก role)
 * เดิมใช้ require_login() — ผู้ใช้ role อื่นเปิดหน้านี้ได้ (พร้อมเมนูของ admin) → จำกัดเฉพาะ admin
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
require __DIR__ . '/../includes/profile_page.php';
