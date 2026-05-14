<?php
// includes/PushNotificationManager.php

class PushNotificationManager {
    private $db;
    private $vapid_public_key;
    private $vapid_private_key;
    private $vapid_subject;

    public function __construct() {
        $this->db = getDB();
        
        // VAPID keys - ควรเก็บใน system_settings หรือ environment variables
        $this->vapid_public_key = getSystemSetting('vapid_public_key', '');
        $this->vapid_private_key = getSystemSetting('vapid_private_key', '');
        $this->vapid_subject = getSystemSetting('vapid_subject', 'mailto:admin@example.com');
    }

    /**
     * บันทึก Push Subscription ของผู้ใช้
     */
    public function saveSubscription($user_id, $subscription_data) {
        try {
            $endpoint = $subscription_data['endpoint'];
            $p256dh = $subscription_data['keys']['p256dh'];
            $auth = $subscription_data['keys']['auth'];

            // ตรวจสอบว่ามี subscription นี้อยู่แล้วหรือไม่
            $check_stmt = $this->db->prepare("
                SELECT id FROM push_subscriptions 
                WHERE user_id = ? AND endpoint = ?
            ");
            $check_stmt->bind_param('is', $user_id, $endpoint);
            $check_stmt->execute();
            $result = $check_stmt->get_result();

            if ($result->num_rows > 0) {
                // อัปเดต subscription ที่มีอยู่
                $update_stmt = $this->db->prepare("
                    UPDATE push_subscriptions 
                    SET p256dh = ?, auth = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE user_id = ? AND endpoint = ?
                ");
                $update_stmt->bind_param('ssis', $p256dh, $auth, $user_id, $endpoint);
                return $update_stmt->execute();
            } else {
                // เพิ่ม subscription ใหม่
                $insert_stmt = $this->db->prepare("
                    INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key) 
                    VALUES (?, ?, ?, ?)
                ");
                $insert_stmt->bind_param('isss', $user_id, $endpoint, $p256dh, $auth);
                return $insert_stmt->execute();
            }
        } catch (Exception $e) {
            error_log("Error saving push subscription: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ส่ง Push Notification ไปยังผู้ใช้คนหนึ่ง
     */
    public function sendNotificationToUser($user_id, $title, $body, $data = []) {
        try {
            // ดึง subscriptions ของผู้ใช้
            $stmt = $this->db->prepare("
                SELECT endpoint, p256dh, auth_key 
                FROM push_subscriptions 
                WHERE user_id = ? AND is_active = 1
            ");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $success_count = 0;
            while ($row = $result->fetch_assoc()) {
                if ($this->sendPushNotification($row, $title, $body, $data)) {
                    $success_count++;
                }
            }

            // บันทึกประวัติการส่ง notification
            $this->logNotification($user_id, $title, $body, $success_count > 0 ? 'sent' : 'failed');

            return $success_count > 0;
        } catch (Exception $e) {
            error_log("Error sending notification to user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ส่ง Push Notification ไปยัง subscription เดียว
     */
    private function sendPushNotification($subscription, $title, $body, $data = []) {
        if (empty($this->vapid_private_key) || empty($this->vapid_public_key)) {
            error_log("VAPID keys not configured");
            return false;
        }

        try {
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'icon' => '/assets/img/icon-192x192.png',
                'badge' => '/assets/img/badge-72x72.png',
                'data' => $data,
                'timestamp' => time() * 1000,
                'tag' => 'document-notification',
                'requireInteraction' => true
            ]);

            // ใช้ Web Push PHP Library หรือ cURL เพื่อส่ง notification
            return $this->sendWebPush($subscription, $payload);
        } catch (Exception $e) {
            error_log("Error sending push notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ส่ง Web Push ด้วย cURL (simplified version)
     */
    private function sendWebPush($subscription, $payload) {
        // สำหรับ production ควรใช้ library เช่น minishlink/web-push
        // นี่เป็นตัวอย่างพื้นฐาน
        
        $endpoint = $subscription['endpoint'];
        $p256dh = $subscription['p256dh'];
        $auth = $subscription['auth_key'];

        // สร้าง JWT token สำหรับ VAPID
        $vapid_header = $this->generateVapidHeader($endpoint);
        
        $headers = [
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Authorization: ' . $vapid_header,
        ];

        // เข้ารหัส payload (ในการใช้งานจริงต้องทำการเข้ารหัสที่ซับซ้อนกว่านี้)
        $encrypted_payload = $this->encryptPayload($payload, $p256dh, $auth);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encrypted_payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code >= 200 && $http_code < 300;
    }

    /**
     * สร้าง VAPID header (simplified)
     */
    private function generateVapidHeader($endpoint) {
        // ในการใช้งานจริงควรใช้ JWT library
        // นี่เป็นตัวอย่างพื้นฐาน
        $url_parts = parse_url($endpoint);
        $audience = $url_parts['scheme'] . '://' . $url_parts['host'];
        
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256'
        ];
        
        $payload = [
            'aud' => $audience,
            'exp' => time() + 12 * 60 * 60, // 12 hours
            'sub' => $this->vapid_subject
        ];

        // สำหรับการใช้งานจริง ต้องลงนาม JWT ด้วย private key
        return 'vapid t=' . base64_encode(json_encode($header)) . '.' . base64_encode(json_encode($payload)) . '.signature, k=' . $this->vapid_public_key;
    }

    /**
     * เข้ารหัส payload (simplified)
     */
    private function encryptPayload($payload, $p256dh, $auth) {
        // ในการใช้งานจริงต้องใช้ Web Push encryption standard
        // นี่เป็นตัวอย่างพื้นฐาน - ส่ง plain text (ไม่ปลอดภัย)
        return $payload;
    }

    /**
     * บันทึกประวัติการส่ง notification
     */
    private function logNotification($user_id, $title, $body, $status) {
        $stmt = $this->db->prepare("
            INSERT INTO notification_logs (user_id, title, message, status, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isss', $user_id, $title, $body, $status);
        return $stmt->execute();
    }

    /**
     * ลบ subscription ที่ไม่ใช้งานแล้ว
     */
    public function removeSubscription($user_id, $endpoint) {
        $stmt = $this->db->prepare("
            DELETE FROM push_subscriptions 
            WHERE user_id = ? AND endpoint = ?
        ");
        $stmt->bind_param('is', $user_id, $endpoint);
        return $stmt->execute();
    }

    /**
     * แจ้งเตือนการส่งต่อเอกสาร
     */
    public function notifyDocumentTransfer($transfer_id) {
        try {
            $stmt = $this->db->prepare("
                SELECT dt.to_user_id, dt.message,
                       d.document_number, d.title,
                       u.first_name, u.last_name
                FROM document_transfers dt
                JOIN documents d ON dt.document_id = d.id
                JOIN users u ON dt.from_user_id = u.id
                WHERE dt.id = ?
            ");
            $stmt->bind_param('i', $transfer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $title = 'เอกสารใหม่ที่ได้รับ';
                $body = sprintf(
                    'คุณได้รับเอกสาร "%s" จาก %s %s',
                    $row['title'],
                    $row['first_name'],
                    $row['last_name']
                );
                
                $data = [
                    'type' => 'document_transfer',
                    'transfer_id' => $transfer_id,
                    'document_number' => $row['document_number'],
                    'url' => '/documents_received.php'
                ];

                return $this->sendNotificationToUser($row['to_user_id'], $title, $body, $data);
            }
        } catch (Exception $e) {
            error_log("Error notifying document transfer: " . $e->getMessage());
            return false;
        }
        return false;
    }

    /**
     * แจ้งเตือนการตอบกลับเอกสาร
     */
    public function notifyDocumentResponse($transfer_id) {
        try {
            $stmt = $this->db->prepare("
                SELECT dt.from_user_id, dt.status, dt.response_message,
                       d.document_number, d.title,
                       u.first_name, u.last_name
                FROM document_transfers dt
                JOIN documents d ON dt.document_id = d.id
                JOIN users u ON dt.to_user_id = u.id
                WHERE dt.id = ?
            ");
            $stmt->bind_param('i', $transfer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $status_text = [
                    'accepted' => 'ยอมรับ',
                    'rejected' => 'ปฏิเสธ',
                    'needs_revision' => 'ต้องการแก้ไข'
                ];

                $title = 'มีการตอบกลับเอกสาร';
                $body = sprintf(
                    '%s %s ได้ %s เอกสาร "%s"',
                    $row['first_name'],
                    $row['last_name'],
                    $status_text[$row['status']] ?? $row['status'],
                    $row['title']
                );
                
                $data = [
                    'type' => 'document_response',
                    'transfer_id' => $transfer_id,
                    'document_number' => $row['document_number'],
                    'url' => '/documents_sent.php'
                ];

                return $this->sendNotificationToUser($row['from_user_id'], $title, $body, $data);
            }
        } catch (Exception $e) {
            error_log("Error notifying document response: " . $e->getMessage());
            return false;
        }
        return false;
    }
}

/**
 * Helper function สำหรับส่ง notification แบบง่าย
 */
function sendPushNotification($user_id, $title, $body, $data = []) {
    $push_manager = new PushNotificationManager();
    return $push_manager->sendNotificationToUser($user_id, $title, $body, $data);
}
?>