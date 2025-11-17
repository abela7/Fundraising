<?php
// Reusable Sidebar Navigation Component
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// --- Resilient Pending Applications Count ---
$pending_applications_count = 0;
try {
    $db_sidebar = db();
    $table_check_sidebar = $db_sidebar->query("SHOW TABLES LIKE 'registrar_applications'");
    if ($table_check_sidebar && $table_check_sidebar->num_rows > 0) {
        $result_sidebar = $db_sidebar->query("SELECT COUNT(*) as count FROM registrar_applications WHERE status = 'pending'");
        if ($result_sidebar) {
            $pending_applications_count = (int)($result_sidebar->fetch_assoc()['count'] ?? 0);
        }
    }
} catch (Exception $e) {
    // If DB fails for any reason, default to 0. This makes the sidebar resilient.
    $pending_applications_count = 0;
}
// --- End Resilient Count ---

?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-brand">
      <i class="fas fa-hand-holding-heart brand-icon"></i>
      <span class="brand-text">Fundraising</span>
    </div>
    <button class="sidebar-close d-lg-none" onclick="toggleSidebar()">
      <i class="fas fa-times"></i>
    </button>
  </div>
  
  <nav class="sidebar-nav">
    <!-- Main Section -->
    <div class="nav-section">
      <div class="nav-section-title">
        <span>Main</span>
      </div>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'dashboard' ? './' : '../dashboard/'; ?>" 
         class="nav-link <?php echo $current_dir === 'dashboard' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-tachometer-alt"></i>
        </span>
        <span class="nav-label">Dashboard</span>
      </a>
    </div>
    
    <!-- Management Section -->
    <div class="nav-section">
      <div class="nav-section-title">
        <span>Management</span>
      </div>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'approvals' ? './' : '../approvals/'; ?>" 
         class="nav-link <?php echo $current_dir === 'approvals' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-check-circle"></i>
        </span>
        <span class="nav-label">Approvals</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'pledges' ? './' : '../pledges/'; ?>" 
         class="nav-link <?php echo $current_dir === 'pledges' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-hand-holding-usd"></i>
        </span>
        <span class="nav-label">Pledges</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'members' ? './' : '../members/'; ?>" 
         class="nav-link <?php echo $current_dir === 'members' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-users"></i>
        </span>
        <span class="nav-label">Members</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'donor-management' ? './' : '../donor-management/'; ?>" 
         class="nav-link <?php echo $current_dir === 'donor-management' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-users-cog"></i>
        </span>
        <span class="nav-label">Donor Management</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'user-status' ? './' : '../user-status/'; ?>" 
         class="nav-link <?php echo $current_dir === 'user-status' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-user-check"></i>
        </span>
        <span class="nav-label">User Login Status</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'registrar-applications' ? './' : '../registrar-applications/'; ?>" 
         class="nav-link <?php echo $current_dir === 'registrar-applications' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-user-plus"></i>
        </span>
        <span class="nav-label">Registrar Applications</span>
        <?php if ($pending_applications_count > 0): ?>
          <span class="badge bg-danger ms-auto"><?php echo $pending_applications_count; ?></span>
        <?php endif; ?>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'payments' ? './' : '../payments/'; ?>" 
         class="nav-link <?php echo ($current_dir === 'payments' && $current_page !== 'cash') ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-credit-card"></i>
        </span>
        <span class="nav-label">Payments</span>
      </a>
      <a href="<?php echo ($current_dir === 'payments' ? 'cash.php' : '../payments/cash.php'); ?>" 
         class="nav-link <?php echo ($current_dir === 'payments' && $current_page === 'cash') ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-money-bill-wave"></i>
        </span>
        <span class="nav-label">Cash Payments</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'donations' ? './' : '../donations/'; ?>" 
         class="nav-link <?php echo $current_dir === 'donations' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-donate"></i>
        </span>
        <span class="nav-label">Donations Management</span>
      </a>
    </div>
    
    <!-- Operations Section -->
    <div class="nav-section">
      <div class="nav-section-title">
        <span>Operations</span>
      </div>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'call-center' ? './' : '../call-center/'; ?>" 
         class="nav-link <?php echo $current_dir === 'call-center' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-headset"></i>
        </span>
        <span class="nav-label">Call Center</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'reports' ? './' : '../reports/'; ?>" 
         class="nav-link <?php echo $current_dir === 'reports' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-chart-bar"></i>
        </span>
        <span class="nav-label">Reports</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'projector' ? './' : '../projector/'; ?>" 
         class="nav-link <?php echo $current_dir === 'projector' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-tv"></i>
        </span>
        <span class="nav-label">Projector Control</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'grid-allocation' ? './' : '../grid-allocation/'; ?>" 
         class="nav-link <?php echo $current_dir === 'grid-allocation' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-th"></i>
        </span>
        <span class="nav-label">Grid Allocation</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'audit' ? './' : '../audit/'; ?>" 
         class="nav-link <?php echo $current_dir === 'audit' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-history"></i>
        </span>
        <span class="nav-label">Audit Logs</span>
      </a>
    </div>
    
    <!-- System Section -->
    <div class="nav-section">
      <div class="nav-section-title">
        <span>System</span>
      </div>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'tools' ? './' : '../tools/'; ?>" 
         class="nav-link <?php echo $current_dir === 'tools' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-wrench"></i>
        </span>
        <span class="nav-label">Developer Tools</span>
      </a>
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'settings' ? './' : '../settings/'; ?>" 
         class="nav-link <?php echo $current_dir === 'settings' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-cog"></i>
        </span>
        <span class="nav-label">Settings</span>
      </a>
      
      <a href="<?php echo dirname($_SERVER['PHP_SELF']) === 'profile' ? './' : '../profile/'; ?>" 
         class="nav-link <?php echo $current_dir === 'profile' ? 'active' : ''; ?>">
        <span class="nav-icon">
          <i class="fas fa-user-circle"></i>
        </span>
        <span class="nav-label">Profile</span>
      </a>
      <a href="../logout.php" class="nav-link">
        <span class="nav-icon">
          <i class="fas fa-sign-out-alt"></i>
        </span>
        <span class="nav-label">Logout</span>
      </a>
    </div>
  </nav>
  
  <div class="sidebar-footer">
    <div class="sidebar-footer-content">
      <i class="fas fa-church"></i>
      <span>Church Fundraising</span>
    </div>
  </div>
</aside>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>
