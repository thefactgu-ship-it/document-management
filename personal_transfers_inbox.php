<?php
// personal_transfers_inbox.php
// สำหรับผู้ใช้รับไฟล์ส่วนตัวที่ถูกส่งต่อมา และสามารถตอบกลับสถานะได้

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
$filter_status = $_GET['status'] ?? 'pending'; // Default to show pending transfers

// ดึงการตั้งค่าขนาดไฟล์และประเภทไฟล์ที่อนุญาต
$max_file_size = (int)getSystemSetting('max_file_size', 10485760); // Default 10MB
$allowed_file_types_str = getSystemSetting('allowed_file_types', 'pdf,doc,docx,jpg,jpeg,png,zip,rar,xls,xlsx'); //
$allowed_file_types_array = array_map('trim', explode(',', strtolower($allowed_file_types_str)));

// --- Handle Transfer Response (Accept/Reject/Needs Revision) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond_personal_transfer') {
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);
    $response_status = trim($_POST['response_status'] ?? '');
    $response_message = trim($_POST['response_message'] ?? '');

    // เพิ่มตัวแปรสำหรับไฟล์ตอบกลับ
    $response_file_path = NULL;
    $response_original_filename = NULL;
    $response_file_size_bytes = NULL;
    $upload_ok = true;

    if ($transfer_id <= 0 || !in_array($response_status, ['accepted', 'rejected', 'needs_revision'])) {
        $message = 'ข้อมูลการตอบกลับไม่ถูกต้อง';
        $message_type = 'danger';
        $upload_ok = false;
    } else {
        // Handle file upload if status is 'needs_revision'
        if ($response_status === 'needs_revision' && isset($_FILES['response_file']) && $_FILES['response_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp_name = $_FILES['response_file']['tmp_name'];
            $file_name = $_FILES['response_file']['name'];
            $file_size_bytes = $_FILES['response_file']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if ($file_size_bytes > $max_file_size) {
                $message = 'ขนาดไฟล์ตอบกลับใหญ่เกินกำหนด (สูงสุด ' . ($max_file_size / 1024 / 1024) . ' MB)';
                $message_type = 'danger';
                $upload_ok = false;
            } elseif (!in_array($file_ext, $allowed_file_types_array)) {
                $message = 'ประเภทไฟล์ตอบกลับไม่ได้รับอนุญาต. อนุญาตเฉพาะ: ' . htmlspecialchars($allowed_file_types_str);
                $message_type = 'danger';
                $upload_ok = false;
            } else {
                $upload_dir = 'personal_response_uploads/'; // โฟลเดอร์สำหรับไฟล์ตอบกลับส่วนตัว
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $new_file_name = uniqid('pres_') . '.' . $file_ext;
                $destination_path = $upload_dir . $new_file_name;

                if (move_uploaded_file($file_tmp_name, $destination_path)) {
                    $response_file_path = $destination_path;
                    $response_original_filename = $file_name;
                    $response_file_size_bytes = $file_size_bytes;
                } else {
                    $message = 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์ตอบกลับ';
                    $message_type = 'danger';
                    $upload_ok = false;
                }
            }
        } elseif ($response_status === 'needs_revision' && (!isset($_FILES['response_file']) || $_FILES['response_file']['error'] !== UPLOAD_ERR_OK)) {
            // Optional: If needs_revision, make file required. Uncomment if needed.
            // $message = 'กรุณาแนบไฟล์เอกสารที่ต้องการแก้ไข';
            // $message_type = 'danger';
            // $upload_ok = false;
        }
    }

    if ($upload_ok) {
        // ตรวจสอบว่าเป็นเอกสารที่ถูกส่งต่อมาหาผู้ใช้คนนี้จริงหรือไม่ และสถานะยังเป็น pending
        $stmt_check = $db->prepare("SELECT id FROM personal_document_transfers WHERE id = ? AND to_user_id = ? AND status = 'pending'");
        if ($stmt_check) {
            $stmt_check->bind_param('ii', $transfer_id, $user_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                // อัปเดตสถานะใน personal_document_transfers
                $stmt_update = $db->prepare("UPDATE personal_document_transfers SET status = ?, response_message = ?, response_file_path = ?, response_original_filename = ?, response_file_size = ?, responded_at = CURRENT_TIMESTAMP WHERE id = ?");
                if ($stmt_update) {
                    $stmt_update->bind_param('ssssii', $response_status, $response_message, $response_file_path, $response_original_filename, $response_file_size_bytes, $transfer_id);
                    if ($stmt_update->execute()) {
                        $message = 'บันทึกการตอบกลับสำเร็จแล้ว';
                        $message_type = 'success';

                        // ส่งแจ้งเตือน Telegram ไปยังผู้ส่งเดิม
                        $telegram_api = new TelegramAPI();
                        $telegram_api->notifyPersonalTransferResponse($transfer_id);

                    } else {
                        $message = 'เกิดข้อผิดพลาดในการบันทึกการตอบกลับ: ' . $stmt_update->error;
                        $message_type = 'danger';
                        if ($response_file_path && file_exists($response_file_path)) {
                            unlink($response_file_path);
                        }
                    }
                    $stmt_update->close();
                } else {
                    $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งตอบกลับ: ' . $db->error;
                    $message_type = 'danger';
                    if ($response_file_path && file_exists($response_file_path)) {
                        unlink($response_file_path);
                    }
                }
            } else {
                $message = 'ไม่พบเอกสารที่รอดำเนินการ หรือคุณไม่มีสิทธิ์ตอบกลับเอกสารฉบับนี้';
                $message_type = 'danger';
                if ($response_file_path && file_exists($response_file_path)) {
                    unlink($response_file_path);
                }
            }
            $stmt_check->close();
        } else {
            $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งตรวจสอบ: ' . $db->error;
            $message_type = 'danger';
            if ($response_file_path && file_exists($response_file_path)) {
                unlink($response_file_path);
            }
        }
    }
}

// --- Fetch Incoming Personal Document Transfers for Display ---
$incoming_personal_transfers = [];
$status_conditions = '';
if ($filter_status === 'pending') {
    $status_conditions = " AND pdt.status = 'pending'";
} elseif ($filter_status === 'accepted') {
    $status_conditions = " AND pdt.status = 'accepted'";
} elseif ($filter_status === 'rejected') {
    $status_conditions = " AND pdt.status = 'rejected'";
} elseif ($filter_status === 'needs_revision') {
    $status_conditions = " AND pdt.status = 'needs_revision'";
}

$query = "SELECT pdt.id AS transfer_id, pdt.personal_document_id, pdt.from_user_id, pdt.message AS transfer_message, pdt.status AS transfer_status, pdt.response_message, pdt.sent_at, pdt.responded_at,
                 pd.title, pd.description, pd.file_path, pd.original_filename, pd.file_size,
                 u.first_name AS from_user_first_name, u.last_name AS from_user_last_name,
                 pdt.response_file_path, pdt.response_original_filename, pdt.response_file_size -- เพิ่มคอลัมน์ไฟล์ตอบกลับ
          FROM personal_document_transfers pdt
          JOIN personal_documents pd ON pdt.personal_document_id = pd.id
          JOIN users u ON pdt.from_user_id = u.id
          WHERE pdt.to_user_id = ? " . $status_conditions . "
          ORDER BY pdt.sent_at DESC";

$stmt = $db->prepare($query);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $incoming_personal_transfers[] = $row;
    }
    $result->free();
    $stmt->close();
} else {
    $message = 'ไม่สามารถดึงข้อมูลไฟล์ส่วนตัวที่ถูกส่งต่อได้: ' . $db->error;
    $message_type = 'danger';
}

// Load Header
$page_title = 'ไฟล์ส่วนตัวที่ได้รับ';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-file-import me-2"></i>ไฟล์ส่วนตัวที่ได้รับ</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-filter me-2"></i>ตัวกรองสถานะ</h3>
        <div class="btn-group" role="group" aria-label="Filter status">
            <a href="?status=pending" class="btn <?php echo ($filter_status === 'pending') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-hourglass-half me-1"></i> รอดำเนินการ
            </a>
            <a href="?status=accepted" class="btn <?php echo ($filter_status === 'accepted') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-check-circle me-1"></i> ยอมรับแล้ว
            </a>
            <a href="?status=rejected" class="btn <?php echo ($filter_status === 'rejected') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-times-circle me-1"></i> ปฏิเสธ
            </a>
            <a href="?status=needs_revision" class="btn <?php echo ($filter_status === 'needs_revision') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-exclamation-circle me-1"></i> ต้องการแก้ไข
            </a>
            <a href="personal_transfers_inbox.php" class="btn <?php echo (!isset($_GET['status']) || $_GET['status'] === '') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-list-alt me-1"></i> ทั้งหมด
            </a>
        </div>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <h3 class="text-white mb-3"><i class="fas fa-box-open me-2"></i>รายการไฟล์ส่วนตัวที่ได้รับ (<?php echo ($filter_status === 'pending') ? 'รอดำเนินการ' : (($filter_status === 'accepted') ? 'ยอมรับแล้ว' : (($filter_status === 'rejected') ? 'ปฏิเสธ' : (($filter_status === 'needs_revision') ? 'ต้องการแก้ไข' : 'ทั้งหมด'))); ?>)</h3>
        <?php if (empty($incoming_personal_transfers)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ไม่พบไฟล์ส่วนตัวที่ถูกส่งต่อมาในสถานะนี้
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">ชื่อเรื่องไฟล์</th>
                            <th scope="col" class="text-white-50">ผู้ส่ง</th>
                            <th scope="col" class="text-white-50">ข้อความจากผู้ส่ง</th>
                            <th scope="col" class="text-white-50">สถานะ</th>
                            <th scope="col" class="text-white-50">ตอบกลับ</th>
                            <th scope="col" class="text-white-50">ส่งเมื่อ</th>
                            <th scope="col" class="text-white-50">ไฟล์</th>
                            <th scope="col" class="text-white-50">ไฟล์ตอบกลับ</th> <th scope="col" class="text-white-50">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incoming_personal_transfers as $index => $transfer): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($transfer['title']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['from_user_first_name'] . ' ' . $transfer['from_user_last_name']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['transfer_message'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                        $status_class = '';
                                        $status_text = '';
                                        switch ($transfer['transfer_status']) {
                                            case 'pending': $status_class = 'bg-secondary'; $status_text = 'รอดำเนินการ'; break;
                                            case 'accepted': $status_class = 'bg-success'; $status_text = 'ยอมรับแล้ว'; break;
                                            case 'rejected': $status_class = 'bg-danger'; $status_text = 'ปฏิเสธ'; break;
                                            case 'needs_revision': $status_class = 'bg-warning'; $status_text = 'ต้องการแก้ไข'; break;
                                            default: $status_class = 'bg-info'; $status_text = htmlspecialchars($transfer['transfer_status']); break;
                                        }
                                    ?>
                                    <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($transfer['response_message'] ?? '-'); ?></td>
                                <td><?php echo formatThaiDateTime($transfer['sent_at']); ?></td>
                                <td>
                                    <?php if (!empty($transfer['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($transfer['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info text-white">
                                            <i class="fas fa-download"></i> ไฟล์
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($transfer['response_file_path'])): ?> <a href="<?php echo htmlspecialchars($transfer['response_file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-warning text-white">
                                            <i class="fas fa-file-download"></i> ไฟล์ตอบกลับ
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($transfer['transfer_status'] === 'pending'): ?>
                                        <button class="btn btn-info-glass btn-sm me-2" data-bs-toggle="modal" data-bs-target="#respondPersonalTransferModal" 
                                                data-transfer-id="<?php echo $transfer['transfer_id']; ?>" 
                                                data-document-title="<?php echo htmlspecialchars($transfer['title']); ?>">
                                            <i class="fas fa-reply"></i> ตอบกลับ
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-check-circle"></i> ตอบกลับแล้ว</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="respondPersonalTransferModal" tabindex="-1" aria-labelledby="respondPersonalTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glassmorphism">
            <div class="modal-header border-bottom border-white-50">
                <h5 class="modal-title text-white" id="respondPersonalTransferModalLabel">ตอบกลับไฟล์ส่วนตัวที่ถูกส่งต่อ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data"> <div class="modal-body">
                    <input type="hidden" name="action" value="respond_personal_transfer">
                    <input type="hidden" name="transfer_id" id="modalPersonalTransferId">
                    <div class="mb-3">
                        <label for="modalPersonalDocumentTitleDisplay" class="form-label text-white-50">เรื่องไฟล์:</label>
                        <input type="text" class="form-control" id="modalPersonalDocumentTitleDisplay" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="personal_response_status" class="form-label text-white-50">สถานะการตอบกลับ: <span class="text-danger">*</span></label>
                        <select class="form-select" id="personal_response_status" name="response_status" required>
                            <option value="">-- เลือกสถานะ --</option>
                            <option value="accepted">ยอมรับ</option>
                            <option value="rejected">ปฏิเสธ</option>
                            <option value="needs_revision">ต้องการแก้ไข</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="personal_response_message" class="form-label text-white-50">ข้อความตอบกลับ (ถ้ามี):</label>
                        <textarea class="form-control" id="personal_response_message" name="response_message" rows="3" placeholder="กรอกข้อความตอบกลับ"></textarea>
                    </div>
                    <div class="mb-3" id="personalResponseFileSection">
                        <label for="personal_response_file" class="form-label text-white-50">ไฟล์เอกสารตอบกลับ (ถ้ามี):</label>
                        <input class="form-control" type="file" id="personal_response_file" name="response_file">
                        <small class="form-text text-white-50">
                            ขนาดไฟล์สูงสุด: <?php echo ($max_file_size / 1024 / 1024) . ' MB'; ?>. ประเภทไฟล์ที่อนุญาต: <?php echo htmlspecialchars($allowed_file_types_str); ?>.
                        </small>
                    </div>
                </div>
                <div class="modal-footer border-top border-white-50">
                    <button type="button" class="btn btn-secondary-glass" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-primary-glass"><i class="fas fa-paper-plane me-2"></i>ส่งการตอบกลับ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // JavaScript for handling the respond personal transfer modal
    var respondPersonalTransferModal = document.getElementById('respondPersonalTransferModal');
    var personalResponseStatusSelect = respondPersonalTransferModal.querySelector('#personal_response_status');
    var personalResponseFileSection = respondPersonalTransferModal.querySelector('#personalResponseFileSection');
    var personalResponseFile = respondPersonalTransferModal.querySelector('#personal_response_file');

    // Function to toggle file input required status
    function togglePersonalResponseFileRequired() {
        if (personalResponseStatusSelect.value === 'needs_revision') {
            // personalResponseFile.setAttribute('required', 'required'); // Uncomment if file is mandatory for needs_revision
            personalResponseFileSection.style.display = 'block';
        } else {
            personalResponseFile.removeAttribute('required');
            personalResponseFileSection.style.display = 'none'; // Hide section
            personalResponseFile.value = ''; // Clear selected file
        }
    }

    // Event listener for modal show
    respondPersonalTransferModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var transferId = button.getAttribute('data-transfer-id');
        var documentTitle = button.getAttribute('data-document-title');

        var modalPersonalTransferId = respondPersonalTransferModal.querySelector('#modalPersonalTransferId');
        var modalPersonalDocumentTitleDisplay = respondPersonalTransferModal.querySelector('#modalPersonalDocumentTitleDisplay');
        var personalResponseMessage = respondPersonalTransferModal.querySelector('#personal_response_message');

        modalPersonalTransferId.value = transferId;
        modalPersonalDocumentTitleDisplay.value = documentTitle;
        personalResponseStatusSelect.value = ''; // Reset status
        personalResponseMessage.value = ''; // Reset message
        personalResponseFile.value = ''; // Reset file input

        togglePersonalResponseFileRequired(); // Ensure correct initial state
    });

    // Event listener for status change
    personalResponseStatusSelect.addEventListener('change', togglePersonalResponseFileRequired);
</script>

<?php
require_once 'includes/footer.php';
?>