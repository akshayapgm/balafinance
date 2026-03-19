<?php
session_start();
require_once 'includes/db.php';

$error = '';
$success = '';

// Check if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } else {
        // Check login attempts
        $attempt_query = "SELECT login_attempts, locked_until FROM users WHERE username = ?";
        $stmt = $conn->prepare($attempt_query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $attempt_result = $stmt->get_result();
        
        if ($attempt_result && $attempt_result->num_rows > 0) {
            $user_data = $attempt_result->fetch_assoc();
            
            // Check if account is locked
            if ($user_data['locked_until'] && strtotime($user_data['locked_until']) > time()) {
                $lock_time = new DateTime($user_data['locked_until']);
                $now = new DateTime();
                $diff = $now->diff($lock_time);
                $minutes = $diff->i;
                $error = "Account is locked. Please try again after $minutes minutes.";
            } else {
                // Verify password
                $user_query = "SELECT * FROM users WHERE username = ? AND status = 'active'";
                $stmt = $conn->prepare($user_query);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    
                    // Check password (supports both hashed and plain text for backward compatibility)
                    $password_valid = false;
                    
                    // Check if password is hashed (starts with $2y$)
                    if (strpos($user['password'], '$2y$') === 0) {
                        $password_valid = password_verify($password, $user['password']);
                    } else {
                        // Plain text comparison (for existing users)
                        $password_valid = ($password === $user['password']);
                        
                        // If plain text matches, update to hashed password
                        if ($password_valid) {
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $update_query = "UPDATE users SET password = ? WHERE id = ?";
                            $update_stmt = $conn->prepare($update_query);
                            $update_stmt->bind_param("si", $hashed, $user['id']);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                    }
                    
                    if ($password_valid) {
                        // Reset login attempts
                        $reset_query = "UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?";
                        $stmt = $conn->prepare($reset_query);
                        $stmt->bind_param("i", $user['id']);
                        $stmt->execute();
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['finance_id'] = $user['finance_id'];
                        
                        // Update last login
                        $login_query = "UPDATE users SET last_login = NOW() WHERE id = ?";
                        $stmt = $conn->prepare($login_query);
                        $stmt->bind_param("i", $user['id']);
                        $stmt->execute();
                        
                        // Log activity
                        if (function_exists('logActivity')) {
                            logActivity($conn, 'login', "User logged in successfully");
                        }
                        
                        // Redirect based on role or requested page
                        $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
                        header('Location: ' . $redirect);
                        exit();
                    } else {
                        // Increment login attempts
                        $attempts = $user_data['login_attempts'] + 1;
                        $locked_until = null;
                        
                        if ($attempts >= 5) {
                            $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                            $error = "Too many failed attempts. Account locked for 15 minutes.";
                        } else {
                            $error = "Invalid password. " . (5 - $attempts) . " attempts remaining.";
                        }
                        
                        $update_attempts = "UPDATE users SET login_attempts = ?, locked_until = ? WHERE username = ?";
                        $stmt = $conn->prepare($update_attempts);
                        $stmt->bind_param("iss", $attempts, $locked_until, $username);
                        $stmt->execute();
                    }
                } else {
                    $error = "Invalid username or account is inactive";
                }
            }
        } else {
            $error = "Invalid username";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Finance Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 0;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            padding: 2rem;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header img {
            width: 80px;
            height: 80px;
            margin-bottom: 1rem;
            border-radius: 50%;
            padding: 10px;
            background: linear-gradient(135deg, var(--primary), var(--info));
        }
        
        .login-header h2 {
            color: #333;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .login-header p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .input-group {
            border: 2px solid #eef2f6;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .input-group:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: none;
            color: #666;
            padding: 0.75rem 1rem;
        }
        
        .form-control {
            border: none;
            padding: 0.75rem 1rem;
            font-size: 1rem;
        }
        
        .form-control:focus {
            box-shadow: none;
            outline: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--primary), var(--info));
            color: white;
            border: none;
            border-radius: 12px;
            padding: 0.75rem;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem;
            margin-bottom: 1.5rem;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }
        
        .alert-success {
            background: #e3f7e3;
            color: #10b981;
            border-left: 4px solid #10b981;
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 1rem;
        }
        
        .role-badge.admin {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .role-badge.staff {
            background: #e3f2fd;
            color: #2196f3;
        }
        
        .role-badge.accountant {
            background: #fff3e0;
            color: #f97316;
        }
        
        .demo-credentials {
            margin-top: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 12px;
            font-size: 0.85rem;
        }
        
        .demo-credentials h6 {
            color: #666;
            margin-bottom: 0.75rem;
        }
        
        .demo-credentials p {
            margin-bottom: 0.25rem;
            color: #555;
        }
        
        .demo-credentials i {
            color: var(--primary);
            margin-right: 0.5rem;
        }
        
        .footer {
            text-align: center;
            margin-top: 2rem;
            color: rgba(255,255,255,0.8);
            font-size: 0.85rem;
        }
        
        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 600;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <img src="assets/logo.png" alt="Finance Manager">
                <h2>Welcome Back</h2>
                <p>Sign in to access your account</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-person"></i>
                        </span>
                        <input type="text" class="form-control" name="username" 
                               placeholder="Enter your username" required autofocus>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>
                        <input type="password" class="form-control" name="password" 
                               placeholder="Enter your password" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>
            
            <!-- Demo Credentials for Testing -->
            
        </div>
        
        <div class="footer">
            <p>&copy; 2026 Finance Manager. All rights reserved.</p>
            <p>
                <a href="#">Privacy Policy</a> | 
                <a href="#">Terms of Service</a> | 
                <a href="#">Contact Support</a>
            </p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add loading state to form
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            btn.classList.add('btn-loading');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in...';
        });
    </script>
</body>
</html>