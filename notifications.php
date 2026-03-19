<?php
require_once 'includes/auth.php';
$currentPage = 'notifications';
$pageTitle = 'Notifications';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Handle mark as read
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = intval($_GET['mark_read']);
    // In a real app, you'd update a notifications table
    // For now, we'll just set a session message
    $_SESSION['success'] = "Notification marked as read.";
    header("Location: notifications.php");
    exit;
}

// Handle mark all as read
if (isset($_GET['mark_all_read'])) {
    $_SESSION['success'] = "All notifications marked as read.";
    header("Location: notifications.php");
    exit;
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $user_role == 'admin') {
    $delete_id = intval($_GET['delete']);
    $_SESSION['success'] = "Notification deleted successfully.";
    header("Location: notifications.php");
    exit;
}

// Get current date for calculations
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$next_week = date('Y-m-d', strtotime('+7 days'));
$next_month = date('Y-m-d', strtotime('+30 days'));

// Build query for overdue notifications
$overdue_query = "
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
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name,
        DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date < CURDATE()
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$overdue_params = [];
$overdue_types = "";

if ($user_role != 'admin') {
    $overdue_query .= " AND es.finance_id = ?";
    $overdue_params[] = $finance_id;
    $overdue_types .= "i";
}

$overdue_query .= " ORDER BY es.emi_due_date ASC";

$overdue_stmt = $conn->prepare($overdue_query);
$overdue_notifications = [];
if ($overdue_stmt) {
    if (!empty($overdue_params)) {
        $overdue_stmt->bind_param($overdue_types, ...$overdue_params);
    }
    $overdue_stmt->execute();
    $overdue_result = $overdue_stmt->get_result();
    while ($row = $overdue_result->fetch_assoc()) {
        $overdue_notifications[] = $row;
    }
    $overdue_stmt->close();
}

// Build query for today's due notifications
$today_query = "
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
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date = CURDATE()
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$today_params = [];
$today_types = "";

if ($user_role != 'admin') {
    $today_query .= " AND es.finance_id = ?";
    $today_params[] = $finance_id;
    $today_types .= "i";
}

$today_query .= " ORDER BY es.emi_due_date ASC";

$today_stmt = $conn->prepare($today_query);
$today_notifications = [];
if ($today_stmt) {
    if (!empty($today_params)) {
        $today_stmt->bind_param($today_types, ...$today_params);
    }
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    while ($row = $today_result->fetch_assoc()) {
        $today_notifications[] = $row;
    }
    $today_stmt->close();
}

// Build query for upcoming notifications (next 7 days)
$upcoming_query = "
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
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name,
        DATEDIFF(es.emi_due_date, CURDATE()) as days_until_due
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date > CURDATE()
      AND es.emi_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$upcoming_params = [];
$upcoming_types = "";

if ($user_role != 'admin') {
    $upcoming_query .= " AND es.finance_id = ?";
    $upcoming_params[] = $finance_id;
    $upcoming_types .= "i";
}

$upcoming_query .= " ORDER BY es.emi_due_date ASC";

$upcoming_stmt = $conn->prepare($upcoming_query);
$upcoming_notifications = [];
if ($upcoming_stmt) {
    if (!empty($upcoming_params)) {
        $upcoming_stmt->bind_param($upcoming_types, ...$upcoming_params);
    }
    $upcoming_stmt->execute();
    $upcoming_result = $upcoming_stmt->get_result();
    while ($row = $upcoming_result->fetch_assoc()) {
        $upcoming_notifications[] = $row;
    }
    $upcoming_stmt->close();
}

// Build query for future notifications (beyond 7 days)
$future_query = "
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
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name,
        DATEDIFF(es.emi_due_date, CURDATE()) as days_until_due
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN finance f ON c.finance_id = f.id
    WHERE es.status IN ('unpaid', 'overdue', 'partial')
      AND es.emi_due_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$future_params = [];
$future_types = "";

if ($user_role != 'admin') {
    $future_query .= " AND es.finance_id = ?";
    $future_params[] = $finance_id;
    $future_types .= "i";
}

$future_query .= " ORDER BY es.emi_due_date ASC LIMIT 20";

$future_stmt = $conn->prepare($future_query);
$future_notifications = [];
if ($future_stmt) {
    if (!empty($future_params)) {
        $future_stmt->bind_param($future_types, ...$future_params);
    }
    $future_stmt->execute();
    $future_result = $future_stmt->get_result();
    while ($row = $future_result->fetch_assoc()) {
        $future_notifications[] = $row;
    }
    $future_stmt->close();
}

// Get summary statistics
$stats = [
    'overdue' => count($overdue_notifications),
    'today' => count($today_notifications),
    'upcoming' => count($upcoming_notifications),
    'future' => count($future_notifications),
    'total' => count($overdue_notifications) + count($today_notifications) + count($upcoming_notifications) + count($future_notifications),
    'overdue_amount' => array_sum(array_column($overdue_notifications, 'emi_amount')),
    'today_amount' => array_sum(array_column($today_notifications, 'emi_amount')),
    'upcoming_amount' => array_sum(array_column($upcoming_notifications, 'emi_amount')),
    'future_amount' => array_sum(array_column($future_notifications, 'emi_amount'))
];

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
            grid-template-columns: repeat(5, 1fr);
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
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .stat-card.overdue:hover { border-color: var(--danger); }
        .stat-card.today:hover { border-color: var(--warning); }
        .stat-card.upcoming:hover { border-color: var(--info); }
        .stat-card.future:hover { border-color: var(--success); }
        .stat-card.total:hover { border-color: var(--primary); }
        
        .stat-card.active {
            border: 2px solid var(--primary);
            background: #e6f0ff;
        }
        
        .stat-card.active.overdue { border-color: var(--danger); background: #fee2e2; }
        .stat-card.active.today { border-color: var(--warning); background: #fff3e0; }
        .stat-card.active.upcoming { border-color: var(--info); background: #e3f2fd; }
        .stat-card.active.future { border-color: var(--success); background: #e3f7e3; }
        
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
        
        .stat-icon.overdue { color: var(--danger); }
        .stat-icon.today { color: var(--warning); }
        .stat-icon.upcoming { color: var(--info); }
        .stat-icon.future { color: var(--success); }
        .stat-icon.total { color: var(--primary); }
        
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
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            background: #f8fafc;
        }
        
        .dashboard-card .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .dashboard-card .card-header .badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .dashboard-card .card-body {
            padding: 0;
        }
        
        /* Notification Item */
        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            background: #f8fafc;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-item.unread {
            background: #f0f7ff;
            border-left: 3px solid var(--primary);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .notification-icon.overdue {
            background: #fee2e2;
            color: var(--danger);
        }
        
        .notification-icon.today {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .notification-icon.upcoming {
            background: #e3f2fd;
            color: var(--info);
        }
        
        .notification-icon.future {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .notification-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .notification-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.7rem;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        
        .notification-meta i {
            margin-right: 0.25rem;
        }
        
        .notification-actions {
            display: flex;
            gap: 0.3rem;
            flex-shrink: 0;
        }
        
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
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            color: white;
        }
        
        .action-btn.read {
            background: var(--success);
        }
        
        .action-btn.delete {
            background: var(--danger);
        }
        
        .action-btn.view {
            background: var(--info);
        }
        
        .action-btn.pay {
            background: var(--warning);
        }
        
        .amount-badge {
            font-weight: 600;
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
            border-radius: 20px;
            background: #f8fafc;
        }
        
        .amount-badge.overdue {
            color: var(--danger);
        }
        
        .amount-badge.today {
            color: var(--warning);
        }
        
        .amount-badge.upcoming {
            color: var(--info);
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
        
        .empty-state h6 {
            margin-top: 1rem;
            color: var(--text-muted);
        }
        
        /* Summary Card */
        .summary-card {
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
            font-size: 1.2rem;
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
            .notification-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .notification-actions {
                align-self: flex-end;
                margin-top: 0.5rem;
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
                        <h4><i class="bi bi-bell me-2"></i>Notifications</h4>
                        <p>Stay updated with payment reminders and alerts</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="?mark_all_read=1" class="btn btn-light" onclick="return confirm('Mark all notifications as read?')">
                            <i class="bi bi-check-all me-2"></i>Mark All Read
                        </a>
                        <span class="badge bg-white text-primary py-2 px-3">
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
                <a href="?type=all" class="stat-card total <?php echo $type_filter == 'all' ? 'active' : ''; ?>">
                    <div class="stat-icon total"><i class="bi bi-bell"></i></div>
                    <div class="stat-label">ALL</div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                </a>
                <a href="?type=overdue" class="stat-card overdue <?php echo $type_filter == 'overdue' ? 'active' : ''; ?>">
                    <div class="stat-icon overdue"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                </a>
                <a href="?type=today" class="stat-card today <?php echo $type_filter == 'today' ? 'active' : ''; ?>">
                    <div class="stat-icon today"><i class="bi bi-calendar-day"></i></div>
                    <div class="stat-label">TODAY</div>
                    <div class="stat-value"><?php echo $stats['today']; ?></div>
                </a>
                <a href="?type=upcoming" class="stat-card upcoming <?php echo $type_filter == 'upcoming' ? 'active' : ''; ?>">
                    <div class="stat-icon upcoming"><i class="bi bi-calendar-week"></i></div>
                    <div class="stat-label">UPCOMING</div>
                    <div class="stat-value"><?php echo $stats['upcoming']; ?></div>
                </a>
                <a href="?type=future" class="stat-card future <?php echo $type_filter == 'future' ? 'active' : ''; ?>">
                    <div class="stat-icon future"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">FUTURE</div>
                    <div class="stat-value"><?php echo $stats['future']; ?></div>
                </a>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="type">
                                <option value="all" <?php echo $type_filter == 'all' ? 'selected' : ''; ?>>All Notifications</option>
                                <option value="overdue" <?php echo $type_filter == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="today" <?php echo $type_filter == 'today' ? 'selected' : ''; ?>>Due Today</option>
                                <option value="upcoming" <?php echo $type_filter == 'upcoming' ? 'selected' : ''; ?>>Upcoming (7 days)</option>
                                <option value="future" <?php echo $type_filter == 'future' ? 'selected' : ''; ?>>Future</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="unread" <?php echo $status_filter == 'unread' ? 'selected' : ''; ?>>Unread</option>
                                <option value="read" <?php echo $status_filter == 'read' ? 'selected' : ''; ?>>Read</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel"></i>
                            </button>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <a href="notifications.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Overdue Notifications -->
            <?php if ($type_filter == 'all' || $type_filter == 'overdue'): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-exclamation-triangle text-danger"></i>
                        Overdue Payments
                    </h5>
                    <span class="badge bg-danger"><?php echo count($overdue_notifications); ?> pending</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($overdue_notifications)): ?>
                        <?php foreach ($overdue_notifications as $notification): ?>
                        <div class="notification-item unread">
                            <div class="notification-icon overdue">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">
                                    <?php echo htmlspecialchars($notification['customer_name']); ?>
                                </div>
                                <div class="notification-desc">
                                    EMI of <strong>₹<?php echo number_format($notification['emi_amount'], 2); ?></strong> is overdue by <span class="text-danger fw-bold"><?php echo $notification['days_overdue']; ?> days</span>
                                </div>
                                <div class="notification-meta">
                                    <span><i class="bi bi-calendar"></i> Due: <?php echo date('d M Y', strtotime($notification['emi_due_date'])); ?></span>
                                    <span><i class="bi bi-file-text"></i> Agr: <?php echo htmlspecialchars($notification['agreement_number']); ?></span>
                                    <span><i class="bi bi-building"></i> <?php echo htmlspecialchars($notification['finance_name']); ?></span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <a href="pay-emi.php?emi_id=<?php echo $notification['id']; ?>&customer_id=<?php echo $notification['customer_id']; ?>" 
                                   class="action-btn pay" title="Pay Now">
                                    <i class="bi bi-cash"></i>
                                </a>
                                <a href="?mark_read=<?php echo $notification['id']; ?>" 
                                   class="action-btn read" title="Mark as Read">
                                    <i class="bi bi-check"></i>
                                </a>
                                <?php if ($user_role == 'admin'): ?>
                                <a href="?delete=<?php echo $notification['id']; ?>" 
                                   class="action-btn delete" 
                                   onclick="return confirm('Delete this notification?')"
                                   title="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle text-success"></i>
                            <h6>No overdue payments</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Today's Due Notifications -->
            <?php if ($type_filter == 'all' || $type_filter == 'today'): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-calendar-day text-warning"></i>
                        Due Today
                    </h5>
                    <span class="badge bg-warning"><?php echo count($today_notifications); ?> pending</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($today_notifications)): ?>
                        <?php foreach ($today_notifications as $notification): ?>
                        <div class="notification-item">
                            <div class="notification-icon today">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">
                                    <?php echo htmlspecialchars($notification['customer_name']); ?>
                                </div>
                                <div class="notification-desc">
                                    EMI of <strong>₹<?php echo number_format($notification['emi_amount'], 2); ?></strong> is due <span class="text-warning fw-bold">today</span>
                                </div>
                                <div class="notification-meta">
                                    <span><i class="bi bi-calendar"></i> Due: <?php echo date('d M Y', strtotime($notification['emi_due_date'])); ?></span>
                                    <span><i class="bi bi-file-text"></i> Agr: <?php echo htmlspecialchars($notification['agreement_number']); ?></span>
                                    <span><i class="bi bi-building"></i> <?php echo htmlspecialchars($notification['finance_name']); ?></span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <a href="pay-emi.php?emi_id=<?php echo $notification['id']; ?>&customer_id=<?php echo $notification['customer_id']; ?>" 
                                   class="action-btn pay" title="Pay Now">
                                    <i class="bi bi-cash"></i>
                                </a>
                                <a href="?mark_read=<?php echo $notification['id']; ?>" 
                                   class="action-btn read" title="Mark as Read">
                                    <i class="bi bi-check"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check-circle text-success"></i>
                            <h6>No payments due today</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upcoming Notifications (Next 7 Days) -->
            <?php if ($type_filter == 'all' || $type_filter == 'upcoming'): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-calendar-week text-info"></i>
                        Upcoming (Next 7 Days)
                    </h5>
                    <span class="badge bg-info"><?php echo count($upcoming_notifications); ?> pending</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($upcoming_notifications)): ?>
                        <?php foreach ($upcoming_notifications as $notification): ?>
                        <div class="notification-item">
                            <div class="notification-icon upcoming">
                                <i class="bi bi-calendar"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">
                                    <?php echo htmlspecialchars($notification['customer_name']); ?>
                                </div>
                                <div class="notification-desc">
                                    EMI of <strong>₹<?php echo number_format($notification['emi_amount'], 2); ?></strong> due in <span class="text-info fw-bold"><?php echo $notification['days_until_due']; ?> days</span>
                                </div>
                                <div class="notification-meta">
                                    <span><i class="bi bi-calendar"></i> Due: <?php echo date('d M Y', strtotime($notification['emi_due_date'])); ?></span>
                                    <span><i class="bi bi-file-text"></i> Agr: <?php echo htmlspecialchars($notification['agreement_number']); ?></span>
                                    <span><i class="bi bi-building"></i> <?php echo htmlspecialchars($notification['finance_name']); ?></span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <a href="?mark_read=<?php echo $notification['id']; ?>" 
                                   class="action-btn read" title="Mark as Read">
                                    <i class="bi bi-check"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-check text-muted"></i>
                            <h6>No upcoming payments in next 7 days</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Future Notifications (Beyond 7 Days) -->
            <?php if ($type_filter == 'all' || $type_filter == 'future'): ?>
            <div class="dashboard-card">
                <div class="card-header">
                    <h5>
                        <i class="bi bi-calendar text-success"></i>
                        Future Payments
                    </h5>
                    <span class="badge bg-success"><?php echo count($future_notifications); ?> pending</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($future_notifications)): ?>
                        <?php foreach ($future_notifications as $notification): ?>
                        <div class="notification-item">
                            <div class="notification-icon future">
                                <i class="bi bi-calendar"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-title">
                                    <?php echo htmlspecialchars($notification['customer_name']); ?>
                                </div>
                                <div class="notification-desc">
                                    EMI of <strong>₹<?php echo number_format($notification['emi_amount'], 2); ?></strong> due on <?php echo date('d M Y', strtotime($notification['emi_due_date'])); ?>
                                </div>
                                <div class="notification-meta">
                                    <span><i class="bi bi-calendar"></i> Due: <?php echo date('d M Y', strtotime($notification['emi_due_date'])); ?></span>
                                    <span><i class="bi bi-file-text"></i> Agr: <?php echo htmlspecialchars($notification['agreement_number']); ?></span>
                                    <span><i class="bi bi-building"></i> <?php echo htmlspecialchars($notification['finance_name']); ?></span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <a href="?mark_read=<?php echo $notification['id']; ?>" 
                                   class="action-btn read" title="Mark as Read">
                                    <i class="bi bi-check"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar text-muted"></i>
                            <h6>No future payments scheduled</h6>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #2d3748, #4a5568); color: white;">
                            <h5 class="text-white"><i class="bi bi-pie-chart me-2"></i>Summary</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Total Notifications</span>
                                <span class="fw-bold"><?php echo $stats['total']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-danger">Overdue</span>
                                <span class="fw-bold"><?php echo $stats['overdue']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-warning">Due Today</span>
                                <span class="fw-bold"><?php echo $stats['today']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-info">Upcoming (7 days)</span>
                                <span class="fw-bold"><?php echo $stats['upcoming']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-success">Future</span>
                                <span class="fw-bold"><?php echo $stats['future']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298); color: white;">
                            <h5 class="text-white"><i class="bi bi-currency-rupee me-2"></i>Amount Summary</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Overdue Amount</span>
                                <span class="fw-bold text-danger">₹<?php echo number_format($stats['overdue_amount'], 0); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Today's Amount</span>
                                <span class="fw-bold text-warning">₹<?php echo number_format($stats['today_amount'], 0); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Upcoming Amount</span>
                                <span class="fw-bold text-info">₹<?php echo number_format($stats['upcoming_amount'], 0); ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Future Amount</span>
                                <span class="fw-bold text-success">₹<?php echo number_format($stats['future_amount'], 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e); color: white;">
                            <h5 class="text-white"><i class="bi bi-info-circle me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-2">
                                <div class="col-6">
                                    <a href="pending-collections.php" class="btn btn-outline-danger w-100">
                                        <i class="bi bi-hourglass-split me-2"></i>Pending
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="overdue-collections.php" class="btn btn-outline-warning w-100">
                                        <i class="bi bi-exclamation-triangle me-2"></i>Overdue
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="collections.php" class="btn btn-outline-success w-100">
                                        <i class="bi bi-cash me-2"></i>Collection
                                    </a>
                                </div>
                                <div class="col-6">
                                    <a href="calendar.php" class="btn btn-outline-primary w-100">
                                        <i class="bi bi-calendar me-2"></i>Calendar
                                    </a>
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

<script>
$(document).ready(function() {
    // Touch feedback
    const buttons = document.querySelectorAll('.btn, .action-btn, .stat-card');
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