<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;

// Check if user has permission to export reports
if ($user_role != 'admin' && $user_role != 'accountant') {
    die("You don't have permission to export reports.");
}

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$finance_filter = isset($_GET['finance_id']) ? intval($_GET['finance_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build query conditions
$conditions = [];

if ($user_role != 'admin') {
    $conditions[] = "i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $conditions[] = "i.finance_id = $finance_filter";
}

if ($status_filter != 'all') {
    $conditions[] = "i.status = '$status_filter'";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get summary statistics
$summary_query = "SELECT 
                  COUNT(*) as total_investors,
                  SUM(CASE WHEN i.status = 'active' THEN 1 ELSE 0 END) as active_investors,
                  SUM(CASE WHEN i.status = 'closed' THEN 1 ELSE 0 END) as closed_investors,
                  COALESCE(SUM(i.investment_amount), 0) as total_investment,
                  COALESCE(SUM(CASE WHEN i.status = 'active' THEN i.investment_amount ELSE 0 END), 0) as active_investment,
                  COALESCE(SUM(i.total_return_paid), 0) as total_returns_paid,
                  AVG(i.interest_rate) as avg_interest_rate
                  FROM investors i
                  $where_clause";

$summary_result = $conn->query($summary_query);
$summary = $summary_result ? $summary_result->fetch_assoc() : [];

// Get return type breakdown
$return_type_query = "SELECT 
                      i.return_type,
                      COUNT(*) as count,
                      COALESCE(SUM(i.investment_amount), 0) as total_amount,
                      AVG(i.interest_rate) as avg_rate,
                      COALESCE(SUM(i.total_return_paid), 0) as returns_paid
                      FROM investors i
                      $where_clause
                      GROUP BY i.return_type
                      ORDER BY total_amount DESC";

$return_type_result = $conn->query($return_type_query);

// Get monthly investment summary
$monthly_query = "SELECT 
                  DATE_FORMAT(i.investment_date, '%Y-%m') as month,
                  COUNT(*) as new_investors,
                  COALESCE(SUM(i.investment_amount), 0) as amount_invested
                  FROM investors i
                  $where_clause
                  GROUP BY DATE_FORMAT(i.investment_date, '%Y-%m')
                  ORDER BY month DESC
                  LIMIT 12";

$monthly_result = $conn->query($monthly_query);

// Get returns for date range
$returns_query = "SELECT 
                  ir.return_date,
                  ir.return_amount,
                  ir.return_type,
                  ir.payment_method,
                  i.investor_name,
                  i.investment_amount,
                  i.interest_rate,
                  i.return_type as investor_return_type,
                  f.finance_name
                  FROM investor_returns ir
                  JOIN investors i ON ir.investor_id = i.id
                  JOIN finance f ON i.finance_id = f.id
                  WHERE ir.return_date BETWEEN '$from_date' AND '$to_date'";

if ($user_role != 'admin') {
    $returns_query .= " AND i.finance_id = $finance_id";
} elseif ($finance_filter > 0) {
    $returns_query .= " AND i.finance_id = $finance_filter";
}

if ($status_filter != 'all') {
    $returns_query .= " AND i.status = '$status_filter'";
}

$returns_query .= " ORDER BY ir.return_date DESC";

$returns_result = $conn->query($returns_query);

// Get all investors for detailed export
$investors_query = "SELECT 
                    i.id,
                    i.investor_name,
                    i.investor_number,
                    i.email,
                    i.investment_amount,
                    i.interest_rate,
                    i.return_type,
                    i.return_amount,
                    i.total_return_paid,
                    i.investment_date,
                    i.maturity_date,
                    i.status,
                    f.finance_name,
                    (i.investment_amount * i.interest_rate / 100) as annual_interest,
                    CASE 
                        WHEN i.status = 'active' AND i.maturity_date < CURDATE() THEN 'Overdue'
                        WHEN i.status = 'active' THEN 'Active'
                        WHEN i.status = 'closed' THEN 'Closed'
                        ELSE i.status
                    END as status_display
                    FROM investors i
                    JOIN finance f ON i.finance_id = f.id
                    $where_clause
                    ORDER BY i.investment_amount DESC";

$investors_result = $conn->query($investors_query);

// Get finance-wise summary for admin
$finance_wise_data = [];
if ($user_role == 'admin') {
    $finance_wise_query = "SELECT 
                           f.id,
                           f.finance_name,
                           COUNT(i.id) as investor_count,
                           COALESCE(SUM(i.investment_amount), 0) as total_investment,
                           COALESCE(SUM(i.total_return_paid), 0) as total_returns
                           FROM finance f
                           LEFT JOIN investors i ON f.id = i.finance_id
                           GROUP BY f.id, f.finance_name
                           ORDER BY total_investment DESC";
    $finance_wise_result = $conn->query($finance_wise_query);
    while ($row = $finance_wise_result->fetch_assoc()) {
        $finance_wise_data[] = $row;
    }
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="investor-report-' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Start output
echo '<html>';
echo '<head>';
echo '<meta charset="UTF-8">';
echo '<title>Investor Report</title>';
echo '<style>';
echo 'td { mso-number-format:\@; }'; // Prevent Excel from auto-formatting
echo '.text { mso-number-format:\@; }';
echo '.date { mso-number-format:"dd\-mmm\-yyyy"; }';
echo '.currency { mso-number-format:"#,##0.00"; }';
echo '.percentage { mso-number-format:"0.00%"; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Report Title
echo '<h2>Investor Report</h2>';
echo '<h3>Generated on: ' . date('d M Y h:i A') . '</h3>';
echo '<h3>Period: ' . date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) . '</h3>';
echo '<br>';

// Summary Statistics
echo '<h3>SUMMARY STATISTICS</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
echo '<tr style="background-color: #f2f2f2;">';
echo '<th>Metric</th>';
echo '<th>Value</th>';
echo '</tr>';
echo '<tr>';
echo '<td>Total Investors</td>';
echo '<td class="text">' . number_format($summary['total_investors'] ?? 0) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Active Investors</td>';
echo '<td class="text">' . number_format($summary['active_investors'] ?? 0) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Closed Investors</td>';
echo '<td class="text">' . number_format($summary['closed_investors'] ?? 0) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Total Investment</td>';
echo '<td class="currency">₹' . number_format($summary['total_investment'] ?? 0, 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Active Investment</td>';
echo '<td class="currency">₹' . number_format($summary['active_investment'] ?? 0, 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Total Returns Paid</td>';
echo '<td class="currency">₹' . number_format($summary['total_returns_paid'] ?? 0, 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Average Interest Rate</td>';
echo '<td class="percentage">' . number_format($summary['avg_interest_rate'] ?? 0, 2) . '%</td>';
echo '</tr>';
echo '</table>';
echo '<br><br>';

// Return Type Breakdown
echo '<h3>INVESTMENT BY RETURN TYPE</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
echo '<tr style="background-color: #f2f2f2;">';
echo '<th>Return Type</th>';
echo '<th>Number of Investors</th>';
echo '<th>Total Investment</th>';
echo '<th>Average Rate</th>';
echo '<th>Returns Paid</th>';
echo '</tr>';

if ($return_type_result && $return_type_result->num_rows > 0) {
    while ($type = $return_type_result->fetch_assoc()) {
        $return_type = $type['return_type'] ?: 'Maturity';
        echo '<tr>';
        echo '<td>' . ucfirst($return_type) . '</td>';
        echo '<td class="text">' . number_format($type['count']) . '</td>';
        echo '<td class="currency">₹' . number_format($type['total_amount'], 2) . '</td>';
        echo '<td class="percentage">' . number_format($type['avg_rate'] ?? 0, 2) . '%</td>';
        echo '<td class="currency">₹' . number_format($type['returns_paid'], 2) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="5" align="center">No data available</td></tr>';
}
echo '</table>';
echo '<br><br>';

// Monthly Investment Trend
echo '<h3>MONTHLY INVESTMENT TREND</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
echo '<tr style="background-color: #f2f2f2;">';
echo '<th>Month</th>';
echo '<th>New Investors</th>';
echo '<th>Amount Invested</th>';
echo '</tr>';

if ($monthly_result && $monthly_result->num_rows > 0) {
    while ($month = $monthly_result->fetch_assoc()) {
        $month_name = date('M Y', strtotime($month['month'] . '-01'));
        echo '<tr>';
        echo '<td class="date">' . $month_name . '</td>';
        echo '<td class="text">' . number_format($month['new_investors']) . '</td>';
        echo '<td class="currency">₹' . number_format($month['amount_invested'], 2) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="3" align="center">No data available</td></tr>';
}
echo '</table>';
echo '<br><br>';

// Finance-wise Summary (Admin Only)
if ($user_role == 'admin' && !empty($finance_wise_data)) {
    echo '<h3>FINANCE-WISE INVESTMENT SUMMARY</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
    echo '<tr style="background-color: #f2f2f2;">';
    echo '<th>Finance Company</th>';
    echo '<th>Investors</th>';
    echo '<th>Total Investment</th>';
    echo '<th>Returns Paid</th>';
    echo '<th>Average per Investor</th>';
    echo '</tr>';

    foreach ($finance_wise_data as $fw) {
        $avg_per_investor = $fw['investor_count'] > 0 ? $fw['total_investment'] / $fw['investor_count'] : 0;
        echo '<tr>';
        echo '<td>' . htmlspecialchars($fw['finance_name']) . '</td>';
        echo '<td class="text">' . number_format($fw['investor_count']) . '</td>';
        echo '<td class="currency">₹' . number_format($fw['total_investment'], 2) . '</td>';
        echo '<td class="currency">₹' . number_format($fw['total_returns'], 2) . '</td>';
        echo '<td class="currency">₹' . number_format($avg_per_investor, 2) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<br><br>';
}

// Returns History
echo '<h3>RETURNS HISTORY (' . date('d M Y', strtotime($from_date)) . ' - ' . date('d M Y', strtotime($to_date)) . ')</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
echo '<tr style="background-color: #f2f2f2;">';
echo '<th>Date</th>';
echo '<th>Investor Name</th>';
echo '<th>Amount</th>';
echo '<th>Return Type</th>';
echo '<th>Payment Method</th>';
echo '<th>Finance Company</th>';
echo '</tr>';

if ($returns_result && $returns_result->num_rows > 0) {
    $total_returns_amount = 0;
    while ($return = $returns_result->fetch_assoc()) {
        $total_returns_amount += $return['return_amount'];
        echo '<tr>';
        echo '<td class="date">' . date('d M Y', strtotime($return['return_date'])) . '</td>';
        echo '<td>' . htmlspecialchars($return['investor_name']) . '</td>';
        echo '<td class="currency">₹' . number_format($return['return_amount'], 2) . '</td>';
        echo '<td>' . ucfirst($return['return_type'] ?: 'Interest') . '</td>';
        echo '<td>' . ucfirst(str_replace('_', ' ', $return['payment_method'] ?: 'Cash')) . '</td>';
        echo '<td>' . htmlspecialchars($return['finance_name']) . '</td>';
        echo '</tr>';
    }
    echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
    echo '<td colspan="2" align="right">Total:</td>';
    echo '<td class="currency">₹' . number_format($total_returns_amount, 2) . '</td>';
    echo '<td colspan="3"></td>';
    echo '</tr>';
} else {
    echo '<tr><td colspan="6" align="center">No returns found for the selected period</td></tr>';
}
echo '</table>';
echo '<br><br>';

// Detailed Investor List
echo '<h3>DETAILED INVESTOR LIST</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
echo '<tr style="background-color: #f2f2f2;">';
echo '<th>ID</th>';
echo '<th>Investor Name</th>';
echo '<th>Phone</th>';
echo '<th>Email</th>';
echo '<th>Finance Company</th>';
echo '<th>Investment Amount</th>';
echo '<th>Interest Rate</th>';
echo '<th>Return Type</th>';
echo '<th>Return Amount</th>';
echo '<th>Returns Paid</th>';
echo '<th>Investment Date</th>';
echo '<th>Maturity Date</th>';
echo '<th>Status</th>';
echo '<th>Annual Interest</th>';
echo '</tr>';

if ($investors_result && $investors_result->num_rows > 0) {
    $total_investment_all = 0;
    $total_returns_all = 0;
    
    while ($inv = $investors_result->fetch_assoc()) {
        $total_investment_all += $inv['investment_amount'];
        $total_returns_all += $inv['total_return_paid'];
        
        echo '<tr>';
        echo '<td class="text">#' . $inv['id'] . '</td>';
        echo '<td>' . htmlspecialchars($inv['investor_name']) . '</td>';
        echo '<td class="text">' . htmlspecialchars($inv['investor_number']) . '</td>';
        echo '<td>' . htmlspecialchars($inv['email'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($inv['finance_name']) . '</td>';
        echo '<td class="currency">₹' . number_format($inv['investment_amount'], 2) . '</td>';
        echo '<td class="percentage">' . $inv['interest_rate'] . '%</td>';
        echo '<td>' . ucfirst($inv['return_type'] ?: 'Maturity') . '</td>';
        echo '<td class="currency">₹' . number_format($inv['return_amount'], 2) . '</td>';
        echo '<td class="currency">₹' . number_format($inv['total_return_paid'], 2) . '</td>';
        echo '<td class="date">' . date('d M Y', strtotime($inv['investment_date'])) . '</td>';
        echo '<td class="date">' . ($inv['maturity_date'] ? date('d M Y', strtotime($inv['maturity_date'])) : '-') . '</td>';
        echo '<td>' . ucfirst($inv['status_display']) . '</td>';
        echo '<td class="currency">₹' . number_format($inv['annual_interest'], 2) . '</td>';
        echo '</tr>';
    }
    
    // Summary row
    echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
    echo '<td colspan="5" align="right">Totals:</td>';
    echo '<td class="currency">₹' . number_format($total_investment_all, 2) . '</td>';
    echo '<td></td>';
    echo '<td></td>';
    echo '<td></td>';
    echo '<td class="currency">₹' . number_format($total_returns_all, 2) . '</td>';
    echo '<td colspan="4"></td>';
    echo '</tr>';
} else {
    echo '<tr><td colspan="14" align="center">No investors found</td></tr>';
}
echo '</table>';
echo '<br><br>';

// Additional Statistics
echo '<h3>ADDITIONAL STATISTICS</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">';
echo '<tr style="background-color: #f2f2f2;">';
echo '<th>Metric</th>';
echo '<th>Value</th>';
echo '</tr>';
echo '<tr>';
echo '<td>Total Investors</td>';
echo '<td class="text">' . number_format($summary['total_investors'] ?? 0) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Average Investment per Investor</td>';
echo '<td class="currency">₹' . number_format(($summary['total_investment'] ?? 0) / max($summary['total_investors'] ?? 1, 1), 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Average Returns per Investor</td>';
echo '<td class="currency">₹' . number_format(($summary['total_returns_paid'] ?? 0) / max($summary['total_investors'] ?? 1, 1), 2) . '</td>';
echo '</tr>';
echo '<tr>';
echo '<td>Return on Investment (ROI)</td>';
echo '<td class="percentage">' . number_format((($summary['total_returns_paid'] ?? 0) / max($summary['total_investment'] ?? 1, 1)) * 100, 2) . '%</td>';
echo '</tr>';
echo '</table>';

// Report Footer
echo '<br><br>';
echo '<hr>';
echo '<p><em>Generated by Finance Manager System</em></p>';
echo '<p><em>Report includes data as of ' . date('d M Y h:i A') . '</em></p>';

echo '</body>';
echo '</html>';
?>