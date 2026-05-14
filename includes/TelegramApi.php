<?php
// includes/TelegramAPI.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/config/database.php';

class TelegramAPI {
    private $bot_token;
    private $api_url;
    private $db;
    
    public function __construct($bot_token = null) {
        $this->bot_token = $bot_token ?? TELEGRAM_BOT_TOKEN;
        $this->api_url = TELEGRAM_API_URL;
        $this->db = getDB();
    }
    
    /**
     * ส่งข้อความไปยัง Telegram
     */
    public function sendMessage($chat_id, $message, $parse_mode = 'HTML') {
        if (empty($this->bot_token)) {
            return ['success' => false, 'error' => 'Bot Token ไม่ได้ตั้งค่า'];
        }
        
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => $parse_mode
        ];
        
        $result = $this->makeRequest('sendMessage', $data);
        
        $this->logTelegramMessage($chat_id, $message, $result);
        
        return $result;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนถึงผู้ใช้
     */
    public function sendNotificationToUser($user_id, $message) {
        $query = "SELECT telegram_chat_id, first_name, last_name FROM users WHERE id = ? AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (!empty($user['telegram_chat_id'])) {
                $full_message = "🔔 <b>แจ้งเตือนจากระบบลงรับหนังสือราชการ</b>\n\n";
                $full_message .= "เรียน คุณ{$user['first_name']} {$user['last_name']}\n\n";
                $full_message .= $message;
                $full_message .= "\n\n📅 " . formatThaiDateTime(date('Y-m-d H:i:s'), true); 
                
                return $this->sendMessage($user['telegram_chat_id'], $full_message);
            } else {
                return ['success' => false, 'error' => 'ผู้ใช้ยังไม่ได้ตั้งค่า Telegram Chat ID'];
            }
        }
        
        return ['success' => false, 'error' => 'ไม่พบผู้ใช้'];
    }
    
    /**
     * ส่งข้อความแจ้งเตือนเอกสารใหม่ (จาก documents_register)
     */
    public function notifyNewDocument($document_id, $recipient_ids) {
        $query = "SELECT d.*, dt.name as document_type_name, u.first_name, u.last_name 
                  FROM documents d 
                  LEFT JOIN document_types dt ON d.document_type_id = dt.id 
                  LEFT JOIN users u ON d.sender_id = u.id 
                  WHERE d.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $document_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($document = $result->fetch_assoc()) {
            $message = "📄 <b>มีหนังสือราชการใหม่ส่งถึงคุณ</b>\n\n";
            $message .= "🔖 <b>เลขที่หนังสือ:</b> " . htmlspecialchars($document['document_number']) . "\n";
            $message .= "📝 <b>เรื่อง:</b> " . htmlspecialchars($document['title']) . "\n";
            $message .= "📋 <b>ประเภท:</b> " . htmlspecialchars($document['document_type_name']) . "\n";
            $message .= "👤 <b>ผู้ส่ง:</b> " . htmlspecialchars($document['first_name'] . ' ' . $document['last_name']) . "\n";
            
            if (!empty($document['due_date'])) {
                $message .= "⏰ <b>กำหนดส่ง:</b> " . formatThaiDate($document['due_date']) . "\n";
            }
            
            $message .= "\n💡 กรุณาเข้าสู่ระบบเพื่อรับหนังสือราชการ";
            
            $results = [];
            foreach ($recipient_ids as $recipient_id) {
                $results[] = $this->sendNotificationToUser($recipient_id, $message);
            }
            
            return $results;
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการรับเอกสาร (จาก documents_inbox)
     */
    public function notifyDocumentReceived($document_id, $recipient_id) {
        $query = "SELECT d.*, u1.first_name as sender_fname, u1.last_name as sender_lname,
                         u2.first_name as recipient_fname, u2.last_name as recipient_lname
                  FROM documents d 
                  LEFT JOIN users u1 ON d.sender_id = u1.id 
                  LEFT JOIN users u2 ON u2.id = ?
                  WHERE d.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $recipient_id, $document_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($document = $result->fetch_assoc()) {
            $message = "✅ <b>มีการรับหนังสือราชการแล้ว</b>\n\n";
            $message .= "🔖 <b>เลขที่หนังสือ:</b> " . htmlspecialchars($document['document_number']) . "\n";
            $message .= "📝 <b>เรื่อง:</b> " . htmlspecialchars($document['title']) . "\n";
            $message .= "👤 <b>ผู้รับ:</b> " . htmlspecialchars($document['recipient_fname'] . ' ' . $document['recipient_lname']) . "\n";
            $message .= "🕐 <b>เวลาที่รับ:</b> " . formatThaiDateTime(date('Y-m-d H:i:s'), true);
            
            return $this->sendNotificationToUser($document['sender_id'], $message);
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการส่งเอกสาร (จาก documents_sent)
     */
    public function notifyDocumentTransfer($transfer_id) {
        $query = "SELECT dt.*, d.document_number, d.title,
                         u1.first_name as from_fname, u1.last_name as from_lname,
                         u2.first_name as to_fname, u2.last_name as to_lname
                  FROM document_transfers dt
                  LEFT JOIN documents d ON dt.document_id = d.id
                  LEFT JOIN users u1 ON dt.from_user_id = u1.id
                  LEFT JOIN users u2 ON dt.to_user_id = u2.id
                  WHERE dt.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($transfer = $result->fetch_assoc()) {
            $message = "📤 <b>มีเอกสารส่งมาถึงคุณ</b>\n\n";
            $message .= "🔖 <b>เลขที่หนังสือ:</b> " . htmlspecialchars($transfer['document_number']) . "\n";
            $message .= "📝 <b>เรื่อง:</b> " . htmlspecialchars($transfer['title']) . "\n";
            $message .= "👤 <b>ผู้ส่ง:</b> " . htmlspecialchars($transfer['from_fname'] . ' ' . $transfer['from_lname']) . "\n";
            
            if (!empty($transfer['message'])) {
                $message .= "💬 <b>ข้อความ:</b> " . htmlspecialchars($transfer['message']) . "\n";
            }
            
            $message .= "\n💡 กรุณาเข้าสู่ระบบเพื่อตอบรับหรือปฏิเสธเอกสาร";
            
            return $this->sendNotificationToUser($transfer['to_user_id'], $message);
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการตอบกลับการส่งเอกสาร (จาก documents_transfers_inbox)
     */
    public function notifyTransferResponse($transfer_id) {
        $query = "SELECT dt.*, d.document_number, d.title,
                         u1.first_name as from_fname, u1.last_name as from_lname,
                         u2.first_name as to_fname, u2.last_name as to_lname
                  FROM document_transfers dt
                  LEFT JOIN documents d ON dt.document_id = d.id
                  LEFT JOIN users u1 ON dt.from_user_id = u1.id
                  LEFT JOIN users u2 ON dt.to_user_id = u2.id
                  WHERE dt.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($transfer = $result->fetch_assoc()) {
            $status_text = [
                'accepted' => '✅ ยอมรับ',
                'rejected' => '❌ ปฏิเสธ',
                'needs_revision' => '🔄 ต้องการแก้ไข'
            ];
            
            $message = "📥 <b>การตอบกลับการส่งเอกสาร</b>\n\n";
            $message .= "🔖 <b>เลขที่หนังสือ:</b> " . htmlspecialchars($transfer['document_number']) . "\n";
            $message .= "📝 <b>เรื่อง:</b> " . htmlspecialchars($transfer['title']) . "\n";
            $message .= "👤 <b>ผู้ตอบกลับ:</b> " . htmlspecialchars($transfer['to_fname'] . ' ' . $transfer['to_lname']) . "\n";
            $message .= "📊 <b>สถานะ:</b> " . ($status_text[$transfer['status']] ?? htmlspecialchars($transfer['status'])) . "\n";
            
            if (!empty($transfer['response_message'])) {
                $message .= "💬 <b>ข้อความตอบกลับ:</b> " . htmlspecialchars($transfer['response_message']) . "\n";
            }
            
            $message .= "🕐 <b>เวลาที่ตอบกลับ:</b> " . formatThaiDateTime($transfer['responded_at'], true);
            
            return $this->sendNotificationToUser($transfer['from_user_id'], $message);
        }
        
        return false;
    }

    /**
     * ส่งข้อความแจ้งเตือนการส่งไฟล์ส่วนตัว (ใหม่)
     */
    public function notifyPersonalDocumentTransfer($transfer_id) {
        $query = "SELECT pdt.*, pd.title AS personal_doc_title,
                         u1.first_name AS from_fname, u1.last_name AS from_lname,
                         u2.first_name AS to_fname, u2.last_name AS to_lname
                  FROM personal_document_transfers pdt
                  JOIN personal_documents pd ON pdt.personal_document_id = pd.id
                  JOIN users u1 ON pdt.from_user_id = u1.id
                  JOIN users u2 ON pdt.to_user_id = u2.id
                  WHERE pdt.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($transfer = $result->fetch_assoc()) {
            $message = "📁 <b>มีไฟล์ส่วนตัวส่งมาถึงคุณ</b>\n\n";
            $message .= "📝 <b>เรื่อง:</b> " . htmlspecialchars($transfer['personal_doc_title']) . "\n";
            $message .= "👤 <b>ผู้ส่ง:</b> " . htmlspecialchars($transfer['from_fname'] . ' ' . $transfer['from_lname']) . "\n";
            
            if (!empty($transfer['message'])) {
                $message .= "💬 <b>ข้อความ:</b> " . htmlspecialchars($transfer['message']) . "\n";
            }
            
            $message .= "\n💡 กรุณาเข้าสู่ระบบเพื่อตรวจสอบไฟล์";
            
            return $this->sendNotificationToUser($transfer['to_user_id'], $message);
        }
        
        return false;
    }

    /**
     * ส่งข้อความแจ้งเตือนการตอบกลับไฟล์ส่วนตัว (ใหม่)
     */
    public function notifyPersonalTransferResponse($transfer_id) {
        $query = "SELECT pdt.*, pd.title AS personal_doc_title,
                         u1.first_name AS from_fname, u1.last_name AS from_lname,
                         u2.first_name AS to_fname, u2.last_name AS to_lname
                  FROM personal_document_transfers pdt
                  JOIN personal_documents pd ON pdt.personal_document_id = pd.id
                  JOIN users u1 ON pdt.from_user_id = u1.id
                  JOIN users u2 ON pdt.to_user_id = u2.id
                  WHERE pdt.id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($transfer = $result->fetch_assoc()) {
            $status_text = [
                'accepted' => '✅ ยอมรับ',
                'rejected' => '❌ ปฏิเสธ',
                'needs_revision' => '🔄 ต้องการแก้ไข'
            ];
            
            $message = "📨 <b>การตอบกลับไฟล์ส่วนตัวของคุณ</b>\n\n";
            $message .= "📝 <b>เรื่อง:</b> " . htmlspecialchars($transfer['personal_doc_title']) . "\n";
            $message .= "👤 <b>ผู้ตอบกลับ:</b> " . htmlspecialchars($transfer['to_fname'] . ' ' . $transfer['to_lname']) . "\n";
            $message .= "📊 <b>สถานะ:</b> " . ($status_text[$transfer['status']] ?? htmlspecialchars($transfer['status'])) . "\n";
            
            if (!empty($transfer['response_message'])) {
                $message .= "💬 <b>ข้อความตอบกลับ:</b> " . htmlspecialchars($transfer['response_message']) . "\n";
            }
            
            $message .= "🕐 <b>เวลาที่ตอบกลับ:</b> " . formatThaiDateTime($transfer['responded_at'], true);
            
            return $this->sendNotificationToUser($transfer['from_user_id'], $message);
        }
        
        return false;
    }
    
    /**
     * เรียก API ของ Telegram
     */
    private function makeRequest($method, $data) {
        $url = $this->api_url . $method;
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
            ],
        ];
        
        // Suppress errors with @ and log them instead
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result === FALSE) {
            error_log("Telegram API request failed for method: $method. URL: $url");
            return ['success' => false, 'error' => 'ไม่สามารถเชื่อมต่อ Telegram API ได้'];
        }
        
        $response = json_decode($result, true);
        
        if ($response['ok']) {
            return ['success' => true, 'data' => $response['result']];
        } else {
            error_log("Telegram API error for method: $method. Response: " . print_r($response, true));
            return ['success' => false, 'error' => $response['description'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];
        }
    }
    
    /**
     * บันทึกการส่งข้อความ Telegram
     */
    private function logTelegramMessage($chat_id, $message, $result) {
        // หา user_id จาก chat_id
        $query = "SELECT id FROM users WHERE telegram_chat_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $chat_id);
        $stmt->execute();
        $user_result = $stmt->get_result();
        
        if ($user = $user_result->fetch_assoc()) {
            $user_id = $user['id'];
            $status = $result['success'] ? 'sent' : 'failed';
            $telegram_message_id = $result['success'] ? ($result['data']['message_id'] ?? null) : null;
            $error_message = !$result['success'] ? ($result['error'] ?? null) : null;
            
            $log_query = "INSERT INTO telegram_logs (user_id, message, telegram_message_id, status, error_message) 
                         VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $this->db->prepare($log_query);
            $log_stmt->bind_param('issss', $user_id, $message, $telegram_message_id, $status, $error_message);
            $log_stmt->execute();
        } else {
            error_log("Telegram log: User not found for chat_id: $chat_id. Message: $message");
        }
    }
    
    /**
     * ตั้งค่า Webhook สำหรับ Telegram Bot
     */
    public function setWebhook($webhook_url) {
        $data = [
            'url' => $webhook_url
        ];
        
        return $this->makeRequest('setWebhook', $data);
    }
    
    /**
     * ลบ Webhook
     */
    public function deleteWebhook() {
        return $this->makeRequest('deleteWebhook', []);
    }
    
    /**
     * ตรวจสอบสถานะ Bot
     */
    public function getMe() {
        return $this->makeRequest('getMe', []);
    }
}
?>