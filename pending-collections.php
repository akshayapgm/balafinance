<?php
require_once 'includes/auth.php';
$currentPage = 'pending-collections';
$pageTitle = 'Pending Collections';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$due_from = isset($_GET['due_from']) ? $_GET['due_from'] : '';
$due_to = isset($_GET['due_to']) ? $_GET['due_to'] : '';
$collection_type = isset($_GET['collection_type']) ? $_GET['collection_type'] : 'all';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Build query for pending collections
$query = "
    SELECT 
        es.id,
        es.customer_id,
        es.emi_amount,
        es.principal_amount,
        es.interest_amount,
        es.emi_due_date,
        es.status,
        es.overdue_charges,
        es.overdue_paid,
        es.emi_bill_number,
        es.collection_type,
        es.week_number,
        es.day_number,
        COALESCE(es.principal_paid, 0) as principal_paid,
        COALESCE(es.interest_paid, 0) as interest_paid,
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name,
        l.loan_name,
        (es.principal_amount - COALESCE(es.principal_paid, 0)) as remaining_principal,
        (es.interest_amount - COALESCE(es.interest_paid, 0)) as remaining_interest,
        (es.overdue_charges - COALESCE(es.overdue_paid, 0)) as remaining_overdue,
        DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN loans l ON c.loan_id = l.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status IN ('unpaid', 'overdue', 'partial') 
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$params = [];
$types = "";

if ($user_role != 'admin') {
    $query .= " AND es.finance_id = ?";
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

if (!empty($due_from)) {
    $query .= " AND es.emi_due_date >= ?";
    $params[] = $due_from;
    $types .= "s";
}

if (!empty($due_to)) {
    $query .= " AND es.emi_due_date <= ?";
    $params[] = $due_to;
    $types .= "s";
}

if ($collection_type != 'all') {
    $query .= " AND es.collection_type = ?";
    $params[] = $collection_type;
    $types .= "s";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $query .= " AND es.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$query .= " ORDER BY 
    CASE 
        WHEN es.emi_due_date < CURDATE() THEN 0 
        ELSE 1 
    END,
    es.emi_due_date ASC,
    es.id ASC";

$pending = null;
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $pending = $stmt->get_result();
    $stmt->close();
}

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_pending,
        COALESCE(SUM(es.emi_amount), 0) as total_amount,
        COALESCE(SUM(es.principal_amount - COALESCE(es.principal_paid, 0)), 0) as total_principal,
        COALESCE(SUM(es.interest_amount - COALESCE(es.interest_paid, 0)), 0) as total_interest,
        COALESCE(SUM(es.overdue_charges - COALESCE(es.overdue_paid, 0)), 0) as total_overdue,
        COUNT(DISTINCT es.customer_id) as unique_customers,
        SUM(CASE WHEN es.emi_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN es.emi_due_date = CURDATE() THEN 1 ELSE 0 END) as due_today_count,
        SUM(CASE WHEN es.emi_due_date > CURDATE() THEN 1 ELSE 0 END) as future_count,
        MIN(es.emi_due_date) as earliest_due,
        MAX(es.emi_due_date) as latest_due
    FROM emi_schedule es
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

if ($user_role != 'admin') {
    $stats_query .= " AND es.finance_id = $finance_id";
}

if (!empty($due_from)) {
    $stats_query .= " AND es.emi_due_date >= '$due_from'";
}
if (!empty($due_to)) {
    $stats_query .= " AND es.emi_due_date <= '$due_to'";
}
if ($collection_type != 'all') {
    $stats_query .= " AND es.collection_type = '$collection_type'";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats = array_merge([
    'total_pending' => 0,
    'total_amount' => 0,
    'total_principal' => 0,
    'total_interest' => 0,
    'total_overdue' => 0,
    'unique_customers' => 0,
    'overdue_count' => 0,
    'due_today_count' => 0,
    'future_count' => 0,
    'earliest_due' => null,
    'latest_due' => null
], $stats);

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get collection type summary
$type_query = "
    SELECT 
        es.collection_type,
        COUNT(*) as type_count,
        COALESCE(SUM(es.emi_amount), 0) as type_total
    FROM emi_schedule es
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

if ($user_role != 'admin') {
    $type_query .= " AND es.finance_id = $finance_id";
}

if (!empty($due_from)) {
    $type_query .= " AND es.emi_due_date >= '$due_from'";
}
if (!empty($due_to)) {
    $type_query .= " AND es.emi_due_date <= '$due_to'";
}

$type_query .= " GROUP BY es.collection_type ORDER BY type_count DESC";

$type_result = $conn->query($type_query);
$collection_types = [];
if ($type_result) {
    while ($row = $type_result->fetch_assoc()) {
        $collection_types[] = $row;
    }
}

// Get upcoming due dates summary
$upcoming_query = "
    SELECT 
        DATE_FORMAT(es.emi_due_date, '%Y-%m-%d') as due_date,
        COUNT(*) as due_count,
        COALESCE(SUM(es.emi_amount), 0) as due_total
    FROM emi_schedule es
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
      AND es.emi_due_date >= CURDATE()
";

if ($user_role != 'admin') {
    $upcoming_query .= " AND es.finance_id = $finance_id";
}

$upcoming_query .= " GROUP BY es.emi_due_date ORDER BY es.emi_due_date ASC LIMIT 7";

$upcoming_result = $conn->query($upcoming_query);
$upcoming_dates = [];
if ($upcoming_result) {
    while ($row = $upcoming_result->fetch_assoc()) {
        $upcoming_dates[] = $row;
    }
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
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            padding: 1.5rem 2rem;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.25rem 2rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .page-header h4 {
            font-weight: 600;
            margin-bottom: 0.25rem;
            font-size: 1.4rem;
        }
        
        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        
        .page-header .btn-light {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.4rem 1.2rem;
            font-weight: 500;
            border-radius: 30px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .page-header .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* Stat Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.75rem 0.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        
        .stat-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            white-space: nowrap;
        }
        
        .stat-icon {
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        
        .stat-icon.primary { color: var(--primary); }
        .stat-icon.success { color: var(--success); }
        .stat-icon.warning { color: var(--warning); }
        .stat-icon.danger { color: var(--danger); }
        .stat-icon.info { color: var(--info); }
        .stat-icon.purple { color: #8b5cf6; }
        .stat-icon.overdue { color: var(--danger); }
        .stat-icon.today { color: var(--warning); }
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            width: 100%;
        }
        
        .filter-card .form-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .filter-card .form-control,
        .filter-card .form-select,
        .filter-card .btn {
            font-size: 0.9rem;
            height: 38px;
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
        
        /* Dashboard Card */
        .dashboard-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .dashboard-card .card-header {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--dark), #1a2634);
            color: white;
            border-bottom: none;
        }
        
        .dashboard-card .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
            font-size: 1rem;
        }
        
        .dashboard-card .card-header p {
            opacity: 0.8;
            margin-bottom: 0;
            font-size: 0.7rem;
            margin-top: 0.2rem;
        }
        
        .dashboard-card .card-body {
            padding: 1.25rem;
        }
        
        /* Table Container */
        .table-container {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1300px;
        }
        
        .table thead th {
            background: #f8fafc;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.7rem;
            border-bottom: 1px solid var(--border-color);
            padding: 0.6rem 1rem;
            white-space: nowrap;
            text-align: left;
            vertical-align: middle;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .table tbody td {
            padding: 0.6rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.8rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .table tbody tr.overdue-row {
            background: #fef2f2;
        }
        
        .table tbody tr.today-row {
            background: #fff3e0;
        }
        
        /* Customer Info */
        .customer-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .customer-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        
        .customer-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 120px;
        }
        
        .customer-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.2rem;
            white-space: nowrap;
        }
        
        /* Amount Colors */
        .amount-primary { color: var(--primary); font-weight: 600; }
        .amount-success { color: var(--success); font-weight: 600; }
        .amount-danger { color: var(--danger); font-weight: 600; }
        .amount-warning { color: var(--warning); font-weight: 600; }
        
        /* Badges */
        .badge-collection {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
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
        
        .badge-status {
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-status.overdue {
            background: #fee2e2;
            color: var(--danger);
        }
        
        .badge-status.due-today {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .badge-status.future {
            background: #e3f2fd;
            color: var(--info);
        }
        
        .badge-finance {
            background: #e6f0ff;
            color: var(--primary);
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Action Buttons */
        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            margin: 0 2px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            color: white;
        }
        
        .action-btn.pay {
            background: var(--success);
        }
        
        .action-btn.view {
            background: var(--info);
        }
        
        .action-btn.whatsapp {
            background: #25D366;
        }
        
        /* Progress Bar */
        .progress-sm {
            height: 4px;
            width: 60px;
            border-radius: 2px;
            background: #e9ecef;
            display: inline-block;
            margin-left: 0.25rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
        }
        
        .empty-state i {
            font-size: 2rem;
            color: #dee2e6;
        }
        
        /* Due Date Box */
        .due-date-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.75rem;
            text-align: center;
            height: 100%;
        }
        
        .due-date-day {
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .due-date-month {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .due-date-count {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
                        <h4><i class="bi bi-hourglass-split me-2"></i>Pending Collections</h4>
                        <p>View all pending EMI collections</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="collections.php" class="btn btn-light">
                            <i class="bi bi-cash me-2"></i>Record Collection
                        </a>
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
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-clock-history"></i></div>
                    <div class="stat-label">TOTAL PENDING</div>
                    <div class="stat-value"><?php echo number_format($stats['total_pending']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="bi bi-currency-rupee"></i></div>
                    <div class="stat-label">TOTAL AMOUNT</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_amount'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-bank"></i></div>
                    <div class="stat-label">PRINCIPAL</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_principal'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-percent"></i></div>
                    <div class="stat-label">INTEREST</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_interest'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_overdue'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                    <div class="stat-label">CUSTOMERS</div>
                    <div class="stat-value"><?php echo number_format($stats['unique_customers']); ?></div>
                </div>
            </div>

            <!-- Status Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon overdue"><i class="bi bi-exclamation-circle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value"><?php echo number_format($stats['overdue_count']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon today"><i class="bi bi-calendar-check"></i></div>
                    <div class="stat-label">DUE TODAY</div>
                    <div class="stat-value"><?php echo number_format($stats['due_today_count']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">FUTURE</div>
                    <div class="stat-value"><?php echo number_format($stats['future_count']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-calendar-week"></i></div>
                    <div class="stat-label">EARLIEST DUE</div>
                    <div class="stat-value"><?php echo $stats['earliest_due'] ? date('d/m', strtotime($stats['earliest_due'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-calendar-week"></i></div>
                    <div class="stat-label">LATEST DUE</div>
                    <div class="stat-value"><?php echo $stats['latest_due'] ? date('d/m', strtotime($stats['latest_due'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-grid"></i></div>
                    <div class="stat-label">UNIQUE CUST</div>
                    <div class="stat-value"><?php echo number_format($stats['unique_customers']); ?></div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search customer, mobile, agreement..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="due_from" 
                                   value="<?php echo $due_from; ?>" placeholder="Due From">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="due_to" 
                                   value="<?php echo $due_to; ?>" placeholder="Due To">
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
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </div>
                        <div class="col-md-1">
                            <a href="pending-collections.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Collection Type Filter -->
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <a href="?<?php 
                            $params = $_GET;
                            unset($params['collection_type']);
                            echo http_build_query($params);
                        ?>" class="filter-chip <?php echo $collection_type == 'all' ? 'active' : ''; ?>">
                            <i class="bi bi-grid-3x3-gap-fill"></i> All
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'monthly';
                            echo http_build_query($params);
                        ?>" class="filter-chip monthly <?php echo $collection_type == 'monthly' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-month"></i> Monthly
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'weekly';
                            echo http_build_query($params);
                        ?>" class="filter-chip weekly <?php echo $collection_type == 'weekly' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-week"></i> Weekly
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'daily';
                            echo http_build_query($params);
                        ?>" class="filter-chip daily <?php echo $collection_type == 'daily' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-day"></i> Daily
                        </a>
                    </div>
                </form>
                
                <?php if ($stats['earliest_due'] && $stats['latest_due']): ?>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Showing pending from <?php echo date('d M Y', strtotime($stats['earliest_due'])); ?> to <?php echo date('d M Y', strtotime($stats['latest_due'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Due Dates -->
            <?php if (!empty($upcoming_dates)): ?>
            <div class="row g-2 mb-3">
                <?php foreach ($upcoming_dates as $due): ?>
                <div class="col-md-3 col-lg-2">
                    <div class="due-date-box">
                        <div class="due-date-day"><?php echo date('d', strtotime($due['due_date'])); ?></div>
                        <div class="due-date-month"><?php echo date('M', strtotime($due['due_date'])); ?></div>
                        <div class="due-date-count"><?php echo $due['due_count']; ?> EMIs</div>
                        <div class="small text-muted">₹<?php echo number_format($due['due_total']/1000, 1); ?>K</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Pending Collections Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Pending Collections</h5>
                            <p>Total: <?php echo $stats['total_pending']; ?> pending | ₹<?php echo number_format($stats['total_amount'], 0); ?> to collect</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($pending && $pending->num_rows > 0): ?>
                        <div class="table-container">
                            <table id="pendingTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Due Date</th>
                                        <th>Customer</th>
                                        <th>Agreement</th>
                                        <th>Loan</th>
                                        <th>Type</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Overdue</th>
                                        <th>Total Due</th>
                                        <th>Status</th>
                                        <th>Finance</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($row = $pending->fetch_assoc()): 
                                    $total_due = $row['remaining_principal'] + $row['remaining_interest'] + $row['remaining_overdue'];
                                    $due_date = strtotime($row['emi_due_date']);
                                    $today = strtotime(date('Y-m-d'));
                                    
                                    if ($due_date < $today) {
                                        $row_class = 'overdue-row';
                                        $status_class = 'overdue';
                                        $status_text = 'Overdue (' . $row['days_overdue'] . 'd)';
                                    } elseif ($due_date == $today) {
                                        $row_class = 'today-row';
                                        $status_class = 'due-today';
                                        $status_text = 'Due Today';
                                    } else {
                                        $row_class = '';
                                        $status_class = 'future';
                                        $status_text = 'Upcoming';
                                    }
                                    
                                    $collection_class = '';
                                    $type_display = '';
                                    if ($row['collection_type'] == 'monthly') {
                                        $collection_class = 'monthly';
                                        $type_display = 'M';
                                    } elseif ($row['collection_type'] == 'weekly') {
                                        $collection_class = 'weekly';
                                        $type_display = 'W' . ($row['week_number'] ?? '');
                                    } else {
                                        $collection_class = 'daily';
                                        $type_display = 'D' . ($row['day_number'] ?? '');
                                    }
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <span class="fw-semibold"><?php echo date('d/m/y', strtotime($row['emi_due_date'])); ?></span>
                                        </td>
                                        <td>
                                            <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $row['customer_id']; ?>'">
                                                <div class="customer-avatar">
                                                    <?php 
                                                    $name_parts = explode(' ', $row['customer_name'] ?? '');
                                                    $initials = '';
                                                    foreach ($name_parts as $part) {
                                                        $initials .= strtoupper(substr($part, 0, 1));
                                                    }
                                                    echo substr($initials, 0, 2) ?: 'U';
                                                    ?>
                                                </div>
                                                <div>
                                                    <div class="customer-name" title="<?php echo htmlspecialchars($row['customer_name']); ?>">
                                                        <?php echo htmlspecialchars(substr($row['customer_name'], 0, 15)); ?>
                                                    </div>
                                                    <div class="customer-meta">
                                                        <i class="bi bi-telephone-fill"></i> <?php echo htmlspecialchars(substr($row['customer_number'], 0, 10)); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars(substr($row['agreement_number'], 0, 8)); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars(substr($row['loan_name'], 0, 8)); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-collection <?php echo $collection_class; ?>" title="<?php echo ucfirst($row['collection_type']); ?>">
                                                <?php echo $type_display; ?>
                                            </span>
                                        </td>
                                        <td class="amount-primary">₹<?php echo number_format($row['remaining_principal'], 0); ?></td>
                                        <td class="amount-primary">₹<?php echo number_format($row['remaining_interest'], 0); ?></td>
                                        <td>
                                            <?php if ($row['remaining_overdue'] > 0): ?>
                                                <span class="amount-danger">₹<?php echo number_format($row['remaining_overdue'], 0); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="amount-danger fw-bold">₹<?php echo number_format($total_due, 0); ?></td>
                                        <td>
                                            <span class="badge-status <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-finance">
                                                <?php echo htmlspecialchars(substr($row['finance_name'], 0, 8)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="pay-emi.php?emi_id=<?php echo $row['id']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                                   class="action-btn pay" title="Pay EMI">
                                                    <i class="bi bi-cash"></i>
                                                </a>
                                                <a href="view-customer.php?id=<?php echo $row['customer_id']; ?>" 
                                                   class="action-btn view" title="View Customer">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" 
                                                        class="action-btn whatsapp" 
                                                        onclick="shareReminder(<?php echo $row['id']; ?>, <?php echo $row['customer_id']; ?>, '<?php echo $row['customer_number']; ?>', '<?php echo date('d/m/y', strtotime($row['emi_due_date'])); ?>', <?php echo $total_due; ?>)"
                                                        title="Send Reminder">
                                                    <i class="bi bi-whatsapp"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                            <h6 class="mt-3 text-muted">No pending collections found</h6>
                            <?php if (!empty($search) || !empty($due_from) || !empty($due_to) || $collection_type != 'all' || $finance_filter > 0): ?>
                                <a href="pending-collections.php" class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Collection Type Summary -->
            <?php if (!empty($collection_types)): ?>
            <div class="row g-3">
                <div class="col-md-12">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                            <h5><i class="bi bi-pie-chart me-2"></i>Collection Type Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($collection_types as $type): 
                                    $color = '';
                                    $icon = '';
                                    if ($type['collection_type'] == 'monthly') {
                                        $color = 'primary';
                                        $icon = 'bi-calendar-month';
                                    } elseif ($type['collection_type'] == 'weekly') {
                                        $color = 'weekly';
                                        $icon = 'bi-calendar-week';
                                    } else {
                                        $color = 'daily';
                                        $icon = 'bi-calendar-day';
                                    }
                                ?>
                                <div class="col-md-4">
                                    <div class="stat-card text-start p-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <div style="font-size: 1.5rem; color: var(--<?php echo $color; ?>-color);">
                                                <i class="bi <?php echo $icon; ?>"></i>
                                            </div>
                                            <div>
                                                <div class="stat-label" style="color: var(--<?php echo $color; ?>-color);"><?php echo ucfirst($type['collection_type']); ?></div>
                                                <div class="stat-value"><?php echo $type['type_count']; ?> pending</div>
                                                <div class="small text-muted">₹<?php echo number_format($type['type_total']/1000, 1); ?>K to collect</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    <?php if ($pending && $pending->num_rows > 0): ?>
    $('#pendingTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
        language: {
            search: "Search:",
            lengthMenu: "_MENU_",
            info: "Showing _START_-_END_ of _TOTAL_",
            paginate: {
                first: '<i class="bi bi-chevron-double-left"></i>',
                previous: '<i class="bi bi-chevron-left"></i>',
                next: '<i class="bi bi-chevron-right"></i>',
                last: '<i class="bi bi-chevron-double-right"></i>'
            }
        },
        columnDefs: [
            { orderable: false, targets: [11] }
        ]
    });
    <?php endif; ?>

    // Touch feedback
    const buttons = document.querySelectorAll('.btn, .action-btn');
    buttons.forEach(button => {
        button.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.98)';
        });
        button.addEventListener('touchend', function() {
            this.style.transform = '';
        });
    });
});

function shareReminder(emiId, customerId, phone, dueDate, amount) {
    const phoneNumber = prompt('Enter phone number with country code:', phone ? '91' + phone.replace(/\D/g, '').substring(0,10) : '');
    if (!phoneNumber) return;

    const message = encodeURIComponent(
        'BALA FINANCE - Payment Reminder\n' +
        'Dear Customer, your EMI of ₹' + amount.toLocaleString() + ' is due on ' + dueDate + '.\n' +
        'Please make the payment at your earliest convenience.\n' +
        'Thank you for your business!'
    );

    window.open('https://wa.me/' + phoneNumber.replace(/\D/g, '') + '?text=' + message, '_blank');
}

// Auto-hide alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        bootstrap.Alert.getOrCreateInstance(alert).close();
    });
}, 4000);
</script>
</body>
</html>