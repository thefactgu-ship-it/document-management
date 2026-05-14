<?php
// personal_file_transfer.php
// สำหรับผู้ใช้ในการอัปโหลดและส่งไฟล์ส่วนตัวที่ไม่ใช่หนังสือราชการ

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/TelegramAPI.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องล็อกอินและเป็น User หรือ Admin
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDB();
$message = '';
$message_type = '';
$user_id = $_SESSION['user_id'];

// ดึงการตั้งค่าขนาดไฟล์และประเภทไฟล์ที่อนุญาต
$max_file_size = (int)getSystemSetting('max_file_size', 10485760); // Default 10MB
$allowed_file_types_str = getSystemSetting('allowed_file_types', 'pdf,doc,docx,jpg,jpeg,png,zip,rar,xls,xlsx'); // เพิ่มประเภทไฟล์ทั่วไป
$allowed_file_types_array = array_map('trim', explode(',', strtolower($allowed_file_types_str)));

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $to_user_ids = $_POST['to_user_ids'] ?? []; // Array of recipient IDs for transfer
    $transfer_message = trim($_POST['transfer_message'] ?? '');

    if (empty($title) || empty($to_user_ids) || !is_array($to_user_ids) || count($to_user_ids) === 0) {
        $message = 'กรุณากรอกชื่อเรื่องและเลือกผู้รับอย่างน้อย 1 คน';
        $message_type = 'danger';
    } elseif (!isset($_FILES['personal_file']) || $_FILES['personal_file']['error'] !== UPLOAD_ERR_OK) {
        $message = 'กรุณาเลือกไฟล์เอกสารที่ต้องการส่ง';
        $message_type = 'danger';
    } else {
        $file_tmp_name = $_FILES['personal_file']['tmp_name'];
        $file_name = $_FILES['personal_file']['name'];
        $file_size_bytes = $_FILES['personal_file']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // ตรวจสอบขนาดไฟล์และประเภทไฟล์
        if ($file_size_bytes > $max_file_size) {
            $message = 'ขนาดไฟล์ใหญ่เกินกำหนด (สูงสุด ' . ($max_file_size / 1024 / 1024) . ' MB)';
            $message_type = 'danger';
        } elseif (!in_array($file_ext, $allowed_file_types_array)) {
            $message = 'ประเภทไฟล์ไม่ได้รับอนุญาต. อนุญาตเฉพาะ: ' . htmlspecialchars($allowed_file_types_str);
            $message_type = 'danger';
        } else {
            $upload_dir = 'personal_uploads/'; // แยกโฟลเดอร์สำหรับไฟล์ส่วนตัว
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $new_file_name = uniqid('personal_doc_') . '.' . $file_ext;
            $destination_path = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp_name, $destination_path)) {
                $db->begin_transaction();

                try {
                    // 1. Insert into personal_documents table
                    $stmt_personal_doc = $db->prepare("INSERT INTO personal_documents (title, description, file_path, original_filename, file_size, uploader_id) VALUES (?, ?, ?, ?, ?, ?)");
                    if (!$stmt_personal_doc) {
                        throw new Exception('เกิดข้อผิดพลาดในการเตรียมคำสั่งบันทึกไฟล์: ' . $db->error);
                    }
                    $stmt_personal_doc->bind_param('ssssii', $title, $description, $destination_path, $file_name, $file_size_bytes, $user_id);
                    if (!$stmt_personal_doc->execute()) {
                        throw new Exception('เกิดข้อผิดพลาดในการบันทึกไฟล์: ' . $stmt_personal_doc->error);
                    }
                    $personal_document_id = $stmt_personal_doc->insert_id;
                    $stmt_personal_doc->close();

                    // 2. Insert into personal_document_transfers table for each recipient
                    $stmt_transfer = $db->prepare("INSERT INTO personal_document_transfers (personal_document_id, from_user_id, to_user_id, message, status) VALUES (?, ?, ?, ?, 'pending')");
                    if (!$stmt_transfer) {
                        throw new Exception('เกิดข้อผิดพลาดในการเตรียมคำสั่งส่งต่อ: ' . $db->error);
                    }

                    $telegram_api = new TelegramAPI();
                    foreach ($to_user_ids as $to_user_id) {
                        $to_user_id_int = (int)$to_user_id;
                        if ($to_user_id_int === $user_id) {
                             error_log("Attempt to self-transfer personal document by user ID: $user_id for doc ID: $personal_document_id - skipped.");
                             continue; // ข้ามการส่งให้ตัวเอง
                        }
                        $stmt_transfer->bind_param('iiis', $personal_document_id, $user_id, $to_user_id_int, $transfer_message);
                        if (!$stmt_transfer->execute()) {
                            error_log("Failed to insert personal document transfer to user {$to_user_id_int} for doc {$personal_document_id}: " . $stmt_transfer->error);
                        } else {
                            // แจ้งเตือน Telegram
                            // ต้องสร้าง notifyPersonalDocumentTransfer ใน TelegramAPI.php
                            $transfer_id = $stmt_transfer->insert_id;
                            $telegram_api->notifyPersonalDocumentTransfer($transfer_id); // เรียกใช้ฟังก์ชันใหม่
                        }
                    }
                    $stmt_transfer->close();

                    $db->commit();
                    $message = 'ส่งไฟล์เอกสารส่วนตัวสำเร็จแล้ว และได้แจ้งเตือนผู้รับแล้ว (หากผู้รับตั้งค่า Telegram)';
                    $message_type = 'success';
                    // Clear form fields on success
                    $_POST = [];
                    $_FILES = [];
                } catch (Exception $e) {
                    $db->rollback();
                    $message = 'เกิดข้อผิดพลาดในการส่งไฟล์เอกสารส่วนตัว: ' . $e->getMessage();
                    $message_type = 'danger';
                    if ($destination_path && file_exists($destination_path)) {
                        unlink($destination_path); // ลบไฟล์ที่อัปโหลดไปแล้วหากเกิดข้อผิดพลาดใน Transaction
                    }
                }
            }
        }
    }
}

// --- Fetch Users for recipient selection (all active users except sender himself) ---
$users_for_transfer = [];
$query_users = "SELECT u.id, u.first_name, u.last_name, u.username, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.is_active = 1 AND u.id != ? ORDER BY u.first_name ASC";
$stmt_users = $db->prepare($query_users);
if ($stmt_users) {
    $stmt_users->bind_param('i', $user_id);
    $stmt_users->execute();
    $result_users = $stmt_users->get_result();
    while ($row = $result_users->fetch_assoc()) {
        $users_for_transfer[] = $row;
    }
    $result_users->free();
    $stmt_users->close();
}


// Load Header
$page_title = 'ส่งไฟล์ส่วนตัว';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-file-upload me-2"></i>ส่งไฟล์ส่วนตัว</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-paperclip me-2"></i>อัปโหลดและส่งไฟล์</h3>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="title" class="form-label text-white-50">ชื่อเรื่อง/หัวข้อไฟล์ <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="title" name="title" required placeholder="ชื่อเรื่องของไฟล์นี้">
            </div>
            <div class="mb-3">
                <label for="description" class="form-label text-white-50">คำอธิบาย</label>
                <textarea class="form-control" id="description" name="description" rows="3" placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับไฟล์"></textarea>
            </div>
            <div class="mb-3">
                <label for="personal_file" class="form-label text-white-50">เลือกไฟล์ <span class="text-danger">*</span></label>
                <input class="form-control" type="file" id="personal_file" name="personal_file" required>
                <small class="form-text text-white-50">
                    ขนาดไฟล์สูงสุด: <?php echo ($max_file_size / 1024 / 1024) . ' MB'; ?>. ประเภทไฟล์ที่อนุญาต: <?php echo htmlspecialchars($allowed_file_types_str); ?>.
                </small>
            </div>

            <h4 class="text-white mt-4 mb-3"><i class="fas fa-share-alt me-2"></i>ส่งถึงผู้ใช้งาน <span class="text-danger">*</span></h4>
            <div class="mb-3 border border-secondary-subtle rounded p-3 glassmorphism-item">
                <small class="text-white-50 mb-2 d-block">เลือกผู้รับไฟล์ (สามารถเลือกได้มากกว่า 1 คน)</small>
                <div class="row">
                    <?php if (empty($users_for_transfer)): ?>
                        <p class="text-white-50">ไม่พบผู้ใช้งานอื่นที่สามารถรับไฟล์ได้</p>
                    <?php else: ?>
                        <?php foreach ($users_for_transfer as $u): ?>
                            <div class="col-md-4 col-sm-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="to_user_ids[]" value="<?php echo $u['id']; ?>" id="to_user_<?php echo $u['id']; ?>">
                                    <label class="form-check-label text-white" for="to_user_<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                                        <small class="text-white-50 d-block"><?php echo htmlspecialchars($u['department_name'] ?? '-'); ?> (<?php echo htmlspecialchars($u['username']); ?>)</small>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label for="transfer_message" class="form-label text-white-50">ข้อความถึงผู้รับ (ถ้ามี):</label>
                <textarea class="form-control" id="transfer_message" name="transfer_message" rows="3" placeholder="ข้อความแนบไปกับไฟล์"></textarea>
            </div>

            <button type="submit" class="btn btn-primary-glass mt-3">
                <i class="fas fa-paper-plane me-2"></i>
                ส่งไฟล์
            </button>
            <button type="reset" class="btn btn-secondary-glass mt-3">
                <i class="fas fa-undo me-2"></i>
                ล้างฟอร์ม
            </button>
        </form>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>