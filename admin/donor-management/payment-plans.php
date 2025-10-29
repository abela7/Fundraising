<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../includes/resilient_db_loader.php';

require_login();
require_admin();

$page_title = 'Payment Plans';
$current_user = current_user();
$db = db();

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    try {
        $db->begin_transaction();
        
        if ($action === 'create_plan') {
            // Create new payment plan
            $donor_id = (int)($_POST['donor_id'] ?? 0);
            $pledge_id = isset($_POST['pledge_id']) ? (int)$_POST['pledge_id'] : null;
            $monthly_amount = (float)($_POST['monthly_amount'] ?? 0);
            $duration_months = (int)($_POST['duration_months'] ?? 0);
            $start_date = $_POST['start_date'] ?? date('Y-m-d');
            $payment_day = (int)($_POST['payment_day'] ?? 1);
            $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
            
            // Validation
            if ($donor_id <= 0) {
                throw new Exception('Please select a valid donor');
            }
            if ($monthly_amount <= 0) {
                throw new Exception('Monthly amount must be greater than 0');
            }
            if ($duration_months <= 0) {
                throw new Exception('Duration must be at least 1 month');
            }
            if ($payment_day < 1 || $payment_day > 28) {
                throw new Exception('Payment day must be between 1 and 28');
            }
            
            // Check if donor already has an active plan
            $check = $db->prepare("SELECT id FROM donor_payment_plans WHERE donor_id = ? AND status = 'active' LIMIT 1");
            $check->bind_param('i', $donor_id);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) {
                throw new Exception('This donor already has an active payment plan. Please complete or cancel it first.');
            }
            
            // Get donor info
            $donor_stmt = $db->prepare("SELECT id, name, phone, total_pledged, total_paid, balance FROM donors WHERE id = ?");
            $donor_stmt->bind_param('i', $donor_id);
            $donor_stmt->execute();
            $donor = $donor_stmt->get_result()->fetch_assoc();
            if (!$donor) {
                throw new Exception('Donor not found');
            }
            
            // Calculate plan totals
            $total_amount = $monthly_amount * $duration_months;
            
            // Calculate first payment due date
            $start_dt = new DateTime($start_date);
            $first_due = clone $start_dt;
            $first_due->setDate($start_dt->format('Y'), $start_dt->format('m'), $payment_day);
            if ($first_due < $start_dt) {
                $first_due->modify('+1 month');
            }
            
            // Insert payment plan
            $stmt = $db->prepare("
                INSERT INTO donor_payment_plans (
                    donor_id, pledge_id, monthly_amount, duration_months, 
                    total_amount, amount_paid, balance, status, 
                    start_date, next_payment_due, payment_day, 
                    preferred_method, created_by_user_id, created_at
                ) VALUES (?, ?, ?, ?, ?, 0, ?, 'active', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param('iidiidssisi', 
                $donor_id, $pledge_id, $monthly_amount, $duration_months,
                $total_amount, $total_amount, $start_date, $first_due->format('Y-m-d'), 
                $payment_day, $payment_method, $current_user['id']
            );
            $stmt->execute();
            $plan_id = $db->insert_id;
            
            // Update donor
            $upd = $db->prepare("UPDATE donors SET has_active_plan = 1, active_payment_plan_id = ?, plan_monthly_amount = ?, plan_duration_months = ?, plan_start_date = ?, plan_next_due_date = ? WHERE id = ?");
            $upd->bind_param('iddissi', $plan_id, $monthly_amount, $duration_months, $start_date, $first_due->format('Y-m-d'), $donor_id);
            $upd->execute();
            
            // Audit log
            $audit_data = json_encode([
                'donor' => $donor['name'],
                'monthly_amount' => $monthly_amount,
                'duration_months' => $duration_months,
                'total_amount' => $total_amount,
                'start_date' => $start_date
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment_plan', ?, 'create', ?, 'admin')");
            $audit->bind_param('iis', $current_user['id'], $plan_id, $audit_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan created successfully for {$donor['name']}!";
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'update_status') {
            // Update plan status (pause/resume/cancel/complete)
            $plan_id = (int)($_POST['plan_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';
            
            if (!in_array($new_status, ['active', 'paused', 'cancelled', 'completed'])) {
                throw new Exception('Invalid status');
            }
            
            // Get current plan
            $stmt = $db->prepare("SELECT * FROM donor_payment_plans WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $plan_id);
            $stmt->execute();
            $plan = $stmt->get_result()->fetch_assoc();
            if (!$plan) {
                throw new Exception('Payment plan not found');
            }
            
            // Update status
            $upd = $db->prepare("UPDATE donor_payment_plans SET status = ?, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('si', $new_status, $plan_id);
            $upd->execute();
            
            // If cancelling or completing, update donor
            if (in_array($new_status, ['cancelled', 'completed'])) {
                $donor_upd = $db->prepare("UPDATE donors SET has_active_plan = 0, active_payment_plan_id = NULL WHERE id = ?");
                $donor_upd->bind_param('i', $plan['donor_id']);
                $donor_upd->execute();
            } elseif ($new_status === 'active' && $plan['status'] !== 'active') {
                // Resuming plan
                $donor_upd = $db->prepare("UPDATE donors SET has_active_plan = 1, active_payment_plan_id = ? WHERE id = ?");
                $donor_upd->bind_param('ii', $plan_id, $plan['donor_id']);
                $donor_upd->execute();
            }
            
            // Audit log
            $audit_data = json_encode([
                'old_status' => $plan['status'],
                'new_status' => $new_status
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment_plan', ?, 'status_change', ?, ?, 'admin')");
            $before = json_encode(['status' => $plan['status']], JSON_UNESCAPED_SLASHES);
            $audit->bind_param('iiss', $current_user['id'], $plan_id, $before, $audit_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan status updated to: " . ucfirst($new_status);
            header('Location: payment-plans.php');
            exit;
            
        } elseif ($action === 'edit_plan') {
            // Edit existing plan
            $plan_id = (int)($_POST['plan_id'] ?? 0);
            $monthly_amount = (float)($_POST['monthly_amount'] ?? 0);
            $duration_months = (int)($_POST['duration_months'] ?? 0);
            $payment_day = (int)($_POST['payment_day'] ?? 1);
            $next_payment_due = $_POST['next_payment_due'] ?? null;
            
            // Get current plan
            $stmt = $db->prepare("SELECT * FROM donor_payment_plans WHERE id = ? FOR UPDATE");
            $stmt->bind_param('i', $plan_id);
            $stmt->execute();
            $plan = $stmt->get_result()->fetch_assoc();
            if (!$plan) {
                throw new Exception('Payment plan not found');
            }
            
            // Cannot edit completed or cancelled plans
            if (in_array($plan['status'], ['completed', 'cancelled'])) {
                throw new Exception('Cannot edit completed or cancelled plans');
            }
            
            // Recalculate totals
            $total_amount = $monthly_amount * $duration_months;
            $balance = $total_amount - $plan['amount_paid'];
            
            // Update plan
            $upd = $db->prepare("
                UPDATE donor_payment_plans 
                SET monthly_amount = ?, duration_months = ?, total_amount = ?, 
                    balance = ?, payment_day = ?, next_payment_due = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $upd->bind_param('dididsi', $monthly_amount, $duration_months, $total_amount, 
                $balance, $payment_day, $next_payment_due, $plan_id);
            $upd->execute();
            
            // Update donor
            $donor_upd = $db->prepare("UPDATE donors SET plan_monthly_amount = ?, plan_duration_months = ?, plan_next_due_date = ? WHERE id = ?");
            $donor_upd->bind_param('ddsi', $monthly_amount, $duration_months, $next_payment_due, $plan['donor_id']);
            $donor_upd->execute();
            
            // Audit log
            $before_data = json_encode([
                'monthly_amount' => $plan['monthly_amount'],
                'duration_months' => $plan['duration_months'],
                'payment_day' => $plan['payment_day']
            ], JSON_UNESCAPED_SLASHES);
            $after_data = json_encode([
                'monthly_amount' => $monthly_amount,
                'duration_months' => $duration_months,
                'payment_day' => $payment_day
            ], JSON_UNESCAPED_SLASHES);
            $audit = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment_plan', ?, 'update', ?, ?, 'admin')");
            $audit->bind_param('iiss', $current_user['id'], $plan_id, $before_data, $after_data);
            $audit->execute();
            
            $db->commit();
            $_SESSION['success_message'] = "Payment plan updated successfully!";
            header('Location: payment-plans.php');
            exit;
        }
        
    } catch (Exception $e) {
        $db->rollback();
        $error_message = $e->getMessage();
    }
}

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Load all payment plans with donor info
$plans = [];
if ($db_connection_ok) {
    try {
        $query = "
            SELECT 
                pp.*,
                d.name as donor_name,
                d.phone as donor_phone,
                d.total_pledged,
                d.total_paid,
                d.balance as donor_balance,
                pl.amount as pledge_amount,
                pl.status as pledge_status
            FROM donor_payment_plans pp
            INNER JOIN donors d ON pp.donor_id = d.id
            LEFT JOIN pledges pl ON pp.pledge_id = pl.id
            ORDER BY pp.created_at DESC
        ";
        $result = $db->query($query);
        if ($result) {
            $plans = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Load donors with open pledges for create modal
$donors_with_pledges = [];
if ($db_connection_ok) {
    try {
        $query = "
            SELECT DISTINCT
                d.id,
                d.name,
                d.phone,
                d.total_pledged,
                d.total_paid,
                d.balance,
                d.has_active_plan
            FROM donors d
            WHERE d.total_pledged > 0 AND d.balance > 0
            ORDER BY d.name ASC
        ";
        $result = $db->query($query);
        if ($result) {
            $donors_with_pledges = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/donor-management.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <?php include '../includes/db_error_banner.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard/">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="./">Donor Management</a></li>
                        <li class="breadcrumb-item active">Payment Plans</li>
                    </ol>
                </nav>

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">
                            <i class="fas fa-calendar-check me-2 text-primary"></i>Payment Plans
                        </h1>
                        <p class="text-muted mb-0">Manage recurring payment plans for pledge donors</p>
                    </div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPlanModal">
                        <i class="fas fa-plus me-2"></i>Create Payment Plan
                    </button>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">ACTIVE PLANS</div>
                                        <div class="stat-value">
                                            <?php 
                                            $active_count = count(array_filter($plans, fn($p) => $p['status'] === 'active'));
                                            echo $active_count;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-success">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">PAUSED</div>
                                        <div class="stat-value">
                                            <?php 
                                            $paused_count = count(array_filter($plans, fn($p) => $p['status'] === 'paused'));
                                            echo $paused_count;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-warning">
                                        <i class="fas fa-pause-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">COMPLETED</div>
                                        <div class="stat-value">
                                            <?php 
                                            $completed_count = count(array_filter($plans, fn($p) => $p['status'] === 'completed'));
                                            echo $completed_count;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-primary">
                                        <i class="fas fa-flag-checkered"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card stat-card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">OVERDUE</div>
                                        <div class="stat-value text-danger">
                                            <?php 
                                            $today = date('Y-m-d');
                                            $overdue_count = count(array_filter($plans, function($p) use ($today) {
                                                return $p['status'] === 'active' && $p['next_payment_due'] && $p['next_payment_due'] < $today;
                                            }));
                                            echo $overdue_count;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-danger">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Plans Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Payment Plans</h5>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="filterToggle">
                            <i class="fas fa-filter me-1"></i>Filters
                        </button>
                    </div>
                    
                    <!-- Filter Panel -->
                    <div class="card-body border-bottom bg-light" id="filterPanel" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Status</label>
                                <select class="form-select form-select-sm" id="filterStatus">
                                    <option value="">All Statuses</option>
                                    <option value="active">Active</option>
                                    <option value="paused">Paused</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Overdue Only</label>
                                <select class="form-select form-select-sm" id="filterOverdue">
                                    <option value="">All</option>
                                    <option value="yes">Yes - Overdue Only</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="button" class="btn btn-sm btn-secondary me-2" id="clearFilters">
                                    <i class="fas fa-times me-1"></i>Clear Filters
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="plansTable">
                                <thead>
                                    <tr>
                                        <th>Donor</th>
                                        <th>Phone</th>
                                        <th>Monthly Amount</th>
                                        <th>Duration</th>
                                        <th>Progress</th>
                                        <th>Next Due</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plans as $plan): 
                                        $progress_pct = $plan['total_amount'] > 0 ? ($plan['amount_paid'] / $plan['total_amount']) * 100 : 0;
                                        $payments_made = $plan['total_amount'] > 0 && $plan['monthly_amount'] > 0 ? floor($plan['amount_paid'] / $plan['monthly_amount']) : 0;
                                        $is_overdue = $plan['status'] === 'active' && $plan['next_payment_due'] && $plan['next_payment_due'] < date('Y-m-d');
                                    ?>
                                    <tr class="plan-row" data-plan='<?php echo htmlspecialchars(json_encode($plan), ENT_QUOTES); ?>'>
                                        <td>
                                            <strong><?php echo htmlspecialchars($plan['donor_name']); ?></strong>
                                        </td>
                                        <td class="text-muted"><?php echo htmlspecialchars($plan['donor_phone']); ?></td>
                                        <td><strong>£<?php echo number_format($plan['monthly_amount'], 2); ?></strong>/month</td>
                                        <td><?php echo $plan['duration_months']; ?> months</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                                    <div class="progress-bar <?php echo $progress_pct >= 100 ? 'bg-success' : 'bg-primary'; ?>" 
                                                         style="width: <?php echo min($progress_pct, 100); ?>%">
                                                        <?php echo number_format($progress_pct, 0); ?>%
                                                    </div>
                                                </div>
                                                <small class="text-muted"><?php echo $payments_made; ?> of <?php echo $plan['duration_months']; ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($plan['next_payment_due']): ?>
                                                <span class="<?php echo $is_overdue ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo date('d M Y', strtotime($plan['next_payment_due'])); ?>
                                                    <?php if ($is_overdue): ?>
                                                        <i class="fas fa-exclamation-circle ms-1"></i>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $badge_colors = [
                                                'active' => 'success',
                                                'paused' => 'warning',
                                                'completed' => 'primary',
                                                'cancelled' => 'secondary'
                                            ];
                                            $badge_color = $badge_colors[$plan['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_color; ?>">
                                                <?php echo ucfirst($plan['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary view-plan" 
                                                        data-plan-id="<?php echo $plan['id']; ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($plan['status'] === 'active'): ?>
                                                <button type="button" class="btn btn-outline-warning change-status" 
                                                        data-plan-id="<?php echo $plan['id']; ?>" data-status="paused" 
                                                        title="Pause Plan">
                                                    <i class="fas fa-pause"></i>
                                                </button>
                                                <?php elseif ($plan['status'] === 'paused'): ?>
                                                <button type="button" class="btn btn-outline-success change-status" 
                                                        data-plan-id="<?php echo $plan['id']; ?>" data-status="active" 
                                                        title="Resume Plan">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Create Payment Plan Modal -->
<div class="modal fade" id="createPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="createPlanForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_plan">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create Payment Plan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold">Select Donor <span class="text-danger">*</span></label>
                            <select class="form-select" name="donor_id" id="donor_select" required>
                                <option value="">Choose a donor...</option>
                                <?php foreach ($donors_with_pledges as $donor): ?>
                                <option value="<?php echo $donor['id']; ?>" 
                                        data-balance="<?php echo $donor['balance']; ?>"
                                        data-has-plan="<?php echo $donor['has_active_plan']; ?>">
                                    <?php echo htmlspecialchars($donor['name']); ?> - 
                                    Balance: £<?php echo number_format($donor['balance'], 2); ?>
                                    <?php if ($donor['has_active_plan']): ?>
                                        <span class="text-danger">(Already has active plan)</span>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Monthly Amount (£) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="monthly_amount" id="monthly_amount" 
                                   min="1" step="0.01" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Duration (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="duration_months" id="duration_months" 
                                   min="1" max="60" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Day (1-28) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="payment_day" min="1" max="28" value="1" required>
                            <small class="text-muted">Day of month for recurring payments</small>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">Preferred Payment Method</label>
                            <select class="form-select" name="payment_method">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="alert alert-info" id="planSummary" style="display: none;">
                                <strong>Plan Summary:</strong>
                                <div id="summaryContent"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Create Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Plan Detail Modal -->
<div class="modal fade" id="planDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Payment Plan Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="planDetailContent">
                <!-- Content loaded dynamically -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/admin.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTables
    const table = $('#plansTable').DataTable({
        order: [[5, 'asc']], // Sort by next due date
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        language: {
            search: "Search plans:",
            lengthMenu: "Show _MENU_ plans per page"
        }
    });
    
    // Toggle filter panel
    $('#filterToggle').click(function() {
        $('#filterPanel').slideToggle(300);
    });
    
    // Status filter
    $('#filterStatus').on('change', function() {
        const status = $(this).val().toLowerCase();
        table.column(6).search(status).draw();
    });
    
    // Overdue filter
    $('#filterOverdue').on('change', function() {
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            const filterOverdue = $('#filterOverdue').val();
            if (!filterOverdue) return true;
            
            const row = table.row(dataIndex).node();
            const planData = $(row).attr('data-plan');
            if (!planData) return true;
            
            const plan = JSON.parse(planData);
            const today = new Date().toISOString().split('T')[0];
            const isOverdue = plan.status === 'active' && plan.next_payment_due && plan.next_payment_due < today;
            
            return filterOverdue === 'yes' ? isOverdue : true;
        });
        table.draw();
        $.fn.dataTable.ext.search.pop();
    });
    
    // Clear filters
    $('#clearFilters').click(function() {
        $('#filterStatus').val('');
        $('#filterOverdue').val('');
        table.search('').columns().search('').draw();
    });
    
    // View plan details
    $(document).on('click', '.view-plan, .plan-row', function(e) {
        if ($(e.target).closest('button').hasClass('change-status')) return;
        
        const $row = $(this).closest('tr');
        const planData = $row.attr('data-plan');
        if (!planData) return;
        
        const plan = JSON.parse(planData);
        
        // Build detail view
        const progressPct = plan.total_amount > 0 ? (plan.amount_paid / plan.total_amount * 100) : 0;
        const paymentsMade = plan.total_amount > 0 && plan.monthly_amount > 0 ? Math.floor(plan.amount_paid / plan.monthly_amount) : 0;
        
        const content = `
            <div class="row g-4">
                <div class="col-md-6">
                    <h6 class="text-muted">Donor Information</h6>
                    <table class="table table-sm">
                        <tr><th>Name:</th><td>${plan.donor_name}</td></tr>
                        <tr><th>Phone:</th><td>${plan.donor_phone}</td></tr>
                        <tr><th>Total Pledged:</th><td>£${parseFloat(plan.total_pledged).toFixed(2)}</td></tr>
                        <tr><th>Total Paid:</th><td>£${parseFloat(plan.total_paid).toFixed(2)}</td></tr>
                        <tr><th>Balance:</th><td>£${parseFloat(plan.donor_balance).toFixed(2)}</td></tr>
                    </table>
                </div>
                
                <div class="col-md-6">
                    <h6 class="text-muted">Plan Details</h6>
                    <table class="table table-sm">
                        <tr><th>Monthly Amount:</th><td>£${parseFloat(plan.monthly_amount).toFixed(2)}</td></tr>
                        <tr><th>Duration:</th><td>${plan.duration_months} months</td></tr>
                        <tr><th>Total Amount:</th><td>£${parseFloat(plan.total_amount).toFixed(2)}</td></tr>
                        <tr><th>Amount Paid:</th><td>£${parseFloat(plan.amount_paid).toFixed(2)}</td></tr>
                        <tr><th>Balance:</th><td>£${parseFloat(plan.balance).toFixed(2)}</td></tr>
                        <tr><th>Status:</th><td><span class="badge bg-success">${plan.status}</span></td></tr>
                    </table>
                </div>
                
                <div class="col-12">
                    <h6 class="text-muted">Payment Progress</h6>
                    <div class="progress" style="height: 30px;">
                        <div class="progress-bar ${progressPct >= 100 ? 'bg-success' : 'bg-primary'}" 
                             style="width: ${Math.min(progressPct, 100)}%">
                            ${progressPct.toFixed(1)}% Complete
                        </div>
                    </div>
                    <p class="mt-2 text-muted">${paymentsMade} of ${plan.duration_months} payments made</p>
                </div>
                
                <div class="col-md-6">
                    <h6 class="text-muted">Timeline</h6>
                    <table class="table table-sm">
                        <tr><th>Start Date:</th><td>${plan.start_date ? new Date(plan.start_date).toLocaleDateString('en-GB') : '-'}</td></tr>
                        <tr><th>Next Due:</th><td>${plan.next_payment_due ? new Date(plan.next_payment_due).toLocaleDateString('en-GB') : '-'}</td></tr>
                        <tr><th>Payment Day:</th><td>Day ${plan.payment_day} of each month</td></tr>
                        <tr><th>Preferred Method:</th><td>${plan.preferred_method}</td></tr>
                    </table>
                </div>
                
                <div class="col-md-6">
                    <h6 class="text-muted">Actions</h6>
                    <div class="d-grid gap-2">
                        ${plan.status === 'active' ? `
                            <button type="button" class="btn btn-warning change-status-modal" data-plan-id="${plan.id}" data-status="paused">
                                <i class="fas fa-pause me-2"></i>Pause Plan
                            </button>
                        ` : ''}
                        ${plan.status === 'paused' ? `
                            <button type="button" class="btn btn-success change-status-modal" data-plan-id="${plan.id}" data-status="active">
                                <i class="fas fa-play me-2"></i>Resume Plan
                            </button>
                        ` : ''}
                        ${plan.status === 'active' || plan.status === 'paused' ? `
                            <button type="button" class="btn btn-danger change-status-modal" data-plan-id="${plan.id}" data-status="cancelled">
                                <i class="fas fa-times me-2"></i>Cancel Plan
                            </button>
                            <button type="button" class="btn btn-primary change-status-modal" data-plan-id="${plan.id}" data-status="completed">
                                <i class="fas fa-check me-2"></i>Mark as Completed
                            </button>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
        
        $('#planDetailContent').html(content);
        $('#planDetailModal').modal('show');
    });
    
    // Change status
    $(document).on('click', '.change-status, .change-status-modal', function(e) {
        e.stopPropagation();
        const planId = $(this).data('plan-id');
        const newStatus = $(this).data('status');
        
        if (confirm(`Are you sure you want to change this plan status to "${newStatus}"?`)) {
            const form = $('<form>', {
                method: 'POST',
                action: ''
            });
            form.append('<?php echo csrf_input(); ?>');
            form.append($('<input>', { type: 'hidden', name: 'action', value: 'update_status' }));
            form.append($('<input>', { type: 'hidden', name: 'plan_id', value: planId }));
            form.append($('<input>', { type: 'hidden', name: 'new_status', value: newStatus }));
            $('body').append(form);
            form.submit();
        }
    });
    
    // Plan summary calculator
    $('#monthly_amount, #duration_months').on('input', function() {
        const monthly = parseFloat($('#monthly_amount').val()) || 0;
        const duration = parseInt($('#duration_months').val()) || 0;
        const donorBalance = parseFloat($('#donor_select option:selected').data('balance')) || 0;
        
        if (monthly > 0 && duration > 0) {
            const total = monthly * duration;
            const diff = total - donorBalance;
            
            let summaryHtml = `
                <div>Total Plan Amount: <strong>£${total.toFixed(2)}</strong></div>
                <div>Donor Balance: <strong>£${donorBalance.toFixed(2)}</strong></div>
            `;
            
            if (Math.abs(diff) > 0.01) {
                if (diff > 0) {
                    summaryHtml += `<div class="text-warning mt-2">⚠️ Plan exceeds balance by £${Math.abs(diff).toFixed(2)}</div>`;
                } else {
                    summaryHtml += `<div class="text-info mt-2">ℹ️ Plan is £${Math.abs(diff).toFixed(2)} less than balance</div>`;
                }
            } else {
                summaryHtml += `<div class="text-success mt-2">✓ Plan matches donor balance perfectly!</div>`;
            }
            
            $('#summaryContent').html(summaryHtml);
            $('#planSummary').slideDown(300);
        } else {
            $('#planSummary').slideUp(300);
        }
    });
    
    // Auto-suggest plan when donor is selected
    $('#donor_select').change(function() {
        const balance = parseFloat($(this).find(':selected').data('balance')) || 0;
        const hasActivePlan = $(this).find(':selected').data('has-plan');
        
        if (hasActivePlan == 1) {
            alert('This donor already has an active payment plan!');
            $(this).val('');
            return;
        }
        
        if (balance > 0) {
            // Suggest 6-month plan
            const suggestedMonthly = balance / 6;
            $('#monthly_amount').val(suggestedMonthly.toFixed(2));
            $('#duration_months').val(6);
            $('#monthly_amount').trigger('input');
        }
    });
});
</script>
</body>
</html>

