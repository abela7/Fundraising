<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

// Require authentication and admin role
requireAuth();
requireRole(['admin']);

$pageTitle = 'Donor Management';
$currentPage = 'donor-management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Fundraising Admin</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Custom Styles -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="./assets/donor-management.css">
</head>
<body>
    <div class="wrapper">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main">
            <?php include __DIR__ . '/../includes/topbar.php'; ?>
            
            <main class="content">
                <div class="container-fluid p-0">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h1 class="h3 mb-0">
                            <i class="bi bi-people-fill me-2"></i>
                            Donor Management
                        </h1>
                    </div>

                    <!-- Main Content Area -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Overview</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">
                                        Donor Management section - Ready for implementation.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </main>

            <?php include __DIR__ . '/../../api/footer.php'; ?>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom Scripts -->
    <script src="../assets/admin.js"></script>
    <script src="./assets/donor-management.js"></script>
</body>
</html>

