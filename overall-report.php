<?php
require_once 'includes/auth.php';
$currentPage = 'overall-report';
$pageTitle = 'Overall Report';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Get filter parameters
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : 3; // March default
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : 2026;
$selected_day = isset($_GET['day']) ? $_GET['day'] : 'all';
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;

// Set date range
$start_date = $selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-01';
$end_date = date('Y-m-t', strtotime($start_date));
$date_label = date('F Y', strtotime($start_date));

// Get months for dropdown
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Get available years
$current_year = date('Y');
$years = range($current_year - 2, $current_year + 2);

// Get finance companies for filter (admin only)
$finance_companies = null;
if ($user_role == 'admin') {
    $finance_companies = $conn->query("SELECT id, finance_name FROM finance ORDER BY finance_name");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
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
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
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
        
        /* Filter Card */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .filter-card .form-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .filter-card .form-control,
        .filter-card .form-select,
        .filter-card .btn {
            font-size: 0.9rem;
            height: 38px;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        
        .filter-item {
            flex: 1 1 auto;
            min-width: 120px;
        }
        
        /* Report Info */
        .report-info {
            background: #e6f0ff;
            border: 1px solid var(--primary);
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            font-weight: 500;
        }
        
        .report-info i {
            font-size: 1.2rem;
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
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, var(--dark), #1a2634);
            color: white;
            border-bottom: none;
        }
        
        .dashboard-card .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
            font-size: 1rem;
        }
        
        .dashboard-card .card-body {
            padding: 1.25rem;
        }
        
        /* Stat Item */
        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .stat-item:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .stat-value {
            font-weight: 600;
            font-size: 1rem;
            color: var(--text-primary);
        }
        
        .stat-value.positive {
            color: var(--success);
        }
        
        .stat-value.negative {
            color: var(--danger);
        }
        
        .stat-note {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
            margin-bottom: 0.5rem;
            padding-left: 0;
            line-height: 1.3;
        }
        
        /* Sub Stats */
        .sub-stats {
            margin-left: 1rem;
            margin-top: 0.25rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
            background: #f8fafc;
            border-radius: 6px;
            padding: 0.5rem;
        }
        
        .sub-stat-item {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0;
        }
        
        .sub-stat-label {
            color: var(--text-muted);
        }
        
        .sub-stat-value {
            font-weight: 500;
        }
        
        /* Two Column Layout */
        .two-column {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px;
        }
        
        .column {
            flex: 1;
            min-width: 300px;
            padding: 0 10px;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .filter-item {
                min-width: 100%;
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
                        <h4><i class="bi bi-pie-chart-fill me-2"></i>Overall Report</h4>
                        <p>Dashboard - Overall Report for March 2026</p>
                    </div>
                    <span class="badge bg-white text-primary py-2 px-3">
                        <i class="bi bi-person-badge me-1"></i>Admin
                    </span>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-card">
                <form method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-item">
                            <label class="form-label">Select Month</label>
                            <select class="form-select" name="month">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3" selected>March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label class="form-label">Select Year</label>
                            <select class="form-select" name="year">
                                <option value="2024">2024</option>
                                <option value="2025">2025</option>
                                <option value="2026" selected>2026</option>
                                <option value="2027">2027</option>
                                <option value="2028">2028</option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label class="form-label">Select Day</label>
                            <select class="form-select" name="day">
                                <option value="all" selected>All Days</option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label class="form-label">Start Date</label>
                            <input type="text" class="form-control" placeholder="dd-mm-yyyy" value="01-04-2026" readonly>
                        </div>
                        
                        <div class="filter-item">
                            <label class="form-label">End Date</label>
                            <input type="text" class="form-control" placeholder="dd-mm-yyyy" value="30-04-2026" readonly>
                        </div>
                        
                        <div class="filter-item">
                            <label class="form-label">Finance</label>
                            <select class="form-select" name="finance_id">
                                <option value="0" selected>All Finance</option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
                
                <div class="report-info">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>Filter Info: Showing data for <strong>April 2026</strong></span>
                </div>
            </div>

            <!-- Cash Position Summary -->
            <div class="dashboard-card">
                <div class="card-header">
                    <h5><i class="bi bi-cash-stack me-2"></i>Cash Position Summary</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="stat-item">
                                <span class="stat-label">Total Investment</span>
                                <span class="stat-value">₹100,000.00</span>
                            </div>
                            <div class="stat-note">Initial capital investment</div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Current Balance</span>
                                <span class="stat-value negative">₹-1,590,901.35</span>
                            </div>
                            <div class="stat-note">Cash in hand (Investment + All income - Expenses - Loans Issued)<br>Includes: Collected Overdue Charges (Regular + Foreclosure)</div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Loans Issued</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Total principal disbursed</div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Regular EMI Received</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Regular monthly EMI collections (includes overdue)</div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="stat-item">
                                <span class="stat-label">Foreclosure Collected</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Total loan settlement amounts (includes overdue)</div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Document Charges</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Total Expenses</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Outstanding Principal</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Principal pending collection</div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Profit / Loss (This Period)</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Interest + Overdue Charges + Document Charges + Foreclosure Income – Expenses<br>Includes Overdue Charges</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cash Flow Breakdown and Regular EMI Breakdown -->
            <div class="row">
                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5><i class="bi bi-arrow-left-right me-2"></i>Cash Flow Breakdown</h5>
                        </div>
                        <div class="card-body">
                            <div class="stat-item">
                                <span class="stat-label">Previous Balance</span>
                                <span class="stat-value negative">₹-1,590,901.35</span>
                            </div>
                            <div class="stat-note">Cash from previous month</div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Cash Inflow</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Regular EMI + Foreclosure + Document Charges</div>
                            
                            <div class="sub-stats">
                                <div class="sub-stat-item">
                                    <span class="sub-stat-label">Regular EMI:</span>
                                    <span class="sub-stat-value">₹0.00</span>
                                </div>
                                <div class="sub-stat-item">
                                    <span class="sub-stat-label">Foreclosure:</span>
                                    <span class="sub-stat-value">₹0.00</span>
                                </div>
                                <div class="sub-stat-item">
                                    <span class="sub-stat-label">Document:</span>
                                    <span class="sub-stat-value">₹0.00</span>
                                </div>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Cash Outflow</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            <div class="stat-note">Expenses + Loans Issued</div>
                            
                            <div class="sub-stats">
                                <div class="sub-stat-item">
                                    <span class="sub-stat-label">Expenses:</span>
                                    <span class="sub-stat-value">₹0.00</span>
                                </div>
                                <div class="sub-stat-item">
                                    <span class="sub-stat-label">Loans Issued:</span>
                                    <span class="sub-stat-value">₹0.00</span>
                                </div>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Current Balance</span>
                                <span class="stat-value negative">₹-1,590,901.35</span>
                            </div>
                            <div class="stat-note">Cash in hand now</div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h5><i class="bi bi-pie-chart me-2"></i>Regular EMI Breakdown (Includes Overdue)</h5>
                        </div>
                        <div class="card-body">
                            <div class="stat-item">
                                <span class="stat-label">Principal</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Interest</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Overdue</span>
                                <span class="stat-value">₹0.00</span>
                            </div>
                            
                            <div class="stat-item">
                                <span class="stat-label">Total</span>
                                <span class="stat-value">₹0.00</span>
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
</body>
</html>