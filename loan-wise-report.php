<?php
require_once 'includes/auth.php';
$currentPage = 'loan-wise-report';
$pageTitle = 'Loan Wise Report';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Get loan list for dropdown
$loan_query = "SELECT id, loan_name, loan_amount, interest_rate, loan_tenure FROM loans";
if ($user_role != 'admin') {
    $loan_query .= " WHERE finance_id = $finance_id";
}
$loan_query .= " ORDER BY loan_name ASC";
$loans = $conn->query($loan_query);

// Build loan summary query
$summary_query = "
    SELECT 
        l.id,
        l.loan_name,
        l.loan_amount,
        l.interest_rate,
        l.loan_tenure,
        COUNT(DISTINCT c.id) as total_customers,
        COALESCE(SUM(c.loan_amount), 0) as total_disbursed,
        COALESCE((
            SELECT COUNT(*) FROM customers c2 
            WHERE c2.loan_id = l.id
        ), 0) as customer_count,
        COALESCE((
            SELECT COUNT(*) FROM emi_schedule es 
            JOIN customers c3 ON es.customer_id = c3.id 
            WHERE c3.loan_id = l.id AND es.status = 'paid'
        ), 0) as paid_emis,
        COALESCE((
            SELECT COUNT(*) FROM emi_schedule es 
            JOIN customers c4 ON es.customer_id = c4.id 
            WHERE c4.loan_id = l.id AND es.status IN ('unpaid', 'overdue', 'partial')
        ), 0) as pending_emis,
        COALESCE((
            SELECT SUM(es.principal_paid) FROM emi_schedule es 
            JOIN customers c5 ON es.customer_id = c5.id 
            WHERE c5.loan_id = l.id
        ), 0) as total_principal_paid,
        COALESCE((
            SELECT SUM(es.interest_paid) FROM emi_schedule es 
            JOIN customers c6 ON es.customer_id = c6.id 
            WHERE c6.loan_id = l.id
        ), 0) as total_interest_paid,
        COALESCE((
            SELECT SUM(es.overdue_paid) FROM emi_schedule es 
            JOIN customers c7 ON es.customer_id = c7.id 
            WHERE c7.loan_id = l.id
        ), 0) as total_overdue_paid,
        COALESCE((
            SELECT SUM(es.emi_amount) FROM emi_schedule es 
            JOIN customers c8 ON es.customer_id = c8.id 
            WHERE c8.loan_id = l.id AND es.status = 'paid'
        ), 0) as total_collected,
        COALESCE((
            SELECT SUM(es.principal_amount - COALESCE(es.principal_paid, 0)) FROM emi_schedule es 
            JOIN customers c9 ON es.customer_id = c9.id 
            WHERE c9.loan_id = l.id AND es.status IN ('unpaid', 'overdue', 'partial')
        ), 0) as outstanding_principal,
        COALESCE((
            SELECT SUM(es.interest_amount - COALESCE(es.interest_paid, 0)) FROM emi_schedule es 
            JOIN customers c10 ON es.customer_id = c10.id 
            WHERE c10.loan_id = l.id AND es.status IN ('unpaid', 'overdue', 'partial')
        ), 0) as outstanding_interest,
        COALESCE((
            SELECT SUM(es.overdue_charges - COALESCE(es.overdue_paid, 0)) FROM emi_schedule es 
            JOIN customers c11 ON es.customer_id = c11.id 
            WHERE c11.loan_id = l.id AND es.status IN ('unpaid', 'overdue', 'partial')
        ), 0) as outstanding_overdue,
        COALESCE((
            SELECT COUNT(DISTINCT c12.id) FROM customers c12 
            JOIN emi_schedule es2 ON c12.id = es2.customer_id 
            WHERE c12.loan_id = l.id AND es2.status = 'overdue'
        ), 0) as overdue_customers
    FROM loans l
    LEFT JOIN customers c ON l.id = c.loan_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($user_role != 'admin') {
    $summary_query .= " AND l.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if (!empty($search)) {
    $summary_query .= " AND l.loan_name LIKE ?";
    $search_param = "%$search%";
    $params[] = $search_param;
    $types .= "s";
}

if ($loan_id > 0) {
    $summary_query .= " AND l.id = ?";
    $params[] = $loan_id;
    $types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $summary_query .= " AND l.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$summary_query .= " GROUP BY l.id ORDER BY total_disbursed DESC";

$loans_summary = null;
$stmt = $conn->prepare($summary_query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $loans_summary = $stmt->get_result();
    $stmt->close();
}

// Get overall statistics
$overall_query = "
    SELECT 
        COUNT(DISTINCT l.id) as total_loans,
        COALESCE(SUM(l.loan_amount), 0) as total_loan_amount,
        COUNT(DISTINCT c.id) as total_customers,
        COALESCE(SUM(paid_principal.total), 0) as total_principal_paid,
        COALESCE(SUM(paid_interest.total), 0) as total_interest_paid,
        COALESCE(SUM(paid_overdue.total), 0) as total_overdue_paid,
        COALESCE(SUM(outstanding.principal), 0) as total_outstanding_principal,
        COALESCE(SUM(outstanding.interest), 0) as total_outstanding_interest,
        COALESCE(SUM(outstanding.overdue), 0) as total_outstanding_overdue,
        COUNT(DISTINCT CASE WHEN overdue_cust.customer_id IS NOT NULL THEN c.id END) as overdue_customers
    FROM loans l
    LEFT JOIN customers c ON l.id = c.loan_id
    LEFT JOIN (
        SELECT c.loan_id, SUM(es.principal_paid) as total 
        FROM customers c 
        JOIN emi_schedule es ON c.id = es.customer_id 
        GROUP BY c.loan_id
    ) paid_principal ON l.id = paid_principal.loan_id
    LEFT JOIN (
        SELECT c.loan_id, SUM(es.interest_paid) as total 
        FROM customers c 
        JOIN emi_schedule es ON c.id = es.customer_id 
        GROUP BY c.loan_id
    ) paid_interest ON l.id = paid_interest.loan_id
    LEFT JOIN (
        SELECT c.loan_id, SUM(es.overdue_paid) as total 
        FROM customers c 
        JOIN emi_schedule es ON c.id = es.customer_id 
        GROUP BY c.loan_id
    ) paid_overdue ON l.id = paid_overdue.loan_id
    LEFT JOIN (
        SELECT c.loan_id, 
               SUM(es.principal_amount - COALESCE(es.principal_paid, 0)) as principal,
               SUM(es.interest_amount - COALESCE(es.interest_paid, 0)) as interest,
               SUM(es.overdue_charges - COALESCE(es.overdue_paid, 0)) as overdue
        FROM customers c 
        JOIN emi_schedule es ON c.id = es.customer_id 
        WHERE es.status IN ('unpaid', 'overdue', 'partial')
        GROUP BY c.loan_id
    ) outstanding ON l.id = outstanding.loan_id
    LEFT JOIN (
        SELECT DISTINCT c.loan_id, c.id as customer_id
        FROM customers c 
        JOIN emi_schedule es ON c.id = es.customer_id 
        WHERE es.status = 'overdue'
    ) overdue_cust ON l.id = overdue_cust.loan_id
    WHERE 1=1
";

if ($user_role != 'admin') {
    $overall_query .= " AND l.finance_id = $finance_id";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $overall_query .= " AND l.finance_id = $finance_filter";
}

$overall_result = $conn->query($overall_query);
$overall = $overall_result ? $overall_result->fetch_assoc() : [];
$overall = array_merge([
    'total_loans' => 0,
    'total_loan_amount' => 0,
    'total_customers' => 0,
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

// Get top performing loans
$top_loans_query = "
    SELECT 
        l.id,
        l.loan_name,
        COUNT(DISTINCT c.id) as customer_count,
        COALESCE(SUM(es.principal_paid + es.interest_paid + es.overdue_paid), 0) as total_collected,
        COALESCE(SUM(c.loan_amount), 0) as total_disbursed
    FROM loans l
    LEFT JOIN customers c ON l.id = c.loan_id
    LEFT JOIN emi_schedule es ON c.id = es.customer_id AND es.status = 'paid'
    WHERE 1=1
";

if ($user_role != 'admin') {
    $top_loans_query .= " AND l.finance_id = $finance_id";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $top_loans_query .= " AND l.finance_id = $finance_filter";
}

$top_loans_query .= " GROUP BY l.id ORDER BY total_collected DESC LIMIT 5";

$top_loans_result = $conn->query($top_loans_query);
$top_loans = [];
if ($top_loans_result) {
    while ($row = $top_loans_result->fetch_assoc()) {
        $top_loans[] = $row;
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
            min-width: 1200px;
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
        
        /* Amount Colors */
        .amount-primary { color: var(--primary); font-weight: 600; }
        .amount-success { color: var(--success); font-weight: 600; }
        .amount-warning { color: var(--warning); font-weight: 600; }
        .amount-danger { color: var(--danger); font-weight: 600; }
        
        /* Badges */
        .badge-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-status.high {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .badge-status.medium {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .badge-status.low {
            background: #fee2e2;
            color: var(--danger);
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
        
        /* Progress Bar */
        .progress-custom {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
            width: 100px;
        }
        
        .progress-custom .progress-bar {
            height: 6px;
            border-radius: 3px;
        }
        
        .progress-custom .progress-bar.high {
            background: var(--success);
        }
        
        .progress-custom .progress-bar.medium {
            background: var(--warning);
        }
        
        .progress-custom .progress-bar.low {
            background: var(--danger);
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
        
        /* Top Loan Item */
        .top-loan-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .top-loan-item:last-child {
            border-bottom: none;
        }
        
        .top-loan-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .top-loan-stats {
            text-align: right;
        }
        
        .top-loan-value {
            font-weight: 700;
            color: var(--success);
        }
        
        .top-loan-count {
            font-size: 0.75rem;
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
                        <h4><i class="bi bi-file-text me-2"></i>Loan Wise Report</h4>
                        <p>View detailed reports for each loan type</p>
                    </div>
                    <span class="badge bg-white text-primary py-2 px-3">
                        <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                    </span>
                </div>
            </div>

            <!-- Overall Summary Stats -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-2">
                    <div class="summary-box">
                        <div class="summary-title">Total Loans</div>
                        <div class="summary-value"><?php echo number_format($overall['total_loans']); ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="summary-box">
                        <div class="summary-title">Loan Amount</div>
                        <div class="summary-value">₹<?php echo number_format($overall['total_loan_amount']/100000, 2); ?>L</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="summary-box">
                        <div class="summary-title">Total Customers</div>
                        <div class="summary-value"><?php echo number_format($overall['total_customers']); ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="summary-box">
                        <div class="summary-title">Collected</div>
                        <div class="summary-value text-success">₹<?php echo number_format(($overall['total_principal_paid'] + $overall['total_interest_paid'] + $overall['total_overdue_paid'])/100000, 2); ?>L</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="summary-box">
                        <div class="summary-title">Outstanding</div>
                        <div class="summary-value text-warning">₹<?php echo number_format(($overall['total_outstanding_principal'] + $overall['total_outstanding_interest'])/100000, 2); ?>L</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <div class="summary-box">
                        <div class="summary-title">Overdue Cust</div>
                        <div class="summary-value text-danger"><?php echo number_format($overall['overdue_customers']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search Loan</label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search by loan name..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Select Loan</label>
                            <select class="form-select" name="loan_id">
                                <option value="0">All Loans</option>
                                <?php 
                                if ($loans) {
                                    $loans->data_seek(0);
                                    while ($loan = $loans->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $loan['id']; ?>" <?php echo $loan_id == $loan['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($loan['loan_name']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                        
                        <?php if ($user_role == 'admin'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Finance Company</label>
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
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-2"></i>Apply
                            </button>
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <a href="loan-wise-report.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat me-2"></i>Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Loans Summary Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>Loan Summary</h5>
                            <p>Total: <?php echo $loans_summary ? $loans_summary->num_rows : 0; ?> loan types</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($loans_summary && $loans_summary->num_rows > 0): ?>
                        <div class="table-container">
                            <table id="loanTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Loan Name</th>
                                        <th>Details</th>
                                        <th>Customers</th>
                                        <th>EMIs</th>
                                        <th>Progress</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Collected</th>
                                        <th>Outstanding</th>
                                        <th>Overdue</th>
                                        <th>Recovery</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($loan = $loans_summary->fetch_assoc()): 
                                    // Calculate progress percentage
                                    $total_emis = $loan['paid_emis'] + $loan['pending_emis'];
                                    $progress = $total_emis > 0 ? round(($loan['paid_emis'] / $total_emis) * 100) : 0;
                                    
                                    // Calculate recovery rate
                                    $total_collectible = $loan['total_disbursed'] + ($loan['total_interest_paid'] + $loan['outstanding_interest']);
                                    $recovery_rate = $total_collectible > 0 ? round((($loan['total_principal_paid'] + $loan['total_interest_paid']) / $total_collectible) * 100, 1) : 0;
                                    
                                    // Determine performance class
                                    if ($recovery_rate >= 75) {
                                        $performance_class = 'high';
                                        $performance_text = 'High';
                                    } elseif ($recovery_rate >= 50) {
                                        $performance_class = 'medium';
                                        $performance_text = 'Medium';
                                    } else {
                                        $performance_class = 'low';
                                        $performance_text = 'Low';
                                    }
                                    
                                    // Total collected
                                    $total_collected = $loan['total_principal_paid'] + $loan['total_interest_paid'] + $loan['total_overdue_paid'];
                                    
                                    // Total outstanding
                                    $total_outstanding = $loan['outstanding_principal'] + $loan['outstanding_interest'] + $loan['outstanding_overdue'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($loan['loan_name']); ?></div>
                                            <div class="small text-muted">ID: #<?php echo $loan['id']; ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">₹<?php echo number_format($loan['loan_amount'], 0); ?></div>
                                            <div class="small text-muted"><?php echo $loan['interest_rate']; ?>% | <?php echo $loan['loan_tenure']; ?> months</div>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo $loan['customer_count']; ?></span>
                                            <div class="small text-muted">customers</div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo $loan['paid_emis']; ?>/<?php echo $total_emis; ?></div>
                                            <div class="small text-muted">paid/total</div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span><?php echo $progress; ?>%</span>
                                                <div class="progress-custom">
                                                    <div class="progress-bar <?php echo $performance_class; ?>" style="width: <?php echo $progress; ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="amount-primary">₹<?php echo number_format($loan['total_principal_paid'], 0); ?></span>
                                            <div class="small text-muted">of ₹<?php echo number_format($loan['total_disbursed'], 0); ?></div>
                                        </td>
                                        <td>
                                            <span class="amount-primary">₹<?php echo number_format($loan['total_interest_paid'], 0); ?></span>
                                        </td>
                                        <td>
                                            <span class="amount-success fw-bold">₹<?php echo number_format($total_collected, 0); ?></span>
                                        </td>
                                        <td>
                                            <span class="amount-warning fw-bold">₹<?php echo number_format($total_outstanding, 0); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($loan['overdue_customers'] > 0): ?>
                                                <span class="badge-status low"><?php echo $loan['overdue_customers']; ?> cust</span>
                                                <div class="small text-danger">₹<?php echo number_format($loan['outstanding_overdue'], 0); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo $performance_class; ?>"><?php echo $recovery_rate; ?>%</span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-file-text text-muted"></i>
                            <h6 class="mt-3 text-muted">No loans found</h6>
                            <?php if (!empty($search) || $loan_id > 0 || $finance_filter > 0): ?>
                                <a href="loan-wise-report.php" class="btn btn-outline-primary btn-sm mt-2">
                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Analytics Section -->
            <div class="row g-3">
                <!-- Top Performing Loans -->
                <?php if (!empty($top_loans)): ?>
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                            <h5><i class="bi bi-trophy me-2"></i>Top Performing Loans</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($top_loans as $index => $loan): ?>
                            <div class="top-loan-item">
                                <div>
                                    <span class="top-loan-name"><?php echo $index + 1; ?>. <?php echo htmlspecialchars($loan['loan_name']); ?></span>
                                    <div class="top-loan-count"><?php echo $loan['customer_count']; ?> customers</div>
                                </div>
                                <div class="top-loan-stats">
                                    <div class="top-loan-value">₹<?php echo number_format($loan['total_collected']/100000, 2); ?>L</div>
                                    <div class="top-loan-count">of ₹<?php echo number_format($loan['total_disbursed']/100000, 2); ?>L</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Collection vs Outstanding Chart -->
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e);">
                            <h5><i class="bi bi-pie-chart me-2"></i>Portfolio Summary</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $total_collected = $overall['total_principal_paid'] + $overall['total_interest_paid'] + $overall['total_overdue_paid'];
                            $total_outstanding = $overall['total_outstanding_principal'] + $overall['total_outstanding_interest'] + $overall['total_outstanding_overdue'];
                            $total_portfolio = $total_collected + $total_outstanding;
                            
                            $collected_percent = $total_portfolio > 0 ? round(($total_collected / $total_portfolio) * 100, 1) : 0;
                            $outstanding_percent = $total_portfolio > 0 ? round(($total_outstanding / $total_portfolio) * 100, 1) : 0;
                            ?>
                            
                            <div class="text-center mb-3">
                                <div class="display-6 fw-bold">₹<?php echo number_format($total_portfolio/100000, 2); ?>L</div>
                                <div class="text-muted">Total Portfolio</div>
                            </div>
                            
                            <div class="progress mb-3" style="height: 25px;">
                                <div class="progress-bar bg-success" style="width: <?php echo $collected_percent; ?>%;" title="Collected: <?php echo $collected_percent; ?>%">
                                    <?php echo $collected_percent; ?>%
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?php echo $outstanding_percent; ?>%;" title="Outstanding: <?php echo $outstanding_percent; ?>%">
                                    <?php echo $outstanding_percent; ?>%
                                </div>
                            </div>
                            
                            <div class="row text-center mt-3">
                                <div class="col-6">
                                    <div class="h5 mb-0 text-success">₹<?php echo number_format($total_collected/100000, 2); ?>L</div>
                                    <div class="small text-muted">Collected (<?php echo $collected_percent; ?>%)</div>
                                </div>
                                <div class="col-6">
                                    <div class="h5 mb-0 text-warning">₹<?php echo number_format($total_outstanding/100000, 2); ?>L</div>
                                    <div class="small text-muted">Outstanding (<?php echo $outstanding_percent; ?>%)</div>
                                </div>
                            </div>
                            
                            <hr class="my-3">
                            
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="fw-bold text-primary">₹<?php echo number_format($overall['total_principal_paid']/100000, 2); ?>L</div>
                                    <div class="small text-muted">Principal</div>
                                </div>
                                <div class="col-4">
                                    <div class="fw-bold text-primary">₹<?php echo number_format($overall['total_interest_paid']/100000, 2); ?>L</div>
                                    <div class="small text-muted">Interest</div>
                                </div>
                                <div class="col-4">
                                    <div class="fw-bold text-danger">₹<?php echo number_format($overall['total_outstanding_overdue']/100000, 2); ?>L</div>
                                    <div class="small text-muted">Overdue</div>
                                </div>
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
    <?php if ($loans_summary && $loans_summary->num_rows > 0): ?>
    $('#loanTable').DataTable({
        pageLength: 25,
        order: [[2, 'desc']],
        language: {
            search: "Search:",
            lengthMenu: "_MENU_",
            info: "Showing _START_-_END_ of _TOTAL_ loans",
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

// Auto-hide alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        bootstrap.Alert.getOrCreateInstance(alert).close();
    });
}, 4000);
</script>
</body>
</html>