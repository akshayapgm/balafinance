<?php
require_once 'includes/auth.php';
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;
$user_id = $_SESSION['user_id'];

// Get today's date for queries
$today = date('Y-m-d');
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

// Get dashboard statistics based on role
$stats = [];

// Base queries with role-based filters
if ($user_role == 'admin') {
    // Admin sees all data
    $customer_query = "SELECT COUNT(*) as cnt FROM customers";
    $loan_query = "SELECT COUNT(*) as cnt FROM customers";
    $disbursed_query = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM customers";
    $collections_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE status = 'paid'";
    $pending_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE status IN ('unpaid', 'overdue')";
    $overdue_query = "SELECT COUNT(DISTINCT customer_id) as cnt FROM emi_schedule WHERE status = 'overdue'";
    $today_collections_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE paid_date = '$today'";
    $today_due_query = "SELECT COUNT(*) as cnt FROM emi_schedule WHERE emi_due_date = '$today' AND status = 'unpaid'";
    $expenses_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN '$month_start' AND '$month_end'";
    $balance_query = "SELECT COALESCE(SUM(amount), 0) as total FROM total_balance";
} elseif ($user_role == 'accountant') {
    // Accountant sees all financial data
    $customer_query = "SELECT COUNT(*) as cnt FROM customers";
    $loan_query = "SELECT COUNT(*) as cnt FROM customers";
    $disbursed_query = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM customers";
    $collections_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE status = 'paid'";
    $pending_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE status IN ('unpaid', 'overdue')";
    $overdue_query = "SELECT COUNT(DISTINCT customer_id) as cnt FROM emi_schedule WHERE status = 'overdue'";
    $today_collections_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE paid_date = '$today'";
    $today_due_query = "SELECT COUNT(*) as cnt FROM emi_schedule WHERE emi_due_date = '$today' AND status = 'unpaid'";
    $expenses_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE expense_date BETWEEN '$month_start' AND '$month_end'";
    $balance_query = "SELECT COALESCE(SUM(amount), 0) as total FROM total_balance";
} else {
    // Staff sees only their assigned finance company data
    $customer_query = "SELECT COUNT(*) as cnt FROM customers WHERE finance_id = $finance_id";
    $loan_query = "SELECT COUNT(*) as cnt FROM customers WHERE finance_id = $finance_id";
    $disbursed_query = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM customers WHERE finance_id = $finance_id";
    $collections_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE finance_id = $finance_id AND status = 'paid'";
    $pending_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE finance_id = $finance_id AND status IN ('unpaid', 'overdue')";
    $overdue_query = "SELECT COUNT(DISTINCT customer_id) as cnt FROM emi_schedule WHERE finance_id = $finance_id AND status = 'overdue'";
    $today_collections_query = "SELECT COALESCE(SUM(emi_amount), 0) as total FROM emi_schedule WHERE finance_id = $finance_id AND paid_date = '$today'";
    $today_due_query = "SELECT COUNT(*) as cnt FROM emi_schedule WHERE finance_id = $finance_id AND emi_due_date = '$today' AND status = 'unpaid'";
    $expenses_query = "SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE finance_id = $finance_id AND expense_date BETWEEN '$month_start' AND '$month_end'";
    $balance_query = "SELECT COALESCE(SUM(amount), 0) as total FROM total_balance WHERE finance_id = $finance_id";
}

// Execute queries
$result = $conn->query($customer_query);
$stats['total_customers'] = $result ? $result->fetch_assoc()['cnt'] : 0;

$result = $conn->query($loan_query);
$stats['total_loans'] = $result ? $result->fetch_assoc()['cnt'] : 0;

$result = $conn->query($disbursed_query);
$stats['total_disbursed'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query($collections_query);
$stats['total_collections'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query($pending_query);
$stats['pending_collections'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query($overdue_query);
$stats['overdue_customers'] = $result ? $result->fetch_assoc()['cnt'] : 0;

$result = $conn->query($today_collections_query);
$stats['today_collections'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query($today_due_query);
$stats['today_due'] = $result ? $result->fetch_assoc()['cnt'] : 0;

$result = $conn->query($expenses_query);
$stats['monthly_expenses'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query($balance_query);
$stats['current_balance'] = $result ? $result->fetch_assoc()['total'] : 0;

// Get role-specific data
if ($user_role == 'admin') {
    // Admin sees all recent collections
    $recent_collections = $conn->query("
        SELECT es.*, c.customer_name, c.agreement_number, f.finance_name 
        FROM emi_schedule es
        JOIN customers c ON es.customer_id = c.id
        JOIN finance f ON c.finance_id = f.id
        WHERE es.status = 'paid'
        ORDER BY es.paid_date DESC
        LIMIT 10
    ");
    
    $upcoming_dues = $conn->query("
        SELECT es.*, c.customer_name, c.customer_number, c.agreement_number, f.finance_name 
        FROM emi_schedule es
        JOIN customers c ON es.customer_id = c.id
        JOIN finance f ON c.finance_id = f.id
        WHERE es.status = 'unpaid' AND es.emi_due_date >= CURDATE()
        ORDER BY es.emi_due_date ASC
        LIMIT 10
    ");
    
    $overdue_emis = $conn->query("
        SELECT es.*, c.customer_name, c.customer_number, c.agreement_number, f.finance_name,
            DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue
        FROM emi_schedule es
        JOIN customers c ON es.customer_id = c.id
        JOIN finance f ON c.finance_id = f.id
        WHERE es.status = 'overdue'
        ORDER BY es.emi_due_date ASC
        LIMIT 10
    ");
    
    // Get recent activity logs for admin only
    $recent_logs = $conn->query("
        SELECT al.*, u.username, u.full_name 
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
} else {
    // Staff and Accountant see only their finance company data
    $recent_collections = $conn->query("
        SELECT es.*, c.customer_name, c.agreement_number 
        FROM emi_schedule es
        JOIN customers c ON es.customer_id = c.id
        WHERE es.finance_id = $finance_id AND es.status = 'paid'
        ORDER BY es.paid_date DESC
        LIMIT 10
    ");
    
    $upcoming_dues = $conn->query("
        SELECT es.*, c.customer_name, c.customer_number, c.agreement_number 
        FROM emi_schedule es
        JOIN customers c ON es.customer_id = c.id
        WHERE es.finance_id = $finance_id 
            AND es.status = 'unpaid' 
            AND es.emi_due_date >= CURDATE()
        ORDER BY es.emi_due_date ASC
        LIMIT 10
    ");
    
    $overdue_emis = $conn->query("
        SELECT es.*, c.customer_name, c.customer_number, c.agreement_number,
            DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue
        FROM emi_schedule es
        JOIN customers c ON es.customer_id = c.id
        WHERE es.finance_id = $finance_id 
            AND es.status = 'overdue'
        ORDER BY es.emi_due_date ASC
        LIMIT 10
    ");
    
    // Non-admin users don't see activity logs
    $recent_logs = null;
}

// Get finance companies list for admin
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance");
}

// Function to get action icon
function getActionIcon($action) {
    $icons = [
        'add_customer' => 'bi-person-plus',
        'edit_customer' => 'bi-pencil',
        'delete_customer' => 'bi-trash',
        'add_loan' => 'bi-cash-stack',
        'edit_loan' => 'bi-pencil-square',
        'delete_loan' => 'bi-trash',
        'record_payment' => 'bi-cash',
        'undo_payment' => 'bi-arrow-counterclockwise',
        'add_expense' => 'bi-wallet2',
        'edit_expense' => 'bi-pencil',
        'delete_expense' => 'bi-trash',
        'login' => 'bi-box-arrow-in-right',
        'logout' => 'bi-box-arrow-right',
        'update_settings' => 'bi-gear'
    ];
    
    return $icons[$action] ?? 'bi-info-circle';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Finance Manager - Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
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
    
    body {
      background-color: #f5f7fa;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
      padding: 2rem;
    }
    
    /* Welcome Banner */
    .welcome-banner {
      background: linear-gradient(135deg, var(--primary), var(--info));
      color: white;
      padding: 1.5rem 2rem;
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    
    .welcome-banner h3 {
      font-weight: 700;
      margin-bottom: 0.5rem;
    }
    
    .welcome-banner p {
      opacity: 0.9;
      margin-bottom: 0;
    }
    
    /* Stat Cards */
    .stat-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      padding: 1.5rem;
      height: 100%;
      transition: all 0.2s ease;
    }
    
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      border-color: var(--primary);
    }
    
    .stat-label {
      font-size: 0.9rem;
      color: var(--text-muted);
      margin-bottom: 0.5rem;
    }
    
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: var(--text-primary);
      line-height: 1.2;
    }
    
    .stat-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }
    
    .stat-icon.blue {
      background: var(--primary-bg);
      color: var(--primary);
    }
    
    .stat-icon.green {
      background: var(--success-bg);
      color: var(--success);
    }
    
    .stat-icon.purple {
      background: #f3e5f5;
      color: #9c27b0;
    }
    
    .stat-icon.orange {
      background: var(--warning-bg);
      color: var(--warning);
    }
    
    .stat-footer {
      margin-top: 1rem;
      padding-top: 1rem;
      border-top: 1px solid var(--border-color);
      font-size: 0.85rem;
    }
    
    /* Small stat cards */
    .small-stat-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      padding: 1rem 1.25rem;
      height: 100%;
    }
    
    .small-stat-label {
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-bottom: 0.25rem;
    }
    
    .small-stat-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text-primary);
      line-height: 1.2;
    }
    
    .small-stat-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
    }
    
    .small-stat-icon.warning {
      background: var(--warning-bg);
      color: var(--warning);
    }
    
    .small-stat-icon.danger {
      background: var(--danger-bg);
      color: var(--danger);
    }
    
    .small-stat-icon.success {
      background: var(--success-bg);
      color: var(--success);
    }
    
    .small-stat-icon.info {
      background: var(--info-bg);
      color: var(--info);
    }
    
    /* Dashboard Cards */
    .dashboard-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      height: 100%;
      overflow: hidden;
    }
    
    .dashboard-card .card-header {
      background: transparent;
      border-bottom: 1px solid var(--border-color);
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    .dashboard-card .card-header h5 {
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 0.25rem;
    }
    
    .dashboard-card .card-header p {
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-bottom: 0;
    }
    
    .btn-outline-custom {
      padding: 0.4rem 1rem;
      border: 1px solid var(--border-color);
      border-radius: 20px;
      color: var(--text-muted);
      text-decoration: none;
      font-size: 0.85rem;
      transition: all 0.2s ease;
    }
    
    .btn-outline-custom:hover {
      background: var(--primary);
      border-color: var(--primary);
      color: white;
    }
    
    /* List Group */
    .list-group-item {
      border-left: none;
      border-right: none;
      border-color: var(--border-color);
    }
    
    .list-group-item:first-child {
      border-top: none;
    }
    
    .list-group-item:last-child {
      border-bottom: none;
    }
    
    /* Quick Action Cards */
    .quick-action-card {
      display: block;
      padding: 1.25rem 1rem;
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: var(--radius);
      text-align: center;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    
    .quick-action-card:hover {
      transform: translateY(-2px);
      border-color: var(--primary);
      box-shadow: var(--shadow);
      text-decoration: none;
    }
    
    .quick-action-card .qa-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 0.75rem;
      font-size: 1.5rem;
    }
    
    .quick-action-card .qa-title {
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 0.25rem;
    }
    
    .quick-action-card .qa-desc {
      font-size: 0.75rem;
      color: var(--text-muted);
    }
    
    /* Activity Log Styles */
    .activity-item {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid var(--border-color);
      transition: background-color 0.2s;
    }
    
    .activity-item:hover {
      background-color: var(--light);
    }
    
    .activity-item:last-child {
      border-bottom: none;
    }
    
    .activity-icon {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--primary-bg);
      color: var(--primary);
    }
    
    .activity-content {
      flex: 1;
    }
    
    .activity-action {
      font-weight: 600;
      color: var(--text-primary);
      text-transform: capitalize;
    }
    
    .activity-details {
      font-size: 0.8rem;
      color: var(--text-muted);
    }
    
    .activity-time {
      font-size: 0.7rem;
      color: var(--text-muted);
    }
    
    .activity-user {
      font-size: 0.75rem;
      font-weight: 500;
      color: var(--primary);
    }
    
    /* Badge styles */
    .badge.bg-danger {
      background: var(--danger) !important;
      color: white;
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
      border-radius: 20px;
    }
    
    .badge.bg-warning {
      background: var(--warning) !important;
      color: white;
    }
    
    .badge.bg-success {
      background: var(--success) !important;
      color: white;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
      .main-content {
        margin-left: 0;
      }
      .page-content {
        padding: 1rem;
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

      <!-- Welcome Banner with Role-specific message -->
      <div class="welcome-banner mb-4" data-testid="welcome-banner">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h3>
              Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>! 
              <span class="badge bg-<?php 
                echo $user_role == 'admin' ? 'danger' : ($user_role == 'accountant' ? 'warning' : 'info'); 
              ?> ms-2">
                <?php echo ucfirst($user_role); ?>
              </span>
            </h3>
            <p>You have <strong><?php echo $stats['overdue_customers']; ?></strong> overdue customers and <strong><?php echo $stats['today_due']; ?></strong> collections due today.</p>
          </div>
          <?php if ($user_role == 'admin' && isset($finance_companies) && $finance_companies->num_rows > 0): ?>
            <div class="dropdown">
              <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-building me-1"></i> Switch Finance
              </button>
              <ul class="dropdown-menu">
                <?php 
                $finance_companies->data_seek(0);
                while ($fc = $finance_companies->fetch_assoc()): 
                ?>
                  <li>
                    <a class="dropdown-item" href="?finance_id=<?php echo $fc['id']; ?>">
                      <?php echo htmlspecialchars($fc['finance_name']); ?>
                    </a>
                  </li>
                <?php endwhile; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
        <div class="mt-2">
          <?php if (function_exists('hasPermission') && hasPermission('add_customer')): ?>
            <a href="add-customer.php" class="btn btn-light btn-sm me-2 fw-semibold" style="border-radius: var(--radius);" data-testid="button-add-customer">
              <i class="bi bi-person-plus me-1"></i> Add New Customer
            </a>
          <?php endif; ?>
          <?php if (function_exists('hasPermission') && hasPermission('record_collection')): ?>
            <a href="collections.php" class="btn btn-outline-light btn-sm fw-semibold" style="border-radius: var(--radius);" data-testid="button-collections">
              <i class="bi bi-cash me-1"></i> Record Collection
            </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Stats Row -->
      <div class="row g-3 mb-4" data-testid="stats-cards">
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="stat-label">Total Customers</div>
                <div class="stat-value" data-testid="stat-total-customers"><?php echo number_format($stats['total_customers']); ?></div>
              </div>
              <div class="stat-icon blue"><i class="bi bi-people"></i></div>
            </div>
            <div class="stat-footer">
              <span class="text-muted">Active borrowers</span>
            </div>
          </div>
        </div>
        
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="stat-label">Total Disbursed</div>
                <div class="stat-value" data-testid="stat-disbursed">₹<?php echo number_format($stats['total_disbursed'], 2); ?></div>
              </div>
              <div class="stat-icon green"><i class="bi bi-cash-stack"></i></div>
            </div>
            <div class="stat-footer">
              <span class="text-muted">Total loan amount</span>
            </div>
          </div>
        </div>
        
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="stat-label">Total Collections</div>
                <div class="stat-value" data-testid="stat-collections">₹<?php echo number_format($stats['total_collections'], 2); ?></div>
              </div>
              <div class="stat-icon purple"><i class="bi bi-cash"></i></div>
            </div>
            <div class="stat-footer">
              <span class="text-muted"><?php echo number_format(($stats['total_collections'] / max($stats['total_disbursed'], 1)) * 100, 1); ?>% recovered</span>
            </div>
          </div>
        </div>
        
        <div class="col-sm-6 col-xl-3">
          <div class="stat-card">
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div>
                <div class="stat-label">Current Balance</div>
                <div class="stat-value" data-testid="stat-balance">₹<?php echo number_format($stats['current_balance'], 2); ?></div>
              </div>
              <div class="stat-icon orange"><i class="bi bi-wallet2"></i></div>
            </div>
            <div class="stat-footer">
              <span class="text-muted">Available cash</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Second Row Stats -->
      <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
          <div class="small-stat-card">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="small-stat-label">Pending Collections</div>
                <div class="small-stat-value">₹<?php echo number_format($stats['pending_collections'], 2); ?></div>
              </div>
              <div class="small-stat-icon warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
          </div>
        </div>
        
        <div class="col-sm-6 col-lg-3">
          <div class="small-stat-card">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="small-stat-label">Overdue Customers</div>
                <div class="small-stat-value text-danger"><?php echo $stats['overdue_customers']; ?></div>
              </div>
              <div class="small-stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
            </div>
          </div>
        </div>
        
        <div class="col-sm-6 col-lg-3">
          <div class="small-stat-card">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="small-stat-label">Today's Collection</div>
                <div class="small-stat-value text-success">₹<?php echo number_format($stats['today_collections'], 2); ?></div>
              </div>
              <div class="small-stat-icon success"><i class="bi bi-calendar-check"></i></div>
            </div>
          </div>
        </div>
        
        <div class="col-sm-6 col-lg-3">
          <div class="small-stat-card">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="small-stat-label">Monthly Expenses</div>
                <div class="small-stat-value">₹<?php echo number_format($stats['monthly_expenses'], 2); ?></div>
              </div>
              <div class="small-stat-icon info"><i class="bi bi-receipt"></i></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tables Row -->
      <div class="row g-3 mb-4">
        <!-- Overdue EMIs -->
        <div class="col-lg-4">
          <div class="dashboard-card" data-testid="overdue-emi">
            <div class="card-header">
              <div>
                <h5>Overdue Collections</h5>
                <p>Customers with delayed payments</p>
              </div>
              <?php if (function_exists('hasPermission') && hasPermission('view_all_collections')): ?>
                <a href="overdue-collections.php" class="btn-outline-custom" data-testid="button-view-overdue">
                  View All <i class="bi bi-arrow-right"></i>
                </a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0">
              <?php if ($overdue_emis && $overdue_emis->num_rows > 0): ?>
                <div class="list-group list-group-flush">
                  <?php while ($emi = $overdue_emis->fetch_assoc()): ?>
                    <div class="list-group-item px-3 py-3">
                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                          <span class="fw-semibold"><?php echo htmlspecialchars($emi['customer_name']); ?></span>
                          <span class="badge bg-danger ms-2"><?php echo $emi['days_overdue']; ?> days</span>
                        </div>
                        <span class="fw-bold text-danger">₹<?php echo number_format($emi['emi_amount'], 2); ?></span>
                      </div>
                      <div class="small text-muted">
                        <i class="bi bi-calendar me-1"></i> Due: <?php echo date('d M Y', strtotime($emi['emi_due_date'])); ?>
                      </div>
                      <div class="small text-muted">
                        <i class="bi bi-hash me-1"></i> AGR: <?php echo htmlspecialchars($emi['agreement_number']); ?>
                      </div>
                      <?php if ($user_role == 'admin' && isset($emi['finance_name'])): ?>
                        <div class="small text-muted">
                          <i class="bi bi-building me-1"></i> <?php echo $emi['finance_name']; ?>
                        </div>
                      <?php endif; ?>
                      <?php if (function_exists('hasPermission') && hasPermission('record_collection')): ?>
                        <div class="mt-2">
                          <a href="collect-emi.php?id=<?php echo $emi['id']; ?>" class="btn btn-sm btn-success">Collect Now</a>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div class="text-center py-4">
                  <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                  <p class="mt-2 text-muted">No overdue collections</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Upcoming Dues -->
        <div class="col-lg-4">
          <div class="dashboard-card" data-testid="upcoming-dues">
            <div class="card-header">
              <div>
                <h5>Upcoming Dues</h5>
                <p>Next 10 pending collections</p>
              </div>
              <?php if (function_exists('hasPermission') && hasPermission('view_all_collections')): ?>
                <a href="pending-collections.php" class="btn-outline-custom" data-testid="button-view-upcoming">
                  View All <i class="bi bi-arrow-right"></i>
                </a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0">
              <?php if ($upcoming_dues && $upcoming_dues->num_rows > 0): ?>
                <div class="list-group list-group-flush">
                  <?php while ($emi = $upcoming_dues->fetch_assoc()): ?>
                    <div class="list-group-item px-3 py-3">
                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                          <span class="fw-semibold"><?php echo htmlspecialchars($emi['customer_name']); ?></span>
                        </div>
                        <span class="fw-bold">₹<?php echo number_format($emi['emi_amount'], 2); ?></span>
                      </div>
                      <div class="small text-muted">
                        <i class="bi bi-calendar me-1"></i> Due: <?php echo date('d M Y', strtotime($emi['emi_due_date'])); ?>
                        <?php if ($emi['emi_due_date'] == date('Y-m-d')): ?>
                          <span class="badge bg-warning ms-2">Today</span>
                        <?php endif; ?>
                      </div>
                      <?php if ($user_role == 'admin' && isset($emi['finance_name'])): ?>
                        <div class="small text-muted">
                          <i class="bi bi-building me-1"></i> <?php echo $emi['finance_name']; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div class="text-center py-4">
                  <i class="bi bi-calendar-check text-success" style="font-size: 2rem;"></i>
                  <p class="mt-2 text-muted">No upcoming dues</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Recent Collections / Activity Logs (Admin Only) -->
        <?php if ($user_role == 'admin' && $recent_logs && $recent_logs->num_rows > 0): ?>
        <!-- Recent Activity Logs for Admin -->
        <div class="col-lg-4">
          <div class="dashboard-card" data-testid="recent-activity">
            <div class="card-header">
              <div>
                <h5>Recent Activity</h5>
                <p>Latest system actions</p>
              </div>
              <a href="activity-logs.php" class="btn-outline-custom" data-testid="button-view-all-logs">
                View All <i class="bi bi-arrow-right"></i>
              </a>
            </div>
            <div class="card-body p-0">
              <div class="list-group list-group-flush">
                <?php while ($log = $recent_logs->fetch_assoc()): ?>
                  <div class="activity-item d-flex align-items-start gap-3">
                    <div class="activity-icon">
                      <i class="bi <?php echo getActionIcon($log['action']); ?>"></i>
                    </div>
                    <div class="activity-content">
                      <div class="d-flex justify-content-between align-items-start">
                        <span class="activity-action"><?php echo str_replace('_', ' ', $log['action']); ?></span>
                        <span class="activity-time"><?php echo date('h:i A', strtotime($log['created_at'])); ?></span>
                      </div>
                      <div class="activity-details">
                        <?php echo htmlspecialchars($log['details'] ?? 'No details'); ?>
                      </div>
                      <div class="d-flex justify-content-between align-items-center mt-1">
                        <span class="activity-user">
                          <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($log['full_name'] ?: $log['username']); ?>
                        </span>
                        <span class="activity-time">
                          <?php echo date('d M Y', strtotime($log['created_at'])); ?>
                        </span>
                      </div>
                      <?php if ($log['ip_address']): ?>
                      <div class="small text-muted mt-1">
                        <i class="bi bi-globe2"></i> <?php echo $log['ip_address']; ?>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endwhile; ?>
              </div>
            </div>
          </div>
        </div>
        <?php else: ?>
        <!-- Recent Collections for non-admin users -->
        <div class="col-lg-4">
          <div class="dashboard-card" data-testid="recent-collections">
            <div class="card-header">
              <div>
                <h5>Recent Collections</h5>
                <p>Last 10 payments received</p>
              </div>
              <?php if (function_exists('hasPermission') && hasPermission('view_all_collections')): ?>
                <a href="collection-list.php" class="btn-outline-custom" data-testid="button-view-collections">
                  View All <i class="bi bi-arrow-right"></i>
                </a>
              <?php endif; ?>
            </div>
            <div class="card-body p-0">
              <?php if ($recent_collections && $recent_collections->num_rows > 0): ?>
                <div class="list-group list-group-flush">
                  <?php while ($emi = $recent_collections->fetch_assoc()): ?>
                    <div class="list-group-item px-3 py-3">
                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                          <span class="fw-semibold"><?php echo htmlspecialchars($emi['customer_name']); ?></span>
                        </div>
                        <span class="fw-bold text-success">₹<?php echo number_format($emi['emi_amount'], 2); ?></span>
                      </div>
                      <div class="small text-muted">
                        <i class="bi bi-calendar me-1"></i> Paid: <?php echo date('d M Y', strtotime($emi['paid_date'])); ?>
                      </div>
                      <?php if ($emi['emi_bill_number']): ?>
                      <div class="small text-muted">
                        <i class="bi bi-receipt me-1"></i> Bill: <?php echo $emi['emi_bill_number']; ?>
                      </div>
                      <?php endif; ?>
                      <?php if ($user_role == 'admin' && isset($emi['finance_name'])): ?>
                        <div class="small text-muted">
                          <i class="bi bi-building me-1"></i> <?php echo $emi['finance_name']; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endwhile; ?>
                </div>
              <?php else: ?>
                <div class="text-center py-4">
                  <i class="bi bi-cash-stack text-muted" style="font-size: 2rem;"></i>
                  <p class="mt-2 text-muted">No collections yet</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Quick Actions -->
      <div class="row g-3">
        <div class="col-12">
          <div class="dashboard-card" data-testid="quick-actions">
            <div class="card-header">
              <div>
                <h5>Quick Actions</h5>
                <p>Frequently used tasks</p>
              </div>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <?php if (function_exists('hasPermission') && hasPermission('add_customer')): ?>
                <div class="col-6 col-md-3">
                  <a href="add-customer.php" class="quick-action-card">
                    <div class="qa-icon bg-primary bg-opacity-10 text-primary">
                      <i class="bi bi-person-plus"></i>
                    </div>
                    <div class="qa-title">Add Customer</div>
                    <div class="qa-desc">Register new customer</div>
                  </a>
                </div>
                <?php endif; ?>
                
                <?php if (function_exists('hasPermission') && hasPermission('add_loan')): ?>
                <div class="col-6 col-md-3">
                  <a href="add-loan.php" class="quick-action-card">
                    <div class="qa-icon bg-success bg-opacity-10 text-success">
                      <i class="bi bi-cash-stack"></i>
                    </div>
                    <div class="qa-title">New Loan</div>
                    <div class="qa-desc">Create new loan</div>
                  </a>
                </div>
                <?php endif; ?>
                
                <?php if (function_exists('hasPermission') && hasPermission('record_collection')): ?>
                <div class="col-6 col-md-3">
                  <a href="collections.php" class="quick-action-card">
                    <div class="qa-icon bg-warning bg-opacity-10 text-warning">
                      <i class="bi bi-cash"></i>
                    </div>
                    <div class="qa-title">Record Collection</div>
                    <div class="qa-desc">Collect EMI payment</div>
                  </a>
                </div>
                <?php endif; ?>
                
                <?php if (function_exists('hasPermission') && hasPermission('add_expense')): ?>
                <div class="col-6 col-md-3">
                  <a href="expenses.php" class="quick-action-card">
                    <div class="qa-icon bg-danger bg-opacity-10 text-danger">
                      <i class="bi bi-wallet2"></i>
                    </div>
                    <div class="qa-title">Add Expense</div>
                    <div class="qa-desc">Record expense</div>
                  </a>
                </div>
                <?php endif; ?>
                
                <?php if (function_exists('hasPermission') && hasPermission('view_all_reports')): ?>
                <div class="col-6 col-md-3">
                  <a href="reports.php" class="quick-action-card">
                    <div class="qa-icon bg-info bg-opacity-10 text-info">
                      <i class="bi bi-graph-up"></i>
                    </div>
                    <div class="qa-title">Reports</div>
                    <div class="qa-desc">View financial reports</div>
                  </a>
                </div>
                <?php endif; ?>
                
                <?php if (function_exists('hasPermission') && hasPermission('view_calendar')): ?>
                <div class="col-6 col-md-3">
                  <a href="calendar.php" class="quick-action-card">
                    <div class="qa-icon bg-secondary bg-opacity-10 text-secondary">
                      <i class="bi bi-calendar3"></i>
                    </div>
                    <div class="qa-title">Calendar</div>
                    <div class="qa-desc">View payment schedule</div>
                  </a>
                </div>
                <?php endif; ?>
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
<script>
  // Add any dashboard-specific JavaScript here
  $(document).ready(function() {
    // Auto-refresh data every 5 minutes (optional)
    // setInterval(function() {
    //   location.reload();
    // }, 300000);
  });
</script>
</body>
</html>