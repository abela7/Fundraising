<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
require_admin();

$db = db();
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($plan_id <= 0) {
    header('Location: donors.php');
    exit;
}

// Fetch payment plan with donor and pledge details
$query = $db->prepare("
    SELECT 
        dpp.*,
        d.id as donor_id,
        d.name as donor_name,
        d.phone as donor_phone,
        d.email as donor_email,
        d.balance as donor_balance,
        d.total_pledged,
        d.total_paid,
        p.id as pledge_id,
        p.amount as pledge_amount,
        p.sqm,
        p.notes as pledge_notes,
        p.created_at as pledge_date,
        ppt.name as template_name,
        ppt.description as template_description,
        cr.name as representative_name,
        cr.phone as representative_phone,
        ch.name as church_name,
        ch.city as church_city
    FROM donor_payment_plans dpp
    INNER JOIN donors d ON dpp.donor_id = d.id
    LEFT JOIN pledges p ON dpp.pledge_id = p.id
    LEFT JOIN payment_plan_templates ppt ON dpp.template_id = ppt.id
    LEFT JOIN church_representatives cr ON d.representative_id = cr.id
    LEFT JOIN churches ch ON d.church_id = ch.id
    WHERE dpp.id = ?
");

$query->bind_param('i', $plan_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Payment plan not found.";
    header('Location: donors.php');
    exit;
}

$plan = $result->fetch_object();
$query->close();

// Fetch all payments associated with this plan
$payments_query = $db->prepare("
    SELECT 
        pay.*,
        u.full_name as approved_by_name
    FROM payments pay
    LEFT JOIN users u ON pay.approved_by = u.id
    WHERE pay.donor_id = ? AND pay.pledge_id = ?
    ORDER BY pay.payment_date DESC
");
$payments_query->bind_param('ii', $plan->donor_id, $plan->pledge_id);
$payments_query->execute();
$payments = $payments_query->get_result()->fetch_all(MYSQLI_ASSOC);
$payments_query->close();

// Calculate progress
$progress_percentage = $plan->total_amount > 0 ? ($plan->amount_paid / $plan->total_amount) * 100 : 0;
$remaining_amount = $plan->total_amount - $plan->amount_paid;
$remaining_payments = $plan->total_payments - $plan->payments_made;

$page_title = "Payment Plan Details - " . htmlspecialchars($plan->donor_name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .plan-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .stat-box {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            background: #f8f9fa;
        }
        .stat-box h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
        }
        .stat-box p {
            margin: 0;
            color: #6c757d;
            font-size: 0.9rem;
        }
        .status-badge {
            font-size: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }
        .progress-bar-animated {
            animation: progress-animation 2s ease-in-out;
        }
        @keyframes progress-animation {
            from { width: 0; }
        }
        .payment-timeline {
            position: relative;
            padding-left: 2rem;
        }
        .payment-timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        .payment-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        .payment-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #28a745;
            border: 2px solid white;
            box-shadow: 0 0 0 2px #28a745;
        }
        .btn-action {
            margin: 0 0.25rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/admin_nav.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Plan Header -->
        <div class="plan-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="mb-2">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Payment Plan #<?php echo $plan->id; ?>
                    </h1>
                    <h4 class="mb-0"><?php echo htmlspecialchars($plan->donor_name); ?></h4>
                    <p class="mb-0 mt-2">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($plan->donor_phone); ?>
                        <?php if ($plan->donor_email): ?>
                            | <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($plan->donor_email); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php
                    $status_colors = [
                        'active' => 'success',
                        'completed' => 'primary',
                        'paused' => 'warning',
                        'defaulted' => 'danger',
                        'cancelled' => 'secondary'
                    ];
                    $status_color = $status_colors[$plan->status] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?php echo $status_color; ?> status-badge">
                        <?php echo strtoupper($plan->status); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="mb-4">
            <a href="view-donor.php?id=<?php echo $plan->donor_id; ?>" class="btn btn-outline-primary">
                <i class="fas fa-user me-2"></i>View Donor Profile
            </a>
            <button type="button" class="btn btn-warning btn-action" onclick="editPlan(<?php echo $plan->id; ?>)">
                <i class="fas fa-edit me-2"></i>Edit Plan
            </button>
            <?php if ($plan->status === 'active'): ?>
            <button type="button" class="btn btn-info btn-action" onclick="pausePlan(<?php echo $plan->id; ?>)">
                <i class="fas fa-pause me-2"></i>Pause Plan
            </button>
            <?php elseif ($plan->status === 'paused'): ?>
            <button type="button" class="btn btn-success btn-action" onclick="resumePlan(<?php echo $plan->id; ?>)">
                <i class="fas fa-play me-2"></i>Resume Plan
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-danger btn-action" onclick="deletePlan(<?php echo $plan->id; ?>)">
                <i class="fas fa-trash me-2"></i>Delete Plan
            </button>
        </div>

        <div class="row">
            <!-- Plan Statistics -->
            <div class="col-lg-8">
                <div class="info-card">
                    <h5 class="mb-4"><i class="fas fa-chart-line me-2"></i>Plan Overview</h5>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="stat-box">
                                <h3 class="text-primary">£<?php echo number_format($plan->total_amount, 2); ?></h3>
                                <p>Total Amount</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <h3 class="text-success">£<?php echo number_format($plan->amount_paid, 2); ?></h3>
                                <p>Paid</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <h3 class="text-warning">£<?php echo number_format($remaining_amount, 2); ?></h3>
                                <p>Remaining</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-box">
                                <h3 class="text-info"><?php echo $plan->payments_made; ?>/<?php echo $plan->total_payments; ?></h3>
                                <p>Payments</p>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Progress</strong>
                            <strong><?php echo number_format($progress_percentage, 1); ?>%</strong>
                        </div>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                                 role="progressbar" 
                                 style="width: <?php echo $progress_percentage; ?>%"
                                 aria-valuenow="<?php echo $progress_percentage; ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                                <?php echo number_format($progress_percentage, 1); ?>%
                            </div>
                        </div>
                    </div>

                    <!-- Plan Details Grid -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong><i class="fas fa-money-bill-wave text-primary me-2"></i>Monthly Amount:</strong>
                            <span class="float-end">£<?php echo number_format($plan->monthly_amount, 2); ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-calendar text-primary me-2"></i>Duration:</strong>
                            <span class="float-end"><?php echo $plan->total_months; ?> months</span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-hashtag text-primary me-2"></i>Total Payments:</strong>
                            <span class="float-end"><?php echo $plan->total_payments; ?> payments</span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-redo text-primary me-2"></i>Frequency:</strong>
                            <span class="float-end text-capitalize">
                                <?php 
                                $freq_num = $plan->plan_frequency_number ?? 1;
                                $freq_unit = $plan->plan_frequency_unit ?? 'month';
                                if ($freq_num == 1) {
                                    echo ucfirst($freq_unit) . 'ly';
                                } else {
                                    echo "Every $freq_num " . ucfirst($freq_unit) . 's';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-calendar-day text-primary me-2"></i>Payment Day:</strong>
                            <span class="float-end">Day <?php echo $plan->payment_day; ?> of month</span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-credit-card text-primary me-2"></i>Payment Method:</strong>
                            <span class="float-end text-capitalize"><?php echo str_replace('_', ' ', $plan->payment_method); ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-play-circle text-primary me-2"></i>Start Date:</strong>
                            <span class="float-end"><?php echo date('d M Y', strtotime($plan->start_date)); ?></span>
                        </div>
                        <div class="col-md-6">
                            <strong><i class="fas fa-flag-checkered text-primary me-2"></i>End Date:</strong>
                            <span class="float-end">
                                <?php 
                                $end_date = date('d M Y', strtotime($plan->start_date . " + {$plan->total_months} months"));
                                echo $end_date;
                                ?>
                            </span>
                        </div>
                        <?php if ($plan->next_payment_due): ?>
                        <div class="col-md-6">
                            <strong><i class="fas fa-clock text-warning me-2"></i>Next Payment Due:</strong>
                            <span class="float-end"><?php echo date('d M Y', strtotime($plan->next_payment_due)); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($plan->last_payment_date): ?>
                        <div class="col-md-6">
                            <strong><i class="fas fa-check-circle text-success me-2"></i>Last Payment:</strong>
                            <span class="float-end"><?php echo date('d M Y', strtotime($plan->last_payment_date)); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="info-card">
                    <h5 class="mb-4"><i class="fas fa-history me-2"></i>Payment History</h5>
                    
                    <?php if (empty($payments)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No payments recorded yet.
                        </div>
                    <?php else: ?>
                        <div class="payment-timeline">
                            <?php foreach ($payments as $payment): ?>
                            <div class="payment-item">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <i class="fas fa-pound-sign text-success me-2"></i>
                                                    £<?php echo number_format($payment['amount'], 2); ?>
                                                </h6>
                                                <p class="text-muted mb-1">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo date('d M Y', strtotime($payment['payment_date'])); ?>
                                                </p>
                                                <p class="text-muted mb-0">
                                                    <small>
                                                        Method: <span class="text-capitalize"><?php echo str_replace('_', ' ', $payment['method']); ?></span>
                                                        <?php if ($payment['approved_by_name']): ?>
                                                            | Approved by: <?php echo htmlspecialchars($payment['approved_by_name']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </p>
                                            </div>
                                            <span class="badge bg-success">
                                                <?php echo strtoupper($payment['status']); ?>
                                            </span>
                                        </div>
                                        <?php if ($payment['notes']): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                <?php echo htmlspecialchars($payment['notes']); ?>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Template Info -->
                <?php if ($plan->template_name): ?>
                <div class="info-card">
                    <h5 class="mb-3"><i class="fas fa-file-alt me-2"></i>Plan Template</h5>
                    <h6><?php echo htmlspecialchars($plan->template_name); ?></h6>
                    <?php if ($plan->template_description): ?>
                    <p class="text-muted small"><?php echo htmlspecialchars($plan->template_description); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Representative Info -->
                <?php if ($plan->representative_name): ?>
                <div class="info-card">
                    <h5 class="mb-3"><i class="fas fa-user-tie me-2"></i>Representative</h5>
                    <p class="mb-1"><strong><?php echo htmlspecialchars($plan->representative_name); ?></strong></p>
                    <?php if ($plan->representative_phone): ?>
                    <p class="text-muted mb-0">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($plan->representative_phone); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Church Info -->
                <?php if ($plan->church_name): ?>
                <div class="info-card">
                    <h5 class="mb-3"><i class="fas fa-church me-2"></i>Church</h5>
                    <p class="mb-1"><strong><?php echo htmlspecialchars($plan->church_name); ?></strong></p>
                    <?php if ($plan->church_city): ?>
                    <p class="text-muted mb-0">
                        <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($plan->church_city); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Pledge Details -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="fas fa-hand-holding-heart me-2"></i>Pledge Details</h5>
                    <p class="mb-2">
                        <strong>Amount:</strong> 
                        <span class="float-end">£<?php echo number_format($plan->pledge_amount, 2); ?></span>
                    </p>
                    <?php if ($plan->sqm): ?>
                    <p class="mb-2">
                        <strong>Square Meters:</strong> 
                        <span class="float-end"><?php echo $plan->sqm; ?> sqm</span>
                    </p>
                    <?php endif; ?>
                    <p class="mb-2">
                        <strong>Pledge Date:</strong> 
                        <span class="float-end"><?php echo date('d M Y', strtotime($plan->pledge_date)); ?></span>
                    </p>
                    <?php if ($plan->pledge_notes): ?>
                    <div class="mt-3 pt-3 border-top">
                        <strong class="d-block mb-2">Notes:</strong>
                        <p class="text-muted small mb-0"><?php echo nl2br(htmlspecialchars($plan->pledge_notes)); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Timestamps -->
                <div class="info-card">
                    <h5 class="mb-3"><i class="fas fa-clock me-2"></i>Timeline</h5>
                    <p class="text-muted small mb-2">
                        <strong>Created:</strong><br>
                        <?php echo date('d M Y, H:i', strtotime($plan->created_at)); ?>
                    </p>
                    <p class="text-muted small mb-0">
                        <strong>Last Updated:</strong><br>
                        <?php echo date('d M Y, H:i', strtotime($plan->updated_at)); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editPlanModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Payment Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editPlanForm" method="POST" action="update-payment-plan.php">
                    <input type="hidden" name="plan_id" value="<?php echo $plan->id; ?>">
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Changing the monthly amount or payment frequency will not automatically recalculate existing payment schedules. Use carefully.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="monthly_amount" class="form-label">Installment Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text">£</span>
                                    <input type="number" step="0.01" class="form-control" id="monthly_amount" 
                                           name="monthly_amount" value="<?php echo $plan->monthly_amount; ?>" required>
                                </div>
                                <small class="text-muted">Amount per payment installment</small>
                            </div>
                            <div class="col-md-6">
                                <label for="total_payments" class="form-label">Total Payments</label>
                                <input type="number" min="1" class="form-control" id="total_payments" 
                                       name="total_payments" value="<?php echo $plan->total_payments; ?>" required>
                                <small class="text-muted">Number of payments to complete the plan</small>
                            </div>
                            <div class="col-md-6">
                                <label for="plan_frequency_unit" class="form-label">Frequency Unit</label>
                                <select class="form-select" id="plan_frequency_unit" name="plan_frequency_unit" required>
                                    <option value="week" <?php echo ($plan->plan_frequency_unit ?? 'month') === 'week' ? 'selected' : ''; ?>>Week</option>
                                    <option value="month" <?php echo ($plan->plan_frequency_unit ?? 'month') === 'month' ? 'selected' : ''; ?>>Month</option>
                                    <option value="year" <?php echo ($plan->plan_frequency_unit ?? 'month') === 'year' ? 'selected' : ''; ?>>Year</option>
                                </select>
                                <small class="text-muted">How often payments are made</small>
                            </div>
                            <div class="col-md-6">
                                <label for="plan_frequency_number" class="form-label">Frequency Number</label>
                                <input type="number" min="1" max="12" class="form-control" id="plan_frequency_number" 
                                       name="plan_frequency_number" value="<?php echo $plan->plan_frequency_number ?? 1; ?>" required>
                                <small class="text-muted">E.g., "2" with "week" = every 2 weeks</small>
                            </div>
                            <div class="col-md-6">
                                <label for="payment_day" class="form-label">Payment Day (1-28)</label>
                                <input type="number" min="1" max="28" class="form-control" id="payment_day" 
                                       name="payment_day" value="<?php echo $plan->payment_day; ?>" required>
                                <small class="text-muted">Day of month when payment is due</small>
                            </div>
                            <div class="col-md-6">
                                <label for="payment_method" class="form-label">Payment Method</label>
                                <select class="form-select" id="payment_method" name="payment_method" required>
                                    <option value="cash" <?php echo $plan->payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                    <option value="bank_transfer" <?php echo $plan->payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="card" <?php echo $plan->payment_method === 'card' ? 'selected' : ''; ?>>Card</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?php echo $plan->status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="paused" <?php echo $plan->status === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                    <option value="completed" <?php echo $plan->status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="defaulted" <?php echo $plan->status === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                    <option value="cancelled" <?php echo $plan->status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="next_payment_due" class="form-label">Next Payment Due</label>
                                <input type="date" class="form-control" id="next_payment_due" 
                                       name="next_payment_due" value="<?php echo $plan->next_payment_due; ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editPlan(planId) {
            const modal = new bootstrap.Modal(document.getElementById('editPlanModal'));
            modal.show();
        }

        function pausePlan(planId) {
            if (confirm('Are you sure you want to pause this payment plan?')) {
                updatePlanStatus(planId, 'paused');
            }
        }

        function resumePlan(planId) {
            if (confirm('Are you sure you want to resume this payment plan?')) {
                updatePlanStatus(planId, 'active');
            }
        }

        function deletePlan(planId) {
            if (confirm('Are you sure you want to delete this payment plan? This action cannot be undone.')) {
                window.location.href = 'delete-payment-plan.php?id=' + planId;
            }
        }

        function updatePlanStatus(planId, status) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'update-payment-plan-status.php';
            
            const planIdInput = document.createElement('input');
            planIdInput.type = 'hidden';
            planIdInput.name = 'plan_id';
            planIdInput.value = planId;
            
            const statusInput = document.createElement('input');
            statusInput.type = 'hidden';
            statusInput.name = 'status';
            statusInput.value = status;
            
            form.appendChild(planIdInput);
            form.appendChild(statusInput);
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>

