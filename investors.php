<?php
require_once 'includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$currentPage = 'investors';
$pageTitle = 'Manage Investors';
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
        
        // Check if investor has any returns
        $check_query = "SELECT COUNT(*) as cnt FROM investor_returns WHERE investor_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param('i', $delete_id);
        $stmt->execute();
        $check_result = $stmt->get_result();
        $return_count = $check_result->fetch_assoc()['cnt'];
        $stmt->close();
        
        if ($return_count > 0) {
            // Soft delete - just mark as closed
            $update_query = "UPDATE investors SET status = 'closed' WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param('i', $delete_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Investor marked as closed successfully";
            } else {
                $error = "Error closing investor";
            }
            $stmt->close();
        } else {
            // Hard delete - no returns yet
            $delete_query = "DELETE FROM investors WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param('i', $delete_id);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Investor deleted successfully";
            } else {
                $error = "Error deleting investor";
            }
            $stmt->close();
        }
        
        header('Location: investors.php');
        exit();
    } else {
        $error = "You don't have permission to delete investors";
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$return_type_filter = isset($_GET['return_type']) ? $_GET['return_type'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Build query
$query = "
    SELECT 
        i.*,
        f.finance_name,
        u.full_name as created_by_name,
        DATEDIFF(i.maturity_date, CURDATE()) as days_to_maturity,
        CASE 
            WHEN i.status = 'active' AND i.maturity_date < CURDATE() THEN 'Overdue'
            WHEN i.status = 'active' THEN 'Active'
            WHEN i.status = 'closed' THEN 'Closed'
            ELSE i.status
        END as status_display,
        CASE 
            WHEN i.return_type = 'monthly' THEN 'Monthly'
            WHEN i.return_type = 'quarterly' THEN 'Quarterly'
            WHEN i.return_type = 'yearly' THEN 'Yearly'
            ELSE 'At Maturity'
        END as return_type_display
    FROM investors i
    JOIN finance f ON i.finance_id = f.id
    LEFT JOIN users u ON i.created_by = u.id
    WHERE 1=1
";

$params = [];
$types = "";

if ($user_role != 'admin') {
    $query .= " AND i.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if (!empty($search)) {
    $query .= " AND (i.investor_name LIKE ? OR i.investor_number LIKE ? OR i.pan_number LIKE ? OR i.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $query .= " AND i.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

if ($return_type_filter != 'all') {
    $query .= " AND i.return_type = ?";
    $params[] = $return_type_filter;
    $types .= "s";
}

if ($status_filter != 'all') {
    if ($status_filter == 'active') {
        $query .= " AND i.status = 'active' AND i.maturity_date >= CURDATE()";
    } elseif ($status_filter == 'overdue') {
        $query .= " AND i.status = 'active' AND i.maturity_date < CURDATE()";
    } elseif ($status_filter == 'closed') {
        $query .= " AND i.status = 'closed'";
    }
}

$query .= " ORDER BY i.created_at DESC";

$investors = null;
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $investors = $stmt->get_result();
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
        COUNT(*) as total_investors,
        SUM(CASE WHEN i.status = 'active' AND i.maturity_date >= CURDATE() THEN 1 ELSE 0 END) as active_investors,
        SUM(CASE WHEN i.status = 'active' AND i.maturity_date < CURDATE() THEN 1 ELSE 0 END) as overdue_investors,
        SUM(CASE WHEN i.status = 'closed' THEN 1 ELSE 0 END) as closed_investors,
        COALESCE(SUM(CASE WHEN i.status = 'active' THEN i.investment_amount ELSE 0 END), 0) as total_active_investment,
        COALESCE(SUM(i.investment_amount), 0) as total_investment,
        COALESCE(SUM(i.total_return_paid), 0) as total_returns_paid,
        AVG(i.interest_rate) as avg_interest_rate
    FROM investors i
";

if ($user_role != 'admin') {
    $stats_query .= " WHERE i.finance_id = $finance_id";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];
$stats = array_merge([
    'total_investors' => 0,
    'active_investors' => 0,
    'overdue_investors' => 0,
    'closed_investors' => 0,
    'total_active_investment' => 0,
    'total_investment' => 0,
    'total_returns_paid' => 0,
    'avg_interest_rate' => 0
], $stats);

// Get return type statistics
$return_type_stats = [];
$return_stats_query = "
    SELECT 
        return_type,
        COUNT(*) as count,
        COALESCE(SUM(investment_amount), 0) as total_amount
    FROM investors
    WHERE 1=1
";

if ($user_role != 'admin') {
    $return_stats_query .= " AND finance_id = $finance_id";
}
$return_stats_query .= " GROUP BY return_type";

$return_stats_result = $conn->query($return_stats_query);
while ($row = $return_stats_result->fetch_assoc()) {
    $return_type_stats[$row['return_type']] = $row;
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
            --primary-bg: #e6f0ff;
            --success-bg: #e3f7e3;
            --warning-bg: #fff3e0;
            --danger-bg: #ffe6e6;
            --info-bg: #e3f2fd;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: flex;
            flex-direction: column;
        }

        .app-wrapper {
            display: flex;
            flex: 1;
            min-height: 100vh;
            width: 100%;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .page-content {
            flex: 1;
            padding: 1.5rem;
            width: 100%;
            max-width: 100%;
            margin: 0;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            width: 100%;
        }

        .page-header h4 {
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.9rem;
        }

        .page-header .btn-light {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.4rem 1.2rem;
            font-weight: 500;
            border-radius: 2rem;
            backdrop-filter: blur(10px);
            transition: all 0.2s;
            font-size: 0.9rem;
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
            padding: 1.25rem;
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
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .stat-footer {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
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
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filter-chip {
            padding: 0.4rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
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

        /* Table Container */
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            overflow-x: auto;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: var(--light);
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
            padding: 0.75rem;
        }

        .table tbody td {
            vertical-align: middle;
            font-size: 0.85rem;
            padding: 0.75rem;
        }

        .investor-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .investor-avatar {
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
        }

        .investor-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
        }

        .investor-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        /* Status badges */
        .badge-status {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
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

        .badge-status.overdue {
            background: var(--danger-bg);
            color: var(--danger);
        }

        /* Return type badges */
        .badge-return {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
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

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
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
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .btn-view { background: var(--info); }
        .btn-edit { background: var(--warning); }
        .btn-return { background: var(--success); }
        .btn-delete { background: var(--danger); }

        /* Footer */
        .footer {
            margin-top: auto;
            padding: 0.75rem 1.5rem;
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
            font-size: 0.8rem;
            color: var(--text-muted);
            width: 100%;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .page-content {
                padding: 1rem;
            }
            
            .stat-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
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
                        <h4><i class="bi bi-people-fill me-2"></i>Manage Investors</h4>
                        <p>View and manage all investors</p>
                    </div>
                    <div>
                        <a href="add-investor.php" class="btn btn-light">
                            <i class="bi bi-person-plus-fill me-2"></i>Add New Investor
                        </a>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stat-row">
                <div class="stat-card primary">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-people"></i>
                                Total Investors
                            </div>
                            <div class="stat-value"><?php echo number_format($stats['total_investors']); ?></div>
                            <div class="stat-footer">
                                Active: <?php echo $stats['active_investors']; ?> | Closed: <?php echo $stats['closed_investors']; ?>
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
                            <div class="stat-value">₹<?php echo number_format($stats['total_investment'], 2); ?></div>
                            <div class="stat-footer">
                                Active: ₹<?php echo number_format($stats['total_active_investment'], 2); ?>
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
                                Avg Interest Rate
                            </div>
                            <div class="stat-value"><?php echo number_format($stats['avg_interest_rate'], 2); ?>%</div>
                            <div class="stat-footer">
                                Returns Paid: ₹<?php echo number_format($stats['total_returns_paid'], 2); ?>
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
                                <i class="bi bi-exclamation-triangle"></i>
                                Overdue
                            </div>
                            <div class="stat-value text-danger"><?php echo $stats['overdue_investors']; ?></div>
                            <div class="stat-footer">
                                Matured but not closed
                            </div>
                        </div>
                        <div class="stat-icon info">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" class="form-control" name="search" placeholder="Search by name, phone, PAN..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="col-md-2">
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="overdue" <?php echo $status_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <select class="form-select" name="return_type">
                                <option value="all" <?php echo $return_type_filter == 'all' ? 'selected' : ''; ?>>All Returns</option>
                                <option value="monthly" <?php echo $return_type_filter == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="quarterly" <?php echo $return_type_filter == 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="yearly" <?php echo $return_type_filter == 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                                <option value="maturity" <?php echo $return_type_filter == 'maturity' ? 'selected' : ''; ?>>At Maturity</option>
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
                        
                        <div class="col-md-<?php echo $user_role == 'admin' ? '3' : '5'; ?> d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="bi bi-funnel me-2"></i>Apply Filters
                            </button>
                            <a href="investors.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Investors Table -->
            <div class="table-container">
                <table id="investorsTable" class="table table-hover">
                    <thead>
                        <tr>
                            <th>Investor</th>
                            <th>Contact</th>
                            <th>Investment</th>
                            <th>Returns</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Finance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($investors && $investors->num_rows > 0): ?>
                            <?php while ($investor = $investors->fetch_assoc()): 
                                $status_class = 'active';
                                if ($investor['status_display'] == 'Overdue') {
                                    $status_class = 'overdue';
                                } elseif ($investor['status_display'] == 'Closed') {
                                    $status_class = 'closed';
                                }
                                
                                $return_class = $investor['return_type'];
                            ?>
                            <tr>
                                <td>
                                    <div class="investor-info">
                                        <div class="investor-avatar">
                                            <?php echo strtoupper(substr($investor['investor_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div class="investor-name"><?php echo htmlspecialchars($investor['investor_name']); ?></div>
                                            <div class="investor-meta">
                                                <?php if ($investor['pan_number']): ?>
                                                    PAN: <?php echo $investor['pan_number']; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($investor['investor_number']); ?></div>
                                    <?php if ($investor['email']): ?>
                                    <div class="small text-muted"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($investor['email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold">₹<?php echo number_format($investor['investment_amount'], 2); ?></div>
                                    <div class="small text-muted">@ <?php echo $investor['interest_rate']; ?>% p.a.</div>
                                </td>
                                <td>
                                    <span class="badge-return <?php echo $return_class; ?>">
                                        <?php echo $investor['return_type_display']; ?>
                                    </span>
                                    <div class="small text-muted mt-1">
                                        Return: ₹<?php echo number_format($investor['return_amount'], 2); ?>
                                    </div>
                                    <div class="small text-success">
                                        Paid: ₹<?php echo number_format($investor['total_return_paid'], 2); ?>
                                    </div>
                                </td>
                                <td>
                                    <div><i class="bi bi-calendar-plus me-1"></i><?php echo date('d M Y', strtotime($investor['investment_date'])); ?></div>
                                    <div class="small text-muted">
                                        <i class="bi bi-calendar-check me-1"></i>
                                        <?php echo $investor['maturity_date'] ? date('d M Y', strtotime($investor['maturity_date'])) : 'N/A'; ?>
                                    </div>
                                    <?php if ($investor['days_to_maturity'] !== null && $investor['status'] == 'active'): ?>
                                        <div class="small <?php echo $investor['days_to_maturity'] < 0 ? 'text-danger' : 'text-muted'; ?>">
                                            <?php echo $investor['days_to_maturity'] < 0 ? abs($investor['days_to_maturity']) . ' days overdue' : $investor['days_to_maturity'] . ' days left'; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status <?php echo $status_class; ?>">
                                        <?php echo $investor['status_display']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-info bg-opacity-10 text-info p-2">
                                        <i class="bi bi-building me-1"></i>
                                        <?php echo htmlspecialchars($investor['finance_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="view-investor.php?id=<?php echo $investor['id']; ?>" class="btn-action btn-view" data-bs-toggle="tooltip" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        
                                        <?php if ($user_role == 'admin' || $investor['status'] == 'active'): ?>
                                        <a href="investor-return.php?investor_id=<?php echo $investor['id']; ?>" class="btn-action btn-return" data-bs-toggle="tooltip" title="Record Return">
                                            <i class="bi bi-cash"></i>
                                        </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($user_role == 'admin'): ?>
                                        <a href="edit-investor.php?id=<?php echo $investor['id']; ?>" class="btn-action btn-edit" data-bs-toggle="tooltip" title="Edit Investor">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        
                                        <?php if ($investor['total_return_paid'] == 0): ?>
                                        <button type="button" class="btn-action btn-delete" data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                data-investor-id="<?php echo $investor['id']; ?>"
                                                data-investor-name="<?php echo htmlspecialchars($investor['investor_name']); ?>"
                                                title="Delete Investor">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="bi bi-people" style="font-size: 3rem; color: #dee2e6;"></i>
                                    <p class="mt-3 text-muted">No investors found</p>
                                    <a href="add-investor.php" class="btn btn-primary">
                                        <i class="bi bi-person-plus-fill me-2"></i>Add Your First Investor
                                    </a>
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
                <p>Are you sure you want to delete investor: <strong id="deleteInvestorName"></strong>?</p>
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
        if ($('#investorsTable tbody tr').length > 0 && $('#investorsTable tbody tr td').length > 1) {
            $('#investorsTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
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
                },
                columnDefs: [
                    { orderable: false, targets: [7] }
                ]
            });
        }

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Delete modal handler
        $('#deleteModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget);
            var investorId = button.data('investor-id');
            var investorName = button.data('investor-name');
            
            var modal = $(this);
            modal.find('#deleteInvestorName').text(investorName);
            modal.find('#deleteConfirmBtn').attr('href', 'investors.php?delete=' + investorId);
        });
    });
</script>
</body>
</html>