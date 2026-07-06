<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$root = '../';
$page_title = "กรอกข้อมูล";
$page_title2 = "GHG Removal";
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>กำลังพัฒนา - UP Net Zero</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600&family=Inter:wght@400;500;600&family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root ?>assets/css/admin.css">
    <link rel="stylesheet" href="<?= $root ?>assets/css/sidebar.css">
</head>

<body style="background-color: #F9FAFB;">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <div class="content-wrapper" style="padding: 40px;">
            <div
                style="background: #FFFFFF; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); padding: 40px; border: 1px solid #E5E7EB;">
                <div class="dev-container">
                    <!-- Construction / Hammer icon -->
                    <svg class="dev-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.83-5.83M15.17 11.42L8.83 5.08A2.652 2.652 0 005.08 8.83l6.34 6.34M15 9l-6 6" />
                    </svg>
                    <div class="dev-title">ระบบกำลังอยู่ในช่วงพัฒนา</div>
                    <div class="dev-desc">หน้านี้กำลังถูกสร้างและร้อยเรียงระบบให้สมบูรณ์
                        เพื่อเตรียมพร้อมสำหรับการใช้งานอย่างเต็มรูปแบบในเร็วๆ นี้ครับ!</div>
                </div>
            </div>
        </div>
    </main>
</body>

</html>