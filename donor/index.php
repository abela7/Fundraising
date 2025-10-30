<?php
/**
 * Donor Portal - Token-based Authentication
 * Donors access this portal via secure token links sent via SMS/email
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
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

// Check token authentication
$donor = current_donor();
if (!$donor && isset($_GET['token'])) {
    // Token-based login
    $token = trim($_GET['token'] ?? '');
    
    if ($token && $db_connection_ok) {
        // Check token in donors table
        $stmt = $db->prepare("
            SELECT id, name, phone, total_pledged, total_paid, balance, 
                   has_active_plan, active_payment_plan_id, plan_monthly_amount,
                   plan_duration_months, plan_start_date, plan_next_due_date,
                   payment_status, preferred_payment_method, preferred_language
            FROM donors 
            WHERE portal_token = ? 
              AND token_expires_at > NOW()
              AND portal_token IS NOT NULL
            LIMIT 1
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $donor_data = $result->fetch_assoc();
        
        if ($donor_data) {
            // Set donor session
            $_SESSION['donor'] = $donor_data;
            
            // Update login tracking
            $update_stmt = $db->prepare("
                UPDATE donors 
                SET last_login_at = NOW(), 
                    login_count = login_count + 1 
                WHERE id = ?
            ");
            $update_stmt->bind_param('i', $donor_data['id']);
            $update_stmt->execute();
            
            // Redirect to remove token from URL
            header('Location: index.php');
            exit;
        }
    }
}

// Require donor login to access
require_donor_login();
$donor = current_donor();

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

// Load recent payments
$recent_payments = [];
if ($db_connection_ok) {
    try {
        $payments_stmt = $db->prepare("
            SELECT id, amount, method, reference, status, received_at, created_at
            FROM payments
            WHERE donor_phone = ?
            ORDER BY received_at DESC, created_at DESC
            LIMIT 10
        ");
        $payments_stmt->bind_param('s', $donor['phone']);
        $payments_stmt->execute();
        $payments_result = $payments_stmt->get_result();
        $recent_payments = $payments_result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        // Silent fail
    }
}
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
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-home me-2"></i>Dashboard
                        </h1>
                        <p class="text-muted mb-0">Welcome back, <?php echo htmlspecialchars($donor['name']); ?>!</p>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-pound-sign"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">£<?php echo number_format($donor['total_pledged'], 2); ?></h3>
                                <p class="stat-label">Total Pledged</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">£<?php echo number_format($donor['total_paid'], 2); ?></h3>
                                <p class="stat-label">Total Paid</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-<?php echo $donor['balance'] > 0 ? 'warning' : 'secondary'; ?>">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">£<?php echo number_format($donor['balance'], 2); ?></h3>
                                <p class="stat-label">Remaining Balance</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon bg-info">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">
                                    <?php 
                                    if ($payment_plan) {
                                        echo $payment_plan['payments_made'] ?? 0;
                                        echo ' / ';
                                        echo $payment_plan['total_payments'] ?? 0;
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </h3>
                                <p class="stat-label">Payments Made</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Payment Plan -->
                <?php if ($payment_plan): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar-alt text-primary me-2"></i>Active Payment Plan
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="border-start border-4 border-primary ps-3">
                                    <small class="text-muted d-block">Plan Type</small>
                                    <h5 class="mb-0"><?php echo htmlspecialchars($payment_plan['template_name'] ?? 'Custom Plan'); ?></h5>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border-start border-4 border-success ps-3">
                                    <small class="text-muted d-block">Monthly Amount</small>
                                    <h5 class="mb-0 text-success">£<?php echo number_format($payment_plan['monthly_amount'] ?? 0, 2); ?></h5>
                                </div>
                            </div>
                            <div class="col-md-6">
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
                            <div class="col-md-6">
                                <div class="border-start border-4 border-warning ps-3">
                                    <small class="text-muted d-block">Progress</small>
                                    <h5 class="mb-0">
                                        <?php 
                                        $payments_made = $payment_plan['payments_made'] ?? 0;
                                        $total_payments = $payment_plan['total_payments'] ?? 1;
                                        $progress = $total_payments > 0 ? ($payments_made / $total_payments) * 100 : 0;
                                        echo round($progress, 1); 
                                        ?>%
                                    </h5>
                                    <div class="progress mt-2" style="height: 8px;">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <a href="payment-plan.php" class="btn btn-primary">
                                <i class="fas fa-eye me-2"></i>View Full Payment Schedule
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Payments -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-history text-primary me-2"></i>Recent Payments
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
                            <div class="mt-3">
                                <a href="payment-history.php" class="btn btn-outline-primary">
                                    <i class="fas fa-list me-2"></i>View All Payments
                                </a>
                            </div>
                        <?php endif; ?>
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

