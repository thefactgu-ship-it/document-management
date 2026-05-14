<?php
// logout.php
// ทำลาย session เพื่อออกจากระบบ

session_start();

// ลบตัวแปร session ทั้งหมด
$_SESSION = array();

// ถ้ามีการใช้ cookies สำหรับ session ก็ลบ cookie นั้นทิ้ง
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// ทำลาย session
session_destroy();

// Redirect ไปยังหน้า Login
header('Location: login.php');
exit();
?>