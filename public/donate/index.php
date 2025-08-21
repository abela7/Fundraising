<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';

// Get settings (single row config)
$db = db();
$settings = $db->query('SELECT * FROM settings WHERE id=1')->fetch_assoc() ?: [];
$currency = $settings['currency_code'] ?? 'GBP';
$targetAmount = (float)($settings['target_amount'] ?? 30000);

// Load donation packages for UI and server-side validation
$pkgRows = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
$pkgByLabel = [];
foreach ($pkgRows as $r) { $pkgByLabel[$r['label']] = $r; }
$pkgOne     = $pkgByLabel['1 m²']   ?? null;
$pkgHalf    = $pkgByLabel['1/2 m²'] ?? null;
$pkgQuarter = $pkgByLabel['1/4 m²'] ?? null;
$pkgCustom  = $pkgByLabel['Custom'] ?? null;

// Get current fundraising stats
$counters = $db->query('SELECT * FROM counters WHERE id=1')->fetch_assoc() ?: [];
$currentTotal = (float)($counters['grand_total'] ?? 0);
$progressPercent = $targetAmount > 0 ? min(100, ($currentTotal / $targetAmount) * 100) : 0;

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // --- Step 1: Sanitize and collect all form inputs ---
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $anonymous = isset($_POST['anonymous']); // will be true or false
    $anonymousFlag = $anonymous ? 1 : 0;
    $sqm_unit = (string)($_POST['pack'] ?? ''); // '1', '0.5', '0.25', 'custom'
    $custom_amount = (float)($_POST['custom_amount'] ?? 0);
    $type = (string)($_POST['type'] ?? 'pledge'); // 'pledge' or 'paid'
    $payment_method_input = trim((string)($_POST['payment_method'] ?? ''));
    $package_choice = (string)($_POST['package_choice'] ?? ''); // '1', '0.5', '0.25', 'custom'
    $notes = trim((string)($_POST['notes'] ?? ''));
    $client_uuid = trim((string)($_POST['client_uuid'] ?? ''));

    // --- Step 2: Validate the inputs and business logic ---
    $error = '';
    if (empty($client_uuid)) {
        $error = 'A unique submission ID is required. Please refresh and try again.';
    }

    // Validation for donor details based on type and anonymity
    if ($type === 'pledge') {
        if (!$anonymous) {
            if ($name === '') $error = 'Name is required for pledges.';
            elseif ($phone === '') $error = 'Phone number is required for pledges.';
        }
    } elseif ($type === 'paid' && !$anonymous) {
        if ($name === '') $error = 'Name is required unless the donation is anonymous.';
        elseif ($phone === '') $error = 'Phone number is required unless the donation is anonymous.';
    }

    // Normalize and validate UK mobile phone for pledges and non-anonymous paid
    $normalizeUk = function(string $raw): string {
        $digits = preg_replace('/[^0-9+]/', '', $raw);
        if (strpos($digits, '+44') === 0) { $digits = '0' . substr($digits, 3); }
        return $digits;
    };

    if (!$anonymous && $phone !== '') {
        $phone = $normalizeUk($phone);
        if (!preg_match('/^07\d{9}$/', $phone)) {
            $error = 'Phone must be a valid UK mobile number (starting with 07).';
        }
    }

    // Payment method validation for paid type
    $payment_method = '';
    if ($type === 'paid') {
        $valid_methods = ['cash', 'card', 'bank', 'other'];
        if (!in_array($payment_method_input, $valid_methods, true)) {
            $error = 'Please select a valid payment method.';
        } else {
            $payment_method = $payment_method_input;
        }
    }

    // Package/amount validation
    $selectedPackage = null;
    $amount = 0.0;
    if ($sqm_unit === 'custom') {
        if ($custom_amount <= 0) {
            $error = 'Custom amount must be greater than zero.';
        } else {
            $amount = $custom_amount;
            $selectedPackage = $pkgCustom;
        }
    } else {
        // Predefined package
        $packageMap = [
            '1' => $pkgOne,
            '0.5' => $pkgHalf,
            '0.25' => $pkgQuarter
        ];
        if (!isset($packageMap[$sqm_unit]) || !$packageMap[$sqm_unit]) {
            $error = 'Please select a valid donation package.';
        } else {
            $selectedPackage = $packageMap[$sqm_unit];
            $amount = (float)$selectedPackage['price'];
        }
    }

    if ($amount <= 0) {
        $error = 'Please select a valid amount greater than zero.';
    }

    // --- Step 3: If validation passes, process the database transaction ---
    if (empty($error)) {
        $db->begin_transaction();
        try {
            $createdBy = null; // Self-pledged donations have no creator user

            // Donor data normalization
            $donorName  = ($type === 'paid' && $anonymous) ? 'Anonymous' : $name;
            $donorPhone = ($type === 'paid' && $anonymous) ? null : $phone;
            $donorEmail = ($type === 'paid' && $anonymous) ? null : $email;

            // For anonymous pledges, we still need some identifier, use "Anonymous"
            if ($type === 'pledge' && $anonymous) {
                $donorName = 'Anonymous';
                $donorPhone = null;
                $donorEmail = null;
            }

            // Notes decoration for paid
            $final_notes = $notes;
            if ($type === 'paid' && $payment_method) {
                $payment_note = 'Paid via ' . ucfirst($payment_method) . '.';
                $final_notes = $notes ? ($payment_note . "\n" . $notes) : $payment_note;
            }

            if ($type === 'paid') {
                // Standalone PAYMENT (no pledge row). Status defaults to pending in DB.
                if ($donorPhone) {
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
                        throw new Exception('This phone number already has a registered pledge/payment. Please contact support if you need to make additional donations.');
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
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment', ?, 'create_pending', ?, 'public')");
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
                if ($donorPhone) {
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
                        throw new Exception('This phone number already has a registered pledge/payment. Please contact support if you need to make additional donations.');
                    }
                }

                $stmt = $db->prepare("
                    INSERT INTO pledges (
                      donor_name, donor_phone, donor_email, source, anonymous,
                      amount, type, status, notes, client_uuid, created_by_user_id, package_id
                    ) VALUES (?, ?, ?, 'self', ?, ?, 'pledge', ?, ?, ?, ?, ?)
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

                $afterJson = json_encode(['amount'=>$amount,'type'=>'pledge','anonymous'=>$anonymousFlag,'donor'=>$donorName,'status'=>'pending']);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'pledge', ?, 'create_pending', ?, 'public')");
                $log->bind_param('iis', $createdBy, $entityId, $afterJson);
                $log->execute();
            }

            $db->commit();
            $success = "Thank you! Your " . ($type === 'pledge' ? 'pledge' : 'payment') . " of {$currency} " . number_format($amount, 2) . " has been submitted for approval. You will be notified once it's processed.";
            
            // Clear form data after successful submission
            $_POST = [];
        } catch (Exception $e) {
            $db->rollback();
            error_log("Public donation error: " . $e->getMessage() . " on line " . $e->getLine());
            $error = 'Error processing your donation. Please try again or contact support.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make a Donation - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="assets/donate.css">
</head>
<body class="public-body">
    <div class="container-fluid">
        <div class="row min-vh-100">
            <!-- Left Side - Info Panel -->
            <div class="col-lg-5 info-panel d-flex flex-column">
                <div class="info-content">
                    <div class="church-logo mb-4">
                        <i class="fas fa-church text-primary" style="font-size: 3rem;"></i>
                        <h1 class="mt-3">Church Fundraising</h1>
                    </div>
                    
                    <div class="progress-section mb-4">
                        <h3 class="text-primary mb-3">
                            <i class="fas fa-target me-2"></i>
                            Fundraising Progress
                        </h3>
                        <div class="progress mb-3" style="height: 20px;">
                            <div class="progress-bar bg-success" style="width: <?php echo number_format($progressPercent, 1); ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold"><?php echo $currency; ?> <?php echo number_format($currentTotal, 0); ?></span>
                            <span class="text-muted">of <?php echo $currency; ?> <?php echo number_format($targetAmount, 0); ?></span>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted"><?php echo number_format($progressPercent, 1); ?>% Complete</small>
                        </div>
                    </div>
                    
                    <div class="info-points">
                        <div class="info-point mb-3">
                            <i class="fas fa-shield-alt text-success me-3"></i>
                            <div>
                                <h5 class="mb-1">Secure & Safe</h5>
                                <p class="mb-0 text-muted">Your information is protected and secure.</p>
                            </div>
                        </div>
                        <div class="info-point mb-3">
                            <i class="fas fa-clock text-info me-3"></i>
                            <div>
                                <h5 class="mb-1">Quick Process</h5>
                                <p class="mb-0 text-muted">Takes less than 2 minutes to complete.</p>
                            </div>
                        </div>
                        <div class="info-point mb-3">
                            <i class="fas fa-heart text-danger me-3"></i>
                            <div>
                                <h5 class="mb-1">Making a Difference</h5>
                                <p class="mb-0 text-muted">Every contribution helps build our community.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Side - Donation Form -->
            <div class="col-lg-7 form-panel">
                <div class="form-container">
                    <div class="form-header mb-4">
                        <h2 class="text-center mb-2">
                            <i class="fas fa-hand-holding-heart text-primary me-2"></i>
                            Make a Donation
                        </h2>
                        <p class="text-center text-muted">Support our community with your generous contribution</p>
                    </div>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="donation-form" id="donationForm">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="client_uuid" value="">
                        
                        <!-- Personal Information -->
                        <div class="form-section mb-4">
                            <h4 class="form-section-title">
                                <i class="fas fa-user text-primary me-2"></i>
                                Your Information
                            </h4>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="anonymous" name="anonymous"
                                       <?php echo (isset($_POST['anonymous']) && $_POST['anonymous']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="anonymous">
                                    <i class="fas fa-user-secret me-1"></i>
                                    Make this donation anonymous
                                </label>
                            </div>
                            
                            <div id="personalInfo">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="name" class="form-label">Full Name</label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               placeholder="Enter your full name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               placeholder="07XXXXXXXXX" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email (Optional)</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           placeholder="your.email@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Message (Optional)</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2" 
                                          placeholder="Any special message or dedication..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Amount Selection -->
                        <div class="form-section mb-4">
                            <h4 class="form-section-title">
                                <i class="fas fa-pound-sign text-primary me-2"></i>
                                Choose Amount
                            </h4>
                            
                            <div class="amount-options">
                                <label class="amount-option" data-pack="1">
                                    <input type="radio" name="pack" value="1" class="d-none">
                                    <div class="amount-card">
                                        <div class="amount-value"><?php echo $currency; ?> <?php echo isset($pkgOne) ? number_format((float)$pkgOne['price'], 0) : 'N/A'; ?></div>
                                        <div class="amount-label">1 Square Meter</div>
                                        <div class="amount-description">Full square meter of our new building</div>
                                        <i class="fas fa-check-circle amount-check"></i>
                                    </div>
                                </label>
                                
                                <label class="amount-option" data-pack="0.5">
                                    <input type="radio" name="pack" value="0.5" class="d-none">
                                    <div class="amount-card">
                                        <div class="amount-value"><?php echo $currency; ?> <?php echo isset($pkgHalf) ? number_format((float)$pkgHalf['price'], 0) : 'N/A'; ?></div>
                                        <div class="amount-label">½ Square Meter</div>
                                        <div class="amount-description">Half square meter contribution</div>
                                        <i class="fas fa-check-circle amount-check"></i>
                                    </div>
                                </label>
                                
                                <label class="amount-option" data-pack="0.25">
                                    <input type="radio" name="pack" value="0.25" class="d-none">
                                    <div class="amount-card">
                                        <div class="amount-value"><?php echo $currency; ?> <?php echo isset($pkgQuarter) ? number_format((float)$pkgQuarter['price'], 0) : 'N/A'; ?></div>
                                        <div class="amount-label">¼ Square Meter</div>
                                        <div class="amount-description">Quarter square meter support</div>
                                        <i class="fas fa-check-circle amount-check"></i>
                                    </div>
                                </label>
                                
                                <label class="amount-option" data-pack="custom">
                                    <input type="radio" name="pack" value="custom" class="d-none">
                                    <div class="amount-card">
                                        <div class="amount-value">Custom</div>
                                        <div class="amount-label">Your Amount</div>
                                        <div class="amount-description">Choose your own amount</div>
                                        <i class="fas fa-check-circle amount-check"></i>
                                    </div>
                                </label>
                            </div>
                            
                            <div class="mb-3 d-none" id="customAmountDiv">
                                <label for="custom_amount" class="form-label">Enter Custom Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?php echo $currency; ?></span>
                                    <input type="number" class="form-control" id="custom_amount" name="custom_amount" 
                                           min="1" step="0.01" placeholder="0.00" value="<?php echo htmlspecialchars($_POST['custom_amount'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Type -->
                        <div class="form-section mb-4">
                            <h4 class="form-section-title">
                                <i class="fas fa-credit-card text-primary me-2"></i>
                                Payment Type
                            </h4>
                            
                            <div class="payment-type-options">
                                <label class="payment-type-option">
                                    <input type="radio" name="type" value="pledge" class="d-none" checked>
                                    <div class="payment-type-card">
                                        <i class="fas fa-handshake text-warning mb-2"></i>
                                        <h5>Promise to Pay</h5>
                                        <p class="text-muted mb-0">I will pay this amount later</p>
                                    </div>
                                </label>
                                
                                <label class="payment-type-option">
                                    <input type="radio" name="type" value="paid" class="d-none">
                                    <div class="payment-type-card">
                                        <i class="fas fa-check-circle text-success mb-2"></i>
                                        <h5>Already Paid</h5>
                                        <p class="text-muted mb-0">I have already made this payment</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Payment Method (shown only for paid) -->
                        <div class="form-section mb-4 d-none" id="paymentMethodDiv">
                            <h4 class="form-section-title">
                                <i class="fas fa-wallet text-primary me-2"></i>
                                Payment Method
                            </h4>
                            
                            <select class="form-select" name="payment_method" id="payment_method">
                                <option value="">How did you pay?</option>
                                <option value="cash" <?php echo (($_POST['payment_method'] ?? '') === 'cash') ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo (($_POST['payment_method'] ?? '') === 'card') ? 'selected' : ''; ?>>Card/Online</option>
                                <option value="bank" <?php echo (($_POST['payment_method'] ?? '') === 'bank') ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="other" <?php echo (($_POST['payment_method'] ?? '') === 'other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg px-5 py-3">
                                <i class="fas fa-heart me-2"></i>
                                Submit Donation
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/donate.js"></script>
</body>
</html>
