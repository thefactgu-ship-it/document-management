<?php
// admin/users.php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง: ต้องเป็น Admin เท่านั้น
requireAdmin();

$db = getDB();
$message = '';
$message_type = ''; // 'success' or 'danger'

// --- Handle Form Submissions ---

// เพิ่ม/แก้ไข ผู้ใช้งาน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $department_id = $_POST['department_id'] === '' ? NULL : (int)$_POST['department_id']; // Handle NULL
    $role = trim($_POST['role'] ?? 'user');
    $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($username) || empty($first_name) || empty($last_name) || empty($email)) {
        $message = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน (ชื่อผู้ใช้, ชื่อ, นามสกุล, อีเมล)';
        $message_type = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'รูปแบบอีเมลไม่ถูกต้อง';
        $message_type = 'danger';
    } else {
        if ($action === 'add') {
            // ตรวจสอบ username และ email ซ้ำ
            $check_stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check_stmt->bind_param('ss', $username, $email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                $message = 'ชื่อผู้ใช้หรืออีเมลนี้มีอยู่ในระบบแล้ว';
                $message_type = 'danger';
            } elseif (empty($password)) {
                $message = 'กรุณากำหนดรหัสผ่านสำหรับผู้ใช้งานใหม่';
                $message_type = 'danger';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, first_name, last_name, email, phone, department_id, role, telegram_chat_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('ssssssissi', $username, $hashed_password, $first_name, $last_name, $email, $phone, $department_id, $role, $telegram_chat_id, $is_active);
                    if ($stmt->execute()) {
                        $message = 'เพิ่มผู้ใช้งานสำเร็จแล้ว';
                        $message_type = 'success';
                    } else {
                        $message = 'เกิดข้อผิดพลาดในการเพิ่มผู้ใช้งาน: ' . $stmt->error;
                        $message_type = 'danger';
                    }
                    $stmt->close();
                } else {
                    $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                    $message_type = 'danger';
                }
            }
            $check_stmt->close();
        } elseif ($action === 'edit') {
            $id = (int) $_POST['id'];
            if ($id > 0) {
                // ตรวจสอบ username และ email ซ้ำ (ยกเว้นของตัวเอง)
                $check_stmt = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $check_stmt->bind_param('ssi', $username, $email, $id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows > 0) {
                    $message = 'ชื่อผู้ใช้หรืออีเมลนี้มีอยู่ในระบบแล้ว';
                    $message_type = 'danger';
                } else {
                    $sql_fields = [];
                    $sql_types = '';
                    $sql_params = [];

                    $sql_fields[] = "username = ?";
                    $sql_params[] = $username;
                    $sql_types .= 's';

                    $sql_fields[] = "first_name = ?";
                    $sql_params[] = $first_name;
                    $sql_types .= 's';

                    $sql_fields[] = "last_name = ?";
                    $sql_params[] = $last_name;
                    $sql_types .= 's';

                    $sql_fields[] = "email = ?";
                    $sql_params[] = $email;
                    $sql_types .= 's';

                    $sql_fields[] = "phone = ?";
                    $sql_params[] = $phone;
                    $sql_types .= 's';
                    
                    $sql_fields[] = "department_id = ?";
                    $sql_params[] = $department_id;
                    $sql_types .= 'i';

                    $sql_fields[] = "role = ?";
                    $sql_params[] = $role;
                    $sql_types .= 's';

                    $sql_fields[] = "telegram_chat_id = ?";
                    $sql_params[] = $telegram_chat_id;
                    $sql_types .= 's';
                    
                    $sql_fields[] = "is_active = ?";
                    $sql_params[] = $is_active;
                    $sql_types .= 'i';

                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $sql_fields[] = "password = ?";
                        $sql_params[] = $hashed_password;
                        $sql_types .= 's';
                    }

                    // Add ID for WHERE clause
                    $sql_params[] = $id;
                    $sql_types .= 'i';

                    $sql = "UPDATE users SET " . implode(', ', $sql_fields) . " WHERE id = ?";
                    
                    $stmt = $db->prepare($sql);
                    if ($stmt) {
                        // Dynamically bind parameters using call_user_func_array
                        // Create an array of references for bind_param
                        $bind_names[] = $sql_types; // First element is the type string
                        for ($i = 0; $i < count($sql_params); $i++) {
                            $bind_names[] = &$sql_params[$i];
                        }
                        call_user_func_array([$stmt, 'bind_param'], $bind_names);

                        if ($stmt->execute()) {
                            $message = 'แก้ไขผู้ใช้งานสำเร็จแล้ว';
                            $message_type = 'success';
                        } else {
                            $message = 'เกิดข้อผิดพลาดในการแก้ไขผู้ใช้งาน: ' . $stmt->error;
                            $message_type = 'danger';
                        }
                        $stmt->close();
                    } else {
                        $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
                        $message_type = 'danger';
                    }
                }
                $check_stmt->close();
            } else {
                $message = 'ไม่พบ ID ผู้ใช้งานที่ถูกต้องสำหรับการแก้ไข';
                $message_type = 'danger';
            }
        }
    }
}

// ลบผู้ใช้งาน
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_to_delete = (int) $_GET['delete'];

    // ป้องกันการลบตัวเอง
    if ($id_to_delete == $_SESSION['user_id']) {
        $message = 'ไม่สามารถลบบัญชีผู้ใช้งานปัจจุบันได้';
        $message_type = 'danger';
    } else {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $id_to_delete);
            if ($stmt->execute()) {
                $message = 'ลบผู้ใช้งานสำเร็จแล้ว';
                $message_type = 'success';
            } else {
                $message = 'เกิดข้อผิดพลาดในการลบผู้ใช้งาน: ' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        } else {
            $message = 'เกิดข้อผิดพลาดในการเตรียมคำสั่ง: ' . $db->error;
            $message_type = 'danger';
        }
    }
}


// --- Fetch Users for Display ---
$users = [];
$query = "SELECT u.id, u.username, u.first_name, u.last_name, u.email, u.phone, u.role, u.telegram_chat_id, u.is_active, u.created_at, u.updated_at, d.name AS department_name, d.id AS department_id 
          FROM users u LEFT JOIN departments d ON u.department_id = d.id 
          ORDER BY u.username ASC";
$result = $db->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free();
} else {
    $message = 'ไม่สามารถดึงข้อมูลผู้ใช้งานได้: ' . $db->error;
    $message_type = 'danger';
}

// --- Fetch Departments for dropdown ---
$departments = [];
$query_dept = "SELECT id, name FROM departments ORDER BY name ASC";
$result_dept = $db->query($query_dept);
if ($result_dept) {
    while ($row_dept = $result_dept->fetch_assoc()) {
        $departments[] = $row_dept;
    }
    $result_dept->free();
}
// Load Header
$page_title = 'จัดการผู้ใช้งาน';
require_once '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="text-white mb-4"><i class="fas fa-users-cog me-2"></i>จัดการผู้ใช้งาน</h1>
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
        <h3 class="text-white mb-3"><i class="fas fa-user-plus me-2"></i>เพิ่ม/แก้ไข ผู้ใช้งาน</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="userId">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="username" class="form-label text-white-50">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="username" name="username" required placeholder="ชื่อผู้ใช้สำหรับเข้าสู่ระบบ">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="password" class="form-label text-white-50">รหัสผ่าน <?php echo isset($_GET['edit']) ? '<small>(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</small>' : '<span class="text-danger">*</span>'; ?></label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="รหัสผ่าน">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="first_name" class="form-label text-white-50">ชื่อจริง <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="first_name" name="first_name" required placeholder="ชื่อจริง">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="last_name" class="form-label text-white-50">นามสกุล <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="last_name" name="last_name" required placeholder="นามสกุล">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label text-white-50">อีเมล <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="email" name="email" required placeholder="email@example.com">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label text-white-50">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-control" id="phone" name="phone" placeholder="เบอร์โทรศัพท์">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="department_id" class="form-label text-white-50">แผนก</label>
                    <select class="form-select" id="department_id" name="department_id">
                        <option value="">-- ไม่ระบุแผนก --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="role" class="form-label text-white-50">บทบาท <span class="text-danger">*</span></label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="user">User (ผู้ใช้งานทั่วไป)</option>
                        <option value="admin">Admin (ผู้ดูแลระบบ)</option>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label for="telegram_chat_id" class="form-label text-white-50">Telegram Chat ID</label>
                <input type="text" class="form-control" id="telegram_chat_id" name="telegram_chat_id" placeholder="ID สำหรับการแจ้งเตือน Telegram (ถ้ามี)">
                <small class="form-text text-white-50">ดูวิธีการหา Telegram Chat ID ได้ที่ <a href="https://api.telegram.org/botYOUR_BOT_TOKEN/getUpdates" target="_blank" class="text-info text-decoration-underline">ลิงก์นี้</a> (แทน YOUR_BOT_TOKEN ด้วย Token ของคุณ)</small>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" checked>
                <label class="form-check-label text-white-50" for="is_active">
                    เปิดใช้งานบัญชี
                </label>
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
        <h3 class="text-white mb-3"><i class="fas fa-users me-2"></i>รายการผู้ใช้งาน</h3>
        <?php if (empty($users)): ?>
            <div class="alert alert-info glassmorphism p-3 text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i> ยังไม่มีข้อมูลผู้ใช้งาน
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-dark table-striped">
                    <thead>
                        <tr>
                            <th scope="col" class="text-white-50">#</th>
                            <th scope="col" class="text-white-50">ชื่อผู้ใช้</th>
                            <th scope="col" class="text-white-50">ชื่อ-นามสกุล</th>
                            <th scope="col" class="text-white-50">อีเมล</th>
                            <th scope="col" class="text-white-50">แผนก</th>
                            <th scope="col" class="text-white-50">บทบาท</th>
                            <th scope="col" class="text-white-50">สถานะ</th>
                            <th scope="col" class="text-white-50">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $index => $user): ?>
                            <tr>
                                <th scope="row"><?php echo $index + 1; ?></th>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['department_name'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                        if ($user['role'] === 'admin') {
                                            echo '<span class="badge bg-danger">Admin</span>';
                                        } else {
                                            echo '<span class="badge bg-info">User</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        if ($user['is_active']) {
                                            echo '<span class="badge bg-success">เปิดใช้งาน</span>';
                                        } else {
                                            echo '<span class="badge bg-warning">ระงับ</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <button class="btn btn-warning btn-sm me-2" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                        <i class="fas fa-edit"></i> แก้ไข
                                    </button>
                                    <?php if ($user['id'] != $_SESSION['user_id']): // ห้ามลบตัวเอง ?>
                                        <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('คุณแน่ใจหรือไม่ที่ต้องการลบผู้ใช้งาน <?php echo htmlspecialchars($user['username']); ?>?');">
                                            <i class="fas fa-trash-alt"></i> ลบ
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-user-times"></i> ลบตัวเองไม่ได้</button>
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

<script>
    function editUser(user) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('userId').value = user.id;
        document.getElementById('username').value = user.username;
        document.getElementById('first_name').value = user.first_name;
        document.getElementById('last_name').value = user.last_name;
        document.getElementById('email').value = user.email;
        document.getElementById('phone').value = user.phone || ''; // Handle null phone
        document.getElementById('department_id').value = user.department_id || ''; // Handle null department
        document.getElementById('role').value = user.role;
        document.getElementById('telegram_chat_id').value = user.telegram_chat_id || '';
        document.getElementById('is_active').checked = user.is_active == 1; // Checkbox needs boolean

        // Update form title and button text
        document.querySelector('.glassmorphism h3').innerHTML = '<i class="fas fa-user-edit me-2"></i>แก้ไขผู้ใช้งาน';
        document.querySelector('.btn-primary-glass').innerHTML = '<i class="fas fa-save me-2"></i>บันทึกการแก้ไข';
        document.querySelector('label[for="password"]').innerHTML = 'รหัสผ่าน <small>(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</small>'; // Change password label
        document.getElementById('password').removeAttribute('required'); // Password is not required for edit

        // Scroll to form
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('userId').value = '';
        document.getElementById('username').value = '';
        document.getElementById('password').value = ''; // Clear password field
        document.getElementById('first_name').value = '';
        document.getElementById('last_name').value = '';
        document.getElementById('email').value = '';
        document.getElementById('phone').value = '';
        document.getElementById('department_id').value = ''; // Reset to default "ไม่ระบุ"
        document.getElementById('role').value = 'user'; // Default to user
        document.getElementById('telegram_chat_id').value = '';
        document.getElementById('is_active').checked = true; // Default to active

        // Reset form title and button text
        document.querySelector('.glassmorphism h3').innerHTML = '<i class="fas fa-user-plus me-2"></i>เพิ่มผู้ใช้งาน';
        document.querySelector('.btn-primary-glass').innerHTML = '<i class="fas fa-save me-2"></i>บันทึก';
        document.querySelector('label[for="password"]').innerHTML = 'รหัสผ่าน <span class="text-danger">*</span>'; // Restore password label
        document.getElementById('password').setAttribute('required', 'required'); // Password is required for add
    }
</script>

<?php
require_once '../includes/footer.php';
?>