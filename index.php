<?php
/**
 * Root index.php — URL Masking Wrapper
 * ใช้ Iframe เพื่อล็อก URL ให้อยู่ที่ http://localhost:3000 ตลอดเวลา
 */
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UP Net Zero</title>
    <style>
        body, html {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden; /* ป้องกัน scrollbar ซ้อนกัน */
            background-color: #111; /* สีพื้นหลังระหว่างโหลด iframe */
        }
        iframe {
            border: none;
            width: 100%;
            height: 100%;
            display: block;
        }
    </style>
</head>
<body>
    <!-- โหลด router.php เพื่อจัดการสิทธิ์และการนำทางภายใน Iframe -->
    <iframe src="router.php" title="UP Net Zero Application"></iframe>
</body>
</html>
