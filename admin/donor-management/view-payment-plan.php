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

try {
    // Simple query like view-donor.php - just get the plan first
    $plan_query = "
        SELECT pp.*, t.name as template_name, t.description as template_description
        FROM donor_payment_plans pp
        LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
        WHERE pp.id = ?
    ";
    
    $query = $db->prepare($plan_query);
    if (!$query) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $query->bind_param('i', $plan_id);
    
    if (!$query->execute()) {
        throw new Exception("Execute failed: " . $query->error);
    }
    
    $result = $query->get_result();

    if ($result->num_rows === 0) {
        $_SESSION['error_message'] = "Payment plan not found.";
        header('Location: donors.php');
        exit;
    }

    $plan = $result->fetch_assoc(); // Use fetch_assoc instead of fetch_object
    $query->close();
    
    // Now fetch donor details separately
    $donor_id = (int)$plan['donor_id'];
    $donor_query = "SELECT id, name, phone, email, balance, total_pledged, total_paid FROM donors WHERE id = ?";
    $donor_stmt = $db->prepare($donor_query);
    $donor_stmt->bind_param('i', $donor_id);
    $donor_stmt->execute();
    $donor_result = $donor_stmt->get_result();
    $donor = $donor_result->fetch_assoc();
    $donor_stmt->close();
    
    if (!$donor) {
        throw new Exception("Donor not found for plan #$plan_id");
    }
    
    // Fetch pledge details if pledge_id exists
    $pledge = null;
    if (!empty($plan['pledge_id'])) {
        $check_sqm_col = $db->query("SHOW COLUMNS FROM pledges LIKE 'sqm'");
        $has_sqm_col = $check_sqm_col && $check_sqm_col->num_rows > 0;
        
        $pledge_fields = "id, amount, notes, created_at";
        if ($has_sqm_col) {
            $pledge_fields .= ", sqm";
        }
        
        $pledge_query = "SELECT $pledge_fields FROM pledges WHERE id = ?";
        $pledge_stmt = $db->prepare($pledge_query);
        $pledge_stmt->bind_param('i', $plan['pledge_id']);
        $pledge_stmt->execute();
        $pledge_result = $pledge_stmt->get_result();
        $pledge = $pledge_result->fetch_assoc();
        $pledge_stmt->close();
    }
    
    // Fetch representative if column exists
    $representative = null;
    $check_rep_col = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    $has_rep_col = $check_rep_col && $check_rep_col->num_rows > 0;
    
    if ($has_rep_col && !empty($donor['representative_id'])) {
        $rep_query = "SELECT name, phone FROM church_representatives WHERE id = ?";
        $rep_stmt = $db->prepare($rep_query);
        $rep_stmt->bind_param('i', $donor['representative_id']);
        $rep_stmt->execute();
        $rep_result = $rep_stmt->get_result();
        $representative = $rep_result->fetch_assoc();
        $rep_stmt->close();
    }
    
    // Fetch church if column exists
    $church = null;
    $check_church_col = $db->query("SHOW COLUMNS FROM donors LIKE 'church_id'");
    $has_church_col = $check_church_col && $check_church_col->num_rows > 0;
    
    if ($has_church_col && !empty($donor['church_id'])) {
        $church_query = "SELECT name, city FROM churches WHERE id = ?";
        $church_stmt = $db->prepare($church_query);
        $church_stmt->bind_param('i', $donor['church_id']);
        $church_stmt->execute();
        $church_result = $church_stmt->get_result();
        $church = $church_result->fetch_assoc();
        $church_stmt->close();
    }
    
    // Debug: Log plan data
    error_log("Plan loaded - ID: " . $plan['id'] . ", Donor: " . $donor['name']);
    error_log("Plan total_amount: " . ($plan['total_amount'] ?? 'NULL'));
    error_log("Plan monthly_amount: " . ($plan['monthly_amount'] ?? 'NULL'));
    error_log("Plan amount_paid: " . ($plan['amount_paid'] ?? 'NULL'));

    // Fetch payments - use donor_id and pledge_id from plan array
    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }
    
    $user_id_col = null;
    if (in_array('approved_by_user_id', $payment_columns)) {
        $user_id_col = 'approved_by_user_id';
    } elseif (in_array('received_by_user_id', $payment_columns)) {
        $user_id_col = 'received_by_user_id';
    } elseif (in_array('approved_by', $payment_columns)) {
        $user_id_col = 'approved_by';
    }
    
    $date_col = in_array('payment_date', $payment_columns) ? 'payment_date' : 
               (in_array('received_at', $payment_columns) ? 'received_at' : 'created_at');
    
    $method_col = in_array('payment_method', $payment_columns) ? 'payment_method' : 'method';
    
    $user_name_column = null;
    $check_name = $db->query("SHOW COLUMNS FROM users LIKE 'name'");
    if ($check_name && $check_name->num_rows > 0) {
        $user_name_column = 'u.name';
    }
    
    $payments_sql = "SELECT pay.*";
    
    if ($user_id_col && $user_name_column) {
        $payments_sql .= ", $user_name_column as approved_by_name";
    }
    
    $payments_sql .= " FROM payments pay";
    
    if ($user_id_col && $user_name_column) {
        $payments_sql .= " LEFT JOIN users u ON u.id = pay.$user_id_col";
    }
    
    $pledge_id_for_payments = !empty($plan['pledge_id']) ? $plan['pledge_id'] : 0;
    $payments_sql .= " WHERE pay.donor_id = ?";
    if ($pledge_id_for_payments > 0) {
        $payments_sql .= " AND pay.pledge_id = ?";
    }
    $payments_sql .= " ORDER BY pay.$date_col DESC";
    
    $payments_query = $db->prepare($payments_sql);
    
    if (!$payments_query) {
        $payments = [];
    } else {
        if ($pledge_id_for_payments > 0) {
            $payments_query->bind_param('ii', $donor_id, $pledge_id_for_payments);
        } else {
            $payments_query->bind_param('i', $donor_id);
        }
        if ($payments_query->execute()) {
            $payments = $payments_query->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $payments = [];
        }
        $payments_query->close();
    }

    // Calculate progress - ensure we have valid numbers
    $total_amount = (float)($plan['total_amount'] ?? 0);
    $amount_paid = (float)($plan['amount_paid'] ?? 0);
    $total_payments = (int)($plan['total_payments'] ?? 0);
    $payments_made = (int)($plan['payments_made'] ?? 0);
    
    $progress_percentage = $total_amount > 0 ? ($amount_paid / $total_amount) * 100 : 0;
    $remaining_amount = $total_amount - $amount_paid;
    $remaining_payments = $total_payments - $payments_made;
    
    error_log("Calculated - Progress: $progress_percentage%, Remaining: $remaining_amount, Remaining Payments: $remaining_payments");

    $page_title = "Payment Plan #$plan_id - " . htmlspecialchars($donor['name'] ?? 'Unknown');
    
} catch (Exception $e) {
    error_log("Error in view-payment-plan.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    die("Error loading payment plan: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .plan-header {
            background: linear-gradient(135deg, #0a6286 0%, #075985 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(10, 98, 134, 0.2);
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            height: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            color: #0a6286;
            margin: 0;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .status-badge {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }
        .progress-container {
            margin: 1.5rem 0;
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
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .plan-header {
                padding: 1.5rem;
            }
            .plan-header h1 {
                font-size: 1.5rem;
            }
            .plan-header h4 {
                font-size: 1.1rem;
            }
            .stat-value {
                font-size: 1.5rem;
            }
            .d-flex.gap-2 {
                flex-direction: column;
            }
            .d-flex.gap-2 .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            .info-card {
                padding: 1rem;
            }
            .payment-timeline {
                padding-left: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .plan-header {
                padding: 1rem;
            }
            .stat-card {
                padding: 1rem;
            }
            .stat-value {
                font-size: 1.25rem;
            }
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
                
                <!-- Back Button -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Donors
                    </a>
                    <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-user me-2"></i>View Donor Profile
                    </a>
                </div>

                <!-- Plan Header -->
                <div class="plan-header">
                    <div class="d-flex justify-content-between align-items-start flex-wrap">
                        <div>
                            <h1 class="mb-2">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Payment Plan #<?php echo $plan['id']; ?>
                            </h1>
                            <h4 class="mb-3"><?php echo htmlspecialchars($donor['name']); ?></h4>
                            <div class="mb-2">
                                <i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($donor['phone']); ?>
                            </div>
                            <?php if (!empty($donor['email'])): ?>
                            <div class="mb-2">
                                <i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($donor['email']); ?>
                            </div>
                            <?php endif; ?>
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
                            $status_color = $status_colors[$plan['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $status_color; ?> status-badge">
                                <?php echo strtoupper($plan['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="mb-4 d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-warning" id="btnEditPlan" data-plan-id="<?php echo $plan['id']; ?>">
                        <i class="fas fa-edit me-2"></i>Edit Plan
                    </button>
                    <?php if ($plan['status'] === 'active'): ?>
                    <button type="button" class="btn btn-info" id="btnPausePlan" data-plan-id="<?php echo $plan['id']; ?>">
                        <i class="fas fa-pause me-2"></i>Pause Plan
                    </button>
                    <?php elseif ($plan['status'] === 'paused'): ?>
                    <button type="button" class="btn btn-success" id="btnResumePlan" data-plan-id="<?php echo $plan['id']; ?>">
                        <i class="fas fa-play me-2"></i>Resume Plan
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-danger" id="btnDeletePlan" data-plan-id="<?php echo $plan['id']; ?>">
                        <i class="fas fa-trash me-2"></i>Delete Plan
                    </button>
                </div>

                <div class="row">
                    <!-- Statistics -->
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-value text-primary">£<?php echo number_format($total_amount, 2); ?></div>
                            <div class="stat-label">Total Amount</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-value text-success">£<?php echo number_format($amount_paid, 2); ?></div>
                            <div class="stat-label">Paid</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-value text-warning">£<?php echo number_format($remaining_amount, 2); ?></div>
                            <div class="stat-label">Remaining</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="stat-card">
                            <div class="stat-value text-info"><?php echo $payments_made; ?>/<?php echo $total_payments; ?></div>
                            <div class="stat-label">Payments</div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Plan Details -->
                    <div class="col-lg-8">
                        <div class="info-card">
                            <h5 class="mb-4"><i class="fas fa-chart-line me-2"></i>Plan Overview</h5>
                            
                            <!-- Progress Bar -->
                            <div class="progress-container">
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
                            <div class="row g-3 mt-3">
                                <div class="col-md-6">
                                    <strong><i class="fas fa-money-bill-wave text-primary me-2"></i>Monthly Amount:</strong>
                                    <span class="float-end">£<?php echo number_format((float)($plan['monthly_amount'] ?? 0), 2); ?></span>
                                </div>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-calendar text-primary me-2"></i>Duration:</strong>
                                    <span class="float-end"><?php echo $plan['total_months'] ?? 0; ?> months</span>
                                </div>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-hashtag text-primary me-2"></i>Total Payments:</strong>
                                    <span class="float-end"><?php echo $total_payments; ?> payments</span>
                                </div>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-redo text-primary me-2"></i>Frequency:</strong>
                                    <span class="float-end text-capitalize">
                                        <?php 
                                        $freq_num = $plan['plan_frequency_number'] ?? 1;
                                        $freq_unit = $plan['plan_frequency_unit'] ?? 'month';
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
                                    <span class="float-end">Day <?php echo $plan['payment_day'] ?? 1; ?> of month</span>
                                </div>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-credit-card text-primary me-2"></i>Payment Method:</strong>
                                    <span class="float-end text-capitalize"><?php echo str_replace('_', ' ', $plan['payment_method'] ?? 'bank_transfer'); ?></span>
                                </div>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-play-circle text-primary me-2"></i>Start Date:</strong>
                                    <span class="float-end"><?php echo $plan['start_date'] ? date('d M Y', strtotime($plan['start_date'])) : 'N/A'; ?></span>
                                </div>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-flag-checkered text-primary me-2"></i>End Date:</strong>
                                    <span class="float-end">
                                        <?php 
                                        if ($plan['start_date'] && $plan['total_months']) {
                                            $end_date = date('d M Y', strtotime($plan['start_date'] . " + {$plan['total_months']} months"));
                                            echo $end_date;
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <?php if (!empty($plan['next_payment_due'])): ?>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-clock text-warning me-2"></i>Next Payment Due:</strong>
                                    <span class="float-end"><?php echo date('d M Y', strtotime($plan['next_payment_due'])); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($plan['last_payment_date'])): ?>
                                <div class="col-md-6">
                                    <strong><i class="fas fa-check-circle text-success me-2"></i>Last Payment:</strong>
                                    <span class="float-end"><?php echo date('d M Y', strtotime($plan['last_payment_date'])); ?></span>
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
                                                            <?php 
                                                            $payment_date = $payment['payment_date'] ?? $payment['received_at'] ?? $payment['created_at'] ?? '';
                                                            echo $payment_date ? date('d M Y', strtotime($payment_date)) : 'N/A';
                                                            ?>
                                                        </p>
                                                        <p class="text-muted mb-0">
                                                            <small>
                                                                Method: <span class="text-capitalize">
                                                                    <?php 
                                                                    $method = $payment['method'] ?? $payment['payment_method'] ?? 'unknown';
                                                                    echo str_replace('_', ' ', $method);
                                                                    ?>
                                                                </span>
                                                                <?php if (isset($payment['approved_by_name']) && $payment['approved_by_name']): ?>
                                                                    | Approved by: <?php echo htmlspecialchars($payment['approved_by_name']); ?>
                                                                <?php endif; ?>
                                                            </small>
                                                        </p>
                                                    </div>
                                                    <span class="badge bg-success">
                                                        <?php echo strtoupper($payment['status'] ?? 'approved'); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($payment['notes'])): ?>
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
                        <?php if (!empty($plan['template_name'])): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-file-alt me-2"></i>Plan Template</h5>
                            <h6><?php echo htmlspecialchars($plan['template_name']); ?></h6>
                            <?php if (!empty($plan['template_description'])): ?>
                            <p class="text-muted small"><?php echo htmlspecialchars($plan['template_description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Representative Info -->
                        <?php if ($representative): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-user-tie me-2"></i>Representative</h5>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($representative['name']); ?></strong></p>
                            <?php if (!empty($representative['phone'])): ?>
                            <p class="text-muted mb-0">
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($representative['phone']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Church Info -->
                        <?php if ($church): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-church me-2"></i>Church</h5>
                            <p class="mb-1"><strong><?php echo htmlspecialchars($church['name']); ?></strong></p>
                            <?php if (!empty($church['city'])): ?>
                            <p class="text-muted mb-0">
                                <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($church['city']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Pledge Details -->
                        <?php if ($pledge): ?>
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-hand-holding-heart me-2"></i>Pledge Details</h5>
                            <p class="mb-2">
                                <strong>Amount:</strong> 
                                <span class="float-end">£<?php echo number_format($pledge['amount'], 2); ?></span>
                            </p>
                            <?php if (isset($pledge['sqm']) && $pledge['sqm']): ?>
                            <p class="mb-2">
                                <strong>Square Meters:</strong> 
                                <span class="float-end"><?php echo $pledge['sqm']; ?> sqm</span>
                            </p>
                            <?php endif; ?>
                            <p class="mb-2">
                                <strong>Pledge Date:</strong> 
                                <span class="float-end"><?php echo date('d M Y', strtotime($pledge['created_at'])); ?></span>
                            </p>
                            <?php if (!empty($pledge['notes'])): ?>
                            <div class="mt-3 pt-3 border-top">
                                <strong class="d-block mb-2">Notes:</strong>
                                <p class="text-muted small mb-0"><?php echo nl2br(htmlspecialchars($pledge['notes'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Timestamps -->
                        <div class="info-card">
                            <h5 class="mb-3"><i class="fas fa-clock me-2"></i>Timeline</h5>
                            <p class="text-muted small mb-2">
                                <strong>Created:</strong><br>
                                <?php echo $plan['created_at'] ? date('d M Y, H:i', strtotime($plan['created_at'])) : 'N/A'; ?>
                            </p>
                            <p class="text-muted small mb-0">
                                <strong>Last Updated:</strong><br>
                                <?php echo $plan['updated_at'] ? date('d M Y, H:i', strtotime($plan['updated_at'])) : 'N/A'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
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
                <?php csrf_field(); ?>
                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Changing the monthly amount or payment frequency will not automatically recalculate existing payment schedules.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="monthly_amount" class="form-label">Installment Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">£</span>
                                <input type="number" step="0.01" class="form-control" id="monthly_amount" 
                                       name="monthly_amount" value="<?php echo $plan['monthly_amount'] ?? 0; ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="total_payments" class="form-label">Total Payments</label>
                            <input type="number" min="1" class="form-control" id="total_payments" 
                                   name="total_payments" value="<?php echo $total_payments; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="plan_frequency_unit" class="form-label">Frequency Unit</label>
                            <select class="form-select" id="plan_frequency_unit" name="plan_frequency_unit" required>
                                <option value="week" <?php echo ($plan['plan_frequency_unit'] ?? 'month') === 'week' ? 'selected' : ''; ?>>Week</option>
                                <option value="month" <?php echo ($plan['plan_frequency_unit'] ?? 'month') === 'month' ? 'selected' : ''; ?>>Month</option>
                                <option value="year" <?php echo ($plan['plan_frequency_unit'] ?? 'month') === 'year' ? 'selected' : ''; ?>>Year</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="plan_frequency_number" class="form-label">Frequency Number</label>
                            <input type="number" min="1" max="12" class="form-control" id="plan_frequency_number" 
                                   name="plan_frequency_number" value="<?php echo $plan['plan_frequency_number'] ?? 1; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="payment_day" class="form-label">Payment Day (1-28)</label>
                            <input type="number" min="1" max="28" class="form-control" id="payment_day" 
                                   name="payment_day" value="<?php echo $plan['payment_day'] ?? 1; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="cash" <?php echo ($plan['payment_method'] ?? 'bank_transfer') === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo ($plan['payment_method'] ?? 'bank_transfer') === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="card" <?php echo ($plan['payment_method'] ?? 'bank_transfer') === 'card' ? 'selected' : ''; ?>>Card</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" <?php echo ($plan['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="paused" <?php echo ($plan['status'] ?? 'active') === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                <option value="completed" <?php echo ($plan['status'] ?? 'active') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="defaulted" <?php echo ($plan['status'] ?? 'active') === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                <option value="cancelled" <?php echo ($plan['status'] ?? 'active') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="next_payment_due" class="form-label">Next Payment Due</label>
                            <input type="date" class="form-control" id="next_payment_due" 
                                   name="next_payment_due" value="<?php echo $plan['next_payment_due'] ?? ''; ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit Plan Button
    const btnEditPlan = document.getElementById('btnEditPlan');
    if (btnEditPlan) {
        btnEditPlan.addEventListener('click', function() {
            const modalElement = document.getElementById('editPlanModal');
            if (modalElement) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        });
    }

    // Pause Plan Button
    const btnPausePlan = document.getElementById('btnPausePlan');
    if (btnPausePlan) {
        btnPausePlan.addEventListener('click', function() {
            const planId = this.getAttribute('data-plan-id');
            if (confirm('Are you sure you want to pause this payment plan?')) {
                updatePlanStatus(planId, 'paused');
            }
        });
    }

    // Resume Plan Button
    const btnResumePlan = document.getElementById('btnResumePlan');
    if (btnResumePlan) {
        btnResumePlan.addEventListener('click', function() {
            const planId = this.getAttribute('data-plan-id');
            if (confirm('Are you sure you want to resume this payment plan?')) {
                updatePlanStatus(planId, 'active');
            }
        });
    }

    // Delete Plan Button
    const btnDeletePlan = document.getElementById('btnDeletePlan');
    if (btnDeletePlan) {
        btnDeletePlan.addEventListener('click', function() {
            const planId = this.getAttribute('data-plan-id');
            if (confirm('Are you sure you want to delete this payment plan? This action cannot be undone.')) {
                window.location.href = 'delete-payment-plan.php?id=' + planId;
            }
        });
    }

    // Form submission handler
    const editPlanForm = document.getElementById('editPlanForm');
    if (editPlanForm) {
        editPlanForm.addEventListener('submit', function(e) {
            // Form will submit normally to update-payment-plan.php
        });
    }
});

function updatePlanStatus(planId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'update-payment-plan-status.php';
    
    // Add CSRF token if available
    const csrfToken = document.querySelector('meta[name="csrf-token"]');
    if (csrfToken) {
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = csrfToken.getAttribute('content');
        form.appendChild(csrfInput);
    }
    
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
