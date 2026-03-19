<?php
require_once 'includes/auth.php';
$currentPage = 'customer-wise-report';
$pageTitle = 'Customer Wise Report';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Get customer list for dropdown
$customer_query = "SELECT id, customer_name, agreement_number FROM customers";
if ($user_role != 'admin') {
    $customer_query .= " WHERE finance_id = $finance_id";
}
$customer_query .= " ORDER BY customer_name ASC";
$customers = $conn->query($customer_query);

// Build customer summary query
$summary_query = "
    SELECT 
        c.id,
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        c.loan_amount,
        c.interest_rate,
        c.loan_tenure,
        c.collection_type,
        f.finance_name,
        l.loan_name,
        COALESCE((
            SELECT COUNT(*) FROM emi_schedule 
            WHERE customer_id = c.id AND status = 'paid'
        ), 0) as paid_emis,
        COALESCE((
            SELECT COUNT(*) FROM emi_schedule 
            WHERE customer_id = c.id AND status IN ('unpaid', 'overdue', 'partial')
        ), 0) as pending_emis,
        COALESCE((
            SELECT SUM(principal_paid) FROM emi_schedule 
            WHERE customer_id = c.id
        ), 0) as total_principal_paid,
        COALESCE((
            SELECT SUM(interest_paid) FROM emi_schedule 
            WHERE customer_id = c.id
        ), 0) as total_interest_paid,
        COALESCE((
            SELECT SUM(overdue_paid) FROM emi_schedule 
            WHERE customer_id = c.id
        ), 0) as total_overdue_paid,
        COALESCE((
            SELECT SUM(emi_amount) FROM emi_schedule 
            WHERE customer_id = c.id AND status = 'paid'
        ), 0) as total_collected,
        COALESCE((
            SELECT SUM(principal_amount - COALESCE(principal_paid, 0)) FROM emi_schedule 
            WHERE customer_id = c.id AND status IN ('unpaid', 'overdue', 'partial')
        ), 0) as outstanding_principal,
        COALESCE((
            SELECT SUM(interest_amount - COALESCE(interest_paid, 0)) FROM emi_schedule 
            WHERE customer_id = c.id AND status IN ('unpaid', 'overdue', 'partial')
        ), 0) as outstanding_interest,
        COALESCE((
            SELECT SUM(overdue_charges - COALESCE(overdue_paid, 0)) FROM emi_schedule 
            WHERE customer_id = c.id AND status IN ('unpaid', 'overdue', 'partial')
        ), 0) as outstanding_overdue,
        (SELECT MAX(paid_date) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as last_payment_date
    FROM customers c
    JOIN loans l ON c.loan_id = l.id
    JOIN finance f ON c.finance_id = f.id
    WHERE 1=1
";

$params = [];
$types = "";

if ($user_role != 'admin') {
    $summary_query .= " AND c.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if (!empty($search)) {
    $summary_query .= " AND (c.customer_name LIKE ? OR c.customer_number LIKE ? OR c.agreement_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($customer_id > 0) {
    $summary_query .= " AND c.id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $summary_query .= " AND c.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$summary_query .= " ORDER BY c.customer_name ASC";

$customers_summary = null;
$stmt = $conn->prepare($summary_query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $customers_summary = $stmt->get_result();
    $stmt->close();
}

// Get overall statistics
$overall_query = "
    SELECT 
        COUNT(DISTINCT c.id) as total_customers,
        COALESCE(SUM(c.loan_amount), 0) as total_loans,
        COALESCE(SUM(paid_principal.total), 0) as total_principal_paid,
        COALESCE(SUM(paid_interest.total), 0) as total_interest_paid,
        COALESCE(SUM(paid_overdue.total), 0) as total_overdue_paid,
        COALESCE(SUM(outstanding.principal), 0) as total_outstanding_principal,
        COALESCE(SUM(outstanding.interest), 0) as total_outstanding_interest,
        COALESCE(SUM(outstanding.overdue), 0) as total_outstanding_overdue,
        COUNT(CASE WHEN c.id IN (SELECT DISTINCT customer_id FROM emi_schedule WHERE status = 'overdue') THEN 1 END) as overdue_customers
    FROM customers c
    LEFT JOIN (
        SELECT customer_id, SUM(principal_paid) as total FROM emi_schedule GROUP BY customer_id
    ) paid_principal ON c.id = paid_principal.customer_id
    LEFT JOIN (
        SELECT customer_id, SUM(interest_paid) as total FROM emi_schedule GROUP BY customer_id
    ) paid_interest ON c.id = paid_interest.customer_id
    LEFT JOIN (
        SELECT customer_id, SUM(overdue_paid) as total FROM emi_schedule GROUP BY customer_id
    ) paid_overdue ON c.id = paid_overdue.customer_id
    LEFT JOIN (
        SELECT customer_id, 
               SUM(principal_amount - COALESCE(principal_paid, 0)) as principal,
               SUM(interest_amount - COALESCE(interest_paid, 0)) as interest,
               SUM(overdue_charges - COALESCE(overdue_paid, 0)) as overdue
        FROM emi_schedule 
        WHERE status IN ('unpaid', 'overdue', 'partial')
        GROUP BY customer_id
    ) outstanding ON c.id = outstanding.customer_id
    WHERE 1=1
";

if ($user_role != 'admin') {
    $overall_query .= " AND c.finance_id = $finance_id";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $overall_query .= " AND c.finance_id = $finance_filter";
}

$overall_result = $conn->query($overall_query);
$overall = $overall_result ? $overall_result->fetch_assoc() : [];
$overall = array_merge([
    'total_customers' => 0,
    'total_loans' => 0,
    'total_principal_paid' => 0,
    'total_interest_paid' => 0,
    'total_overdue_paid' => 0,
    'total_outstanding_principal' => 0,
    'total_outstanding_interest' => 0,
    'total_outstanding_overdue' => 0,
    'overdue_customers' => 0
], $overall);

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
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
        
        /* Badges - Compact */
        .badge-status {
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
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
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-collection.monthly {
            background: #e3f2fd;
            color: #3b82f6;
        }
        
        .badge-collection.weekly {
            background: #f3e5f5;
            color: #8b5cf6;
        }
        
        .badge-collection.daily {
            background: #fff3e0;
            color: #f59e0b;
        }
        
        .badge-finance {
            background: #e6f0ff;
            color: var(--primary);
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Action Buttons - Ultra Compact */
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
            margin: 0 1px;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
            color: white;
        }
        
        .action-btn.view {
            background: var(--info);
        }
        
        .action-btn.emi {
            background: var(--primary);
        }
        
        /* Progress Bar - Compact */
        .progress-compact {
            height: 4px;
            width: 60px;
            border-radius: 2px;
            background: #e9ecef;
            display: inline-block;
        }
        
        .progress-bar-compact {
            height: 4px;
            border-radius: 2px;
            background: var(--success);
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
        
        /* Summary Box */
        .summary-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            height: 100%;
        }
        
        .summary-title {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        
        .summary-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .summary-small {
            font-size: 0.6rem;
            color: var(--text-muted);
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
                        <h4><i class="bi bi-person-badge me-2"></i>Customer Wise Report</h4>
                        <p>View detailed reports for each customer</p>
                    </div>
                    <span class="badge bg-white text-primary py-2 px-3">
                        <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                    </span>
                </div>
            </div>

            <!-- Overall Summary Stats -->
            <div class="row g-2 mb-3">
                <div class="col-md-2">
                    <div class="summary-box">
                        <div class="summary-title">Total Customers</div>
                        <div class="summary-value"><?php echo number_format($overall['total_customers']); ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-box">
                        <div class="summary-title">Total Loans</div>
                        <div class="summary-value">₹<?php echo number_format($overall['total_loans']/100000, 1); ?>L</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-box">
                        <div class="summary-title">Principal Paid</div>
                        <div class="summary-value">₹<?php echo number_format($overall['total_principal_paid']/100000, 1); ?>L</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-box">
                        <div class="summary-title">Interest Paid</div>
                        <div class="summary-value">₹<?php echo number_format($overall['total_interest_paid']/100000, 1); ?>L</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-box">
                        <div class="summary-title">Outstanding</div>
                        <div class="summary-value text-warning">₹<?php echo number_format(($overall['total_outstanding_principal'] + $overall['total_outstanding_interest'])/100000, 1); ?>L</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="summary-box">
                        <div class="summary-title">Overdue Cust</div>
                        <div class="summary-value text-danger"><?php echo number_format($overall['overdue_customers']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Customer name, mobile, agreement..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <label class="form-label">Select Customer</label>
                            <select class="form-select" name="customer_id">
                                <option value="0">All Customers</option>
                                <?php 
                                if ($customers) {
                                    $customers->data_seek(0);
                                    while ($cust = $customers->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $cust['id']; ?>" <?php echo $customer_id == $cust['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cust['customer_name']); ?> - <?php echo htmlspecialchars($cust['agreement_number']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
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
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-1"></i>Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <a href="customer-wise-report.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat me-1"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Customer Summary Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Customer Summary</h5>
                            <p>Total: <?php echo $customers_summary ? $customers_summary->num_rows : 0; ?> customers</p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if ($customers_summary && $customers_summary->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table id="customerTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Agreement</th>
                                        <th>Loan</th>
                                        <th>Type</th>
                                        <th>Finance</th>
                                        <th>Progress</th>
                                        <th>Paid</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Outstanding</th>
                                        <th>Last Paid</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($cust = $customers_summary->fetch_assoc()): 
                                    // Calculate progress percentage
                                    $total_emis = $cust['paid_emis'] + $cust['pending_emis'];
                                    $progress = $total_emis > 0 ? round(($cust['paid_emis'] / $total_emis) * 100) : 0;
                                    
                                    // Determine status
                                    if ($cust['outstanding_overdue'] > 0) {
                                        $status_class = 'overdue';
                                        $status_text = 'Overdue';
                                    } elseif ($cust['pending_emis'] > 0) {
                                        $status_class = 'active';
                                        $status_text = 'Active';
                                    } else {
                                        $status_class = 'completed';
                                        $status_text = 'Completed';
                                    }
                                    
                                    // Collection type class
                                    $collection_class = '';
                                    if ($cust['collection_type'] == 'monthly') {
                                        $collection_class = 'monthly';
                                    } elseif ($cust['collection_type'] == 'weekly') {
                                        $collection_class = 'weekly';
                                    } else {
                                        $collection_class = 'daily';
                                    }
                                    
                                    // Total outstanding
                                    $total_outstanding = $cust['outstanding_principal'] + $cust['outstanding_interest'] + $cust['outstanding_overdue'];
                                    
                                    // Get initials
                                    $name_parts = explode(' ', $cust['customer_name'] ?? '');
                                    $initials = '';
                                    foreach ($name_parts as $part) {
                                        $initials .= strtoupper(substr($part, 0, 1));
                                    }
                                    $initials = substr($initials, 0, 2) ?: 'U';
                                ?>
                                    <tr>
                                        <td>
                                            <div class="customer-info" onclick="window.location.href='view-customer.php?id=<?php echo $cust['id']; ?>'">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div>
                                                    <div class="customer-name" title="<?php echo htmlspecialchars($cust['customer_name']); ?>">
                                                        <?php echo htmlspecialchars(substr($cust['customer_name'], 0, 12)); ?>
                                                    </div>
                                                    <div class="customer-meta"><?php echo htmlspecialchars($cust['customer_number']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars(substr($cust['agreement_number'], 0, 8)); ?></span>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars(substr($cust['loan_name'], 0, 8)); ?></div>
                                            <div class="customer-meta">₹<?php echo number_format($cust['loan_amount']/1000, 0); ?>K</div>
                                        </td>
                                        <td>
                                            <span class="badge-collection <?php echo $collection_class; ?>">
                                                <?php echo substr(ucfirst($cust['collection_type']), 0, 3); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-finance">
                                                <?php echo htmlspecialchars(substr($cust['finance_name'], 0, 6)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-1">
                                                <span class="small"><?php echo $progress; ?>%</span>
                                                <div class="progress-compact">
                                                    <div class="progress-bar-compact" style="width: <?php echo $progress; ?>%;"></div>
                                                </div>
                                            </div>
                                            <div class="customer-meta"><?php echo $cust['paid_emis']; ?>/<?php echo $total_emis; ?></div>
                                        </td>
                                        <td>
                                            <span class="amount-success">₹<?php echo number_format(($cust['total_principal_paid'] + $cust['total_interest_paid'])/1000, 1); ?>K</span>
                                        </td>
                                        <td>
                                            <span class="amount-primary">₹<?php echo number_format($cust['total_principal_paid']/1000, 1); ?>K</span>
                                        </td>
                                        <td>
                                            <span class="amount-primary">₹<?php echo number_format($cust['total_interest_paid']/1000, 1); ?>K</span>
                                        </td>
                                        <td>
                                            <span class="amount-warning fw-bold">₹<?php echo number_format($total_outstanding/1000, 1); ?>K</span>
                                            <?php if ($cust['outstanding_overdue'] > 0): ?>
                                                <span class="badge-status overdue ms-1">!</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cust['last_payment_date'] && $cust['last_payment_date'] != '0000-00-00'): ?>
                                                <?php echo date('d/m/y', strtotime($cust['last_payment_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view-customer.php?id=<?php echo $cust['id']; ?>" 
                                                   class="action-btn view" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="emi-schedule.php?customer_id=<?php echo $cust['id']; ?>" 
                                                   class="action-btn emi" title="EMI Schedule">
                                                    <i class="bi bi-calendar-check"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                            <h6 class="mt-3 text-muted">No customers found</h6>
                            <?php if (!empty($search) || $customer_id > 0 || $finance_filter > 0): ?>
                                <a href="customer-wise-report.php" class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary Cards at Bottom -->
            <div class="row g-2">
                <div class="col-md-3">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                            <h5><i class="bi bi-cash-stack me-2"></i>Total Collections</h5>
                        </div>
                        <div class="card-body p-2">
                            <div class="text-center p-2">
                                <div class="stat-value">₹<?php echo number_format(($overall['total_principal_paid'] + $overall['total_interest_paid'] + $overall['total_overdue_paid'])/100000, 2); ?>L</div>
                                <div class="small text-muted">Principal: ₹<?php echo number_format($overall['total_principal_paid']/100000, 2); ?>L</div>
                                <div class="small text-muted">Interest: ₹<?php echo number_format($overall['total_interest_paid']/100000, 2); ?>L</div>
                                <div class="small text-muted">Overdue: ₹<?php echo number_format($overall['total_overdue_paid']/100000, 2); ?>L</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e);">
                            <h5><i class="bi bi-hourglass-split me-2"></i>Outstanding</h5>
                        </div>
                        <div class="card-body p-2">
                            <div class="text-center p-2">
                                <div class="stat-value">₹<?php echo number_format(($overall['total_outstanding_principal'] + $overall['total_outstanding_interest'] + $overall['total_outstanding_overdue'])/100000, 2); ?>L</div>
                                <div class="small text-muted">Principal: ₹<?php echo number_format($overall['total_outstanding_principal']/100000, 2); ?>L</div>
                                <div class="small text-muted">Interest: ₹<?php echo number_format($overall['total_outstanding_interest']/100000, 2); ?>L</div>
                                <div class="small text-danger">Overdue: ₹<?php echo number_format($overall['total_outstanding_overdue']/100000, 2); ?>L</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #0f766e, #14b8a6);">
                            <h5><i class="bi bi-graph-up me-2"></i>Collection Rate</h5>
                        </div>
                        <div class="card-body p-2">
                            <?php 
                            $total_loan_amount = $overall['total_loans'];
                            $total_collected = $overall['total_principal_paid'] + $overall['total_interest_paid'] + $overall['total_overdue_paid'];
                            $collection_rate = $total_loan_amount > 0 ? round(($total_collected / $total_loan_amount) * 100, 1) : 0;
                            ?>
                            <div class="text-center p-2">
                                <div class="stat-value"><?php echo $collection_rate; ?>%</div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $collection_rate; ?>%;"></div>
                                </div>
                                <div class="small text-muted mt-2">Collected: ₹<?php echo number_format($total_collected/100000, 2); ?>L</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #b45309, #f59e0b);">
                            <h5><i class="bi bi-exclamation-triangle me-2"></i>Overdue Status</h5>
                        </div>
                        <div class="card-body p-2">
                            <div class="text-center p-2">
                                <div class="stat-value text-danger"><?php echo number_format($overall['overdue_customers']); ?></div>
                                <div class="small text-muted">Customers with overdue</div>
                                <div class="small text-danger mt-1">₹<?php echo number_format($overall['total_outstanding_overdue']/100000, 2); ?>L overdue</div>
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
    <?php if ($customers_summary && $customers_summary->num_rows > 0): ?>
    $('#customerTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
        language: {
            search: "Search:",
            lengthMenu: "_MENU_",
            info: "Showing _START_-_END_ of _TOTAL_ customers",
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

// Auto-hide alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        bootstrap.Alert.getOrCreateInstance(alert).close();
    });
}, 4000);
</script>
</body>
</html>