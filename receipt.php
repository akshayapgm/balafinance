<?php
// receipt.php - Thermal Receipt Print Page
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u966043993/domains/ecommerstore.in/public_html/bala-finance/error_log');

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'includes/auth.php';
require_once 'includes/db.php';

$emi_id = intval($_GET['emi_id'] ?? 0);
$customer_id = intval($_GET['customer_id'] ?? 0);

if ($emi_id <= 0 || $customer_id <= 0) {
    die('Invalid EMI or Customer ID');
}

$user_role = $_SESSION['role'] ?? 'user';
$session_finance_id = intval($_SESSION['finance_id'] ?? 0);

// -------------------------
// Fetch receipt data
// -------------------------
if ($user_role === 'admin') {
    $sql = "SELECT
                e.id AS emi_id,
                e.customer_id,
                e.emi_amount,
                e.principal_amount,
                e.interest_amount,
                e.emi_due_date,
                e.status,
                e.overdue_charges,
                e.overdue_paid,
                e.emi_bill_number,
                e.paid_date,
                e.payment_method,
                e.week_number,
                e.day_number,
                e.collection_type,
                COALESCE(e.principal_paid, 0) AS principal_paid,
                COALESCE(e.interest_paid, 0) AS interest_paid,
                e.foreclosure_id,
                c.customer_name,
                c.customer_number,
                c.agreement_number,
                c.loan_amount,
                c.finance_id,
                c.vehicle_number,
                c.vehicle_type,
                f.finance_name,
                fp.total_amount AS foreclosure_total,
                fp.total_principal,
                fp.total_interest,
                fp.total_overdue,
                fp.foreclosure_charge,
                fp.bill_number AS foreclosure_bill_number,
                fp.payment_date AS foreclosure_payment_date,
                (SELECT COALESCE(SUM(excess_amount),0) FROM excess_payments WHERE emi_id = e.id AND customer_id = c.id) AS excess_amount
            FROM emi_schedule e
            JOIN customers c ON e.customer_id = c.id
            LEFT JOIN finance f ON c.finance_id = f.id
            LEFT JOIN foreclosure_payments fp ON fp.customer_id = c.id AND FIND_IN_SET(e.id, fp.emi_ids)
            WHERE e.id = ? AND e.customer_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Database error');
    }
    $stmt->bind_param("ii", $emi_id, $customer_id);
} else {
    $sql = "SELECT
                e.id AS emi_id,
                e.customer_id,
                e.emi_amount,
                e.principal_amount,
                e.interest_amount,
                e.emi_due_date,
                e.status,
                e.overdue_charges,
                e.overdue_paid,
                e.emi_bill_number,
                e.paid_date,
                e.payment_method,
                e.week_number,
                e.day_number,
                e.collection_type,
                COALESCE(e.principal_paid, 0) AS principal_paid,
                COALESCE(e.interest_paid, 0) AS interest_paid,
                e.foreclosure_id,
                c.customer_name,
                c.customer_number,
                c.agreement_number,
                c.loan_amount,
                c.finance_id,
                c.vehicle_number,
                c.vehicle_type,
                f.finance_name,
                fp.total_amount AS foreclosure_total,
                fp.total_principal,
                fp.total_interest,
                fp.total_overdue,
                fp.foreclosure_charge,
                fp.bill_number AS foreclosure_bill_number,
                fp.payment_date AS foreclosure_payment_date,
                (SELECT COALESCE(SUM(excess_amount),0) FROM excess_payments WHERE emi_id = e.id AND customer_id = c.id) AS excess_amount
            FROM emi_schedule e
            JOIN customers c ON e.customer_id = c.id
            LEFT JOIN finance f ON c.finance_id = f.id
            LEFT JOIN foreclosure_payments fp ON fp.customer_id = c.id AND FIND_IN_SET(e.id, fp.emi_ids)
            WHERE e.id = ? AND e.customer_id = ? AND c.finance_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Database error');
    }
    $stmt->bind_param("iii", $emi_id, $customer_id, $session_finance_id);
}

$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

if (!$data) {
    die('Receipt not found');
}

// -------------------------
// Access check
// -------------------------
if ($user_role !== 'admin' && intval($data['finance_id']) !== $session_finance_id) {
    die('Access denied');
}

// -------------------------
// Values
// -------------------------
$is_foreclosure = !empty($data['foreclosure_id']);
$principal_paid = floatval($data['principal_paid'] ?? 0);
$interest_paid = floatval($data['interest_paid'] ?? 0);
$overdue_paid = floatval($data['overdue_paid'] ?? 0);
$excess_amount = floatval($data['excess_amount'] ?? 0);

if ($is_foreclosure) {
    $receipt_title = 'FORECLOSURE RECEIPT';
    $bill_no = $data['foreclosure_bill_number'] ?: $data['emi_bill_number'];
    $receipt_date = !empty($data['foreclosure_payment_date']) ? $data['foreclosure_payment_date'] : $data['paid_date'];
    $principal_display = floatval($data['total_principal'] ?? 0);
    $interest_display = floatval($data['total_interest'] ?? 0);
    $overdue_display = floatval($data['total_overdue'] ?? 0);
    $foreclosure_charge = floatval($data['foreclosure_charge'] ?? 0);
    $total_paid = floatval($data['foreclosure_total'] ?? 0);
} else {
    $receipt_title = 'PAYMENT RECEIPT';
    $bill_no = $data['emi_bill_number'];
    $receipt_date = $data['paid_date'];
    $principal_display = $principal_paid;
    $interest_display = $interest_paid;
    $overdue_display = $overdue_paid;
    $foreclosure_charge = 0;
    $total_paid = $principal_paid + $interest_paid + $overdue_paid + $excess_amount;
}

$receipt_date_display = (!empty($receipt_date) && $receipt_date !== '0000-00-00')
    ? date('d-m-Y', strtotime($receipt_date))
    : '-';

$due_date_display = (!empty($data['emi_due_date']) && $data['emi_due_date'] !== '0000-00-00')
    ? date('d-m-Y', strtotime($data['emi_due_date']))
    : '-';

$payment_method = $data['payment_method'] ?: 'cash';

$period_label = 'EMI';
if (($data['collection_type'] ?? '') === 'weekly' && !empty($data['week_number'])) {
    $period_label = 'Week ' . intval($data['week_number']);
} elseif (($data['collection_type'] ?? '') === 'daily' && !empty($data['day_number'])) {
    $period_label = 'Day ' . intval($data['day_number']);
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo e($bill_no); ?></title>
    <style>
        @page {
            size: 80mm auto;
            margin: 4mm;
        }

        body {
            margin: 0;
            padding: 0;
            background: #fff;
            font-family: Arial, sans-serif;
            color: #000;
        }

        .receipt {
            width: 72mm;
            margin: 0 auto;
            padding: 2mm 0;
            font-size: 12px;
            line-height: 1.35;
        }

        .center {
            text-align: center;
        }

        .bold {
            font-weight: 700;
        }

        .title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .subtitle {
            font-size: 12px;
            margin-bottom: 6px;
        }

        .line {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin: 2px 0;
        }

        .row .label {
            flex: 1;
        }

        .row .value {
            text-align: right;
            white-space: nowrap;
        }

        .big-total {
            font-size: 16px;
            font-weight: 700;
        }

        .small {
            font-size: 11px;
        }

        .footer {
            margin-top: 8px;
            text-align: center;
            font-size: 11px;
        }

        .print-btn-wrap {
            text-align: center;
            margin: 12px 0;
        }

        .print-btn {
            background: #111;
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }

        @media print {
            .print-btn-wrap {
                display: none;
            }

            body {
                width: 80mm;
            }
        }
    </style>
</head>
<body>

<div class="print-btn-wrap">
    <button class="print-btn" onclick="window.print()">Print Receipt</button>
</div>

<div class="receipt">
    <div class="center bold title"><?php echo e($data['finance_name'] ?: 'Bala Finance'); ?></div>
    <div class="center subtitle"><?php echo e($receipt_title); ?></div>

    <div class="line"></div>

    <div class="row">
        <div class="label">Bill No</div>
        <div class="value bold"><?php echo e($bill_no ?: '-'); ?></div>
    </div>
    <div class="row">
        <div class="label">Date</div>
        <div class="value"><?php echo e($receipt_date_display); ?></div>
    </div>
    <div class="row">
        <div class="label">Agreement</div>
        <div class="value"><?php echo e($data['agreement_number']); ?></div>
    </div>

    <div class="line"></div>

    <div class="row">
        <div class="label">Customer</div>
        <div class="value"><?php echo e($data['customer_name']); ?></div>
    </div>
    <div class="row">
        <div class="label">Mobile</div>
        <div class="value"><?php echo e($data['customer_number']); ?></div>
    </div>

    <?php if (!empty($data['vehicle_number'])): ?>
    <div class="row">
        <div class="label">Vehicle</div>
        <div class="value"><?php echo e($data['vehicle_number']); ?></div>
    </div>
    <?php endif; ?>

    <div class="line"></div>

    <div class="row">
        <div class="label">Collection Type</div>
        <div class="value"><?php echo e(ucfirst($data['collection_type'])); ?></div>
    </div>
    <div class="row">
        <div class="label">Reference</div>
        <div class="value"><?php echo e($period_label); ?></div>
    </div>
    <div class="row">
        <div class="label">Due Date</div>
        <div class="value"><?php echo e($due_date_display); ?></div>
    </div>
    <div class="row">
        <div class="label">Payment Mode</div>
        <div class="value"><?php echo e(ucwords(str_replace('_', ' ', $payment_method))); ?></div>
    </div>

    <div class="line"></div>

    <div class="row">
        <div class="label">Principal</div>
        <div class="value">₹<?php echo number_format($principal_display, 2); ?></div>
    </div>
    <div class="row">
        <div class="label">Interest</div>
        <div class="value">₹<?php echo number_format($interest_display, 2); ?></div>
    </div>

    <?php if ($overdue_display > 0): ?>
    <div class="row">
        <div class="label">Overdue</div>
        <div class="value">₹<?php echo number_format($overdue_display, 2); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($foreclosure_charge > 0): ?>
    <div class="row">
        <div class="label">Foreclosure Fee</div>
        <div class="value">₹<?php echo number_format($foreclosure_charge, 2); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!$is_foreclosure && $excess_amount > 0): ?>
    <div class="row">
        <div class="label">Excess</div>
        <div class="value">₹<?php echo number_format($excess_amount, 2); ?></div>
    </div>
    <?php endif; ?>

    <div class="line"></div>

    <div class="row big-total">
        <div class="label">Total Paid</div>
        <div class="value">₹<?php echo number_format($total_paid, 2); ?></div>
    </div>

    <div class="line"></div>

    <div class="footer">
        <div>Thank you</div>
        <div class="small">Generated on <?php echo date('d-m-Y h:i A'); ?></div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 300);
});
</script>

</body>
</html>