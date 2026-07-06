<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin']);

$root = '../';
$page_title = "กรอกข้อมูล";
$page_title2 = "การคำนวณกิจกรรม";
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>กำลังพัฒนา - UP Net Zero</title>
    <link rel="stylesheet" href="<?= $root ?>assets/css/user-new.css">
    <style>
        .dev-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 70vh;
            text-align: center;
            animation: fadeIn 0.5s ease;
        }

        .dev-icon {
            width: 120px;
            height: 120px;
            color: #D3B79C;
            margin-bottom: 20px;
        }

        .dev-title {
            font-size: 28px;
            font-weight: 600;
            color: #62368B;
            margin-bottom: 10px;
            font-family: 'Kanit', sans-serif;
        }

        .dev-desc {
            font-size: 16px;
            color: #6B7280;
            max-width: 400px;
            line-height: 1.6;
            font-family: 'Kanit', sans-serif;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body style="background-color: #F9FAFB;">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <?php include __DIR__ . '/includes/header.php'; ?>

        <div class="content-wrapper" style="padding: 40px;">
            <div
                style="background: #FFFFFF; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); padding: 40px; border: 1px solid #E5E7EB;">
                <div class="dev-container">
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