<?php
declare(strict_types=1);

/**
 * API v1 - PWA Heartbeat Endpoint
 * 
 * POST /api/v1/pwa/heartbeat
 * 
 * Updates the last_opened_at timestamp for PWA installations.
 * Called periodically when the app is open.
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
$isStandalone = isset($input['is_standalone']) ? (bool) $input['is_standalone'] : true;

$db = db();

// Update last_opened_at for matching installations
if ($deviceType) {
    $stmt = $db->prepare(
        "UPDATE pwa_installations 
         SET last_opened_at = NOW(), updated_at = NOW()
         WHERE user_type = ? AND user_id = ? AND device_type = ? AND is_active = 1"
    );
    $stmt->bind_param('sis', $authData['user_type'], $authData['user_id'], $deviceType);
} else {
    $stmt = $db->prepare(
        "UPDATE pwa_installations 
         SET last_opened_at = NOW(), updated_at = NOW()
         WHERE user_type = ? AND user_id = ? AND is_active = 1"
    );
    $stmt->bind_param('si', $authData['user_type'], $authData['user_id']);
}

$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

ApiResponse::success([
    'updated' => $affected,
    'is_standalone' => $isStandalone,
], 'Heartbeat recorded');

