<?php
// includes/auth.php

// ฟังก์ชันตรวจสอบว่าผู้ใช้ล็อกอินอยู่หรือไม่
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// ฟังก์ชันตรวจสอบว่าผู้ใช้มีบทบาทเป็น admin หรือไม่
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

// ฟังก์ชันตรวจสอบว่าผู้ใช้มีบทบาทเป็น user หรือไม่
function isUser() {
    return isLoggedIn() && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'user';
}

// ฟังก์ชันสำหรับ redirect ไปยังหน้า login หากยังไม่ได้ล็อกอิน
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

// ฟังก์ชันสำหรับ redirect ไปยังหน้า login หากไม่มีสิทธิ์ admin
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ../login.php'); // หรือหน้าแจ้งเตือนสิทธิ์ไม่พอ
        exit();
    }
}

// ฟังก์ชันสำหรับ redirect ไปยังหน้า login หากไม่มีสิทธิ์ user
function requireUser() {
    if (!isUser()) {
        header('Location: ../login.php'); // หรือหน้าแจ้งเตือนสิทธิ์ไม่พอ
        exit();
    }
}
?>