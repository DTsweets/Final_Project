<?php
/**
 * OFFICER — ③ กรอกปริมาณ (หน้าจริงอยู่ที่ includes/entry_items_page.php ใช้ร่วม officer / admin)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['officer']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
$ENTRY_ADMIN = false;
require __DIR__ . '/../includes/entry_items_page.php';
