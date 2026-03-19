<?php
require_once 'includes/auth.php';
$currentPage = 'view-loan';
$pageTitle = 'View Loan Details';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to view loans
if ($user_role != 'admin' && $user_role != 'accountant' && $user_role != 'staff') {
    $error = "You don't have permission to view loan details.";
}

$message = '';
$error = isset($error) ? $error : '';

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id == 0) {
    header("Location: loans.php?error=invalid_id");
    exit();
}

// Fetch loan details with finance company info
$loan_query = "SELECT l.*, f.finance_name 
                FROM loans l
                LEFT JOIN finance f ON l.finance_id = f.id
                WHERE l.id = ?";

// Add finance filter for non-admin users
if ($user_role != 'admin') {
    $loan_query .= " AND (l.finance_id = $finance_id OR l.finance_id IS NULL)";
}

$stmt = $conn->prepare($loan_query);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan_result = $stmt->get_result();

if ($loan_result->num_rows == 0) {
    header("Location: loans.php?error=not_found");
    exit();
}

$loan = $loan_result->fetch_assoc();
$stmt->close();

// Get customers using this loan
$customers_query = "SELECT c.*, 
                    (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as paid_emis,
                    (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id) as total_emis,
                    (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE customer_id = c.id AND status = 'paid') as total_paid
                    FROM customers c
                    WHERE c.loan_id = ?";

if ($user_role != 'admin') {
    $customers_query .= " AND c.finance_id = $finance_id";
}

$customers_query .= " ORDER BY c.id DESC LIMIT 20";

$stmt = $conn->prepare($customers_query);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$customers_result = $stmt->get_result();
$stmt->close();

// Get payment summary for this loan
$payment_query = "SELECT 
                  COUNT(DISTINCT es.customer_id) as active_customers,
                  COUNT(*) as total_emi_count,
                  SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_emi_count,
                  SUM(CASE WHEN es.status = 'overdue' THEN 1 ELSE 0 END) as overdue_emi_count,
                  SUM(CASE WHEN es.status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_emi_count,
                  COALESCE(SUM(es.emi_amount), 0) as total_expected,
                  COALESCE(SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END), 0) as total_collected,
                  COALESCE(SUM(CASE WHEN es.status = 'overdue' THEN es.emi_amount ELSE 0 END), 0) as total_overdue
                  FROM emi_schedule es
                  JOIN customers c ON es.customer_id = c.id
                  WHERE c.loan_id = ?";

if ($user_role != 'admin') {
    $payment_query .= " AND c.finance_id = $finance_id";
}

$stmt = $conn->prepare($payment_query);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$payment_result = $stmt->get_result();
$payment_stats = $payment_result->fetch_assoc();
$stmt->close();

// Get monthly collection trend
$monthly_query = "SELECT 
                  DATE_FORMAT(es.paid_date, '%Y-%m') as month,
                  COUNT(*) as payment_count,
                  COALESCE(SUM(es.emi_amount), 0) as month_total
                  FROM emi_schedule es
                  JOIN customers c ON es.customer_id = c.id
                  WHERE c.loan_id = ? AND es.status = 'paid' AND es.paid_date IS NOT NULL";

if ($user_role != 'admin') {
    $monthly_query .= " AND c.finance_id = $finance_id";
}

$monthly_query .= " GROUP BY DATE_FORMAT(es.paid_date, '%Y-%m')
                   ORDER BY month DESC
                   LIMIT 6";

$stmt = $conn->prepare($monthly_query);
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$monthly_result = $stmt->get_result();
$monthly_data = [];
if ($monthly_result && $monthly_result->num_rows > 0) {
    while ($row = $monthly_result->fetch_assoc()) {
        $monthly_data[] = $row;
    }
}
$stmt->close();

// Calculate interest rate classification
$interest_rate = floatval($loan['interest_rate']);
if ($interest_rate <= 1.5) {
    $interest_class = 'low';
    $interest_text = 'Low Interest';
} elseif ($interest_rate <= 2.5) {
    $interest_class = 'medium';
    $interest_text = 'Medium Interest';
} else {
    $interest_class = 'high';
    $interest_text = 'High Interest';
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
            background: linear-gradient(135deg, var(--primary), var(--info));
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
            color: var(--primary);
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
            border-color: var(--primary);
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
        
        .stat-icon.blue {
            background: var(--primary-bg);
            color: var(--primary);
        }
        
        .stat-icon.green {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .stat-icon.orange {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .stat-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
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
        
        /* Info Cards */
        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .info-card h5 {
            font-size: clamp(1rem, 3vw, 1.1rem);
            margin-bottom: 1rem;
            color: var(--text-primary);
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            font-size: clamp(0.85rem, 2.8vw, 0.95rem);
        }
        
        .info-item i {
            width: 20px;
            color: var(--primary);
            flex-shrink: 0;
        }
        
        .info-item span {
            word-break: break-word;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--text-muted);
            min-width: 100px;
        }
        
        .info-value {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        /* Interest Badge */
        .interest-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: clamp(0.75rem, 2.5vw, 0.85rem);
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }
        
        .interest-badge.low {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .interest-badge.medium {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .interest-badge.high {
            background: var(--danger-bg);
            color: var(--danger);
        }
        
        /* Progress Bar */
        .progress {
            height: 8px;
            border-radius: 4px;
            margin: 0.5rem 0;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, var(--success), var(--primary));
            border-radius: 4px;
        }
        
        /* Chart Cards */
        .chart-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
        }
        
        .chart-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            font-size: clamp(1rem, 3vw, 1.1rem);
        }
        
        .monthly-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .monthly-month {
            min-width: 60px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
        }
        
        .monthly-bar {
            flex: 1;
            height: 25px;
            background: #f8f9fa;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .monthly-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--primary));
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 0.5rem;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .monthly-amount {
            min-width: 80px;
            text-align: right;
            font-weight: 600;
            color: var(--success);
            font-size: clamp(0.8rem, 2.5vw, 0.9rem);
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
            min-width: 800px;
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
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        .customer-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .small-progress {
            width: 80px;
            height: 5px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
        }
        
        /* Document Tags */
        .document-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .document-tag {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: clamp(0.7rem, 2.3vw, 0.8rem);
            color: var(--text-primary);
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
            
            .welcome-banner {
                padding: 1.25rem;
            }
            
            .welcome-banner .d-flex {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .info-card {
                padding: 1.25rem;
                margin-bottom: 1rem;
            }
            
            .info-item {
                flex-wrap: wrap;
            }
            
            .info-label {
                min-width: 80px;
            }
            
            .monthly-item {
                flex-wrap: wrap;
            }
            
            .monthly-month {
                min-width: 50px;
            }
            
            .monthly-amount {
                min-width: 70px;
            }
            
            .table-container {
                padding: 1rem;
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
            
            .info-card {
                padding: 1rem;
            }
            
            .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            
            .info-label {
                min-width: auto;
            }
            
            .monthly-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .monthly-bar {
                width: 100%;
            }
            
            .monthly-amount {
                text-align: left;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
            
            .action-buttons .btn {
                flex: 1;
            }
        }
        
        /* Touch-friendly improvements */
        .btn, 
        .dropdown-item,
        .nav-link,
        .stat-card,
        .info-card {
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Empty state */
        .empty-state {
            padding: 2rem 1rem;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: #dee2e6;
            margin-bottom: 1rem;
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
        
        .flex-shrink-0 {
            flex-shrink: 0;
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
                        <h3 class="text-truncate-custom"><i class="bi bi-file-text me-2"></i>Loan Details</h3>
                        <p class="text-truncate-custom">View complete information about this loan product</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap flex-shrink-0">
                        <a href="loans.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Loans
                        </a>
                        <?php if ($user_role == 'admin'): ?>
                        <a href="edit-loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-light">
                            <i class="bi bi-pencil me-2"></i>Edit Loan
                        </a>
                        <?php endif; ?>
                        <span class="badge bg-white text-primary">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2 flex-shrink-0"></i>
                    <span class="break-word"><?php echo $message; ?></span>
                    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
                    <span class="break-word"><?php echo $error; ?></span>
                    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Check if user has permission -->
            <?php if ($user_role == 'admin' || $user_role == 'accountant' || $user_role == 'staff'): ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Active Customers</div>
                                    <div class="stat-value"><?php echo $payment_stats['active_customers'] ?? 0; ?></div>
                                </div>
                                <div class="stat-icon blue flex-shrink-0"><i class="bi bi-people"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Using this loan</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Total EMIs</div>
                                    <div class="stat-value"><?php echo number_format($payment_stats['total_emi_count'] ?? 0); ?></div>
                                </div>
                                <div class="stat-icon green flex-shrink-0"><i class="bi bi-calendar-check"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">All time</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Collected</div>
                                    <div class="stat-value">₹<?php echo number_format($payment_stats['total_collected'] ?? 0, 0); ?></div>
                                </div>
                                <div class="stat-icon orange flex-shrink-0"><i class="bi bi-cash"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">Total collected</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 col-md-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div class="overflow-hidden" style="min-width: 0; flex: 1;">
                                    <div class="stat-label text-truncate">Overdue</div>
                                    <div class="stat-value text-danger"><?php echo number_format($payment_stats['overdue_emi_count'] ?? 0); ?></div>
                                </div>
                                <div class="stat-icon purple flex-shrink-0"><i class="bi bi-exclamation-triangle"></i></div>
                            </div>
                            <div class="stat-footer text-truncate">
                                <span class="text-muted">₹<?php echo number_format($payment_stats['total_overdue'] ?? 0, 0); ?> overdue</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loan Information Cards -->
                <div class="row g-3 mb-4">
                    <!-- Basic Information -->
                    <div class="col-md-4">
                        <div class="info-card">
                            <h5><i class="bi bi-info-circle me-2"></i>Basic Information</h5>
                            <div class="info-item">
                                <i class="bi bi-tag"></i>
                                <span class="info-label">Loan ID:</span>
                                <span class="info-value">#<?php echo $loan['id']; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-building"></i>
                                <span class="info-label">Finance:</span>
                                <span class="info-value"><?php echo $loan['finance_name'] ?? 'All Companies'; ?></span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-calendar"></i>
                                <span class="info-label">Created:</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($loan['created_at'])); ?></span>
                            </div>
                            <?php if (!empty($loan['loan_purpose'])): ?>
                            <div class="info-item">
                                <i class="bi bi-quote"></i>
                                <span class="info-label">Purpose:</span>
                                <span class="info-value break-word"><?php echo htmlspecialchars($loan['loan_purpose']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Financial Details -->
                    <div class="col-md-4">
                        <div class="info-card">
                            <h5><i class="bi bi-cash-stack me-2"></i>Financial Details</h5>
                            <div class="info-item">
                                <i class="bi bi-currency-rupee"></i>
                                <span class="info-label">Loan Amount:</span>
                                <span class="info-value fw-bold">₹<?php echo number_format($loan['loan_amount'], 2); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-percent"></i>
                                <span class="info-label">Interest Rate:</span>
                                <span class="info-value">
                                    <span class="interest-badge <?php echo $interest_class; ?>">
                                        <?php echo $loan['interest_rate']; ?>% (<?php echo $interest_text; ?>)
                                    </span>
                                </span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-calendar-month"></i>
                                <span class="info-label">Tenure:</span>
                                <span class="info-value"><?php echo $loan['loan_tenure']; ?> months</span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-calculator"></i>
                                <span class="info-label">Monthly EMI:</span>
                                <span class="info-value fw-bold text-primary">
                                    ₹<?php 
                                    $monthly_interest = ($loan['loan_amount'] * $loan['interest_rate']) / 100;
                                    $monthly_principal = $loan['loan_amount'] / $loan['loan_tenure'];
                                    echo number_format($monthly_principal + $monthly_interest, 2);
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Summary -->
                    <div class="col-md-4">
                        <div class="info-card">
                            <h5><i class="bi bi-pie-chart me-2"></i>Payment Summary</h5>
                            <div class="info-item">
                                <i class="bi bi-check-circle text-success"></i>
                                <span class="info-label">Paid EMIs:</span>
                                <span class="info-value"><?php echo number_format($payment_stats['paid_emi_count'] ?? 0); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-hourglass-split text-warning"></i>
                                <span class="info-label">Unpaid:</span>
                                <span class="info-value"><?php echo number_format($payment_stats['unpaid_emi_count'] ?? 0); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="bi bi-exclamation-triangle text-danger"></i>
                                <span class="info-label">Overdue:</span>
                                <span class="info-value"><?php echo number_format($payment_stats['overdue_emi_count'] ?? 0); ?></span>
                            </div>
                            <div class="mt-2">
                                <div class="d-flex justify-content-between align-items-center small">
                                    <span class="text-muted">Collection Rate</span>
                                    <span class="fw-semibold">
                                        <?php 
                                        $collection_rate = ($payment_stats['total_expected'] > 0) ? 
                                            ($payment_stats['total_collected'] / $payment_stats['total_expected'] * 100) : 0;
                                        echo number_format($collection_rate, 1) . '%';
                                        ?>
                                    </span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $collection_rate; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents and Monthly Trend -->
                <div class="row g-3 mb-4">
                    <!-- Required Documents -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="bi bi-file-earmark-text me-2"></i>Required Documents</h5>
                            <?php if (!empty($loan['loan_documents'])): ?>
                                <div class="document-tags">
                                    <?php 
                                    $documents = explode(',', $loan['loan_documents']);
                                    foreach ($documents as $doc): 
                                        $doc = trim($doc);
                                        if (!empty($doc)):
                                    ?>
                                        <span class="document-tag">
                                            <i class="bi bi-check-circle-fill text-success me-1"></i>
                                            <?php echo htmlspecialchars($doc); ?>
                                        </span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">No specific documents required</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Monthly Collection Trend -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="bi bi-graph-up me-2"></i>Monthly Collection Trend</h5>
                            <?php if (!empty($monthly_data)): ?>
                                <?php 
                                $max_monthly = 0;
                                foreach ($monthly_data as $month) {
                                    $max_monthly = max($max_monthly, $month['month_total']);
                                }
                                foreach ($monthly_data as $month): 
                                    $percentage = $max_monthly > 0 ? ($month['month_total'] / $max_monthly) * 100 : 0;
                                    $month_name = date('M Y', strtotime($month['month'] . '-01'));
                                ?>
                                <div class="monthly-item">
                                    <span class="monthly-month"><?php echo $month_name; ?></span>
                                    <div class="monthly-bar">
                                        <div class="monthly-bar-fill" style="width: <?php echo $percentage; ?>%;">
                                            <?php if ($percentage > 20): ?>
                                                ₹<?php echo number_format($month['month_total'], 0); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="monthly-amount">₹<?php echo number_format($month['month_total'], 0); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-graph-up"></i>
                                    <p class="text-muted">No collection data available</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Customers Using This Loan -->
                <div class="table-container">
                    <h5 class="mb-3"><i class="bi bi-people me-2"></i>Customers Using This Loan</h5>
                    <?php if ($customers_result && $customers_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table-custom">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Customer</th>
                                        <th>Agreement</th>
                                        <th>Loan Amount</th>
                                        <th>EMI</th>
                                        <th>Progress</th>
                                        <th>Collected</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($customer = $customers_result->fetch_assoc()): 
                                        $progress = ($customer['total_emis'] > 0) ? 
                                            ($customer['paid_emis'] / $customer['total_emis'] * 100) : 0;
                                    ?>
                                    <tr>
                                        <td><span class="fw-semibold">#<?php echo $customer['id']; ?></span></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="customer-avatar">
                                                    <?php 
                                                    $initials = '';
                                                    $nameParts = explode(' ', $customer['customer_name']);
                                                    foreach ($nameParts as $part) {
                                                        $initials .= strtoupper(substr($part, 0, 1));
                                                    }
                                                    echo substr($initials, 0, 2) ?: 'CU';
                                                    ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($customer['customer_number']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($customer['agreement_number']); ?></td>
                                        <td>₹<?php echo number_format($customer['loan_amount'], 2); ?></td>
                                        <td>₹<?php echo number_format($customer['emi'], 2); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress small-progress">
                                                    <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <small><?php echo $customer['paid_emis']; ?>/<?php echo $customer['total_emis']; ?></small>
                                            </div>
                                        </td>
                                        <td>₹<?php echo number_format($customer['total_paid'] ?? 0, 0); ?></td>
                                        <td>
                                            <?php if ($customer['paid_emis'] == $customer['total_emis']): ?>
                                                <span class="badge bg-success">Completed</span>
                                            <?php elseif ($customer['paid_emis'] > 0): ?>
                                                <span class="badge bg-info">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">New</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view-customer.php?id=<?php echo $customer['id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View Customer">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="collect-emi.php?customer_id=<?php echo $customer['id']; ?>" 
                                                   class="btn btn-sm btn-outline-success" title="Collect EMI">
                                                    <i class="bi bi-cash"></i>
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
                            <i class="bi bi-people"></i>
                            <p class="text-muted">No customers are currently using this loan</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Permission Denied Message -->
                <div class="permission-denied">
                    <i class="bi bi-shield-lock-fill"></i>
                    <h4 class="break-word">Access Denied</h4>
                    <p class="break-word">You don't have permission to view loan details.</p>
                    <a href="loans.php" class="btn btn-primary">
                        <i class="bi bi-cash-stack me-2"></i>Back to Loans
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // Add touch feedback
    window.onload = function() {
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
</script>
</body>
</html>