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
                
                if ($pledge_id > 0) {
                    $sql = "INSERT INTO pledge_payments (pledge_id, donor_id, amount, payment_method, payment_date, reference_number, payment_proof, notes, status) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'pending')";
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param('iidssss', $pledge_id, $donor['id'], $payment_amount, $payment_method, $reference, $payment_proof, $notes);
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
                    'pledge_id' => $pledge_id, 'proof' => $payment_proof
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
                    
                    <!-- Wizard Navigation -->
                    <div class="wizard-nav d-flex justify-content-between align-items-center overflow-auto">
                        <div class="d-flex align-items-center">
                            <div class="step-indicator active" id="ind1">1</div>
                            <span class="fw-bold d-none d-sm-inline">Plan</span>
                        </div>
                        <div class="text-muted mx-2"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind2">2</div>
                            <span class="fw-bold d-none d-sm-inline text-muted">Amount</span>
                        </div>
                        <div class="text-muted mx-2"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind3">3</div>
                            <span class="fw-bold d-none d-sm-inline text-muted">Method</span>
                        </div>
                        <div class="text-muted mx-2"><i class="fas fa-chevron-right small"></i></div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="ind4">4</div>
                            <span class="fw-bold d-none d-sm-inline text-muted">Confirm</span>
                        </div>
                    </div>

                    <!-- Step 1: Payment Plan Priority -->
                    <div class="wizard-step active" id="step1">
                        <h5 class="mb-3">Payment Plan Status</h5>
                        <?php if ($active_plan): ?>
                            <div class="card border-primary mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="card-title text-primary"><i class="fas fa-calendar-check me-2"></i>Active Plan</h5>
                                            <p class="mb-1">Next Due: <strong><?php echo date('d M Y', strtotime($active_plan['next_payment_due'])); ?></strong></p>
                                            <p class="mb-0">Amount: <strong>£<?php echo number_format($active_plan['monthly_amount'], 2); ?></strong></p>
                                        </div>
                                        <span class="badge bg-primary">Active</span>
                                    </div>
                                    <hr>
                                    <div class="d-grid gap-2">
                                        <button type="button" class="btn btn-primary btn-lg" onclick="selectPlanAmount(<?php echo $active_plan['monthly_amount']; ?>)">
                                            Pay Monthly Amount (£<?php echo number_format($active_plan['monthly_amount'], 2); ?>)
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
                                            Pay Different Amount
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card mb-3">
                                <div class="card-body text-center py-4">
                                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                    <h5>No Active Payment Plan</h5>
                                    <p class="text-muted">You don't have a scheduled payment plan set up.</p>
                                    <div class="d-grid gap-2 d-md-block">
                                        <button type="button" class="btn btn-primary px-4" onclick="goToStep(2)">Make One-Time Payment</button>
                                        <a href="payment-plan.php" class="btn btn-outline-primary px-4">Create Payment Plan</a>
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

                    <!-- Step 3: Payment Method -->
                    <div class="wizard-step" id="step3">
                        <h5 class="mb-3">Choose Payment Method</h5>
                        
                        <div class="row g-3 mb-4">
                            <!-- Bank Transfer -->
                            <div class="col-6">
                                <div class="card card-radio p-3 text-center h-100" onclick="selectMethod('bank_transfer')" id="card_bank_transfer">
                                    <i class="fas fa-university fa-2x mb-2 text-primary"></i>
                                    <div class="fw-bold">Bank Transfer</div>
                                </div>
                            </div>
                            <!-- Cash -->
                            <div class="col-6">
                                <div class="card card-radio p-3 text-center h-100" onclick="selectMethod('cash')" id="card_cash">
                                    <i class="fas fa-money-bill-wave fa-2x mb-2 text-success"></i>
                                    <div class="fw-bold">Cash</div>
                                </div>
                            </div>
                            <!-- Card -->
                            <div class="col-6">
                                <div class="card card-radio p-3 text-center h-100" onclick="selectMethod('card')" id="card_card">
                                    <i class="fas fa-credit-card fa-2x mb-2 text-info"></i>
                                    <div class="fw-bold">Card</div>
                                </div>
                            </div>
                            <!-- Other -->
                            <div class="col-6">
                                <div class="card card-radio p-3 text-center h-100" onclick="selectMethod('other')" id="card_other">
                                    <i class="fas fa-ellipsis-h fa-2x mb-2 text-secondary"></i>
                                    <div class="fw-bold">Other</div>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic Details Section -->
                        <div id="methodDetailsArea">
                            <!-- Bank Details (For Bank Transfer & Card) -->
                            <div id="bankDetails" style="display: none;">
                                <div class="alert alert-light border shadow-sm">
                                    <h6 class="mb-3 fw-bold text-primary"><i class="fas fa-info-circle me-2"></i>Payment Details</h6>
                                    
                                    <!-- Account Name -->
                                    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                                        <span class="text-muted small">Account Name</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold me-2"><?php echo $bank_account_name; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyText('<?php echo $bank_account_name; ?>')">Copy</button>
                                        </div>
                                    </div>
                                    
                                    <!-- Account Number -->
                                    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                                        <span class="text-muted small">Account Number</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold me-2"><?php echo $bank_account_number; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyText('<?php echo $bank_account_number; ?>')">Copy</button>
                                        </div>
                                    </div>
                                    
                                    <!-- Sort Code -->
                                    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                                        <span class="text-muted small">Sort Code</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold me-2"><?php echo $bank_sort_code; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyText('<?php echo $bank_sort_code; ?>')">Copy</button>
                                        </div>
                                    </div>
                                    
                                    <!-- Reference -->
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted small">Reference</span>
                                        <div class="d-flex align-items-center">
                                            <span class="fw-bold text-primary me-2"><?php echo $bank_reference_label; ?></span>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="copyText('<?php echo $bank_reference_label; ?>')">Copy</button>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 small text-muted fst-italic" id="cardNote" style="display:none;">
                                        Note: Please use these bank details to make a card transfer if your banking app supports it.
                                    </div>
                                </div>
                            </div>

                            <!-- Cash Details -->
                            <div id="cashDetails" style="display: none;">
                                <div class="alert alert-success shadow-sm">
                                    <h6 class="alert-heading fw-bold mb-3"><i class="fas fa-hand-holding-usd me-2"></i>Cash Payment</h6>
                                    
                                    <?php if ($assigned_rep): ?>
                                        <!-- Scenario A: Assigned Rep -->
                                        <p class="mb-2">Please pay to your assigned representative:</p>
                                        <div class="card bg-white border-0 shadow-sm mb-3">
                                            <div class="card-body">
                                                <h5 class="card-title fw-bold text-success"><?php echo htmlspecialchars($assigned_rep['name']); ?></h5>
                                                <p class="card-text mb-1"><i class="fas fa-church me-2 text-muted"></i><?php echo htmlspecialchars($assigned_rep['church_name']); ?> (<?php echo htmlspecialchars($assigned_rep['city']); ?>)</p>
                                                <p class="card-text mb-0">
                                                    <i class="fas fa-phone me-2 text-muted"></i>
                                                    <a href="tel:<?php echo htmlspecialchars($assigned_rep['phone']); ?>" class="fw-bold text-decoration-none"><?php echo htmlspecialchars($assigned_rep['phone']); ?></a>
                                                </p>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Scenario B: No Rep - Find One -->
                                        <p class="mb-3">You are not assigned to a representative yet. Please find one near you to make a cash payment.</p>
                                        
                                        <div class="rep-finder-container bg-white border rounded p-3">
                                            <div class="mb-2">
                                                <label class="form-label small fw-bold text-muted text-uppercase">1. Select City</label>
                                                <select class="form-select form-select-sm" id="finderCity" onchange="loadChurches(this.value)">
                                                    <option value="">-- Choose City --</option>
                                                </select>
                                            </div>
                                            <div class="mb-2" id="divChurch" style="display:none;">
                                                <label class="form-label small fw-bold text-muted text-uppercase">2. Select Church</label>
                                                <select class="form-select form-select-sm" id="finderChurch" onchange="loadReps(this.value)">
                                                    <option value="">-- Choose Church --</option>
                                                </select>
                                            </div>
                                            <div class="mb-3" id="divRep" style="display:none;">
                                                <label class="form-label small fw-bold text-muted text-uppercase">3. Select Representative</label>
                                                <select class="form-select form-select-sm" id="finderRep">
                                                    <option value="">-- Choose Representative --</option>
                                                </select>
                                            </div>
                                            <button type="button" class="btn btn-success w-100 btn-sm fw-bold" id="btnAssignRep" onclick="assignRepresentative()" disabled>
                                                <i class="fas fa-check me-1"></i> Assign & View Contact Info
                                            </button>
                                        </div>
                                        <div id="newRepDetails" class="mt-3" style="display:none;"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Other Details -->
                            <div id="otherDetails" style="display: none;">
                                <div class="alert alert-secondary">
                                    <i class="fas fa-info-circle me-2"></i>Please provide details in the notes section on the next step.
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">Back</button>
                            <button type="button" class="btn btn-primary px-4" id="btnMethodNext" disabled onclick="validateStep3()">Next: Confirm</button>
                        </div>
                    </div>

                    <!-- Step 4: Confirmation -->
                    <div class="wizard-step" id="step4">
                        <h5 class="mb-3">Review & Confirm</h5>
                        
                        <div class="card bg-light border-0 mb-3">
                            <div class="card-body">
                                <div class="row mb-2">
                                    <div class="col-6 text-muted">Amount:</div>
                                    <div class="col-6 fw-bold text-end">£<span id="confirmAmount">0.00</span></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6 text-muted">Method:</div>
                                    <div class="col-6 fw-bold text-end text-capitalize"><span id="confirmMethod">-</span></div>
                                </div>
                            </div>
                        </div>

                        <!-- Optional Fields -->
                        <div class="mb-3">
                            <label class="form-label">Reference Number (Optional)</label>
                            <input type="text" name="reference" class="form-control" placeholder="e.g. Transaction ID">
                        </div>
                        
                        <div class="mb-3" id="proofUploadDiv">
                            <label class="form-label">Payment Proof (Receipt/Screenshot)</label>
                            <input type="file" name="payment_proof" class="form-control" accept="image/*,.pdf">
                            <div class="form-text">Recommended for faster approval.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="goToStep(3)">Back</button>
                            <button type="submit" class="btn btn-success px-4 fw-bold">Submit Payment</button>
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
let maxBalance = <?php echo $donor['balance']; ?>;
let assignedRep = <?php echo json_encode($assigned_rep ? true : false); ?>;

// --- Navigation ---
function goToStep(step) {
    // Hide all
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step-indicator').forEach(el => {
        el.classList.remove('active');
        if(parseInt(el.id.replace('ind','')) < step) el.classList.add('completed');
        else el.classList.remove('completed');
    });

    // Show target
    document.getElementById('step' + step).classList.add('active');
    document.getElementById('ind' + step).classList.add('active');
    currentStep = step;
    
    // Init logic for specific steps
    // if(step === 3) checkCashLogic(); // Logic moved to selection
}

// --- Step 1 Logic ---
function selectPlanAmount(amount) {
    selectedAmount = amount;
    // Auto-select relevant pledge if possible (logic simplified: select first active)
    document.getElementById('step2_amount').value = amount.toFixed(2);
    goToStep(2); // Review amount/pledge
    validateStep2(); // Auto-validate if simple
    goToStep(3); // Skip to method
}

// --- Step 2 Logic ---
function updateMaxAmount(radio) {
    const rem = parseFloat(radio.getAttribute('data-remaining'));
    document.getElementById('maxAmountDisplay').textContent = rem.toFixed(2);
    document.getElementById('step2_amount').value = rem.toFixed(2); // Auto-fill
}

function validateStep2() {
    const amt = parseFloat(document.getElementById('step2_amount').value);
    if(!amt || amt <= 0) { alert('Please enter a valid amount'); return; }
    if(amt > maxBalance) { alert('Amount exceeds your total balance'); return; }
    
    selectedAmount = amt;
    const pledgeRadio = document.querySelector('input[name="step2_pledge"]:checked');
    selectedPledgeId = pledgeRadio ? pledgeRadio.value : 0;
    
    goToStep(3);
}

// --- Step 3 Logic ---
function selectMethod(method) {
    selectedMethod = method;
    
    // UI Highlight
    document.querySelectorAll('.card-radio').forEach(el => el.classList.remove('selected'));
    document.getElementById('card_' + method).classList.add('selected');
    
    // Hide all details first
    document.getElementById('bankDetails').style.display = 'none';
    document.getElementById('cashDetails').style.display = 'none';
    document.getElementById('otherDetails').style.display = 'none';
    document.getElementById('cardNote').style.display = 'none';
    
    // Enable/Disable Next based on method
    let canProceed = true;
    
    if(method === 'bank_transfer') {
        document.getElementById('bankDetails').style.display = 'block';
    } else if (method === 'card') {
        document.getElementById('bankDetails').style.display = 'block';
        document.getElementById('cardNote').style.display = 'block';
    } else if (method === 'cash') {
        document.getElementById('cashDetails').style.display = 'block';
        // Only proceed if assigned rep exists
        if (!assignedRep) {
            canProceed = false;
            loadCities(); // Load finder
        }
    } else {
        document.getElementById('otherDetails').style.display = 'block';
    }
    
    document.getElementById('btnMethodNext').disabled = !canProceed;
}

function validateStep3() {
    if(!selectedMethod) return;
    
    // Populate Confirm Screen
    document.getElementById('confirmAmount').textContent = selectedAmount.toFixed(2);
    document.getElementById('confirmMethod').textContent = selectedMethod.replace('_', ' ');
    document.getElementById('proofUploadDiv').style.display = (selectedMethod === 'cash' ? 'none' : 'block');
    
    // Populate Hidden Inputs
    document.getElementById('finalAmount').value = selectedAmount;
    document.getElementById('finalPledgeId').value = selectedPledgeId;
    document.getElementById('finalMethod').value = selectedMethod;
    
    goToStep(4);
}

// --- Cash Representative Finder Logic ---
function loadCities() {
    const sel = document.getElementById('finderCity');
    if(sel.options.length > 1) return; // Already loaded
    
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
    sel.innerHTML = '<option value="">-- Choose Church --</option>';
    document.getElementById('divChurch').style.display = city ? 'block' : 'none';
    document.getElementById('divRep').style.display = 'none';
    document.getElementById('btnAssignRep').disabled = true;
    
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
    sel.innerHTML = '<option value="">-- Choose Representative --</option>';
    document.getElementById('divRep').style.display = churchId ? 'block' : 'none';
    document.getElementById('btnAssignRep').disabled = true;
    
    if(!churchId) return;
    
    fetch(`api/location-data.php?action=get_representatives&church_id=${churchId}`)
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                d.representatives.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.textContent = r.name + ' (' + r.role + ')';
                    // Store data for display
                    opt.setAttribute('data-phone', r.phone);
                    opt.setAttribute('data-name', r.name);
                    sel.appendChild(opt);
                });
            }
        });
        
    sel.onchange = function() {
        document.getElementById('btnAssignRep').disabled = !this.value;
    }
}

function assignRepresentative() {
    const repId = document.getElementById('finderRep').value;
    const churchId = document.getElementById('finderChurch').value;
    const btn = document.getElementById('btnAssignRep');
    
    // Get display details before saving
    const sel = document.getElementById('finderRep');
    const opt = sel.options[sel.selectedIndex];
    const name = opt.getAttribute('data-name');
    const phone = opt.getAttribute('data-phone');
    
    btn.disabled = true;
    btn.textContent = 'Assigning...';
    
    const fd = new FormData();
    fd.append('representative_id', repId);
    fd.append('church_id', churchId);
    
    fetch('api/location-data.php?action=assign_rep', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if(d.success) {
                // Hide finder, show details
                document.querySelector('.rep-finder-container').style.display = 'none';
                const det = document.getElementById('newRepDetails');
                det.style.display = 'block';
                det.innerHTML = `
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-2"></i><strong>Assigned!</strong><br>
                        Please contact <strong>${name}</strong> to arrange payment.<br>
                        <i class="fas fa-phone me-2"></i><a href="tel:${phone}">${phone}</a>
                    </div>
                `;
                // Update global state and enable Next
                assignedRep = true;
                document.getElementById('btnMethodNext').disabled = false;
            } else {
                alert('Error: ' + d.message);
                btn.disabled = false;
                btn.textContent = 'Assign & View Contact Info';
            }
        });
}

// Helper: Copy Text
function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => alert('Copied to clipboard!'));
    } else {
        // Fallback
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand("Copy");
        textArea.remove();
        alert('Copied to clipboard!');
    }
}

</script>
</body>
</html>
