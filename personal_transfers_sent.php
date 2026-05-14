<?php
// personal_transfers_sent.php
// สำหรับผู้ใช้ดูประวัติการส่งไฟล์ส่วนตัวของตนเอง และสถานะการตอบกลับ

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องล็อกอินและเป็น User หรือ Admin
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$db = getDB();
$message = '';
$message_type = '';
$user_id = $_SESSION['user_id'];
$filter_status = $_GET['status'] ?? 'all'; // Filter for transfer status

// --- Fetch Personal Documents Uploaded by Current User ---
// ส่วนนี้ยังคงดึงไฟล์ต้นฉบับของผู้ส่ง ไม่ใช่ไฟล์ตอบกลับ
$uploaded_personal_documents = [];
$query_uploaded = "SELECT pd.id AS personal_document_id, pd.title, pd.description, pd.file_path, pd.original_filename, pd.file_size, pd.created_at
                   FROM personal_documents pd
                   WHERE pd.uploader_id = ?
                   ORDER BY pd.created_at DESC";
$stmt_uploaded = $db->prepare($query_uploaded);
if ($stmt_uploaded) {
    $stmt_uploaded->bind_param('i', $user_id);
    $stmt_uploaded->execute();
    $result_uploaded = $stmt_uploaded->get_result();
    while ($row = $result_uploaded->fetch_assoc()) {
        $uploaded_personal_documents[] = $row;
    }
    $result_uploaded->free();
    $stmt_uploaded->close();
} else {
    $message = 'ไม่สามารถดึงข้อมูลไฟล์ส่วนตัวที่อัปโหลดได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Fetch Sent Personal Document Transfers and their Status ---
$sent_personal_transfers = [];
$transfer_status_conditions = '';
if ($filter_status === 'pending') {
    $transfer_status_conditions = " AND pdt.status = 'pending'";
} elseif ($filter_status === 'accepted') {
    $transfer_status_conditions = " AND pdt.status = 'accepted'";
} elseif ($filter_status === 'rejected') {
    $transfer_status_conditions = " AND pdt.status = 'rejected'";
} elseif ($filter_status === 'needs_revision') {
    $transfer_status_conditions = " AND pdt.status = 'needs_revision'";
}

$query_sent_transfers = "SELECT pdt.id AS transfer_id, pdt.personal_document_id, pdt.from_user_id, pdt.to_user_id, pdt.message AS transfer_message, pdt.status AS transfer_status, pdt.response_message, pdt.sent_at, pdt.responded_at,
                                pd.title AS personal_doc_title, pd.original_filename, pd.file_path, -- ไฟล์ต้นฉบับ
                                u.first_name AS to_user_first_name, u.last_name AS to_user_last_name,
                                pdt.response_file_path, pdt.response_original_filename, pdt.response_file_size -- เพิ่มคอลัมน์สำหรับไฟล์ตอบกลับ
                         FROM personal_document_transfers pdt
                         JOIN personal_documents pd ON pdt.personal_document_id = pd.id
                         JOIN users u ON pdt.to_user_id = u.id
                         WHERE pdt.from_user_id = ? " . $transfer_status_conditions . "
                         ORDER BY pdt.sent_at DESC";

$stmt_sent_transfers = $db->prepare($query_sent_transfers);
if ($stmt_sent_transfers) {
    $stmt_sent_transfers->bind_param('i', $user_id);
    $stmt_sent_transfers->execute();
    $result_sent_transfers = $stmt_sent_transfers->get_result();
    while ($row = $result_sent_transfers->fetch_assoc()) {
        $sent_personal_transfers[] = $row;
    }
    $result_sent_transfers->free();
    $stmt_sent_transfers->close();
} else {
    $message = 'ไม่สามารถดึงข้อมูลประวัติการส่งไฟล์ส่วนตัวได้: ' . $db->error;
    $message_type = 'danger';
}


// Load Header
$page_title = 'ประวัติส่งไฟล์ส่วนตัว';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-file-export me-2"></i>ประวัติส่งไฟล์ส่วนตัว</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-filter me-2"></i>ตัวกรองสถานะการส่งต่อ</h3>
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

        <?php if (empty($sent_personal_transfers)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ไม่พบประวัติการส่งไฟล์ส่วนตัวในสถานะนี้
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">ชื่อเรื่องไฟล์</th>
                            <th scope="col" class="text-white-50">ส่งถึง</th>
                            <th scope="col" class="text-white-50">ข้อความ</th>
                            <th scope="col" class="text-white-50">สถานะ</th>
                            <th scope="col" class="text-white-50">ตอบกลับ</th>
                            <th scope="col" class="text-white-50">ไฟล์ต้นฉบับ</th> <th scope="col" class="text-white-50">ไฟล์ตอบกลับ</th> <th scope="col" class="text-white-50">ส่งเมื่อ</th>
                            <th scope="col" class="text-white-50">ตอบกลับเมื่อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sent_personal_transfers as $index => $transfer): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($transfer['personal_doc_title']); ?></td>
                                <td><?php echo htmlspecialchars($transfer['to_user_first_name'] . ' ' . $transfer['to_user_last_name']); ?></td>
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
</div>

<?php
require_once 'includes/footer.php';
?>