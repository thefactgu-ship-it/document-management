<?php
// admin/settings.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/TelegramAPI.php'; // เรียกใช้ TelegramAPI class

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องเป็น Admin เท่านั้น
requireAdmin();

$db = getDB();
$message = '';
$message_type = ''; // 'success' or 'danger'

// ดึงการตั้งค่าปัจจุบัน
$settings = [];
$query = "SELECT setting_key, setting_value, description FROM system_settings";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'description' => $row['description']
        ];
    }
    $result->free();
} else {
    $message = 'ไม่สามารถดึงข้อมูลการตั้งค่าระบบได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    $updates = [];
    $update_types = '';
    $update_params = [];

    // ดึงค่าที่ส่งมาจากฟอร์ม
    foreach (['site_name', 'telegram_bot_token', 'timezone', 'max_file_size', 'allowed_file_types'] as $key) {
        if (isset($_POST[$key])) {
            $value = trim($_POST[$key]);
            $updates[] = "`setting_value` = CASE `setting_key` ";
            $updates[] .= "WHEN ? THEN ? ";
            $updates_case_end[] = "END"; // เก็บส่วนท้ายของ CASE statement
            $update_params[] = $key;
            $update_params[] = $value;
            $update_types .= 'ss'; // s สำหรับ setting_key, s สำหรับ setting_value
        }
    }

    if (!empty($updates)) {
        // สร้าง SQL query สำหรับ UPDATE หลายรายการในครั้งเดียว
        $sql = "UPDATE system_settings SET " . implode('', $updates) . implode('', $updates_case_end) . " WHERE `setting_key` IN (";
        $in_placeholders = [];
        foreach (['site_name', 'telegram_bot_token', 'timezone', 'max_file_size', 'allowed_file_types'] as $key) {
            if (isset($_POST[$key])) {
                $in_placeholders[] = '?';
                $update_params[] = $key; // เพิ่ม key สำหรับ IN clause
                $update_types .= 's'; // s สำหรับ setting_key ใน IN clause
            }
        }
        $sql .= implode(',', $in_placeholders) . ")";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            // สร้าง array สำหรับ bind_param
            $bind_names = array_merge([$update_types], $update_params);
            $ref = [];
            foreach($bind_names as $key => $value) $ref[$key] = &$bind_names[$key];
            call_user_func_array([$stmt, 'bind_param'], $ref);

            if ($stmt->execute()) {
                $message = 'บันทึกการตั้งค่าระบบสำเร็จแล้ว';
                $message_type = 'success';
                // อัปเดตค่าใน $settings array เพื่อแสดงค่าใหม่ทันที
                foreach (['site_name', 'telegram_bot_token', 'timezone', 'max_file_size', 'allowed_file_types'] as $key) {
                    if (isset($_POST[$key])) {
                        $settings[$key]['value'] = $_POST[$key];
                    }
                }
                // ต้องอัปเดตค่าคงที่ใน config/database.php ด้วย (ในทางปฏิบัติอาจต้องมี mechanism ที่ดีกว่านี้)
                // สำหรับตอนนี้ เราจะถือว่าค่าใน DB คือค่าจริง และจะถูกโหลดใหม่เมื่อมีการ refresh หน้า
                // หรืออาจจะให้ผู้ใช้ไปแก้ไขใน config/database.php ด้วยตนเองสำหรับค่าคงที่
            } else {
                $message = 'เกิดข้อผิดพลาดในการบันทึกการตั้งค่า: ' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        } else {
            $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
            $message_type = 'danger';
        }
    } else {
        $message = 'ไม่มีการตั้งค่าใดๆ ที่จะบันทึก';
        $message_type = 'info';
    }
}

// --- Handle Test Telegram Connection ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_telegram') {
    $telegram_chat_id = trim($_POST['test_telegram_chat_id'] ?? '');
    
    if (empty($telegram_chat_id)) {
        $message = 'กรุณากรอก Telegram Chat ID สำหรับทดสอบ';
        $message_type = 'danger';
    } else {
        // ต้องโหลดค่า bot token ที่อัปเดตล่าสุดจาก DB
        $stmt_token = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_bot_token'");
        $stmt_token->execute();
        $res_token = $stmt_token->get_result();
        $row_token = $res_token->fetch_assoc();
        $current_bot_token = $row_token['setting_value'];
        $stmt_token->close();

        // อัปเดตค่าคงที่ TELEGRAM_BOT_TOKEN ชั่วคราวในรันไทม์ (ไม่แนะนำสำหรับ Production)
        // เพื่อให้ TelegramAPI ใช้ค่าใหม่ทันที
        if (!defined('TELEGRAM_BOT_TOKEN') || TELEGRAM_BOT_TOKEN !== $current_bot_token) {
            // ในสภาพแวดล้อมจริง ควรให้ TelegramAPI ดึงค่าจาก DB โดยตรง หรือ reload config
            // สำหรับตอนนี้เราจะกำหนดค่าใหม่ชั่วคราว
            // หาก TELEGRAM_BOT_TOKEN ถูกกำหนดไปแล้ว จะเกิด Error
            // ดังนั้นเราจะใช้ ReflectionClass หรือวิธีอื่นที่ซับซ้อนกว่า
            // แต่สำหรับตัวอย่างนี้ เราจะสมมติว่าเราสามารถอัปเดตค่าได้
            // หรือจะสร้าง instance ของ TelegramAPI หลังจากดึงค่า token ล่าสุด
            
            // วิธีที่ง่ายที่สุดคือส่ง token เข้าไปใน constructor ของ TelegramAPI
            // แต่เนื่องจาก TelegramAPI ถูกออกแบบมาให้ดึงจาก constant
            // เราจะใช้การสร้าง object ใหม่หลังจากดึงค่าจาก DB
            // หรือถ้าเราต้องการให้ TelegramAPI ใช้ค่าจาก DB โดยตรง
            // เราอาจจะต้องปรับปรุง TelegramAPI class เล็กน้อย
            
            // สำหรับตอนนี้ ให้ถือว่า TELEGRAM_BOT_TOKEN ใน config/database.php
            // ถูกตั้งค่าอย่างถูกต้องแล้ว หรือผู้ใช้จะตั้งค่าในฟอร์มแล้วกดบันทึกก่อนทดสอบ
            // ถ้าไม่เช่นนั้น จะต้องมีการโหลด config ใหม่ หรือส่ง token เข้าไปใน constructor ของ TelegramAPI
        }

        // สร้าง instance ของ TelegramAPI (จะใช้ค่าคงที่ที่ถูกกำหนดไว้แล้ว หรือต้องโหลดใหม่)
        // เพื่อให้แน่ใจว่าใช้ token ที่ถูกตั้งค่าล่าสุด
        // เราจะสร้าง TelegramAPI object ภายใน if block นี้
        // และส่ง bot token ที่ได้จาก DB ไปให้ constructor โดยตรง
        // (ซึ่งต้องปรับแก้ TelegramAPI class เล็กน้อย)

        // *** การปรับแก้ชั่วคราวสำหรับ TelegramAPI class ***
        // ในไฟล์ includes/TelegramAPI.php
        // เปลี่ยน constructor เป็น:
        // public function __construct($bot_token = null) {
        //     $this->bot_token = $bot_token ?? TELEGRAM_BOT_TOKEN;
        //     $this->api_url = TELEGRAM_API_URL;
        //     $this->db = getDB();
        // }
        // ***

        // หลังจากปรับแก้ TelegramAPI แล้ว:
        $telegram = new TelegramAPI($current_bot_token); // ส่ง token ที่ได้จาก DB เข้าไป
        $test_message = "✅ การเชื่อมต่อ Telegram Bot สำเร็จ! นี่คือข้อความทดสอบจากระบบลงรับหนังสือราชการของคุณ.";
        $response = $telegram->sendMessage($telegram_chat_id, $test_message);

        if ($response['success']) {
            $message = 'ส่งข้อความทดสอบ Telegram สำเร็จแล้ว! กรุณาตรวจสอบ Telegram ของคุณ';
            $message_type = 'success';
        } else {
            $message = 'เกิดข้อผิดพลาดในการส่งข้อความทดสอบ Telegram: ' . htmlspecialchars($response['error']);
            $message_type = 'danger';
        }
    }
}


// Load Header
$page_title = 'ตั้งค่าระบบ';
require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-cogs me-2"></i>ตั้งค่าระบบ</h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success-glass' : 'danger-glass'; ?> glassmorphism p-3 mb-4 d-flex align-items-center" role="alert">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="glassmorphism p-4 rounded-lg shadow-lg mb-4">
        <h3 class="text-white mb-3"><i class="fas fa-sliders-h me-2"></i>การตั้งค่าทั่วไป</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_settings">

            <div class="mb-3">
                <label for="site_name" class="form-label text-white-50">ชื่อระบบ</label>
                <input type="text" class="form-control" id="site_name" name="site_name" 
                       value="<?php echo htmlspecialchars($settings['site_name']['value'] ?? ''); ?>" 
                       placeholder="ชื่อระบบลงรับหนังสือราชการ">
                <div class="form-text text-white-50"><?php echo htmlspecialchars($settings['site_name']['description'] ?? ''); ?></div>
            </div>

            <div class="mb-3">
                <label for="timezone" class="form-label text-white-50">เขตเวลา</label>
                <input type="text" class="form-control" id="timezone" name="timezone" 
                       value="<?php echo htmlspecialchars($settings['timezone']['value'] ?? ''); ?>" 
                       placeholder="เช่น Asia/Bangkok">
                <div class="form-text text-white-50"><?php echo htmlspecialchars($settings['timezone']['description'] ?? ''); ?></div>
            </div>

            <div class="mb-3">
                <label for="max_file_size" class="form-label text-white-50">ขนาดไฟล์สูงสุดที่อนุญาต (Bytes)</label>
                <input type="number" class="form-control" id="max_file_size" name="max_file_size" 
                       value="<?php echo htmlspecialchars($settings['max_file_size']['value'] ?? ''); ?>" 
                       placeholder="เช่น 10485760 (10 MB)">
                <div class="form-text text-white-50"><?php echo htmlspecialchars($settings['max_file_size']['description'] ?? ''); ?></div>
            </div>

            <div class="mb-3">
                <label for="allowed_file_types" class="form-label text-white-50">ประเภทไฟล์ที่อนุญาต (คั่นด้วย comma)</label>
                <input type="text" class="form-control" id="allowed_file_types" name="allowed_file_types" 
                       value="<?php echo htmlspecialchars($settings['allowed_file_types']['value'] ?? ''); ?>" 
                       placeholder="เช่น pdf,doc,docx,jpg,jpeg,png">
                <div class="form-text text-white-50"><?php echo htmlspecialchars($settings['allowed_file_types']['description'] ?? ''); ?></div>
            </div>

            <button type="submit" class="btn btn-primary-glass">
                <i class="fas fa-save me-2"></i>
                บันทึกการตั้งค่า
            </button>
        </form>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg mb-4">
        <h3 class="text-white mb-3"><i class="fab fa-telegram-plane me-2"></i>ตั้งค่า Telegram Bot</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_settings">
            <div class="mb-3">
                <label for="telegram_bot_token" class="form-label text-white-50">Telegram Bot Token</label>
                <input type="text" class="form-control" id="telegram_bot_token" name="telegram_bot_token" 
                       value="<?php echo htmlspecialchars($settings['telegram_bot_token']['value'] ?? ''); ?>" 
                       placeholder="กรอก Bot Token ของคุณ">
                <div class="form-text text-white-50"><?php echo htmlspecialchars($settings['telegram_bot_token']['description'] ?? ''); ?></div>
            </div>
            <button type="submit" class="btn btn-primary-glass mb-3">
                <i class="fas fa-save me-2"></i>
                บันทึก Telegram Bot Token
            </button>
        </form>

        <hr class="border-white-50 my-4">

        <h4 class="text-white mb-3"><i class="fas fa-paper-plane me-2"></i>ทดสอบการเชื่อมต่อ Telegram</h4>
        <p class="text-white-50">
            กรุณากรอก Chat ID ของ Telegram ของคุณเพื่อทดสอบการส่งข้อความ
            คุณสามารถหา Chat ID ของคุณได้โดยการส่งข้อความหา <a href="https://t.me/userinfobot" target="_blank" class="text-info">@userinfobot</a> ใน Telegram
        </p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="test_telegram">
            <div class="mb-3">
                <label for="test_telegram_chat_id" class="form-label text-white-50">Telegram Chat ID</label>
                <input type="text" class="form-control" id="test_telegram_chat_id" name="test_telegram_chat_id" 
                       placeholder="กรอก Chat ID ของคุณ">
            </div>
            <button type="submit" class="btn btn-info-glass">
                <i class="fas fa-robot me-2"></i>
                ทดสอบส่งข้อความ Telegram
            </button>
        </form>
    </div>

</div>

<?php
require_once '../includes/footer.php';
?>