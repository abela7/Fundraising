<?php
// Disable strict types to be more forgiving
// declare(strict_types=1);

// Enable error reporting for debugging (will be caught by try-catch later)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

// Ensure user is logged in and admin
require_login();
require_admin();

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
            // Simple query first
            $query = "SELECT * FROM payments WHERE donor_id = ? AND pledge_id = ? ORDER BY payment_date DESC";
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
        .stat-box { background: white; border-radius: 10px; padding: 20px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); height: 100%; }
        .stat-val { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
        .stat-lbl { color: #6c757d; font-size: 14px; }
        .detail-box { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .detail-lbl { font-weight: 500; color: #555; }
        .detail-val { font-weight: 600; color: #333; }
        .header-section { background: linear-gradient(135deg, #0a6286 0%, #065471 100%); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <div class="mb-3">
                    <a href="donors.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i>Back to Donors</a>
                </div>

                <!-- Header -->
                <div class="header-section">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="h3 mb-2"><i class="fas fa-calendar-check me-2"></i>Payment Plan #<?php echo $plan_id; ?></h1>
                            <h4 class="h5 mb-0 text-light opacity-75">
                                <?php echo htmlspecialchars($donor['name'] ?? 'Unknown Donor'); ?>
                                <?php if(!empty($donor['phone'])): ?>
                                    <span class="ms-3 fs-6"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?></span>
                                <?php endif; ?>
                            </h4>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <span class="badge bg-light text-dark fs-6 px-3 py-2 text-uppercase">
                                <?php echo htmlspecialchars($plan['status'] ?? 'Unknown'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="stat-box border-start border-primary border-4">
                            <div class="stat-val text-primary">£<?php echo number_format($total_amount, 2); ?></div>
                            <div class="stat-lbl">Total Amount</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-box border-start border-success border-4">
                            <div class="stat-val text-success">£<?php echo number_format($amount_paid, 2); ?></div>
                            <div class="stat-lbl">Amount Paid</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-box border-start border-warning border-4">
                            <div class="stat-val text-warning">£<?php echo number_format($remaining, 2); ?></div>
                            <div class="stat-lbl">Remaining</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="stat-box border-start border-info border-4">
                            <div class="stat-val text-info"><?php echo (int)($plan['payments_made'] ?? 0); ?> / <?php echo (int)($plan['total_payments'] ?? 0); ?></div>
                            <div class="stat-lbl">Installments</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column: Plan Details -->
                    <div class="col-lg-8">
                        <div class="detail-box">
                            <h5 class="mb-4 border-bottom pb-2"><i class="fas fa-info-circle me-2 text-primary"></i>Plan Information</h5>
                            
                            <div class="mb-4">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span>Progress</span>
                                    <span><?php echo number_format($progress, 1); ?>%</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </div>

                            <div class="detail-row">
                                <span class="detail-lbl">Monthly Installment</span>
                                <span class="detail-val fs-5 text-primary">£<?php echo number_format($monthly_amount, 2); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">Total Duration</span>
                                <span class="detail-val"><?php echo (int)($plan['total_months'] ?? 0); ?> Months</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">Start Date</span>
                                <span class="detail-val"><?php echo !empty($plan['start_date']) ? date('d M Y', strtotime($plan['start_date'])) : '-'; ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">Payment Day</span>
                                <span class="detail-val">Day <?php echo (int)($plan['payment_day'] ?? 1); ?> of month</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">Payment Method</span>
                                <span class="detail-val text-uppercase"><?php echo htmlspecialchars($plan['payment_method'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">Next Due Date</span>
                                <span class="detail-val text-danger"><?php echo !empty($plan['next_payment_due']) ? date('d M Y', strtotime($plan['next_payment_due'])) : '-'; ?></span>
                            </div>
                        </div>

                        <!-- Payment History -->
                        <div class="detail-box">
                            <h5 class="mb-4 border-bottom pb-2"><i class="fas fa-history me-2 text-primary"></i>Payment History</h5>
                            <?php if(empty($payments)): ?>
                                <div class="alert alert-light text-center">No payments found for this plan.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Status</th>
                                                <th>Received By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($payments as $pay): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($pay['payment_date'])); ?></td>
                                                <td class="fw-bold">£<?php echo number_format((float)$pay['amount'], 2); ?></td>
                                                <td><?php echo ucfirst($pay['payment_method']); ?></td>
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
                                                <td class="small text-muted"><?php echo htmlspecialchars($pay['received_by_name']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Column: Related Info -->
                    <div class="col-lg-4">
                        <!-- Donor Info -->
                        <div class="detail-box">
                            <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-user me-2 text-primary"></i>Donor Details</h5>
                            <div class="detail-row">
                                <span class="detail-lbl">Name</span>
                                <span class="detail-val text-end"><?php echo htmlspecialchars($donor['name'] ?? '-'); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">City</span>
                                <span class="detail-val text-end"><?php echo htmlspecialchars($donor['city'] ?? '-'); ?></span>
                            </div>
                            <?php if(!empty($church)): ?>
                            <div class="mt-3 pt-2 border-top">
                                <div class="fw-bold text-primary mb-1"><i class="fas fa-church me-1"></i> Church</div>
                                <div><?php echo htmlspecialchars($church['name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($church['city'] ?? ''); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if(!empty($representative)): ?>
                            <div class="mt-3 pt-2 border-top">
                                <div class="fw-bold text-primary mb-1"><i class="fas fa-user-tie me-1"></i> Representative</div>
                                <div><?php echo htmlspecialchars($representative['name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($representative['phone'] ?? ''); ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="d-grid mt-3">
                                <a href="view-donor.php?id=<?php echo $plan['donor_id']; ?>" class="btn btn-outline-primary btn-sm">View Full Profile</a>
                            </div>
                        </div>

                        <!-- Pledge Info -->
                        <?php if(!empty($pledge)): ?>
                        <div class="detail-box">
                            <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-hand-holding-heart me-2 text-primary"></i>Original Pledge</h5>
                            <div class="detail-row">
                                <span class="detail-lbl">Amount</span>
                                <span class="detail-val">£<?php echo number_format((float)$pledge['amount'], 2); ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-lbl">Date</span>
                                <span class="detail-val"><?php echo date('d M Y', strtotime($pledge['created_at'])); ?></span>
                            </div>
                            <?php if(!empty($pledge['notes'])): ?>
                            <div class="mt-3 bg-light p-2 rounded small">
                                <strong>Notes:</strong><br>
                                <?php echo nl2br(htmlspecialchars($pledge['notes'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Template Info -->
                        <?php if(!empty($template)): ?>
                        <div class="detail-box">
                            <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-file-alt me-2 text-primary"></i>Plan Template</h5>
                            <div class="fw-bold"><?php echo htmlspecialchars($template['name']); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($template['description'] ?? ''); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Actions -->
                        <div class="detail-box">
                            <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-cogs me-2 text-primary"></i>Actions</h5>
                            <div class="d-grid gap-2">
                                <button class="btn btn-warning" id="btnEditPlan"><i class="fas fa-edit me-2"></i>Edit Plan</button>
                                <?php if(($plan['status'] ?? '') === 'active'): ?>
                                    <button class="btn btn-info text-white" id="btnPausePlan"><i class="fas fa-pause me-2"></i>Pause Plan</button>
                                <?php endif; ?>
                                <button class="btn btn-danger" id="btnDeletePlan"><i class="fas fa-trash me-2"></i>Delete Plan</button>
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
    // Simple sidebar toggle fix
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('sidebar-toggle');
        const wrapper = document.querySelector('.admin-wrapper');
        if(toggle && wrapper) {
            toggle.addEventListener('click', function() {
                wrapper.classList.toggle('toggled');
            });
        }
        
        // Placeholders for buttons
        document.getElementById('btnEditPlan')?.addEventListener('click', () => alert('Edit feature coming soon'));
        document.getElementById('btnPausePlan')?.addEventListener('click', () => alert('Pause feature coming soon'));
        document.getElementById('btnDeletePlan')?.addEventListener('click', () => alert('Delete feature coming soon'));
    });
</script>
</body>
</html>
