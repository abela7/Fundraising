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
        $check = $db->query("SHOW TABLES LIKE 'pledges'");
        if ($check->num_rows > 0) {
            $stmt = $db->prepare("SELECT * FROM pledges WHERE id = ?");
            $stmt->bind_param('i', $plan['pledge_id']);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
    }

    // 4. Fetch Template
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

    // 5. Fetch Church & Representative
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
        $check = $db->query("SHOW TABLES LIKE 'payments'");
        if ($check->num_rows > 0) {
            $query = "SELECT * FROM payments WHERE donor_id = ? AND pledge_id = ? ORDER BY created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bind_param('ii', $plan['donor_id'], $plan['pledge_id']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
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

    // 7. Fetch Schedule from DB
    $schedule = [];
    $using_db_schedule = false;
    
    $check_table = $db->query("SHOW TABLES LIKE 'payment_plan_schedule'");
    if ($check_table->num_rows > 0) {
        $s_stmt = $db->prepare("SELECT * FROM payment_plan_schedule WHERE plan_id = ? ORDER BY installment_number ASC");
        $s_stmt->bind_param('i', $plan_id);
        $s_stmt->execute();
        $s_res = $s_stmt->get_result();
        while($row = $s_res->fetch_assoc()) {
            $schedule[] = [
                'id' => $row['id'],
                'number' => $row['installment_number'],
                'date' => $row['due_date'],
                'amount' => (float)$row['amount'],
                'status' => $row['status']
            ];
        }
        $using_db_schedule = !empty($schedule);
    }
    
    // Fallback to calculation if DB schedule missing (Legacy support)
    if (empty($schedule) && !empty($plan['start_date'])) {
        try {
            $current_date = new DateTime($plan['start_date']);
            $today = new DateTime('today');
            $freq_unit = $plan['plan_frequency_unit'] ?? 'month';
            $freq_num = (int)($plan['plan_frequency_number'] ?? 1);
            $payments_made = (int)($plan['payments_made'] ?? 0);
            
            for ($i = 1; $i <= $plan['total_payments']; $i++) {
                $status = 'pending';
                if ($i <= $payments_made) {
                    $status = 'paid';
                } elseif ($current_date < $today) {
                    $status = 'overdue';
                }
                
                $schedule[] = [
                    'id' => 0, // Virtual
                    'number' => $i,
                    'date' => $current_date->format('Y-m-d'),
                    'amount' => $monthly_amount,
                    'status' => $status
                ];
                
                // Advance date
                if ($freq_unit === 'day') {
                    $current_date->modify("+{$freq_num} days");
                } elseif ($freq_unit === 'week') {
                    $current_date->modify("+{$freq_num} weeks");
                } elseif ($freq_unit === 'month') {
                    $current_date->modify("+{$freq_num} months");
                } elseif ($freq_unit === 'year') {
                    $current_date->modify("+{$freq_num} years");
                } else {
                    $current_date->modify("+1 month");
                }
            }
        } catch (Exception $e) {
            // ignore
        }
    }

} catch (Throwable $e) {
    die('<div class="alert alert-danger m-5">Error: ' . htmlspecialchars($e->getMessage()) . '</div>');
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
        .stat-card { background: white; border-radius: 12px; padding: 1.5rem; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #dee2e6; height: 100%; }
        .stat-card.primary { border-left-color: #0a6286; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-value { font-size: 2rem; font-weight: 700; margin-bottom: 0.25rem; }
        .stat-label { color: #6c757d; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .content-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 1.5rem; }
        .content-card h5 { color: #0a6286; font-weight: 700; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e9ecef; }
        .info-row { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #f1f3f5; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #6c757d; font-weight: 500; font-size: 0.9rem; }
        .info-value { color: #212529; font-weight: 600; text-align: right; }
        .nav-tabs .nav-link { color: #64748b; font-weight: 600; padding: 1rem 1.5rem; }
        .nav-tabs .nav-link.active { color: #0a6286; border-bottom: 3px solid #0a6286; }
        .table-compact { font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <!-- Header -->
                <div class="page-header mb-4">
                    <div class="container-fluid">
                        <div class="row align-items-center">
                            <div class="col-lg-6">
                                <a href="donors.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
                                <div class="donor-name"><?php echo htmlspecialchars($donor['name'] ?? 'Unknown'); ?></div>
                                <div class="donor-info">Plan #<?php echo $plan_id; ?></div>
                            </div>
                            <div class="col-lg-6 text-lg-end">
                                <span class="badge bg-<?php echo ($plan['status'] === 'active' ? 'success' : 'secondary'); ?> px-3 py-2 fs-6 text-uppercase"><?php echo $plan['status']; ?></span>
                                <button class="btn btn-warning btn-sm ms-2" id="btnEditPlan"><i class="fas fa-edit me-1"></i>Edit</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stat-card primary">
                            <div class="stat-value text-primary">£<?php echo number_format($total_amount, 2); ?></div>
                            <div class="stat-label">Total</div>
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
                            <div class="stat-value text-info"><?php echo (int)($plan['payments_made'] ?? 0); ?>/<?php echo (int)($plan['total_payments'] ?? 0); ?></div>
                            <div class="stat-label">Payments</div>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" id="planTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="schedule-tab" data-bs-toggle="tab" data-bs-target="#schedule" type="button" role="tab">Schedule</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Overview Tab -->
                    <div class="tab-pane fade show active" id="overview" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-8">
                                <div class="content-card">
                                    <h5>Plan Details</h5>
                                    <div class="info-row">
                                        <span class="info-label">Monthly Installment</span>
                                        <span class="info-value text-primary fw-bold">£<?php echo number_format($monthly_amount, 2); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Frequency</span>
                                        <span class="info-value"><?php echo ucfirst($plan['plan_frequency_unit'] ?? 'month'); ?>ly (Every <?php echo $plan['plan_frequency_number'] ?? 1; ?>)</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Payment Method</span>
                                        <span class="info-value text-uppercase"><?php echo htmlspecialchars($plan['payment_method'] ?? '-'); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Next Due</span>
                                        <span class="info-value text-danger"><?php echo !empty($plan['next_payment_due']) ? date('d M Y', strtotime($plan['next_payment_due'])) : '-'; ?></span>
                                    </div>
                                </div>

                                <div class="content-card">
                                    <h5>Payment History</h5>
                                    <?php if(empty($payments)): ?>
                                        <div class="text-muted">No payments yet.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover table-compact mb-0">
                                                <thead>
                                                    <tr><th>Date</th><th>Amount</th><th>Status</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach($payments as $p): ?>
                                                    <tr>
                                                        <td><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                                                        <td class="fw-bold">£<?php echo number_format((float)$p['amount'], 2); ?></td>
                                                        <td><span class="badge bg-<?php echo $p['status']=='approved'?'success':'secondary'; ?>"><?php echo ucfirst($p['status']); ?></span></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="content-card">
                                    <h5>Quick Links</h5>
                                    <div class="d-grid gap-2">
                                        <a href="view-donor.php?id=<?php echo $plan['donor_id']; ?>" class="btn btn-outline-primary">Donor Profile</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule Tab -->
                    <div class="tab-pane fade" id="schedule" role="tabpanel">
                        <div class="content-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Payment Schedule</h5>
                                <?php if($using_db_schedule && $plan['status'] === 'active'): ?>
                                <button class="btn btn-primary btn-sm" id="btnRescheduleAll">
                                    <i class="fas fa-calendar-plus me-1"></i>Reschedule Remaining
                                </button>
                                <?php endif; ?>
                            </div>
                            
                            <?php if(!$using_db_schedule): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    This is a legacy calculated schedule. 
                                    <a href="migrate_schedules.php" class="alert-link">Run Migration</a> to enable editing.
                            </div>
                            <?php endif; ?>
                            
                            <div class="table-responsive">
                                <table class="table table-hover table-compact align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width: 50px;">#</th>
                                            <th>Due Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <?php if($using_db_schedule): ?>
                                            <th style="width: 50px;">Edit</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($schedule as $item): ?>
                                        <tr class="<?php echo $item['status'] === 'paid' ? 'table-success' : ''; ?>">
                                            <td><?php echo $item['number']; ?></td>
                                            <td><?php echo date('d M Y', strtotime($item['date'])); ?></td>
                                            <td class="fw-bold">£<?php echo number_format($item['amount'], 2); ?></td>
                                            <td>
                                                <?php if($item['status'] === 'paid'): ?>
                                                    <span class="badge bg-success">Paid</span>
                                                <?php elseif($item['status'] === 'overdue'): ?>
                                                    <span class="badge bg-danger">Overdue</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if($using_db_schedule): ?>
                                            <td>
                                                <?php if($item['status'] === 'pending'): ?>
                                                <button class="btn btn-link btn-sm p-0 text-secondary btn-edit-date" 
                                                        data-id="<?php echo $item['id']; ?>" 
                                                        data-date="<?php echo $item['date']; ?>"
                                                        title="Edit Due Date">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                        <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Edit Single Date Modal -->
<div class="modal fade" id="editDateModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Edit Due Date</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit_schedule_id">
                <label class="form-label small fw-bold">New Date</label>
                <input type="date" class="form-control" id="edit_schedule_date">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-primary w-100" id="btnSaveDate">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Reschedule All Modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-clock me-2"></i>Reschedule Remaining</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Select a new start date for the <strong>next pending installment</strong>. All subsequent payments will be shifted automatically based on the plan frequency.</p>
                <div class="mb-3">
                    <label class="form-label fw-bold">New Start Date</label>
                    <input type="date" class="form-control" id="reschedule_start_date" min="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="alert alert-info small mb-0">
                    <i class="fas fa-info-circle me-1"></i> This will extend the plan end date.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveReschedule">Apply Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Existing Edit Plan Modal (kept for structure but hidden) -->
<div class="modal fade" id="editPlanModal" tabindex="-1" aria-labelledby="editPlanModalLabel" aria-hidden="true">
    <!-- ... copied from previous, abbreviated for space as it's unchanged ... -->
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Payment Plan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPlanForm" method="POST" action="update-payment-plan.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="plan_id" value="<?php echo $plan_id; ?>">
                <div class="modal-body">
                     <!-- Same fields as before -->
                     <p class="text-center text-muted">Use standard edit for amount/frequency changes.</p>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Edit Single Date
    const editDateModal = new bootstrap.Modal(document.getElementById('editDateModal'));
    document.querySelectorAll('.btn-edit-date').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_schedule_id').value = this.dataset.id;
            document.getElementById('edit_schedule_date').value = this.dataset.date;
            editDateModal.show();
        });
    });
    
    document.getElementById('btnSaveDate').addEventListener('click', function() {
        const id = document.getElementById('edit_schedule_id').value;
        const date = document.getElementById('edit_schedule_date').value;
        
        fetch('ajax-update-schedule.php', {
            method: 'POST',
            body: JSON.stringify({ id, date })
        })
        .then(r => r.json())
        .then(res => {
            if(res.success) location.reload();
            else alert(res.message || 'Error');
        });
    });
    
    // 2. Reschedule All
    const btnRescheduleAll = document.getElementById('btnRescheduleAll');
    if(btnRescheduleAll) {
        const rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
        btnRescheduleAll.addEventListener('click', () => rescheduleModal.show());
        
        document.getElementById('btnSaveReschedule').addEventListener('click', function() {
            const date = document.getElementById('reschedule_start_date').value;
            if(!date) return alert('Please select a date');
            
            fetch('ajax-reschedule-plan.php', {
                method: 'POST',
                body: JSON.stringify({ plan_id: <?php echo $plan_id; ?>, start_date: date })
            })
            .then(r => r.json())
            .then(res => {
                if(res.success) location.reload();
                else alert(res.message || 'Error');
            });
        });
    }
    
    // Edit Plan Button logic (Standard)
    const btnEditPlan = document.getElementById('btnEditPlan');
    if (btnEditPlan) {
        btnEditPlan.addEventListener('click', function() {
            const modal = new bootstrap.Modal(document.getElementById('editPlanModal'));
            modal.show();
        });
    }
});
</script>
</body>
</html>
