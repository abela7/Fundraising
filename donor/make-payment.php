<?php
/**
 * Donor Portal - Make a Payment (Wizard)
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

require_donor_login();
$donor = current_donor();
$page_title = 'Make a Payment';

$success_message = '';
$error_message = '';

// --- Data Fetching ---

// 1. Active Payment Plan
$active_plan = null;
if ($donor['has_active_plan'] && $donor['active_payment_plan_id'] && $db_connection_ok) {
    try {
        $plan_stmt = $db->prepare("
            SELECT pp.*, t.name as template_name
            FROM donor_payment_plans pp
            LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
            WHERE pp.id = ? AND pp.donor_id = ? AND pp.status = 'active'
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $donor['active_payment_plan_id'], $donor['id']);
        $plan_stmt->execute();
        $active_plan = $plan_stmt->get_result()->fetch_assoc();
    } catch (Exception $e) {}
}

// 2. Active Pledges
$active_pledges = [];
if ($db_connection_ok) {
    try {
        $has_pp_table = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
        $query = "
            SELECT 
                p.id, p.amount, p.notes, p.created_at,
                " . ($has_pp_table ? "COALESCE(SUM(pp.amount), 0)" : "0") . " as paid
            FROM pledges p
            " . ($has_pp_table ? "LEFT JOIN pledge_payments pp ON p.id = pp.pledge_id AND pp.status = 'confirmed'" : "") . "
            WHERE p.donor_id = ? AND p.status = 'approved'
            GROUP BY p.id
            HAVING (p.amount - paid) > 0.01
            ORDER BY p.created_at ASC
        ";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $donor['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $active_pledges[] = [
                'id' => $row['id'],
                'amount' => (float)$row['amount'],
                'paid' => (float)$row['paid'],
                'remaining' => (float)$row['amount'] - (float)$row['paid'],
                'date' => date('d M Y', strtotime($row['created_at'])),
                'notes' => $row['notes']
            ];
        }
    } catch (Exception $e) {
        error_log('Error fetching pledges: ' . $e->getMessage());
    }
}

// 3. Assigned Representative (for Cash logic)
$assigned_rep = null;
if ($db_connection_ok) {
    try {
        // Check columns first
        $has_rep_col = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'")->num_rows > 0;
        if ($has_rep_col) {
             $rep_query = "
                SELECT cr.name, cr.phone, c.name as church_name, c.city
                FROM donors d
                JOIN church_representatives cr ON d.representative_id = cr.id
                JOIN churches c ON cr.church_id = c.id
                WHERE d.id = ?
             ";
             $stmt = $db->prepare($rep_query);
             $stmt->bind_param('i', $donor['id']);
             $stmt->execute();
             $assigned_rep = $stmt->get_result()->fetch_assoc();
        }
    } catch (Exception $e) {}
}

// --- Bank Details ---
$bank_account_name   = 'LMKATH';
$bank_account_number = '85455687';
$bank_sort_code      = '53-70-44';

// Reference Generation
$bank_reference_label = '';
if (!empty($donor['name'])) {
    $name_parts = preg_split('/\s+/', trim((string)$donor['name']));
    $bank_reference_label = $name_parts[0] ?? 'Donor';
    // Append digits from pledge notes if available
    if (!empty($active_pledges)) {
        $digits = preg_replace('/\D+/', '', (string)($active_pledges[0]['notes'] ?? ''));
        if ($digits) $bank_reference_label .= (strlen($digits) >= 4 ? substr($digits, -4) : $digits);
    }
}

// --- Handle Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
    verify_csrf();
    
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $pledge_id = (int)($_POST['pledge_id'] ?? 0);
    $payment_plan_id = (int)($_POST['payment_plan_id'] ?? 0);
    
    // Validate
    if ($payment_amount <= 0) {
        $error_message = 'Please enter a valid payment amount.';
    } elseif ($payment_amount > $donor['balance']) {
        $error_message = 'Payment amount cannot exceed your remaining balance.';
    } elseif (!in_array($payment_method, ['cash', 'bank_transfer', 'card', 'other'])) {
        $error_message = 'Invalid payment method.';
    } else {
        // Handle File Upload
        $payment_proof = null;
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
             $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
             if (!in_array($_FILES['payment_proof']['type'], $allowed)) {
                 $error_message = "Invalid file type.";
             } elseif ($_FILES['payment_proof']['size'] > 5 * 1024 * 1024) {
                 $error_message = "File too large (Max 5MB).";
             } else {
                 $dir = __DIR__ . '/../uploads/payment_proofs/';
                 if (!is_dir($dir)) mkdir($dir, 0755, true);
                 $fn = 'proof_donor_' . $donor['id'] . '_' . time() . '_' . uniqid() . '.' . pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
                 if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $dir . $fn)) {
                     $payment_proof = 'uploads/payment_proofs/' . $fn;
                 } else {
                     $error_message = "Upload failed.";
                 }
             }
        } else {
             $payment_proof = '';
        }

        if (empty($error_message) && $db_connection_ok) {
            try {
                $db->begin_transaction();
                
                // Use payment_plan_id from form (set if user has active plan)
                // If not provided but active plan exists, use it
                if ($payment_plan_id <= 0 && $active_plan && isset($active_plan['id'])) {
                    $payment_plan_id = (int)$active_plan['id'];
                }
                
                if ($pledge_id > 0) {
                    // Check if pledge_payments table has payment_plan_id column
                    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
                    
                    if ($has_plan_col && $payment_plan_id > 0) {
                        $sql = "INSERT INTO pledge_payments (pledge_id, donor_id, payment_plan_id, amount, payment_method, payment_date, reference_number, payment_proof, notes, status) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'pending')";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param('iiidssss', $pledge_id, $donor['id'], $payment_plan_id, $payment_amount, $payment_method, $reference, $payment_proof, $notes);
                    } else {
                        $sql = "INSERT INTO pledge_payments (pledge_id, donor_id, amount, payment_method, payment_date, reference_number, payment_proof, notes, status) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'pending')";
                        $stmt = $db->prepare($sql);
                        $stmt->bind_param('iidssss', $pledge_id, $donor['id'], $payment_amount, $payment_method, $reference, $payment_proof, $notes);
                    }
                    $entity_type = 'pledge_payment';
                } else {
                    $sql = "INSERT INTO payments (donor_id, donor_name, donor_phone, amount, method, reference, notes, status, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'donor_portal', NOW())";
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param('issdsss', $donor['id'], $donor['name'], $donor['phone'], $payment_amount, $payment_method, $reference, $notes);
                    $entity_type = 'payment';
                }
                $stmt->execute();
                $payment_id = $db->insert_id;
                
                // Audit Log
                $audit = json_encode([
                    'payment_id' => $payment_id, 'amount' => $payment_amount, 'method' => $payment_method,
                    'pledge_id' => $pledge_id, 'payment_plan_id' => $payment_plan_id > 0 ? $payment_plan_id : null, 'proof' => $payment_proof
                ]);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(0, ?, ?, 'create_pending', ?, 'donor_portal')");
                $log->bind_param('sis', $entity_type, $payment_id, $audit);
                $log->execute();
                
                $db->commit();
                $success_message = "Payment submitted successfully!";
                
                // Refresh Session
                $ref = $db->prepare("SELECT * FROM donors WHERE id = ?");
                $ref->bind_param('i', $donor['id']);
                $ref->execute();
                if ($d = $ref->get_result()->fetch_assoc()) $_SESSION['donor'] = $d;
                
            } catch (Exception $e) {
                $db->rollback();
                $error_message = "System error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/donor.css">
    <style>
        .wizard-step { display: none; }
        .wizard-step.active { display: block; animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .step-indicator { width: 30px; height: 30px; border-radius: 50%; background: #e9ecef; color: #6c757d; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 10px; }
        .step-indicator.active { background: #0d6efd; color: white; }
        .step-indicator.completed { background: #198754; color: white; }
        .wizard-nav { border-bottom: 1px solid #dee2e6; margin-bottom: 20px; padding-bottom: 15px; }
        .card-radio { cursor: pointer; transition: all 0.2s; border: 2px solid #dee2e6; }
        .card-radio:hover { border-color: #aeccea; background: #f8f9fa; }
        .card-radio.selected { border-color: #0d6efd; background: #f0f7ff; }
        .rep-finder-container { background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #dee2e6; }
    </style>
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-0">
                <div class="page-header mb-4">
                    <h1 class="page-title">Make a Payment</h1>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                        <button class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                        <button class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                                <?php if ($donor['balance'] <= 0): ?>
                                    <div class="alert alert-success text-center py-5">
                                        <i class="fas fa-check-circle fa-3x mb-3"></i>
                                        <h5>No Payment Due</h5>
                        <p>You have completed all your payments! Thank you for your generosity.</p>
                        <a href="index.php" class="btn btn-primary mt-3">Return to Dashboard</a>
                                    </div>
                                <?php else: ?>

                <form method="POST" id="paymentWizardForm" enctype="multipart/form-data">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="submit_payment">
                    <input type="hidden" name="payment_amount" id="finalAmount">
                    <input type="hidden" name="pledge_id" id="finalPledgeId">
                    <input type="hidden" name="payment_method" id="finalMethod">
                    <input type="hidden" name="payment_plan_id" id="finalPaymentPlanId" value="<?php echo $active_plan ? $active_plan['id'] : ''; ?>">
                    
                    <!-- Wizard Navigation -->
                    <div class="wizard-nav d-flex justify-content-between align-items-center overflow-auto">
                        <div class="d-flex align-items-center">
                            <div class="step-indicator active" id="ind1">1</div>
                            <span class="fw-bold d-none d-md-inline">Plan</span>
                        </div>
                        <div class="text-muted mx-1"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind2">2</div>
                            <span class="fw-bold d-none d-md-inline text-muted">Amount</span>
                        </div>
                        <div class="text-muted mx-1"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind3">3</div>
                            <span class="fw-bold d-none d-md-inline text-muted">Method</span>
                        </div>
                        <div class="text-muted mx-1"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind4">4</div>
                            <span class="fw-bold d-none d-md-inline text-muted">Details</span>
                        </div>
                        <div class="text-muted mx-1"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind5">5</div>
                            <span class="fw-bold d-none d-md-inline text-muted">Confirm</span>
                        </div>
                    </div>

                    <!-- Step 1: Payment Plan Priority -->
                    <div class="wizard-step active" id="step1">
                        <h5 class="mb-4">Payment Plan Status</h5>
                        <?php if ($active_plan): 
                            // Calculate installment number
                            $payments_made = (int)($active_plan['payments_made'] ?? 0);
                            $total_payments = (int)($active_plan['total_payments'] ?? $active_plan['total_months'] ?? 1);
                            $next_installment = $payments_made + 1;
                            $is_last_payment = ($next_installment >= $total_payments);
                            $plan_progress = $total_payments > 0 ? round(($payments_made / $total_payments) * 100) : 0;
                        ?>
                            <div class="card mb-4 border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-calendar-check me-2"></i>Active Payment Plan
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <!-- Installment Badge -->
                                    <div class="alert alert-info mb-4 d-flex align-items-center">
                                        <i class="fas fa-info-circle fa-2x me-3"></i>
                                        <div class="flex-grow-1">
                                            <strong>This is Payment <?php echo $next_installment; ?> of <?php echo $total_payments; ?></strong>
                                            <br>
                                            <small>Part of your scheduled payment plan</small>
                                        </div>
                                        <?php if ($is_last_payment): ?>
                                            <span class="badge bg-warning text-dark ms-2">Final Payment</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Progress Bar -->
                                    <div class="mb-4">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="text-muted">Plan Progress</small>
                                            <small class="text-muted fw-bold"><?php echo $payments_made; ?> / <?php echo $total_payments; ?> payments</small>
                                        </div>
                                        <div class="progress" style="height: 25px;">
                                            <div class="progress-bar bg-success progress-bar-striped" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $plan_progress; ?>%"
                                                 aria-valuenow="<?php echo $plan_progress; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <strong><?php echo $plan_progress; ?>%</strong>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-6 col-12">
                                            <div class="border-start border-4 border-info ps-3">
                                                <small class="text-muted d-block mb-1">Next Payment Due</small>
                                                <h5 class="mb-0 text-info">
                                                    <?php echo date('d M Y', strtotime($active_plan['next_payment_due'])); ?>
                                                </h5>
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-12">
                                            <div class="border-start border-4 border-success ps-3">
                                                <small class="text-muted d-block mb-1">Amount Due</small>
                                                <h5 class="mb-0 text-success">£<?php echo number_format($active_plan['monthly_amount'], 2); ?></h5>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Plan Summary -->
                                    <div class="row g-3 mb-4">
                                        <div class="col-md-4 col-6">
                                            <div class="text-center p-2 bg-light rounded">
                                                <small class="text-muted d-block">Total Plan Amount</small>
                                                <strong class="text-primary">£<?php echo number_format($active_plan['total_amount'] ?? ($active_plan['monthly_amount'] * $total_payments), 2); ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-md-4 col-6">
                                            <div class="text-center p-2 bg-light rounded">
                                                <small class="text-muted d-block">Amount Paid</small>
                                                <strong class="text-success">£<?php echo number_format($active_plan['amount_paid'] ?? 0, 2); ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-md-4 col-12">
                                            <div class="text-center p-2 bg-light rounded">
                                                <small class="text-muted d-block">Remaining</small>
                                                <strong class="text-warning">£<?php echo number_format(($active_plan['total_amount'] ?? ($active_plan['monthly_amount'] * $total_payments)) - ($active_plan['amount_paid'] ?? 0), 2); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-grid gap-2 d-md-flex">
                                        <button type="button" class="btn btn-success btn-lg flex-fill" onclick="selectPlanAmount(<?php echo $active_plan['monthly_amount']; ?>, <?php echo $active_plan['id']; ?>)">
                                            <i class="fas fa-credit-card me-2"></i>Pay Installment <?php echo $next_installment; ?> (£<?php echo number_format($active_plan['monthly_amount'], 2); ?>)
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-lg flex-fill" onclick="goToStep(2)">
                                            <i class="fas fa-edit me-2"></i>Pay Different Amount
                                        </button>
                                    </div>
                                    
                                    <?php if ($is_last_payment): ?>
                                        <div class="alert alert-warning mt-3 mb-0">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>Final Payment:</strong> After this payment, your payment plan will be completed.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card mb-4">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                    <h5 class="mb-2">No Active Payment Plan</h5>
                                    <p class="text-muted mb-4">You don't have a scheduled payment plan set up.</p>
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                        <button type="button" class="btn btn-success btn-lg px-4" onclick="goToStep(2)">
                                            <i class="fas fa-credit-card me-2"></i>Make One-Time Payment
                                        </button>
                                        <a href="payment-plan.php" class="btn btn-outline-primary btn-lg px-4">
                                            <i class="fas fa-calendar-plus me-2"></i>Create Payment Plan
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Step 2: Amount & Pledge Selection -->
                    <div class="wizard-step" id="step2">
                        <h5 class="mb-3">Select Pledge & Amount</h5>
                        
                        <?php if (!empty($active_pledges)): ?>
                            <div class="mb-3">
                                <label class="form-label">Select Pledge</label>
                                <div class="list-group">
                                    <?php foreach ($active_pledges as $idx => $p): ?>
                                        <label class="list-group-item list-group-item-action d-flex justify-content-between align-items-center pledge-item">
                                            <div>
                                                <input class="form-check-input me-2" type="radio" name="step2_pledge" value="<?php echo $p['id']; ?>" 
                                                       data-remaining="<?php echo $p['remaining']; ?>" 
                                                       <?php echo $idx === 0 ? 'checked' : ''; ?> 
                                                       onchange="updateMaxAmount(this)">
                                                <strong><?php echo $p['date']; ?></strong>
                                                <div class="small text-muted"><?php echo htmlspecialchars($p['notes'] ?? ''); ?></div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-light text-dark border">Rem: £<?php echo number_format($p['remaining'], 2); ?></span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning mb-3">No active pledges found. You can still make a general payment.</div>
                            <input type="hidden" name="step2_pledge" value="0">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Payment Amount</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text">£</span>
                                <input type="number" class="form-control" id="step2_amount" step="0.01" min="0.01" placeholder="0.00">
                            </div>
                            <div class="form-text">Max: £<span id="maxAmountDisplay"><?php echo number_format($donor['balance'], 2); ?></span></div>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)">Back</button>
                            <button type="button" class="btn btn-primary px-4" onclick="validateStep2()">Next: Method</button>
                        </div>
                    </div>

                    <!-- Step 3: Payment Method Selection -->
                    <div class="wizard-step" id="step3">
                        <h5 class="mb-3">Choose Payment Method</h5>
                        
                        <div class="row g-3 mb-4">
                            <!-- Bank Transfer -->
                            <div class="col-6">
                                <div class="card card-radio p-3 text-center h-100" onclick="selectMethod('bank_transfer')" id="card_bank_transfer">
                                    <i class="fas fa-university fa-2x mb-2 text-primary"></i>
                                    <div class="fw-bold">Bank Transfer</div>
                                    <small class="text-muted">Direct to our account</small>
                                </div>
                            </div>
                            <!-- Cash -->
                            <div class="col-6">
                                <div class="card card-radio p-3 text-center h-100" onclick="selectMethod('cash')" id="card_cash">
                                    <i class="fas fa-money-bill-wave fa-2x mb-2 text-success"></i>
                                    <div class="fw-bold">Cash</div>
                                    <small class="text-muted">Pay to representative</small>
                                </div>
                                            </div>
                                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">Back</button>
                            <button type="button" class="btn btn-primary px-4" id="btnMethodNext" disabled onclick="goToStep(4)">Next</button>
                        </div>
                                        </div>

                    <!-- Step 4: Payment Details -->
                    <div class="wizard-step" id="step4">
                        
                        <!-- Bank Transfer Details -->
                        <div id="bankDetails" style="display: none;">
                            <h5 class="mb-3"><i class="fas fa-university text-primary me-2"></i>Bank Transfer Details</h5>
                            
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle me-2"></i>Transfer the amount to the account below and upload your receipt.
                            </div>
                            
                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-body">
                                                <!-- Account Name -->
                                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                        <span class="text-muted">Account Name</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold me-2" id="bankAccName"><?php echo $bank_account_name; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="copyField('bankAccName')">
                                                <i class="fas fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Account Number -->
                                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                        <span class="text-muted">Account Number</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold me-2" id="bankAccNum"><?php echo $bank_account_number; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="copyField('bankAccNum')">
                                                <i class="fas fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Sort Code -->
                                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                        <span class="text-muted">Sort Code</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold me-2" id="bankSortCode"><?php echo $bank_sort_code; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="copyField('bankSortCode')">
                                                <i class="fas fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                    <!-- Reference -->
                                    <div class="d-flex justify-content-between align-items-center py-2">
                                        <span class="text-muted">Reference</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold text-primary me-2" id="bankRef"><?php echo $bank_reference_label; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" onclick="copyField('bankRef')">
                                                <i class="fas fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                </div>
                            </div>
                            
                            <!-- Amount Reminder -->
                            <div class="card bg-light border-0 mb-3">
                                <div class="card-body text-center">
                                    <small class="text-muted">Amount to Transfer</small>
                                    <h3 class="mb-0 text-primary">£<span id="bankAmountDisplay">0.00</span></h3>
                                </div>
                            </div>
                        </div>

                        <!-- Cash Payment Details -->
                        <div id="cashDetails" style="display: none;">
                            <h5 class="mb-3"><i class="fas fa-hand-holding-usd text-success me-2"></i>Cash Payment</h5>
                            
                            <?php if ($assigned_rep): ?>
                                <!-- Has Assigned Representative -->
                                <div class="alert alert-success mb-3">
                                    <i class="fas fa-check-circle me-2"></i>Go to your church and pay to your assigned representative.
                                </div>
                                
                                <div class="card border-success shadow-sm mb-4">
                                    <div class="card-header bg-success text-white">
                                        <i class="fas fa-church me-2"></i>Your Church & Representative
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-12 mb-3">
                                                <small class="text-muted d-block">Church</small>
                                                <h5 class="mb-0"><?php echo htmlspecialchars($assigned_rep['church_name']); ?></h5>
                                                <small class="text-muted"><?php echo htmlspecialchars($assigned_rep['city']); ?></small>
                                                </div>
                                            <div class="col-12">
                                                <small class="text-muted d-block">Representative</small>
                                                <h5 class="mb-1 text-success"><?php echo htmlspecialchars($assigned_rep['name']); ?></h5>
                                                <a href="tel:<?php echo htmlspecialchars($assigned_rep['phone']); ?>" class="btn btn-outline-success btn-sm">
                                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($assigned_rep['phone']); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Amount Reminder -->
                                <div class="card bg-light border-0 mb-3">
                                    <div class="card-body text-center">
                                        <small class="text-muted">Amount to Pay</small>
                                        <h3 class="mb-0 text-success">£<span id="cashAmountDisplay">0.00</span></h3>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Once the representative confirms they received your cash, your payment will be approved.
                                </div>
                            <?php else: ?>
                                <!-- No Representative - Find One -->
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>You're not assigned to a church or representative yet. Please select one below.
                                        </div>

                                <div class="card shadow-sm mb-4">
                                    <div class="card-header bg-white">
                                        <strong>Find Your Representative</strong>
                                    </div>
                                    <div class="card-body">
                                        <!-- Step 1: City -->
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                <span class="badge bg-primary me-2">1</span>Select Your City
                                            </label>
                                            <select class="form-select" id="finderCity" onchange="loadChurches(this.value)">
                                                <option value="">-- Choose City --</option>
                                            </select>
                                        </div>

                                        <!-- Step 2: Church -->
                                        <div class="mb-3" id="divChurch" style="display:none;">
                                            <label class="form-label fw-bold">
                                                <span class="badge bg-primary me-2">2</span>Select Church
                                            </label>
                                            <select class="form-select" id="finderChurch" onchange="loadReps(this.value)">
                                                <option value="">-- Choose Church --</option>
                                            </select>
                                        </div>

                                        <!-- Step 3: Representative -->
                                        <div class="mb-3" id="divRep" style="display:none;">
                                            <label class="form-label fw-bold">
                                                <span class="badge bg-primary me-2">3</span>Select Representative
                                            </label>
                                            <select class="form-select" id="finderRep">
                                                <option value="">-- Choose Representative --</option>
                                            </select>
                                        </div>

                                        <button type="button" class="btn btn-success w-100 fw-bold" id="btnAssignRep" onclick="assignRepresentative()" disabled>
                                            <i class="fas fa-check me-2"></i>Confirm & Assign
                                            </button>
                                    </div>
                                        </div>
                                
                                <!-- Assigned Rep Details (shown after assignment) -->
                                <div id="newRepDetails" style="display:none;"></div>
                                <?php endif; ?>
                            </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(3)">Back</button>
                            <button type="button" class="btn btn-primary px-4" id="btnDetailsNext" onclick="goToStep(5)">Next: Confirm</button>
                        </div>
                    </div>

                    <!-- Step 5: Confirmation -->
                    <div class="wizard-step" id="step5">
                        <h5 class="mb-3"><i class="fas fa-check-circle text-success me-2"></i>Confirm Payment</h5>
                        
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-body">
                                <div class="row mb-2">
                                    <div class="col-6 text-muted">Amount:</div>
                                    <div class="col-6 fw-bold text-end fs-5">£<span id="confirmAmount">0.00</span></div>
                                </div>
                                <div class="row">
                                    <div class="col-6 text-muted">Method:</div>
                                    <div class="col-6 fw-bold text-end text-capitalize"><span id="confirmMethod">-</span></div>
                                </div>
                            </div>
                        </div>

                        <!-- Reference & Proof (For Bank Transfer) -->
                        <div id="bankConfirmFields">
                            <div class="mb-3">
                                <label class="form-label">Reference Number <span class="text-muted">(from your bank)</span></label>
                                <input type="text" name="reference" class="form-control" placeholder="e.g. Transaction ID">
                            </div>
                            
                                <div class="mb-3">
                                <label class="form-label">Payment Proof <span class="text-danger">*</span></label>
                                <input type="file" name="payment_proof" class="form-control" accept="image/*,.pdf">
                                <div class="form-text">Upload your bank transfer receipt for faster approval.</div>
                                </div>
                                    </div>
                        
                        <!-- Cash Confirmation Message -->
                        <div id="cashConfirmMessage" style="display: none;">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>After you pay cash to your representative, they will confirm receipt and your payment will be approved.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Additional Notes <span class="text-muted">(optional)</span></label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(4)">Back</button>
                            <button type="submit" class="btn btn-success px-4 fw-bold">
                                <i class="fas fa-paper-plane me-2"></i>Submit Payment
                            </button>
                        </div>
                    </div>

                </form>
                <?php endif; ?>
                </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
<script>
// --- State ---
let currentStep = 1;
let selectedAmount = 0;
let selectedMethod = '';
let selectedPledgeId = 0;
let selectedPaymentPlanId = <?php echo $active_plan ? $active_plan['id'] : 0; ?>;
let maxBalance = <?php echo $donor['balance']; ?>;
let assignedRep = <?php echo json_encode($assigned_rep ? true : false); ?>;
let isPlanPayment = <?php echo $active_plan ? 'true' : 'false'; ?>;
let planInstallment = <?php echo $active_plan ? (($active_plan['payments_made'] ?? 0) + 1) : 0; ?>;
let planTotal = <?php echo $active_plan ? ($active_plan['total_payments'] ?? $active_plan['total_months'] ?? 1) : 0; ?>;

// --- Navigation ---
function goToStep(step) {
    // Special handling for step 4 (details)
    if (step === 4) {
        setupStep4();
    }
    
    // Special handling for step 5 (confirm)
    if (step === 5) {
        setupStep5();
    }
    
    // Hide all
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step-indicator').forEach(el => {
        el.classList.remove('active');
        if(parseInt(el.id.replace('ind','')) < step) el.classList.add('completed');
        else el.classList.remove('completed');
    });

    // Show target
    document.getElementById('step' + step).classList.add('active');
    const ind = document.getElementById('ind' + step);
    if (ind) ind.classList.add('active');
    currentStep = step;
}

// --- Step 1 Logic ---
function selectPlanAmount(amount, planId) {
    selectedAmount = amount;
    selectedPaymentPlanId = planId || 0;
    isPlanPayment = planId > 0;
    
    document.getElementById('step2_amount').value = amount.toFixed(2);
    document.getElementById('finalPaymentPlanId').value = planId || '';
    
    // Auto-select first pledge
    const pledgeRadio = document.querySelector('input[name="step2_pledge"]:checked');
    selectedPledgeId = pledgeRadio ? pledgeRadio.value : 0;
    
    goToStep(3); // Skip to method selection
}

// --- Step 2 Logic ---
function updateMaxAmount(radio) {
    const rem = parseFloat(radio.getAttribute('data-remaining'));
    document.getElementById('maxAmountDisplay').textContent = rem.toFixed(2);
    document.getElementById('step2_amount').value = rem.toFixed(2);
}

function validateStep2() {
    const amt = parseFloat(document.getElementById('step2_amount').value);
    if(!amt || amt <= 0) { alert('Please enter a valid amount'); return; }
    if(amt > maxBalance) { alert('Amount exceeds your total balance'); return; }
    
    selectedAmount = amt;
    const pledgeRadio = document.querySelector('input[name="step2_pledge"]:checked');
    selectedPledgeId = pledgeRadio ? pledgeRadio.value : 0;
    
    // If user manually enters amount, check if they still want to link to plan
    // Keep plan link if amount matches monthly amount (or let them choose)
    // For now, we'll keep the plan link if it was set initially
    // User can clear it by going back to step 1
    
    goToStep(3);
}

// --- Step 3 Logic (Method Selection) ---
function selectMethod(method) {
    selectedMethod = method;
    
    // UI Highlight
    document.querySelectorAll('.card-radio').forEach(el => el.classList.remove('selected'));
    const selectedCard = document.getElementById('card_' + method);
    if (selectedCard) selectedCard.classList.add('selected');
    
    // Enable Next button
    const btn = document.getElementById('btnMethodNext');
    if (btn) btn.disabled = false;
}

// --- Step 4 Logic (Payment Details) ---
function setupStep4() {
    const bankDetails = document.getElementById('bankDetails');
    const cashDetails = document.getElementById('cashDetails');
    const btnDetailsNext = document.getElementById('btnDetailsNext');
    
    // Hide all detail sections first
    if (bankDetails) bankDetails.style.display = 'none';
    if (cashDetails) cashDetails.style.display = 'none';
    
    if (selectedMethod === 'bank_transfer') {
        if (bankDetails) bankDetails.style.display = 'block';
        // Show amount
        const amtDisplay = document.getElementById('bankAmountDisplay');
        if (amtDisplay) amtDisplay.textContent = selectedAmount.toFixed(2);
        // Enable next
        if (btnDetailsNext) btnDetailsNext.disabled = false;
    } 
    else if (selectedMethod === 'cash') {
        if (cashDetails) cashDetails.style.display = 'block';
        // Show amount
        const amtDisplay = document.getElementById('cashAmountDisplay');
        if (amtDisplay) amtDisplay.textContent = selectedAmount.toFixed(2);
        
        // Check if rep is assigned
        if (assignedRep) {
            if (btnDetailsNext) btnDetailsNext.disabled = false;
        } else {
            // Need to assign rep first
            if (btnDetailsNext) btnDetailsNext.disabled = true;
            loadCities();
            }
        }
    }

// --- Step 5 Logic (Confirmation) ---
function setupStep5() {
    // Populate summary
    document.getElementById('confirmAmount').textContent = selectedAmount.toFixed(2);
    document.getElementById('confirmMethod').textContent = selectedMethod.replace('_', ' ');
    
    // Show payment plan info if applicable
    const confirmCard = document.querySelector('#step5 .card');
    if (confirmCard && isPlanPayment && planInstallment > 0) {
        let planInfo = confirmCard.querySelector('.plan-info');
        if (!planInfo) {
            planInfo = document.createElement('div');
            planInfo.className = 'alert alert-info mb-3 plan-info';
            confirmCard.querySelector('.card-body').insertBefore(planInfo, confirmCard.querySelector('.card-body').firstChild);
        }
        planInfo.innerHTML = `
            <i class="fas fa-calendar-check me-2"></i>
            <strong>Payment Plan Installment:</strong> Payment ${planInstallment} of ${planTotal}
        `;
    } else if (confirmCard) {
        const planInfo = confirmCard.querySelector('.plan-info');
        if (planInfo) planInfo.remove();
    }
    
    // Show/hide relevant fields
    const bankFields = document.getElementById('bankConfirmFields');
    const cashMessage = document.getElementById('cashConfirmMessage');
    
    if (selectedMethod === 'bank_transfer') {
        if (bankFields) bankFields.style.display = 'block';
        if (cashMessage) cashMessage.style.display = 'none';
    } else if (selectedMethod === 'cash') {
        if (bankFields) bankFields.style.display = 'none';
        if (cashMessage) cashMessage.style.display = 'block';
    }
    
    // Populate hidden inputs
    document.getElementById('finalAmount').value = selectedAmount;
    document.getElementById('finalPledgeId').value = selectedPledgeId;
    document.getElementById('finalMethod').value = selectedMethod;
    document.getElementById('finalPaymentPlanId').value = selectedPaymentPlanId || '';
}

// --- Cash Representative Finder Logic ---
function loadCities() {
    const sel = document.getElementById('finderCity');
    if (!sel) return;
    if (sel.options.length > 1) return; // Already loaded
    
    fetch('api/location-data.php?action=get_cities')
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                d.cities.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    sel.appendChild(opt);
                });
            }
        });
}

function loadChurches(city) {
    const sel = document.getElementById('finderChurch');
    const divChurch = document.getElementById('divChurch');
    const divRep = document.getElementById('divRep');
    const btnAssign = document.getElementById('btnAssignRep');
    
    if (!sel) return;
    
    sel.innerHTML = '<option value="">-- Choose Church --</option>';
    if (divChurch) divChurch.style.display = city ? 'block' : 'none';
    if (divRep) divRep.style.display = 'none';
    if (btnAssign) btnAssign.disabled = true;
    
    if(!city) return;
    
    fetch(`api/location-data.php?action=get_churches&city=${encodeURIComponent(city)}`)
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                d.churches.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    sel.appendChild(opt);
                });
            }
        });
}

function loadReps(churchId) {
    const sel = document.getElementById('finderRep');
    const divRep = document.getElementById('divRep');
    const btnAssign = document.getElementById('btnAssignRep');
    
    if (!sel) return;
    
    sel.innerHTML = '<option value="">-- Choose Representative --</option>';
    if (divRep) divRep.style.display = churchId ? 'block' : 'none';
    if (btnAssign) btnAssign.disabled = true;
    
    if(!churchId) return;
    
    fetch(`api/location-data.php?action=get_representatives&church_id=${churchId}`)
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                d.representatives.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.textContent = r.name + ' (' + r.role + ')';
                    opt.setAttribute('data-phone', r.phone || '');
                    opt.setAttribute('data-name', r.name);
                    sel.appendChild(opt);
                });
            }
        });
        
    sel.onchange = function() {
        if (btnAssign) btnAssign.disabled = !this.value;
    }
}

function assignRepresentative() {
    const repSel = document.getElementById('finderRep');
    const churchSel = document.getElementById('finderChurch');
    const btn = document.getElementById('btnAssignRep');
    
    if (!repSel || !churchSel || !btn) return;
    
    const repId = repSel.value;
    const churchId = churchSel.value;
    
    // Get display details
    const opt = repSel.options[repSel.selectedIndex];
    const name = opt.getAttribute('data-name');
    const phone = opt.getAttribute('data-phone');
    const churchName = churchSel.options[churchSel.selectedIndex].textContent;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Assigning...';
    
    const fd = new FormData();
    fd.append('representative_id', repId);
    fd.append('church_id', churchId);
    
    fetch('api/location-data.php?action=assign_rep', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                // Hide finder card
                const finderCard = btn.closest('.card');
                if (finderCard) finderCard.style.display = 'none';
                
                // Show success with rep details
                const det = document.getElementById('newRepDetails');
                if (det) {
                    det.style.display = 'block';
                    det.innerHTML = `
                        <div class="card border-success shadow-sm">
                            <div class="card-header bg-success text-white">
                                <i class="fas fa-check-circle me-2"></i>Representative Assigned!
                            </div>
                            <div class="card-body">
                                <p class="mb-2">Go to your church and pay to:</p>
                                <h5 class="text-success mb-1">${name}</h5>
                                <p class="text-muted mb-2">${churchName}</p>
                                ${phone ? `<a href="tel:${phone}" class="btn btn-outline-success btn-sm"><i class="fas fa-phone me-1"></i>${phone}</a>` : ''}
                            </div>
                        </div>
                        <div class="card bg-light border-0 mt-3">
                            <div class="card-body text-center">
                                <small class="text-muted">Amount to Pay</small>
                                <h3 class="mb-0 text-success">£${selectedAmount.toFixed(2)}</h3>
                            </div>
                        </div>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="fas fa-info-circle me-2"></i>Once the representative confirms they received your cash, your payment will be approved.
                        </div>
                    `;
                }
                
                // Update state and enable Next
                assignedRep = true;
                const btnNext = document.getElementById('btnDetailsNext');
                if (btnNext) btnNext.disabled = false;
            } else {
                alert('Error: ' + (d.message || 'Could not assign representative'));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm & Assign';
            }
        })
        .catch(err => {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-2"></i>Confirm & Assign';
        });
}

// Helper: Copy field by ID
function copyField(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    const text = el.textContent.trim();
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showCopyFeedback(el);
        });
    } else {
        // Fallback
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand("Copy");
        textArea.remove();
        showCopyFeedback(el);
    }
}

function showCopyFeedback(el) {
    const original = el.textContent;
    el.innerHTML = '<i class="fas fa-check text-success"></i> Copied!';
    setTimeout(() => { el.textContent = original; }, 1000);
}

</script>
</body>
</html>
