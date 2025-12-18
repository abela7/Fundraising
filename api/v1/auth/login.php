<?php
declare(strict_types=1);

/**
 * API v1 - Login Endpoint
 * 
 * POST /api/v1/auth/login
 * 
 * For admin/registrar: phone + password
 * For donor: phone + OTP code (after OTP is sent)
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../ApiRateLimiter.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$startTime = microtime(true);
$rateLimiter = new ApiRateLimiter();

// Enforce rate limiting
$rateLimiter->enforce('auth/login');

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$userType = $input['user_type'] ?? 'user'; // 'donor' or 'user' (admin/registrar)
$phone = trim($input['phone'] ?? '');
$password = $input['password'] ?? '';
$otpCode = $input['otp_code'] ?? '';

// Validate required fields
if (empty($phone)) {
    ApiResponse::error('Phone number is required', 422, 'VALIDATION_ERROR', [
        'phone' => 'Phone number is required',
    ]);
}

$auth = new ApiAuth();

try {
    if ($userType === 'donor') {
        // Donor login with OTP
        if (empty($otpCode)) {
            ApiResponse::error('OTP code is required for donor login', 422, 'VALIDATION_ERROR', [
                'otp_code' => 'OTP code is required',
            ]);
        }
        
        $result = $auth->authenticateDonor($phone, $otpCode);
    } else {
        // Admin/Registrar login with password
        if (empty($password)) {
            ApiResponse::error('Password is required', 422, 'VALIDATION_ERROR', [
                'password' => 'Password is required',
            ]);
        }
        
        $result = $auth->authenticateUser($phone, $password);
    }

    // Log successful request
    $responseTime = (int) ((microtime(true) - $startTime) * 1000);
    $rateLimiter->logRequest(
        'auth/login',
        'POST',
        $result['user']['role'] ?? $userType,
        $result['user']['id'] ?? null,
        200,
        $responseTime
    );

    ApiResponse::success($result, 'Login successful');
} catch (Exception $e) {
    // Log failed request
    $responseTime = (int) ((microtime(true) - $startTime) * 1000);
    $rateLimiter->logRequest(
        'auth/login',
        'POST',
        null,
        null,
        401,
        $responseTime,
        $e->getMessage()
    );
    
    throw $e;
}

