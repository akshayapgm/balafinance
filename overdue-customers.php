<?php
require_once 'includes/auth.php';
$currentPage = 'overdue-customers';
$pageTitle = 'Overdue Customers';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to view customers
if ($user_role != 'admin' && $user_role != 'accountant' && $user_role != 'staff') {
    $error = "You don't have permission to view customers.";
}

$message = '';
$error = isset($error) ? $error : '';

// Handle payment collection (record payment for overdue EMI)
if (isset($_POST['collect_payment']) && isset($_POST['emi_id'])) {
    $emi_id = intval($_POST['emi_id']);
    $customer_id = intval($_POST['customer_id']);
    $paid_amount = floatval($_POST['paid_amount']);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
    
    // Get EMI details
    $emi_query = "SELECT * FROM emi_schedule WHERE id = ? AND customer_id = ?";
    $stmt = $conn->prepare($emi_query);
    $stmt->bind_param("ii", $emi_id, $customer_id);
    $stmt->execute();
    $emi_result = $stmt->get_result();
    $emi = $emi_result->fetch_assoc();
    $stmt->close();
    
    if ($emi) {
        // Update EMI status
        $update_query = "UPDATE emi_schedule SET status = 'paid', paid_date = ?, overdue_charges = 0 WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $payment_date, $emi_id);
        
        if ($stmt->execute()) {
            // Insert payment log
            $log_query = "INSERT INTO payment_logs (emi_id, customer_id, payment_date, total_amount, remark) 
                         VALUES (?, ?, ?, ?, ?)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->bind_param("iisds", $emi_id, $customer_id, $payment_date, $paid_amount, $remarks);
            $log_stmt->execute();
            $log_stmt->close();
            
            $message = "Payment collected successfully!";
        } else {
            $error = "Error collecting payment: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : '';
$days_filter = isset($_GET['days']) ? intval($_GET['days']) : 0;

// Build query with filters
$where_clauses = [];

if ($user_role != 'admin') {
    // Non-admin users see only their finance company's customers
    $where_clauses[] = "c.finance_id = $finance_id";
}

// Only show customers with overdue EMIs
$where_clauses[] = "c.id IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status = 'overdue')";

if (!empty($search)) {
    $where_clauses[] = "(c.customer_name LIKE '%$search%' OR c.customer_number LIKE '%$search%' OR c.agreement_number LIKE '%$search%' OR c.vehicle_number LIKE '%$search%')";
}

if (!empty($finance_filter) && $user_role == 'admin') {
    $where_clauses[] = "c.finance_id = $finance_filter";
}

if ($days_filter > 0) {
    $where_clauses[] = "c.id IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status = 'overdue' AND DATEDIFF(CURDATE(), emi_due_date) >= $days_filter)";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get all overdue customers with statistics
$customers_query = "SELECT c.*, 
                    f.finance_name,
                    l.loan_name,
                    (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') as overdue_count,
                    (SELECT MIN(emi_due_date) FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') as earliest_overdue,
                    (SELECT MAX(emi_due_date) FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') as latest_overdue,
                    (SELECT SUM(emi_amount) FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') as total_overdue_amount,
                    (SELECT GROUP_CONCAT(id) FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') as overdue_emi_ids,
                    (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id) as total_emis,
                    (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as paid_emis
                    FROM customers c
                    LEFT JOIN finance f ON c.finance_id = f.id
                    LEFT JOIN loans l ON c.loan_id = l.id
                    $where_sql
                    ORDER BY earliest_overdue ASC";

$customers = $conn->query($customers_query);

// Get finance companies for filter dropdown (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get detailed overdue EMIs for each customer (for popup)
$overdue_emis = [];
if ($customers && $customers->num_rows > 0) {
    $customers->data_seek(0);
    while ($customer = $customers->fetch_assoc()) {
        if (!empty($customer['overdue_emi_ids'])) {
            $emi_ids = $customer['overdue_emi_ids'];
            $emi_query = "SELECT es.*, DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue 
                         FROM emi_schedule es 
                         WHERE es.id IN ($emi_ids) 
                         ORDER BY es.emi_due_date ASC";
            $emi_result = $conn->query($emi_query);
            if ($emi_result && $emi_result->num_rows > 0) {
                $overdue_emis[$customer['id']] = [];
                while ($emi = $emi_result->fetch_assoc()) {
                    $overdue_emis[$customer['id']][] = $emi;
                }
            }
        }
    }
    $customers->data_seek(0);
}

// Get overall statistics for overdue customers
$stats_query = "SELECT 
                COUNT(DISTINCT c.id) as total_overdue_customers,
                SUM(CASE WHEN c.id IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status = 'overdue') THEN 1 ELSE 0 END) as overdue_customers,
                (SELECT COUNT(*) FROM emi_schedule WHERE status = 'overdue') as total_overdue_emis,
                (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE status = 'overdue') as total_overdue_amount,
                (SELECT COALESCE(AVG(DATEDIFF(CURDATE(), emi_due_date)), 0) FROM emi_schedule WHERE status = 'overdue') as avg_days_overdue,
                (SELECT MAX(DATEDIFF(CURDATE(), emi_due_date)) FROM emi_schedule WHERE status = 'overdue') as max_days_overdue
                FROM customers c";

if ($user_role != 'admin') {
    $stats_query .= " WHERE c.finance_id = $finance_id";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];

// Get collection summary for overdue payments
$collection_query = "SELECT 
                    COALESCE(SUM(CASE WHEN es.status = 'overdue' THEN es.emi_amount ELSE 0 END), 0) as total_overdue,
                    COUNT(DISTINCT es.customer_id) as customers_with_overdue
                    FROM emi_schedule es
                    JOIN customers c ON es.customer_id = c.id
                    WHERE es.status = 'overdue'";

if ($user_role != 'admin') {
    $collection_query .= " AND c.finance_id = $finance_id";
}

$collection_result = $conn->query($collection_query);
$collection = $collection_result ? $collection_result->fetch_assoc() : [];
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
            background: linear-gradient(135deg, var(--danger), #c0392b);
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
            color: var(--danger);
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
            border-color: var(--danger);
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
        
        .stat-icon.danger {
            background: var(--danger-bg);
            color: var(--danger);
        }
        
        .stat-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .stat-icon.info {
            background: var(--info-bg);
            color: var(--info);
        }
        
        .stat-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
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
        
        /* Customer Cards */
        .customer-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            height: 100%;
            overflow: hidden;
            transition: all 0.2s ease;
            border-left: 4px solid var(--danger);
        }
        
        .customer-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--danger);
        }
        
        .customer-card:active {
            transform: scale(0.98);
        }
        
        .customer-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        
        .customer-header h5 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            font-size: clamp(1rem, 3.5vw, 1.1rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .customer-header small {
            color: var(--text-muted);
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .customer-body {
            padding: 1.25rem;
        }
        
        .customer-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: clamp(0.8rem, 2.8vw, 0.85rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .customer-info-item i {
            width: 18px;
            color: var(--danger);
            flex-shrink: 0;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .customer-info-item span {
            word-break: break-word;
            overflow-wrap: break-word;
            flex: 1;
        }
        
        .customer-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin: 1rem 0;
        }
        
        .customer-stat-item {
            text-align: center;
            padding: 0.4rem;
            background: #f8f9fa;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .customer-stat-value {
            font-size: clamp(0.9rem, 3vw, 1rem);
            font-weight: 700;
            color: var(--danger);
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .customer-stat-label {
            font-size: clamp(0.6rem, 2vw, 0.65rem);
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .customer-footer {
            padding: 0.75rem 1.25rem;
            background: #f8f9fa;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        
        .finance-badge {
            background: var(--info-bg);
            color: var(--info);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: clamp(0.65rem, 2.2vw, 0.7rem);
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .loan-badge {
            background: var(--warning-bg);
            color: var(--warning);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: clamp(0.65rem, 2.2vw, 0.7rem);
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .overdue-badge {
            background: var(--danger-bg);
            color: var(--danger);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: clamp(0.7rem, 2.4vw, 0.75rem);
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
        }
        
        .severity-high {
            background: #e74c3c;
            color: white;
        }
        
        .severity-medium {
            background: #e67e22;
            color: white;
        }
        
        .severity-low {
            background: #f39c12;
            color: white;
        }
        
        .progress {
            height: 6px;
            border-radius: 3px;
            margin-top: 0.5rem;
            width: 100%;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, var(--danger), #c0392b);
            border-radius: 3px;
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
            min-width: 900px; /* Reduced from 1000px */
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
        
        .table-custom tbody tr.overdue-row {
            border-left: 4px solid var(--danger);
        }
        
        .customer-avatar {
            width: clamp(35px, 8vw, 40px);
            height: clamp(35px, 8vw, 40px);
            border-radius: 50%;
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: clamp(0.9rem, 3vw, 1rem);
            flex-shrink: 0;
        }
        
        .small-avatar {
            width: clamp(25px, 6vw, 30px);
            height: clamp(25px, 6vw, 30px);
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
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
            background: var(--danger);
            color: white;
            border-color: var(--danger);
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
        
        /* Badge group */
        .badge-group {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
            max-width: 100%;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
            border-radius: var(--radius) var(--radius) 0 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-body {
            max-height: 60vh;
            overflow-y: auto;
            padding: 1rem;
        }
        
        .emi-list-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-left: 3px solid var(--danger);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .emi-list-item .date {
            font-weight: 600;
            color: var(--danger);
            white-space: nowrap;
        }
        
        .emi-list-item .amount {
            font-weight: 700;
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
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .page-content {
                padding: 1rem;
            }
            
            .customer-stats {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                padding: 0.5rem;
                margin: 0 -0.5rem;
                width: calc(100% + 1rem);
                border-radius: 0;
            }
            
            .table-custom {
                min-width: 700px; /* Adjusted for mobile */
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
            
            .action-buttons .btn {
                min-width: 36px;
                min-height: 36px;
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
            
            .customer-header {
                padding: 0.75rem;
            }
            
            .customer-body {
                padding: 0.75rem;
            }
            
            .customer-footer {
                padding: 0.5rem 0.75rem;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                width: 100%;
                justify-content: flex-start;
            }
            
            .action-buttons .btn {
                flex: 1;
                min-width: 40px;
            }
            
            .badge-group {
                flex-direction: row;
                flex-wrap: wrap;
            }
            
            .finance-badge,
            .loan-badge {
                max-width: 100%;
            }
        }
        
        @media (max-width: 380px) {
            .page-content {
                padding: 0.5rem;
            }
            
            .welcome-banner {
                padding: 0.75rem;
            }
            
            .welcome-banner h3 {
                font-size: 1.1rem;
            }
            
            .stat-value {
                font-size: 1.1rem;
            }
            
            .table-custom {
                min-width: 600px;
            }
            
            .action-buttons .btn {
                min-width: 35px;
                min-height: 35px;
                padding: 0.15rem 0.25rem;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .customer-card,
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
        
        .empty-state p {
            word-wrap: break-word;
        }
        
        /* Alert messages */
        .alert {
            padding: 0.75rem 1rem;
            font-size: clamp(0.85rem, 3vw, 0.95rem);
            margin-bottom: 1rem;
            word-wrap: break-word;
        }
        
        /* Utility classes for overflow control */
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
                        <h3 class="text-truncate-custom"><i class="bi bi-exclamation-triangle me-2"></i>Overdue Customers</h3>
                        <p class="text-truncate-custom">Customers with delayed payments requiring immediate attention</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <a href="customers.php" class="btn btn-light">
                            <i class="bi bi-people me-2"></i>All Customers
                        </a>
                        <span class="badge bg-white text-danger">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
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

            <!-- Statistics Cards -->
            <div class="row g-3 mb-4" data-testid="stats-cards">
                <div class="col-6 col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Overdue Customers</div>
                                <div class="stat-value"><?php echo number_format($stats['overdue_customers'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon danger flex-shrink-0"><i class="bi bi-people"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Need attention</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-6 col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Overdue EMIs</div>
                                <div class="stat-value"><?php echo number_format($stats['total_overdue_emis'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon warning flex-shrink-0"><i class="bi bi-exclamation-circle"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Pending payments</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-6 col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Total Amount</div>
                                <div class="stat-value break-word">₹<?php echo number_format($stats['total_overdue_amount'] ?? 0, 0); ?></div>
                            </div>
                            <div class="stat-icon purple flex-shrink-0"><i class="bi bi-cash-stack"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Overdue amount</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-6 col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Avg Delay</div>
                                <div class="stat-value"><?php echo round($stats['avg_days_overdue'] ?? 0); ?> days</div>
                            </div>
                            <div class="stat-icon info flex-shrink-0"><i class="bi bi-clock-history"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Average delay</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2">
                        <div class="col-12 col-md-<?php echo $user_role == 'admin' ? '4' : '6'; ?>">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control border-start-0" name="search" 
                                       placeholder="Search customers..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <?php if ($user_role == 'admin' && $finance_companies && $finance_companies->num_rows > 0): ?>
                        <div class="col-12 col-md-3">
                            <select class="form-select" name="finance_id">
                                <option value="">All Companies</option>
                                <?php 
                                $finance_companies->data_seek(0);
                                while ($fc = $finance_companies->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $fc['id']; ?>" 
                                        <?php echo $finance_filter == $fc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($fc['finance_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-12 col-md-<?php echo $user_role == 'admin' ? '3' : '4'; ?>">
                            <select class="form-select" name="days">
                                <option value="0">All Overdue</option>
                                <option value="7" <?php echo $days_filter == 7 ? 'selected' : ''; ?>>7+ days overdue</option>
                                <option value="15" <?php echo $days_filter == 15 ? 'selected' : ''; ?>>15+ days overdue</option>
                                <option value="30" <?php echo $days_filter == 30 ? 'selected' : ''; ?>>30+ days overdue</option>
                                <option value="60" <?php echo $days_filter == 60 ? 'selected' : ''; ?>>60+ days overdue</option>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-<?php echo $user_role == 'admin' ? '2' : '2'; ?>">
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-funnel me-2"></i>Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- View Toggle -->
            <div class="view-toggle justify-content-end">
                <button type="button" class="btn btn-outline-danger active" id="gridViewBtn" onclick="toggleView('grid')">
                    <i class="bi bi-grid-3x3-gap-fill me-2"></i>Grid
                </button>
                <button type="button" class="btn btn-outline-danger" id="listViewBtn" onclick="toggleView('list')">
                    <i class="bi bi-list-ul me-2"></i>List
                </button>
            </div>

            <!-- Grid View -->
            <div id="gridView" class="row g-3">
                <?php if ($customers && $customers->num_rows > 0): ?>
                    <?php while ($customer = $customers->fetch_assoc()): 
                        $total_emis = max($customer['total_emis'], 1);
                        $paid_emis = $customer['paid_emis'] ?: 0;
                        $progress = ($paid_emis / $total_emis) * 100;
                        
                        // Calculate severity based on days overdue
                        $days_overdue = $customer['earliest_overdue'] ? floor((time() - strtotime($customer['earliest_overdue'])) / (60 * 60 * 24)) : 0;
                        $severity_class = 'severity-low';
                        if ($days_overdue > 30) {
                            $severity_class = 'severity-high';
                        } elseif ($days_overdue > 15) {
                            $severity_class = 'severity-medium';
                        }
                        
                        // Get initials for avatar
                        $initials = '';
                        $nameParts = explode(' ', $customer['customer_name']);
                        foreach ($nameParts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                        $initials = substr($initials, 0, 2) ?: 'CU';
                    ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="customer-card">
                                <div class="customer-header">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden" style="min-width: 0; flex: 1;">
                                            <div class="customer-avatar flex-shrink-0">
                                                <?php echo $initials; ?>
                                            </div>
                                            <div class="overflow-hidden">
                                                <h5 class="text-truncate"><?php echo htmlspecialchars($customer['customer_name']); ?></h5>
                                                <small class="text-truncate d-block">
                                                    <i class="bi bi-file-text me-1"></i><?php echo htmlspecialchars($customer['agreement_number']); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <span class="overdue-badge <?php echo $severity_class; ?> flex-shrink-0">
                                            <?php echo $days_overdue; ?>d
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="customer-body">
                                    <div class="customer-info-item">
                                        <i class="bi bi-phone"></i>
                                        <span class="text-truncate"><?php echo htmlspecialchars($customer['customer_number']); ?></span>
                                    </div>
                                    <div class="customer-info-item">
                                        <i class="bi bi-exclamation-circle"></i>
                                        <span class="text-truncate"><strong><?php echo $customer['overdue_count']; ?></strong> overdue EMIs</span>
                                    </div>
                                    <div class="customer-info-item">
                                        <i class="bi bi-cash"></i>
                                        <span class="text-truncate">₹<?php echo number_format($customer['total_overdue_amount'], 2); ?></span>
                                    </div>
                                    
                                    <div class="badge-group mt-2">
                                        <?php if (!empty($customer['finance_name']) && $user_role == 'admin'): ?>
                                        <span class="finance-badge text-truncate" title="<?php echo htmlspecialchars($customer['finance_name']); ?>">
                                            <i class="bi bi-building me-1"></i><?php echo $customer['finance_name']; ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($customer['loan_name'])): ?>
                                        <span class="loan-badge text-truncate" title="<?php echo htmlspecialchars($customer['loan_name']); ?>">
                                            <i class="bi bi-tag me-1"></i><?php echo $customer['loan_name']; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="customer-stats">
                                        <div class="customer-stat-item">
                                            <div class="customer-stat-value"><?php echo $customer['overdue_count']; ?></div>
                                            <div class="customer-stat-label">Overdue</div>
                                        </div>
                                        <div class="customer-stat-item">
                                            <div class="customer-stat-value"><?php echo $paid_emis; ?>/<?php echo $total_emis; ?></div>
                                            <div class="customer-stat-label">EMIs</div>
                                        </div>
                                        <div class="customer-stat-item">
                                            <div class="customer-stat-value">₹<?php echo number_format($customer['emi'], 0); ?></div>
                                            <div class="customer-stat-label">Per EMI</div>
                                        </div>
                                        <div class="customer-stat-item">
                                            <div class="customer-stat-value"><?php echo $days_overdue; ?>d</div>
                                            <div class="customer-stat-label">Delay</div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($customer['earliest_overdue']): ?>
                                    <div class="small text-muted text-truncate">
                                        <i class="bi bi-calendar-exclamation me-1 text-danger"></i>
                                        First: <?php echo date('d M Y', strtotime($customer['earliest_overdue'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="customer-footer">
                                    <div class="small text-muted text-truncate" style="max-width: 120px;">
                                        <i class="bi bi-cash-stack me-1"></i>
                                        ₹<?php echo number_format($customer['total_overdue_amount'], 0); ?>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="view-customer.php?id=<?php echo $customer['id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-warning" 
                                                title="View Overdue EMIs"
                                                onclick="showOverdueEMIs(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['customer_name'])); ?>')">
                                            <i class="bi bi-list-check"></i>
                                        </button>
                                        <a href="collect-emi.php?customer_id=<?php echo $customer['id']; ?>" 
                                           class="btn btn-sm btn-outline-success" title="Collect Payment">
                                            <i class="bi bi-cash"></i>
                                        </a>
                                        <a href="?collect_all=<?php echo $customer['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Collect All Overdue">
                                            <i class="bi bi-cash-stack"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state text-center py-5">
                            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted break-word">No overdue customers found</h5>
                            <?php if (!empty($search) || !empty($finance_filter) || $days_filter > 0): ?>
                                <p class="text-muted break-word">Try adjusting your filters</p>
                                <a href="overdue-customers.php" class="btn btn-outline-danger">Clear Filters</a>
                            <?php else: ?>
                                <p class="text-muted break-word">All customers are up to date with their payments!</p>
                                <a href="customers.php" class="btn btn-primary">View All Customers</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- List View -->
            <div id="listView" style="display: none;">
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table-custom" id="customersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Agreement</th>
                                    <?php if ($user_role == 'admin'): ?>
                                        <th>Finance</th>
                                    <?php endif; ?>
                                    <th>Overdue</th>
                                    <th>Amount</th>
                                    <th>First Overdue</th>
                                    <th>Days</th>
                                    <th>Per EMI</th>
                                    <th>Progress</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if ($customers && $customers->num_rows > 0) {
                                    $customers->data_seek(0);
                                    while ($customer = $customers->fetch_assoc()): 
                                        $total_emis = max($customer['total_emis'], 1);
                                        $paid_emis = $customer['paid_emis'] ?: 0;
                                        $progress = ($paid_emis / $total_emis) * 100;
                                        
                                        $days_overdue = $customer['earliest_overdue'] ? floor((time() - strtotime($customer['earliest_overdue'])) / (60 * 60 * 24)) : 0;
                                        
                                        // Get initials for avatar
                                        $initials = '';
                                        $nameParts = explode(' ', $customer['customer_name']);
                                        foreach ($nameParts as $part) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        $initials = substr($initials, 0, 2) ?: 'CU';
                                ?>
                                    <tr class="overdue-row">
                                        <td><span class="fw-semibold">#<?php echo $customer['id']; ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2" style="max-width: 200px;">
                                                <div class="customer-avatar small-avatar flex-shrink-0">
                                                    <?php echo $initials; ?>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <div class="fw-semibold text-truncate"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                                    <small class="text-muted text-truncate d-block"><?php echo htmlspecialchars($customer['customer_number']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="text-truncate d-block" style="max-width: 100px;"><?php echo htmlspecialchars($customer['agreement_number']); ?></span></td>
                                        <?php if ($user_role == 'admin'): ?>
                                            <td><span class="text-truncate d-block" style="max-width: 100px;" title="<?php echo htmlspecialchars($customer['finance_name'] ?? ''); ?>"><?php echo $customer['finance_name'] ?? '—'; ?></span></td>
                                        <?php endif; ?>
                                        <td><span class="badge bg-danger"><?php echo $customer['overdue_count']; ?></span></td>
                                        <td class="fw-bold text-danger">₹<?php echo number_format($customer['total_overdue_amount'], 0); ?></td>
                                        <td>
                                            <?php if ($customer['earliest_overdue']): ?>
                                                <span class="text-nowrap"><?php echo date('d M', strtotime($customer['earliest_overdue'])); ?></span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $days_overdue > 30 ? 'bg-danger' : ($days_overdue > 15 ? 'bg-warning' : 'bg-warning text-dark'); ?>">
                                                <?php echo $days_overdue; ?>
                                            </span>
                                        </td>
                                        <td>₹<?php echo number_format($customer['emi'], 0); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2" style="min-width: 80px;">
                                                <div class="progress flex-grow-1" style="width: 50px;">
                                                    <div class="progress-bar bg-danger" style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <small class="text-nowrap"><?php echo $paid_emis; ?>/<?php echo $total_emis; ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view-customer.php?id=<?php echo $customer['id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-warning" 
                                                        title="View Overdue EMIs"
                                                        onclick="showOverdueEMIs(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['customer_name'])); ?>')">
                                                    <i class="bi bi-list-check"></i>
                                                </button>
                                                <a href="collect-emi.php?customer_id=<?php echo $customer['id']; ?>" 
                                                   class="btn btn-sm btn-outline-success" title="Collect">
                                                    <i class="bi bi-cash"></i>
                                                </a>
                                                <a href="?collect_all=<?php echo $customer['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Collect All">
                                                    <i class="bi bi-cash-stack"></i>
                                                </a>
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
                    
                    <?php if ($customers && $customers->num_rows > 0): ?>
                        <div class="text-muted small mt-3">
                            Showing <?php echo $customers->num_rows; ?> overdue customers
                            <?php if (!empty($search) || !empty($finance_filter) || $days_filter > 0): ?>
                                (filtered)
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Overdue EMIs Modal -->
<div class="modal fade" id="overdueEMIModal" tabindex="-1" aria-labelledby="overdueEMIModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="overdueEMIModalLabel">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Overdue EMIs for <span id="modalCustomerName" class="text-truncate" style="max-width: 300px; display: inline-block;"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="overdueEMIsList" class="mb-3">
                    <!-- EMIs will be loaded here -->
                </div>
                <div class="alert alert-info" id="noOverdueEMIs" style="display: none;">
                    <i class="bi bi-info-circle me-2"></i>
                    No overdue EMIs found for this customer.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="collectAllBtn" class="btn btn-danger">
                    <i class="bi bi-cash-stack me-2"></i>Collect All Overdue
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
    // Overdue EMIs data (passed from PHP)
    const overdueEMIs = <?php echo json_encode($overdue_emis); ?>;
    
    // Initialize DataTable for list view
    $(document).ready(function() {
        if ($.fn.DataTable.isDataTable('#customersTable')) {
            $('#customersTable').DataTable().destroy();
        }
        
        const isMobile = window.innerWidth <= 768;
        
        $('#customersTable').DataTable({
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
            localStorage.setItem('overdueView', 'grid');
        } else {
            gridView.style.display = 'none';
            listView.style.display = 'block';
            gridBtn.classList.remove('active');
            listBtn.classList.add('active');
            localStorage.setItem('overdueView', 'list');
            
            setTimeout(function() {
                if ($.fn.DataTable.isDataTable('#customersTable')) {
                    $('#customersTable').DataTable().destroy();
                }
                
                const isMobile = window.innerWidth <= 768;
                
                $('#customersTable').DataTable({
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
    
    // Show overdue EMIs in modal
    function showOverdueEMIs(customerId, customerName) {
        const modal = new bootstrap.Modal(document.getElementById('overdueEMIModal'));
        document.getElementById('modalCustomerName').textContent = customerName;
        
        const emisList = document.getElementById('overdueEMIsList');
        const noEmis = document.getElementById('noOverdueEMIs');
        const collectAllBtn = document.getElementById('collectAllBtn');
        
        collectAllBtn.href = 'collect-emi.php?customer_id=' + customerId + '&all_overdue=1';
        
        if (overdueEMIs[customerId] && overdueEMIs[customerId].length > 0) {
            let html = '<div class="list-group">';
            overdueEMIs[customerId].forEach(emi => {
                const dueDate = new Date(emi.emi_due_date).toLocaleDateString('en-IN', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                });
                
                let severityClass = 'bg-warning';
                if (emi.days_overdue > 30) {
                    severityClass = 'bg-danger';
                } else if (emi.days_overdue > 15) {
                    severityClass = 'bg-warning';
                } else {
                    severityClass = 'bg-warning text-dark';
                }
                
                html += `
                    <div class="emi-list-item">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <span class="date">${dueDate}</span>
                                <span class="badge ${severityClass}">${emi.days_overdue} days</span>
                            </div>
                            <div class="amount text-danger">₹${parseFloat(emi.emi_amount).toFixed(2)}</div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                            <small class="text-muted">Principal: ₹${parseFloat(emi.principal_amount).toFixed(2)} | Interest: ₹${parseFloat(emi.interest_amount).toFixed(2)}</small>
                            <a href="collect-emi.php?emi_id=${emi.id}" class="btn btn-sm btn-success">
                                <i class="bi bi-cash"></i> Collect
                            </a>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            emisList.innerHTML = html;
            noEmis.style.display = 'none';
        } else {
            emisList.innerHTML = '';
            noEmis.style.display = 'block';
        }
        
        modal.show();
    }
    
    // Load saved view preference
    window.onload = function() {
        const savedView = localStorage.getItem('overdueView');
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
                if ($.fn.DataTable.isDataTable('#customersTable')) {
                    $('#customersTable').DataTable().destroy();
                }
                
                const isMobile = window.innerWidth <= 768;
                
                $('#customersTable').DataTable({
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