<?php
// my_line_webhook.php

// สำหรับโปรเจกต์แยกต่างหาก อาจจะไม่ต้องใช้ database.php หรือ LineAPI.php ของโปรเจกต์เดิม
// หากคุณต้องการส่งข้อความตอบกลับ LINE คุณจะต้องมี Channel Access Token
// ซึ่งคุณอาจจะ hardcode ไว้ในไฟล์นี้ชั่วคราวสำหรับการทดสอบ หรือดึงมาจาก config file ของโปรเจกต์ใหม่นี้

// ดึง Channel Secret ที่คุณได้มาจาก LINE Developers Console
// สำหรับโปรเจกต์ที่ไม่ได้เกี่ยวข้องกัน คุณอาจจะ define ตรงนี้ หรือเก็บไว้ใน config file แยกต่างหาก
define('MY_LINE_CHANNEL_SECRET', 'example-line-channel-secret');
define('MY_LINE_CHANNEL_ACCESS_TOKEN', 'example-line-channel-access-token'); // ถ้าต้องการส่งข้อความตอบกลับ

// ฟังก์ชันสำหรับตรวจสอบ Signature (สามารถคัดลอกมาจาก LineAPI.php ได้)
function validateLineSignature($body, $signature, $channel_secret) {
    $hash = hash_hmac('sha256', $body, $channel_secret, true);
    $expected_signature = base64_encode($hash);
    return hash_equals($signature, $expected_signature);
}

// ฟังก์ชันสำหรับส่งข้อความตอบกลับ (ถ้าจำเป็น)
function sendLineReplyMessage($reply_token, $message_text, $channel_access_token) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $data = [
        'replyToken' => $reply_token,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message_text
            ]
        ]
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $channel_access_token
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Consider setting to true in production with proper CA certs

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === FALSE || !empty($error)) {
        error_log("LINE Reply API cURL error: " . $error);
        return ['success' => false, 'error' => 'Failed to connect to LINE Reply API'];
    }

    $response = json_decode($result, true);
    if ($http_code !== 200) {
        error_log("LINE Reply API error. HTTP Code: $http_code. Response: " . print_r($response, true));
        return ['success' => false, 'error' => $response['message'] ?? 'Unknown error'];
    }
    return ['success' => true, 'data' => $response];
}

// รับข้อมูลจาก LINE
$input = file_get_contents('php://input');
$events = json_decode($input, true);

// รับ Signature จาก Header
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// ตรวจสอบ Signature เพื่อความปลอดภัย
if (!validateLineSignature($input, $signature, MY_LINE_CHANNEL_SECRET)) {
    error_log("LINE Webhook: Invalid signature. Request from unauthorized source.");
    http_response_code(401); // Unauthorized
    exit();
}

// บันทึก Log ของข้อมูลดิบ (สำหรับ Debug)
file_put_contents('my_line_webhook_log.txt', date('Y-m-d H:i:s') . " - " . $input . "\n", FILE_APPEND);

if (!empty($events['events'])) {
    foreach ($events['events'] as $event) {
        $line_user_id = $event['source']['userId'] ?? null; // LINE User ID
        $event_type = $event['type'] ?? 'unknown';
        $reply_token = $event['replyToken'] ?? null; // สำหรับ Reply Message

        if ($line_user_id) {
            error_log("Received LINE Event: User ID: " . $line_user_id . ", Type: " . $event_type);

            // ตัวอย่างการประมวลผล Event
            switch ($event_type) {
                case 'follow':
                    // ผู้ใช้เพิ่ม LINE OA ของคุณเป็นเพื่อน
                    error_log("User with LINE ID " . $line_user_id . " just followed.");
                    // คุณสามารถบันทึก line_user_id นี้ลงในฐานข้อมูลของโปรเจกต์ใหม่ของคุณ
                    // เช่น INSERT INTO my_app_users (line_user_id, ...) VALUES (?)

                    // ส่งข้อความต้อนรับ
                    if ($reply_token) {
                        sendLineReplyMessage($reply_token, "สวัสดีครับ! ขอบคุณที่เพิ่มเราเป็นเพื่อน นี่คือ User ID ของคุณ: " . $line_user_id, MY_LINE_CHANNEL_ACCESS_TOKEN);
                    }
                    break;

                case 'message':
                    // ผู้ใช้ส่งข้อความมา
                    $message_type = $event['message']['type'] ?? '';
                    $message_text = '';

                    if ($message_type === 'text') {
                        $message_text = $event['message']['text'] ?? '';
                        error_log("Message from " . $line_user_id . ": " . $message_text);

                        // ตัวอย่าง: ถ้าผู้ใช้พิมพ์ "myid" ให้ตอบกลับด้วย user id
                        if (strtolower(trim($message_text)) === 'myid') {
                             if ($reply_token) {
                                sendLineReplyMessage($reply_token, "LINE User ID ของคุณคือ: " . $line_user_id, MY_LINE_CHANNEL_ACCESS_TOKEN);
                            }
                        } else {
                            if ($reply_token) {
                                sendLineReplyMessage($reply_token, "คุณส่งข้อความมาว่า: " . $message_text . "\nหากต้องการทราบ User ID ของคุณ พิมพ์ 'myid'", MY_LINE_CHANNEL_ACCESS_TOKEN);
                            }
                        }
                    } else {
                        error_log("Received non-text message type: " . $message_type . " from " . $line_user_id);
                        if ($reply_token) {
                            sendLineReplyMessage($reply_token, "ฉันได้รับข้อความประเภท " . $message_type . " จากคุณแล้ว.", MY_LINE_CHANNEL_ACCESS_TOKEN);
                        }
                    }
                    break;

                case 'unfollow':
                    // ผู้ใช้บล็อกหรือยกเลิกการติดตาม
                    error_log("User with LINE ID " . $line_user_id . " unfollowed.");
                    // คุณอาจจะ mark ผู้ใช้นี้ว่าไม่ active ในฐานข้อมูลของคุณ
                    break;

                // เพิ่ม case อื่นๆ ตาม Event Type ที่คุณสนใจ (join, leave, postback, beacon ฯลฯ)
                default:
                    error_log("Unhandled event type " . $event_type . " from " . $line_user_id);
                    break;
            }
        }
    }
}

// ตอบกลับ LINE ด้วย HTTP 200 OK เพื่อยืนยันว่าได้รับ Webhook แล้ว
http_response_code(200);
?>
