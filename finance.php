<?php
require_once 'includes/auth.php';
$currentPage = 'finance';
$pageTitle = 'Finance Companies';
require_once 'includes/db.php';

// Check if user has permission to view finance companies
// Instead of 'manage_finance', we'll check for appropriate role
$user_role = $_SESSION['role'] ?? '';

// Allow access for admin and accountant (they should be able to view finance companies)
if ($user_role != 'admin' && $user_role != 'accountant') {
    // If not authorized, set error message instead of redirecting
    $error = "You don't have permission to view finance companies.";
    // Log this attempt
    error_log("Unauthorized access attempt to finance.php by user role: " . $user_role);
}

$message = '';
// Don't overwrite existing error
if (!isset($error)) {
    $error = '';
}

// Handle finance company deletion (only admin can delete)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && $user_role == 'admin') {
    $delete_id = intval($_GET['delete']);
    
    // Check if finance company has associated users
    $check_users = "SELECT COUNT(*) as user_count FROM users WHERE finance_id = ?";
    $stmt = $conn->prepare($check_users);
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $user_result = $stmt->get_result();
        $user_count = $user_result->fetch_assoc()['user_count'];
        $stmt->close();
    } else {
        $user_count = 0;
    }
    
    // Check if finance company has associated customers
    $check_customers = "SELECT COUNT(*) as customer_count FROM customers WHERE finance_id = ?";
    $stmt = $conn->prepare($check_customers);
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $customer_result = $stmt->get_result();
        $customer_count = $customer_result->fetch_assoc()['customer_count'];
        $stmt->close();
    } else {
        $customer_count = 0;
    }
    
    if ($user_count > 0 || $customer_count > 0) {
        $error = "Cannot delete finance company. It has $user_count users and $customer_count customers associated with it.";
    } else {
        // Get finance company name for logging
        $name_query = "SELECT finance_name FROM finance WHERE id = ?";
        $stmt = $conn->prepare($name_query);
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $name_result = $stmt->get_result();
            $finance_name = $name_result->fetch_assoc()['finance_name'];
            $stmt->close();
            
            // Delete the finance company
            $delete_query = "DELETE FROM finance WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    if (function_exists('logActivity')) {
                        logActivity($conn, 'delete_finance', "Deleted finance company: $finance_name (ID: $delete_id)");
                    }
                    $message = "Finance company deleted successfully!";
                } else {
                    $error = "Error deleting finance company: " . $conn->error;
                }
                $stmt->close();
            } else {
                $error = "Error preparing delete statement";
            }
        } else {
            $error = "Finance company not found.";
        }
    }
} elseif (isset($_GET['delete']) && $user_role != 'admin') {
    $error = "Only administrators can delete finance companies.";
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// Build query with filters
$where_clauses = [];
if (!empty($search)) {
    $where_clauses[] = "(finance_name LIKE '%$search%' OR description LIKE '%$search%')";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get all finance companies with statistics
$finance_query = "SELECT f.*, 
                (SELECT COUNT(*) FROM users WHERE finance_id = f.id) as user_count,
                (SELECT COUNT(*) FROM customers WHERE finance_id = f.id) as customer_count,
                (SELECT COUNT(*) FROM loans WHERE finance_id = f.id) as loan_count,
                (SELECT COALESCE(SUM(loan_amount), 0) FROM customers WHERE finance_id = f.id) as total_disbursed,
                (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE finance_id = f.id AND status = 'paid') as total_collected
                FROM finance f 
                $where_sql
                ORDER BY f.id DESC";

$finance_companies = $conn->query($finance_query);

// Check if query was successful
if (!$finance_companies) {
    $error = "Error fetching finance companies: " . $conn->error;
    $finance_companies = null;
}

// Get overall statistics
$stats_query = "SELECT 
                COUNT(*) as total_companies,
                (SELECT COUNT(*) FROM users WHERE finance_id IS NOT NULL) as total_users,
                (SELECT COUNT(*) FROM customers) as total_customers,
                (SELECT COALESCE(SUM(loan_amount), 0) FROM customers) as total_disbursed_all,
                (SELECT COALESCE(SUM(emi_amount), 0) FROM emi_schedule WHERE status = 'paid') as total_collected_all
                FROM finance f";
$stats_result = $conn->query($stats_query);
$stats = $stats_result ? $stats_result->fetch_assoc() : [];

// Calculate active companies (companies with at least one customer)
$active_query = "SELECT COUNT(DISTINCT finance_id) as active_companies FROM customers WHERE finance_id IS NOT NULL";
$active_result = $conn->query($active_query);
$active_companies = $active_result ? $active_result->fetch_assoc()['active_companies'] : 0;
$stats['active_companies'] = $active_companies;
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
        
        /* Company Cards */
        .company-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            height: 100%;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .company-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .company-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .company-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .company-title {
            flex: 1;
        }
        
        .company-title h5 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .company-title small {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .company-body {
            padding: 1.25rem;
        }
        
        .company-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .company-stat-item {
            text-align: center;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .company-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.2;
        }
        
        .company-stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        .company-footer {
            padding: 1rem 1.25rem;
            background: #f8f9fa;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .company-pin {
            font-family: monospace;
            font-size: 1rem;
            font-weight: 600;
            color: var(--warning);
            background: var(--warning-bg);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
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
            .company-stats {
                grid-template-columns: 1fr;
            }
            .table-container {
                overflow-x: auto;
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
                        <h3><i class="bi bi-building me-2"></i>Finance Companies</h3>
                        <p>Manage all finance companies and their performance metrics</p>
                    </div>
                    <?php if ($user_role == 'admin'): ?>
                        <a href="add-finance.php" class="btn btn-light">
                            <i class="bi bi-building-add me-2"></i>Add New Company
                        </a>
                    <?php endif; ?>
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

            <!-- Check if user has permission to view -->
            <?php if ($user_role == 'admin' || $user_role == 'accountant'): ?>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4" data-testid="stats-cards">
                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="stat-label">Total Companies</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_companies'] ?? 0); ?></div>
                                </div>
                                <div class="stat-icon blue"><i class="bi bi-building"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Registered finance companies</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="stat-label">Active Companies</div>
                                    <div class="stat-value"><?php echo number_format($stats['active_companies'] ?? 0); ?></div>
                                </div>
                                <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Companies with active customers</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="stat-label">Total Customers</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_customers'] ?? 0); ?></div>
                                </div>
                                <div class="stat-icon purple"><i class="bi bi-people"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Across all companies</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-sm-6 col-xl-3">
                        <div class="stat-card">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <div class="stat-label">Total Disbursed</div>
                                    <div class="stat-value">₹<?php echo number_format($stats['total_disbursed_all'] ?? 0, 2); ?></div>
                                </div>
                                <div class="stat-icon orange"><i class="bi bi-cash-stack"></i></div>
                            </div>
                            <div class="stat-footer">
                                <span class="text-muted">Overall loan amount</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filter Card -->
                <div class="filter-card">
                    <form method="GET" action="" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent border-end-0">
                                        <i class="bi bi-search"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0" name="search" 
                                           placeholder="Search by company name or description..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
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
                    <?php if ($finance_companies && $finance_companies->num_rows > 0): ?>
                        <?php while ($company = $finance_companies->fetch_assoc()): 
                            $collection_percentage = ($company['total_disbursed'] > 0) ? 
                                ($company['total_collected'] / $company['total_disbursed'] * 100) : 0;
                        ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="company-card">
                                    <div class="company-header">
                                        <div class="company-icon">
                                            <?php echo strtoupper(substr($company['finance_name'], 0, 2)); ?>
                                        </div>
                                        <div class="company-title">
                                            <h5><?php echo htmlspecialchars($company['finance_name']); ?></h5>
                                            <small>
                                                <i class="bi bi-calendar3 me-1"></i>
                                                Created: <?php echo date('d M Y', strtotime($company['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="company-body">
                                        <div class="company-stats">
                                            <div class="company-stat-item">
                                                <div class="company-stat-value"><?php echo $company['customer_count']; ?></div>
                                                <div class="company-stat-label">Customers</div>
                                            </div>
                                            <div class="company-stat-item">
                                                <div class="company-stat-value"><?php echo $company['loan_count']; ?></div>
                                                <div class="company-stat-label">Loans</div>
                                            </div>
                                            <div class="company-stat-item">
                                                <div class="company-stat-value"><?php echo $company['user_count']; ?></div>
                                                <div class="company-stat-label">Users</div>
                                            </div>
                                            <div class="company-stat-item">
                                                <div class="company-stat-value">₹<?php echo number_format($company['total_disbursed'], 2); ?></div>
                                                <div class="company-stat-label">Disbursed</div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($company['total_disbursed'] > 0): ?>
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
                                        
                                        <?php if (!empty($company['description'])): ?>
                                            <p class="small text-muted mt-2 mb-0">
                                                <i class="bi bi-card-text me-1"></i>
                                                <?php echo substr(htmlspecialchars($company['description']), 0, 80); ?>
                                                <?php if (strlen($company['description']) > 80) echo '...'; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="company-footer">
                                        <div>
                                            <span class="company-pin">
                                                <i class="bi bi-key me-1"></i><?php echo $company['pin']; ?>
                                            </span>
                                        </div>
                                        <div class="action-buttons">
                                            <?php if ($user_role == 'admin'): ?>
                                                <a href="edit-finance.php?id=<?php echo $company['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($company['user_count'] == 0 && $company['customer_count'] == 0): ?>
                                                    <a href="?delete=<?php echo $company['id']; ?>" 
                                                       class="btn btn-sm btn-outline-danger" title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this company? This action cannot be undone.')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-info text-white">View Only</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="bi bi-building" style="font-size: 3rem; color: #dee2e6;"></i>
                                <h5 class="mt-3 text-muted">No finance companies found</h5>
                                <?php if (!empty($search)): ?>
                                    <p class="text-muted">Try adjusting your search criteria</p>
                                    <a href="finance.php" class="btn btn-outline-primary">Clear Search</a>
                                <?php else: ?>
                                    <?php if ($user_role == 'admin'): ?>
                                        <a href="add-finance.php" class="btn btn-primary">
                                            <i class="bi bi-building-add me-2"></i>Add Your First Company
                                        </a>
                                    <?php endif; ?>
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
                                    <th>Company Name</th>
                                    <th>PIN</th>
                                    <th>Users</th>
                                    <th>Customers</th>
                                    <th>Loans</th>
                                    <th>Disbursed</th>
                                    <th>Collected</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if ($finance_companies && $finance_companies->num_rows > 0) {
                                    $finance_companies->data_seek(0);
                                    while ($company = $finance_companies->fetch_assoc()): 
                                ?>
                                    <tr>
                                        <td><span class="fw-semibold">#<?php echo $company['id']; ?></span></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($company['finance_name']); ?></div>
                                            <?php if (!empty($company['description'])): ?>
                                                <small class="text-muted"><?php echo substr(htmlspecialchars($company['description']), 0, 50); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="company-pin"><?php echo $company['pin']; ?></span></td>
                                        <td><?php echo $company['user_count']; ?></td>
                                        <td><?php echo $company['customer_count']; ?></td>
                                        <td><?php echo $company['loan_count']; ?></td>
                                        <td>₹<?php echo number_format($company['total_disbursed'], 2); ?></td>
                                        <td>₹<?php echo number_format($company['total_collected'], 2); ?></td>
                                        <td><?php echo date('d M Y', strtotime($company['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($user_role == 'admin'): ?>
                                                    <a href="edit-finance.php?id=<?php echo $company['id']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <?php if ($company['user_count'] == 0 && $company['customer_count'] == 0): ?>
                                                        <a href="?delete=<?php echo $company['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger" title="Delete"
                                                           onclick="return confirm('Are you sure you want to delete this company?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-info">View Only</span>
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
                        
                        <?php if ($finance_companies && $finance_companies->num_rows > 0): ?>
                            <div class="text-muted small mt-3">
                                Showing <?php echo $finance_companies->num_rows; ?> companies
                                <?php if (!empty($search)): ?>
                                    (filtered)
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- Permission Denied Message -->
                <div class="permission-denied">
                    <i class="bi bi-shield-lock-fill"></i>
                    <h4>Access Denied</h4>
                    <p>You don't have permission to view finance companies. Please contact your administrator.</p>
                    <a href="index.php" class="btn btn-primary">
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
            localStorage.setItem('financeView', 'grid');
        } else {
            gridView.style.display = 'none';
            listView.style.display = 'block';
            gridBtn.classList.remove('active');
            listBtn.classList.add('active');
            localStorage.setItem('financeView', 'list');
        }
    }
    
    // Load saved view preference
    window.onload = function() {
        const savedView = localStorage.getItem('financeView');
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