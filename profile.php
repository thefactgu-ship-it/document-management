<?php
// profile.php
// สำหรับผู้ใช้งานดูและแก้ไขข้อมูลส่วนตัว รวมถึงเปลี่ยนรหัสผ่าน

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/TelegramAPI.php'; // สำหรับทดสอบ Telegram Chat ID (ถ้ามี)
require_once 'includes/LineAPI.php'; // เพิ่มสำหรับ Line User ID

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องล็อกอิน
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDB();
$message = '';
$message_type = '';
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลผู้ใช้งานปัจจุบัน
$user_data = [];
// เพิ่ม line_user_id ในการ SELECT
$stmt_user = $db->prepare("SELECT id, username, first_name, last_name, email, phone, department_id, role, telegram_chat_id, line_user_id FROM users WHERE id = ?");
if ($stmt_user) {
    $stmt_user->bind_param('i', $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($row_user = $result_user->fetch_assoc()) {
        $user_data = $row_user;
    } else {
        // หากไม่พบข้อมูลผู้ใช้ (เช่น บัญชีถูกลบ) ให้ logout
        header('Location: logout.php');
        exit();
    }
    $stmt_user->close();
} else {
    $message = 'เกิดข้อผิดพลาดในการดึงข้อมูลผู้ใช้งาน: ' . $db->error;
    $message_type = 'danger';
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');
        $line_user_id = trim($_POST['line_user_id'] ?? ''); // เพิ่มการรับค่า line_user_id

        if (empty($first_name) || empty($last_name) || empty($email)) {
            $message = 'กรุณากรอกชื่อจริง นามสกุล และอีเมล';
            $message_type = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'รูปแบบอีเมลไม่ถูกต้อง';
            $message_type = 'danger';
        } else {
            // ตรวจสอบอีเมลซ้ำ (ยกเว้นของตัวเอง)
            $check_email_stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_email_stmt->bind_param('si', $email, $user_id);
            $check_email_stmt->execute();
            $check_email_result = $check_email_stmt->get_result();
            if ($check_email_result->num_rows > 0) {
                $message = 'อีเมลนี้มีอยู่ในระบบแล้ว กรุณาใช้อีเมลอื่น';
                $message_type = 'danger';
            } else {
                // เพิ่ม line_user_id ในการ UPDATE
                $stmt_update = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, telegram_chat_id = ?, line_user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                if ($stmt_update) {
                    // เพิ่ม line_user_id ใน bind_param
                    $stmt_update->bind_param('ssssssi', $first_name, $last_name, $email, $phone, $telegram_chat_id, $line_user_id, $user_id);
                    if ($stmt_update->execute()) {
                        $message = 'บันทึกข้อมูลส่วนตัวสำเร็จแล้ว';
                        $message_type = 'success';
                        // อัปเดต Session data ด้วย
                        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                        $_SESSION['user_email'] = $email;
                        // ดึงข้อมูลล่าสุดมาแสดงในฟอร์ม
                        $user_data['first_name'] = $first_name;
                        $user_data['last_name'] = $last_name;
                        $user_data['email'] = $email;
                        $user_data['phone'] = $phone;
                        $user_data['telegram_chat_id'] = $telegram_chat_id;
                        $user_data['line_user_id'] = $line_user_id; // อัปเดต user_data
                    } else {
                        $message = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $stmt_update->error;
                        $message_type = 'danger';
                    }
                    $stmt_update->close();
                } else {
                    $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                    $message_type = 'danger';
                }
            }
            $check_email_stmt->close();
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = 'กรุณากรอกข้อมูลรหัสผ่านให้ครบถ้วน';
            $message_type = 'danger';
        } elseif ($new_password !== $confirm_password) {
            $message = 'รหัสผ่านใหม่ไม่ตรงกัน';
            $message_type = 'danger';
        } elseif (strlen($new_password) < 6) { // ตัวอย่าง: รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร
            $message = 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
            $message_type = 'danger';
        } else {
            // ตรวจสอบรหัสผ่านปัจจุบัน
            $stmt_check_pw = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt_check_pw->bind_param('i', $user_id);
            $stmt_check_pw->execute();
            $result_check_pw = $stmt_check_pw->get_result();
            if ($row_pw = $result_check_pw->fetch_assoc()) {
                if (password_verify($current_password, $row_pw['password'])) {
                    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt_update_pw = $db->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if ($stmt_update_pw) {
                        $stmt_update_pw->bind_param('si', $hashed_new_password, $user_id);
                        if ($stmt_update_pw->execute()) {
                            $message = 'เปลี่ยนรหัสผ่านสำเร็จแล้ว';
                            $message_type = 'success';
                        } else {
                            $message = 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน: ' . $stmt_update_pw->error;
                            $message_type = 'danger';
                        }
                        $stmt_update_pw->close();
                    } else {
                        $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งเปลี่ยนรหัสผ่าน: ' . $db->error;
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                    $message_type = 'danger';
                }
            } else {
                $message = 'ไม่พบข้อมูลผู้ใช้งาน';
                $message_type = 'danger';
            }
            $stmt_check_pw->close();
        }
    } elseif ($action === 'test_telegram') {
        $test_telegram_chat_id = trim($_POST['test_telegram_chat_id'] ?? '');

        if (empty($test_telegram_chat_id)) {
            $message = 'กรุณากรอก Telegram Chat ID สำหรับทดสอบ';
            $message_type = 'danger';
        } else {
            // ดึง bot token ล่าสุดจาก system_settings
            $current_bot_token = getSystemSetting('telegram_bot_token', '');
            if (empty($current_bot_token)) {
                $message = 'ยังไม่ได้ตั้งค่า Telegram Bot Token ในการตั้งค่าระบบ. กรุณาติดต่อผู้ดูแลระบบ.';
                $message_type = 'danger';
            } else {
                $telegram = new TelegramAPI($current_bot_token);
                $test_message = "✅ การเชื่อมต่อ Telegram Bot สำเร็จ! นี่คือข้อความทดสอบจากระบบลงรับหนังสือราชการของคุณ.";
                $response = $telegram->sendMessage($test_telegram_chat_id, $test_message);

                if ($response['success']) {
                    $message = 'ส่งข้อความทดสอบ Telegram สำเร็จแล้ว! กรุณาตรวจสอบ Telegram ของคุณ';
                    $message_type = 'success';
                } else {
                    $message = 'เกิดข้อผิดพลาดในการส่งข้อความทดสอบ Telegram: ' . htmlspecialchars($response['error']);
                    $message_type = 'danger';
                }
            }
        }
    } elseif ($action === 'test_line') { // เพิ่ม action สำหรับทดสอบ LINE
        $test_line_user_id = trim($_POST['test_line_user_id'] ?? '');

        if (empty($test_line_user_id)) {
            $message = 'กรุณากรอก LINE User ID สำหรับทดสอบ';
            $message_type = 'danger';
        } else {
            // ดึง LINE Channel Access Token ล่าสุดจาก system_settings
            $current_line_access_token = getSystemSetting('line_channel_access_token', '');
            // ดึง LINE Channel Secret ล่าสุดจาก system_settings
            $current_line_secret = getSystemSetting('line_channel_secret', '');

            if (empty($current_line_access_token) || empty($current_line_secret)) {
                $message = 'ยังไม่ได้ตั้งค่า LINE Channel Access Token หรือ Channel Secret ในการตั้งค่าระบบ. กรุณาติดต่อผู้ดูแลระบบ.';
                $message_type = 'danger';
            } else {
                $lineApi = new LineAPI($current_line_access_token, $current_line_secret); // สร้าง LineAPI instance
                $test_message = "✅ การเชื่อมต่อ LINE Messaging API สำเร็จ! นี่คือข้อความทดสอบจากระบบลงรับหนังสือราชการของคุณ.";
                $response = $lineApi->sendMessage($test_line_user_id, $test_message); // ส่งข้อความผ่าน LineAPI

                if ($response['success']) {
                    $message = 'ส่งข้อความทดสอบ LINE สำเร็จแล้ว! กรุณาตรวจสอบ LINE ของคุณ';
                    $message_type = 'success';
                } else {
                    $message = 'เกิดข้อผิดพลาดในการส่งข้อความทดสอบ LINE: ' . htmlspecialchars($response['error']);
                    $message_type = 'danger';
                }
            }
        }
    }
}

// Load Header
$page_title = 'ข้อมูลส่วนตัว';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-user me-2"></i>ข้อมูลส่วนตัว</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-user-edit me-2"></i>แก้ไขข้อมูลส่วนตัว</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_profile">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="username" class="form-label text-white-50">ชื่อผู้ใช้ (ไม่สามารถแก้ไขได้)</label>
                    <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label text-white-50">อีเมล <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="email" name="email" required placeholder="email@example.com" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="first_name" class="form-label text-white-50">ชื่อจริง <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="first_name" name="first_name" required placeholder="ชื่อจริง" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="last_name" class="form-label text-white-50">นามสกุล <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="last_name" name="last_name" required placeholder="นามสกุล" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label for="phone" class="form-label text-white-50">เบอร์โทรศัพท์</label>
                <input type="text" class="form-control" id="phone" name="phone" placeholder="เบอร์โทรศัพท์" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
            </div>
            <div class="mb-3">
                <label for="telegram_chat_id" class="form-label text-white-50">Telegram Chat ID</label>
                <input type="text" class="form-control" id="telegram_chat_id" name="telegram_chat_id" placeholder="ID สำหรับการแจ้งเตือน Telegram (ถ้ามี)" value="<?php echo htmlspecialchars($user_data['telegram_chat_id'] ?? ''); ?>">
                <small class="form-text text-white-50">คุณสามารถหา Chat ID ได้โดยการส่งข้อความหา <a href="https://t.me/userinfobot" target="_blank" class="text-info text-decoration-underline">@userinfobot</a> ใน Telegram</small>
            </div>
            <div class="mb-3">
                <label for="line_user_id" class="form-label text-white-50">LINE User ID</label>
                <input type="text" class="form-control" id="line_user_id" name="line_user_id" placeholder="ID สำหรับการแจ้งเตือน LINE (ถ้ามี)" value="<?php echo htmlspecialchars($user_data['line_user_id'] ?? ''); ?>">
                <small class="form-text text-white-50">คุณสามารถหา User ID ได้โดยเพิ่ม <a href="https://line.me/ti/p/@your_bot_id" target="_blank" class="text-info text-decoration-underline">LINE Official Account ของระบบ</a> เป็นเพื่อนและส่งข้อความหา จากนั้นผู้ดูแลระบบจะสามารถดึง User ID ของคุณได้ หรือใช้เครื่องมือ Hooke/Webhook Test ได้</small>
            </div>
            <button type="submit" class="btn btn-primary-glass">
                <i class="fas fa-save me-2"></i>
                บันทึกข้อมูลส่วนตัว
            </button>
        </form>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg mb-4">
        <h3 class="text-white mb-3"><i class="fas fa-key me-2"></i>เปลี่ยนรหัสผ่าน</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="change_password">
            <div class="mb-3">
                <label for="current_password" class="form-label text-white-50">รหัสผ่านปัจจุบัน <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="current_password" name="current_password" required placeholder="รหัสผ่านปัจจุบัน">
            </div>
            <div class="mb-3">
                <label for="new_password" class="form-label text-white-50">รหัสผ่านใหม่ <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="new_password" name="new_password" required placeholder="รหัสผ่านใหม่ (อย่างน้อย 6 ตัวอักษร)">
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label text-white-50">ยืนยันรหัสผ่านใหม่ <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="ยืนยันรหัสผ่านใหม่">
            </div>
            <button type="submit" class="btn btn-warning-glass">
                <i class="fas fa-unlock-alt me-2"></i>
                เปลี่ยนรหัสผ่าน
            </button>
        </form>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg mb-4">
        <h3 class="text-white mb-3"><i class="fab fa-telegram-plane me-2"></i>ทดสอบ Telegram Chat ID</h3>
        <p class="text-white-50">ใช้สำหรับทดสอบว่า Telegram Chat ID ของคุณถูกต้องและระบบสามารถส่งข้อความแจ้งเตือนไปถึงคุณได้</p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="test_telegram">
            <div class="mb-3">
                <label for="test_telegram_chat_id" class="form-label text-white-50">Telegram Chat ID ของฉัน</label>
                <input type="text" class="form-control" id="test_telegram_chat_id" name="test_telegram_chat_id"
                       placeholder="กรอก Chat ID ของคุณ" value="<?php echo htmlspecialchars($user_data['telegram_chat_id'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn btn-info-glass">
                <i class="fas fa-robot me-2"></i>
                ทดสอบส่งข้อความ Telegram
            </button>
        </form>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <h3 class="text-white mb-3"><i class="fab fa-line me-2"></i>ทดสอบ LINE User ID</h3>
        <p class="text-white-50">ใช้สำหรับทดสอบว่า LINE User ID ของคุณถูกต้องและระบบสามารถส่งข้อความแจ้งเตือนไปถึงคุณได้</p>
        <form method="POST" action="">
            <input type="hidden" name="action" value="test_line">
            <div class="mb-3">
                <label for="test_line_user_id" class="form-label text-white-50">LINE User ID ของฉัน</label>
                <input type="text" class="form-control" id="test_line_user_id" name="test_line_user_id"
                       placeholder="กรอก LINE User ID ของคุณ" value="<?php echo htmlspecialchars($user_data['line_user_id'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn btn-success-glass">
                <i class="fas fa-robot me-2"></i>
                ทดสอบส่งข้อความ LINE
            </button>
        </form>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>