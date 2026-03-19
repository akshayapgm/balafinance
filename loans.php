<?php
require_once 'includes/auth.php';
$currentPage = 'loans';
$pageTitle = 'Loan Management';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to view loans
if ($user_role != 'admin' && $user_role != 'accountant' && $user_role != 'staff') {
    $error = "You don't have permission to view loans.";
}

$message = '';
$error = isset($error) ? $error : '';

// Handle loan deletion (only admin can delete)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $user_role == 'admin') {
    $delete_id = intval($_GET['delete']);
    
    // Check if loan has associated customers
    $check_customers = "SELECT COUNT(*) as customer_count FROM customers WHERE loan_id = ?";
    $stmt = $conn->prepare($check_customers);
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $customer_result = $stmt->get_result();
        $customer_count = $customer_result->fetch_assoc()['customer_count'];
        $stmt->close();
        
        if ($customer_count > 0) {
            $error = "Cannot delete loan. It has $customer_count customers associated with it.";
        } else {
            // Get loan name for logging
            $name_query = "SELECT loan_name FROM loans WHERE id = ?";
            $stmt = $conn->prepare($name_query);
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $name_result = $stmt->get_result();
            $loan_name = $name_result->fetch_assoc()['loan_name'];
            $stmt->close();
            
            // Delete the loan
            $delete_query = "DELETE FROM loans WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("i", $delete_id);
            
            if ($stmt->execute()) {
                // Log the activity
                if (function_exists('logActivity')) {
                    logActivity($conn, 'delete_loan', "Deleted loan: $loan_name (ID: $delete_id)");
                }
                $message = "Loan deleted successfully!";
            } else {
                $error = "Error deleting loan: " . $conn->error;
            }
            $stmt->close();
        }
    }
} elseif (isset($_GET['delete']) && $user_role != 'admin') {
    $error = "Only administrators can delete loans.";
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : '';

// Build query with filters
$where_clauses = [];

if ($user_role != 'admin') {
    // Non-admin users see only their finance company's loans
    $where_clauses[] = "l.finance_id = $finance_id";
}

if (!empty($search)) {
    $where_clauses[] = "(l.loan_name LIKE '%$search%' OR l.loan_purpose LIKE '%$search%')";
}

if (!empty($finance_filter) && $user_role == 'admin') {
    $where_clauses[] = "l.finance_id = $finance_filter";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get all loans with statistics
$loans_query = "SELECT l.*, 
                f.finance_name,
                (SELECT COUNT(*) FROM customers WHERE loan_id = l.id) as customer_count,
                (SELECT COALESCE(SUM(loan_amount), 0) FROM customers WHERE loan_id = l.id) as total_disbursed,
                (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule es 
                 JOIN customers c ON es.customer_id = c.id 
                 WHERE c.loan_id = l.id AND es.status = 'paid') as total_collected
                FROM loans l
                LEFT JOIN finance f ON l.finance_id = f.id
                $where_sql
                ORDER BY l.id DESC";

$loans = $conn->query($loans_query);

// Get finance companies for filter dropdown (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}

// Get overall statistics
$stats_query = "SELECT 
                COUNT(*) as total_loans,
                COALESCE(SUM(loan_amount), 0) as total_amount,
                AVG(interest_rate) as avg_interest,
                AVG(loan_tenure) as avg_tenure
                FROM loans l";

if ($user_role != 'admin') {
    $stats_query .= " WHERE l.finance_id = $finance_id";
}

$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];

// Get active loans count (loans with at least one customer)
$active_query = "SELECT COUNT(DISTINCT loan_id) as active_loans FROM customers";
if ($user_role != 'admin') {
    $active_query .= " WHERE finance_id = $finance_id";
}
$active_result = $conn->query($active_query);
$active_loans = $active_result ? $active_result->fetch_assoc()['active_loans'] : 0;
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
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 2rem;
        }
        
        /* Loan Cards */
        .loan-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            height: 100%;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .loan-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .loan-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        
        .loan-header h5 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .loan-header small {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .loan-body {
            padding: 1.25rem;
        }
        
        .loan-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .loan-stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .loan-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.2;
        }
        
        .loan-stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        .loan-footer {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .finance-badge {
            background: var(--info-bg);
            color: var(--info);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .documents-badge {
            background: var(--warning-bg);
            color: var(--warning);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            cursor: pointer;
        }
        
        .progress {
            height: 6px;
            border-radius: 3px;
            margin-top: 0.5rem;
        }
        
        .progress-bar {
            background: linear-gradient(90deg, var(--primary), var(--info));
            border-radius: 3px;
        }
        
        /* Table Styles */
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
        }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-custom th {
            text-align: left;
            padding: 1rem;
            background: #f8f9fa;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .table-custom td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .view-toggle .btn {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            transition: all 0.2s ease;
        }
        
        .view-toggle .btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
        }
        
        /* Interest Rate Badge */
        .interest-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .interest-badge.low {
            background: var(--success-bg);
            color: var(--success);
        }
        
        .interest-badge.medium {
            background: var(--warning-bg);
            color: var(--warning);
        }
        
        .interest-badge.high {
            background: var(--danger-bg);
            color: var(--danger);
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .loan-stats {
                grid-template-columns: 1fr;
            }
            .table-container {
                overflow-x: auto;
            }
            .view-toggle {
                justify-content: center;
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
                        <h3><i class="bi bi-cash-stack me-2"></i>Loan Management</h3>
                        <p>Manage all loan products and their performance metrics</p>
                    </div>
                    <div>
                        <a href="add-loan.php" class="btn btn-light me-2">
                            <i class="bi bi-plus-circle me-2"></i>Add New Loan
                        </a>
                        <span class="badge bg-white text-primary ms-2">
                            <i class="bi bi-person-badge me-1"></i><?php echo ucfirst($user_role); ?>
                        </span>
                    </div>
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
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Total Loans</div>
                                <div class="stat-value"><?php echo number_format($stats['total_loans'] ?? 0); ?></div>
                            </div>
                            <div class="stat-icon blue"><i class="bi bi-cash-stack"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted">Active loan products</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Active Loans</div>
                                <div class="stat-value"><?php echo number_format($active_loans); ?></div>
                            </div>
                            <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted">Loans with customers</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Avg Interest</div>
                                <div class="stat-value"><?php echo number_format($stats['avg_interest'] ?? 0, 2); ?>%</div>
                            </div>
                            <div class="stat-icon purple"><i class="bi bi-percent"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted">Monthly interest rate</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="stat-label">Avg Tenure</div>
                                <div class="stat-value"><?php echo number_format($stats['avg_tenure'] ?? 0, 1); ?>m</div>
                            </div>
                            <div class="stat-icon orange"><i class="bi bi-calendar-month"></i></div>
                        </div>
                        <div class="stat-footer">
                            <span class="text-muted">Average loan period</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="filter-card">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3">
                        <div class="col-md-<?php echo $user_role == 'admin' ? '5' : '8'; ?>">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control border-start-0" name="search" 
                                       placeholder="Search by loan name or purpose..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        
                        <?php if ($user_role == 'admin' && $finance_companies && $finance_companies->num_rows > 0): ?>
                        <div class="col-md-4">
                            <select class="form-select" name="finance_id">
                                <option value="">All Finance Companies</option>
                                <?php while ($fc = $finance_companies->fetch_assoc()): ?>
                                    <option value="<?php echo $fc['id']; ?>" 
                                        <?php echo $finance_filter == $fc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($fc['finance_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                        <?php else: ?>
                        <div class="col-md-4">
                        <?php endif; ?>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-funnel me-2"></i>Apply Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- View Toggle -->
            <div class="view-toggle justify-content-end">
                <button type="button" class="btn btn-outline-primary active" id="gridViewBtn" onclick="toggleView('grid')">
                    <i class="bi bi-grid-3x3-gap-fill me-2"></i>Grid View
                </button>
                <button type="button" class="btn btn-outline-primary" id="listViewBtn" onclick="toggleView('list')">
                    <i class="bi bi-list-ul me-2"></i>List View
                </button>
            </div>

            <!-- Grid View -->
            <div id="gridView" class="row g-3">
                <?php if ($loans && $loans->num_rows > 0): ?>
                    <?php while ($loan = $loans->fetch_assoc()): 
                        $disbursed = $loan['total_disbursed'] ?? 0;
                        $collected = $loan['total_collected'] ?? 0;
                        $collection_percentage = $disbursed > 0 ? ($collected / $disbursed * 100) : 0;
                        
                        // Determine interest rate class
                        $interest_rate = floatval($loan['interest_rate']);
                        if ($interest_rate <= 1.5) {
                            $interest_class = 'low';
                        } elseif ($interest_rate <= 2.5) {
                            $interest_class = 'medium';
                        } else {
                            $interest_class = 'high';
                        }
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="loan-card">
                                <div class="loan-header">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5><?php echo htmlspecialchars($loan['loan_name']); ?></h5>
                                            <small>
                                                <i class="bi bi-tag me-1"></i>ID: #<?php echo $loan['id']; ?>
                                            </small>
                                        </div>
                                        <?php if ($user_role == 'admin' && !empty($loan['finance_name'])): ?>
                                            <span class="finance-badge">
                                                <i class="bi bi-building me-1"></i><?php echo $loan['finance_name']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="loan-body">
                                    <div class="loan-stats">
                                        <div class="loan-stat-item">
                                            <div class="loan-stat-value">₹<?php echo number_format($loan['loan_amount'], 2); ?></div>
                                            <div class="loan-stat-label">Amount</div>
                                        </div>
                                        <div class="loan-stat-item">
                                            <div class="loan-stat-value">
                                                <span class="interest-badge <?php echo $interest_class; ?>">
                                                    <?php echo $loan['interest_rate']; ?>%
                                                </span>
                                            </div>
                                            <div class="loan-stat-label">Interest</div>
                                        </div>
                                        <div class="loan-stat-item">
                                            <div class="loan-stat-value"><?php echo $loan['loan_tenure']; ?>m</div>
                                            <div class="loan-stat-label">Tenure</div>
                                        </div>
                                        <div class="loan-stat-item">
                                            <div class="loan-stat-value"><?php echo $loan['customer_count']; ?></div>
                                            <div class="loan-stat-label">Customers</div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($disbursed > 0): ?>
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="small text-muted">Collection Rate</span>
                                                <span class="small fw-semibold"><?php echo number_format($collection_percentage, 1); ?>%</span>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar" style="width: <?php echo $collection_percentage; ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($loan['loan_purpose'])): ?>
                                        <p class="small text-muted mt-2 mb-0">
                                            <i class="bi bi-quote me-1"></i>
                                            <?php echo substr(htmlspecialchars($loan['loan_purpose']), 0, 60); ?>
                                            <?php if (strlen($loan['loan_purpose']) > 60) echo '...'; ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($loan['loan_documents'])): ?>
                                        <div class="mt-2">
                                            <span class="documents-badge" title="<?php echo htmlspecialchars($loan['loan_documents']); ?>">
                                                <i class="bi bi-file-text me-1"></i>Required Docs
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="loan-footer">
                                    <div>
                                        <small class="text-muted">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?php echo date('d M Y', strtotime($loan['created_at'])); ?>
                                        </small>
                                    </div>
                                    <div class="action-buttons">
                                        <a href="view-loan.php?id=<?php echo $loan['id']; ?>" 
                                           class="btn btn-sm btn-outline-info" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($user_role == 'admin'): ?>
                                            <a href="edit-loan.php?id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($loan['customer_count'] == 0): ?>
                                                <a href="?delete=<?php echo $loan['id']; ?>" 
                                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                                   onclick="return confirm('Are you sure you want to delete this loan? This action cannot be undone.')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="bi bi-cash-stack" style="font-size: 3rem; color: #dee2e6;"></i>
                            <h5 class="mt-3 text-muted">No loans found</h5>
                            <?php if (!empty($search) || !empty($finance_filter)): ?>
                                <p class="text-muted">Try adjusting your filter criteria</p>
                                <a href="loans.php" class="btn btn-outline-primary">Clear Filters</a>
                            <?php else: ?>
                                <a href="add-loan.php" class="btn btn-primary">
                                    <i class="bi bi-plus-circle me-2"></i>Add Your First Loan
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- List View -->
            <div id="listView" style="display: none;">
                <div class="table-container">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Loan Name</th>
                                <?php if ($user_role == 'admin'): ?>
                                    <th>Finance Company</th>
                                <?php endif; ?>
                                <th>Amount</th>
                                <th>Interest</th>
                                <th>Tenure</th>
                                <th>Customers</th>
                                <th>Disbursed</th>
                                <th>Collected</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($loans && $loans->num_rows > 0) {
                                $loans->data_seek(0);
                                while ($loan = $loans->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><span class="fw-semibold">#<?php echo $loan['id']; ?></span></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($loan['loan_name']); ?></div>
                                        <?php if (!empty($loan['loan_purpose'])): ?>
                                            <small class="text-muted"><?php echo substr(htmlspecialchars($loan['loan_purpose']), 0, 30); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($user_role == 'admin'): ?>
                                        <td>
                                            <?php if (!empty($loan['finance_name'])): ?>
                                                <span class="finance-badge"><?php echo $loan['finance_name']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td>
                                        <span class="interest-badge <?php 
                                            $rate = floatval($loan['interest_rate']);
                                            echo $rate <= 1.5 ? 'low' : ($rate <= 2.5 ? 'medium' : 'high'); 
                                        ?>">
                                            <?php echo $loan['interest_rate']; ?>%
                                        </span>
                                    </td>
                                    <td><?php echo $loan['loan_tenure']; ?> months</td>
                                    <td><?php echo $loan['customer_count']; ?></td>
                                    <td>₹<?php echo number_format($loan['total_disbursed'] ?? 0, 2); ?></td>
                                    <td>₹<?php echo number_format($loan['total_collected'] ?? 0, 2); ?></td>
                                    <td><?php echo date('d M Y', strtotime($loan['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view-loan.php?id=<?php echo $loan['id']; ?>" 
                                               class="btn btn-sm btn-outline-info" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($user_role == 'admin'): ?>
                                                <a href="edit-loan.php?id=<?php echo $loan['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($loan['customer_count'] == 0): ?>
                                                    <a href="?delete=<?php echo $loan['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger" title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this loan?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php 
                                endwhile;
                            } 
                            ?>
                        </tbody>
                    </table>
                    
                    <?php if ($loans && $loans->num_rows > 0): ?>
                        <div class="text-muted small mt-3">
                            Showing <?php echo $loans->num_rows; ?> loans
                            <?php if (!empty($search) || !empty($finance_filter)): ?>
                                (filtered)
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats Summary -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-transparent">
                            <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Loan Portfolio Summary</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Total Loan Value</div>
                                        <div class="h4 mb-0 text-primary">₹<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Average Loan</div>
                                        <div class="h4 mb-0 text-success">
                                            ₹<?php 
                                            $avg_loan = ($stats['total_loans'] > 0) ? ($stats['total_amount'] / $stats['total_loans']) : 0;
                                            echo number_format($avg_loan, 2);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Interest Range</div>
                                        <div class="h4 mb-0 text-warning">
                                            <?php 
                                            $min_rate = 100;
                                            $max_rate = 0;
                                            if ($loans && $loans->num_rows > 0) {
                                                $loans->data_seek(0);
                                                while ($r = $loans->fetch_assoc()) {
                                                    $rate = floatval($r['interest_rate']);
                                                    $min_rate = min($min_rate, $rate);
                                                    $max_rate = max($max_rate, $rate);
                                                }
                                                $loans->data_seek(0);
                                            }
                                            echo $min_rate . '% - ' . $max_rate . '%';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border rounded p-3 text-center">
                                        <div class="text-muted small">Tenure Range</div>
                                        <div class="h4 mb-0 text-info">
                                            <?php 
                                            $min_tenure = 1000;
                                            $max_tenure = 0;
                                            if ($loans && $loans->num_rows > 0) {
                                                $loans->data_seek(0);
                                                while ($r = $loans->fetch_assoc()) {
                                                    $tenure = intval($r['loan_tenure']);
                                                    $min_tenure = min($min_tenure, $tenure);
                                                    $max_tenure = max($max_tenure, $tenure);
                                                }
                                                $loans->data_seek(0);
                                            }
                                            echo $min_tenure . ' - ' . $max_tenure . ' months';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
    // Toggle between grid and list view
    function toggleView(view) {
        const gridView = document.getElementById('gridView');
        const listView = document.getElementById('listView');
        const gridBtn = document.getElementById('gridViewBtn');
        const listBtn = document.getElementById('listViewBtn');
        
        if (view === 'grid') {
            gridView.style.display = 'flex';
            listView.style.display = 'none';
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
            localStorage.setItem('loansView', 'grid');
        } else {
            gridView.style.display = 'none';
            listView.style.display = 'block';
            gridBtn.classList.remove('active');
            listBtn.classList.add('active');
            localStorage.setItem('loansView', 'list');
        }
    }
    
    // Load saved view preference
    window.onload = function() {
        const savedView = localStorage.getItem('loansView');
        if (savedView === 'list') {
            toggleView('list');
        }
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