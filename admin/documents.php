<?php
// admin/documents_all.php
// สำหรับผู้ดูแลระบบดูเอกสารทั้งหมดในระบบ (ทั้งหนังสือราชการและไฟล์ส่วนตัว)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องเป็น Admin เท่านั้น
requireAdmin();

$db = getDB();
$message = '';
$message_type = '';

// --- Fetch All Documents (Official) for Display ---
$official_documents = [];
$query_official = "SELECT d.id, d.document_number, d.title, d.description, d.priority, d.due_date, d.file_path, d.original_filename, d.file_size, d.status, d.created_at, d.updated_at,
                          dt.name AS document_type_name,
                          u.first_name AS sender_first_name, u.last_name AS sender_last_name
                   FROM documents d
                   LEFT JOIN document_types dt ON d.document_type_id = dt.id
                   LEFT JOIN users u ON d.sender_id = u.id
                   ORDER BY d.created_at DESC";
$result_official = $db->query($query_official);
if ($result_official) {
    while ($row = $result_official->fetch_assoc()) {
        $official_documents[] = $row;
    }
    $result_official->free();
} else {
    $message = 'ไม่สามารถดึงข้อมูลหนังสือราชการได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Fetch All Personal Documents for Display ---
$personal_documents = [];
$query_personal = "SELECT pd.id, pd.title, pd.description, pd.file_path, pd.original_filename, pd.file_size, pd.created_at, pd.updated_at,
                          u.first_name AS uploader_first_name, u.last_name AS uploader_last_name
                   FROM personal_documents pd
                   JOIN users u ON pd.uploader_id = u.id
                   ORDER BY pd.created_at DESC";
$result_personal = $db->query($query_personal);
if ($result_personal) {
    while ($row = $result_personal->fetch_assoc()) {
        $personal_documents[] = $row;
    }
    $result_personal->free();
} else {
    $message .= ($message ? '<br>' : '') . 'ไม่สามารถดึงข้อมูลไฟล์ส่วนตัวได้: ' . $db->error;
    $message_type = ($message_type === 'danger' ? 'danger' : 'warning'); // ถ้ามี error แล้ว ให้เป็น danger
}


// Load Header
$page_title = 'เอกสารทั้งหมด';
require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-book me-2"></i>เอกสารทั้งหมด</h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success-glass' : 'danger-glass'; ?> glassmorphism p-3 mb-4 d-flex align-items-center" role="alert">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4 glassmorphism-item p-2 rounded-lg" id="documentTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link text-white active" id="official-tab" data-bs-toggle="tab" data-bs-target="#officialDocuments" type="button" role="tab" aria-controls="officialDocuments" aria-selected="true">
                <i class="fas fa-file-invoice me-2"></i>หนังสือราชการ
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link text-white" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personalDocuments" type="button" role="tab" aria-controls="personalDocuments" aria-selected="false">
                <i class="fas fa-paperclip me-2"></i>ไฟล์ส่วนตัว
            </button>
        </li>
    </ul>

    <div class="tab-content" id="documentTabsContent">
        <div class="tab-pane fade show active" id="officialDocuments" role="tabpanel" aria-labelledby="official-tab">
            <div class="glassmorphism p-4 rounded-lg shadow-lg">
                <h3 class="text-white mb-3"><i class="fas fa-list me-2"></i>รายการหนังสือราชการทั้งหมด</h3>
                <?php if (empty($official_documents)): ?>
                    <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                        <i class="fas fa-info-circle me-2"></i> ไม่พบข้อมูลหนังสือราชการ
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
                                    <th scope="col" class="text-white-50">ผู้ส่ง</th>
                                    <th scope="col" class="text-white-50">ความสำคัญ</th>
                                    <th scope="col" class="text-white-50">สถานะ</th>
                                    <th scope="col" class="text-white-50">กำหนดส่ง</th>
                                    <th scope="col" class="text-white-50">สร้างเมื่อ</th>
                                    <th scope="col" class="text-white-50">ไฟล์</th>
                                    <th scope="col" class="text-white-50">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($official_documents as $index => $doc): ?>
                                    <tr>
                                        <th scope="row"><?php echo $index + 1; ?></th>
                                        <td><?php echo htmlspecialchars($doc['document_number']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['document_type_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($doc['sender_first_name'] . ' ' . $doc['sender_last_name']); ?></td>
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
                                        <td>
                                            <?php
                                                $status_class = '';
                                                switch ($doc['status']) {
                                                    case 'draft': $status_class = 'bg-secondary'; break;
                                                    case 'sent': $status_class = 'bg-primary'; break;
                                                    case 'completed': $status_class = 'bg-success'; break;
                                                }
                                            ?>
                                            <span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($doc['status']); ?></span>
                                        </td>
                                        <td><?php echo formatThaiDate($doc['due_date']); ?></td>
                                        <td><?php echo formatThaiDateTime($doc['created_at']); ?></td>
                                        <td>
                                            <?php if (!empty($doc['file_path'])): ?>
                                                <a href="<?php echo htmlspecialchars('../' . $doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info text-white">
                                                    <i class="fas fa-download"></i> ไฟล์
                                                </a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-secondary btn-sm" disabled>ไม่มีการจัดการ</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-pane fade" id="personalDocuments" role="tabpanel" aria-labelledby="personal-tab">
            <div class="glassmorphism p-4 rounded-lg shadow-lg">
                <h3 class="text-white mb-3"><i class="fas fa-list me-2"></i>รายการไฟล์ส่วนตัวทั้งหมด</h3>
                <?php if (empty($personal_documents)): ?>
                    <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                        <i class="fas fa-info-circle me-2"></i> ไม่พบข้อมูลไฟล์ส่วนตัว
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-dark table-striped">
                            <thead>
                                <tr>
                                    <th scope="col" class="text-white-50">#</th>
                                    <th scope="col" class="text-white-50">ชื่อเรื่อง</th>
                                    <th scope="col" class="text-white-50">คำอธิบาย</th>
                                    <th scope="col" class="text-white-50">ผู้อัปโหลด</th>
                                    <th scope="col" class="text-white-50">ขนาดไฟล์</th>
                                    <th scope="col" class="text-white-50">สร้างเมื่อ</th>
                                    <th scope="col" class="text-white-50">ไฟล์</th>
                                    <th scope="col" class="text-white-50">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($personal_documents as $index => $doc): ?>
                                    <tr>
                                        <th scope="row"><?php echo $index + 1; ?></th>
                                        <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['description'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($doc['uploader_first_name'] . ' ' . $doc['uploader_last_name']); ?></td>
                                        <td><?php echo round(($doc['file_size'] ?? 0) / 1024, 2); ?> KB</td>
                                        <td><?php echo formatThaiDateTime($doc['created_at']); ?></td>
                                        <td>
                                            <?php if (!empty($doc['file_path'])): ?>
                                                <a href="<?php echo htmlspecialchars('../' . $doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-info text-white">
                                                    <i class="fas fa-download"></i> ไฟล์
                                                </a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-secondary btn-sm" disabled>ไม่มีการจัดการ</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>