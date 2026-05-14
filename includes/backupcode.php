<?php
// includes/header.php
// ตรวจสอบว่า session_start() ถูกเรียกแล้วหรือไม่
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบว่า database.php ถูกเรียกแล้วหรือไม่ (ป้องกันการเรียกซ้ำ)
if (!defined('DB_SERVER')) {
    // ใช้ dirname(__DIR__) เพื่อให้ path ถูกต้องเสมอ ไม่ว่าจะเรียกจากไฟล์ที่อยู่ใน sub-directory ไหน
    require_once dirname(__DIR__) . '/config/database.php';
}

// กำหนดชื่อเว็บไซต์ ถ้าไม่ได้กำหนดมาจากหน้าเรียก
if (!isset($page_title)) {
    $page_title = SITE_NAME;
}

// ตรวจสอบบทบาทของผู้ใช้งาน
$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$is_user = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'user';
$logged_in = isset($_SESSION['user_id']);

// ดึงชื่อผู้ใช้งานและบทบาทจาก Session
$display_user_name = $_SESSION['user_name'] ?? 'ผู้ใช้งาน';
$display_user_role = $_SESSION['user_role'] ?? 'Guest';

// ตรวจสอบว่าอยู่ในโฟลเดอร์ admin หรือไม่
$is_in_admin_folder = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false);

// กำหนด base path สำหรับลิงก์ต่างๆ
$base_path = $is_in_admin_folder ? '../' : './';
$admin_path = $is_in_admin_folder ? './' : './admin/';

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars(SITE_NAME); ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            font-family: 'Prompt', sans-serif;
        }
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #fff;
            display: flex;
            flex-direction: column;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
        }
        .glassmorphism-nav {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 2px 10px 0 rgba(31, 38, 135, 0.2);
        }
        .glassmorphism-item {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            transition: all 0.3s ease;
        }
        .glassmorphism-item:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .navbar-brand, .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .navbar-brand:hover, .nav-link:hover {
            color: #fff !important;
            text-shadow: 0 0 5px rgba(255, 255, 255, 0.5);
        }
        
        /* Enhanced Dropdown Styles with Better Visibility */
        .navbar {
            z-index: 1050 !important;
            position: relative !important;
        }
        
        .dropdown-menu {
            background: rgba(40, 44, 52, 0.95) !important; /* เปลี่ยนจากสีขาวโปร่งใสเป็นสีเทาเข้ม */
            backdrop-filter: blur(20px) !important; /* เพิ่มความเบลอ */
            -webkit-backdrop-filter: blur(20px) !important;
            border: 2px solid rgba(255, 255, 255, 0.3) !important; /* เพิ่มความหนาของเส้นขอบ */
            border-radius: 15px !important;
            padding: 20px !important; /* เพิ่ม padding */
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5) !important; /* เพิ่มเงา */
            z-index: 9999 !important;
            position: absolute !important;
            min-width: 280px !important; /* เพิ่มความกว้าง */
            will-change: transform !important;
        }
        
        .dropdown {
            z-index: 1051 !important;
            position: relative !important;
        }
        
        .dropdown-menu.show {
            z-index: 10000 !important;
            transform: translateZ(0) !important;
        }
        
        .dropdown-item {
            color: #ffffff !important; /* เปลี่ยนเป็นสีขาวทึบ */
            background-color: transparent !important;
            transition: all 0.3s ease !important;
            border-radius: 10px !important; /* เพิ่มมุมโค้ง */
            margin-bottom: 10px !important; /* เพิ่มระยะห่าง */
            padding: 15px 20px !important; /* เพิ่ม padding */
            font-weight: 500 !important;
            font-size: 1rem !important; /* เพิ่มขนาดตัวอักษร */
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3) !important; /* เพิ่ม text shadow */
        }
        
        .dropdown-item:last-child {
            margin-bottom: 0 !important;
        }
        
        .dropdown-item:hover, .dropdown-item:focus {
            background: linear-gradient(45deg, rgba(102, 126, 234, 0.8), rgba(118, 75, 162, 0.8)) !important; /* ใช้ gradient สีเดียวกับ background */
            color: #ffffff !important;
            transform: translateX(8px) scale(1.02) !important; /* เพิ่มการขยาย */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important; /* เพิ่มเงาเมื่อ hover */
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5) !important;
        }
        
        .dropdown-item i {
            width: 20px !important; /* กำหนดความกว้างของไอคอน */
            text-align: center !important;
            color: rgba(255, 255, 255, 0.9) !important;
        }
        
        .dropdown-item:hover i {
            color: #ffffff !important;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5) !important;
        }
        
        .dropdown-divider {
            border-color: rgba(255, 255, 255, 0.5) !important; /* เข้มขึ้น */
            margin: 15px 0 !important; /* เพิ่มระยะห่าง */
            opacity: 0.8 !important;
        }
        
        /* Ensure dropdown is clickable */
        .navbar-nav .dropdown:hover .dropdown-menu {
            display: block;
        }
        
        /* เพิ่ม Animation เมื่อเปิด dropdown */
        .dropdown-menu {
            animation: dropdownFadeIn 0.3s ease-out !important;
        }
        
        @keyframes dropdownFadeIn {
            0% {
                opacity: 0;
                transform: translateY(-10px) scale(0.95);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .btn-primary-glass {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-primary-glass:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            background: linear-gradient(45deg, #5a6ed0, #6a4192);
        }
        .btn-secondary-glass {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-secondary-glass:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        .btn-info-glass {
            background: linear-gradient(45deg, #17a2b8, #007bff);
            border: none;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-info-glass:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            background: linear-gradient(45deg, #138496, #0056b3);
        }
        .btn-warning {
            background: rgba(255, 193, 7, 0.3);
            border: 1px solid rgba(255, 193, 7, 0.5);
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-warning:hover {
            background: rgba(255, 193, 7, 0.5);
            transform: translateY(-1px);
        }
        .btn-danger {
            background: rgba(220, 53, 69, 0.3);
            border: 1px solid rgba(220, 53, 69, 0.5);
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-danger:hover {
            background: rgba(220, 53, 69, 0.5);
            transform: translateY(-1px);
        }
        .btn-logout-glass {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
        }
        .btn-logout-glass:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
        }
        .navbar-text-user {
            color: rgba(255, 255, 255, 0.8) !important;
            font-weight: 500;
            padding-right: 1rem;
            display: flex;
            align-items: center;
        }
        .navbar-text-user small {
            font-weight: 400;
            opacity: 0.8;
            margin-left: 0.5rem;
        }
        .navbar-text-user i {
            margin-right: 0.5rem;
        }

        .alert-danger-glass {
            background: rgba(220, 53, 69, 0.2);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ffcccc;
        }
        .alert-success-glass {
            background: rgba(40, 167, 69, 0.2);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: #d4edda;
        }
        .alert-info-glass {
            background: rgba(23, 162, 184, 0.2);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(23, 162, 184, 0.3);
            color: #d1ecf1;
        }

        main {
            flex: 1;
        }
        .footer {
            padding: 1rem 0;
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            margin-top: auto;
        }

        .floating-elements {
            position: fixed;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: -1 !important;
            top: 0;
            left: 0;
            pointer-events: none !important;
        }
        .floating-elements div {
            position: absolute;
            display: block;
            list-style: none;
            width: 20px;
            height: 20px;
            background: rgba(255, 255, 255, 0.1);
            animation: animate 25s linear infinite;
            bottom: -150px;
            filter: blur(2px);
        }
        .floating-elements div:nth-child(1) { left: 25%; width: 80px; height: 80px; animation-delay: 0s; }
        .floating-elements div:nth-child(2) { left: 10%; width: 20px; height: 20px; animation-delay: 2s; animation-duration: 12s; }
        .floating-elements div:nth-child(3) { left: 70%; width: 20px; height: 20px; animation-delay: 4s; }
        .floating-elements div:nth-child(4) { left: 40%; width: 60px; height: 60px; animation-delay: 0s; animation-duration: 18s; }
        .floating-elements div:nth-child(5) { left: 65%; width: 20px; height: 20px; animation-delay: 0s; }
        .floating-elements div:nth-child(6) { left: 75%; width: 110px; height: 110px; animation-delay: 3s; }
        .floating-elements div:nth-child(7) { left: 35%; width: 150px; height: 150px; animation-delay: 7s; }
        .floating-elements div:nth-child(8) { left: 50%; width: 25px; height: 25px; animation-delay: 15s; animation-duration: 45s; }
        .floating-elements div:nth-child(9) { left: 20%; width: 15px; height: 15px; animation-delay: 2s; animation-duration: 35s; }
        .floating-elements div:nth-child(10) { left: 85%; width: 150px; height: 150px; animation-delay: 0s; animation-duration: 11s; }

        @keyframes animate {
            0% { transform: translateY(0) rotate(0deg); opacity: 1; border-radius: 0; }
            100% { transform: translateY(-1000px) rotate(720deg); opacity: 0; border-radius: 50%; }
        }
        .table-dark th, .table-dark td {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .table-dark thead th {
            border-bottom-color: rgba(255, 255, 255, 0.2);
        }
        .table-hover tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.08);
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="floating-elements">
        <div></div><div></div><div></div><div></div><div></div>
        <div></div><div></div><div></div><div></div><div></div>
    </div>
    <nav class="navbar navbar-expand-lg glassmorphism-nav py-3">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="<?php echo $is_admin ? ($is_in_admin_folder ? 'index.php' : './admin/index.php') : ($is_in_admin_folder ? '../dashboard.php' : './dashboard.php'); ?>">
                <i class="fas fa-book-reader me-2"></i><?php echo htmlspecialchars(SITE_NAME); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if ($is_admin): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $is_in_admin_folder ? 'index.php' : './admin/index.php'; ?>"><i class="fas fa-tachometer-alt me-1"></i> ภาพรวม (Admin)</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownAdmin" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-tasks me-1"></i> จัดการระบบ
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdownAdmin">
                                <li><a class="dropdown-item" href="<?php echo $admin_path; ?>users.php"><i class="fas fa-users-cog me-2"></i> ผู้ใช้งาน</a></li>
                                <li><a class="dropdown-item" href="<?php echo $admin_path; ?>departments.php"><i class="fas fa-building me-2"></i> แผนก</a></li>
                                <li><a class="dropdown-item" href="<?php echo $admin_path; ?>document_types.php"><i class="fas fa-tags me-2"></i> ประเภทเอกสาร</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo $admin_path; ?>system_settings.php"><i class="fas fa-sliders-h me-2"></i> ตั้งค่าระบบ</a></li>
                                <li><a class="dropdown-item" href="<?php echo $admin_path; ?>reports.php"><i class="fas fa-chart-line me-2"></i> รายงาน</a></li>
                                <li><a class="dropdown-item" href="<?php echo $admin_path; ?>telegram_logs.php"><i class="fab fa-telegram-plane me-2"></i> บันทึก Telegram</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $admin_path; ?>documents.php"><i class="fas fa-book me-1"></i> เอกสารทั้งหมด</a>
                        </li>
                    <?php elseif ($is_user): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $base_path; ?>dashboard.php"><i class="fas fa-home me-1"></i> หน้าหลัก</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $base_path; ?>documents_register.php"><i class="fas fa-file-upload me-1"></i> ลงทะเบียนหนังสือ</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownDocuments" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-folder-open me-1"></i> เอกสารของฉัน
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="navbarDropdownDocuments">
                                <li><a class="dropdown-item" href="<?php echo $base_path; ?>documents_inbox.php"><i class="fas fa-inbox me-2"></i> หนังสือเข้า (ลงทะเบียน)</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_path; ?>documents_sent.php"><i class="fas fa-paper-plane me-2"></i> หนังสือที่ส่งออก (ราชการ)</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_path; ?>documents_all.php"><i class="fas fa-book me-2"></i> หนังสือทั้งหมด (ราชการ)</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo $base_path; ?>personal_file_transfer.php"><i class="fas fa-share-alt me-2"></i> ส่งไฟล์ส่วนตัว</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_path; ?>personal_transfers_inbox.php"><i class="fas fa-file-import me-2"></i> ไฟล์ส่วนตัวที่ได้รับ</a></li>
                                <li><a class="dropdown-item" href="<?php echo $base_path; ?>personal_transfers_sent.php"><i class="fas fa-file-export me-2"></i> ประวัติส่งไฟล์ส่วนตัว</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo $base_path; ?>profile.php"><i class="fas fa-user me-1"></i> ข้อมูลส่วนตัว</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php if ($logged_in): ?>
                        <li class="nav-item d-flex align-items-center me-3">
                            <span class="navbar-text navbar-text-user">
                                <i class="fas fa-user-circle"></i>
                                <?php echo htmlspecialchars($display_user_name); ?>
                                <small>(<?php echo htmlspecialchars($display_user_role); ?>)</small>
                            </span>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-logout-glass" href="<?php echo $base_path; ?>logout.php">
                                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="btn btn-primary-glass" href="<?php echo $base_path; ?>login.php"><i class="fas fa-sign-in-alt me-2"></i> เข้าสู่ระบบ</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1">
    
    <!-- Bootstrap JavaScript - ย้ายมาไว้ก่อน closing body tag -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Additional JavaScript to ensure dropdown works
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize all dropdowns
            var dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(function(dropdown) {
                new bootstrap.Dropdown(dropdown);
            });
            
            // Fix z-index issues when dropdown opens
            document.querySelectorAll('.dropdown').forEach(function(dropdown) {
                var dropdownToggle = dropdown.querySelector('.dropdown-toggle');
                var dropdownMenu = dropdown.querySelector('.dropdown-menu');
                
                if (dropdownToggle && dropdownMenu) {
                    dropdownToggle.addEventListener('click', function(e) {
                        // Force high z-index when opening
                        setTimeout(function() {
                            if (dropdownMenu.classList.contains('show')) {
                                dropdownMenu.style.zIndex = '10001';
                                dropdown.style.zIndex = '10001';
                            }
                        }, 10);
                    });
                    
                    // Clean up z-index when closing
                    dropdownToggle.addEventListener('hidden.bs.dropdown', function() {
                        dropdownMenu.style.zIndex = '';
                        dropdown.style.zIndex = '';
                    });
                }
            });
            
            // Add hover effect for better UX (optional)
            document.querySelectorAll('.navbar-nav .dropdown').forEach(function(dropdown) {
                dropdown.addEventListener('mouseenter', function() {
                    var dropdownToggle = this.querySelector('.dropdown-toggle');
                    var dropdownMenu = this.querySelector('.dropdown-menu');
                    if (dropdownToggle && dropdownMenu && window.innerWidth > 768) {
                        dropdownToggle.setAttribute('aria-expanded', 'true');
                        dropdownMenu.classList.add('show');
                        dropdownMenu.style.zIndex = '10001';
                        this.style.zIndex = '10001';
                    }
                });
                
                dropdown.addEventListener('mouseleave', function() {
                    var dropdownToggle = this.querySelector('.dropdown-toggle');
                    var dropdownMenu = this.querySelector('.dropdown-menu');
                    if (dropdownToggle && dropdownMenu && window.innerWidth > 768) {
                        dropdownToggle.setAttribute('aria-expanded', 'false');
                        dropdownMenu.classList.remove('show');
                        dropdownMenu.style.zIndex = '';
                        this.style.zIndex = '';
                    }
                });
            });
        });
    </script>