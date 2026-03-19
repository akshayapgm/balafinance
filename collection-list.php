<?php
require_once 'includes/auth.php';
$currentPage = 'collection-list';
$pageTitle = 'Collection List';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Set date range based on filter
if ($date_filter == 'today') {
    $date_from = date('Y-m-d');
    $date_to = date('Y-m-d');
} elseif ($date_filter == 'week') {
    $date_from = date('Y-m-d', strtotime('monday this week'));
    $date_to = date('Y-m-d', strtotime('sunday this week'));
} elseif ($date_filter == 'month') {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-t');
} elseif ($date_filter == 'year') {
    $date_from = date('Y-01-01');
    $date_to = date('Y-12-31');
}

// Build query
$query = "
    SELECT 
        es.id,
        es.customer_id,
        es.emi_amount,
        es.principal_amount,
        es.interest_amount,
        es.emi_due_date,
        es.status,
        es.paid_date,
        es.overdue_charges,
        es.overdue_paid,
        es.emi_bill_number,
        es.payment_method,
        es.principal_paid,
        es.interest_paid,
        es.finance_id,
        es.collection_type,
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name,
        (COALESCE(es.principal_paid, 0) + COALESCE(es.interest_paid, 0) + COALESCE(es.overdue_paid, 0)) as total_paid,
        (es.principal_amount - COALESCE(es.principal_paid, 0)) as remaining_principal,
        (es.interest_amount - COALESCE(es.interest_paid, 0)) as remaining_interest,
        (es.overdue_charges - COALESCE(es.overdue_paid, 0)) as remaining_overdue
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status = 'paid' AND es.paid_date IS NOT NULL AND es.paid_date != '0000-00-00'
";

$params = [];
$types = "";

if ($user_role != 'admin') {
    $query .= " AND es.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if (!empty($search)) {
    $query .= " AND (c.customer_name LIKE ? OR c.customer_number LIKE ? OR c.agreement_number LIKE ? OR es.emi_bill_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if (!empty($date_from)) {
    $query .= " AND es.paid_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND es.paid_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $query .= " AND es.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$query .= " ORDER BY es.paid_date DESC, es.id DESC";

$collections = null;
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $collections = $stmt->get_result();
    $stmt->close();
}

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_collections,
        COALESCE(SUM(es.emi_amount), 0) as total_amount,
        COALESCE(SUM(es.principal_paid), 0) as total_principal,
        COALESCE(SUM(es.interest_paid), 0) as total_interest,
        COALESCE(SUM(es.overdue_paid), 0) as total_overdue,
        COUNT(DISTINCT es.customer_id) as unique_customers,
        MIN(es.paid_date) as first_collection,
        MAX(es.paid_date) as last_collection
    FROM emi_schedule es
    WHERE es.status = 'paid' AND es.paid_date IS NOT NULL AND es.paid_date != '0000-00-00'
";

if ($user_role != 'admin') {
    $stats_query .= " AND es.finance_id = $finance_id";
}

if (!empty($date_from)) {
    $stats_query .= " AND es.paid_date >= '$date_from'";
}
if (!empty($date_to)) {
    $stats_query .= " AND es.paid_date <= '$date_to'";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats = array_merge([
    'total_collections' => 0,
    'total_amount' => 0,
    'total_principal' => 0,
    'total_interest' => 0,
    'total_overdue' => 0,
    'unique_customers' => 0,
    'first_collection' => null,
    'last_collection' => null
], $stats);

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get monthly collection summary for chart
$monthly_query = "
    SELECT 
        DATE_FORMAT(es.paid_date, '%Y-%m') as month,
        COUNT(*) as collection_count,
        COALESCE(SUM(es.emi_amount), 0) as month_total
    FROM emi_schedule es
    WHERE es.status = 'paid' AND es.paid_date IS NOT NULL AND es.paid_date != '0000-00-00'
";

if ($user_role != 'admin') {
    $monthly_query .= " AND es.finance_id = $finance_id";
}

$monthly_query .= " GROUP BY DATE_FORMAT(es.paid_date, '%Y-%m') ORDER BY month DESC LIMIT 6";

$monthly_result = $conn->query($monthly_query);
$monthly_data = [];
if ($monthly_result) {
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[] = $row;
    }
}

// Get payment method summary
$method_query = "
    SELECT 
        COALESCE(es.payment_method, 'cash') as payment_method,
        COUNT(*) as method_count,
        COALESCE(SUM(es.emi_amount), 0) as method_total
    FROM emi_schedule es
    WHERE es.status = 'paid' AND es.paid_date IS NOT NULL AND es.paid_date != '0000-00-00'
";

if ($user_role != 'admin') {
    $method_query .= " AND es.finance_id = $finance_id";
}

if (!empty($date_from)) {
    $method_query .= " AND es.paid_date >= '$date_from'";
}
if (!empty($date_to)) {
    $method_query .= " AND es.paid_date <= '$date_to'";
}

$method_query .= " GROUP BY COALESCE(es.payment_method, 'cash') ORDER BY method_count DESC";

$method_result = $conn->query($method_query);
$payment_methods = [];
if ($method_result) {
    while ($row = $method_result->fetch_assoc()) {
        $payment_methods[] = $row;
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
        
        .filter-chip.today.active {
            background: var(--success);
            border-color: var(--success);
        }
        
        .filter-chip.week.active {
            background: var(--info);
            border-color: var(--info);
        }
        
        .filter-chip.month.active {
            background: var(--warning);
            border-color: var(--warning);
        }
        
        .filter-chip.year.active {
            background: var(--danger);
            border-color: var(--danger);
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
            padding: 0.6rem 1rem;
            background: linear-gradient(135deg, var(--dark), #1a2634);
            color: white;
            border-bottom: none;
        }
        
        .dashboard-card .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
            font-size: 0.95rem;
        }
        
        .dashboard-card .card-header p {
            opacity: 0.8;
            margin-bottom: 0;
            font-size: 0.65rem;
            margin-top: 0.2rem;
        }
        
        .dashboard-card .card-body {
            padding: 0;
        }
        
        /* Table Container - Fixed Overflow */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        
        .table thead th {
            background: #f8fafc;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.65rem;
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 0.4rem;
            white-space: nowrap;
            text-align: left;
            vertical-align: middle;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .table tbody td {
            padding: 0.5rem 0.4rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.7rem;
            vertical-align: middle;
            white-space: nowrap;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        /* Customer Info - Compact */
        .customer-info {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            cursor: pointer;
        }
        
        .customer-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.65rem;
            flex-shrink: 0;
        }
        
        .customer-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.7rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 70px;
        }
        
        .customer-meta {
            font-size: 0.6rem;
            color: var(--text-muted);
            white-space: nowrap;
        }
        
        /* Amount Colors - Compact */
        .amount-primary { color: var(--primary); font-weight: 600; font-size: 0.7rem; }
        .amount-success { color: var(--success); font-weight: 600; font-size: 0.7rem; }
        .amount-warning { color: var(--warning); font-weight: 600; font-size: 0.7rem; }
        
        /* Badges - Compact */
        .badge-payment {
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-payment.cash {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .badge-payment.bank_transfer {
            background: #e3f2fd;
            color: var(--info);
        }
        
        .badge-payment.cheque {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .badge-payment.online {
            background: #e6f0ff;
            color: var(--primary);
        }
        
        .badge-dark {
            padding: 0.15rem 0.35rem;
            font-size: 0.55rem;
            border-radius: 4px;
        }
        
        /* Action Buttons - Ultra Compact */
        .action-buttons {
            display: flex;
            gap: 0.1rem;
            flex-wrap: nowrap;
        }
        
        .action-btn {
            width: 20px;
            height: 20px;
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.6rem;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
            color: white;
        }
        
        .action-btn.print {
            background: var(--danger);
        }
        
        .action-btn.view {
            background: var(--info);
        }
        
        .action-btn.whatsapp {
            background: #25D366;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 1.5rem;
        }
        
        .empty-state i {
            font-size: 2rem;
            color: #dee2e6;
        }
        
        /* Stat Box */
        .stat-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.5rem;
            text-align: center;
        }
        
        .stat-box .label {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-bottom: 0.1rem;
        }
        
        .stat-box .value {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        /* Method Item - Compact */
        .method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.4rem 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .method-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.7rem;
        }
        
        .method-count {
            font-size: 0.6rem;
            color: var(--text-muted);
        }
        
        .method-stats {
            text-align: right;
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
            .table-responsive {
                margin: 0 -1rem;
                width: calc(100% + 2rem);
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
                        <h4><i class="bi bi-list-check me-2"></i>Collection List</h4>
                        <p>View all collected EMI payments</p>
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
                    <div class="stat-icon primary"><i class="bi bi-cash-stack"></i></div>
                    <div class="stat-label">TOTAL COLLECTIONS</div>
                    <div class="stat-value"><?php echo number_format($stats['total_collections']); ?></div>
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
                    <div class="stat-icon warning"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_overdue'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                    <div class="stat-label">CUSTOMERS</div>
                    <div class="stat-value"><?php echo number_format($stats['unique_customers']); ?></div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Customer, agreement, bill..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <!-- Date Filter Chips -->
                        <div class="col-md-5">
                            <label class="form-label">Date Range</label>
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="?date_filter=all&search=<?php echo urlencode($search); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                                   class="filter-chip <?php echo $date_filter == 'all' ? 'active' : ''; ?>">All</a>
                                <a href="?date_filter=today&search=<?php echo urlencode($search); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                                   class="filter-chip today <?php echo $date_filter == 'today' ? 'active' : ''; ?>">Today</a>
                                <a href="?date_filter=week&search=<?php echo urlencode($search); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                                   class="filter-chip week <?php echo $date_filter == 'week' ? 'active' : ''; ?>">Week</a>
                                <a href="?date_filter=month&search=<?php echo urlencode($search); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                                   class="filter-chip month <?php echo $date_filter == 'month' ? 'active' : ''; ?>">Month</a>
                                <a href="?date_filter=year&search=<?php echo urlencode($search); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                                   class="filter-chip year <?php echo $date_filter == 'year' ? 'active' : ''; ?>">Year</a>
                                <a href="?date_filter=custom&search=<?php echo urlencode($search); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                                   class="filter-chip <?php echo $date_filter == 'custom' ? 'active' : ''; ?>">Custom</a>
                            </div>
                        </div>
                        
                        <?php if ($user_role == 'admin'): ?>
                        <div class="col-md-2">
                            <label class="form-label">Finance</label>
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
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <a href="collection-list.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Custom Date Range (shown only when custom is selected) -->
                    <div class="row g-2 mt-2" id="customDateRange" style="display: <?php echo $date_filter == 'custom' ? 'flex' : 'none'; ?>;">
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>" placeholder="From Date">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>" placeholder="To Date">
                        </div>
                        <div class="col-md-2">
                            <span class="text-muted small">Select custom range</span>
                        </div>
                    </div>
                </form>
                
                <?php if ($stats['first_collection'] && $stats['last_collection'] && $stats['first_collection'] != '0000-00-00' && $stats['last_collection'] != '0000-00-00'): ?>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Showing collections from <?php echo date('d M Y', strtotime($stats['first_collection'])); ?> to <?php echo date('d M Y', strtotime($stats['last_collection'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Collections Table - Compact with removed columns -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Collections</h5>
                            <p>Total: <?php echo $stats['total_collections']; ?> | ₹<?php echo number_format($stats['total_amount'], 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($collections && $collections->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="collectionsTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Bill No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Agreement</th>
                                        <th>EMI</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Overdue</th>
                                        <th>Mode</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($col = $collections->fetch_assoc()): 
                                    // Get initials
                                    $name_parts = explode(' ', $col['customer_name'] ?? '');
                                    $initials = '';
                                    foreach ($name_parts as $part) {
                                        $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    $initials = substr($initials, 0, 2) ?: 'U';
                                    
                                    // Format date properly
                                    $paid_date = $col['paid_date'];
                                    $formatted_date = ($paid_date && $paid_date != '0000-00-00') ? date('d/m/y', strtotime($paid_date)) : 'N/A';
                                ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark"><?php echo htmlspecialchars(substr($col['emi_bill_number'] ?: 'N/A', 0, 8)); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo $formatted_date; ?></span>
                                        </td>
                                        <td>
                                            <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $col['customer_id']; ?>'">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div class="customer-name" title="<?php echo htmlspecialchars($col['customer_name']); ?>">
                                                    <?php echo htmlspecialchars(substr($col['customer_name'], 0, 12)); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars(substr($col['agreement_number'], 0, 6)); ?></span>
                                        </td>
                                        <td class="amount-primary fw-bold">₹<?php echo number_format($col['emi_amount'], 0); ?></td>
                                        <td class="amount-success">₹<?php echo number_format($col['principal_paid'], 0); ?></td>
                                        <td class="amount-success">₹<?php echo number_format($col['interest_paid'], 0); ?></td>
                                        <td>
                                            <?php if ($col['overdue_paid'] > 0): ?>
                                                <span class="amount-warning">₹<?php echo number_format($col['overdue_paid'], 0); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-payment <?php echo strtolower(str_replace(' ', '_', $col['payment_method'] ?: 'cash')); ?>">
                                                <?php echo substr(ucfirst(str_replace('_', ' ', $col['payment_method'] ?: 'cash')), 0, 4); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="receipt.php?emi_id=<?php echo $col['id']; ?>&customer_id=<?php echo $col['customer_id']; ?>" 
                                                   class="action-btn print" target="_blank" title="Print">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <a href="view-customer.php?id=<?php echo $col['customer_id']; ?>" 
                                                   class="action-btn view" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" 
                                                        class="action-btn whatsapp" 
                                                        onclick="shareViaWhatsApp(<?php echo $col['id']; ?>, <?php echo $col['customer_id']; ?>, '<?php echo $col['customer_number']; ?>')"
                                                        title="WhatsApp">
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
                            <i class="bi bi-cash-stack text-muted" style="font-size: 3rem;"></i>
                            <h6 class="mt-3 text-muted">No collections found</h6>
                            <?php if (!empty($search) || !empty($date_from) || !empty($date_to) || $finance_filter > 0): ?>
                                <a href="collection-list.php" class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                            <div class="mt-3">
                                <a href="collections.php" class="btn btn-primary btn-sm">
                                    <i class="bi bi-cash me-2"></i>Record New Collection
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Analytics Section -->
            <div class="row g-3">
                <!-- Monthly Collection Chart -->
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                            <h5><i class="bi bi-graph-up me-2"></i>Monthly Collections</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($monthly_data)): ?>
                                <div class="mini-chart">
                                    <?php 
                                    $max_amount = 0;
                                    foreach ($monthly_data as $month) {
                                        $max_amount = max($max_amount, $month['month_total']);
                                    }
                                    $monthly_data = array_reverse($monthly_data);
                                    foreach ($monthly_data as $month): 
                                        $height = $max_amount > 0 ? ($month['month_total'] / $max_amount) * 60 : 4;
                                    ?>
                                    <div class="chart-bar" style="height: <?php echo max(4, $height); ?>px;" 
                                         title="<?php echo $month['month']; ?>: ₹<?php echo number_format($month['month_total'], 0); ?> (<?php echo $month['collection_count']; ?>)"></div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="row g-2 mt-3">
                                    <?php foreach (array_reverse($monthly_data) as $month): ?>
                                    <div class="col-4">
                                        <div class="stat-box p-2">
                                            <div class="label"><?php echo date('M', strtotime($month['month'] . '-01')); ?></div>
                                            <div class="value small">₹<?php echo number_format($month['month_total']/1000, 1); ?>K</div>
                                            <div class="label"><?php echo $month['collection_count']; ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-graph-up text-muted" style="font-size: 2rem;"></i>
                                    <p class="mt-2 text-muted small">No monthly data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods -->
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e);">
                            <h5><i class="bi bi-credit-card me-2"></i>Payment Methods</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($payment_methods)): ?>
                                <div class="method-list">
                                    <?php foreach ($payment_methods as $method): ?>
                                    <div class="method-item">
                                        <div>
                                            <span class="method-name"><?php echo ucfirst(str_replace('_', ' ', substr($method['payment_method'], 0, 8))); ?></span>
                                            <div class="method-count"><?php echo $method['method_count']; ?> txns</div>
                                        </div>
                                        <div class="method-stats">
                                            <div class="amount-success fw-bold">₹<?php echo number_format($method['method_total']/1000, 1); ?>K</div>
                                            <div class="method-count">
                                                <?php echo round(($method['method_total'] / max($stats['total_amount'], 1)) * 100, 1); ?>%
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-credit-card text-muted" style="font-size: 2rem;"></i>
                                    <p class="mt-2 text-muted small">No payment method data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
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
    <?php if ($collections && $collections->num_rows > 0): ?>
    $('#collectionsTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
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
            { orderable: false, targets: [9] }
        ]
    });
    <?php endif; ?>

    // Show/hide custom date range based on filter selection
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('date_filter') === 'custom') {
        $('#customDateRange').show();
    }

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

function shareViaWhatsApp(emiId, customerId, phone) {
    const phoneNumber = prompt('Enter phone number:', phone ? '91' + phone.replace(/\D/g, '').substring(0,10) : '');
    if (!phoneNumber) return;

    const baseUrl = window.location.protocol + '//' + window.location.host;
    const receiptUrl = baseUrl + '/bala-finance/receipt.php?emi_id=' + emiId + '&customer_id=' + customerId;
    const message = encodeURIComponent(
        'Payment Receipt - Bala Finance\n' +
        'Receipt: ' + receiptUrl
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