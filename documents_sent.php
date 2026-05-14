<?php
// documents_sent.php
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
$user_id = $_SESSION['user_id'];
$filter_status = $_GET['status'] ?? 'all';

// --- Handle Document Transfer ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transfer_document') {
    $document_id = (int)($_POST['document_id'] ?? 0);
    $to_user_id = (int)($_POST['to_user_id'] ?? 0);
    $transfer_message = trim($_POST['transfer_message'] ?? '');

    if ($document_id <= 0 || $to_user_id <= 0) {
        $message = 'ข้อมูลเอกสารหรือผู้รับปลายทางไม่ถูกต้อง';
        $message_type = 'danger';
    } elseif ($to_user_id === $user_id) {
        $message = 'ไม่สามารถส่งเอกสารให้ตัวเองได้';
        $message_type = 'danger';
    } else {
        $check_doc_stmt = $db->prepare("SELECT id FROM documents WHERE id = ? AND sender_id = ?");
        $check_doc_stmt->bind_param('ii', $document_id, $user_id);
        $check_doc_stmt->execute();
        $check_doc_result = $check_doc_stmt->get_result();
        if ($check_doc_result->num_rows === 0) {
            $message = 'ไม่พบเอกสารที่คุณมีสิทธิ์ส่งต่อ';
            $message_type = 'danger';
        } else {
            $stmt_transfer = $db->prepare("INSERT INTO document_transfers (document_id, from_user_id, to_user_id, message, status) VALUES (?, ?, ?, ?, 'pending')");
            if ($stmt_transfer) {
                $stmt_transfer->bind_param('iiss', $document_id, $user_id, $to_user_id, $transfer_message);
                if ($stmt_transfer->execute()) {
                    $transfer_id = $stmt_transfer->insert_id;
                    $message = 'ส่งเอกสารสำเร็จแล้ว';
                    $message_type = 'success';

                    $telegram_api = new TelegramAPI();
                    $telegram_api->notifyDocumentTransfer($transfer_id);

                } else {
                    $message = 'เกิดข้อผิดพลาดในการส่งเอกสาร: ' . $stmt_transfer->error;
                    $message_type = 'danger';
                }
                $stmt_transfer->close();
            } else {
                $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งส่งต่อ: ' . $db->error;
                $message_type = 'danger';
            }
        }
        $check_doc_stmt->close();
    }
}

// --- Fetch Sent Documents (Original Documents) for Display ---
$sent_documents = [];
$query = "SELECT d.id AS document_id, d.document_number, d.title, d.description, d.priority, d.due_date, d.file_path, d.original_filename, d.created_at,
                 dt.name AS document_type_name
          FROM documents d
          LEFT JOIN document_types dt ON d.document_type_id = dt.id
          WHERE d.sender_id = ?
          ORDER BY d.created_at DESC";

$stmt = $db->prepare($query);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sent_documents[] = $row;
    }
    $result->free();
    $stmt->close();
} else {
    $message = 'ไม่สามารถดึงข้อมูลหนังสือที่ส่งออกได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Fetch Sent Transfers and their Status (if any) ---
$sent_transfers = [];
$transfer_status_conditions = '';
if ($filter_status === 'pending') {
    $transfer_status_conditions = " AND dt.status = 'pending'";
} elseif ($filter_status === 'accepted') {
    $transfer_status_conditions = " AND dt.status = 'accepted'";
} elseif ($filter_status === 'rejected') {
    $transfer_status_conditions = " AND dt.status = 'rejected'";
} elseif ($filter_status === 'needs_revision') {
    $transfer_status_conditions = " AND dt.status = 'needs_revision'";
}

$query_transfers = "SELECT dt.id AS transfer_id, dt.document_id, dt.from_user_id, dt.to_user_id, dt.message AS transfer_message, dt.status AS transfer_status, dt.response_message, dt.sent_at, dt.responded_at,
                           d.document_number, d.title AS document_title, d.original_filename, d.file_path, -- ไฟล์ต้นฉบับ
                           u.first_name AS to_user_first_name, u.last_name AS to_user_last_name,
                           dt.response_file_path, dt.response_original_filename, dt.response_file_size -- เพิ่มคอลัมน์สำหรับไฟล์ตอบกลับ
                    FROM document_transfers dt
                    JOIN documents d ON dt.document_id = d.id
                    JOIN users u ON dt.to_user_id = u.id
                    WHERE dt.from_user_id = ? " . $transfer_status_conditions . "
                    ORDER BY dt.sent_at DESC";

$stmt_transfers = $db->prepare($query_transfers);
if ($stmt_transfers) {
    $stmt_transfers->bind_param('i', $user_id);
    $stmt_transfers->execute();
    $result_transfers = $stmt_transfers->get_result();
    while ($row = $result_transfers->fetch_assoc()) {
        $sent_transfers[] = $row;
    }
    $result_transfers->free();
    $stmt_transfers->close();
}


// --- Fetch Users for transfer destination dropdown (all users except sender himself) ---
$users_for_transfer = [];
$query_users_transfer = "SELECT u.id, u.first_name, u.last_name, u.username, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.is_active = 1 AND u.id != ? ORDER BY u.first_name ASC";
$stmt_users_transfer = $db->prepare($query_users_transfer);
if ($stmt_users_transfer) {
    $stmt_users_transfer->bind_param('i', $user_id);
    $stmt_users_transfer->execute();
    $result_users_transfer = $stmt_users_transfer->get_result();
    while ($row = $result_users_transfer->fetch_assoc()) {
        $users_for_transfer[] = $row;
    }
    $result_users_transfer->free();
    $stmt_users_transfer->close();
}


// Load Header
$page_title = 'หนังสือที่ส่งออก';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-paper-plane me-2"></i>หนังสือที่ส่งออก</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-exchange-alt me-2"></i>ประวัติการส่งต่อเอกสาร</h3>
        <div class="btn-group mb-3" role="group" aria-label="Filter transfer status">
            <a href="?status=all" class="btn <?php echo ($filter_status === 'all') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-list-alt me-1"></i> ทั้งหมด
            </a>
            <a href="?status=pending" class="btn <?php echo ($filter_status === 'pending') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-hourglass-start me-1"></i> รอดำเนินการ
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
        </div>

        <?php if (empty($sent_transfers)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ไม่พบประวัติการส่งต่อเอกสารในสถานะนี้
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">เลขที่หนังสือ</th>
                            <th scope="col" class="text-white-50">เรื่อง</th>
                            <th scope="col" class="text-white-50">ส่งถึง</th>
                            <th scope="col" class="text-white-50">สถานะ</th>
                            <th scope="col" class="text-white-50">ข้อความ</th>
                            <th scope="col" class="text-white-50">ตอบกลับ</th>
                            <th scope="col" class="text-white-50">ไฟล์ต้นฉบับ</th> <th scope="col" class="text-white-50">ไฟล์ตอบกลับ</th> <th scope="col" class="text-white-50">ส่งเมื่อ</th>
                            <th scope="col" class="text-white-50">ตอบกลับเมื่อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sent_transfers as $index => $transfer): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($transfer['document_number']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['document_title']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['to_user_first_name'] . ' ' . $transfer['to_user_last_name']); ?></td>
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
                                <td><?php echo htmlspecialchars($transfer['transfer_message'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($transfer['response_message'] ?? '-'); ?></td>
                                <td>
                                    <?php if (!empty($transfer['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($transfer['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info text-white">
                                            <i class="fas fa-download"></i> ไฟล์ต้นฉบับ
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
                                <td><?php echo formatThaiDateTime($transfer['sent_at']); ?></td>
                                <td><?php echo formatThaiDateTime($transfer['responded_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <h3 class="text-white mb-3"><i class="fas fa-upload me-2"></i>รายการหนังสือที่ลงทะเบียน (ต้นฉบับ)</h3>
        <?php if (empty($sent_documents)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> คุณยังไม่ได้ลงทะเบียนหนังสือใดๆ
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">เลขที่หนังสือ</th>
                            <th scope="col" class="text-white-50">ชื่อเรื่อง</th>
                            <th scope="col" class="text-white-50">ประเภท</th>
                            <th scope="col" class="text-white-50">ความสำคัญ</th>
                            <th scope="col" class="text-white-50">สร้างเมื่อ</th>
                            <th scope="col" class="text-white-50">ไฟล์</th>
                            <th scope="col" class="text-white-50">ส่งต่อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sent_documents as $index => $doc): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($doc['document_number']); ?></td>
                                <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                <td><?php echo htmlspecialchars($doc['document_type_name'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                        $priority_class = '';
                                        switch ($doc['priority']) {
                                            case 'urgent': $priority_class = 'bg-danger'; break;
                                            case 'high': $priority_class = 'bg-warning'; break;
                                            case 'medium': $priority_class = 'bg-info'; break;
                                            case 'low': $priority_class = 'bg-success'; break;
                                        }
                                    ?>
                                    <span class="badge <?php echo $priority_class; ?>"><?php echo htmlspecialchars($doc['priority']); ?></span>
                                </td>
                                <td><?php echo formatThaiDateTime($doc['created_at']); ?></td>
                                <td>
                                    <?php if (!empty($doc['file_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info text-white">
                                            <i class="fas fa-download"></i> ไฟล์
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary-glass btn-sm" data-bs-toggle="modal" data-bs-target="#transferDocumentModal" 
                                            data-document-id="<?php echo $doc['document_id']; ?>" 
                                            data-document-title="<?php echo htmlspecialchars($doc['title']); ?>">
                                        <i class="fas fa-share"></i> ส่งต่อ
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="transferDocumentModal" tabindex="-1" aria-labelledby="transferDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glassmorphism">
            <div class="modal-header border-bottom border-white-50">
                <h5 class="modal-title text-white" id="transferDocumentModalLabel">ส่งต่อเอกสาร</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="transfer_document">
                    <input type="hidden" name="document_id" id="modalDocumentId">
                    <div class="mb-3">
                        <label for="modalDocumentTitle" class="form-label text-white-50">เรื่องเอกสาร:</label>
                        <input type="text" class="form-control" id="modalDocumentTitle" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="to_user_id" class="form-label text-white-50">ส่งถึงผู้ใช้งาน: <span class="text-danger">*</span></label>
                        <select class="form-select" id="to_user_id" name="to_user_id" required>
                            <option value="">-- เลือกผู้รับ --</option>
                            <?php foreach ($users_for_transfer as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['department_name'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="transfer_message" class="form-label text-white-50">ข้อความ (ถ้ามี):</label>
                        <textarea class="form-control" id="transfer_message" name="transfer_message" rows="3" placeholder="ข้อความถึงผู้รับเอกสาร"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-white-50">
                    <button type="button" class="btn btn-secondary-glass" data-bs-dismiss="modal">ปิด</button>
                    <button type="submit" class="btn btn-primary-glass"><i class="fas fa-paper-plane me-2"></i>ส่งต่อ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // JavaScript for handling the transfer modal
    var transferDocumentModal = document.getElementById('transferDocumentModal');
    transferDocumentModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget; // Button that triggered the modal
        var documentId = button.getAttribute('data-document-id');
        var documentTitle = button.getAttribute('data-document-title');

        var modalDocumentId = transferDocumentModal.querySelector('#modalDocumentId');
        var modalDocumentTitle = transferDocumentModal.querySelector('#modalDocumentTitle');

        modalDocumentId.value = documentId;
        modalDocumentTitle.value = documentTitle;
    });
</script>

<?php
require_once 'includes/footer.php';
?>