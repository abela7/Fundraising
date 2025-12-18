<?php
declare(strict_types=1);

/**
 * API v1 - PWA Install Log Endpoint
 * 
 * POST /api/v1/pwa/install-log
 * 
 * Logs when a user installs the PWA.
 */

require_once __DIR__ . '/../ApiResponse.php';
require_once __DIR__ . '/../ApiAuth.php';
require_once __DIR__ . '/../ApiRateLimiter.php';
require_once __DIR__ . '/../../../config/db.php';

// Handle CORS preflight
ApiResponse::handlePreflight();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}

$rateLimiter = new ApiRateLimiter();
$rateLimiter->enforce('pwa/install-log');

// Require authentication
$auth = new ApiAuth();
$authData = $auth->requireAuth();

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    ApiResponse::error('Invalid JSON body', 400, 'INVALID_JSON');
}

// Extract device info from request
$deviceType = $input['device_type'] ?? null; // android, ios, desktop
$devicePlatform = $input['device_platform'] ?? null; // Android 14, iOS 17.2, etc.
$browser = $input['browser'] ?? null; // Chrome 120, Safari 17, etc.
$screenWidth = isset($input['screen_width']) ? (int) $input['screen_width'] : null;
$screenHeight = isset($input['screen_height']) ? (int) $input['screen_height'] : null;
$appVersion = $input['app_version'] ?? null;
$pushEnabled = isset($input['push_enabled']) ? (int) (bool) $input['push_enabled'] : 0;
$pushEndpoint = $input['push_subscription_endpoint'] ?? null;

// Get user agent from headers
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$db = db();

// Check if already installed (same user + device type)
$checkStmt = $db->prepare(
    "SELECT id FROM pwa_installations 
     WHERE user_type = ? AND user_id = ? AND device_type = ? AND is_active = 1
     LIMIT 1"
);
$checkStmt->bind_param('sis', $authData['user_type'], $authData['user_id'], $deviceType);
$checkStmt->execute();
$existing = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($existing) {
    // Update existing installation
    $updateStmt = $db->prepare(
        "UPDATE pwa_installations SET 
         device_platform = ?, browser = ?, screen_width = ?, screen_height = ?,
         user_agent = ?, app_version = ?, push_enabled = ?, push_subscription_endpoint = ?,
         last_opened_at = NOW(), updated_at = NOW()
         WHERE id = ?"
    );
    $updateStmt->bind_param(
        'ssiissisi',
        $devicePlatform,
        $browser,
        $screenWidth,
        $screenHeight,
        $userAgent,
        $appVersion,
        $pushEnabled,
        $pushEndpoint,
        $existing['id']
    );
    $updateStmt->execute();
    $updateStmt->close();

    $rateLimiter->logRequest('pwa/install-log', 'POST', $authData['user_type'], $authData['user_id'], 200);

    ApiResponse::success([
        'installation_id' => (int) $existing['id'],
        'status' => 'updated',
    ], 'Installation updated');
} else {
    // Create new installation record
    $insertStmt = $db->prepare(
        "INSERT INTO pwa_installations 
         (user_type, user_id, device_type, device_platform, browser, screen_width, screen_height,
          user_agent, app_version, push_enabled, push_subscription_endpoint, last_opened_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $insertStmt->bind_param(
        'sisssiiisis',
        $authData['user_type'],
        $authData['user_id'],
        $deviceType,
        $devicePlatform,
        $browser,
        $screenWidth,
        $screenHeight,
        $userAgent,
        $appVersion,
        $pushEnabled,
        $pushEndpoint
    );
    $insertStmt->execute();
    $installationId = $insertStmt->insert_id;
    $insertStmt->close();

    $rateLimiter->logRequest('pwa/install-log', 'POST', $authData['user_type'], $authData['user_id'], 201);

    ApiResponse::success([
        'installation_id' => $installationId,
        'status' => 'created',
    ], 'Installation logged');
}

