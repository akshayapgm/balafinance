<?php
session_start();
require_once 'includes/db.php';

// Log activity if user was logged in
if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
    logActivity($conn, 'logout', 'User logged out successfully');
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>