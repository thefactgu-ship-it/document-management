<?php
// admin/document_types.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องเป็น Admin เท่านั้น
requireAdmin();

$db = getDB();
$message = '';
$message_type = ''; // 'success' or 'danger'

// --- Handle Form Submissions ---

// เพิ่ม/แก้ไข ประเภทเอกสาร
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name)) {
        $message = 'กรุณากรอกชื่อประเภทเอกสาร';
        $message_type = 'danger';
    } else {
        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO document_types (name, description) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('ss', $name, $description);
                if ($stmt->execute()) {
                    $message = 'เพิ่มประเภทเอกสารสำเร็จแล้ว';
                    $message_type = 'success';
                } else {
                    $message = 'เกิดข้อผิดพลาดในการเพิ่มประเภทเอกสาร: ' . $stmt->error;
                    $message_type = 'danger';
                }
                $stmt->close();
            } else {
                $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                $message_type = 'danger';
            }
        } elseif ($action === 'edit') {
            $id = (int) $_POST['id'];
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE document_types SET name = ?, description = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssi', $name, $description, $id);
                    if ($stmt->execute()) {
                        $message = 'แก้ไขประเภทเอกสารสำเร็จแล้ว';
                        $message_type = 'success';
                    } else {
                        $message = 'เกิดข้อผิดพลาดในการแก้ไขประเภทเอกสาร: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                    $stmt->close();
                } else {
                    $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                    $message_type = 'danger';
                }
            } else {
                $message = 'ไม่พบ ID ประเภทเอกสารที่ถูกต้องสำหรับการแก้ไข';
                $message_type = 'danger';
            }
        }
    }
}

// ลบประเภทเอกสาร
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_to_delete = (int) $_GET['delete'];
    $stmt = $db->prepare("DELETE FROM document_types WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id_to_delete);
        if ($stmt->execute()) {
            $message = 'ลบประเภทเอกสารสำเร็จแล้ว';
            $message_type = 'success';
        } else {
            $message = 'เกิดข้อผิดพลาดในการลบประเภทเอกสาร: ' . $stmt->error;
            $message_type = 'danger';
        }
        $stmt->close();
    } else {
        $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
        $message_type = 'danger';
    }
}

// --- Fetch Document Types for Display ---
$document_types = [];
$query = "SELECT id, name, description, created_at, updated_at FROM document_types ORDER BY name ASC";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $document_types[] = $row;
    }
    $result->free();
} else {
    $message = 'ไม่สามารถดึงข้อมูลประเภทเอกสารได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Load Header ---
$page_title = 'จัดการประเภทหนังสือราชการ';
require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-tags me-2"></i>จัดการประเภทหนังสือราชการ</h1>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?> glassmorphism p-3 mb-4 d-flex align-items-center" role="alert">
            <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="glassmorphism p-4 rounded-lg shadow-lg mb-4">
        <h3 class="text-white mb-3"><i class="fas fa-plus-circle me-2"></i>เพิ่ม/แก้ไข ประเภทเอกสาร</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="documentTypeId">

            <div class="mb-3">
                <label for="name" class="form-label text-white-50">ชื่อประเภทเอกสาร <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required placeholder="เช่น หนังสือราชการภายใน">
            </div>
            <div class="mb-3">
                <label for="description" class="form-label text-white-50">คำอธิบาย</label>
                <textarea class="form-control" id="description" name="description" rows="3" placeholder="คำอธิบายเพิ่มเติมเกี่ยวกับประเภทเอกสาร"></textarea>
            </div>
            <button type="submit" class="btn btn-primary-glass">
                <i class="fas fa-save me-2"></i>
                บันทึก
            </button>
            <button type="button" class="btn btn-secondary-glass" onclick="resetForm()">
                <i class="fas fa-undo me-2"></i>
                ล้างฟอร์ม
            </button>
        </form>
    </div>

    <div class="glassmorphism p-4 rounded-lg shadow-lg">
        <h3 class="text-white mb-3"><i class="fas fa-list me-2"></i>รายการประเภทหนังสือราชการ</h3>
        <?php if (empty($document_types)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ยังไม่มีข้อมูลประเภทหนังสือราชการ
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">ชื่อประเภท</th>
                            <th scope="col" class="text-white-50">คำอธิบาย</th>
                            <th scope="col" class="text-white-50">สร้างเมื่อ</th>
                            <th scope="col" class="text-white-50">อัปเดตล่าสุด</th>
                            <th scope="col" class="text-white-50">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($document_types as $index => $type): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($type['name']); ?></td>
                                <td><?php echo htmlspecialchars($type['description'] ?? '-'); ?></td>
                                <td><?php echo formatThaiDateTime($type['created_at']); ?></td>
                                <td><?php echo formatThaiDateTime($type['updated_at']); ?></td>
                                <td>
                                    <button class="btn btn-warning btn-sm me-2" onclick="editDocumentType(<?php echo htmlspecialchars(json_encode($type)); ?>)">
                                        <i class="fas fa-edit"></i> แก้ไข
                                    </button>
                                    <a href="?delete=<?php echo $type['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('คุณแน่ใจหรือไม่ที่ต้องการลบประเภทเอกสารนี้?');">
                                        <i class="fas fa-trash-alt"></i> ลบ
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function editDocumentType(type) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('documentTypeId').value = type.id;
        document.getElementById('name').value = type.name;
        document.getElementById('description').value = type.description;
        document.querySelector('.glassmorphism h3').innerHTML = '<i class="fas fa-edit me-2"></i>แก้ไขประเภทเอกสาร';
        document.querySelector('.btn-primary-glass').innerHTML = '<i class="fas fa-save me-2"></i>บันทึกการแก้ไข';
        // Scroll to form
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('documentTypeId').value = '';
        document.getElementById('name').value = '';
        document.getElementById('description').value = '';
        document.querySelector('.glassmorphism h3').innerHTML = '<i class="fas fa-plus-circle me-2"></i>เพิ่มประเภทเอกสาร';
        document.querySelector('.btn-primary-glass').innerHTML = '<i class="fas fa-save me-2"></i>บันทึก';
    }
</script>

<?php
require_once '../includes/footer.php';
?>