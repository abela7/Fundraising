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
    $qParam = $queueId > 0 ? $queueId : null;
    $stmt = $db->prepare("
        INSERT INTO call_center_sessions 
        (agent_id, donor_id, queue_id, call_started_at, status, conversation_stage, call_source, agent_phone_number, donor_phone_number)
        VALUES (?, ?, ?, NOW(), 'initiating', 'pending', 'twilio', ?, ?)
    ");
    
    if (!$stmt) {
        throw new Exception('Failed to prepare session insert: ' . $db->error);
    }
    
    $stmt->bind_param('iiiss', $userId, $donorId, $qParam, $agentPhone, $donor['phone']);
    
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
    
    // Update queue if applicable
    if ($queueId > 0) {
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

