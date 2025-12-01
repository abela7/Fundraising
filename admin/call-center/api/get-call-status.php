<?php
/**
 * Get Call Status API
 * 
 * Returns real-time status of a Twilio call for polling/notifications
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../config/db.php';
    
    require_login();
    
    $db = db();
    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    
    if ($sessionId <= 0) {
        throw new Exception('Invalid session ID');
    }
    
    // Get call session data
    $stmt = $db->prepare("
        SELECT 
            id,
            donor_id,
            agent_id,
            conversation_stage,
            outcome,
            call_source,
            twilio_call_sid,
            twilio_status,
            twilio_duration,
            twilio_error_code,
            twilio_error_message,
            call_started_at,
            call_ended_at,
            duration_seconds
        FROM call_center_sessions 
        WHERE id = ?
    ");
    
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        throw new Exception('Session not found');
    }
    
    // Return status
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'status' => $session['conversation_stage'],
        'outcome' => $session['outcome'],
        'call_source' => $session['call_source'],
        'twilio_call_sid' => $session['twilio_call_sid'],
        'twilio_status' => $session['twilio_status'],
        'twilio_duration' => $session['twilio_duration'],
        'twilio_error_code' => $session['twilio_error_code'],
        'twilio_error_message' => $session['twilio_error_message'],
        'call_started_at' => $session['call_started_at'],
        'call_ended_at' => $session['call_ended_at'],
        'duration_seconds' => $session['duration_seconds']
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

