<?php
require_once 'includes/auth.php';
$currentPage = 'date-wise-report';
$pageTitle = 'Date Wise Report';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$report_type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$report_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$report_month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$report_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Set date ranges based on report type
if ($report_type == 'daily') {
    $date_from = $report_date;
    $date_to = $report_date;
    $date_label = date('d M Y', strtotime($report_date));
} elseif ($report_type == 'monthly') {
    $date_from = $report_year . '-' . str_pad($report_month, 2, '0', STR_PAD_LEFT) . '-01';
    $date_to = date('Y-m-t', strtotime($date_from));
    $date_label = date('F Y', strtotime($date_from));
} elseif ($report_type == 'range') {
    if (empty($date_from)) $date_from = date('Y-m-01');
    if (empty($date_to)) $date_to = date('Y-m-d');
    $date_label = date('d M Y', strtotime($date_from)) . ' to ' . date('d M Y', strtotime($date_to));
} else { // yearly
    $date_from = $report_year . '-01-01';
    $date_to = $report_year . '-12-31';
    $date_label = 'Year ' . $report_year;
}

// Build query for collections
$collections_query = "
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
        l.loan_name,
        (COALESCE(es.principal_paid, 0) + COALESCE(es.interest_paid, 0) + COALESCE(es.overdue_paid, 0)) as total_paid
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN loans l ON c.loan_id = l.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status = 'paid' 
      AND es.paid_date IS NOT NULL 
      AND es.paid_date != '0000-00-00'
      AND es.paid_date BETWEEN ? AND ?
";

$params = [$date_from, $date_to];
$types = "ss";

if ($user_role != 'admin') {
    $collections_query .= " AND es.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $collections_query .= " AND es.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$collections_query .= " ORDER BY es.paid_date DESC, es.id DESC";

$collections = null;
$stmt = $conn->prepare($collections_query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
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
        COUNT(DISTINCT es.customer_id) as unique_customers
    FROM emi_schedule es
    WHERE es.status = 'paid' 
      AND es.paid_date IS NOT NULL 
      AND es.paid_date != '0000-00-00'
      AND es.paid_date BETWEEN ? AND ?
";

$stats_params = [$date_from, $date_to];
$stats_types = "ss";

if ($user_role != 'admin') {
    $stats_query .= " AND es.finance_id = ?";
    $stats_params[] = $finance_id;
    $stats_types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $stats_query .= " AND es.finance_id = ?";
    $stats_params[] = $finance_filter;
    $stats_types .= "i";
}

$stats_stmt = $conn->prepare($stats_query);
$stats = [];
if ($stats_stmt) {
    $stats_stmt->bind_param($stats_types, ...$stats_params);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
    $stats_stmt->close();
}

$stats = array_merge([
    'total_collections' => 0,
    'total_amount' => 0,
    'total_principal' => 0,
    'total_interest' => 0,
    'total_overdue' => 0,
    'unique_customers' => 0
], $stats);

// Get daily breakdown for the period
$daily_query = "
    SELECT 
        es.paid_date,
        DAYOFWEEK(es.paid_date) as day_of_week,
        COUNT(*) as daily_count,
        COALESCE(SUM(es.emi_amount), 0) as daily_total
    FROM emi_schedule es
    WHERE es.status = 'paid' 
      AND es.paid_date IS NOT NULL 
      AND es.paid_date != '0000-00-00'
      AND es.paid_date BETWEEN ? AND ?
";

$daily_params = [$date_from, $date_to];
$daily_types = "ss";

if ($user_role != 'admin') {
    $daily_query .= " AND es.finance_id = ?";
    $daily_params[] = $finance_id;
    $daily_types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $daily_query .= " AND es.finance_id = ?";
    $daily_params[] = $finance_filter;
    $daily_types .= "i";
}

$daily_query .= " GROUP BY es.paid_date ORDER BY es.paid_date ASC";

$daily_stmt = $conn->prepare($daily_query);
$daily_data = [];
if ($daily_stmt) {
    $daily_stmt->bind_param($daily_types, ...$daily_params);
    $daily_stmt->execute();
    $daily_result = $daily_stmt->get_result();
    while ($row = $daily_result->fetch_assoc()) {
        $daily_data[] = $row;
    }
    $daily_stmt->close();
}

// Get payment method summary
$method_query = "
    SELECT 
        COALESCE(es.payment_method, 'cash') as payment_method,
        COUNT(*) as method_count,
        COALESCE(SUM(es.emi_amount), 0) as method_total
    FROM emi_schedule es
    WHERE es.status = 'paid' 
      AND es.paid_date IS NOT NULL 
      AND es.paid_date != '0000-00-00'
      AND es.paid_date BETWEEN ? AND ?
";

$method_params = [$date_from, $date_to];
$method_types = "ss";

if ($user_role != 'admin') {
    $method_query .= " AND es.finance_id = ?";
    $method_params[] = $finance_id;
    $method_types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $method_query .= " AND es.finance_id = ?";
    $method_params[] = $finance_filter;
    $method_types .= "i";
}

$method_query .= " GROUP BY COALESCE(es.payment_method, 'cash') ORDER BY method_count DESC";

$method_stmt = $conn->prepare($method_query);
$payment_methods = [];
if ($method_stmt) {
    $method_stmt->bind_param($method_types, ...$method_params);
    $method_stmt->execute();
    $method_result = $method_stmt->get_result();
    while ($row = $method_result->fetch_assoc()) {
        $payment_methods[] = $row;
    }
    $method_stmt->close();
}

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get months for dropdown
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get days of week
$days_of_week = [
    1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday',
    5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'
];

// Get available years
$current_year = date('Y');
$years = range($current_year - 5, $current_year + 1);
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
        
        /* Report Type Cards */
        .report-type-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .report-type-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .report-type-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .report-type-card.active {
            border-color: var(--primary);
            background: #e6f0ff;
        }
        
        .report-type-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }
        
        .report-type-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .report-type-desc {
            font-size: 0.7rem;
            color: var(--text-muted);
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
            min-width: 1000px;
        }
        
        .table thead th {
            background: #f8fafc;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            padding: 0.75rem 1rem;
            white-space: nowrap;
            text-align: left;
            vertical-align: middle;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.85rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
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
            white-space: nowrap;
        }
        
        /* Amount Colors */
        .amount-primary { color: var(--primary); font-weight: 600; }
        .amount-success { color: var(--success); font-weight: 600; }
        .amount-warning { color: var(--warning); font-weight: 600; }
        .amount-danger { color: var(--danger); font-weight: 600; }
        
        /* Badges */
        .badge-payment {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
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
            padding: 0.25rem 0.75rem;
            font-size: 0.7rem;
            border-radius: 20px;
        }
        
        .badge-finance {
            background: #e6f0ff;
            color: var(--primary);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Action Buttons */
        .action-btn {
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
            margin: 0 2px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
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
        
        /* Mini Chart */
        .mini-chart {
            display: flex;
            align-items: flex-end;
            gap: 0.5rem;
            height: 60px;
            margin-top: 0.5rem;
        }
        
        .chart-bar {
            flex: 1;
            background: linear-gradient(135deg, var(--primary), var(--info));
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            transition: height 0.3s ease;
        }
        
        /* Method Item */
        .method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .method-item:last-child {
            border-bottom: none;
        }
        
        .method-name {
            font-weight: 600;
            color: var(--text-primary);
            text-transform: capitalize;
        }
        
        .method-stats {
            text-align: right;
        }
        
        .method-count {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        /* Summary Box */
        .summary-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            height: 100%;
        }
        
        .summary-title {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        
        .summary-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .summary-small {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        /* Day Box */
        .day-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.75rem;
            text-align: center;
            height: 100%;
        }
        
        .day-name {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .day-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .day-count {
            font-size: 0.65rem;
            color: var(--text-muted);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
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
            .report-type-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .report-type-grid {
                grid-template-columns: 1fr;
            }
            .table-container {
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
                        <h4><i class="bi bi-calendar-week me-2"></i>Date Wise Report</h4>
                        <p>View collection reports by date range</p>
                    </div>
                    <span class="badge bg-white text-primary py-2 px-3">
                        <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                    </span>
                </div>
            </div>

            <!-- Report Type Selection -->
            <div class="report-type-grid">
                <a href="?type=daily&date=<?php echo date('Y-m-d'); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                   class="report-type-card <?php echo $report_type == 'daily' ? 'active' : ''; ?>">
                    <div class="report-type-icon"><i class="bi bi-calendar-day"></i></div>
                    <div class="report-type-title">Daily Report</div>
                    <div class="report-type-desc">View collections for a specific day</div>
                </a>
                <a href="?type=monthly&month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                   class="report-type-card <?php echo $report_type == 'monthly' ? 'active' : ''; ?>">
                    <div class="report-type-icon"><i class="bi bi-calendar-month"></i></div>
                    <div class="report-type-title">Monthly Report</div>
                    <div class="report-type-desc">View collections for a specific month</div>
                </a>
                <a href="?type=yearly&year=<?php echo date('Y'); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                   class="report-type-card <?php echo $report_type == 'yearly' ? 'active' : ''; ?>">
                    <div class="report-type-icon"><i class="bi bi-calendar"></i></div>
                    <div class="report-type-title">Yearly Report</div>
                    <div class="report-type-desc">View collections for a specific year</div>
                </a>
                <a href="?type=range&date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" 
                   class="report-type-card <?php echo $report_type == 'range' ? 'active' : ''; ?>">
                    <div class="report-type-icon"><i class="bi bi-calendar-range"></i></div>
                    <div class="report-type-title">Custom Range</div>
                    <div class="report-type-desc">View collections for a custom date range</div>
                </a>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="type" value="<?php echo $report_type; ?>">
                    
                    <div class="row g-3 align-items-end">
                        <?php if ($report_type == 'daily'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Select Date</label>
                            <input type="date" class="form-control" name="date" value="<?php echo $report_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <?php elseif ($report_type == 'monthly'): ?>
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="year">
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $report_year == $year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="month">
                                <?php foreach ($months as $num => $name): ?>
                                <option value="<?php echo $num; ?>" <?php echo $report_month == $num ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif ($report_type == 'yearly'): ?>
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="year">
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $report_year == $year ? 'selected' : ''; ?>><?php echo $year; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif ($report_type == 'range'): ?>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <?php endif; ?>
                        
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
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-2"></i>Generate
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="date-wise-report.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
                
                <div class="mt-3 text-center">
                    <span class="badge bg-primary p-2 fs-6"><?php echo ucfirst($report_type); ?> Report: <?php echo $date_label; ?></span>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-cash-stack"></i></div>
                    <div class="stat-label">COLLECTIONS</div>
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

            <!-- Daily Breakdown Chart (for multi-day reports) -->
            <?php if (($report_type == 'monthly' || $report_type == 'yearly' || $report_type == 'range') && count($daily_data) > 0): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <h5><i class="bi bi-bar-chart-line me-2"></i>Daily Breakdown</h5>
                </div>
                <div class="card-body">
                    <div class="mini-chart">
                        <?php 
                        $max_daily = 0;
                        foreach ($daily_data as $day) {
                            $max_daily = max($max_daily, $day['daily_total']);
                        }
                        foreach ($daily_data as $day): 
                            $height = $max_daily > 0 ? ($day['daily_total'] / $max_daily) * 60 : 4;
                        ?>
                        <div class="chart-bar" style="height: <?php echo max(4, $height); ?>px;" 
                             title="<?php echo date('d M', strtotime($day['paid_date'])); ?>: ₹<?php echo number_format($day['daily_total'], 0); ?> (<?php echo $day['daily_count']; ?>)"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-2 mt-3">
                        <?php 
                        // Show only first 7 days or all if less
                        $display_days = array_slice($daily_data, 0, 7);
                        foreach ($display_days as $day): 
                        ?>
                        <div class="col">
                            <div class="day-box">
                                <div class="day-name"><?php echo date('D', strtotime($day['paid_date'])); ?></div>
                                <div class="day-value">₹<?php echo number_format($day['daily_total']/1000, 1); ?>K</div>
                                <div class="day-count"><?php echo $day['daily_count']; ?> txns</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($daily_data) > 7): ?>
                        <div class="col-auto">
                            <div class="day-box">
                                <div class="day-name">+<?php echo count($daily_data) - 7; ?> more</div>
                                <div class="day-value">...</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Collections Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Collection Details</h5>
                            <p>Total: <?php echo $stats['total_collections']; ?> collections | ₹<?php echo number_format($stats['total_amount'], 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($collections && $collections->num_rows > 0): ?>
                        <div class="table-container">
                            <table id="collectionsTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Bill No</th>
                                        <th>Customer</th>
                                        <th>Agreement</th>
                                        <th>Loan</th>
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
                                    
                                    // Format date
                                    $paid_date = $col['paid_date'];
                                    $formatted_date = ($paid_date && $paid_date != '0000-00-00') ? date('d/m/y', strtotime($paid_date)) : 'N/A';
                                ?>
                                    <tr>
                                        <td>
                                            <span class="fw-semibold"><?php echo $formatted_date; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-dark"><?php echo htmlspecialchars($col['emi_bill_number'] ?: 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $col['customer_id']; ?>'">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div class="customer-name" title="<?php echo htmlspecialchars($col['customer_name']); ?>">
                                                    <?php echo htmlspecialchars(substr($col['customer_name'], 0, 15)); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars($col['agreement_number']); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars(substr($col['loan_name'], 0, 10)); ?></span>
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
                                                <?php echo ucfirst(str_replace('_', ' ', $col['payment_method'] ?: 'cash')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="receipt.php?emi_id=<?php echo $col['id']; ?>&customer_id=<?php echo $col['customer_id']; ?>" 
                                                   class="action-btn print" target="_blank" title="Print Receipt">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <a href="view-customer.php?id=<?php echo $col['customer_id']; ?>" 
                                                   class="action-btn view" title="View Customer">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button type="button" 
                                                        class="action-btn whatsapp" 
                                                        onclick="shareViaWhatsApp(<?php echo $col['id']; ?>, <?php echo $col['customer_id']; ?>, '<?php echo $col['customer_number']; ?>')"
                                                        title="Share via WhatsApp">
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
                            <i class="bi bi-calendar-x text-muted"></i>
                            <h6 class="mt-3 text-muted">No collections found for this period</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Methods Summary -->
            <?php if (!empty($payment_methods)): ?>
            <div class="dashboard-card">
                <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e);">
                    <h5><i class="bi bi-credit-card me-2"></i>Payment Methods</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($payment_methods as $method): ?>
                        <div class="col-md-6">
                            <div class="method-item">
                                <div>
                                    <span class="method-name"><?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?></span>
                                    <div class="method-count"><?php echo $method['method_count']; ?> transactions</div>
                                </div>
                                <div class="method-stats">
                                    <div class="amount-success fw-bold">₹<?php echo number_format($method['method_total'], 0); ?></div>
                                    <div class="method-count">
                                        <?php echo round(($method['method_total'] / max($stats['total_amount'], 1)) * 100, 1); ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
    <?php if ($collections && $collections->num_rows > 0): ?>
    $('#collectionsTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "_MENU_",
            info: "Showing _START_-_END_ of _TOTAL_ entries",
            paginate: {
                first: '<i class="bi bi-chevron-double-left"></i>',
                previous: '<i class="bi bi-chevron-left"></i>',
                next: '<i class="bi bi-chevron-right"></i>',
                last: '<i class="bi bi-chevron-double-right"></i>'
            }
        },
        columnDefs: [
            { orderable: false, targets: [10] }
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