<?php
/**
 * LOGOUT
 * ------
 * ทำลาย session และ redirect กลับ login.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$timeout = isset($_GET['timeout']) && $_GET['timeout'] === '1';

// ลบ session data
$_SESSION = [];

// ลบ session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// กำหนด root path สำหรับ redirect (รองรับ subdirectory)
$root = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

header('Location: ' . $root . '/login.php' . ($timeout ? '?timeout=1' : ''));
exit;
