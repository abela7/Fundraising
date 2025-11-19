<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$db = db();
$donor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$donor_id) {
    header('Location: donors.php');
    exit;
}

$page_title = 'Donor Profile';

// Fetch Donor Details
try {
    $query = "
        SELECT 
            d.id, d.name, d.phone, d.preferred_language, 
            d.preferred_payment_method, d.source, d.total_pledged, d.total_paid, 
            d.balance, d.payment_status, d.created_at, d.updated_at,
            d.has_active_plan, d.active_payment_plan_id, d.plan_monthly_amount, 
            d.plan_duration_months, d.plan_start_date, d.plan_next_due_date,
            d.last_payment_date, d.last_sms_sent_at, d.login_count, d.admin_notes,
            d.registered_by_user_id, d.pledge_count, d.payment_count, d.achievement_badge,
            -- Payment plan details
            pp.id as plan_id, pp.total_amount as plan_total_amount,
            pp.monthly_amount as plan_monthly_amount, pp.total_months as plan_total_months,
            pp.total_payments as plan_total_payments, pp.start_date as plan_start_date,
            pp.payment_day as plan_payment_day, pp.payment_method as plan_payment_method,
            pp.next_payment_due as plan_next_payment_due, pp.last_payment_date as plan_last_payment_date,
            pp.status as plan_status, pp.plan_frequency_unit, pp.plan_frequency_number,
            pp.plan_payment_day_type, pp.template_id,
            pp.next_reminder_date, pp.miss_notification_date, pp.overdue_reminder_date,
            pp.payments_made, pp.amount_paid,
            -- Template name if exists
            t.name as template_name,
            -- Registrar name
            u.name as registrar_name
        FROM donors d
        LEFT JOIN donor_payment_plans pp ON d.active_payment_plan_id = pp.id
        LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
        LEFT JOIN users u ON d.registered_by_user_id = u.id
        WHERE d.id = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_assoc();
    
    if (!$donor) {
        die("Donor not found.");
    }
    
} catch (Exception $e) {
    die("Error loading donor: " . $e->getMessage());
}

// Determine type
$donor_type = ((float)$donor['total_pledged'] > 0) ? 'pledge' : 'immediate_payment';

// Format helpers
function formatDate($dateStr) {
    if (!$dateStr || $dateStr === '-') return '-';
    try {
        $date = new DateTime($dateStr);
        return $date->format('D, M j, Y');
    } catch (Exception $e) {
        return $dateStr;
    }
}

function formatMoney($amount) {
    return '£' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Donor Profile - <?php echo htmlspecialchars($donor['name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
                
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1 text-primary">
                            <i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($donor['name']); ?>
                        </h1>
                        <p class="text-muted mb-0">Donor Profile #<?php echo $donor['id']; ?></p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="donors.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to List
                        </a>
                        <a href="../../call-center/make-call.php?donor_id=<?php echo $donor['id']; ?>" class="btn btn-success">
                            <i class="fas fa-phone me-2"></i>Call Donor
                        </a>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left Column -->
                    <div class="col-lg-6">
                        <!-- Basic Info -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-id-card me-2 text-primary"></i>Basic Information</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="text-muted" style="width: 40%;">Full Name</td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($donor['name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Phone</td>
                                        <td>
                                            <a href="tel:<?php echo htmlspecialchars($donor['phone']); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($donor['phone']); ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Donor Type</td>
                                        <td>
                                            <?php if ($donor_type === 'pledge'): ?>
                                                <span class="badge bg-warning text-dark">Pledge Donor</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Immediate Payer</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Language</td>
                                        <td><?php echo strtoupper($donor['preferred_language'] ?? 'EN'); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Payment Method</td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $donor['preferred_payment_method'] ?? '')); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Source</td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $donor['source'] ?? '')); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- System Info -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-secondary"></i>System Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <small class="text-muted d-block">Registered By</small>
                                        <strong><?php echo htmlspecialchars($donor['registrar_name'] ?? 'System'); ?></strong>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <small class="text-muted d-block">Created At</small>
                                        <strong><?php echo formatDate($donor['created_at']); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Last Payment</small>
                                        <strong><?php echo formatDate($donor['last_payment_date']); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Last SMS</small>
                                        <strong><?php echo formatDate($donor['last_sms_sent_at']); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-lg-6">
                        <!-- Financial Summary -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-pound-sign me-2 text-success"></i>Financial Summary</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless mb-0">
                                    <tr>
                                        <td class="text-muted" style="width: 40%;">Total Pledged</td>
                                        <td class="fw-bold text-warning"><?php echo formatMoney($donor['total_pledged']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Total Paid</td>
                                        <td class="fw-bold text-success"><?php echo formatMoney($donor['total_paid']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Balance</td>
                                        <td class="fw-bold text-danger"><?php echo formatMoney($donor['balance']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Payment Status</td>
                                        <td>
                                            <span class="badge bg-<?php echo match($donor['payment_status'] ?? 'no_pledge') {
                                                'completed' => 'success', 'paying' => 'primary', 'overdue' => 'danger', 'not_started' => 'warning', default => 'secondary'
                                            }; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $donor['payment_status'] ?? '')); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Achievement Badge</td>
                                        <td>
                                            <span class="badge bg-info"><?php echo ucwords(str_replace('_', ' ', $donor['achievement_badge'] ?? 'pending')); ?></span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Payment Plan -->
                        <?php if ($donor['has_active_plan'] == 1 && $donor['plan_id']): ?>
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt me-2 text-info"></i>Active Payment Plan</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Monthly Amount</small>
                                        <strong><?php echo formatMoney($donor['plan_monthly_amount']); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Next Due</small>
                                        <strong class="text-primary"><?php echo formatDate($donor['plan_next_payment_due'] ?? $donor['plan_next_due_date']); ?></strong>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Plan Status</small>
                                        <span class="badge bg-success"><?php echo ucfirst($donor['plan_status']); ?></span>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted d-block">Progress</small>
                                        <?php echo (int)$donor['payments_made']; ?> / <?php echo (int)($donor['plan_total_payments'] ?: $donor['plan_total_months']); ?> payments
                                    </div>
                                    <div class="col-12 pt-2">
                                        <a href="payment-plans.php?id=<?php echo $donor['plan_id']; ?>" class="btn btn-sm btn-outline-info w-100">
                                            <i class="fas fa-eye me-1"></i>View Plan Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Admin Notes -->
                        <?php if ($donor['admin_notes']): ?>
                        <div class="card border-0 shadow-sm mt-4">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-sticky-note me-2 text-warning"></i>Admin Notes</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-0 text-muted"><?php echo nl2br(htmlspecialchars($donor['admin_notes'])); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

