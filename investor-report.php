<?php
require_once 'includes/auth.php';
$currentPage = 'investor-report';
$pageTitle = 'Investor Report';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to view reports
if ($user_role != 'admin' && $user_role != 'accountant') {
    $error = "You don't have permission to view investor reports.";
}

$message = '';
$error = isset($error) ? $error : '';

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';

// Get finance companies for filter
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Build query conditions
$conditions = [];

if ($user_role != 'admin') {
    $conditions[] = "i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $conditions[] = "i.finance_id = $finance_filter";
}

if ($status_filter != 'all') {
    $conditions[] = "i.status = '$status_filter'";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get summary statistics
$summary_query = "SELECT 
                  COUNT(*) as total_investors,
                  SUM(CASE WHEN i.status = 'active' THEN 1 ELSE 0 END) as active_investors,
                  SUM(CASE WHEN i.status = 'closed' THEN 1 ELSE 0 END) as closed_investors,
                  COALESCE(SUM(i.investment_amount), 0) as total_investment,
                  COALESCE(SUM(CASE WHEN i.status = 'active' THEN i.investment_amount ELSE 0 END), 0) as active_investment,
                  COALESCE(SUM(i.total_return_paid), 0) as total_returns_paid,
                  AVG(i.interest_rate) as avg_interest_rate,
                  MAX(i.investment_amount) as max_investment,
                  MIN(i.investment_amount) as min_investment
                  FROM investors i
                  $where_clause";

$summary_result = $conn->query($summary_query);
$summary = $summary_result ? $summary_result->fetch_assoc() : [];

// Get return type breakdown
$return_type_query = "SELECT 
                      i.return_type,
                      COUNT(*) as count,
                      COALESCE(SUM(i.investment_amount), 0) as total_amount,
                      AVG(i.interest_rate) as avg_rate,
                      COALESCE(SUM(i.total_return_paid), 0) as returns_paid
                      FROM investors i
                      $where_clause
                      GROUP BY i.return_type
                      ORDER BY total_amount DESC";

$return_type_result = $conn->query($return_type_query);

// Get monthly investment summary
$monthly_query = "SELECT 
                  DATE_FORMAT(i.investment_date, '%Y-%m') as month,
                  COUNT(*) as new_investors,
                  COALESCE(SUM(i.investment_amount), 0) as amount_invested
                  FROM investors i
                  $where_clause
                  GROUP BY DATE_FORMAT(i.investment_date, '%Y-%m')
                  ORDER BY month DESC
                  LIMIT 12";

$monthly_result = $conn->query($monthly_query);
$monthly_data = [];
if ($monthly_result && $monthly_result->num_rows > 0) {
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[] = $row;
    }
}

// Get returns summary for date range
$returns_query = "SELECT 
                  ir.return_date,
                  ir.return_amount,
                  ir.return_type,
                  ir.payment_method,
                  i.investor_name,
                  i.investment_amount,
                  f.finance_name
                  FROM investor_returns ir
                  JOIN investors i ON ir.investor_id = i.id
                  JOIN finance f ON i.finance_id = f.id
                  WHERE ir.return_date BETWEEN '$from_date' AND '$to_date'";

if ($user_role != 'admin') {
    $returns_query .= " AND i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $returns_query .= " AND i.finance_id = $finance_filter";
}

if ($status_filter != 'all') {
    $returns_query .= " AND i.status = '$status_filter'";
}

$returns_query .= " ORDER BY ir.return_date DESC";

$returns_result = $conn->query($returns_query);

// Get returns statistics
$returns_stats_query = "SELECT 
                        COUNT(*) as total_returns,
                        COALESCE(SUM(ir.return_amount), 0) as total_amount,
                        AVG(ir.return_amount) as avg_return,
                        COUNT(DISTINCT ir.investor_id) as investors_with_returns
                        FROM investor_returns ir
                        JOIN investors i ON ir.investor_id = i.id
                        WHERE ir.return_date BETWEEN '$from_date' AND '$to_date'";

if ($user_role != 'admin') {
    $returns_stats_query .= " AND i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $returns_stats_query .= " AND i.finance_id = $finance_filter";
}

$returns_stats_result = $conn->query($returns_stats_query);
$returns_stats = $returns_stats_result ? $returns_stats_result->fetch_assoc() : [];

// Get top investors
$top_investors_query = "SELECT 
                        i.id,
                        i.investor_name,
                        i.investment_amount,
                        i.interest_rate,
                        i.return_type,
                        i.total_return_paid,
                        i.status,
                        f.finance_name,
                        (i.investment_amount * i.interest_rate / 100) as annual_interest
                        FROM investors i
                        JOIN finance f ON i.finance_id = f.id
                        $where_clause
                        ORDER BY i.investment_amount DESC
                        LIMIT 10";

$top_investors = $conn->query($top_investors_query);

// Get maturity summary
$maturity_query = "SELECT 
                   COUNT(*) as maturing_count,
                   COALESCE(SUM(i.investment_amount), 0) as maturing_amount
                   FROM investors i
                   WHERE i.maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                   AND i.status = 'active'";

if ($user_role != 'admin') {
    $maturity_query .= " AND i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $maturity_query .= " AND i.finance_id = $finance_filter";
}

$maturity_result = $conn->query($maturity_query);
$maturity = $maturity_result ? $maturity_result->fetch_assoc() : [];

// Get overdue summary
$overdue_query = "SELECT 
                  COUNT(*) as overdue_count,
                  COALESCE(SUM(i.investment_amount), 0) as overdue_amount
                  FROM investors i
                  WHERE i.maturity_date < CURDATE()
                  AND i.status = 'active'";

if ($user_role != 'admin') {
    $overdue_query .= " AND i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $overdue_query .= " AND i.finance_id = $finance_filter";
}

$overdue_result = $conn->query($overdue_query);
$overdue = $overdue_result ? $overdue_result->fetch_assoc() : [];

// Get finance-wise summary for admin
$finance_wise = null;
if ($user_role == 'admin') {
    $finance_wise_query = "SELECT 
                           f.id,
                           f.finance_name,
                           COUNT(i.id) as investor_count,
                           COALESCE(SUM(i.investment_amount), 0) as total_investment,
                           COALESCE(SUM(i.total_return_paid), 0) as total_returns
                           FROM finance f
                           LEFT JOIN investors i ON f.id = i.finance_id
                           GROUP BY f.id, f.finance_name
                           ORDER BY total_investment DESC";
    $finance_wise = $conn->query($finance_wise_query);
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
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            width: 100%;
        }
        
        .page-header h4 {
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-size: clamp(1.5rem, 5vw, 2rem);
            word-wrap: break-word;
        }
        
        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: clamp(0.9rem, 3vw, 1rem);
            word-wrap: break-word;
        }
        
        .page-header .btn-light {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.5rem 1.5rem;
            font-weight: 500;
            border-radius: 2rem;
            backdrop-filter: blur(10px);
            transition: all 0.2s;
            font-size: clamp(0.9rem, 3vw, 1rem);
            white-space: nowrap;
        }
        
        .page-header .btn-light:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }
        
        /* Stat Cards */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }
        
        .stat-card.primary::before { background: var(--primary); }
        .stat-card.success::before { background: var(--success); }
        .stat-card.warning::before { background: var(--warning); }
        .stat-card.info::before { background: var(--info); }
        
        .stat-label {
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .stat-value {
            font-size: clamp(1.5rem, 5vw, 2rem);
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .stat-footer {
            margin-top: 0.5rem;
            font-size: clamp(0.7rem, 2.2vw, 0.8rem);
            color: var(--text-muted);
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
        
        .stat-icon.primary { background: var(--primary-bg); color: var(--primary); }
        .stat-icon.success { background: var(--success-bg); color: var(--success); }
        .stat-icon.warning { background: var(--warning-bg); color: var(--warning); }
        .stat-icon.info { background: var(--info-bg); color: var(--info); }
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .filter-card .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: clamp(0.85rem, 2.8vw, 0.95rem);
        }
        
        .filter-card .form-control,
        .filter-card .form-select {
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 0.6rem 1rem;
            transition: all 0.2s ease;
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .filter-card .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--info));
            border: none;
            border-radius: 12px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .filter-card .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        /* Report Cards */
        .report-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
            box-shadow: var(--shadow);
            transition: all 0.2s ease;
        }
        
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            border-color: var(--primary);
        }
        
        .report-card h6 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            font-size: clamp(1rem, 3vw, 1.1rem);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .report-card h6 i {
            color: var(--primary);
            font-size: clamp(1.1rem, 3.5vw, 1.2rem);
        }
        
        /* Report Table */
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .report-table th {
            text-align: left;
            padding: 0.75rem;
            background: #f8f9fa;
            color: var(--text-muted);
            font-weight: 600;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        
        .report-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: clamp(0.8rem, 2.8vw, 0.9rem);
        }
        
        .report-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* Badges */
        .badge-return {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: clamp(0.65rem, 2.2vw, 0.75rem);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-return.monthly {
            background: var(--primary-bg);
            color: var(--primary);
        }
        
        .badge-return.quarterly {
            background: var(--info-bg);
            color: var(--info);
        }
        
        .badge-return.yearly {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .badge-return.maturity {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .badge-status {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: clamp(0.65rem, 2.2vw, 0.75rem);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-status.active {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .badge-status.closed {
            background: var(--secondary);
            color: white;
        }
        
        /* Chart Bars */
        .chart-bar-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .chart-label {
            min-width: 80px;
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .chart-bar-wrapper {
            flex: 1;
            height: 30px;
            background: #f8f9fa;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .chart-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--info));
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 0.5rem;
            color: white;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .chart-value {
            min-width: 100px;
            text-align: right;
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
            font-weight: 600;
            color: var(--primary);
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
        }
        
        .view-toggle .btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Summary Items */
        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--border-color);
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            color: var(--text-muted);
            font-size: clamp(0.8rem, 2.7vw, 0.9rem);
        }
        
        .summary-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: clamp(0.9rem, 3vw, 1rem);
        }
        
        /* Print Button */
        .btn-print {
            background: var(--card-bg);
            color: var(--text-primary);
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-print:hover {
            background: #e9ecef;
            border-color: #dee2e6;
        }
        
        .btn-export {
            background: var(--success);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-export:hover {
            background: #0d9488;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
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
            
            .stat-row {
                grid-template-columns: repeat(2, 1fr);
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
            
            .stat-row {
                gap: 0.5rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .filter-card .row {
                --bs-gutter-y: 0.5rem;
            }
            
            .chart-bar-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .chart-bar-wrapper {
                width: 100%;
            }
            
            .chart-value {
                text-align: left;
            }
            
            .view-toggle {
                justify-content: center;
            }
            
            .page-header .d-flex {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 576px) {
            .page-content {
                padding: 0.75rem;
            }
            
            .page-header {
                padding: 1rem;
            }
            
            .page-header h4 {
                font-size: 1.2rem;
            }
            
            .stat-card {
                padding: 0.75rem;
            }
            
            .stat-value {
                font-size: 1.1rem;
            }
            
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1.2rem;
            }
            
            .report-card {
                padding: 1rem;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .stat-card,
        .report-card {
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
                    <div class="overflow-hidden" style="max-width: 100%;">
                        <h4 class="text-truncate-custom"><i class="bi bi-graph-up-arrow me-2"></i>Investor Report</h4>
                        <p class="text-truncate-custom">Comprehensive investor analytics and performance metrics</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <button onclick="window.print()" class="btn btn-light">
                            <i class="bi bi-printer me-2"></i>Print
                        </button>
                        <a href="investors.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Investors
                        </a>
                        <span class="badge bg-white text-primary">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <span class="break-word"><?php echo $error; ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="from_date" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="to_date" value="<?php echo $to_date; ?>">
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
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-2"></i>Generate Report
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="stat-row">
                <div class="stat-card primary">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-people"></i>
                                Total Investors
                            </div>
                            <div class="stat-value"><?php echo number_format($summary['total_investors'] ?? 0); ?></div>
                            <div class="stat-footer">
                                Active: <?php echo $summary['active_investors'] ?? 0; ?> | 
                                Closed: <?php echo $summary['closed_investors'] ?? 0; ?>
                            </div>
                        </div>
                        <div class="stat-icon primary">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-cash-stack"></i>
                                Total Investment
                            </div>
                            <div class="stat-value">₹<?php echo number_format($summary['total_investment'] ?? 0, 2); ?></div>
                            <div class="stat-footer">
                                Active: ₹<?php echo number_format($summary['active_investment'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <div class="stat-icon success">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-graph-up-arrow"></i>
                                Returns Paid
                            </div>
                            <div class="stat-value">₹<?php echo number_format($summary['total_returns_paid'] ?? 0, 2); ?></div>
                            <div class="stat-footer">
                                Avg Rate: <?php echo number_format($summary['avg_interest_rate'] ?? 0, 2); ?>%
                            </div>
                        </div>
                        <div class="stat-icon warning">
                            <i class="bi bi-percent"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-clock-history"></i>
                                Investment Range
                            </div>
                            <div class="stat-value">₹<?php echo number_format($summary['min_investment'] ?? 0, 2); ?></div>
                            <div class="stat-footer">
                                Max: ₹<?php echo number_format($summary['max_investment'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <div class="stat-icon info">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Returns Summary for Selected Period -->
            <div class="stat-row">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-cash"></i>
                                Returns (<?php echo date('d M', strtotime($from_date)); ?> - <?php echo date('d M', strtotime($to_date)); ?>)
                            </div>
                            <div class="stat-value">₹<?php echo number_format($returns_stats['total_amount'] ?? 0, 2); ?></div>
                            <div class="stat-footer">
                                <?php echo $returns_stats['total_returns'] ?? 0; ?> returns | 
                                <?php echo $returns_stats['investors_with_returns'] ?? 0; ?> investors
                            </div>
                        </div>
                        <div class="stat-icon" style="background: var(--info-bg); color: var(--info);">
                            <i class="bi bi-cash"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-calendar-check"></i>
                                Maturing (Next 30 Days)
                            </div>
                            <div class="stat-value"><?php echo number_format($maturity['maturing_count'] ?? 0); ?></div>
                            <div class="stat-footer">
                                Amount: ₹<?php echo number_format($maturity['maturing_amount'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <div class="stat-icon" style="background: var(--warning-bg); color: var(--warning);">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-exclamation-triangle"></i>
                                Overdue
                            </div>
                            <div class="stat-value text-danger"><?php echo number_format($overdue['overdue_count'] ?? 0); ?></div>
                            <div class="stat-footer">
                                Amount: ₹<?php echo number_format($overdue['overdue_amount'] ?? 0, 2); ?>
                            </div>
                        </div>
                        <div class="stat-icon" style="background: var(--danger-bg); color: var(--danger);">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-building"></i>
                                Finance Companies
                            </div>
                            <div class="stat-value"><?php echo $finance_wise ? $finance_wise->num_rows : 1; ?></div>
                            <div class="stat-footer">
                                Active investors across companies
                            </div>
                        </div>
                        <div class="stat-icon" style="background: var(--primary-bg); color: var(--primary);">
                            <i class="bi bi-building"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Return Type Breakdown -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="report-card">
                        <h6><i class="bi bi-pie-chart"></i>Investment by Return Type</h6>
                        <?php if ($return_type_result && $return_type_result->num_rows > 0): ?>
                            <?php 
                            $max_amount = 0;
                            $return_types = [];
                            while ($row = $return_type_result->fetch_assoc()) {
                                $return_types[] = $row;
                                $max_amount = max($max_amount, $row['total_amount']);
                            }
                            foreach ($return_types as $type): 
                                $percentage = $max_amount > 0 ? ($type['total_amount'] / $max_amount) * 100 : 0;
                                $type_class = $type['return_type'] ?: 'maturity';
                            ?>
                            <div class="summary-item">
                                <div>
                                    <span class="badge-return <?php echo $type_class; ?> me-2">
                                        <?php echo ucfirst($type['return_type'] ?: 'Maturity'); ?>
                                    </span>
                                    <span class="text-muted">(<?php echo $type['count']; ?> investors)</span>
                                </div>
                                <span class="summary-value">₹<?php echo number_format($type['total_amount'], 2); ?></span>
                            </div>
                            <div class="chart-bar-container mb-3">
                                <div class="chart-bar-wrapper">
                                    <div class="chart-bar" style="width: <?php echo $percentage; ?>%;">
                                        <?php if ($percentage > 20): ?>
                                            ₹<?php echo number_format($type['total_amount'] / 100000, 1); ?>L
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="chart-value"><?php echo number_format($percentage, 1); ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="report-card">
                        <h6><i class="bi bi-calendar-month"></i>Monthly Investment Trend</h6>
                        <?php if (!empty($monthly_data)): ?>
                            <?php 
                            $max_amount = 0;
                            foreach ($monthly_data as $month) {
                                $max_amount = max($max_amount, $month['amount_invested']);
                            }
                            foreach ($monthly_data as $month): 
                                $percentage = $max_amount > 0 ? ($month['amount_invested'] / $max_amount) * 100 : 0;
                                $month_name = date('M Y', strtotime($month['month'] . '-01'));
                            ?>
                            <div class="summary-item">
                                <span class="summary-label"><?php echo $month_name; ?></span>
                                <span class="summary-value">₹<?php echo number_format($month['amount_invested'], 2); ?></span>
                            </div>
                            <div class="chart-bar-container mb-3">
                                <span class="chart-label"><?php echo $month['new_investors']; ?> investors</span>
                                <div class="chart-bar-wrapper">
                                    <div class="chart-bar" style="width: <?php echo $percentage; ?>%;">
                                        <?php if ($percentage > 20): ?>
                                            ₹<?php echo number_format($month['amount_invested'] / 100000, 1); ?>L
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">No monthly data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Finance-wise Summary (Admin Only) -->
            <?php if ($user_role == 'admin' && $finance_wise && $finance_wise->num_rows > 0): ?>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="report-card">
                        <h6><i class="bi bi-building"></i>Finance-wise Investment Summary</h6>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Finance Company</th>
                                        <th>Investors</th>
                                        <th>Total Investment</th>
                                        <th>Returns Paid</th>
                                        <th>Avg per Investor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $finance_wise->data_seek(0);
                                    while ($fw = $finance_wise->fetch_assoc()): 
                                    ?>
                                    <tr>
                                        <td><span class="fw-semibold"><?php echo htmlspecialchars($fw['finance_name']); ?></span></td>
                                        <td><?php echo number_format($fw['investor_count']); ?></td>
                                        <td class="fw-semibold text-primary">₹<?php echo number_format($fw['total_investment'], 2); ?></td>
                                        <td class="text-success">₹<?php echo number_format($fw['total_returns'], 2); ?></td>
                                        <td>₹<?php echo $fw['investor_count'] > 0 ? number_format($fw['total_investment'] / $fw['investor_count'], 2) : 0; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Returns Summary Table -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="report-card">
                        <h6><i class="bi bi-clock-history"></i>Returns History (<?php echo date('d M Y', strtotime($from_date)); ?> - <?php echo date('d M Y', strtotime($to_date)); ?>)</h6>
                        <div class="table-responsive">
                            <table class="report-table" id="returnsTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Investor</th>
                                        <th>Amount</th>
                                        <th>Type</th>
                                        <th>Payment Method</th>
                                        <th>Finance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($returns_result && $returns_result->num_rows > 0): ?>
                                        <?php while ($return = $returns_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="text-nowrap"><?php echo date('d M Y', strtotime($return['return_date'])); ?></span></td>
                                            <td><?php echo htmlspecialchars($return['investor_name']); ?></td>
                                            <td class="fw-semibold text-success">₹<?php echo number_format($return['return_amount'], 2); ?></td>
                                            <td>
                                                <span class="badge-return <?php echo $return['return_type'] ?: 'interest'; ?>">
                                                    <?php echo ucfirst($return['return_type'] ?: 'interest'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $return['payment_method'] ?: 'cash')); ?></td>
                                            <td><?php echo htmlspecialchars($return['finance_name']); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <i class="bi bi-inbox" style="font-size: 2rem; color: #dee2e6;"></i>
                                                <p class="mt-2 text-muted">No returns found for the selected period</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($returns_result && $returns_result->num_rows > 0): ?>
                        <div class="text-muted small mt-3">
                            Total: <?php echo $returns_result->num_rows; ?> returns | 
                            Amount: ₹<?php echo number_format($returns_stats['total_amount'] ?? 0, 2); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Investors -->
            <div class="row g-3">
                <div class="col-12">
                    <div class="report-card">
                        <h6><i class="bi bi-trophy"></i>Top Investors by Investment Amount</h6>
                        <div class="table-responsive">
                            <table class="report-table" id="topInvestorsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Investor Name</th>
                                        <th>Finance</th>
                                        <th>Investment</th>
                                        <th>Rate</th>
                                        <th>Return Type</th>
                                        <th>Returns Paid</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($top_investors && $top_investors->num_rows > 0): ?>
                                        <?php 
                                        $rank = 1;
                                        while ($inv = $top_investors->fetch_assoc()): 
                                        ?>
                                        <tr>
                                            <td><span class="fw-semibold">#<?php echo $rank++; ?></span></td>
                                            <td>
                                                <a href="view-investor.php?id=<?php echo $inv['id']; ?>" class="text-decoration-none fw-semibold">
                                                    <?php echo htmlspecialchars($inv['investor_name']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($inv['finance_name']); ?></td>
                                            <td class="fw-semibold text-primary">₹<?php echo number_format($inv['investment_amount'], 2); ?></td>
                                            <td><?php echo $inv['interest_rate']; ?>%</td>
                                            <td>
                                                <span class="badge-return <?php echo $inv['return_type'] ?: 'maturity'; ?>">
                                                    <?php echo ucfirst($inv['return_type'] ?: 'Maturity'); ?>
                                                </span>
                                            </td>
                                            <td class="text-success">₹<?php echo number_format($inv['total_return_paid'], 2); ?></td>
                                            <td>
                                                <span class="badge-status <?php echo $inv['status']; ?>">
                                                    <?php echo ucfirst($inv['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <i class="bi bi-inbox" style="font-size: 2rem; color: #dee2e6;"></i>
                                                <p class="mt-2 text-muted">No investors found</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Options -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="d-flex gap-2 justify-content-end">
                        <button class="btn btn-print" onclick="window.print()">
                            <i class="bi bi-printer me-2"></i>Print Report
                        </button>
                        <a href="export-investor-report.php?from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&finance_id=<?php echo $finance_filter; ?>&status=<?php echo $status_filter; ?>" class="btn btn-export">
                            <i class="bi bi-file-excel me-2"></i>Export to Excel
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize DataTables
        if ($('#returnsTable tbody tr').length > 1) {
            $('#returnsTable').DataTable({
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ returns",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                }
            });
        }

        if ($('#topInvestorsTable tbody tr').length > 1) {
            $('#topInvestorsTable').DataTable({
                pageLength: 10,
                order: [[3, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ investors",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                }
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
    });
</script>

<style media="print">
    @page {
        size: A4 landscape;
        margin: 1cm;
    }
    body {
        background-color: white;
    }
    .sidebar, .topbar, .footer, .btn, .filter-card, .view-toggle, .page-header .btn-light {
        display: none !important;
    }
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
    }
    .page-content {
        padding: 0 !important;
    }
    .stat-card, .report-card {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    .page-header {
        background: white !important;
        color: black !important;
        border: 1px solid #ddd !important;
        box-shadow: none !important;
    }
    .page-header h4, .page-header p {
        color: black !important;
    }
    .badge-return, .badge-status {
        border: 1px solid #ddd !important;
        background: #f5f5f5 !important;
        color: black !important;
    }
</style>
</body>
</html>