<?php
// admin/reports.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องเป็น Admin เท่านั้น
requireAdmin();

$db = getDB();
$message = '';
$message_type = '';

// ตรวจสอบการเรียก export
if (isset($_GET['export']) && $_GET['export'] === 'users') {
    // Export ผู้ใช้งาน
    exportUsersToExcel($db);
} elseif (isset($_GET['export']) && $_GET['export'] === 'documents') {
    // Export เอกสาร (จะพัฒนาในภายหลังเมื่อมีข้อมูลเอกสาร)
    exportDocumentsToExcel($db);
}

// ฟังก์ชันสำหรับ Export ผู้ใช้งานเป็น Excel
function exportUsersToExcel($db) {
    // ตั้งค่า header สำหรับดาวน์โหลดไฟล์ Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="users_report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0'); // For IE 9

    // เปิด PHP output stream
    $output = fopen('php://output', 'w');

    // ตั้งค่า UTF-8 BOM สำหรับ Excel เพื่อรองรับภาษาไทย
    fwrite($output, "\xEF\xBB\xBF");

    // หัวตาราง
    fputcsv($output, [
        'ID',
        'ชื่อผู้ใช้',
        'ชื่อจริง',
        'นามสกุล',
        'อีเมล',
        'เบอร์โทรศัพท์',
        'แผนก',
        'บทบาท',
        'Telegram Chat ID',
        'สถานะ',
        'สร้างเมื่อ',
        'อัปเดตล่าสุด'
    ]);

    // ดึงข้อมูลผู้ใช้งาน
    $query = "SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, d.name AS department_name, u.role, u.telegram_chat_id, u.is_active, u.created_at, u.updated_at 
              FROM users u LEFT JOIN departments d ON u.department_id = d.id 
              ORDER BY u.id ASC";
    $result = $db->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $status = $row['is_active'] ? 'เปิดใช้งาน' : 'ระงับ';
            $department_name = $row['department_name'] ?? '-';
            $phone = $row['phone'] ?? '-';
            $telegram_chat_id = $row['telegram_chat_id'] ?? '-';
            
            fputcsv($output, [
                $row['id'],
                $row['username'],
                $row['first_name'],
                $row['last_name'],
                $row['email'],
                $phone,
                $department_name,
                $row['role'],
                $telegram_chat_id,
                $status,
                formatThaiDateTime($row['created_at']),
                formatThaiDateTime($row['updated_at'])
            ]);
        }
        $result->free();
    } else {
        // ในกรณีที่มีข้อผิดพลาดในการ query, จะไม่สามารถแสดงข้อความบนหน้าเว็บได้โดยตรง
        // อาจจะต้องเขียนลง log file แทน หรือให้หน้าเว็บแสดงข้อผิดพลาดก่อนที่จะพยายาม export
        error_log("Error fetching users for export: " . $db->error);
    }

    fclose($output);
    exit(); // ต้อง exit เพื่อหยุดการทำงานของ PHP หลังจากส่งไฟล์
}

// ฟังก์ชันสำหรับ Export เอกสารเป็น Excel (Placeholder, จะพัฒนาจริงเมื่อระบบลงทะเบียนเอกสารเสร็จ)
function exportDocumentsToExcel($db) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="documents_report_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($output, [
        'ID',
        'เลขที่เอกสาร',
        'ชื่อเรื่อง',
        'ประเภทเอกสาร',
        'ผู้ส่ง',
        'ลำดับความสำคัญ',
        'สถานะ',
        'วันที่กำหนดส่ง',
        'สร้างเมื่อ',
        'อัปเดตล่าสุด'
    ]);

    // ตัวอย่างการดึงข้อมูลเอกสาร (จะแก้ไขเมื่อมีตาราง documents พร้อมใช้งาน)
    $query = "SELECT d.id, d.document_number, d.title, dt.name AS document_type_name, 
                     u.first_name AS sender_first_name, u.last_name AS sender_last_name,
                     d.priority, d.status, d.due_date, d.created_at, d.updated_at
              FROM documents d
              LEFT JOIN document_types dt ON d.document_type_id = dt.id
              LEFT JOIN users u ON d.sender_id = u.id
              ORDER BY d.created_at DESC";
    $result = $db->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['document_number'],
                $row['title'],
                $row['document_type_name'] ?? '-',
                ($row['sender_first_name'] . ' ' . $row['sender_last_name']) ?? '-',
                $row['priority'],
                $row['status'],
                formatThaiDate($row['due_date']),
                formatThaiDateTime($row['created_at']),
                formatThaiDateTime($row['updated_at'])
            ]);
        }
        $result->free();
    } else {
        error_log("Error fetching documents for export: " . $db->error);
    }

    fclose($output);
    exit();
}


// Load Header
$page_title = 'รายงานและ Export ข้อมูล';
require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-chart-line me-2"></i>รายงานและ Export ข้อมูล</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-file-export me-2"></i>Export ข้อมูลเป็น Excel</h3>
        <p class="text-white-50">เลือกประเภทข้อมูลที่คุณต้องการ Export เป็นไฟล์ Excel:</p>
        <div class="d-grid gap-2 col-md-6 mx-auto">
            <a href="?export=users" class="btn btn-primary-glass btn-lg mb-2">
                <i class="fas fa-users me-2"></i> Export ผู้ใช้งานทั้งหมด
            </a>
            <a href="?export=documents" class="btn btn-primary-glass btn-lg mb-2">
                <i class="fas fa-file-alt me-2"></i> Export เอกสารทั้งหมด
            </a>
            </div>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <h3 class="text-white mb-3"><i class="fas fa-info-circle me-2"></i>ข้อมูลเพิ่มเติม</h3>
        <p class="text-white-50">หน้านี้จะใช้สำหรับสร้างรายงานและ Export ข้อมูลสำคัญของระบบ หากมีการเพิ่มข้อมูลเอกสารในอนาคต สามารถเพิ่มรายงานที่นี่ได้</p>
    </div>

</div>

<?php
require_once '../includes/footer.php';
?>