<?php
/**
 * Donor Portal - Profile
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

require_donor_login();
$donor = current_donor();
$page_title = 'My Profile';
$current_donor = $donor;

$success_message = '';
$error_message = '';

// Handle profile updates (if needed in future)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    // Future: Allow donors to update preferences
    $success_message = 'Profile updated successfully!';
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/donor.css">
</head>
<body>
<div class="donor-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="donor-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-user me-2"></i>My Profile
                        </h1>
                        <p class="text-muted mb-0">Manage your account information</p>
                    </div>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Profile Information -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-info-circle text-primary me-2"></i>Personal Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Name</label>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($donor['name']); ?></strong></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted">Phone</label>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($donor['phone']); ?></strong></p>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label text-muted">Language Preference</label>
                                    <p class="mb-0">
                                        <span class="badge bg-info">
                                            <?php 
                                            $lang = $donor['preferred_language'] ?? 'en';
                                            echo $lang === 'en' ? 'English' : ($lang === 'am' ? 'Amharic' : 'Tigrinya');
                                            ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-pound-sign text-primary me-2"></i>Financial Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Total Pledged</label>
                                    <p class="mb-0"><strong class="text-primary">£<?php echo number_format($donor['total_pledged'], 2); ?></strong></p>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted">Total Paid</label>
                                    <p class="mb-0"><strong class="text-success">£<?php echo number_format($donor['total_paid'], 2); ?></strong></p>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label text-muted">Remaining Balance</label>
                                    <p class="mb-0">
                                        <strong class="text-<?php echo $donor['balance'] > 0 ? 'warning' : 'secondary'; ?>">
                                            £<?php echo number_format($donor['balance'], 2); ?>
                                        </strong>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Preferences -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-credit-card text-primary me-2"></i>Payment Preferences
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Preferred Payment Method</label>
                                    <p class="mb-0">
                                        <span class="badge bg-secondary">
                                            <?php echo ucfirst(str_replace('_', ' ', $donor['preferred_payment_method'] ?? 'bank_transfer')); ?>
                                        </span>
                                    </p>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label text-muted">Preferred Payment Day</label>
                                    <p class="mb-0"><strong>Day <?php echo $donor['preferred_payment_day'] ?? 1; ?> of the month</strong></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Activity -->
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-clock text-primary me-2"></i>Account Activity
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label text-muted">Last Login</label>
                                    <p class="mb-0">
                                        <?php 
                                        if ($donor['last_login_at'] ?? null) {
                                            echo date('d M Y, H:i', strtotime($donor['last_login_at']));
                                        } else {
                                            echo '<span class="text-muted">Never</span>';
                                        }
                                        ?>
                                    </p>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label text-muted">Total Logins</label>
                                    <p class="mb-0"><strong><?php echo $donor['login_count'] ?? 0; ?></strong></p>
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
<script src="assets/donor.js"></script>
</body>
</html>

