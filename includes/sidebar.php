<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar Navigation -->
<nav class="sidebar" id="sidebar" data-testid="sidebar">
  <div class="sidebar-brand">
    <div class="sidebar-brand-icon">
      <img src="assets/logo.png" alt="Finance Logo" style="width: 38px; height: 38px; object-fit: contain;">
    </div>
    <div>
      <h2>Finance Manager</h2>
      <small>Financial Management</small>
    </div>
  </div>

  <div class="sidebar-nav">
    <div class="sidebar-label">Main</div>

    <a href="index.php" class="nav-link <?php echo ($currentPage ?? '') === 'dashboard' ? 'active' : ''; ?>" data-testid="nav-dashboard">
      <i class="bi bi-grid-1x2-fill"></i>
      <span>Dashboard</span>
    </a>

    <div class="sidebar-label">Finance Management</div>

    <a href="finance.php" class="nav-link <?php echo ($currentPage ?? '') === 'finance' ? 'active' : ''; ?>" data-testid="nav-finance">
      <i class="bi bi-bank"></i>
      <span>Manage Finance</span>
    </a>
    
    <a href="add-finance.php" class="nav-link <?php echo ($currentPage ?? '') === 'add-finance' ? 'active' : ''; ?>" data-testid="nav-add-finance">
      <i class="bi bi-plus-circle"></i>
      <span>Add Finance</span>
    </a>

    <!-- Investor Management Section -->
    <div class="sidebar-label">Investor Management</div>

    <a href="investors.php" class="nav-link <?php echo ($currentPage ?? '') === 'investors' ? 'active' : ''; ?>" data-testid="nav-investors">
      <i class="bi bi-people-fill"></i>
      <span>Manage Investors</span>
      <?php
      // Get investor count
      $investor_count = 0;
      if (isset($conn)) {
        $result = $conn->query("SELECT COUNT(*) as cnt FROM investors WHERE status = 'active'");
        $investor_count = $result ? $result->fetch_assoc()['cnt'] : 0;
      }
      if ($investor_count > 0) {
        echo '<span class="badge bg-success ms-auto">' . $investor_count . '</span>';
      }
      ?>
    </a>
    
    <a href="add-investor.php" class="nav-link <?php echo ($currentPage ?? '') === 'add-investor' ? 'active' : ''; ?>" data-testid="nav-add-investor">
      <i class="bi bi-person-plus"></i>
      <span>Add Investor</span>
    </a>
    
    <a href="investor-return.php" class="nav-link <?php echo ($currentPage ?? '') === 'investor-return' ? 'active' : ''; ?>" data-testid="nav-investor-return">
      <i class="bi bi-cash-stack"></i>
      <span>Return Investor</span>
    </a>
    
    <a href="investor-report.php" class="nav-link <?php echo ($currentPage ?? '') === 'investor-report' ? 'active' : ''; ?>" data-testid="nav-investor-report">
      <i class="bi bi-graph-up"></i>
      <span>Investor Report</span>
    </a>

    <div class="sidebar-label">Loan Management</div>

    <a href="loans.php" class="nav-link <?php echo ($currentPage ?? '') === 'loans' ? 'active' : ''; ?>" data-testid="nav-loans">
      <i class="bi bi-cash-stack"></i>
      <span>Manage Loans</span>
    </a>
    
    <a href="add-loan.php" class="nav-link <?php echo ($currentPage ?? '') === 'add-loan' ? 'active' : ''; ?>" data-testid="nav-add-loan">
      <i class="bi bi-plus-circle"></i>
      <span>Add New Loan</span>
    </a>

    <div class="sidebar-label">Customer Management</div>

    <a href="manage-customers.php" class="nav-link <?php echo ($currentPage ?? '') === 'customers' ? 'active' : ''; ?>" data-testid="nav-customers">
      <i class="bi bi-people"></i>
      <span>Manage Customers</span>
    </a>
    
    <a href="add-customer.php" class="nav-link <?php echo ($currentPage ?? '') === 'add-customer' ? 'active' : ''; ?>" data-testid="nav-add-customer">
      <i class="bi bi-person-plus"></i>
      <span>Add New Customer</span>
    </a>
    
    <a href="overdue-customers.php" class="nav-link <?php echo ($currentPage ?? '') === 'overdue-customers' ? 'active' : ''; ?>" data-testid="nav-overdue-customers">
      <i class="bi bi-exclamation-triangle"></i>
      <span>Overdue Customers</span>
      <?php
      // Get overdue count
      $overdue_count = 0;
      if (isset($conn)) {
        $result = $conn->query("SELECT COUNT(DISTINCT customer_id) as cnt FROM emi_schedule WHERE status='overdue'");
        $overdue_count = $result ? $result->fetch_assoc()['cnt'] : 0;
      }
      if ($overdue_count > 0) {
        echo '<span class="badge bg-danger ms-auto">' . $overdue_count . '</span>';
      }
      ?>
    </a>

    <div class="sidebar-label">Collections</div>

    <a href="collection-list.php" class="nav-link <?php echo ($currentPage ?? '') === 'collection-list' ? 'active' : ''; ?>" data-testid="nav-collection-list">
      <i class="bi bi-list-check"></i>
      <span>Collection List</span>
    </a>
    
    <a href="pending-collections.php" class="nav-link <?php echo ($currentPage ?? '') === 'pending-collections' ? 'active' : ''; ?>" data-testid="nav-pending-collections">
      <i class="bi bi-hourglass-split"></i>
      <span>Pending Collections</span>
    </a>
    
    <a href="collections.php" class="nav-link <?php echo ($currentPage ?? '') === 'collections' ? 'active' : ''; ?>" data-testid="nav-collections">
      <i class="bi bi-cash"></i>
      <span>Collections</span>
    </a>
    
    <a href="overdue-collections.php" class="nav-link <?php echo ($currentPage ?? '') === 'overdue-collections' ? 'active' : ''; ?>" data-testid="nav-overdue-collections">
      <i class="bi bi-clock-history"></i>
      <span>Overdue Collections</span>
    </a>

    <div class="sidebar-label">Expenses</div>

    <a href="expenses.php" class="nav-link <?php echo ($currentPage ?? '') === 'expenses' ? 'active' : ''; ?>" data-testid="nav-expenses">
      <i class="bi bi-wallet2"></i>
      <span>Manage Expenses</span>
    </a>
    
    <a href="expense-history.php" class="nav-link <?php echo ($currentPage ?? '') === 'expense-history' ? 'active' : ''; ?>" data-testid="nav-expense-history">
      <i class="bi bi-clock"></i>
      <span>Expenses History</span>
    </a>

    <div class="sidebar-label">Reports</div>

    <a href="reports.php" class="nav-link <?php echo ($currentPage ?? '') === 'reports' ? 'active' : ''; ?>" data-testid="nav-reports">
      <i class="bi bi-graph-up"></i>
      <span>Reports</span>
    </a>
    
    <a href="overall-report.php" class="nav-link <?php echo ($currentPage ?? '') === 'overall-report' ? 'active' : ''; ?>" data-testid="nav-overall-report">
      <i class="bi bi-pie-chart-fill"></i>
      <span>Overall Report</span>
    </a>
    
    <a href="customer-wise-report.php" class="nav-link <?php echo ($currentPage ?? '') === 'customer-wise' ? 'active' : ''; ?>" data-testid="nav-customer-wise">
      <i class="bi bi-person-badge"></i>
      <span>Customer Wise Report</span>
    </a>
    
    <a href="loan-wise-report.php" class="nav-link <?php echo ($currentPage ?? '') === 'loan-wise' ? 'active' : ''; ?>" data-testid="nav-loan-wise">
      <i class="bi bi-file-text"></i>
      <span>Loan Wise Report</span>
    </a>
    
    <a href="date-wise-report.php" class="nav-link <?php echo ($currentPage ?? '') === 'date-wise' ? 'active' : ''; ?>" data-testid="nav-date-wise">
      <i class="bi bi-calendar-week"></i>
      <span>Date Wise Report</span>
    </a>

    <div class="sidebar-label">Foreclosure</div>

    <a href="foreclosure-list.php" class="nav-link <?php echo ($currentPage ?? '') === 'foreclosure' ? 'active' : ''; ?>" data-testid="nav-foreclosure">
      <i class="bi bi-file-earmark-x"></i>
      <span>Foreclosure List</span>
    </a>

    <div class="sidebar-label">Calendar & Notifications</div>

    <a href="calendar.php" class="nav-link <?php echo ($currentPage ?? '') === 'calendar' ? 'active' : ''; ?>" data-testid="nav-calendar">
      <i class="bi bi-calendar3"></i>
      <span>Calendar</span>
    </a>
    
    <a href="notifications.php" class="nav-link <?php echo ($currentPage ?? '') === 'notifications' ? 'active' : ''; ?>" data-testid="nav-notifications">
      <i class="bi bi-bell"></i>
      <span>Notifications</span>
      <?php
      // Get unread notifications count (for demo, you can customize this)
      $notify_count = 0;
      echo '<span class="badge bg-danger ms-auto">' . $notify_count . '</span>';
      ?>
    </a>

    <div class="sidebar-label">User Management</div>

    <a href="users.php" class="nav-link <?php echo ($currentPage ?? '') === 'users' ? 'active' : ''; ?>" data-testid="nav-users">
      <i class="bi bi-people-fill"></i>
      <span>List Users</span>
    </a>
    
    <a href="add-user.php" class="nav-link <?php echo ($currentPage ?? '') === 'add-user' ? 'active' : ''; ?>" data-testid="nav-add-user">
      <i class="bi bi-person-plus"></i>
      <span>Add User</span>
    </a>

    <div class="sidebar-label">System</div>

    <a href="settings.php" class="nav-link <?php echo ($currentPage ?? '') === 'settings' ? 'active' : ''; ?>" data-testid="nav-settings">
      <i class="bi bi-gear"></i>
      <span>Settings</span>
    </a>
    
    <a href="logout.php" class="nav-link" data-testid="nav-logout">
      <i class="bi bi-box-arrow-right"></i>
      <span>Logout</span>
    </a>
  </div>

  <div class="sidebar-footer">
    <div class="user-info">
      <div class="user-avatar">
        <?php 
        // Get user initials from session
        $userName = $_SESSION['full_name'] ?? 'Admin User';
        $initials = '';
        $nameParts = explode(' ', $userName);
        foreach ($nameParts as $part) {
          $initials .= strtoupper(substr($part, 0, 1));
        }
        echo substr($initials, 0, 2);
        ?>
      </div>
      <div>
        <div class="user-name"><?php echo $_SESSION['full_name'] ?? 'Admin User'; ?></div>
        <div class="user-role"><?php echo $_SESSION['role'] ?? 'Administrator'; ?></div>
      </div>
    </div>
  </div>

  <button class="sidebar-toggle-compact" onclick="toggleCompactSidebar()" data-testid="button-compact-sidebar">
    <i class="bi bi-layout-sidebar-inset" id="compactIcon"></i>
    <span>Compact View</span>
  </button>
</nav>