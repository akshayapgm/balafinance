<?php
require_once 'includes/auth.php';
$currentPage = 'manage-customers';
$pageTitle = 'Manage Customers';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

$message = '';
$error = '';

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if ($user_role == 'admin') {
        $delete_id = intval($_GET['delete']);
        
        $conn->begin_transaction();
        
        try {
            // Check if customer has paid EMIs
            $check_paid_query = "SELECT COUNT(*) as cnt FROM emi_schedule WHERE customer_id = ? AND status = 'paid'";
            $stmt = $conn->prepare($check_paid_query);
            $stmt->bind_param('i', $delete_id);
            $stmt->execute();
            $check_result = $stmt->get_result();
            $paid_count = $check_result->fetch_assoc()['cnt'];
            $stmt->close();
            
            if ($paid_count > 0) {
                throw new Exception("Cannot delete customer with paid EMIs.");
            }
            
            // Get customer name for logging
            $name_query = "SELECT customer_name FROM customers WHERE id = ?";
            $stmt = $conn->prepare($name_query);
            $stmt->bind_param('i', $delete_id);
            $stmt->execute();
            $name_result = $stmt->get_result();
            $customer_name = $name_result->fetch_assoc()['customer_name'];
            $stmt->close();
            
            // Delete related records
            $conn->query("DELETE FROM payment_logs WHERE customer_id = $delete_id");
            $conn->query("DELETE FROM emi_undo_logs WHERE customer_id = $delete_id");
            $conn->query("DELETE FROM foreclosures WHERE customer_id = $delete_id");
            $conn->query("DELETE FROM foreclosure_payments WHERE customer_id = $delete_id");
            $conn->query("DELETE FROM emi_schedule WHERE customer_id = $delete_id");
            
            // Delete customer
            $delete_query = "DELETE FROM customers WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param('i', $delete_id);
            
            if ($stmt->execute()) {
                $conn->commit();
                
                // Log activity
                if (function_exists('logActivity')) {
                    logActivity($conn, 'delete_customer', "Deleted customer: $customer_name (ID: $delete_id)");
                }
                
                $_SESSION['success'] = "Customer deleted successfully";
                header('Location: manage-customers.php');
                exit();
            } else {
                throw new Exception("Error deleting customer");
            }
            $stmt->close();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } else {
        $error = "You don't have permission to delete customers";
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$collection_type_filter = isset($_GET['collection_type']) ? $_GET['collection_type'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Build query
$query = "
    SELECT 
        c.*,
        l.loan_name,
        f.finance_name,
        (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND status = 'unpaid') as pending_emis,
        (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as paid_emis,
        (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') as overdue_emis,
        (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE customer_id = c.id AND status = 'unpaid') as total_pending,
        (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as total_paid,
        (SELECT MAX(paid_date) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as last_payment_date,
        CASE 
            WHEN c.collection_type = 'monthly' AND c.monthly_date IS NOT NULL 
                THEN CONCAT('Monthly (Day ', c.monthly_date, ')')
            WHEN c.collection_type = 'weekly' AND c.weekly_days IS NOT NULL AND c.weekly_days != '' 
                THEN CONCAT('Weekly (', REPLACE(c.weekly_days, ',', ', '), ')')
            WHEN c.collection_type = 'daily' THEN 'Daily'
            ELSE c.collection_type
        END as collection_display
    FROM customers c
    JOIN loans l ON c.loan_id = l.id
    JOIN finance f ON c.finance_id = f.id
    WHERE 1=1
";

$params = [];
$types = "";

if ($user_role != 'admin') {
    $query .= " AND c.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if (!empty($search)) {
    $query .= " AND (c.customer_name LIKE ? OR c.customer_number LIKE ? OR c.agreement_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $query .= " AND c.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

if ($collection_type_filter != 'all') {
    $query .= " AND c.collection_type = ?";
    $params[] = $collection_type_filter;
    $types .= "s";
}

if ($status_filter == 'active') {
    $query .= " AND c.id IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status IN ('unpaid', 'overdue'))";
} elseif ($status_filter == 'completed') {
    $query .= " AND c.id NOT IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status IN ('unpaid', 'overdue'))";
} elseif ($status_filter == 'overdue') {
    $query .= " AND c.id IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status = 'overdue')";
}

$query .= " ORDER BY c.id DESC";

$customers = null;
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $customers = $stmt->get_result();
    $stmt->close();
}

// Get finance companies for filter
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_customers,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM emi_schedule WHERE customer_id = c.id AND status = 'overdue') THEN 1 ELSE 0 END) as overdue_customers,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM emi_schedule WHERE customer_id = c.id AND status IN ('unpaid', 'overdue')) THEN 1 ELSE 0 END) as active_customers,
        COALESCE((SELECT SUM(emi_amount) FROM emi_schedule WHERE status = 'paid'), 0) as total_collected,
        SUM(CASE WHEN c.collection_type = 'monthly' THEN 1 ELSE 0 END) as monthly_count,
        SUM(CASE WHEN c.collection_type = 'weekly' THEN 1 ELSE 0 END) as weekly_count,
        SUM(CASE WHEN c.collection_type = 'daily' THEN 1 ELSE 0 END) as daily_count
    FROM customers c
";

if ($user_role != 'admin') {
    $stats_query .= " WHERE c.finance_id = $finance_id";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats = array_merge([
    'total_customers' => 0,
    'overdue_customers' => 0,
    'active_customers' => 0,
    'total_collected' => 0,
    'monthly_count' => 0,
    'weekly_count' => 0,
    'daily_count' => 0
], $stats);

// Get monthly stats
$current_month = date('Y-m');
$collection_stats_query = "
    SELECT 
        es.collection_type,
        COUNT(*) as total_emis,
        SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
        COALESCE(SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END), 0) as paid_amount
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    WHERE DATE_FORMAT(es.emi_due_date, '%Y-%m') = ?
";

if ($user_role != 'admin') {
    $collection_stats_query .= " AND es.finance_id = ?";
}

$collection_stats_query .= " GROUP BY es.collection_type";

$collection_stats = [
    'monthly' => ['total' => 0, 'paid' => 0, 'paid_amount' => 0],
    'weekly' => ['total' => 0, 'paid' => 0, 'paid_amount' => 0],
    'daily' => ['total' => 0, 'paid' => 0, 'paid_amount' => 0]
];

$stmt = $conn->prepare($collection_stats_query);
if ($stmt) {
    if ($user_role != 'admin') {
        $stmt->bind_param('si', $current_month, $finance_id);
    } else {
        $stmt->bind_param('s', $current_month);
    }
    $stmt->execute();
    $collection_result = $stmt->get_result();
    while ($row = $collection_result->fetch_assoc()) {
        $type = $row['collection_type'];
        if (isset($collection_stats[$type])) {
            $collection_stats[$type] = [
                'total' => $row['total_emis'],
                'paid' => $row['paid_emis'],
                'paid_amount' => $row['paid_amount']
            ];
        }
    }
    $stmt->close();
}

// Get unique monthly dates
$monthly_dates_query = "
    SELECT DISTINCT c.monthly_date 
    FROM customers c 
    WHERE c.collection_type = 'monthly' AND c.monthly_date IS NOT NULL
";
if ($user_role != 'admin') {
    $monthly_dates_query .= " AND c.finance_id = $finance_id";
}
$monthly_dates_result = $conn->query($monthly_dates_query);
$unique_monthly_dates = [];
while ($row = $monthly_dates_result->fetch_assoc()) {
    $unique_monthly_dates[] = $row['monthly_date'];
}
sort($unique_monthly_dates);

// Get unique weekly days
$weekly_days_query = "
    SELECT c.weekly_days 
    FROM customers c 
    WHERE c.collection_type = 'weekly' AND c.weekly_days IS NOT NULL AND c.weekly_days != ''
";
if ($user_role != 'admin') {
    $weekly_days_query .= " AND c.finance_id = $finance_id";
}
$weekly_days_result = $conn->query($weekly_days_query);
$unique_weekly_days = [];
while ($row = $weekly_days_result->fetch_assoc()) {
    $days = explode(',', $row['weekly_days']);
    foreach ($days as $day) {
        $unique_weekly_days[trim($day)] = true;
    }
}
$unique_weekly_days = array_keys($unique_weekly_days);
sort($unique_weekly_days);
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
            --shadow: 0 2px 4px rgba(0,0,0,0.05);
            --radius: 10px;
            --monthly-color: #3b82f6;
            --weekly-color: #8b5cf6;
            --daily-color: #f59e0b;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            overflow-x: hidden;
            width: 100%;
        }
        
        .app-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
        }
        
        .page-content {
            padding: 1.5rem 2rem;
            width: 100%;
            max-width: 100%;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.25rem 2rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            width: 100%;
        }
        
        .page-header h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 1.5rem;
        }
        
        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.95rem;
        }
        
        .page-header .btn-light {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.5rem 1.5rem;
            font-weight: 500;
            border-radius: 30px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        
        /* Stat Cards */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            font-weight: 500;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .stat-icon.blue {
            background: #e6f0ff;
            color: var(--primary);
        }
        
        .stat-icon.green {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .stat-icon.orange {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .stat-icon.purple {
            background: #f3e5f5;
            color: #8b5cf6;
        }
        
        /* Collection Type Stats */
        .collection-stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            height: 100%;
        }
        
        .collection-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .collection-value {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .collection-detail {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        .progress {
            height: 4px;
            border-radius: 2px;
            background: #e9ecef;
            margin-top: 0.5rem;
        }
        
        .progress-bar.monthly {
            background: var(--monthly-color);
        }
        
        .progress-bar.weekly {
            background: var(--weekly-color);
        }
        
        .progress-bar.daily {
            background: var(--daily-color);
        }
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            width: 100%;
        }
        
        .filter-card .form-control,
        .filter-card .form-select {
            font-size: 0.9rem;
            height: 40px;
        }
        
        .filter-chip {
            padding: 0.35rem 1rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid var(--border-color);
            background: white;
            color: var(--text-primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .filter-chip.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .filter-chip.monthly.active {
            background: var(--monthly-color);
            border-color: var(--monthly-color);
        }
        
        .filter-chip.weekly.active {
            background: var(--weekly-color);
            border-color: var(--weekly-color);
        }
        
        .filter-chip.daily.active {
            background: var(--daily-color);
            border-color: var(--daily-color);
        }
        
        /* Table Container - Optimized */
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0;
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
        }
        
        /* Column Widths - 8 columns now */
        .table th:nth-child(1) { width: 20%; }  /* Customer */
        .table th:nth-child(2) { width: 10%; }  /* Agreement */
        .table th:nth-child(3) { width: 15%; }  /* Loan */
        .table th:nth-child(4) { width: 8%; }   /* Collection */
        .table th:nth-child(5) { width: 12%; }  /* Finance */
        .table th:nth-child(6) { width: 10%; }  /* Progress */
        .table th:nth-child(7) { width: 10%; }  /* Status */
        .table th:nth-child(8) { width: 15%; }  /* Actions */
        
        .table thead th {
            background: #f8fafc;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            white-space: nowrap;
            text-align: left;
        }
        
        .table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.85rem;
            vertical-align: middle;
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        /* Customer Info */
        .customer-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
        }
        
        .customer-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .customer-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .customer-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
        }
        
        /* Badges */
        .badge-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
        }
        
        .badge-status.active {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .badge-status.overdue {
            background: #fee2e2;
            color: var(--danger);
        }
        
        .badge-status.completed {
            background: #e6f0ff;
            color: var(--primary);
        }
        
        .badge-collection {
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            white-space: nowrap;
        }
        
        .badge-collection.monthly {
            background: #e3f2fd;
            color: var(--monthly-color);
        }
        
        .badge-collection.weekly {
            background: #f3e5f5;
            color: var(--weekly-color);
        }
        
        .badge-collection.daily {
            background: #fff3e0;
            color: var(--daily-color);
        }
        
        .badge-finance {
            background: #e6f0ff;
            color: var(--primary);
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-action {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            color: white;
        }
        
        .btn-view {
            background: var(--info);
        }
        
        .btn-edit {
            background: var(--warning);
        }
        
        .btn-emi {
            background: var(--primary);
        }
        
        .btn-delete {
            background: var(--danger);
        }
        
        /* Progress Bar */
        .progress-sm {
            height: 4px;
            width: 70px;
            border-radius: 2px;
            background: #e9ecef;
            display: inline-block;
            margin-left: 0.5rem;
        }
        
        .amount-text {
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        
        /* Empty State */
        .empty-state {
            padding: 3rem;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .page-content {
                padding: 1rem;
            }
            
            .table {
                min-width: 1000px;
            }
        }
        
        @media (max-width: 768px) {
            .page-header {
                padding: 1rem;
            }
            
            .page-header h4 {
                font-size: 1.2rem;
            }
            
            .stat-value {
                font-size: 1.4rem;
            }
            
            .collection-value {
                font-size: 1.3rem;
            }
            
            .table-container {
                border-radius: 0;
                margin: 0 -1rem;
                width: calc(100% + 2rem);
            }
            
            .table {
                min-width: 900px;
            }
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h4><i class="bi bi-people me-2"></i>Manage Customers</h4>
                        <p>View and manage all customers</p>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($user_role == 'admin' || (function_exists('hasPermission') && hasPermission('add_customer'))): ?>
                        <a href="add-customer.php" class="btn btn-light">
                            <i class="bi bi-person-plus me-2"></i>Add Customer
                        </a>
                        <?php endif; ?>
                        <span class="badge bg-white text-primary py-2 px-3">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error']) || $error): ?>
                <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['error'] ?? $error;
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Row -->
            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Total Customers</div>
                                <div class="stat-value"><?php echo number_format($stats['total_customers']); ?></div>
                            </div>
                            <div class="stat-icon blue"><i class="bi bi-people"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Active</div>
                                <div class="stat-value"><?php echo number_format($stats['active_customers']); ?></div>
                            </div>
                            <div class="stat-icon green"><i class="bi bi-person-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Overdue</div>
                                <div class="stat-value text-danger"><?php echo number_format($stats['overdue_customers']); ?></div>
                            </div>
                            <div class="stat-icon orange"><i class="bi bi-exclamation-triangle"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Collections</div>
                                <div class="stat-value">₹<?php echo number_format($stats['total_collected']/100000,1); ?>L</div>
                            </div>
                            <div class="stat-icon purple"><i class="bi bi-cash-stack"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collection Type Stats -->
            <div class="row g-2 mb-3">
                <div class="col-sm-6 col-lg-4">
                    <div class="collection-stat-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="collection-label">
                                    <i class="bi bi-calendar-month me-1" style="color: var(--monthly-color);"></i>
                                    Monthly
                                </div>
                                <div class="collection-value" style="color: var(--monthly-color);"><?php echo $stats['monthly_count']; ?></div>
                                <div class="collection-detail">
                                    <?php if (!empty($unique_monthly_dates)): ?>
                                        Day <?php echo implode(', ', $unique_monthly_dates); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">This Month</div>
                                <div class="fw-bold" style="color: var(--monthly-color);">₹<?php echo number_format($collection_stats['monthly']['paid_amount']/1000,1); ?>K</div>
                            </div>
                        </div>
                        <div class="progress mt-2">
                            <?php 
                            $monthly_progress = $collection_stats['monthly']['total'] > 0 
                                ? round(($collection_stats['monthly']['paid'] / $collection_stats['monthly']['total']) * 100) 
                                : 0;
                            ?>
                            <div class="progress-bar monthly" style="width: <?php echo $monthly_progress; ?>%;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="collection-stat-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="collection-label">
                                    <i class="bi bi-calendar-week me-1" style="color: var(--weekly-color);"></i>
                                    Weekly
                                </div>
                                <div class="collection-value" style="color: var(--weekly-color);"><?php echo $stats['weekly_count']; ?></div>
                                <div class="collection-detail">
                                    <?php if (!empty($unique_weekly_days)): ?>
                                        <?php echo implode(', ', array_slice($unique_weekly_days, 0, 2)); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">This Month</div>
                                <div class="fw-bold" style="color: var(--weekly-color);">₹<?php echo number_format($collection_stats['weekly']['paid_amount']/1000,1); ?>K</div>
                            </div>
                        </div>
                        <div class="progress mt-2">
                            <?php 
                            $weekly_progress = $collection_stats['weekly']['total'] > 0 
                                ? round(($collection_stats['weekly']['paid'] / $collection_stats['weekly']['total']) * 100) 
                                : 0;
                            ?>
                            <div class="progress-bar weekly" style="width: <?php echo $weekly_progress; ?>%;"></div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="collection-stat-card">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="collection-label">
                                    <i class="bi bi-calendar-day me-1" style="color: var(--daily-color);"></i>
                                    Daily
                                </div>
                                <div class="collection-value" style="color: var(--daily-color);"><?php echo $stats['daily_count']; ?></div>
                                <div class="collection-detail">Every day</div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">This Month</div>
                                <div class="fw-bold" style="color: var(--daily-color);">₹<?php echo number_format($collection_stats['daily']['paid_amount']/1000,1); ?>K</div>
                            </div>
                        </div>
                        <div class="progress mt-2">
                            <?php 
                            $daily_progress = $collection_stats['daily']['total'] > 0 
                                ? round(($collection_stats['daily']['paid'] / $collection_stats['daily']['total']) * 100) 
                                : 0;
                            ?>
                            <div class="progress-bar daily" style="width: <?php echo $daily_progress; ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search name, mobile, agreement..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <?php if ($user_role == 'admin'): ?>
                        <div class="col-md-2">
                            <select class="form-select" name="finance_id">
                                <option value="0">All Finance</option>
                                <?php 
                                if ($finance_companies) {
                                    $finance_companies->data_seek(0);
                                    while ($fc = $finance_companies->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $fc['id']; ?>" <?php echo $finance_filter == $fc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($fc['finance_name']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-1"></i>Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="manage-customers.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat me-1"></i>Reset
                            </a>
                        </div>
                    </div>
                    
                    <!-- Collection Type Filter -->
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <a href="?<?php 
                            $params = $_GET;
                            unset($params['collection_type']);
                            echo http_build_query($params);
                        ?>" class="filter-chip <?php echo $collection_type_filter == 'all' ? 'active' : ''; ?>">
                            <i class="bi bi-grid-3x3-gap-fill"></i> All
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'monthly';
                            echo http_build_query($params);
                        ?>" class="filter-chip monthly <?php echo $collection_type_filter == 'monthly' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-month"></i> Monthly
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'weekly';
                            echo http_build_query($params);
                        ?>" class="filter-chip weekly <?php echo $collection_type_filter == 'weekly' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-week"></i> Weekly
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'daily';
                            echo http_build_query($params);
                        ?>" class="filter-chip daily <?php echo $collection_type_filter == 'daily' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-day"></i> Daily
                        </a>
                    </div>
                </form>
            </div>

            <!-- Customers Table - 8 Columns -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Agreement</th>
                                <th>Loan</th>
                                <th>Collection</th>
                                <th>Finance</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers && $customers->num_rows > 0): ?>
                                <?php while ($customer = $customers->fetch_assoc()): 
                                    $total_emis = ($customer['paid_emis'] ?? 0) + ($customer['pending_emis'] ?? 0) + ($customer['overdue_emis'] ?? 0);
                                    $progress_percent = $total_emis > 0 ? round((($customer['paid_emis'] ?? 0) / $total_emis) * 100) : 0;
                                    
                                    // Determine status
                                    if (($customer['overdue_emis'] ?? 0) > 0) {
                                        $status_class = 'overdue';
                                        $status_text = 'Overdue';
                                    } elseif (($customer['pending_emis'] ?? 0) > 0) {
                                        $status_class = 'active';
                                        $status_text = 'Active';
                                    } else {
                                        $status_class = 'completed';
                                        $status_text = 'Completed';
                                    }
                                    
                                    // Collection type display
                                    $collection_type_display = $customer['collection_type'] ?? 'monthly';
                                    $collection_class = '';
                                    $collection_icon = '';
                                    
                                    if ($collection_type_display == 'monthly') {
                                        $collection_class = 'monthly';
                                        $collection_icon = 'bi-calendar-month';
                                    } elseif ($collection_type_display == 'weekly') {
                                        $collection_class = 'weekly';
                                        $collection_icon = 'bi-calendar-week';
                                    } else {
                                        $collection_class = 'daily';
                                        $collection_icon = 'bi-calendar-day';
                                    }
                                    
                                    // Calculate outstanding amount
                                    $outstanding = ($customer['loan_amount'] ?? 0) - ($customer['total_paid'] ?? 0);
                                    $per_installment = $customer['emi'] ?? 0;
                                    $has_paid_emis = ($customer['paid_emis'] ?? 0) > 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $customer['id']; ?>'">
                                            <div class="customer-avatar">
                                                <?php 
                                                $name_parts = explode(' ', $customer['customer_name'] ?? '');
                                                $initials = '';
                                                foreach ($name_parts as $part) {
                                                    $initials .= strtoupper(substr($part, 0, 1));
                                                }
                                                echo substr($initials, 0, 2) ?: 'U';
                                                ?>
                                            </div>
                                            <div>
                                                <div class="customer-name"><?php echo htmlspecialchars($customer['customer_name'] ?? ''); ?></div>
                                                <div class="customer-meta">
                                                    <i class="bi bi-telephone-fill"></i> <?php echo htmlspecialchars($customer['customer_number'] ?? ''); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($customer['agreement_number'] ?? ''); ?></div>
                                        <div class="customer-meta">
                                            <?php echo isset($customer['agreement_date']) ? date('d/m/y', strtotime($customer['agreement_date'])) : ''; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($customer['loan_name'] ?? ''); ?></div>
                                        <div class="customer-meta">₹<?php echo number_format($customer['loan_amount'] ?? 0, 0); ?></div>
                                        <div class="customer-meta" style="color: var(--<?php echo $collection_class; ?>-color);">
                                            ₹<?php echo number_format($per_installment, 0); ?>/<?php echo substr($collection_type_display, 0, 3); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-collection <?php echo $collection_class; ?>">
                                            <i class="bi <?php echo $collection_icon; ?>"></i>
                                            <?php echo ucfirst(substr($collection_type_display, 0, 3)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-finance">
                                            <?php echo htmlspecialchars(substr($customer['finance_name'] ?? '', 0, 10)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span><?php echo $progress_percent; ?>%</span>
                                            <div class="progress-sm">
                                                <div class="progress-bar <?php echo $collection_class; ?>" style="width: <?php echo $progress_percent; ?>%;"></div>
                                            </div>
                                        </div>
                                        <div class="customer-meta"><?php echo $customer['paid_emis'] ?? 0; ?>/<?php echo $total_emis; ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view-customer.php?id=<?php echo $customer['id']; ?>" 
                                               class="btn-action btn-view" 
                                               data-bs-toggle="tooltip" 
                                               title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            
                                            <?php if ($user_role == 'admin' || (function_exists('hasPermission') && hasPermission('edit_customer'))): ?>
                                            <a href="edit-customer.php?id=<?php echo $customer['id']; ?>" 
                                               class="btn-action btn-edit" 
                                               data-bs-toggle="tooltip" 
                                               title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <a href="emi-schedule.php?customer_id=<?php echo $customer['id']; ?>" 
                                               class="btn-action btn-emi" 
                                               data-bs-toggle="tooltip" 
                                               title="EMI">
                                                <i class="bi bi-calendar-check"></i>
                                            </a>
                                            
                                            <?php if ($user_role == 'admin' && !$has_paid_emis): ?>
                                            <button type="button" 
                                                    class="btn-action btn-delete" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal"
                                                    data-customer-id="<?php echo $customer['id']; ?>"
                                                    data-customer-name="<?php echo htmlspecialchars($customer['customer_name'] ?? ''); ?>"
                                                    title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="bi bi-people"></i>
                                            <h6 class="mt-2">No customers found</h6>
                                            <?php if (!empty($search) || $collection_type_filter != 'all' || $status_filter != 'all' || $finance_filter > 0): ?>
                                                <a href="manage-customers.php" class="btn btn-outline-primary btn-sm mt-2">
                                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Confirm Delete</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-0">Delete <strong id="deleteCustomerName"></strong>?</p>
                <p class="text-danger small mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>Cannot be undone</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-sm btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Delete modal handler
        $('#deleteModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget);
            var customerId = button.data('customer-id');
            var customerName = button.data('customer-name');
            
            var modal = $(this);
            modal.find('#deleteCustomerName').text(customerName);
            modal.find('#deleteConfirmBtn').attr('href', 'manage-customers.php?delete=' + customerId);
        });
    });

    // Auto-hide alerts
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        });
    }, 4000);
</script>
</body>
</html>