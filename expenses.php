<?php
require_once 'includes/auth.php';
$currentPage = 'expenses';
$pageTitle = 'Manage Expenses';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to manage expenses
if ($user_role != 'admin' && $user_role != 'accountant') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

// Handle expense addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $remark = trim($_POST['remark'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $category = trim($_POST['category'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $description = trim($_POST['description'] ?? '');

    if (empty($remark)) {
        $error = "Please enter a remark for the expense.";
    } elseif ($amount <= 0) {
        $error = "Please enter a valid amount.";
    } elseif (empty($category)) {
        $error = "Please select a category.";
    } elseif (empty($expense_date)) {
        $error = "Please select an expense date.";
    } elseif (strtotime($expense_date) > strtotime(date('Y-m-d'))) {
        $error = "Expense date cannot be in the future.";
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert expense - removed bill_number field as it doesn't exist in table
            $insert_sql = "INSERT INTO expenses (remark, amount, expense_date, category, payment_method, description, finance_id, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($insert_sql);
            $stmt->bind_param("sdssssi", $remark, $amount, $expense_date, $category, $payment_method, $description, $finance_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to add expense: " . $stmt->error);
            }
            $expense_id = $conn->insert_id;
            $stmt->close();

            // Update total balance (expense reduces balance)
            $balance_sql = "SELECT balance FROM total_balance WHERE finance_id = ? ORDER BY id DESC LIMIT 1";
            $stmt = $conn->prepare($balance_sql);
            $stmt->bind_param("i", $finance_id);
            $stmt->execute();
            $balance_result = $stmt->get_result();
            $last_balance = $balance_result->num_rows > 0 ? floatval($balance_result->fetch_assoc()['balance']) : 0;
            $stmt->close();
            
            $new_balance = $last_balance - $amount;
            
            $balance_insert_sql = "INSERT INTO total_balance 
                                  (transaction_type, amount, balance, description, transaction_date, finance_id)
                                  VALUES ('expense', ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($balance_insert_sql);
            $description_text = "Expense: $remark - Category: $category";
            $stmt->bind_param("ddssi", $amount, $new_balance, $description_text, $expense_date, $finance_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update balance: " . $stmt->error);
            }
            $stmt->close();

            // Log activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'add_expense', "Added expense: ₹$amount for $remark (Category: $category)");
            }

            $conn->commit();
            
            $_SESSION['success'] = "Expense added successfully! ₹" . number_format($amount, 2) . " for $remark";
            header("Location: expenses.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error adding expense: " . $e->getMessage();
        }
    }
}

// Handle expense deletion (admin only)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $user_role == 'admin') {
    $delete_id = intval($_GET['delete']);
    
    $conn->begin_transaction();
    
    try {
        // Get expense details for balance reversal
        $expense_query = "SELECT amount, remark, expense_date, category FROM expenses WHERE id = ? AND finance_id = ?";
        $stmt = $conn->prepare($expense_query);
        $stmt->bind_param("ii", $delete_id, $finance_id);
        $stmt->execute();
        $expense_result = $stmt->get_result();
        $expense = $expense_result->fetch_assoc();
        $stmt->close();
        
        if (!$expense) {
            throw new Exception("Expense not found.");
        }
        
        // Delete the expense
        $delete_query = "DELETE FROM expenses WHERE id = ? AND finance_id = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("ii", $delete_id, $finance_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting expense: " . $conn->error);
        }
        $stmt->close();
        
        // Reverse balance (add back the amount)
        $balance_sql = "SELECT balance FROM total_balance WHERE finance_id = ? ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($balance_sql);
        $stmt->bind_param("i", $finance_id);
        $stmt->execute();
        $balance_result = $stmt->get_result();
        $last_balance = $balance_result->num_rows > 0 ? floatval($balance_result->fetch_assoc()['balance']) : 0;
        $stmt->close();
        
        $new_balance = $last_balance + $expense['amount'];
        
        $balance_insert_sql = "INSERT INTO total_balance 
                              (transaction_type, amount, balance, description, transaction_date, finance_id) 
                              VALUES ('expense_reversal', ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($balance_insert_sql);
        $description_text = "Expense reversal: {$expense['remark']} (Category: {$expense['category']})";
        $stmt->bind_param("ddssi", $expense['amount'], $new_balance, $description_text, $expense['expense_date'], $finance_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to reverse balance: " . $stmt->error);
        }
        $stmt->close();
        
        // Log the activity
        if (function_exists('logActivity')) {
            logActivity($conn, 'delete_expense', "Deleted expense: ₹{$expense['amount']} for {$expense['remark']}");
        }
        
        $conn->commit();
        
        $_SESSION['success'] = "Expense deleted successfully!";
        header("Location: expenses.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// Get filter parameters for expense history
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';
$year_filter = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Build query for recent expenses
$recent_query = "SELECT * FROM expenses WHERE finance_id = ?";
$recent_params = [$finance_id];
$recent_types = "i";

if (!empty($search)) {
    $recent_query .= " AND (remark LIKE ? OR category LIKE ?)";
    $search_param = "%$search%";
    $recent_params[] = $search_param;
    $recent_params[] = $search_param;
    $recent_types .= "ss";
}

if (!empty($category_filter)) {
    $recent_query .= " AND category = ?";
    $recent_params[] = $category_filter;
    $recent_types .= "s";
}

if (!empty($month_filter)) {
    $recent_query .= " AND MONTH(expense_date) = ?";
    $recent_params[] = $month_filter;
    $recent_types .= "i";
}

if (!empty($year_filter)) {
    $recent_query .= " AND YEAR(expense_date) = ?";
    $recent_params[] = $year_filter;
    $recent_types .= "i";
}

$recent_query .= " ORDER BY expense_date DESC, id DESC LIMIT 20";

$stmt = $conn->prepare($recent_query);
if ($stmt) {
    $stmt->bind_param($recent_types, ...$recent_params);
    $stmt->execute();
    $recent_expenses = $stmt->get_result();
    $stmt->close();
}

// Get summary statistics
$stats_query = "SELECT 
                COUNT(*) as total_expenses,
                COALESCE(SUM(amount), 0) as total_amount,
                COALESCE(AVG(amount), 0) as avg_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount,
                COUNT(DISTINCT category) as category_count,
                MIN(expense_date) as first_expense,
                MAX(expense_date) as last_expense
                FROM expenses 
                WHERE finance_id = ?";

$stats_result = $conn->execute_query($stats_query, [$finance_id]);
$stats = $stats_result->fetch_assoc();

// Get monthly totals for current year
$monthly_query = "SELECT 
                  MONTH(expense_date) as month,
                  COUNT(*) as expense_count,
                  COALESCE(SUM(amount), 0) as month_total
                  FROM expenses 
                  WHERE finance_id = ? AND YEAR(expense_date) = ?
                  GROUP BY MONTH(expense_date)
                  ORDER BY month";

$monthly_result = $conn->execute_query($monthly_query, [$finance_id, $year_filter]);
$monthly_data = [];
while ($row = $monthly_result->fetch_assoc()) {
    $monthly_data[$row['month']] = $row;
}

// Get category summary
$category_query = "SELECT 
                  category,
                  COUNT(*) as count,
                  COALESCE(SUM(amount), 0) as total,
                  COALESCE(AVG(amount), 0) as average,
                  MIN(amount) as min,
                  MAX(amount) as max
                  FROM expenses 
                  WHERE finance_id = ?
                  GROUP BY category
                  ORDER BY total DESC
                  LIMIT 10";

$category_result = $conn->execute_query($category_query, [$finance_id]);
$categories = [];
while ($row = $category_result->fetch_assoc()) {
    $categories[] = $row;
}

// Get unique categories for filter
$filter_categories_query = "SELECT DISTINCT category FROM expenses WHERE finance_id = ? ORDER BY category";
$filter_categories_result = $conn->execute_query($filter_categories_query, [$finance_id]);
$filter_categories = [];
while ($row = $filter_categories_result->fetch_assoc()) {
    $filter_categories[] = $row['category'];
}

// Get available years for filter
$years_query = "SELECT DISTINCT YEAR(expense_date) as year FROM expenses WHERE finance_id = ? ORDER BY year DESC";
$years_result = $conn->execute_query($years_query, [$finance_id]);
$available_years = [];
while ($row = $years_result->fetch_assoc()) {
    $available_years[] = $row['year'];
}

// Predefined categories
$predefined_categories = [
    'Salary',
    'Rent',
    'Electricity',
    'Water',
    'Internet',
    'Office Supplies',
    'Transportation',
    'Marketing',
    'Maintenance',
    'Legal',
    'Taxes',
    'Insurance',
    'Equipment',
    'Software',
    'Training',
    'Travel',
    'Meals',
    'Miscellaneous'
];
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
            --expense-color: #ef4444;
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
            background: linear-gradient(135deg, var(--expense-color), #dc2626);
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
            color: var(--expense-color);
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
            border-color: var(--expense-color);
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
        .stat-icon.expense { color: var(--expense-color); }
        .stat-icon.info { color: var(--info); }
        .stat-icon.purple { color: #8b5cf6; }
        
        /* Form Card */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .form-card h5 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .form-control, .form-select {
            font-size: 0.9rem;
            height: 38px;
        }
        
        .input-group-text {
            background: #f8fafc;
            border-color: var(--border-color);
            color: var(--text-muted);
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
            background: linear-gradient(135deg, var(--expense-color), #dc2626);
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
            min-width: 900px;
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
        
        .amount-expense {
            color: var(--expense-color);
            font-weight: 600;
        }
        
        /* Category Badge */
        .category-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            background: #fee2e2;
            color: var(--expense-color);
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
        
        .action-btn.delete {
            background: var(--danger);
        }
        
        .action-btn.view {
            background: var(--info);
        }
        
        .action-btn.edit {
            background: var(--warning);
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
            background: linear-gradient(135deg, var(--expense-color), #dc2626);
            border-radius: 4px 4px 0 0;
            min-height: 4px;
            transition: height 0.3s ease;
        }
        
        .category-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .category-item:last-child {
            border-bottom: none;
        }
        
        .category-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .category-stats {
            text-align: right;
        }
        
        .category-count {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-card .form-label {
            font-size: 0.75rem;
            margin-bottom: 0.1rem;
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
                        <h4><i class="bi bi-wallet2 me-2"></i>Manage Expenses</h4>
                        <p>Record and track all business expenses</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="expense-history.php" class="btn btn-light">
                            <i class="bi bi-clock-history me-2"></i>View History
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

            <?php if (isset($_SESSION['error']) || $error): ?>
                <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $_SESSION['error'] ?? $error; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon expense"><i class="bi bi-receipt"></i></div>
                    <div class="stat-label">TOTAL EXPENSES</div>
                    <div class="stat-value"><?php echo number_format($stats['total_expenses']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon expense"><i class="bi bi-currency-rupee"></i></div>
                    <div class="stat-label">TOTAL AMOUNT</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_amount'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-tags"></i></div>
                    <div class="stat-label">CATEGORIES</div>
                    <div class="stat-value"><?php echo number_format($stats['category_count']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="bi bi-calculator"></i></div>
                    <div class="stat-label">AVERAGE</div>
                    <div class="stat-value">₹<?php echo number_format($stats['avg_amount'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">FIRST</div>
                    <div class="stat-value"><?php echo $stats['first_expense'] ? date('d/m', strtotime($stats['first_expense'])) : '-'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">LATEST</div>
                    <div class="stat-value"><?php echo $stats['last_expense'] ? date('d/m', strtotime($stats['last_expense'])) : '-'; ?></div>
                </div>
            </div>

            <!-- Add Expense Form -->
            <div class="form-card">
                <h5><i class="bi bi-plus-circle me-2"></i>Add New Expense</h5>
                
                <form method="POST" action="" id="expenseForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Remark <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="remark" placeholder="e.g., Office rent, Salary" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Amount (₹) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="0.01" class="form-control" name="amount" placeholder="0.00" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="expense_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="category" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($predefined_categories as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="online">Online Payment</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <!-- Empty column for spacing -->
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Description (Optional)</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="Additional details about the expense"></textarea>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" name="add_expense" class="btn btn-danger">
                                <i class="bi bi-check-circle me-2"></i>Add Expense
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Monthly Chart and Category Summary -->
            <div class="row g-3">
                <!-- Monthly Chart -->
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5><i class="bi bi-graph-up me-2"></i>Monthly Expenses - <?php echo $year_filter; ?></h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            $max_monthly = 0;
                            foreach ($monthly_data as $data) {
                                $max_monthly = max($max_monthly, $data['month_total']);
                            }
                            ?>
                            <div class="mini-chart">
                                <?php for ($m = 1; $m <= 12; $m++): 
                                    $has_data = isset($monthly_data[$m]);
                                    $amount = $has_data ? $monthly_data[$m]['month_total'] : 0;
                                    $height = $max_monthly > 0 ? ($amount / $max_monthly) * 60 : 4;
                                ?>
                                <div class="chart-bar" style="height: <?php echo max(4, $height); ?>px;" 
                                     title="<?php echo $months[$m-1]; ?>: ₹<?php echo number_format($amount, 0); ?> (<?php echo $has_data ? $monthly_data[$m]['expense_count'] : 0; ?> expenses)"></div>
                                <?php endfor; ?>
                            </div>
                            <div class="row g-2 mt-3">
                                <?php for ($m = 1; $m <= 12; $m++): 
                                    $has_data = isset($monthly_data[$m]);
                                    $amount = $has_data ? $monthly_data[$m]['month_total'] : 0;
                                ?>
                                <div class="col-3 col-md-2">
                                    <div class="stat-box p-2 text-center">
                                        <div class="label"><?php echo $months[$m-1]; ?></div>
                                        <div class="value small amount-expense">₹<?php echo number_format($amount/1000, 1); ?>K</div>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Summary -->
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5><i class="bi bi-pie-chart me-2"></i>Top Expense Categories</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($categories)): ?>
                                <?php foreach ($categories as $cat): ?>
                                <div class="category-item">
                                    <div>
                                        <span class="category-name"><?php echo htmlspecialchars($cat['category']); ?></span>
                                        <div class="category-count"><?php echo $cat['count']; ?> transactions</div>
                                    </div>
                                    <div class="category-stats">
                                        <div class="amount-expense fw-bold">₹<?php echo number_format($cat['total'], 0); ?></div>
                                        <div class="category-count">Avg: ₹<?php echo number_format($cat['average'], 0); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-pie-chart text-muted"></i>
                                    <p class="mt-2 text-muted small">No category data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section for Recent Expenses -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="search" placeholder="Search remarks, category..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="category">
                                <option value="">All Categories</option>
                                <?php foreach ($filter_categories as $cat): ?>
                                <option value="<?php echo $cat; ?>" <?php echo $category_filter == $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="month">
                                <option value="">All Months</option>
                                <option value="1" <?php echo $month_filter == '1' ? 'selected' : ''; ?>>January</option>
                                <option value="2" <?php echo $month_filter == '2' ? 'selected' : ''; ?>>February</option>
                                <option value="3" <?php echo $month_filter == '3' ? 'selected' : ''; ?>>March</option>
                                <option value="4" <?php echo $month_filter == '4' ? 'selected' : ''; ?>>April</option>
                                <option value="5" <?php echo $month_filter == '5' ? 'selected' : ''; ?>>May</option>
                                <option value="6" <?php echo $month_filter == '6' ? 'selected' : ''; ?>>June</option>
                                <option value="7" <?php echo $month_filter == '7' ? 'selected' : ''; ?>>July</option>
                                <option value="8" <?php echo $month_filter == '8' ? 'selected' : ''; ?>>August</option>
                                <option value="9" <?php echo $month_filter == '9' ? 'selected' : ''; ?>>September</option>
                                <option value="10" <?php echo $month_filter == '10' ? 'selected' : ''; ?>>October</option>
                                <option value="11" <?php echo $month_filter == '11' ? 'selected' : ''; ?>>November</option>
                                <option value="12" <?php echo $month_filter == '12' ? 'selected' : ''; ?>>December</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" name="year">
                                <?php 
                                $current_year = date('Y');
                                for ($y = $current_year; $y >= $current_year - 5; $y--): 
                                ?>
                                <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-funnel me-1"></i>Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Recent Expenses Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h5><i class="bi bi-table me-2"></i>Recent Expenses</h5>
                </div>
                <div class="card-body">
                    <?php if ($recent_expenses && $recent_expenses->num_rows > 0): ?>
                        <div class="table-container">
                            <table id="expensesTable" class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Remark</th>
                                        <th>Category</th>
                                        <th>Amount</th>
                                        <th>Payment Method</th>
                                        <th>Added On</th>
                                        <?php if ($user_role == 'admin'): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php while ($expense = $recent_expenses->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d/m/y', strtotime($expense['expense_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($expense['remark']); ?></td>
                                        <td><span class="category-badge"><?php echo htmlspecialchars($expense['category']); ?></span></td>
                                        <td class="amount-expense fw-bold">₹<?php echo number_format($expense['amount'], 2); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $expense['payment_method'] ?? 'cash')); ?></td>
                                        <td><?php echo date('d/m/y', strtotime($expense['created_at'])); ?></td>
                                        <?php if ($user_role == 'admin'): ?>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="?delete=<?php echo $expense['id']; ?>" 
                                                   class="action-btn delete" 
                                                   onclick="return confirm('Are you sure you want to delete this expense? This action cannot be undone.')"
                                                   title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-end mt-3">
                            <a href="expense-history.php" class="btn btn-outline-danger btn-sm">
                                View Full History <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-wallet2 text-muted" style="font-size: 3rem;"></i>
                            <h6 class="mt-3 text-muted">No expenses found</h6>
                            <?php if (!empty($search) || !empty($category_filter) || !empty($month_filter)): ?>
                                <a href="expenses.php" class="btn btn-outline-danger btn-sm mt-2">
                                    <i class="bi bi-arrow-repeat me-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
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
    <?php if ($recent_expenses && $recent_expenses->num_rows > 0): ?>
    $('#expensesTable').DataTable({
        pageLength: 10,
        order: [[0, 'desc']],
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
            <?php if ($user_role == 'admin'): ?>
            { orderable: false, targets: [6] }
            <?php else: ?>
            { orderable: false, targets: [5] }
            <?php endif; ?>
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