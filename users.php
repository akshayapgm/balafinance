<?php
require_once 'includes/auth.php';
$currentPage = 'users';
$pageTitle = 'User Management';
require_once 'includes/db.php';

// Get user role from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to manage users (only admin can manage users)
if ($user_role != 'admin') {
    $error = "You don't have permission to manage users. Only administrators can access this page.";
}

$message = '';
$error = isset($error) ? $error : '';

// Handle user deletion (only admin can delete)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $user_role == 'admin') {
    $delete_id = intval($_GET['delete']);
    
    // Don't allow deleting own account
    if ($delete_id != $_SESSION['user_id']) {
        // Check if user exists before deleting
        $check_query = "SELECT id, username, full_name FROM users WHERE id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $check_result = $stmt->get_result();
        
        if ($check_result && $check_result->num_rows > 0) {
            $user_data = $check_result->fetch_assoc();
            $username = $user_data['username'];
            $full_name = $user_data['full_name'];
            
            // Delete the user
            $delete_query = "DELETE FROM users WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("i", $delete_id);
            
            if ($stmt->execute()) {
                // Log the activity
                if (function_exists('logActivity')) {
                    logActivity($conn, 'delete_user', "Deleted user: $username (ID: $delete_id)");
                }
                $message = "User deleted successfully!";
            } else {
                $error = "Error deleting user: " . $conn->error;
            }
        } else {
            $error = "User not found.";
        }
        $stmt->close();
    } else {
        $error = "You cannot delete your own account!";
    }
}

// Handle status toggle (activate/deactivate)
if (isset($_GET['toggle']) && is_numeric($_GET['toggle']) && $user_role == 'admin') {
    $toggle_id = intval($_GET['toggle']);
    
    // Don't allow toggling own account status
    if ($toggle_id != $_SESSION['user_id']) {
        // Get current status
        $status_query = "SELECT status, username FROM users WHERE id = ?";
        $stmt = $conn->prepare($status_query);
        $stmt->bind_param("i", $toggle_id);
        $stmt->execute();
        $status_result = $stmt->get_result();
        
        if ($status_result && $status_result->num_rows > 0) {
            $user_data = $status_result->fetch_assoc();
            $current_status = $user_data['status'];
            $username = $user_data['username'];
            $new_status = ($current_status == 'active') ? 'inactive' : 'active';
            
            // Update status
            $update_query = "UPDATE users SET status = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("si", $new_status, $toggle_id);
            
            if ($stmt->execute()) {
                // Log the activity
                if (function_exists('logActivity')) {
                    logActivity($conn, 'toggle_user_status', "Changed user $username status to $new_status");
                }
                $message = "User status updated successfully!";
            } else {
                $error = "Error updating user status: " . $conn->error;
            }
        } else {
            $error = "User not found.";
        }
        $stmt->close();
    } else {
        $error = "You cannot change your own account status!";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$role_filter = isset($_GET['role']) ? mysqli_real_escape_string($conn, $_GET['role']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Build query with filters
$where_clauses = [];

if (!empty($search)) {
    $where_clauses[] = "(u.username LIKE '%$search%' OR u.full_name LIKE '%$search%' OR u.email LIKE '%$search%')";
}
if (!empty($role_filter)) {
    $where_clauses[] = "u.role = '$role_filter'";
}
if (!empty($status_filter)) {
    $where_clauses[] = "u.status = '$status_filter'";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get all users with finance company details
$users_query = "SELECT u.*, f.finance_name 
                FROM users u 
                LEFT JOIN finance f ON u.finance_id = f.id 
                $where_sql
                ORDER BY u.id DESC";

$users = $conn->query($users_query);

// Get statistics - Updated to properly count staff and accountant
$stats_query = "SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
                SUM(CASE WHEN role = 'staff' THEN 1 ELSE 0 END) as total_staff,
                SUM(CASE WHEN role = 'accountant' THEN 1 ELSE 0 END) as total_accountants,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users
                FROM users";
$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];

// Ensure all values are set to prevent undefined index errors
$stats = array_merge([
    'total_users' => 0,
    'total_admins' => 0,
    'total_staff' => 0,
    'total_accountants' => 0,
    'active_users' => 0,
    'inactive_users' => 0
], $stats);

// Get finance companies for filter
$finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo $pageTitle; ?> - Finance Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 2rem;
            width: 100%;
        }
        
        .filter-card .form-control,
        .filter-card .form-select,
        .filter-card .btn {
            font-size: clamp(0.85rem, 3vw, 1rem);
        }
        
        /* User Cards */
        .user-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            height: 100%;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .user-card:active {
            transform: scale(0.98);
        }
        
        .user-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        
        .user-header h5 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            font-size: clamp(1rem, 3.5vw, 1.1rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .user-header small {
            color: var(--text-muted);
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .user-body {
            padding: 1.25rem;
        }
        
        .user-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: clamp(0.8rem, 2.8vw, 0.9rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .user-info-item i {
            width: 20px;
            color: var(--primary);
            flex-shrink: 0;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .user-info-item span {
            word-break: break-word;
            overflow-wrap: break-word;
            flex: 1;
        }
        
        .user-footer {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            font-weight: 600;
            text-align: center;
            min-width: 80px;
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
        
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            font-weight: 600;
            min-width: 70px;
            text-align: center;
            white-space: nowrap;
        }
        
        .status-badge.active {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .status-badge.inactive {
            background: var(--danger-bg);
            color: var(--danger);
        }
        
        .finance-badge {
            background: var(--info-bg);
            color: var(--info);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: clamp(0.65rem, 2.2vw, 0.75rem);
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-avatar {
            width: clamp(40px, 8vw, 50px);
            height: clamp(40px, 8vw, 50px);
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: clamp(1rem, 3vw, 1.2rem);
            flex-shrink: 0;
        }
        
        .small-avatar {
            width: clamp(30px, 6vw, 35px);
            height: clamp(30px, 6vw, 35px);
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
        }
        
        /* Table Styles - Fixed Overflow */
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }
        
        .table-custom th {
            text-align: left;
            padding: 0.75rem;
            background: #f8f9fa;
            color: var(--text-muted);
            font-weight: 600;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        
        .table-custom td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: clamp(0.8rem, 2.8vw, 0.9rem);
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 200px;
        }
        
        .table-custom td:first-child {
            white-space: nowrap;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.4rem;
            border-radius: 6px;
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            min-width: 32px;
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .action-buttons .btn i {
            font-size: clamp(0.8rem, 2.8vw, 0.9rem);
        }
        
        .action-buttons .btn:active {
            transform: scale(0.95);
        }
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .view-toggle .btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            transition: all 0.2s ease;
            white-space: nowrap;
            font-size: clamp(0.8rem, 3vw, 0.95rem);
            flex: 0 1 auto;
        }
        
        .view-toggle .btn:active {
            transform: scale(0.95);
        }
        
        .view-toggle .btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Permission Denied */
        .permission-denied {
            text-align: center;
            padding: 3rem;
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
            max-width: 100%;
            overflow: hidden;
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
        
        /* Role Permissions Guide */
        .role-guide-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .role-guide-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
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
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .page-content {
                padding: 1rem;
            }
            
            .table-container {
                padding: 0.5rem;
                margin: 0 -0.5rem;
                width: calc(100% + 1rem);
                border-radius: 0;
            }
            
            .table-custom {
                min-width: 800px;
            }
            
            .view-toggle {
                justify-content: center;
            }
            
            .welcome-banner .d-flex {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .filter-card .row {
                --bs-gutter-y: 0.5rem;
            }
            
            .filter-card .col-md-4,
            .filter-card .col-md-3,
            .filter-card .col-md-2 {
                width: 100%;
            }
            
            .filter-card .btn {
                width: 100%;
            }
            
            .user-footer {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                width: 100%;
                justify-content: flex-start;
            }
            
            .role-guide-card {
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
            
            .user-header {
                padding: 1rem;
            }
            
            .user-body {
                padding: 1rem;
            }
            
            .user-footer {
                padding: 0.75rem 1rem;
            }
            
            .action-buttons .btn {
                min-width: 35px;
                min-height: 35px;
            }
            
            .role-badge {
                min-width: 70px;
                font-size: 0.75rem;
            }
            
            .status-badge {
                min-width: 60px;
                font-size: 0.75rem;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .user-card,
        .stat-card {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Empty state */
        .empty-state {
            padding: 3rem 1rem;
            max-width: 100%;
            overflow: hidden;
        }
        
        .empty-state i {
            font-size: clamp(2.5rem, 10vw, 3rem);
        }
        
        .empty-state h5 {
            font-size: clamp(1.1rem, 4vw, 1.3rem);
            word-wrap: break-word;
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
                        <h3 class="text-truncate-custom"><i class="bi bi-people-fill me-2"></i>User Management</h3>
                        <p class="text-truncate-custom">Manage system users and their permissions</p>
                    </div>
                    <?php if ($user_role == 'admin'): ?>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <a href="add-user.php" class="btn btn-light">
                            <i class="bi bi-person-plus me-2"></i>Add New User
                        </a>
                        <span class="badge bg-white text-primary">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <span class="break-word"><?php echo $message; ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <span class="break-word"><?php echo $error; ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Check if user has permission -->
            <?php if ($user_role == 'admin'): ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4" data-testid="stats-cards">
                    <div class="col-6 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Total Users</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                                </div>
                                <div class="stat-icon blue flex-shrink-0"><i class="bi bi-people"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Registered users</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Active Users</div>
                                    <div class="stat-value"><?php echo number_format($stats['active_users']); ?></div>
                                </div>
                                <div class="stat-icon green flex-shrink-0"><i class="bi bi-check-circle"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Active accounts</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Administrators</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_admins']); ?></div>
                                </div>
                                <div class="stat-icon orange flex-shrink-0"><i class="bi bi-shield-lock"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Admin accounts</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Staff & Accountants</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_staff'] + $stats['total_accountants']); ?></div>
                                </div>
                                <div class="stat-icon purple flex-shrink-0"><i class="bi bi-person-badge"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Staff: <?php echo $stats['total_staff']; ?>, Accountants: <?php echo $stats['total_accountants']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" action="" id="filterForm">
                        <div class="row g-2">
                            <div class="col-12 col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent border-end-0">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0" name="search" 
                                           placeholder="Search by name, username or email" 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <select class="form-select" name="role">
                                    <option value="">All Roles</option>
                                    <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="staff" <?php echo $role_filter == 'staff' ? 'selected' : ''; ?>>Staff</option>
                                    <option value="accountant" <?php echo $role_filter == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-funnel me-2"></i>Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- View Toggle -->
                <div class="view-toggle justify-content-end">
                    <button type="button" class="btn btn-outline-primary active" id="gridViewBtn" onclick="toggleView('grid')">
                        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Grid
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="listViewBtn" onclick="toggleView('list')">
                        <i class="bi bi-list-ul me-2"></i>List
                    </button>
                </div>

                <!-- Grid View -->
                <div id="gridView" class="row g-3">
                    <?php if ($users && $users->num_rows > 0): ?>
                        <?php while ($user = $users->fetch_assoc()): 
                            // Get initials for avatar
                            $initials = '';
                            $nameParts = explode(' ', $user['full_name'] ?? $user['username']);
                            foreach ($nameParts as $part) {
                                $initials .= strtoupper(substr($part, 0, 1));
                            }
                            $initials = substr($initials, 0, 2) ?: 'U';
                        ?>
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="user-card">
                                    <div class="user-header">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="user-avatar flex-shrink-0">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div class="overflow-hidden">
                                                <h5 class="text-truncate"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h5>
                                                <small class="text-truncate d-block">@<?php echo htmlspecialchars($user['username']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="user-body">
                                        <div class="user-info-item">
                                            <i class="bi bi-envelope flex-shrink-0"></i>
                                            <span class="text-truncate"><?php echo htmlspecialchars($user['email'] ?: 'No email'); ?></span>
                                        </div>
                                        <div class="user-info-item">
                                            <i class="bi bi-shield-lock flex-shrink-0"></i>
                                            <span class="role-badge <?php echo $user['role'] ?: 'staff'; ?>">
                                                <?php echo $user['role'] ? ucfirst($user['role']) : 'Staff'; ?>
                                            </span>
                                        </div>
                                        <?php if ($user['finance_name']): ?>
                                        <div class="user-info-item">
                                            <i class="bi bi-building flex-shrink-0"></i>
                                            <span class="finance-badge text-truncate" title="<?php echo htmlspecialchars($user['finance_name']); ?>">
                                                <?php echo htmlspecialchars($user['finance_name']); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="user-info-item">
                                            <i class="bi bi-calendar flex-shrink-0"></i>
                                            <span class="text-truncate">Last Login: <?php echo $user['last_login'] ? date('d M Y', strtotime($user['last_login'])) : 'Never'; ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="user-footer">
                                        <span class="status-badge <?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                        <div class="action-buttons">
                                            <a href="edit-user.php?id=<?php echo $user['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="?toggle=<?php echo $user['id']; ?>" 
                                               class="btn btn-sm btn-outline-<?php echo $user['status'] == 'active' ? 'warning' : 'success'; ?>"
                                               title="<?php echo $user['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>"
                                               onclick="return confirm('Are you sure you want to <?php echo $user['status'] == 'active' ? 'deactivate' : 'activate'; ?> this user?')">
                                                <i class="bi bi-<?php echo $user['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                            </a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="?delete=<?php echo $user['id']; ?>" 
                                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="empty-state text-center py-5">
                                <i class="bi bi-people" style="font-size: 3rem; color: #dee2e6;"></i>
                                <h5 class="mt-3 text-muted break-word">No users found</h5>
                                <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter)): ?>
                                    <p class="text-muted break-word">Try adjusting your filters</p>
                                    <a href="users.php" class="btn btn-outline-primary">Clear Filters</a>
                                <?php else: ?>
                                    <a href="add-user.php" class="btn btn-primary">
                                        <i class="bi bi-person-plus me-2"></i>Add Your First User
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- List View -->
                <div id="listView" style="display: none;">
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table-custom" id="usersTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Finance</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($users && $users->num_rows > 0) {
                                        $users->data_seek(0);
                                        while ($user = $users->fetch_assoc()): 
                                            // Get initials for avatar
                                            $initials = '';
                                            $nameParts = explode(' ', $user['full_name'] ?? $user['username']);
                                            foreach ($nameParts as $part) {
                                                $initials .= strtoupper(substr($part, 0, 1));
                                            }
                                            $initials = substr($initials, 0, 2) ?: 'U';
                                    ?>
                                        <tr>
                                            <td><span class="fw-semibold">#<?php echo $user['id']; ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="user-avatar small-avatar flex-shrink-0">
                                                        <?php echo $initials; ?>
                                                    </div>
                                                    <div class="overflow-hidden">
                                                        <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="text-truncate d-block" style="max-width: 100px;"><?php echo htmlspecialchars($user['username']); ?></span></td>
                                            <td><span class="text-truncate d-block" style="max-width: 150px;" title="<?php echo htmlspecialchars($user['email'] ?: ''); ?>"><?php echo htmlspecialchars($user['email'] ?: '—'); ?></span></td>
                                            <td>
                                                <span class="role-badge <?php echo $user['role'] ?: 'staff'; ?>">
                                                    <?php echo $user['role'] ? ucfirst($user['role']) : 'Staff'; ?>
                                                </span>
                                            </td>
                                            <td><span class="text-truncate d-block" style="max-width: 100px;" title="<?php echo htmlspecialchars($user['finance_name'] ?? ''); ?>"><?php echo $user['finance_name'] ?? '—'; ?></span></td>
                                            <td>
                                                <span class="status-badge <?php echo $user['status']; ?>">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td><span class="text-nowrap"><?php echo $user['last_login'] ? date('d M Y', strtotime($user['last_login'])) : 'Never'; ?></span></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="edit-user.php?id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="?toggle=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-outline-<?php echo $user['status'] == 'active' ? 'warning' : 'success'; ?>"
                                                       title="<?php echo $user['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>"
                                                       onclick="return confirm('Are you sure you want to <?php echo $user['status'] == 'active' ? 'deactivate' : 'activate'; ?> this user?')">
                                                        <i class="bi bi-<?php echo $user['status'] == 'active' ? 'pause' : 'play'; ?>"></i>
                                                    </a>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <a href="?delete=<?php echo $user['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" title="Delete"
                                                           onclick="return confirm('Are you sure you want to delete this user?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php 
                                        endwhile;
                                    } 
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($users && $users->num_rows > 0): ?>
                            <div class="text-muted small mt-3">
                                Showing <?php echo $users->num_rows; ?> users
                                <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter)): ?>
                                    (filtered)
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Role Permissions Guide -->
                <div class="row mt-4 g-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-transparent">
                                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>User Role Permissions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="role-guide-card">
                                            <div class="d-flex align-items-start gap-3">
                                                <div class="role-badge admin flex-shrink-0">Admin</div>
                                                <div class="overflow-hidden">
                                                    <h6 class="mb-1">Full System Access</h6>
                                                    <ul class="small text-muted mb-0 ps-3">
                                                        <li class="break-word">Manage all finance companies</li>
                                                        <li class="break-word">Add/Edit/Delete users</li>
                                                        <li class="break-word">View all reports and data</li>
                                                        <li class="break-word">System settings access</li>
                                                        <li class="break-word">Bulk operations</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="role-guide-card">
                                            <div class="d-flex align-items-start gap-3">
                                                <div class="role-badge staff flex-shrink-0">Staff</div>
                                                <div class="overflow-hidden">
                                                    <h6 class="mb-1">Operational Access (Sales Person)</h6>
                                                    <ul class="small text-muted mb-0 ps-3">
                                                        <li class="break-word">Manage assigned finance company</li>
                                                        <li class="break-word">Add and manage customers</li>
                                                        <li class="break-word">Create and manage loans</li>
                                                        <li class="break-word">Record collections</li>
                                                        <li class="break-word">View basic reports</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="role-guide-card">
                                            <div class="d-flex align-items-start gap-3">
                                                <div class="role-badge accountant flex-shrink-0">Accountant</div>
                                                <div class="overflow-hidden">
                                                    <h6 class="mb-1">Financial Access</h6>
                                                    <ul class="small text-muted mb-0 ps-3">
                                                        <li class="break-word">View all financial data</li>
                                                        <li class="break-word">Record and manage expenses</li>
                                                        <li class="break-word">Generate financial reports</li>
                                                        <li class="break-word">View collections and dues</li>
                                                        <li class="break-word">Cannot manage users</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Permission Denied Message -->
                <div class="permission-denied">
                    <i class="bi bi-shield-lock-fill"></i>
                    <h4 class="break-word">Access Denied</h4>
                    <p class="break-word">You don't have permission to manage users. Only administrators can access this page.</p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="bi bi-house-door me-2"></i>Go to Dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
    // Initialize DataTable for list view
    $(document).ready(function() {
        if ($.fn.DataTable.isDataTable('#usersTable')) {
            $('#usersTable').DataTable().destroy();
        }
        
        const isMobile = window.innerWidth <= 768;
        
        $('#usersTable').DataTable({
            paging: false,
            searching: false,
            ordering: true,
            info: false,
            columnDefs: [
                { orderable: false, targets: -1 }
            ],
            scrollX: isMobile ? true : false,
            scrollCollapse: isMobile ? true : false
        });
    });

    // Toggle between grid and list view
    function toggleView(view) {
        const gridView = document.getElementById('gridView');
        const listView = document.getElementById('listView');
        const gridBtn = document.getElementById('gridViewBtn');
        const listBtn = document.getElementById('listViewBtn');
        
        if (view === 'grid') {
            gridView.style.display = 'flex';
            listView.style.display = 'none';
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
            localStorage.setItem('usersView', 'grid');
        } else {
            gridView.style.display = 'none';
            listView.style.display = 'block';
            gridBtn.classList.remove('active');
            listBtn.classList.add('active');
            localStorage.setItem('usersView', 'list');
            
            setTimeout(function() {
                if ($.fn.DataTable.isDataTable('#usersTable')) {
                    $('#usersTable').DataTable().destroy();
                }
                
                const isMobile = window.innerWidth <= 768;
                
                $('#usersTable').DataTable({
                    paging: false,
                    searching: false,
                    ordering: true,
                    info: false,
                    columnDefs: [
                        { orderable: false, targets: -1 }
                    ],
                    scrollX: isMobile ? true : false,
                    scrollCollapse: isMobile ? true : false
                });
            }, 100);
        }
    }
    
    // Load saved view preference
    window.onload = function() {
        const savedView = localStorage.getItem('usersView');
        if (savedView === 'list') {
            toggleView('list');
        }
        
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
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (document.getElementById('listView').style.display === 'block') {
                if ($.fn.DataTable.isDataTable('#usersTable')) {
                    $('#usersTable').DataTable().destroy();
                }
                
                const isMobile = window.innerWidth <= 768;
                
                $('#usersTable').DataTable({
                    paging: false,
                    searching: false,
                    ordering: true,
                    info: false,
                    columnDefs: [
                        { orderable: false, targets: -1 }
                    ],
                    scrollX: isMobile ? true : false,
                    scrollCollapse: isMobile ? true : false
                });
            }
        }, 250);
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Add loading state to filter button
    document.getElementById('filterForm').addEventListener('submit', function() {
        const btn = this.querySelector('button[type="submit"]');
        btn.classList.add('btn-loading');
        btn.disabled = true;
    });
</script>
</body>
</html>