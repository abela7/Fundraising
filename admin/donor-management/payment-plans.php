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
            $upd->bind_param('iddssi', $plan_id, $monthly_amount, $duration_months, $start_date, $first_due->format('Y-m-d'), $donor_id);
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

// Load statistics
$stats = [
    'active_count' => 0,
    'paused_count' => 0,
    'completed_count' => 0,
    'overdue_count' => 0,
    'total_monthly_expected' => 0,
    'total_collected' => 0,
    'total_outstanding' => 0
];

if ($db_connection_ok) {
    try {
        $today = date('Y-m-d');
        
        // Get counts
        $result = $db->query("
            SELECT 
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                COUNT(CASE WHEN status = 'paused' THEN 1 END) as paused_count,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status = 'active' AND next_payment_due < '$today' THEN 1 END) as overdue_count,
                COALESCE(SUM(CASE WHEN status = 'active' THEN monthly_amount ELSE 0 END), 0) as total_monthly_expected,
                COALESCE(SUM(amount_paid), 0) as total_collected,
                COALESCE(SUM(balance), 0) as total_outstanding
            FROM donor_payment_plans
        ");
        
        if ($result) {
            $stats = array_merge($stats, $result->fetch_assoc());
        }
    } catch (Exception $e) {
        // Silent fail
    }
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
                d.balance as donor_balance
            FROM donor_payment_plans pp
            INNER JOIN donors d ON pp.donor_id = d.id
            ORDER BY 
                CASE pp.status
                    WHEN 'active' THEN 1
                    WHEN 'paused' THEN 2
                    WHEN 'completed' THEN 3
                    WHEN 'cancelled' THEN 4
                END,
                pp.next_payment_due ASC
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
        
        <main class="main-content">
            <div class="container-fluid">
                <?php include '../includes/db_error_banner.php'; ?>
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-calendar-check me-2"></i>Payment Plans Management
                        </h1>
                        <p class="text-muted mb-0">Track and manage recurring payment schedules for pledge donors</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print
                        </button>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPlanModal">
                            <i class="fas fa-plus me-2"></i>Create Plan
                        </button>
                    </div>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show animate-fade-in">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show animate-fade-in">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Summary Statistics -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="color: #0d7f4d;">
                            <div class="stat-icon bg-success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value"><?php echo number_format((int)$stats['active_count']); ?></h3>
                                <p class="stat-label">Active Plans</p>
                                <div class="stat-trend text-success">
                                    <i class="fas fa-play-circle"></i> Running
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.1s; color: #b91c1c;">
                            <div class="stat-icon bg-danger">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value text-danger"><?php echo number_format((int)$stats['overdue_count']); ?></h3>
                                <p class="stat-label">Overdue</p>
                                <div class="stat-trend text-danger">
                                    <i class="fas fa-clock"></i> Need attention
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.2s; color: #0a6286;">
                            <div class="stat-icon bg-primary">
                                <i class="fas fa-pound-sign"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">£ <?php echo number_format((float)$stats['total_monthly_expected'], 0); ?></h3>
                                <p class="stat-label">Monthly Expected</p>
                                <div class="stat-trend text-primary">
                                    <i class="fas fa-calendar"></i> Per month
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-sm-6 col-lg-3">
                        <div class="stat-card animate-fade-in" style="animation-delay: 0.3s; color: #b88a1a;">
                            <div class="stat-icon bg-warning">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="stat-content">
                                <h3 class="stat-value">£ <?php echo number_format((float)$stats['total_outstanding'], 0); ?></h3>
                                <p class="stat-label">Outstanding</p>
                                <div class="stat-trend text-warning">
                                    <i class="fas fa-arrow-down"></i> Remaining
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Stats -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-4">
                        <div class="card animate-fade-in border-0 shadow-sm" style="animation-delay: 0.4s;">
                            <div class="card-body">
                                <div class="border-start border-4 border-primary ps-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">Total Collected</h6>
                                            <p class="mb-0 small text-muted">From all payment plans</p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0 text-primary">£<?php echo number_format((float)$stats['total_collected'], 0); ?></h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-lg-4">
                        <div class="card animate-fade-in border-0 shadow-sm" style="animation-delay: 0.5s;">
                            <div class="card-body">
                                <div class="border-start border-4 border-warning ps-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">Paused Plans</h6>
                                            <p class="mb-0 small text-muted">Temporarily suspended</p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0 text-warning"><?php echo number_format((int)$stats['paused_count']); ?></h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12 col-lg-4">
                        <div class="card animate-fade-in border-0 shadow-sm" style="animation-delay: 0.6s;">
                            <div class="card-body">
                                <div class="border-start border-4 border-success ps-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1 fw-semibold">Completed Plans</h6>
                                            <p class="mb-0 small text-muted">Successfully finished</p>
                                        </div>
                                        <div class="text-end">
                                            <h4 class="mb-0 text-success"><?php echo number_format((int)$stats['completed_count']); ?></h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Plans Table -->
                <div class="card border-0 shadow-sm animate-fade-in" style="animation-delay: 0.7s;">
                    <div class="card-header bg-white border-bottom">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2 text-primary"></i>
                                All Payment Plans (<?php echo count($plans); ?>)
                            </h5>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="filterToggle">
                                <i class="fas fa-filter me-1"></i>Filters
                            </button>
                        </div>
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
                                    <option value="">All Plans</option>
                                    <option value="yes">Yes - Overdue Only</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="button" class="btn btn-sm btn-secondary" id="clearFilters">
                                    <i class="fas fa-times me-1"></i>Clear Filters
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="plansTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Donor</th>
                                        <th>Monthly</th>
                                        <th>Progress</th>
                                        <th>Next Due</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($plans as $plan): 
                                        $progress_pct = $plan['total_amount'] > 0 ? ($plan['amount_paid'] / $plan['total_amount']) * 100 : 0;
                                        $payments_made = $plan['total_amount'] > 0 && $plan['monthly_amount'] > 0 ? floor($plan['amount_paid'] / $plan['monthly_amount']) : 0;
                                        $is_overdue = $plan['status'] === 'active' && $plan['next_payment_due'] && $plan['next_payment_due'] < date('Y-m-d');
                                    ?>
                                    <tr class="plan-row" style="cursor: pointer;" data-plan='<?php echo htmlspecialchars(json_encode($plan), ENT_QUOTES); ?>'>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                        <i class="fas fa-user"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <strong class="d-block"><?php echo htmlspecialchars($plan['donor_name']); ?></strong>
                                                    <small class="text-muted"><?php echo htmlspecialchars($plan['donor_phone']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="text-primary">£<?php echo number_format($plan['monthly_amount'], 2); ?></strong>
                                            <small class="text-muted d-block"><?php echo $plan['duration_months']; ?> months</small>
                                        </td>
                                        <td>
                                            <div style="min-width: 150px;">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <small class="text-muted"><?php echo $payments_made; ?> of <?php echo $plan['duration_months']; ?></small>
                                                    <small class="fw-bold text-<?php echo $progress_pct >= 100 ? 'success' : 'primary'; ?>"><?php echo number_format($progress_pct, 0); ?>%</small>
                                                </div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-<?php echo $progress_pct >= 100 ? 'success' : 'primary'; ?>" 
                                                         style="width: <?php echo min($progress_pct, 100); ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($plan['next_payment_due']): ?>
                                                <span class="<?php echo $is_overdue ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo date('d M Y', strtotime($plan['next_payment_due'])); ?>
                                                    <?php if ($is_overdue): ?>
                                                        <i class="fas fa-exclamation-circle ms-1" title="Overdue"></i>
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
                                            $badge_icons = [
                                                'active' => 'fa-check-circle',
                                                'paused' => 'fa-pause-circle',
                                                'completed' => 'fa-flag-checkered',
                                                'cancelled' => 'fa-times-circle'
                                            ];
                                            $badge_color = $badge_colors[$plan['status']] ?? 'secondary';
                                            $badge_icon = $badge_icons[$plan['status']] ?? 'fa-circle';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_color; ?>">
                                                <i class="fas <?php echo $badge_icon; ?> me-1"></i><?php echo ucfirst($plan['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <?php if ($plan['status'] === 'active'): ?>
                                                <button type="button" class="btn btn-outline-warning change-status" 
                                                        data-plan-id="<?php echo $plan['id']; ?>" data-status="paused" 
                                                        title="Pause Plan" onclick="event.stopPropagation();">
                                                    <i class="fas fa-pause"></i>
                                                </button>
                                                <?php elseif ($plan['status'] === 'paused'): ?>
                                                <button type="button" class="btn btn-outline-success change-status" 
                                                        data-plan-id="<?php echo $plan['id']; ?>" data-status="active" 
                                                        title="Resume Plan" onclick="event.stopPropagation();">
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
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST" action="" id="createPlanForm">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="create_plan">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Create New Payment Plan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-bold">Select Donor <span class="text-danger">*</span></label>
                            <select class="form-select form-select-lg" name="donor_id" id="donor_select" required>
                                <option value="">Choose a donor...</option>
                                <?php foreach ($donors_with_pledges as $donor): ?>
                                <option value="<?php echo $donor['id']; ?>" 
                                        data-balance="<?php echo $donor['balance']; ?>"
                                        data-has-plan="<?php echo $donor['has_active_plan']; ?>">
                                    <?php echo htmlspecialchars($donor['name']); ?> - 
                                    Balance: £<?php echo number_format($donor['balance'], 2); ?>
                                    <?php if ($donor['has_active_plan']): ?>
                                        (Already has plan)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Monthly Amount (£) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" name="monthly_amount" id="monthly_amount" 
                                       min="1" step="0.01" placeholder="0.00" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Duration (Months) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" name="duration_months" id="duration_months" 
                                   min="1" max="60" placeholder="6" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-lg" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Day (1-28) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-lg" name="payment_day" min="1" max="28" value="1" required>
                            <small class="text-muted">Day of month for recurring payments</small>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label fw-bold">Preferred Payment Method</label>
                            <select class="form-select form-select-lg" name="payment_method">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="alert alert-info border-info" id="planSummary" style="display: none;">
                                <h6 class="alert-heading"><i class="fas fa-calculator me-2"></i>Plan Summary</h6>
                                <div id="summaryContent"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save me-2"></i>Create Payment Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Plan Detail Modal -->
<div class="modal fade" id="planDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Payment Plan Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="planDetailContent">
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
        order: [[3, 'asc']], // Sort by next due date
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        language: {
            search: "Search plans:",
            lengthMenu: "Show _MENU_ plans"
        },
        columnDefs: [
            { orderable: false, targets: [5] } // Disable sorting on Actions column
        ]
    });
    
    // Toggle filter panel
    $('#filterToggle').click(function() {
        $('#filterPanel').slideToggle(300);
        $(this).find('i').toggleClass('fa-filter fa-times');
    });
    
    // Status filter
    $('#filterStatus').on('change', function() {
        const status = $(this).val().toLowerCase();
        table.column(4).search(status).draw();
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
        $('#filterToggle').find('i').removeClass('fa-times').addClass('fa-filter');
        $('#filterPanel').slideUp(300);
    });
    
    // View plan details
    $(document).on('click', '.plan-row', function(e) {
        if ($(e.target).closest('button').length) return;
        
        const planData = $(this).attr('data-plan');
        if (!planData) return;
        
        const plan = JSON.parse(planData);
        
        // Build detail view
        const progressPct = plan.total_amount > 0 ? (plan.amount_paid / plan.total_amount * 100) : 0;
        const paymentsMade = plan.total_amount > 0 && plan.monthly_amount > 0 ? Math.floor(plan.amount_paid / plan.monthly_amount) : 0;
        
        const content = `
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-user text-primary me-2"></i>Donor Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="border-start border-4 border-primary ps-3 mb-3">
                                <div><strong>Name:</strong> ${plan.donor_name}</div>
                                <div class="text-muted small">${plan.donor_phone}</div>
                            </div>
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td class="text-muted">Total Pledged:</td><td class="text-end fw-bold">£${parseFloat(plan.total_pledged).toFixed(2)}</td></tr>
                                <tr><td class="text-muted">Total Paid:</td><td class="text-end fw-bold text-success">£${parseFloat(plan.total_paid).toFixed(2)}</td></tr>
                                <tr><td class="text-muted">Balance:</td><td class="text-end fw-bold text-danger">£${parseFloat(plan.donor_balance).toFixed(2)}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-calendar-check text-success me-2"></i>Plan Details</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless">
                                <tr><td class="text-muted">Monthly Amount:</td><td class="text-end fw-bold text-primary">£${parseFloat(plan.monthly_amount).toFixed(2)}</td></tr>
                                <tr><td class="text-muted">Duration:</td><td class="text-end fw-bold">${plan.duration_months} months</td></tr>
                                <tr><td class="text-muted">Total Amount:</td><td class="text-end fw-bold">£${parseFloat(plan.total_amount).toFixed(2)}</td></tr>
                                <tr><td class="text-muted">Amount Paid:</td><td class="text-end fw-bold text-success">£${parseFloat(plan.amount_paid).toFixed(2)}</td></tr>
                                <tr><td class="text-muted">Balance:</td><td class="text-end fw-bold text-danger">£${parseFloat(plan.balance).toFixed(2)}</td></tr>
                                <tr><td class="text-muted">Start Date:</td><td class="text-end">${plan.start_date ? new Date(plan.start_date).toLocaleDateString('en-GB') : '-'}</td></tr>
                                <tr><td class="text-muted">Next Due:</td><td class="text-end">${plan.next_payment_due ? new Date(plan.next_payment_due).toLocaleDateString('en-GB') : '-'}</td></tr>
                                <tr><td class="text-muted">Payment Day:</td><td class="text-end">Day ${plan.payment_day}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-chart-line text-info me-2"></i>Payment Progress</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span>${paymentsMade} of ${plan.duration_months} payments made</span>
                                <span class="fw-bold text-${progressPct >= 100 ? 'success' : 'primary'}">${progressPct.toFixed(1)}% Complete</span>
                            </div>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-${progressPct >= 100 ? 'success' : 'primary'}" 
                                     style="width: ${Math.min(progressPct, 100)}%">
                                    ${progressPct.toFixed(1)}%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-cog text-secondary me-2"></i>Actions</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                ${plan.status === 'active' ? `
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-warning w-100 change-status-modal" data-plan-id="${plan.id}" data-status="paused">
                                            <i class="fas fa-pause me-2"></i>Pause Plan
                                        </button>
                                    </div>
                                ` : ''}
                                ${plan.status === 'paused' ? `
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-success w-100 change-status-modal" data-plan-id="${plan.id}" data-status="active">
                                            <i class="fas fa-play me-2"></i>Resume Plan
                                        </button>
                                    </div>
                                ` : ''}
                                ${plan.status === 'active' || plan.status === 'paused' ? `
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-danger w-100 change-status-modal" data-plan-id="${plan.id}" data-status="cancelled">
                                            <i class="fas fa-times me-2"></i>Cancel Plan
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-primary w-100 change-status-modal" data-plan-id="${plan.id}" data-status="completed">
                                            <i class="fas fa-check me-2"></i>Mark Completed
                                        </button>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
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
                <div class="row g-2">
                    <div class="col-6">Total Plan Amount:</div>
                    <div class="col-6 text-end"><strong>£${total.toFixed(2)}</strong></div>
                    <div class="col-6">Donor Balance:</div>
                    <div class="col-6 text-end"><strong>£${donorBalance.toFixed(2)}</strong></div>
                </div>
            `;
            
            if (Math.abs(diff) > 0.01) {
                if (diff > 0) {
                    summaryHtml += `<div class="alert alert-warning mt-2 mb-0">⚠️ Plan exceeds balance by £${Math.abs(diff).toFixed(2)}</div>`;
                } else {
                    summaryHtml += `<div class="alert alert-info mt-2 mb-0">ℹ️ Plan is £${Math.abs(diff).toFixed(2)} less than balance</div>`;
                }
            } else {
                summaryHtml += `<div class="alert alert-success mt-2 mb-0">✓ Plan matches donor balance perfectly!</div>`;
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
