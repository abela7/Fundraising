<?php
declare(strict_types=1);

/**
 * API v1 - Refresh Token Endpoint
 * 
 * POST /api/v1/auth/refresh
 * 
 * Exchange a refresh token for a new access token.
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

$rateLimiter = new ApiRateLimiter();
$rateLimiter->enforce('auth/refresh');

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$refreshToken = $input['refresh_token'] ?? '';

if (empty($refreshToken)) {
    ApiResponse::error('Refresh token is required', 422, 'VALIDATION_ERROR', [
        'refresh_token' => 'Refresh token is required',
    ]);
}

$auth = new ApiAuth();
$result = $auth->refreshToken($refreshToken);

$rateLimiter->logRequest(
    'auth/refresh',
    'POST',
    $result['user']['role'] ?? null,
    $result['user']['id'] ?? null,
    200
);

ApiResponse::success($result, 'Token refreshed');

