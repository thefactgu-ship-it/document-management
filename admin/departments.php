<?php
// admin/departments.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องเป็น Admin เท่านั้น
requireAdmin();

$db = getDB();
$message = '';
$message_type = ''; // 'success' or 'danger'

// --- Handle Form Submissions ---

// เพิ่ม/แก้ไข แผนก
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($name)) {
        $message = 'กรุณากรอกชื่อแผนก';
        $message_type = 'danger';
    } else {
        // ตรวจสอบชื่อแผนกซ้ำ (ยกเว้นกรณีแก้ไขและชื่อเดิม)
        $check_duplicate_sql = "SELECT id FROM departments WHERE name = ?";
        $check_params = [$name];
        $check_types = 's';
        if ($action === 'edit' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $check_duplicate_sql .= " AND id != ?";
            $check_params[] = $id;
            $check_types .= 'i';
        }

        $check_stmt = $db->prepare($check_duplicate_sql);
        if ($check_stmt) {
            $bind_names = array_merge([$check_types], $check_params);
            $ref = [];
            foreach($bind_names as $key => $value) $ref[$key] = &$bind_names[$key];
            call_user_func_array([$check_stmt, 'bind_param'], $ref);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                $message = 'ชื่อแผนกนี้มีอยู่ในระบบแล้ว';
                $message_type = 'danger';
            } else {
                if ($action === 'add') {
                    $stmt = $db->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ss', $name, $description);
                        if ($stmt->execute()) {
                            $message = 'เพิ่มแผนกสำเร็จแล้ว';
                            $message_type = 'success';
                        } else {
                            $message = 'เกิดข้อผิดพลาดในการเพิ่มแผนก: ' . $stmt->error;
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
                        $stmt = $db->prepare("UPDATE departments SET name = ?, description = ? WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param('ssi', $name, $description, $id);
                            if ($stmt->execute()) {
                                $message = 'แก้ไขแผนกสำเร็จแล้ว';
                                $message_type = 'success';
                            } else {
                                $message = 'เกิดข้อผิดพลาดในการแก้ไขแผนก: ' . $stmt->error;
                                $message_type = 'danger';
                            }
                            $stmt->close();
                        } else {
                            $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                            $message_type = 'danger';
                        }
                    } else {
                        $message = 'ไม่พบ ID แผนกที่ถูกต้องสำหรับการแก้ไข';
                        $message_type = 'danger';
                    }
                }
            }
            $check_stmt->close();
        } else {
            $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่งตรวจสอบชื่อซ้ำ: ' . $db->error;
            $message_type = 'danger';
        }
    }
}

// ลบแผนก
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_to_delete = (int) $_GET['delete'];

    // ตรวจสอบว่ามีผู้ใช้งานอยู่ในแผนกนี้หรือไม่ก่อนลบ
    $check_users_stmt = $db->prepare("SELECT COUNT(id) AS user_count FROM users WHERE department_id = ?");
    if ($check_users_stmt) {
        $check_users_stmt->bind_param('i', $id_to_delete);
        $check_users_stmt->execute();
        $user_count_result = $check_users_stmt->get_result();
        $user_count_row = $user_count_result->fetch_assoc();
        $user_count = $user_count_row['user_count'];
        $check_users_stmt->close();

        if ($user_count > 0) {
            $message = "ไม่สามารถลบแผนกนี้ได้ เนื่องจากยังมีผู้ใช้งานจำนวน {$user_count} คนอยู่ในแผนกนี้. กรุณาย้ายผู้ใช้งานออกจากแผนกนี้ก่อน.";
            $message_type = 'danger';
        } else {
            $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id_to_delete);
                if ($stmt->execute()) {
                    $message = 'ลบแผนกสำเร็จแล้ว';
                    $message_type = 'success';
                } else {
                    $message = 'เกิดข้อผิดพลาดในการลบแผนก: ' . $stmt->error;
                    $message_type = 'danger';
                }
                $stmt->close();
            } else {
                $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                $message_type = 'danger';
            }
        }
    } else {
        $message = 'เกิดข้อผิดพลาดในการตรวจสอบผู้ใช้งานในแผนก: ' . $db->error;
        $message_type = 'danger';
    }
}


// --- Fetch Departments for Display ---
$departments = [];
$query = "SELECT id, name, description, created_at, updated_at FROM departments ORDER BY name ASC";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    $result->free();
} else {
    $message = 'ไม่สามารถดึงข้อมูลแผนกได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Load Header ---
$page_title = 'จัดการแผนก';
require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-building me-2"></i>จัดการแผนก</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-plus-circle me-2"></i>เพิ่ม/แก้ไข แผนก</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="departmentId">

            <div class="mb-3">
                <label for="name" class="form-label text-white-50">ชื่อแผนก <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required placeholder="เช่น แผนกการเงิน">
            </div>
            <div class="mb-3">
                <label for="description" class="form-label text-white-50">คำอธิบาย</label>
                <textarea class="form-control" id="description" name="description" rows="3" placeholder="คำอธิบายเพิ่มเติมเกี่ยวกับแผนก"></textarea>
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
        <h3 class="text-white mb-3"><i class="fas fa-list me-2"></i>รายการแผนก</h3>
        <?php if (empty($departments)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ยังไม่มีข้อมูลแผนก
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">ชื่อแผนก</th>
                            <th scope="col" class="text-white-50">คำอธิบาย</th>
                            <th scope="col" class="text-white-50">สร้างเมื่อ</th>
                            <th scope="col" class="text-white-50">อัปเดตล่าสุด</th>
                            <th scope="col" class="text-white-50">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $index => $dept): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($dept['name']); ?></td>
                                <td><?php echo htmlspecialchars($dept['description'] ?? '-'); ?></td>
                                <td><?php echo formatThaiDateTime($dept['created_at']); ?></td>
                                <td><?php echo formatThaiDateTime($dept['updated_at']); ?></td>
                                <td>
                                    <button class="btn btn-warning btn-sm me-2" onclick="editDepartment(<?php echo htmlspecialchars(json_encode($dept)); ?>)">
                                        <i class="fas fa-edit"></i> แก้ไข
                                    </button>
                                    <a href="?delete=<?php echo $dept['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('คุณแน่ใจหรือไม่ที่ต้องการลบแผนกนี้? หากมีผู้ใช้งานอยู่ในแผนกนี้จะไม่สามารถลบได้');">
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
    function editDepartment(dept) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('departmentId').value = dept.id;
        document.getElementById('name').value = dept.name;
        document.getElementById('description').value = dept.description;
        document.querySelector('.glassmorphism h3').innerHTML = '<i class="fas fa-edit me-2"></i>แก้ไขแผนก';
        document.querySelector('.btn-primary-glass').innerHTML = '<i class="fas fa-save me-2"></i>บันทึกการแก้ไข';
        // Scroll to form
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('departmentId').value = '';
        document.getElementById('name').value = '';
        document.getElementById('description').value = '';
        document.querySelector('.glassmorphism h3').innerHTML = '<i class="fas fa-plus-circle me-2"></i>เพิ่มแผนก';
        document.querySelector('.btn-primary-glass').innerHTML = '<i class="fas fa-save me-2"></i>บันทึก';
    }
</script>

<?php
require_once '../includes/footer.php';
?>