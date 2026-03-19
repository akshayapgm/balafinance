<?php
require_once 'includes/auth.php';
$currentPage = 'investor-return';
$pageTitle = 'Investor Return';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';

// Check if investor ID is provided
$investor_id = isset($_GET['investor_id']) ? intval($_GET['investor_id']) : (isset($_POST['investor_id']) ? intval($_POST['investor_id']) : 0);

// Get investor details
$investor = null;
if ($investor_id > 0) {
    $investor_query = "SELECT i.*, f.finance_name 
                      FROM investors i
                      JOIN finance f ON i.finance_id = f.id
                      WHERE i.id = ?";
    
    if ($user_role != 'admin') {
        $investor_query .= " AND i.finance_id = ?";
        $stmt = $conn->prepare($investor_query);
        $stmt->bind_param('ii', $investor_id, $finance_id);
    } else {
        $stmt = $conn->prepare($investor_query);
        $stmt->bind_param('i', $investor_id);
    }
    
    $stmt->execute();
    $investor = $stmt->get_result()->fetch_assoc();
    
    if (!$investor) {
        $error = 'Investor not found or access denied';
    } elseif ($investor['status'] == 'closed') {
        $error = 'This investment is already closed';
    }
}

// Get all active investors for dropdown
$investors_query = "SELECT i.id, i.investor_name, i.investment_amount, i.interest_rate, 
                   i.return_type, i.return_amount, i.total_return_paid,
                   f.finance_name
                   FROM investors i
                   JOIN finance f ON i.finance_id = f.id
                   WHERE i.status = 'active'";

if ($user_role != 'admin') {
    $investors_query .= " AND i.finance_id = $finance_id";
}
$investors_query .= " ORDER BY i.investor_name ASC";

$investors = $conn->query($investors_query);

// Get return history for this investor
$return_history = null;
if ($investor_id > 0) {
    $history_query = "SELECT ir.*, u.full_name as processed_by_name 
                     FROM investor_returns ir
                     LEFT JOIN users u ON ir.processed_by = u.id
                     WHERE ir.investor_id = ?
                     ORDER BY ir.return_date DESC, ir.created_at DESC";
    
    $stmt = $conn->prepare($history_query);
    $stmt->bind_param('i', $investor_id);
    $stmt->execute();
    $return_history = $stmt->get_result();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_return'])) {
    
    $investor_id = intval($_POST['investor_id']);
    $return_amount = isset($_POST['return_amount']) ? floatval($_POST['return_amount']) : 0;
    $return_date = $_POST['return_date'] ?? date('Y-m-d');
    $return_type = $_POST['return_type'] ?? 'interest';
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $transaction_id = isset($_POST['transaction_id']) ? trim($_POST['transaction_id']) : null;
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    // Validate
    if ($investor_id <= 0) {
        $error = 'Please select an investor';
    } elseif ($return_amount <= 0) {
        $error = 'Please enter a valid return amount';
    } elseif (empty($return_date)) {
        $error = 'Please select a return date';
    }
    
    if (empty($error)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get current investor data
            $check_query = "SELECT * FROM investors WHERE id = ? FOR UPDATE";
            $stmt = $conn->prepare($check_query);
            $stmt->bind_param('i', $investor_id);
            $stmt->execute();
            $current_investor = $stmt->get_result()->fetch_assoc();
            
            if (!$current_investor) {
                throw new Exception('Investor not found');
            }
            
            if ($current_investor['status'] == 'closed') {
                throw new Exception('This investment is already closed');
            }
            
            // Insert return record
            $insert_query = "INSERT INTO investor_returns (
                investor_id, return_date, return_amount, return_type, 
                payment_method, transaction_id, remarks, processed_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param(
                'isdssssi',
                $investor_id,
                $return_date,
                $return_amount,
                $return_type,
                $payment_method,
                $transaction_id,
                $remarks,
                $user_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to record return: ' . $stmt->error);
            }
            
            $return_id = $conn->insert_id;
            
            // Update investor's total return paid
            $new_total = $current_investor['total_return_paid'] + $return_amount;
            
            $update_query = "UPDATE investors SET total_return_paid = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param('di', $new_total, $investor_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update investor: ' . $stmt->error);
            }
            
            // Insert into investor_transactions for complete history
            $trans_query = "INSERT INTO investor_transactions (
                investor_id, transaction_type, amount, transaction_date, 
                reference_number, payment_method, remarks, created_by
            ) VALUES (?, 'return', ?, ?, ?, ?, ?, ?)";
            
            $trans_ref = 'RET-' . str_pad($return_id, 6, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare($trans_query);
            $stmt->bind_param(
                'idssssi',
                $investor_id,
                $return_amount,
                $return_date,
                $trans_ref,
                $payment_method,
                $remarks,
                $user_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to record transaction: ' . $stmt->error);
            }
            
            // Commit transaction
            $conn->commit();
            
            // Log activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'investor_return', "Recorded return of ₹$return_amount for investor ID: $investor_id");
            }
            
            $_SESSION['success'] = 'Return recorded successfully';
            header('Location: investor-return.php?investor_id=' . $investor_id);
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error processing return: ' . $e->getMessage();
        }
    }
}

// Calculate expected return amounts
function calculateExpectedReturn($investor) {
    if (!$investor) return 0;
    
    $investment_date = new DateTime($investor['investment_date']);
    $current_date = new DateTime();
    $months_diff = $investment_date->diff($current_date)->m + ($investment_date->diff($current_date)->y * 12);
    
    $expected = 0;
    if ($investor['return_type'] == 'monthly') {
        $expected = $investor['return_amount'] * $months_diff;
    } elseif ($investor['return_type'] == 'quarterly') {
        $quarters = floor($months_diff / 3);
        $expected = $investor['return_amount'] * $quarters;
    } elseif ($investor['return_type'] == 'yearly') {
        $years = floor($months_diff / 12);
        $expected = $investor['return_amount'] * $years;
    }
    
    return $expected;
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

        /* Form Card */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
            margin-bottom: 1.5rem;
        }

        /* Investor Info Card */
        .investor-info-card {
            background: var(--primary-bg);
            border: 1px solid var(--primary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .info-value small {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: normal;
        }

        .summary-box {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            height: 100%;
        }

        .summary-box .label {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .summary-box .value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .summary-box .value.success { color: var(--success); }
        .summary-box .value.warning { color: var(--warning); }
        .summary-box .value.danger { color: var(--danger); }

        /* Return History Table */
        .history-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .history-card .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }

        .history-card .card-header h5 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0;
            font-size: 1rem;
        }

        .history-table {
            width: 100%;
        }

        .history-table th {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            padding: 0.75rem 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
            background: var(--light);
        }

        .history-table td {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border-color);
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        .btn-success {
            background: var(--success);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 0.5rem;
        }

        .btn-success:hover {
            background: #0d9488;
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            .page-content {
                padding: 1rem;
            }
            .form-card {
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
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="bi bi-cash-stack me-2"></i>Investor Return</h4>
                        <p>Record returns/payments to investors</p>
                    </div>
                    <div>
                        <a href="investors.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Investors
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

            <!-- Investor Selection Form -->
            <div class="form-card">
                <form method="GET" action="" id="investorSelectForm">
                    <div class="row align-items-end g-3">
                        <div class="col-md-8">
                            <label class="form-label">Select Investor</label>
                            <select class="form-select" name="investor_id" onchange="this.form.submit()">
                                <option value="">Choose Investor...</option>
                                <?php 
                                if ($investors) {
                                    $investors->data_seek(0);
                                    while ($inv = $investors->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $inv['id']; ?>" <?php echo $investor_id == $inv['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($inv['investor_name']); ?> - 
                                        ₹<?php echo number_format($inv['investment_amount'], 2); ?> 
                                        (<?php echo ucfirst($inv['return_type']); ?>) - 
                                        <?php echo $inv['finance_name']; ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <a href="investor-return.php" class="btn btn-secondary w-100">Clear Selection</a>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($investor && $investor_id > 0): ?>
                <!-- Investor Information Card -->
                <div class="investor-info-card">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="info-label">Investor Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($investor['investor_name']); ?></div>
                            <div class="mt-2">
                                <span class="badge bg-info bg-opacity-10 text-info p-2">
                                    <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($investor['investor_number']); ?>
                                </span>
                                <?php if ($investor['email']): ?>
                                <span class="badge bg-info bg-opacity-10 text-info p-2 ms-2">
                                    <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($investor['email']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-label">Investment Details</div>
                            <div class="info-value">₹<?php echo number_format($investor['investment_amount'], 2); ?></div>
                            <div class="mt-2">
                                <span class="badge bg-primary bg-opacity-10 text-primary p-2">
                                    <i class="bi bi-percent"></i> <?php echo $investor['interest_rate']; ?>% p.a.
                                </span>
                                <span class="badge bg-<?php 
                                    echo $investor['return_type'] == 'monthly' ? 'primary' : 
                                        ($investor['return_type'] == 'quarterly' ? 'info' : 
                                        ($investor['return_type'] == 'yearly' ? 'success' : 'warning')); 
                                ?> bg-opacity-10 text-<?php 
                                    echo $investor['return_type'] == 'monthly' ? 'primary' : 
                                        ($investor['return_type'] == 'quarterly' ? 'info' : 
                                        ($investor['return_type'] == 'yearly' ? 'success' : 'warning')); 
                                ?> p-2 ms-2">
                                    <i class="bi bi-calendar-check"></i> <?php echo ucfirst($investor['return_type']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-label">Finance Company</div>
                            <div class="info-value"><?php echo htmlspecialchars($investor['finance_name']); ?></div>
                            <div class="mt-2">
                                <span class="badge bg-secondary bg-opacity-10 text-secondary p-2">
                                    <i class="bi bi-calendar"></i> Invested: <?php echo date('d M Y', strtotime($investor['investment_date'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="row g-3 mb-4">
                    <?php 
                    $expected_return = calculateExpectedReturn($investor);
                    $due_amount = $expected_return - $investor['total_return_paid'];
                    ?>
                    <div class="col-md-3">
                        <div class="summary-box">
                            <div class="label">Return Amount (per period)</div>
                            <div class="value">₹<?php echo number_format($investor['return_amount'], 2); ?></div>
                            <small class="text-muted"><?php echo ucfirst($investor['return_type']); ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box">
                            <div class="label">Total Returns Paid</div>
                            <div class="value success">₹<?php echo number_format($investor['total_return_paid'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box">
                            <div class="label">Expected Returns (to date)</div>
                            <div class="value">₹<?php echo number_format($expected_return, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-box">
                            <div class="label">Due Amount</div>
                            <div class="value <?php echo $due_amount > 0 ? 'danger' : 'success'; ?>">
                                ₹<?php echo number_format($due_amount, 2); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Return Form -->
                <div class="form-card">
                    <h5 class="mb-3"><i class="bi bi-cash me-2"></i>Record New Return</h5>
                    <form method="POST" action="" id="returnForm">
                        <input type="hidden" name="investor_id" value="<?php echo $investor_id; ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Return Amount (₹) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="return_amount" 
                                           value="<?php echo $investor['return_amount']; ?>" required>
                                </div>
                                <small class="text-muted">Suggested: ₹<?php echo number_format($investor['return_amount'], 2); ?> per <?php echo $investor['return_type']; ?></small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Return Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="return_type" required>
                                    <option value="interest">Interest Only</option>
                                    <option value="principal">Principal Only</option>
                                    <option value="both">Interest + Principal</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Return Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="return_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                <select class="form-select" name="payment_method" required>
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="online">Online Payment</option>
                                    <option value="upi">UPI</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Transaction ID (Optional)</label>
                                <input type="text" class="form-control" name="transaction_id" placeholder="Cheque/Reference number">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Remarks (Optional)</label>
                                <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes..."></textarea>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Note:</strong> This will record a return payment for this investor. 
                                    The total returns paid will be updated automatically.
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-secondary px-4">Reset</button>
                                <button type="submit" name="record_return" class="btn btn-success px-5">
                                    <i class="bi bi-check-circle me-2"></i>Record Return
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Return History -->
                <div class="history-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="bi bi-clock-history me-2"></i>Return History</h5>
                        <span class="badge bg-primary"><?php echo $return_history ? $return_history->num_rows : 0; ?> records</span>
                    </div>
                    <div class="table-responsive">
                        <table class="history-table">
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
                                <?php if ($return_history && $return_history->num_rows > 0): ?>
                                    <?php while ($return = $return_history->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('d M Y', strtotime($return['return_date'])); ?></td>
                                        <td class="fw-semibold text-success">₹<?php echo number_format($return['return_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $return['return_type'] == 'interest' ? 'info' : 
                                                    ($return['return_type'] == 'principal' ? 'warning' : 'success'); 
                                            ?> bg-opacity-10 text-<?php 
                                                echo $return['return_type'] == 'interest' ? 'info' : 
                                                    ($return['return_type'] == 'principal' ? 'warning' : 'success'); 
                                            ?> p-2">
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
            <?php elseif ($investor_id > 0 && !$investor): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Investor not found or access denied.
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Form validation
    document.getElementById('returnForm')?.addEventListener('submit', function(e) {
        var amount = parseFloat(document.querySelector('input[name="return_amount"]').value) || 0;
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid return amount');
            return false;
        }
        
        return confirm('Record this return payment?');
    });
</script>
</body>
</html>