<?php
// Disable strict types to be more forgiving
// declare(strict_types=1);

// Enable error reporting for debugging (will be caught by try-catch later)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

// Ensure user is logged in and admin
require_login();
require_admin();

$csrf_token = csrf_token();

$page_title = "View Payment Plan";

try {
    $db = db();
    // Convert mysqli errors to exceptions
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($plan_id <= 0) {
        throw new Exception("Invalid Payment Plan ID");
    }

    // 1. Fetch Payment Plan
    $stmt = $db->prepare("SELECT * FROM donor_payment_plans WHERE id = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) {
        throw new Exception("Payment Plan #$plan_id not found");
    }

    // 2. Fetch Donor
    $donor = [];
    if (!empty($plan['donor_id'])) {
        $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
        $stmt->bind_param('i', $plan['donor_id']);
        $stmt->execute();
        $donor = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
    }

    // 3. Fetch Pledge
    $pledge = [];
    if (!empty($plan['pledge_id'])) {
        // Check if pledges table exists just in case
        $check = $db->query("SHOW TABLES LIKE 'pledges'");
        if ($check->num_rows > 0) {
            $stmt = $db->prepare("SELECT * FROM pledges WHERE id = ?");
            $stmt->bind_param('i', $plan['pledge_id']);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }

    // 4. Fetch Template (Safe check)
    $template = [];
    if (!empty($plan['template_id'])) {
        $check = $db->query("SHOW TABLES LIKE 'payment_plan_templates'");
        if ($check->num_rows > 0) {
            $stmt = $db->prepare("SELECT * FROM payment_plan_templates WHERE id = ?");
            $stmt->bind_param('i', $plan['template_id']);
            $stmt->execute();
            $template = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }

    // 5. Fetch Church & Representative (from Donor)
    $church = [];
    $representative = [];
    if (!empty($donor['church_id'])) {
        $check = $db->query("SHOW TABLES LIKE 'churches'");
        if ($check->num_rows > 0) {
            $stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
            $stmt->bind_param('i', $donor['church_id']);
            $stmt->execute();
            $church = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }
    
    // Check for representative_id in donor (based on user's schema report)
    $rep_id = $donor['representative_id'] ?? null;
    if ($rep_id) {
        $check = $db->query("SHOW TABLES LIKE 'church_representatives'");
        if ($check->num_rows > 0) {
            $stmt = $db->prepare("SELECT * FROM church_representatives WHERE id = ?");
            $stmt->bind_param('i', $rep_id);
            $stmt->execute();
            $representative = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }

    // 6. Fetch Payments
    $payments = [];
    if (!empty($plan['donor_id']) && !empty($plan['pledge_id'])) {
        // Check if payments table exists
        $check = $db->query("SHOW TABLES LIKE 'payments'");
        if ($check->num_rows > 0) {
            // Simple query first - use created_at instead of payment_date
            $query = "SELECT * FROM payments WHERE donor_id = ? AND pledge_id = ? ORDER BY created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $plan['donor_id'], $plan['pledge_id']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                // Fetch user name manually to avoid join issues
                $received_by = 'N/A';
                if (!empty($row['received_by_user_id'])) {
                    $u_res = $db->query("SELECT name FROM users WHERE id = " . (int)$row['received_by_user_id']);
                    if ($u_res && $u_row = $u_res->fetch_assoc()) {
                        $received_by = $u_row['name'];
                    }
                }
                $row['received_by_name'] = $received_by;
                $payments[] = $row;
            }
            $stmt->close();
        }
    }

    // Calculations
    $total_amount = (float)($plan['total_amount'] ?? 0);
    $amount_paid = (float)($plan['amount_paid'] ?? 0);
    $monthly_amount = (float)($plan['monthly_amount'] ?? 0);
    $progress = $total_amount > 0 ? ($amount_paid / $total_amount) * 100 : 0;
    $remaining = $total_amount - $amount_paid;

} catch (Throwable $e) {
    // Catch ANY error and display it safely
    die('<div class="alert alert-danger m-5">
            <h3><i class="fas fa-exclamation-triangle"></i> System Error</h3>
            <p>Something went wrong loading this page.</p>
            <pre class="bg-light p-3 border">' . htmlspecialchars($e->getMessage()) . '</pre>
            <a href="donors.php" class="btn btn-secondary">Go Back</a>
         </div>');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Plan #<?php echo $plan_id; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        body { background: #f8f9fa; }
        .page-header { background: white; border-bottom: 3px solid #0a6286; padding: 1.5rem 0; margin-bottom: 2rem; }
        .donor-name { font-size: 1.75rem; font-weight: 700; color: #0a6286; margin-bottom: 0.25rem; }
        .donor-info { color: #6c757d; font-size: 0.95rem; }
        .stat-card { background: white; border-radius: 12px; padding: 1.5rem; text-align: center; 
                     box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #dee2e6; 
                     transition: transform 0.2s; height: 100%; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .stat-card.primary { border-left-color: #0a6286; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-value { font-size: 2rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { color: #6c757d; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .content-card { background: white; border-radius: 12px; padding: 1.5rem; 
                       box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .content-card h5 { color: #0a6286; font-weight: 700; margin-bottom: 1rem; 
                          padding-bottom: 0.75rem; border-bottom: 2px solid #e9ecef; }
        .info-row { display: flex; justify-content: space-between; align-items: center; 
                   padding: 0.75rem 0; border-bottom: 1px solid #f1f3f5; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #6c757d; font-weight: 500; font-size: 0.9rem; }
        .info-value { color: #212529; font-weight: 600; text-align: right; }
        .progress-lg { height: 20px; border-radius: 10px; }
        .table-compact { font-size: 0.9rem; }
        .table-compact th { background: #f8f9fa; font-weight: 600; text-transform: uppercase; 
                           font-size: 0.8rem; letter-spacing: 0.5px; }
        @media (max-width: 768px) {
            .stat-value { font-size: 1.5rem; }
            .donor-name { font-size: 1.5rem; }
            .info-row { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
            .info-value { text-align: left; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <!-- Page Header -->
                <div class="page-header mb-4">
                    <div class="container-fluid">
                        <div class="row align-items-center">
                            <div class="col-lg-6 mb-3 mb-lg-0">
                                <a href="donors.php" class="btn btn-sm btn-outline-secondary mb-2">
                                    <i class="fas fa-arrow-left me-1"></i>Back
                                </a>
                                <div class="donor-name"><?php echo htmlspecialchars($donor['name'] ?? 'Unknown Donor'); ?></div>
                                <div class="donor-info">
                                    <i class="fas fa-calendar-alt me-1"></i>Payment Plan #<?php echo $plan_id; ?>
                                    <?php if(!empty($donor['phone'])): ?>
                                        <span class="ms-3"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($donor['city'])): ?>
                                        <span class="ms-3"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($donor['city']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-lg-6 text-lg-end">
                                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                                    <?php
                                    $status_badges = [
                                        'active' => 'success',
                                        'paused' => 'warning',
                                        'completed' => 'info',
                                        'defaulted' => 'danger',
                                        'cancelled' => 'secondary'
                                    ];
                                    $status = $plan['status'] ?? 'active';
                                    $badge_color = $status_badges[$status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_color; ?> px-3 py-2 fs-6">
                                        <?php echo strtoupper($status); ?>
                                    </span>
                                    <button class="btn btn-warning btn-sm" id="btnEditPlan">
                                        <i class="fas fa-edit me-1"></i>Edit
                                    </button>
                                    <?php if($status === 'active'): ?>
                                    <button class="btn btn-info btn-sm text-white" id="btnPausePlan" data-action="pause">
                                        <i class="fas fa-pause me-1"></i>Pause
                                    </button>
                                    <?php elseif($status === 'paused'): ?>
                                    <button class="btn btn-success btn-sm" id="btnResumePlan" data-action="resume">
                                        <i class="fas fa-play me-1"></i>Resume
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-danger btn-sm" id="btnDeletePlan">
                                        <i class="fas fa-trash me-1"></i>Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card primary">
                            <div class="stat-value text-primary">£<?php echo number_format($total_amount, 2); ?></div>
                            <div class="stat-label">Total Amount</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card success">
                            <div class="stat-value text-success">£<?php echo number_format($amount_paid, 2); ?></div>
                            <div class="stat-label">Paid</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card warning">
                            <div class="stat-value text-warning">£<?php echo number_format($remaining, 2); ?></div>
                            <div class="stat-label">Remaining</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card info">
                            <div class="stat-value text-info"><?php echo (int)($plan['payments_made'] ?? 0); ?> / <?php echo (int)($plan['total_payments'] ?? 0); ?></div>
                            <div class="stat-label">Payments</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-8">
                        <div class="content-card">
                            <h5><i class="fas fa-info-circle me-2"></i>Plan Information</h5>
                            
                            <div class="mb-4">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="fw-bold">Progress</span>
                                    <span class="fw-bold text-primary"><?php echo number_format($progress, 1); ?>%</span>
                                </div>
                                <div class="progress progress-lg">
                                    <div class="progress-bar bg-gradient bg-success" role="progressbar" 
                                         style="width: <?php echo $progress; ?>%" 
                                         aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100">
                                        <?php echo number_format($progress, 1); ?>%
                                    </div>
                                </div>
                            </div>

                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-money-bill-wave me-2 text-primary"></i>Monthly Installment</span>
                                <span class="info-value fs-5 text-primary">£<?php echo number_format($monthly_amount, 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-calendar me-2"></i>Duration</span>
                                <span class="info-value"><?php echo (int)($plan['total_months'] ?? 0); ?> Months</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-play-circle me-2"></i>Start Date</span>
                                <span class="info-value"><?php echo !empty($plan['start_date']) ? date('d M Y', strtotime($plan['start_date'])) : '-'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-clock me-2"></i>Payment Day</span>
                                <span class="info-value">Day <?php echo (int)($plan['payment_day'] ?? 1); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-credit-card me-2"></i>Payment Method</span>
                                <span class="info-value text-uppercase"><?php echo htmlspecialchars($plan['payment_method'] ?? '-'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-bell me-2 text-danger"></i>Next Payment Due</span>
                                <span class="info-value text-danger fw-bold"><?php echo !empty($plan['next_payment_due']) ? date('d M Y', strtotime($plan['next_payment_due'])) : '-'; ?></span>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <div class="content-card">
                            <h5><i class="fas fa-history me-2"></i>Payment History</h5>
                            <?php if(empty($payments)): ?>
                                <div class="alert alert-info border-0">
                                    <i class="fas fa-info-circle me-2"></i>No payments recorded yet.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-compact mb-0">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Status</th>
                                                <th class="d-none d-md-table-cell">Received By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($payments as $pay): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($pay['created_at'] ?? $pay['received_at'] ?? 'now')); ?></td>
                                                <td class="fw-bold text-success">£<?php echo number_format((float)$pay['amount'], 2); ?></td>
                                                <td><span class="badge bg-light text-dark"><?php echo ucfirst($pay['payment_method']); ?></span></td>
                                                <td>
                                                    <?php 
                                                    $badge = match($pay['status']) {
                                                        'approved' => 'success',
                                                        'pending' => 'warning',
                                                        'rejected' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst($pay['status']); ?></span>
                                                </td>
                                                <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($pay['received_by_name']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-lg-4">
                        
                        <!-- Quick Links -->
                        <div class="content-card">
                            <h5><i class="fas fa-link me-2"></i>Quick Links</h5>
                            <div class="d-grid gap-2">
                                <a href="view-donor.php?id=<?php echo $plan['donor_id']; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-user me-2"></i>View Donor Profile
                                </a>
                                <a href="donors.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-users me-2"></i>All Donors
                                </a>
                            </div>
                        </div>

                        <!-- Church & Representative -->
                        <?php if(!empty($church) || !empty($representative)): ?>
                        <div class="content-card">
                            <h5><i class="fas fa-church me-2"></i>Church Information</h5>
                            <?php if(!empty($church)): ?>
                            <div class="mb-3">
                                <div class="small text-muted mb-1">Church</div>
                                <div class="fw-bold"><?php echo htmlspecialchars($church['name']); ?></div>
                                <?php if(!empty($church['city'])): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($church['city']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($representative)): ?>
                            <div class="pt-3 border-top">
                                <div class="small text-muted mb-1">Representative</div>
                                <div class="fw-bold"><?php echo htmlspecialchars($representative['name']); ?></div>
                                <?php if(!empty($representative['phone'])): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($representative['phone']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Pledge Info -->
                        <?php if(!empty($pledge)): ?>
                        <div class="content-card">
                            <h5><i class="fas fa-hand-holding-heart me-2"></i>Original Pledge</h5>
                            <div class="info-row">
                                <span class="info-label">Amount</span>
                                <span class="info-value">£<?php echo number_format((float)$pledge['amount'], 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Date</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($pledge['created_at'])); ?></span>
                            </div>
                            <?php if(!empty($pledge['notes'])): ?>
                            <div class="mt-3 bg-light p-3 rounded">
                                <div class="small fw-bold mb-1">Notes:</div>
                                <div class="small text-muted"><?php echo nl2br(htmlspecialchars($pledge['notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Template Info -->
                        <?php if(!empty($template)): ?>
                        <div class="content-card">
                            <h5><i class="fas fa-file-alt me-2"></i>Plan Template</h5>
                            <div class="fw-bold mb-1"><?php echo htmlspecialchars($template['name']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($template['description'] ?? ''); ?></div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal fade" id="editPlanModal" tabindex="-1" aria-labelledby="editPlanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editPlanModalLabel">
                    <i class="fas fa-edit me-2"></i>Edit Payment Plan
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPlanForm" method="POST" action="update-payment-plan.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="plan_id" value="<?php echo $plan_id; ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Monthly Installment Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">£</span>
                                <input type="number" step="0.01" class="form-control" name="monthly_amount" 
                                       id="edit_monthly_amount" value="<?php echo number_format($monthly_amount, 2); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Total Payments <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="total_payments" 
                                   id="edit_total_payments" value="<?php echo (int)($plan['total_payments'] ?? 0); ?>" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Day <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="payment_day" 
                                   id="edit_payment_day" value="<?php echo (int)($plan['payment_day'] ?? 1); ?>" min="1" max="28" required>
                            <small class="text-muted">Day of month (1-28)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" name="payment_method" id="edit_payment_method" required>
                                <option value="cash" <?php echo ($plan['payment_method'] ?? '') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo ($plan['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="card" <?php echo ($plan['payment_method'] ?? '') === 'card' ? 'selected' : ''; ?>>Card</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Frequency Unit <span class="text-danger">*</span></label>
                            <select class="form-select" name="plan_frequency_unit" id="edit_frequency_unit" required>
                                <option value="week" <?php echo ($plan['plan_frequency_unit'] ?? 'month') === 'week' ? 'selected' : ''; ?>>Week</option>
                                <option value="month" <?php echo ($plan['plan_frequency_unit'] ?? 'month') === 'month' ? 'selected' : ''; ?>>Month</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Frequency Number <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="plan_frequency_number" 
                                   id="edit_frequency_number" value="<?php echo (int)($plan['plan_frequency_number'] ?? 1); ?>" min="1" max="12" required>
                            <small class="text-muted">Every X weeks/months</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status <span class="text-danger">*</span></label>
                            <select class="form-select" name="status" id="edit_status" required>
                                <option value="active" <?php echo ($plan['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="paused" <?php echo ($plan['status'] ?? '') === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                <option value="completed" <?php echo ($plan['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="defaulted" <?php echo ($plan['status'] ?? '') === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                <option value="cancelled" <?php echo ($plan['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Next Payment Due Date</label>
                            <input type="date" class="form-control" name="next_payment_due" 
                                   id="edit_next_due" value="<?php echo !empty($plan['next_payment_due']) ? date('Y-m-d', strtotime($plan['next_payment_due'])) : ''; ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const planId = <?php echo $plan_id; ?>;
    const donorId = <?php echo $plan['donor_id']; ?>;
    
    // Edit Plan Button - Open Modal
    const btnEditPlan = document.getElementById('btnEditPlan');
    if (btnEditPlan) {
        btnEditPlan.addEventListener('click', function() {
            const modal = new bootstrap.Modal(document.getElementById('editPlanModal'));
            modal.show();
        });
    }
    
    // Pause Plan Button - AJAX Request
    const btnPausePlan = document.getElementById('btnPausePlan');
    if (btnPausePlan) {
        btnPausePlan.addEventListener('click', function() {
            if (confirm('Are you sure you want to pause this payment plan?')) {
                updatePlanStatus('paused');
            }
        });
    }
    
    // Resume Plan Button - AJAX Request
    const btnResumePlan = document.getElementById('btnResumePlan');
    if (btnResumePlan) {
        btnResumePlan.addEventListener('click', function() {
            if (confirm('Are you sure you want to resume this payment plan?')) {
                updatePlanStatus('active');
            }
        });
    }
    
    // Delete Plan Button - Redirect to confirmation page
    const btnDeletePlan = document.getElementById('btnDeletePlan');
    if (btnDeletePlan) {
        btnDeletePlan.addEventListener('click', function() {
            if (confirm('Are you sure you want to DELETE this payment plan?\n\nThis action cannot be undone!')) {
                window.location.href = 'delete-payment-plan.php?id=' + planId + '&donor_id=' + donorId;
            }
        });
    }
    
    // Update Plan Status Function (AJAX)
    function updatePlanStatus(newStatus) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'update-payment-plan-status.php';
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo $csrf_token; ?>';
        form.appendChild(csrfInput);
        
        const planIdInput = document.createElement('input');
        planIdInput.type = 'hidden';
        planIdInput.name = 'plan_id';
        planIdInput.value = planId;
        form.appendChild(planIdInput);
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'status';
        statusInput.value = newStatus;
        form.appendChild(statusInput);
        
        document.body.appendChild(form);
        form.submit();
    }
    
    // Show success/error messages if present
    <?php if(isset($_SESSION['success_message'])): ?>
        alert('<?php echo addslashes($_SESSION['success_message']); ?>');
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error_message'])): ?>
        alert('Error: <?php echo addslashes($_SESSION['error_message']); ?>');
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
});
</script>
</body>
</html>
