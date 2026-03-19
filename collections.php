<?php
require_once 'includes/auth.php';
$currentPage = 'collections';
$pageTitle = 'Collections';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get customer list for dropdown
$customer_query = "SELECT id, customer_name, customer_number, agreement_number FROM customers";
if ($user_role != 'admin') {
    $customer_query .= " WHERE finance_id = $finance_id";
}
$customer_query .= " ORDER BY customer_name ASC";
$customers = $conn->query($customer_query);

// Get today's date for default
$today = date('Y-m-d');

// Handle AJAX request for EMIs
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_emis' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json');
    
    $ajax_customer_id = intval($_GET['customer_id']);
    $user_role = $_SESSION['role'] ?? 'user';
    $session_finance_id = intval($_SESSION['finance_id'] ?? 0);
    
    if ($ajax_customer_id <= 0) {
        echo json_encode([]);
        exit;
    }
    
    // Check permission
    if ($user_role != 'admin') {
        $check_sql = "SELECT finance_id FROM customers WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $ajax_customer_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_row = $check_result->fetch_assoc()) {
            if ($check_row['finance_id'] != $session_finance_id) {
                echo json_encode([]);
                exit;
            }
        }
        $check_stmt->close();
    }
    
    // Get all pending/unpaid EMIs for this customer
    $sql = "SELECT 
                es.id,
                es.emi_amount,
                es.principal_amount,
                es.interest_amount,
                es.emi_due_date,
                es.status,
                es.overdue_charges,
                es.overdue_paid,
                es.principal_paid,
                es.interest_paid,
                es.week_number,
                es.day_number,
                es.collection_type,
                (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = es.customer_id AND id <= es.id) as emi_number
            FROM emi_schedule es
            WHERE es.customer_id = ? 
              AND es.status IN ('unpaid', 'overdue', 'partial')
              AND (es.principal_amount - COALESCE(es.principal_paid, 0) > 0 
                   OR es.interest_amount - COALESCE(es.interest_paid, 0) > 0
                   OR es.overdue_charges - COALESCE(es.overdue_paid, 0) > 0)
            ORDER BY es.emi_due_date ASC, es.id ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $ajax_customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $emis = [];
    while ($row = $result->fetch_assoc()) {
        $remaining_principal = max(0, $row['principal_amount'] - ($row['principal_paid'] ?? 0));
        $remaining_interest = max(0, $row['interest_amount'] - ($row['interest_paid'] ?? 0));
        $remaining_overdue = max(0, ($row['overdue_charges'] ?? 0) - ($row['overdue_paid'] ?? 0));
        $remaining_total = $remaining_principal + $remaining_interest + $remaining_overdue;
        
        $emis[] = [
            'id' => $row['id'],
            'emi_number' => $row['emi_number'],
            'emi_amount' => $row['emi_amount'],
            'principal_amount' => $row['principal_amount'],
            'interest_amount' => $row['interest_amount'],
            'emi_due_date' => date('d-m-Y', strtotime($row['emi_due_date'])),
            'status' => $row['status'],
            'overdue_charges' => $row['overdue_charges'] ?? 0,
            'overdue_paid' => $row['overdue_paid'] ?? 0,
            'principal_paid' => $row['principal_paid'] ?? 0,
            'interest_paid' => $row['interest_paid'] ?? 0,
            'remaining_principal' => $remaining_principal,
            'remaining_interest' => $remaining_interest,
            'remaining_overdue' => $remaining_overdue,
            'remaining_total' => $remaining_total,
            'week_number' => $row['week_number'],
            'day_number' => $row['day_number'],
            'collection_type' => $row['collection_type']
        ];
    }
    
    $stmt->close();
    echo json_encode($emis);
    exit;
}

// Process form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $emi_id = intval($_POST['emi_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $principal = floatval($_POST['principal'] ?? 0);
    $interest = floatval($_POST['interest'] ?? 0);
    $overdue = floatval($_POST['overdue'] ?? 0);
    $bill_number = trim($_POST['bill_number'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $paid_date = $_POST['paid_date'] ?? $today;
    $remarks = trim($_POST['remarks'] ?? '');
    $payment_type = $_POST['payment_type'] ?? 'full';

    if ($customer_id <= 0) {
        $error = "Please select a customer.";
    } elseif ($emi_id <= 0) {
        $error = "Please select an EMI to pay.";
    } elseif ($amount <= 0) {
        $error = "Please enter a valid amount.";
    } elseif (empty($bill_number)) {
        $error = "Bill number is required.";
    } elseif (empty($paid_date)) {
        $error = "Paid date is required.";
    } elseif (strtotime($paid_date) > strtotime($today)) {
        $error = "Paid date cannot be in the future.";
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Get EMI details
            $emi_sql = "SELECT es.*, c.customer_name, c.finance_id 
                        FROM emi_schedule es 
                        JOIN customers c ON es.customer_id = c.id 
                        WHERE es.id = ? AND es.customer_id = ?";
            $stmt = $conn->prepare($emi_sql);
            $stmt->bind_param("ii", $emi_id, $customer_id);
            $stmt->execute();
            $emi_result = $stmt->get_result();
            
            if ($emi_result->num_rows === 0) {
                throw new Exception("EMI not found.");
            }
            
            $emi = $emi_result->fetch_assoc();
            $stmt->close();

            // Calculate remaining amounts
            $remaining_principal = max(0, $emi['principal_amount'] - ($emi['principal_paid'] ?? 0));
            $remaining_interest = max(0, $emi['interest_amount'] - ($emi['interest_paid'] ?? 0));
            $remaining_overdue = max(0, ($emi['overdue_charges'] ?? 0) - ($emi['overdue_paid'] ?? 0));
            $remaining_total = $remaining_principal + $remaining_interest + $remaining_overdue;

            // Validate amount based on payment type
            if ($payment_type === 'full' && $amount != $remaining_total) {
                $amount = $remaining_total;
            } elseif ($payment_type === 'principal' && $amount > $remaining_principal) {
                $amount = $remaining_principal;
            } elseif ($payment_type === 'interest' && $amount > $remaining_interest) {
                $amount = $remaining_interest;
            }

            // Calculate allocation
            $principal_paid_now = 0;
            $interest_paid_now = 0;
            $overdue_paid_now = 0;
            $excess_amount = 0;
            $remaining_amount = $amount;

            // First pay overdue if any
            if ($remaining_amount > 0 && $remaining_overdue > 0) {
                $overdue_paid_now = min($remaining_amount, $remaining_overdue);
                $remaining_amount -= $overdue_paid_now;
            }

            // Then pay principal/interest based on payment type
            if ($remaining_amount > 0) {
                if ($payment_type === 'principal') {
                    $principal_paid_now = min($remaining_amount, $remaining_principal);
                    $remaining_amount -= $principal_paid_now;
                } elseif ($payment_type === 'interest') {
                    $interest_paid_now = min($remaining_amount, $remaining_interest);
                    $remaining_amount -= $interest_paid_now;
                } else {
                    // Split proportionally between principal and interest
                    $total_remaining = $remaining_principal + $remaining_interest;
                    if ($total_remaining > 0) {
                        $principal_ratio = $remaining_principal / $total_remaining;
                        $interest_ratio = $remaining_interest / $total_remaining;
                        
                        $principal_paid_now = round($remaining_amount * $principal_ratio, 2);
                        $interest_paid_now = round($remaining_amount * $interest_ratio, 2);
                        
                        // Adjust for rounding
                        $adjustment = $remaining_amount - ($principal_paid_now + $interest_paid_now);
                        if ($adjustment != 0) {
                            $interest_paid_now += $adjustment;
                        }
                        
                        $principal_paid_now = min($principal_paid_now, $remaining_principal);
                        $interest_paid_now = min($interest_paid_now, $remaining_interest);
                        $remaining_amount -= ($principal_paid_now + $interest_paid_now);
                    }
                }
            }

            // Any remaining amount becomes excess
            $excess_amount = $remaining_amount;

            // Calculate new totals
            $new_principal_paid = ($emi['principal_paid'] ?? 0) + $principal_paid_now;
            $new_interest_paid = ($emi['interest_paid'] ?? 0) + $interest_paid_now;
            $new_overdue_paid = ($emi['overdue_paid'] ?? 0) + $overdue_paid_now;
            
            // Determine new status
            $principal_fully_paid = $new_principal_paid >= $emi['principal_amount'];
            $interest_fully_paid = $new_interest_paid >= $emi['interest_amount'];
            $overdue_fully_paid = $new_overdue_paid >= ($emi['overdue_charges'] ?? 0);
            
            if ($principal_fully_paid && $interest_fully_paid && $overdue_fully_paid) {
                $new_status = 'paid';
            } elseif ($principal_paid_now > 0 || $interest_paid_now > 0 || $overdue_paid_now > 0) {
                $new_status = 'partial';
            } else {
                $new_status = $emi['status'];
            }

            // Update EMI
            $update_sql = "UPDATE emi_schedule 
                          SET principal_paid = ?,
                              interest_paid = ?,
                              overdue_paid = ?,
                              emi_bill_number = ?,
                              payment_method = ?,
                              paid_date = ?,
                              status = ?,
                              remarks = CONCAT(IFNULL(remarks, ''), ?),
                              updated_at = NOW()
                          WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            $remarks_text = " | Paid on " . date('d-m-Y', strtotime($paid_date)) . " - Type: $payment_type" . ($remarks ? " - $remarks" : "");
            $stmt->bind_param("dddsssssi", 
                $new_principal_paid, 
                $new_interest_paid, 
                $new_overdue_paid,
                $bill_number,
                $payment_method,
                $paid_date,
                $new_status,
                $remarks_text,
                $emi_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update EMI: " . $stmt->error);
            }
            $stmt->close();

            // Log payment
            $log_sql = "INSERT INTO payment_logs 
                       (customer_id, emi_id, paid_amount, principal_paid, interest_paid, overdue_charges, bill_number, payment_method, paid_date, remarks, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($log_sql);
            $total_paid = $principal_paid_now + $interest_paid_now + $overdue_paid_now;
            $stmt->bind_param("iiddddssssi", 
                $customer_id,
                $emi_id,
                $total_paid,
                $principal_paid_now,
                $interest_paid_now,
                $overdue_paid_now,
                $bill_number,
                $payment_method,
                $paid_date,
                $remarks,
                $_SESSION['user_id']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to log payment: " . $stmt->error);
            }
            $stmt->close();

            // Handle excess payment if any
            if ($excess_amount > 0) {
                // Get the next unpaid EMI for future reduction
                $future_sql = "SELECT id FROM emi_schedule 
                              WHERE customer_id = ? AND id > ? AND status IN ('unpaid', 'overdue', 'partial')
                              ORDER BY emi_due_date ASC, id ASC LIMIT 1";
                $stmt = $conn->prepare($future_sql);
                $stmt->bind_param("ii", $customer_id, $emi_id);
                $stmt->execute();
                $future_result = $stmt->get_result();
                
                if ($future_row = $future_result->fetch_assoc()) {
                    // Apply excess to next EMI
                    $future_emi_id = $future_row['id'];
                    
                    // Insert excess payment record
                    $excess_sql = "INSERT INTO excess_payments 
                                  (customer_id, emi_id, excess_amount, payment_date, bill_number, remaining_amount, note, status)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'active')";
                    $stmt = $conn->prepare($excess_sql);
                    $excess_note = "Excess payment from Bill #$bill_number for EMI #$emi_id";
                    $stmt->bind_param("iidssds", 
                        $customer_id,
                        $future_emi_id,
                        $excess_amount,
                        $paid_date,
                        $bill_number,
                        $excess_amount,
                        $excess_note
                    );
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to record excess payment: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }

            // Update total balance
            $balance_sql = "SELECT balance FROM total_balance WHERE finance_id = ? ORDER BY id DESC LIMIT 1";
            $stmt = $conn->prepare($balance_sql);
            $stmt->bind_param("i", $emi['finance_id']);
            $stmt->execute();
            $balance_result = $stmt->get_result();
            $last_balance = $balance_result->num_rows > 0 ? floatval($balance_result->fetch_assoc()['balance']) : 0;
            $stmt->close();
            
            $new_balance = $last_balance + $total_paid;
            
            $balance_insert_sql = "INSERT INTO total_balance 
                                  (transaction_type, amount, balance, description, transaction_date, finance_id)
                                  VALUES ('emi_paid', ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($balance_insert_sql);
            $description = "EMI Payment - Bill: $bill_number (Customer: {$emi['customer_name']})";
            $stmt->bind_param("ddssi", $total_paid, $new_balance, $description, $paid_date, $emi['finance_id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update balance: " . $stmt->error);
            }
            $stmt->close();

            $conn->commit();
            
            $_SESSION['success'] = "Payment recorded successfully! Bill #$bill_number - Amount: ₹" . number_format($total_paid, 2);
            header("Location: emi-schedule.php?customer_id=$customer_id");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error processing payment: " . $e->getMessage();
        }
    }
}

// Get EMI details via AJAX
$selected_customer = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$selected_emi = isset($_GET['emi_id']) ? intval($_GET['emi_id']) : 0;
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
        
        /* Payment Card */
        .payment-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .payment-card h5 {
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
        
        /* Payment Type Cards */
        .payment-type-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .payment-type-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem 0.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .payment-type-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }
        
        .payment-type-card.selected {
            border-color: var(--success);
            background: #f0fdf4;
        }
        
        .payment-type-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .payment-type-icon.primary { color: var(--primary); }
        .payment-type-icon.success { color: var(--success); }
        .payment-type-icon.warning { color: var(--warning); }
        .payment-type-icon.info { color: var(--info); }
        
        .payment-type-title {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .payment-type-desc {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        
        /* EMI Info Card */
        .emi-info-card {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .emi-info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--border-color);
        }
        
        .emi-info-row:last-child {
            border-bottom: none;
        }
        
        .emi-info-label {
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        
        .emi-info-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.9rem;
        }
        
        .emi-info-value.principal { color: var(--primary); }
        .emi-info-value.interest { color: var(--warning); }
        .emi-info-value.overdue { color: var(--danger); }
        .emi-info-value.total { color: var(--success); }
        
        /* Summary Card */
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .summary-row:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        .summary-value {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        /* Action Buttons */
        .btn-primary {
            background: var(--primary);
            border: none;
            padding: 0.6rem 1.5rem;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success);
            border: none;
            padding: 0.6rem 1.5rem;
            font-weight: 500;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .payment-type-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .payment-type-grid {
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
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h4><i class="bi bi-cash me-2"></i>Collections</h4>
                        <p>Record EMI collections and payments</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="collection-list.php" class="btn btn-light">
                            <i class="bi bi-list-check me-2"></i>Collection List
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

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Main Form -->
            <div class="payment-card">
                <h5><i class="bi bi-pencil-square me-2"></i>Record New Collection</h5>
                
                <form method="POST" action="" id="collectionForm">
                    <!-- Customer Selection -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Select Customer <span class="text-danger">*</span></label>
                            <select class="form-select" name="customer_id" id="customerSelect" required>
                                <option value="">-- Choose Customer --</option>
                                <?php 
                                if ($customers) {
                                    $customers->data_seek(0);
                                    while ($cust = $customers->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $cust['id']; ?>" <?php echo $selected_customer == $cust['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cust['customer_name']); ?> - <?php echo htmlspecialchars($cust['agreement_number']); ?> (<?php echo htmlspecialchars($cust['customer_number']); ?>)
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Select EMI <span class="text-danger">*</span></label>
                            <select class="form-select" name="emi_id" id="emiSelect" required>
                                <option value="">-- First select a customer --</option>
                            </select>
                        </div>
                    </div>

                    <!-- EMI Info (will be populated by JS) -->
                    <div id="emiInfo" style="display: none;">
                        <div class="emi-info-card">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Principal Amount:</span>
                                        <span class="emi-info-value principal" id="displayPrincipal">₹0.00</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Principal Paid:</span>
                                        <span class="emi-info-value" id="displayPrincipalPaid">₹0.00</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Remaining Principal:</span>
                                        <span class="emi-info-value principal" id="displayRemainingPrincipal">₹0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Interest Amount:</span>
                                        <span class="emi-info-value interest" id="displayInterest">₹0.00</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Interest Paid:</span>
                                        <span class="emi-info-value" id="displayInterestPaid">₹0.00</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Remaining Interest:</span>
                                        <span class="emi-info-value interest" id="displayRemainingInterest">₹0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Overdue Charges:</span>
                                        <span class="emi-info-value overdue" id="displayOverdue">₹0.00</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Overdue Paid:</span>
                                        <span class="emi-info-value" id="displayOverduePaid">₹0.00</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Remaining Overdue:</span>
                                        <span class="emi-info-value overdue" id="displayRemainingOverdue">₹0.00</span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Due Date:</span>
                                        <span class="emi-info-value" id="displayDueDate">-</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Status:</span>
                                        <span class="emi-info-value" id="displayStatus">-</span>
                                    </div>
                                    <div class="emi-info-row">
                                        <span class="emi-info-label">Total Remaining:</span>
                                        <span class="emi-info-value total" id="displayTotalRemaining">₹0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Type Selection -->
                    <div class="mb-4">
                        <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                        <div class="payment-type-grid">
                            <div class="payment-type-card <?php echo (!isset($_POST['payment_type']) || $_POST['payment_type'] == 'full') ? 'selected' : ''; ?>" data-type="full">
                                <div class="payment-type-icon success"><i class="bi bi-cash-stack"></i></div>
                                <div class="payment-type-title">Full EMI</div>
                                <div class="payment-type-desc">Pay complete EMI</div>
                            </div>
                            <div class="payment-type-card <?php echo (isset($_POST['payment_type']) && $_POST['payment_type'] == 'principal') ? 'selected' : ''; ?>" data-type="principal">
                                <div class="payment-type-icon primary"><i class="bi bi-bank"></i></div>
                                <div class="payment-type-title">Principal Only</div>
                                <div class="payment-type-desc">Pay only principal</div>
                            </div>
                            <div class="payment-type-card <?php echo (isset($_POST['payment_type']) && $_POST['payment_type'] == 'interest') ? 'selected' : ''; ?>" data-type="interest">
                                <div class="payment-type-icon warning"><i class="bi bi-percent"></i></div>
                                <div class="payment-type-title">Interest Only</div>
                                <div class="payment-type-desc">Pay only interest</div>
                            </div>
                            <div class="payment-type-card <?php echo (isset($_POST['payment_type']) && $_POST['payment_type'] == 'custom') ? 'selected' : ''; ?>" data-type="custom">
                                <div class="payment-type-icon info"><i class="bi bi-pencil-square"></i></div>
                                <div class="payment-type-title">Custom Amount</div>
                                <div class="payment-type-desc">Enter any amount</div>
                            </div>
                        </div>
                        <input type="hidden" name="payment_type" id="paymentType" value="<?php echo htmlspecialchars($_POST['payment_type'] ?? 'full'); ?>">
                    </div>

                    <!-- Payment Details -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Amount to Pay (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="amount" value="<?php echo htmlspecialchars($_POST['amount'] ?? ''); ?>" required>
                            <small class="text-muted" id="amountHelp">Enter amount to pay</small>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Bill Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="bill_number" value="<?php echo htmlspecialchars($_POST['bill_number'] ?? ''); ?>" required placeholder="Enter bill number">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash" <?php echo (($_POST['payment_method'] ?? 'cash') == 'cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo (($_POST['payment_method'] ?? '') == 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cheque" <?php echo (($_POST['payment_method'] ?? '') == 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                                <option value="online" <?php echo (($_POST['payment_method'] ?? '') == 'online') ? 'selected' : ''; ?>>Online</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Paid Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="paid_date" value="<?php echo htmlspecialchars($_POST['paid_date'] ?? $today); ?>" max="<?php echo $today; ?>" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Principal Portion</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="principal" id="principalPortion" value="0" readonly>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Interest Portion</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="interest" id="interestPortion" value="0" readonly>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Overdue Portion</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" class="form-control" name="overdue" id="overduePortion" value="0" readonly>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes"><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Summary Card -->
                    <div class="summary-card" id="summaryCard" style="display: none;">
                        <div class="summary-row">
                            <span class="summary-label">Payment Type:</span>
                            <span class="summary-value" id="summaryType">Full EMI</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Amount to Pay:</span>
                            <span class="summary-value" id="summaryAmount">₹0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Principal Portion:</span>
                            <span class="summary-value" id="summaryPrincipal">₹0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Interest Portion:</span>
                            <span class="summary-value" id="summaryInterest">₹0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Overdue Portion:</span>
                            <span class="summary-value" id="summaryOverdue">₹0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Excess Amount:</span>
                            <span class="summary-value" id="summaryExcess">₹0.00</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="collection-list.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>Process Payment
                        </button>
                    </div>
                </form>
            </div>

            <!-- Quick Tips -->
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="payment-card">
                        <h5><i class="bi bi-lightbulb me-2 text-warning"></i>Quick Tips</h5>
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2 small"></i> Select customer first</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2 small"></i> Choose the EMI to pay</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2 small"></i> Select payment type</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2 small"></i> Enter amount and bill number</li>
                            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2 small"></i> Review summary before submitting</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="payment-card">
                        <h5><i class="bi bi-info-circle me-2 text-info"></i>Payment Types</h5>
                        <ul class="list-unstyled">
                            <li class="mb-2"><strong class="text-success">Full EMI:</strong> Pay complete EMI amount</li>
                            <li class="mb-2"><strong class="text-primary">Principal Only:</strong> Pay only principal portion</li>
                            <li class="mb-2"><strong class="text-warning">Interest Only:</strong> Pay only interest portion</li>
                            <li class="mb-2"><strong class="text-info">Custom Amount:</strong> Enter any amount</li>
                        </ul>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="payment-card">
                        <h5><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Note</h5>
                        <p class="small mb-0">Excess payments will be automatically applied to future EMIs starting from the earliest due date.</p>
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
    // Load EMIs when customer is selected
    $('#customerSelect').change(function() {
        const customerId = $(this).val();
        if (customerId) {
            // Show loading state
            $('#emiSelect').html('<option value="">Loading EMIs...</option>');
            
            $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: { 
                    ajax: 'get_emis',
                    customer_id: customerId 
                },
                dataType: 'json',
                success: function(response) {
                    let options = '<option value="">-- Select EMI --</option>';
                    if (response && response.length > 0) {
                        response.forEach(function(emi) {
                            const status = emi.status.charAt(0).toUpperCase() + emi.status.slice(1);
                            const dueDate = emi.emi_due_date;
                            
                            options += `<option value="${emi.id}" 
                                data-principal="${emi.remaining_principal}" 
                                data-interest="${emi.remaining_interest}" 
                                data-overdue="${emi.remaining_overdue}"
                                data-total="${emi.remaining_total}"
                                data-principal-paid="${emi.principal_paid}"
                                data-interest-paid="${emi.interest_paid}"
                                data-overdue-paid="${emi.overdue_paid}"
                                data-principal-amount="${emi.principal_amount}"
                                data-interest-amount="${emi.interest_amount}"
                                data-overdue-charges="${emi.overdue_charges}"
                                data-due="${emi.emi_due_date}" 
                                data-status="${emi.status}">
                                EMI #${emi.emi_number} - Due: ${dueDate} - Status: ${status} - ₹${emi.remaining_total.toFixed(2)}
                            </option>`;
                        });
                    } else {
                        options += '<option value="">No pending EMIs found for this customer</option>';
                    }
                    $('#emiSelect').html(options);
                    
                    // Hide EMI info and summary when customer changes
                    $('#emiInfo').hide();
                    $('#summaryCard').hide();
                },
                error: function(xhr, status, error) {
                    console.error('Error loading EMIs:', error);
                    $('#emiSelect').html('<option value="">Error loading EMIs. Please try again.</option>');
                }
            });
        } else {
            $('#emiSelect').html('<option value="">-- First select a customer --</option>');
            $('#emiInfo').hide();
            $('#summaryCard').hide();
        }
    });

    // Show EMI info when selected
    $('#emiSelect').change(function() {
        const selected = $(this).find(':selected');
        const emiId = $(this).val();
        
        if (emiId && emiId !== '') {
            const principal = parseFloat(selected.data('principal') || 0);
            const interest = parseFloat(selected.data('interest') || 0);
            const overdue = parseFloat(selected.data('overdue') || 0);
            const total = parseFloat(selected.data('total') || 0);
            
            const principalPaid = parseFloat(selected.data('principal-paid') || 0);
            const interestPaid = parseFloat(selected.data('interest-paid') || 0);
            const overduePaid = parseFloat(selected.data('overdue-paid') || 0);
            
            const principalAmount = parseFloat(selected.data('principal-amount') || 0);
            const interestAmount = parseFloat(selected.data('interest-amount') || 0);
            const overdueCharges = parseFloat(selected.data('overdue-charges') || 0);
            
            const dueDate = selected.data('due') || 'N/A';
            const status = selected.data('status') || 'unknown';
            
            $('#displayPrincipal').text('₹' + principalAmount.toFixed(2));
            $('#displayPrincipalPaid').text('₹' + principalPaid.toFixed(2));
            $('#displayRemainingPrincipal').text('₹' + principal.toFixed(2));
            $('#displayInterest').text('₹' + interestAmount.toFixed(2));
            $('#displayInterestPaid').text('₹' + interestPaid.toFixed(2));
            $('#displayRemainingInterest').text('₹' + interest.toFixed(2));
            $('#displayOverdue').text('₹' + overdueCharges.toFixed(2));
            $('#displayOverduePaid').text('₹' + overduePaid.toFixed(2));
            $('#displayRemainingOverdue').text('₹' + overdue.toFixed(2));
            $('#displayDueDate').text(dueDate);
            $('#displayStatus').text(status.charAt(0).toUpperCase() + status.slice(1));
            $('#displayTotalRemaining').text('₹' + total.toFixed(2));
            
            $('#emiInfo').show();
            updateAmountHelp();
        } else {
            $('#emiInfo').hide();
            $('#summaryCard').hide();
        }
    });

    // Payment type selection
    $('.payment-type-card').click(function() {
        $('.payment-type-card').removeClass('selected');
        $(this).addClass('selected');
        
        const type = $(this).data('type');
        $('#paymentType').val(type);
        
        updateAmountHelp();
        calculateAllocation();
    });

    // Amount input change
    $('#amount').on('input', function() {
        calculateAllocation();
    });

    function updateAmountHelp() {
        const type = $('#paymentType').val();
        const selected = $('#emiSelect').find(':selected');
        
        if (!selected.val() || selected.val() === '') {
            return;
        }
        
        const principal = parseFloat(selected.data('principal') || 0);
        const interest = parseFloat(selected.data('interest') || 0);
        const overdue = parseFloat(selected.data('overdue') || 0);
        const total = principal + interest + overdue;
        
        let helpText = '';
        if (type === 'full') {
            helpText = `Full EMI amount: ₹${total.toFixed(2)}`;
            $('#amount').val(total.toFixed(2));
        } else if (type === 'principal') {
            helpText = `Principal only - Max: ₹${principal.toFixed(2)}`;
            $('#amount').val(principal.toFixed(2));
        } else if (type === 'interest') {
            helpText = `Interest only - Max: ₹${interest.toFixed(2)}`;
            $('#amount').val(interest.toFixed(2));
        } else {
            helpText = 'Enter any amount. Excess will reduce future EMIs';
        }
        $('#amountHelp').text(helpText);
        
        calculateAllocation();
    }

    function calculateAllocation() {
        const type = $('#paymentType').val();
        const selected = $('#emiSelect').find(':selected');
        
        if (!selected.val() || selected.val() === '') {
            return;
        }
        
        const amount = parseFloat($('#amount').val()) || 0;
        
        const principal = parseFloat(selected.data('principal') || 0);
        const interest = parseFloat(selected.data('interest') || 0);
        const overdue = parseFloat(selected.data('overdue') || 0);
        
        let principalPortion = 0;
        let interestPortion = 0;
        let overduePortion = 0;
        let remainingAmount = amount;
        let excessAmount = 0;
        
        // First pay overdue if any
        if (remainingAmount > 0 && overdue > 0) {
            overduePortion = Math.min(remainingAmount, overdue);
            remainingAmount -= overduePortion;
        }
        
        // Then pay based on type
        if (remainingAmount > 0) {
            if (type === 'principal') {
                principalPortion = Math.min(remainingAmount, principal);
                remainingAmount -= principalPortion;
            } else if (type === 'interest') {
                interestPortion = Math.min(remainingAmount, interest);
                remainingAmount -= interestPortion;
            } else {
                // Split proportionally between principal and interest
                const totalRemaining = principal + interest;
                if (totalRemaining > 0) {
                    const principalRatio = principal / totalRemaining;
                    const interestRatio = interest / totalRemaining;
                    
                    principalPortion = Math.min(remainingAmount * principalRatio, principal);
                    interestPortion = Math.min(remainingAmount * interestRatio, interest);
                    
                    // Adjust for rounding - ensure we don't exceed remaining amount
                    const sum = principalPortion + interestPortion;
                    if (sum < remainingAmount) {
                        // Add remaining to interest portion
                        interestPortion += (remainingAmount - sum);
                    }
                    
                    remainingAmount -= (principalPortion + interestPortion);
                }
            }
        }
        
        excessAmount = remainingAmount;
        
        // Ensure portions don't exceed available amounts
        principalPortion = Math.min(principalPortion, principal);
        interestPortion = Math.min(interestPortion, interest);
        overduePortion = Math.min(overduePortion, overdue);
        
        // Update readonly fields
        $('#principalPortion').val(principalPortion.toFixed(2));
        $('#interestPortion').val(interestPortion.toFixed(2));
        $('#overduePortion').val(overduePortion.toFixed(2));
        
        // Update summary
        const emiId = $('#emiSelect').val();
        if (emiId && emiId !== '') {
            $('#summaryCard').show();
            let typeText = '';
            if (type === 'full') typeText = 'Full EMI';
            else if (type === 'principal') typeText = 'Principal Only';
            else if (type === 'interest') typeText = 'Interest Only';
            else typeText = 'Custom Amount';
            
            $('#summaryType').text(typeText);
            $('#summaryAmount').text('₹' + amount.toFixed(2));
            $('#summaryPrincipal').text('₹' + principalPortion.toFixed(2));
            $('#summaryInterest').text('₹' + interestPortion.toFixed(2));
            $('#summaryOverdue').text('₹' + overduePortion.toFixed(2));
            $('#summaryExcess').text('₹' + excessAmount.toFixed(2));
        }
    }

    // If customer and EMI are pre-selected from URL params
    <?php if ($selected_customer > 0 && $selected_emi > 0): ?>
    $('#customerSelect').val(<?php echo $selected_customer; ?>).trigger('change');
    setTimeout(function() {
        $('#emiSelect').val(<?php echo $selected_emi; ?>).trigger('change');
    }, 1000);
    <?php endif; ?>

    // Touch feedback
    const buttons = document.querySelectorAll('.btn, .payment-type-card');
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