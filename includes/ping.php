<?php
/**
 * Session Ping — ต่ออายุ session ผ่าน AJAX
 * ------------------------------------------
 * เรียกจาก session-timer.js เมื่อ user กด "ต่ออายุ Session"
 */

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'remaining' => 0]);
    exit;
}

// อัพเดทเวลา last_activity
$_SESSION['last_activity'] = time();

echo json_encode([
    'success'   => true,
    'remaining' => SESSION_TIMEOUT,
]);
exit;
