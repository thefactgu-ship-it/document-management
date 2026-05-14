<?php
// config/database.php

// กำหนดค่าคงที่สำหรับเชื่อมต่อฐานข้อมูล
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // เปลี่ยนตามชื่อผู้ใช้ MySQL ของคุณ
define('DB_PASSWORD', '');     // เปลี่ยนตามรหัสผ่าน MySQL ของคุณ
define('DB_NAME', 'official_document_system'); // ชื่อฐานข้อมูลที่คุณสร้าง

// กำหนดค่าคงที่ LINE API
define('LINE_CHANNEL_ACCESS_TOKEN', getSystemSetting('line_channel_access_token', ''));
define('LINE_CHANNEL_SECRET', getSystemSetting('line_channel_secret', ''));


// ฟังก์ชันสำหรับดึงค่าการตั้งค่าจากตาราง system_settings
// ควรถูกเรียกก่อนใช้ค่าคงที่ที่อิงจาก DB
function getSystemSetting($key, $default_value = null) {
    // การสร้าง connection ใหม่ทุกครั้งสำหรับ getSystemSetting อาจไม่มีประสิทธิภาพ
    // ในระบบ Production ควรมี mechanism cache หรือเรียกใช้ครั้งเดียว
    // สำหรับตอนนี้เราจะทำแบบนี้ไปก่อน
    $temp_conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($temp_conn->connect_error) {
        error_log("Connection failed for system settings: " . $temp_conn->connect_error);
        return $default_value;
    }
    $temp_conn->set_charset("utf8mb4");

    $stmt = $temp_conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $value = $row['setting_value'];
            $stmt->close();
            $temp_conn->close();
            return $value;
        }
        $stmt->close();
    }
    $temp_conn->close();
    return $default_value;
}

// กำหนดค่าคงที่ที่อิงจาก system_settings
define('SITE_NAME', getSystemSetting('site_name', 'Example Document Management System')); //
define('TIMEZONE', getSystemSetting('timezone', 'Asia/Bangkok')); //
date_default_timezone_set(TIMEZONE);

// กำหนดค่าคงที่ Telegram Bot Token และ API URL
define('TELEGRAM_BOT_TOKEN', getSystemSetting('telegram_bot_token', '')); //
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/'); //


// ตัวแปรเก็บการเชื่อมต่อฐานข้อมูล
$conn = null;

// ฟังก์ชันสำหรับเชื่อมต่อฐานข้อมูล
function getDB() {
    global $conn;

    // ถ้ายังไม่มีการเชื่อมต่อ ให้สร้างการเชื่อมต่อใหม่
    if ($conn === null) {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

        // ตรวจสอบการเชื่อมต่อ
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        // ตั้งค่า charset เป็น utf8mb4
        $conn->set_charset("utf8mb4");
    }

    return $conn;
}

// ฟังก์ชันสำหรับฟอร์แมตวันที่และเวลาให้เป็นภาษาไทย (รวม function เดิมทั้งสอง)
function formatThaiDateTime($datetime_str, $include_time = true) {
    if (empty($datetime_str) || $datetime_str == '0000-00-00 00:00:00' || $datetime_str == '0000-00-00') {
        return '-';
    }
    $timestamp = strtotime($datetime_str);
    if ($timestamp === false) {
        return '-'; // Invalid date
    }

    $thai_months = [
        'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
    ];
    $thai_months_full = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];

    $day = date('d', $timestamp);
    $month_index = date('n', $timestamp) - 1;
    $year = date('Y', $timestamp) + 543; // Convert to Buddhist era

    if ($include_time) {
        $time = date('H:i', $timestamp);
        return sprintf("%d %s %d %s น.", $day, $thai_months[$month_index], $year, $time);
    } else {
        return sprintf("%d %s %d", $day, $thai_months_full[$month_index], $year);
    }
}

// คงฟังก์ชัน formatThaiDate เดิมไว้เพื่อความเข้ากันได้ย้อนหลัง แต่ให้เรียกใช้ formatThaiDateTime
function formatThaiDate($date_str) {
    return formatThaiDateTime($date_str, false); // เรียกใช้ formatThaiDateTime โดยไม่รวมเวลา
}
?>
