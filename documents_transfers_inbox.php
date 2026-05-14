<?php
// documents_transfers_inbox.php
// สำหรับผู้ใช้รับเอกสารที่ถูกส่งต่อมา และสามารถตอบกลับสถานะได้

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/TelegramAPI.php'; // สำหรับแจ้งเตือน Telegram

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

// --- Handle Transfer Response (Accept/Reject/Needs Revision) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond_transfer') {
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);
    $response_status = trim($_POST['response_status'] ?? '');
    $response_message = trim($_POST['response_message'] ?? '');

    if ($transfer_id <= 0 || !in_array($response_status, ['accepted', 'rejected', 'needs_revision'])) {
        $message = 'ข้อมูลการตอบกลับไม่ถูกต้อง';
        $message_type = 'danger';
    } else {
        // ตรวจสอบว่าเป็นเอกสารที่ถูกส่งต่อมาหาผู้ใช้คนนี้จริงหรือไม่ และสถานะยังเป็น pending
        $stmt_check = $db->prepare("SELECT id FROM document_transfers WHERE id = ? AND to_user_id = ? AND status = 'pending'");
        if ($stmt_check) {
            $stmt_check->bind_param('ii', $transfer_id, $user_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows > 0) {
                // อัปเดตสถานะใน document_transfers
                $stmt_update = $db->prepare("UPDATE document_transfers SET status = ?, response_message = ?, responded_at = CURRENT_TIMESTAMP WHERE id = ?");
                if ($stmt_update) {
                    $stmt_update->bind_param('ssi', $response_status, $response_message, $transfer_id);
                    if ($stmt_update->execute()) {
                        $message = 'บันทึกการตอบกลับสำเร็จแล้ว';
                        $message_type = 'success';

                        // ส่งแจ้งเตือน Telegram ไปยังผู้ส่งเดิม
                        $telegram_api = new TelegramAPI();
                        $telegram_api->notifyTransferResponse($transfer_id);

                    } else {
                        $message = 'เกิดข้อผิดพลาดในการบันทึกการตอบกลับ: ' . $stmt_update->error;
                        $message_type = 'danger';
                    }
                    $stmt_update->close();
                } else {
                    $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งตอบกลับ: ' . $db->error;
                    $message_type = 'danger';
                }
            } else {
                $message = 'ไม่พบเอกสารที่รอดำเนินการ หรือคุณไม่มีสิทธิ์ตอบกลับเอกสารฉบับนี้';
                $message_type = 'danger';
            }
            $stmt_check->close();
        } else {
            $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งตรวจสอบ: ' . $db->error;
            $message_type = 'danger';
        }
    }
}

// --- Fetch Incoming Transfers for Display ---
$incoming_transfers = [];
$status_conditions = '';
if ($filter_status === 'pending') {
    $status_conditions = " AND dt.status = 'pending'";
} elseif ($filter_status === 'accepted') {
    $status_conditions = " AND dt.status = 'accepted'";
} elseif ($filter_status === 'rejected') {
    $status_conditions = " AND dt.status = 'rejected'";
} elseif ($filter_status === 'needs_revision') {
    $status_conditions = " AND dt.status = 'needs_revision'";
}

$query = "SELECT dt.id AS transfer_id, dt.document_id, dt.from_user_id, dt.message AS transfer_message, dt.status AS transfer_status, dt.response_message, dt.sent_at, dt.responded_at,
                 d.document_number, d.title, d.description, d.file_path, d.original_filename, d.due_date, d.priority,
                 u.first_name AS from_user_first_name, u.last_name AS from_user_last_name
          FROM document_transfers dt
          JOIN documents d ON dt.document_id = d.id
          JOIN users u ON dt.from_user_id = u.id
          WHERE dt.to_user_id = ? " . $status_conditions . "
          ORDER BY dt.sent_at DESC";

$stmt = $db->prepare($query);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $incoming_transfers[] = $row;
    }
    $result->free();
    $stmt->close();
} else {
    $message = 'ไม่สามารถดึงข้อมูลเอกสารที่ถูกส่งต่อได้: ' . $db->error;
    $message_type = 'danger';
}

// Load Header
$page_title = 'เอกสารที่ถูกส่งต่อ';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-exchange-alt me-2"></i>เอกสารที่ถูกส่งต่อ</h1>
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
        <div class="btn-group" role="group" aria-label="Filter transfer status">
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
            <a href="documents_transfers_inbox.php" class="btn <?php echo (!isset($_GET['status']) || $_GET['status'] === '') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-list-alt me-1"></i> ทั้งหมด
            </a>
        </div>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <h3 class="text-white mb-3"><i class="fas fa-envelope-open-text me-2"></i>รายการเอกสารที่ถูกส่งต่อ (<?php echo ($filter_status === 'pending') ? 'รอดำเนินการ' : (($filter_status === 'accepted') ? 'ยอมรับแล้ว' : (($filter_status === 'rejected') ? 'ปฏิเสธ' : (($filter_status === 'needs_revision') ? 'ต้องการแก้ไข' : 'ทั้งหมด'))); ?>)</h3>
        <?php if (empty($incoming_transfers)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ไม่พบเอกสารที่ถูกส่งต่อในสถานะนี้
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">เลขที่หนังสือ</th>
                            <th scope="col" class="text-white-50">ชื่อเรื่อง</th>
                            <th scope="col" class="text-white-50">ผู้ส่งต้นฉบับ</th>
                            <th scope="col" class="text-white-50">ความสำคัญ</th>
                            <th scope="col" class="text-white-50">กำหนดส่ง</th>
                            <th scope="col" class="text-white-50">ข้อความจากผู้ส่ง</th>
                            <th scope="col" class="text-white-50">สถานะ</th>
                            <th scope="col" class="text-white-50">ตอบกลับ</th>
                            <th scope="col" class="text-white-50">ส่งเมื่อ</th>
                            <th scope="col" class="text-white-50">ไฟล์</th>
                            <th scope="col" class="text-white-50">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incoming_transfers as $index => $transfer): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($transfer['document_number']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['title']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['from_user_first_name'] . ' ' . $transfer['from_user_last_name']); ?></td>
                                <td>
                                    <?php
                                        $priority_class = '';
                                        switch ($transfer['priority']) {
                                            case 'urgent': $priority_class = 'bg-danger'; break;
                                            case 'high': $priority_class = 'bg-warning'; break;
                                            case 'medium': $priority_class = 'bg-info'; break;
                                            case 'low': $priority_class = 'bg-success'; break;
                                        }
                                    ?>
                                    <span class="badge <?php echo $priority_class; ?>"><?php echo htmlspecialchars($transfer['priority']); ?></span>
                                </td>
                                <td><?php echo formatThaiDate($transfer['due_date']); ?></td>
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
                                    <?php if ($transfer['transfer_status'] === 'pending'): ?>
                                        <button class="btn btn-info-glass btn-sm me-2" data-bs-toggle="modal" data-bs-target="#respondTransferModal" 
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

<div class="modal fade" id="respondTransferModal" tabindex="-1" aria-labelledby="respondTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glassmorphism">
            <div class="modal-header border-bottom border-white-50">
                <h5 class="modal-title text-white" id="respondTransferModalLabel">ตอบกลับเอกสารที่ถูกส่งต่อ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="respond_transfer">
                    <input type="hidden" name="transfer_id" id="modalTransferId">
                    <div class="mb-3">
                        <label for="modalDocumentTitleDisplay" class="form-label text-white-50">เรื่องเอกสาร:</label>
                        <input type="text" class="form-control" id="modalDocumentTitleDisplay" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="response_status" class="form-label text-white-50">สถานะการตอบกลับ: <span class="text-danger">*</span></label>
                        <select class="form-select" id="response_status" name="response_status" required>
                            <option value="">-- เลือกสถานะ --</option>
                            <option value="accepted">ยอมรับ</option>
                            <option value="rejected">ปฏิเสธ</option>
                            <option value="needs_revision">ต้องการแก้ไข</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="response_message" class="form-label text-white-50">ข้อความตอบกลับ (ถ้ามี):</label>
                        <textarea class="form-control" id="response_message" name="response_message" rows="3" placeholder="กรอกข้อความตอบกลับ เช่น เหตุผลการปฏิเสธ/แก้ไข"></textarea>
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
    // JavaScript for handling the respond transfer modal
    var respondTransferModal = document.getElementById('respondTransferModal');
    respondTransferModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget; // Button that triggered the modal
        var transferId = button.getAttribute('data-transfer-id');
        var documentTitle = button.getAttribute('data-document-title');

        var modalTransferId = respondTransferModal.querySelector('#modalTransferId');
        var modalDocumentTitleDisplay = respondTransferModal.querySelector('#modalDocumentTitleDisplay');
        var responseStatus = respondTransferModal.querySelector('#response_status');
        var responseMessage = respondTransferModal.querySelector('#response_message');

        modalTransferId.value = transferId;
        modalDocumentTitleDisplay.value = documentTitle;
        // Reset fields when opening modal for a new response
        responseStatus.value = ''; 
        responseMessage.value = '';
    });
</script>

<?php
require_once 'includes/footer.php';
?>