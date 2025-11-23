<?php
/**
 * Donor Portal - Make a Payment
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
$current_donor = $donor;

$success_message = '';
$error_message = '';

// Calculate amount due
$amount_due = $donor['balance'] > 0 ? $donor['balance'] : 0;
if ($donor['has_active_plan'] && $donor['active_payment_plan_id'] && $db_connection_ok) {
    try {
        $plan_stmt = $db->prepare("
            SELECT monthly_amount, next_payment_due
            FROM donor_payment_plans
            WHERE id = ? AND donor_id = ? AND status = 'active'
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $donor['active_payment_plan_id'], $donor['id']);
        $plan_stmt->execute();
        $plan_result = $plan_stmt->get_result();
        $plan = $plan_result->fetch_assoc();
        if ($plan && $plan['monthly_amount']) {
            // If there's a payment plan, suggest the monthly amount
            $amount_due = min($plan['monthly_amount'], $donor['balance']);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Bank transfer details (static)
$bank_account_name   = 'LMKATH';
$bank_account_number = '85455687';
$bank_sort_code      = '53-70-44';

// Build suggested bank transfer reference: FirstName + 4‑digit code from pledge notes (if available)
$bank_reference_digits = '';
$bank_reference_label  = '';

if ($db_connection_ok) {
    try {
        // Only attempt lookup if pledges table exists
        $pledges_table_exists = $db->query("SHOW TABLES LIKE 'pledges'")->num_rows > 0;
        if ($pledges_table_exists) {
            // Prefer donor_id if column exists, otherwise fall back to donor_phone
            $has_donor_id_col = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'")->num_rows > 0;

            if ($has_donor_id_col) {
                $ref_stmt = $db->prepare("
                    SELECT notes 
                    FROM pledges 
                    WHERE donor_id = ? AND status = 'approved'
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ");
                $ref_stmt->bind_param('i', $donor['id']);
            } else {
                $ref_stmt = $db->prepare("
                    SELECT notes 
                    FROM pledges 
                    WHERE donor_phone = ? AND status = 'approved'
                    ORDER BY created_at DESC, id DESC
                    LIMIT 1
                ");
                $ref_stmt->bind_param('s', $donor['phone']);
            }

            if ($ref_stmt && $ref_stmt->execute()) {
                $ref_result = $ref_stmt->get_result();
                $row = $ref_result->fetch_assoc();
                if ($row && !empty($row['notes'])) {
                    // Extract digits from notes (e.g. 4‑digit code)
                    $digits_only = preg_replace('/\D+/', '', (string)$row['notes']);
                    if ($digits_only !== '') {
                        // Use last 4 digits where possible
                        $bank_reference_digits = strlen($digits_only) >= 4
                            ? substr($digits_only, -4)
                            : $digits_only;
                    }
                }
            }
            if ($ref_stmt) {
                $ref_stmt->close();
            }
        }
    } catch (Exception $e) {
        // If anything fails, silently fall back to name‑only reference
        error_log('Bank reference lookup failed in donor make-payment: ' . $e->getMessage());
    }
}

// Derive first name from donor name
$reference_name_part = '';
if (!empty($donor['name'])) {
    $name_parts = preg_split('/\s+/', trim((string)$donor['name']));
    if (!empty($name_parts[0])) {
        $reference_name_part = $name_parts[0];
    }
}

if ($reference_name_part !== '' && $bank_reference_digits !== '') {
    $bank_reference_label = $reference_name_part . $bank_reference_digits;
} elseif ($reference_name_part !== '') {
    $bank_reference_label = $reference_name_part;
} elseif ($bank_reference_digits !== '') {
    $bank_reference_label = $bank_reference_digits;
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
    verify_csrf();
    
    $payment_amount = (float)($_POST['payment_amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if ($payment_amount <= 0) {
        $error_message = 'Please enter a valid payment amount.';
    } elseif ($payment_amount > $donor['balance']) {
        $error_message = 'Payment amount cannot exceed your remaining balance of £' . number_format($donor['balance'], 2) . '.';
    } elseif (!in_array($payment_method, ['cash', 'bank_transfer', 'card', 'other'])) {
        $error_message = 'Please select a valid payment method.';
    } else {
        // Create pending payment record
        if ($db_connection_ok) {
            try {
                $db->begin_transaction();
                
                $insert_stmt = $db->prepare("
                    INSERT INTO payments (
                        donor_id, donor_name, donor_phone, 
                        amount, method, reference, notes, 
                        status, source, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'donor_portal', NOW())
                ");
                $insert_stmt->bind_param(
                    'issdssss',
                    $donor['id'],
                    $donor['name'],
                    $donor['phone'],
                    $payment_amount,
                    $payment_method,
                    $reference,
                    $notes
                );
                $insert_stmt->execute();
                $payment_id = $db->insert_id;
                
                // Audit log
                $audit_data = json_encode([
                    'payment_id' => $payment_id,
                    'amount' => $payment_amount,
                    'method' => $payment_method,
                    'reference' => $reference,
                    'donor_id' => $donor['id'],
                    'donor_name' => $donor['name']
                ], JSON_UNESCAPED_SLASHES);
                $audit_stmt = $db->prepare("
                    INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) 
                    VALUES(?, 'payment', ?, 'create_pending', ?, 'donor_portal')
                ");
                $user_id = 0; // System/Donor portal
                $audit_stmt->bind_param('iis', $user_id, $payment_id, $audit_data);
                $audit_stmt->execute();
                
                $db->commit();
                
                $success_message = 'Payment submitted successfully! Your payment of £' . number_format($payment_amount, 2) . ' is pending approval. You will receive a confirmation once it\'s been processed.';
                
                // Refresh donor data
                $refresh_stmt = $db->prepare("
                    SELECT id, name, phone, total_pledged, total_paid, balance, 
                           has_active_plan, active_payment_plan_id, plan_monthly_amount,
                           plan_duration_months, plan_start_date, plan_next_due_date,
                           payment_status, preferred_payment_method, preferred_language
                    FROM donors 
                    WHERE id = ?
                    LIMIT 1
                ");
                $refresh_stmt->bind_param('i', $donor['id']);
                $refresh_stmt->execute();
                $refresh_result = $refresh_stmt->get_result();
                $updated_donor = $refresh_result->fetch_assoc();
                if ($updated_donor) {
                    $_SESSION['donor'] = $updated_donor;
                    $donor = $updated_donor;
                }
            } catch (Exception $e) {
                $db->rollback();
                $error_message = 'An error occurred while submitting your payment. Please try again or contact support.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
                <div class="page-header mb-3">
                    <h1 class="page-title">Make a Payment</h1>
                </div>

                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3 g-lg-4">
                    <!-- Payment Summary -->
                    <div class="col-lg-4">
                        <div class="card sticky-top" style="top: 100px;">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-info-circle text-primary"></i>Payment Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3 pb-3 border-bottom">
                                    <small class="text-muted d-block">Total Pledged</small>
                                    <h4 class="mb-0">£<?php echo number_format($donor['total_pledged'], 2); ?></h4>
                                </div>
                                <div class="mb-3 pb-3 border-bottom">
                                    <small class="text-muted d-block">Total Paid</small>
                                    <h4 class="mb-0 text-success">£<?php echo number_format($donor['total_paid'], 2); ?></h4>
                                </div>
                                <div class="mb-3">
                                    <small class="text-muted d-block">Remaining Balance</small>
                                    <h4 class="mb-0 text-<?php echo $donor['balance'] > 0 ? 'warning' : 'secondary'; ?>">
                                        £<?php echo number_format($donor['balance'], 2); ?>
                                    </h4>
                                </div>
                                <?php if ($donor['has_active_plan']): ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-calendar-alt me-2"></i>
                                        <strong>Monthly Amount:</strong> £<?php echo number_format($amount_due, 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Form -->
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">
                                    <i class="fas fa-credit-card text-primary"></i>Payment Details
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if ($donor['balance'] <= 0): ?>
                                    <div class="alert alert-success text-center py-5">
                                        <i class="fas fa-check-circle fa-3x mb-3"></i>
                                        <h5>No Payment Due</h5>
                                        <p class="mb-0">You have completed all your payments!</p>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" id="paymentForm">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="action" value="submit_payment">
                                        
                                        <!-- Payment Amount -->
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-pound-sign me-2"></i>Payment Amount <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group input-group-lg">
                                                <span class="input-group-text">£</span>
                                                <input type="number" 
                                                       class="form-control" 
                                                       name="payment_amount" 
                                                       id="payment_amount"
                                                       value="<?php echo number_format($amount_due, 2, '.', ''); ?>"
                                                       min="0.01" 
                                                       max="<?php echo $donor['balance']; ?>"
                                                       step="0.01"
                                                       required>
                                            </div>
                                            <div class="form-text">
                                                <button type="button" class="btn btn-sm btn-outline-primary me-2" onclick="setAmount(<?php echo $amount_due; ?>)">
                                                    Pay Full Amount (£<?php echo number_format($donor['balance'], 2); ?>)
                                                </button>
                                                <?php if ($donor['has_active_plan'] && $amount_due < $donor['balance']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setAmount(<?php echo $amount_due; ?>)">
                                                        Pay Monthly Amount (£<?php echo number_format($amount_due, 2); ?>)
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Payment Method -->
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-credit-card me-2"></i>Payment Method <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select form-select-lg" name="payment_method" id="payment_method" required>
                                                <option value="">Select payment method...</option>
                                                <option value="bank_transfer" <?php echo ($donor['preferred_payment_method'] ?? '') === 'bank_transfer' ? 'selected' : ''; ?>>
                                                    Bank Transfer
                                                </option>
                                                <option value="cash">Cash</option>
                                                <option value="card">Card</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>

                                        <!-- Bank Transfer Details (shown only when Bank Transfer is selected) -->
                                        <div class="mb-4" id="bankTransferDetails" style="display: none;"
                                             data-reference="<?php echo htmlspecialchars($bank_reference_label, ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="alert alert-secondary mb-0 alert-persistent">
                                                <h6 class="alert-heading mb-3">
                                                    <i class="fas fa-university me-2"></i>Bank Transfer Details
                                                </h6>

                                                <!-- Account Name -->
                                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                                                    <div class="small text-muted">Account Name</div>
                                                    <div class="d-flex align-items-center mt-1 mt-sm-0">
                                                        <strong class="me-2" id="bankAccountName"><?php echo htmlspecialchars($bank_account_name); ?></strong>
                                                        <button type="button"
                                                                class="btn btn-sm btn-link p-0 copy-btn"
                                                                data-copy-value="<?php echo htmlspecialchars($bank_account_name, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-bs-toggle="tooltip"
                                                                title="Copy account name">
                                                            <i class="far fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Account Number -->
                                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                                                    <div class="small text-muted">Account Number</div>
                                                    <div class="d-flex align-items-center mt-1 mt-sm-0">
                                                        <strong class="me-2" id="bankAccountNumber"><?php echo htmlspecialchars($bank_account_number); ?></strong>
                                                        <button type="button"
                                                                class="btn btn-sm btn-link p-0 copy-btn"
                                                                data-copy-value="<?php echo htmlspecialchars($bank_account_number, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-bs-toggle="tooltip"
                                                                title="Copy account number">
                                                            <i class="far fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Sort Code -->
                                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                                                    <div class="small text-muted">Sort Code</div>
                                                    <div class="d-flex align-items-center mt-1 mt-sm-0">
                                                        <strong class="me-2" id="bankSortCode"><?php echo htmlspecialchars($bank_sort_code); ?></strong>
                                                        <button type="button"
                                                                class="btn btn-sm btn-link p-0 copy-btn"
                                                                data-copy-value="<?php echo htmlspecialchars($bank_sort_code, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-bs-toggle="tooltip"
                                                                title="Copy sort code">
                                                            <i class="far fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Recommended Reference -->
                                                <?php if ($bank_reference_label !== ''): ?>
                                                <div class="border-top pt-3 mt-2">
                                                    <small class="text-muted d-block mb-1">
                                                        When making a transfer, <strong>please use this reference</strong>:
                                                    </small>
                                                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                                                        <div class="fw-bold text-primary" id="bankReferenceText">
                                                            <?php echo htmlspecialchars($bank_reference_label); ?>
                                                        </div>
                                                        <button type="button"
                                                                class="btn btn-sm btn-link p-0 copy-btn mt-1 mt-sm-0"
                                                                data-copy-value="<?php echo htmlspecialchars($bank_reference_label, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-bs-toggle="tooltip"
                                                                title="Copy reference">
                                                            <i class="far fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <?php else: ?>
                                                <div class="border-top pt-3 mt-2">
                                                    <small class="text-muted">
                                                        When making a transfer, please use your <strong>first name</strong> as the payment reference.
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Reference Number -->
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-hashtag me-2"></i>Reference Number
                                            </label>
                                            <input type="text" 
                                                   class="form-control form-control-lg" 
                                                   name="reference" 
                                                   placeholder="Transaction reference, receipt number, etc. (optional)">
                                            <div class="form-text">Include a reference number if available (transaction ID, receipt number, etc.)</div>
                                        </div>

                                        <!-- Notes -->
                                        <div class="mb-4">
                                            <label class="form-label fw-bold">
                                                <i class="fas fa-sticky-note me-2"></i>Additional Notes
                                            </label>
                                            <textarea class="form-control" 
                                                      name="notes" 
                                                      rows="3" 
                                                      placeholder="Any additional information about this payment (optional)"></textarea>
                                        </div>

                                        <!-- Payment Instructions -->
                                        <div class="alert alert-info">
                                            <h6 class="alert-heading">
                                                <i class="fas fa-info-circle me-2"></i>Payment Instructions
                                            </h6>
                                            <p class="mb-0">
                                                After submitting this form, your payment will be marked as <strong>pending</strong> and sent for approval. 
                                                Once approved, your balance will be updated automatically. You'll receive a confirmation when your payment is processed.
                                            </p>
                                        </div>

                                        <!-- Submit Button -->
                                        <div class="d-grid gap-2">
                                            <button type="submit" class="btn btn-success btn-lg">
                                                <i class="fas fa-paper-plane me-2"></i>Submit Payment
                                            </button>
                                            <a href="<?php echo htmlspecialchars(url_for('donor/index.php')); ?>" class="btn btn-outline-secondary">
                                                <i class="fas fa-arrow-left me-2"></i>Cancel
                                            </a>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
<script>
function setAmount(amount) {
    document.getElementById('payment_amount').value = amount.toFixed(2);
}

// Page-specific behaviour for Make Payment
document.addEventListener('DOMContentLoaded', function () {
    const methodSelect = document.getElementById('payment_method');
    const bankDetails  = document.getElementById('bankTransferDetails');
    const referenceInput = document.querySelector('input[name="reference"]');

    function updateBankDetailsVisibility() {
        if (!methodSelect || !bankDetails) return;
        const isBank = methodSelect.value === 'bank_transfer';
        bankDetails.style.display = isBank ? 'block' : 'none';

        // If switching to bank transfer and reference is empty, pre-fill with suggested reference
        if (isBank && referenceInput && referenceInput.value.trim() === '') {
            const suggestedRef = bankDetails.getAttribute('data-reference') || '';
            if (suggestedRef !== '') {
                referenceInput.value = suggestedRef;
            }
        }
    }

    if (methodSelect && bankDetails) {
        methodSelect.addEventListener('change', updateBankDetailsVisibility);
        // Run once on load to handle pre-selected method
        updateBankDetailsVisibility();
    }

    // Copy-to-clipboard buttons
    document.querySelectorAll('.copy-btn[data-copy-value]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const value = btn.getAttribute('data-copy-value') || '';
            if (!value) return;

            function showCopiedTooltip() {
                try {
                    // If Bootstrap tooltip is enabled, temporarily change title
                    const originalTitle = btn.getAttribute('data-bs-original-title') || btn.getAttribute('title') || 'Copy';
                    btn.setAttribute('data-bs-original-title', 'Copied!');
                    btn.setAttribute('title', 'Copied!');
                    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                        const tooltip = bootstrap.Tooltip.getInstance(btn) || new bootstrap.Tooltip(btn);
                        tooltip.show();
                        setTimeout(function () {
                            tooltip.hide();
                            btn.setAttribute('data-bs-original-title', originalTitle);
                            btn.setAttribute('title', originalTitle);
                        }, 1200);
                    }
                } catch (e) {
                    // Silent fail if tooltips not available
                }
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(showCopiedTooltip).catch(function () {
                    showCopiedTooltip();
                });
            } else {
                // Fallback for older browsers
                const tempInput = document.createElement('input');
                tempInput.type = 'text';
                tempInput.value = value;
                document.body.appendChild(tempInput);
                tempInput.select();
                try {
                    document.execCommand('copy');
                } catch (e) {
                    // ignore
                }
                document.body.removeChild(tempInput);
                showCopiedTooltip();
            }
        });
    });
});
</script>
</body>
</html>

