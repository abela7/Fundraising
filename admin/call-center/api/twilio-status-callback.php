<?php
/**
 * Twilio Webhook: Status Callback
 * 
 * Receives real-time status updates during a call:
 * - initiated: Call is being set up
 * - ringing: Phone is ringing
 * - answered: Call was answered (donor picked up)
 * - completed: Call ended
 * 
 * This updates the call_center_sessions table automatically
 */

declare(strict_types=1);

// Disable output buffering for immediate response
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    
    if ($sessionId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid session_id']);
        exit;
    }
    
    // Get Twilio callback data
    $callSid = $_POST['CallSid'] ?? '';
    $accountSid = $_POST['AccountSid'] ?? '';
    $callStatus = $_POST['CallStatus'] ?? '';
    $from = $_POST['From'] ?? '';
    $to = $_POST['To'] ?? '';
    $duration = isset($_POST['CallDuration']) ? (int)$_POST['CallDuration'] : null;
    $direction = $_POST['Direction'] ?? '';
    
    // Log the webhook to database
    try {
        $check = $db->query("SHOW TABLES LIKE 'twilio_webhook_logs'");
        if ($check && $check->num_rows > 0) {
            $payload = json_encode($_POST);
            $requestUrl = $_SERVER['REQUEST_URI'] ?? '';
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt = $db->prepare("
                INSERT INTO twilio_webhook_logs 
                (webhook_type, call_sid, account_sid, request_method, request_url, payload, ip_address, user_agent)
                VALUES ('status_callback', ?, ?, 'POST', ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssssss', $callSid, $accountSid, $requestUrl, $payload, $ipAddress, $userAgent);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to log webhook: " . $e->getMessage());
    }
    
    // Update call_center_sessions based on status
    if (!empty($callSid)) {
        // Update session with Twilio data
        $updateFields = [
            'call_source' => 'twilio',
            'twilio_call_sid' => $callSid,
            'twilio_status' => $callStatus
        ];
        
        // If call is completed, update duration
        if ($callStatus === 'completed' && $duration !== null && $duration > 0) {
            $updateFields['twilio_duration'] = $duration;
            $updateFields['duration_seconds'] = $duration;
            $updateFields['call_ended_at'] = 'NOW()';
        }
        
        // If call is answered, update status
        if ($callStatus === 'in-progress' || $callStatus === 'answered') {
            $updateFields['status'] = 'connected';
            if (empty($updateFields['call_started_at'])) {
                $updateFields['call_started_at'] = 'NOW()';
            }
        }
        
        // Build SQL
        $setParts = [];
        $params = [];
        $types = '';
        
        foreach ($updateFields as $field => $value) {
            if ($value === 'NOW()') {
                $setParts[] = "$field = NOW()";
            } else {
                $setParts[] = "$field = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : 's';
            }
        }
        
        $setParts[] = "updated_at = NOW()";
        $params[] = $sessionId;
        $types .= 'i';
        
        $sql = "UPDATE call_center_sessions SET " . implode(', ', $setParts) . " WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
        
        // Also update/insert into twilio_call_logs
        try {
            $check = $db->query("SHOW TABLES LIKE 'twilio_call_logs'");
            if ($check && $check->num_rows > 0) {
                $webhookData = json_encode($_POST);
                
                $stmt = $db->prepare("
                    INSERT INTO twilio_call_logs 
                    (call_sid, session_id, from_number, to_number, direction, status, duration, webhook_data)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        duration = VALUES(duration),
                        webhook_data = VALUES(webhook_data),
                        updated_at = NOW()
                ");
                
                $stmt->bind_param('sissssis', 
                    $callSid, $sessionId, $from, $to, $direction, 
                    $callStatus, $duration, $webhookData
                );
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to update twilio_call_logs: " . $e->getMessage());
        }
    }
    
    // Mark webhook as processed
    try {
        $stmt = $db->prepare("
            UPDATE twilio_webhook_logs 
            SET processed = 1, processed_at = NOW() 
            WHERE call_sid = ? AND webhook_type = 'status_callback'
            ORDER BY received_at DESC LIMIT 1
        ");
        $stmt->bind_param('s', $callSid);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Silently fail
    }
    
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'call_sid' => $callSid,
        'status' => $callStatus,
        'updated' => true
    ]);
    
} catch (Exception $e) {
    error_log("Twilio Status Callback Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}

