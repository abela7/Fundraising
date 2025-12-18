<?php
declare(strict_types=1);

/**
 * API v1 - Current User Endpoint
 * 
 * GET /api/v1/auth/me
 * 
 * Returns the currently authenticated user's data.
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$auth = new ApiAuth();
$authData = $auth->requireAuth();

ApiResponse::success([
    'user_type' => $authData['user_type'],
    'user' => $authData['user'],
]);

