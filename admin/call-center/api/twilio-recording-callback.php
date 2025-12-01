<?php
/**
 * Twilio Webhook: Recording Callback
 * 
 * Called when a call recording is ready.
 * Saves the recording URL to the database for playback later.
 */

declare(strict_types=1);

// Disable output buffering
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
    
    // Get recording data from Twilio
    $recordingSid = $_POST['RecordingSid'] ?? '';
    $recordingUrl = $_POST['RecordingUrl'] ?? '';
    $recordingStatus = $_POST['RecordingStatus'] ?? '';
    $recordingDuration = isset($_POST['RecordingDuration']) ? (int)$_POST['RecordingDuration'] : null;
    $callSid = $_POST['CallSid'] ?? '';
    $accountSid = $_POST['AccountSid'] ?? '';
    
    // Build full recording URL (add .mp3 extension for direct playback)
    if (!empty($recordingUrl) && !str_ends_with($recordingUrl, '.mp3')) {
        $fullRecordingUrl = $recordingUrl . '.mp3';
    } else {
        $fullRecordingUrl = $recordingUrl;
    }
    
    // Log the webhook
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
                VALUES ('recording', ?, ?, 'POST', ?, ?, ?, ?)
            ");
            $stmt->bind_param('ssssss', $callSid, $accountSid, $requestUrl, $payload, $ipAddress, $userAgent);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to log recording webhook: " . $e->getMessage());
    }
    
    // Update call_center_sessions with recording URL
    if (!empty($fullRecordingUrl) && !empty($callSid)) {
        $stmt = $db->prepare("
            UPDATE call_center_sessions 
            SET twilio_recording_url = ?,
                recording_url = ?,
                updated_at = NOW()
            WHERE id = ? AND twilio_call_sid = ?
        ");
        
        if ($stmt) {
            $stmt->bind_param('ssis', $fullRecordingUrl, $fullRecordingUrl, $sessionId, $callSid);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected === 0) {
                // Try updating without call_sid check (in case it's not set yet)
                $stmt = $db->prepare("
                    UPDATE call_center_sessions 
                    SET twilio_recording_url = ?,
                        recording_url = ?,
                        twilio_call_sid = COALESCE(twilio_call_sid, ?),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('sssi', $fullRecordingUrl, $fullRecordingUrl, $callSid, $sessionId);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Update twilio_call_logs
        try {
            $check = $db->query("SHOW TABLES LIKE 'twilio_call_logs'");
            if ($check && $check->num_rows > 0) {
                $stmt = $db->prepare("
                    UPDATE twilio_call_logs 
                    SET recording_url = ?,
                        recording_duration = ?,
                        updated_at = NOW()
                    WHERE call_sid = ?
                ");
                $stmt->bind_param('sis', $fullRecordingUrl, $recordingDuration, $callSid);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to update twilio_call_logs with recording: " . $e->getMessage());
        }
    }
    
    // Mark webhook as processed
    try {
        $stmt = $db->prepare("
            UPDATE twilio_webhook_logs 
            SET processed = 1, processed_at = NOW() 
            WHERE call_sid = ? AND webhook_type = 'recording'
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
        'recording_sid' => $recordingSid,
        'recording_url' => $fullRecordingUrl,
        'status' => $recordingStatus,
        'updated' => true
    ]);
    
} catch (Exception $e) {
    error_log("Twilio Recording Callback Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}

