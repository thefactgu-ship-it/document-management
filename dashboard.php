<?php
// dashboard.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}
if (isAdmin()) {
    header('Location: admin/index.php');
    exit();
}

$db = getDB();

$user_id = $_SESSION['user_id'];

$total_inbox_pending = 0;
$total_inbox_received = 0;
$total_sent_documents = 0;
$total_transfers_pending = 0;
$total_transfers_needs_revision = 0;
$total_personal_inbox_pending = 0; // เพิ่ม: สำหรับไฟล์ส่วนตัวที่รอดำเนินการ
$total_personal_sent_pending = 0; // เพิ่ม: สำหรับไฟล์ส่วนตัวที่ส่งออกและรอดำเนินการ/แก้ไข

// จำนวนหนังสือเข้าที่รอดำเนินการ (pending)
$query_inbox_pending = "SELECT COUNT(dr.id) AS total_pending
                        FROM document_recipients dr
                        WHERE dr.recipient_id = ? AND dr.status = 'pending'";
$stmt_inbox_pending = $db->prepare($query_inbox_pending);
if ($stmt_inbox_pending) {
    $stmt_inbox_pending->bind_param('i', $user_id);
    $stmt_inbox_pending->execute();
    $result_inbox_pending = $stmt_inbox_pending->get_result();
    $row_inbox_pending = $result_inbox_pending->fetch_assoc();
    $total_inbox_pending = $row_inbox_pending['total_pending'];
    $stmt_inbox_pending->close();
}

// จำนวนหนังสือเข้าที่รับแล้ว (received)
$query_inbox_received = "SELECT COUNT(dr.id) AS total_received
                         FROM document_recipients dr
                         WHERE dr.recipient_id = ? AND dr.status = 'received'";
$stmt_inbox_received = $db->prepare($query_inbox_received);
if ($stmt_inbox_received) {
    $stmt_inbox_received->bind_param('i', $user_id);
    $stmt_inbox_received->execute();
    $result_inbox_received = $stmt_inbox_received->get_result();
    $row_inbox_received = $result_inbox_received->fetch_assoc();
    $total_inbox_received = $row_inbox_received['total_received'];
    $stmt_inbox_received->close();
}

// จำนวนหนังสือที่ส่งออกไป (sender)
$query_sent_documents = "SELECT COUNT(d.id) AS total_sent_documents
                         FROM documents d
                         WHERE d.sender_id = ? AND d.status = 'sent'";
$stmt_sent_documents = $db->prepare($query_sent_documents);
if ($stmt_sent_documents) {
    $stmt_sent_documents->bind_param('i', $user_id);
    $stmt_sent_documents->execute();
    $result_sent_documents = $stmt_sent_documents->get_result();
    $row_sent_documents = $result_sent_documents->fetch_assoc();
    $total_sent_documents = $row_sent_documents['total_sent_documents'];
    $stmt_sent_documents->close();
}

// จำนวนเอกสารที่ถูกส่งต่อ (document_transfers) และรอดำเนินการ/ต้องการแก้ไข
$query_transfers_pending = "SELECT COUNT(dt.id) AS total_pending_transfers
                            FROM document_transfers dt
                            WHERE dt.to_user_id = ? AND dt.status = 'pending'"; // นับที่ส่งมาหาเรา
$stmt_transfers_pending = $db->prepare($query_transfers_pending);
if ($stmt_transfers_pending) {
    $stmt_transfers_pending->bind_param('i', $user_id);
    $stmt_transfers_pending->execute();
    $result_transfers_pending = $stmt_transfers_pending->get_result();
    $row_transfers_pending = $result_transfers_pending->fetch_assoc();
    $total_transfers_pending = $row_transfers_pending['total_pending_transfers'];
    $stmt_transfers_pending->close();
}

// เพิ่ม: จำนวนไฟล์ส่วนตัวที่ถูกส่งต่อมาหารอดำเนินการ
$query_personal_inbox_pending = "SELECT COUNT(pdt.id) AS total_personal_pending
                                 FROM personal_document_transfers pdt
                                 WHERE pdt.to_user_id = ? AND pdt.status = 'pending'";
$stmt_personal_inbox_pending = $db->prepare($query_personal_inbox_pending);
if ($stmt_personal_inbox_pending) {
    $stmt_personal_inbox_pending->bind_param('i', $user_id);
    $stmt_personal_inbox_pending->execute();
    $result_personal_inbox_pending = $stmt_personal_inbox_pending->get_result();
    $row_personal_inbox_pending = $result_personal_inbox_pending->fetch_assoc();
    $total_personal_inbox_pending = $row_personal_inbox_pending['total_personal_pending'];
    $stmt_personal_inbox_pending->close();
}

// เพิ่ม: จำนวนไฟล์ส่วนตัวที่ส่งออกและรอดำเนินการ/ต้องการแก้ไข
$query_personal_sent_pending = "SELECT COUNT(pdt.id) AS total_personal_sent_pending
                                FROM personal_document_transfers pdt
                                WHERE pdt.from_user_id = ? AND pdt.status IN ('pending', 'needs_revision')";
$stmt_personal_sent_pending = $db->prepare($query_personal_sent_pending);
if ($stmt_personal_sent_pending) {
    $stmt_personal_sent_pending->bind_param('i', $user_id);
    $stmt_personal_sent_pending->execute();
    $result_personal_sent_pending = $stmt_personal_sent_pending->get_result();
    $row_personal_sent_pending = $result_personal_sent_pending->fetch_assoc();
    $total_personal_sent_pending = $row_personal_sent_pending['total_personal_sent_pending'];
    $stmt_personal_sent_pending->close();
}

// โหลด header
$page_title = 'หน้าหลัก (User)';
require_once 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4">
                <i class="fas fa-home me-2"></i>หน้าหลักผู้ใช้งาน
            </h1>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="glassmorphism p-4 rounded-lg shadow-lg h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-white-50 fw-bold mb-1">
                            หนังสือเข้า (รอดำเนินการ)
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_inbox_pending); ?> ฉบับ</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="mt-3">
                    <a href="documents_inbox.php" class="text-white-50 text-decoration-none small stretched-link">
                        ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="glassmorphism p-4 rounded-lg shadow-lg h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-white-50 fw-bold mb-1">
                            หนังสือเข้า (รับแล้ว)
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_inbox_received); ?> ฉบับ</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-check-double"></i></div>
                </div>
                <div class="mt-3">
                    <a href="documents_inbox.php?status=received" class="text-white-50 text-decoration-none small stretched-link">
                        ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="glassmorphism p-4 rounded-lg shadow-lg h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-white-50 fw-bold mb-1">
                            ไฟล์ส่วนตัว (รอดำเนินการ)
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_personal_inbox_pending); ?> ฉบับ</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-hourglass"></i></div>
                </div>
                <div class="mt-3">
                    <a href="personal_transfers_inbox.php" class="text-white-50 text-decoration-none small stretched-link">
                        ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="glassmorphism p-4 rounded-lg shadow-lg h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-white-50 fw-bold mb-1">
                            ไฟล์ส่วนตัว (ส่งออก/รอดำเนินการ)
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_personal_sent_pending); ?> ฉบับ</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-exchange-alt"></i></div>
                </div>
                <div class="mt-3">
                    <a href="personal_transfers_sent.php" class="text-white-50 text-decoration-none small stretched-link">
                        ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="glassmorphism p-4 rounded-lg shadow-lg">
                <h3 class="text-white mb-3"><i class="fas fa-tasks me-2"></i>การดำเนินการ</h3>
                <div class="list-group">
                    <a href="documents_register.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-file-upload me-2"></i> ลงทะเบียนหนังสือราชการ
                    </a>
                    <a href="documents_inbox.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-inbox me-2"></i> รับหนังสือราชการที่ส่งถึงตัวเอง
                    </a>
                    <a href="documents_all.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-book me-2"></i> หนังสือราชการทั้งหมด
                    </a>
                    <a href="documents_sent.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-paper-plane me-2"></i> หนังสือที่ส่งออก (ราชการ)
                    </a>
                    <a href="personal_file_transfer.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-share-alt me-2"></i> ส่งไฟล์ส่วนตัว
                    </a>
                    <a href="personal_transfers_inbox.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-file-import me-2"></i> ไฟล์ส่วนตัวที่ได้รับ
                    </a>
                    <a href="personal_transfers_sent.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-file-export me-2"></i> ประวัติส่งไฟล์ส่วนตัว
                    </a>
                    <a href="profile.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-user me-2"></i> ข้อมูลส่วนตัว
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
// โหลด footer
require_once 'includes/footer.php';
?>