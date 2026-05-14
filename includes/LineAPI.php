<?php
// includes/LineAPI.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(__DIR__) . '/config/database.php';

class LineAPI {
    private $channel_access_token;
    private $channel_secret;
    private $api_url;
    private $db;
    
    public function __construct($channel_access_token = null, $channel_secret = null) {
        $this->channel_access_token = $channel_access_token ?? LINE_CHANNEL_ACCESS_TOKEN;
        $this->channel_secret = $channel_secret ?? LINE_CHANNEL_SECRET;
        $this->api_url = 'https://api.line.me/v2/bot/';
        $this->db = getDB();
    }
    
    /**
     * ส่งข้อความไปยัง LINE
     */
    public function sendMessage($user_id, $message, $type = 'text') {
        if (empty($this->channel_access_token)) {
            return ['success' => false, 'error' => 'LINE Channel Access Token ไม่ได้ตั้งค่า'];
        }
        
        $data = [
            'to' => $user_id,
            'messages' => [
                [
                    'type' => $type,
                    'text' => $message
                ]
            ]
        ];
        
        $result = $this->makeRequest('message/push', $data);
        
        $this->logLineMessage($user_id, $message, $result);
        
        return $result;
    }
    
    /**
     * ส่งข้อความแบบ Flex Message
     */
    public function sendFlexMessage($user_id, $alt_text, $flex_content) {
        if (empty($this->channel_access_token)) {
            return ['success' => false, 'error' => 'LINE Channel Access Token ไม่ได้ตั้งค่า'];
        }
        
        $data = [
            'to' => $user_id,
            'messages' => [
                [
                    'type' => 'flex',
                    'altText' => $alt_text,
                    'contents' => $flex_content
                ]
            ]
        ];
        
        $result = $this->makeRequest('message/push', $data);
        
        $this->logLineMessage($user_id, $alt_text, $result);
        
        return $result;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนถึงผู้ใช้
     */
    public function sendNotificationToUser($user_id, $message) {
        $query = "SELECT line_user_id, first_name, last_name FROM users WHERE id = ? AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (!empty($user['line_user_id'])) {
                $full_message = "🔔 แจ้งเตือนจากระบบลงรับหนังสือราชการ\n\n";
                $full_message .= "เรียน คุณ{$user['first_name']} {$user['last_name']}\n\n";
                $full_message .= $message;
                $full_message .= "\n\n📅 " . formatThaiDateTime(date('Y-m-d H:i:s'), true); 
                
                return $this->sendMessage($user['line_user_id'], $full_message);
            } else {
                return ['success' => false, 'error' => 'ผู้ใช้ยังไม่ได้ตั้งค่า LINE User ID'];
            }
        }
        
        return ['success' => false, 'error' => 'ไม่พบผู้ใช้'];
    }
    
    /**
     * ส่งข้อความแจ้งเตือนเอกสารใหม่แบบ Flex Message
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
            $alt_text = "มีหนังสือราชการใหม่: " . $document['title'];
            
            $flex_content = $this->createDocumentFlexMessage($document, 'new');
            
            $results = [];
            foreach ($recipient_ids as $recipient_id) {
                $user_query = "SELECT line_user_id FROM users WHERE id = ? AND is_active = 1";
                $user_stmt = $this->db->prepare($user_query);
                $user_stmt->bind_param('i', $recipient_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                
                if ($user = $user_result->fetch_assoc()) {
                    if (!empty($user['line_user_id'])) {
                        $results[] = $this->sendFlexMessage($user['line_user_id'], $alt_text, $flex_content);
                    }
                }
            }
            
            return $results;
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการรับเอกสาร
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
            $message = "✅ มีการรับหนังสือราชการแล้ว\n\n";
            $message .= "🔖 เลขที่หนังสือ: " . $document['document_number'] . "\n";
            $message .= "📝 เรื่อง: " . $document['title'] . "\n";
            $message .= "👤 ผู้รับ: " . $document['recipient_fname'] . ' ' . $document['recipient_lname'] . "\n";
            $message .= "🕐 เวลาที่รับ: " . formatThaiDateTime(date('Y-m-d H:i:s'), true);
            
            return $this->sendNotificationToUser($document['sender_id'], $message);
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการส่งเอกสาร
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
            $message = "📤 มีเอกสารส่งมาถึงคุณ\n\n";
            $message .= "🔖 เลขที่หนังสือ: " . $transfer['document_number'] . "\n";
            $message .= "📝 เรื่อง: " . $transfer['title'] . "\n";
            $message .= "👤 ผู้ส่ง: " . $transfer['from_fname'] . ' ' . $transfer['from_lname'] . "\n";
            
            if (!empty($transfer['message'])) {
                $message .= "💬 ข้อความ: " . $transfer['message'] . "\n";
            }
            
            $message .= "\n💡 กรุณาเข้าสู่ระบบเพื่อตอบรับหรือปฏิเสธเอกสาร";
            
            return $this->sendNotificationToUser($transfer['to_user_id'], $message);
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการตอบกลับการส่งเอกสาร
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
            
            $message = "📥 การตอบกลับการส่งเอกสาร\n\n";
            $message .= "🔖 เลขที่หนังสือ: " . $transfer['document_number'] . "\n";
            $message .= "📝 เรื่อง: " . $transfer['title'] . "\n";
            $message .= "👤 ผู้ตอบกลับ: " . $transfer['to_fname'] . ' ' . $transfer['to_lname'] . "\n";
            $message .= "📊 สถานะ: " . ($status_text[$transfer['status']] ?? $transfer['status']) . "\n";
            
            if (!empty($transfer['response_message'])) {
                $message .= "💬 ข้อความตอบกลับ: " . $transfer['response_message'] . "\n";
            }
            
            $message .= "🕐 เวลาที่ตอบกลับ: " . formatThaiDateTime($transfer['responded_at'], true);
            
            return $this->sendNotificationToUser($transfer['from_user_id'], $message);
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการส่งไฟล์ส่วนตัว
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
            $message = "📁 มีไฟล์ส่วนตัวส่งมาถึงคุณ\n\n";
            $message .= "📝 เรื่อง: " . $transfer['personal_doc_title'] . "\n";
            $message .= "👤 ผู้ส่ง: " . $transfer['from_fname'] . ' ' . $transfer['from_lname'] . "\n";
            
            if (!empty($transfer['message'])) {
                $message .= "💬 ข้อความ: " . $transfer['message'] . "\n";
            }
            
            $message .= "\n💡 กรุณาเข้าสู่ระบบเพื่อตรวจสอบไฟล์";
            
            return $this->sendNotificationToUser($transfer['to_user_id'], $message);
        }
        
        return false;
    }
    
    /**
     * ส่งข้อความแจ้งเตือนการตอบกลับไฟล์ส่วนตัว
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
            
            $message = "📨 การตอบกลับไฟล์ส่วนตัวของคุณ\n\n";
            $message .= "📝 เรื่อง: " . $transfer['personal_doc_title'] . "\n";
            $message .= "👤 ผู้ตอบกลับ: " . $transfer['to_fname'] . ' ' . $transfer['to_lname'] . "\n";
            $message .= "📊 สถานะ: " . ($status_text[$transfer['status']] ?? $transfer['status']) . "\n";
            
            if (!empty($transfer['response_message'])) {
                $message .= "💬 ข้อความตอบกลับ: " . $transfer['response_message'] . "\n";
            }
            
            $message .= "🕐 เวลาที่ตอบกลับ: " . formatThaiDateTime($transfer['responded_at'], true);
            
            return $this->sendNotificationToUser($transfer['from_user_id'], $message);
        }
        
        return false;
    }
    
    /**
     * สร้าง Flex Message สำหรับเอกสาร
     */
    private function createDocumentFlexMessage($document, $type = 'new') {
        $header_text = $type === 'new' ? '📄 หนังสือราชการใหม่' : '📄 แจ้งเตือนเอกสาร';
        $header_color = $type === 'new' ? '#0066CC' : '#009900';
        
        return [
            'type' => 'bubble',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $header_text,
                        'weight' => 'bold',
                        'color' => '#ffffff',
                        'size' => 'md'
                    ]
                ],
                'backgroundColor' => $header_color,
                'paddingAll' => '13px'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $document['title'],
                        'weight' => 'bold',
                        'size' => 'md',
                        'wrap' => true,
                        'color' => '#333333'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => [
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'spacing' => 'sm',
                                'contents' => [
                                    [
                                        'type' => 'text',
                                        'text' => '🔖 เลขที่:',
                                        'color' => '#666666',
                                        'size' => 'sm',
                                        'flex' => 2
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => $document['document_number'],
                                        'wrap' => true,
                                        'color' => '#333333',
                                        'size' => 'sm',
                                        'flex' => 3
                                    ]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'spacing' => 'sm',
                                'contents' => [
                                    [
                                        'type' => 'text',
                                        'text' => '📋 ประเภท:',
                                        'color' => '#666666',
                                        'size' => 'sm',
                                        'flex' => 2
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => $document['document_type_name'] ?? 'ไม่ระบุ',
                                        'wrap' => true,
                                        'color' => '#333333',
                                        'size' => 'sm',
                                        'flex' => 3
                                    ]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'spacing' => 'sm',
                                'contents' => [
                                    [
                                        'type' => 'text',
                                        'text' => '👤 ผู้ส่ง:',
                                        'color' => '#666666',
                                        'size' => 'sm',
                                        'flex' => 2
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => $document['first_name'] . ' ' . $document['last_name'],
                                        'wrap' => true,
                                        'color' => '#333333',
                                        'size' => 'sm',
                                        'flex' => 3
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '💡 กรุณาเข้าสู่ระบบเพื่อรับเอกสาร',
                        'color' => '#666666',
                        'size' => 'xs',
                        'wrap' => true
                    ],
                    [
                        'type' => 'text',
                        'text' => '📅 ' . formatThaiDateTime(date('Y-m-d H:i:s'), true),
                        'color' => '#999999',
                        'size' => 'xs'
                    ]
                ]
            ]
        ];
    }
    
    /**
     * เรียก API ของ LINE
     */
    private function makeRequest($endpoint, $data) {
        $url = $this->api_url . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channel_access_token
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($result === FALSE || !empty($error)) {
            error_log("LINE API cURL error: " . $error);
            return ['success' => false, 'error' => 'ไม่สามารถเชื่อมต่อ LINE API ได้'];
        }
        
        $response = json_decode($result, true);
        
        if ($http_code === 200) {
            return ['success' => true, 'data' => $response];
        } else {
            error_log("LINE API error. HTTP Code: $http_code. Response: " . print_r($response, true));
            return ['success' => false, 'error' => $response['message'] ?? 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];
        }
    }
    
    /**
     * บันทึกการส่งข้อความ LINE
     */
    private function logLineMessage($line_user_id, $message, $result) {
        // หา user_id จาก line_user_id
        $user_id = null;
        $query = "SELECT id FROM users WHERE line_user_id = ?";
        $stmt = $this->db->prepare($query);
        if ($stmt) {
            $stmt->bind_param('s', $line_user_id);
            $stmt->execute();
            $user_result = $stmt->get_result();
            if ($user = $user_result->fetch_assoc()) {
                $user_id = $user['id'];
            }
            $stmt->close();
        } else {
            error_log("LINE log: Failed to prepare user query: " . $this->db->error);
        }

        $status = $result['success'] ? 'sent' : 'failed';
        $error_message = !$result['success'] ? ($result['error'] ?? null) : null;
        
        $log_query = "INSERT INTO line_logs (user_id, line_user_id, message, status, error_message) 
                     VALUES (?, ?, ?, ?, ?)";
        $log_stmt = $this->db->prepare($log_query);
        if ($log_stmt) {
            $log_stmt->bind_param('issss', $user_id, $line_user_id, $message, $status, $error_message);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            error_log("LINE log: Failed to prepare log query: " . $this->db->error);
        }
    }
    
    /**
     * ตรวจสอบ Webhook signature
     */
    public function validateSignature($body, $signature) {
        $hash = hash_hmac('sha256', $body, $this->channel_secret, true);
        $expected_signature = base64_encode($hash);
        
        return hash_equals($signature, $expected_signature);
    }
    
    /**
     * ตั้งค่า Rich Menu
     */
    public function createRichMenu($rich_menu_data) {
        return $this->makeRequest('richmenu', $rich_menu_data);
    }
    
    /**
     * ลิงค์ Rich Menu กับผู้ใช้
     */
    public function linkRichMenuToUser($user_id, $rich_menu_id) {
        return $this->makeRequest("user/$user_id/richmenu/$rich_menu_id", [], 'POST');
    }
    
    /**
     * ตรวจสอบสถานะ Channel
     */
    public function getChannelInfo() {
        return $this->makeRequest('info', []);
    }
}
?>