<?php
require_once 'includes/auth.php';
$currentPage = 'investors';
$pageTitle = 'Edit Investor';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;
$user_id = $_SESSION['user_id'];

// Check if user has permission to edit investors
if ($user_role != 'admin') {
    $error = "You don't have permission to edit investors.";
}

// Check if investor ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: investors.php');
    exit();
}

$investor_id = intval($_GET['id']);

// Get investor details
$investor_query = "SELECT i.*, f.finance_name 
                  FROM investors i
                  JOIN finance f ON i.finance_id = f.id
                  WHERE i.id = ?";

if ($user_role != 'admin') {
    $investor_query .= " AND i.finance_id = ?";
    $stmt = $conn->prepare($investor_query);
    $stmt->bind_param('ii', $investor_id, $finance_id);
} else {
    $stmt = $conn->prepare($investor_query);
    $stmt->bind_param('i', $investor_id);
}

$stmt->execute();
$investor = $stmt->get_result()->fetch_assoc();

if (!$investor) {
    header('Location: investors.php');
    exit();
}

$message = '';
$error = '';

// Get finance companies for dropdown
$finance_query = "SELECT id, finance_name FROM finance ORDER BY finance_name";
$finance_companies = $conn->query($finance_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate required fields
    $investor_name = trim($_POST['investor_name'] ?? '');
    $investor_number = trim($_POST['investor_number'] ?? '');
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $address = isset($_POST['address']) ? trim($_POST['address']) : null;
    $pan_number = isset($_POST['pan_number']) ? trim($_POST['pan_number']) : null;
    $aadhar_number = isset($_POST['aadhar_number']) ? trim($_POST['aadhar_number']) : null;
    
    $investment_amount = isset($_POST['investment_amount']) ? floatval($_POST['investment_amount']) : 0;
    $interest_rate = isset($_POST['interest_rate']) ? floatval($_POST['interest_rate']) : 0;
    $investment_date = $_POST['investment_date'] ?? date('Y-m-d');
    $maturity_date = isset($_POST['maturity_date']) ? $_POST['maturity_date'] : null;
    $return_type = $_POST['return_type'] ?? 'maturity';
    $return_amount = isset($_POST['return_amount']) ? floatval($_POST['return_amount']) : 0;
    $status = $_POST['status'] ?? 'active';
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
    $investor_finance_id = isset($_POST['finance_id']) ? intval($_POST['finance_id']) : 0;
    
    // Validate
    if (empty($investor_name)) {
        $error = 'Investor name is required';
    } elseif (empty($investor_number)) {
        $error = 'Investor phone number is required';
    } elseif ($investment_amount <= 0) {
        $error = 'Please enter a valid investment amount';
    } elseif ($interest_rate <= 0) {
        $error = 'Please enter a valid interest rate';
    } elseif (empty($investment_date)) {
        $error = 'Investment date is required';
    } elseif ($user_role != 'admin' && $investor_finance_id != $finance_id) {
        $error = 'Invalid finance company selected';
    }
    
    // Calculate return amount based on type if not provided
    if ($return_amount <= 0) {
        if ($return_type == 'monthly') {
            $return_amount = ($investment_amount * $interest_rate / 100) / 12;
        } elseif ($return_type == 'quarterly') {
            $return_amount = ($investment_amount * $interest_rate / 100) / 4;
        } elseif ($return_type == 'yearly') {
            $return_amount = ($investment_amount * $interest_rate / 100);
        }
    }
    
    if (empty($error)) {
        // Update investor
        $update_query = "UPDATE investors SET 
            investor_name = ?,
            investor_number = ?,
            email = ?,
            address = ?,
            pan_number = ?,
            aadhar_number = ?,
            investment_amount = ?,
            interest_rate = ?,
            investment_date = ?,
            maturity_date = ?,
            return_type = ?,
            return_amount = ?,
            status = ?,
            remarks = ?,
            finance_id = ?,
            updated_at = NOW()
            WHERE id = ?";
        
        $stmt = $conn->prepare($update_query);
        
        if ($stmt) {
            $stmt->bind_param(
                'ssssssddssssdsii',
                $investor_name,
                $investor_number,
                $email,
                $address,
                $pan_number,
                $aadhar_number,
                $investment_amount,
                $interest_rate,
                $investment_date,
                $maturity_date,
                $return_type,
                $return_amount,
                $status,
                $remarks,
                $investor_finance_id,
                $investor_id
            );
            
            if ($stmt->execute()) {
                // Log activity
                if (function_exists('logActivity')) {
                    logActivity($conn, 'edit_investor', "Updated investor: $investor_name (ID: $investor_id)");
                }
                
                $_SESSION['success'] = 'Investor updated successfully';
                header('Location: view-investor.php?id=' . $investor_id);
                exit();
            } else {
                $error = 'Error updating investor: ' . $stmt->error;
                error_log("MySQL Error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $error = 'Error preparing statement: ' . $conn->error;
            error_log("Prepare Error: " . $conn->error);
        }
    }
}
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
            --primary-bg: #e6f0ff;
            --success-bg: #e3f7e3;
            --warning-bg: #fff3e0;
            --danger-bg: #ffe6e6;
            --info-bg: #e3f2fd;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            display: flex;
            flex-direction: column;
        }

        .app-wrapper {
            display: flex;
            flex: 1;
            min-height: 100vh;
            width: 100%;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .page-content {
            flex: 1;
            padding: 1.5rem;
            width: 100%;
            max-width: 100%;
            margin: 0;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-lg);
            width: 100%;
        }

        .page-header h4 {
            font-weight: 700;
            margin-bottom: 0.25rem;
            font-size: 1.5rem;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.9rem;
        }

        .page-header .btn-light {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 0.4rem 1.2rem;
            font-weight: 500;
            border-radius: 2rem;
            backdrop-filter: blur(10px);
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        .page-header .btn-light:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        /* Form Card */
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

        .info-note {
            background: var(--info-bg);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--info);
            border-left: 3px solid var(--info);
        }

        .input-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
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
                        <h4><i class="bi bi-pencil-square me-2"></i>Edit Investor</h4>
                        <p>Update investor information</p>
                    </div>
                    <div>
                        <a href="view-investor.php?id=<?php echo $investor_id; ?>" class="btn btn-light me-2">
                            <i class="bi bi-eye me-2"></i>View Investor
                        </a>
                        <a href="investors.php" class="btn btn-light">
                            <i class="bi bi-arrow-left me-2"></i>Back to Investors
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <form method="POST" action="" id="investorForm">
                    <!-- Investor Personal Information -->
                    <div class="form-section">
                        <h5><i class="bi bi-person-circle"></i>Personal Information</h5>
                        <div class="row g-3">
                            <?php if ($user_role == 'admin'): ?>
                            <div class="col-md-6">
                                <label class="form-label">Select Finance <span class="text-danger">*</span></label>
                                <select class="form-select" name="finance_id" required>
                                    <option value="">Select Finance Company</option>
                                    <?php if ($finance_companies): ?>
                                        <?php while ($fc = $finance_companies->fetch_assoc()): ?>
                                            <option value="<?php echo $fc['id']; ?>" <?php echo $investor['finance_id'] == $fc['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($fc['finance_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <?php else: ?>
                                <input type="hidden" name="finance_id" value="<?php echo $finance_id; ?>">
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label class="form-label">Investor Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="investor_name" value="<?php echo htmlspecialchars($investor['investor_name']); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="investor_number" pattern="[0-9]{10}" maxlength="10" value="<?php echo htmlspecialchars($investor['investor_number']); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($investor['email'] ?? ''); ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($investor['address'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">PAN Number</label>
                                <input type="text" class="form-control" name="pan_number" placeholder="ABCDE1234F" value="<?php echo htmlspecialchars($investor['pan_number'] ?? ''); ?>">
                                <div class="input-hint">
                                    <i class="bi bi-info-circle"></i> Format: ABCDE1234F
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Aadhar Number</label>
                                <input type="text" class="form-control" name="aadhar_number" pattern="[0-9]{12}" maxlength="12" placeholder="123456789012" value="<?php echo htmlspecialchars($investor['aadhar_number'] ?? ''); ?>">
                                <div class="input-hint">
                                    <i class="bi bi-info-circle"></i> 12-digit Aadhar number
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Investment Details -->
                    <div class="form-section">
                        <h5><i class="bi bi-cash-stack"></i>Investment Details</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Investment Amount (₹) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" name="investment_amount" id="investment_amount" value="<?php echo $investor['investment_amount']; ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Interest Rate (% per annum) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" class="form-control" name="interest_rate" id="interest_rate" value="<?php echo $investor['interest_rate']; ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Investment Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="investment_date" id="investment_date" value="<?php echo $investor['investment_date']; ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Maturity Date</label>
                                <input type="date" class="form-control" name="maturity_date" id="maturity_date" value="<?php echo $investor['maturity_date'] ?? ''; ?>">
                                <div class="input-hint">
                                    <i class="bi bi-info-circle"></i> Leave empty to keep current
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Return Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="return_type" id="return_type" required onchange="calculateReturn()">
                                    <option value="maturity" <?php echo $investor['return_type'] == 'maturity' ? 'selected' : ''; ?>>At Maturity</option>
                                    <option value="monthly" <?php echo $investor['return_type'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="quarterly" <?php echo $investor['return_type'] == 'quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                    <option value="yearly" <?php echo $investor['return_type'] == 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Return Amount (per period) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₹</span>
                                    <input type="number" step="0.01" class="form-control" name="return_amount" id="return_amount" value="<?php echo $investor['return_amount']; ?>" required>
                                </div>
                                <div class="input-hint">
                                    <i class="bi bi-info-circle"></i> Auto-calculated based on amount
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" <?php echo $investor['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="closed" <?php echo $investor['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Remarks (Optional)</label>
                                <textarea class="form-control" name="remarks" rows="2" placeholder="Any additional notes..."><?php echo htmlspecialchars($investor['remarks'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Calculation Display -->
                        <div class="calculation-box mt-3">
                            <h6 class="mb-3">Investment Summary</h6>
                            <div class="calculation-item">
                                <span class="calculation-label">Total Investment</span>
                                <span class="calculation-value" id="total_investment">₹<?php echo number_format($investor['investment_amount'], 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Annual Interest</span>
                                <span class="calculation-value" id="annual_interest">₹<?php echo number_format(($investor['investment_amount'] * $investor['interest_rate'] / 100), 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Monthly Interest</span>
                                <span class="calculation-value" id="monthly_interest">₹<?php echo number_format(($investor['investment_amount'] * $investor['interest_rate'] / 100 / 12), 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Quarterly Interest</span>
                                <span class="calculation-value" id="quarterly_interest">₹<?php echo number_format(($investor['investment_amount'] * $investor['interest_rate'] / 100 / 4), 2); ?></span>
                            </div>
                            <div class="calculation-item">
                                <span class="calculation-label">Yearly Interest</span>
                                <span class="calculation-value" id="yearly_interest">₹<?php echo number_format(($investor['investment_amount'] * $investor['interest_rate'] / 100), 2); ?></span>
                            </div>
                        </div>

                        <!-- Info Note -->
                        <div class="info-note mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Changing investment amount or interest rate will affect future return calculations. Existing return records will not be modified.
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="view-investor.php?id=<?php echo $investor_id; ?>" class="btn btn-secondary px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-5">
                            <i class="bi bi-save me-2"></i>Update Investor
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Calculate returns based on investment amount and interest rate
    function calculateReturn() {
        var amount = parseFloat(document.getElementById('investment_amount').value) || 0;
        var rate = parseFloat(document.getElementById('interest_rate').value) || 0;
        var returnType = document.getElementById('return_type').value;
        
        if (amount > 0 && rate > 0) {
            var annualInterest = (amount * rate) / 100;
            var monthlyInterest = annualInterest / 12;
            var quarterlyInterest = annualInterest / 4;
            
            // Update summary
            document.getElementById('total_investment').innerHTML = '₹' + amount.toFixed(2);
            document.getElementById('annual_interest').innerHTML = '₹' + annualInterest.toFixed(2);
            document.getElementById('monthly_interest').innerHTML = '₹' + monthlyInterest.toFixed(2);
            document.getElementById('quarterly_interest').innerHTML = '₹' + quarterlyInterest.toFixed(2);
            document.getElementById('yearly_interest').innerHTML = '₹' + annualInterest.toFixed(2);
            
            // Set return amount based on type
            var returnAmount = document.getElementById('return_amount').value;
            var suggestedAmount = 0;
            
            if (returnType === 'monthly') {
                suggestedAmount = monthlyInterest;
            } else if (returnType === 'quarterly') {
                suggestedAmount = quarterlyInterest;
            } else if (returnType === 'yearly') {
                suggestedAmount = annualInterest;
            } else {
                suggestedAmount = amount + annualInterest; // For maturity, total amount
            }
            
            // Only update if the field is empty or user hasn't manually changed it
            if (returnAmount == 0 || returnAmount == <?php echo $investor['return_amount']; ?>) {
                document.getElementById('return_amount').value = suggestedAmount.toFixed(2);
            }
        }
    }
    
    // Auto-calculate on input change
    document.getElementById('investment_amount').addEventListener('input', calculateReturn);
    document.getElementById('interest_rate').addEventListener('input', calculateReturn);
    document.getElementById('return_type').addEventListener('change', calculateReturn);
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        calculateReturn();
        
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
    
    // Form validation
    document.getElementById('investorForm').addEventListener('submit', function(e) {
        var investorName = document.querySelector('input[name="investor_name"]').value.trim();
        var phoneNumber = document.querySelector('input[name="investor_number"]').value.trim();
        var amount = parseFloat(document.getElementById('investment_amount').value) || 0;
        
        if (!investorName) {
            e.preventDefault();
            alert('Please enter investor name');
            return false;
        }
        
        if (!phoneNumber || phoneNumber.length !== 10) {
            e.preventDefault();
            alert('Please enter a valid 10-digit phone number');
            return false;
        }
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid investment amount');
            return false;
        }
        
        return confirm('Are you sure you want to update this investor?');
    });
</script>
</body>
</html>