<?php
// login.php
// หน้าเข้าสู่ระบบ

require_once 'config/database.php';

session_start();

// ตรวจสอบและโหลดธีมจาก session หรือ cookie
$current_theme = $_SESSION['theme'] ?? $_COOKIE['theme'] ?? 'midnight';

// ถ้ามีการเปลี่ยนธีม
if (isset($_GET['theme'])) {
    $theme = $_GET['theme'];
    if (in_array($theme, ['midnight', 'deepocean', 'darkpurple'])) {
        $_SESSION['theme'] = $theme;
        setcookie('theme', $theme, time() + (86400 * 365), '/'); // เก็บไว้ 1 ปี
        $current_theme = $theme;
    }
}

// ถ้าเข้าสู่ระบบแล้วให้ redirect ไป dashboard หรือ admin dashboard ตาม role
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header('Location: admin/index.php');
        exit();
    } else {
        header('Location: dashboard.php');
        exit();
    }
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error_message = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $db = getDB();
        $query = "SELECT id, username, password, first_name, last_name, email, role, department_id, is_active 
                  FROM users WHERE username = ? AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                // เข้าสู่ระบบสำเร็จ
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['department_id'] = $user['department_id'];
                
                // บันทึกเวลาเข้าสู่ระบบล่าสุด
                $update_query = "UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bind_param('i', $user['id']);
                $update_stmt->execute();
                
                // Redirect ตามบทบาทผู้ใช้
                if ($user['role'] === 'admin') {
                    header('Location: admin/index.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit();
            } else {
                $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            }
        } else {
            $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

// กำหนด gradient สำหรับแต่ละธีม - สีเข้มทั้งหมด
$themes = [
    'midnight' => [
        'gradient' => 'linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%)',
        'button' => 'linear-gradient(45deg, #2c5364, #203a43)',
        'name' => 'มิดไนท์บลู'
    ],
    'deepocean' => [
        'gradient' => 'linear-gradient(135deg, #000428 0%, #004e92 100%)',
        'button' => 'linear-gradient(45deg, #004e92, #000428)',
        'name' => 'ทะเลลึก'
    ],
    'darkpurple' => [
        'gradient' => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)',
        'button' => 'linear-gradient(45deg, #0f3460, #16213e)',
        'name' => 'ม่วงมิดไนท์'
    ]
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - <?php echo SITE_NAME; ?></title>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Prompt', sans-serif;
        }
        
        body {
            background: <?php echo $themes[$current_theme]['gradient']; ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.5s ease;
        }
        
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.5);
        }
        
        .form-control {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            color: #ffffff;
        }
        
        .form-control:focus {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }
        
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        
        .btn-login {
            background: <?php echo $themes[$current_theme]['button']; ?>;
            border: none;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5);
        }
        
        .login-icon {
            font-size: 4rem;
            color: rgba(255, 255, 255, 0.9);
            text-shadow: 2px 2px 8px rgba(0, 0, 0, 0.5);
        }
        
        .alert {
            background: rgba(220, 53, 69, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(220, 53, 69, 0.5);
            color: #ffffff;
        }
        
        .input-group-text {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }
        
        /* Theme Switcher Styles */
        .theme-switcher {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .theme-toggle-btn {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 50px;
            padding: 10px 20px;
            color: white;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .theme-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            color: white;
        }
        
        .theme-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 10px;
            background: rgba(20, 20, 30, 0.95);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-radius: 15px;
            padding: 10px;
            min-width: 200px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .theme-dropdown.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .theme-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: #ffffff;
            margin-bottom: 5px;
        }
        
        .theme-option:last-child {
            margin-bottom: 0;
        }
        
        .theme-option:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
            color: #ffffff;
        }
        
        .theme-option.active {
            background: rgba(255, 255, 255, 0.15);
            font-weight: 600;
        }
        
        .theme-color-preview {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
        }
        
        .theme-color-preview.midnight {
            background: linear-gradient(135deg, #0f2027 0%, #2c5364 100%);
        }
        
        .theme-color-preview.deepocean {
            background: linear-gradient(135deg, #000428 0%, #004e92 100%);
        }
        
        .theme-color-preview.darkpurple {
            background: linear-gradient(135deg, #1a1a2e 0%, #0f3460 100%);
        }
        
        .floating-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1;
        }
        
        .floating-elements div {
            position: absolute;
            display: block;
            list-style: none;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.05);
            animation: animate 25s linear infinite;
            bottom: -150px;
        }
        
        .floating-elements div:nth-child(1) {
            left: 25%;
            width: 80px;
            height: 80px;
            animation-delay: 0s;
        }
        
        .floating-elements div:nth-child(2) {
            left: 10%;
            width: 20px;
            height: 20px;
            animation-delay: 2s;
            animation-duration: 12s;
        }
        
        .floating-elements div:nth-child(3) {
            left: 70%;
            width: 20px;
            height: 20px;
            animation-delay: 4s;
        }
        
        .floating-elements div:nth-child(4) {
            left: 40%;
            width: 60px;
            height: 60px;
            animation-delay: 0s;
            animation-duration: 18s;
        }
        
        .floating-elements div:nth-child(5) {
            left: 65%;
            width: 20px;
            height: 20px;
            animation-delay: 0s;
        }
        
        .floating-elements div:nth-child(6) {
            left: 75%;
            width: 110px;
            height: 110px;
            animation-delay: 3s;
        }
        
        .floating-elements div:nth-child(7) {
            left: 35%;
            width: 150px;
            height: 150px;
            animation-delay: 7s;
        }
        
        .floating-elements div:nth-child(8) {
            left: 50%;
            width: 25px;
            height: 25px;
            animation-delay: 15s;
            animation-duration: 45s;
        }
        
        .floating-elements div:nth-child(9) {
            left: 20%;
            width: 15px;
            height: 15px;
            animation-delay: 2s;
            animation-duration: 35s;
        }
        
        .floating-elements div:nth-child(10) {
            left: 85%;
            width: 150px;
            height: 150px;
            animation-delay: 0s;
            animation-duration: 11s;
        }
        
        @keyframes animate {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 1;
                border-radius: 0;
            }
            100% {
                transform: translateY(-1000px) rotate(720deg);
                opacity: 0;
                border-radius: 50%;
            }
        }
    </style>
</head>
<body>
    <!-- Theme Switcher -->
    <div class="theme-switcher">
        <button class="theme-toggle-btn" id="themeToggle" onclick="toggleThemeDropdown()">
            <i class="fas fa-palette"></i>
            <span>ธีม: <?php echo $themes[$current_theme]['name']; ?></span>
            <i class="fas fa-chevron-down"></i>
        </button>
        <div class="theme-dropdown" id="themeDropdown">
            <?php foreach ($themes as $key => $theme): ?>
                <a href="?theme=<?php echo $key; ?>" class="theme-option <?php echo $current_theme === $key ? 'active' : ''; ?>">
                    <div class="theme-color-preview <?php echo $key; ?>"></div>
                    <span><?php echo $theme['name']; ?></span>
                    <?php if ($current_theme === $key): ?>
                        <i class="fas fa-check ms-auto"></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="floating-elements">
        <div></div>
        <div></div>
        <div></div>
        <div></div>
        <div></div>
        <div></div>
        <div></div>
        <div></div>
        <div></div>
        <div></div>
    </div>
    
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="glassmorphism p-5">
                    <div class="text-center mb-4">
                        <i class="fas fa-file-alt login-icon"></i>
                        <h2 class="text-white mt-3 fw-bold">เข้าสู่ระบบ</h2>
                        <p class="text-white-50">ระบบลงรับหนังสือราชการ</p>
                    </div>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text border-end-0">
                                    <i class="fas fa-user text-white-50"></i>
                                </span>
                                <input type="text" 
                                       class="form-control border-start-0" 
                                       name="username" 
                                       placeholder="ชื่อผู้ใช้" 
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                       required>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text border-end-0">
                                    <i class="fas fa-lock text-white-50"></i>
                                </span>
                                <input type="password" 
                                       class="form-control border-start-0" 
                                       name="password" 
                                       placeholder="รหัสผ่าน" 
                                       required>
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-login btn-lg text-white fw-bold py-3">
                                <i class="fas fa-sign-in-alt me-2"></i>
                                เข้าสู่ระบบ
                            </button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4">
                        <small class="text-white-50">
                            <i class="fas fa-shield-alt me-1"></i>
                            ระบบปลอดภัยด้วยเทคโนโลยีการเข้ารหัส
                        </small>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <p class="text-white-50">
                        <i class="fas fa-info-circle me-2"></i>
                        หากมีปัญหาการเข้าสู่ระบบ กรุณาติดต่อผู้ดูแลระบบ
                    </p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Theme dropdown toggle
        function toggleThemeDropdown() {
            const dropdown = document.getElementById('themeDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const themeToggle = document.getElementById('themeToggle');
            const themeDropdown = document.getElementById('themeDropdown');
            
            if (!themeToggle.contains(event.target) && !themeDropdown.contains(event.target)) {
                themeDropdown.classList.remove('show');
            }
        });
        
        // Auto hide alert after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.querySelector('.alert');
            if (alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            }
        });
        
        // Add focus effects
        document.querySelectorAll('.form-control').forEach(function(input) {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
                this.parentElement.style.transition = 'transform 0.2s ease';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>
