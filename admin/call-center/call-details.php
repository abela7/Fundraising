<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

$db = db();
$user_id = (int)$_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_admin = ($user_role === 'admin');

// Get session ID
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$session_id) {
    header('Location: call-history.php');
    exit;
}

// Fetch Call Session Details
$query = "
    SELECT 
        s.*,
        d.name as donor_name,
        d.phone as donor_phone,
        d.email as donor_email,
        d.city as donor_city,
        u.name as agent_name,
        pp.total_amount as plan_amount,
        pp.status as plan_status
    FROM call_center_sessions s
    LEFT JOIN donors d ON s.donor_id = d.id
    LEFT JOIN users u ON s.agent_id = u.id
    LEFT JOIN donor_payment_plans pp ON s.payment_plan_id = pp.id
    WHERE s.id = ?
";

$stmt = $db->prepare($query);
$stmt->bind_param('i', $session_id);
$stmt->execute();
$result = $stmt->get_result();
$call = $result->fetch_object();

if (!$call) {
    header('Location: call-history.php');
    exit;
}

// Format Duration
$duration_sec = (int)($call->duration_seconds ?? 0);
if ($duration_sec > 60) {
    $formatted_duration = floor($duration_sec / 60) . 'm ' . ($duration_sec % 60) . 's';
} elseif ($duration_sec > 0) {
    $formatted_duration = $duration_sec . 's';
} else {
    $formatted_duration = '-';
}

$page_title = 'Call Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-phone-square-alt me-2"></i>
                        Call Details
                    </h1>
                    <p class="content-subtitle">
                        Record #<?php echo $call->id; ?> • <?php echo date('M j, Y g:i A', strtotime($call->call_started_at)); ?>
                    </p>
                </div>
                <div class="header-actions">
                    <a href="call-history.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back
                    </a>
                    <a href="edit-call-record.php?id=<?php echo $call->id; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Record
                    </a>
                    <?php if ($is_admin || $call->agent_id == $user_id): ?>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete(<?php echo $call->id; ?>)">
                        <i class="fas fa-trash-alt me-2"></i>Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-4">
                <!-- Main Info -->
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title"><i class="fas fa-info-circle me-2"></i>Call Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="text-muted small text-uppercase fw-bold">Outcome</label>
                                    <div class="mt-1">
                                        <span class="outcome-badge outcome-<?php echo str_replace('_', '-', $call->outcome); ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $call->outcome)); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small text-uppercase fw-bold">Duration</label>
                                    <div class="mt-1 fw-bold fs-5"><?php echo $formatted_duration; ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small text-uppercase fw-bold">Agent</label>
                                    <div class="mt-1"><?php echo htmlspecialchars($call->agent_name ?? 'Unknown'); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small text-uppercase fw-bold">Call Stage</label>
                                    <div class="mt-1"><?php echo ucwords(str_replace('_', ' ', $call->conversation_stage)); ?></div>
                                </div>
                                
                                <div class="col-12 mt-4">
                                    <label class="text-muted small text-uppercase fw-bold">Notes</label>
                                    <div class="p-3 bg-light rounded border mt-1">
                                        <?php echo nl2br(htmlspecialchars($call->notes ?? 'No notes recorded.')); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Plan Section -->
                    <?php if ($call->payment_plan_id): ?>
                    <div class="card border-success mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title text-white"><i class="fas fa-file-invoice-dollar me-2"></i>Payment Plan Created</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-4 fw-bold text-success">£<?php echo number_format((float)$call->plan_amount, 2); ?></div>
                                    <div class="text-muted">Total Pledged Amount</div>
                                    <div class="mt-2 badge bg-success bg-opacity-10 text-success border border-success">
                                        Status: <?php echo ucfirst($call->plan_status); ?>
                                    </div>
                                </div>
                                <div>
                                    <a href="../donor-management/payment-plans.php?id=<?php echo $call->payment_plan_id; ?>" class="btn btn-outline-success">
                                        <i class="fas fa-external-link-alt me-2"></i>View Plan
                                    </a>
                                    <a href="edit-payment-plan-flow.php?plan_id=<?php echo $call->payment_plan_id; ?>&session_id=<?php echo $call->id; ?>" class="btn btn-success ms-2">
                                        <i class="fas fa-redo me-2"></i>Redo / Edit Plan
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Callback Section -->
                    <?php if ($call->callback_scheduled_for): ?>
                    <div class="card border-warning mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="card-title text-dark"><i class="fas fa-calendar-check me-2"></i>Callback Scheduled</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="text-muted small">Scheduled For</label>
                                    <div class="fw-bold fs-5"><?php echo date('M j, Y g:i A', strtotime($call->callback_scheduled_for)); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small">Reason</label>
                                    <div><?php echo htmlspecialchars($call->callback_reason ?? 'None'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar Info -->
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title"><i class="fas fa-user me-2"></i>Donor Profile</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="avatar-placeholder rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-2" style="width: 64px; height: 64px; font-size: 24px; color: var(--cc-primary);">
                                    <?php echo strtoupper(substr($call->donor_name, 0, 1)); ?>
                                </div>
                                <h5 class="mb-0"><?php echo htmlspecialchars($call->donor_name); ?></h5>
                                <div class="text-muted small"><?php echo htmlspecialchars($call->donor_city ?? 'No City'); ?></div>
                            </div>
                            
                            <hr>
                            
                            <div class="info-item mb-2">
                                <i class="fas fa-phone text-muted me-2"></i>
                                <a href="tel:<?php echo htmlspecialchars($call->donor_phone); ?>" class="text-decoration-none"><?php echo htmlspecialchars($call->donor_phone); ?></a>
                            </div>
                            
                            <?php if ($call->donor_email): ?>
                            <div class="info-item mb-3">
                                <i class="fas fa-envelope text-muted me-2"></i>
                                <a href="mailto:<?php echo htmlspecialchars($call->donor_email); ?>" class="text-decoration-none"><?php echo htmlspecialchars($call->donor_email); ?></a>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2 mt-3">
                                <a href="make-call.php?donor_id=<?php echo $call->donor_id; ?>" class="btn btn-success">
                                    <i class="fas fa-phone me-2"></i>Call Again
                                </a>
                                <a href="../donor-management/view-donor.php?id=<?php echo $call->donor_id; ?>" class="btn btn-outline-secondary">
                                    View Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this call record?\n\nWARNING: If a payment plan is attached, you will be asked how to handle it on the next screen.')) {
        window.location.href = 'delete-call-record.php?id=' + id;
    }
}
</script>
</body>
</html>

