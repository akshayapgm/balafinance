<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once 'includes/auth.php';
require_once 'includes/db.php';

$currentPage = 'emi-schedule';
$pageTitle = 'EMI Schedule';

$user_role = $_SESSION['role'] ?? 'user';
$session_finance_id = intval($_SESSION['finance_id'] ?? 0);
$customer_id = intval($_GET['customer_id'] ?? 0);

if ($customer_id <= 0) {
    $_SESSION['error'] = "Invalid customer ID.";
    header("Location: manage-customers.php");
    exit;
}

// -------------------------
// Get customer
// -------------------------
$check_sql = "SELECT c.*, f.finance_name
              FROM customers c
              LEFT JOIN finance f ON c.finance_id = f.id
              WHERE c.id = ?";
$check_stmt = $conn->prepare($check_sql);

if (!$check_stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error'] = "Database error occurred.";
    header("Location: manage-customers.php");
    exit;
}

$check_stmt->bind_param("i", $customer_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    $_SESSION['error'] = "Customer not found.";
    header("Location: manage-customers.php");
    exit;
}

$customer = $check_result->fetch_assoc();
$check_stmt->close();

if ($user_role !== 'admin' && intval($customer['finance_id']) !== $session_finance_id) {
    $_SESSION['error'] = "You don't have permission to view this customer.";
    header("Location: manage-customers.php");
    exit;
}

// -------------------------
// EMI Schedule Query
// -------------------------
if ($user_role === 'admin') {
    $sql = "SELECT
                es.id AS emi_id,
                es.emi_amount,
                es.principal_amount,
                es.interest_amount,
                es.emi_due_date,
                es.status,
                es.overdue_charges,
                es.overdue_paid,
                es.emi_bill_number,
                es.paid_date,
                es.payment_method,
                COALESCE(es.principal_paid, 0) AS principal_paid,
                COALESCE(es.interest_paid, 0) AS interest_paid,
                es.foreclosure_id,
                es.week_number,
                es.day_number,
                es.collection_type,
                f.principal_amount AS foreclosure_principal,
                f.interest_amount AS foreclosure_interest,
                f.overdue_charges AS foreclosure_overdue,
                f.foreclosure_charge AS foreclosure_fee,
                f.total_amount AS foreclosure_total,
                f.bill_number AS foreclosure_bill_number,
                f.payment_date AS foreclosure_date,
                f.remarks AS foreclosure_remarks
            FROM emi_schedule es
            LEFT JOIN foreclosures f ON es.foreclosure_id = f.id
            WHERE es.customer_id = ?
            ORDER BY es.emi_due_date ASC, es.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error'] = "Database error occurred.";
        header("Location: manage-customers.php");
        exit;
    }
    $stmt->bind_param("i", $customer_id);
} else {
    $sql = "SELECT
                es.id AS emi_id,
                es.emi_amount,
                es.principal_amount,
                es.interest_amount,
                es.emi_due_date,
                es.status,
                es.overdue_charges,
                es.overdue_paid,
                es.emi_bill_number,
                es.paid_date,
                es.payment_method,
                COALESCE(es.principal_paid, 0) AS principal_paid,
                COALESCE(es.interest_paid, 0) AS interest_paid,
                es.foreclosure_id,
                es.week_number,
                es.day_number,
                es.collection_type,
                f.principal_amount AS foreclosure_principal,
                f.interest_amount AS foreclosure_interest,
                f.overdue_charges AS foreclosure_overdue,
                f.foreclosure_charge AS foreclosure_fee,
                f.total_amount AS foreclosure_total,
                f.bill_number AS foreclosure_bill_number,
                f.payment_date AS foreclosure_date,
                f.remarks AS foreclosure_remarks
            FROM emi_schedule es
            LEFT JOIN foreclosures f ON es.foreclosure_id = f.id
            WHERE es.customer_id = ? AND es.finance_id = ?
            ORDER BY es.emi_due_date ASC, es.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error'] = "Database error occurred.";
        header("Location: manage-customers.php");
        exit;
    }
    $stmt->bind_param("ii", $customer_id, $session_finance_id);
}

$stmt->execute();
$res = $stmt->get_result();

$emis = [];
$total_collected = 0;
$total_paid_overdue = 0;
$unpaid_emis = [];
$total_pending_all = 0;

$total_principal_outstanding = 0;
$total_interest_outstanding = 0;
$total_overdue_outstanding = 0;
$total_overdue_collected = 0;
$total_principal_paid = 0;
$total_interest_paid = 0;

$pending_emi_count = 0;
$paid_emi_count = 0;
$partial_emi_count = 0;

// -------------------------
// Track foreclosures without duplication
// -------------------------
$foreclosure_data = [];
$has_foreclosed_emis = false;
$total_foreclosed_count = 0;
$total_foreclosed_amount = 0;

// -------------------------
// Remarks
// -------------------------
$total_remarks_amount = 0;
$remarks = [];

if ($user_role === 'admin') {
    $remarks_sql = "SELECT * FROM customer_remarks WHERE customer_id = ? ORDER BY remark_date DESC, id DESC";
    $stmt_remarks = $conn->prepare($remarks_sql);
    if ($stmt_remarks) {
        $stmt_remarks->bind_param("i", $customer_id);
    }
} else {
    $remarks_sql = "SELECT * FROM customer_remarks WHERE customer_id = ? AND finance_id = ? ORDER BY remark_date DESC, id DESC";
    $stmt_remarks = $conn->prepare($remarks_sql);
    if ($stmt_remarks) {
        $stmt_remarks->bind_param("ii", $customer_id, $session_finance_id);
    }
}

if (!empty($stmt_remarks)) {
    $stmt_remarks->execute();
    $remarks_result = $stmt_remarks->get_result();
    while ($remark_row = $remarks_result->fetch_assoc()) {
        $remarks[] = $remark_row;
        $total_remarks_amount += floatval($remark_row['amount']);
    }
    $stmt_remarks->close();
}

// -------------------------
// Excess Payments
// -------------------------
$excess_payments = [];
$total_excess_available = 0;

$excess_sql = "SELECT * FROM excess_payments WHERE customer_id = ? ORDER BY payment_date DESC, id DESC";
$stmt_excess = $conn->prepare($excess_sql);
if ($stmt_excess) {
    $stmt_excess->bind_param("i", $customer_id);
    $stmt_excess->execute();
    $excess_result = $stmt_excess->get_result();

    while ($excess = $excess_result->fetch_assoc()) {
        $excess_payments[] = $excess;
        if (($excess['status'] ?? '') === 'active') {
            $total_excess_available += floatval($excess['remaining_amount']);
        }
    }
    $stmt_excess->close();
}

// -------------------------
// Process EMI rows
// -------------------------
while ($row = $res->fetch_assoc()) {
    $total_overdue = floatval($row['overdue_charges'] ?? 0);
    $paid_overdue = floatval($row['overdue_paid'] ?? 0);
    $pending_overdue = max(0, $total_overdue - $paid_overdue);

    $paid_principal = floatval($row['principal_paid'] ?? 0);
    $paid_interest = floatval($row['interest_paid'] ?? 0);
    $paid = $paid_principal + $paid_interest + $paid_overdue;

    $row['paid_amount'] = $paid;
    $row['has_any_payment'] = $paid > 0;
    $row['is_fully_paid'] = (($row['status'] ?? '') === 'paid');

    $row['remaining_principal'] = max(0, floatval($row['principal_amount']) - $paid_principal);
    $row['remaining_interest'] = max(0, floatval($row['interest_amount']) - $paid_interest);
    $row['remaining_total'] = $row['remaining_principal'] + $row['remaining_interest'];

    $row['total_overdue'] = $total_overdue;
    $row['paid_overdue'] = $paid_overdue;
    $row['pending_overdue'] = $pending_overdue;

    if ($paid_overdue > 0) {
        $row['overdue_display'] = 'Paid: ₹' . number_format($paid_overdue, 2);
        $row['overdue_class'] = 'success';
    } elseif ($total_overdue > 0) {
        $row['overdue_display'] = '₹' . number_format($total_overdue, 2) . ' (Pending)';
        $row['overdue_class'] = 'danger';
    } else {
        $row['overdue_display'] = '-';
        $row['overdue_class'] = 'secondary';
    }

    $row['pending_this_emi'] = $row['remaining_total'];
    $row['calc_overdue'] = $pending_overdue;

    $row['is_foreclosed'] = !empty($row['foreclosure_id']);
    if ($row['is_foreclosed']) {
        $has_foreclosed_emis = true;
        $total_foreclosed_count++;
        
        if (!isset($foreclosure_data[$row['foreclosure_id']])) {
            $foreclosure_data[$row['foreclosure_id']] = [
                'principal' => $row['foreclosure_principal'],
                'interest' => $row['foreclosure_interest'],
                'overdue' => $row['foreclosure_overdue'],
                'fee' => $row['foreclosure_fee'],
                'total' => $row['foreclosure_total'],
                'bill_number' => $row['foreclosure_bill_number'],
                'date' => $row['foreclosure_date'],
                'remarks' => $row['foreclosure_remarks'],
                'count' => 0
            ];
            $total_foreclosed_amount += floatval($row['foreclosure_total'] ?? 0);
        }
        $foreclosure_data[$row['foreclosure_id']]['count']++;
    }

    $reduction_sql = "SELECT * FROM emi_reductions WHERE emi_id = ?";
    $stmt_reduction = $conn->prepare($reduction_sql);
    if ($stmt_reduction) {
        $stmt_reduction->bind_param("i", $row['emi_id']);
        $stmt_reduction->execute();
        $reductions_result = $stmt_reduction->get_result();
        $row['reductions'] = [];
        $row['total_reduction'] = 0;

        while ($reduction = $reductions_result->fetch_assoc()) {
            $row['reductions'][] = $reduction;
            $row['total_reduction'] += floatval($reduction['reduction_amount']);
        }
        $stmt_reduction->close();
    } else {
        $row['reductions'] = [];
        $row['total_reduction'] = 0;
    }

    $excess_with_this_emi = 0;
    $excess_query = "SELECT SUM(excess_amount) AS total_excess FROM excess_payments WHERE emi_id = ? AND customer_id = ?";
    $stmt_excess_emi = $conn->prepare($excess_query);
    if ($stmt_excess_emi) {
        $stmt_excess_emi->bind_param("ii", $row['emi_id'], $customer_id);
        $stmt_excess_emi->execute();
        $excess_result = $stmt_excess_emi->get_result();
        if ($excess_row = $excess_result->fetch_assoc()) {
            $excess_with_this_emi = floatval($excess_row['total_excess'] ?? 0);
        }
        $stmt_excess_emi->close();
    }

    $total_customer_payment = $paid + $excess_with_this_emi;
    $row['total_customer_payment'] = $total_customer_payment;
    $row['excess_with_this_emi'] = $excess_with_this_emi;

    $row['can_foreclose_select'] = (!$row['is_foreclosed'] && (($row['status'] ?? '') !== 'paid'));

    $emis[] = $row;

    $total_collected += $total_customer_payment;
    $total_principal_paid += $paid_principal;
    $total_interest_paid += $paid_interest;
    $total_overdue_collected += $paid_overdue;
    $total_paid_overdue += $paid_overdue;

    if (($row['status'] ?? '') === 'paid') {
        $paid_emi_count++;
    } elseif (($row['status'] ?? '') === 'partial') {
        $partial_emi_count++;
        $pending_emi_count++;
    } elseif (!$row['is_foreclosed']) {
        $pending_emi_count++;
    }

    if (($row['status'] ?? '') !== 'paid' && !$row['is_foreclosed']) {
        if ($row['remaining_total'] > 0) {
            $unpaid_emis[] = $row;
        }

        $total_pending_all += $row['pending_this_emi'] + $row['calc_overdue'];
        $total_principal_outstanding += $row['remaining_principal'];
        $total_interest_outstanding += $row['remaining_interest'];
        $total_overdue_outstanding += $row['calc_overdue'];
    }
}
$stmt->close();

// -------------------------
// Outstanding
// -------------------------
$total_out_principal = 0;
$total_out_interest = 0;
$total_out_emi = 0;
$total_overdue_pending = 0;
$unpaid_count = 0;

foreach ($emis as $emi) {
    if (($emi['status'] ?? '') === 'paid' || !empty($emi['is_foreclosed'])) {
        continue;
    }

    $unpaid_count++;
    $total_out_principal += $emi['remaining_principal'];
    $total_out_interest += $emi['remaining_interest'];
    $total_out_emi += $emi['remaining_total'];
    $total_overdue_pending += $emi['calc_overdue'];
}

// -------------------------
// Add Remark
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_remark'])) {
    $remark_amount = floatval($_POST['remark_amount'] ?? 0);
    $remark_date = $_POST['remark_date'] ?? date('Y-m-d');
    $remark_note = trim($_POST['remark_note'] ?? '');

    if ($remark_amount > 0 && $remark_note !== '') {
        $insert_sql = "INSERT INTO customer_remarks (customer_id, finance_id, amount, remark_date, note, created_at)
                       VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($insert_sql);

        if ($stmt) {
            $customer_finance_id = intval($customer['finance_id']);
            $stmt->bind_param("iidss", $customer_id, $customer_finance_id, $remark_amount, $remark_date, $remark_note);

            if ($stmt->execute()) {
                $_SESSION['success'] = "Remark/Note added successfully!";
                $stmt->close();
                header("Location: emi-schedule.php?customer_id=" . $customer_id);
                exit;
            } else {
                $_SESSION['error'] = "Failed to add remark: " . $stmt->error;
                $stmt->close();
            }
        } else {
            $_SESSION['error'] = "Database error preparing remark statement.";
        }
    } else {
        $_SESSION['error'] = "Please enter valid amount and remark note.";
    }
}

// -------------------------
// Delete Remark
// -------------------------
if (isset($_GET['delete_remark'])) {
    $remark_id = intval($_GET['delete_remark']);

    if ($user_role === 'admin') {
        $delete_sql = "DELETE FROM customer_remarks WHERE id = ? AND customer_id = ?";
        $stmt = $conn->prepare($delete_sql);
        if ($stmt) {
            $stmt->bind_param("ii", $remark_id, $customer_id);
        }
    } else {
        $delete_sql = "DELETE FROM customer_remarks WHERE id = ? AND customer_id = ? AND finance_id = ?";
        $stmt = $conn->prepare($delete_sql);
        if ($stmt) {
            $stmt->bind_param("iii", $remark_id, $customer_id, $session_finance_id);
        }
    }

    if (!empty($stmt)) {
        if ($stmt->execute()) {
            $_SESSION['success'] = "Remark deleted successfully!";
            $stmt->close();
            header("Location: emi-schedule.php?customer_id=" . $customer_id);
            exit;
        } else {
            $_SESSION['error'] = "Failed to delete remark: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $_SESSION['error'] = "Database error preparing delete statement.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($customer['customer_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            --monthly-color: #3b82f6;
            --weekly-color: #8b5cf6;
            --daily-color: #f59e0b;
            --foreclosed-color: #b91c1c;
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
        
        /* Customer Info Card */
        .customer-info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .customer-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .customer-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .customer-detail-item i {
            width: 20px;
            color: var(--primary);
            font-size: 0.9rem;
        }
        
        .loan-badge {
            background: var(--primary-bg);
            color: var(--primary);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }
        
        .badge-collection-type {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .badge-collection-type.monthly {
            background: #e3f2fd;
            color: var(--monthly-color);
        }
        
        .badge-collection-type.weekly {
            background: #f3e5f5;
            color: var(--weekly-color);
        }
        
        .badge-collection-type.daily {
            background: #fff3e0;
            color: var(--daily-color);
        }
        
        /* Stat Cards - Compact Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.75rem 0.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        
        .stat-value {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            white-space: nowrap;
        }
        
        .stat-icon {
            margin-bottom: 0.25rem;
            font-size: 1rem;
        }
        
        .stat-icon.primary { color: var(--primary); }
        .stat-icon.success { color: var(--success); }
        .stat-icon.warning { color: var(--warning); }
        .stat-icon.danger { color: var(--danger); }
        .stat-icon.info { color: var(--info); }
        .stat-icon.purple { color: #8b5cf6; }
        .stat-icon.foreclosed { color: var(--foreclosed-color); }
        
        /* Progress Bar */
        .progress-custom {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-custom .progress-bar {
            background: linear-gradient(90deg, var(--primary), var(--info));
            border-radius: 4px;
        }
        
        .stat-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.75rem;
            text-align: center;
        }
        
        .stat-box .label {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .stat-box .value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        /* Dashboard Card */
        .dashboard-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .dashboard-card .card-header {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--dark), #1a2634);
            color: white;
            border-bottom: none;
        }
        
        .dashboard-card .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
            font-size: 1rem;
        }
        
        .dashboard-card .card-header p {
            opacity: 0.8;
            margin-bottom: 0;
            font-size: 0.7rem;
            margin-top: 0.2rem;
        }
        
        .dashboard-card .card-body {
            padding: 1.25rem;
        }
        
        /* Table Container */
        .table-container {
            overflow-x: auto;
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1400px;
        }
        
        .table thead th {
            background: #f8fafc;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.7rem;
            border-bottom: 1px solid var(--border-color);
            padding: 0.6rem 1rem;
            white-space: nowrap;
            text-align: left;
            vertical-align: middle;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .table tbody td {
            padding: 0.6rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.8rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .table tbody tr.paid {
            background: #f0fdf4;
        }
        
        .table tbody tr.overdue {
            background: #fef2f2;
        }
        
        .table tbody tr.foreclosed {
            background: #fee2e2;
        }
        
        /* Amount Colors */
        .amount-primary { color: var(--primary); font-weight: 600; }
        .amount-success { color: var(--success); font-weight: 600; }
        .amount-danger { color: var(--danger); font-weight: 600; }
        .amount-warning { color: var(--warning); font-weight: 600; }
        .amount-foreclosed { color: var(--foreclosed-color); font-weight: 600; }
        
        /* Status Badges */
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        
        .status-badge.paid {
            background: #e3f7e3;
            color: var(--success);
        }
        
        .status-badge.unpaid {
            background: #fff3e0;
            color: var(--warning);
        }
        
        .status-badge.overdue {
            background: #fee2e2;
            color: var(--danger);
        }
        
        .status-badge.foreclosed {
            background: #fee2e2;
            color: var(--foreclosed-color);
        }
        
        .status-badge.partial {
            background: var(--primary-bg);
            color: var(--primary);
        }
        
        /* Action Buttons */
        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.75rem;
            transition: all 0.2s ease;
            border: none;
            text-decoration: none;
            margin: 0 2px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            color: white;
        }
        
        .action-btn.whatsapp { background: #25D366; }
        .action-btn.pay { background: var(--success); }
        .action-btn.undo { background: var(--warning); }
        .action-btn.print { background: var(--danger); }
        .action-btn.info { background: var(--info); }
        
        .emi-checkbox {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        
        .foreclose-toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .foreclose-info-chip {
            background: #fee2e2;
            color: #b91c1c;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        /* Foreclosure Summary */
        .foreclosure-summary-card {
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .foreclosure-summary-icon {
            width: 40px;
            height: 40px;
            background: #dc2626;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .foreclosure-summary-details {
            flex: 1;
        }
        
        .foreclosure-summary-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #991b1b;
            margin-bottom: 0.2rem;
        }
        
        .foreclosure-summary-stats {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        
        .foreclosure-stat-item {
            display: flex;
            flex-direction: column;
        }
        
        .foreclosure-stat-label {
            font-size: 0.6rem;
            color: #b91c1c;
            opacity: 0.8;
        }
        
        .foreclosure-stat-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #991b1b;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem;
        }
        
        .empty-state i {
            font-size: 2rem;
            color: #dee2e6;
        }
        
        .foreclosed-mark {
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            background: #fee2e2;
            color: #991b1b;
            padding: 0.15rem 0.4rem;
            border-radius: 3px;
            font-size: 0.6rem;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .customer-info-card .d-flex {
                flex-direction: column;
            }
            .customer-avatar {
                margin-bottom: 1rem;
            }
            .border-start {
                border-left: none !important;
                border-top: 1px solid var(--border-color);
                padding-top: 1rem;
                margin-top: 1rem;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
                        <h4><i class="bi bi-calendar-check me-2"></i>EMI Schedule</h4>
                        <p><?php echo htmlspecialchars($customer['customer_name']); ?> • Agreement: <?php echo htmlspecialchars($customer['agreement_number']); ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="manage-customers.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back
                        </a>
                        <a href="add-customer.php?edit=<?php echo (int)$customer_id; ?>" class="btn btn-light">
                            <i class="bi bi-pencil me-2"></i>Edit
                        </a>
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
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Customer Info Card -->
            <div class="customer-info-card">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex gap-4">
                            <div class="customer-avatar">
                                <?php
                                $name_parts = explode(' ', $customer['customer_name'] ?? '');
                                $initials = '';
                                foreach ($name_parts as $part) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                                echo substr($initials, 0, 2) ?: 'U';
                                ?>
                            </div>
                            <div style="flex:1;">
                                <h5 class="mb-3"><?php echo htmlspecialchars($customer['customer_name']); ?></h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-telephone-fill"></i>
                                            <span><?php echo htmlspecialchars($customer['customer_number']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-calendar"></i>
                                            <span>Joined: <?php echo !empty($customer['agreement_date']) && $customer['agreement_date'] !== '0000-00-00' ? date('d M Y', strtotime($customer['agreement_date'])) : 'N/A'; ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-building"></i>
                                            <span><?php echo htmlspecialchars($customer['finance_name']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-cash-stack"></i>
                                            <span>Loan: ₹<?php echo number_format((float)$customer['loan_amount'], 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-percent"></i>
                                            <span><?php echo number_format((float)$customer['interest_rate'], 2); ?>%</span>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($customer['vehicle_number'])): ?>
                                    <div class="mt-2">
                                        <span class="loan-badge">
                                            <i class="bi bi-truck"></i> <?php echo htmlspecialchars($customer['vehicle_number']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border-start ps-4">
                            <div class="mb-3">
                                <span class="badge-collection-type <?php echo htmlspecialchars($customer['collection_type']); ?>">
                                    <i class="bi bi-<?php echo $customer['collection_type'] === 'monthly' ? 'calendar-month' : ($customer['collection_type'] === 'weekly' ? 'calendar-week' : 'calendar-day'); ?> me-1"></i>
                                    <?php echo ucfirst(htmlspecialchars($customer['collection_type'])); ?>
                                </span>
                                <span class="ms-2 small text-muted">
                                    First EMI: <?php echo !empty($customer['emi_date']) && $customer['emi_date'] !== '0000-00-00' ? date('d M Y', strtotime($customer['emi_date'])) : 'N/A'; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Progress</span>
                                <span class="fw-semibold">
                                    <?php echo $paid_emi_count; ?>/<?php echo count($emis); ?> Paid
                                </span>
                            </div>
                            <?php
                            $progress = count($emis) > 0 ? ($paid_emi_count / count($emis)) * 100 : 0;
                            ?>
                            <div class="progress-custom mb-3">
                                <div class="progress-bar" style="width: <?php echo $progress; ?>%;"></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="stat-box">
                                        <div class="label">Pending</div>
                                        <div class="value text-warning"><?php echo $pending_emi_count; ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box">
                                        <div class="label">Paid</div>
                                        <div class="value text-success"><?php echo $paid_emi_count; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid - Properly Arranged -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="bi bi-hourglass"></i></div>
                    <div class="stat-label">PENDING EMIS</div>
                    <div class="stat-value"><?php echo $pending_emi_count; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="bi bi-check-circle"></i></div>
                    <div class="stat-label">PAID EMIS</div>
                    <div class="stat-value"><?php echo $paid_emi_count; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon foreclosed"><i class="bi bi-lock"></i></div>
                    <div class="stat-label">FORECLOSED</div>
                    <div class="stat-value"><?php echo $total_foreclosed_count; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-cash"></i></div>
                    <div class="stat-label">COLLECTED</div>
                    <div class="stat-value">₹<?php echo number_format($total_collected, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-bank"></i></div>
                    <div class="stat-label">PRINCIPAL</div>
                    <div class="stat-value">₹<?php echo number_format($total_principal_paid, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-percent"></i></div>
                    <div class="stat-label">INTEREST</div>
                    <div class="stat-value">₹<?php echo number_format($total_interest_paid, 0); ?></div>
                </div>
            </div>

            <!-- Outstanding Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="bi bi-wallet"></i></div>
                    <div class="stat-label">OUTSTANDING</div>
                    <div class="stat-value">₹<?php echo number_format($total_out_emi + $total_overdue_pending, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon info"><i class="bi bi-bank2"></i></div>
                    <div class="stat-label">PRINCIPAL DUE</div>
                    <div class="stat-value">₹<?php echo number_format($total_principal_outstanding, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="bi bi-percent"></i></div>
                    <div class="stat-label">INTEREST DUE</div>
                    <div class="stat-value">₹<?php echo number_format($total_interest_outstanding, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
                    <div class="stat-label">OVERDUE</div>
                    <div class="stat-value">₹<?php echo number_format($total_overdue_outstanding, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="bi bi-gift"></i></div>
                    <div class="stat-label">EXCESS</div>
                    <div class="stat-value">₹<?php echo number_format($total_excess_available, 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="bi bi-sticky"></i></div>
                    <div class="stat-label">REMARKS</div>
                    <div class="stat-value">₹<?php echo number_format($total_remarks_amount, 0); ?></div>
                </div>
            </div>

            <!-- Foreclosure Summary -->
            <?php if ($total_foreclosed_count > 0): ?>
            <div class="foreclosure-summary-card">
                <div class="foreclosure-summary-icon">
                    <i class="bi bi-lock-fill"></i>
                </div>
                <div class="foreclosure-summary-details">
                    <div class="foreclosure-summary-title">Foreclosed EMIs Summary</div>
                    <div class="foreclosure-summary-stats">
                        <div class="foreclosure-stat-item">
                            <span class="foreclosure-stat-label">Foreclosed EMIs</span>
                            <span class="foreclosure-stat-value"><?php echo $total_foreclosed_count; ?></span>
                        </div>
                        <div class="foreclosure-stat-item">
                            <span class="foreclosure-stat-label">Foreclosure Amount</span>
                            <span class="foreclosure-stat-value">₹<?php echo number_format($total_foreclosed_amount, 2); ?></span>
                        </div>
                        <div class="foreclosure-stat-item">
                            <span class="foreclosure-stat-label">Foreclosure Bills</span>
                            <span class="foreclosure-stat-value"><?php echo count($foreclosure_data); ?></span>
                        </div>
                    </div>
                </div>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="$('#foreclosureDetails').slideToggle()">
                        <i class="bi bi-chevron-down me-1"></i>Details
                    </button>
                </div>
            </div>

            <!-- Foreclosure Details (Collapsible) -->
            <div id="foreclosureDetails" style="display: none; margin-bottom: 1rem;">
                <div class="dashboard-card">
                    <div class="card-header" style="background: linear-gradient(135deg, #7f1d1d, #991b1b);">
                        <h5><i class="bi bi-lock-fill me-2"></i>Foreclosure Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Bill No</th>
                                        <th>Date</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Overdue</th>
                                        <th>Charge</th>
                                        <th>Total</th>
                                        <th>EMIs</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($foreclosure_data as $fid => $fdata): ?>
                                    <tr>
                                        <td><span class="badge bg-dark"><?php echo htmlspecialchars($fdata['bill_number'] ?? 'N/A'); ?></span></td>
                                        <td><?php echo !empty($fdata['date']) && $fdata['date'] !== '0000-00-00' ? date('d/m/y', strtotime($fdata['date'])) : 'N/A'; ?></td>
                                        <td class="amount-primary">₹<?php echo number_format((float)$fdata['principal'], 2); ?></td>
                                        <td class="amount-primary">₹<?php echo number_format((float)$fdata['interest'], 2); ?></td>
                                        <td class="amount-danger">₹<?php echo number_format((float)$fdata['overdue'], 2); ?></td>
                                        <td class="amount-warning">₹<?php echo number_format((float)$fdata['fee'], 2); ?></td>
                                        <td class="amount-foreclosed fw-bold">₹<?php echo number_format((float)$fdata['total'], 2); ?></td>
                                        <td><span class="badge bg-danger"><?php echo (int)$fdata['count']; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- EMI Schedule Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h5><i class="bi bi-table me-2"></i>EMI Schedule</h5>
                            <p>Total: <?php echo count($emis); ?> | Pending: <?php echo $pending_emi_count; ?> | Paid: <?php echo $paid_emi_count; ?> | Foreclosed: <?php echo $total_foreclosed_count; ?></p>
                        </div>
                        <div class="foreclose-toolbar">
                            <span class="foreclose-info-chip" id="selectedCountChip">Selected: 0</span>
                            <button type="button" class="btn btn-danger btn-sm" id="openForecloseSelectedBtn">
                                <i class="bi bi-lock-fill me-1"></i>Foreclose
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($emis)): ?>
                        <div class="empty-state">
                            <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                            <h6 class="mt-3 text-muted">No EMI records found</h6>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table id="emiTable" class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 30px;">
                                            <input type="checkbox" id="selectAllEmis" class="emi-checkbox">
                                        </th>
                                        <th>#</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>EMI</th>
                                        <th>Paid</th>
                                        <th>Pending</th>
                                        <th>Due</th>
                                        <th>Paid Date</th>
                                        <th>Overdue</th>
                                        <th>Bill</th>
                                        <th>Status</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $emi_counter = 0; ?>
                                <?php foreach ($emis as $e): ?>
                                    <?php
                                    $emi_counter++;
                                    $isPaid = (($e['status'] ?? '') === 'paid') || !empty($e['is_foreclosed']);
                                    $hasPayment = !empty($e['has_any_payment']) || !empty($e['is_foreclosed']);

                                    if (!empty($e['is_foreclosed'])) {
                                        $badgeClass = 'foreclosed';
                                        $badgeText = 'Foreclosed';
                                        $row_class = 'foreclosed';
                                    } elseif (($e['status'] ?? '') === 'paid') {
                                        $badgeClass = 'paid';
                                        $badgeText = 'Paid';
                                        $row_class = 'paid';
                                    } elseif (($e['status'] ?? '') === 'partial') {
                                        $badgeClass = 'partial';
                                        $badgeText = 'Partial';
                                        $row_class = '';
                                    } elseif (($e['pending_overdue'] ?? 0) > 0) {
                                        $badgeClass = 'overdue';
                                        $badgeText = 'Overdue';
                                        $row_class = 'overdue';
                                    } else {
                                        $badgeClass = 'unpaid';
                                        $badgeText = 'Unpaid';
                                        $row_class = '';
                                    }

                                    $total_customer_payment = floatval($e['total_customer_payment'] ?? 0);
                                    $due_date_display = (!empty($e['emi_due_date']) && $e['emi_due_date'] !== '0000-00-00') ? date('d/m/y', strtotime($e['emi_due_date'])) : 'N/A';
                                    $paid_date_display = (!empty($e['paid_date']) && $e['paid_date'] !== '0000-00-00') ? date('d/m/y', strtotime($e['paid_date'])) : '-';

                                    $emi_label = "EMI " . $emi_counter;
                                    if (($e['collection_type'] ?? '') === 'weekly' && !empty($e['week_number'])) {
                                        $emi_label = "W" . (int)$e['week_number'];
                                    } elseif (($e['collection_type'] ?? '') === 'daily' && !empty($e['day_number'])) {
                                        $emi_label = "D" . (int)$e['day_number'];
                                    }
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td>
                                            <?php if ($e['can_foreclose_select']): ?>
                                                <input type="checkbox" class="emi-checkbox emi-row-check" value="<?php echo (int)$e['emi_id']; ?>" data-principal="<?php echo number_format((float)$e['remaining_principal'], 2, '.', ''); ?>" data-interest="<?php echo number_format((float)$e['remaining_interest'], 2, '.', ''); ?>" data-overdue="<?php echo number_format((float)$e['calc_overdue'], 2, '.', ''); ?>" data-emi-label="<?php echo $emi_label; ?>">
                                            <?php elseif (!empty($e['is_foreclosed'])): ?>
                                                <span class="foreclosed-mark"><i class="bi bi-lock-fill"></i></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo $emi_label; ?></span></td>
                                        <td><span class="amount-primary">₹<?php echo number_format((float)$e['principal_amount'], 0); ?></span></td>
                                        <td><span class="amount-primary">₹<?php echo number_format((float)$e['interest_amount'], 0); ?></span></td>
                                        <td><span class="fw-bold">₹<?php echo number_format((float)$e['emi_amount'], 0); ?></span></td>
                                        <td>
                                            <?php if ($total_customer_payment > 0): ?>
                                                <span class="amount-success fw-bold">₹<?php echo number_format($total_customer_payment, 0); ?></span>
                                            <?php elseif (!empty($e['is_foreclosed'])): ?>
                                                <span class="amount-foreclosed">Foreclosed</span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isPaid): ?>
                                                <span class="amount-success">₹0</span>
                                            <?php else: ?>
                                                <span class="amount-danger fw-bold">₹<?php echo number_format((float)$e['remaining_total'] + (float)$e['calc_overdue'], 0); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $due_date_display; ?></td>
                                        <td>
                                            <?php if (!empty($e['is_foreclosed'])): ?>
                                                <span class="badge bg-danger"><?php echo !empty($e['foreclosure_date']) ? date('d/m/y', strtotime($e['foreclosure_date'])) : '-'; ?></span>
                                            <?php elseif ($paid_date_display !== '-'): ?>
                                                <span class="badge bg-success"><?php echo $paid_date_display; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($e['paid_overdue'] ?? 0) > 0): ?>
                                                <span class="badge bg-success">₹<?php echo number_format((float)$e['paid_overdue'], 0); ?></span>
                                            <?php elseif (($e['pending_overdue'] ?? 0) > 0): ?>
                                                <span class="badge bg-danger">₹<?php echo number_format((float)$e['pending_overdue'], 0); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($e['emi_bill_number'])): ?>
                                                <span class="badge bg-dark"><?php echo htmlspecialchars($e['emi_bill_number']); ?></span>
                                            <?php elseif (!empty($e['foreclosure_bill_number'])): ?>
                                                <span class="badge bg-danger"><?php echo htmlspecialchars($e['foreclosure_bill_number']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $badgeClass; ?>">
                                                <?php echo $badgeText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if ($hasPayment && empty($e['is_foreclosed'])): ?>
                                                    <button type="button" class="action-btn undo" onclick="undoPayment(<?php echo (int)$e['emi_id']; ?>, <?php echo (int)$customer_id; ?>)" title="Undo Payment">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                    <button type="button" class="action-btn print" onclick="printReceipt(<?php echo (int)$e['emi_id']; ?>, <?php echo (int)$customer_id; ?>)" title="Print Receipt">
                                                        <i class="bi bi-printer"></i>
                                                    </button>
                                                    <button type="button" class="action-btn whatsapp" onclick="shareViaWhatsApp(<?php echo (int)$e['emi_id']; ?>, <?php echo (int)$customer_id; ?>)" title="Share via WhatsApp">
                                                        <i class="bi bi-whatsapp"></i>
                                                    </button>
                                                <?php elseif (!empty($e['is_foreclosed'])): ?>
                                                    <button type="button" class="action-btn info" onclick="viewForeclosureDetails(<?php echo (int)$e['foreclosure_id']; ?>)" title="Foreclosure Details">
                                                        <i class="bi bi-info-circle"></i>
                                                    </button>
                                                    <button type="button" class="action-btn print" onclick="printForeclosureReceipt(<?php echo (int)$e['foreclosure_id']; ?>, <?php echo (int)$customer_id; ?>)" title="Print Foreclosure Receipt">
                                                        <i class="bi bi-printer"></i>
                                                    </button>
                                                    <button type="button" class="action-btn whatsapp" onclick="shareForeclosureViaWhatsApp(<?php echo (int)$e['foreclosure_id']; ?>, <?php echo (int)$customer_id; ?>)" title="Share Foreclosure Receipt">
                                                        <i class="bi bi-whatsapp"></i>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if (!$isPaid): ?>
                                                    <a href="pay-emi.php?emi_id=<?php echo (int)$e['emi_id']; ?>&customer_id=<?php echo (int)$customer_id; ?>" class="action-btn pay" title="Pay EMI">
                                                        <i class="bi bi-cash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Excess Payments -->
            <div class="dashboard-card">
                <div class="card-header" style="background: linear-gradient(135deg, #6b46c1, #8b5cf6);">
                    <h5><i class="bi bi-gift me-2"></i>Excess Payments</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($excess_payments)): ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Bill No</th>
                                        <th>Excess</th>
                                        <th>Used</th>
                                        <th>Available</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($excess_payments as $index => $excess): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><span class="badge bg-dark"><?php echo htmlspecialchars($excess['bill_number']); ?></span></td>
                                        <td class="amount-primary">₹<?php echo number_format((float)$excess['excess_amount'], 2); ?></td>
                                        <td class="amount-success">₹<?php echo number_format((float)$excess['used_amount'], 2); ?></td>
                                        <td class="amount-danger fw-bold">₹<?php echo number_format((float)$excess['remaining_amount'], 2); ?></td>
                                        <td><?php echo date('d/m/y', strtotime($excess['payment_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo ($excess['status'] === 'active') ? 'success' : (($excess['status'] === 'used') ? 'info' : 'secondary'); ?>">
                                                <?php echo ucfirst(htmlspecialchars($excess['status'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-gift text-muted" style="font-size: 2rem;"></i>
                            <p class="mt-2 text-muted">No excess payments available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Remarks -->
            <div class="dashboard-card">
                <div class="card-header" style="background: linear-gradient(135deg, #2d3748, #4a5568);">
                    <h5><i class="bi bi-sticky me-2"></i>Remarks</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <input type="number" step="0.01" min="0.01" class="form-control" name="remark_amount" placeholder="Amount (₹)" required>
                        </div>
                        <div class="col-md-3">
                            <input type="date" class="form-control" name="remark_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" name="remark_note" placeholder="Remark note" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="add_remark" class="btn btn-success w-100">
                                <i class="bi bi-plus"></i> Add
                            </button>
                        </div>
                    </form>

                    <?php if (!empty($remarks)): ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Note</th>
                                        <th>Added</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($remarks as $index => $remark): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="amount-success">₹<?php echo number_format((float)$remark['amount'], 2); ?></td>
                                        <td><?php echo date('d/m/y', strtotime($remark['remark_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($remark['note']); ?></td>
                                        <td><?php echo date('d/m/y H:i', strtotime($remark['created_at'])); ?></td>
                                        <td>
                                            <a href="?customer_id=<?php echo (int)$customer_id; ?>&delete_remark=<?php echo (int)$remark['id']; ?>"
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('Delete this remark?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-sticky text-muted" style="font-size: 2rem;"></i>
                            <p class="mt-2 text-muted">No remarks added yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Undo Payment Modal -->
<div class="modal fade" id="undoModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h6 class="modal-title">Undo Payment</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="undo-payment.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="emi_id" id="undoEmiId" value="">
                    <input type="hidden" name="customer_id" id="undoCustomerId" value="">
                    <p class="small mb-2">Reason for undo:</p>
                    <textarea class="form-control" name="undo_remark" rows="2" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning text-white">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Print Modal -->
<div class="modal fade" id="printModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title">Print Receipt</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="printFormat('thermal')">
                        <i class="bi bi-receipt"></i> Thermal
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="printFormat('a4')">
                        <i class="bi bi-card-text"></i> A4
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Foreclosure Modal -->
<div class="modal fade" id="foreclosureModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="forecloseForm" action="foreclose-loan.php" method="POST">
                <div class="modal-header bg-danger text-white">
                    <h6 class="modal-title">Foreclose Selected EMIs</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="customer_id" value="<?php echo (int)$customer_id; ?>">
                    <input type="hidden" name="selected_emi_ids" id="selectedEmiIds" value="">

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Selected unpaid EMIs will be foreclosed.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Selected EMIs</label>
                        <div id="selectedEmiList" class="small text-muted">None</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small">Principal (₹)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="principal_paid" id="foreclosePrincipal" value="0.00" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Interest (₹)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="interest_paid" id="forecloseInterest" value="0.00" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Overdue (₹)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="overdue_charges" id="forecloseOverdue" value="0.00" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Charge (₹)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="foreclosure_charge" id="forecloseCharge" value="0.00">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small">Bill Number</label>
                            <input type="text" class="form-control" name="bill_number" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Paid Date</label>
                            <input type="date" class="form-control" name="paid_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="online">Online</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="2"></textarea>
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="d-flex justify-content-between">
                                    <strong>Total Amount</strong>
                                    <h5 class="mb-0 text-danger">₹<span id="forecloseTotalDisplay">0.00</span></h5>
                                </div>
                                <input type="hidden" name="paid_amount" id="foreclosePaidAmount" value="0.00">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Foreclosure</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    <?php if (!empty($emis)): ?>
    $('#emiTable').DataTable({
        pageLength: 25,
        order: [],
        language: {
            search: "Search:",
            lengthMenu: "_MENU_",
            info: "Showing _START_-_END_ of _TOTAL_",
            paginate: {
                first: '<i class="bi bi-chevron-double-left"></i>',
                previous: '<i class="bi bi-chevron-left"></i>',
                next: '<i class="bi bi-chevron-right"></i>',
                last: '<i class="bi bi-chevron-double-right"></i>'
            }
        },
        columnDefs: [
            { orderable: false, targets: [0, 12] }
        ]
    });
    <?php endif; ?>

    updateSelectedCount();
});

function undoPayment(emiId, customerId) {
    document.getElementById('undoEmiId').value = emiId;
    document.getElementById('undoCustomerId').value = customerId;
    new bootstrap.Modal(document.getElementById('undoModal')).show();
}

let printEmiId = 0;
let printCustomerId = 0;

function printReceipt(emiId, customerId) {
    printEmiId = emiId;
    printCustomerId = customerId;
    new bootstrap.Modal(document.getElementById('printModal')).show();
}

function printFormat(format) {
    if (format === 'thermal') {
        window.open(`receipt.php?emi_id=${printEmiId}&customer_id=${printCustomerId}`, '_blank');
    } else {
        window.open(`print.php?emi_id=${printEmiId}&customer_id=${printCustomerId}`, '_blank');
    }
    bootstrap.Modal.getInstance(document.getElementById('printModal')).hide();
}

function shareViaWhatsApp(emiId, customerId) {
    const phone = prompt('Enter phone:', '91<?php echo preg_replace('/[^0-9]/', '', $customer['customer_number']); ?>');
    if (!phone) return;

    const baseUrl = window.location.protocol + '//' + window.location.host;
    const receiptUrl = baseUrl + '/bala-finance/receipt.php?emi_id=' + emiId + '&customer_id=' + customerId;
    const message = encodeURIComponent(
        'Payment Receipt - Bala Finance\n' +
        'Customer: <?php echo addslashes($customer['customer_name']); ?>\n' +
        'Receipt: ' + receiptUrl
    );

    window.open('https://wa.me/' + phone.replace(/\D/g, '') + '?text=' + message, '_blank');
}

function viewForeclosureDetails(foreclosureId) {
    window.location.href = 'foreclosure-details.php?id=' + foreclosureId;
}

function printForeclosureReceipt(foreclosureId, customerId) {
    window.open(`foreclosure-receipt.php?foreclosure_id=${foreclosureId}&customer_id=${customerId}`, '_blank');
}

function shareForeclosureViaWhatsApp(foreclosureId, customerId) {
    const phone = prompt('Enter phone:', '91<?php echo preg_replace('/[^0-9]/', '', $customer['customer_number']); ?>');
    if (!phone) return;

    const baseUrl = window.location.protocol + '//' + window.location.host;
    const receiptUrl = baseUrl + '/bala-finance/foreclosure-receipt.php?foreclosure_id=' + foreclosureId + '&customer_id=' + customerId;
    const message = encodeURIComponent(
        'Foreclosure Receipt - Bala Finance\n' +
        'Customer: <?php echo addslashes($customer['customer_name']); ?>\n' +
        'Receipt: ' + receiptUrl
    );

    window.open('https://wa.me/' + phone.replace(/\D/g, '') + '?text=' + message, '_blank');
}

// Foreclose checkbox logic
const selectAllEmis = document.getElementById('selectAllEmis');
const openForecloseSelectedBtn = document.getElementById('openForecloseSelectedBtn');
const selectedCountChip = document.getElementById('selectedCountChip');

if (selectAllEmis) {
    selectAllEmis.addEventListener('change', function() {
        document.querySelectorAll('.emi-row-check').forEach(cb => {
            cb.checked = this.checked;
        });
        updateSelectedCount();
    });
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('emi-row-check')) {
        updateSelectedCount();
        syncSelectAllCheckbox();
    }
});

function syncSelectAllCheckbox() {
    const all = document.querySelectorAll('.emi-row-check');
    const checked = document.querySelectorAll('.emi-row-check:checked');
    if (!selectAllEmis) return;
    if (all.length === 0) {
        selectAllEmis.checked = false;
        selectAllEmis.indeterminate = false;
        return;
    }
    selectAllEmis.checked = all.length === checked.length;
    selectAllEmis.indeterminate = checked.length > 0 && checked.length < all.length;
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.emi-row-check:checked').length;
    if (selectedCountChip) {
        selectedCountChip.textContent = `Selected: ${checked}`;
    }
}

function calculateForecloseTotal() {
    const principal = parseFloat(document.getElementById('foreclosePrincipal').value || 0);
    const interest = parseFloat(document.getElementById('forecloseInterest').value || 0);
    const overdue = parseFloat(document.getElementById('forecloseOverdue').value || 0);
    const charge = parseFloat(document.getElementById('forecloseCharge').value || 0);

    const total = principal + interest + overdue + charge;
    document.getElementById('forecloseTotalDisplay').textContent = total.toFixed(2);
    document.getElementById('foreclosePaidAmount').value = total.toFixed(2);
}

['foreclosePrincipal','forecloseInterest','forecloseOverdue','forecloseCharge'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', calculateForecloseTotal);
    }
});

if (openForecloseSelectedBtn) {
    openForecloseSelectedBtn.addEventListener('click', function() {
        const checked = document.querySelectorAll('.emi-row-check:checked');
        if (checked.length === 0) {
            alert('Please select at least one EMI to foreclose.');
            return;
        }

        let selectedIds = [];
        let selectedLabels = [];
        let principal = 0;
        let interest = 0;
        let overdue = 0;

        checked.forEach(cb => {
            selectedIds.push(cb.value);
            selectedLabels.push(cb.dataset.emiLabel);
            principal += parseFloat(cb.dataset.principal || 0);
            interest += parseFloat(cb.dataset.interest || 0);
            overdue += parseFloat(cb.dataset.overdue || 0);
        });

        document.getElementById('selectedEmiIds').value = selectedIds.join(',');
        document.getElementById('selectedEmiList').textContent = selectedLabels.join(', ');
        document.getElementById('foreclosePrincipal').value = principal.toFixed(2);
        document.getElementById('forecloseInterest').value = interest.toFixed(2);
        document.getElementById('forecloseOverdue').value = overdue.toFixed(2);
        document.getElementById('forecloseCharge').value = '0.00';

        calculateForecloseTotal();

        new bootstrap.Modal(document.getElementById('foreclosureModal')).show();
    });
}

// Auto-hide alerts
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        bootstrap.Alert.getOrCreateInstance(alert).close();
    });
}, 4000);
</script>
</body>
</html>