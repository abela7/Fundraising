<div class="topbar">
    <div class="topbar-left">
        <button class="btn btn-link sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-breadcrumb">
            <span class="breadcrumb-item active"><?php echo htmlspecialchars($page_title ?? 'Dashboard'); ?></span>
        </div>
    </div>
    
    <div class="topbar-right">
        <div class="dropdown">
            <button class="btn btn-link dropdown-toggle" type="button" id="userMenu" data-bs-toggle="dropdown">
                <i class="fas fa-user-circle me-2"></i>
                <span class="d-none d-md-inline"><?php echo htmlspecialchars($current_donor['name'] ?? 'Donor'); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="profile.php">
                        <i class="fas fa-user me-2"></i>My Profile
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

