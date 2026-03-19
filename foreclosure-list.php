<?php
require_once 'includes/auth.php';
$currentPage = 'foreclosure-list';
$pageTitle = 'Foreclosure List';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to view foreclosures
if ($user_role != 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header("Location: index.php");
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Build query for foreclosures
$query = "
    SELECT 
        f.id,
        f.customer_id,
        f.emi_ids,
        f.foreclosure_date,
        f.principal_amount,
        f.principal_remaining,
        f.interest_amount,
        f.interest_remaining,
        f.overdue_charges,
        f.foreclosure_charge,
        f.total_amount,
        f.payment_date,
        f.bill_number,
        f.remarks,
        f.foreclosed_by,
        f.created_at,
        f.paid_amount,
        f.payment_method,
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f2.finance_name,
        u.username as foreclosed_by_name,
        u.full_name as foreclosed_by_fullname,
        (SELECT COUNT(*) FROM emi_schedule WHERE foreclosure_id = f.id) as emi_count
    FROM foreclosures f
    JOIN customers c ON f.customer_id = c.id
    JOIN finance f2 ON c.finance_id = f2.id
    LEFT JOIN users u ON f.foreclosed_by = u.id
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
    $query .= " AND (c.customer_name LIKE ? OR c.customer_number LIKE ? OR c.agreement_number LIKE ? OR f.bill_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if (!empty($date_from)) {
    $query .= " AND f.foreclosure_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND f.foreclosure_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $query .= " AND c.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$query .= " ORDER BY f.foreclosure_date DESC, f.id DESC";

$foreclosures = null;
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $foreclosures = $stmt->get_result();
    $stmt->close();
}

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_foreclosures,
        COALESCE(SUM(f.total_amount), 0) as total_amount,
        COALESCE(SUM(f.principal_amount), 0) as total_principal,
        COALESCE(SUM(f.interest_amount), 0) as total_interest,
        COALESCE(SUM(f.overdue_charges), 0) as total_overdue,
        COALESCE(SUM(f.foreclosure_charge), 0) as total_charges,
        COALESCE(SUM(f.paid_amount), 0) as total_paid,
        COUNT(DISTINCT f.customer_id) as unique_customers,
        MIN(f.foreclosure_date) as first_foreclosure,
        MAX(f.foreclosure_date) as last_foreclosure,
        SUM(CASE WHEN f.paid_amount >= f.total_amount THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN f.paid_amount < f.total_amount AND f.paid_amount > 0 THEN 1 ELSE 0 END) as partially_paid,
        SUM(CASE WHEN f.paid_amount = 0 THEN 1 ELSE 0 END) as unpaid
    FROM foreclosures f
    JOIN customers c ON f.customer_id = c.id
    WHERE 1=1
";

if ($user_role != 'admin') {
    $stats_query .= " AND c.finance_id = $finance_id";
}

if (!empty($date_from)) {
    $stats_query .= " AND f.foreclosure_date >= '$date_from'";
}
if (!empty($date_to)) {
    $stats_query .= " AND f.foreclosure_date <= '$date_to'";
}
if ($finance_filter > 0 && $user_role == 'admin') {
    $stats_query .= " AND c.finance_id = $finance_filter";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats = array_merge([
    'total_foreclosures' => 0,
    'total_amount' => 0,
    'total_principal' => 0,
    'total_interest' => 0,
    'total_overdue' => 0,
    'total_charges' => 0,
    'total_paid' => 0,
    'unique_customers' => 0,
    'first_foreclosure' => null,
    'last_foreclosure' => null,
    'fully_paid' => 0,
    'partially_paid' => 0,
    'unpaid' => 0
], $stats);

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get monthly foreclosure summary for chart
$monthly_query = "
    SELECT 
        DATE_FORMAT(f.foreclosure_date, '%Y-%m') as month,
        COUNT(*) as foreclosure_count,
        COALESCE(SUM(f.total_amount), 0) as month_total
    FROM foreclosures f
    JOIN customers c ON f.customer_id = c.id
    WHERE 1=1
";

if ($user_role != 'admin') {
    $monthly_query .= " AND c.finance_id = $finance_id";
}

if (!empty($date_from)) {
    $monthly_query .= " AND f.foreclosure_date >= '$date_from'";
}
if (!empty($date_to)) {
    $monthly_query .= " AND f.foreclosure_date <= '$date_to'";
}
if ($finance_filter > 0 && $user_role == 'admin') {
    $monthly_query .= " AND c.finance_id = $finance_filter";
}

$monthly_query .= " GROUP BY DATE_FORMAT(f.foreclosure_date, '%Y-%m') ORDER BY month DESC LIMIT 6";

$monthly_result = $conn->query($monthly_query);
$monthly_data = [];
if ($monthly_result) {
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[] = $row;
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
            --foreclosed-color: #b91c1c;
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
            background: linear-gradient(135deg, var(--foreclosed-color), #991b1b);
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
            color: var(--foreclosed-color);
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
            border-color: var(--foreclosed-color);
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
        .stat-icon.foreclosed { color: var(--foreclosed-color); }
        .stat-icon.info { color: var(--info); }
        .stat-icon.purple { color: #8b5cf6; }
        
        /* Status Grid */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .status-card {
            background: var(--card-bg);
            border-left: 4px solid;
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }
        
        .status-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .status-card.fully {
            border-left-color: var(--success);
        }
        
        .status-card.partial {
            border-left-color: var(--warning);
        }
        
        .status-card.unpaid {
            border-left-color: var(--danger);
        }
        
        .status-title {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .status-value {
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .status-desc {
            font-size: 0.65rem;
            color: var(--text-muted);
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
            padding: 0.6rem 1rem;
            background: linear-gradient(135deg, var(--foreclosed-color), #991b1b);
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
        
        /* Table Container */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1400px;
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
        
        .table tbody tr.fully-paid {
            background: #f0fdf4;
        }
        
        .table tbody tr.partial-paid {
            background: #fffbeb;
        }
        
        .table tbody tr.unpaid {
            background: #fef2f2;
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
            background: linear-gradient(135deg, var(--foreclosed-color), #991b1b);
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
            max-width: 80px;
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
        .amount-danger { color: var(--danger); font-weight: 600; font-size: 0.7rem; }
        .amount-foreclosed { color: var(--foreclosed-color); font-weight: 600; font-size: 0.7rem; }
        
        /* Badges - Compact */
        .badge-status {
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-status.fully {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .badge-status.partial {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .badge-status.unpaid {
            background: #fee2e2;
            color: var(--danger);
        }
        
        .badge-finance {
            background: #fee2e2;
            color: var(--foreclosed-color);
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
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
        
        .action-btn.view {
            background: var(--info);
        }
        
        .action-btn.print {
            background: var(--danger);
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
            background: linear-gradient(135deg, var(--foreclosed-color), #991b1b);
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            transition: height 0.3s ease;
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
            .status-grid {
                grid-template-columns: 1fr;
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
                        <h4><i class="bi bi-file-earmark-x me-2"></i>Foreclosure List</h4>
                        <p>View all foreclosed loans</p>
                    </div>
                    <span class="badge bg-white text-danger py-2 px-3">
                        <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                    </span>
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
                    <div class="stat-icon foreclosed"><i class="bi bi-file-earmark-x"></i></div>
                    <div class="stat-label">TOTAL FORECLOSURES</div>
                    <div class="stat-value"><?php echo number_format($stats['total_foreclosures']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon foreclosed"><i class="bi bi-currency-rupee"></i></div>
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
                    <div class="stat-icon warning"><i class="bi bi-exclamation-circle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_overdue'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                    <div class="stat-label">CUSTOMERS</div>
                    <div class="stat-value"><?php echo number_format($stats['unique_customers']); ?></div>
                </div>
            </div>

            <!-- Status Grid -->
            <div class="status-grid">
                <div class="status-card fully">
                    <div class="status-title">FULLY PAID</div>
                    <div class="status-value"><?php echo number_format($stats['fully_paid']); ?></div>
                    <div class="status-desc">₹<?php echo number_format($stats['total_paid'], 0); ?> collected</div>
                </div>
                <div class="status-card partial">
                    <div class="status-title">PARTIALLY PAID</div>
                    <div class="status-value"><?php echo number_format($stats['partially_paid']); ?></div>
                    <div class="status-desc">Pending settlement</div>
                </div>
                <div class="status-card unpaid">
                    <div class="status-title">UNPAID</div>
                    <div class="status-value"><?php echo number_format($stats['unpaid']); ?></div>
                    <div class="status-desc">Awaiting payment</div>
                </div>
            </div>

            <!-- Additional Stats Row -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">FIRST</div>
                    <div class="stat-value"><?php echo $stats['first_foreclosure'] && $stats['first_foreclosure'] != '0000-00-00' ? date('d/m', strtotime($stats['first_foreclosure'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">LATEST</div>
                    <div class="stat-value"><?php echo $stats['last_foreclosure'] && $stats['last_foreclosure'] != '0000-00-00' ? date('d/m', strtotime($stats['last_foreclosure'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="bi bi-cash"></i></div>
                    <div class="stat-label">CHARGES</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_charges'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-calculator"></i></div>
                    <div class="stat-label">AVG AMOUNT</div>
                    <div class="stat-value">₹<?php echo $stats['total_foreclosures'] > 0 ? number_format($stats['total_amount'] / $stats['total_foreclosures'], 0) : 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="bi bi-hourglass"></i></div>
                    <div class="stat-label">PENDING</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_amount'] - $stats['total_paid'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-file-earmark"></i></div>
                    <div class="stat-label">TOTAL EMIs</div>
                    <div class="stat-value"><?php echo $foreclosures ? $foreclosures->num_rows : 0; ?></div>
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
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
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
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <a href="foreclosure-list.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                        </div>
                    </div>
                </form>
                
                <?php if ($stats['first_foreclosure'] && $stats['last_foreclosure'] && $stats['first_foreclosure'] != '0000-00-00'): ?>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Showing foreclosures from <?php echo date('d M Y', strtotime($stats['first_foreclosure'])); ?> to <?php echo date('d M Y', strtotime($stats['last_foreclosure'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Monthly Chart -->
            <?php if (!empty($monthly_data)): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <h5><i class="bi bi-bar-chart-line me-2"></i>Monthly Foreclosures</h5>
                </div>
                <div class="card-body p-2">
                    <div class="mini-chart">
                        <?php 
                        $max_monthly = 0;
                        foreach ($monthly_data as $month) {
                            $max_monthly = max($max_monthly, $month['month_total']);
                        }
                        $monthly_data = array_reverse($monthly_data);
                        foreach ($monthly_data as $month): 
                            $height = $max_monthly > 0 ? ($month['month_total'] / $max_monthly) * 60 : 4;
                        ?>
                        <div class="chart-bar" style="height: <?php echo max(4, $height); ?>px;" 
                             title="<?php echo $month['month']; ?>: ₹<?php echo number_format($month['month_total'], 0); ?> (<?php echo $month['foreclosure_count']; ?>)"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-2 mt-2">
                        <?php foreach (array_reverse($monthly_data) as $month): ?>
                        <div class="col-2">
                            <div class="stat-box p-1">
                                <div class="label"><?php echo date('M', strtotime($month['month'] . '-01')); ?></div>
                                <div class="value small">₹<?php echo number_format($month['month_total']/1000, 1); ?>K</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Foreclosures Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Foreclosure Details</h5>
                            <p>Total: <?php echo $stats['total_foreclosures']; ?> foreclosures | ₹<?php echo number_format($stats['total_amount'], 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($foreclosures && $foreclosures->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="foreclosureTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Bill No</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Agreement</th>
                                        <th>EMIs</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Overdue</th>
                                        <th>Charge</th>
                                        <th>Total</th>
                                        <th>Paid</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($row = $foreclosures->fetch_assoc()): 
                                    // Determine status
                                    if ($row['paid_amount'] >= $row['total_amount']) {
                                        $status_class = 'fully';
                                        $status_text = 'Fully Paid';
                                        $row_class = 'fully-paid';
                                    } elseif ($row['paid_amount'] > 0) {
                                        $status_class = 'partial';
                                        $status_text = 'Partial';
                                        $row_class = 'partial-paid';
                                    } else {
                                        $status_class = 'unpaid';
                                        $status_text = 'Unpaid';
                                        $row_class = 'unpaid';
                                    }
                                    
                                    // Get initials
                                    $name_parts = explode(' ', $row['customer_name'] ?? '');
                                    $initials = '';
                                    foreach ($name_parts as $part) {
                                        $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    $initials = substr($initials, 0, 2) ?: 'U';
                                    
                                    // Format date
                                    $foreclosure_date = $row['foreclosure_date'];
                                    $formatted_date = ($foreclosure_date && $foreclosure_date != '0000-00-00') ? date('d/m/y', strtotime($foreclosure_date)) : 'N/A';
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <span class="badge bg-dark"><?php echo htmlspecialchars($row['bill_number'] ?: 'N/A'); ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo $formatted_date; ?></span>
                                        </td>
                                        <td>
                                            <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $row['customer_id']; ?>'">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div class="customer-name" title="<?php echo htmlspecialchars($row['customer_name']); ?>">
                                                    <?php echo htmlspecialchars(substr($row['customer_name'], 0, 12)); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars(substr($row['agreement_number'], 0, 6)); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-dark"><?php echo $row['emi_count']; ?></span>
                                        </td>
                                        <td class="amount-primary">₹<?php echo number_format($row['principal_amount'], 0); ?></td>
                                        <td class="amount-primary">₹<?php echo number_format($row['interest_amount'], 0); ?></td>
                                        <td class="amount-warning">₹<?php echo number_format($row['overdue_charges'], 0); ?></td>
                                        <td class="amount-danger">₹<?php echo number_format($row['foreclosure_charge'], 0); ?></td>
                                        <td class="amount-foreclosed fw-bold">₹<?php echo number_format($row['total_amount'], 0); ?></td>
                                        <td class="amount-success fw-bold">₹<?php echo number_format($row['paid_amount'], 0); ?></td>
                                        <td>
                                            <span class="badge-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view-customer.php?id=<?php echo $row['customer_id']; ?>" 
                                                   class="action-btn view" title="View Customer">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="foreclosure-receipt.php?foreclosure_id=<?php echo $row['id']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                                   class="action-btn print" target="_blank" title="Print Receipt">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <button type="button" 
                                                        class="action-btn whatsapp" 
                                                        onclick="shareForeclosure(<?php echo $row['id']; ?>, <?php echo $row['customer_id']; ?>, '<?php echo $row['customer_number']; ?>', '<?php echo $row['bill_number']; ?>', <?php echo $row['total_amount']; ?>)"
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
                            <i class="bi bi-file-earmark-x text-muted" style="font-size: 3rem;"></i>
                            <h6 class="mt-3 text-muted">No foreclosures found</h6>
                            <?php if (!empty($search) || !empty($date_from) || !empty($date_to) || $finance_filter > 0): ?>
                                <a href="foreclosure-list.php" class="btn btn-outline-danger btn-sm mt-2">
                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                            <h5><i class="bi bi-pie-chart me-2"></i>Foreclosure Breakdown</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="amount-foreclosed fw-bold h4">₹<?php echo number_format($stats['total_principal']/1000, 1); ?>K</div>
                                    <div class="small text-muted">Principal</div>
                                </div>
                                <div class="col-4">
                                    <div class="amount-foreclosed fw-bold h4">₹<?php echo number_format($stats['total_interest']/1000, 1); ?>K</div>
                                    <div class="small text-muted">Interest</div>
                                </div>
                                <div class="col-4">
                                    <div class="amount-foreclosed fw-bold h4">₹<?php echo number_format($stats['total_overdue']/1000, 1); ?>K</div>
                                    <div class="small text-muted">Overdue</div>
                                </div>
                            </div>
                            <div class="progress mt-3" style="height: 20px;">
                                <?php 
                                $principal_percent = $stats['total_amount'] > 0 ? round(($stats['total_principal'] / $stats['total_amount']) * 100, 1) : 0;
                                $interest_percent = $stats['total_amount'] > 0 ? round(($stats['total_interest'] / $stats['total_amount']) * 100, 1) : 0;
                                $overdue_percent = $stats['total_amount'] > 0 ? round(($stats['total_overdue'] / $stats['total_amount']) * 100, 1) : 0;
                                $charges_percent = $stats['total_amount'] > 0 ? round(($stats['total_charges'] / $stats['total_amount']) * 100, 1) : 0;
                                ?>
                                <div class="progress-bar bg-primary" style="width: <?php echo $principal_percent; ?>%;" title="Principal: <?php echo $principal_percent; ?>%"><?php echo $principal_percent; ?>%</div>
                                <div class="progress-bar bg-purple" style="width: <?php echo $interest_percent; ?>%; background-color: #8b5cf6;" title="Interest: <?php echo $interest_percent; ?>%"><?php echo $interest_percent; ?>%</div>
                                <div class="progress-bar bg-warning" style="width: <?php echo $overdue_percent; ?>%;" title="Overdue: <?php echo $overdue_percent; ?>%"><?php echo $overdue_percent; ?>%</div>
                                <div class="progress-bar bg-danger" style="width: <?php echo $charges_percent; ?>%;" title="Charges: <?php echo $charges_percent; ?>%"><?php echo $charges_percent; ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e);">
                            <h5><i class="bi bi-cash-stack me-2"></i>Collection Status</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="amount-success fw-bold h4">₹<?php echo number_format($stats['total_paid']/1000, 1); ?>K</div>
                                    <div class="small text-muted">Collected</div>
                                </div>
                                <div class="col-4">
                                    <div class="amount-warning fw-bold h4">₹<?php echo number_format(($stats['total_amount'] - $stats['total_paid'])/1000, 1); ?>K</div>
                                    <div class="small text-muted">Pending</div>
                                </div>
                                <div class="col-4">
                                    <div class="amount-foreclosed fw-bold h4"><?php echo $stats['total_foreclosures']; ?></div>
                                    <div class="small text-muted">Total Cases</div>
                                </div>
                            </div>
                            <div class="progress mt-3" style="height: 20px;">
                                <?php 
                                $collected_percent = $stats['total_amount'] > 0 ? round(($stats['total_paid'] / $stats['total_amount']) * 100, 1) : 0;
                                $pending_percent = $stats['total_amount'] > 0 ? round((($stats['total_amount'] - $stats['total_paid']) / $stats['total_amount']) * 100, 1) : 0;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $collected_percent; ?>%;" title="Collected: <?php echo $collected_percent; ?>%"><?php echo $collected_percent; ?>%</div>
                                <div class="progress-bar bg-warning" style="width: <?php echo $pending_percent; ?>%;" title="Pending: <?php echo $pending_percent; ?>%"><?php echo $pending_percent; ?>%</div>
                            </div>
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
    <?php if ($foreclosures && $foreclosures->num_rows > 0): ?>
    $('#foreclosureTable').DataTable({
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
            { orderable: false, targets: [12] }
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

function shareForeclosure(foreclosureId, customerId, phone, billNumber, amount) {
    const phoneNumber = prompt('Enter phone number:', phone ? '91' + phone.replace(/\D/g, '').substring(0,10) : '');
    if (!phoneNumber) return;

    const baseUrl = window.location.protocol + '//' + window.location.host;
    const receiptUrl = baseUrl + '/bala-finance/foreclosure-receipt.php?foreclosure_id=' + foreclosureId + '&customer_id=' + customerId;
    const message = encodeURIComponent(
        'BALA FINANCE - Foreclosure Receipt\n' +
        '--------------------------------\n' +
        'Bill Number: ' + billNumber + '\n' +
        'Amount: ₹' + amount.toLocaleString() + '\n\n' +
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