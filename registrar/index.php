<?php
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';

// Resiliently load DB data. This must come after auth/csrf but before using $db.
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';
require_once __DIR__ . '/../shared/GridAllocationBatchTracker.php';

// Check if logged in and has registrar or admin role
require_login();
$user = current_user();
$role = strtolower(trim((string)($user['role'] ?? '')));
if (!in_array($role, ['registrar', 'admin'], true)) {
    header('Location: ../admin/error/403.php');
    exit;
}

// Use pre-loaded data, provide defaults if not available
$currency = $settings['currency_code'] ?? 'GBP';
$pkgRows = [];
if ($db_connection_ok) {
    try {
        $pkg_table_exists = $db->query("SHOW TABLES LIKE 'donation_packages'")->num_rows > 0;
        if ($pkg_table_exists) {
            $pkgRows = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
        } else {
            if (empty($db_error_message)) $db_error_message = '`donation_packages` table not found.';
        }
    } catch(Exception $e) {
        if (empty($db_error_message)) $db_error_message = 'Could not load donation packages.';
    }
}

$pkgByLabel = [];
foreach ($pkgRows as $r) { $pkgByLabel[$r['label']] = $r; }
$pkgOne     = $pkgByLabel['1 m²']   ?? null;
$pkgHalf    = $pkgByLabel['1/2 m²'] ?? null;
$pkgQuarter = $pkgByLabel['1/4 m²'] ?? null;
$pkgCustom  = $pkgByLabel['Custom'] ?? null;

// Get today's stats for current user (single-day totals for fundraising page)
$userId = (int)(current_user()['id'] ?? 0);
$todayPledges = ['count' => 0, 'total' => 0];
$todayPayments = ['count' => 0, 'total' => 0];

if ($db_connection_ok) {
    try {
        $pledges_table_exists = $db->query("SHOW TABLES LIKE 'pledges'")->num_rows > 0;
        $payments_table_exists = $db->query("SHOW TABLES LIKE 'payments'")->num_rows > 0;

        if ($pledges_table_exists) {
// Pledges created today by this registrar
$stmt = $db->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total FROM pledges WHERE created_by_user_id = ? AND DATE(created_at) = CURDATE()");
$stmt->bind_param("i", $userId);
$stmt->execute();
$todayPledges = $stmt->get_result()->fetch_assoc() ?: $todayPledges;
$stmt->close();
        } else {
            if (empty($db_error_message)) $db_error_message = '`pledges` table not found.';
        }

        if ($payments_table_exists) {
// Payments received today by this registrar
$stmt = $db->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(amount),0) AS total FROM payments WHERE received_by_user_id = ? AND DATE(received_at) = CURDATE()");
$stmt->bind_param("i", $userId);
$stmt->execute();
$todayPayments = $stmt->get_result()->fetch_assoc() ?: $todayPayments;
$stmt->close();
        } else {
             if (empty($db_error_message)) $db_error_message = '`payments` table not found.';
        }
    } catch(Exception $e) {
        if (empty($db_error_message)) $db_error_message = 'Could not load registrar stats.';
    }
}

$todayCombined = [
	'count' => (int)($todayPledges['count'] ?? 0) + (int)($todayPayments['count'] ?? 0),
	'total' => (float)($todayPledges['total'] ?? 0) + (float)($todayPayments['total'] ?? 0),
];

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // --- Step 1: Sanitize and collect all form inputs ---
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? '')); // Tombola code (4 digits)
    $anonymous = isset($_POST['anonymous']); // will be true or false
    $anonymousFlag = $anonymous ? 1 : 0;
    $sqm_unit = (string)($_POST['pack'] ?? ''); // '1', '0.5', '0.25', 'custom'
    $custom_amount = (float)($_POST['custom_amount'] ?? 0);
    $type = (string)($_POST['type'] ?? 'pledge'); // 'pledge' or 'paid'
    $payment_method_input = trim((string)($_POST['payment_method'] ?? ''));
    $package_choice = (string)($_POST['package_choice'] ?? ''); // '1', '0.5', '0.25', 'custom'
    $client_uuid = trim((string)($_POST['client_uuid'] ?? ''));
    $additional_donation = isset($_POST['additional_donation']) && $_POST['additional_donation'] === '1';
    if ($client_uuid === '') {
        try { $client_uuid = bin2hex(random_bytes(16)); } catch (Throwable $e) { $client_uuid = uniqid('uuid_', true); }
    }

    // --- Step 2: Validate the inputs and business logic ---
    $error = '';
    if (empty($client_uuid)) {
        $error = 'A unique submission ID is required. Please refresh and try again.';
    }

    // Validation for donor details (required unless anonymous)
    if (!$anonymous) {
        if ($name === '') {
            $error = 'Full name is required.';
        } elseif ($phone === '') {
            $error = 'Phone number is required.';
        }
    }

    // Normalize and validate UK mobile phone for pledges and non-anonymous paid
    $normalizeUk = function(string $raw): string {
        $digits = preg_replace('/[^0-9+]/', '', $raw);
        if (strpos($digits, '+44') === 0) {
            $digits = '0' . substr($digits, 3);
        }
        return $digits;
    };
    if (!$error && $phone !== '') {
        $phone = $normalizeUk($phone);
        if (!preg_match('/^07\d{9}$/', $phone)) {
            $error = 'Please enter a valid UK mobile number starting with 07.';
        }
    }

    // Validate tombola: must be exactly 4 digits
    $notesDigits = preg_replace('/\D+/', '', $notes);
    if (!$error && !preg_match('/^\d{4}$/', $notesDigits)) {
        $error = 'Please enter a 4-digit tombola number.';
    }

    // Validate and normalize payment method
    $payment_method = null;
    if ($type === 'paid') {
        if ($payment_method_input === 'transfer') $payment_method_input = 'bank';
        if ($payment_method_input === 'cheque') $payment_method_input = 'other';
        $valid_methods = ['cash', 'card', 'bank', 'other'];
        if (in_array($payment_method_input, $valid_methods, true)) {
            $payment_method = $payment_method_input;
        } else {
            $error = 'Please choose a valid payment method for paid donations.';
        }
    }

    // --- Step 3: Calculate donation amount based on selection ---
    $amount = 0.0;
    $sqm_quantity = 0.0;

    // Map UI choice to package model
    $selectedPackage = null;
    if ($sqm_unit === '1') { $selectedPackage = $pkgOne; }
    elseif ($sqm_unit === '0.5') { $selectedPackage = $pkgHalf; }
    elseif ($sqm_unit === '0.25') { $selectedPackage = $pkgQuarter; }
    elseif ($sqm_unit === 'custom') { $selectedPackage = $pkgCustom; }
    else { $selectedPackage = null; }

    if ($selectedPackage) {
        if ($sqm_unit === 'custom') {
            $amount = max(0, $custom_amount);
        } else {
            $amount = (float)$selectedPackage['price'];
        }
    } else {
        $error = 'Please select a valid donation package.';
    }

    if ($amount <= 0 && !$error) {
        $error = 'Please select a valid amount greater than zero.';
    }

    // --- Step 4: If validation passes, process the database transaction ---
    if (empty($error)) {
        $db->begin_transaction();
        try {
            $createdBy = (int)(current_user()['id'] ?? 0);

            // Donor data normalization
            $donorName  = $anonymous ? 'Anonymous' : $name;
            $donorPhone = $phone;
            $donorEmail = null; // Email field removed

            // Use tombola code as-is (exactly 4 digits)
            $final_notes = $notesDigits;

            if ($type === 'paid') {
                // Standalone PAYMENT (no pledge row). Status defaults to pending in DB.
                // Insert with explicit pending status for clarity; link selected package if available
                // Duplicate check for payments too: block if any existing pledge/payment is pending or approved for same phone
                if ($donorPhone && !$additional_donation) {
                    $q1 = $db->prepare("SELECT id FROM pledges WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
                    $q1->bind_param('s', $donorPhone);
                    $q1->execute();
                    $existsPledge = (bool)$q1->get_result()->fetch_assoc();
                    $q1->close();
                    $q2 = $db->prepare("SELECT id FROM payments WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
                    $q2->bind_param('s', $donorPhone);
                    $q2->execute();
                    $existsPayment = (bool)$q2->get_result()->fetch_assoc();
                    $q2->close();
                    if ($existsPledge || $existsPayment) {
                        throw new Exception('This donor already has a registered pledge/payment. Please review existing records instead of creating duplicates.');
                    }
                }
                $sql = "INSERT INTO payments (donor_name, donor_phone, donor_email, amount, method, package_id, reference, status, received_by_user_id, received_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
                $pmt = $db->prepare($sql);
                $reference = $final_notes; // store notes as reference so admin can see context
                $packageId = (int)($selectedPackage['id'] ?? 0);
                $packageIdNullable = $packageId > 0 ? $packageId : null;
                $pmt->bind_param('sssdsisi', $donorName, $donorPhone, $donorEmail, $amount, $payment_method, $packageIdNullable, $reference, $createdBy);
                $pmt->execute();
                if ($pmt->affected_rows === 0) { throw new Exception('Failed to record payment.'); }
                $entityId = $db->insert_id;

                // Audit
                $afterJson = json_encode(['amount'=>$amount,'method'=>$payment_method,'donor'=>$donorName,'status'=>'pending']);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment', ?, 'create_pending', ?, 'registrar')");
                $log->bind_param('iis', $createdBy, $entityId, $afterJson);
                $log->execute();
            } else {
                // PLEDGE path. Guard duplicate UUID.
                $stmt = $db->prepare("SELECT id FROM pledges WHERE client_uuid = ?");
                $stmt->bind_param("s", $client_uuid);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()) {
                    throw new Exception("Duplicate submission detected. Please do not click submit twice.");
                }
                $stmt->close();

                $status = 'pending';
                $typeNormalized = 'pledge';
                // Duplicate check: avoid multiple active pledges/payments for the same phone
                if ($donorPhone && !$additional_donation) {
                    // Check pledges with status pending or approved
                    $dup = $db->prepare("SELECT id FROM pledges WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
                    $dup->bind_param('s', $donorPhone);
                    $dup->execute();
                    $hasPledge = (bool)$dup->get_result()->fetch_assoc();
                    $dup->close();

                    // Check payments with status pending or approved (to avoid double registration when already paid as donation)
                    $hasPayment = false;
                    $dp = $db->prepare("SELECT id FROM payments WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
                    $dp->bind_param('s', $donorPhone);
                    $dp->execute();
                    $hasPayment = (bool)$dp->get_result()->fetch_assoc();
                    $dp->close();

                    if ($hasPledge || $hasPayment) {
                        throw new Exception('This donor already has a registered pledge/payment. Please review existing records instead of creating duplicates.');
                    }
                }

                $stmt = $db->prepare("
                    INSERT INTO pledges (
                      donor_name, donor_phone, donor_email, source, anonymous,
                      amount, type, status, notes, client_uuid, created_by_user_id, package_id
                    ) VALUES (?, ?, ?, 'volunteer', ?, ?, 'pledge', ?, ?, ?, ?, ?)
                ");
                $packageId = (int)($selectedPackage['id'] ?? 0);
                $packageIdNullable = $packageId > 0 ? $packageId : null;
                $stmt->bind_param(
                    'sssidsssii',
                    $donorName, $donorPhone, $donorEmail, $anonymousFlag,
                    $amount, $status, $final_notes, $client_uuid, $createdBy, $packageIdNullable
                );
                $stmt->execute();
                if ($stmt->affected_rows === 0) { throw new Exception('Failed to insert pledge.'); }
                $entityId = $db->insert_id;

                // Create allocation batch record if this is an additional donation
                if ($additional_donation && $donorPhone) {
                    $batchTracker = new GridAllocationBatchTracker($db);
                    
                    // Normalize phone
                    $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                        $normalized_phone = '0' . substr($normalized_phone, 2);
                    }
                    
                    // Find donor ID
                    $donorId = null;
                    if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                        $findDonor = $db->prepare("SELECT id FROM donors WHERE phone = ? LIMIT 1");
                        $findDonor->bind_param('s', $normalized_phone);
                        $findDonor->execute();
                        $donorRecord = $findDonor->get_result()->fetch_assoc();
                        $findDonor->close();
                        if ($donorRecord) {
                            $donorId = (int)$donorRecord['id'];
                        }
                    }
                    
                    // Find original approved pledge
                    $originalPledgeId = null;
                    $originalAmount = 0.00;
                    if ($donorId) {
                        $findOriginal = $db->prepare("
                            SELECT id, amount 
                            FROM pledges 
                            WHERE donor_id = ? AND status = 'approved' AND type = 'pledge' 
                            ORDER BY approved_at DESC, id DESC 
                            LIMIT 1
                        ");
                        $findOriginal->bind_param('i', $donorId);
                        $findOriginal->execute();
                        $originalPledge = $findOriginal->get_result()->fetch_assoc();
                        $findOriginal->close();
                        if ($originalPledge) {
                            $originalPledgeId = (int)$originalPledge['id'];
                            $originalAmount = (float)$originalPledge['amount'];
                        }
                    }
                    
                    // Create batch for additional donation
                    $batchData = [
                        'batch_type' => $originalPledgeId ? 'pledge_update' : 'new_pledge',
                        'request_type' => 'registrar',
                        'original_pledge_id' => $originalPledgeId,
                        'new_pledge_id' => $entityId,
                        'donor_id' => $donorId,
                        'donor_name' => $donorName,
                        'donor_phone' => $normalized_phone,
                        'original_amount' => $originalAmount,
                        'additional_amount' => $amount,
                        'total_amount' => $originalAmount + $amount,
                        'requested_by_user_id' => $createdBy,
                        'request_source' => 'volunteer',
                        'package_id' => $packageIdNullable,
                        'metadata' => [
                            'client_uuid' => $client_uuid,
                            'notes' => $final_notes,
                            'additional_donation' => true
                        ]
                    ];
                    $batchId = $batchTracker->createBatch($batchData);
                    if ($batchId) {
                        error_log("Registrar: Created allocation batch #{$batchId} for additional donation");
                    }
                }

                $afterJson = json_encode(['amount'=>$amount,'type'=>'pledge','anonymous'=>$anonymousFlag,'donor'=>$donorName,'status'=>'pending']);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'pledge', ?, 'create_pending', ?, 'registrar')");
                $log->bind_param('iis', $createdBy, $entityId, $afterJson);
                $log->execute();
            }

            $db->commit();
            $_SESSION['success_message'] = "Registration for {$currency} " . number_format($amount, 2) . " submitted for approval successfully!";
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $db->rollback();
            error_log("Registrar form submission error: " . $e->getMessage() . " on line " . $e->getLine());
            $error = (defined('ENVIRONMENT') && ENVIRONMENT !== 'production')
                ? ('Error saving registration: ' . $e->getMessage())
                : 'Error saving registration. Please try again or contact an administrator.';
        }
    }
}

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Registration - Registrar Panel</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/registrar.css?v=<?php echo @filemtime(__DIR__ . '/assets/registrar.css'); ?>">
</head>
<body data-user-id="<?php echo (int)($user['id'] ?? 0); ?>" data-user-type="registrar">
    <script>window.currentUserId = <?php echo (int)($user['id'] ?? 0); ?>;</script>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="app-content">
            <?php include 'includes/topbar.php'; ?>
            
            <main class="main-content">
                <?php include __DIR__ . '/../admin/includes/db_error_banner.php'; ?>
                <!-- Page Header -->

                
                <!-- Stats Cards -->

                
                <!-- Registration Form -->
                <div class="card">
                    
                    
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="registration-form needs-validation" novalidate>
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="client_uuid" value="">
                        
                        <!-- Donor Information -->
                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="fas fa-user"></i>
                                Donor Information
                            </h3>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       placeholder="Enter full name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" required
                                       placeholder="Enter phone number" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Tombola Number<span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="notes" name="notes" required
                                       min="0" max="9999" step="1" inputmode="numeric"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,4)"
                                       placeholder="Enter 4-digit tombola number"
                                       value="<?php echo htmlspecialchars($_POST['notes'] ?? ''); ?>">
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="anonymous" name="anonymous" 
                                       <?php echo isset($_POST['anonymous']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="anonymous">
                                    <i class="fas fa-user-secret me-1"></i>
                                    Make this donation anonymous
                                </label>
                            </div>
                            
                            <div class="form-check mb-3 d-none" id="additionalDonationDiv">
                                <input class="form-check-input" type="checkbox" id="additional_donation" name="additional_donation" 
                                       value="1">
                                <label class="form-check-label" for="additional_donation">
                                    <i class="fas fa-plus-circle me-1"></i>
                                    This donor wants to make another donation
                                </label>
                            </div>
                        </div>
                        
                        <!-- Amount Selection -->
                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="fas fa-pound-sign"></i>
                                Select Amount
                            </h3>
                            
                            <div class="quick-amounts">
                                <label class="quick-amount-btn" data-pack="1">
                                    <input type="radio" name="pack" value="1" class="d-none">
                                    <span class="quick-amount-value"><?php echo $currency; ?> <?php echo isset($pkgOne) ? number_format((float)$pkgOne['price'], 0) : 'N/A'; ?></span>
                                    <span class="quick-amount-label">1 Square Meter</span>
                                    <i class="fas fa-check-circle checkmark"></i>
                                </label>
                                
                                <label class="quick-amount-btn" data-pack="0.5">
                                    <input type="radio" name="pack" value="0.5" class="d-none">
                                    <span class="quick-amount-value"><?php echo $currency; ?> <?php echo isset($pkgHalf) ? number_format((float)$pkgHalf['price'], 0) : 'N/A'; ?></span>
                                    <span class="quick-amount-label">½ Square Meter</span>
                                    <i class="fas fa-check-circle checkmark"></i>
                                </label>
                                
                                <label class="quick-amount-btn" data-pack="0.25">
                                    <input type="radio" name="pack" value="0.25" class="d-none">
                                    <span class="quick-amount-value"><?php echo $currency; ?> <?php echo isset($pkgQuarter) ? number_format((float)$pkgQuarter['price'], 0) : 'N/A'; ?></span>
                                    <span class="quick-amount-label">¼ Square Meter</span>
                                    <i class="fas fa-check-circle checkmark"></i>
                                </label>
                                
                                <label class="quick-amount-btn" data-pack="custom">
                                    <input type="radio" name="pack" value="custom" class="d-none">
                                    <span class="quick-amount-value">Custom</span>
                                    <span class="quick-amount-label">Enter Amount</span>
                                    <i class="fas fa-check-circle checkmark"></i>
                                </label>
                            </div>
                            
                            <div class="mb-3 d-none" id="customAmountDiv">
                                <label for="custom_amount" class="form-label">Custom Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo $currency; ?></span>
                                    <input type="number" class="form-control" id="custom_amount" name="custom_amount" 
                                           min="1" step="0.01" placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Type -->
                        <div class="form-section half">
                            <h3 class="form-section-title">
                                <i class="fas fa-credit-card"></i>
                                Payment Type
                            </h3>
                            <div class="segmented-control">
                                <input class="form-check-input" type="radio" name="type" id="typePledge" 
                                       value="pledge" checked>
                                <label class="form-check-label" for="typePledge">
                                    <i class="fas fa-handshake me-1"></i>
                                    Promise to Pay Later
                                </label>
                                
                                <input class="form-check-input" type="radio" name="type" id="typePaid" value="paid">
                                <label class="form-check-label" for="typePaid">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Paid Now
                                </label>
                            </div>
                        </div>
                        
                        <!-- Payment Method (shown only for paid) -->
                        <div class="form-section half d-none" id="paymentMethodDiv">
                            <h3 class="form-section-title">
                                <i class="fas fa-wallet"></i>
                                Payment Method
                            </h3>
                            
                            <select class="form-select" name="payment_method" id="payment_method">
                                <option value="">Select method...</option>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-submit">
                            <i class="fas fa-save"></i>
                            Register Donation
                        </button>
                    </form>
                </div>
                
                <!-- Mobile Action Button -->
            </main>
        </div>
    </div>
    
    <!-- Repeat Donor Modal -->
    <div class="modal fade" id="repeatDonorModal" tabindex="-1" aria-labelledby="repeatDonorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning bg-opacity-10 border-bottom-warning">
                    <h5 class="modal-title" id="repeatDonorModalLabel">
                        <i class="fas fa-user-circle me-2 text-warning"></i>Returning Donor Detected
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="donorInfo" class="mb-3">
                        <p class="mb-2"><strong>Donor Name:</strong> <span id="modalDonorName">-</span></p>
                        <p class="mb-3"><strong>Phone:</strong> <span id="modalDonorPhone">-</span></p>
                    </div>
                    
                    <h6 class="mb-3 border-bottom pb-2">Previous Donations</h6>
                    <div id="previousDonationsContainer" class="mb-3" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="donationsTableBody">
                                <!-- Populated by JS -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>To continue:</strong> Check the "This donor wants to make another donation" checkbox below the anonymous option to proceed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" data-bs-dismiss="modal">
                        <i class="fas fa-check me-1"></i>Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/registrar.js?v=<?php echo @filemtime(__DIR__ . '/assets/registrar.js'); ?>"></script>
    <script>
    // Quick amount selection
    document.querySelectorAll('.quick-amount-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.quick-amount-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const pack = this.dataset.pack;
            if (pack === 'custom') {
                document.getElementById('customAmountDiv').classList.remove('d-none');
                document.getElementById('custom_amount').focus();
            } else {
                document.getElementById('customAmountDiv').classList.add('d-none');
            }
        });
    });
    
    // Payment type toggle
    document.querySelectorAll('input[name="type"]').forEach(input => {
        input.addEventListener('change', function() {
            if (this.value === 'paid') {
                document.getElementById('paymentMethodDiv').classList.remove('d-none');
                document.getElementById('payment_method').required = true;
            } else {
                document.getElementById('paymentMethodDiv').classList.add('d-none');
                document.getElementById('payment_method').required = false;
            }
        });
    });
    
    // Anonymous toggle: name and phone are optional when anonymous
    function applyAnonymousState() {
        const anonEl = document.getElementById('anonymous');
        const nameField = document.getElementById('name');
        const phoneField = document.getElementById('phone');
        if (!anonEl || !nameField || !phoneField) return;
        const isAnon = anonEl.checked;
        nameField.required = !isAnon;
        phoneField.required = !isAnon;
        nameField.placeholder = isAnon ? 'Anonymous' : 'Enter full name';
        phoneField.placeholder = isAnon ? 'Anonymous' : 'Enter phone number';
        if (isAnon) {
            nameField.classList.remove('is-valid', 'is-invalid');
            phoneField.classList.remove('is-valid', 'is-invalid');
        }
    }
    document.getElementById('anonymous').addEventListener('change', applyAnonymousState);
    applyAnonymousState(); // Run on load in case anonymous is pre-checked (e.g. form re-display)
    </script>
</body>
</html>
