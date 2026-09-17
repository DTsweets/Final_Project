<?php
/**
 * ADMIN — ② เลือกหมวด (หน้าจริงอยู่ที่ includes/entry_groups_page.php ใช้ร่วม officer / admin)
 * admin เลือกหน่วยงานที่จะกรอกได้ (?affil=)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
$ENTRY_ADMIN = true;
require __DIR__ . '/../includes/entry_groups_page.php';
