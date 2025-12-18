<?php
declare(strict_types=1);

/**
 * API v1 - Send OTP Endpoint
 * 
 * POST /api/v1/auth/otp-send
 * 
 * Sends an OTP code to the donor's phone via SMS.
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiRateLimiter.php';
require_once __DIR__ . '/../../../config/db.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$rateLimiter = new ApiRateLimiter();

// Strict rate limiting for OTP sending
$rateLimiter->enforce('auth/otp/send');

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$phone = trim($input['phone'] ?? '');

// Validate phone
if (empty($phone)) {
    ApiResponse::error('Phone number is required', 422, 'VALIDATION_ERROR', [
        'phone' => 'Phone number is required',
    ]);
}

// Normalize phone
$normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
if (str_starts_with($normalizedPhone, '44') && strlen($normalizedPhone) === 12) {
    $normalizedPhone = '0' . substr($normalizedPhone, 2);
}

// Validate UK mobile format
if (strlen($normalizedPhone) !== 11 || !str_starts_with($normalizedPhone, '07')) {
    ApiResponse::error('Please enter a valid UK mobile number starting with 07', 422, 'VALIDATION_ERROR', [
        'phone' => 'Invalid UK mobile number format',
    ]);
}

$db = db();

// Check if donor exists
$stmt = $db->prepare(
    "SELECT id, name FROM donors WHERE phone = ? 
     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?
     LIMIT 1"
);
$stmt->bind_param('ss', $normalizedPhone, $normalizedPhone);
$stmt->execute();
$donor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$donor) {
    // Also check pledges/payments for phone number
    $checkStmt = $db->prepare(
        "SELECT DISTINCT donor_name, donor_phone FROM (
            SELECT donor_name, donor_phone FROM pledges WHERE donor_phone = ?
            UNION
            SELECT donor_name, donor_phone FROM payments WHERE donor_phone = ?
        ) AS combined LIMIT 1"
    );
    $checkStmt->bind_param('ss', $normalizedPhone, $normalizedPhone);
    $checkStmt->execute();
    $phoneRecord = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$phoneRecord) {
        ApiResponse::error('No account found with this phone number', 404, 'DONOR_NOT_FOUND');
    }
}

// Check cooldown (prevent spam)
$cooldownSeconds = 60;
$cooldownStmt = $db->prepare(
    "SELECT created_at FROM donor_otp_codes 
     WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
     ORDER BY created_at DESC LIMIT 1"
);
$cooldownStmt->bind_param('si', $normalizedPhone, $cooldownSeconds);
$cooldownStmt->execute();
$recentOtp = $cooldownStmt->get_result()->fetch_assoc();
$cooldownStmt->close();

if ($recentOtp) {
    $waitTime = $cooldownSeconds - (time() - strtotime($recentOtp['created_at']));
    ApiResponse::error(
        "Please wait {$waitTime} seconds before requesting another code",
        429,
        'COOLDOWN',
        ['wait_seconds' => $waitTime]
    );
}

// Generate OTP
$otpLength = 6;
$otp = str_pad((string) random_int(0, 999999), $otpLength, '0', STR_PAD_LEFT);
$expiryMinutes = 10;
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryMinutes} minutes"));

// Delete old OTPs for this phone
$deleteStmt = $db->prepare("DELETE FROM donor_otp_codes WHERE phone = ?");
$deleteStmt->bind_param('s', $normalizedPhone);
$deleteStmt->execute();
$deleteStmt->close();

// Insert new OTP
$insertStmt = $db->prepare(
    "INSERT INTO donor_otp_codes (phone, code, expires_at) VALUES (?, ?, ?)"
);
$insertStmt->bind_param('sss', $normalizedPhone, $otp, $expiresAt);
$insertStmt->execute();
$insertStmt->close();

// Send SMS
try {
    require_once __DIR__ . '/../../../services/SMSHelper.php';
    $smsHelper = new SMSHelper($db);
    
    $message = "Your donor portal verification code is: {$otp}\n\n" .
               "This code expires in {$expiryMinutes} minutes.\n\n" .
               "Do not share this code with anyone.";
    
    $result = $smsHelper->sendDirect($normalizedPhone, $message, null, 'donor_otp');
    
    if ($result['success']) {
        $rateLimiter->logRequest('auth/otp/send', 'POST', 'donor', $donor['id'] ?? null, 200);
        
        ApiResponse::success([
            'phone' => substr($normalizedPhone, 0, 5) . '****' . substr($normalizedPhone, -2),
            'expires_in' => $expiryMinutes * 60,
        ], 'Verification code sent');
    } else {
        $rateLimiter->logRequest('auth/otp/send', 'POST', 'donor', null, 500, null, $result['error'] ?? 'SMS failed');
        ApiResponse::error('Failed to send SMS. Please try again.', 500, 'SMS_FAILED');
    }
} catch (Exception $e) {
    error_log("OTP SMS exception: " . $e->getMessage());
    $rateLimiter->logRequest('auth/otp/send', 'POST', 'donor', null, 500, null, $e->getMessage());
    ApiResponse::error('SMS service unavailable. Please try again later.', 503, 'SMS_UNAVAILABLE');
}

