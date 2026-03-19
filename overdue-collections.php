<?php
require_once 'includes/auth.php';
$currentPage = 'overdue-collections';
$pageTitle = 'Overdue Collections';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$due_from = isset($_GET['due_from']) ? $_GET['due_from'] : '';
$due_to = isset($_GET['due_to']) ? $_GET['due_to'] : '';
$days_overdue = isset($_GET['days_overdue']) ? intval($_GET['days_overdue']) : 0;
$collection_type = isset($_GET['collection_type']) ? $_GET['collection_type'] : 'all';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Build query for overdue collections
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
      AND es.emi_due_date < CURDATE()
      AND es.emi_due_date != '0000-00-00'
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

if ($days_overdue > 0) {
    $query .= " AND DATEDIFF(CURDATE(), es.emi_due_date) >= ?";
    $params[] = $days_overdue;
    $types .= "i";
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

$query .= " ORDER BY es.emi_due_date ASC, es.id ASC";

$overdue = null;
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $overdue = $stmt->get_result();
    $stmt->close();
}

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_overdue,
        COALESCE(SUM(es.emi_amount), 0) as total_amount,
        COALESCE(SUM(es.principal_amount - COALESCE(es.principal_paid, 0)), 0) as total_principal,
        COALESCE(SUM(es.interest_amount - COALESCE(es.interest_paid, 0)), 0) as total_interest,
        COALESCE(SUM(es.overdue_charges - COALESCE(es.overdue_paid, 0)), 0) as total_overdue_charges,
        COUNT(DISTINCT es.customer_id) as unique_customers,
        MIN(es.emi_due_date) as earliest_due,
        MAX(es.emi_due_date) as latest_due,
        AVG(DATEDIFF(CURDATE(), es.emi_due_date)) as avg_days_overdue,
        MAX(DATEDIFF(CURDATE(), es.emi_due_date)) as max_days_overdue,
        SUM(CASE WHEN DATEDIFF(CURDATE(), es.emi_due_date) <= 7 THEN 1 ELSE 0 END) as critical_7days,
        SUM(CASE WHEN DATEDIFF(CURDATE(), es.emi_due_date) BETWEEN 8 AND 30 THEN 1 ELSE 0 END) as critical_30days,
        SUM(CASE WHEN DATEDIFF(CURDATE(), es.emi_due_date) > 30 THEN 1 ELSE 0 END) as critical_30plus
    FROM emi_schedule es
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date < CURDATE()
      AND es.emi_due_date != '0000-00-00'
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
if ($days_overdue > 0) {
    $stats_query .= " AND DATEDIFF(CURDATE(), es.emi_due_date) >= $days_overdue";
}
if ($collection_type != 'all') {
    $stats_query .= " AND es.collection_type = '$collection_type'";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats = array_merge([
    'total_overdue' => 0,
    'total_amount' => 0,
    'total_principal' => 0,
    'total_interest' => 0,
    'total_overdue_charges' => 0,
    'unique_customers' => 0,
    'earliest_due' => null,
    'latest_due' => null,
    'avg_days_overdue' => 0,
    'max_days_overdue' => 0,
    'critical_7days' => 0,
    'critical_30days' => 0,
    'critical_30plus' => 0
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
        COALESCE(SUM(es.emi_amount), 0) as type_total,
        AVG(DATEDIFF(CURDATE(), es.emi_due_date)) as type_avg_days
    FROM emi_schedule es
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date < CURDATE()
      AND es.emi_due_date != '0000-00-00'
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
if ($days_overdue > 0) {
    $type_query .= " AND DATEDIFF(CURDATE(), es.emi_due_date) >= $days_overdue";
}

$type_query .= " GROUP BY es.collection_type ORDER BY type_count DESC";

$type_result = $conn->query($type_query);
$collection_types = [];
if ($type_result) {
    while ($row = $type_result->fetch_assoc()) {
        $collection_types[] = $row;
    }
}

// Get customers with multiple overdue EMIs
$multi_query = "
    SELECT 
        es.customer_id,
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        COUNT(*) as overdue_count,
        COALESCE(SUM(es.emi_amount), 0) as total_due
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date < CURDATE()
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

if ($user_role != 'admin') {
    $multi_query .= " AND es.finance_id = $finance_id";
}

$multi_query .= " GROUP BY es.customer_id HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 5";

$multi_result = $conn->query($multi_query);
$multi_overdue = [];
if ($multi_result) {
    while ($row = $multi_result->fetch_assoc()) {
        $multi_overdue[] = $row;
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
            --overdue-critical: #b91c1c;
            --overdue-high: #c2410c;
            --overdue-medium: #b45309;
            --overdue-low: #92400e;
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
            background: linear-gradient(135deg, var(--danger), #c0392b);
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
            color: var(--danger);
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
            border-color: var(--danger);
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
        
        /* Criticality Cards */
        .criticality-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .criticality-card {
            background: var(--card-bg);
            border-left: 4px solid;
            border-radius: var(--radius);
            padding: 0.5rem 0.75rem;
            transition: all 0.2s ease;
        }
        
        .criticality-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .criticality-card.critical {
            border-left-color: var(--overdue-critical);
        }
        
        .criticality-card.high {
            border-left-color: var(--overdue-high);
        }
        
        .criticality-card.medium {
            border-left-color: var(--overdue-medium);
        }
        
        .criticality-card.low {
            border-left-color: var(--overdue-low);
        }
        
        .criticality-title {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .criticality-value {
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .criticality-desc {
            font-size: 0.6rem;
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
            background: var(--danger);
            color: white;
            border-color: var(--danger);
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
            padding: 0.6rem 1rem;
            background: linear-gradient(135deg, var(--danger), #c0392b);
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
            min-width: 900px; /* Reduced from 1200px */
        }
        
        .table thead th {
            background: #f8fafc;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.6rem;
            border-bottom: 1px solid var(--border-color);
            padding: 0.4rem 0.3rem;
            white-space: nowrap;
            text-align: left;
            vertical-align: middle;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .table tbody td {
            padding: 0.4rem 0.3rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.7rem;
            vertical-align: middle;
            white-space: nowrap;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .table tbody tr.overdue-critical {
            background: #fee2e2;
        }
        
        .table tbody tr.overdue-high {
            background: #ffedd5;
        }
        
        .table tbody tr.overdue-medium {
            background: #fef3c7;
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
            background: linear-gradient(135deg, var(--danger), #c0392b);
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
        
        /* Amount Colors - Compact */
        .amount-primary { color: var(--primary); font-weight: 600; font-size: 0.7rem; }
        .amount-danger { color: var(--danger); font-weight: 600; font-size: 0.7rem; }
        
        /* Badges - Compact */
        .badge-collection {
            padding: 0.1rem 0.25rem;
            border-radius: 8px;
            font-size: 0.55rem;
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
        
        .badge-overdue {
            padding: 0.1rem 0.25rem;
            border-radius: 12px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-overdue.critical {
            background: #fee2e2;
            color: var(--danger);
        }
        
        .badge-overdue.high {
            background: #ffedd5;
            color: #c2410c;
        }
        
        .badge-overdue.medium {
            background: #fef3c7;
            color: #b45309;
        }
        
        .badge-finance {
            background: #fee2e2;
            color: var(--danger);
            padding: 0.1rem 0.25rem;
            border-radius: 8px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
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
        
        .action-btn.pay {
            background: var(--success);
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
            .criticality-grid {
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
                        <h4><i class="bi bi-clock-history me-2"></i>Overdue Collections</h4>
                        <p>View all overdue EMI collections requiring immediate attention</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="collections.php" class="btn btn-light">
                            <i class="bi bi-cash me-2"></i>Record Collection
                        </a>
                        <span class="badge bg-white text-danger py-2 px-3">
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
                    <div class="stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-label">TOTAL OVERDUE</div>
                    <div class="stat-value"><?php echo number_format($stats['total_overdue']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="bi bi-currency-rupee"></i></div>
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
                    <div class="stat-label">OVERDUE CHARGES</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_overdue_charges'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                    <div class="stat-label">CUSTOMERS</div>
                    <div class="stat-value"><?php echo number_format($stats['unique_customers']); ?></div>
                </div>
            </div>

            <!-- Criticality Grid -->
            <div class="criticality-grid">
                <div class="criticality-card critical">
                    <div class="criticality-title">CRITICAL (7+ DAYS)</div>
                    <div class="criticality-value"><?php echo number_format($stats['critical_7days']); ?></div>
                    <div class="criticality-desc">Immediate action</div>
                </div>
                <div class="criticality-card high">
                    <div class="criticality-title">HIGH (8-30 DAYS)</div>
                    <div class="criticality-value"><?php echo number_format($stats['critical_30days']); ?></div>
                    <div class="criticality-desc">Urgent follow-up</div>
                </div>
                <div class="criticality-card medium">
                    <div class="criticality-title">SEVERE (30+ DAYS)</div>
                    <div class="criticality-value"><?php echo number_format($stats['critical_30plus']); ?></div>
                    <div class="criticality-desc">Legal action</div>
                </div>
            </div>

            <!-- Additional Stats Row -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">EARLIEST</div>
                    <div class="stat-value"><?php echo $stats['earliest_due'] && $stats['earliest_due'] != '0000-00-00' ? date('d/m', strtotime($stats['earliest_due'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">LATEST</div>
                    <div class="stat-value"><?php echo $stats['latest_due'] && $stats['latest_due'] != '0000-00-00' ? date('d/m', strtotime($stats['latest_due'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="bi bi-clock"></i></div>
                    <div class="stat-label">AVG DAYS</div>
                    <div class="stat-value"><?php echo round($stats['avg_days_overdue']); ?>d</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="bi bi-hourglass"></i></div>
                    <div class="stat-label">MAX DAYS</div>
                    <div class="stat-value"><?php echo $stats['max_days_overdue']; ?>d</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-calculator"></i></div>
                    <div class="stat-label">AVG AMOUNT</div>
                    <div class="stat-value">₹<?php echo $stats['total_overdue'] > 0 ? number_format($stats['total_amount'] / $stats['total_overdue'], 0) : 0; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-person-badge"></i></div>
                    <div class="stat-label">MULTI-EMI</div>
                    <div class="stat-value"><?php echo count($multi_overdue); ?></div>
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
                                   value="<?php echo $due_from; ?>" placeholder="From Date">
                        </div>
                        <div class="col-md-2">
                            <input type="date" class="form-control" name="due_to" 
                                   value="<?php echo $due_to; ?>" placeholder="To Date">
                        </div>
                        <div class="col-md-1">
                            <select class="form-select" name="days_overdue">
                                <option value="0">All</option>
                                <option value="7" <?php echo $days_overdue == 7 ? 'selected' : ''; ?>>7+</option>
                                <option value="15" <?php echo $days_overdue == 15 ? 'selected' : ''; ?>>15+</option>
                                <option value="30" <?php echo $days_overdue == 30 ? 'selected' : ''; ?>>30+</option>
                                <option value="60" <?php echo $days_overdue == 60 ? 'selected' : ''; ?>>60+</option>
                                <option value="90" <?php echo $days_overdue == 90 ? 'selected' : ''; ?>>90+</option>
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
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </div>
                        <div class="col-md-1">
                            <a href="overdue-collections.php" class="btn btn-secondary w-100">
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
                            All
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'monthly';
                            echo http_build_query($params);
                        ?>" class="filter-chip monthly <?php echo $collection_type == 'monthly' ? 'active' : ''; ?>">
                            Monthly
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'weekly';
                            echo http_build_query($params);
                        ?>" class="filter-chip weekly <?php echo $collection_type == 'weekly' ? 'active' : ''; ?>">
                            Weekly
                        </a>
                        <a href="?<?php 
                            $params = $_GET;
                            $params['collection_type'] = 'daily';
                            echo http_build_query($params);
                        ?>" class="filter-chip daily <?php echo $collection_type == 'daily' ? 'active' : ''; ?>">
                            Daily
                        </a>
                    </div>
                </form>
                
                <?php if ($stats['earliest_due'] && $stats['latest_due'] && $stats['earliest_due'] != '0000-00-00'): ?>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Showing overdue from <?php echo date('d M Y', strtotime($stats['earliest_due'])); ?> to <?php echo date('d M Y', strtotime($stats['latest_due'])); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Overdue Collections Table - Compact -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Overdue Collections</h5>
                            <p>Total: <?php echo $stats['total_overdue']; ?> | ₹<?php echo number_format($stats['total_amount'], 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($overdue && $overdue->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="overdueTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Due</th>
                                        <th>Days</th>
                                        <th>Customer</th>
                                        <th>Agr</th>
                                        <th>Loan</th>
                                        <th>Type</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Overdue</th>
                                        <th>Total</th>
                                        <th>Fin</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($row = $overdue->fetch_assoc()): 
                                    $total_due = $row['remaining_principal'] + $row['remaining_interest'] + $row['remaining_overdue'];
                                    $days = $row['days_overdue'];
                                    
                                    if ($days <= 7) {
                                        $row_class = 'overdue-critical';
                                        $badge_class = 'critical';
                                    } elseif ($days <= 30) {
                                        $row_class = 'overdue-high';
                                        $badge_class = 'high';
                                    } else {
                                        $row_class = 'overdue-medium';
                                        $badge_class = 'medium';
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
                                    
                                    // Format date properly
                                    $due_date = $row['emi_due_date'];
                                    $formatted_date = ($due_date && $due_date != '0000-00-00') ? date('d/m', strtotime($due_date)) : 'N/A';
                                    
                                    // Get initials
                                    $name_parts = explode(' ', $row['customer_name'] ?? '');
                                    $initials = '';
                                    foreach ($name_parts as $part) {
                                        $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    $initials = substr($initials, 0, 2) ?: 'U';
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td><?php echo $formatted_date; ?></td>
                                        <td><span class="badge-overdue <?php echo $badge_class; ?>"><?php echo $days; ?></span></td>
                                        <td>
                                            <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $row['customer_id']; ?>'">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div class="customer-name"><?php echo htmlspecialchars(substr($row['customer_name'], 0, 8)); ?></div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars(substr($row['agreement_number'], 0, 5)); ?></td>
                                        <td><?php echo htmlspecialchars(substr($row['loan_name'], 0, 4)); ?></td>
                                        <td><span class="badge-collection <?php echo $collection_class; ?>"><?php echo $type_display; ?></span></td>
                                        <td class="amount-primary">₹<?php echo number_format($row['remaining_principal'], 0); ?></td>
                                        <td class="amount-primary">₹<?php echo number_format($row['remaining_interest'], 0); ?></td>
                                        <td><?php echo $row['remaining_overdue'] > 0 ? '₹'.number_format($row['remaining_overdue'], 0) : '-'; ?></td>
                                        <td class="amount-danger fw-bold">₹<?php echo number_format($total_due, 0); ?></td>
                                        <td><span class="badge-finance"><?php echo htmlspecialchars(substr($row['finance_name'], 0, 4)); ?></span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="pay-emi.php?emi_id=<?php echo $row['id']; ?>&customer_id=<?php echo $row['customer_id']; ?>" 
                                                   class="action-btn pay" title="Pay"><i class="bi bi-cash"></i></a>
                                                <a href="view-customer.php?id=<?php echo $row['customer_id']; ?>" 
                                                   class="action-btn view" title="View"><i class="bi bi-eye"></i></a>
                                                <button type="button" class="action-btn whatsapp" 
                                                        onclick="shareReminder(<?php echo $row['id']; ?>, <?php echo $row['customer_id']; ?>, '<?php echo $row['customer_number']; ?>', '<?php echo $formatted_date; ?>', <?php echo $total_due; ?>, <?php echo $days; ?>)"
                                                        title="WhatsApp"><i class="bi bi-whatsapp"></i></button>
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
                            <h6 class="mt-3 text-muted">No overdue collections found</h6>
                        </div>
                    <?php endif; ?>
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
    <?php if ($overdue && $overdue->num_rows > 0): ?>
    $('#overdueTable').DataTable({
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
            { orderable: false, targets: [11] }
        ]
    });
    <?php endif; ?>
});

function shareReminder(emiId, customerId, phone, dueDate, amount, days) {
    const phoneNumber = prompt('Enter phone number:', phone ? '91' + phone.replace(/\D/g, '').substring(0,10) : '');
    if (!phoneNumber) return;

    let urgencyMsg = '';
    if (days <= 7) {
        urgencyMsg = '⚠️ IMMEDIATE ACTION REQUIRED';
    } else if (days <= 30) {
        urgencyMsg = '⚠️ URGENT - Please clear';
    } else {
        urgencyMsg = '⚠️ SEVERE - Legal action';
    }

    const message = encodeURIComponent(
        'BALA FINANCE - OVERDUE REMINDER\n' +
        '--------------------------------\n' +
        urgencyMsg + '\n\n' +
        'Your EMI of ₹' + amount.toLocaleString() + ' is overdue by ' + days + ' days.\n' +
        'Due Date: ' + dueDate + '\n\n' +
        'Please clear the outstanding amount immediately.\n' +
        'Thank you.'
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