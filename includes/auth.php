<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Function to check if user has permission
function hasPermission($permission) {
    $role = $_SESSION['role'] ?? 'user';
    
    // Define permissions by role
    $permissions = [
        'admin' => [
            'view_dashboard',
            'manage_finance',
            'add_finance',
            'manage_loans',
            'add_loan',
            'manage_customers',
            'add_customer',
            'view_overdue_customers',
            'view_collection_list',
            'view_pending_collections',
            'record_collection',
            'view_overdue_collections',
            'manage_expenses',
            'add_expense',
            'view_expense_history',
            'view_reports',
            'view_customer_wise_report',
            'view_loan_wise_report',
            'view_date_wise_report',
            'view_foreclosure',
            'view_calendar',
            'view_notifications',
            'manage_users',
            'add_user',
            'manage_settings',
            'manage_investors',
            'add_investor',
            'process_investor_return',
            'view_investor_report',
            'edit_payment',
            'view_all_collections',
            'view_customer_details',
            'edit_emi'
        ],
        'staff' => [
            'view_dashboard',
            'manage_customers',
            'add_customer',
            'view_overdue_customers',
            'view_collection_list',
            'view_pending_collections',
            'record_collection',
            'view_overdue_collections',
            'view_reports',
            'view_customer_wise_report',
            'view_calendar',
            'view_notifications',
            'view_investors',
            'view_investor_report'
        ],
        'accountant' => [
            'view_dashboard',
            'view_collection_list',
            'view_pending_collections',
            'view_overdue_collections',
            'manage_expenses',
            'add_expense',
            'view_expense_history',
            'view_reports',
            'view_customer_wise_report',
            'view_loan_wise_report',
            'view_date_wise_report',
            'view_calendar',
            'view_notifications',
            'view_investors',
            'view_investor_report'
        ],
        'user' => [] // Default user has no permissions
    ];
    
    return in_array($permission, $permissions[$role] ?? []);
}

// Function to log activity
if (!function_exists('logActivity')) {
    function logActivity($conn, $action, $details) {
        $user_id = $_SESSION['user_id'] ?? 0;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $query = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issss", $user_id, $action, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
}
?>