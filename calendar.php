<?php
require_once 'includes/auth.php';
$currentPage = 'calendar';
$pageTitle = 'Payment Calendar';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get month and year from URL or default to current
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Adjust for invalid values
if ($month < 1) $month = 1;
if ($month > 12) $month = 12;
if ($year < 2020) $year = date('Y');
if ($year > 2030) $year = date('Y');

// Calculate previous and next month
$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) {
    $prev_month = 12;
    $prev_year = $year - 1;
}

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year = $year + 1;
}

// Get first day of month and number of days
$first_day = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day);
$month_name = date('F', $first_day);

// Get starting day of week (0 = Sunday, 1 = Monday, etc.)
$start_day = date('w', $first_day);

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get finance filter
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Build query for events
$query = "
    SELECT 
        es.id,
        es.customer_id,
        es.emi_amount,
        es.principal_amount,
        es.interest_amount,
        es.emi_due_date,
        es.status,
        es.paid_date,
        es.overdue_charges,
        es.overdue_paid,
        es.collection_type,
        es.week_number,
        es.day_number,
        c.customer_name,
        c.customer_number,
        c.agreement_number,
        f.finance_name,
        l.loan_name,
        DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue
    FROM emi_schedule es
    JOIN customers c ON es.customer_id = c.id
    JOIN loans l ON c.loan_id = l.id
    JOIN finance f ON c.finance_id = f.id
    WHERE MONTH(es.emi_due_date) = ? AND YEAR(es.emi_due_date) = ?
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$params = [$month, $year];
$types = "ii";

if ($user_role != 'admin') {
    $query .= " AND es.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $query .= " AND es.finance_id = ?";
    $params[] = $finance_filter;
    $types .= "i";
}

$query .= " ORDER BY es.emi_due_date ASC, es.id ASC";

$events = null;
$stmt = $conn->prepare($query);
$calendar_events = [];
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $events = $stmt->get_result();
    
    // Organize events by day
    while ($row = $events->fetch_assoc()) {
        $day = (int)date('j', strtotime($row['emi_due_date']));
        if (!isset($calendar_events[$day])) {
            $calendar_events[$day] = [];
        }
        $calendar_events[$day][] = $row;
    }
    $stmt->close();
}

// Get summary statistics for the month
$stats_query = "
    SELECT 
        COUNT(*) as total_pending,
        COALESCE(SUM(es.emi_amount), 0) as total_amount,
        COUNT(DISTINCT es.customer_id) as unique_customers,
        SUM(CASE WHEN es.emi_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN es.emi_due_date = CURDATE() THEN 1 ELSE 0 END) as due_today_count,
        SUM(CASE WHEN es.emi_due_date > CURDATE() THEN 1 ELSE 0 END) as future_count
    FROM emi_schedule es
    WHERE MONTH(es.emi_due_date) = ? AND YEAR(es.emi_due_date) = ?
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$stats_params = [$month, $year];
$stats_types = "ii";

if ($user_role != 'admin') {
    $stats_query .= " AND es.finance_id = ?";
    $stats_params[] = $finance_id;
    $stats_types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $stats_query .= " AND es.finance_id = ?";
    $stats_params[] = $finance_filter;
    $stats_types .= "i";
}

$stats_stmt = $conn->prepare($stats_query);
$stats = [];
if ($stats_stmt) {
    $stats_stmt->bind_param($stats_types, ...$stats_params);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
    $stats_stmt->close();
}

$stats = array_merge([
    'total_pending' => 0,
    'total_amount' => 0,
    'unique_customers' => 0,
    'overdue_count' => 0,
    'due_today_count' => 0,
    'future_count' => 0
], $stats);

// Get collection types summary
$type_query = "
    SELECT 
        es.collection_type,
        COUNT(*) as type_count,
        COALESCE(SUM(es.emi_amount), 0) as type_total
    FROM emi_schedule es
    WHERE MONTH(es.emi_due_date) = ? AND YEAR(es.emi_due_date) = ?
      AND es.emi_due_date != '0000-00-00'
      AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
           OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
           OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
";

$type_params = [$month, $year];
$type_types = "ii";

if ($user_role != 'admin') {
    $type_query .= " AND es.finance_id = ?";
    $type_params[] = $finance_id;
    $type_types .= "i";
}

if ($finance_filter > 0 && $user_role == 'admin') {
    $type_query .= " AND es.finance_id = ?";
    $type_params[] = $finance_filter;
    $type_types .= "i";
}

$type_query .= " GROUP BY es.collection_type ORDER BY type_count DESC";

$type_stmt = $conn->prepare($type_query);
$collection_types = [];
if ($type_stmt) {
    $type_stmt->bind_param($type_types, ...$type_params);
    $type_stmt->execute();
    $type_result = $type_stmt->get_result();
    while ($row = $type_result->fetch_assoc()) {
        $collection_types[] = $row;
    }
    $type_stmt->close();
}

// Days of week
$days_of_week = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
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
        
        /* Calendar Container */
        .calendar-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--dark), #1a2634);
            color: white;
        }
        
        .calendar-header h3 {
            font-weight: 600;
            margin: 0;
            font-size: 1.2rem;
        }
        
        .calendar-nav {
            display: flex;
            gap: 0.5rem;
        }
        
        .calendar-nav .btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 0.3rem 1rem;
            font-size: 0.9rem;
            border-radius: 30px;
        }
        
        .calendar-nav .btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 0;
            text-align: center;
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1px;
            background: var(--border-color);
            padding: 1px;
        }
        
        .calendar-day {
            background: var(--card-bg);
            min-height: 100px;
            padding: 0.5rem;
            position: relative;
            transition: all 0.2s ease;
        }
        
        .calendar-day:hover {
            background: #f8fafc;
        }
        
        .calendar-day.empty {
            background: #f8fafc;
            opacity: 0.7;
        }
        
        .calendar-day.today {
            background: #e6f0ff;
            border: 2px solid var(--primary);
        }
        
        .day-number {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .day-events {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        
        .event-item {
            padding: 0.2rem 0.3rem;
            border-radius: 4px;
            font-size: 0.65rem;
            color: white;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .event-item:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        .event-item.overdue {
            background: var(--danger);
        }
        
        .event-item.due-today {
            background: var(--warning);
        }
        
        .event-item.upcoming {
            background: var(--primary);
        }
        
        .event-item.paid {
            background: var(--success);
        }
        
        .event-count {
            display: inline-block;
            padding: 0.1rem 0.3rem;
            border-radius: 10px;
            font-size: 0.6rem;
            background: rgba(0,0,0,0.1);
            color: var(--text-primary);
            margin-top: 0.2rem;
        }
        
        /* Summary Cards */
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
        
        /* Type Badge */
        .type-badge {
            display: inline-block;
            padding: 0.15rem 0.35rem;
            border-radius: 10px;
            font-size: 0.55rem;
            font-weight: 600;
            margin-right: 0.2rem;
        }
        
        .type-badge.monthly {
            background: #e3f2fd;
            color: var(--monthly-color);
        }
        
        .type-badge.weekly {
            background: #f3e5f5;
            color: var(--weekly-color);
        }
        
        .type-badge.daily {
            background: #fff3e0;
            color: var(--daily-color);
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
            .calendar-day {
                min-height: 80px;
                padding: 0.3rem;
            }
            .event-item {
                font-size: 0.55rem;
                padding: 0.1rem 0.2rem;
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
                        <h4><i class="bi bi-calendar3 me-2"></i>Payment Calendar</h4>
                        <p>View and manage payment schedules</p>
                    </div>
                    <span class="badge bg-white text-primary py-2 px-3">
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
                    <div class="stat-icon primary"><i class="bi bi-calendar-check"></i></div>
                    <div class="stat-label">TOTAL PENDING</div>
                    <div class="stat-value"><?php echo number_format($stats['total_pending']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="bi bi-currency-rupee"></i></div>
                    <div class="stat-label">TOTAL AMOUNT</div>
                    <div class="stat-value">₹<?php echo number_format($stats['total_amount'], 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                    <div class="stat-label">CUSTOMERS</div>
                    <div class="stat-value"><?php echo number_format($stats['unique_customers']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value"><?php echo number_format($stats['overdue_count']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="bi bi-calendar-day"></i></div>
                    <div class="stat-label">DUE TODAY</div>
                    <div class="stat-value"><?php echo number_format($stats['due_today_count']); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-calendar"></i></div>
                    <div class="stat-label">UPCOMING</div>
                    <div class="stat-value"><?php echo number_format($stats['future_count']); ?></div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label">Month</label>
                            <select class="form-select" name="month">
                                <option value="1" <?php echo $month == 1 ? 'selected' : ''; ?>>January</option>
                                <option value="2" <?php echo $month == 2 ? 'selected' : ''; ?>>February</option>
                                <option value="3" <?php echo $month == 3 ? 'selected' : ''; ?>>March</option>
                                <option value="4" <?php echo $month == 4 ? 'selected' : ''; ?>>April</option>
                                <option value="5" <?php echo $month == 5 ? 'selected' : ''; ?>>May</option>
                                <option value="6" <?php echo $month == 6 ? 'selected' : ''; ?>>June</option>
                                <option value="7" <?php echo $month == 7 ? 'selected' : ''; ?>>July</option>
                                <option value="8" <?php echo $month == 8 ? 'selected' : ''; ?>>August</option>
                                <option value="9" <?php echo $month == 9 ? 'selected' : ''; ?>>September</option>
                                <option value="10" <?php echo $month == 10 ? 'selected' : ''; ?>>October</option>
                                <option value="11" <?php echo $month == 11 ? 'selected' : ''; ?>>November</option>
                                <option value="12" <?php echo $month == 12 ? 'selected' : ''; ?>>December</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Year</label>
                            <select class="form-select" name="year">
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
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
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-calendar-check me-1"></i>View
                            </button>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <a href="calendar.php" class="btn btn-secondary w-100">
                                <i class="bi bi-arrow-repeat me-1"></i>Today
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Calendar Navigation -->
            <div class="calendar-container">
                <div class="calendar-header">
                    <h3><i class="bi bi-calendar-range me-2"></i><?php echo $month_name . ' ' . $year; ?></h3>
                    <div class="calendar-nav">
                        <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" class="btn btn-sm">
                            <i class="bi bi-chevron-left"></i> Prev
                        </a>
                        <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?><?php echo $finance_filter ? '&finance_id='.$finance_filter : ''; ?>" class="btn btn-sm">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <!-- Weekdays -->
                <div class="calendar-weekdays">
                    <?php foreach ($days_of_week as $day): ?>
                    <div><?php echo $day; ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar Days -->
                <div class="calendar-grid">
                    <?php
                    // Fill empty cells before first day
                    for ($i = 0; $i < $start_day; $i++):
                    ?>
                    <div class="calendar-day empty"></div>
                    <?php endfor; ?>

                    <?php
                    // Fill actual days
                    $today = date('Y-m-d');
                    for ($day = 1; $day <= $days_in_month; $day++):
                        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $is_today = ($date == $today);
                        $day_events = isset($calendar_events[$day]) ? $calendar_events[$day] : [];
                        $event_count = count($day_events);
                        
                        // Count overdue and due today
                        $overdue_count = 0;
                        $due_today_count = 0;
                        $upcoming_count = 0;
                        
                        foreach ($day_events as $event) {
                            if ($event['days_overdue'] > 0) {
                                $overdue_count++;
                            } elseif ($date == date('Y-m-d')) {
                                $due_today_count++;
                            } else {
                                $upcoming_count++;
                            }
                        }
                    ?>
                    <div class="calendar-day <?php echo $is_today ? 'today' : ''; ?>">
                        <div class="day-number"><?php echo $day; ?></div>
                        <?php if ($event_count > 0): ?>
                        <div class="day-events">
                            <?php 
                            // Show first 2 events
                            $shown = 0;
                            foreach ($day_events as $event):
                                if ($shown >= 2) break;
                                $shown++;
                                
                                // Determine event class
                                if ($event['days_overdue'] > 0) {
                                    $event_class = 'overdue';
                                    $status_text = 'Overdue';
                                } elseif ($date == date('Y-m-d')) {
                                    $event_class = 'due-today';
                                    $status_text = 'Due Today';
                                } else {
                                    $event_class = 'upcoming';
                                    $status_text = 'Due';
                                }
                                
                                // Get initials
                                $name_parts = explode(' ', $event['customer_name'] ?? '');
                                $initials = '';
                                foreach ($name_parts as $part) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                                $initials = substr($initials, 0, 2) ?: 'U';
                            ?>
                            <div class="event-item <?php echo $event_class; ?>" 
                                 onclick="window.location.href='pay-emi.php?emi_id=<?php echo $event['id']; ?>&customer_id=<?php echo $event['customer_id']; ?>'"
                                 title="<?php echo htmlspecialchars($event['customer_name']); ?> - ₹<?php echo number_format($event['emi_amount'], 0); ?>">
                                <span class="type-badge <?php echo $event['collection_type']; ?>">
                                    <?php echo substr($event['collection_type'], 0, 1); ?>
                                </span>
                                <?php echo $initials; ?>: ₹<?php echo number_format($event['emi_amount'], 0); ?>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if ($event_count > 2): ?>
                            <div class="event-count" onclick="showDayDetails(<?php echo $day; ?>, <?php echo $month; ?>, <?php echo $year; ?>)">
                                +<?php echo $event_count - 2; ?> more
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>

                    <?php
                    // Fill remaining cells to complete the grid
                    $total_cells = $start_day + $days_in_month;
                    $remaining_cells = 42 - $total_cells; // 6 rows * 7 days = 42
                    if ($remaining_cells > 0 && $remaining_cells < 7) {
                        for ($i = 0; $i < $remaining_cells; $i++):
                        ?>
                        <div class="calendar-day empty"></div>
                        <?php endfor;
                    }
                    ?>
                </div>
            </div>

            <!-- Legend and Summary -->
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #2d3748, #4a5568);">
                            <h5><i class="bi bi-palette me-2"></i>Legend</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center mb-2">
                                <div style="width: 20px; height: 20px; background: var(--danger); border-radius: 4px; margin-right: 10px;"></div>
                                <span>Overdue - Payment is past due date</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <div style="width: 20px; height: 20px; background: var(--warning); border-radius: 4px; margin-right: 10px;"></div>
                                <span>Due Today - Payment due today</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <div style="width: 20px; height: 20px; background: var(--primary); border-radius: 4px; margin-right: 10px;"></div>
                                <span>Upcoming - Future due dates</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <div style="width: 20px; height: 20px; background: var(--success); border-radius: 4px; margin-right: 10px;"></div>
                                <span>Paid - Already collected</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div style="width: 20px; height: 20px; background: #e6f0ff; border: 2px solid var(--primary); border-radius: 4px; margin-right: 10px;"></div>
                                <span>Today's Date</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">
                            <h5><i class="bi bi-pie-chart me-2"></i>Collection Types</h5>
                        </div>
                        <div class="card-body p-3">
                            <?php if (!empty($collection_types)): ?>
                                <?php foreach ($collection_types as $type): 
                                    $color = '';
                                    if ($type['collection_type'] == 'monthly') $color = 'primary';
                                    elseif ($type['collection_type'] == 'weekly') $color = 'weekly';
                                    else $color = 'daily';
                                ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <span class="type-badge <?php echo $type['collection_type']; ?>">
                                            <?php echo ucfirst(substr($type['collection_type'], 0, 3)); ?>
                                        </span>
                                        <span class="ms-2"><?php echo ucfirst($type['collection_type']); ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="fw-bold"><?php echo $type['type_count']; ?></span>
                                        <span class="text-muted ms-2">₹<?php echo number_format($type['type_total']/1000, 1); ?>K</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted text-center py-3">No data for this month</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-header" style="background: linear-gradient(135deg, #5f2c3e, #9b4b6e);">
                            <h5><i class="bi bi-info-circle me-2"></i>Quick Stats</h5>
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="summary-box">
                                        <div class="summary-title">Avg per day</div>
                                        <div class="summary-value">₹<?php echo $stats['total_pending'] > 0 ? number_format($stats['total_amount'] / $stats['total_pending'], 0) : 0; ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="summary-box">
                                        <div class="summary-title">Busiest day</div>
                                        <div class="summary-value">
                                            <?php
                                            $max_day = 0;
                                            $max_count = 0;
                                            foreach ($calendar_events as $day => $events) {
                                                if (count($events) > $max_count) {
                                                    $max_count = count($events);
                                                    $max_day = $day;
                                                }
                                            }
                                            echo $max_day ? date('j M', strtotime("$year-$month-$max_day")) : '-';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="summary-box">
                                        <div class="summary-title">Collection rate</div>
                                        <div class="summary-value">
                                            <?php
                                            $total_emis = $stats['total_pending'];
                                            $paid_this_month = 0; // You'd need to query this
                                            echo $total_emis > 0 ? round(($paid_this_month / $total_emis) * 100, 1) : 0; ?>%
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="summary-box">
                                        <div class="summary-title">Days with dues</div>
                                        <div class="summary-value"><?php echo count($calendar_events); ?></div>
                                    </div>
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

<!-- Day Details Modal -->
<div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Payments for <span id="modalDate"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalContent">
                <div class="text-center py-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
function showDayDetails(day, month, year) {
    const modal = new bootstrap.Modal(document.getElementById('dayModal'));
    const modalDate = document.getElementById('modalDate');
    const modalContent = document.getElementById('modalContent');
    
    const dateStr = year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    modalDate.textContent = new Date(dateStr).toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    modalContent.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    modal.show();
    
    // Load events via AJAX
    $.ajax({
        url: 'get-day-events.php',
        method: 'GET',
        data: { 
            date: dateStr,
            finance_id: <?php echo $finance_filter ?: 0; ?>
        },
        dataType: 'json',
        success: function(response) {
            let html = '';
            if (response.length > 0) {
                html += '<div class="table-responsive"><table class="table table-sm">';
                html += '<thead><tr><th>Customer</th><th>Agreement</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                
                response.forEach(function(event) {
                    let statusClass = '';
                    let statusText = '';
                    
                    if (event.days_overdue > 0) {
                        statusClass = 'danger';
                        statusText = 'Overdue (' + event.days_overdue + 'd)';
                    } else if (event.emi_due_date == '<?php echo date('Y-m-d'); ?>') {
                        statusClass = 'warning';
                        statusText = 'Due Today';
                    } else {
                        statusClass = 'primary';
                        statusText = 'Upcoming';
                    }
                    
                    html += '<tr>';
                    html += '<td>' + event.customer_name + '</td>';
                    html += '<td>' + event.agreement_number + '</td>';
                    html += '<td>₹' + Number(event.emi_amount).toLocaleString() + '</td>';
                    html += '<td><span class="badge bg-' + statusClass + '">' + statusText + '</span></td>';
                    html += '<td><a href="pay-emi.php?emi_id=' + event.id + '&customer_id=' + event.customer_id + '" class="btn btn-sm btn-success">Pay</a></td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
            } else {
                html = '<div class="alert alert-info mb-0">No payments scheduled for this day.</div>';
            }
            modalContent.innerHTML = html;
        },
        error: function() {
            modalContent.innerHTML = '<div class="alert alert-danger mb-0">Error loading events.</div>';
        }
    });
}

// Touch feedback
const buttons = document.querySelectorAll('.btn, .event-item');
buttons.forEach(button => {
    button.addEventListener('touchstart', function() {
        this.style.transform = 'scale(0.98)';
    });
    button.addEventListener('touchend', function() {
        this.style.transform = '';
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