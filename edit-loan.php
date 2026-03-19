<?php
require_once 'includes/auth.php';
$currentPage = 'loans';
$pageTitle = 'Edit Loan';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to edit loans
if (!function_exists('hasPermission') || !hasPermission('edit_loan')) {
    $_SESSION['error'] = 'You do not have permission to edit loans';
    header('Location: loans.php');
    exit();
}

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id == 0) {
    $_SESSION['error'] = 'Invalid loan ID';
    header('Location: loans.php');
    exit();
}

// Get loan details with role-based access
$loan_query = "SELECT l.*, f.finance_name 
               FROM loans l
               JOIN finance f ON l.finance_id = f.id
               WHERE l.id = ?";

if ($user_role != 'admin') {
    $loan_query .= " AND l.finance_id = ?";
}

$stmt = $conn->prepare($loan_query);
if ($user_role != 'admin') {
    $stmt->bind_param('ii', $loan_id, $finance_id);
} else {
    $stmt->bind_param('i', $loan_id);
}
$stmt->execute();
$loan_result = $stmt->get_result();

if ($loan_result->num_rows == 0) {
    $_SESSION['error'] = 'Loan not found or you do not have access';
    header('Location: loans.php');
    exit();
}

$loan = $loan_result->fetch_assoc();

// Get finance companies for dropdown (admin only)
$finance_companies = [];
if ($user_role == 'admin') {
    $finance_query = "SELECT id, finance_name FROM finance ORDER BY finance_name";
    $finance_result = $conn->query($finance_query);
    while ($row = $finance_result->fetch_assoc()) {
        $finance_companies[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $loan_name = trim($_POST['loan_name']);
    $loan_amount = floatval($_POST['loan_amount']);
    $interest_rate = floatval($_POST['interest_rate']);
    $loan_tenure = intval($_POST['loan_tenure']);
    $loan_documents = isset($_POST['loan_documents']) ? implode(', ', $_POST['loan_documents']) : '';
    $loan_purpose = trim($_POST['loan_purpose']);
    $finance_id = intval($_POST['finance_id'] ?? $loan['finance_id']);
    $status = $_POST['status'] ?? 'active';
    
    // Validate required fields
    $errors = [];
    
    if (empty($loan_name)) {
        $errors[] = 'Loan name is required';
    }
    if ($loan_amount <= 0) {
        $errors[] = 'Loan amount must be greater than 0';
    }
    if ($interest_rate <= 0) {
        $errors[] = 'Interest rate must be greater than 0';
    }
    if ($loan_tenure <= 0) {
        $errors[] = 'Loan tenure must be greater than 0';
    }
    
    // If no errors, update database
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $update_query = "UPDATE loans SET 
                loan_name = ?,
                loan_amount = ?,
                interest_rate = ?,
                loan_tenure = ?,
                loan_documents = ?,
                loan_purpose = ?";
            
            // Add finance_id update only for admin
            if ($user_role == 'admin') {
                $update_query .= ", finance_id = ?";
            }
            
            $update_query .= ", status = ? WHERE id = ?";
            
            $stmt = $conn->prepare($update_query);
            
            // Bind parameters based on user role
            if ($user_role == 'admin') {
                $stmt->bind_param('sddissisi', 
                    $loan_name,
                    $loan_amount,
                    $interest_rate,
                    $loan_tenure,
                    $loan_documents,
                    $loan_purpose,
                    $finance_id,
                    $status,
                    $loan_id
                );
            } else {
                $stmt->bind_param('sddissi', 
                    $loan_name,
                    $loan_amount,
                    $interest_rate,
                    $loan_tenure,
                    $loan_documents,
                    $loan_purpose,
                    $status,
                    $loan_id
                );
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update loan: ' . $stmt->error);
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                         VALUES (?, 'edit_loan', ?, ?, ?)";
            $stmt = $conn->prepare($log_query);
            $details = "Edited loan: $loan_name (ID: $loan_id)";
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $stmt->bind_param('isss', $_SESSION['user_id'], $details, $ip, $agent);
            $stmt->execute();
            
            $conn->commit();
            
            $_SESSION['success'] = 'Loan updated successfully';
            header("Location: view-loan.php?id=$loan_id");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Error updating loan: ' . $e->getMessage();
        }
    }
}

// Common document types
$document_types = [
    'Aadhar Card',
    'PAN Card',
    'Voter ID',
    'Driving License',
    'Passport',
    'Bank Statement',
    'Salary Slip',
    'Income Proof',
    'Property Documents',
    'Vehicle RC',
    'Insurance Paper',
    'GST Certificate',
    'Business Proof',
    'Address Proof',
    'Photographs'
];

// Parse existing documents
$existing_documents = !empty($loan['loan_documents']) ? explode(', ', $loan['loan_documents']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo htmlspecialchars($loan['loan_name']); ?></title>
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

        /* Document Checkbox Group */
        .document-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.75rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: var(--radius);
            max-height: 300px;
            overflow-y: auto;
        }

        .document-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .document-item:hover {
            border-color: var(--primary);
            background: #e6f0ff;
        }

        .document-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .document-item label {
            margin: 0;
            cursor: pointer;
            font-size: 0.9rem;
            color: var(--text-primary);
            flex: 1;
        }

        /* Calculation Result */
        .calculation-result {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .calculation-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .calculation-item:last-child {
            border-bottom: none;
        }

        .calculation-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .calculation-value {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .calculation-value.total {
            font-size: 1.5rem;
            color: #ffd700;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-badge.active {
            background: #e3f7e3;
            color: #2ecc71;
        }

        .status-badge.inactive {
            background: #ffe6e6;
            color: #e74c3c;
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
            .document-group {
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
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="bi bi-pencil-square me-2"></i>Edit Loan</h4>
                        <p>Update loan product details and requirements</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Loan Details
                        </a>
                        <a href="loans.php" class="btn btn-light">
                            <i class="bi bi-list-ul me-2"></i>All Loans
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
            <form method="POST" id="editLoanForm">
                <div class="form-card">
                    
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Basic Information
                        </h5>
                        
                        <div class="row g-3">
                            <?php if ($user_role == 'admin'): ?>
                            <div class="col-md-6">
                                <label class="form-label">Finance Company *</label>
                                <select class="form-select" name="finance_id" id="finance_id" required>
                                    <option value="">Select Finance Company</option>
                                    <?php foreach ($finance_companies as $fc): ?>
                                        <option value="<?php echo $fc['id']; ?>" 
                                            <?php echo $fc['id'] == $loan['finance_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($fc['finance_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            
                            <div class="col-md-6">
                                <label class="form-label">Loan Name *</label>
                                <input type="text" class="form-control" name="loan_name" 
                                       value="<?php echo htmlspecialchars($loan['loan_name']); ?>" 
                                       placeholder="e.g., Personal Loan, Home Loan, etc." required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" <?php echo $loan['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $loan['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
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
                                <label class="form-label">Loan Amount *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="1000" min="1000" class="form-control" 
                                           name="loan_amount" id="loan_amount" 
                                           value="<?php echo $loan['loan_amount']; ?>" required>
                                </div>
                                <small class="text-muted">Enter loan amount in rupees</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Interest Rate (%) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" step="0.1" min="0.1" class="form-control" 
                                           name="interest_rate" id="interest_rate" 
                                           value="<?php echo $loan['interest_rate']; ?>" required>
                                </div>
                                <small class="text-muted">Annual interest rate</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Loan Tenure (Months) *</label>
                                <input type="number" min="1" max="360" class="form-control" 
                                       name="loan_tenure" id="loan_tenure" 
                                       value="<?php echo $loan['loan_tenure']; ?>" required>
                                <small class="text-muted">Repayment period in months</small>
                            </div>
                        </div>

                        <!-- Calculation Summary -->
                        <div class="calculation-result mt-4">
                            <h6 class="text-white mb-3">Loan Calculation Summary</h6>
                            <div class="calculation-item">
                                <span class="calculation-label">Principal Amount</span>
                                <span class="calculation-value" id="calc_principal">₹<?php echo number_format($loan['loan_amount'], 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Interest Rate</span>
                                <span class="calculation-value" id="calc_rate"><?php echo $loan['interest_rate']; ?>%</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Loan Tenure</span>
                                <span class="calculation-value" id="calc_tenure"><?php echo $loan['loan_tenure']; ?> months</span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Interest</span>
                                <span class="calculation-value" id="calc_interest">₹<?php echo number_format(($loan['loan_amount'] * $loan['interest_rate'] / 100) * $loan['loan_tenure'], 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Payable</span>
                                <span class="calculation-value total" id="calc_total">₹<?php 
                                    $total = $loan['loan_amount'] + (($loan['loan_amount'] * $loan['interest_rate'] / 100) * $loan['loan_tenure']);
                                    echo number_format($total, 2); 
                                ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Monthly EMI</span>
                                <span class="calculation-value" id="calc_emi">₹<?php 
                                    $emi = $total / $loan['loan_tenure'];
                                    echo number_format($emi, 2); 
                                ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Required Documents Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-files"></i>
                            Required Documents
                        </h5>
                        
                        <div class="mb-3">
                            <label class="form-label">Select required documents for this loan</label>
                            <div class="document-group">
                                <?php foreach ($document_types as $doc): ?>
                                    <div class="document-item">
                                        <input type="checkbox" name="loan_documents[]" 
                                               value="<?php echo $doc; ?>" 
                                               id="doc_<?php echo str_replace(' ', '_', $doc); ?>"
                                               <?php echo in_array($doc, $existing_documents) ? 'checked' : ''; ?>>
                                        <label for="doc_<?php echo str_replace(' ', '_', $doc); ?>">
                                            <?php echo $doc; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Custom Document (if not listed above)</label>
                            <input type="text" class="form-control" id="custom_document" placeholder="Enter custom document name">
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addCustomDocument()">
                                <i class="bi bi-plus-circle me-1"></i>Add Custom Document
                            </button>
                        </div>
                    </div>

                    <!-- Loan Purpose Section -->
                    <div class="form-section">
                        <h5 class="section-title">
                            <i class="bi bi-chat"></i>
                            Loan Purpose & Description
                        </h5>
                        
                        <div class="mb-3">
                            <label class="form-label">Loan Purpose / Description</label>
                            <textarea class="form-control" name="loan_purpose" rows="4" 
                                      placeholder="Describe the purpose of this loan, eligibility criteria, or any special notes..."><?php echo htmlspecialchars($loan['loan_purpose']); ?></textarea>
                            <small class="text-muted">Optional: Add details about loan usage, eligibility, or special conditions</small>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Update Loan
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
    // Update calculations when inputs change
    $('#loan_amount, #interest_rate, #loan_tenure').on('input', function() {
        updateCalculations();
    });

    function updateCalculations() {
        var amount = parseFloat($('#loan_amount').val()) || 0;
        var rate = parseFloat($('#interest_rate').val()) || 0;
        var tenure = parseFloat($('#loan_tenure').val()) || 1;
        
        var totalInterest = (amount * rate / 100) * tenure;
        var totalPayable = amount + totalInterest;
        var monthlyEMI = totalPayable / tenure;
        
        $('#calc_principal').text('₹' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        $('#calc_rate').text(rate.toFixed(2) + '%');
        $('#calc_tenure').text(tenure + ' months');
        $('#calc_interest').text('₹' + totalInterest.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        $('#calc_total').text('₹' + totalPayable.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
        $('#calc_emi').text('₹' + monthlyEMI.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','));
    }

    // Initialize with default values
    updateCalculations();
});

// Add custom document to the list
function addCustomDocument() {
    var customDoc = document.getElementById('custom_document').value.trim();
    
    if (customDoc === '') {
        alert('Please enter a custom document name');
        return;
    }
    
    // Check if document already exists
    var exists = false;
    document.querySelectorAll('.document-item label').forEach(function(label) {
        if (label.textContent === customDoc) {
            exists = true;
        }
    });
    
    if (exists) {
        alert('This document is already in the list');
        return;
    }
    
    // Create new document item
    var docId = 'doc_' + customDoc.replace(/[^a-zA-Z0-9]/g, '_');
    var html = '<div class="document-item">' +
               '<input type="checkbox" name="loan_documents[]" value="' + customDoc + '" id="' + docId + '" checked>' +
               '<label for="' + docId + '">' + customDoc + '</label>' +
               '</div>';
    
    // Add to document group
    document.querySelector('.document-group').insertAdjacentHTML('beforeend', html);
    
    // Clear input
    document.getElementById('custom_document').value = '';
}

// Form validation
document.getElementById('editLoanForm')?.addEventListener('submit', function(e) {
    var loanName = document.querySelector('input[name="loan_name"]').value.trim();
    var loanAmount = parseFloat(document.querySelector('input[name="loan_amount"]').value);
    var interestRate = parseFloat(document.querySelector('input[name="interest_rate"]').value);
    var loanTenure = parseInt(document.querySelector('input[name="loan_tenure"]').value);
    
    if (loanName === '') {
        e.preventDefault();
        alert('Please enter a loan name');
        return false;
    }
    
    if (isNaN(loanAmount) || loanAmount <= 0) {
        e.preventDefault();
        alert('Please enter a valid loan amount');
        return false;
    }
    
    if (isNaN(interestRate) || interestRate <= 0) {
        e.preventDefault();
        alert('Please enter a valid interest rate');
        return false;
    }
    
    if (isNaN(loanTenure) || loanTenure <= 0) {
        e.preventDefault();
        alert('Please enter a valid loan tenure');
        return false;
    }
    
    return confirm('Are you sure you want to update this loan?');
});
</script>
</body>
</html>