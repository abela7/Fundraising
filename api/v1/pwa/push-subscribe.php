<?php
declare(strict_types=1);

/**
 * API v1 - Push Notification Subscribe Endpoint
 * 
 * POST /api/v1/pwa/push-subscribe
 * 
 * Stores push notification subscription for a PWA installation.
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../../../config/db.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

// Require authentication
$auth = new ApiAuth();
$authData = $auth->requireAuth();

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

$subscription = $input['subscription'] ?? null;
$deviceType = $input['device_type'] ?? null;

if (!$subscription || !isset($subscription['endpoint'])) {
    ApiResponse::error('Push subscription is required', 422, 'VALIDATION_ERROR', [
        'subscription' => 'Push subscription with endpoint is required',
    ]);
}

$endpoint = $subscription['endpoint'];

$db = db();

// Update installation with push subscription
$stmt = $db->prepare(
    "UPDATE pwa_installations 
     SET push_enabled = 1, push_subscription_endpoint = ?, updated_at = NOW()
     WHERE user_type = ? AND user_id = ? AND device_type = ? AND is_active = 1"
);
$stmt->bind_param('ssis', $endpoint, $authData['user_type'], $authData['user_id'], $deviceType);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected > 0) {
    ApiResponse::success([
        'subscribed' => true,
    ], 'Push notifications enabled');
} else {
    ApiResponse::error('No matching installation found', 404, 'INSTALLATION_NOT_FOUND');
}

