<?php
/**
 * ADMIN — ③ กรอกปริมาณ (หน้าจริงอยู่ที่ includes/entry_items_page.php ใช้ร่วม officer / admin)
 * admin เลือกหน่วยงาน + เพิ่ม/แก้/ลบรายการ Emission Factor · เดิมบันทึกไม่ได้เลย (อ่าน session ผิดคีย์ + SQL พารามิเตอร์ซ้ำ)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$pdo  = getDB();
$root = '../';
$SIDEBAR = __DIR__ . '/includes/sidebar.php';
$HEADER  = __DIR__ . '/includes/header.php';
$ENTRY_ADMIN = true;
require __DIR__ . '/../includes/entry_items_page.php';
