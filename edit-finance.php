<?php
require_once 'includes/auth.php';
$currentPage = 'edit-finance';
$pageTitle = 'Edit Finance Company';
require_once 'includes/db.php';

// Get user role from session
$user_role = $_SESSION['role'];

// Check if user has permission to edit finance companies (only admin can edit)
if ($user_role != 'admin') {
    $error = "You don't have permission to edit finance companies. Only administrators can access this page.";
}

$message = '';
$error = isset($error) ? $error : '';

// Get finance ID from URL
$finance_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($finance_id == 0) {
    header("Location: finance.php?error=invalid_id");
    exit();
}

// Fetch finance company details
$finance_query = "SELECT * FROM finance WHERE id = ?";
$stmt = $conn->prepare($finance_query);
$stmt->bind_param("i", $finance_id);
$stmt->execute();
$finance_result = $stmt->get_result();

if ($finance_result->num_rows == 0) {
    header("Location: finance.php?error=not_found");
    exit();
}

$finance = $finance_result->fetch_assoc();
$stmt->close();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $user_role == 'admin') {
    $finance_name = mysqli_real_escape_string($conn, $_POST['finance_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $pin = mysqli_real_escape_string($conn, $_POST['pin']);
    $status = isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : 'active';
    
    // Validation
    $errors = [];
    
    if (empty($finance_name)) {
        $errors[] = "Finance company name is required.";
    }
    
    // Check if finance company name already exists (excluding current)
    $check_query = "SELECT id FROM finance WHERE finance_name = ? AND id != ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("si", $finance_name, $finance_id);
    $stmt->execute();
    $check_result = $stmt->get_result();
    if ($check_result && $check_result->num_rows > 0) {
        $errors[] = "Finance company name already exists. Please choose another name.";
    }
    $stmt->close();
    
    // Validate PIN (4 digits)
    if (empty($pin)) {
        $errors[] = "PIN is required.";
    } elseif (!preg_match('/^\d{4}$/', $pin)) {
        $errors[] = "PIN must be exactly 4 digits.";
    }
    
    // If no errors, update finance company
    if (empty($errors)) {
        // Check if status column exists
        $column_check = $conn->query("SHOW COLUMNS FROM finance LIKE 'status'");
        $has_status = ($column_check && $column_check->num_rows > 0);
        
        if ($has_status) {
            $update_query = "UPDATE finance SET finance_name = ?, description = ?, pin = ?, status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ssssi", $finance_name, $description, $pin, $status, $finance_id);
        } else {
            $update_query = "UPDATE finance SET finance_name = ?, description = ?, pin = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("sssi", $finance_name, $description, $pin, $finance_id);
        }
        
        if ($stmt->execute()) {
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'edit_finance', "Updated finance company: $finance_name (ID: $finance_id)");
            }
            
            $message = "Finance company updated successfully!";
            
            // Refresh finance data
            $finance['finance_name'] = $finance_name;
            $finance['description'] = $description;
            $finance['pin'] = $pin;
            if ($has_status) {
                $finance['status'] = $status;
            }
        } else {
            $error = "Error updating finance company: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get statistics for this finance company
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM users WHERE finance_id = ?) as user_count,
                (SELECT COUNT(*) FROM customers WHERE finance_id = ?) as customer_count,
                (SELECT COUNT(*) FROM loans WHERE finance_id = ?) as loan_count,
                (SELECT COALESCE(SUM(loan_amount), 0) FROM customers WHERE finance_id = ?) as total_disbursed,
                (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE finance_id = ? AND status = 'paid') as total_collected
                FROM dual";

$stmt = $conn->prepare($stats_query);
$stmt->bind_param("iiiii", $finance_id, $finance_id, $finance_id, $finance_id, $finance_id);
$stmt->execute();
$stats_result = $stmt->get_result();
$stats = $stats_result->fetch_assoc();
$stmt->close();

// Check if status column exists
$status_column_exists = false;
$column_check = $conn->query("SHOW COLUMNS FROM finance LIKE 'status'");
if ($column_check && $column_check->num_rows > 0) {
    $status_column_exists = true;
}
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
            --warning: #f39c12;
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
        
        /* PIN Input Group */
        .pin-input-group {
            position: relative;
        }
        
        .pin-input-group .form-control {
            padding-right: 60px;
        }
        
        .pin-input-group .pin-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            z-index: 10;
        }
        
        .pin-input-group .pin-toggle:hover {
            color: var(--primary);
        }
        
        /* Requirements List */
        .requirements-list {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.5rem;
            font-size: clamp(0.8rem, 2.7vw, 0.9rem);
        }
        
        .requirements-list ul {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }
        
        .requirements-list li {
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            word-wrap: break-word;
        }
        
        .requirements-list li.valid {
            color: var(--success);
        }
        
        .requirements-list li.invalid {
            color: var(--danger);
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
        
        /* Status Badge */
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
        
        /* Company Info Card */
        .company-info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .company-info-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .company-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: clamp(0.85rem, 2.8vw, 0.95rem);
        }
        
        .company-info-item i {
            width: 20px;
            color: var(--primary);
            flex-shrink: 0;
        }
        
        .company-info-item span {
            word-break: break-word;
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
            
            .company-info-item {
                flex-wrap: wrap;
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
                font-size: 1.1rem;
            }
            
            .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 1.1rem;
            }
            
            .preview-card {
                padding: 1rem;
            }
            
            .btn-submit, .btn-reset {
                padding: 0.7rem 1rem;
            }
            
            .company-info-card {
                padding: 1rem;
            }
            
            .status-badge {
                min-width: 60px;
                font-size: 0.7rem;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .stat-card,
        .company-info-card {
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
                        <h3 class="text-truncate-custom"><i class="bi bi-building-gear me-2"></i>Edit Finance Company</h3>
                        <p class="text-truncate-custom">Update finance company details and settings</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <a href="finance.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Finance
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
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Users</div>
                                    <div class="stat-value"><?php echo $stats['user_count'] ?? 0; ?></div>
                                </div>
                                <div class="stat-icon blue flex-shrink-0"><i class="bi bi-people"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Assigned users</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Customers</div>
                                    <div class="stat-value"><?php echo $stats['customer_count'] ?? 0; ?></div>
                                </div>
                                <div class="stat-icon green flex-shrink-0"><i class="bi bi-person-badge"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Active customers</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Loans</div>
                                    <div class="stat-value"><?php echo $stats['loan_count'] ?? 0; ?></div>
                                </div>
                                <div class="stat-icon orange flex-shrink-0"><i class="bi bi-cash-stack"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Active loans</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Collected</div>
                                    <div class="stat-value">₹<?php echo number_format($stats['total_collected'] ?? 0, 0); ?></div>
                                </div>
                                <div class="stat-icon purple flex-shrink-0"><i class="bi bi-cash"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Total collected</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Company Info Card -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="company-info-card">
                            <h5 class="mb-3"><i class="bi bi-building me-2"></i>Company Info</h5>
                            <div class="company-info-item">
                                <i class="bi bi-tag"></i>
                                <span><strong>ID:</strong> #<?php echo $finance['id']; ?></span>
                            </div>
                            <div class="company-info-item">
                                <i class="bi bi-calendar"></i>
                                <span><strong>Created:</strong> <?php echo date('d M Y', strtotime($finance['created_at'])); ?></span>
                            </div>
                            <?php if (!empty($finance['updated_at']) && $finance['updated_at'] != $finance['created_at']): ?>
                            <div class="company-info-item">
                                <i class="bi bi-clock-history"></i>
                                <span><strong>Updated:</strong> <?php echo date('d M Y', strtotime($finance['updated_at'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($status_column_exists): ?>
                            <div class="company-info-item">
                                <i class="bi bi-toggle-on"></i>
                                <span><strong>Status:</strong> 
                                    <span class="status-badge <?php echo $finance['status'] ?? 'active'; ?>">
                                        <?php echo ucfirst($finance['status'] ?? 'active'); ?>
                                    </span>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-8">
                        <div class="company-info-card">
                            <h5 class="mb-3"><i class="bi bi-graph-up me-2"></i>Financial Summary</h5>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded">
                                        <small class="text-muted d-block">Total Disbursed</small>
                                        <strong class="h5">₹<?php echo number_format($stats['total_disbursed'] ?? 0, 2); ?></strong>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded">
                                        <small class="text-muted d-block">Recovery Rate</small>
                                        <strong class="h5">
                                            <?php 
                                            $rate = ($stats['total_disbursed'] > 0) ? 
                                                ($stats['total_collected'] / $stats['total_disbursed'] * 100) : 0;
                                            echo number_format($rate, 1) . '%';
                                            ?>
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Form Card -->
                <div class="form-card">
                    <div class="form-header">
                        <h2><i class="bi bi-pencil-square me-2"></i>Edit Company Details</h2>
                        <p class="break-word">Update the information for <?php echo htmlspecialchars($finance['finance_name']); ?></p>
                    </div>

                    <form method="POST" action="" id="editFinanceForm" onsubmit="return validateForm()">
                        <div class="row g-3">
                            <!-- Finance Company Name -->
                            <div class="col-12">
                                <label for="finance_name" class="form-label">
                                    <i class="bi bi-building me-1"></i>Finance Company Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="finance_name" name="finance_name" 
                                       value="<?php echo htmlspecialchars($finance['finance_name']); ?>" 
                                       required maxlength="255" 
                                       placeholder="e.g., SRI Sabarivasa Finance, ABC Finance, etc."
                                       onkeyup="updatePreview()">
                                <div class="form-text break-word">Enter the full name of the finance company</div>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label for="description" class="form-label">
                                    <i class="bi bi-card-text me-1"></i>Description
                                </label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Enter company description, address, or additional information"
                                          onkeyup="updatePreview()"><?php echo htmlspecialchars($finance['description'] ?? ''); ?></textarea>
                                <div class="form-text break-word">Optional: Add any additional information about the finance company</div>
                            </div>

                            <!-- PIN -->
                            <div class="col-md-6">
                                <label for="pin" class="form-label">
                                    <i class="bi bi-key me-1"></i>PIN Code <span class="text-danger">*</span>
                                </label>
                                <div class="pin-input-group">
                                    <input type="password" class="form-control" id="pin" name="pin" 
                                           value="<?php echo htmlspecialchars($finance['pin']); ?>" 
                                           required maxlength="4" pattern="\d{4}" 
                                           placeholder="1234"
                                           onkeyup="validatePIN(); updatePreview();">
                                    <button type="button" class="pin-toggle" onclick="togglePIN()">
                                        <i class="bi bi-eye" id="pinToggleIcon"></i>
                                    </button>
                                </div>
                                <div class="requirements-list">
                                    <small class="text-muted d-block mb-2">PIN requirements:</small>
                                    <ul id="pinRequirements" class="mb-0">
                                        <li id="pinLength" class="<?php echo (strlen($finance['pin']) == 4) ? 'valid' : 'invalid'; ?>">
                                            <?php echo (strlen($finance['pin']) == 4) ? '✓' : '✗'; ?> Exactly 4 digits
                                        </li>
                                        <li id="pinDigits" class="<?php echo (ctype_digit($finance['pin'])) ? 'valid' : 'invalid'; ?>">
                                            <?php echo (ctype_digit($finance['pin'])) ? '✓' : '✗'; ?> Only numbers allowed
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Status (if column exists) -->
                            <?php if ($status_column_exists): ?>
                            <div class="col-md-6">
                                <label for="status" class="form-label">
                                    <i class="bi bi-toggle-on me-1"></i>Status
                                </label>
                                <select class="form-select" id="status" name="status" onchange="updatePreview()">
                                    <option value="active" <?php echo ($finance['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($finance['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Change the status of the finance company</div>
                            </div>
                            <?php endif; ?>

                            <!-- Preview Section -->
                            <div class="col-12">
                                <div class="preview-card">
                                    <h6><i class="bi bi-eye me-2"></i>Live Preview</h6>
                                    <div class="preview-item">
                                        <span class="preview-label">Company Name:</span>
                                        <span class="preview-value break-word" id="previewName"><?php echo htmlspecialchars($finance['finance_name']); ?></span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Description:</span>
                                        <span class="preview-value break-word" id="previewDesc"><?php echo htmlspecialchars($finance['description'] ?? '—'); ?></span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">PIN:</span>
                                        <span class="preview-value" id="previewPIN">••••</span>
                                    </div>
                                    <?php if ($status_column_exists): ?>
                                    <div class="preview-item">
                                        <span class="preview-label">Status:</span>
                                        <span class="preview-value" id="previewStatus">
                                            <span class="status-badge <?php echo $finance['status'] ?? 'active'; ?>">
                                                <?php echo ucfirst($finance['status'] ?? 'active'); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Additional Information -->
                            <div class="col-12">
                                <div class="info-box">
                                    <i class="bi bi-info-circle flex-shrink-0"></i>
                                    <div class="break-word">
                                        <strong>Important Notes:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>Changing the PIN will affect access for staff and accountants</li>
                                            <li>Updating the company name will reflect in all related records</li>
                                            <li>Inactive companies will not appear in dropdown menus for new entries</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="col-12 mt-4">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <button type="submit" class="btn btn-submit">
                                            <i class="bi bi-check-circle me-2"></i>Update Company
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <a href="finance.php" class="btn btn-reset d-block text-center">
                                            <i class="bi bi-x-circle me-2"></i>Cancel
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- Permission Denied Message -->
                <div class="permission-denied">
                    <i class="bi bi-shield-lock-fill"></i>
                    <h4 class="break-word">Access Denied</h4>
                    <p class="break-word">You don't have permission to edit finance companies. Only administrators can access this page.</p>
                    <a href="finance.php" class="btn btn-primary">
                        <i class="bi bi-building me-2"></i>View Finance Companies
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // Toggle PIN visibility
    function togglePIN() {
        const pinInput = document.getElementById('pin');
        const icon = document.getElementById('pinToggleIcon');
        
        if (pinInput.type === 'password') {
            pinInput.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            pinInput.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }
    
    // Validate PIN
    function validatePIN() {
        const pin = document.getElementById('pin').value;
        const lengthCheck = document.getElementById('pinLength');
        const digitsCheck = document.getElementById('pinDigits');
        
        // Check length
        if (pin.length === 4) {
            lengthCheck.className = 'valid';
            lengthCheck.innerHTML = '✓ Exactly 4 digits';
        } else {
            lengthCheck.className = 'invalid';
            lengthCheck.innerHTML = '✗ Exactly 4 digits';
        }
        
        // Check if only digits
        if (/^\d*$/.test(pin)) {
            digitsCheck.className = 'valid';
            digitsCheck.innerHTML = '✓ Only numbers allowed';
        } else {
            digitsCheck.className = 'invalid';
            digitsCheck.innerHTML = '✗ Only numbers allowed';
        }
    }
    
    // Update preview
    function updatePreview() {
        const name = document.getElementById('finance_name').value;
        const desc = document.getElementById('description').value;
        const pin = document.getElementById('pin').value;
        
        document.getElementById('previewName').textContent = name || '—';
        document.getElementById('previewDesc').textContent = desc || '—';
        document.getElementById('previewPIN').textContent = pin ? '••••' : '—';
        
        <?php if ($status_column_exists): ?>
        const status = document.getElementById('status').value;
        const statusSpan = document.getElementById('previewStatus');
        statusSpan.innerHTML = `<span class="status-badge ${status}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
        <?php endif; ?>
    }
    
    // Validate form before submission
    function validateForm() {
        const name = document.getElementById('finance_name').value.trim();
        const pin = document.getElementById('pin').value.trim();
        
        if (name === '') {
            alert('Please enter finance company name');
            document.getElementById('finance_name').focus();
            return false;
        }
        
        if (pin === '') {
            alert('Please enter PIN');
            document.getElementById('pin').focus();
            return false;
        }
        
        if (pin.length !== 4 || !/^\d+$/.test(pin)) {
            alert('PIN must be exactly 4 digits (numbers only)');
            document.getElementById('pin').focus();
            return false;
        }
        
        return confirm('Are you sure you want to update this finance company?');
    }
    
    // Add touch feedback
    window.onload = function() {
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