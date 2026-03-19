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
</head>
<body>

<div class="container">
    <h2 class="mt-5 mb-3">Foreclosure Payment</h2>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="">

        <!-- Bill Number -->
        <div class="mb-3">
            <label for="bill_number" class="form-label">Bill Number</label>
            <input type="text" class="form-control" id="bill_number" name="bill_number" required>
        </div>

        <!-- Amounts -->
        <div class="mb-3">
            <label for="principal_paid" class="form-label">Principal Paid</label>
            <input type="number" class="form-control" id="principal_paid" name="principal_paid" value="<?php echo number_format($total_principal, 2); ?>" readonly>
        </div>
        
        <div class="mb-3">
            <label for="interest_paid" class="form-label">Interest Paid</label>
            <input type="number" class="form-control" id="interest_paid" name="interest_paid" value="<?php echo number_format($total_interest, 2); ?>" readonly>
        </div>

        <div class="mb-3">
            <label for="overdue_charges" class="form-label">Overdue Charges Paid</label>
            <input type="number" class="form-control" id="overdue_charges" name="overdue_charges" value="<?php echo number_format($total_overdue, 2); ?>" readonly>
        </div>

        <!-- Foreclosure Charge -->
        <div class="mb-3">
            <label for="foreclosure_charge" class="form-label">Foreclosure Charge (2%)</label>
            <input type="number" class="form-control" id="foreclosure_charge" name="foreclosure_charge" value="<?php echo number_format($total_without_charge * 0.02, 2); ?>" readonly>
        </div>

        <!-- Total Amount -->
        <div class="mb-3">
            <label for="paid_amount" class="form-label">Total Paid Amount</label>
            <input type="number" class="form-control" id="paid_amount" name="paid_amount" value="<?php echo number_format($total_without_charge * 1.02, 2); ?>" readonly>
        </div>

        <!-- Payment Date -->
        <div class="mb-3">
            <label for="paid_date" class="form-label">Payment Date</label>
            <input type="date" class="form-control" id="paid_date" name="paid_date" required>
        </div>

        <!-- Payment Method -->
        <div class="mb-3">
            <label for="payment_method" class="form-label">Payment Method</label>
            <select class="form-select" id="payment_method" name="payment_method" required>
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="online">Online Payment</option>
            </select>
        </div>

        <!-- Remarks -->
        <div class="mb-3">
            <label for="remarks" class="form-label">Remarks</label>
            <textarea class="form-control" id="remarks" name="remarks" rows="3" placeholder="Any remarks regarding foreclosure"></textarea>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn btn-success">Process Foreclosure</button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>