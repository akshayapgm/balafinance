<?php
require_once 'includes/auth.php';
$currentPage = 'investors';
$pageTitle = 'View Investor';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if investor ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: investors.php');
    exit();
}

$investor_id = intval($_GET['id']);

// Get investor details
$query = "SELECT i.*, f.finance_name, u.full_name as created_by_name,
          DATEDIFF(i.maturity_date, CURDATE()) as days_to_maturity
          FROM investors i
          JOIN finance f ON i.finance_id = f.id
          LEFT JOIN users u ON i.created_by = u.id
          WHERE i.id = ?";

// Add role-based filter
if ($user_role != 'admin') {
    $query .= " AND i.finance_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $investor_id, $finance_id);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $investor_id);
}

$stmt->execute();
$investor = $stmt->get_result()->fetch_assoc();

if (!$investor) {
    header('Location: investors.php');
    exit();
}

// Get return history
$returns_query = "SELECT ir.*, u.full_name as processed_by_name 
                 FROM investor_returns ir
                 LEFT JOIN users u ON ir.processed_by = u.id
                 WHERE ir.investor_id = ?
                 ORDER BY ir.return_date DESC, ir.created_at DESC";

$stmt = $conn->prepare($returns_query);
$stmt->bind_param('i', $investor_id);
$stmt->execute();
$returns = $stmt->get_result();

// Get transaction history
$transactions_query = "SELECT it.*, u.full_name as created_by_name 
                      FROM investor_transactions it
                      LEFT JOIN users u ON it.created_by = u.id
                      WHERE it.investor_id = ?
                      ORDER BY it.transaction_date DESC, it.created_at DESC";

$stmt = $conn->prepare($transactions_query);
$stmt->bind_param('i', $investor_id);
$stmt->execute();
$transactions = $stmt->get_result();

// Calculate statistics
$total_returns = 0;
$total_interest = 0;
$total_principal = 0;
$return_count = 0;

if ($returns) {
    $return_count = $returns->num_rows;
    $returns->data_seek(0);
    while ($row = $returns->fetch_assoc()) {
        $total_returns += $row['return_amount'];
        if ($row['return_type'] == 'interest') {
            $total_interest += $row['return_amount'];
        } elseif ($row['return_type'] == 'principal') {
            $total_principal += $row['return_amount'];
        } elseif ($row['return_type'] == 'both') {
            // Assume 50-50 split for simplicity
            $total_interest += $row['return_amount'] / 2;
            $total_principal += $row['return_amount'] / 2;
        }
    }
    $returns->data_seek(0);
}

// Calculate expected returns to date
$investment_date = new DateTime($investor['investment_date']);
$current_date = new DateTime();
$months_diff = $investment_date->diff($current_date)->m + ($investment_date->diff($current_date)->y * 12);

$expected_returns = 0;
if ($investor['return_type'] == 'monthly') {
    $expected_returns = $investor['return_amount'] * $months_diff;
} elseif ($investor['return_type'] == 'quarterly') {
    $quarters = floor($months_diff / 3);
    $expected_returns = $investor['return_amount'] * $quarters;
} elseif ($investor['return_type'] == 'yearly') {
    $years = floor($months_diff / 12);
    $expected_returns = $investor['return_amount'] * $years;
}

$due_amount = $expected_returns - $investor['total_return_paid'];

// Determine status display
$status_display = ucfirst($investor['status']);
$status_class = 'success';
if ($investor['status'] == 'active' && $investor['maturity_date'] && $investor['maturity_date'] < date('Y-m-d')) {
    $status_display = 'Overdue';
    $status_class = 'danger';
} elseif ($investor['status'] == 'closed') {
    $status_class = 'secondary';
}

// Get return type display
$return_type_display = ucfirst($investor['return_type']);
$return_type_class = 'primary';
if ($investor['return_type'] == 'quarterly') {
    $return_type_class = 'info';
} elseif ($investor['return_type'] == 'yearly') {
    $return_type_class = 'success';
} elseif ($investor['return_type'] == 'maturity') {
    $return_type_class = 'warning';
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
    <link href="assets/styles.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1e293b;
            --light: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #1e293b;
            --text-muted: #64748b;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.75rem;
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
            background-color: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            width: 100%;
        }

        .page-header h4 {
            font-weight: 700;
            margin-bottom: 0.25rem;
            font-size: 1.5rem;
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

        /* Profile Card */
        .profile-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 600;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .profile-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
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
            font-size: 0.8rem;
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

        /* Info Card */
        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-card h6 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .info-item {
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .info-item .label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .info-item .value {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Timeline Card */
        .timeline-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .timeline-card .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }

        .timeline-card .card-header h5 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0;
            font-size: 1rem;
        }

        .timeline {
            padding: 1.5rem;
        }

        .timeline-item {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            position: relative;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 35px;
            bottom: -15px;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item:last-child::before {
            display: none;
        }

        .timeline-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-bg);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            z-index: 1;
        }

        .timeline-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }

        .timeline-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .timeline-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            gap: 1rem;
        }

        .timeline-amount {
            font-weight: 600;
            color: var(--success);
        }

        /* Table Styles */
        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .table-card .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }

        .table-card .card-header h5 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0;
            font-size: 1rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            margin-bottom: 0;
        }

        .table th {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            padding: 0.75rem 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
            background: var(--light);
            white-space: nowrap;
        }

        .table td {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .badge-return {
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .badge-return.interest {
            background: var(--info-bg);
            color: var(--info);
        }

        .badge-return.principal {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .badge-return.both {
            background: var(--success-bg);
            color: var(--success);
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

        .btn-return { background: var(--success); }
        .btn-edit { background: var(--warning); }

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
            
            .info-grid {
                grid-template-columns: 1fr;
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
                        <h4><i class="bi bi-person-badge me-2"></i>Investor Details</h4>
                        <p>View complete investor information</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="investor-return.php?investor_id=<?php echo $investor_id; ?>" class="btn btn-light">
                            <i class="bi bi-cash-stack me-2"></i>Record Return
                        </a>
                        <?php if ($user_role == 'admin'): ?>
                        <a href="edit-investor.php?id=<?php echo $investor_id; ?>" class="btn btn-light">
                            <i class="bi bi-pencil me-2"></i>Edit Investor
                        </a>
                        <?php endif; ?>
                        <a href="investors.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Investors
                        </a>
                    </div>
                </div>
            </div>

            <!-- Profile Card -->
            <div class="profile-card">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex gap-4">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($investor['investor_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="profile-name"><?php echo htmlspecialchars($investor['investor_name']); ?></div>
                                <div class="profile-meta">
                                    <span class="profile-meta-item">
                                        <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($investor['investor_number']); ?>
                                    </span>
                                    <?php if ($investor['email']): ?>
                                    <span class="profile-meta-item">
                                        <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($investor['email']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="profile-meta-item">
                                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($investor['finance_name']); ?>
                                    </span>
                                </div>
                                <div class="mt-3">
                                    <span class="badge bg-<?php echo $status_class; ?> bg-opacity-10 text-<?php echo $status_class; ?> p-2 me-2">
                                        <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>
                                        <?php echo $status_display; ?>
                                    </span>
                                    <span class="badge bg-<?php echo $return_type_class; ?> bg-opacity-10 text-<?php echo $return_type_class; ?> p-2">
                                        <i class="bi bi-calendar-check me-1"></i>
                                        <?php echo $return_type_display; ?> Returns
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border-start ps-4">
                            <div class="mb-2">
                                <span class="text-muted small">Created By</span>
                                <div class="fw-semibold"><?php echo $investor['created_by_name'] ?: 'System'; ?></div>
                            </div>
                            <div class="mb-2">
                                <span class="text-muted small">Created At</span>
                                <div class="fw-semibold"><?php echo date('d M Y, h:i A', strtotime($investor['created_at'])); ?></div>
                            </div>
                            <?php if ($investor['updated_at'] != $investor['created_at']): ?>
                            <div>
                                <span class="text-muted small">Last Updated</span>
                                <div class="fw-semibold"><?php echo date('d M Y, h:i A', strtotime($investor['updated_at'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stat Cards -->
            <div class="stat-row">
                <div class="stat-card primary">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-cash-stack"></i>
                                Investment Amount
                            </div>
                            <div class="stat-value">₹<?php echo number_format($investor['investment_amount'], 2); ?></div>
                            <div class="stat-footer">@ <?php echo $investor['interest_rate']; ?>% p.a.</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card success">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-cash"></i>
                                Total Returns Paid
                            </div>
                            <div class="stat-value">₹<?php echo number_format($investor['total_return_paid'], 2); ?></div>
                            <div class="stat-footer"><?php echo $return_count; ?> payments</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card warning">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-graph-up-arrow"></i>
                                Expected Returns
                            </div>
                            <div class="stat-value">₹<?php echo number_format($expected_returns, 2); ?></div>
                            <div class="stat-footer">Since investment date</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card info">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">
                                <i class="bi bi-clock-history"></i>
                                Due Amount
                            </div>
                            <div class="stat-value <?php echo $due_amount > 0 ? 'text-danger' : 'text-success'; ?>">
                                ₹<?php echo number_format($due_amount, 2); ?>
                            </div>
                            <div class="stat-footer"><?php echo $due_amount > 0 ? 'Pending' : 'Up to date'; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Investment Details Card -->
            <div class="info-card">
                <h6><i class="bi bi-info-circle me-2"></i>Investment Details</h6>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="label">Investment Date</div>
                        <div class="value"><?php echo date('d M Y', strtotime($investor['investment_date'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Maturity Date</div>
                        <div class="value">
                            <?php echo $investor['maturity_date'] ? date('d M Y', strtotime($investor['maturity_date'])) : 'Not set'; ?>
                            <?php if ($investor['days_to_maturity'] !== null && $investor['status'] == 'active'): ?>
                                <span class="small <?php echo $investor['days_to_maturity'] < 0 ? 'text-danger' : 'text-muted'; ?> ms-2">
                                    (<?php echo $investor['days_to_maturity'] < 0 ? abs($investor['days_to_maturity']) . ' days overdue' : $investor['days_to_maturity'] . ' days left'; ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="label">Return Type</div>
                        <div class="value"><?php echo ucfirst($investor['return_type']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="label">Return Amount</div>
                        <div class="value">₹<?php echo number_format($investor['return_amount'], 2); ?> per <?php echo rtrim($investor['return_type'], 'ly'); ?></div>
                    </div>
                    <?php if ($investor['pan_number']): ?>
                    <div class="info-item">
                        <div class="label">PAN Number</div>
                        <div class="value"><?php echo htmlspecialchars($investor['pan_number']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($investor['aadhar_number']): ?>
                    <div class="info-item">
                        <div class="label">Aadhar Number</div>
                        <div class="value"><?php echo htmlspecialchars($investor['aadhar_number']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($investor['address']): ?>
                    <div class="info-item">
                        <div class="label">Address</div>
                        <div class="value"><?php echo nl2br(htmlspecialchars($investor['address'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($investor['remarks']): ?>
                    <div class="info-item">
                        <div class="label">Remarks</div>
                        <div class="value"><?php echo nl2br(htmlspecialchars($investor['remarks'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Return History Table -->
            <div class="table-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-clock-history me-2"></i>Return History</h5>
                    <a href="investor-return.php?investor_id=<?php echo $investor_id; ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-plus-circle me-1"></i>New Return
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Payment Method</th>
                                <th>Transaction ID</th>
                                <th>Processed By</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($returns && $returns->num_rows > 0): ?>
                                <?php while ($return = $returns->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($return['return_date'])); ?></td>
                                    <td class="fw-semibold text-success">₹<?php echo number_format($return['return_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge-return <?php echo $return['return_type']; ?>">
                                            <?php echo ucfirst($return['return_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $return['payment_method'])); ?></td>
                                    <td><?php echo $return['transaction_id'] ?: '-'; ?></td>
                                    <td><?php echo $return['processed_by_name'] ?: 'System'; ?></td>
                                    <td><?php echo $return['remarks'] ?: '-'; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem; color: #dee2e6;"></i>
                                        <p class="mt-2 text-muted">No return records found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Transaction History Table -->
            <div class="table-card">
                <div class="card-header">
                    <h5><i class="bi bi-arrow-left-right me-2"></i>Transaction History</h5>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Payment Method</th>
                                <th>Created By</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($transactions && $transactions->num_rows > 0): ?>
                                <?php while ($trans = $transactions->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($trans['transaction_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $trans['transaction_type'] == 'investment' ? 'primary' : 
                                                ($trans['transaction_type'] == 'return' ? 'success' : 'warning'); 
                                        ?> bg-opacity-10 text-<?php 
                                            echo $trans['transaction_type'] == 'investment' ? 'primary' : 
                                                ($trans['transaction_type'] == 'return' ? 'success' : 'warning'); 
                                        ?> p-2">
                                            <?php echo ucfirst($trans['transaction_type']); ?>
                                        </span>
                                    </td>
                                    <td class="fw-semibold <?php echo $trans['transaction_type'] == 'return' ? 'text-success' : 'text-primary'; ?>">
                                        ₹<?php echo number_format($trans['amount'], 2); ?>
                                    </td>
                                    <td><?php echo $trans['reference_number'] ?: '-'; ?></td>
                                    <td><?php echo $trans['payment_method'] ? ucfirst(str_replace('_', ' ', $trans['payment_method'])) : '-'; ?></td>
                                    <td><?php echo $trans['created_by_name'] ?: 'System'; ?></td>
                                    <td><?php echo $trans['remarks'] ?: '-'; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem; color: #dee2e6;"></i>
                                        <p class="mt-2 text-muted">No transaction records found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Investment Timeline -->
            <div class="timeline-card">
                <div class="card-header">
                    <h5><i class="bi bi-clock me-2"></i>Investment Timeline</h5>
                </div>
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-icon">
                            <i class="bi bi-plus-circle"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">Investment Created</div>
                            <div class="timeline-meta">
                                <span><i class="bi bi-calendar"></i> <?php echo date('d M Y, h:i A', strtotime($investor['created_at'])); ?></span>
                                <span><i class="bi bi-person"></i> <?php echo $investor['created_by_name'] ?: 'System'; ?></span>
                            </div>
                            <div class="mt-2">
                                Initial investment of <span class="timeline-amount">₹<?php echo number_format($investor['investment_amount'], 2); ?></span> created
                            </div>
                        </div>
                    </div>

                    <?php if ($returns && $returns->num_rows > 0): ?>
                        <?php 
                        $returns->data_seek(0);
                        $return_count = 0;
                        while ($return = $returns->fetch_assoc()): 
                            $return_count++;
                        ?>
                        <div class="timeline-item">
                            <div class="timeline-icon success">
                                <i class="bi bi-cash"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">Return #<?php echo $return_count; ?> Recorded</div>
                                <div class="timeline-meta">
                                    <span><i class="bi bi-calendar"></i> <?php echo date('d M Y', strtotime($return['return_date'])); ?></span>
                                    <span><i class="bi bi-person"></i> <?php echo $return['processed_by_name'] ?: 'System'; ?></span>
                                </div>
                                <div class="mt-2">
                                    Return of <span class="timeline-amount">₹<?php echo number_format($return['return_amount'], 2); ?></span> 
                                    (<?php echo ucfirst($return['return_type']); ?>) via <?php echo ucfirst(str_replace('_', ' ', $return['payment_method'])); ?>
                                </div>
                                <?php if ($return['remarks']): ?>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-chat"></i> <?php echo $return['remarks']; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php endif; ?>

                    <?php if ($investor['status'] == 'closed'): ?>
                    <div class="timeline-item">
                        <div class="timeline-icon warning">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">Investment Closed</div>
                            <div class="timeline-meta">
                                <span><i class="bi bi-calendar"></i> <?php echo date('d M Y', strtotime($investor['updated_at'])); ?></span>
                            </div>
                            <div class="mt-2">
                                Investment marked as closed
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>