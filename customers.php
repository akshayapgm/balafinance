<?php
require_once 'includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$currentPage = 'customers';
$pageTitle = 'Manage Customers';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'] ?? 'user';
$finance_id = $_SESSION['finance_id'] ?? 1;

$message = '';
$error = '';

// Handle delete action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if ($user_role == 'admin') {
        $delete_id = intval($_GET['delete']);
        
        $conn->begin_transaction();
        
        try {
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
            $conn->query("DELETE FROM emi_schedule WHERE customer_id = $delete_id");
            
            $delete_query = "DELETE FROM customers WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param('i', $delete_id);
            
            if ($stmt->execute()) {
                $conn->commit();
                $_SESSION['success'] = "Customer deleted successfully";
                header('Location: customers.php');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Finance Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            --monthly-color: #2196f3;
            --weekly-color: #9c27b0;
            --daily-color: #ff9800;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
        }

        .page-content {
            padding: 2rem;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .page-header h4 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        .page-header .btn-light {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            border-radius: 30px;
            transition: all 0.2s ease;
        }

        .page-header .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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
            color: #9c27b0;
        }

        .stat-icon.monthly {
            background: #e3f2fd;
            color: var(--monthly-color);
        }

        .stat-icon.weekly {
            background: #f3e5f5;
            color: var(--weekly-color);
        }

        .stat-icon.daily {
            background: #fff3e0;
            color: var(--daily-color);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .stat-small {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .stat-detail {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .collection-type-filter {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-chip {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            background: white;
            color: var(--text-primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .filter-chip:hover {
            background: var(--light);
            border-color: var(--primary);
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

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            overflow-x: auto;
        }

        .table thead th {
            background: #f8f9fa;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        .table tbody td {
            vertical-align: middle;
            font-size: 0.9rem;
        }

        .customer-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .customer-info:hover .customer-name {
            color: var(--primary);
            text-decoration: underline;
        }

        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1rem;
        }

        .customer-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .customer-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .badge-status {
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-status.active {
            background: #e3f7e3;
            color: var(--success);
        }

        .badge-status.overdue {
            background: #ffe6e6;
            color: var(--danger);
        }

        .badge-status.completed {
            background: #e6f0ff;
            color: var(--primary);
        }

        .badge-collection {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
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

        .collection-detail {
            font-size: 0.7rem;
            margin-top: 0.25rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
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

        .progress {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
        }

        .progress-bar {
            border-radius: 3px;
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

        .active-filter-badge {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
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
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="bi bi-people me-2"></i>Manage Customers</h4>
                        <p>View and manage all customers</p>
                    </div>
                    <div>
                        <?php if ($user_role == 'admin'): ?>
                        <a href="add-customer.php" class="btn btn-light">
                            <i class="bi bi-person-plus me-2"></i>Add New Customer
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Total Customers</div>
                                <div class="stat-value"><?php echo $stats['total_customers']; ?></div>
                            </div>
                            <div class="stat-icon blue">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Active Customers</div>
                                <div class="stat-value"><?php echo $stats['active_customers']; ?></div>
                            </div>
                            <div class="stat-icon green">
                                <i class="bi bi-person-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Overdue Customers</div>
                                <div class="stat-value text-danger"><?php echo $stats['overdue_customers']; ?></div>
                            </div>
                            <div class="stat-icon orange">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">Total Collections</div>
                                <div class="stat-value">₹<?php echo number_format($stats['total_collected'], 2); ?></div>
                            </div>
                            <div class="stat-icon purple">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collection Type Stats -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">
                                    <i class="bi bi-calendar-month me-1" style="color: var(--monthly-color);"></i>
                                    Monthly Collections
                                </div>
                                <div class="stat-value" style="color: var(--monthly-color);"><?php echo $stats['monthly_count']; ?></div>
                                <div class="stat-detail">
                                    <i class="bi bi-calendar-day"></i>
                                    <?php if (!empty($unique_monthly_dates)): ?>
                                        Day <?php echo implode(', Day ', $unique_monthly_dates); ?>
                                    <?php else: ?>
                                        Various dates
                                    <?php endif; ?>
                                </div>
                                <div class="stat-small">
                                    This Month: <?php echo $collection_stats['monthly']['total']; ?> EMIs 
                                    (₹<?php echo number_format($collection_stats['monthly']['paid_amount'], 2); ?> paid)
                                </div>
                            </div>
                            <div class="stat-icon monthly">
                                <i class="bi bi-calendar-month"></i>
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
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">
                                    <i class="bi bi-calendar-week me-1" style="color: var(--weekly-color);"></i>
                                    Weekly Collections
                                </div>
                                <div class="stat-value" style="color: var(--weekly-color);"><?php echo $stats['weekly_count']; ?></div>
                                <div class="stat-detail">
                                    <i class="bi bi-calendar-week"></i>
                                    <?php if (!empty($unique_weekly_days)): ?>
                                        <?php echo implode(', ', array_keys($unique_weekly_days)); ?>
                                    <?php else: ?>
                                        Various days
                                    <?php endif; ?>
                                </div>
                                <div class="stat-small">
                                    This Month: <?php echo $collection_stats['weekly']['total']; ?> EMIs 
                                    (₹<?php echo number_format($collection_stats['weekly']['paid_amount'], 2); ?> paid)
                                </div>
                            </div>
                            <div class="stat-icon weekly">
                                <i class="bi bi-calendar-week"></i>
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
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <div class="stat-label">
                                    <i class="bi bi-calendar-day me-1" style="color: var(--daily-color);"></i>
                                    Daily Collections
                                </div>
                                <div class="stat-value" style="color: var(--daily-color);"><?php echo $stats['daily_count']; ?></div>
                                <div class="stat-detail">
                                    <i class="bi bi-calendar-day"></i>
                                    Every day
                                </div>
                                <div class="stat-small">
                                    This Month: <?php echo $collection_stats['daily']['total']; ?> EMIs 
                                    (₹<?php echo number_format($collection_stats['daily']['paid_amount'], 2); ?> paid)
                                </div>
                            </div>
                            <div class="stat-icon daily">
                                <i class="bi bi-calendar-day"></i>
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
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0">
                                    <i class="bi bi-search text-muted"></i>
                                </span>
                                <input type="text" class="form-control border-start-0" name="search" 
                                       placeholder="Search by name, mobile, agreement..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Customers</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        
                        <?php if ($user_role == 'admin'): ?>
                        <div class="col-md-2">
                            <label class="form-label">Finance Company</label>
                            <select class="form-select" name="finance_id">
                                <option value="0">All Companies</option>
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
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-2"></i>Apply Filters
                            </button>
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <a href="customers.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                    
                    <!-- Collection Type Filter -->
                    <div class="row mt-3">
                        <div class="col-12">
                            <label class="filter-label">Collection Type</label>
                            <div class="collection-type-filter">
                                <a href="?<?php 
                                    $params = $_GET;
                                    unset($params['collection_type']);
                                    echo http_build_query($params);
                                ?>" class="filter-chip <?php echo $collection_type_filter == 'all' ? 'active' : ''; ?>">
                                    All Types
                                </a>
                                <a href="?<?php 
                                    $params = $_GET;
                                    $params['collection_type'] = 'monthly';
                                    echo http_build_query($params);
                                ?>" class="filter-chip monthly <?php echo $collection_type_filter == 'monthly' ? 'active' : ''; ?>">
                                    <i class="bi bi-calendar-month me-1"></i>Monthly
                                </a>
                                <a href="?<?php 
                                    $params = $_GET;
                                    $params['collection_type'] = 'weekly';
                                    echo http_build_query($params);
                                ?>" class="filter-chip weekly <?php echo $collection_type_filter == 'weekly' ? 'active' : ''; ?>">
                                    <i class="bi bi-calendar-week me-1"></i>Weekly
                                </a>
                                <a href="?<?php 
                                    $params = $_GET;
                                    $params['collection_type'] = 'daily';
                                    echo http_build_query($params);
                                ?>" class="filter-chip daily <?php echo $collection_type_filter == 'daily' ? 'active' : ''; ?>">
                                    <i class="bi bi-calendar-day me-1"></i>Daily
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
                
                <?php if ($collection_type_filter != 'all'): ?>
                <div class="mt-3">
                    <span class="active-filter-badge">
                        <i class="bi bi-<?php 
                            echo $collection_type_filter == 'monthly' ? 'calendar-month' : 
                                ($collection_type_filter == 'weekly' ? 'calendar-week' : 'calendar-day'); 
                        ?> me-1"></i>
                        Showing: <?php echo ucfirst($collection_type_filter); ?> Customers
                        <a href="?<?php 
                            $params = $_GET;
                            unset($params['collection_type']);
                            echo http_build_query($params);
                        ?>" class="btn-close btn-close-white" style="font-size: 0.5rem;"></a>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Customers Table -->
            <div class="table-container">
                <table id="customersTable" class="table table-hover">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Agreement #</th>
                            <th>Loan Details</th>
                            <th>Collection Type</th>
                            <th>Finance</th>
                            <th>Progress</th>
                            <th>Pending</th>
                            <th>Last Payment</th>
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
                                $collection_detail = '';
                                $unit_text = 'EMIs';
                                $per_text = 'EMI';
                                $progress_bar_class = '';
                                
                                if ($collection_type_display == 'monthly') {
                                    $collection_class = 'monthly';
                                    $collection_icon = 'bi-calendar-month';
                                    $unit_text = 'EMIs';
                                    $per_text = 'EMI';
                                    $progress_bar_class = 'monthly';
                                    if (!empty($customer['monthly_date'])) {
                                        $collection_detail = 'Day ' . $customer['monthly_date'];
                                    }
                                } elseif ($collection_type_display == 'weekly') {
                                    $collection_class = 'weekly';
                                    $collection_icon = 'bi-calendar-week';
                                    $unit_text = 'weeks';
                                    $per_text = 'week';
                                    $progress_bar_class = 'weekly';
                                    if (!empty($customer['weekly_days'])) {
                                        $days = explode(',', $customer['weekly_days']);
                                        $day_abbr = array_map(function($day) {
                                            return substr($day, 0, 3);
                                        }, $days);
                                        $collection_detail = implode(', ', $day_abbr);
                                    }
                                } else {
                                    $collection_class = 'daily';
                                    $collection_icon = 'bi-calendar-day';
                                    $unit_text = 'days';
                                    $per_text = 'day';
                                    $progress_bar_class = 'daily';
                                    $collection_detail = 'Every day';
                                }
                                
                                // Calculate outstanding amount
                                $outstanding = ($customer['principal_amount'] + $customer['interest_amount']) - ($customer['total_paid'] ?? 0);
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
                                        <div class="customer-details">
                                            <div class="customer-name"><?php echo htmlspecialchars($customer['customer_name'] ?? ''); ?></div>
                                            <div class="customer-meta">
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($customer['customer_number'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($customer['agreement_number'] ?? ''); ?></span>
                                    <div class="customer-meta">
                                        <i class="bi bi-calendar"></i> <?php echo isset($customer['agreement_date']) ? date('d M Y', strtotime($customer['agreement_date'])) : ''; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($customer['loan_name'] ?? ''); ?></span>
                                    <div class="customer-meta">
                                        ₹<?php echo number_format($customer['loan_amount'] ?? 0, 2); ?> | 
                                        <?php echo $customer['loan_tenure'] ?? 0; ?> months | 
                                        <?php echo $customer['interest_rate'] ?? 0; ?>%
                                    </div>
                                    <div class="customer-meta" style="color: var(--<?php echo $collection_class; ?>-color);">
                                        <i class="bi bi-cash"></i> ₹<?php echo number_format($per_installment, 2); ?>/<?php echo $per_text; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-collection <?php echo $collection_class; ?>">
                                        <i class="bi <?php echo $collection_icon; ?> me-1"></i>
                                        <?php echo ucfirst($collection_type_display); ?>
                                    </span>
                                    <?php if (!empty($collection_detail)): ?>
                                        <div class="collection-detail">
                                            <i class="bi bi-info-circle"></i>
                                            <?php echo $collection_detail; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info bg-opacity-10 text-info p-2">
                                        <i class="bi bi-building me-1"></i>
                                        <?php echo htmlspecialchars($customer['finance_name'] ?? ''); ?>
                                    </span>
                                </td>
                                <td style="min-width: 120px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="small fw-semibold"><?php echo $progress_percent; ?>%</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar <?php echo $progress_bar_class; ?>" style="width: <?php echo $progress_percent; ?>%"></div>
                                    </div>
                                    <div class="customer-meta mt-1">
                                        <?php echo $customer['paid_emis'] ?? 0; ?>/<?php echo $total_emis; ?> <?php echo $unit_text; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-semibold" style="color: var(--<?php echo $collection_class; ?>-color);">₹<?php echo number_format($outstanding, 2); ?></span>
                                    <div class="customer-meta">
                                        <?php echo $customer['pending_emis'] ?? 0; ?> pending
                                    </div>
                                </td>
                                <td>
                                    <?php if (isset($customer['last_payment_date']) && $customer['last_payment_date']): ?>
                                        <span><?php echo date('d M Y', strtotime($customer['last_payment_date'])); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">No payments</span>
                                    <?php endif; ?>
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
                                           title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        
                                        <?php if ($user_role == 'admin'): ?>
                                        <a href="edit-customer.php?id=<?php echo $customer['id']; ?>" 
                                           class="btn-action btn-edit" 
                                           data-bs-toggle="tooltip" 
                                           title="Edit Customer">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php endif; ?>
                                        
                                        <!-- FIXED: Changed from customer_id to view_customer parameter -->
                                        <a href="emi-list.php?view_customer=<?php echo $customer['id']; ?>" 
                                           class="btn-action btn-emi" 
                                           data-bs-toggle="tooltip" 
                                           title="View EMI Schedule">
                                            <i class="bi bi-calendar-check"></i>
                                        </a>
                                        
                                        <?php if ($user_role == 'admin' && !$has_paid_emis): ?>
                                        <button type="button" 
                                                class="btn-action btn-delete" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#deleteModal"
                                                data-customer-id="<?php echo $customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['customer_name'] ?? ''); ?>"
                                                title="Delete Customer">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    <i class="bi bi-people" style="font-size: 3rem; color: #dee2e6;"></i>
                                    <p class="mt-3 text-muted">No customers found</p>
                                    <?php if ($user_role == 'admin'): ?>
                                    <a href="add-customer.php" class="btn btn-primary">
                                        <i class="bi bi-person-plus me-2"></i>Add Your First Customer
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete customer: <strong id="deleteCustomerName"></strong>?</p>
                <p class="text-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        if ($('#customersTable tbody tr').length > 0) {
            $('#customersTable').DataTable({
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ customers",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [9] }
                ]
            });
        }

        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        $('#deleteModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget);
            var customerId = button.data('customer-id');
            var customerName = button.data('customer-name');
            
            var modal = $(this);
            modal.find('#deleteCustomerName').text(customerName);
            modal.find('#deleteConfirmBtn').attr('href', 'customers.php?delete=' + customerId);
        });
    });
</script>
</body>
</html>