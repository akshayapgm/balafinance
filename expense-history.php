<?php
require_once 'includes/auth.php';
$currentPage = 'expense-history';
$pageTitle = 'Expense History';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to view expense history
if ($user_role != 'admin' && $user_role != 'accountant' && $user_role != 'staff') {
    $error = "You don't have permission to view expense history.";
}

$message = '';
$error = isset($error) ? $error : '';

// Handle expense deletion from history (admin only)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $user_role == 'admin') {
    $delete_id = intval($_GET['delete']);
    
    // Get expense details for logging and balance reversal
    $expense_query = "SELECT amount, remark, expense_date FROM expenses WHERE id = ? AND finance_id = ?";
    $stmt = $conn->prepare($expense_query);
    $stmt->bind_param("ii", $delete_id, $finance_id);
    $stmt->execute();
    $expense_result = $stmt->get_result();
    $expense = $expense_result->fetch_assoc();
    $stmt->close();
    
    if ($expense) {
        // Delete the expense
        $delete_query = "DELETE FROM expenses WHERE id = ? AND finance_id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("ii", $delete_id, $finance_id);
        
        if ($stmt->execute()) {
            // Reverse balance (add back the amount)
            $balance_query = "INSERT INTO total_balance 
                             (transaction_type, amount, balance, description, transaction_date, finance_id) 
                             SELECT 'expense_reversal', ?, 
                             (SELECT COALESCE(SUM(amount), 0) FROM total_balance WHERE finance_id = ?) + ?,
                             CONCAT('Expense reversal: ', ?),
                             ?, ?";
            $balance_stmt = $conn->prepare($balance_query);
            $balance_stmt->bind_param("ddisi", $expense['amount'], $finance_id, $expense['amount'], $expense['remark'], $expense['expense_date'], $finance_id);
            $balance_stmt->execute();
            $balance_stmt->close();
            
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'delete_expense', "Deleted expense from history: ₹{$expense['amount']} for {$expense['remark']}");
            }
            
            $message = "Expense deleted successfully!";
        } else {
            $error = "Error deleting expense: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$category_filter = isset($_GET['category']) ? mysqli_real_escape_string($conn, $_GET['category']) : '';
$year_filter = isset($_GET['year']) ? intval($_GET['year']) : 0;
$month_filter = isset($_GET['month']) ? intval($_GET['month']) : 0;
$sort_by = isset($_GET['sort']) ? mysqli_real_escape_string($conn, $_GET['sort']) : 'date_desc';

// Build query with filters
$where_clauses = ["finance_id = $finance_id"];

if (!empty($search)) {
    $where_clauses[] = "(remark LIKE '%$search%' OR category LIKE '%$search%')";
}

if (!empty($category_filter)) {
    $where_clauses[] = "category = '$category_filter'";
}

if ($year_filter > 0) {
    $where_clauses[] = "YEAR(expense_date) = $year_filter";
    
    if ($month_filter > 0) {
        $where_clauses[] = "MONTH(expense_date) = $month_filter";
    }
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// Determine sort order
$order_by = "ORDER BY expense_date DESC, id DESC";
switch ($sort_by) {
    case 'date_asc':
        $order_by = "ORDER BY expense_date ASC, id ASC";
        break;
    case 'amount_desc':
        $order_by = "ORDER BY amount DESC, expense_date DESC";
        break;
    case 'amount_asc':
        $order_by = "ORDER BY amount ASC, expense_date DESC";
        break;
    case 'category_asc':
        $order_by = "ORDER BY category ASC, expense_date DESC";
        break;
    default:
        $order_by = "ORDER BY expense_date DESC, id DESC";
}

// Get expenses with pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$expenses_query = "SELECT * FROM expenses 
                   $where_sql 
                   $order_by 
                   LIMIT $offset, $limit";
$expenses = $conn->query($expenses_query);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM expenses $where_sql";
$count_result = $conn->query($count_query);
$total_records = $count_result ? $count_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_records / $limit);

// Get expense categories for filter
$categories_query = "SELECT DISTINCT category, COUNT(*) as count FROM expenses WHERE finance_id = $finance_id GROUP BY category ORDER BY category";
$categories = $conn->query($categories_query);

// Get years for filter
$years_query = "SELECT DISTINCT YEAR(expense_date) as year FROM expenses WHERE finance_id = $finance_id ORDER BY year DESC";
$years = $conn->query($years_query);

// Get comprehensive statistics
$stats_query = "SELECT 
                COUNT(*) as total_expenses,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(AVG(amount), 0) as avg_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount,
                MIN(expense_date) as earliest_date,
                MAX(expense_date) as latest_date,
                COUNT(DISTINCT category) as category_count
                FROM expenses 
                WHERE finance_id = $finance_id";
$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];

// Get monthly breakdown for current year
$current_year = date('Y');
$monthly_query = "SELECT 
                  MONTH(expense_date) as month,
                  COUNT(*) as expense_count,
                  COALESCE(SUM(amount), 0) as month_total
                  FROM expenses 
                  WHERE finance_id = $finance_id AND YEAR(expense_date) = $current_year
                  GROUP BY MONTH(expense_date)
                  ORDER BY month";
$monthly_result = $conn->query($monthly_query);
$monthly_data = [];
if ($monthly_result && $monthly_result->num_rows > 0) {
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[$row['month']] = $row;
    }
}

// Get category summary
$category_summary_query = "SELECT 
                          category,
                          COUNT(*) as count,
                          COALESCE(SUM(amount), 0) as total,
                          COALESCE(AVG(amount), 0) as average,
                          MIN(amount) as min,
                          MAX(amount) as max
                          FROM expenses 
                          WHERE finance_id = $finance_id
                          GROUP BY category
                          ORDER BY total DESC";
$category_summary = $conn->query($category_summary_query);

// Get daily average for current month
$current_month = date('Y-m');
$daily_avg_query = "SELECT 
                   COALESCE(AVG(daily_total), 0) as daily_avg
                   FROM (
                       SELECT expense_date, SUM(amount) as daily_total
                       FROM expenses
                       WHERE finance_id = $finance_id AND DATE_FORMAT(expense_date, '%Y-%m') = '$current_month'
                       GROUP BY expense_date
                   ) as daily";
$daily_avg_result = $conn->query($daily_avg_query);
$daily_avg = $daily_avg_result ? $daily_avg_result->fetch_assoc()['daily_avg'] : 0;

// Get largest expense
$largest_query = "SELECT * FROM expenses WHERE finance_id = $finance_id ORDER BY amount DESC LIMIT 1";
$largest_result = $conn->query($largest_query);
$largest_expense = $largest_result ? $largest_result->fetch_assoc() : null;

// Get most frequent category
$frequent_query = "SELECT category, COUNT(*) as count FROM expenses WHERE finance_id = $finance_id GROUP BY category ORDER BY count DESC LIMIT 1";
$frequent_result = $conn->query($frequent_query);
$frequent_category = $frequent_result ? $frequent_result->fetch_assoc() : null;
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
            --expense-bg: #fee2e2;
            --expense: #ef4444;
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
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--expense), #dc2626);
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            width: 100%;
        }
        
        .welcome-banner h3 {
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-size: clamp(1.5rem, 5vw, 2rem);
            word-wrap: break-word;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: clamp(0.9rem, 3vw, 1rem);
            word-wrap: break-word;
        }
        
        .welcome-banner .btn-light {
            background: white;
            color: var(--expense);
            border: none;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            border-radius: 30px;
            font-size: clamp(0.85rem, 3vw, 1rem);
            white-space: nowrap;
        }
        
        .welcome-banner .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Stat Cards */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
            transition: all 0.2s ease;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--expense);
        }
        
        .stat-card:active {
            transform: scale(0.98);
        }
        
        .stat-label {
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .stat-value {
            font-size: clamp(1.5rem, 5vw, 2rem);
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            word-break: break-word;
            overflow-wrap: break-word;
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
        
        .stat-icon.expense {
            background: var(--expense-bg);
            color: var(--expense);
        }
        
        .stat-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .stat-icon.info {
            background: var(--info-bg);
            color: var(--info);
        }
        
        .stat-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .stat-footer {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 2rem;
            width: 100%;
        }
        
        .filter-card .form-control,
        .filter-card .form-select,
        .filter-card .btn {
            font-size: clamp(0.85rem, 3vw, 1rem);
        }
        
        /* Summary Cards */
        .summary-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
        }
        
        .summary-title {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .summary-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        .highlight-box {
            background: var(--expense-bg);
            border-radius: 12px;
            padding: 0.75rem;
            text-align: center;
        }
        
        .highlight-label {
            font-size: 0.8rem;
            color: var(--expense);
        }
        
        .highlight-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--expense);
        }
        
        /* Month Cards */
        .month-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            text-align: center;
            transition: all 0.2s ease;
        }
        
        .month-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--expense);
        }
        
        .month-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .month-amount {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--expense);
        }
        
        .month-count {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .progress {
            height: 6px;
            border-radius: 3px;
            margin-top: 0.5rem;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, var(--expense), #dc2626);
            border-radius: 3px;
        }
        
        /* Category Items */
        .category-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-info {
            flex: 1;
        }
        
        .category-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .category-stats {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .category-total {
            font-weight: 700;
            color: var(--expense);
            margin-left: 1rem;
        }
        
        .category-average {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: right;
        }
        
        /* Table Styles */
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }
        
        .table-custom th {
            text-align: left;
            padding: 1rem;
            background: #f8f9fa;
            color: var(--text-muted);
            font-weight: 600;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        
        .table-custom td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: clamp(0.8rem, 2.8vw, 0.9rem);
            word-break: break-word;
            overflow-wrap: break-word;
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .table-custom tbody tr.expense-row {
            border-left: 4px solid var(--expense);
        }
        
        .amount-col {
            font-weight: 600;
            color: var(--expense);
        }
        
        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        
        .pagination .page-link {
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem 0.75rem;
            margin: 0 0.2rem;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .pagination .page-link:hover {
            background: var(--expense);
            color: white;
            border-color: var(--expense);
        }
        
        .pagination .page-item.active .page-link {
            background: var(--expense);
            border-color: var(--expense);
            color: white;
        }
        
        .pagination .page-item.disabled .page-link {
            color: var(--text-muted);
            pointer-events: none;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.4rem;
            border-radius: 6px;
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            min-width: 32px;
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .action-buttons .btn i {
            font-size: clamp(0.8rem, 2.8vw, 0.9rem);
        }
        
        .action-buttons .btn:active {
            transform: scale(0.95);
        }
        
        /* Badge Styles */
        .category-badge {
            background: var(--warning-bg);
            color: var(--warning);
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            font-size: clamp(0.65rem, 2.2vw, 0.7rem);
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
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
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .page-content {
                padding: 1rem;
            }
            
            .table-container {
                padding: 1rem;
                margin: 0 -0.5rem;
                width: calc(100% + 1rem);
                border-radius: 0;
            }
            
            .table-custom {
                min-width: 800px;
            }
            
            .welcome-banner .d-flex {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
            
            .filter-card .row {
                --bs-gutter-y: 0.5rem;
            }
            
            .filter-card .col-md-3,
            .filter-card .col-md-2 {
                width: 100%;
            }
            
            .filter-card .btn {
                width: 100%;
            }
            
            .category-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .category-total {
                margin-left: 0;
            }
        }
        
        @media (max-width: 576px) {
            .page-content {
                padding: 0.75rem;
            }
            
            .welcome-banner {
                padding: 1rem;
            }
            
            .welcome-banner h3 {
                font-size: 1.2rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 1.2rem;
            }
            
            .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 1.2rem;
            }
            
            .summary-value {
                font-size: 1.2rem;
            }
            
            .action-buttons .btn {
                min-width: 36px;
                min-height: 36px;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .stat-card,
        .month-card {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Empty state */
        .empty-state {
            padding: 3rem 1rem;
            max-width: 100%;
            overflow: hidden;
        }
        
        .empty-state i {
            font-size: clamp(2.5rem, 10vw, 3rem);
        }
        
        .empty-state h5 {
            font-size: clamp(1.1rem, 4vw, 1.3rem);
            word-wrap: break-word;
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
            <!-- Welcome Banner -->
            <div class="welcome-banner" data-testid="welcome-banner">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div class="overflow-hidden" style="max-width: 100%;">
                        <h3 class="text-truncate-custom"><i class="bi bi-clock-history me-2"></i>Expense History</h3>
                        <p class="text-truncate-custom">Complete historical view of all expenses</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <a href="expenses.php" class="btn btn-light">
                            <i class="bi bi-plus-circle me-2"></i>Add Expense
                        </a>
                        <span class="badge bg-white text-danger">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <span class="break-word"><?php echo $message; ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <span class="break-word"><?php echo $error; ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Lifetime Statistics -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Total Expenses</div>
                                <div class="stat-value"><?php echo number_format($stats['total_expenses'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon expense flex-shrink-0"><i class="bi bi-receipt"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Lifetime expenses</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Total Amount</div>
                                <div class="stat-value break-word">₹<?php echo number_format($stats['total_amount'] ?? 0, 0); ?></div>
                            </div>
                            <div class="stat-icon warning flex-shrink-0"><i class="bi bi-currency-rupee"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Total spent</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Categories</div>
                                <div class="stat-value"><?php echo number_format($stats['category_count'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon info flex-shrink-0"><i class="bi bi-tags"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Unique categories</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                <div class="stat-label text-truncate">Average</div>
                                <div class="stat-value">₹<?php echo number_format($stats['avg_amount'] ?? 0, 0); ?></div>
                            </div>
                            <div class="stat-icon success flex-shrink-0"><i class="bi bi-graph-up"></i></div>
                        </div>
                        <div class="stat-footer text-truncate">
                            <span class="text-muted">Per expense</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Highlights Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="summary-card">
                        <div class="summary-title">Earliest Expense</div>
                        <div class="summary-value"><?php echo $stats['earliest_date'] ? date('d M Y', strtotime($stats['earliest_date'])) : 'N/A'; ?></div>
                        <div class="summary-desc">First recorded expense</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card">
                        <div class="summary-title">Latest Expense</div>
                        <div class="summary-value"><?php echo $stats['latest_date'] ? date('d M Y', strtotime($stats['latest_date'])) : 'N/A'; ?></div>
                        <div class="summary-desc">Most recent expense</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card">
                        <div class="summary-title">Daily Average</div>
                        <div class="summary-value">₹<?php echo number_format($daily_avg, 0); ?></div>
                        <div class="summary-desc">This month</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="summary-card">
                        <div class="summary-title">Largest Expense</div>
                        <div class="summary-value">₹<?php echo $largest_expense ? number_format($largest_expense['amount'], 0) : '0'; ?></div>
                        <div class="summary-desc text-truncate"><?php echo $largest_expense ? htmlspecialchars($largest_expense['remark']) : 'N/A'; ?></div>
                    </div>
                </div>
            </div>

            <!-- Monthly Breakdown -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="chart-card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.5rem;">
                        <h5 class="mb-3"><i class="bi bi-calendar-month me-2"></i>Monthly Breakdown - <?php echo $current_year; ?></h5>
                        <div class="row g-2">
                            <?php 
                            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            for ($m = 1; $m <= 12; $m++): 
                                $has_data = isset($monthly_data[$m]);
                                $amount = $has_data ? $monthly_data[$m]['month_total'] : 0;
                                $count = $has_data ? $monthly_data[$m]['expense_count'] : 0;
                                
                                // Calculate percentage for progress bar
                                $max_monthly = 0;
                                foreach ($monthly_data as $data) {
                                    $max_monthly = max($max_monthly, $data['month_total']);
                                }
                                $percentage = $max_monthly > 0 ? ($amount / $max_monthly) * 100 : 0;
                            ?>
                            <div class="col-6 col-md-3 col-lg-2">
                                <div class="month-card">
                                    <div class="month-name"><?php echo $months[$m-1]; ?></div>
                                    <div class="month-amount">₹<?php echo number_format($amount, 0); ?></div>
                                    <div class="month-count"><?php echo $count; ?> expenses</div>
                                    <div class="progress">
                                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Analysis -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="chart-card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.5rem;">
                        <h5 class="mb-3"><i class="bi bi-pie-chart me-2"></i>Category Analysis</h5>
                        <?php if ($category_summary && $category_summary->num_rows > 0): ?>
                            <?php 
                            $total_expense_amount = $stats['total_amount'] ?? 1;
                            while ($cat = $category_summary->fetch_assoc()): 
                                $percentage = ($cat['total'] / $total_expense_amount) * 100;
                            ?>
                            <div class="category-item">
                                <div class="category-info">
                                    <div class="category-name"><?php echo htmlspecialchars($cat['category']); ?></div>
                                    <div class="category-stats">
                                        <?php echo $cat['count']; ?> expenses | Avg: ₹<?php echo number_format($cat['average'], 0); ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="category-total">₹<?php echo number_format($cat['total'], 0); ?></div>
                                    <div class="category-average"><?php echo number_format($percentage, 1); ?>%</div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">No category data available</p>
                        <?php endif; ?>
                        
                        <?php if ($frequent_category): ?>
                        <div class="highlight-box mt-3">
                            <div class="highlight-label">Most Frequent Category</div>
                            <div class="highlight-value"><?php echo htmlspecialchars($frequent_category['category']); ?></div>
                            <div class="highlight-label"><?php echo $frequent_category['count']; ?> expenses</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="chart-card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 1.5rem;">
                        <h5 class="mb-3"><i class="bi bi-info-circle me-2"></i>Expense Insights</h5>
                        
                        <?php
                        // Calculate some insights
                        $total_days = 1;
                        if ($stats['earliest_date'] && $stats['latest_date']) {
                            $earliest = new DateTime($stats['earliest_date']);
                            $latest = new DateTime($stats['latest_date']);
                            $interval = $earliest->diff($latest);
                            $total_days = $interval->days + 1;
                        }
                        $daily_avg_lifetime = $total_days > 0 ? ($stats['total_amount'] / $total_days) : 0;
                        
                        // Find peak month
                        $peak_month = null;
                        $peak_amount = 0;
                        foreach ($monthly_data as $m => $data) {
                            if ($data['month_total'] > $peak_amount) {
                                $peak_amount = $data['month_total'];
                                $peak_month = $m;
                            }
                        }
                        ?>
                        
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="summary-title">Daily Average</div>
                                    <div class="summary-value">₹<?php echo number_format($daily_avg_lifetime, 0); ?></div>
                                    <div class="summary-desc">Lifetime</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="summary-title">Min Expense</div>
                                    <div class="summary-value">₹<?php echo number_format($stats['min_amount'] ?? 0, 0); ?></div>
                                    <div class="summary-desc">Smallest amount</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="summary-title">Max Expense</div>
                                    <div class="summary-value">₹<?php echo number_format($stats['max_amount'] ?? 0, 0); ?></div>
                                    <div class="summary-desc">Largest amount</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="summary-card">
                                    <div class="summary-title">Range</div>
                                    <div class="summary-value">₹<?php echo number_format(($stats['max_amount'] - $stats['min_amount']), 0); ?></div>
                                    <div class="summary-desc">Min to Max</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($peak_month): ?>
                        <div class="highlight-box mt-3">
                            <div class="highlight-label">Peak Spending Month</div>
                            <div class="highlight-value"><?php echo date('M Y', mktime(0, 0, 0, $peak_month, 1, $current_year)); ?></div>
                            <div class="highlight-label">₹<?php echo number_format($peak_amount, 0); ?> total</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2">
                        <div class="col-12 col-md-3">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control border-start-0" name="search" 
                                       placeholder="Search by description or category..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <div class="col-12 col-md-2">
                            <select class="form-select" name="category">
                                <option value="">All Categories</option>
                                <?php if ($categories && $categories->num_rows > 0): ?>
                                    <?php 
                                    $categories->data_seek(0);
                                    while ($cat = $categories->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                            <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category']); ?> (<?php echo $cat['count']; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-2">
                            <select class="form-select" name="year" id="year">
                                <option value="0">All Years</option>
                                <?php if ($years && $years->num_rows > 0): ?>
                                    <?php 
                                    $years->data_seek(0);
                                    while ($year = $years->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $year['year']; ?>" 
                                            <?php echo $year_filter == $year['year'] ? 'selected' : ''; ?>>
                                            <?php echo $year['year']; ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-2">
                            <select class="form-select" name="month" id="month" <?php echo $year_filter == 0 ? 'disabled' : ''; ?>>
                                <option value="0">All Months</option>
                                <option value="1" <?php echo $month_filter == 1 ? 'selected' : ''; ?>>January</option>
                                <option value="2" <?php echo $month_filter == 2 ? 'selected' : ''; ?>>February</option>
                                <option value="3" <?php echo $month_filter == 3 ? 'selected' : ''; ?>>March</option>
                                <option value="4" <?php echo $month_filter == 4 ? 'selected' : ''; ?>>April</option>
                                <option value="5" <?php echo $month_filter == 5 ? 'selected' : ''; ?>>May</option>
                                <option value="6" <?php echo $month_filter == 6 ? 'selected' : ''; ?>>June</option>
                                <option value="7" <?php echo $month_filter == 7 ? 'selected' : ''; ?>>July</option>
                                <option value="8" <?php echo $month_filter == 8 ? 'selected' : ''; ?>>August</option>
                                <option value="9" <?php echo $month_filter == 9 ? 'selected' : ''; ?>>September</option>
                                <option value="10" <?php echo $month_filter == 10 ? 'selected' : ''; ?>>October</option>
                                <option value="11" <?php echo $month_filter == 11 ? 'selected' : ''; ?>>November</option>
                                <option value="12" <?php echo $month_filter == 12 ? 'selected' : ''; ?>>December</option>
                            </select>
                        </div>
                        
                        <div class="col-12 col-md-3">
                            <div class="d-flex gap-2">
                                <select class="form-select" name="sort">
                                    <option value="date_desc" <?php echo $sort_by == 'date_desc' ? 'selected' : ''; ?>>Newest First</option>
                                    <option value="date_asc" <?php echo $sort_by == 'date_asc' ? 'selected' : ''; ?>>Oldest First</option>
                                    <option value="amount_desc" <?php echo $sort_by == 'amount_desc' ? 'selected' : ''; ?>>Highest Amount</option>
                                    <option value="amount_asc" <?php echo $sort_by == 'amount_asc' ? 'selected' : ''; ?>>Lowest Amount</option>
                                    <option value="category_asc" <?php echo $sort_by == 'category_asc' ? 'selected' : ''; ?>>Category (A-Z)</option>
                                </select>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-funnel"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Expenses Table -->
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table-custom" id="expensesTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Added On</th>
                                <?php if ($user_role == 'admin'): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expenses && $expenses->num_rows > 0): ?>
                                <?php while ($expense = $expenses->fetch_assoc()): ?>
                                <tr class="expense-row">
                                    <td><span class="fw-semibold">#<?php echo $expense['id']; ?></span></td>
                                    <td><span class="text-nowrap"><?php echo date('d M Y', strtotime($expense['expense_date'])); ?></span></td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($expense['remark']); ?>">
                                            <?php echo htmlspecialchars($expense['remark']); ?>
                                        </div>
                                    </td>
                                    <td><span class="category-badge"><?php echo htmlspecialchars($expense['category']); ?></span></td>
                                    <td class="amount-col">₹<?php echo number_format($expense['amount'], 2); ?></td>
                                    <td><?php echo date('d M Y', strtotime($expense['created_at'])); ?></td>
                                    <?php if ($user_role == 'admin'): ?>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?delete=<?php echo $expense['id']; ?>&<?php echo http_build_query($_GET); ?>" 
                                               class="btn btn-sm btn-outline-danger" title="Delete"
                                               onclick="return confirm('Are you sure you want to delete this expense from history? This action cannot be undone.')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $user_role == 'admin' ? '7' : '6'; ?>" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="bi bi-wallet2 text-danger" style="font-size: 2rem;"></i>
                                            <h6 class="mt-2 text-muted">No expenses found matching your criteria</h6>
                                            <?php if (!empty($search) || !empty($category_filter) || $year_filter > 0 || $month_filter > 0): ?>
                                                <a href="expense-history.php" class="btn btn-outline-danger btn-sm mt-2">Clear Filters</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-container">
                    <nav aria-label="Expense history pagination">
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                
                <?php if ($total_records > 0): ?>
                <div class="text-muted small mt-3 text-center">
                    Showing <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> expenses
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // Enable/disable month dropdown based on year selection
    document.getElementById('year').addEventListener('change', function() {
        const monthSelect = document.getElementById('month');
        if (this.value == '0') {
            monthSelect.disabled = true;
            monthSelect.value = '0';
        } else {
            monthSelect.disabled = false;
        }
    });

    // Load saved filter preferences
    window.onload = function() {
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
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Add loading state to filter button
    document.getElementById('filterForm').addEventListener('submit', function() {
        const btn = this.querySelector('button[type="submit"]');
        btn.classList.add('btn-loading');
        btn.disabled = true;
    });
</script>
</body>
</html>