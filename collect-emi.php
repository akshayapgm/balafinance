<?php
require_once 'includes/auth.php';
$currentPage = 'collections';
$pageTitle = 'Collect EMI Payment';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';

// Check if EMI ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: emi-list.php');
    exit();
}

$emi_id = intval($_GET['id']);

// Get EMI details with customer information
$query = "SELECT es.*, 
          c.customer_name, c.agreement_number, c.customer_number, 
          c.collection_type, c.finance_id,
          f.finance_name,
          DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue,
          CASE 
            WHEN es.emi_due_date = '0000-00-00' OR es.emi_due_date IS NULL THEN 'Invalid Date'
            ELSE DATE_FORMAT(es.emi_due_date, '%d-%m-%Y')
          END as formatted_due_date
          FROM emi_schedule es
          JOIN customers c ON es.customer_id = c.id
          JOIN finance f ON c.finance_id = f.id
          WHERE es.id = ?";

// Add role-based filter
if ($user_role != 'admin') {
    $query .= " AND es.finance_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $emi_id, $finance_id);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $emi_id);
}

$stmt->execute();
$emi = $stmt->get_result()->fetch_assoc();

if (!$emi) {
    header('Location: emi-list.php');
    exit();
}

// Check if EMI is already paid
if ($emi['status'] == 'paid') {
    $error = 'This EMI has already been paid.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['collect_payment'])) {
    
    $paid_amount = isset($_POST['paid_amount']) ? floatval($_POST['paid_amount']) : 0;
    $overdue_charges = isset($_POST['overdue_charges']) ? floatval($_POST['overdue_charges']) : 0;
    $bill_number = isset($_POST['bill_number']) ? trim($_POST['bill_number']) : '';
    $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
    $paid_date = isset($_POST['paid_date']) ? $_POST['paid_date'] : date('Y-m-d');
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
    
    // Calculate split amounts
    $principal_paid = isset($_POST['principal_paid']) ? floatval($_POST['principal_paid']) : 0;
    $interest_paid = isset($_POST['interest_paid']) ? floatval($_POST['interest_paid']) : 0;
    
    // If split amounts not provided, calculate based on EMI amounts
    if ($principal_paid == 0 && $interest_paid == 0) {
        $principal_paid = $emi['principal_amount'];
        $interest_paid = $emi['interest_amount'];
    }
    
    // Validate
    if ($paid_amount <= 0) {
        $error = 'Please enter a valid payment amount';
    } elseif ($emi['status'] == 'paid') {
        $error = 'This EMI has already been paid';
    } elseif (empty($bill_number)) {
        $error = 'Bill number is required';
    }
    
    if (empty($error)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update EMI schedule - FIXED: 8 parameters with 8 type characters
            $update_query = "UPDATE emi_schedule SET 
                status = 'paid',
                paid_date = ?,
                overdue_charges = ?,
                emi_bill_number = ?,
                payment_method = ?,
                remarks = CONCAT(IFNULL(remarks, ''), ' | ', ?),
                principal_paid = ?,
                interest_paid = ?,
                updated_at = NOW()
                WHERE id = ? AND status != 'paid'";
            
            $stmt = $conn->prepare($update_query);
            $remarks_text = "Paid on " . date('d-m-Y') . " by " . ($_SESSION['full_name'] ?? 'User');
            
            // CORRECT: 8 parameters with 8 type characters (sdsssddi)
            $stmt->bind_param(
                'sdsssddi',  // 8 characters: s,d,s,s,s,d,d,i
                $paid_date,
                $overdue_charges,
                $bill_number,
                $payment_method,
                $remarks_text,
                $principal_paid,
                $interest_paid,
                $emi_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update EMI: ' . $stmt->error);
            }
            
            if ($stmt->affected_rows == 0) {
                throw new Exception('EMI could not be updated. It may have been already paid.');
            }
            
            // Insert into payment_logs - FIXED: 11 parameters with 11 type characters
            $log_query = "INSERT INTO payment_logs (
                customer_id, emi_id, paid_amount, principal_paid, interest_paid,
                overdue_charges, bill_number, payment_method, paid_date, remarks, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($log_query);
            
            // CORRECT: 11 parameters with 11 type characters (iiddddssssi)
            $stmt->bind_param(
                'iiddddssssi',  // 11 characters: i,i,d,d,d,d,s,s,s,s,i
                $emi['customer_id'],
                $emi_id,
                $paid_amount,
                $principal_paid,
                $interest_paid,
                $overdue_charges,
                $bill_number,
                $payment_method,
                $paid_date,
                $remarks,
                $user_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to log payment: ' . $stmt->error);
            }
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success'] = 'Payment collected successfully';
            header('Location: emi-list.php');
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Error processing payment: ' . $e->getMessage();
        }
    }
}

// Get next bill number suggestion
$bill_query = "SELECT MAX(emi_bill_number) as last_bill FROM emi_schedule WHERE emi_bill_number IS NOT NULL";
$bill_result = $conn->query($bill_query);
$last_bill = $bill_result->fetch_assoc()['last_bill'];
$next_bill = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
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
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
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
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.5rem 1.5rem;
            font-weight: 500;
            border-radius: 2rem;
            backdrop-filter: blur(10px);
            transition: all 0.2s;
        }

        .page-header .btn-light:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .info-card {
            background: var(--primary-bg);
            border: 1px solid var(--primary);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-item {
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .info-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .amount-box {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            height: 100%;
            transition: all 0.2s;
        }

        .amount-box.primary {
            border-color: var(--primary);
            background: var(--primary-bg);
        }

        .amount-box.success {
            border-color: var(--success);
            background: var(--success-bg);
        }

        .amount-box.warning {
            border-color: var(--warning);
            background: var(--warning-bg);
        }

        .amount-box.danger {
            border-color: var(--danger);
            background: var(--danger-bg);
        }

        .amount-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .amount-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .amount-value small {
            font-size: 1rem;
            font-weight: normal;
            color: var(--text-muted);
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
                        <h4><i class="bi bi-cash me-2"></i>Collect EMI Payment</h4>
                        <p>Record payment for EMI #<?php echo $emi_id; ?></p>
                    </div>
                    <div>
                        <a href="emi-list.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to EMI List
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <!-- Customer Info Card -->
                <div class="info-card mb-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="info-label">Customer Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($emi['customer_name']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Agreement Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($emi['agreement_number']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Phone Number</div>
                            <div class="info-value"><?php echo htmlspecialchars($emi['customer_number']); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Collection Type</div>
                            <div class="info-value">
                                <span class="badge bg-info"><?php echo ucfirst($emi['collection_type']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Amount Summary Boxes -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="amount-box primary">
                            <div class="amount-label">Principal Amount</div>
                            <div class="amount-value">₹<?php echo number_format($emi['principal_amount'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="amount-box warning">
                            <div class="amount-label">Interest Amount</div>
                            <div class="amount-value">₹<?php echo number_format($emi['interest_amount'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="amount-box success">
                            <div class="amount-label">Total EMI</div>
                            <div class="amount-value">₹<?php echo number_format($emi['emi_amount'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="amount-box <?php echo ($emi['status'] == 'overdue' || $emi['days_overdue'] > 0) ? 'danger' : ''; ?>">
                            <div class="amount-label">Due Date</div>
                            <div class="amount-value"><small><?php echo $emi['formatted_due_date']; ?></small></div>
                            <?php if ($emi['status'] == 'overdue' && $emi['days_overdue'] > 0): ?>
                                <div class="small text-danger mt-1">
                                    <i class="bi bi-exclamation-triangle"></i> <?php echo $emi['days_overdue']; ?> days overdue
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Payment Form -->
                <form method="POST" action="" id="paymentForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Principal Paid <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="principal_paid" id="principal_paid" 
                                       value="<?php echo $emi['principal_amount']; ?>" required readonly>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Interest Paid <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="interest_paid" id="interest_paid" 
                                       value="<?php echo $emi['interest_amount']; ?>" required readonly>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Overdue Charges</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="overdue_charges" id="overdue_charges" 
                                       value="<?php echo $emi['overdue_charges'] > 0 ? $emi['overdue_charges'] : 0; ?>">
                            </div>
                            <small class="text-muted">Enter late payment fee if applicable</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Total Paid Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="paid_amount" id="paid_amount" 
                                       value="<?php echo $emi['emi_amount']; ?>" required readonly>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bill Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="bill_number" value="<?php echo $next_bill; ?>" required>
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
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="paid_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Remarks (Optional)</label>
                            <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes..."></textarea>
                        </div>

                        <div class="col-12 mt-4">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Note:</strong> This payment will be recorded for EMI #<?php echo $emi_id; ?>. 
                                Please verify the details before confirming.
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2">
                            <a href="emi-list.php" class="btn btn-secondary px-4">Cancel</a>
                            <button type="submit" name="collect_payment" class="btn btn-success px-5">
                                <i class="bi bi-check-circle me-2"></i>Confirm Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var principal = parseFloat(document.getElementById('principal_paid').value) || 0;
        var interest = parseFloat(document.getElementById('interest_paid').value) || 0;
        var overdue = parseFloat(document.getElementById('overdue_charges')?.value || 0);
        
        function updateTotal() {
            var total = principal + interest + overdue;
            document.getElementById('paid_amount').value = total.toFixed(2);
        }
        
        if (document.getElementById('overdue_charges')) {
            document.getElementById('overdue_charges').addEventListener('input', function() {
                overdue = parseFloat(this.value) || 0;
                updateTotal();
            });
        }
        
        // Form validation
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            var billNumber = document.querySelector('input[name="bill_number"]').value.trim();
            var paymentMethod = document.querySelector('select[name="payment_method"]').value;
            
            if (!billNumber) {
                e.preventDefault();
                alert('Please enter a bill number');
                return false;
            }
            
            if (!paymentMethod) {
                e.preventDefault();
                alert('Please select a payment method');
                return false;
            }
            
            return confirm('Are you sure you want to record this payment?');
        });
    });
</script>
</body>
</html>