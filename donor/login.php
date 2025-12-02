<?php
/**
 * Donor Portal Login - SMS OTP with Trusted Device
 * 
 * Flow:
 * 1. Enter phone number
 * 2. If trusted device cookie exists → auto-login
 * 3. Otherwise → send SMS OTP
 * 4. Verify OTP → login + optionally trust device
 */

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/audit_helper.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';

// Constants
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 10);
define('DEVICE_TRUST_DAYS', 90);
define('MAX_OTP_ATTEMPTS', 5);
define('OTP_COOLDOWN_SECONDS', 60);

// Check if already logged in
function current_donor(): ?array {
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

if (current_donor()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$step = 'phone'; // phone, otp
$phone = '';
$show_resend = false;

// Handle logout message
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have been logged out successfully.';
}

// Handle change phone (go back to step 1)
if (isset($_GET['change']) && $_GET['change'] === '1') {
    unset($_SESSION['otp_phone']);
}

/**
 * Check if device is trusted for a donor
 */
function checkTrustedDevice($db, string $phone): ?array {
    $cookie_name = 'donor_device_token';
    
    if (!isset($_COOKIE[$cookie_name])) {
        return null;
    }
    
    $token = $_COOKIE[$cookie_name];
    
    // Validate token format (64 hex chars)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    
    // Normalize phone
    $normalized_phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
        $normalized_phone = '0' . substr($normalized_phone, 2);
    }
    
    // Look up trusted device
    $stmt = $db->prepare("
        SELECT td.*, d.id as donor_id, d.name, d.phone, d.total_pledged, d.total_paid, d.balance,
               d.has_active_plan, d.active_payment_plan_id, d.payment_status, 
               d.preferred_payment_method, d.preferred_language
        FROM donor_trusted_devices td
        JOIN donors d ON td.donor_id = d.id
        WHERE td.device_token = ? 
          AND td.is_active = 1 
          AND td.expires_at > NOW()
          AND d.phone = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $token, $normalized_phone);
    $stmt->execute();
    $result = $stmt->get_result();
    $device = $result->fetch_assoc();
    $stmt->close();
    
    if ($device) {
        // Update last used
        $update = $db->prepare("UPDATE donor_trusted_devices SET last_used_at = NOW(), ip_address = ? WHERE id = ?");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $update->bind_param('si', $ip, $device['id']);
        $update->execute();
        $update->close();
        
        return $device;
    }
    
    return null;
}

/**
 * Generate and send OTP
 */
function sendOTP($db, string $phone): array {
    // Normalize phone
    $normalized_phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
        $normalized_phone = '0' . substr($normalized_phone, 2);
    }
    
    // Check cooldown (prevent spam)
    $cooldown_stmt = $db->prepare("
        SELECT created_at FROM donor_otp_codes 
        WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ORDER BY created_at DESC LIMIT 1
    ");
    $cooldown = OTP_COOLDOWN_SECONDS;
    $cooldown_stmt->bind_param('si', $normalized_phone, $cooldown);
    $cooldown_stmt->execute();
    if ($cooldown_stmt->get_result()->num_rows > 0) {
        $cooldown_stmt->close();
        return ['success' => false, 'error' => 'Please wait before requesting another code.', 'cooldown' => true];
    }
    $cooldown_stmt->close();
    
    // Generate 6-digit OTP
    $otp = str_pad((string)random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
    
    // Set expiry
    $expires_at = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));
    
    // Delete old OTPs for this phone
    $delete = $db->prepare("DELETE FROM donor_otp_codes WHERE phone = ?");
    $delete->bind_param('s', $normalized_phone);
    $delete->execute();
    $delete->close();
    
    // Insert new OTP
    $insert = $db->prepare("INSERT INTO donor_otp_codes (phone, code, expires_at) VALUES (?, ?, ?)");
    $insert->bind_param('sss', $normalized_phone, $otp, $expires_at);
    $insert->execute();
    $insert->close();
    
    // Send SMS
    try {
        require_once __DIR__ . '/../services/SMSHelper.php';
        $smsHelper = new SMSHelper($db);
        
        $message = "Your Church Fundraising verification code is: {$otp}\n\nThis code expires in " . OTP_EXPIRY_MINUTES . " minutes.\n\nDo not share this code with anyone.";
        
        $result = $smsHelper->sendDirect($normalized_phone, $message, null, 'donor_otp');
        
        if ($result['success']) {
            return ['success' => true, 'message' => 'Verification code sent to your phone.'];
        } else {
            error_log("OTP SMS failed for $normalized_phone: " . ($result['error'] ?? 'Unknown'));
            return ['success' => false, 'error' => 'Failed to send SMS. Please try again.'];
        }
    } catch (Exception $e) {
        error_log("OTP SMS exception for $normalized_phone: " . $e->getMessage());
        return ['success' => false, 'error' => 'SMS service unavailable. Please try again later.'];
    }
}

/**
 * Verify OTP code
 */
function verifyOTP($db, string $phone, string $code): array {
    // Normalize
    $normalized_phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
        $normalized_phone = '0' . substr($normalized_phone, 2);
    }
    
    $code = preg_replace('/[^0-9]/', '', $code);
    
    // Find valid OTP
    $stmt = $db->prepare("
        SELECT id, code, attempts FROM donor_otp_codes 
        WHERE phone = ? AND expires_at > NOW() AND verified = 0
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->bind_param('s', $normalized_phone);
    $stmt->execute();
    $result = $stmt->get_result();
    $otp_record = $result->fetch_assoc();
    $stmt->close();
    
    if (!$otp_record) {
        return ['success' => false, 'error' => 'Code expired or not found. Please request a new code.'];
    }
    
    // Check max attempts
    if ($otp_record['attempts'] >= MAX_OTP_ATTEMPTS) {
        // Delete the OTP
        $del = $db->prepare("DELETE FROM donor_otp_codes WHERE id = ?");
        $del->bind_param('i', $otp_record['id']);
        $del->execute();
        $del->close();
        return ['success' => false, 'error' => 'Too many failed attempts. Please request a new code.'];
    }
    
    // Verify code
    if ($otp_record['code'] !== $code) {
        // Increment attempts
        $inc = $db->prepare("UPDATE donor_otp_codes SET attempts = attempts + 1 WHERE id = ?");
        $inc->bind_param('i', $otp_record['id']);
        $inc->execute();
        $inc->close();
        
        $remaining = MAX_OTP_ATTEMPTS - $otp_record['attempts'] - 1;
        return ['success' => false, 'error' => "Invalid code. {$remaining} attempts remaining."];
    }
    
    // Mark as verified
    $verify = $db->prepare("UPDATE donor_otp_codes SET verified = 1 WHERE id = ?");
    $verify->bind_param('i', $otp_record['id']);
    $verify->execute();
    $verify->close();
    
    return ['success' => true];
}

/**
 * Create trusted device token
 */
function createTrustedDevice($db, int $donor_id): string {
    // Generate secure token
    $token = bin2hex(random_bytes(32)); // 64 hex chars
    
    // Device info
    $device_name = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
    $device_name = substr($device_name, 0, 255);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // Expiry
    $expires_at = date('Y-m-d H:i:s', strtotime('+' . DEVICE_TRUST_DAYS . ' days'));
    
    // Insert
    $stmt = $db->prepare("
        INSERT INTO donor_trusted_devices (donor_id, device_token, device_name, ip_address, last_used_at, expires_at)
        VALUES (?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->bind_param('issss', $donor_id, $token, $device_name, $ip, $expires_at);
    $stmt->execute();
    $stmt->close();
    
    // Set cookie (secure, httponly, samesite)
    $cookie_options = [
        'expires' => strtotime('+' . DEVICE_TRUST_DAYS . ' days'),
        'path' => '/donor/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('donor_device_token', $token, $cookie_options);
    
    return $token;
}

/**
 * Find or create donor by phone
 */
function findOrCreateDonor($db, string $phone): ?array {
    $normalized_phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
        $normalized_phone = '0' . substr($normalized_phone, 2);
    }
    
    // Check columns
    $email_check = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
    $has_email = $email_check->num_rows > 0;
    $email_check->close();
    
    $email_opt_check = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
    $has_email_opt = $email_opt_check->num_rows > 0;
    $email_opt_check->close();
    
    $select_fields = "id, name, phone, total_pledged, total_paid, balance, 
               has_active_plan, active_payment_plan_id,
               payment_status, preferred_payment_method, preferred_language";
    if ($has_email) $select_fields .= ", email";
    if ($has_email_opt) $select_fields .= ", email_opt_in";
    
    // Try exact match
    $stmt = $db->prepare("SELECT $select_fields FROM donors WHERE phone = ? LIMIT 1");
    $stmt->bind_param('s', $normalized_phone);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($donor) return $donor;
    
    // Try with SQL normalization
    $stmt2 = $db->prepare("
        SELECT $select_fields FROM donors 
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?
        LIMIT 1
    ");
    $stmt2->bind_param('s', $normalized_phone);
    $stmt2->execute();
    $donor = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    
    if ($donor) return $donor;
    
    // Check pledges/payments and create donor if found
    $check = $db->prepare("
        SELECT DISTINCT donor_name, donor_phone FROM (
            SELECT donor_name, donor_phone FROM pledges WHERE donor_phone = ?
            UNION
            SELECT donor_name, donor_phone FROM payments WHERE donor_phone = ?
        ) AS combined LIMIT 1
    ");
    $check->bind_param('ss', $normalized_phone, $normalized_phone);
    $check->execute();
    $phone_record = $check->get_result()->fetch_assoc();
    $check->close();
    
    if ($phone_record) {
        // Calculate totals
        $totals = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'pledge' AND status = 'approved' THEN amount ELSE 0 END), 0) as total_pledged,
                COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) as total_paid
            FROM (
                SELECT amount, 'pledge' as type, status FROM pledges WHERE donor_phone = ?
                UNION ALL
                SELECT amount, 'payment' as type, status FROM payments WHERE donor_phone = ?
            ) AS combined
        ");
        $totals->bind_param('ss', $normalized_phone, $normalized_phone);
        $totals->execute();
        $totals_data = $totals->get_result()->fetch_assoc();
        $totals->close();
        
        // Create donor
        $insert = $db->prepare("
            INSERT INTO donors (phone, name, total_pledged, total_paid, source)
            VALUES (?, ?, ?, ?, 'sms_verified')
            ON DUPLICATE KEY UPDATE name = VALUES(name), total_pledged = VALUES(total_pledged), total_paid = VALUES(total_paid)
        ");
        $total_pledged = (float)($totals_data['total_pledged'] ?? 0);
        $total_paid = (float)($totals_data['total_paid'] ?? 0);
        $insert->bind_param('ssdd', $normalized_phone, $phone_record['donor_name'], $total_pledged, $total_paid);
        $insert->execute();
        $insert->close();
        
        // Fetch created donor
        $fetch = $db->prepare("SELECT $select_fields FROM donors WHERE phone = ? LIMIT 1");
        $fetch->bind_param('s', $normalized_phone);
        $fetch->execute();
        $donor = $fetch->get_result()->fetch_assoc();
        $fetch->close();
        
        return $donor;
    }
    
    return null;
}

// ============================================================
// HANDLE FORM SUBMISSIONS
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    verify_csrf();
    
    $action = $_POST['action'] ?? 'check_phone';
    
    if ($action === 'check_phone') {
        // Step 1: Check phone and send OTP
        $phone = trim($_POST['phone'] ?? '');
        $normalized_phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
            $normalized_phone = '0' . substr($normalized_phone, 2);
        }
        
        if (strlen($normalized_phone) !== 11 || substr($normalized_phone, 0, 2) !== '07') {
            $error = 'Please enter a valid UK mobile number starting with 07.';
        } else {
            // Check if donor exists
            $donor = findOrCreateDonor($db, $normalized_phone);
            
            if (!$donor) {
                $error = 'No account found with this phone number. Please contact the church office.';
            } else {
                // Check for trusted device
                $trusted = checkTrustedDevice($db, $normalized_phone);
                
                if ($trusted) {
                    // Auto-login with trusted device
                    $_SESSION['donor'] = [
                        'id' => $trusted['donor_id'],
                        'name' => $trusted['name'],
                        'phone' => $trusted['phone'],
                        'total_pledged' => $trusted['total_pledged'],
                        'total_paid' => $trusted['total_paid'],
                        'balance' => $trusted['balance'],
                        'has_active_plan' => $trusted['has_active_plan'],
                        'active_payment_plan_id' => $trusted['active_payment_plan_id'],
                        'payment_status' => $trusted['payment_status'],
                        'preferred_payment_method' => $trusted['preferred_payment_method'],
                        'preferred_language' => $trusted['preferred_language']
                    ];
                    
                    // Update login tracking
                    $upd = $db->prepare("UPDATE donors SET last_login_at = NOW(), login_count = COALESCE(login_count, 0) + 1 WHERE id = ?");
                    $upd->bind_param('i', $trusted['donor_id']);
                    $upd->execute();
                    $upd->close();
                    
                    // Audit log
                    log_audit($db, 'login', 'donor', $trusted['donor_id'], null, ['method' => 'trusted_device'], 'donor_portal', null);
                    
                    session_regenerate_id(true);
                    header('Location: index.php');
                    exit;
                }
                
                // Send OTP
                $otp_result = sendOTP($db, $normalized_phone);
                
                if ($otp_result['success']) {
                    $step = 'otp';
                    $phone = $normalized_phone;
                    $_SESSION['otp_phone'] = $normalized_phone;
                    $success = $otp_result['message'];
                } else {
                    $error = $otp_result['error'];
                    if (isset($otp_result['cooldown'])) {
                        $show_resend = true;
                    }
                }
            }
        }
    } elseif ($action === 'verify_otp') {
        // Step 2: Verify OTP
        $phone = $_SESSION['otp_phone'] ?? '';
        $code = preg_replace('/[^0-9]/', '', trim($_POST['otp_code'] ?? ''));
        $remember = isset($_POST['remember_device']);
        
        if (empty($phone)) {
            $error = 'Session expired. Please start again.';
        } elseif (strlen($code) !== OTP_LENGTH) {
            $error = 'Please enter all 6 digits of the verification code.';
            $step = 'otp';
        } else {
            $verify_result = verifyOTP($db, $phone, $code);
            
            if ($verify_result['success']) {
                // Find donor
                $donor = findOrCreateDonor($db, $phone);
                
                if ($donor) {
                    // Set session
                    $_SESSION['donor'] = $donor;
                    unset($_SESSION['otp_phone']);
                    
                    // Create trusted device if requested
                    if ($remember) {
                        createTrustedDevice($db, $donor['id']);
                    }
                    
                    // Update login tracking
                    $upd = $db->prepare("UPDATE donors SET last_login_at = NOW(), login_count = COALESCE(login_count, 0) + 1 WHERE id = ?");
                    $upd->bind_param('i', $donor['id']);
                    $upd->execute();
                    $upd->close();
                    
                    // Audit log
                    log_audit($db, 'login', 'donor', $donor['id'], null, ['method' => 'sms_otp', 'remember' => $remember], 'donor_portal', null);
                    
                    session_regenerate_id(true);
                    header('Location: index.php');
                    exit;
                } else {
                    $error = 'Account not found. Please contact the church office.';
                }
            } else {
                $error = $verify_result['error'];
                $step = 'otp';
            }
        }
    } elseif ($action === 'resend_otp') {
        // Resend OTP
        $phone = $_SESSION['otp_phone'] ?? '';
        
        if (empty($phone)) {
            $error = 'Session expired. Please start again.';
        } else {
            $otp_result = sendOTP($db, $phone);
            
            if ($otp_result['success']) {
                $success = 'New verification code sent!';
                $step = 'otp';
            } else {
                $error = $otp_result['error'];
                $step = 'otp';
            }
        }
    }
}

// Check if we're in OTP step from session
if (isset($_SESSION['otp_phone']) && $step === 'phone') {
    $step = 'otp';
    $phone = $_SESSION['otp_phone'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Portal Login - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/auth.css?v=<?php echo @filemtime(__DIR__ . '/assets/auth.css'); ?>">
    <style>
        .otp-single-input {
            width: 100%;
            max-width: 240px;
            height: 60px;
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.5rem;
            border: 2px solid #dee2e6;
            border-radius: 12px;
            transition: all 0.2s;
            margin: 1.5rem auto;
            display: block;
        }
        .otp-single-input:focus {
            border-color: var(--primary-color, #0a6286);
            box-shadow: 0 0 0 3px rgba(10, 98, 134, 0.15);
            outline: none;
        }
        .otp-single-input.valid {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-bottom: 1.5rem;
        }
        .step-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #dee2e6;
            transition: all 0.3s;
        }
        .step-dot.active {
            background: var(--primary-color, #0a6286);
            transform: scale(1.2);
        }
        .step-dot.completed {
            background: #28a745;
        }
        .phone-display {
            background: #f8f9fa;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .phone-display .phone-number {
            font-weight: 600;
            color: #333;
        }
        .resend-timer {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .remember-device {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .remember-device label {
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .remember-device .form-check-input {
            margin-top: 0.25rem;
        }
        .remember-info {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        @media (max-width: 400px) {
            .otp-single-input {
                font-size: 1.5rem;
                height: 50px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <div class="auth-logo">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h1 class="auth-title">Donor Portal</h1>
                    <p class="auth-subtitle">Secure SMS Verification</p>
                </div>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step-dot <?php echo $step === 'phone' ? 'active' : 'completed'; ?>"></div>
                    <div class="step-dot <?php echo $step === 'otp' ? 'active' : ''; ?>"></div>
                </div>

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

                <?php if ($step === 'phone'): ?>
                <!-- Step 1: Phone Number -->
                <form method="POST" class="auth-form" id="phoneForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="check_phone">
                    
                    <p class="text-center text-muted mb-4">
                        Enter your phone number to receive a verification code via SMS.
                    </p>
                    
                    <div class="form-floating mb-4">
                        <input type="tel" 
                               class="form-control" 
                               id="phone" 
                               name="phone" 
                               placeholder="Phone number" 
                               pattern="[0-9+\s\-\(\)]+"
                               required 
                               autofocus>
                        <label for="phone">
                            <i class="fas fa-phone me-2"></i>Phone Number
                        </label>
                        <div class="form-text">
                            UK mobile number (e.g., 07123456789)
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login w-100">
                        <i class="fas fa-sms me-2"></i>Send Verification Code
                    </button>
                </form>

                <?php else: ?>
                <!-- Step 2: OTP Verification -->
                <form method="POST" class="auth-form" id="otpForm">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="verify_otp">
                    
                    <div class="phone-display">
                        <div>
                            <small class="text-muted">Code sent to</small>
                            <div class="phone-number"><?php echo htmlspecialchars(substr($phone, 0, 5) . ' ' . substr($phone, 5)); ?></div>
                        </div>
                        <a href="login.php?change=1" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-edit"></i>
                        </a>
                    </div>
                    
                    <p class="text-center text-muted mb-3">
                        Enter the 6-digit code we sent to your phone.
                    </p>
                    
                    <input type="text" 
                           class="otp-single-input" 
                           name="otp_code"
                           id="otpCode"
                           maxlength="6" 
                           inputmode="numeric" 
                           pattern="[0-9]{6}"
                           placeholder="000000"
                           autocomplete="one-time-code"
                           required
                           autofocus>
                    
                    <div class="remember-device">
                        <label>
                            <input type="checkbox" class="form-check-input" name="remember_device" checked>
                            <div>
                                <strong>Remember this device</strong>
                                <div class="remember-info">
                                    <i class="fas fa-shield-alt me-1"></i>
                                    Skip verification on this device for <?php echo DEVICE_TRUST_DAYS; ?> days
                                </div>
                            </div>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login w-100" id="verifyBtn">
                        <i class="fas fa-check-circle me-2"></i>Verify & Login
                    </button>
                </form>
                
                <!-- Resend Form (separate from main form) -->
                <div class="text-center mt-3">
                    <span class="resend-timer" id="resendTimer">Resend code in <span id="countdown">60</span>s</span>
                    <form method="POST" class="d-inline" id="resendForm" style="display: none;">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="resend_otp">
                        <button type="submit" class="btn btn-link p-0">
                            <i class="fas fa-redo me-1"></i>Resend Code
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="auth-footer">
                    <p class="mb-2">
                        <a href="../" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-1"></i>Back to Home
                        </a>
                    </p>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-lock me-1"></i>Secured with SMS verification
                    </p>
                </div>
            </div>
        </div>

        <div class="auth-decoration">
            <div class="circle circle-1"></div>
            <div class="circle circle-2"></div>
            <div class="circle circle-3"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    <?php if ($step === 'phone'): ?>
    // Phone formatting
    document.getElementById('phone').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.startsWith('44') && value.length === 12) {
            value = '0' + value.slice(2);
        }
        if (value.length > 11) value = value.slice(0, 11);
        let formatted = value;
        if (value.length > 5) {
            formatted = value.slice(0, 5) + ' ' + value.slice(5);
        }
        e.target.value = formatted;
    });
    
    document.getElementById('phoneForm').addEventListener('submit', function() {
        const phone = document.getElementById('phone');
        phone.value = phone.value.replace(/\D/g, '');
    });
    <?php else: ?>
    // OTP input handling - single input field
    const otpInput = document.getElementById('otpCode');
    const verifyBtn = document.getElementById('verifyBtn');
    
    // Only allow numbers
    otpInput.addEventListener('input', function(e) {
        // Remove non-digits
        let value = e.target.value.replace(/\D/g, '');
        // Limit to 6 digits
        if (value.length > 6) value = value.slice(0, 6);
        e.target.value = value;
        
        // Add valid class when 6 digits
        e.target.classList.toggle('valid', value.length === 6);
    });
    
    // Handle paste
    otpInput.addEventListener('paste', function(e) {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        const digits = paste.replace(/\D/g, '').slice(0, 6);
        e.target.value = digits;
        e.target.classList.toggle('valid', digits.length === 6);
    });
    
    // Resend countdown
    let countdown = 60;
    const timerEl = document.getElementById('resendTimer');
    const countdownEl = document.getElementById('countdown');
    const resendForm = document.getElementById('resendForm');
    
    const timer = setInterval(() => {
        countdown--;
        countdownEl.textContent = countdown;
        
        if (countdown <= 0) {
            clearInterval(timer);
            timerEl.style.display = 'none';
            resendForm.style.display = 'inline';
        }
    }, 1000);
    <?php endif; ?>
    </script>
</body>
</html>
