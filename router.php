<?php
/**
 * ROUTER.PHP
 * จัดการการกำหนดเส้นทางตามบทบาท (Role-based Routing)
 * เพื่อคัดแยกผู้ใช้งาน (user) และผู้ดูแลส่วนกลาง (admin) ให้ชัดเจน
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ถ้ายังไม่ได้ล็อกอินหรือ session หาย ให้ไปหน้า login ตรง ๆ
// (ถอดหน้า landing 3D ออกจากทางเข้าแล้ว — ไฟล์ landing.php ยังเก็บไว้ เปิดใช้ใหม่ได้โดยเปลี่ยนกลับเป็น 'landing.php')
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$role = $_SESSION['role'];

// ── กฎการกำหนดเส้นทาง (Routing Rules) ──

if ($role === 'admin') {
    // ผู้ดูแลระบบ: แดชบอร์ดส่วนกลาง
    header('Location: admin/');
    exit;
} elseif ($role === 'dean') {
    // บุคลากร/คณบดี: โซนดูอย่างเดียว + รายงาน
    header('Location: dean/');
    exit;
} elseif ($role === 'officer') {
    // เจ้าหน้าที่บันทึกข้อมูล: โซนกรอกข้อมูลของคณะ
    header('Location: officer/');
    exit;
} else {
    // สิทธิ์ไม่ถูกต้อง ลบ session ทิ้งแล้วกลับไปหน้าล็อกอิน
    session_destroy();
    header('Location: login.php?error=invalid_role');
    exit;
}
