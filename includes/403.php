<?php
/**
 * 403 Forbidden Page
 * -------------------
 * แสดงเมื่อ user ไม่มีสิทธิ์เข้าถึงหน้านั้น
 */
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — ไม่มีสิทธิ์เข้าถึง</title>
    <link rel="stylesheet" href="<?= str_repeat('../', substr_count($_SERVER['PHP_SELF'], '/') - 2) ?>assets/css/login.css">
    <style>
        .error-card { text-align: center; }
        .error-code {
            font-size: 6rem; font-weight: 900; line-height: 1;
            background: linear-gradient(135deg, #ef4444, #f97316);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            margin-bottom: 0.5rem;
        }
        .error-title { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.75rem; }
        .error-desc  { color: rgba(248,250,252,0.6); margin-bottom: 2rem; font-size: 0.9rem; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 10px; color: #fff; text-decoration: none;
            font-weight: 600; font-size: 0.9rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-back:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(79,70,229,0.4); }
    </style>
</head>
<body>
<div class="bg-canvas"></div>
<div class="login-wrapper">
    <div class="login-card error-card">
        <div class="error-code">403</div>
        <h1 class="error-title">ไม่มีสิทธิ์เข้าถึง</h1>
        <p class="error-desc">คุณไม่มีสิทธิ์เข้าถึงหน้านี้<br>กรุณาติดต่อผู้ดูแลระบบ</p>
        <a href="javascript:history.back()" class="btn-back">
            <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
            </svg>
            ย้อนกลับ
        </a>
    </div>
</div>
</body>
</html>
