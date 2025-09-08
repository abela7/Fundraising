<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/RateLimiter.php';
require_once __DIR__ . '/../../shared/BotDetector.php';

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

// Get custom amount tracking for progress calculation
$customAmounts = $db->query('SELECT SUM(total_amount) as total_tracked FROM custom_amount_tracking')->fetch_assoc() ?: [];
$customTotal = (float)($customAmounts['total_tracked'] ?? 0);

// Include custom amounts in progress (both allocated and pending)
$totalWithCustom = $currentTotal + $customTotal;
$progressPercent = $targetAmount > 0 ? min(100, ($totalWithCustom / $targetAmount) * 100) : 0;

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    // 🛡️ SECURITY: Rate Limiting & Bot Detection
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimiter = new RateLimiter($db);
    $botDetector = new BotDetector($db);
    
    // Check if IP is blocked
    $blockCheck = $rateLimiter->isBlocked($clientIP);
    if ($blockCheck['blocked']) {
        $retryTime = RateLimiter::formatRetryAfter($blockCheck['retry_after']);
        $error = "Access temporarily restricted. Please try again in {$retryTime}. If you believe this is an error, please contact support.";
        goto skip_processing;
    }
    
    // Analyze for bot behavior
    $botAnalysis = $botDetector->analyzeSubmission($_POST, $_SERVER);
    if ($botAnalysis['is_bot']) {
        // Log suspicious activity but don't reveal detection
        error_log("Bot detected on donation form: IP={$clientIP}, Confidence={$botAnalysis['confidence']}, Reasons=" . implode(', ', $botAnalysis['reasons']));
        $error = 'Please try again. If you continue to experience issues, contact support.';
        goto skip_processing;
    }

    // --- Step 1: Sanitize and collect all form inputs ---
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $anonymous = isset($_POST['anonymous']); // will be true or false
    $anonymousFlag = $anonymous ? 1 : 0;
    $sqm_unit = (string)($_POST['pack'] ?? ''); // '1', '0.5', '0.25', 'custom'
    $custom_amount = (float)($_POST['custom_amount'] ?? 0);
    $type = (string)($_POST['type'] ?? 'pledge'); // 'pledge' or 'paid'
    $payment_method_input = trim((string)($_POST['payment_method'] ?? ''));
    $package_choice = (string)($_POST['package_choice'] ?? ''); // '1', '0.5', '0.25', 'custom'
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

    // --- Step 3: Check rate limits before database processing ---
    if (empty($error)) {
        // Get normalized phone for rate limiting
        $normalizedPhone = null;
        if (!$anonymous && $phone !== '') {
            $normalizedPhone = $normalizeUk($phone);
        }
        
        // Check submission limits
        $limitCheck = $rateLimiter->checkSubmission($clientIP, $normalizedPhone);
        if (!$limitCheck['allowed']) {
            $retryTime = $limitCheck['retry_after'] ? RateLimiter::formatRetryAfter($limitCheck['retry_after']) : '';
            $error = $limitCheck['reason'] . ($retryTime ? " Please try again in {$retryTime}." : '');
            goto skip_processing;
        }
        
        // Require CAPTCHA for suspicious activity (future enhancement)
        if ($limitCheck['require_captcha']) {
            // For now, just log this - CAPTCHA can be added later
            error_log("CAPTCHA should be required for IP: {$clientIP}");
        }
    }
    
    // --- Step 4: If all security checks pass, process the database transaction ---
    if (empty($error)) {
        $db->begin_transaction();
        try {
            $createdBy = null; // Self-pledged donations have no creator user

            // Donor data normalization
            $donorName  = ($type === 'paid' && $anonymous) ? 'Anonymous' : $name;
            $donorPhone = ($type === 'paid' && $anonymous) ? null : $phone;
            $donorEmail = null; // Email field removed

            // For anonymous pledges, we still need some identifier, use "Anonymous"
            if ($type === 'pledge' && $anonymous) {
                $donorName = 'Anonymous';
                $donorPhone = null;
                $donorEmail = null;
            }

            // Notes decoration for paid (simplified since no user notes)
            $final_notes = '';
            if ($type === 'paid' && $payment_method) {
                $final_notes = 'Paid via ' . ucfirst($payment_method) . '.';
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

                // Audit - completely non-blocking
                try {
                    $afterJson = json_encode(['amount'=>$amount,'method'=>$payment_method,'donor'=>$donorName,'status'=>'pending']);
                    $source = 'public';
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'payment', ?, 'create_pending', ?, ?)");
                    $log->bind_param('iiss', $createdBy, $entityId, $afterJson, $source);
                    $log->execute();
                } catch (Exception $auditError) {
                    // Audit log failure is completely ignored - main donation continues
                }
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

                // Audit - completely non-blocking
                try {
                    $afterJson = json_encode(['amount'=>$amount,'type'=>'pledge','anonymous'=>$anonymousFlag,'donor'=>$donorName,'status'=>'pending']);
                    $source = 'public';
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(?, 'pledge', ?, 'create_pending', ?, ?)");
                    $log->bind_param('iiss', $createdBy, $entityId, $afterJson, $source);
                    $log->execute();
                } catch (Exception $auditError) {
                    // Audit log failure is completely ignored - main donation continues
                }
            }

            $db->commit();
            
            // 🛡️ SECURITY: Record successful submission for rate limiting
            $submissionMetadata = [
                'type' => $type,
                'amount' => $amount,
                'anonymous' => $anonymousFlag,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'package' => $selectedPackage['label'] ?? 'custom'
            ];
            $rateLimiter->recordSubmission($clientIP, $normalizedPhone, $submissionMetadata);
            
            $success = "Thank you! Your " . ($type === 'pledge' ? 'pledge' : 'payment') . " of {$currency} " . number_format($amount, 2) . " has been submitted for approval. You will be notified once it's processed.";
            
            // Clear form data after successful submission
            $_POST = [];
        } catch (Exception $e) {
            // Since the donation is being created successfully, show a success message
            // even if there's an exception (likely in audit logging)
            try {
                $db->commit();
                
                // Still record submission for rate limiting even on partial success
                $submissionMetadata = [
                    'type' => $type,
                    'amount' => $amount,
                    'anonymous' => $anonymousFlag,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'package' => $selectedPackage['label'] ?? 'custom',
                    'partial_success' => true
                ];
                $rateLimiter->recordSubmission($clientIP, $normalizedPhone ?? null, $submissionMetadata);
                
                $success = "Thank you! Your " . ($type === 'pledge' ? 'pledge' : 'payment') . " of {$currency} " . number_format($amount, 2) . " has been submitted for approval. You will be notified once it's processed.";
                $_POST = [];
            } catch (Exception $commitError) {
                // If commit fails, rollback and show error
                $db->rollback();
                $error = 'Error processing your donation. Please try again or contact support.';
            }
        }
    }
    
    // Label for security-related early exits
    skip_processing:
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make a Donation - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donate.css?v=<?php echo @filemtime(__DIR__ . '/assets/donate.css'); ?>">
</head>
<body>
    <div class="app-wrapper">
        <div class="app-content">
            <main class="main-content">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-hand-holding-heart me-2"></i>
                        Make a Donation
                    </h1>
                    <p class="text-muted">Support our church with your generous contribution</p>
                </div>
                
                <!-- Hero Instructions Section -->
                <div class="instructions-hero mb-4">
                    <div class="container-fluid px-0">
                        <div class="row g-0">
                            <div class="col-12">
                                <div class="hero-card">
                                    <div class="hero-header">
                                        <div class="hero-icon">
                                            <i class="fas fa-hand-holding-heart"></i>
                                        </div>
                                        <h2 class="hero-title">How to Make Your Donation</h2>
                                        <p class="hero-subtitle">Choose the option that works best for you</p>
                                    </div>
                                    
                                    <div class="donation-options">
                                        <div class="option-card option-immediate">
                                            <div class="option-header">
                                                <div class="option-icon">
                                                    <i class="fas fa-credit-card"></i>
                                                </div>
                                                <h3 class="option-title">Pay Right Now</h3>
                                                <p class="option-desc">Transfer funds immediately</p>
                                            </div>
                                            <div class="option-steps">
                                                <div class="step">
                                                    <span class="step-number">1</span>
                                                    <span class="step-text">Use our bank details below to transfer</span>
                                                </div>
                                                <div class="step">
                                                    <span class="step-number">2</span>
                                                    <span class="step-text">Select your payment method</span>
                                                </div>
                                                <div class="step">
                                                    <span class="step-number">3</span>
                                                    <span class="step-text">Submit your donation form</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="option-card option-pledge">
                                            <div class="option-header">
                                                <div class="option-icon">
                                                    <i class="fas fa-handshake"></i>
                                                </div>
                                                <h3 class="option-title">Promise to Pay Later</h3>
                                                <p class="option-desc">Pledge now, pay monthly</p>
                                            </div>
                                            <div class="option-steps">
                                                <div class="step">
                                                    <span class="step-number">1</span>
                                                    <span class="step-text">Choose your pledge amount</span>
                                                </div>
                                                <div class="step">
                                                    <span class="step-number">2</span>
                                                    <span class="step-text">Select "Promise to Pay Later"</span>
                                                </div>
                                                <div class="step">
                                                    <span class="step-number">3</span>
                                                    <span class="step-text">Submit your pledge form</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="hero-footer">
                                        <div class="contact-notice">
                                            <i class="fas fa-phone-alt"></i>
                                            <span><strong>We'll contact you</strong> after submission to confirm and process your contribution</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Bank Details Section -->
                <div class="bank-details-section mb-4">
                    <div class="bank-card">
                        <div class="bank-header">
                            <div class="bank-icon">
                                <i class="fas fa-university"></i>
                            </div>
                            <div class="bank-title-group">
                                <h3 class="bank-title">Bank Account Details</h3>
                                <p class="bank-subtitle">For immediate transfers and payments</p>
                            </div>
                        </div>
                        
                        <div class="bank-content">
                            <div class="bank-details-grid">
                                <div class="bank-detail-item">
                                    <div class="detail-label">Account Name</div>
                                    <div class="detail-value">LMKATH</div>
                                </div>
                                <div class="bank-detail-item">
                                    <div class="detail-label">Account Number</div>
                                    <div class="detail-value">85455687</div>
                                </div>
                                <div class="bank-detail-item">
                                    <div class="detail-label">Sort Code</div>
                                    <div class="detail-value">53-70-44</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="card mb-4">
                    <div class="progress-section">
                        <div class="progress mb-2" style="height: 20px;">
                            <div class="progress-bar" style="width: <?php echo number_format($progressPercent, 1); ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between text-sm">
                            <span><?php echo $currency; ?> <?php echo number_format($currentTotal, 0); ?> allocated</span>
                            <span><?php echo number_format($progressPercent, 1); ?>% complete</span>
                        </div>
                        <?php if ($customTotal > 0): ?>
                        <div class="d-flex justify-content-between text-sm text-muted">
                            <span>+ <?php echo $currency; ?> <?php echo number_format($customTotal, 0); ?> pending</span>
                            <span>Total: <?php echo $currency; ?> <?php echo number_format($totalWithCustom, 0); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
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
                    
                    <form method="POST" class="registration-form">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="client_uuid" value="">
                        
                        <?php 
                        // 🛡️ SECURITY: Add honeypot fields for bot detection
                        echo BotDetector::generateHoneypotFields(); 
                        ?>
                        
                        <!-- Donor Information -->
                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="fas fa-user"></i>
                                Donor Information
                            </h3>
                            
                            <div class="mb-3">
                                <label for="name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" 
                                       placeholder="Enter full name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       placeholder="Enter phone number" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                            </div>
                            
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="anonymous" name="anonymous" 
                                       <?php echo isset($_POST['anonymous']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="anonymous">
                                    <i class="fas fa-user-secret me-1"></i>
                                    Make this donation anonymous
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
                                    <i class="fas fa-handshake me-1"></i>Promise to Pay Later
                                </label>
                                
                                <input class="form-check-input" type="radio" name="type" id="typePaid" value="paid">
                                <label class="form-check-label" for="typePaid">
                                    <i class="fas fa-check-circle me-1"></i>Paid Now
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
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i>
                            Register Donation
                        </button>
                    </form>
                </div>
                
                <!-- Navigation Buttons -->
                <div class="navigation-section">
                    <div class="nav-buttons">
                        <a href="http://donate.abuneteklehaymanot.org/" class="nav-btn btn-home">
                            <i class="fas fa-home me-2"></i>
                            <span class="btn-text">Homepage</span>
                        </a>
                        
                        <a href="../projector/" class="nav-btn btn-projector">
                            <i class="fas fa-tv me-2"></i>
                            <span class="btn-text">Donation status</span>
                        </a>
                        
                        <a href="../projector/floor/" class="nav-btn btn-floor">
                            <i class="fas fa-map me-2"></i>
                            <span class="btn-text">The Chruch's Floor Map</span>
                        </a>
                    </div>
                </div>
                
                <!-- Mobile Action Button -->
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/donate.js"></script>
    
    <?php 
    // 🛡️ SECURITY: Add bot detection JavaScript
    echo BotDetector::generateDetectionScript(); 
    ?>
</body>
</html>
