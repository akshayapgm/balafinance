<?php
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

$currentPage = 'pay-emi';
$pageTitle = 'Pay EMI';

$user_role = $_SESSION['role'] ?? 'user';
$session_finance_id = intval($_SESSION['finance_id'] ?? 0);

$emi_id = intval($_POST['emi_id'] ?? $_GET['emi_id'] ?? 0);
$customer_id = intval($_POST['customer_id'] ?? $_GET['customer_id'] ?? 0);

if ($emi_id <= 0 || $customer_id <= 0) {
    $_SESSION['error'] = "Invalid EMI or Customer ID.";
    header("Location: manage-customers.php");
    exit;
}

// -------------------------
// Create required tables if not exist
// -------------------------
$conn->query("CREATE TABLE IF NOT EXISTS emi_reductions (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    emi_id INT(11) NOT NULL,
    customer_id INT(11) NOT NULL,
    reduction_amount DECIMAL(15,2) NOT NULL,
    original_principal DECIMAL(15,2) NOT NULL,
    original_interest DECIMAL(15,2) NOT NULL,
    new_principal DECIMAL(15,2) NOT NULL,
    new_interest DECIMAL(15,2) NOT NULL,
    reduction_date DATE NOT NULL,
    bill_number VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emi (emi_id),
    INDEX idx_customer (customer_id),
    INDEX idx_bill (bill_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS excess_payments (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT(11) NOT NULL,
    emi_id INT(11) NOT NULL,
    excess_amount DECIMAL(15,2) NOT NULL,
    used_amount DECIMAL(15,2) DEFAULT 0.00,
    remaining_amount DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    bill_number VARCHAR(50) NOT NULL,
    status ENUM('active', 'used', 'cancelled') DEFAULT 'active',
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_emi (emi_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// -------------------------
// Fetch EMI and customer
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
                c.customer_name,
                c.agreement_number,
                c.customer_number,
                c.loan_amount,
                c.finance_id,
                (SELECT COUNT(*) FROM emi_schedule es2 WHERE es2.customer_id = c.id AND es2.emi_due_date <= e.emi_due_date) AS emi_number,
                (SELECT COUNT(*) FROM emi_schedule es3 WHERE es3.customer_id = c.id AND es3.status IN ('unpaid', 'overdue', 'partial') AND es3.id > e.id) AS remaining_emis,
                (SELECT COALESCE(SUM(principal_amount - COALESCE(principal_paid,0)), 0) FROM emi_schedule es4 WHERE es4.customer_id = c.id AND es4.status IN ('unpaid', 'overdue', 'partial') AND es4.id > e.id) AS future_principal,
                (SELECT COALESCE(SUM(interest_amount - COALESCE(interest_paid,0)), 0) FROM emi_schedule es5 WHERE es5.customer_id = c.id AND es5.status IN ('unpaid', 'overdue', 'partial') AND es5.id > e.id) AS future_interest
            FROM emi_schedule e
            JOIN customers c ON e.customer_id = c.id
            WHERE e.id = ? AND e.customer_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error'] = "Database error occurred.";
        header("Location: emi-schedule.php?customer_id=$customer_id");
        exit;
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
                c.customer_name,
                c.agreement_number,
                c.customer_number,
                c.loan_amount,
                c.finance_id,
                (SELECT COUNT(*) FROM emi_schedule es2 WHERE es2.customer_id = c.id AND es2.emi_due_date <= e.emi_due_date) AS emi_number,
                (SELECT COUNT(*) FROM emi_schedule es3 WHERE es3.customer_id = c.id AND es3.status IN ('unpaid', 'overdue', 'partial') AND es3.id > e.id) AS remaining_emis,
                (SELECT COALESCE(SUM(principal_amount - COALESCE(principal_paid,0)), 0) FROM emi_schedule es4 WHERE es4.customer_id = c.id AND es4.status IN ('unpaid', 'overdue', 'partial') AND es4.id > e.id) AS future_principal,
                (SELECT COALESCE(SUM(interest_amount - COALESCE(interest_paid,0)), 0) FROM emi_schedule es5 WHERE es5.customer_id = c.id AND es5.status IN ('unpaid', 'overdue', 'partial') AND es5.id > e.id) AS future_interest
            FROM emi_schedule e
            JOIN customers c ON e.customer_id = c.id
            WHERE e.id = ? AND e.customer_id = ? AND c.finance_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error'] = "Database error occurred.";
        header("Location: emi-schedule.php?customer_id=$customer_id");
        exit;
    }
    $stmt->bind_param("iii", $emi_id, $customer_id, $session_finance_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "EMI not found.";
    header("Location: emi-schedule.php?customer_id=$customer_id");
    exit;
}

$emi = $result->fetch_assoc();
$stmt->close();

if ($user_role !== 'admin' && intval($emi['finance_id']) !== $session_finance_id) {
    $_SESSION['error'] = "You don't have permission to access this EMI.";
    header("Location: emi-schedule.php?customer_id=$customer_id");
    exit;
}

$remaining_principal = max(0, floatval($emi['principal_amount']) - floatval($emi['principal_paid']));
$remaining_interest = max(0, floatval($emi['interest_amount']) - floatval($emi['interest_paid']));
$remaining_total = $remaining_principal + $remaining_interest;

$total_overdue = floatval($emi['overdue_charges'] ?? 0);
$paid_overdue = floatval($emi['overdue_paid'] ?? 0);
$pending_overdue = max(0, $total_overdue - $paid_overdue);

$reduced_by_previous = 0;
$original_principal = $emi['principal_amount'];
$original_interest = $emi['interest_amount'];

$reduction_sql = "SELECT reduction_amount, original_principal, original_interest FROM emi_reductions WHERE emi_id = ?";
$stmt = $conn->prepare($reduction_sql);
if ($stmt) {
    $stmt->bind_param("i", $emi_id);
    $stmt->execute();
    $reduction_result = $stmt->get_result();
    if ($reduction_row = $reduction_result->fetch_assoc()) {
        $reduced_by_previous = floatval($reduction_row['reduction_amount']);
        $original_principal = floatval($reduction_row['original_principal']);
        $original_interest = floatval($reduction_row['original_interest']);
    }
    $stmt->close();
}

$unpaid_count_sql = "SELECT COUNT(*) as total_unpaid FROM emi_schedule
                     WHERE customer_id = ? AND status IN ('unpaid', 'overdue', 'partial') AND id > ?";
$stmt = $conn->prepare($unpaid_count_sql);
$stmt->bind_param("ii", $customer_id, $emi_id);
$stmt->execute();
$unpaid_result = $stmt->get_result();
$unpaid_data = $unpaid_result->fetch_assoc();
$total_unpaid_emis = intval($unpaid_data['total_unpaid'] ?? 0);
$stmt->close();

$available_excess = 0;
$excess_sql = "SELECT SUM(remaining_amount) as total_excess FROM excess_payments WHERE customer_id = ? AND status = 'active'";
$stmt = $conn->prepare($excess_sql);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$excess_result = $stmt->get_result();
if ($excess_row = $excess_result->fetch_assoc()) {
    $available_excess = floatval($excess_row['total_excess'] ?? 0);
}
$stmt->close();

// -------------------------
// Handle payment
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bill_number = trim($_POST['emi_bill_number'] ?? '');
    $paid_date = $_POST['paid_date'] ?? '';
    $pay_type = $_POST['pay_type'] ?? 'full';
    $custom_amount = floatval($_POST['custom_amount'] ?? 0);
    $customer_number = trim($_POST['customer_number'] ?? '');
    $overdue_input = floatval($_POST['overdue_charges'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $payment_method = $_POST['payment_method'] ?? 'cash';

    $errors = [];

    if ($bill_number === '') {
        $errors[] = "Bill Number is required.";
    }
    if (empty($paid_date) || !strtotime($paid_date)) {
        $errors[] = "Valid Paid Date is required.";
    } elseif (strtotime($paid_date) > strtotime(date('Y-m-d'))) {
        $errors[] = "Paid Date cannot be in the future.";
    }
    if ($customer_number === '') {
        $errors[] = "Customer number is required.";
    }
    if ($overdue_input < 0) {
        $errors[] = "Overdue charges cannot be negative.";
    }
    if ($custom_amount <= 0) {
        $errors[] = "Payment amount must be greater than zero.";
    }
    if (($emi['status'] ?? '') === 'paid') {
        $errors[] = "This EMI is already fully paid.";
    }

    if (empty($errors)) {
        $conn->begin_transaction();

        try {
            $finance_id = intval($emi['finance_id'] ?? $session_finance_id);

            $actual_emi_needed = $remaining_total;

            if ($pay_type === 'full') {
                $custom_amount = $actual_emi_needed;
            } elseif ($pay_type === 'principal') {
                $custom_amount = min($custom_amount, $remaining_principal);
            } elseif ($pay_type === 'interest') {
                $custom_amount = min($custom_amount, $remaining_interest);
            }

            $amount_to_current_emi = min($custom_amount, $actual_emi_needed);
            $excess_amount = max(0, $custom_amount - $actual_emi_needed);

            $principal_paid_now = 0;
            $interest_paid_now = 0;
            $new_principal_paid = floatval($emi['principal_paid']);
            $new_interest_paid = floatval($emi['interest_paid']);
            $new_overdue_paid = $paid_overdue;
            $new_status = $emi['status'];

            if ($amount_to_current_emi > 0) {
                if ($pay_type === 'principal') {
                    $principal_paid_now = min($amount_to_current_emi, $remaining_principal);
                    $interest_paid_now = 0;
                } elseif ($pay_type === 'interest') {
                    $interest_paid_now = min($amount_to_current_emi, $remaining_interest);
                    $principal_paid_now = 0;
                } else {
                    $principal_ratio = ($remaining_total > 0) ? ($remaining_principal / $remaining_total) : 0;
                    $interest_ratio = ($remaining_total > 0) ? ($remaining_interest / $remaining_total) : 0;

                    $principal_paid_now = round($amount_to_current_emi * $principal_ratio, 2);
                    $interest_paid_now = round($amount_to_current_emi * $interest_ratio, 2);

                    $adjustment = $amount_to_current_emi - ($principal_paid_now + $interest_paid_now);
                    if ($adjustment != 0) {
                        $interest_paid_now += $adjustment;
                    }
                }

                $new_principal_paid = min(floatval($emi['principal_amount']), floatval($emi['principal_paid']) + $principal_paid_now);
                $new_interest_paid = min(floatval($emi['interest_amount']), floatval($emi['interest_paid']) + $interest_paid_now);
            }

            if ($overdue_input > 0) {
                $new_overdue_paid = $paid_overdue + $overdue_input;
                if ($new_overdue_paid > $total_overdue) {
                    $total_overdue = $new_overdue_paid;
                }
            }

            $emi_fully_paid = ($new_principal_paid >= floatval($emi['principal_amount'])) &&
                              ($new_interest_paid >= floatval($emi['interest_amount']));

            if ($emi_fully_paid) {
                $new_status = 'paid';
            } elseif ($new_principal_paid > 0 || $new_interest_paid > 0 || $new_overdue_paid > 0) {
                $new_status = 'partial';
            } else {
                $new_status = 'unpaid';
            }

            $update_sql = "UPDATE emi_schedule
                           SET principal_paid = ?,
                               interest_paid = ?,
                               overdue_paid = ?,
                               overdue_charges = ?,
                               emi_bill_number = ?,
                               payment_method = ?,
                               paid_date = ?,
                               status = ?,
                               remarks = CONCAT(IFNULL(remarks, ''), ?),
                               updated_at = NOW()
                           WHERE id = ?";
            $stmt = $conn->prepare($update_sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare EMI update: " . $conn->error);
            }

            $remarks_text = " | Paid on " . date('d-m-Y', strtotime($paid_date)) . " - Type: $pay_type" . ($remarks ? " - $remarks" : "");

            $stmt->bind_param(
                "ddddsssssi",
                $new_principal_paid,
                $new_interest_paid,
                $new_overdue_paid,
                $total_overdue,
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

            $log_query = "INSERT INTO payment_logs
                          (customer_id, emi_id, paid_amount, principal_paid, interest_paid, overdue_charges, bill_number, payment_method, paid_date, remarks, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($log_query);
            if (!$stmt) {
                throw new Exception("Failed to prepare payment log: " . $conn->error);
            }

            $total_paid = $custom_amount + $overdue_input;
            $stmt->bind_param(
                "iiddddssssi",
                $customer_id,
                $emi_id,
                $total_paid,
                $principal_paid_now,
                $interest_paid_now,
                $overdue_input,
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

            // -------------------------
            // Save excess and reduce future EMIs
            // -------------------------
            $remaining_excess = $excess_amount;
            $reduction_details = [];
            $excess_id = 0;

            if ($remaining_excess > 0) {
                $excess_note = "Excess payment from Bill #$bill_number for EMI #{$emi['emi_number']}";

                $insert_excess_sql = "INSERT INTO excess_payments
                                      (customer_id, emi_id, excess_amount, payment_date, bill_number, remaining_amount, note, status)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, 'active')";
                $stmt = $conn->prepare($insert_excess_sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare excess payment insert: " . $conn->error);
                }

                $stmt->bind_param(
                    "iidssds",
                    $customer_id,
                    $emi_id,
                    $remaining_excess,
                    $paid_date,
                    $bill_number,
                    $remaining_excess,
                    $excess_note
                );

                if (!$stmt->execute()) {
                    throw new Exception("Failed to record excess payment: " . $stmt->error);
                }
                $excess_id = $conn->insert_id;
                $stmt->close();

                $future_emis_sql = "SELECT * FROM emi_schedule
                                    WHERE customer_id = ?
                                      AND id > ?
                                      AND status IN ('unpaid', 'overdue', 'partial')
                                    ORDER BY emi_due_date DESC, id DESC";
                $stmt = $conn->prepare($future_emis_sql);
                if (!$stmt) {
                    throw new Exception("Failed to prepare future EMI query: " . $conn->error);
                }

                $stmt->bind_param("ii", $customer_id, $emi_id);
                $stmt->execute();
                $future_emis_result = $stmt->get_result();

                while ($remaining_excess > 0 && $future_emi = $future_emis_result->fetch_assoc()) {
                    $future_emi_id = intval($future_emi['id']);

                    $future_principal_remaining = max(0, floatval($future_emi['principal_amount']) - floatval($future_emi['principal_paid'] ?? 0));
                    $future_interest_remaining = max(0, floatval($future_emi['interest_amount']) - floatval($future_emi['interest_paid'] ?? 0));
                    $future_emi_remaining = $future_principal_remaining + $future_interest_remaining;

                    if ($future_emi_remaining <= 0) {
                        continue;
                    }

                    $reduction_amount = min($remaining_excess, $future_emi_remaining);

                    if ($reduction_amount > 0) {
                        $principal_ratio = ($future_emi_remaining > 0) ? ($future_principal_remaining / $future_emi_remaining) : 0;
                        $interest_ratio = ($future_emi_remaining > 0) ? ($future_interest_remaining / $future_emi_remaining) : 0;

                        $principal_reduction = round($reduction_amount * $principal_ratio, 2);
                        $interest_reduction = round($reduction_amount * $interest_ratio, 2);

                        $adjustment = $reduction_amount - ($principal_reduction + $interest_reduction);
                        if ($adjustment != 0) {
                            $interest_reduction += $adjustment;
                        }

                        $new_future_principal = max(0, floatval($future_emi['principal_amount']) - $principal_reduction);
                        $new_future_interest = max(0, floatval($future_emi['interest_amount']) - $interest_reduction);
                        $new_future_total = $new_future_principal + $new_future_interest;

                        $new_remaining = max(0, $remaining_excess - $reduction_amount);
                        $new_excess_status = $new_remaining > 0 ? 'active' : 'used';

                        $update_excess_sql = "UPDATE excess_payments
                                              SET used_amount = used_amount + ?,
                                                  remaining_amount = ?,
                                                  status = ?,
                                                  note = CONCAT(IFNULL(note, ''), ?)
                                              WHERE id = ?";
                        $stmt2 = $conn->prepare($update_excess_sql);
                        if (!$stmt2) {
                            throw new Exception("Failed to prepare excess update: " . $conn->error);
                        }
                        $append_note = " | Reduced EMI #$future_emi_id";
                        $stmt2->bind_param("dsssi", $reduction_amount, $new_remaining, $new_excess_status, $append_note, $excess_id);
                        if (!$stmt2->execute()) {
                            throw new Exception("Failed to update excess payment: " . $stmt2->error);
                        }
                        $stmt2->close();

                        $check_reduction_sql = "SELECT id FROM emi_reductions WHERE emi_id = ?";
                        $check_stmt = $conn->prepare($check_reduction_sql);
                        if (!$check_stmt) {
                            throw new Exception("Failed to prepare reduction check: " . $conn->error);
                        }
                        $check_stmt->bind_param("i", $future_emi_id);
                        $check_stmt->execute();
                        $reduction_exists = $check_stmt->get_result()->num_rows > 0;
                        $check_stmt->close();

                        if ($reduction_exists) {
                            $reduction_sql = "UPDATE emi_reductions
                                              SET reduction_amount = reduction_amount + ?,
                                                  new_principal = ?,
                                                  new_interest = ?
                                              WHERE emi_id = ?";
                            $stmt3 = $conn->prepare($reduction_sql);
                            if (!$stmt3) {
                                throw new Exception("Failed to prepare reduction update: " . $conn->error);
                            }
                            $stmt3->bind_param("dddi", $reduction_amount, $new_future_principal, $new_future_interest, $future_emi_id);
                        } else {
                            $reduction_sql = "INSERT INTO emi_reductions
                                              (emi_id, customer_id, reduction_amount, original_principal, original_interest, new_principal, new_interest, reduction_date, bill_number)
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt3 = $conn->prepare($reduction_sql);
                            if (!$stmt3) {
                                throw new Exception("Failed to prepare reduction insert: " . $conn->error);
                            }
                            $stmt3->bind_param(
                                "iidddddss",
                                $future_emi_id,
                                $customer_id,
                                $reduction_amount,
                                $future_emi['principal_amount'],
                                $future_emi['interest_amount'],
                                $new_future_principal,
                                $new_future_interest,
                                $paid_date,
                                $bill_number
                            );
                        }

                        if (!$stmt3->execute()) {
                            throw new Exception("Failed to record reduction for EMI #$future_emi_id: " . $stmt3->error);
                        }
                        $stmt3->close();

                        $update_future_sql = "UPDATE emi_schedule
                                              SET principal_amount = ?, interest_amount = ?, emi_amount = ?
                                              WHERE id = ?";
                        $stmt3 = $conn->prepare($update_future_sql);
                        if (!$stmt3) {
                            throw new Exception("Failed to prepare future EMI update: " . $conn->error);
                        }
                        $stmt3->bind_param("dddi", $new_future_principal, $new_future_interest, $new_future_total, $future_emi_id);
                        if (!$stmt3->execute()) {
                            throw new Exception("Failed to update future EMI #$future_emi_id: " . $stmt3->error);
                        }
                        $stmt3->close();

                        $remaining_excess -= $reduction_amount;

                        $reduction_details[] = [
                            'emi_id' => $future_emi_id,
                            'reduction' => $reduction_amount,
                            'original' => floatval($future_emi['principal_amount']) + floatval($future_emi['interest_amount']),
                            'new' => $new_future_total
                        ];
                    }
                }
                $stmt->close();
            }

            // -------------------------
            // total_balance
            // -------------------------
            $total_paid_for_balance = $custom_amount + $overdue_input;
            if ($total_paid_for_balance > 0) {
                $last_balance = 0;
                $bal_stmt = $conn->prepare("SELECT balance FROM total_balance WHERE finance_id = ? ORDER BY id DESC LIMIT 1");
                if ($bal_stmt) {
                    $bal_stmt->bind_param("i", $finance_id);
                    $bal_stmt->execute();
                    $bal_res = $bal_stmt->get_result();
                    if ($bal_row = $bal_res->fetch_assoc()) {
                        $last_balance = floatval($bal_row['balance']);
                    }
                    $bal_stmt->close();
                }

                $new_balance = $last_balance + $total_paid_for_balance;

                $balance_sql = "INSERT INTO total_balance
                                (transaction_type, amount, balance, description, transaction_date, finance_id)
                                VALUES ('emi_paid', ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($balance_sql);
                if (!$stmt) {
                    throw new Exception("Balance prepare failed: " . $conn->error);
                }

                $description = "EMI Payment - Bill: $bill_number (Customer: {$emi['customer_name']})";
                $stmt->bind_param("ddssi", $total_paid_for_balance, $new_balance, $description, $paid_date, $finance_id);

                if (!$stmt->execute()) {
                    throw new Exception("Balance update failed: " . $stmt->error);
                }
                $stmt->close();
            }

            $conn->commit();

            $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://") . $_SERVER['HTTP_HOST'];
            $receipt_url = $base_url . "/bala-finance/receipt.php?emi_id=$emi_id&customer_id=$customer_id";
            $whatsapp_url = "https://wa.me/" . preg_replace('/\D/', '', $customer_number) . "?text=" . urlencode(
                "BALA FINANCE - Payment Receipt\nBill: $bill_number\nAmount: ₹" . number_format($total_paid_for_balance, 2) .
                "\nCustomer: {$emi['customer_name']}\nDownload: $receipt_url"
            );

            $success_message = "<strong>✅ Payment Successful!</strong><br>";
            $success_message .= "Bill Number: <strong>$bill_number</strong><br>";
            $success_message .= "Payment Type: <strong>" . ucfirst($pay_type) . "</strong><br>";
            $success_message .= "Amount Paid: <strong>₹" . number_format($total_paid_for_balance, 2) . "</strong><br>";

            if ($amount_to_current_emi > 0) {
                $success_message .= "Applied to EMI #" . intval($emi['emi_number']) . ": <strong>₹" . number_format($amount_to_current_emi, 2) . "</strong><br>";
                $success_message .= "&nbsp;&nbsp; Principal: ₹" . number_format($principal_paid_now, 2) . "<br>";
                $success_message .= "&nbsp;&nbsp; Interest: ₹" . number_format($interest_paid_now, 2) . "<br>";
            }

            if ($overdue_input > 0) {
                $success_message .= "Manual Overdue Paid: <strong>₹" . number_format($overdue_input, 2) . "</strong><br>";
            }

            if ($excess_amount > 0) {
                $success_message .= "Excess Payment: <strong>₹" . number_format($excess_amount, 2) . "</strong><br>";

                if (!empty($reduction_details)) {
                    $success_message .= "<strong>Excess Applied to Reduce Future EMIs (Last First):</strong><br>";
                    foreach ($reduction_details as $detail) {
                        $success_message .= "- EMI #{$detail['emi_id']}: Reduced by ₹" .
                                            number_format($detail['reduction'], 2) .
                                            " (₹" . number_format($detail['original'], 2) .
                                            " → ₹" . number_format($detail['new'], 2) . ")<br>";
                    }
                }

                if ($remaining_excess > 0) {
                    $success_message .= "Remaining excess: ₹" . number_format($remaining_excess, 2) . " stored as available credit<br>";
                }
            }

            $success_message .= "<div class='mt-3'>";
            $success_message .= "<a href='$whatsapp_url' target='_blank' class='btn btn-success btn-sm'>";
            $success_message .= "<i class='bi bi-whatsapp me-1'></i> Share Receipt via WhatsApp";
            $success_message .= "</a>";
            $success_message .= "</div>";

            $_SESSION['success'] = $success_message;
            header("Location: emi-schedule.php?customer_id=$customer_id");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
            error_log("Pay EMI error: " . $e->getMessage());
            $_SESSION['error'] = implode(" ", $errors);
            header("Location: pay-emi.php?emi_id=$emi_id&customer_id=$customer_id");
            exit;
        }
    } else {
        $_SESSION['error'] = implode(" ", $errors);
        header("Location: pay-emi.php?emi_id=$emi_id&customer_id=$customer_id");
        exit;
    }
}

$due_date_display = 'Not set';
if (!empty($emi['emi_due_date']) && $emi['emi_due_date'] != '0000-00-00') {
    $due_date_display = date('d-m-Y', strtotime($emi['emi_due_date']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($emi['customer_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/styles.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --primary-bg: #e6f0ff;
        }

        body { background-color: #f5f7fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .app-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; margin-left: 280px; transition: margin-left 0.3s ease; }
        .page-content { padding: 2rem; }

        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white; padding: 1.5rem 2rem; border-radius: 12px; margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .page-header h4 { font-weight: 700; margin-bottom: 0.5rem; }

        .page-header .btn-light {
            background: white; color: var(--primary); border: none;
            padding: 0.5rem 1.5rem; font-weight: 600; border-radius: 30px;
        }

        .payment-card, .info-card {
            background: white; border: 1px solid #e9ecef; border-radius: 12px;
            padding: 2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .info-card { margin-bottom: 1.5rem; padding: 1.5rem; }

        .info-row {
            display: flex; justify-content: space-between; padding: 0.75rem 0;
            border-bottom: 1px dashed #e9ecef;
        }

        .info-row:last-child { border-bottom: none; }

        .info-label { color: #7f8c8d; font-weight: 500; }
        .info-value { font-weight: 600; color: #2c3e50; }
        .info-value.principal { color: var(--primary); }
        .info-value.interest { color: var(--warning); }
        .info-value.overdue { color: var(--danger); }

        .payment-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;
        }

        .summary-item { text-align: center; }
        .summary-label { font-size: 0.9rem; opacity: 0.9; margin-bottom: 0.5rem; }
        .summary-value { font-size: 1.5rem; font-weight: 700; }

        .btn-primary, .btn-success {
            border: none; padding: 0.75rem 2rem; font-weight: 600; border-radius: 8px;
        }

        .btn-primary { background: var(--primary); }
        .btn-success { background: var(--success); }

        .payment-type-card {
            border: 2px solid #e9ecef; border-radius: 12px; padding: 1.5rem;
            text-align: center; cursor: pointer; transition: all 0.2s ease; height: 100%;
        }

        .payment-type-card:hover { border-color: var(--primary); transform: translateY(-2px); }
        .payment-type-card.selected { border-color: var(--primary); background: var(--primary-bg); }

        .payment-type-icon { font-size: 2rem; margin-bottom: 0.5rem; color: var(--primary); }
        .payment-type-title { font-weight: 600; margin-bottom: 0.25rem; }
        .payment-type-amount { font-size: 1.1rem; font-weight: 700; color: var(--primary); }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .page-content { padding: 1rem; }
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
                        <h4><i class="bi bi-cash me-2"></i>Pay EMI</h4>
                        <p><?php echo htmlspecialchars($emi['customer_name']); ?> - Agreement: <?php echo htmlspecialchars($emi['agreement_number']); ?></p>
                    </div>
                    <div>
                        <a href="emi-schedule.php?customer_id=<?php echo (int)$customer_id; ?>" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Schedule
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="payment-summary">
                <div class="row">
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">EMI #</div>
                            <div class="summary-value"><?php echo (int)$emi['emi_number']; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Due Date</div>
                            <div class="summary-value"><?php echo $due_date_display; ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Remaining</div>
                            <div class="summary-value">₹<?php echo number_format($remaining_total, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-item">
                            <div class="summary-label">Available Excess</div>
                            <div class="summary-value">₹<?php echo number_format($available_excess, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <h5 class="mb-3">EMI Details</h5>
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row">
                            <span class="info-label">Principal Amount:</span>
                            <span class="info-value principal">₹<?php echo number_format((float)$emi['principal_amount'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Principal Paid:</span>
                            <span class="info-value">₹<?php echo number_format((float)$emi['principal_paid'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Remaining Principal:</span>
                            <span class="info-value principal">₹<?php echo number_format($remaining_principal, 2); ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row">
                            <span class="info-label">Interest Amount:</span>
                            <span class="info-value interest">₹<?php echo number_format((float)$emi['interest_amount'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Interest Paid:</span>
                            <span class="info-value">₹<?php echo number_format((float)$emi['interest_paid'], 2); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Remaining Interest:</span>
                            <span class="info-value interest">₹<?php echo number_format($remaining_interest, 2); ?></span>
                        </div>
                    </div>
                    <?php if ($pending_overdue > 0): ?>
                    <div class="col-12">
                        <div class="info-row">
                            <span class="info-label">Pending Overdue:</span>
                            <span class="info-value overdue">₹<?php echo number_format($pending_overdue, 2); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <div class="info-row">
                            <span class="info-label">Collection Type:</span>
                            <span class="info-value">
                                <?php echo ucfirst(htmlspecialchars($emi['collection_type'])); ?>
                                <?php if ($emi['collection_type'] === 'weekly' && !empty($emi['week_number'])): ?>
                                    (Week <?php echo (int)$emi['week_number']; ?>)
                                <?php elseif ($emi['collection_type'] === 'daily' && !empty($emi['day_number'])): ?>
                                    (Day <?php echo (int)$emi['day_number']; ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="payment-card">
                <form method="POST" action="" id="paymentForm">
                    <input type="hidden" name="emi_id" value="<?php echo (int)$emi_id; ?>">
                    <input type="hidden" name="customer_id" value="<?php echo (int)$customer_id; ?>">

                    <div class="mb-4">
                        <label class="form-label fw-bold fs-5">Select Payment Type</label>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="payment-type-card <?php echo (($_POST['pay_type'] ?? 'full') === 'full') ? 'selected' : ''; ?>" data-type="full">
                                    <div class="payment-type-icon"><i class="bi bi-cash-stack"></i></div>
                                    <div class="payment-type-title">Full EMI</div>
                                    <div class="payment-type-amount">₹<?php echo number_format($remaining_total, 2); ?></div>
                                    <small class="text-muted">Pay complete EMI</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="payment-type-card <?php echo (($_POST['pay_type'] ?? '') === 'principal') ? 'selected' : ''; ?>" data-type="principal">
                                    <div class="payment-type-icon"><i class="bi bi-bank"></i></div>
                                    <div class="payment-type-title">Principal Only</div>
                                    <div class="payment-type-amount">₹<?php echo number_format($remaining_principal, 2); ?></div>
                                    <small class="text-muted">Pay only principal</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="payment-type-card <?php echo (($_POST['pay_type'] ?? '') === 'interest') ? 'selected' : ''; ?>" data-type="interest">
                                    <div class="payment-type-icon"><i class="bi bi-percent"></i></div>
                                    <div class="payment-type-title">Interest Only</div>
                                    <div class="payment-type-amount">₹<?php echo number_format($remaining_interest, 2); ?></div>
                                    <small class="text-muted">Pay only interest</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="payment-type-card <?php echo (($_POST['pay_type'] ?? '') === 'custom') ? 'selected' : ''; ?>" data-type="custom">
                                    <div class="payment-type-icon"><i class="bi bi-pencil-square"></i></div>
                                    <div class="payment-type-title">Custom Amount</div>
                                    <div class="payment-type-amount">Any Amount</div>
                                    <small class="text-muted">Enter your own amount</small>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="pay_type" id="pay_type" value="<?php echo htmlspecialchars($_POST['pay_type'] ?? 'full'); ?>">
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount to Pay (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="custom_amount" id="customAmount"
                                   value="<?php echo htmlspecialchars($_POST['custom_amount'] ?? $remaining_total); ?>"
                                   min="0.01" required>
                            <small class="text-muted" id="amountHelp">Enter the amount to pay</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Manual Overdue (₹) <small class="text-muted">(Optional)</small></label>
                            <input type="number" step="0.01" min="0" class="form-control" name="overdue_charges" id="overdueCharges" value="<?php echo htmlspecialchars($_POST['overdue_charges'] ?? 0); ?>">
                            <small class="text-muted">
                                Current overdue: ₹<?php echo number_format($total_overdue, 2); ?> |
                                Paid: ₹<?php echo number_format($paid_overdue, 2); ?>
                            </small>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Bill Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="emi_bill_number" value="<?php echo htmlspecialchars($_POST['emi_bill_number'] ?? ''); ?>" required placeholder="Enter bill number">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash" <?php echo (($_POST['payment_method'] ?? 'cash') === 'cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo (($_POST['payment_method'] ?? '') === 'bank_transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="cheque" <?php echo (($_POST['payment_method'] ?? '') === 'cheque') ? 'selected' : ''; ?>>Cheque</option>
                                <option value="online" <?php echo (($_POST['payment_method'] ?? '') === 'online') ? 'selected' : ''; ?>>Online Payment</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Paid Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="paid_date" value="<?php echo htmlspecialchars($_POST['paid_date'] ?? date('Y-m-d')); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Customer Number</label>
                            <input type="text" class="form-control" name="customer_number" value="<?php echo htmlspecialchars($_POST['customer_number'] ?? $emi['customer_number']); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes"><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6 class="alert-heading"><i class="bi bi-calculator me-2"></i>Payment Summary</h6>
                        <div class="row">
                            <div class="col-md-4"><strong>Payment Type:</strong> <span id="summaryType">Full EMI</span></div>
                            <div class="col-md-4"><strong>Amount:</strong> <span id="summaryAmount">₹<?php echo number_format($remaining_total, 2); ?></span></div>
                            <div class="col-md-4"><strong>Overdue:</strong> <span id="summaryOverdue">₹0.00</span></div>
                            <div class="col-md-4"><strong>Total Payment:</strong> <span id="summaryTotal">₹<?php echo number_format($remaining_total, 2); ?></span></div>
                            <div class="col-md-4"><strong>Applied to EMI #<?php echo (int)$emi['emi_number']; ?>:</strong> <span id="summaryApplied">₹<?php echo number_format($remaining_total, 2); ?></span></div>
                            <div class="col-md-4"><strong>Excess:</strong> <span id="summaryExcess">₹0.00</span></div>
                        </div>
                        <div id="excessNote" class="mt-2 d-none">
                            <hr>
                            <div class="text-warning">
                                <i class="bi bi-info-circle"></i>
                                <strong>Excess amount will reduce future EMIs starting from the LAST EMI</strong>
                                <?php if ($total_unpaid_emis > 0): ?>
                                    <br><small>(Total <?php echo $total_unpaid_emis; ?> unpaid EMI<?php echo $total_unpaid_emis > 1 ? 's' : ''; ?> after this)</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="emi-schedule.php?customer_id=<?php echo (int)$customer_id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>Process Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script>
function selectPaymentType(type) {
    document.getElementById('pay_type').value = type;

    document.querySelectorAll('.payment-type-card').forEach(card => {
        card.classList.remove('selected');
        if (card.getAttribute('data-type') === type) {
            card.classList.add('selected');
        }
    });

    const remainingTotal = <?php echo json_encode($remaining_total); ?>;
    const remainingPrincipal = <?php echo json_encode($remaining_principal); ?>;
    const remainingInterest = <?php echo json_encode($remaining_interest); ?>;

    const customAmount = document.getElementById('customAmount');
    const amountHelp = document.getElementById('amountHelp');

    if (type === 'full') {
        customAmount.value = Number(remainingTotal).toFixed(2);
        customAmount.max = remainingTotal;
        amountHelp.textContent = `Full EMI amount: ₹${Number(remainingTotal).toFixed(2)}`;
    } else if (type === 'principal') {
        customAmount.value = Number(remainingPrincipal).toFixed(2);
        customAmount.max = remainingPrincipal;
        amountHelp.textContent = `Principal only - Max: ₹${Number(remainingPrincipal).toFixed(2)}`;
    } else if (type === 'interest') {
        customAmount.value = Number(remainingInterest).toFixed(2);
        customAmount.max = remainingInterest;
        amountHelp.textContent = `Interest only - Max: ₹${Number(remainingInterest).toFixed(2)}`;
    } else {
        customAmount.removeAttribute('max');
        amountHelp.textContent = 'Enter any amount. Excess will reduce future EMIs';
    }

    updateSummary();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.payment-type-card').forEach(card => {
        card.addEventListener('click', function() {
            selectPaymentType(this.getAttribute('data-type'));
        });
    });

    const payType = document.getElementById('pay_type');
    const customAmount = document.getElementById('customAmount');
    const overdueCharges = document.getElementById('overdueCharges');

    const summaryType = document.getElementById('summaryType');
    const summaryAmount = document.getElementById('summaryAmount');
    const summaryApplied = document.getElementById('summaryApplied');
    const summaryOverdue = document.getElementById('summaryOverdue');
    const summaryTotal = document.getElementById('summaryTotal');
    const summaryExcess = document.getElementById('summaryExcess');
    const excessNote = document.getElementById('excessNote');

    const remainingTotal = <?php echo json_encode($remaining_total); ?>;
    const remainingPrincipal = <?php echo json_encode($remaining_principal); ?>;
    const remainingInterest = <?php echo json_encode($remaining_interest); ?>;

    const paymentTypeNames = {
        full: 'Full EMI',
        principal: 'Principal Only',
        interest: 'Interest Only',
        custom: 'Custom Amount'
    };

    window.updateSummary = function() {
        const type = payType.value;
        let amount = parseFloat(customAmount.value) || 0;
        const overdue = parseFloat(overdueCharges.value) || 0;

        if (type === 'principal') {
            amount = Math.min(amount, remainingPrincipal);
            customAmount.value = amount.toFixed(2);
        } else if (type === 'interest') {
            amount = Math.min(amount, remainingInterest);
            customAmount.value = amount.toFixed(2);
        } else if (type === 'full') {
            amount = remainingTotal;
            customAmount.value = amount.toFixed(2);
        }

        const appliedAmount = Math.min(amount, remainingTotal);
        const excessAmount = Math.max(0, amount - remainingTotal);

        summaryType.textContent = paymentTypeNames[type] || 'Custom Amount';
        summaryAmount.textContent = '₹' + amount.toFixed(2);
        summaryOverdue.textContent = '₹' + overdue.toFixed(2);
        summaryTotal.textContent = '₹' + (amount + overdue).toFixed(2);
        summaryApplied.textContent = '₹' + appliedAmount.toFixed(2);
        summaryExcess.textContent = '₹' + excessAmount.toFixed(2);

        if (excessAmount > 0) {
            excessNote.classList.remove('d-none');
        } else {
            excessNote.classList.add('d-none');
        }
    };

    customAmount.addEventListener('input', updateSummary);
    overdueCharges.addEventListener('input', updateSummary);

    updateSummary();
});
</script>
</body>
</html>