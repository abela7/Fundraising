<?php
/**
 * Custom Call Initiation API
 *
 * Starts a two-leg Twilio call to any phone number: the chosen agent's
 * phone rings first, then connects to the number entered. No donor
 * record is required and no call_center_sessions row is created, since
 * that table's donor_id column is a foreign key and a custom call has
 * no donor to attach to.
 */

declare(strict_types=1);

ob_start();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../shared/csrf.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/TwilioService.php';

    require_login();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    verify_csrf();

    $db = db();

    $customPhone = trim($_POST['custom_phone'] ?? '');
    $agentPhone = trim($_POST['agent_phone'] ?? '');

    if (empty($customPhone)) {
        throw new Exception('Phone number is required');
    }

    if (empty($agentPhone)) {
        throw new Exception('Please choose an agent');
    }

    $twilio = TwilioService::fromDatabase($db);

    if (!$twilio) {
        throw new Exception('Twilio is not configured. Please configure Twilio settings first.');
    }

    // No session row needed here — the answer webhook receives the phone
    // numbers directly via URL params, and the status callback exits
    // gracefully if session_id is 0. Passing 0 keeps that path harmless.
    $result = $twilio->initiateCall(
        $agentPhone,
        $customPhone,
        'the number',
        0
    );

    if (!$result['success']) {
        throw new Exception($result['message'] ?? 'Failed to initiate call');
    }

    ob_end_clean();

    echo json_encode([
        'success' => true,
        'message' => 'Call initiated. Agent phone will ring first.',
        'call_sid' => $result['call_sid'],
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
