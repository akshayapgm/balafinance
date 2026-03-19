<?php
require_once 'includes/auth.php';
$currentPage = 'add-customer';
$pageTitle = 'Add Customer';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'] ?? '';
$session_finance_id = intval($_SESSION['finance_id'] ?? 1);

// Check if user has permission to add customers
if (!function_exists('hasPermission') || !hasPermission('add_customer')) {
    $_SESSION['error'] = "You don't have permission to add customers.";
    header("Location: dashboard.php");
    exit;
}

// Check if editing an existing customer
$edit_mode = isset($_GET['edit']) && is_numeric($_GET['edit']);
$edit_customer_id = $edit_mode ? intval($_GET['edit']) : 0;

// Fetch finance records
$sql_finance = "SELECT id, finance_name FROM finance ORDER BY finance_name ASC";
$result_finance = $conn->query($sql_finance);
$finances = [];
if ($result_finance) {
    while ($row = $result_finance->fetch_assoc()) {
        $finances[] = $row;
    }
}

// Get selected finance_id (supports GET for dropdown reload and POST for save)
$selected_finance_id = isset($_POST['finance_id'])
    ? intval($_POST['finance_id'])
    : (isset($_GET['finance_id'])
        ? intval($_GET['finance_id'])
        : $session_finance_id);

// Fetch loans based on selected finance
$loans = [];
if ($selected_finance_id > 0) {
    $sql_loans = "SELECT id, loan_name, loan_amount, interest_rate, loan_tenure 
                  FROM loans 
                  WHERE finance_id = ? 
                  ORDER BY loan_name ASC";
    $stmt_loans = $conn->prepare($sql_loans);
    if ($stmt_loans) {
        $stmt_loans->bind_param("i", $selected_finance_id);
        $stmt_loans->execute();
        $result_loans = $stmt_loans->get_result();
        while ($row = $result_loans->fetch_assoc()) {
            $loans[] = $row;
        }
        $stmt_loans->close();
    }
}

// Fetch customer data for edit mode
$customer_data = [];
$emi_schedule = [];
$selected_weekly_days = [];

if ($edit_mode) {
    $sql_customer = "SELECT * FROM customers WHERE id = ?";
    $stmt_customer = $conn->prepare($sql_customer);
    if (!$stmt_customer) {
        $_SESSION['error'] = "Prepare failed: " . $conn->error;
        header("Location: manage-customers.php");
        exit;
    }

    $stmt_customer->bind_param("i", $edit_customer_id);
    $stmt_customer->execute();
    $result_customer = $stmt_customer->get_result();

    if ($result_customer->num_rows > 0) {
        $customer_data = $result_customer->fetch_assoc();
        $selected_finance_id = intval($customer_data['finance_id']);

        // Reload loans for customer's finance when edit mode
        $loans = [];
        $sql_loans = "SELECT id, loan_name, loan_amount, interest_rate, loan_tenure 
                      FROM loans 
                      WHERE finance_id = ? 
                      ORDER BY loan_name ASC";
        $stmt_loans = $conn->prepare($sql_loans);
        if ($stmt_loans) {
            $stmt_loans->bind_param("i", $selected_finance_id);
            $stmt_loans->execute();
            $result_loans = $stmt_loans->get_result();
            while ($row = $result_loans->fetch_assoc()) {
                $loans[] = $row;
            }
            $stmt_loans->close();
        }

        if ($customer_data['collection_type'] === 'weekly' && !empty($customer_data['weekly_days'])) {
            $selected_weekly_days = array_map('trim', explode(',', $customer_data['weekly_days']));
        }

        $sql_emi = "SELECT id, emi_amount, principal_amount, interest_amount, emi_due_date, status, collection_type, week_number, day_number
                    FROM emi_schedule
                    WHERE customer_id = ?
                    ORDER BY emi_due_date ASC, id ASC";
        $stmt_emi = $conn->prepare($sql_emi);
        if ($stmt_emi) {
            $stmt_emi->bind_param("i", $edit_customer_id);
            $stmt_emi->execute();
            $result_emi = $stmt_emi->get_result();
            while ($row = $result_emi->fetch_assoc()) {
                $emi_schedule[] = $row;
            }
            $stmt_emi->close();
        }
    } else {
        $_SESSION['error'] = "Customer not found.";
        header("Location: manage-customers.php");
        exit;
    }

    $stmt_customer->close();
}

function generateEMISchedule(
    $conn,
    $customer_id,
    $finance_id,
    $collection_type,
    $loan_tenure,
    $first_emi_date,
    $per_installment_emi,
    $per_installment_principal,
    $per_installment_interest,
    $weekly_days = []
) {
    $sql_emi = "INSERT INTO emi_schedule
                (customer_id, finance_id, collection_type, week_number, day_number, emi_amount, principal_amount, interest_amount, emi_due_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid')";

    $stmt_emi = $conn->prepare($sql_emi);
    if (!$stmt_emi) {
        error_log("EMI Prepare failed: " . $conn->error);
        return false;
    }

    $type_string = "iisiiddds";
    $has_error = false;

    if ($collection_type === 'monthly') {
        for ($i = 0; $i < $loan_tenure; $i++) {
            $due_date = date('Y-m-d', strtotime($first_emi_date . " +$i months"));
            $week_number = 0;
            $day_number = 0;

            $stmt_emi->bind_param(
                $type_string,
                $customer_id,
                $finance_id,
                $collection_type,
                $week_number,
                $day_number,
                $per_installment_emi,
                $per_installment_principal,
                $per_installment_interest,
                $due_date
            );

            if (!$stmt_emi->execute()) {
                error_log("Failed to insert monthly EMI: " . $stmt_emi->error);
                $has_error = true;
            }
        }
    } elseif ($collection_type === 'weekly') {
        // Current business logic: one EMI every 7 days starting from first EMI date
        // weekly_days are saved in customers table but not used for multiple EMI generation here
        $start_date = new DateTime($first_emi_date);
        $total_weeks = $loan_tenure * 4;

        for ($week = 0; $week < $total_weeks; $week++) {
            $due_date = clone $start_date;
            $due_date->modify("+$week weeks");
            $week_number = $week + 1;
            $day_number = 0;
            $formatted_date = $due_date->format('Y-m-d');

            $stmt_emi->bind_param(
                $type_string,
                $customer_id,
                $finance_id,
                $collection_type,
                $week_number,
                $day_number,
                $per_installment_emi,
                $per_installment_principal,
                $per_installment_interest,
                $formatted_date
            );

            if (!$stmt_emi->execute()) {
                error_log("Failed to insert weekly EMI: " . $stmt_emi->error);
                $has_error = true;
            }
        }
    } elseif ($collection_type === 'daily') {
        $start_date = new DateTime($first_emi_date);
        $total_days = $loan_tenure * 30;

        for ($day = 0; $day < $total_days; $day++) {
            $due_date = clone $start_date;
            $due_date->modify("+$day days");
            $week_number = 0;
            $day_number = $day + 1;
            $formatted_date = $due_date->format('Y-m-d');

            $stmt_emi->bind_param(
                $type_string,
                $customer_id,
                $finance_id,
                $collection_type,
                $week_number,
                $day_number,
                $per_installment_emi,
                $per_installment_principal,
                $per_installment_interest,
                $formatted_date
            );

            if (!$stmt_emi->execute()) {
                error_log("Failed to insert daily EMI: " . $stmt_emi->error);
                $has_error = true;
            }
        }
    }

    $stmt_emi->close();
    return !$has_error;
}

// Handle only real save/update POST, not finance dropdown GET reload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_name'])) {
    $finance_id = intval($_POST['finance_id'] ?? 0);
    $collection_type = trim($_POST['collection_type'] ?? 'monthly');
    $agreement_number = trim($_POST['agreement_number'] ?? '');
    $agreement_date = trim($_POST['agreement_date'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_number = trim($_POST['customer_number'] ?? '');
    $nominee_name = trim($_POST['nominee_name'] ?? '');
    $nominee_number = trim($_POST['nominee_number'] ?? '');
    $customer_aadhar = trim($_POST['customer_aadhar'] ?? '');
    $nominee_aadhar = trim($_POST['nominee_aadhar'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $loan_amount = floatval($_POST['loan_amount'] ?? 0);
    $interest_rate = floatval($_POST['interest_rate'] ?? 0);
    $loan_tenure = intval($_POST['loan_tenure'] ?? 0);
    $document_charge = floatval($_POST['document_charge'] ?? 0);
    $first_emi_date = trim($_POST['first_emi_date'] ?? '');
    $rc_number = trim($_POST['rc_number'] ?? '');
    $vehicle_type = trim($_POST['vehicle_type'] ?? '');
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');

    // Handle file uploads
    $target_dir = "uploads/customers/";
    $customer_photo = $edit_mode ? ($customer_data['customer_photo'] ?? null) : null;
    $aadhar_photo = $edit_mode ? ($customer_data['aadhar_photo'] ?? null) : null;

    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION));
        $customer_photo = $target_dir . 'customer_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['customer_photo']['tmp_name'], $customer_photo);
    }

    if (isset($_FILES['aadhar_photo']) && $_FILES['aadhar_photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['aadhar_photo']['name'], PATHINFO_EXTENSION));
        $aadhar_photo = $target_dir . 'aadhar_' . time() . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['aadhar_photo']['tmp_name'], $aadhar_photo);
    }

    $weekly_days = isset($_POST['weekly_days']) && is_array($_POST['weekly_days']) ? $_POST['weekly_days'] : [];

    // Required validation
    if (
        $finance_id <= 0 ||
        empty($agreement_number) ||
        empty($agreement_date) ||
        empty($customer_name) ||
        empty($customer_number) ||
        empty($nominee_name) ||
        empty($nominee_number) ||
        $loan_id <= 0 ||
        $loan_amount <= 0 ||
        $interest_rate < 0 ||
        $loan_tenure <= 0 ||
        $document_charge < 0 ||
        empty($first_emi_date)
    ) {
        $_SESSION['error'] = "All required fields must be filled.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if (!in_array($collection_type, ['monthly', 'weekly', 'daily'], true)) {
        $_SESSION['error'] = "Invalid collection type selected.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if ($collection_type === 'weekly' && empty($weekly_days)) {
        $_SESSION['error'] = "Please select at least one day for weekly collection.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if (!empty($customer_number) && !preg_match('/^\d{10}$/', $customer_number)) {
        $_SESSION['error'] = "Customer number must be 10 digits.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if (!empty($nominee_number) && !preg_match('/^\d{10}$/', $nominee_number)) {
        $_SESSION['error'] = "Nominee number must be 10 digits.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if (!empty($customer_aadhar) && !preg_match('/^\d{12}$/', $customer_aadhar)) {
        $_SESSION['error'] = "Customer Aadhar number must be 12 digits.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if (!empty($nominee_aadhar) && !preg_match('/^\d{12}$/', $nominee_aadhar)) {
        $_SESSION['error'] = "Nominee Aadhar number must be 12 digits.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    if (strtotime($first_emi_date) < strtotime($agreement_date)) {
        $_SESSION['error'] = "First EMI date must be on or after agreement date.";
        header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
        exit;
    }

    // Vehicle validation based on loan name
    $is_vehicle_loan = false;
    foreach ($loans as $loan) {
        if (intval($loan['id']) === $loan_id) {
            $loan_name_check = strtolower($loan['loan_name']);
            if (
                strpos($loan_name_check, 'vehicle') !== false ||
                strpos($loan_name_check, 'car') !== false ||
                strpos($loan_name_check, 'bike') !== false ||
                strpos($loan_name_check, 'auto') !== false ||
                strpos($loan_name_check, 'truck') !== false ||
                strpos($loan_name_check, 'van') !== false
            ) {
                $is_vehicle_loan = true;
            }
            break;
        }
    }

    if ($is_vehicle_loan) {
        if (empty($rc_number) || empty($vehicle_type) || empty($vehicle_number)) {
            $_SESSION['error'] = "RC Number, Vehicle Type and Vehicle Number are required for vehicle loans.";
            header("Location: add-customer.php" . ($edit_mode ? "?edit=$edit_customer_id" : ""));
            exit;
        }
    }

    // Calculations
    $principal_per_month = $loan_amount / $loan_tenure;
    $monthly_interest = $loan_amount * ($interest_rate / 100);
    $emi = round($principal_per_month + $monthly_interest, 2);
    $total_principal = round($loan_amount, 2);
    $total_interest = round($monthly_interest * $loan_tenure, 2);

    $per_installment_principal = round($principal_per_month, 2);
    $per_installment_interest = round($monthly_interest, 2);
    $per_installment_emi = round($emi, 2);

    if ($collection_type === 'weekly') {
        $weeks_in_month = 4;
        $per_installment_principal = round($principal_per_month / $weeks_in_month, 2);
        $per_installment_interest = round($monthly_interest / $weeks_in_month, 2);
        $per_installment_emi = round($per_installment_principal + $per_installment_interest, 2);
    } elseif ($collection_type === 'daily') {
        $days_in_month = 30;
        $per_installment_principal = round($principal_per_month / $days_in_month, 2);
        $per_installment_interest = round($monthly_interest / $days_in_month, 2);
        $per_installment_emi = round($per_installment_principal + $per_installment_interest, 2);
    }

    $weekly_days_string = !empty($weekly_days) ? implode(',', $weekly_days) : null;

    if ($edit_mode) {
        $sql = "UPDATE customers SET
                agreement_number = ?,
                agreement_date = ?,
                customer_name = ?,
                customer_number = ?,
                nominee_name = ?,
                nominee_number = ?,
                customer_aadhar = ?,
                nominee_aadhar = ?,
                customer_address = ?,
                vehicle_number = ?,
                loan_id = ?,
                loan_amount = ?,
                principal_amount = ?,
                interest_amount = ?,
                interest_rate = ?,
                loan_tenure = ?,
                document_charge = ?,
                emi = ?,
                emi_date = ?,
                rc_number = ?,
                vehicle_type = ?,
                customer_photo = ?,
                aadhar_photo = ?,
                finance_id = ?,
                collection_type = ?,
                weekly_days = ?
                WHERE id = ?";

        $stmt_update = $conn->prepare($sql);
        if (!$stmt_update) {
            $_SESSION['error'] = "Prepare failed: " . $conn->error;
            header("Location: add-customer.php?edit=$edit_customer_id");
            exit;
        }

        $stmt_update->bind_param(
            "ssssssssssiddddiddsssssissi",
            $agreement_number,
            $agreement_date,
            $customer_name,
            $customer_number,
            $nominee_name,
            $nominee_number,
            $customer_aadhar,
            $nominee_aadhar,
            $customer_address,
            $vehicle_number,
            $loan_id,
            $loan_amount,
            $total_principal,
            $total_interest,
            $interest_rate,
            $loan_tenure,
            $document_charge,
            $emi,
            $first_emi_date,
            $rc_number,
            $vehicle_type,
            $customer_photo,
            $aadhar_photo,
            $finance_id,
            $collection_type,
            $weekly_days_string,
            $edit_customer_id
        );

        if ($stmt_update->execute()) {
            $stmt_update->close();

            // Delete only unpaid EMIs, keep paid history
            $sql_delete_emi = "DELETE FROM emi_schedule WHERE customer_id = ? AND status = 'unpaid'";
            $stmt_delete_emi = $conn->prepare($sql_delete_emi);
            if ($stmt_delete_emi) {
                $stmt_delete_emi->bind_param("i", $edit_customer_id);
                $stmt_delete_emi->execute();
                $stmt_delete_emi->close();
            }

            generateEMISchedule(
                $conn,
                $edit_customer_id,
                $finance_id,
                $collection_type,
                $loan_tenure,
                $first_emi_date,
                $per_installment_emi,
                $per_installment_principal,
                $per_installment_interest,
                $weekly_days
            );

            $_SESSION['success'] = "Customer updated successfully!";
            header("Location: manage-customers.php");
            exit;
        } else {
            $_SESSION['error'] = "Error: " . $stmt_update->error;
            $stmt_update->close();
            header("Location: add-customer.php?edit=$edit_customer_id");
            exit;
        }
    } else {
        $sql = "INSERT INTO customers
                (agreement_number, agreement_date, customer_name, customer_number, nominee_name, nominee_number,
                 customer_aadhar, nominee_aadhar, customer_address, vehicle_number, loan_id,
                 loan_amount, principal_amount, interest_amount, interest_rate, loan_tenure,
                 document_charge, emi, emi_date, rc_number, vehicle_type, customer_photo, aadhar_photo,
                 finance_id, collection_type, weekly_days)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt_insert = $conn->prepare($sql);
        if (!$stmt_insert) {
            $_SESSION['error'] = "Prepare failed: " . $conn->error;
            header("Location: add-customer.php");
            exit;
        }

        $stmt_insert->bind_param(
            "ssssssssssiddddiddsssssiss",
            $agreement_number,
            $agreement_date,
            $customer_name,
            $customer_number,
            $nominee_name,
            $nominee_number,
            $customer_aadhar,
            $nominee_aadhar,
            $customer_address,
            $vehicle_number,
            $loan_id,
            $loan_amount,
            $total_principal,
            $total_interest,
            $interest_rate,
            $loan_tenure,
            $document_charge,
            $emi,
            $first_emi_date,
            $rc_number,
            $vehicle_type,
            $customer_photo,
            $aadhar_photo,
            $finance_id,
            $collection_type,
            $weekly_days_string
        );

        if ($stmt_insert->execute()) {
            $customer_id = $conn->insert_id;
            $stmt_insert->close();

            generateEMISchedule(
                $conn,
                $customer_id,
                $finance_id,
                $collection_type,
                $loan_tenure,
                $first_emi_date,
                $per_installment_emi,
                $per_installment_principal,
                $per_installment_interest,
                $weekly_days
            );

            if (function_exists('logActivity')) {
                logActivity($conn, 'add_customer', "Added new customer: $customer_name (ID: $customer_id) - Collection Type: $collection_type");
            }

            $_SESSION['success'] = "Customer added successfully with " . ucfirst($collection_type) . " EMI schedule!";
            header("Location: manage-customers.php");
            exit;
        } else {
            $_SESSION['error'] = "Error: " . $stmt_insert->error;
            $stmt_insert->close();
            header("Location: add-customer.php");
            exit;
        }
    }
}

// Prefill values
$form_agreement_number = $edit_mode ? ($customer_data['agreement_number'] ?? '') : '';
$form_agreement_date = $edit_mode ? ($customer_data['agreement_date'] ?? date('Y-m-d')) : date('Y-m-d');
$form_customer_name = $edit_mode ? ($customer_data['customer_name'] ?? '') : '';
$form_customer_number = $edit_mode ? ($customer_data['customer_number'] ?? '') : '';
$form_customer_aadhar = $edit_mode ? ($customer_data['customer_aadhar'] ?? '') : '';
$form_customer_address = $edit_mode ? ($customer_data['customer_address'] ?? '') : '';
$form_nominee_name = $edit_mode ? ($customer_data['nominee_name'] ?? '') : '';
$form_nominee_number = $edit_mode ? ($customer_data['nominee_number'] ?? '') : '';
$form_nominee_aadhar = $edit_mode ? ($customer_data['nominee_aadhar'] ?? '') : '';
$form_loan_id = $edit_mode ? intval($customer_data['loan_id'] ?? 0) : 0;
$form_loan_amount = $edit_mode ? ($customer_data['loan_amount'] ?? '') : '';
$form_interest_rate = $edit_mode ? ($customer_data['interest_rate'] ?? '') : '';
$form_loan_tenure = $edit_mode ? ($customer_data['loan_tenure'] ?? '') : '';
$form_document_charge = $edit_mode ? ($customer_data['document_charge'] ?? '0') : '0';
$form_first_emi_date = $edit_mode ? ($customer_data['emi_date'] ?? date('Y-m-d')) : date('Y-m-d');
$form_rc_number = $edit_mode ? ($customer_data['rc_number'] ?? '') : '';
$form_vehicle_type = $edit_mode ? ($customer_data['vehicle_type'] ?? '') : '';
$form_vehicle_number = $edit_mode ? ($customer_data['vehicle_number'] ?? '') : '';
$form_collection_type = $edit_mode ? ($customer_data['collection_type'] ?? 'monthly') : 'monthly';
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
            --monthly-color: #3b82f6;
            --weekly-color: #8b5cf6;
            --daily-color: #f97316;
            --primary-bg: #e6f0ff;
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

        .form-section {
            background: #f8fafc;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--primary);
        }

        .form-section h5 {
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .collection-type-option {
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem 1rem;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            height: 100%;
            position: relative;
            background: white;
        }

        .collection-type-option:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .collection-type-option.active {
            border-color: var(--primary);
            background: var(--primary-bg);
        }

        .collection-type-option.monthly.active {
            border-color: var(--monthly-color);
            background: #e6f0ff;
        }

        .collection-type-option.weekly.active {
            border-color: var(--weekly-color);
            background: #f3e5f5;
        }

        .collection-type-option.daily.active {
            border-color: var(--daily-color);
            background: #fff3e0;
        }

        .collection-type-option i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .collection-type-option.monthly i {
            color: var(--monthly-color);
        }

        .collection-type-option.weekly i {
            color: var(--weekly-color);
        }

        .collection-type-option.daily i {
            color: var(--daily-color);
        }

        .collection-type-option .title {
            font-weight: 600;
            font-size: 1.1rem;
            display: block;
            margin-bottom: 0.25rem;
        }

        .collection-type-option .desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: block;
        }

        .weekly-days-container,
        .monthly-info-container {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            border: 1px solid var(--border-color);
            display: none;
        }

        .weekly-days-container.active,
        .monthly-info-container.active {
            display: block;
        }

        .day-checkbox {
            display: inline-flex;
            align-items: center;
            margin-right: 1.5rem;
            margin-bottom: 0.75rem;
            padding: 0.5rem 1rem;
            background: #f8fafc;
            border-radius: 2rem;
            transition: all 0.2s;
            cursor: pointer;
        }

        .day-checkbox:hover {
            background: #e2e8f0;
        }

        .day-checkbox input {
            margin-right: 0.5rem;
            width: 1rem;
            height: 1rem;
            cursor: pointer;
        }

        .day-checkbox input:checked {
            accent-color: var(--weekly-color);
        }

        .info-note {
            background: #e6f0ff;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--primary);
            border-left: 3px solid var(--primary);
        }

        .calculation-box {
            background: #f8fafc;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .calculation-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .calculation-value {
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
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

        .date-hint {
            font-size: 0.8rem;
            color: var(--info);
            margin-top: 0.25rem;
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
                        <h4><i class="bi bi-person-plus me-2"></i><?php echo $edit_mode ? 'Edit Customer' : 'Add New Customer'; ?></h4>
                        <p><?php echo $edit_mode ? 'Edit customer details and collection schedule' : 'Add customer details along with loan information'; ?></p>
                    </div>
                    <div>
                        <a href="manage-customers.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Customers
                        </a>
                    </div>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $_SESSION['success']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $_SESSION['error']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="form-card">
                <form method="POST" action="" enctype="multipart/form-data" id="customerForm">
                    <div class="form-section">
                        <h5><i class="bi bi-person-circle"></i>Customer Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="customer_name"
                                       value="<?php echo htmlspecialchars($form_customer_name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="customer_number" pattern="[0-9]{10}" maxlength="10"
                                       value="<?php echo htmlspecialchars($form_customer_number); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer Aadhar Number</label>
                                <input type="text" class="form-control" name="customer_aadhar" pattern="[0-9]{12}" maxlength="12"
                                       value="<?php echo htmlspecialchars($form_customer_aadhar); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Customer Address</label>
                                <textarea class="form-control" name="customer_address" rows="2"><?php echo htmlspecialchars($form_customer_address); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer Photo</label>
                                <input type="file" class="form-control" name="customer_photo" accept="image/*">
                                <?php if ($edit_mode && !empty($customer_data['customer_photo'])): ?>
                                    <small class="text-muted">Current: <a href="<?php echo htmlspecialchars($customer_data['customer_photo']); ?>" target="_blank">View</a></small>
                                <?php endif; ?>
                                <small class="text-muted d-block">Max size: 2MB</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h5><i class="bi bi-person-badge"></i>Nominee Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nominee Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nominee_name"
                                       value="<?php echo htmlspecialchars($form_nominee_name); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nominee Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="nominee_number" pattern="[0-9]{10}" maxlength="10"
                                       value="<?php echo htmlspecialchars($form_nominee_number); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Nominee Aadhar Number</label>
                                <input type="text" class="form-control" name="nominee_aadhar" pattern="[0-9]{12}" maxlength="12"
                                       value="<?php echo htmlspecialchars($form_nominee_aadhar); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Aadhar Photo</label>
                                <input type="file" class="form-control" name="aadhar_photo" accept="image/*">
                                <?php if ($edit_mode && !empty($customer_data['aadhar_photo'])): ?>
                                    <small class="text-muted">Current: <a href="<?php echo htmlspecialchars($customer_data['aadhar_photo']); ?>" target="_blank">View</a></small>
                                <?php endif; ?>
                                <small class="text-muted">Max size: 2MB</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h5><i class="bi bi-file-text"></i>Loan and Agreement Details</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Select Finance <span class="text-danger">*</span></label>
                                <select class="form-select" name="finance_id" id="finance_id" required>
                                    <option value="">Select Finance Company</option>
                                    <?php foreach ($finances as $finance): ?>
                                        <option value="<?php echo $finance['id']; ?>" <?php echo ($selected_finance_id == $finance['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($finance['finance_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Agreement Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="agreement_number"
                                       value="<?php echo htmlspecialchars($form_agreement_number); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Agreement Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="agreement_date" id="agreement_date"
                                       value="<?php echo htmlspecialchars($form_agreement_date); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Select Loan <span class="text-danger">*</span></label>
                                <select class="form-select" name="loan_id" id="loan_id" required onchange="loadLoanDetails()">
                                    <option value="">Select Loan Type</option>
                                    <?php if (!empty($loans)): ?>
                                        <?php foreach ($loans as $loan): ?>
                                            <option value="<?php echo $loan['id']; ?>"
                                                    data-amount="<?php echo $loan['loan_amount']; ?>"
                                                    data-interest="<?php echo $loan['interest_rate']; ?>"
                                                    data-tenure="<?php echo $loan['loan_tenure']; ?>"
                                                    <?php echo ($form_loan_id == $loan['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($loan['loan_name']); ?> (₹<?php echo number_format($loan['loan_amount']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Loan Amount (₹) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" name="loan_amount" id="loan_amount"
                                       value="<?php echo htmlspecialchars($form_loan_amount); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Interest Rate (% per month) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" name="interest_rate" id="interest_rate"
                                       value="<?php echo htmlspecialchars($form_interest_rate); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Loan Tenure (Months) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="loan_tenure" id="loan_tenure"
                                       value="<?php echo htmlspecialchars($form_loan_tenure); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Document Charge (₹)</label>
                                <input type="number" step="0.01" class="form-control" name="document_charge" id="document_charge"
                                       value="<?php echo htmlspecialchars($form_document_charge); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">First EMI Due Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="first_emi_date" id="first_emi_date"
                                       value="<?php echo htmlspecialchars($form_first_emi_date); ?>" required>
                                <div class="date-hint">
                                    <i class="bi bi-info-circle"></i> This will be the due date for the first EMI
                                </div>
                            </div>

                            <div class="col-md-4 vehicle-field" style="display: none;">
                                <label class="form-label">RC Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="rc_number" id="rc_number"
                                       value="<?php echo htmlspecialchars($form_rc_number); ?>">
                            </div>

                            <div class="col-md-4 vehicle-field" style="display: none;">
                                <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="vehicle_type" id="vehicle_type">
                                    <option value="">Select Vehicle Type</option>
                                    <option value="Two-Wheeler" <?php echo ($form_vehicle_type === 'Two-Wheeler') ? 'selected' : ''; ?>>Two-Wheeler</option>
                                    <option value="Car" <?php echo ($form_vehicle_type === 'Car') ? 'selected' : ''; ?>>Car</option>
                                    <option value="SUV" <?php echo ($form_vehicle_type === 'SUV') ? 'selected' : ''; ?>>SUV</option>
                                    <option value="Truck" <?php echo ($form_vehicle_type === 'Truck') ? 'selected' : ''; ?>>Truck</option>
                                    <option value="Bus" <?php echo ($form_vehicle_type === 'Bus') ? 'selected' : ''; ?>>Bus</option>
                                    <option value="Auto" <?php echo ($form_vehicle_type === 'Auto') ? 'selected' : ''; ?>>Auto Rickshaw</option>
                                    <option value="Tractor" <?php echo ($form_vehicle_type === 'Tractor') ? 'selected' : ''; ?>>Tractor</option>
                                    <option value="Others" <?php echo ($form_vehicle_type === 'Others') ? 'selected' : ''; ?>>Others</option>
                                </select>
                            </div>

                            <div class="col-md-4 vehicle-field" style="display: none;">
                                <label class="form-label">Vehicle Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="vehicle_number" id="vehicle_number"
                                       value="<?php echo htmlspecialchars($form_vehicle_number); ?>"
                                       placeholder="TN29 AB 1234">
                            </div>
                        </div>

                        <div class="calculation-box mt-3">
                            <h6 class="mb-3">Loan Calculation</h6>
                            <div class="calculation-item">
                                <span class="calculation-label">Monthly Principal (Base)</span>
                                <span class="calculation-value" id="monthly_principal">₹0.00</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Monthly Interest (Base)</span>
                                <span class="calculation-value" id="monthly_interest">₹0.00</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Interest (Tenure)</span>
                                <span class="calculation-value" id="total_interest">₹0.00</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Amount (Principal + Interest)</span>
                                <span class="calculation-value" id="total_amount">₹0.00</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Monthly EMI (Base)</span>
                                <span class="calculation-value" id="monthly_emi">₹0.00</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label" id="per_period_label">Per Installment EMI</span>
                                <span class="calculation-value" id="per_installment">₹0.00</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h5><i class="bi bi-calendar-check"></i>Collection Schedule</h5>

                        <div class="collection-type-container">
                            <label class="form-label fw-semibold mb-3">Collection Type <span class="text-danger">*</span></label>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="collection-type-option monthly" id="monthlyOption" onclick="selectCollectionType('monthly')">
                                        <i class="bi bi-calendar-month"></i>
                                        <span class="title">Monthly</span>
                                        <span class="desc">Once every month</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="collection-type-option weekly" id="weeklyOption" onclick="selectCollectionType('weekly')">
                                        <i class="bi bi-calendar-week"></i>
                                        <span class="title">Weekly</span>
                                        <span class="desc">Once every week</span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="collection-type-option daily" id="dailyOption" onclick="selectCollectionType('daily')">
                                        <i class="bi bi-calendar-day"></i>
                                        <span class="title">Daily</span>
                                        <span class="desc">Every day</span>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="collection_type" id="collection_type" value="<?php echo htmlspecialchars($form_collection_type); ?>">

                            <div id="monthlyInfo" class="monthly-info-container">
                                <h6 class="mb-3"><i class="bi bi-calendar-month me-2" style="color: var(--monthly-color);"></i>Monthly Collection</h6>
                                <div class="info-note">
                                    <i class="bi bi-info-circle-fill me-2"></i>
                                    Monthly EMI will be due on the same day of each month as the First EMI Due Date
                                </div>
                            </div>

                            <div id="weeklyDays" class="weekly-days-container">
                                <h6 class="mb-3"><i class="bi bi-calendar-week me-2" style="color: var(--weekly-color);"></i>Select Collection Days</h6>
                                <div class="row">
                                    <div class="col-12">
                                        <?php
                                        $days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                                        foreach ($days_of_week as $day):
                                            $checked = ($edit_mode && in_array($day, $selected_weekly_days, true)) ? 'checked' : '';
                                        ?>
                                            <label class="day-checkbox">
                                                <input type="checkbox" name="weekly_days[]" value="<?php echo $day; ?>" <?php echo $checked; ?>> <?php echo $day; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <p class="small text-muted mt-2">
                                    <i class="bi bi-info-circle"></i>
                                    Weekly EMIs will be collected every 7 days from the first EMI date.
                                </p>
                            </div>

                            <div id="dailyInfo" class="info-note" style="display: none; margin-top: 1.5rem;">
                                <i class="bi bi-info-circle-fill me-2"></i>
                                Daily collection will be made every day starting from the first EMI date.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="manage-customers.php" class="btn btn-secondary px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="bi bi-save me-2"></i><?php echo $edit_mode ? 'Update Customer' : 'Save Customer'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($edit_mode && !empty($emi_schedule)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h4 class="card-title mb-0">Collection Schedule</h4>
                        <small class="text-muted">Schedule based on <?php echo htmlspecialchars($customer_data['collection_type']); ?> collection type</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Type</th>
                                        <?php if (($customer_data['collection_type'] ?? '') === 'weekly'): ?>
                                            <th>Week</th>
                                        <?php elseif (($customer_data['collection_type'] ?? '') === 'daily'): ?>
                                            <th>Day</th>
                                        <?php endif; ?>
                                        <th>EMI Amount</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($emi_schedule as $index => $emi): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo ucfirst($emi['collection_type']); ?></td>
                                            <?php if (($customer_data['collection_type'] ?? '') === 'weekly'): ?>
                                                <td><?php echo !empty($emi['week_number']) ? 'Week ' . $emi['week_number'] : '-'; ?></td>
                                            <?php elseif (($customer_data['collection_type'] ?? '') === 'daily'): ?>
                                                <td><?php echo !empty($emi['day_number']) ? 'Day ' . $emi['day_number'] : '-'; ?></td>
                                            <?php endif; ?>
                                            <td>₹<?php echo number_format((float)$emi['emi_amount'], 2); ?></td>
                                            <td>₹<?php echo number_format((float)$emi['principal_amount'], 2); ?></td>
                                            <td>₹<?php echo number_format((float)$emi['interest_amount'], 2); ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($emi['emi_due_date'])); ?></td>
                                            <td>
                                                <?php
                                                $status = strtolower($emi['status'] ?? 'unpaid');
                                                $badge = 'warning';
                                                if ($status === 'paid') {
                                                    $badge = 'success';
                                                } elseif ($status === 'overdue') {
                                                    $badge = 'danger';
                                                } elseif ($status === 'partial') {
                                                    $badge = 'info';
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $badge; ?>">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let currentCollectionType = '<?php echo htmlspecialchars($form_collection_type, ENT_QUOTES); ?>';
    let currentLoanIsVehicle = false;

    function loadLoanDetails() {
        const select = document.getElementById('loan_id');
        const selected = select.options[select.selectedIndex];

        if (selected && selected.value) {
            const amount = parseFloat(selected.getAttribute('data-amount')) || 0;
            const interest = parseFloat(selected.getAttribute('data-interest')) || 0;
            const tenure = parseInt(selected.getAttribute('data-tenure')) || 0;

            document.getElementById('loan_amount').value = amount;
            document.getElementById('interest_rate').value = interest;
            document.getElementById('loan_tenure').value = tenure;

            const loanName = selected.text.toLowerCase();
            const isVehicleLoan =
                loanName.includes('vehicle') ||
                loanName.includes('car') ||
                loanName.includes('bike') ||
                loanName.includes('auto') ||
                loanName.includes('truck') ||
                loanName.includes('van');

            currentLoanIsVehicle = isVehicleLoan;
            showHideVehicleFields(isVehicleLoan);
            calculateLoan();
        } else {
            currentLoanIsVehicle = false;
            showHideVehicleFields(false);
            calculateLoan();
        }
    }

    function showHideVehicleFields(show) {
        const vehicleFields = document.querySelectorAll('.vehicle-field');
        const rcNumber = document.getElementById('rc_number');
        const vehicleType = document.getElementById('vehicle_type');
        const vehicleNumber = document.getElementById('vehicle_number');

        vehicleFields.forEach(function(field) {
            field.style.display = show ? 'block' : 'none';
        });

        if (show) {
            rcNumber.setAttribute('required', 'required');
            vehicleType.setAttribute('required', 'required');
            vehicleNumber.setAttribute('required', 'required');
        } else {
            rcNumber.removeAttribute('required');
            vehicleType.removeAttribute('required');
            vehicleNumber.removeAttribute('required');
            rcNumber.value = '';
            vehicleType.value = '';
            vehicleNumber.value = '';
        }
    }

    function calculateLoan() {
        const loanAmount = parseFloat(document.getElementById('loan_amount').value) || 0;
        const interestRate = parseFloat(document.getElementById('interest_rate').value) || 0;
        const tenure = parseInt(document.getElementById('loan_tenure').value) || 0;

        if (loanAmount > 0 && interestRate >= 0 && tenure > 0) {
            const monthlyPrincipal = loanAmount / tenure;
            const monthlyInterest = loanAmount * (interestRate / 100);
            const totalInterest = monthlyInterest * tenure;
            const monthlyEmi = monthlyPrincipal + monthlyInterest;

            let perInstallment = monthlyEmi;
            if (currentCollectionType === 'weekly') {
                perInstallment = monthlyEmi / 4;
            } else if (currentCollectionType === 'daily') {
                perInstallment = monthlyEmi / 30;
            }

            document.getElementById('monthly_principal').innerHTML = '₹' + monthlyPrincipal.toFixed(2);
            document.getElementById('monthly_interest').innerHTML = '₹' + monthlyInterest.toFixed(2);
            document.getElementById('total_interest').innerHTML = '₹' + totalInterest.toFixed(2);
            document.getElementById('total_amount').innerHTML = '₹' + (loanAmount + totalInterest).toFixed(2);
            document.getElementById('monthly_emi').innerHTML = '₹' + monthlyEmi.toFixed(2);
            document.getElementById('per_installment').innerHTML = '₹' + perInstallment.toFixed(2);

            const label = document.getElementById('per_period_label');
            if (currentCollectionType === 'weekly') {
                label.innerHTML = 'Per Week EMI';
            } else if (currentCollectionType === 'daily') {
                label.innerHTML = 'Per Day EMI';
            } else {
                label.innerHTML = 'Per Month EMI';
            }
        } else {
            document.getElementById('monthly_principal').innerHTML = '₹0.00';
            document.getElementById('monthly_interest').innerHTML = '₹0.00';
            document.getElementById('total_interest').innerHTML = '₹0.00';
            document.getElementById('total_amount').innerHTML = '₹0.00';
            document.getElementById('monthly_emi').innerHTML = '₹0.00';
            document.getElementById('per_installment').innerHTML = '₹0.00';
        }
    }

    function selectCollectionType(type) {
        currentCollectionType = type;
        document.getElementById('collection_type').value = type;

        document.querySelectorAll('.collection-type-option').forEach(function(el) {
            el.classList.remove('active');
        });

        if (type === 'monthly') {
            document.getElementById('monthlyOption').classList.add('active');
        } else if (type === 'weekly') {
            document.getElementById('weeklyOption').classList.add('active');
        } else if (type === 'daily') {
            document.getElementById('dailyOption').classList.add('active');
        }

        const weeklyDays = document.getElementById('weeklyDays');
        const monthlyInfo = document.getElementById('monthlyInfo');
        const dailyInfo = document.getElementById('dailyInfo');

        weeklyDays.classList.remove('active');
        monthlyInfo.classList.remove('active');
        dailyInfo.style.display = 'none';

        if (type === 'weekly') {
            weeklyDays.classList.add('active');
        } else if (type === 'monthly') {
            monthlyInfo.classList.add('active');
        } else if (type === 'daily') {
            dailyInfo.style.display = 'block';
        }

        calculateLoan();
    }

    document.getElementById('loan_amount').addEventListener('input', calculateLoan);
    document.getElementById('interest_rate').addEventListener('input', calculateLoan);
    document.getElementById('loan_tenure').addEventListener('input', calculateLoan);

    document.getElementById('finance_id').addEventListener('change', function() {
        const financeId = this.value;
        let url = 'add-customer.php?finance_id=' + encodeURIComponent(financeId);
        <?php if ($edit_mode): ?>
        url += '&edit=<?php echo $edit_customer_id; ?>';
        <?php endif; ?>
        window.location.href = url;
    });

    document.getElementById('customerForm').addEventListener('submit', function(e) {
        const collectionType = document.getElementById('collection_type').value;

        if (collectionType === 'weekly') {
            const checkedDays = document.querySelectorAll('input[name="weekly_days[]"]:checked');
            if (checkedDays.length === 0) {
                e.preventDefault();
                alert('Please select at least one collection day for weekly collection');
                return false;
            }
        }

        if (currentLoanIsVehicle) {
            const rcNumber = document.getElementById('rc_number').value.trim();
            const vehicleType = document.getElementById('vehicle_type').value;
            const vehicleNumber = document.getElementById('vehicle_number').value.trim();

            if (!rcNumber) {
                e.preventDefault();
                alert('RC Number is required for vehicle loans');
                document.getElementById('rc_number').focus();
                return false;
            }

            if (!vehicleType) {
                e.preventDefault();
                alert('Vehicle Type is required for vehicle loans');
                document.getElementById('vehicle_type').focus();
                return false;
            }

            if (!vehicleNumber) {
                e.preventDefault();
                alert('Vehicle Number is required for vehicle loans');
                document.getElementById('vehicle_number').focus();
                return false;
            }
        }

        const agreementDate = document.getElementById('agreement_date').value;
        const firstEmiDate = document.getElementById('first_emi_date').value;
        if (agreementDate && firstEmiDate && firstEmiDate < agreementDate) {
            e.preventDefault();
            alert('First EMI date must be on or after agreement date');
            document.getElementById('first_emi_date').focus();
            return false;
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        selectCollectionType(currentCollectionType);
        loadLoanDetails();
        calculateLoan();
    });
</script>
</body>
</html>