<?php
require_once 'includes/auth.php';
$currentPage = 'add-user';
$pageTitle = 'Add New User';
require_once 'includes/db.php';

// Get user role from session
$user_role = $_SESSION['role'];

// Check if user has permission to add users (only admin can add users)
if ($user_role != 'admin') {
    $error = "You don't have permission to add users. Only administrators can access this page.";
}

$message = '';
$error = isset($error) ? $error : '';

// Get finance companies for dropdown (only for staff/accountant)
$finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $user_role == 'admin') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $role = mysqli_real_escape_string($conn, $_POST['role']);
    $finance_id = !empty($_POST['finance_id']) ? intval($_POST['finance_id']) : null;
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    
    // Validation
    $errors = [];
    
    // Check if username already exists
    $check_query = "SELECT id FROM users WHERE username = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $check_result = $stmt->get_result();
    if ($check_result && $check_result->num_rows > 0) {
        $errors[] = "Username already exists. Please choose another username.";
    }
    $stmt->close();
    
    // Check if email already exists (if provided)
    if (!empty($email)) {
        $check_email = "SELECT id FROM users WHERE email = ?";
        $stmt = $conn->prepare($check_email);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $email_result = $stmt->get_result();
        if ($email_result && $email_result->num_rows > 0) {
            $errors[] = "Email already exists. Please use another email address.";
        }
        $stmt->close();
    }
    
    // Validate password
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Validate email format
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    
    // Validate finance_id if provided (check if it exists in finance table)
    if ($finance_id !== null) {
        $check_finance = "SELECT id FROM finance WHERE id = ?";
        $stmt = $conn->prepare($check_finance);
        $stmt->bind_param("i", $finance_id);
        $stmt->execute();
        $finance_result = $stmt->get_result();
        if ($finance_result->num_rows == 0) {
            $errors[] = "Selected finance company does not exist.";
            $finance_id = null;
        }
        $stmt->close();
    }
    
    // If no errors, insert user
    if (empty($errors)) {
        // Use password_hash for better security
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert query with proper parameter handling
        if ($finance_id === null) {
            $insert_query = "INSERT INTO users (username, password, full_name, email, role, finance_id, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, NULL, ?, NOW())";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("ssssss", $username, $hashed_password, $full_name, $email, $role, $status);
        } else {
            $insert_query = "INSERT INTO users (username, password, full_name, email, role, finance_id, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("sssssis", $username, $hashed_password, $full_name, $email, $role, $finance_id, $status);
        }
        
        if ($stmt->execute()) {
            $user_id = $stmt->insert_id;
            
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'add_user', "Added new user: $username (ID: $user_id) with role: $role");
            }
            
            $message = "User added successfully!";
            
            // Clear form data
            $_POST = [];
        } else {
            $error = "Error adding user: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get total users count for display
$count_query = "SELECT COUNT(*) as total FROM users";
$count_result = $conn->query($count_query);
$total_users = $count_result ? $count_result->fetch_assoc()['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo $pageTitle; ?> - Finance Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #6c757d;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f59e0b;
            --info: #3498db;
            --dark: #2c3e50;
            --light: #f8f9fa;
            --card-bg: #ffffff;
            --border-color: #e9ecef;
            --text-primary: #2c3e50;
            --text-muted: #7f8c8d;
            --shadow: 0 4px 6px rgba(0,0,0,0.1);
            --radius: 12px;
            --primary-bg: #e6f0ff;
            --success-bg: #e3f7e3;
            --warning-bg: #fff3e0;
            --danger-bg: #ffe6e6;
            --info-bg: #e3f2fd;
        }
        
        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            width: 100%;
            -webkit-tap-highlight-color: transparent;
        }
        
        .app-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
            overflow-x: hidden;
        }
        
        .page-content {
            padding: 2rem;
            max-width: 100%;
            overflow-x: hidden;
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            width: 100%;
        }
        
        .welcome-banner h3 {
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-size: clamp(1.5rem, 5vw, 2rem);
            word-wrap: break-word;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: clamp(0.9rem, 3vw, 1rem);
            word-wrap: break-word;
        }
        
        .welcome-banner .btn-light {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            border-radius: 30px;
            font-size: clamp(0.85rem, 3vw, 1rem);
            white-space: nowrap;
        }
        
        .welcome-banner .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Stat Cards */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
            transition: all 0.2s ease;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        .stat-label {
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .stat-value {
            font-size: clamp(1.5rem, 5vw, 2rem);
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .stat-icon {
            width: clamp(40px, 8vw, 48px);
            height: clamp(40px, 8vw, 48px);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.2rem, 4vw, 1.5rem);
            flex-shrink: 0;
        }
        
        .stat-icon.blue {
            background: var(--primary-bg);
            color: var(--primary);
        }
        
        .stat-icon.green {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .stat-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
        }
        
        .stat-icon.orange {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .stat-footer {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Form Card */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            width: 100%;
        }
        
        .form-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .form-header {
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .form-header h2 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: clamp(1.3rem, 4vw, 1.8rem);
            word-wrap: break-word;
        }
        
        .form-header p {
            color: var(--text-muted);
            font-size: clamp(0.85rem, 2.8vw, 0.95rem);
            word-wrap: break-word;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: clamp(0.85rem, 2.8vw, 0.95rem);
        }
        
        .form-control, .form-select {
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 0.6rem 1rem;
            transition: all 0.2s ease;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .input-group-text {
            border: 2px solid #eef2f6;
            border-radius: 12px 0 0 12px;
            background: #f8f9fa;
            color: var(--text-muted);
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        /* Alert */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem;
            margin-bottom: 1.5rem;
            word-wrap: break-word;
        }
        
        /* Info Box */
        .info-box {
            background: var(--info-bg);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: clamp(0.85rem, 2.8vw, 0.9rem);
            color: var(--info);
            word-wrap: break-word;
        }
        
        .info-box i {
            margin-right: 0.5rem;
            flex-shrink: 0;
        }
        
        .info-box ul {
            padding-left: 1.2rem;
            margin-bottom: 0;
        }
        
        .info-box li {
            word-wrap: break-word;
        }
        
        /* Password Requirements */
        .password-requirements {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.5rem;
            font-size: clamp(0.8rem, 2.7vw, 0.9rem);
        }
        
        .password-requirements ul {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }
        
        .password-requirements li {
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            word-wrap: break-word;
        }
        
        .password-requirements li.valid {
            color: var(--success);
        }
        
        .password-requirements li.invalid {
            color: var(--danger);
        }
        
        /* Role Badges */
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .role-badge.admin {
            background: var(--danger-bg);
            color: var(--danger);
        }
        
        .role-badge.staff {
            background: var(--info-bg);
            color: var(--info);
        }
        
        .role-badge.accountant {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        /* Preview Card */
        .preview-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border: 1px dashed var(--primary);
        }
        
        .preview-card h6 {
            color: var(--text-primary);
            margin-bottom: 1rem;
            font-size: clamp(1rem, 3vw, 1.1rem);
        }
        
        .preview-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .preview-item:last-child {
            border-bottom: none;
        }
        
        .preview-label {
            font-weight: 600;
            color: var(--text-muted);
            min-width: 100px;
            font-size: clamp(0.85rem, 2.8vw, 0.9rem);
        }
        
        .preview-value {
            color: var(--text-primary);
            flex: 1;
            word-break: break-word;
            font-size: clamp(0.85rem, 2.8vw, 0.9rem);
        }
        
        /* Action Buttons */
        .btn-submit {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .btn-reset {
            background: #f8f9fa;
            color: var(--text-primary);
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .btn-reset:hover {
            background: #e9ecef;
            border-color: #dee2e6;
        }
        
        .btn-submit:active, .btn-reset:active {
            transform: scale(0.98);
        }
        
        /* Permission Denied */
        .permission-denied {
            text-align: center;
            padding: 3rem 1.5rem;
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .permission-denied i {
            font-size: clamp(2.5rem, 10vw, 3rem);
            color: var(--danger);
            margin-bottom: 1rem;
        }
        
        .permission-denied h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: clamp(1.2rem, 4vw, 1.5rem);
            word-wrap: break-word;
        }
        
        .permission-denied p {
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            font-size: clamp(0.9rem, 3vw, 1rem);
            word-wrap: break-word;
        }
        
        /* Quick Tips Cards */
        .tip-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .tip-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .tip-card:active {
            transform: scale(0.98);
        }
        
        .tip-icon {
            width: clamp(35px, 7vw, 40px);
            height: clamp(35px, 7vw, 40px);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(1.1rem, 3.5vw, 1.25rem);
            margin-bottom: 1rem;
        }
        
        .tip-icon.primary {
            background: var(--primary-bg);
            color: var(--primary);
        }
        
        .tip-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .tip-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .tip-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: clamp(1rem, 3.2vw, 1.1rem);
            word-wrap: break-word;
        }
        
        .tip-text {
            font-size: clamp(0.8rem, 2.7vw, 0.9rem);
            color: var(--text-muted);
            margin-bottom: 0;
            word-wrap: break-word;
        }
        
        /* Form text */
        .form-text {
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            word-wrap: break-word;
        }
        
        /* Badge styles */
        .badge {
            font-size: clamp(0.7rem, 2.3vw, 0.8rem);
            white-space: nowrap;
        }
        
        /* Mobile Responsive */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
        }
        
        @media (max-width: 992px) {
            .page-content {
                padding: 1.5rem;
            }
            
            .form-card {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .page-content {
                padding: 1rem;
            }
            
            .form-card {
                padding: 1.25rem;
            }
            
            .welcome-banner {
                padding: 1.25rem;
            }
            
            .welcome-banner .d-flex {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .stat-card {
                padding: 1.25rem;
            }
            
            .preview-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            
            .preview-label {
                width: auto;
            }
            
            .tip-card {
                margin-bottom: 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .page-content {
                padding: 0.75rem;
            }
            
            .welcome-banner {
                padding: 1rem;
            }
            
            .welcome-banner h3 {
                font-size: 1.2rem;
            }
            
            .form-card {
                padding: 1rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.2rem;
            }
            
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1.2rem;
            }
            
            .preview-card {
                padding: 1rem;
            }
            
            .tip-card {
                padding: 1rem;
            }
            
            .tip-icon {
                width: 35px;
                height: 35px;
                font-size: 1.1rem;
            }
            
            .permission-denied {
                padding: 2rem 1rem;
            }
            
            .btn-submit, .btn-reset {
                padding: 0.7rem 1rem;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .stat-card,
        .tip-card {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Utility classes */
        .text-truncate-custom {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        
        .break-word {
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .flex-shrink-0 {
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">
            <!-- Welcome Banner -->
            <div class="welcome-banner" data-testid="welcome-banner">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="overflow-hidden" style="max-width: 100%;">
                        <h3 class="text-truncate-custom"><i class="bi bi-person-plus me-2"></i>Add New User</h3>
                        <p class="text-truncate-custom">Create a new user account with role-based permissions</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <a href="users.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Users
                        </a>
                        <span class="badge bg-white text-primary">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2 flex-shrink-0"></i>
                    <span class="break-word"><?php echo $message; ?></span>
                    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <div class="text-center mt-3">
                    <a href="users.php" class="btn btn-primary">
                        <i class="bi bi-people me-2"></i>View All Users
                    </a>
                    <a href="add-user.php" class="btn btn-outline-primary ms-2">
                        <i class="bi bi-plus-circle me-2"></i>Add Another User
                    </a>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
                    <span class="break-word"><?php echo $error; ?></span>
                    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Check if user has permission -->
            <?php if ($user_role == 'admin'): ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4" data-testid="stats-cards">
                    <div class="col-6 col-md-4">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Total Users</div>
                                    <div class="stat-value"><?php echo number_format($total_users); ?></div>
                                </div>
                                <div class="stat-icon blue flex-shrink-0"><i class="bi bi-people"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Registered users</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-4">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Your Role</div>
                                    <div class="stat-value break-word" style="font-size: clamp(1.2rem, 4vw, 1.5rem);"><?php echo ucfirst($user_role); ?></div>
                                </div>
                                <div class="stat-icon green flex-shrink-0"><i class="bi bi-shield-lock"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Full system access</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-4">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Action</div>
                                    <div class="stat-value break-word" style="font-size: clamp(1.2rem, 4vw, 1.5rem);">Create New</div>
                                </div>
                                <div class="stat-icon purple flex-shrink-0"><i class="bi bi-person-plus"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Adding new user</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Form Card -->
                <div class="form-card">
                    <div class="form-header">
                        <h2><i class="bi bi-person-badge me-2"></i>User Details</h2>
                        <p class="break-word">Fill in the information below to create a new user</p>
                    </div>

                    <form method="POST" action="" id="addUserForm" onsubmit="return validateForm()">
                        <div class="row g-3">
                            <!-- Username -->
                            <div class="col-md-6">
                                <label for="username" class="form-label">
                                    <i class="bi bi-person me-1"></i>Username <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                                       required maxlength="50" pattern="[a-zA-Z0-9_]+" 
                                       title="Username can only contain letters, numbers, and underscores"
                                       onkeyup="updatePreview()">
                                <div class="form-text break-word">Only letters, numbers, and underscores allowed</div>
                            </div>

                            <!-- Full Name -->
                            <div class="col-md-6">
                                <label for="full_name" class="form-label">
                                    <i class="bi bi-card-text me-1"></i>Full Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                       required maxlength="255" placeholder="Enter full name"
                                       onkeyup="updatePreview()">
                            </div>

                            <!-- Password -->
                            <div class="col-md-6">
                                <label for="password" class="form-label">
                                    <i class="bi bi-lock me-1"></i>Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       required minlength="6" onkeyup="checkPasswordStrength(); updatePreview()">
                                <div class="password-requirements">
                                    <small class="text-muted d-block mb-2">Password requirements:</small>
                                    <ul id="passwordRequirements" class="mb-0">
                                        <li id="lengthCheck" class="invalid">✗ At least 6 characters</li>
                                        <li id="matchCheck" class="invalid">✗ Passwords match</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Confirm Password -->
                            <div class="col-md-6">
                                <label for="confirm_password" class="form-label">
                                    <i class="bi bi-lock-fill me-1"></i>Confirm Password <span class="text-danger">*</span>
                                </label>
                                <input type="password" class="form-control" id="confirm_password" 
                                       name="confirm_password" required minlength="6" onkeyup="checkPasswordStrength()">
                            </div>

                            <!-- Email -->
                            <div class="col-md-6">
                                <label for="email" class="form-label">
                                    <i class="bi bi-envelope me-1"></i>Email Address
                                </label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="user@example.com" onkeyup="updatePreview()">
                                <div class="form-text break-word">Optional but recommended for notifications</div>
                            </div>

                            <!-- Role -->
                            <div class="col-md-6">
                                <label for="role" class="form-label">
                                    <i class="bi bi-shield-lock me-1"></i>User Role <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="role" name="role" required onchange="toggleFinanceField(); updatePreview()">
                                    <option value="">Select Role</option>
                                    <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : ''; ?>>Admin (Full Access)</option>
                                    <option value="staff" <?php echo (isset($_POST['role']) && $_POST['role'] == 'staff') ? 'selected' : ''; ?>>Staff (Sales Person)</option>
                                    <option value="accountant" <?php echo (isset($_POST['role']) && $_POST['role'] == 'accountant') ? 'selected' : ''; ?>>Accountant</option>
                                </select>
                                <div class="form-text break-word">Staff role is for sales personnel</div>
                            </div>

                            <!-- Finance Company (for staff and accountant) -->
                            <div class="col-md-6" id="financeField" style="display: none;">
                                <label for="finance_id" class="form-label">
                                    <i class="bi bi-building me-1"></i>Finance Company
                                </label>
                                <select class="form-select" id="finance_id" name="finance_id" onchange="updatePreview()">
                                    <option value="">-- Select Finance Company (Optional) --</option>
                                    <?php 
                                    if ($finance_companies && $finance_companies->num_rows > 0) {
                                        $finance_companies->data_seek(0);
                                        while ($fc = $finance_companies->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $fc['id']; ?>" 
                                            <?php echo (isset($_POST['finance_id']) && $_POST['finance_id'] == $fc['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fc['finance_name']); ?>
                                        </option>
                                    <?php 
                                        endwhile; 
                                    } else {
                                        echo '<option value="" disabled>No finance companies available</option>';
                                    }
                                    ?>
                                </select>
                                <div class="form-text break-word">Assign which finance company this user manages (optional)</div>
                            </div>

                            <!-- Status -->
                            <div class="col-md-6">
                                <label for="status" class="form-label">
                                    <i class="bi bi-toggle-on me-1"></i>Status <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="status" name="status" required onchange="updatePreview()">
                                    <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>

                            <!-- Preview Section -->
                            <div class="col-12">
                                <div class="preview-card" id="previewCard" style="display: none;">
                                    <h6><i class="bi bi-eye me-2"></i>User Preview</h6>
                                    <div class="preview-item">
                                        <span class="preview-label">Username:</span>
                                        <span class="preview-value break-word" id="previewUsername">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Full Name:</span>
                                        <span class="preview-value break-word" id="previewName">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Email:</span>
                                        <span class="preview-value break-word" id="previewEmail">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Role:</span>
                                        <span class="preview-value" id="previewRole">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Finance:</span>
                                        <span class="preview-value break-word" id="previewFinance">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Status:</span>
                                        <span class="preview-value" id="previewStatus">—</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Information -->
                            <div class="col-12">
                                <div class="info-box">
                                    <i class="bi bi-info-circle flex-shrink-0"></i>
                                    <div class="break-word">
                                        <strong>Role Permissions:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li><span class="role-badge admin me-2">Admin</span> Full system access including user management</li>
                                            <li><span class="role-badge staff me-2">Staff</span> Sales person - Can manage customers, loans, and collections</li>
                                            <li><span class="role-badge accountant me-2">Accountant</span> Can view financial data and generate reports</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="col-12 mt-4">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <button type="submit" class="btn btn-submit">
                                            <i class="bi bi-check-circle me-2"></i>Create User
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="reset" class="btn btn-reset" onclick="resetForm()">
                                            <i class="bi bi-arrow-counterclockwise me-2"></i>Reset Form
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Quick Tips -->
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="tip-card">
                            <div class="tip-icon primary">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                            <h6 class="tip-title break-word">Admin Users</h6>
                            <p class="tip-text break-word">Have full access to all features including user management, settings, and all financial data.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="tip-card">
                            <div class="tip-icon success">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <h6 class="tip-title break-word">Staff (Sales Person)</h6>
                            <p class="tip-text break-word">Can manage customers, loans, and collections for their assigned finance company.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="tip-card">
                            <div class="tip-icon warning">
                                <i class="bi bi-calculator"></i>
                            </div>
                            <h6 class="tip-title break-word">Accountants</h6>
                            <p class="tip-text break-word">Can view financial reports, expenses, and collections but cannot manage users.</p>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Permission Denied Message -->
                <div class="permission-denied">
                    <i class="bi bi-shield-lock-fill"></i>
                    <h4 class="break-word">Access Denied</h4>
                    <p class="break-word">You don't have permission to add users. Only administrators can access this page.</p>
                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <a href="users.php" class="btn btn-primary">
                            <i class="bi bi-people me-2"></i>View Users
                        </a>
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="bi bi-house-door me-2"></i>Go to Dashboard
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // Toggle finance field based on role selection
    function toggleFinanceField() {
        const role = document.getElementById('role').value;
        const financeField = document.getElementById('financeField');
        
        if (role === 'staff' || role === 'accountant') {
            financeField.style.display = 'block';
        } else {
            financeField.style.display = 'none';
            document.getElementById('finance_id').value = '';
        }
        updatePreview();
    }
    
    // Check password strength
    function checkPasswordStrength() {
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirm_password').value;
        
        // Length check
        const lengthCheck = document.getElementById('lengthCheck');
        if (password.length >= 6) {
            lengthCheck.className = 'valid';
            lengthCheck.innerHTML = '✓ At least 6 characters';
        } else {
            lengthCheck.className = 'invalid';
            lengthCheck.innerHTML = '✗ At least 6 characters';
        }
        
        // Match check
        const matchCheck = document.getElementById('matchCheck');
        if (password && confirm && password === confirm) {
            matchCheck.className = 'valid';
            matchCheck.innerHTML = '✓ Passwords match';
        } else {
            matchCheck.className = 'invalid';
            matchCheck.innerHTML = '✗ Passwords match';
        }
    }
    
    // Update preview section
    function updatePreview() {
        const username = document.getElementById('username').value;
        const fullName = document.getElementById('full_name').value;
        const email = document.getElementById('email').value;
        const role = document.getElementById('role').value;
        const status = document.getElementById('status').value;
        const financeId = document.getElementById('finance_id');
        
        const previewCard = document.getElementById('previewCard');
        
        if (username || fullName || email || role) {
            previewCard.style.display = 'block';
            
            document.getElementById('previewUsername').textContent = username || '—';
            document.getElementById('previewName').textContent = fullName || '—';
            document.getElementById('previewEmail').textContent = email || '—';
            
            // Role display with badge
            let roleText = '—';
            if (role === 'admin') roleText = '<span class="role-badge admin">Admin</span>';
            else if (role === 'staff') roleText = '<span class="role-badge staff">Staff (Sales)</span>';
            else if (role === 'accountant') roleText = '<span class="role-badge accountant">Accountant</span>';
            document.getElementById('previewRole').innerHTML = roleText;
            
            // Finance display
            let financeText = '—';
            if (financeId && financeId.value) {
                const selectedOption = financeId.options[financeId.selectedIndex];
                financeText = selectedOption ? selectedOption.text : '—';
            }
            document.getElementById('previewFinance').textContent = financeText;
            
            // Status display with badge
            let statusText = '—';
            if (status === 'active') statusText = '<span class="badge bg-success">Active</span>';
            else if (status === 'inactive') statusText = '<span class="badge bg-secondary">Inactive</span>';
            document.getElementById('previewStatus').innerHTML = statusText;
        } else {
            previewCard.style.display = 'none';
        }
    }
    
    // Validate form before submission
    function validateForm() {
        const username = document.getElementById('username').value.trim();
        const fullName = document.getElementById('full_name').value.trim();
        const password = document.getElementById('password').value;
        const confirm = document.getElementById('confirm_password').value;
        const role = document.getElementById('role').value;
        
        if (username === '') {
            alert('Please enter username');
            document.getElementById('username').focus();
            return false;
        }
        
        if (fullName === '') {
            alert('Please enter full name');
            document.getElementById('full_name').focus();
            return false;
        }
        
        if (password === '') {
            alert('Please enter password');
            document.getElementById('password').focus();
            return false;
        }
        
        if (password.length < 6) {
            alert('Password must be at least 6 characters long');
            document.getElementById('password').focus();
            return false;
        }
        
        if (password !== confirm) {
            alert('Passwords do not match');
            document.getElementById('confirm_password').focus();
            return false;
        }
        
        if (role === '') {
            alert('Please select user role');
            document.getElementById('role').focus();
            return false;
        }
        
        return true;
    }
    
    // Reset form
    function resetForm() {
        document.getElementById('addUserForm').reset();
        document.getElementById('financeField').style.display = 'none';
        document.getElementById('previewCard').style.display = 'none';
        
        // Reset password checks
        document.getElementById('lengthCheck').className = 'invalid';
        document.getElementById('lengthCheck').innerHTML = '✗ At least 6 characters';
        document.getElementById('matchCheck').className = 'invalid';
        document.getElementById('matchCheck').innerHTML = '✗ Passwords match';
    }
    
    // Initialize on page load
    window.onload = function() {
        toggleFinanceField();
        updatePreview();
        
        // Add touch feedback
        const buttons = document.querySelectorAll('.btn');
        buttons.forEach(button => {
            button.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            button.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
</script>
</body>
</html>