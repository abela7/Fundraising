<?php
require_once __DIR__ . '/../../shared/url.php';
// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
?>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar collapsed" id="sidebar">
    <div class="sidebar-header">
        <a href="<?php echo htmlspecialchars(url_for('donor/index.php')); ?>" class="sidebar-brand">
            <i class="fas fa-user-heart"></i>
            <div class="sidebar-brand-text">
                Donor Portal
                <small>Church Fundraising</small>
            </div>
        </a>
        <button class="sidebar-close" id="sidebarClose">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/index.php')); ?>" 
                   class="nav-link <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span class="nav-link-text">Dashboard</span>
                </a>
            </div>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Payment</div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/make-payment.php')); ?>" 
                   class="nav-link <?php echo $current_page === 'make-payment' ? 'active' : ''; ?>">
                    <i class="fas fa-credit-card"></i>
                    <span class="nav-link-text">Make a Payment</span>
                </a>
            </div>
            <?php if (isset($current_donor) && $current_donor['has_active_plan']): ?>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/payment-plan.php')); ?>" 
                   class="nav-link <?php echo $current_page === 'payment-plan' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span class="nav-link-text">Pledges & Plans</span>
                </a>
            </div>
            <?php endif; ?>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/payment-history.php')); ?>" 
                   class="nav-link <?php echo $current_page === 'payment-history' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <span class="nav-link-text">Payment History</span>
                </a>
            </div>
        </div>
        
        <div class="nav-section">
            <div class="nav-section-title">Account</div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/profile.php')); ?>" 
                   class="nav-link <?php echo $current_page === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span class="nav-link-text">Preferences</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/contact.php')); ?>" 
                   class="nav-link <?php echo $current_page === 'contact' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i>
                    <span class="nav-link-text">Contact Support</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('donor/logout.php')); ?>" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="nav-link-text">Logout</span>
                </a>
            </div>
        </div>
    </nav>
</aside>

