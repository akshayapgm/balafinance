<?php
require_once 'includes/auth.php';
$currentPage = 'customers';
$pageTitle = 'Edit Customer';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to edit customers
if (!function_exists('hasPermission') || !hasPermission('edit_customer')) {
    $_SESSION['error'] = 'You do not have permission to edit customers';
    header('Location: customers.php');
    exit();
}

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($customer_id == 0) {
    $_SESSION['error'] = 'Invalid customer ID';
    header('Location: customers.php');
    exit();
}

// Get customer details with role-based access
$customer_query = "SELECT c.*, l.loan_name, l.id as loan_id, f.finance_name 
                  FROM customers c
                  JOIN loans l ON c.loan_id = l.id
                  JOIN finance f ON c.finance_id = f.id
                  WHERE c.id = ?";

if ($user_role != 'admin') {
    $customer_query .= " AND c.finance_id = ?";
}

$stmt = $conn->prepare($customer_query);
if ($user_role != 'admin') {
    $stmt->bind_param('ii', $customer_id, $finance_id);
} else {
    $stmt->bind_param('i', $customer_id);
}
$stmt->execute();
$customer_result = $stmt->get_result();

if ($customer_result->num_rows == 0) {
    $_SESSION['error'] = 'Customer not found or you do not have access';
    header('Location: customers.php');
    exit();
}

$customer = $customer_result->fetch_assoc();

// Get finance companies for dropdown (admin only)
$finance_companies = [];
if ($user_role == 'admin') {
    $finance_query = "SELECT id, finance_name FROM finance ORDER BY finance_name";
    $finance_result = $conn->query($finance_query);
    while ($row = $finance_result->fetch_assoc()) {
        $finance_companies[] = $row;
    }
}

// Get available loans based on finance_id
$loan_query = "SELECT id, loan_name, loan_amount, interest_rate, loan_tenure 
               FROM loans WHERE finance_id = ? ORDER BY loan_name";
$stmt = $conn->prepare($loan_query);
$stmt->bind_param('i', $customer['finance_id']);
$stmt->execute();
$loans = $stmt->get_result();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $agreement_number = trim($_POST['agreement_number']);
    $agreement_date = !empty($_POST['agreement_date']) ? $_POST['agreement_date'] : null;
    $customer_name = trim($_POST['customer_name']);
    $customer_number = trim($_POST['customer_number']);
    $nominee_name = trim($_POST['nominee_name']);
    $nominee_number = trim($_POST['nominee_number']);
    $customer_aadhar = trim($_POST['customer_aadhar']);
    $nominee_aadhar = trim($_POST['nominee_aadhar']);
    $customer_address = trim($_POST['customer_address']);
    $vehicle_number = trim($_POST['vehicle_number']);
    $loan_id = intval($_POST['loan_id']);
    $loan_amount = floatval($_POST['loan_amount']);
    $principal_amount = floatval($_POST['principal_amount']);
    $interest_amount = floatval($_POST['interest_amount']);
    $interest_rate = floatval($_POST['interest_rate']);
    $loan_tenure = intval($_POST['loan_tenure']);
    $document_charge = floatval($_POST['document_charge']);
    $emi = floatval($_POST['emi']);
    $emi_date = $_POST['emi_date'];
    $rc_number = trim($_POST['rc_number']);
    $vehicle_type = trim($_POST['vehicle_type']);
    $collection_type = $_POST['collection_type'];
    $finance_id = intval($_POST['finance_id'] ?? $customer['finance_id']);
    
    // Validate required fields
    $errors = [];
    
    if (empty($agreement_number)) {
        $errors[] = 'Agreement number is required';
    }
    if (empty($customer_name)) {
        $errors[] = 'Customer name is required';
    }
    if (empty($customer_number)) {
        $errors[] = 'Customer number is required';
    }
    if (empty($nominee_name)) {
        $errors[] = 'Nominee name is required';
    }
    if (empty($nominee_number)) {
        $errors[] = 'Nominee number is required';
    }
    if ($loan_id <= 0) {
        $errors[] = 'Please select a loan type';
    }
    if ($loan_amount <= 0) {
        $errors[] = 'Loan amount must be greater than 0';
    }
    if ($principal_amount <= 0) {
        $errors[] = 'Principal amount must be greater than 0';
    }
    if ($interest_amount < 0) {
        $errors[] = 'Interest amount cannot be negative';
    }
    if ($interest_rate <= 0) {
        $errors[] = 'Interest rate must be greater than 0';
    }
    if ($loan_tenure <= 0) {
        $errors[] = 'Loan tenure must be greater than 0';
    }
    if ($emi <= 0) {
        $errors[] = 'EMI amount must be greater than 0';
    }
    if (empty($emi_date)) {
        $errors[] = 'EMI date is required';
    }
    
    // Validate phone numbers
    if (!preg_match('/^[0-9]{10}$/', $customer_number)) {
        $errors[] = 'Customer number must be a 10-digit number';
    }
    if (!preg_match('/^[0-9]{10}$/', $nominee_number)) {
        $errors[] = 'Nominee number must be a 10-digit number';
    }
    
    // Validate Aadhar if provided
    if (!empty($customer_aadhar) && !preg_match('/^[0-9]{12}$/', $customer_aadhar)) {
        $errors[] = 'Customer Aadhar must be a 12-digit number';
    }
    if (!empty($nominee_aadhar) && !preg_match('/^[0-9]{12}$/', $nominee_aadhar)) {
        $errors[] = 'Nominee Aadhar must be a 12-digit number';
    }
    
    // Handle file uploads
    $upload_dir = 'uploads/customers/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $customer_photo = $customer['customer_photo']; // Keep existing by default
    $aadhar_photo = $customer['aadhar_photo']; // Keep existing by default
    
    // Upload customer photo if provided
    if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($_FILES['customer_photo']['type'], $allowed_types)) {
            $errors[] = 'Customer photo must be a JPEG or PNG image';
        } elseif ($_FILES['customer_photo']['size'] > $max_size) {
            $errors[] = 'Customer photo size must be less than 5MB';
        } else {
            // Delete old file if exists
            if (!empty($customer['customer_photo']) && file_exists($upload_dir . $customer['customer_photo'])) {
                unlink($upload_dir . $customer['customer_photo']);
            }
            
            $extension = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'customer_' . time() . '_' . uniqid() . '.' . $extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $upload_path)) {
                $customer_photo = $new_filename;
            } else {
                $errors[] = 'Failed to upload customer photo';
            }
        }
    }
    
    // Upload Aadhar photo if provided
    if (isset($_FILES['aadhar_photo']) && $_FILES['aadhar_photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($_FILES['aadhar_photo']['type'], $allowed_types)) {
            $errors[] = 'Aadhar photo must be a JPEG, PNG, or PDF file';
        } elseif ($_FILES['aadhar_photo']['size'] > $max_size) {
            $errors[] = 'Aadhar photo size must be less than 5MB';
        } else {
            // Delete old file if exists
            if (!empty($customer['aadhar_photo']) && file_exists($upload_dir . $customer['aadhar_photo'])) {
                unlink($upload_dir . $customer['aadhar_photo']);
            }
            
            $extension = pathinfo($_FILES['aadhar_photo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'aadhar_' . time() . '_' . uniqid() . '.' . $extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['aadhar_photo']['tmp_name'], $upload_path)) {
                $aadhar_photo = $new_filename;
            } else {
                $errors[] = 'Failed to upload Aadhar photo';
            }
        }
    }
    
    // If no errors, update database
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $update_query = "UPDATE customers SET 
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
                collection_type = ?";
            
            // Add finance_id update only for admin
            if ($user_role == 'admin') {
                $update_query .= ", finance_id = ?";
            }
            
            $update_query .= " WHERE id = ?";
            
            $stmt = $conn->prepare($update_query);
            
            // Bind parameters
            if ($user_role == 'admin') {
                $stmt->bind_param('sssssssssssddddiddsssssi', 
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
                    $principal_amount,
                    $interest_amount,
                    $interest_rate,
                    $loan_tenure,
                    $document_charge,
                    $emi,
                    $emi_date,
                    $rc_number,
                    $vehicle_type,
                    $customer_photo,
                    $aadhar_photo,
                    $collection_type,
                    $finance_id,
                    $customer_id
                );
            } else {
                $stmt->bind_param('sssssssssssddddiddssssi', 
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
                    $principal_amount,
                    $interest_amount,
                    $interest_rate,
                    $loan_tenure,
                    $document_charge,
                    $emi,
                    $emi_date,
                    $rc_number,
                    $vehicle_type,
                    $customer_photo,
                    $aadhar_photo,
                    $collection_type,
                    $customer_id
                );
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update customer: ' . $stmt->error);
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                         VALUES (?, 'edit_customer', ?, ?, ?)";
            $stmt = $conn->prepare($log_query);
            $details = "Edited customer: $customer_name (ID: $customer_id)";
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt->bind_param('isss', $_SESSION['user_id'], $details, $ip, $agent);
            $stmt->execute();
            
            $conn->commit();
            
            $_SESSION['success'] = 'Customer updated successfully';
            header("Location: view-customer.php?id=$customer_id");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Error updating customer: ' . $e->getMessage();
        }
    }
}

// Get loan details for calculation
$loan_details_query = "SELECT * FROM loans WHERE id = ?";
$stmt = $conn->prepare($loan_details_query);
$stmt->bind_param('i', $customer['loan_id']);
$stmt->execute();
$loan_details = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo htmlspecialchars($customer['customer_name']); ?></title>
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
            --info: #3498db;
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

        /* Page Header */
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
        }

        .page-header .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Form Card */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: var(--shadow);
        }

        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            color: var(--primary);
            margin-right: 0.5rem;
            font-size: 1.25rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            font-size: 0.95rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.25);
        }

        .input-group-text {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 8px 0 0 8px;
            color: var(--text-muted);
        }

        /* Current File Display */
        .current-file {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .current-file i {
            color: var(--success);
            margin-right: 0.5rem;
        }

        .btn-remove-file {
            color: var(--danger);
            background: none;
            border: none;
            padding: 0 0.5rem;
            font-size: 0.8rem;
        }

        .btn-remove-file:hover {
            text-decoration: underline;
        }

        /* Calculation Result */
        .calculation-result {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 0.5rem;
        }

        .calculation-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .calculation-item:last-child {
            border-bottom: none;
        }

        .calculation-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .calculation-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .calculation-value.total {
            font-size: 1.2rem;
            color: var(--primary);
        }

        /* Error List */
        .error-list {
            background: #fee;
            border: 1px solid var(--danger);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .error-list ul {
            margin-bottom: 0;
            color: var(--danger);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .form-card {
                padding: 1.5rem;
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
                        <h4><i class="bi bi-pencil-square me-2"></i>Edit Customer</h4>
                        <p>Update customer information and loan details</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="view-customer.php?id=<?php echo $customer_id; ?>" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Details
                        </a>
                        <a href="customers.php" class="btn btn-light">
                            <i class="bi bi-people me-2"></i>All Customers
                        </a>
                    </div>
                </div>
            </div>

            <!-- Error Display -->
            <?php if (!empty($errors)): ?>
                <div class="error-list">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <form method="POST" enctype="multipart/form-data" id="editCustomerForm">
                <div class="form-card">
                    
                    <!-- Customer Information Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-person"></i>
                            Customer Information
                        </h5>
                        
                        <div class="row g-3">
                            <?php if ($user_role == 'admin'): ?>
                            <div class="col-md-4">
                                <label class="form-label">Finance Company *</label>
                                <select class="form-select" name="finance_id" id="finance_id" required>
                                    <option value="">Select Finance Company</option>
                                    <?php foreach ($finance_companies as $fc): ?>
                                        <option value="<?php echo $fc['id']; ?>" 
                                            <?php echo $fc['id'] == $customer['finance_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fc['finance_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-4">
                                <label class="form-label">Agreement Number *</label>
                                <input type="text" class="form-control" name="agreement_number" 
                                       value="<?php echo htmlspecialchars($customer['agreement_number']); ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Agreement Date</label>
                                <input type="date" class="form-control" name="agreement_date" 
                                       value="<?php echo $customer['agreement_date'] ? date('Y-m-d', strtotime($customer['agreement_date'])) : ''; ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Customer Name *</label>
                                <input type="text" class="form-control" name="customer_name" 
                                       value="<?php echo htmlspecialchars($customer['customer_name']); ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Customer Number *</label>
                                <input type="tel" class="form-control" name="customer_number" 
                                       value="<?php echo htmlspecialchars($customer['customer_number']); ?>" 
                                       pattern="[0-9]{10}" maxlength="10" required>
                                <small class="text-muted">10-digit mobile number</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Customer Aadhar</label>
                                <input type="text" class="form-control" name="customer_aadhar" 
                                       value="<?php echo htmlspecialchars($customer['customer_aadhar']); ?>" 
                                       pattern="[0-9]{12}" maxlength="12">
                                <small class="text-muted">12-digit Aadhar number (optional)</small>
                            </div>
                        </div>
                    </div>

                    <!-- Nominee Information Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-people"></i>
                            Nominee Information
                        </h5>
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Nominee Name *</label>
                                <input type="text" class="form-control" name="nominee_name" 
                                       value="<?php echo htmlspecialchars($customer['nominee_name']); ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Nominee Number *</label>
                                <input type="tel" class="form-control" name="nominee_number" 
                                       value="<?php echo htmlspecialchars($customer['nominee_number']); ?>" 
                                       pattern="[0-9]{10}" maxlength="10" required>
                                <small class="text-muted">10-digit mobile number</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Nominee Aadhar</label>
                                <input type="text" class="form-control" name="nominee_aadhar" 
                                       value="<?php echo htmlspecialchars($customer['nominee_aadhar']); ?>" 
                                       pattern="[0-9]{12}" maxlength="12">
                                <small class="text-muted">12-digit Aadhar number (optional)</small>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Customer Address</label>
                                <textarea class="form-control" name="customer_address" rows="3"><?php echo htmlspecialchars($customer['customer_address']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicle Information Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-truck"></i>
                            Vehicle Information
                        </h5>
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Vehicle Number</label>
                                <input type="text" class="form-control" name="vehicle_number" 
                                       value="<?php echo htmlspecialchars($customer['vehicle_number']); ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">RC Number</label>
                                <input type="text" class="form-control" name="rc_number" 
                                       value="<?php echo htmlspecialchars($customer['rc_number']); ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Vehicle Type</label>
                                <select class="form-select" name="vehicle_type">
                                    <option value="">Select Type</option>
                                    <option value="Two-Wheeler" <?php echo $customer['vehicle_type'] == 'Two-Wheeler' ? 'selected' : ''; ?>>Two-Wheeler</option>
                                    <option value="Three-Wheeler" <?php echo $customer['vehicle_type'] == 'Three-Wheeler' ? 'selected' : ''; ?>>Three-Wheeler</option>
                                    <option value="Four-Wheeler" <?php echo $customer['vehicle_type'] == 'Four-Wheeler' ? 'selected' : ''; ?>>Four-Wheeler</option>
                                    <option value="Commercial" <?php echo $customer['vehicle_type'] == 'Commercial' ? 'selected' : ''; ?>>Commercial</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Loan Details Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-cash-stack"></i>
                            Loan Details
                        </h5>
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Loan Type *</label>
                                <select class="form-select" name="loan_id" id="loan_id" required>
                                    <option value="">Select Loan Type</option>
                                    <?php 
                                    $loans->data_seek(0);
                                    while ($loan = $loans->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo $loan['id']; ?>" 
                                            data-amount="<?php echo $loan['loan_amount']; ?>"
                                            data-rate="<?php echo $loan['interest_rate']; ?>"
                                            data-tenure="<?php echo $loan['loan_tenure']; ?>"
                                            <?php echo $loan['id'] == $customer['loan_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($loan['loan_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Loan Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="loan_amount" 
                                           id="loan_amount" value="<?php echo $customer['loan_amount']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Principal Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="principal_amount" 
                                           id="principal_amount" value="<?php echo $customer['principal_amount']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Interest Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="interest_amount" 
                                           id="interest_amount" value="<?php echo $customer['interest_amount']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Interest Rate (%) *</label>
                                <input type="number" step="0.01" class="form-control" name="interest_rate" 
                                       id="interest_rate" value="<?php echo $customer['interest_rate']; ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Loan Tenure (Months) *</label>
                                <input type="number" class="form-control" name="loan_tenure" 
                                       id="loan_tenure" value="<?php echo $customer['loan_tenure']; ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Document Charge</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="document_charge" 
                                           value="<?php echo $customer['document_charge']; ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">EMI Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="emi" 
                                           id="emi" value="<?php echo $customer['emi']; ?>" required>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">EMI Date *</label>
                                <input type="date" class="form-control" name="emi_date" 
                                       value="<?php echo date('Y-m-d', strtotime($customer['emi_date'])); ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Collection Type *</label>
                                <select class="form-select" name="collection_type" required>
                                    <option value="monthly" <?php echo $customer['collection_type'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="weekly" <?php echo $customer['collection_type'] == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="daily" <?php echo $customer['collection_type'] == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                </select>
                            </div>
                        </div>

                        <!-- Calculation Summary -->
                        <div class="calculation-result mt-3">
                            <h6 class="mb-3">Loan Summary</h6>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Loan Amount</span>
                                <span class="calculation-value" id="total_loan_display">₹<?php echo number_format($customer['loan_amount'], 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Interest</span>
                                <span class="calculation-value" id="total_interest_display">₹<?php echo number_format($customer['interest_amount'], 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Monthly EMI</span>
                                <span class="calculation-value" id="emi_display">₹<?php echo number_format($customer['emi'], 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Payable</span>
                                <span class="calculation-value total" id="total_payable">₹<?php echo number_format($customer['loan_amount'] + $customer['interest_amount'], 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Documents Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-files"></i>
                            Documents
                        </h5>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Customer Photo</label>
                                <input type="file" class="form-control" name="customer_photo" accept="image/*">
                                <small class="text-muted">Max size: 5MB. Allowed: JPEG, PNG</small>
                                
                                <?php if (!empty($customer['customer_photo'])): ?>
                                <div class="current-file">
                                    <i class="bi bi-check-circle-fill"></i>
                                    Current: <?php echo htmlspecialchars($customer['customer_photo']); ?>
                                    <button type="button" class="btn-remove-file float-end" onclick="removeFile('customer_photo')">
                                        <i class="bi bi-x"></i> Remove
                                    </button>
                                    <input type="hidden" name="existing_customer_photo" id="existing_customer_photo" value="<?php echo $customer['customer_photo']; ?>">
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Aadhar Photo</label>
                                <input type="file" class="form-control" name="aadhar_photo" accept="image/*,application/pdf">
                                <small class="text-muted">Max size: 5MB. Allowed: JPEG, PNG, PDF</small>
                                
                                <?php if (!empty($customer['aadhar_photo'])): ?>
                                <div class="current-file">
                                    <i class="bi bi-check-circle-fill"></i>
                                    Current: <?php echo htmlspecialchars($customer['aadhar_photo']); ?>
                                    <button type="button" class="btn-remove-file float-end" onclick="removeFile('aadhar_photo')">
                                        <i class="bi bi-x"></i> Remove
                                    </button>
                                    <input type="hidden" name="existing_aadhar_photo" id="existing_aadhar_photo" value="<?php echo $customer['aadhar_photo']; ?>">
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="view-customer.php?id=<?php echo $customer_id; ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Update Customer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Load loans when finance changes (for admin)
    $('#finance_id').change(function() {
        var financeId = $(this).val();
        if (financeId) {
            $.ajax({
                url: 'get-loans.php',
                type: 'POST',
                data: { finance_id: financeId },
                dataType: 'json',
                success: function(data) {
                    var loanSelect = $('#loan_id');
                    loanSelect.empty().append('<option value="">Select Loan Type</option>');
                    
                    $.each(data, function(index, loan) {
                        loanSelect.append('<option value="' + loan.id + '" ' +
                            'data-amount="' + loan.loan_amount + '" ' +
                            'data-rate="' + loan.interest_rate + '" ' +
                            'data-tenure="' + loan.loan_tenure + '">' + 
                            loan.loan_name + '</option>');
                    });
                }
            });
        }
    });

    // Auto-calculate based on loan selection
    $('#loan_id').change(function() {
        var selected = $(this).find(':selected');
        var loanAmount = selected.data('amount');
        var interestRate = selected.data('rate');
        var loanTenure = selected.data('tenure');
        
        if (loanAmount && interestRate && loanTenure) {
            $('#loan_amount').val(loanAmount);
            $('#principal_amount').val(loanAmount);
            $('#interest_rate').val(interestRate);
            $('#loan_tenure').val(loanTenure);
            
            // Calculate interest amount
            var interestAmount = (loanAmount * interestRate / 100) * loanTenure;
            $('#interest_amount').val(interestAmount.toFixed(2));
            
            // Calculate EMI
            var totalPayable = loanAmount + interestAmount;
            var emi = totalPayable / loanTenure;
            $('#emi').val(emi.toFixed(2));
            
            updateSummary();
        }
    });

    // Update calculations when inputs change
    $('#loan_amount, #interest_amount, #loan_tenure').on('input', function() {
        updateSummary();
    });

    function updateSummary() {
        var loanAmount = parseFloat($('#loan_amount').val()) || 0;
        var interestAmount = parseFloat($('#interest_amount').val()) || 0;
        var loanTenure = parseFloat($('#loan_tenure').val()) || 1;
        
        var totalPayable = loanAmount + interestAmount;
        var emi = totalPayable / loanTenure;
        
        $('#total_loan_display').text('₹' + loanAmount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        $('#total_interest_display').text('₹' + interestAmount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        $('#emi_display').text('₹' + emi.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        $('#total_payable').text('₹' + totalPayable.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        
        // Update EMI field
        $('#emi').val(emi.toFixed(2));
    }

    // Phone number validation
    $('input[pattern="[0-9]{10}"]').on('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
    });

    // Aadhar number validation
    $('input[pattern="[0-9]{12}"]').on('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 12);
    });
});

// File remove function
function removeFile(type) {
    if (confirm('Are you sure you want to remove this file?')) {
        $('#' + type + '_file').remove();
        $('#existing_' + type).val('');
        $('.current-file:has(#existing_' + type + ')').hide();
    }
}

// Form validation
document.getElementById('editCustomerForm').addEventListener('submit', function(e) {
    var customerNumber = document.querySelector('input[name="customer_number"]').value;
    var nomineeNumber = document.querySelector('input[name="nominee_number"]').value;
    var customerAadhar = document.querySelector('input[name="customer_aadhar"]').value;
    var nomineeAadhar = document.querySelector('input[name="nominee_aadhar"]').value;
    
    if (customerNumber && !/^\d{10}$/.test(customerNumber)) {
        e.preventDefault();
        alert('Customer number must be a 10-digit number');
        return false;
    }
    
    if (nomineeNumber && !/^\d{10}$/.test(nomineeNumber)) {
        e.preventDefault();
        alert('Nominee number must be a 10-digit number');
        return false;
    }
    
    if (customerAadhar && !/^\d{12}$/.test(customerAadhar)) {
        e.preventDefault();
        alert('Customer Aadhar must be a 12-digit number');
        return false;
    }
    
    if (nomineeAadhar && !/^\d{12}$/.test(nomineeAadhar)) {
        e.preventDefault();
        alert('Nominee Aadhar must be a 12-digit number');
        return false;
    }
});
</script>
</body>
</html>