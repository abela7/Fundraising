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

// Fetch payment plan with all related data in ONE query
$query = "
    SELECT 
        pp.*,
        d.id as donor_id,
        d.name as donor_name,
        d.phone as donor_phone,
        d.email as donor_email,
        d.balance as donor_balance,
        d.total_paid as donor_total_paid,
        p.id as pledge_id,
        p.amount as pledge_amount,
        p.notes as pledge_notes,
        p.created_at as pledge_date,
        t.name as template_name,
        t.description as template_description,
        c.name as church_name,
        c.city as church_city,
        r.name as representative_name,
        r.phone as representative_phone
    FROM donor_payment_plans pp
    INNER JOIN donors d ON pp.donor_id = d.id
    LEFT JOIN pledges p ON pp.pledge_id = p.id
    LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
    LEFT JOIN churches c ON d.church_id = c.id
    LEFT JOIN church_representatives r ON d.representative_id = r.id
    WHERE pp.id = ?
";

$stmt = $db->prepare($query);
$stmt->bind_param('i', $plan_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error_message'] = "Payment plan not found.";
    header('Location: donors.php');
    exit;
}

$plan = $result->fetch_assoc();
$stmt->close();

// Fetch payment history
$payments_query = "
    SELECT 
        pay.id,
        pay.amount,
        pay.payment_method,
        pay.payment_date,
        pay.status,
        pay.notes,
        u.name as received_by
    FROM payments pay
    LEFT JOIN users u ON pay.received_by_user_id = u.id
    WHERE pay.donor_id = ? AND pay.pledge_id = ?
    ORDER BY pay.payment_date DESC
";

$payments_stmt = $db->prepare($payments_query);
$payments_stmt->bind_param('ii', $plan['donor_id'], $plan['pledge_id']);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();
$payments = [];
while ($row = $payments_result->fetch_assoc()) {
    $payments[] = $row;
}
$payments_stmt->close();

// Calculate progress
$progress_percent = $plan['total_amount'] > 0 ? ($plan['amount_paid'] / $plan['total_amount']) * 100 : 0;
$remaining = $plan['total_amount'] - $plan['amount_paid'];

$page_title = "Payment Plan #" . $plan_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - <?php echo htmlspecialchars($plan['donor_name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            border: 2px solid #e2e8f0;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
        }
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            margin-bottom: 1rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #64748b;
            font-weight: 500;
        }
        .info-value {
            color: #1e293b;
            font-weight: 600;
        }
        .status-badge {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
        }
        .header-card {
            background: linear-gradient(135deg, #0a6286 0%, #065471 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
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
                
                <!-- Breadcrumb -->
                <div class="mb-3">
                    <a href="donors.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donors
                    </a>
                    <a href="view-donor.php?id=<?php echo $plan['donor_id']; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-user me-2"></i>View Donor Profile
                    </a>
                </div>

                <!-- Header Card -->
                <div class="header-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h1 class="mb-2"><i class="fas fa-calendar-alt me-3"></i>Payment Plan #<?php echo $plan_id; ?></h1>
                            <h4 class="mb-2"><?php echo htmlspecialchars($plan['donor_name']); ?></h4>
                            <p class="mb-0"><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($plan['donor_phone']); ?></p>
                        </div>
                        <div>
                            <?php
                            $status_colors = [
                                'active' => 'success',
                                'paused' => 'warning',
                                'completed' => 'info',
                                'defaulted' => 'danger',
                                'cancelled' => 'secondary'
                            ];
                            $status_color = $status_colors[$plan['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_color; ?> status-badge">
                                <?php echo strtoupper($plan['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Stats Row -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-primary">£<?php echo number_format($plan['total_amount'], 2); ?></div>
                            <div class="stat-label">Total Amount</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-success">£<?php echo number_format($plan['amount_paid'], 2); ?></div>
                            <div class="stat-label">Paid</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-warning">£<?php echo number_format($remaining, 2); ?></div>
                            <div class="stat-label">Remaining</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <div class="stat-value text-info"><?php echo $plan['payments_made']; ?>/<?php echo $plan['total_payments']; ?></div>
                            <div class="stat-label">Payments</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column -->
                    <div class="col-md-8">
                        
                        <!-- Plan Details -->
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Plan Details</h5>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <strong>Progress</strong>
                                    <strong><?php echo number_format($progress_percent, 1); ?>%</strong>
                                </div>
                                <div class="progress" style="height: 25px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $progress_percent; ?>%">
                                        <?php echo number_format($progress_percent, 1); ?>%
                                    </div>
                                </div>
                            </div>

                            <div class="info-row">
                                <span class="info-label">Monthly Amount:</span>
                                <span class="info-value">£<?php echo number_format($plan['monthly_amount'], 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Duration:</span>
                                <span class="info-value"><?php echo $plan['total_months']; ?> months</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Total Payments:</span>
                                <span class="info-value"><?php echo $plan['total_payments']; ?> payments</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Day:</span>
                                <span class="info-value">Day <?php echo $plan['payment_day']; ?> of month</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Payment Method:</span>
                                <span class="info-value"><?php echo ucfirst($plan['payment_method']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Start Date:</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($plan['start_date'])); ?></span>
                            </div>
                            <?php if ($plan['next_payment_due']): ?>
                            <div class="info-row">
                                <span class="info-label">Next Payment Due:</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($plan['next_payment_due'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Payment History -->
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-history me-2"></i>Payment History</h5>
                            
                            <?php if (count($payments) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Status</th>
                                            <th>Received By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><strong>£<?php echo number_format($payment['amount'], 2); ?></strong></td>
                                            <td><?php echo ucfirst($payment['payment_method']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $payment['status'] === 'approved' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($payment['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['received_by'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>No payments recorded yet.
                            </div>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- Right Column -->
                    <div class="col-md-4">
                        
                        <!-- Template Info -->
                        <?php if ($plan['template_name']): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-file-invoice me-2"></i>Template</h5>
                            <h6><?php echo htmlspecialchars($plan['template_name']); ?></h6>
                            <?php if ($plan['template_description']): ?>
                            <p class="text-muted small mb-0"><?php echo htmlspecialchars($plan['template_description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Pledge Info -->
                        <?php if ($plan['pledge_amount']): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-hand-holding-heart me-2"></i>Pledge Details</h5>
                            <div class="info-row">
                                <span class="info-label">Amount:</span>
                                <span class="info-value">£<?php echo number_format($plan['pledge_amount'], 2); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Date:</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($plan['pledge_date'])); ?></span>
                            </div>
                            <?php if ($plan['pledge_notes']): ?>
                            <div class="mt-3 pt-3 border-top">
                                <strong class="d-block mb-2">Notes:</strong>
                                <p class="text-muted small mb-0"><?php echo nl2br(htmlspecialchars($plan['pledge_notes'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Church Info -->
                        <?php if ($plan['church_name']): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-church me-2"></i>Church</h5>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($plan['church_name']); ?></strong></p>
                            <?php if ($plan['church_city']): ?>
                            <p class="text-muted mb-0">
                                <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($plan['church_city']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Representative Info -->
                        <?php if ($plan['representative_name']): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-user-tie me-2"></i>Representative</h5>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($plan['representative_name']); ?></strong></p>
                            <?php if ($plan['representative_phone']): ?>
                            <p class="text-muted mb-0">
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($plan['representative_phone']); ?>
                            </p>
                            <?php endif; ?>
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
