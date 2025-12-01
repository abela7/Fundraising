<?php
/**
 * Twilio API: Initiate Call
 * 
 * AJAX endpoint to start a Twilio call from agent to donor
 */

declare(strict_types=1);

// Output buffering and error handling
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
    $donorId = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $queueId = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
    $agentPhone = trim($_POST['agent_phone'] ?? '');
    
    if ($donorId <= 0) {
        throw new Exception('Invalid donor ID');
    }
    
    if (empty($agentPhone)) {
        throw new Exception('Agent phone number is required');
    }
    
    // Get donor information
    $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_assoc();
    $stmt->close();
    
    if (!$donor) {
        throw new Exception('Donor not found');
    }
    
    if (empty($donor['phone'])) {
        throw new Exception('Donor has no phone number');
    }
    
    // Create call center session
    // Check which columns exist in call_center_sessions
    $columns_check = $db->query("SHOW COLUMNS FROM call_center_sessions");
    $available_columns = [];
    while ($col = $columns_check->fetch_assoc()) {
        $available_columns[] = $col['Field'];
    }
    
    // Build INSERT query based on available columns
    $insert_columns = ['agent_id', 'donor_id'];
    $insert_values = ['?', '?'];
    $param_types = 'ii';
    $param_values = [$userId, $donorId];
    
    // Add queue_id if column exists
    if (in_array('queue_id', $available_columns) && $queueId > 0) {
        $insert_columns[] = 'queue_id';
        $insert_values[] = '?';
        $param_types .= 'i';
        $param_values[] = $queueId;
    }
    
    // Add call_started_at if column exists
    if (in_array('call_started_at', $available_columns)) {
        $insert_columns[] = 'call_started_at';
        $insert_values[] = 'NOW()';
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
    
    // Add donor_phone_number if column exists
    if (in_array('donor_phone_number', $available_columns)) {
        $insert_columns[] = 'donor_phone_number';
        $insert_values[] = '?';
        $param_types .= 's';
        $param_values[] = $donor['phone'];
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
    
    // Initiate the call
    $result = $twilio->initiateCall(
        $agentPhone,
        $donor['phone'],
        $donor['name'],
        $sessionId
    );
    
    if (!$result['success']) {
        // Update session as failed
        $stmt = $db->prepare("
            UPDATE call_center_sessions 
            SET status = 'failed', 
                outcome = 'twilio_error',
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
            status = 'in_progress'
        WHERE id = ?
    ");
    $stmt->bind_param('si', $callSid, $sessionId);
    $stmt->execute();
    $stmt->close();
    
    // Update queue if applicable and table exists
    if ($queueId > 0) {
        $queue_table_check = $db->query("SHOW TABLES LIKE 'call_center_queues'");
        if ($queue_table_check && $queue_table_check->num_rows > 0) {
            $stmt = $db->prepare("
                UPDATE call_center_queues 
                SET attempts_count = attempts_count + 1,
                    last_attempt_at = NOW(),
                    status = 'in_progress'
                WHERE id = ?
            ");
            $stmt->bind_param('i', $queueId);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Clear output buffer
    ob_end_clean();
    
    echo json_encode([
        'success' => true,
        'message' => 'Call initiated! Your phone will ring first.',
        'session_id' => $sessionId,
        'call_sid' => $callSid,
        'donor_name' => $donor['name'],
        'redirect_url' => '../../call-center/conversation.php?session_id=' . $sessionId . '&donor_id=' . $donorId . '&queue_id=' . $queueId
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

