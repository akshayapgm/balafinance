<?php
require_once 'includes/auth.php';
$currentPage = 'emi-list';
$pageTitle = 'EMI Schedule';
require_once 'includes/db.php';

// Get user role and finance_id from session
$user_role = $_SESSION['role'];
$finance_id = $_SESSION['finance_id'] ?? 1;
$user_id = $_SESSION['user_id'];

// Get filter parameters
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$view_customer = isset($_GET['view_customer']) ? intval($_GET['view_customer']) : 0;

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query conditions
$conditions = [];
$params = [];
$types = "";

// Role-based filter
if ($user_role != 'admin') {
    $conditions[] = "es.finance_id = ?";
    $params[] = $finance_id;
    $types .= "i";
}

// If viewing specific customer, show only that customer's EMIs
if ($view_customer > 0) {
    $conditions[] = "es.customer_id = ?";
    $params[] = $view_customer;
    $types .= "i";
}

// Collection type filter
if ($filter_type != 'all' && $view_customer == 0) {
    $conditions[] = "c.collection_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

// Status filter
if ($filter_status != 'all' && $view_customer == 0) {
    $conditions[] = "es.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

// Customer filter (for main list)
if ($customer_id > 0 && $view_customer == 0) {
    $conditions[] = "es.customer_id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

// Search filter
if (!empty($search) && $view_customer == 0) {
    $conditions[] = "(c.customer_name LIKE ? OR c.agreement_number LIKE ? OR c.customer_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM emi_schedule es 
                JOIN customers c ON es.customer_id = c.id 
                $where_clause";
                
$stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_count = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_count / $limit);

// Get EMI list with customer details
$query = "SELECT es.*, 
          c.customer_name, c.agreement_number, c.customer_number, 
          c.collection_type, c.loan_amount, c.principal_amount as total_principal, 
          c.interest_amount as total_interest, c.loan_tenure, 
          c.emi as monthly_emi, c.interest_rate, c.agreement_date,
          c.emi_date as first_emi_date,
          c.weekly_days, c.monthly_date, c.vehicle_number, c.rc_number, c.vehicle_type,
          l.loan_name,
          f.finance_name,
          (SELECT COUNT(*) FROM emi_schedule WHERE customer_id = c.id AND id <= es.id) as emi_number,
          DATEDIFF(CURDATE(), es.emi_due_date) as days_overdue
          FROM emi_schedule es
          JOIN customers c ON es.customer_id = c.id
          JOIN loans l ON c.loan_id = l.id
          JOIN finance f ON c.finance_id = f.id
          $where_clause
          ORDER BY 
            CASE 
              WHEN es.status = 'overdue' THEN 1
              WHEN es.status = 'unpaid' AND es.emi_due_date <= CURDATE() THEN 2
              WHEN es.status = 'unpaid' THEN 3
              ELSE 4
            END,
            es.emi_due_date ASC,
            es.id ASC
          LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
$all_params = array_merge($params, [$limit, $offset]);
$all_types = $types . "ii";
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$emi_list = $stmt->get_result();

// Get customer details if viewing specific customer
$customer_details = null;
$customer_collection_type = '';
if ($view_customer > 0) {
    $customer_query = "SELECT c.*, f.finance_name, l.loan_name,
                      COUNT(es.id) as total_emis,
                      SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
                      SUM(CASE WHEN es.status IN ('unpaid', 'overdue') THEN 1 ELSE 0 END) as pending_emis,
                      COALESCE(SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END), 0) as total_paid,
                      COALESCE(SUM(CASE WHEN es.status IN ('unpaid', 'overdue') THEN es.emi_amount ELSE 0 END), 0) as total_pending,
                      MAX(es.paid_date) as last_payment_date,
                      MIN(es.emi_due_date) as first_emi_due_date,
                      c.emi_date as first_emi_date
                      FROM customers c
                      LEFT JOIN emi_schedule es ON c.id = es.customer_id
                      JOIN finance f ON c.finance_id = f.id
                      JOIN loans l ON c.loan_id = l.id
                      WHERE c.id = ?";
    
    if ($user_role != 'admin') {
        $customer_query .= " AND c.finance_id = ?";
        $stmt = $conn->prepare($customer_query);
        $stmt->bind_param('ii', $view_customer, $finance_id);
    } else {
        $stmt = $conn->prepare($customer_query);
        $stmt->bind_param('i', $view_customer);
    }
    $stmt->execute();
    $customer_details = $stmt->get_result()->fetch_assoc();
    $customer_collection_type = $customer_details['collection_type'] ?? '';
}

// Get summary statistics
$summary_query = "SELECT 
                  COUNT(*) as total_emis,
                  SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                  SUM(CASE WHEN es.status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
                  SUM(CASE WHEN es.status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
                  COALESCE(SUM(CASE WHEN es.status = 'paid' THEN es.emi_amount ELSE 0 END), 0) as total_collected,
                  COALESCE(SUM(CASE WHEN es.status IN ('unpaid', 'overdue') THEN es.emi_amount ELSE 0 END), 0) as total_pending,
                  COALESCE(SUM(es.overdue_charges), 0) as total_overdue_charges,
                  COALESCE(SUM(CASE WHEN es.status IN ('unpaid', 'overdue') THEN es.principal_amount ELSE 0 END), 0) as outstanding_principal,
                  COALESCE(SUM(CASE WHEN es.status IN ('unpaid', 'overdue') THEN es.interest_amount ELSE 0 END), 0) as outstanding_interest
                  FROM emi_schedule es
                  JOIN customers c ON es.customer_id = c.id
                  $where_clause";

$stmt = $conn->prepare($summary_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// Get collection type stats
$collection_stats_query = "SELECT 
                          c.collection_type,
                          COUNT(DISTINCT c.id) as customer_count,
                          COUNT(es.id) as total_emis,
                          SUM(CASE WHEN es.status = 'paid' THEN 1 ELSE 0 END) as paid_emis
                          FROM customers c
                          LEFT JOIN emi_schedule es ON c.id = es.customer_id
                          WHERE 1=1";

if ($user_role != 'admin') {
    $collection_stats_query .= " AND c.finance_id = ?";
}

if ($view_customer > 0 && $customer_collection_type) {
    $collection_stats_query .= " AND c.collection_type = ? AND c.id = ?";
} elseif ($filter_type != 'all' && $view_customer == 0) {
    $collection_stats_query .= " AND c.collection_type = ?";
}
if ($customer_id > 0 && $view_customer == 0) {
    $collection_stats_query .= " AND c.id = ?";
}
if (!empty($search) && $view_customer == 0) {
    $collection_stats_query .= " AND (c.customer_name LIKE ? OR c.agreement_number LIKE ? OR c.customer_number LIKE ?)";
}

$collection_stats_query .= " GROUP BY c.collection_type";

$collection_stats_params = [];
$collection_stats_types = "";

if ($user_role != 'admin') {
    $collection_stats_params[] = $finance_id;
    $collection_stats_types .= "i";
}
if ($view_customer > 0 && $customer_collection_type) {
    $collection_stats_params[] = $customer_collection_type;
    $collection_stats_params[] = $view_customer;
    $collection_stats_types .= "si";
} elseif ($filter_type != 'all' && $view_customer == 0) {
    $collection_stats_params[] = $filter_type;
    $collection_stats_types .= "s";
}
if ($customer_id > 0 && $view_customer == 0) {
    $collection_stats_params[] = $customer_id;
    $collection_stats_types .= "i";
}
if (!empty($search) && $view_customer == 0) {
    $search_param = "%$search%";
    $collection_stats_params[] = $search_param;
    $collection_stats_params[] = $search_param;
    $collection_stats_params[] = $search_param;
    $collection_stats_types .= "sss";
}

$collection_stats = [
    'monthly' => ['customer_count' => 0, 'total_emis' => 0, 'paid_emis' => 0],
    'weekly' => ['customer_count' => 0, 'total_emis' => 0, 'paid_emis' => 0],
    'daily' => ['customer_count' => 0, 'total_emis' => 0, 'paid_emis' => 0]
];

if (!empty($collection_stats_params)) {
    $stmt = $conn->prepare($collection_stats_query);
    $stmt->bind_param($collection_stats_types, ...$collection_stats_params);
    $stmt->execute();
    $collection_result = $stmt->get_result();
    while ($row = $collection_result->fetch_assoc()) {
        $type = $row['collection_type'];
        if (isset($collection_stats[$type])) {
            $collection_stats[$type] = [
                'customer_count' => $row['customer_count'],
                'total_emis' => $row['total_emis'],
                'paid_emis' => $row['paid_emis']
            ];
        }
    }
    $stmt->close();
}

// Get customers list for filter dropdown
$customer_query = "SELECT id, customer_name, agreement_number FROM customers";
if ($user_role != 'admin') {
    $customer_query .= " WHERE finance_id = $finance_id";
}
$customer_query .= " ORDER BY customer_name";
$customers = $conn->query($customer_query);

// Handle undo payment action
if (isset($_GET['undo']) && is_numeric($_GET['undo']) && function_exists('hasPermission') && hasPermission('edit_payment')) {
    $undo_id = intval($_GET['undo']);
    
    $undo_query = "SELECT * FROM emi_schedule WHERE id = ?";
    $stmt = $conn->prepare($undo_query);
    $stmt->bind_param('i', $undo_id);
    $stmt->execute();
    $undo_emi = $stmt->get_result()->fetch_assoc();
    
    if ($undo_emi && $undo_emi['status'] == 'paid') {
        $conn->begin_transaction();
        
        try {
            $log_query = "INSERT INTO emi_undo_logs (
                emi_id, customer_id, old_status, old_paid_date, 
                old_principal_paid, old_interest_paid, old_overdue_charges, undone_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($log_query);
            $stmt->bind_param(
                'iissdddi',
                $undo_emi['id'],
                $undo_emi['customer_id'],
                $undo_emi['status'],
                $undo_emi['paid_date'],
                $undo_emi['principal_paid'],
                $undo_emi['interest_paid'],
                $undo_emi['overdue_charges'],
                $user_id
            );
            $stmt->execute();
            
            $update_query = "UPDATE emi_schedule SET 
                status = 'unpaid',
                paid_date = NULL,
                emi_bill_number = NULL,
                payment_method = NULL,
                remarks = CONCAT(IFNULL(remarks, ''), ' | Undone on ', DATE_FORMAT(NOW(), '%d-%m-%Y'), ' by ', ?),
                updated_at = NOW()
                WHERE id = ?";
            
            $stmt = $conn->prepare($update_query);
            $undo_text = $_SESSION['full_name'] ?? 'User';
            $stmt->bind_param('si', $undo_text, $undo_id);
            $stmt->execute();
            
            $conn->commit();
            $_SESSION['success'] = 'Payment undone successfully';
            
            // Redirect to refresh the page
            header('Location: emi-list.php?' . http_build_query($_GET));
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Error undoing payment: ' . $e->getMessage();
        }
    }
}

// WhatsApp link generator
function getWhatsAppLink($phone, $message) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $message = urlencode($message);
    return "https://wa.me/91$phone?text=$message";
}

// Function to get formatted due date
function getDueDate($emi) {
    if (isset($emi['emi_due_date']) && $emi['emi_due_date'] != '0000-00-00' && !empty($emi['emi_due_date'])) {
        return date('d-m-Y', strtotime($emi['emi_due_date']));
    }
    
    if (isset($emi['first_emi_date']) && $emi['first_emi_date'] != '0000-00-00' && !empty($emi['first_emi_date'])) {
        try {
            $first_date = new DateTime($emi['first_emi_date']);
            $installment_number = isset($emi['emi_number']) ? $emi['emi_number'] : 1;
            
            if ($emi['collection_type'] == 'monthly') {
                $first_date->modify('+' . ($installment_number - 1) . ' months');
                return $first_date->format('d-m-Y');
            } elseif ($emi['collection_type'] == 'weekly') {
                $days_to_add = ($installment_number - 1) * 7;
                $first_date->modify('+' . $days_to_add . ' days');
                return $first_date->format('d-m-Y');
            } elseif ($emi['collection_type'] == 'daily') {
                $first_date->modify('+' . ($installment_number - 1) . ' days');
                return $first_date->format('d-m-Y');
            }
        } catch (Exception $e) {
            return 'Invalid Date';
        }
    }
    
    return '-';
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
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            --monthly-color: #2196f3;
            --weekly-color: #9c27b0;
            --daily-color: #ff9800;
            --primary-bg: #e6f0ff;
            --success-bg: #e3f7e3;
            --warning-bg: #fff3e0;
            --danger-bg: #ffe6e6;
            --info-bg: #e3f2fd;
            --whatsapp: #25D366;
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
            transition: all 0.2s ease;
        }

        .page-header .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Summary Cards */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.5rem;
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

        .stat-icon.primary {
            background: var(--primary-bg);
            color: var(--primary);
        }

        .stat-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }

        .stat-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .stat-icon.danger {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .stat-icon.info {
            background: var(--info-bg);
            color: var(--info);
        }

        .stat-icon.purple {
            background: #f3e5f5;
            color: #9c27b0;
        }

        /* Collection Type Stats Cards */
        .collection-stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .collection-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .collection-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .collection-card.monthly::before { background: var(--monthly-color); }
        .collection-card.weekly::before { background: var(--weekly-color); }
        .collection-card.daily::before { background: var(--daily-color); }

        .collection-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .collection-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .collection-value.monthly { color: var(--monthly-color); }
        .collection-value.weekly { color: var(--weekly-color); }
        .collection-value.daily { color: var(--daily-color); }

        .collection-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* Customer Info Card */
        .customer-info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .customer-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--primary-bg);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .customer-detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .customer-detail-item i {
            width: 20px;
            color: var(--text-muted);
        }

        .loan-badge {
            background: var(--primary-bg);
            color: var(--primary);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .progress-custom {
            height: 8px;
            border-radius: 4px;
            background: #e9ecef;
            overflow: hidden;
        }

        .progress-custom .progress-bar {
            background: var(--primary);
        }

        /* Dashboard Card */
        .dashboard-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 0;
        }

        .dashboard-card .card-header {
            background: linear-gradient(135deg, #1a365d 0%, #2b6cb0 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: none;
        }

        .dashboard-card .card-header h5 {
            font-weight: 600;
            margin-bottom: 0;
            font-size: 1.1rem;
        }

        .dashboard-card .card-header p {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-bottom: 0;
        }

        .dashboard-card .card-body {
            padding: 1.5rem;
        }

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .btn-filter {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            background: white;
            color: var(--text-muted);
            font-size: 0.85rem;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-filter:hover,
        .btn-filter.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .btn-filter.monthly.active {
            background: var(--monthly-color);
            border-color: var(--monthly-color);
        }

        .btn-filter.weekly.active {
            background: var(--weekly-color);
            border-color: var(--weekly-color);
        }

        .btn-filter.daily.active {
            background: var(--daily-color);
            border-color: var(--daily-color);
        }

        /* EMI Table Styles */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead th {
            background: #f8f9fa;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        .table tbody tr:hover td {
            background: #f8f9fa;
        }

        .table tbody tr.paid td {
            background: var(--success-bg);
        }

        .table tbody tr.overdue td {
            background: var(--danger-bg);
        }

        /* Installment Details */
        .installment-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .installment-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
        }

        .installment-label {
            min-width: 60px;
            color: var(--text-muted);
        }

        .installment-value {
            font-weight: 600;
        }

        .installment-value.principal { color: var(--primary); }
        .installment-value.interest { color: var(--info); }
        .installment-value.total { color: var(--success); font-weight: 700; }

        .paid-amounts {
            background: var(--success-bg);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-top: 4px;
        }

        /* Badge styles */
        .badge-collection-type {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .badge-collection-type.monthly {
            background: var(--primary-bg);
            color: var(--primary);
        }

        .badge-collection-type.weekly {
            background: #f3e5f5;
            color: var(--weekly-color);
        }

        .badge-collection-type.daily {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .badge-loan-type {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.2rem 0.4rem;
            background: var(--info-bg);
            color: var(--info);
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-badge.paid {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-badge.unpaid {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .status-badge.overdue {
            background: var(--danger-bg);
            color: var(--danger);
        }

        /* Action Buttons */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            transition: all 0.2s;
            margin: 0 2px;
            border: none;
        }

        .action-btn.whatsapp {
            background: #25D366;
        }

        .action-btn.whatsapp:hover {
            background: #128C7E;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(37, 211, 102, 0.3);
        }

        .action-btn.view {
            background: var(--info);
        }

        .action-btn.view:hover {
            background: #2c5282;
            transform: translateY(-2px);
        }

        .action-btn.collect {
            background: var(--success);
        }

        .action-btn.collect:hover {
            background: #276749;
            transform: translateY(-2px);
        }

        .action-btn.undo {
            background: var(--warning);
        }

        .action-btn.undo:hover {
            background: #c05621;
            transform: translateY(-2px);
        }

        .action-btn.pdf {
            background: var(--danger);
        }

        .action-btn.pdf:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        /* Overdue Charge badge */
        .overdue-charge-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 0.2rem 0.4rem;
            background: var(--danger-bg);
            color: var(--danger);
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        /* Date info */
        .date-item {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.85rem;
        }

        .date-item i {
            color: var(--text-muted);
        }

        .overdue-days {
            font-size: 0.75rem;
            color: var(--danger);
            font-weight: 600;
        }

        /* Stat box */
        .stat-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0.75rem;
        }

        .stat-box .label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }

        .stat-box .value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Pagination */
        .pagination {
            margin-top: 1.5rem;
            justify-content: center;
        }

        .pagination .page-link {
            color: var(--primary);
            border: 1px solid var(--border-color);
            margin: 0 3px;
            border-radius: 6px;
            padding: 0.4rem 0.8rem;
        }

        .pagination .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .page-content {
                padding: 1rem;
            }
            .summary-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .collection-stats-row {
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
                        <h4><i class="bi bi-calendar-check me-2"></i>EMI Schedule</h4>
                        <p><?php echo $view_customer > 0 ? 'Viewing EMIs for ' . htmlspecialchars($customer_details['customer_name']) : 'Manage and track all EMI collections'; ?></p>
                    </div>
                    <div>
                        <?php if ($view_customer > 0): ?>
                        <a href="emi-list.php" class="btn btn-light me-2">
                            <i class="bi bi-arrow-left me-2"></i>Back to All EMIs
                        </a>
                        <?php endif; ?>
                        <a href="collections.php" class="btn btn-light">
                            <i class="bi bi-cash me-2"></i>Record Collection
                        </a>
                    </div>
                </div>
            </div>

            <!-- Summary Cards - First Row -->
            <div class="summary-row">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Outstanding Principal</div>
                            <div class="stat-value">₹<?php echo number_format($summary['outstanding_principal'] ?? 0, 2); ?></div>
                        </div>
                        <div class="stat-icon primary"><i class="bi bi-cash-stack"></i></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Outstanding Interest</div>
                            <div class="stat-value">₹<?php echo number_format($summary['outstanding_interest'] ?? 0, 2); ?></div>
                        </div>
                        <div class="stat-icon warning"><i class="bi bi-percent"></i></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Total EMI Outstanding</div>
                            <div class="stat-value">₹<?php echo number_format(($summary['outstanding_principal'] ?? 0) + ($summary['outstanding_interest'] ?? 0), 2); ?></div>
                        </div>
                        <div class="stat-icon purple"><i class="bi bi-credit-card"></i></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Overdue Charges</div>
                            <div class="stat-value">₹<?php echo number_format($summary['total_overdue_charges'] ?? 0, 2); ?></div>
                        </div>
                        <div class="stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards - Second Row -->
            <div class="summary-row">
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Total Collected</div>
                            <div class="stat-value">₹<?php echo number_format($summary['total_collected'] ?? 0, 2); ?></div>
                        </div>
                        <div class="stat-icon success"><i class="bi bi-cash"></i></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Payment Progress</div>
                            <div class="stat-value"><?php echo ($summary['paid_count'] ?? 0); ?>/<?php echo ($summary['unpaid_count'] ?? 0) + ($summary['paid_count'] ?? 0) + ($summary['overdue_count'] ?? 0); ?></div>
                        </div>
                        <div class="stat-icon info"><i class="bi bi-pie-chart"></i></div>
                    </div>
                    <?php 
                    $total_emis = ($summary['unpaid_count'] ?? 0) + ($summary['paid_count'] ?? 0) + ($summary['overdue_count'] ?? 0);
                    $progress_percent = $total_emis > 0 ? round((($summary['paid_count'] ?? 0) / $total_emis) * 100) : 0;
                    ?>
                    <div class="progress-custom mt-2">
                        <div class="progress-bar" style="width: <?php echo $progress_percent; ?>%;"></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Unpaid EMIs</div>
                            <div class="stat-value"><?php echo ($summary['unpaid_count'] ?? 0); ?></div>
                        </div>
                        <div class="stat-icon warning"><i class="bi bi-clock-history"></i></div>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="stat-label">Paid EMIs</div>
                            <div class="stat-value"><?php echo ($summary['paid_count'] ?? 0); ?></div>
                        </div>
                        <div class="stat-icon success"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
            </div>

            <!-- Collection Type Stats -->
            <div class="collection-stats-row">
                <div class="collection-card monthly">
                    <div class="collection-label">
                        <i class="bi bi-calendar-month" style="color: var(--monthly-color);"></i>
                        Monthly Collections
                    </div>
                    <div class="collection-value monthly"><?php echo $collection_stats['monthly']['customer_count']; ?></div>
                    <div class="collection-sub"><?php echo $collection_stats['monthly']['total_emis']; ?> EMIs (<?php echo $collection_stats['monthly']['paid_emis']; ?> paid)</div>
                </div>
                
                <div class="collection-card weekly">
                    <div class="collection-label">
                        <i class="bi bi-calendar-week" style="color: var(--weekly-color);"></i>
                        Weekly Collections
                    </div>
                    <div class="collection-value weekly"><?php echo $collection_stats['weekly']['customer_count']; ?></div>
                    <div class="collection-sub"><?php echo $collection_stats['weekly']['total_emis']; ?> EMIs (<?php echo $collection_stats['weekly']['paid_emis']; ?> paid)</div>
                </div>
                
                <div class="collection-card daily">
                    <div class="collection-label">
                        <i class="bi bi-calendar-day" style="color: var(--daily-color);"></i>
                        Daily Collections
                    </div>
                    <div class="collection-value daily"><?php echo $collection_stats['daily']['customer_count']; ?></div>
                    <div class="collection-sub"><?php echo $collection_stats['daily']['total_emis']; ?> EMIs (<?php echo $collection_stats['daily']['paid_emis']; ?> paid)</div>
                </div>
            </div>

            <?php if ($view_customer > 0 && $customer_details): ?>
            <!-- Customer Details Card -->
            <div class="customer-info-card">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex gap-4">
                            <div class="customer-avatar">
                                <?php echo strtoupper(substr($customer_details['customer_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <h5 class="mb-3"><?php echo htmlspecialchars($customer_details['customer_name']); ?></h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-telephone"></i>
                                            <span><?php echo htmlspecialchars($customer_details['customer_number']); ?></span>
                                        </div>
                                        <div class="customer-detail-item">
                                            <i class="bi bi-hash"></i>
                                            <span>Agreement: <?php echo htmlspecialchars($customer_details['agreement_number']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-calendar"></i>
                                            <span>Date: <?php echo isset($customer_details['agreement_date']) ? date('d M Y', strtotime($customer_details['agreement_date'])) : ''; ?></span>
                                        </div>
                                        <div class="customer-detail-item">
                                            <i class="bi bi-building"></i>
                                            <span><?php echo htmlspecialchars($customer_details['finance_name']); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="customer-detail-item">
                                            <i class="bi bi-cash-stack"></i>
                                            <span>Loan: ₹<?php echo number_format($customer_details['loan_amount'], 2); ?></span>
                                        </div>
                                        <div class="customer-detail-item">
                                            <i class="bi bi-percent"></i>
                                            <span><?php echo $customer_details['interest_rate']; ?>% per month</span>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($customer_details['vehicle_number'])): ?>
                                <div class="mt-2">
                                    <span class="loan-badge">
                                        <i class="bi bi-truck"></i> Vehicle: <?php echo $customer_details['vehicle_number']; ?> (<?php echo $customer_details['vehicle_type']; ?>)
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border-start ps-4">
                            <div class="mb-3">
                                <span class="badge-collection-type <?php echo $customer_details['collection_type']; ?>">
                                    <i class="bi bi-<?php echo $customer_details['collection_type'] == 'monthly' ? 'calendar-month' : ($customer_details['collection_type'] == 'weekly' ? 'calendar-week' : 'calendar-day'); ?> me-1"></i>
                                    <?php echo ucfirst($customer_details['collection_type']); ?>
                                </span>
                                <span class="ms-2 small text-muted">
                                    First EMI: <?php echo isset($customer_details['first_emi_date']) ? date('d M Y', strtotime($customer_details['first_emi_date'])) : ''; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Progress</span>
                                <span class="fw-semibold"><?php echo $customer_details['paid_emis']; ?>/<?php echo $customer_details['total_emis']; ?> EMIs</span>
                            </div>
                            <?php $progress = $customer_details['total_emis'] > 0 ? ($customer_details['paid_emis'] / $customer_details['total_emis']) * 100 : 0; ?>
                            <div class="progress-custom mb-3">
                                <div class="progress-bar" style="width: <?php echo $progress; ?>%;"></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="stat-box">
                                        <div class="label">Paid Amount</div>
                                        <div class="value text-success">₹<?php echo number_format($customer_details['total_paid'], 2); ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box">
                                        <div class="label">Pending</div>
                                        <div class="value text-warning">₹<?php echo number_format($customer_details['total_pending'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($customer_details['last_payment_date'] && $customer_details['last_payment_date'] != '0000-00-00'): ?>
                            <div class="mt-2 small text-muted">
                                <i class="bi bi-clock-history"></i> Last payment: <?php echo date('d M Y', strtotime($customer_details['last_payment_date'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <?php if ($view_customer == 0): ?>
            <div class="filter-section">
                <form method="GET" action="">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Collection Type</label>
                            <select class="form-select" name="filter_type" onchange="this.form.submit()">
                                <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                <option value="monthly" <?php echo $filter_type == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="weekly" <?php echo $filter_type == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="daily" <?php echo $filter_type == 'daily' ? 'selected' : ''; ?>>Daily</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" onchange="this.form.submit()">
                                <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="unpaid" <?php echo $filter_status == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                <option value="overdue" <?php echo $filter_status == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Customer</label>
                            <select class="form-select" name="customer_id" onchange="this.form.submit()">
                                <option value="0">All Customers</option>
                                <?php 
                                if ($customers) {
                                    $customers->data_seek(0);
                                    while ($customer = $customers->fetch_assoc()): 
                                ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo $customer_id == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['customer_name']); ?> (<?php echo $customer['agreement_number']; ?>)
                                </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="input-group">
                                <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- EMI Table -->
            <div class="dashboard-card">
                <div class="card-header">
                    <div>
                        <h5>EMI Schedule</h5>
                        <p>Showing <?php echo $emi_list->num_rows; ?> of <?php echo $total_count; ?> records</p>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($emi_list && $emi_list->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="emiTable">
                                <thead>
                                    <tr>
                                        <?php if ($view_customer == 0): ?>
                                        <th>Customer</th>
                                        <?php endif; ?>
                                        <th>#</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Installment</th>
                                        <th>Paid Amount</th>
                                        <th>Remaining</th>
                                        <th>Due Date</th>
                                        <th>Paid Date</th>
                                        <th>Overdue Days</th>
                                        <th>Overdue</th>
                                        <th>Bill No</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = $offset + 1;
                                    while ($emi = $emi_list->fetch_assoc()): 
                                        // Calculate paid amount
                                        $paid_amount = ($emi['principal_paid'] ?? 0) + ($emi['interest_paid'] ?? 0) + ($emi['overdue_charges'] ?? 0);
                                        
                                        // Calculate remaining
                                        $remaining = ($emi['emi_amount'] ?? 0) - (($emi['principal_paid'] ?? 0) + ($emi['interest_paid'] ?? 0));
                                        if ($remaining < 0) $remaining = 0;
                                        
                                        // Get due date
                                        $due_date_display = getDueDate($emi);
                                        
                                        // Format paid date
                                        $paid_date_display = '-';
                                        if (isset($emi['paid_date']) && $emi['paid_date'] != '0000-00-00') {
                                            $paid_date_display = date('d-m-Y', strtotime($emi['paid_date']));
                                        }
                                        
                                        // Calculate overdue days
                                        $overdue_days = 0;
                                        if ($emi['status'] != 'paid' && $due_date_display != '-' && $due_date_display != 'Invalid Date') {
                                            try {
                                                $due_date = DateTime::createFromFormat('d-m-Y', $due_date_display);
                                                if ($due_date) {
                                                    $due_date->setTime(0, 0, 0);
                                                    $today = new DateTime();
                                                    $today->setTime(0, 0, 0);
                                                    
                                                    if ($due_date < $today) {
                                                        $interval = $today->diff($due_date);
                                                        $overdue_days = $interval->days;
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                $overdue_days = 0;
                                            }
                                        }
                                        
                                        // Determine row class
                                        $row_class = '';
                                        if ($emi['status'] == 'paid') {
                                            $row_class = 'paid';
                                        } elseif ($overdue_days > 0) {
                                            $row_class = 'overdue';
                                        }
                                        
                                        // WhatsApp message
                                        if ($emi['status'] == 'paid') {
                                            $whatsapp_message = "Dear " . $emi['customer_name'] . ", thank you for your EMI payment of ₹" . number_format($paid_amount, 2) . " for Agreement " . $emi['agreement_number'] . " received on " . $paid_date_display . ". Bill No: " . ($emi['emi_bill_number'] ?? 'N/A');
                                        } elseif ($overdue_days > 0) {
                                            $whatsapp_message = "Dear " . $emi['customer_name'] . ", your EMI of ₹" . number_format($emi['emi_amount'], 2) . " for Agreement " . $emi['agreement_number'] . " was due on " . $due_date_display . " and is now overdue by " . $overdue_days . " days. Overdue charges: ₹" . number_format($emi['overdue_charges'], 2) . ". Please make the payment immediately.";
                                        } else {
                                            $whatsapp_message = "Dear " . $emi['customer_name'] . ", your EMI of ₹" . number_format($emi['emi_amount'], 2) . " for Agreement " . $emi['agreement_number'] . " is due on " . $due_date_display . ". Please make the payment.";
                                        }
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <?php if ($view_customer == 0): ?>
                                        <td>
                                            <a href="?view_customer=<?php echo $emi['customer_id']; ?>" class="text-decoration-none">
                                                <div class="fw-semibold text-primary"><?php echo htmlspecialchars($emi['customer_name']); ?></div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-hash"></i> <?php echo htmlspecialchars($emi['agreement_number']); ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($emi['customer_number']); ?>
                                                </div>
                                            </a>
                                            <div class="mt-1">
                                                <span class="badge-collection-type <?php echo $emi['collection_type']; ?>">
                                                    <i class="bi bi-<?php echo $emi['collection_type'] == 'monthly' ? 'calendar-month' : ($emi['collection_type'] == 'weekly' ? 'calendar-week' : 'calendar-day'); ?> me-1"></i>
                                                    <?php echo ucfirst($emi['collection_type']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <span class="fw-semibold">#<?php echo $counter++; ?></span>
                                            <div class="mt-1">
                                                <span class="badge-loan-type">
                                                    <i class="bi bi-bank"></i>
                                                    <?php echo htmlspecialchars(substr($emi['loan_name'], 0, 10)); ?>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div class="installment-details">
                                                <span class="installment-value principal">₹<?php echo number_format($emi['principal_amount'] ?? 0, 2); ?></span>
                                                <?php if (($emi['principal_paid'] ?? 0) > 0): ?>
                                                <div class="small text-success">Paid: ₹<?php echo number_format($emi['principal_paid'], 2); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div class="installment-details">
                                                <span class="installment-value interest">₹<?php echo number_format($emi['interest_amount'] ?? 0, 2); ?></span>
                                                <?php if (($emi['interest_paid'] ?? 0) > 0): ?>
                                                <div class="small text-success">Paid: ₹<?php echo number_format($emi['interest_paid'], 2); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <span class="installment-value total fw-bold">₹<?php echo number_format($emi['emi_amount'] ?? 0, 2); ?></span>
                                        </td>
                                        
                                        <td>
                                            <?php if ($paid_amount > 0): ?>
                                            <span class="fw-bold text-success">₹<?php echo number_format($paid_amount, 2); ?></span>
                                            <?php if (($emi['principal_paid'] ?? 0) > 0 || ($emi['interest_paid'] ?? 0) > 0 || ($emi['overdue_charges'] ?? 0) > 0): ?>
                                            <div class="paid-amounts">
                                                P:₹<?php echo number_format($emi['principal_paid'] ?? 0, 2); ?> 
                                                I:₹<?php echo number_format($emi['interest_paid'] ?? 0, 2); ?>
                                                <?php if (($emi['overdue_charges'] ?? 0) > 0): ?>
                                                <span class="text-danger">O:₹<?php echo number_format($emi['overdue_charges'], 2); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php if ($remaining > 0): ?>
                                            <span class="fw-semibold text-warning">₹<?php echo number_format($remaining, 2); ?></span>
                                            <?php else: ?>
                                            <span class="text-success">₹0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <div class="date-item">
                                                <i class="bi bi-calendar3"></i>
                                                <span><?php echo $due_date_display; ?></span>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <?php if ($emi['status'] == 'paid'): ?>
                                            <div class="date-item text-success">
                                                <i class="bi bi-check-circle"></i>
                                                <span><?php echo $paid_date_display; ?></span>
                                            </div>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php if ($overdue_days > 0): ?>
                                            <span class="overdue-days"><?php echo $overdue_days; ?> days</span>
                                            <?php else: ?>
                                            <span class="text-muted">0 days</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php if (($emi['overdue_charges'] ?? 0) > 0): ?>
                                                <span class="overdue-charge-badge">
                                                    <i class="bi bi-exclamation-circle"></i>
                                                    ₹<?php echo number_format($emi['overdue_charges'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php if (isset($emi['emi_bill_number']) && $emi['emi_bill_number']): ?>
                                            <span class="small"><?php echo $emi['emi_bill_number']; ?></span>
                                            <?php else: ?>
                                            <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?php if ($emi['status'] == 'paid'): ?>
                                                <span class="status-badge paid">Paid</span>
                                            <?php elseif ($overdue_days > 0 || $emi['status'] == 'overdue'): ?>
                                                <span class="status-badge overdue">Overdue</span>
                                            <?php else: ?>
                                                <span class="status-badge unpaid">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <div class="d-flex gap-1">
                                                <a href="view-customer.php?id=<?php echo $emi['customer_id']; ?>" class="action-btn view" title="View Customer" data-bs-toggle="tooltip">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                
                                                <a href="<?php echo getWhatsAppLink($emi['customer_number'] ?? '', $whatsapp_message); ?>" target="_blank" class="action-btn whatsapp" title="Send WhatsApp" data-bs-toggle="tooltip">
                                                    <i class="bi bi-whatsapp"></i>
                                                </a>
                                                
                                                <?php if ($emi['status'] == 'paid'): ?>
                                                    <a href="download-receipt.php?emi_id=<?php echo $emi['emi_id'] ?? $emi['id']; ?>" class="action-btn pdf" title="Download Receipt" data-bs-toggle="tooltip">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </a>
                                                    <?php if (function_exists('hasPermission') && hasPermission('edit_payment')): ?>
                                                    <a href="?undo=<?php echo $emi['emi_id'] ?? $emi['id']; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page])); ?>" class="action-btn undo" title="Undo Payment" onclick="return confirm('Are you sure you want to undo this payment?')" data-bs-toggle="tooltip">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (function_exists('hasPermission') && hasPermission('record_collection')): ?>
                                                    <a href="collect-emi.php?emi_id=<?php echo $emi['emi_id'] ?? $emi['id']; ?>&customer_id=<?php echo $emi['customer_id']; ?>" class="action-btn collect" title="Collect Payment" data-bs-toggle="tooltip">
                                                        <i class="bi bi-cash"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page-1; ?>&filter_type=<?php echo $filter_type; ?>&status=<?php echo $filter_status; ?>&customer_id=<?php echo $customer_id; ?>&search=<?php echo urlencode($search); ?><?php echo $view_customer > 0 ? '&view_customer=' . $view_customer : ''; ?>">
                                        <i class="bi bi-chevron-left"></i> Previous
                                    </a>
                                </li>
                                <?php for ($i = max(1, $page-2); $i <= min($page+2, $total_pages); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&filter_type=<?php echo $filter_type; ?>&status=<?php echo $filter_status; ?>&customer_id=<?php echo $customer_id; ?>&search=<?php echo urlencode($search); ?><?php echo $view_customer > 0 ? '&view_customer=' . $view_customer : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page+1; ?>&filter_type=<?php echo $filter_type; ?>&status=<?php echo $filter_status; ?>&customer_id=<?php echo $customer_id; ?>&search=<?php echo urlencode($search); ?><?php echo $view_customer > 0 ? '&view_customer=' . $view_customer : ''; ?>">
                                        Next <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-calendar-x" style="font-size: 3rem; color: #dee2e6;"></i>
                            <h5 class="mt-3 text-muted">No EMI records found</h5>
                            <p class="text-muted">Try adjusting your filters or add a new customer</p>
                            <a href="add-customer.php" class="btn btn-primary mt-2">
                                <i class="bi bi-person-plus me-2"></i>Add Customer
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Initialize DataTable if there are records
        if ($('#emiTable tbody tr').length > 0) {
            $('#emiTable').DataTable({
                pageLength: 25,
                ordering: true,
                searching: true,
                paging: true,
                info: true,
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [<?php echo $view_customer > 0 ? '13' : '14'; ?>] }
                ]
            });
        }
    });
</script>

</body>
</html>