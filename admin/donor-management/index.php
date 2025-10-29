<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Donor Management';
$current_user = current_user();
$db = db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donor Management - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-users-cog me-2"></i>Donor Management
                        </h1>
                        <p class="text-muted mb-0">Comprehensive donor tracking and engagement tools</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="donor.php" class="btn btn-primary">
                            <i class="fas fa-chart-line me-2"></i>View Reports
                        </a>
                    </div>
                </div>

                <!-- Quick Stats Overview -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card animate-fade-in" style="color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-handshake"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">~186</h3>
                                <p class="stat-label">Pledge Donors</p>
                                <div class="stat-trend text-primary">
                                    <i class="fas fa-users"></i> Being tracked
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.1s; color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">Real-time</h3>
                                <p class="stat-label">Payment Tracking</p>
                                <div class="stat-trend text-success">
                                    <i class="fas fa-sync"></i> Live updates
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.2s; color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">Automated</h3>
                                <p class="stat-label">SMS Reminders</p>
                                <div class="stat-trend text-warning">
                                    <i class="fas fa-mobile-alt"></i> Coming soon
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.3s; color: #6366f1;">
                            <div class="stat-icon" style="background: #6366f1;">
                                <i class="fas fa-globe"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">Portal</h3>
                                <p class="stat-label">Self-Service Access</p>
                                <div class="stat-trend" style="color: #6366f1;">
                                    <i class="fas fa-user-check"></i> Coming soon
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Feature Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-12">
                        <h5 class="mb-3">
                            <i class="fas fa-th-large me-2 text-primary"></i>
                            Available Features
                        </h5>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Active Features -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="donor.php" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm hover-lift">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="feature-icon bg-primary-subtle text-primary me-3">
                                            <i class="fas fa-database"></i>
                                        </div>
                                        <div>
                                            <h5 class="card-title mb-0">Pledge Donor Report</h5>
                                            <span class="badge bg-success badge-sm">Active</span>
                                        </div>
                                    </div>
                                    <p class="card-text text-muted mb-3">
                                        Comprehensive tracking of ~186 pledge donors with payment progress monitoring, status tracking, and detailed analytics.
                                    </p>
                                    <div class="d-flex align-items-center text-primary">
                                        <span class="me-2">Open Report</span>
                                        <i class="fas fa-arrow-right"></i>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- Coming Soon Features -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.7;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-3">
                                        <i class="fas fa-list"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-0">Donor List</h5>
                                        <span class="badge bg-secondary badge-sm">Coming Soon</span>
                                    </div>
                                </div>
                                <p class="card-text text-muted mb-3">
                                    Browse, search, and filter all registered donors with advanced sorting and export capabilities.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.7;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-3">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-0">Payment Plans</h5>
                                        <span class="badge bg-secondary badge-sm">Coming Soon</span>
                                    </div>
                                </div>
                                <p class="card-text text-muted mb-3">
                                    Create and manage flexible payment plans with automated tracking and milestone notifications.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.7;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-3">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-0">SMS Reminders</h5>
                                        <span class="badge bg-secondary badge-sm">Coming Soon</span>
                                    </div>
                                </div>
                                <p class="card-text text-muted mb-3">
                                    Send automated payment reminders and updates via SMS with customizable templates.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.7;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-3">
                                        <i class="fas fa-globe"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-0">Donor Portal</h5>
                                        <span class="badge bg-secondary badge-sm">Coming Soon</span>
                                    </div>
                                </div>
                                <p class="card-text text-muted mb-3">
                                    Self-service portal for donors to view their pledges, make payments, and update information.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.7;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-3">
                                        <i class="fas fa-flag"></i>
                                    </div>
                                    <div>
                                        <h5 class="card-title mb-0">Follow-ups</h5>
                                        <span class="badge bg-secondary badge-sm">Coming Soon</span>
                                    </div>
                                </div>
                                <p class="card-text text-muted mb-3">
                                    Track and manage flagged donors requiring special attention or follow-up actions.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="row mt-5">
                    <div class="col-12">
                        <div class="card border-0 bg-gradient" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center text-white">
                                    <div class="me-3">
                                        <i class="fas fa-lightbulb fa-3x"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-2 text-white">About Donor Management</h5>
                                        <p class="mb-0 opacity-90">
                                            This comprehensive module helps you track pledge donors, monitor payment progress, send automated reminders, 
                                            and provide self-service portal access. Start with the <strong>Pledge Donor Report</strong> to get detailed 
                                            insights into your ~186 pledge donors and their payment status.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/donor-management.js"></script>

<script>
// Fallback for sidebar toggle if admin.js failed to attach for any reason
if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>
