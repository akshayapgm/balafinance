<?php
require_once 'includes/auth.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$currentPage = 'view-customer';
$pageTitle = 'View Customer';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'] ?? 'user';
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($customer_id == 0) {
    header('Location: customers.php');
    exit();
}

// Get customer details
$customer_query = "
    SELECT 
        c.*,
        l.loan_name,
        f.finance_name
    FROM customers c
    JOIN loans l ON c.loan_id = l.id
    JOIN finance f ON c.finance_id = f.id
    WHERE c.id = ?
";

$stmt = $conn->prepare($customer_query);
if (!$stmt) {
    die("Error preparing customer query: " . $conn->error);
}
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    header('Location: customers.php');
    exit();
}

// Check if user has access to this customer
if ($user_role != 'admin' && $customer['finance_id'] != $finance_id) {
    header('Location: customers.php');
    exit();
}

// Get EMI schedule for this customer
// FIXED: Properly structured query with correct column names
$emi_query = "
    SELECT 
        es.*,
        DATE_FORMAT(es.emi_due_date, '%d-%m-%Y') as formatted_due_date,
        DATE_FORMAT(es.paid_date, '%d-%m-%Y') as formatted_paid_date,
        CASE 
            WHEN es.status = 'paid' THEN 'Paid'
            WHEN es.status = 'unpaid' AND es.emi_due_date < CURDATE() THEN 'Overdue'
            WHEN es.status = 'unpaid' THEN 'Unpaid'
            WHEN es.status = 'foreclosed' THEN 'Foreclosed'
            ELSE es.status
        END as display_status
    FROM emi_schedule es
    WHERE es.customer_id = ?
    ORDER BY es.emi_due_date ASC
";

$stmt = $conn->prepare($emi_query);
if (!$stmt) {
    die("Error preparing EMI query at line " . __LINE__ . ": " . $conn->error);
}
$stmt->bind_param('i', $customer_id);
$stmt->execute();
$emi_schedule = $stmt->get_result();
$stmt->close();

// Calculate summary statistics
$total_emis = $emi_schedule->num_rows;
$paid_emis = 0;
$unpaid_emis = 0;
$overdue_emis = 0;
$foreclosed_emis = 0;
$total_paid = 0;
$total_principal_paid = 0;
$total_interest_paid = 0;

$emi_schedule->data_seek(0);
while ($emi = $emi_schedule->fetch_assoc()) {
    if ($emi['status'] == 'paid') {
        $paid_emis++;
        $total_paid += $emi['emi_amount'];
        $total_principal_paid += ($emi['principal_paid'] ?? 0);
        $total_interest_paid += ($emi['interest_paid'] ?? 0);
    } elseif ($emi['status'] == 'unpaid') {
        if (strtotime($emi['emi_due_date']) < time()) {
            $overdue_emis++;
        } else {
            $unpaid_emis++;
        }
    } elseif ($emi['status'] == 'foreclosed') {
        $foreclosed_emis++;
        $total_paid += ($emi['principal_paid'] ?? 0) + ($emi['interest_paid'] ?? 0);
        $total_principal_paid += ($emi['principal_paid'] ?? 0);
        $total_interest_paid += ($emi['interest_paid'] ?? 0);
    }
}
$emi_schedule->data_seek(0);

// Calculate outstanding amount
$total_loan_amount = $customer['principal_amount'] + $customer['interest_amount'];
$outstanding = $total_loan_amount - $total_paid;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo htmlspecialchars($customer['customer_name']); ?></title>
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

        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .page-header h4 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
        }

        .page-header .btn-light {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            border-radius: 30px;
            transition: all 0.2s ease;
        }

        .page-header .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .customer-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .info-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .info-badge.monthly {
            background: #e3f2fd;
            color: #2196f3;
        }

        .info-badge.weekly {
            background: #f3e5f5;
            color: #9c27b0;
        }

        .info-badge.daily {
            background: #fff3e0;
            color: #ff9800;
        }

        .summary-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            height: 100%;
            transition: all 0.2s ease;
            box-shadow: var(--shadow);
        }

        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        .summary-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .summary-label {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
        }

        .badge-status {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-paid {
            background: #e3f7e3;
            color: var(--success);
        }

        .badge-unpaid {
            background: #fff3e0;
            color: var(--warning);
        }

        .badge-overdue {
            background: #ffe6e6;
            color: var(--danger);
        }

        .badge-foreclosed {
            background: #e6e6e6;
            color: var(--secondary);
        }

        .btn-action {
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-pay {
            background: var(--success);
            color: white;
        }

        .btn-pay:hover {
            background: #27ae60;
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #e67e22;
            color: white;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #2980b9;
            color: white;
        }

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
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="bi bi-person-badge me-2"></i>Customer Details</h4>
                        <p><?php echo htmlspecialchars($customer['customer_name']); ?> - <?php echo htmlspecialchars($customer['loan_name']); ?></p>
                    </div>
                    <div>
                        <a href="customers.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Customers
                        </a>
                    </div>
                </div>
            </div>

            <!-- Customer Information -->
            <div class="customer-card">
                <div class="row g-4">
                    <div class="col-md-3">
                        <div class="info-label">Customer Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-label">Agreement #</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['agreement_number']); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-label">Loan Amount</div>
                        <div class="info-value">₹<?php echo number_format($customer['loan_amount'], 2); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-label">Interest Rate</div>
                        <div class="info-value"><?php echo $customer['interest_rate']; ?>%</div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-label">Collection Type</div>
                        <div>
                            <span class="info-badge <?php echo $customer['collection_type'] ?? 'monthly'; ?>">
                                <i class="bi bi-<?php echo $customer['collection_type'] == 'weekly' ? 'calendar-week' : ($customer['collection_type'] == 'daily' ? 'calendar-day' : 'calendar-month'); ?> me-1"></i>
                                <?php echo ucfirst($customer['collection_type'] ?? 'Monthly'); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="summary-card">
                        <div class="summary-value"><?php echo $paid_emis + $foreclosed_emis; ?>/<?php echo $total_emis; ?></div>
                        <div class="summary-label">EMIs Paid/Foreclosed</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="summary-card">
                        <div class="summary-value">₹<?php echo number_format($total_paid, 2); ?></div>
                        <div class="summary-label">Total Paid</div>
                        <div class="small text-muted">
                            P: ₹<?php echo number_format($total_principal_paid, 2); ?> | 
                            I: ₹<?php echo number_format($total_interest_paid, 2); ?>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="summary-card">
                        <div class="summary-value text-warning">₹<?php echo number_format($outstanding, 2); ?></div>
                        <div class="summary-label">Outstanding</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="summary-card">
                        <div class="summary-value"><?php echo $unpaid_emis; ?> | <?php echo $overdue_emis; ?> | <?php echo $foreclosed_emis; ?></div>
                        <div class="summary-label">Unpaid | Overdue | Foreclosed</div>
                    </div>
                </div>
            </div>

            <!-- EMI Schedule Table -->
            <div class="table-container">
                <h5 class="mb-3"><i class="bi bi-calendar-check me-2"></i>EMI Schedule</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Week</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Installment</th>
                                <th>Paid Amount</th>
                                <th>Due Date</th>
                                <th>Paid Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($emi_schedule && $emi_schedule->num_rows > 0): ?>
                                <?php 
                                $counter = 1;
                                while ($emi = $emi_schedule->fetch_assoc()): 
                                    $paid_amount = ($emi['principal_paid'] ?? 0) + ($emi['interest_paid'] ?? 0);
                                    
                                    $badge_class = '';
                                    if ($emi['status'] == 'paid') {
                                        $badge_class = 'badge-paid';
                                    } elseif ($emi['status'] == 'unpaid') {
                                        if (strtotime($emi['emi_due_date']) < time()) {
                                            $badge_class = 'badge-overdue';
                                        } else {
                                            $badge_class = 'badge-unpaid';
                                        }
                                    } elseif ($emi['status'] == 'foreclosed') {
                                        $badge_class = 'badge-foreclosed';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <?php 
                                        if ($customer['collection_type'] == 'weekly') {
                                            echo 'Week ' . ($emi['week_number'] ?: $counter);
                                        } elseif ($customer['collection_type'] == 'daily') {
                                            echo 'Day ' . ($emi['day_number'] ?: $counter);
                                        } else {
                                            echo 'Month ' . $counter;
                                        }
                                        ?>
                                    </td>
                                    <td>₹<?php echo number_format($emi['principal_amount'], 2); ?></td>
                                    <td>₹<?php echo number_format($emi['interest_amount'], 2); ?></td>
                                    <td>₹<?php echo number_format($emi['emi_amount'], 2); ?></td>
                                    <td>₹<?php echo number_format($paid_amount, 2); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($emi['emi_due_date']) && $emi['emi_due_date'] != '0000-00-00') {
                                            echo date('d-m-Y', strtotime($emi['emi_due_date']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($emi['paid_date']) && $emi['paid_date'] != '0000-00-00') {
                                            echo date('d-m-Y', strtotime($emi['paid_date']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $badge_class; ?>">
                                            <?php 
                                            if ($emi['status'] == 'unpaid' && strtotime($emi['emi_due_date']) < time()) {
                                                echo 'Overdue';
                                            } elseif ($emi['status'] == 'foreclosed') {
                                                echo 'Foreclosed';
                                            } else {
                                                echo ucfirst($emi['status']);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($emi['status'] == 'unpaid'): ?>
                                            <?php if (strtotime($emi['emi_due_date']) < time()): ?>
                                                <a href="pay-emi.php?emi_id=<?php echo $emi['id']; ?>" class="btn-action btn-warning btn-sm">
                                                    <i class="bi bi-cash"></i> Pay Late
                                                </a>
                                            <?php else: ?>
                                                <a href="pay-emi.php?emi_id=<?php echo $emi['id']; ?>" class="btn-action btn-pay btn-sm">
                                                    <i class="bi bi-cash"></i> Pay
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif ($emi['status'] == 'paid' || $emi['status'] == 'foreclosed'): ?>
                                            <a href="receipt.php?emi_id=<?php echo $emi['id']; ?>" class="btn-action btn-info btn-sm">
                                                <i class="bi bi-receipt"></i> Receipt
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">
                                        <i class="bi bi-calendar-x" style="font-size: 2rem; color: #dee2e6;"></i>
                                        <p class="mt-2 text-muted">No EMI records found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>