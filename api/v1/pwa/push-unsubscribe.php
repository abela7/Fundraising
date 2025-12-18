<?php
declare(strict_types=1);

/**
 * API v1 - Push Notification Unsubscribe Endpoint
 * 
 * POST /api/v1/pwa/push-unsubscribe
 * 
 * Removes push notification subscription for a PWA installation.
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
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$deviceType = $input['device_type'] ?? null;

$db = db();

// Remove push subscription from installation
$stmt = $db->prepare(
    "UPDATE pwa_installations 
     SET push_enabled = 0, push_subscription_endpoint = NULL, updated_at = NOW()
     WHERE user_type = ? AND user_id = ? AND device_type = ? AND is_active = 1"
);
$stmt->bind_param('sis', $authData['user_type'], $authData['user_id'], $deviceType);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

ApiResponse::success([
    'unsubscribed' => $affected > 0,
], 'Push notifications disabled');

