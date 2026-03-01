<?php
require_once __DIR__ . '/../../shared/url.php';
require_once __DIR__ . '/../../shared/csrf.php';
// Get donor initials for avatar
$donor_initials = '';
$display_name = $current_donor['name'] ?? 'Donor';
$names = preg_split('/\s+/', trim($display_name));
foreach ($names as $name) {
    if ($name !== '') {
        $donor_initials .= strtoupper(substr($name, 0, 1));
    }
}
$donor_initials = substr($donor_initials ?: 'D', 0, 2);
$logout_url = url_for('donor/logout.php') . '?csrf_token=' . rawurlencode(csrf_token());
?>

<!-- Topbar -->
<header class="topbar">
    <div class="topbar-inner">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <button class="sidebar-toggle desktop-sidebar-toggle" id="desktopSidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="topbar-title d-none d-md-block">
                <?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?>
            </h1>
        </div>
        
        <div class="topbar-right">
            <!-- User Menu -->
            <div class="user-menu dropdown">
                <button class="user-menu-toggle dropdown-toggle" data-bs-toggle="dropdown">
                    <div class="user-avatar"><?php echo $donor_initials; ?></div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($display_name); ?></div>
                        <div class="user-role">Donor</div>
                    </div>
                </button>
                
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="<?php echo htmlspecialchars(url_for('donor/profile.php')); ?>">
                            <i class="fas fa-cog me-2"></i>Preferences
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="<?php echo htmlspecialchars(url_for('donor/contact.php')); ?>">
                            <i class="fas fa-envelope me-2"></i>Contact Support
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="<?php echo htmlspecialchars($logout_url); ?>">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>

