<?php
// documents_register.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/TelegramAPI.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDB();
$message = '';
$message_type = '';

$max_file_size = (int)getSystemSetting('max_file_size', 10485760);
$allowed_file_types_str = getSystemSetting('allowed_file_types', 'pdf,doc,docx,jpg,jpeg,png');
$allowed_file_types_array = array_map('trim', explode(',', strtolower($allowed_file_types_str)));


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_number = trim($_POST['document_number'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $document_type_id = (int)($_POST['document_type_id'] ?? 0);
    $priority = $_POST['priority'] ?? 'medium';
    $due_date = NULL;
    if (!empty($_POST['due_date']) && $_POST['due_date'] !== '') {
        $input_date = trim($_POST['due_date']);
        // ตรวจสอบรูปแบบวันที่ก่อนกำหนดค่า
        if (DateTime::createFromFormat('Y-m-d', $input_date) !== false) {
            $due_date = $input_date;
        }
    }
    $recipient_ids = $_POST['recipient_ids'] ?? [];

    $sender_id = $_SESSION['user_id'];

    if (empty($document_number) || empty($title) || empty($document_type_id) || empty($recipient_ids)) {
        $message = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน (เลขที่หนังสือ, ชื่อเรื่อง, ประเภท, และผู้รับ)';
        $message_type = 'danger';
    } elseif (!is_array($recipient_ids) || count($recipient_ids) === 0) {
        $message = 'กรุณาเลือกผู้รับอย่างน้อย 1 คน';
        $message_type = 'danger';
    } else {
        $check_doc_stmt = $db->prepare("SELECT id FROM documents WHERE document_number = ?");
        $check_doc_stmt->bind_param('s', $document_number);
        $check_doc_stmt->execute();
        $check_doc_result = $check_doc_stmt->get_result();
        if ($check_doc_result->num_rows > 0) {
            $message = 'เลขที่หนังสือนี้มีอยู่ในระบบแล้ว กรุณาใช้เลขที่อื่น';
            $message_type = 'danger';
        } else {
            $file_path = NULL;
            $original_filename = NULL;
            $file_size_bytes = NULL;
            $upload_ok = true;

            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['document_file']['tmp_name'];
                $file_name = $_FILES['document_file']['name'];
                $file_size_bytes = $_FILES['document_file']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if ($file_size_bytes > $max_file_size) {
                    $message = 'ขนาดไฟล์ใหญ่เกินกำหนด (สูงสุด ' . ($max_file_size / 1024 / 1024) . ' MB)';
                    $message_type = 'danger';
                    $upload_ok = false;
                } elseif (!in_array($file_ext, $allowed_file_types_array)) {
                    $message = 'ประเภทไฟล์ไม่ได้รับอนุญาต. อนุญาตเฉพาะ: ' . htmlspecialchars($allowed_file_types_str);
                    $message_type = 'danger';
                    $upload_ok = false;
                } else {
                    $upload_dir = 'uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $new_file_name = uniqid('doc_') . '.' . $file_ext;
                    $destination_path = $upload_dir . $new_file_name;

                    if (move_uploaded_file($file_tmp_name, $destination_path)) {
                        $file_path = $destination_path;
                        $original_filename = $file_name;
                    } else {
                        $message = 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์';
                        $message_type = 'danger';
                        $upload_ok = false;
                    }
                }
            }

            if ($upload_ok) {
                $db->begin_transaction();

                try {
                    // 1. Insert into documents table
                    // ตรวจสอบ Type definition string ใหม่: 'sssississi' (10 ตัวอักษร)
                    // s: document_number
                    // s: title
                    // s: description
                    // i: document_type_id
                    // i: sender_id
                    // s: priority
                    // s: due_date
                    // s: file_path
                    // s: original_filename
                    // i: file_size_bytes
                    $stmt_doc = $db->prepare("INSERT INTO documents (document_number, title, description, document_type_id, sender_id, priority, due_date, file_path, original_filename, file_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent')");

                    if (!$stmt_doc) {
                        throw new Exception('เกิดข้อผิดพลาดในการเตรียมคำสั่งเอกสาร: ' . $db->error);
                    }

                    // แก้ไขบรรทัด bind_param ให้มี file_size_bytes และ type string ที่ถูกต้อง
                    $stmt_doc->bind_param(
                        'sssississi',
                        $document_number,
                        $title,
                        $description,
                        $document_type_id,
                        $sender_id,
                        $priority,
                        $due_date,
                        $file_path,
                        $original_filename,
                        $file_size_bytes // นี่คือตัวที่เพิ่มเข้ามา
                    );

                    if (!$stmt_doc->execute()) {
                        throw new Exception('เกิดข้อผิดพลาดในการบันทึกเอกสาร: ' . $stmt_doc->error);
                    }
                    $document_id = $stmt_doc->insert_id;
                    $stmt_doc->close();

                    // 2. Insert into document_recipients table for each recipient
                    $stmt_rec = $db->prepare("INSERT INTO document_recipients (document_id, recipient_id, status) VALUES (?, ?, 'pending')");
                    if (!$stmt_rec) {
                        throw new Exception('เกิดข้อผิดพลาดในการเตรียมคำสั่งผู้รับ: ' . $db->error);
                    }

                    $telegram_api = new TelegramAPI();
                    foreach ($recipient_ids as $rec_id) {
                        $rec_id_int = (int)$rec_id;
                        $stmt_rec->bind_param('ii', $document_id, $rec_id_int);
                        if (!$stmt_rec->execute()) {
                            error_log("Failed to insert recipient {$rec_id_int} for document {$document_id}: " . $stmt_rec->error);
                        } else {
                            $telegram_api->notifyNewDocument($document_id, [$rec_id_int]);
                        }
                    }
                    $stmt_rec->close();

                    $db->commit();
                    $message = 'ลงทะเบียนหนังสือราชการสำเร็จแล้ว และได้แจ้งเตือนผู้รับแล้ว (หากผู้รับตั้งค่า Telegram)';
                    $message_type = 'success';
                } catch (Exception $e) {
                    $db->rollback();
                    $message = 'เกิดข้อผิดพลาดในการลงทะเบียนหนังสือราชการ: ' . $e->getMessage();
                    $message_type = 'danger';
                    if ($file_path && file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }
        }
    }
}


// --- Fetch Document Types for dropdown ---
$document_types = [];
$query_doc_types = "SELECT id, name FROM document_types ORDER BY name ASC";
$result_doc_types = $db->query($query_doc_types);
if ($result_doc_types) {
    while ($row = $result_doc_types->fetch_assoc()) {
        $document_types[] = $row;
    }
    $result_doc_types->free();
}

// --- Fetch Users for recipient selection ---
$recipients = [];
$query_recipients = "SELECT u.id, u.first_name, u.last_name, u.username, u.department_id, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.is_active = 1 AND u.role = 'user' ORDER BY u.first_name ASC";
$result_recipients = $db->query($query_recipients);
if ($result_recipients) {
    while ($row = $result_recipients->fetch_assoc()) {
        $recipients[] = $row;
    }
    $result_recipients->free();
}


// Load Header
$page_title = 'ลงทะเบียนหนังสือราชการ';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-file-upload me-2"></i>ลงทะเบียนหนังสือราชการ</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-file-invoice me-2"></i>ข้อมูลหนังสือราชการ</h3>
        <form method="POST" action="" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="document_number" class="form-label text-white-50">เลขที่หนังสือ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="document_number" name="document_number" required placeholder="เช่น ลว. 123/2568">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="title" class="form-label text-white-50">ชื่อเรื่อง <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="title" name="title" required placeholder="ชื่อเรื่องของหนังสือ">
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label text-white-50">รายละเอียด</label>
                <textarea class="form-control" id="description" name="description" rows="3" placeholder="รายละเอียดเพิ่มเติมเกี่ยวกับหนังสือ (ถ้ามี)"></textarea>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="document_type_id" class="form-label text-white-50">ประเภทหนังสือ <span class="text-danger">*</span></label>
                    <select class="form-select" id="document_type_id" name="document_type_id" required>
                        <option value="">-- เลือกประเภทหนังสือ --</option>
                        <?php foreach ($document_types as $type): ?>
                            <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="priority" class="form-label text-white-50">ความสำคัญ</label>
                    <select class="form-select" id="priority" name="priority">
                        <option value="low">ต่ำ</option>
                        <option value="medium" selected>ปานกลาง</option>
                        <option value="high">สูง</option>
                        <option value="urgent">ด่วนที่สุด</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label for="due_date" class="form-label text-white-50">กำหนดส่ง (ถ้ามี)</label>
                <input type="date" class="form-control" id="due_date" name="due_date">
            </div>

            <div class="mb-3">
                <label for="document_file" class="form-label text-white-50">ไฟล์เอกสาร (แนบ)</label>
                <input class="form-control" type="file" id="document_file" name="document_file">
                <small class="form-text text-white-50">
                    ขนาดไฟล์สูงสุด: <?php echo ($max_file_size / 1024 / 1024) . ' MB'; ?>. ประเภทไฟล์ที่อนุญาต: <?php echo htmlspecialchars($allowed_file_types_str); ?>.
                </small>
            </div>

            <h4 class="text-white mt-4 mb-3"><i class="fas fa-users-check me-2"></i>ผู้รับหนังสือ <span class="text-danger">*</span></h4>
            <div class="mb-3 border border-secondary-subtle rounded p-3 glassmorphism-item">
                <small class="text-white-50 mb-2 d-block">เลือกผู้รับหนังสือ (สามารถเลือกได้มากกว่า 1 คน)</small>
                <div class="row">
                    <?php if (empty($recipients)): ?>
                        <p class="text-white-50">ไม่พบผู้ใช้งานที่สามารถเป็นผู้รับได้ (อาจยังไม่มีผู้ใช้งาน Role 'user')</p>
                    <?php else: ?>
                        <?php foreach ($recipients as $rec): ?>
                            <div class="col-md-4 col-sm-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="recipient_ids[]" value="<?php echo $rec['id']; ?>" id="recipient_<?php echo $rec['id']; ?>">
                                    <label class="form-check-label text-white" for="recipient_<?php echo $rec['id']; ?>">
                                        <?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?>
                                        <small class="text-white-50 d-block"><?php echo htmlspecialchars($rec['department_name'] ?? '-'); ?> (<?php echo htmlspecialchars($rec['username']); ?>)</small>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary-glass mt-3">
                <i class="fas fa-plus-circle me-2"></i>
                ลงทะเบียนหนังสือ
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