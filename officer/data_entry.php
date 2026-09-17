<?php
/**
 * OFFICER — ② เลือกหมวด (หน้าจริงอยู่ที่ includes/entry_groups_page.php ใช้ร่วม officer / admin)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['officer']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
$ENTRY_ADMIN = false;
require __DIR__ . '/../includes/entry_groups_page.php';
