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
                        <p class="text-muted mb-0">Manage donor profiles, history, and relationships</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="donor.php" class="btn btn-primary">
                            <i class="fas fa-chart-line me-2"></i>View Donor Report
                        </a>
                    </div>
                </div>

                <!-- Main Content Area -->
                <div class="row g-4">
                    <!-- Quick Access Cards -->
                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="setup_donor_type.php" class="text-decoration-none">
                            <div class="card h-100 border-0 shadow-sm hover-lift">
                                <div class="card-body text-center p-4">
                                    <div class="mb-3">
                                        <i class="fas fa-tag fa-3x text-success"></i>
                                    </div>
                                    <h5 class="card-title">Setup Donor Types</h5>
                                    <p class="card-text text-muted">
                                        Add identifier to distinguish immediate payers from pledge donors
                                    </p>
                                    <span class="badge bg-success">Recommended First</span>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="donor.php" class="text-decoration-none">
                            <div class="card h-100 border-0 shadow-sm hover-lift">
                                <div class="card-body text-center p-4">
                                    <div class="mb-3">
                                        <i class="fas fa-database fa-3x text-primary"></i>
                                    </div>
                                    <h5 class="card-title">Pledge Donor Report</h5>
                                    <p class="card-text text-muted">
                                        Track your ~186 pledge donors and monitor payment progress
                                    </p>
                                </div>
                            </div>
                        </a>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm" style="opacity: 0.6;">
                            <div class="card-body text-center p-4">
                                <div class="mb-3">
                                    <i class="fas fa-list fa-3x text-secondary"></i>
                                </div>
                                <h5 class="card-title">Donor List</h5>
                                <p class="card-text text-muted">
                                    Browse and search all registered donors
                                </p>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm" style="opacity: 0.6;">
                            <div class="card-body text-center p-4">
                                <div class="mb-3">
                                    <i class="fas fa-calendar-alt fa-3x text-secondary"></i>
                                </div>
                                <h5 class="card-title">Payment Plans</h5>
                                <p class="card-text text-muted">
                                    Manage donor payment plans and schedules
                                </p>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm" style="opacity: 0.6;">
                            <div class="card-body text-center p-4">
                                <div class="mb-3">
                                    <i class="fas fa-bell fa-3x text-secondary"></i>
                                </div>
                                <h5 class="card-title">SMS Reminders</h5>
                                <p class="card-text text-muted">
                                    Send payment reminders and updates via SMS
                                </p>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm" style="opacity: 0.6;">
                            <div class="card-body text-center p-4">
                                <div class="mb-3">
                                    <i class="fas fa-globe fa-3x text-secondary"></i>
                                </div>
                                <h5 class="card-title">Donor Portal</h5>
                                <p class="card-text text-muted">
                                    Manage donor portal access and tokens
                                </p>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm" style="opacity: 0.6;">
                            <div class="card-body text-center p-4">
                                <div class="mb-3">
                                    <i class="fas fa-flag fa-3x text-secondary"></i>
                                </div>
                                <h5 class="card-title">Follow-ups</h5>
                                <p class="card-text text-muted">
                                    Track flagged donors requiring attention
                                </p>
                                <span class="badge bg-secondary">Coming Soon</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Section -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="fas fa-info-circle fa-2x text-primary"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-1">About Donor Management</h6>
                                        <p class="mb-0 text-muted small">
                                            This module provides comprehensive donor management capabilities including database reports, 
                                            payment plan tracking, SMS reminders, portal access management, and follow-up tracking. 
                                            Start with the <strong>Donor Database Report</strong> to get an overview of your donor data.
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

