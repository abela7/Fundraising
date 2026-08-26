<?php
/**
 * Custom Call Initiation API
 *
 * AJAX endpoint to start a Twilio call to any phone number
 * (doesn't require a registered donor)
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

    $db = db();
    $userId = (int)$_SESSION['user']['id'];

    // Verify CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    verify_csrf();

    // Get parameters
    $customPhone = trim($_POST['custom_phone'] ?? '');
    $agentPhone = trim($_POST['agent_phone'] ?? '');

    if (empty($customPhone)) {
        throw new Exception('Phone number is required');
    }

    if (empty($agentPhone)) {
        throw new Exception('Agent phone number is required');
    }

    // Get user name
    $user_query = $db->prepare("SELECT name FROM users WHERE id = ?");
    $user_query->bind_param('i', $userId);
    $user_query->execute();
    $user_result = $user_query->get_result();
    $user_data = $user_result->fetch_assoc();
    $user_query->close();

    $userName = $user_data['name'] ?? 'Agent';

    // Create custom call session
    $columns_check = $db->query("SHOW COLUMNS FROM call_center_sessions");
    $available_columns = [];
    while ($col = $columns_check->fetch_assoc()) {
        $available_columns[] = $col['Field'];
    }

    // Build INSERT query based on available columns
    $insert_columns = ['agent_id'];
    $insert_values = ['?'];
    $param_types = 'i';
    $param_values = [$userId];

    // Add call_started_at if column exists
    if (in_array('call_started_at', $available_columns)) {
        $insert_columns[] = 'call_started_at';
        $insert_values[] = 'NOW()';
    }

    // Add is_custom_call if column exists (to mark this as custom)
    if (in_array('is_custom_call', $available_columns)) {
        $insert_columns[] = 'is_custom_call';
        $insert_values[] = '?';
        $param_types .= 'i';
        $param_values[] = 1;
    }

    // Add status if column exists
    if (in_array('status', $available_columns)) {
        $insert_columns[] = 'status';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = 'initiating';
    }

    // Add conversation_stage if column exists
    if (in_array('conversation_stage', $available_columns)) {
        $insert_columns[] = 'conversation_stage';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = 'pending';
    }

    // Add call_source if column exists
    if (in_array('call_source', $available_columns)) {
        $insert_columns[] = 'call_source';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = 'twilio';
    }

    // Add agent_phone_number if column exists
    if (in_array('agent_phone_number', $available_columns)) {
        $insert_columns[] = 'agent_phone_number';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = $agentPhone;
    }

    // Add donor_phone_number (called custom_phone_number for clarity)
    if (in_array('donor_phone_number', $available_columns)) {
        $insert_columns[] = 'donor_phone_number';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = $customPhone;
    }

    // Add custom_phone_label to track what was called
    if (in_array('notes', $available_columns)) {
        $insert_columns[] = 'notes';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = '[Custom Call] No donor record';
    }

    // Build and execute query
    $sql = "INSERT INTO call_center_sessions (" . implode(', ', $insert_columns) . ") VALUES (" . implode(', ', $insert_values) . ")";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare session insert: ' . $db->error);
    }

    if (count($param_values) > 0) {
        $stmt->bind_param($param_types, ...$param_values);
    }

    if (!$stmt->execute()) {
        throw new Exception('Failed to create session: ' . $stmt->error);
    }

    $sessionId = $db->insert_id;
    $stmt->close();

    // Initialize Twilio service
    $twilio = TwilioService::fromDatabase($db);

    if (!$twilio) {
        throw new Exception('Twilio is not configured. Please configure Twilio settings first.');
    }

    // Extract first name for greeting
    $displayName = 'Contact'; // Generic greeting for unknown number
    $callerId = htmlspecialchars($customPhone); // Show the number being called

    // Initiate the call
    $result = $twilio->initiateCall(
        $agentPhone,
        $customPhone,
        $displayName,
        $sessionId
    );

    if (!$result['success']) {
        // Update session as failed
        $stmt = $db->prepare("
            UPDATE call_center_sessions
            SET conversation_stage = 'attempt_failed',
                outcome = 'call_failed_technical',
                notes = ?
            WHERE id = ?
        ");
        $errorMsg = $result['message'] ?? 'Unknown error';
        $stmt->bind_param('si', $errorMsg, $sessionId);
        $stmt->execute();
        $stmt->close();

        throw new Exception($result['message'] ?? 'Failed to initiate call');
    }

    // Update session with call SID
    $callSid = $result['call_sid'];
    $stmt = $db->prepare("
        UPDATE call_center_sessions
        SET twilio_call_sid = ?,
            twilio_status = 'queued',
            conversation_stage = 'initiating'
        WHERE id = ?
    ");
    $stmt->bind_param('si', $callSid, $sessionId);
    $stmt->execute();
    $stmt->close();

    // Clear output buffer
    ob_end_clean();

    echo json_encode([
        'success' => true,
        'message' => 'Custom call initiated! Your phone will ring first.',
        'session_id' => $sessionId,
        'call_sid' => $callSid,
        'phone_number' => $customPhone,
        'redirect_url' => '../custom-call-status.php?session_id=' . $sessionId
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
