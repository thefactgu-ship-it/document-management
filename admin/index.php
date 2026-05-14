<?php
// admin/index.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php'; // สำหรับตรวจสอบสิทธิ์การเข้าถึง

// ตรวจสอบว่าผู้ใช้ล็อกอินหรือไม่ และมีสิทธิ์เป็น admin หรือไม่
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ../login.php');
    exit();
}

// โหลด header
$page_title = 'ภาพรวมระบบ (Admin)';
require_once '../includes/header.php';

// เชื่อมต่อฐานข้อมูล
$db = getDB();

// ดึงข้อมูลภาพรวม (ตัวอย่าง)
$total_users = 0;
$total_documents = 0;
$total_departments = 0;
$total_document_types = 0;

// จำนวนผู้ใช้งานทั้งหมด
$query_users = "SELECT COUNT(id) AS total_users FROM users WHERE is_active = 1";
$result_users = $db->query($query_users);
if ($result_users) {
    $row_users = $result_users->fetch_assoc();
    $total_users = $row_users['total_users'];
}

// จำนวนเอกสารทั้งหมด (สถานะ sent หรือ completed)
$query_documents = "SELECT COUNT(id) AS total_documents FROM documents WHERE status IN ('sent', 'completed')";
$result_documents = $db->query($query_documents);
if ($result_documents) {
    $row_documents = $result_documents->fetch_assoc();
    $total_documents = $row_documents['total_documents'];
}

// จำนวนแผนกทั้งหมด
$query_departments = "SELECT COUNT(id) AS total_departments FROM departments";
$result_departments = $db->query($query_departments);
if ($result_departments) {
    $row_departments = $result_departments->fetch_assoc();
    $total_departments = $row_departments['total_departments'];
}

// จำนวนประเภทเอกสารทั้งหมด
$query_document_types = "SELECT COUNT(id) AS total_document_types FROM document_types";
$result_document_types = $db->query($query_document_types);
if ($result_document_types) {
    $row_document_types = $result_document_types->fetch_assoc();
    $total_document_types = $row_document_types['total_document_types'];
}

?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4">
                <i class="fas fa-tachometer-alt me-2"></i>ภาพรวมระบบ
            </h1>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="glassmorphism p-4 rounded-lg shadow-lg h-100 d-flex flex-column justify-content-between">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-uppercase text-white-50 fw-bold mb-1">
                            ผู้ใช้งานทั้งหมด
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_users); ?> คน</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-users"></i></div>
                </div>
                <div class="mt-3">
                    <a href="users.php" class="text-white-50 text-decoration-none small stretched-link">
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
                            เอกสารในระบบ
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_documents); ?> ฉบับ</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-file-alt"></i></div>
                </div>
                <div class="mt-3">
                    <a href="documents.php" class="text-white-50 text-decoration-none small stretched-link">
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
                            แผนกทั้งหมด
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_departments); ?> แผนก</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-building"></i></div>
                </div>
                <div class="mt-3">
                    <a href="departments.php" class="text-white-50 text-decoration-none small stretched-link">
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
                            ประเภทเอกสาร
                        </div>
                        <div class="h2 mb-0 text-white"><?php echo number_format($total_document_types); ?> ประเภท</div>
                    </div>
                    <div class="h1 text-white-50"><i class="fas fa-tags"></i></div>
                </div>
                <div class="mt-3">
                    <a href="document_types.php" class="text-white-50 text-decoration-none small stretched-link">
                        ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="glassmorphism p-4 rounded-lg shadow-lg">
                <h3 class="text-white mb-3"><i class="fas fa-cogs me-2"></i>การจัดการระบบ</h3>
                <div class="list-group">
                    <a href="users.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-user-tie me-2"></i> จัดการผู้ใช้งาน
                    </a>
                    <a href="departments.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-building me-2"></i> จัดการแผนก
                    </a>
                    <a href="document_types.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-file-invoice me-2"></i> จัดการประเภทหนังสือราชการ
                    </a>
                    <a href="system_settings.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-sliders-h me-2"></i> ตั้งค่าระบบ
                    </a>
                    <a href="reports.php" class="list-group-item list-group-item-action glassmorphism-item text-white border-0 mb-2 rounded">
                        <i class="fas fa-chart-line me-2"></i> รายงานและ Export ข้อมูล
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
// โหลด footer
require_once '../includes/footer.php';
?>