<?php
require_once __DIR__ . '/../../shared/url.php';
// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_messages = strpos($request_uri, '/registrar/messages/') !== false;
if ($is_messages) { $current_page = 'messages'; }
?>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar collapsed" id="sidebar">
    <div class="sidebar-header">
        <a href="../" class="sidebar-brand">
            <i class="fas fa-church"></i>
            <div class="sidebar-brand-text">
                Registrar Panel
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
				<a href="<?php echo htmlspecialchars(url_for('registrar/index.php')); ?>" class="nav-link <?php echo ($current_page === 'index' && !$is_messages) ? 'active' : ''; ?>">
                    <i class="fas fa-plus-circle"></i>
                    <span class="nav-link-text">New Registration</span>
                </a>
            </div>
        </div>
        
        <!-- Records Section -->
        <div class="nav-section">
            <div class="nav-section-title">Records</div>
			<div class="nav-item">
				<a href="<?php echo htmlspecialchars(url_for('registrar/my-registrations.php')); ?>" class="nav-link <?php echo $current_page === 'my-registrations' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    <span class="nav-link-text">My Registrations</span>
                </a>
            </div>
            <div class="nav-item">
				<a href="<?php echo htmlspecialchars(url_for('registrar/statistics.php')); ?>" class="nav-link <?php echo $current_page === 'statistics' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-link-text">Statistics</span>
                </a>
            </div>
            <div class="nav-item">
				<a href="<?php echo htmlspecialchars(url_for('registrar/messages/')); ?>" class="nav-link <?php echo $is_messages ? 'active' : ''; ?>">
                    <i class="fas fa-comments"></i>
                    <span class="nav-link-text">Messages</span>
                </a>
            </div>
        </div>
        
        <!-- Call Center Section -->
        <div class="nav-section">
            <div class="nav-section-title">Call Center</div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('admin/call-center/')); ?>" class="nav-link">
                    <i class="fas fa-headset"></i>
                    <span class="nav-link-text">Call Center</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('admin/call-center/my-schedule.php')); ?>" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span class="nav-link-text">My Schedule</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('admin/messaging/whatsapp/inbox.php')); ?>" class="nav-link">
                    <i class="fab fa-whatsapp" style="color: #25D366;"></i>
                    <span class="nav-link-text">WhatsApp Chat</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('admin/donations/record-pledge-payment.php')); ?>" class="nav-link">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span class="nav-link-text">Record Payment</span>
                </a>
            </div>
        </div>
        
        <!-- Quick Links -->
        <div class="nav-section">
            <div class="nav-section-title">Quick Links</div>
            <div class="nav-item">
                <a href="<?php echo htmlspecialchars(url_for('public/projector/')); ?>" target="_blank" class="nav-link">
                    <i class="fas fa-tv"></i>
                    <span class="nav-link-text">Projector View</span>
                </a>
            </div>
        </div>
    </nav>
</aside>
