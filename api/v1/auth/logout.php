<?php
declare(strict_types=1);

/**
 * API v1 - Logout Endpoint
 * 
 * POST /api/v1/auth/logout
 * 
 * Revoke the current access token.
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

$auth = new ApiAuth();

// Get token from header
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = null;

if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
    $token = $matches[1];
}

if (!$token) {
    ApiResponse::error('No token provided', 400, 'NO_TOKEN');
}

$revoked = $auth->revokeToken($token);

if ($revoked) {
    $rateLimiter = new ApiRateLimiter();
    $rateLimiter->logRequest('auth/logout', 'POST', null, null, 200);
    
    ApiResponse::success(null, 'Logged out successfully');
} else {
    ApiResponse::error('Token not found or already revoked', 400, 'TOKEN_NOT_FOUND');
}

