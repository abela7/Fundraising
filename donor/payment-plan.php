<?php
/**
 * Donor Portal - Payment Plan View
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
$page_title = 'Pledges & Plans';
$current_donor = $donor;

// Load all pledges
$pledges = [];
if ($db_connection_ok) {
    try {
        $pledges_stmt = $db->prepare("
            SELECT id, amount, type, status, notes, created_at, approved_at
            FROM pledges
            WHERE donor_phone = ?
            ORDER BY created_at DESC
        ");
        $pledges_stmt->bind_param('s', $donor['phone']);
        $pledges_stmt->execute();
        $pledges_result = $pledges_stmt->get_result();
        $pledges = $pledges_result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load payment plan details
$payment_plan = null;
$schedule = [];

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
        
        // Generate schedule (simplified - would need actual schedule table for full implementation)
        if ($payment_plan) {
            $start_date = new DateTime($payment_plan['start_date']);
            $total_payments = $payment_plan['total_payments'] ?? $payment_plan['total_months'] ?? 1;
            $monthly_amount = $payment_plan['monthly_amount'];
            
            for ($i = 0; $i < $total_payments; $i++) {
                $payment_date = clone $start_date;
                $payment_date->modify("+{$i} months");
                
                $schedule[] = [
                    'installment' => $i + 1,
                    'date' => $payment_date->format('Y-m-d'),
                    'amount' => $monthly_amount,
                    'status' => ($i < ($payment_plan['payments_made'] ?? 0)) ? 'paid' : 'pending'
                ];
            }
        }
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
                            <i class="fas fa-calendar-alt me-2"></i>Pledges & Plans
                        </h1>
                        <p class="text-muted mb-0">View your pledges and payment schedule</p>
                    </div>
                </div>

                <!-- Pledges Table -->
                <?php if (!empty($pledges)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="fas fa-handshake text-primary me-2"></i>Your Pledges
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pledges as $pledge): ?>
                                    <tr>
                                        <td>
                                            <?php echo date('d M Y', strtotime($pledge['created_at'])); ?>
                                        </td>
                                        <td><strong>£<?php echo number_format($pledge['amount'], 2); ?></strong></td>
                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo ucfirst($pledge['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $pledge['status'];
                                            $status_classes = [
                                                'pending' => 'bg-warning',
                                                'approved' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                                'cancelled' => 'bg-secondary'
                                            ];
                                            ?>
                                            <span class="badge <?php echo $status_classes[$status] ?? 'bg-secondary'; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Plan -->
                <?php if ($payment_plan): ?>
                    <!-- Plan Summary -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas fa-info-circle text-primary me-2"></i>Plan Summary
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
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
                                        <small class="text-muted d-block">Progress</small>
                                        <h5 class="mb-0">
                                            <?php 
                                            $payments_made = $payment_plan['payments_made'] ?? 0;
                                            $total_payments = $payment_plan['total_payments'] ?? 1;
                                            echo $payments_made . ' / ' . $total_payments;
                                            ?>
                                        </h5>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="border-start border-4 border-warning ps-3">
                                        <small class="text-muted d-block">Next Payment</small>
                                        <h5 class="mb-0 text-warning">
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
                            </div>
                        </div>
                    </div>

                    <!-- Payment Schedule -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas fa-list text-primary me-2"></i>Payment Schedule
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Payment Date</th>
                                            <th class="text-end">Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($schedule as $payment): ?>
                                        <tr>
                                            <td><strong><?php echo $payment['installment']; ?></strong></td>
                                            <td>
                                                <i class="fas fa-calendar text-muted me-2"></i>
                                                <?php echo date('d M Y', strtotime($payment['date'])); ?>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-success">£<?php echo number_format($payment['amount'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <?php 
                                                $badge_class = $payment['status'] === 'paid' ? 'bg-success' : 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($payment['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5>No Active Payment Plan</h5>
                            <p class="text-muted">You don't have an active payment plan at this time.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>

