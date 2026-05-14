<?php
// documents_transfers_inbox.php (แต่จริงๆ คือ documents_inbox.php)
// สำหรับผู้ใช้รับหนังสือราชการที่ส่งถึงตัวเอง และสามารถกดรับหนังสือได้

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
// แก้ไข: กำหนดค่าเริ่มต้นสำหรับ filter_status ให้เป็น 'all' เพื่อให้ง่ายต่อการจัดการ
$filter_status = $_GET['status'] ?? 'all'; 

// ดึงการตั้งค่าขนาดไฟล์และประเภทไฟล์ที่อนุญาต
$max_file_size = (int)getSystemSetting('max_file_size', 10485760); // Default 10MB
$allowed_file_types_str = getSystemSetting('allowed_file_types', 'pdf,doc,docx,jpg,jpeg,png');
$allowed_file_types_array = array_map('trim', explode(',', strtolower($allowed_file_types_str)));

// --- Handle Bulk Document Receiving ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_receive'])) {
    $selected_documents = $_POST['selected_documents'] ?? [];
    
    if (!empty($selected_documents)) {
        $success_count = 0;
        $error_count = 0;
        $received_titles = [];
        
        foreach ($selected_documents as $document_recipient_id) {
            $document_recipient_id = (int) $document_recipient_id;
            
            // ตรวจสอบว่าเป็นผู้รับเอกสารนี้จริงหรือไม่ และสถานะยังเป็น pending
            $stmt_check = $db->prepare("SELECT dr.id, dr.document_id, dr.recipient_id, d.title 
                                        FROM document_recipients dr
                                        JOIN documents d ON dr.document_id = d.id
                                        WHERE dr.id = ? AND dr.recipient_id = ? AND dr.status = 'pending'");
            if ($stmt_check) {
                $stmt_check->bind_param('ii', $document_recipient_id, $user_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($row_check = $result_check->fetch_assoc()) {
                    // อัปเดตสถานะใน document_recipients
                    $stmt_update = $db->prepare("UPDATE document_recipients SET status = 'received', received_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if ($stmt_update) {
                        $stmt_update->bind_param('i', $document_recipient_id);
                        if ($stmt_update->execute()) {
                            $success_count++;
                            $received_titles[] = $row_check['title'];
                            
                            // ส่งแจ้งเตือน Telegram ไปยังผู้ส่งเอกสาร
                            $telegram_api = new TelegramAPI();
                            $telegram_api->notifyDocumentReceived($row_check['document_id'], $user_id);
                        } else {
                            $error_count++;
                        }
                        $stmt_update->close();
                    } else {
                        $error_count++;
                    }
                } else {
                    $error_count++;
                }
                $stmt_check->close();
            } else {
                $error_count++;
            }
        }
        
        if ($success_count > 0) {
            $message = "รับหนังสือสำเร็จ {$success_count} ฉบับ";
            if ($error_count > 0) {
                $message .= " (มีข้อผิดพลาด {$error_count} ฉบับ)";
            }
            $message_type = 'success';
        } else {
            $message = "ไม่สามารถรับหนังสือใดได้ กรุณาตรวจสอบและลองใหม่";
            $message_type = 'danger';
        }
    } else {
        $message = 'กรุณาเลือกหนังสือที่ต้องการรับอย่างน้อย 1 ฉบับ';
        $message_type = 'warning';
    }
}

// --- Handle Single Document Receiving ---
if (isset($_GET['receive']) && is_numeric($_GET['receive'])) {
    $document_recipient_id = (int) $_GET['receive'];

    // ตรวจสอบว่าเป็นผู้รับเอกสารนี้จริงหรือไม่ และสถานะยังเป็น pending
    $stmt_check = $db->prepare("SELECT dr.id, dr.document_id, dr.recipient_id, d.title 
                                FROM document_recipients dr
                                JOIN documents d ON dr.document_id = d.id
                                WHERE dr.id = ? AND dr.recipient_id = ? AND dr.status = 'pending'");
    if ($stmt_check) {
        $stmt_check->bind_param('ii', $document_recipient_id, $user_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($row_check = $result_check->fetch_assoc()) {
            // อัปเดตสถานะใน document_recipients
            $stmt_update = $db->prepare("UPDATE document_recipients SET status = 'received', received_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($stmt_update) {
                $stmt_update->bind_param('i', $document_recipient_id);
                if ($stmt_update->execute()) {
                    $message = 'คุณได้รับหนังสือราชการเรื่อง "' . htmlspecialchars($row_check['title']) . '" แล้ว';
                    $message_type = 'success';

                    // ส่งแจ้งเตือน Telegram ไปยังผู้ส่งเอกสาร
                    $telegram_api = new TelegramAPI();
                    $telegram_api->notifyDocumentReceived($row_check['document_id'], $user_id);

                } else {
                    $message = 'เกิดข้อผิดพลาดในการรับหนังสือ: ' . $stmt_update->error;
                    $message_type = 'danger';
                }
                $stmt_update->close();
            } else {
                $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งรับหนังสือ: ' . $db->error;
                $message_type = 'danger';
            }
        } else {
            $message = 'ไม่พบหนังสือที่รอดำเนินการ หรือคุณไม่มีสิทธิ์รับหนังสือฉบับนี้';
            $message_type = 'danger';
        }
        $stmt_check->close();
    } else {
        $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งตรวจสอบ: ' . $db->error;
        $message_type = 'danger';
    }
}

// --- Fetch Inbox Documents for Display ---
$inbox_documents = [];
$status_conditions = '';
// แก้ไข Logic การสร้างเงื่อนไขสถานะ
if ($filter_status !== 'all') { // ถ้าไม่ใช่ 'all' ค่อยเพิ่มเงื่อนไขสถานะ
    $status_conditions = " AND dr.status = '" . $db->real_escape_string($filter_status) . "'";
}
// ถ้า $filter_status เป็น 'all', $status_conditions จะยังคงเป็นสตริงว่าง ทำให้ดึงมาทั้งหมด

$query = "SELECT d.id AS document_id, d.document_number, d.title, d.description, d.priority, d.due_date, d.file_path, d.original_filename, d.created_at AS document_created_at,
                 dt.name AS document_type_name,
                 u.first_name AS sender_first_name, u.last_name AS sender_last_name,
                 dr.id AS document_recipient_id, dr.status AS recipient_status, dr.received_at, dr.notes
          FROM document_recipients dr
          JOIN documents d ON dr.document_id = d.id
          LEFT JOIN document_types dt ON d.document_type_id = dt.id
          LEFT JOIN users u ON d.sender_id = u.id
          WHERE dr.recipient_id = ? " . $status_conditions . "
          ORDER BY d.created_at DESC";

$stmt = $db->prepare($query);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $inbox_documents[] = $row;
    }
    $result->free();
    $stmt->close();
} else {
    $message = 'ไม่สามารถดึงข้อมูลหนังสือเข้าได้: ' . $db->error;
    $message_type = 'danger';
}

// นับจำนวนหนังสือที่รอดำเนินการ
$pending_count = 0;
foreach ($inbox_documents as $doc) {
    if ($doc['recipient_status'] === 'pending') {
        $pending_count++;
    }
}

// Load Header
$page_title = 'หนังสือเข้า';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-inbox me-2"></i>หนังสือเข้า</h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success-glass' : ($message_type === 'warning' ? 'warning-glass' : 'danger-glass'); ?> glassmorphism p-3 mb-4 d-flex align-items-center" role="alert">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : ($message_type === 'warning' ? 'fa-exclamation-triangle' : 'fa-exclamation-triangle'); ?> me-2"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="glassmorphism p-4 rounded-lg shadow-lg mb-4">
        <h3 class="text-white mb-3"><i class="fas fa-filter me-2"></i>ตัวกรองสถานะ</h3>
        <div class="btn-group" role="group" aria-label="Filter status">
            <a href="?status=pending" class="btn <?php echo ($filter_status === 'pending') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-hourglass-half me-1"></i> รอดำเนินการ 
                <?php if ($pending_count > 0): ?>
                    <span class="badge bg-danger ms-1"><?php echo $pending_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="?status=received" class="btn <?php echo ($filter_status === 'received') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-check-double me-1"></i> รับแล้ว
            </a>
            <a href="?status=all" class="btn <?php echo ($filter_status === 'all') ? 'btn-primary-glass' : 'btn-secondary-glass'; ?>">
                <i class="fas fa-list-alt me-1"></i> ทั้งหมด
            </a>
        </div>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="text-white mb-0">
                <i class="fas fa-envelope-open-text me-2"></i>รายการหนังสือเข้า 
                (<?php echo ($filter_status === 'pending') ? 'รอดำเนินการ' : (($filter_status === 'received') ? 'รับแล้ว' : 'ทั้งหมด'); ?>)
            </h3>
            <?php if ($pending_count > 0): ?>
                <div class="bulk-actions">
                    <button type="button" class="btn btn-success" id="bulkReceiveBtn" disabled>
                        <i class="fas fa-check-double me-2"></i>รับหนังสือที่เลือก
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($inbox_documents)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ไม่พบหนังสือเข้าในสถานะนี้
            </div>
        <?php else: ?>
            <form id="bulkReceiveForm" method="post" action="">
                <input type="hidden" name="bulk_receive" value="1">
                <div class="table-responsive">
                    <table class="table table-hover table-dark table-striped">
                        <thead>
                            <tr>
                                <?php if ($pending_count > 0): ?>
                                    <th scope="col" class="text-white-50" style="width: 50px;">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="selectAll">
                                            <label class="form-check-label" for="selectAll">
                                                <i class="fas fa-check-square"></i>
                                            </label>
                                        </div>
                                    </th>
                                <?php endif; ?>
                                <th scope="col" class="text-white-50">#</th>
                                <th scope="col" class="text-white-50">เลขที่หนังสือ</th>
                                <th scope="col" class="text-white-50">ชื่อเรื่อง</th>
                                <th scope="col" class="text-white-50">ประเภท</th>
                                <th scope="col" class="text-white-50">ผู้ส่ง</th>
                                <th scope="col" class="text-white-50">ความสำคัญ</th>
                                <th scope="col" class="text-white-50">สถานะ</th>
                                <th scope="col" class="text-white-50">กำหนดส่ง</th>
                                <th scope="col" class="text-white-50">ได้รับเมื่อ</th>
                                <th scope="col" class="text-white-50">ไฟล์</th>
                                <th scope="col" class="text-white-50">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inbox_documents as $index => $doc): ?>
                                <tr>
                                    <?php if ($pending_count > 0): ?>
                                        <td>
                                            <?php if ($doc['recipient_status'] === 'pending'): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input document-checkbox" type="checkbox" 
                                                           name="selected_documents[]" 
                                                           value="<?php echo $doc['document_recipient_id']; ?>" 
                                                           id="doc_<?php echo $doc['document_recipient_id']; ?>">
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <th scope="row"><?php echo $index + 1; ?></th>
                                    <td><?php echo htmlspecialchars($doc['document_number']); ?></td>
                                    <td>
                                        <div class="document-title" title="<?php echo htmlspecialchars($doc['description']); ?>">
                                            <?php echo htmlspecialchars($doc['title']); ?>
                                            <?php if (!empty($doc['description'])): ?>
                                                <i class="fas fa-info-circle ms-1 text-muted" data-bs-toggle="tooltip" 
                                                   title="<?php echo htmlspecialchars($doc['description']); ?>"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($doc['document_type_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($doc['sender_first_name'] . ' ' . $doc['sender_last_name']); ?></td>
                                    <td>
                                        <?php
                                            $priority_class = '';
                                            $priority_text = '';
                                            switch ($doc['priority']) {
                                                case 'urgent': 
                                                    $priority_class = 'bg-danger'; 
                                                    $priority_text = 'ด่วนที่สุด';
                                                    break;
                                                case 'high': 
                                                    $priority_class = 'bg-warning text-dark'; 
                                                    $priority_text = 'ด่วน';
                                                    break;
                                                case 'medium': 
                                                    $priority_class = 'bg-info'; 
                                                    $priority_text = 'ปกติ';
                                                    break;
                                                case 'low': 
                                                    $priority_class = 'bg-success'; 
                                                    $priority_text = 'ไม่ด่วน';
                                                    break;
                                            }
                                        ?>
                                        <span class="badge <?php echo $priority_class; ?>"><?php echo $priority_text; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                            $status_class = '';
                                            $status_text = '';
                                            switch ($doc['recipient_status']) {
                                                case 'pending': 
                                                    $status_class = 'bg-warning text-dark'; 
                                                    $status_text = 'รอดำเนินการ';
                                                    break;
                                                case 'received': 
                                                    $status_class = 'bg-success'; 
                                                    $status_text = 'รับแล้ว';
                                                    break;
                                                case 'rejected': 
                                                    $status_class = 'bg-danger'; 
                                                    $status_text = 'ปฏิเสธ';
                                                    break;
                                            }
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($doc['due_date']) {
                                            $due_date = new DateTime($doc['due_date']);
                                            $now = new DateTime();
                                            $is_overdue = $due_date < $now && $doc['recipient_status'] === 'pending';
                                            $date_class = $is_overdue ? 'text-danger fw-bold' : '';
                                            echo '<span class="' . $date_class . '">' . formatThaiDate($doc['due_date']) . '</span>';
                                            if ($is_overdue) {
                                                echo ' <i class="fas fa-exclamation-triangle text-danger" title="เลยกำหนด"></i>';
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $doc['received_at'] ? formatThaiDateTime($doc['received_at']) : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($doc['file_path'])): ?>
                                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" 
                                               class="btn btn-sm btn-outline-info text-white">
                                                <i class="fas fa-download"></i> ไฟล์
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($doc['recipient_status'] === 'pending'): ?>
                                            <a href="?receive=<?php echo $doc['document_recipient_id']; ?>" 
                                               class="btn btn-success btn-sm" 
                                               onclick="return confirm('คุณต้องการยืนยันการรับหนังสือราชการเรื่อง <?php echo htmlspecialchars($doc['title']); ?> นี้หรือไม่?');">
                                                <i class="fas fa-check"></i> รับหนังสือ
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled>
                                                <i class="fas fa-check"></i> รับแล้ว
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const documentCheckboxes = document.querySelectorAll('.document-checkbox');
    const bulkReceiveBtn = document.getElementById('bulkReceiveBtn');
    const bulkReceiveForm = document.getElementById('bulkReceiveForm');

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    if (selectAllCheckbox && documentCheckboxes.length > 0) {
        // Handle select all checkbox
        selectAllCheckbox.addEventListener('change', function() {
            documentCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBulkReceiveButton();
        });

        // Handle individual checkboxes
        documentCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectAllCheckbox();
                updateBulkReceiveButton();
            });
        });

        // Handle bulk receive button
        if (bulkReceiveBtn) {
            bulkReceiveBtn.addEventListener('click', function() {
                const selectedCount = document.querySelectorAll('.document-checkbox:checked').length;
                if (selectedCount > 0) {
                    if (confirm(`คุณต้องการยืนยันการรับหนังสือราชการที่เลือก ${selectedCount} ฉบับหรือไม่?`)) {
                        bulkReceiveForm.submit();
                    }
                }
            });
        }
    }

    function updateSelectAllCheckbox() {
        const checkedCount = document.querySelectorAll('.document-checkbox:checked').length;
        const totalCount = documentCheckboxes.length;
        
        if (checkedCount === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (checkedCount === totalCount) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.indeterminate = true;
        }
    }

    function updateBulkReceiveButton() {
        const checkedCount = document.querySelectorAll('.document-checkbox:checked').length;
        if (bulkReceiveBtn) {
            bulkReceiveBtn.disabled = checkedCount === 0;
            bulkReceiveBtn.innerHTML = `<i class="fas fa-check-double me-2"></i>รับหนังสือที่เลือก (${checkedCount})`;
        }
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>