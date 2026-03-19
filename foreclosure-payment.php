<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$finance_id = $_SESSION['finance_id'] ?? 0;

$customer_id = intval($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);
$selected_emi_ids = $_POST['selected_emi_ids'] ?? '';

if (!$customer_id || empty($selected_emi_ids)) {
    $_SESSION['error'] = "Invalid foreclosure request";
    header("Location: emi-schedule.php?customer_id=$customer_id");
    exit;
}

$emi_ids = array_map('intval', explode(',', $selected_emi_ids));

// ----------------------
// FETCH EMI DATA
// ----------------------
$placeholders = implode(',', array_fill(0, count($emi_ids), '?'));

$sql = "SELECT * FROM emi_schedule 
        WHERE id IN ($placeholders) AND customer_id = ?";
$stmt = $conn->prepare($sql);

$params = array_merge($emi_ids, [$customer_id]);
$stmt->bind_param(str_repeat('i', count($params)), ...$params);
$stmt->execute();
$res = $stmt->get_result();

$total_principal = 0;
$total_interest = 0;
$total_overdue = 0;

$emis = [];

while ($row = $res->fetch_assoc()) {

    $remaining_principal = $row['principal_amount'] - ($row['principal_paid'] ?? 0);
    $remaining_interest = $row['interest_amount'] - ($row['interest_paid'] ?? 0);
    $remaining_overdue = $row['overdue_charges'] - ($row['overdue_paid'] ?? 0);

    $remaining_principal = max(0, $remaining_principal);
    $remaining_interest = max(0, $remaining_interest);
    $remaining_overdue = max(0, $remaining_overdue);

    $total_principal += $remaining_principal;
    $total_interest += $remaining_interest;
    $total_overdue += $remaining_overdue;

    $emis[] = $row;
}

$stmt->close();

$total_without_charge = $total_principal + $total_interest + $total_overdue;

// ----------------------
// FORM SUBMIT
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $principal_paid = floatval($_POST['principal_paid']);
    $interest_paid = floatval($_POST['interest_paid']);
    $overdue_paid = floatval($_POST['overdue_charges']);
    $foreclosure_charge = floatval($_POST['foreclosure_charge']);
    $paid_amount = floatval($_POST['paid_amount']);

    $bill_number = trim($_POST['bill_number']);
    $paid_date = $_POST['paid_date'];
    $payment_method = $_POST['payment_method'];
    $remarks = trim($_POST['remarks']);

    if ($paid_amount <= 0) {
        $_SESSION['error'] = "Invalid amount";
        header("Location: emi-schedule.php?customer_id=$customer_id");
        exit;
    }

    $conn->begin_transaction();

    try {

        // INSERT FORECLOSURE
        $stmt = $conn->prepare("INSERT INTO foreclosures 
        (customer_id, emi_ids, principal_remaining, interest_remaining, overdue_charges, foreclosure_charge, total_amount, paid_amount, bill_number, payment_method, payment_date, remarks, foreclosed_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $emi_string = implode(',', $emi_ids);

        $stmt->bind_param("isddddddssssi",
            $customer_id,
            $emi_string,
            $principal_paid,
            $interest_paid,
            $overdue_paid,
            $foreclosure_charge,
            $paid_amount,
            $paid_amount,
            $bill_number,
            $payment_method,
            $paid_date,
            $remarks,
            $_SESSION['user_id']
        );

        $stmt->execute();
        $foreclosure_id = $stmt->insert_id;
        $stmt->close();

        // SPLIT AMOUNT PER EMI
        $count = count($emi_ids);

        $per_principal = $principal_paid / $count;
        $per_interest = $interest_paid / $count;
        $per_overdue = $overdue_paid / $count;

        foreach ($emi_ids as $emi_id) {

            $stmt = $conn->prepare("UPDATE emi_schedule SET 
                status='foreclosed',
                foreclosure_id=?,
                principal_paid = principal_paid + ?,
                interest_paid = interest_paid + ?,
                overdue_paid = overdue_paid + ?,
                paid_date=?,
                payment_method=?,
                emi_bill_number=?,
                remarks = CONCAT(IFNULL(remarks,''),' | Foreclosed')
            WHERE id=?");

            $stmt->bind_param("idddsssi",
                $foreclosure_id,
                $per_principal,
                $per_interest,
                $per_overdue,
                $paid_date,
                $payment_method,
                $bill_number,
                $emi_id
            );

            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        $_SESSION['success'] = "Foreclosure completed successfully";
        header("Location: emi-schedule.php?customer_id=$customer_id");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header("Location: emi-schedule.php?customer_id=$customer_id");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foreclosure Payment - <?php echo htmlspecialchars($customer['customer_name']); ?></title>
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
            --info: #17a2b8;
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

        .payment-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
        }

        .summary-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-item {
            text-align: center;
            padding: 1rem;
        }

        .summary-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .info-card {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .info-value.principal {
            color: var(--primary);
        }

        .info-value.interest {
            color: var(--warning);
        }

        .info-value.overdue {
            color: var(--danger);
        }

        .info-value.foreclosure {
            color: var(--info);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }

        .input-group-text {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
        }

        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .btn-success {
            background: var(--success);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
        }

        .btn-success:hover {
            background: #27ae60;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .emi-list-badge {
            background: var(--info);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .tamil-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            font-style: italic;
        }

        .emi-chip {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #e9ecef;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .payment-card {
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
                        <h4><i class="bi bi-file-earmark-x me-2"></i>Foreclosure Payment</h4>
                        <p><?php echo htmlspecialchars($customer['customer_name']); ?> - <?php echo htmlspecialchars($customer['loan_name']); ?></p>
                    </div>
                    <div>
                        <a href="emi-list.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to EMI List
                        </a>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Selected EMIs Info -->
            <div class="info-card">
                <h5 class="mb-3"><i class="bi bi-check-circle-fill text-info me-2"></i>Selected EMIs for Foreclosure</h5>
                <div class="mb-3">
                    <?php 
                    $counter = 1;
                    foreach ($emi_list as $emi): 
                    ?>
                    <span class="emi-chip">
                        <strong>EMI <?php echo $counter; ?></strong> (Due: <?php echo $emi['formatted_due_date']; ?>)
                    </span>
                    <?php 
                        $counter++;
                    endforeach; 
                    ?>
                </div>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Total foreclosure amount will be distributed evenly:</strong> 
                    ₹<?php echo number_format($per_emi_amount, 2); ?> per EMI
                </div>
            </div>

            <!-- Collection Summary -->
            <div class="summary-section">
                <div class="row g-4">
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Principal to Collect</div>
                            <div class="summary-value">₹<?php echo number_format($total_principal, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Interest to Collect</div>
                            <div class="summary-value">₹<?php echo number_format($total_interest, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Overdue Charges</div>
                            <div class="summary-value">₹<?php echo number_format($total_overdue, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Total Due (Without Charges)</div>
                            <div class="summary-value">₹<?php echo number_format($total_without_charge, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Form -->
            <div class="payment-card">
                <form method="POST" action="" id="paymentForm">
                    <!-- Payment Details -->
                    <div class="info-card">
                        <h5 class="mb-3">Payment Details</h5>
                        
                        <div class="row g-4">
                            <!-- Principal Amount -->
                            <div class="col-md-6">
                                <label class="form-label">Principal Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" 
                                           name="principal_paid" id="principal_paid" 
                                           value="<?php echo $total_principal; ?>" 
                                           readonly>
                                </div>
                                <div class="tamil-text">มูลค่าตัดตั้งค่า</div>
                            </div>

                            <!-- Overdue Charges -->
                            <div class="col-md-6">
                                <label class="form-label">Overdue Charges</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" 
                                           name="overdue_charges" id="overdue_charges" 
                                           value="<?php echo $total_overdue; ?>" 
                                           readonly>
                                </div>
                                <div class="tamil-text">ค่าคะแนนการชำระเงิน</div>
                                <small class="text-muted">You can edit this amount</small>
                            </div>

                            <!-- Bill Number -->
                            <div class="col-md-6">
                                <label class="form-label">Bill Number *</label>
                                <input type="text" class="form-control" name="bill_number" 
                                       placeholder="Enter bill number" required>
                                <div class="tamil-text">บัญชีรายเดือน</div>
                            </div>

                            <!-- Interest Amount -->
                            <div class="col-md-6">
                                <label class="form-label">Interest Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" 
                                           name="interest_paid" id="interest_paid" 
                                           value="<?php echo $total_interest; ?>" 
                                           readonly>
                                </div>
                                <div class="tamil-text">มูลค่าตัดตั้งค่า - You can reduce for settlement</div>
                                <small class="text-muted">Original Remaining Interest: ₹<?php echo number_format($total_interest, 2); ?></small>
                            </div>

                            <!-- Foreclosure Charge -->
                            <div class="col-md-6">
                                <label class="form-label">Foreclosure Charge (2%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" 
                                           name="foreclosure_charge" id="foreclosure_charge" 
                                           value="<?php echo $foreclosure_charge; ?>" 
                                           readonly>
                                </div>
                                <div class="tamil-text">ค่าจ้างคืน ค่ามอบหนี้สิน</div>
                                <small class="text-muted">Will be split evenly among selected EMIs</small>
                            </div>

                            <!-- Payment Method -->
                            <div class="col-md-6">
                                <label class="form-label">Payment Method</label>
                                <select class="form-select" name="payment_method">
                                    <option value="cash">Cash</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="online">Online Payment</option>
                                </select>
                            </div>

                            <!-- Paid Date -->
                            <div class="col-md-6">
                                <label class="form-label">Paid Date *</label>
                                <input type="date" class="form-control" name="paid_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                                <div class="tamil-text">วันที่ชำระ</div>
                            </div>

                            <!-- Remarks -->
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks" rows="2" 
                                          placeholder="Enter any remarks"></textarea>
                            </div>

                            <!-- Total Amount -->
                            <div class="col-12">
                                <div class="info-card bg-light">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <h5 class="mb-0">Total Foreclosure Amount</h5>
                                            <div class="tamil-text">ค่าตัดตั้งค่าก่อน foreclosure (Will be split evenly)</div>
                                            <small class="text-muted">
                                                ₹<?php echo number_format($per_emi_amount, 2); ?> per EMI 
                                                (<?php echo $selected_emis_count; ?> EMIs)
                                            </small>
                                        </div>
                                        <div class="col-md-4">
                                            <h3 class="text-primary mb-0 text-end" id="total_amount_display">
                                                ₹<?php echo number_format($grand_total, 2); ?>
                                            </h3>
                                            <input type="hidden" name="paid_amount" id="paid_amount" 
                                                   value="<?php echo $grand_total; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="emi-list.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-secondary px-4">Cancel</a>
                        <button type="submit" class="btn btn-success px-5">
                            <i class="bi bi-check-circle me-2"></i>Process Foreclosure Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script>
    // Calculate total amount when any editable field changes
    function calculateTotal() {
        var principal = parseFloat(document.getElementById('principal_paid').value) || 0;
        var interest = parseFloat(document.getElementById('interest_paid').value) || 0;
        var overdue = parseFloat(document.getElementById('overdue_charges').value) || 0;
        var foreclosure = parseFloat(document.getElementById('foreclosure_charge').value) || 0;
        
        var total = principal + interest + overdue + foreclosure;
        
        document.getElementById('total_amount_display').innerHTML = '₹' + total.toFixed(2);
        document.getElementById('paid_amount').value = total.toFixed(2);
    }
    
    // Make overdue charges editable
    document.getElementById('overdue_charges').addEventListener('input', calculateTotal);
    document.getElementById('overdue_charges').removeAttribute('readonly');
    
    // Initialize tooltips
    document.addEventListener('DOMContentLoaded', function() {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
</script>

</body>
</html>