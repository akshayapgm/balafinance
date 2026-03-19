<?php
require_once 'includes/auth.php';
$currentPage = 'add-loan';
$pageTitle = 'Add New Loan';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to add loans
if ($user_role != 'admin' && $user_role != 'staff' && $user_role != 'accountant') {
    $error = "You don't have permission to add loans.";
}

$message = '';
$error = isset($error) ? $error : '';

// Get finance companies for dropdown (only for admin)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get existing loan types for reference
$loan_types = $conn->query("SELECT id, loan_name, loan_amount, interest_rate, loan_tenure FROM loans ORDER BY loan_name");

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)) {
    // Sanitize inputs
    $loan_name = mysqli_real_escape_string($conn, $_POST['loan_name']);
    $loan_amount = floatval($_POST['loan_amount']);
    $interest_rate = floatval($_POST['interest_rate']);
    $loan_tenure = intval($_POST['loan_tenure']);
    $loan_documents = mysqli_real_escape_string($conn, $_POST['loan_documents']);
    $loan_purpose = mysqli_real_escape_string($conn, $_POST['loan_purpose']);
    $selected_finance_id = ($user_role == 'admin' && !empty($_POST['finance_id'])) ? intval($_POST['finance_id']) : $finance_id;
    
    // Validation
    $errors = [];
    
    if (empty($loan_name)) {
        $errors[] = "Loan name is required.";
    }
    
    if ($loan_amount <= 0) {
        $errors[] = "Loan amount must be greater than 0.";
    }
    
    if ($interest_rate <= 0) {
        $errors[] = "Interest rate must be greater than 0.";
    }
    
    if ($loan_tenure <= 0) {
        $errors[] = "Loan tenure must be greater than 0.";
    }
    
    // Check if finance company exists (for admin)
    if ($user_role == 'admin' && !empty($_POST['finance_id'])) {
        $check_finance = "SELECT id FROM finance WHERE id = ?";
        $stmt = $conn->prepare($check_finance);
        $stmt->bind_param("i", $selected_finance_id);
        $stmt->execute();
        $finance_result = $stmt->get_result();
        if ($finance_result->num_rows == 0) {
            $errors[] = "Selected finance company does not exist.";
        }
        $stmt->close();
    }
    
    // If no errors, insert loan
    if (empty($errors)) {
        $insert_query = "INSERT INTO loans (loan_name, loan_amount, interest_rate, loan_tenure, loan_documents, loan_purpose, finance_id, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sdiissi", $loan_name, $loan_amount, $interest_rate, $loan_tenure, $loan_documents, $loan_purpose, $selected_finance_id);
        
        if ($stmt->execute()) {
            $loan_id = $stmt->insert_id;
            
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'add_loan', "Added new loan: $loan_name (ID: $loan_id)");
            }
            
            $message = "Loan added successfully!";
            
            // Clear form data
            $_POST = [];
        } else {
            $error = "Error adding loan: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get total loans count for display
$count_query = "SELECT COUNT(*) as total FROM loans";
$count_result = $conn->query($count_query);
$total_loans = $count_result ? $count_result->fetch_assoc()['total'] : 0;
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
            --primary-bg: #e6f0ff;
            --success-bg: #e3f7e3;
            --warning-bg: #fff3e0;
            --danger-bg: #ffe6e6;
            --info-bg: #e3f2fd;
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
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        
        .welcome-banner h3 {
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            margin-bottom: 0;
        }
        
        .welcome-banner .btn-light {
            background: white;
            color: var(--primary);
            border: none;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            border-radius: 30px;
        }
        
        .welcome-banner .btn-light:hover {
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
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }
        
        .form-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        
        .form-header {
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .form-header h2 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .form-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 0.6rem 1rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .input-group-text {
            border: 2px solid #eef2f6;
            border-radius: 12px 0 0 12px;
            background: #f8f9fa;
            color: var(--text-muted);
        }
        
        /* Stat Cards */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            height: 100%;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-icon.blue {
            background: var(--primary-bg);
            color: var(--primary);
        }
        
        .stat-icon.green {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .stat-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
        }
        
        .stat-footer {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.85rem;
        }
        
        /* Alert */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* Info Box */
        .info-box {
            background: var(--info-bg);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--info);
        }
        
        .info-box i {
            margin-right: 0.5rem;
        }
        
        /* Document Tags */
        .document-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .document-tag {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 0.25rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .document-tag:hover {
            background: var(--primary-bg);
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .document-tag.selected {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Preview Card */
        .preview-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border: 1px dashed var(--primary);
        }
        
        .preview-card h6 {
            color: var(--text-primary);
            margin-bottom: 1rem;
        }
        
        .preview-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .preview-item:last-child {
            border-bottom: none;
        }
        
        .preview-label {
            font-weight: 600;
            color: var(--text-muted);
            width: 120px;
        }
        
        .preview-value {
            color: var(--text-primary);
            flex: 1;
        }
        
        /* Action Buttons */
        .btn-submit {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .btn-reset {
            background: #f8f9fa;
            color: var(--text-primary);
            border: 2px solid #eef2f6;
            border-radius: 12px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .btn-reset:hover {
            background: #e9ecef;
            border-color: #dee2e6;
        }
        
        /* Loan Type Cards */
        .loan-type-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 100%;
        }
        
        .loan-type-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }
        
        .loan-type-card.selected {
            border-color: var(--primary);
            background: var(--primary-bg);
        }
        
        .loan-type-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .loan-type-details {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .preview-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            .preview-label {
                width: auto;
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
            <!-- Welcome Banner -->
            <div class="welcome-banner" data-testid="welcome-banner">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3><i class="bi bi-cash-stack me-2"></i>Add New Loan</h3>
                        <p>Create a new loan product for your customers</p>
                    </div>
                    <a href="loans.php" class="btn btn-light">
                        <i class="bi bi-arrow-left me-2"></i>Back to Loans
                    </a>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $message; ?>
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

            <!-- Statistics Cards -->
            <div class="row g-3 mb-4" data-testid="stats-cards">
                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Total Loans</div>
                                <div class="stat-value"><?php echo number_format($total_loans); ?></div>
                            </div>
                            <div class="stat-icon blue"><i class="bi bi-cash-stack"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted">Available loan products</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Your Role</div>
                                <div class="stat-value" style="font-size: 1.5rem;"><?php echo ucfirst($user_role); ?></div>
                            </div>
                            <div class="stat-icon green"><i class="bi bi-person-badge"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted"><?php echo $user_role == 'admin' ? 'Full access' : ($user_role == 'accountant' ? 'Financial access' : 'Limited access'); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Finance Company</div>
                                <div class="stat-value" style="font-size: 1.5rem;">
                                    <?php 
                                    if ($user_role == 'admin') {
                                        echo 'All Companies';
                                    } else {
                                        $finfo = $conn->query("SELECT finance_name FROM finance WHERE id = $finance_id");
                                        echo $finfo ? $finfo->fetch_assoc()['finance_name'] : 'Not Assigned';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="stat-icon purple"><i class="bi bi-building"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted">Managing loans for</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Form Card -->
            <div class="form-card">
                <div class="form-header">
                    <h2><i class="bi bi-file-text me-2"></i>Loan Details</h2>
                    <p>Fill in the information below to create a new loan product</p>
                </div>

                <!-- Quick Loan Templates -->
                <?php if ($loan_types && $loan_types->num_rows > 0): ?>
                <div class="mb-4">
                    <label class="form-label">Quick Select from Existing Loans</label>
                    <div class="row g-2">
                        <?php while ($loan = $loan_types->fetch_assoc()): ?>
                        <div class="col-md-4">
                            <div class="loan-type-card" onclick="selectLoanTemplate(<?php echo htmlspecialchars(json_encode($loan)); ?>)">
                                <div class="loan-type-name"><?php echo htmlspecialchars($loan['loan_name']); ?></div>
                                <div class="loan-type-details">
                                    ₹<?php echo number_format($loan['loan_amount'], 2); ?> | 
                                    <?php echo $loan['interest_rate']; ?>% | 
                                    <?php echo $loan['loan_tenure']; ?> months
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="addLoanForm" onsubmit="return validateForm()">
                    <div class="row g-3">
                        <!-- Loan Name -->
                        <div class="col-md-8">
                            <label for="loan_name" class="form-label">
                                <i class="bi bi-tag me-1"></i>Loan Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="loan_name" name="loan_name" 
                                   value="<?php echo htmlspecialchars($_POST['loan_name'] ?? ''); ?>" 
                                   required maxlength="255" 
                                   placeholder="e.g., Personal Loan, Vehicle Loan, Gold Loan"
                                   onkeyup="updatePreview()">
                            <div class="form-text">Enter a descriptive name for the loan product</div>
                        </div>

                        <!-- Finance Company (for admin only) -->
                        <?php if ($user_role == 'admin'): ?>
                        <div class="col-md-4">
                            <label for="finance_id" class="form-label">
                                <i class="bi bi-building me-1"></i>Finance Company
                            </label>
                            <select class="form-select" id="finance_id" name="finance_id">
                                <option value="">All Companies</option>
                                <?php if ($finance_companies && $finance_companies->num_rows > 0): ?>
                                    <?php while ($fc = $finance_companies->fetch_assoc()): ?>
                                        <option value="<?php echo $fc['id']; ?>" 
                                            <?php echo (isset($_POST['finance_id']) && $_POST['finance_id'] == $fc['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fc['finance_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">Assign to specific finance company (optional)</div>
                        </div>
                        <?php endif; ?>

                        <!-- Loan Amount -->
                        <div class="col-md-4">
                            <label for="loan_amount" class="form-label">
                                <i class="bi bi-cash me-1"></i>Loan Amount <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" class="form-control" id="loan_amount" name="loan_amount" 
                                       value="<?php echo htmlspecialchars($_POST['loan_amount'] ?? ''); ?>" 
                                       required min="1" step="0.01" 
                                       placeholder="100000"
                                       onkeyup="calculateEMI(); updatePreview();">
                            </div>
                        </div>

                        <!-- Interest Rate -->
                        <div class="col-md-4">
                            <label for="interest_rate" class="form-label">
                                <i class="bi bi-percent me-1"></i>Interest Rate (%) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="interest_rate" name="interest_rate" 
                                       value="<?php echo htmlspecialchars($_POST['interest_rate'] ?? ''); ?>" 
                                       required min="0.1" max="100" step="0.01" 
                                       placeholder="2.0"
                                       onkeyup="calculateEMI(); updatePreview();">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>

                        <!-- Loan Tenure -->
                        <div class="col-md-4">
                            <label for="loan_tenure" class="form-label">
                                <i class="bi bi-calendar-month me-1"></i>Loan Tenure <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="loan_tenure" name="loan_tenure" 
                                       value="<?php echo htmlspecialchars($_POST['loan_tenure'] ?? ''); ?>" 
                                       required min="1" max="360" 
                                       placeholder="24"
                                       onkeyup="calculateEMI(); updatePreview();">
                                <span class="input-group-text">months</span>
                            </div>
                        </div>

                        <!-- EMI Calculator Display -->
                        <div class="col-12">
                            <div class="info-box" id="emiCalculator" style="display: none;">
                                <div class="row">
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Monthly EMI</small>
                                        <strong class="h5" id="emiAmount">₹0.00</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Total Interest</small>
                                        <strong class="h5" id="totalInterest">₹0.00</strong>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block">Total Payment</small>
                                        <strong class="h5" id="totalPayment">₹0.00</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Required Documents -->
                        <div class="col-12">
                            <label for="loan_documents" class="form-label">
                                <i class="bi bi-file-earmark-text me-1"></i>Required Documents
                            </label>
                            <input type="text" class="form-control" id="loan_documents" name="loan_documents" 
                                   value="<?php echo htmlspecialchars($_POST['loan_documents'] ?? ''); ?>" 
                                   placeholder="Aadhar Card, PAN Card, Income Proof, etc."
                                   onkeyup="updatePreview()">
                            <div class="document-tags">
                                <span class="document-tag" onclick="addDocument('Aadhar Card')">Aadhar Card</span>
                                <span class="document-tag" onclick="addDocument('PAN Card')">PAN Card</span>
                                <span class="document-tag" onclick="addDocument('Voter ID')">Voter ID</span>
                                <span class="document-tag" onclick="addDocument('Driving License')">Driving License</span>
                                <span class="document-tag" onclick="addDocument('Passport')">Passport</span>
                                <span class="document-tag" onclick="addDocument('Income Proof')">Income Proof</span>
                                <span class="document-tag" onclick="addDocument('Bank Statement')">Bank Statement</span>
                                <span class="document-tag" onclick="addDocument('Property Documents')">Property Documents</span>
                            </div>
                            <div class="form-text">Comma-separated list of required documents</div>
                        </div>

                        <!-- Loan Purpose -->
                        <div class="col-12">
                            <label for="loan_purpose" class="form-label">
                                <i class="bi bi-question-circle me-1"></i>Loan Purpose
                            </label>
                            <textarea class="form-control" id="loan_purpose" name="loan_purpose" 
                                      rows="3" placeholder="Describe the purpose of this loan (optional)"
                                      onkeyup="updatePreview()"><?php echo htmlspecialchars($_POST['loan_purpose'] ?? ''); ?></textarea>
                        </div>

                        <!-- Preview Section -->
                        <div class="col-12">
                            <div class="preview-card" id="previewCard" style="display: none;">
                                <h6><i class="bi bi-eye me-2"></i>Loan Preview</h6>
                                <div class="preview-item">
                                    <span class="preview-label">Loan Name:</span>
                                    <span class="preview-value" id="previewName">—</span>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">Amount:</span>
                                    <span class="preview-value" id="previewAmount">—</span>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">Interest Rate:</span>
                                    <span class="preview-value" id="previewRate">—</span>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">Tenure:</span>
                                    <span class="preview-value" id="previewTenure">—</span>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">EMI:</span>
                                    <span class="preview-value" id="previewEMI">—</span>
                                </div>
                                <div class="preview-item">
                                    <span class="preview-label">Documents:</span>
                                    <span class="preview-value" id="previewDocs">—</span>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Information -->
                        <div class="col-12">
                            <div class="info-box">
                                <i class="bi bi-info-circle"></i>
                                <strong>Important Notes:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Loan name should be unique and descriptive</li>
                                    <li>Interest rate is per month (e.g., 2% means 2% per month)</li>
                                    <li>Loan tenure is in months</li>
                                    <li>Required documents can be selected from the tags above</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="col-12 mt-4">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <button type="submit" class="btn btn-submit">
                                        <i class="bi bi-check-circle me-2"></i>Create Loan
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <button type="reset" class="btn btn-reset" onclick="resetForm()">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>Reset Form
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Quick Tips -->
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="stat-icon blue" style="width: 40px; height: 40px; font-size: 1.25rem;">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <h6 class="mb-0">Loan Amount</h6>
                            </div>
                            <p class="small text-muted mb-0">Set appropriate loan amounts based on customer requirements and company policies.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="stat-icon green" style="width: 40px; height: 40px; font-size: 1.25rem;">
                                    <i class="bi bi-percent"></i>
                                </div>
                                <h6 class="mb-0">Interest Rate</h6>
                            </div>
                            <p class="small text-muted mb-0">Monthly interest rate. Total interest will be calculated based on tenure.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="stat-icon purple" style="width: 40px; height: 40px; font-size: 1.25rem;">
                                    <i class="bi bi-file-text"></i>
                                </div>
                                <h6 class="mb-0">Documents</h6>
                            </div>
                            <p class="small text-muted mb-0">Specify all required documents to streamline the loan application process.</p>
                        </div>
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
    // Calculate EMI
    function calculateEMI() {
        const amount = parseFloat(document.getElementById('loan_amount').value) || 0;
        const rate = parseFloat(document.getElementById('interest_rate').value) || 0;
        const tenure = parseInt(document.getElementById('loan_tenure').value) || 0;
        
        const emiCalculator = document.getElementById('emiCalculator');
        
        if (amount > 0 && rate > 0 && tenure > 0) {
            const monthlyRate = rate / 100;
            const emi = amount * monthlyRate * Math.pow(1 + monthlyRate, tenure) / (Math.pow(1 + monthlyRate, tenure) - 1);
            const totalPayment = emi * tenure;
            const totalInterest = totalPayment - amount;
            
            document.getElementById('emiAmount').innerHTML = '₹' + emi.toFixed(2);
            document.getElementById('totalInterest').innerHTML = '₹' + totalInterest.toFixed(2);
            document.getElementById('totalPayment').innerHTML = '₹' + totalPayment.toFixed(2);
            
            emiCalculator.style.display = 'block';
        } else {
            emiCalculator.style.display = 'none';
        }
    }
    
    // Add document tag to input
    function addDocument(doc) {
        const input = document.getElementById('loan_documents');
        const currentVal = input.value;
        
        if (currentVal.includes(doc)) {
            // Remove if already exists
            input.value = currentVal.split(',').map(d => d.trim()).filter(d => d !== doc).join(', ');
        } else {
            // Add new document
            if (currentVal) {
                input.value = currentVal + ', ' + doc;
            } else {
                input.value = doc;
            }
        }
        updatePreview();
    }
    
    // Select loan template
    function selectLoanTemplate(loan) {
        document.getElementById('loan_name').value = loan.loan_name;
        document.getElementById('loan_amount').value = loan.loan_amount;
        document.getElementById('interest_rate').value = loan.interest_rate;
        document.getElementById('loan_tenure').value = loan.loan_tenure;
        
        calculateEMI();
        updatePreview();
    }
    
    // Update preview
    function updatePreview() {
        const name = document.getElementById('loan_name').value;
        const amount = document.getElementById('loan_amount').value;
        const rate = document.getElementById('interest_rate').value;
        const tenure = document.getElementById('loan_tenure').value;
        const docs = document.getElementById('loan_documents').value;
        const previewCard = document.getElementById('previewCard');
        
        if (name || amount || rate || tenure || docs) {
            previewCard.style.display = 'block';
            
            document.getElementById('previewName').textContent = name || '—';
            document.getElementById('previewAmount').textContent = amount ? '₹' + parseFloat(amount).toFixed(2) : '—';
            document.getElementById('previewRate').textContent = rate ? rate + '%' : '—';
            document.getElementById('previewTenure').textContent = tenure ? tenure + ' months' : '—';
            
            // Calculate EMI for preview
            if (amount && rate && tenure) {
                const monthlyRate = parseFloat(rate) / 100;
                const emi = parseFloat(amount) * monthlyRate * Math.pow(1 + monthlyRate, parseInt(tenure)) / (Math.pow(1 + monthlyRate, parseInt(tenure)) - 1);
                document.getElementById('previewEMI').innerHTML = '₹' + emi.toFixed(2);
            } else {
                document.getElementById('previewEMI').innerHTML = '—';
            }
            
            document.getElementById('previewDocs').textContent = docs || '—';
        } else {
            previewCard.style.display = 'none';
        }
    }
    
    // Validate form before submission
    function validateForm() {
        const name = document.getElementById('loan_name').value.trim();
        const amount = document.getElementById('loan_amount').value.trim();
        const rate = document.getElementById('interest_rate').value.trim();
        const tenure = document.getElementById('loan_tenure').value.trim();
        
        if (name === '') {
            alert('Please enter loan name');
            document.getElementById('loan_name').focus();
            return false;
        }
        
        if (amount === '' || parseFloat(amount) <= 0) {
            alert('Please enter valid loan amount');
            document.getElementById('loan_amount').focus();
            return false;
        }
        
        if (rate === '' || parseFloat(rate) <= 0) {
            alert('Please enter valid interest rate');
            document.getElementById('interest_rate').focus();
            return false;
        }
        
        if (tenure === '' || parseInt(tenure) <= 0) {
            alert('Please enter valid loan tenure');
            document.getElementById('loan_tenure').focus();
            return false;
        }
        
        return true;
    }
    
    // Reset form
    function resetForm() {
        document.getElementById('addLoanForm').reset();
        document.getElementById('previewCard').style.display = 'none';
        document.getElementById('emiCalculator').style.display = 'none';
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
</script>
</body>
</html>