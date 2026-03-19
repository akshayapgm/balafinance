<?php
// undo-payment.php - Complete Payment Reversal System
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/home/u329947844/domains/hifi11.in/public_html/finance/error_log');

session_start();
include 'includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login first.";
    header("Location: login.php");
    exit;
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header("Location: manage-customers.php");
    exit;
}

// Get form data
$emi_id = isset($_POST['emi_id']) ? intval($_POST['emi_id']) : 0;
$customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
$undo_remark = isset($_POST['undo_remark']) ? trim($_POST['undo_remark']) : '';

// Validate inputs
if ($emi_id <= 0 || $customer_id <= 0) {
    $_SESSION['error'] = "Invalid EMI or Customer ID.";
    header("Location: manage-customers.php");
    exit;
}

if (empty($undo_remark)) {
    $_SESSION['error'] = "Please provide a reason for undoing the payment.";
    header("Location: emi-schedule.php?customer_id=$customer_id");
    exit;
}

// Validate finance_id from session
$finance_id = isset($_SESSION['finance_id']) ? intval($_SESSION['finance_id']) : 0;
if ($finance_id <= 0) {
    $_SESSION['error'] = "No finance ID selected in session.";
    header("Location: manage-customers.php");
    exit;
}

// Begin transaction
$conn->begin_transaction();

try {
    // Fetch EMI details before undo
    $sql_emi = "SELECT es.*, c.customer_name, c.agreement_number, c.finance_id
                FROM emi_schedule es 
                JOIN customers c ON es.customer_id = c.id 
                WHERE es.id = ? AND es.customer_id = ?";
    $stmt_emi = $conn->prepare($sql_emi);
    $stmt_emi->bind_param("ii", $emi_id, $customer_id);
    $stmt_emi->execute();
    $result_emi = $stmt_emi->get_result();
    $emi = $result_emi->fetch_assoc();
    $stmt_emi->close();

    if (!$emi) {
        throw new Exception("EMI not found or access denied.");
    }

    // Check if user has permission
    $user_role = $_SESSION['role'] ?? 'user';
    if ($user_role != 'admin' && $emi['finance_id'] != $finance_id) {
        throw new Exception("You don't have permission to undo this payment.");
    }

    // Check if there's any payment to undo
    $principal_paid_before = $emi['principal_paid'] ?? 0;
    $interest_paid_before = $emi['interest_paid'] ?? 0;
    $overdue_paid_before = $emi['overdue_paid'] ?? 0;
    $total_overdue = $emi['overdue_charges'] ?? 0;
    $total_paid_before = $principal_paid_before + $interest_paid_before + $overdue_paid_before;
    
    if ($total_paid_before <= 0) {
        throw new Exception("No payment found to undo for this EMI.");
    }

    // Store payment details
    $bill_number_before = $emi['emi_bill_number'] ?? '';
    $paid_date_before = $emi['paid_date'] ?? '';
    $status_before = $emi['status'] ?? '';

    // Check if this EMI was part of a foreclosure
    if ($emi['foreclosure_id']) {
        throw new Exception("Cannot undo payment from foreclosure.");
    }

    // STEP 1: Get and store reduction details BEFORE deleting them
    $reduction_details = [];
    if (!empty($bill_number_before)) {
        // Get reduction details first
        $get_reductions_sql = "SELECT * FROM emi_reductions 
                              WHERE customer_id = ? AND bill_number = ?";
        $stmt_get = $conn->prepare($get_reductions_sql);
        $stmt_get->bind_param("is", $customer_id, $bill_number_before);
        $stmt_get->execute();
        $result_get = $stmt_get->get_result();
        
        while ($reduction = $result_get->fetch_assoc()) {
            $reduction_details[] = $reduction;
        }
        $stmt_get->close();
    }

    // STEP 2: Delete excess payments created by this payment
    $deleted_excess = 0;
    if (!empty($bill_number_before)) {
        // Delete from excess_payments table
        $delete_excess_sql = "DELETE FROM excess_payments 
                             WHERE customer_id = ? AND bill_number = ?";
        $stmt_delete = $conn->prepare($delete_excess_sql);
        $stmt_delete->bind_param("is", $customer_id, $bill_number_before);
        
        if ($stmt_delete->execute()) {
            $deleted_excess = $stmt_delete->affected_rows;
            error_log("Deleted $deleted_excess excess payment(s) for bill $bill_number_before");
        } else {
            error_log("Error deleting excess payments: " . $stmt_delete->error);
        }
        $stmt_delete->close();
    }

    // STEP 3: Restore EMI amounts BEFORE deleting reductions
    $restored_emis = [];
    foreach ($reduction_details as $reduction) {
        $reduction_emi_id = $reduction['emi_id'];
        
        // Calculate original totals
        $original_principal = $reduction['original_principal'];
        $original_interest = $reduction['original_interest'];
        $original_total = $original_principal + $original_interest;
        
        // Current reduced amounts
        $current_principal = $reduction['new_principal'];
        $current_interest = $reduction['new_interest'];
        $current_total = $current_principal + $current_interest;
        
        // Restore EMI to original amount
        $restore_sql = "UPDATE emi_schedule 
                       SET principal_amount = ?,
                           interest_amount = ?,
                           emi_amount = ?
                       WHERE id = ? AND customer_id = ?";
        $stmt_restore = $conn->prepare($restore_sql);
        $stmt_restore->bind_param("ddiii", 
            $original_principal,
            $original_interest,
            $original_total,
            $reduction_emi_id,
            $customer_id
        );
        
        if ($stmt_restore->execute()) {
            $affected = $stmt_restore->affected_rows;
            if ($affected > 0) {
                $restored_emis[] = [
                    'emi_id' => $reduction_emi_id,
                    'original_total' => $original_total,
                    'current_total' => $current_total,
                    'reduction' => $reduction['reduction_amount']
                ];
                error_log("Restored EMI #$reduction_emi_id from ₹$current_total to ₹$original_total");
            }
        } else {
            error_log("Error restoring EMI #$reduction_emi_id: " . $stmt_restore->error);
        }
        $stmt_restore->close();
    }

    // STEP 4: Delete emi_reductions after restoring amounts
    $deleted_reductions = 0;
    if (!empty($bill_number_before)) {
        // Delete from emi_reductions table
        $delete_reduction_sql = "DELETE FROM emi_reductions 
                                WHERE customer_id = ? AND bill_number = ?";
        $stmt_delete = $conn->prepare($delete_reduction_sql);
        $stmt_delete->bind_param("is", $customer_id, $bill_number_before);
        
        if ($stmt_delete->execute()) {
            $deleted_reductions = $stmt_delete->affected_rows;
            error_log("Deleted $deleted_reductions reduction(s) for bill $bill_number_before");
        } else {
            error_log("Error deleting reductions: " . $stmt_delete->error);
        }
        $stmt_delete->close();
    }

    // STEP 5: Reset the EMI payment
    $update_sql = "UPDATE emi_schedule 
                   SET status = 'unpaid',
                       paid_date = NULL,
                       overdue_paid = 0,
                       emi_bill_number = NULL,
                       payment_method = NULL,
                       principal_paid = 0,
                       interest_paid = 0,
                       remarks = CONCAT(IFNULL(remarks, ''), ' | Undone on ', DATE_FORMAT(NOW(), '%d-%m-%Y'), ' by ', ?),
                       updated_at = NOW()
                   WHERE id = ? AND customer_id = ?";
    $stmt_update = $conn->prepare($update_sql);
    $undo_text = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
    $stmt_update->bind_param("sii", $undo_text, $emi_id, $customer_id);
    
    if (!$stmt_update->execute()) {
        throw new Exception("Error updating EMI: " . $stmt_update->error);
    }
    
    if ($stmt_update->affected_rows === 0) {
        throw new Exception("EMI not updated.");
    }
    $stmt_update->close();

    // STEP 6: Update total_balance table (reverse the payment)
    $desc = "Payment Undone - Bill: " . ($bill_number_before ?: 'N/A') . 
            " - Customer: " . $emi['customer_name'] . 
            " - Reason: " . $undo_remark;
    
    $balance_sql = "INSERT INTO total_balance 
                    (transaction_type, amount, balance, description, transaction_date, finance_id)
                    VALUES ('emi_undo', ?, 
                    COALESCE((SELECT balance FROM total_balance WHERE finance_id = ? ORDER BY id DESC LIMIT 1), 0) - ?,
                    ?, NOW(), ?)";
    $stmt_balance = $conn->prepare($balance_sql);
    $negative_amount = -$total_paid_before;
    $stmt_balance->bind_param("dddsi", 
        $negative_amount, 
        $finance_id, 
        $total_paid_before,
        $desc,
        $finance_id
    );
    
    if (!$stmt_balance->execute()) {
        error_log("Could not update total_balance: " . $stmt_balance->error);
    }
    $stmt_balance->close();

    // STEP 7: Log the undo action
    $conn->query("CREATE TABLE IF NOT EXISTS emi_undo_logs (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        emi_id INT(11) NOT NULL,
        customer_id INT(11) NOT NULL,
        bill_number VARCHAR(50),
        amount DECIMAL(15,2) NOT NULL,
        details TEXT,
        remark TEXT NOT NULL,
        undone_by INT(11),
        undone_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_emi (emi_id),
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $log_details = "Undone payment of ₹" . number_format($total_paid_before, 2) . 
                  " | Bill: " . ($bill_number_before ?: 'N/A') . 
                  " | Principal: ₹" . number_format($principal_paid_before, 2) .
                  " | Interest: ₹" . number_format($interest_paid_before, 2) .
                  " | Overdue Paid: ₹" . number_format($overdue_paid_before, 2) .
                  " | Deleted excess: " . $deleted_excess .
                  " | Deleted reductions: " . $deleted_reductions .
                  " | Restored " . count($restored_emis) . " EMI(s)";

    $log_sql = "INSERT INTO emi_undo_logs 
                (emi_id, customer_id, bill_number, amount, details, remark, undone_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt_log = $conn->prepare($log_sql);

    if ($stmt_log) {
        $stmt_log->bind_param("iisdssi", 
            $emi_id, 
            $customer_id, 
            $bill_number_before,
            $total_paid_before,
            $log_details,
            $undo_remark,
            $_SESSION['user_id']
        );
        
        if (!$stmt_log->execute()) {
            error_log("Could not log undo action: " . $stmt_log->error);
        }
        $stmt_log->close();
    }

    // STEP 8: Add remark to customer_remarks table
    $conn->query("CREATE TABLE IF NOT EXISTS customer_remarks (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT(11) NOT NULL,
        finance_id INT(11) NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        remark_date DATE NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id),
        INDEX idx_finance (finance_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $remark_note = "Payment undone: Bill " . ($bill_number_before ?: 'N/A') . 
                  ", Amount: ₹" . number_format($total_paid_before, 2) .
                  " (P:₹" . number_format($principal_paid_before, 2) .
                  " + I:₹" . number_format($interest_paid_before, 2) .
                  " + O:₹" . number_format($overdue_paid_before, 2) .
                  ") - Reason: " . $undo_remark;
    
    $remark_sql = "INSERT INTO customer_remarks (customer_id, finance_id, amount, remark_date, note)
                   VALUES (?, ?, ?, CURDATE(), ?)";
    $stmt_remark = $conn->prepare($remark_sql);
    $stmt_remark->bind_param("iids", 
        $customer_id, 
        $finance_id, 
        $total_paid_before,
        $remark_note
    );
    
    $stmt_remark->execute();
    $stmt_remark->close();

    // Commit transaction
    $conn->commit();

    // Prepare success message
    $success_message = "✅ Payment Successfully Undone<br><br>";
    $success_message .= "<strong>Customer:</strong> " . htmlspecialchars($emi['customer_name']) . "<br>";
    $success_message .= "<strong>Agreement:</strong> " . htmlspecialchars($emi['agreement_number']) . "<br>";
    $success_message .= "<strong>Bill No:</strong> " . ($bill_number_before ?: 'Not set') . "<br>";
    $success_message .= "<strong>Paid Date:</strong> " . ($paid_date_before ? date('d-m-Y', strtotime($paid_date_before)) : 'Not set') . "<br><br>";
    
    $success_message .= "<strong>Amounts Reset:</strong><br>";
    $success_message .= "Principal Paid: ₹" . number_format($principal_paid_before, 2) . "<br>";
    $success_message .= "Interest Paid: ₹" . number_format($interest_paid_before, 2) . "<br>";
    $success_message .= "Overdue Paid: ₹" . number_format($overdue_paid_before, 2) . "<br>";
    $success_message .= "<strong>Total Paid: ₹" . number_format($total_paid_before, 2) . "</strong><br><br>";
    
    if ($deleted_excess > 0) {
        $success_message .= "Deleted $deleted_excess excess payment(s)<br>";
    }
    
    if (!empty($restored_emis)) {
        $success_message .= "Restored " . count($restored_emis) . " EMI(s) to original amounts<br>";
    }
    
    $success_message .= "<br><strong>Reason:</strong> " . htmlspecialchars($undo_remark);

    $_SESSION['success'] = $success_message;

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error: " . $e->getMessage();
    error_log("Undo payment error: " . $e->getMessage());
}

header("Location: emi-schedule.php?customer_id=$customer_id");
exit;
?>