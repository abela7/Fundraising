<?php
/**
 * Donor Portal - Payment Plan View
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
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
validate_donor_device(); // Check if device was revoked
$donor = current_donor();
$page_title = 'Pledges & Plans';

// Refresh donor data from database to ensure latest values
if ($donor && $db_connection_ok) {
    try {
        $refresh_stmt = $db->prepare("
            SELECT id, name, phone, total_pledged, total_paid, balance, 
                   has_active_plan, active_payment_plan_id,
                   payment_status, preferred_payment_method, preferred_language
            FROM donors 
            WHERE id = ? 
            LIMIT 1
        ");
        $refresh_stmt->bind_param('i', $donor['id']);
        $refresh_stmt->execute();
        $fresh_donor = $refresh_stmt->get_result()->fetch_assoc();
        $refresh_stmt->close();
        
        if ($fresh_donor) {
            $_SESSION['donor'] = $fresh_donor;
            $donor = $fresh_donor;
        }
    } catch (Exception $e) {
        // Silent fail - continue with session data
    }
}

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
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
    <style>
        /* Mobile-friendly cards */
        .pledge-card, .schedule-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            background: white;
            transition: all 0.2s;
        }
        .pledge-card:hover, .schedule-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e9ecef;
        }
        .info-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .info-label {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 500;
        }
        .info-value {
            font-weight: 600;
            text-align: right;
        }
        .pledge-amount {
            font-size: 1.25rem;
            color: #198754;
            font-weight: 700;
        }
        
        /* Desktop table - hide on mobile */
        @media (max-width: 767px) {
            .pledge-table, .schedule-table {
                display: none;
            }
            .pledge-cards, .schedule-cards {
                display: block;
            }
        }
        
        /* Mobile cards - hide on desktop */
        @media (min-width: 768px) {
            .pledge-cards, .schedule-cards {
                display: none;
            }
            .pledge-table, .schedule-table {
                display: table;
            }
        }
        
        /* Plan summary responsive */
        .plan-summary-item {
            margin-bottom: 1.5rem;
        }
        @media (min-width: 768px) {
            .plan-summary-item {
                margin-bottom: 0;
            }
        }
        
        /* Clickable table rows */
        .table tbody tr {
            cursor: pointer;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
                <div class="page-header">
                    <h1 class="page-title">Pledges & Plans</h1>
                </div>

                <!-- Pledges Table -->
                <?php if (!empty($pledges)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-handshake text-primary"></i>Your Pledges
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <!-- Desktop Table View -->
                        <div class="table-responsive pledge-table">
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
                                    <?php 
                                    $pledge_date = date('d M Y', strtotime($pledge['created_at']));
                                    $pledge_amount = number_format($pledge['amount'], 2);
                                    $pledge_type = ucfirst($pledge['type']);
                                    $pledge_status = $pledge['status'];
                                    $status_classes = [
                                        'pending' => 'bg-warning',
                                        'approved' => 'bg-success',
                                        'rejected' => 'bg-danger',
                                        'cancelled' => 'bg-secondary'
                                    ];
                                    $status_class = $status_classes[$pledge_status] ?? 'bg-secondary';
                                    $pledge_notes = htmlspecialchars($pledge['notes'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?php echo $pledge_date; ?></td>
                                        <td><strong>£<?php echo $pledge_amount; ?></strong></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $pledge_type; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo ucfirst($pledge_status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Mobile Card View -->
                        <div class="pledge-cards p-3">
                            <?php foreach ($pledges as $pledge): ?>
                            <?php 
                            $pledge_date = date('d M Y', strtotime($pledge['created_at']));
                            $pledge_amount = number_format($pledge['amount'], 2);
                            $pledge_type = ucfirst($pledge['type']);
                            $pledge_status = $pledge['status'];
                            $status_classes = [
                                'pending' => 'bg-warning',
                                'approved' => 'bg-success',
                                'rejected' => 'bg-danger',
                                'cancelled' => 'bg-secondary'
                            ];
                            $status_class = $status_classes[$pledge_status] ?? 'bg-secondary';
                            ?>
                            <div class="pledge-card">
                                <div class="info-row">
                                    <span class="info-label">Amount</span>
                                    <span class="info-value pledge-amount">£<?php echo $pledge_amount; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Date</span>
                                    <span class="info-value"><?php echo $pledge_date; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Type</span>
                                    <span class="info-value">
                                        <span class="badge bg-info"><?php echo $pledge_type; ?></span>
                                    </span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Status</span>
                                    <span class="info-value">
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst($pledge_status); ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment Plan -->
                <?php if ($payment_plan): ?>
                    <!-- Plan Summary -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle text-primary"></i>Plan Summary
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-md-3 col-12 plan-summary-item">
                                    <div class="border-start border-4 border-primary ps-3">
                                        <small class="text-muted d-block mb-1">Plan Type</small>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($payment_plan['template_name'] ?? 'Custom Plan'); ?></h5>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12 plan-summary-item">
                                    <div class="border-start border-4 border-success ps-3">
                                        <small class="text-muted d-block mb-1">Monthly Amount</small>
                                        <h5 class="mb-0 text-success">£<?php echo number_format($payment_plan['monthly_amount'] ?? 0, 2); ?></h5>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12 plan-summary-item">
                                    <div class="border-start border-4 border-info ps-3">
                                        <small class="text-muted d-block mb-1">Progress</small>
                                        <h5 class="mb-0">
                                            <?php 
                                            $payments_made = $payment_plan['payments_made'] ?? 0;
                                            $total_payments = $payment_plan['total_payments'] ?? 1;
                                            echo $payments_made . ' / ' . $total_payments;
                                            ?>
                                        </h5>
                                    </div>
                                </div>
                                <div class="col-md-3 col-12 plan-summary-item">
                                    <div class="border-start border-4 border-warning ps-3">
                                        <small class="text-muted d-block mb-1">Next Payment</small>
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
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-list text-primary"></i>Payment Schedule
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <!-- Desktop Table View -->
                            <div class="table-responsive schedule-table">
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
                                        <?php 
                                        $installment = $payment['installment'];
                                        $payment_date = date('d M Y', strtotime($payment['date']));
                                        $payment_amount = number_format($payment['amount'], 2);
                                        $badge_class = $payment['status'] === 'paid' ? 'bg-success' : 'bg-secondary';
                                        $payment_status = ucfirst($payment['status']);
                                        ?>
                                        <tr>
                                            <td><strong><?php echo $installment; ?></strong></td>
                                            <td>
                                                <i class="fas fa-calendar text-muted me-2"></i>
                                                <?php echo $payment_date; ?>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-success">£<?php echo $payment_amount; ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo $payment_status; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Mobile Card View -->
                            <div class="schedule-cards p-3">
                                <?php foreach ($schedule as $payment): ?>
                                <?php 
                                $installment = $payment['installment'];
                                $payment_date = date('d M Y', strtotime($payment['date']));
                                $payment_amount = number_format($payment['amount'], 2);
                                $badge_class = $payment['status'] === 'paid' ? 'bg-success' : 'bg-secondary';
                                $payment_status = ucfirst($payment['status']);
                                ?>
                                <div class="schedule-card">
                                    <div class="info-row">
                                        <span class="info-label">Installment</span>
                                        <span class="info-value">
                                            <strong>#<?php echo $installment; ?></strong>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Amount</span>
                                        <span class="info-value">
                                            <strong class="text-success">£<?php echo $payment_amount; ?></strong>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Date</span>
                                        <span class="info-value">
                                            <i class="fas fa-calendar text-muted me-1"></i>
                                            <?php echo $payment_date; ?>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Status</span>
                                        <span class="info-value">
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $payment_status; ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card mb-4">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5>No Active Payment Plan</h5>
                            <p class="text-muted mb-3">You don't have an active payment plan at this time.</p>
                            <a href="make-payment.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Make a Payment
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
</body>
</html>

