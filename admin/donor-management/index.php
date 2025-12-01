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

                <!-- Key Stats Overview -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">186</h3>
                                <p class="stat-label">Pledge Donors</p>
                                <div class="stat-trend text-muted">
                                    <i class="fas fa-clipboard-check"></i> Tracked
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.1s; color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">Active</h3>
                                <p class="stat-label">Tracking System</p>
                                <div class="stat-trend text-warning">
                                    <i class="fas fa-check"></i> Live
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.2s; color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">Real-time</h3>
                                <p class="stat-label">Payment Reports</p>
                                <div class="stat-trend text-success">
                                    <i class="fas fa-sync"></i> Updated
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.3s; color: #b91c1c;">
                            <div class="stat-icon bg-danger">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">4</h3>
                                <p class="stat-label">Active Features</p>
                                <div class="stat-trend text-danger">
                                    <i class="fas fa-check"></i> Available
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Feature Section -->
                <div class="row mb-4">
                    <div class="col-12">
                        <h5 class="mb-3">
                            <i class="fas fa-rocket me-2 text-primary"></i>
                            Available Tools & Features
                        </h5>
                    </div>
                </div>

                <div class="row g-4 mb-4">
                    <!-- Active Feature -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="donor.php" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="feature-icon bg-primary-subtle text-primary me-2">
                                            <i class="fas fa-database"></i>
                                        </div>
                                        <h6 class="mb-0">Pledge Donor Report</h6>
                                    </div>
                                    <p class="card-text text-muted small">View detailed analytics and payment tracking.</p>
                                    <span class="badge bg-success badge-sm">Active</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <!-- Active Features -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="donors.php" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="feature-icon bg-success-subtle text-success me-2">
                                            <i class="fas fa-list"></i>
                                        </div>
                                        <h6 class="mb-0">Donor List</h6>
                                    </div>
                                    <p class="card-text text-muted small">Browse and search all donors easily.</p>
                                    <span class="badge bg-success badge-sm">Active</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="payments.php" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="feature-icon bg-success-subtle text-success me-2">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                        <h6 class="mb-0">Payment Management</h6>
                                    </div>
                                    <p class="card-text text-muted small">Track all donor payments and status.</p>
                                    <span class="badge bg-success badge-sm">Active</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="payment-plans.php" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="feature-icon bg-warning-subtle text-warning me-2">
                                            <i class="fas fa-th-list"></i>
                                        </div>
                                        <h6 class="mb-0">Payment Plan Templates</h6>
                                    </div>
                                    <p class="card-text text-muted small">Create plan options donors can choose from.</p>
                                    <span class="badge bg-success badge-sm">Active</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="sms/" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="feature-icon bg-primary-subtle text-primary me-2">
                                            <i class="fas fa-comments"></i>
                                        </div>
                                        <h6 class="mb-0">SMS Dashboard</h6>
                                    </div>
                                    <p class="card-text text-muted small">Send messages & automated reminders.</p>
                                    <span class="badge bg-success badge-sm">Active</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="twilio/settings.php" class="text-decoration-none">
                            <div class="feature-card card h-100 border-0 shadow-sm">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="feature-icon bg-success-subtle text-success me-2">
                                            <i class="fas fa-phone-alt"></i>
                                        </div>
                                        <h6 class="mb-0">Twilio Call Settings</h6>
                                    </div>
                                    <p class="card-text text-muted small">Click-to-call with automatic recording.</p>
                                    <span class="badge bg-success badge-sm">Active</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.75;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-2">
                                        <i class="fas fa-globe"></i>
                                    </div>
                                    <h6 class="mb-0">Donor Portal</h6>
                                </div>
                                <p class="card-text text-muted small">Self-service access for donors.</p>
                                <span class="badge bg-secondary badge-sm">Coming Soon</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="feature-card card h-100 border-0 shadow-sm" style="opacity: 0.75;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center mb-2">
                                    <div class="feature-icon bg-secondary-subtle text-secondary me-2">
                                        <i class="fas fa-flag"></i>
                                    </div>
                                    <h6 class="mb-0">Follow-ups</h6>
                                </div>
                                <p class="card-text text-muted small">Track flagged donors for attention.</p>
                                <span class="badge bg-secondary badge-sm">Coming Soon</span>
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
