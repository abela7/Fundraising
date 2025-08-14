<?php
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
				<a href="/fundraising/registrar/index.php" class="nav-link <?php echo ($current_page === 'index' && !$is_messages) ? 'active' : ''; ?>">
                    <i class="fas fa-plus-circle"></i>
                    <span class="nav-link-text">New Registration</span>
                </a>
            </div>
        </div>
        
        <!-- Records Section -->
        <div class="nav-section">
            <div class="nav-section-title">Records</div>
			<div class="nav-item">
				<a href="/fundraising/registrar/my-registrations.php" class="nav-link <?php echo $current_page === 'my-registrations' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    <span class="nav-link-text">My Registrations</span>
                </a>
            </div>
            <div class="nav-item">
				<a href="/fundraising/registrar/statistics.php" class="nav-link <?php echo $current_page === 'statistics' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-link-text">Statistics</span>
                </a>
            </div>
            <div class="nav-item">
				<a href="/fundraising/registrar/messages/" class="nav-link <?php echo $is_messages ? 'active' : ''; ?>">
                    <i class="fas fa-comments"></i>
                    <span class="nav-link-text">Messages</span>
                </a>
            </div>
        </div>
        
        <!-- Quick Links -->
        <div class="nav-section">
            <div class="nav-section-title">Quick Links</div>
			<div class="nav-item">
				<a href="/fundraising/public/projector/" target="_blank" class="nav-link">
                    <i class="fas fa-tv"></i>
                    <span class="nav-link-text">Projector View</span>
                </a>
            </div>
            
        </div>
    </nav>
</aside>
