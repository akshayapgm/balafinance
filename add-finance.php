<?php
require_once 'includes/auth.php';
$currentPage = 'add-finance';
$pageTitle = 'Add Finance Company';
require_once 'includes/db.php';

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Check if user has permission to add finance companies (only admin can add)
if ($user_role != 'admin') {
    $error = "You don't have permission to add finance companies. Only administrators can access this page.";
}

$message = '';
$error = isset($error) ? $error : '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $user_role == 'admin') {
    $finance_name = mysqli_real_escape_string($conn, $_POST['finance_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $pin = mysqli_real_escape_string($conn, $_POST['pin']);
    
    // Validation
    $errors = [];
    
    if (empty($finance_name)) {
        $errors[] = "Finance company name is required.";
    }
    
    // Check if finance company name already exists
    if (!empty($finance_name)) {
        $check_query = "SELECT id FROM finance WHERE finance_name = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("s", $finance_name);
        $stmt->execute();
        $check_result = $stmt->get_result();
        if ($check_result && $check_result->num_rows > 0) {
            $errors[] = "Finance company name already exists. Please choose another name.";
        }
        $stmt->close();
    }
    
    // Validate PIN (4 digits)
    if (empty($pin)) {
        $errors[] = "PIN is required.";
    } elseif (!preg_match('/^\d{4}$/', $pin)) {
        $errors[] = "PIN must be exactly 4 digits.";
    }
    
    // If no errors, insert finance company
    if (empty($errors)) {
        $insert_query = "INSERT INTO finance (finance_name, description, pin, created_at) 
                        VALUES (?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sss", $finance_name, $description, $pin);
        
        if ($stmt->execute()) {
            $finance_id = $stmt->insert_id;
            
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity($conn, 'add_finance', "Added new finance company: $finance_name (ID: $finance_id)");
            }
            
            $message = "Finance company added successfully!";
            
            // Clear form data
            $_POST = [];
        } else {
            $error = "Error adding finance company: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = implode("<br>", $errors);
    }
}

// Get total finance companies count for display
$count_query = "SELECT COUNT(*) as total FROM finance";
$count_result = $conn->query($count_query);
$total_finance = $count_result ? $count_result->fetch_assoc()['total'] : 0;
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
        
        .stat-icon.orange {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .stat-footer {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.85rem;
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
        
        /* PIN Input Group */
        .pin-input-group {
            position: relative;
        }
        
        .pin-input-group .form-control {
            padding-right: 60px;
        }
        
        .pin-input-group .pin-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            z-index: 10;
        }
        
        .pin-input-group .pin-toggle:hover {
            color: var(--primary);
        }
        
        /* Requirements List */
        .requirements-list {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        
        .requirements-list ul {
            margin-bottom: 0;
            padding-left: 1.2rem;
        }
        
        .requirements-list li {
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .requirements-list li.valid {
            color: var(--success);
        }
        
        .requirements-list li.invalid {
            color: var(--danger);
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
        
        /* Permission Denied */
        .permission-denied {
            text-align: center;
            padding: 3rem;
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
        }
        
        .permission-denied i {
            font-size: 3rem;
            color: var(--danger);
            margin-bottom: 1rem;
        }
        
        .permission-denied h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .permission-denied p {
            color: var(--text-muted);
            margin-bottom: 1.5rem;
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
                        <h3><i class="bi bi-building-add me-2"></i>Add Finance Company</h3>
                        <p>Create a new finance company to manage loans and customers</p>
                    </div>
                    <a href="finance.php" class="btn btn-light">
                        <i class="bi bi-arrow-left me-2"></i>Back to Finance Companies
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

            <!-- Check if user has permission -->
            <?php if ($user_role == 'admin'): ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4" data-testid="stats-cards">
                    <div class="col-sm-6 col-xl-4">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="stat-label">Total Companies</div>
                                    <div class="stat-value"><?php echo number_format($total_finance); ?></div>
                                </div>
                                <div class="stat-icon blue"><i class="bi bi-building"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Registered finance companies</span>
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
                                <div class="stat-icon green"><i class="bi bi-shield-lock"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Full system access</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-sm-6 col-xl-4">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="stat-label">Action</div>
                                    <div class="stat-value" style="font-size: 1.5rem;">Create New</div>
                                </div>
                                <div class="stat-icon purple"><i class="bi bi-plus-circle"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Adding new finance company</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Form Card -->
                <div class="form-card">
                    <div class="form-header">
                        <h2><i class="bi bi-building me-2"></i>Finance Company Details</h2>
                        <p>Fill in the information below to create a new finance company</p>
                    </div>

                    <form method="POST" action="" id="addFinanceForm" onsubmit="return validateForm()">
                        <div class="row g-3">
                            <!-- Finance Company Name -->
                            <div class="col-12">
                                <label for="finance_name" class="form-label">
                                    <i class="bi bi-building me-1"></i>Finance Company Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="finance_name" name="finance_name" 
                                       value="<?php echo htmlspecialchars($_POST['finance_name'] ?? ''); ?>" 
                                       required maxlength="255" 
                                       placeholder="e.g., SRI Sabarivasa Finance, ABC Finance, etc."
                                       onkeyup="updatePreview()">
                                <div class="form-text">Enter the full name of the finance company</div>
                            </div>

                            <!-- Description -->
                            <div class="col-12">
                                <label for="description" class="form-label">
                                    <i class="bi bi-card-text me-1"></i>Description
                                </label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="3" placeholder="Enter company description, address, or additional information"
                                          onkeyup="updatePreview()"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                <div class="form-text">Optional: Add any additional information about the finance company</div>
                            </div>

                            <!-- PIN -->
                            <div class="col-md-6">
                                <label for="pin" class="form-label">
                                    <i class="bi bi-key me-1"></i>PIN Code <span class="text-danger">*</span>
                                </label>
                                <div class="pin-input-group">
                                    <input type="password" class="form-control" id="pin" name="pin" 
                                           value="<?php echo htmlspecialchars($_POST['pin'] ?? ''); ?>" 
                                           required maxlength="4" pattern="\d{4}" 
                                           placeholder="1234"
                                           onkeyup="validatePIN(); updatePreview();">
                                    <button type="button" class="pin-toggle" onclick="togglePIN()">
                                        <i class="bi bi-eye" id="pinToggleIcon"></i>
                                    </button>
                                </div>
                                <div class="requirements-list">
                                    <small class="text-muted d-block mb-2">PIN requirements:</small>
                                    <ul id="pinRequirements">
                                        <li id="pinLength" class="<?php echo (isset($_POST['pin']) && strlen($_POST['pin']) == 4) ? 'valid' : 'invalid'; ?>">
                                            <?php echo (isset($_POST['pin']) && strlen($_POST['pin']) == 4) ? '✓' : '✗'; ?> Exactly 4 digits
                                        </li>
                                        <li id="pinDigits" class="<?php echo (isset($_POST['pin']) && ctype_digit($_POST['pin'])) ? 'valid' : 'invalid'; ?>">
                                            <?php echo (isset($_POST['pin']) && ctype_digit($_POST['pin'])) ? '✓' : '✗'; ?> Only numbers allowed
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Preview Section -->
                            <div class="col-12">
                                <div class="preview-card" id="previewCard" style="display: none;">
                                    <h6><i class="bi bi-eye me-2"></i>Company Preview</h6>
                                    <div class="preview-item">
                                        <span class="preview-label">Company Name:</span>
                                        <span class="preview-value" id="previewName">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">Description:</span>
                                        <span class="preview-value" id="previewDesc">—</span>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">PIN:</span>
                                        <span class="preview-value" id="previewPIN">••••</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Additional Information -->
                            <div class="col-12">
                                <div class="info-box">
                                    <i class="bi bi-info-circle"></i>
                                    <strong>Important Notes:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Finance company name must be unique</li>
                                        <li>PIN must be exactly 4 digits (numbers only)</li>
                                        <li>Staff and accountants can be assigned to this finance company</li>
                                        <li>Companies are active by default when created</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="col-12 mt-4">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <button type="submit" class="btn btn-submit">
                                            <i class="bi bi-check-circle me-2"></i>Create Finance Company
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
                                        <i class="bi bi-building"></i>
                                    </div>
                                    <h6 class="mb-0">Unique Name</h6>
                                </div>
                                <p class="small text-muted mb-0">Each finance company must have a unique name to avoid confusion in reports and assignments.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="stat-icon orange" style="width: 40px; height: 40px; font-size: 1.25rem;">
                                        <i class="bi bi-key"></i>
                                    </div>
                                    <h6 class="mb-0">Secure PIN</h6>
                                </div>
                                <p class="small text-muted mb-0">The 4-digit PIN is used for company-specific operations and must be kept secure.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="stat-icon green" style="width: 40px; height: 40px; font-size: 1.25rem;">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <h6 class="mb-0">User Assignment</h6>
                                </div>
                                <p class="small text-muted mb-0">Staff and accountants can be assigned to this company to manage its operations.</p>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Permission Denied Message -->
                <div class="permission-denied">
                    <i class="bi bi-shield-lock-fill"></i>
                    <h4>Access Denied</h4>
                    <p>You don't have permission to add finance companies. Only administrators can access this page.</p>
                    <a href="finance.php" class="btn btn-primary">
                        <i class="bi bi-building me-2"></i>View Finance Companies
                    </a>
                    <a href="index.php" class="btn btn-outline-primary ms-2">
                        <i class="bi bi-house-door me-2"></i>Go to Dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // Toggle PIN visibility
    function togglePIN() {
        const pinInput = document.getElementById('pin');
        const icon = document.getElementById('pinToggleIcon');
        
        if (pinInput.type === 'password') {
            pinInput.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            pinInput.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }
    
    // Validate PIN
    function validatePIN() {
        const pin = document.getElementById('pin').value;
        const lengthCheck = document.getElementById('pinLength');
        const digitsCheck = document.getElementById('pinDigits');
        
        // Check length
        if (pin.length === 4) {
            lengthCheck.className = 'valid';
            lengthCheck.innerHTML = '✓ Exactly 4 digits';
        } else {
            lengthCheck.className = 'invalid';
            lengthCheck.innerHTML = '✗ Exactly 4 digits';
        }
        
        // Check if only digits
        if (/^\d*$/.test(pin)) {
            digitsCheck.className = 'valid';
            digitsCheck.innerHTML = '✓ Only numbers allowed';
        } else {
            digitsCheck.className = 'invalid';
            digitsCheck.innerHTML = '✗ Only numbers allowed';
        }
    }
    
    // Update preview
    function updatePreview() {
        const name = document.getElementById('finance_name').value;
        const desc = document.getElementById('description').value;
        const pin = document.getElementById('pin').value;
        const previewCard = document.getElementById('previewCard');
        
        if (name || desc || pin) {
            previewCard.style.display = 'block';
            
            document.getElementById('previewName').textContent = name || '—';
            document.getElementById('previewDesc').textContent = desc || '—';
            document.getElementById('previewPIN').textContent = pin ? '••••' : '—';
        } else {
            previewCard.style.display = 'none';
        }
    }
    
    // Validate form before submission
    function validateForm() {
        const name = document.getElementById('finance_name').value.trim();
        const pin = document.getElementById('pin').value.trim();
        
        if (name === '') {
            alert('Please enter finance company name');
            document.getElementById('finance_name').focus();
            return false;
        }
        
        if (pin === '') {
            alert('Please enter PIN');
            document.getElementById('pin').focus();
            return false;
        }
        
        if (pin.length !== 4 || !/^\d+$/.test(pin)) {
            alert('PIN must be exactly 4 digits (numbers only)');
            document.getElementById('pin').focus();
            return false;
        }
        
        return true;
    }
    
    // Reset form
    function resetForm() {
        document.getElementById('addFinanceForm').reset();
        document.getElementById('previewCard').style.display = 'none';
        
        // Reset PIN validation
        document.getElementById('pinLength').className = 'invalid';
        document.getElementById('pinLength').innerHTML = '✗ Exactly 4 digits';
        document.getElementById('pinDigits').className = 'invalid';
        document.getElementById('pinDigits').innerHTML = '✗ Only numbers allowed';
        
        // Reset PIN visibility to password
        const pinInput = document.getElementById('pin');
        pinInput.type = 'password';
        const icon = document.getElementById('pinToggleIcon');
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Run validation on page load if there's a value
    window.onload = function() {
        const pin = document.getElementById('pin').value;
        if (pin) {
            validatePIN();
            updatePreview();
        }
    }
</script>
</body>
</html>