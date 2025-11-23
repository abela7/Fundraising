<?php
/**
 * Donor Portal - Token-based Authentication
 * Donors access this portal via secure token links sent via SMS/email
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

// Check if donor is logged in via token
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

// Note: Token-based login can be added later if needed
// For now, donors login via phone number on login.php

// Require donor login to access
require_donor_login();
$donor = current_donor();

// Refresh donor data from database to ensure latest values
// This is critical after admin actions like undo batch
if ($donor && $db_connection_ok) {
    try {
        // Check if email column exists
        $email_check = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
        $has_email = $email_check->num_rows > 0;
        $email_check->close();
        
        // Check if email_opt_in column exists
        $email_opt_in_check = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
        $has_email_opt_in = $email_opt_in_check->num_rows > 0;
        $email_opt_in_check->close();
        
        // Build SELECT query with or without email and email_opt_in
        $select_fields = "id, name, phone, total_pledged, total_paid, balance, 
                   has_active_plan, active_payment_plan_id, plan_monthly_amount,
                   plan_duration_months, plan_start_date, plan_next_due_date,
                   payment_status, preferred_payment_method, preferred_language";
        if ($has_email) {
            $select_fields .= ", email";
        }
        if ($has_email_opt_in) {
            $select_fields .= ", email_opt_in";
        }
        
        $refresh_stmt = $db->prepare("
            SELECT $select_fields
            FROM donors 
            WHERE id = ? 
            LIMIT 1
        ");
        $refresh_stmt->bind_param('i', $donor['id']);
        $refresh_stmt->execute();
        $fresh_donor = $refresh_stmt->get_result()->fetch_assoc();
        $refresh_stmt->close();
        
        if ($fresh_donor) {
            // Update session with fresh data
            $_SESSION['donor'] = $fresh_donor;
            $donor = $fresh_donor;
        }
    } catch (Exception $e) {
        // Silent fail - continue with session data
        error_log("Failed to refresh donor session: " . $e->getMessage());
    }
}

$page_title = 'Donor Portal Dashboard';
$current_donor = $donor;

// Load donor's active payment plan if exists
$payment_plan = null;
if ($donor['has_active_plan'] && $donor['active_payment_plan_id'] && $db_connection_ok) {
    try {
        $plan_stmt = $db->prepare("
            SELECT pp.*, t.name as template_name
            FROM donor_payment_plans pp
            LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
            WHERE pp.id = ? AND pp.donor_id = ? AND pp.status = 'active'
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $donor['active_payment_plan_id'], $donor['id']);
        $plan_stmt->execute();
        $plan_result = $plan_stmt->get_result();
        $payment_plan = $plan_result->fetch_assoc();
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load last payment
$last_payment = null;
if ($db_connection_ok) {
    try {
        $last_payment_stmt = $db->prepare("
            SELECT amount, method, reference, status, received_at, created_at
            FROM payments
            WHERE donor_phone = ? AND status = 'approved'
            ORDER BY received_at DESC, created_at DESC
            LIMIT 1
        ");
        $last_payment_stmt->bind_param('s', $donor['phone']);
        $last_payment_stmt->execute();
        $last_payment_result = $last_payment_stmt->get_result();
        $last_payment = $last_payment_result->fetch_assoc();
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load recent payments
$recent_payments = [];
if ($db_connection_ok) {
    try {
        $payments_stmt = $db->prepare("
            SELECT id, amount, method, reference, status, received_at, created_at
            FROM payments
            WHERE donor_phone = ?
            ORDER BY received_at DESC, created_at DESC
            LIMIT 5
        ");
        $payments_stmt->bind_param('s', $donor['phone']);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
        $recent_payments = $payments_result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        // Silent fail
    }
}

// Calculate progress percentage
$progress_percentage = $donor['total_pledged'] > 0 
    ? min(100, ($donor['total_paid'] / $donor['total_pledged']) * 100) 
    : 0;

// Get achievement badge info
$achievement_badge = $donor['achievement_badge'] ?? 'pending';
$badge_classes = [
    'pending' => 'badge-pending',
    'started' => 'badge-started',
    'on_track' => 'badge-on-track',
    'fast_finisher' => 'badge-fast-finisher',
    'completed' => 'badge-completed',
    'champion' => 'badge-champion'
];
$badge_labels = [
    'pending' => 'Pending',
    'started' => 'Started',
    'on_track' => 'On Track',
    'fast_finisher' => 'Fast Finisher',
    'completed' => 'Completed',
    'champion' => 'Champion ⭐'
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div class="welcome-header">
                        <div class="welcome-content">
                            <p class="welcome-greeting">Welcome back,</p>
                            <h1 class="welcome-name"><?php echo htmlspecialchars($donor['name']); ?></h1>
                        </div>
                        <div class="welcome-badge-wrapper">
                            <span class="achievement-badge <?php echo $badge_classes[$achievement_badge] ?? 'badge-pending'; ?>">
                                <i class="fas fa-trophy"></i>
                                <span class="badge-text"><?php echo $badge_labels[$achievement_badge] ?? 'Pending'; ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Progress Bar Toward Pledge Goal -->
                <?php if ($donor['total_pledged'] > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-bullseye text-primary"></i>Pledge Progress
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted">Amount Paid</span>
                            <span class="fw-bold">
                                £<?php echo number_format($donor['total_paid'], 2); ?> / £<?php echo number_format($donor['total_pledged'], 2); ?>
                            </span>
                        </div>
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" 
                                 role="progressbar" 
                                 style="width: <?php echo $progress_percentage; ?>%"
                                 aria-valuenow="<?php echo $progress_percentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <strong class="px-2"><?php echo round($progress_percentage, 1); ?>%</strong>
                            </div>
                        </div>
                        <?php if ($donor['balance'] > 0): ?>
                            <p class="text-muted mt-2 mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                £<?php echo number_format($donor['balance'], 2); ?> remaining to complete your pledge
                            </p>
                        <?php else: ?>
                            <p class="text-success mt-2 mb-0">
                                <i class="fas fa-check-circle me-1"></i>
                                Congratulations! You've completed your pledge!
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="quick-actions-grid">
                    <a href="<?php echo htmlspecialchars(url_for('donor/make-payment.php')); ?>" class="action-btn">
                        <i class="fas fa-credit-card"></i>
                        <span>Make Payment</span>
                    </a>
                    <a href="<?php echo htmlspecialchars(url_for('donor/payment-history.php')); ?>" class="action-btn">
                        <i class="fas fa-history"></i>
                        <span>Payment History</span>
                    </a>
                    <a href="<?php echo htmlspecialchars(url_for('donor/payment-plan.php')); ?>" class="action-btn">
                        <i class="fas fa-calendar-alt"></i>
                        <span>My Plan</span>
                    </a>
                    <a href="<?php echo htmlspecialchars(url_for('donor/update-pledge.php')); ?>" class="action-btn">
                        <i class="fas fa-hand-holding-heart"></i>
                        <span>Increase Pledge</span>
                    </a>
                    <a href="<?php echo htmlspecialchars(url_for('donor/contact.php')); ?>" class="action-btn">
                        <i class="fas fa-envelope"></i>
                        <span>Contact Us</span>
                    </a>
                    <a href="<?php echo htmlspecialchars(url_for('donor/profile.php')); ?>" class="action-btn">
                        <i class="fas fa-user-circle"></i>
                        <span>My Profile</span>
                    </a>
                </div>

                <!-- Quick Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <p class="stat-label">Total Pledged</p>
                        <div class="stat-value">£<?php echo number_format($donor['total_pledged'], 2); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <p class="stat-label">Total Paid</p>
                        <div class="stat-value">£<?php echo number_format($donor['total_paid'], 2); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <p class="stat-label">Remaining Balance</p>
                        <div class="stat-value">£<?php echo number_format($donor['balance'], 2); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <p class="stat-label">Payments Made</p>
                        <div class="stat-value">
                            <?php 
                            if ($payment_plan) {
                                echo $payment_plan['payments_made'] ?? 0;
                                echo ' / ';
                                echo $payment_plan['total_payments'] ?? 0;
                            } else {
                                echo '-';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Current Payment Plan Summary -->
                <?php if ($payment_plan): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-calendar-alt text-primary"></i>Current Payment Plan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4 mb-3">
                            <div class="col-md-3">
                                <div class="border-start border-4 border-primary ps-3">
                                    <small class="text-muted d-block">Plan Type</small>
                                    <h5 class="mb-0"><?php echo htmlspecialchars($payment_plan['template_name'] ?? 'Custom Plan'); ?></h5>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border-start border-4 border-success ps-3">
                                    <small class="text-muted d-block">Monthly Amount</small>
                                    <h5 class="mb-0 text-success">£<?php echo number_format($payment_plan['monthly_amount'] ?? 0, 2); ?></h5>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border-start border-4 border-info ps-3">
                                    <small class="text-muted d-block">Next Payment Due</small>
                                    <h5 class="mb-0 text-info">
                                        <?php 
                                        if ($payment_plan['next_payment_due']) {
                                            echo date('d M Y', strtotime($payment_plan['next_payment_due']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </h5>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border-start border-4 border-warning ps-3">
                                    <small class="text-muted d-block">Status</small>
                                    <h5 class="mb-0">
                                        <?php 
                                        $payments_made = $payment_plan['payments_made'] ?? 0;
                                        $total_payments = $payment_plan['total_payments'] ?? 1;
                                        echo $payments_made . ' / ' . $total_payments;
                                        ?>
                                    </h5>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex">
                            <a href="<?php echo htmlspecialchars(url_for('donor/payment-plan.php')); ?>" 
                               class="btn btn-outline-primary btn-lg flex-fill">
                                <i class="fas fa-calendar-check me-2"></i>View Full Schedule
                            </a>
                            <a href="<?php echo htmlspecialchars(url_for('donor/make-payment.php')); ?>" 
                               class="btn btn-success btn-lg flex-fill">
                                <i class="fas fa-credit-card me-2"></i>Make a Payment
                            </a>
                        </div>
                    </div>
                </div>
                <?php elseif ($donor['balance'] > 0): ?>
                <!-- No Active Plan but Has Balance -->
                <div class="card">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-calendar-times fa-3x text-warning mb-3"></i>
                        <h5>No Active Payment Plan</h5>
                        <p class="text-muted mb-3">You have a remaining balance of <strong>£<?php echo number_format($donor['balance'], 2); ?></strong></p>
                        <a href="<?php echo htmlspecialchars(url_for('donor/make-payment.php')); ?>" class="btn btn-success btn-lg">
                            <i class="fas fa-credit-card me-2"></i>Make a Payment
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Last Payment Made -->
                <?php if ($last_payment): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-check-circle text-success"></i>Last Payment Made
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <p class="mb-0 text-muted">
                                    <?php 
                                    $date = $last_payment['received_at'] ?? $last_payment['created_at'];
                                    echo $date ? date('d M Y', strtotime($date)) : '-';
                                    ?>
                                </p>
                            </div>
                            <div class="text-end">
                                <h4 class="mb-0 text-success">£<?php echo number_format($last_payment['amount'], 2); ?></h4>
                                <span class="badge bg-secondary">
                                    <?php echo ucfirst(str_replace('_', ' ', $last_payment['method'] ?? 'N/A')); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Payments -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="fas fa-history text-primary"></i>Recent Payments
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_payments)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>No payments recorded yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $date = $payment['received_at'] ?? $payment['created_at'];
                                                echo $date ? date('d M Y', strtotime($date)) : '-';
                                                ?>
                                            </td>
                                            <td><strong>£<?php echo number_format($payment['amount'], 2); ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst(str_replace('_', ' ', $payment['method'] ?? 'N/A')); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['reference'] ?? '-'); ?></td>
                                            <td>
                                                <?php 
                                                $status = $payment['status'] ?? 'pending';
                                                $badge_class = $status === 'approved' ? 'bg-success' : 'bg-warning';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <a href="<?php echo htmlspecialchars(url_for('donor/payment-history.php')); ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-list me-2"></i>View All Payments
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Overall Donation Status Button -->
                        <div class="mt-3 pt-3 border-top">
                            <a href="https://donate.abuneteklehaymanot.org/public/projector/" 
                               target="_blank" 
                               rel="noopener noreferrer"
                               class="btn btn-lg btn-primary w-100">
                                <i class="fas fa-chart-line me-2"></i>View Current Status of Overall Donation
                                <i class="fas fa-external-link-alt ms-2 small"></i>
                            </a>
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

