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
    $errorCode = $_POST['ErrorCode'] ?? null;
    $errorMessage = $_POST['ErrorMessage'] ?? null;
    
    // IMPORTANT: For <Dial> operations, Twilio sends these additional fields
    // DialCallStatus tells us what happened with the DONOR leg specifically
    $dialCallStatus = $_POST['DialCallStatus'] ?? null;
    $dialCallDuration = isset($_POST['DialCallDuration']) ? (int)$_POST['DialCallDuration'] : null;
    $dialCallSid = $_POST['DialCallSid'] ?? null;
    
    // Use dial-specific status if available (more accurate for donor outcome)
    if ($dialCallStatus !== null) {
        $donorStatus = $dialCallStatus;
    } else {
        $donorStatus = $callStatus;
    }
    
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
        // Store the DONOR-SPECIFIC status (dialCallStatus) for accurate reporting
        $statusToStore = $dialCallStatus ?? $callStatus;
        
        $updateFields = [
            'call_source' => 'twilio',
            'twilio_call_sid' => $callSid,
            'twilio_status' => $statusToStore
        ];
        
        // If we have dial duration, use that (more accurate for donor call)
        $actualDuration = $dialCallDuration ?? $duration;
        
        // Determine if donor actually answered or not
        $donorAnswered = false;
        $donorFailed = false;
        
        // Check dial status first (most accurate for donor outcome)
        if ($dialCallStatus !== null) {
            if ($dialCallStatus === 'completed' && $dialCallDuration > 0) {
                // Donor answered and talked
                $donorAnswered = true;
            } elseif ($dialCallStatus === 'busy') {
                $donorFailed = true;
                $errorCode = $errorCode ?? '486';
                $errorMessage = $errorMessage ?? 'Donor line was busy or call was rejected';
            } elseif ($dialCallStatus === 'no-answer') {
                $donorFailed = true;
                $errorCode = $errorCode ?? '480';
                $errorMessage = $errorMessage ?? 'Donor did not answer the call';
            } elseif ($dialCallStatus === 'failed') {
                $donorFailed = true;
                $errorCode = $errorCode ?? '31005';
                $errorMessage = $errorMessage ?? 'Call to donor failed';
            } elseif ($dialCallStatus === 'canceled') {
                $donorFailed = true;
                $errorCode = $errorCode ?? '487';
                $errorMessage = $errorMessage ?? 'Call was canceled before donor answered';
            } elseif ($dialCallStatus === 'completed' && ($dialCallDuration === null || $dialCallDuration === 0)) {
                // Completed but no duration = donor rejected or voicemail without talk
                $donorFailed = true;
                $errorCode = $errorCode ?? '603';
                $errorMessage = $errorMessage ?? 'Donor declined or did not engage with the call';
            }
        }
        
        // If call is completed with duration, update duration
        if ($donorAnswered && $actualDuration !== null && $actualDuration > 0) {
            $updateFields['twilio_duration'] = $actualDuration;
            $updateFields['duration_seconds'] = $actualDuration;
            $updateFields['call_ended_at'] = 'NOW()';
            $updateFields['conversation_stage'] = 'contact_made';
        }
        
        // If call to donor failed (busy, no-answer, rejected)
        if ($donorFailed) {
            $updateFields['twilio_error_code'] = $errorCode;
            $updateFields['twilio_error_message'] = $errorMessage;
            $updateFields['conversation_stage'] = 'attempt_failed';
            $updateFields['call_ended_at'] = 'NOW()';
            $updateFields['outcome'] = match($dialCallStatus) {
                'busy' => 'busy_signal',
                'no-answer' => 'no_answer',
                'canceled' => 'call_dropped_before_talk',
                'failed' => 'call_failed_technical',
                default => 'no_answer'
            };
        }
        
        // Agent leg statuses (not dial-specific)
        if ($dialCallStatus === null) {
            // If call is answered by agent, update conversation stage
            if ($callStatus === 'in-progress' || $callStatus === 'answered') {
                $updateFields['conversation_stage'] = 'pending'; // Agent answered, waiting for donor
            }
            
            // Handle parent call failures (before connecting to donor)
            if ($callStatus === 'failed' || $callStatus === 'busy' || $callStatus === 'no-answer' || $callStatus === 'canceled') {
                if ($errorCode !== null) {
                    $updateFields['twilio_error_code'] = $errorCode;
                }
                if ($errorMessage !== null) {
                    $updateFields['twilio_error_message'] = $errorMessage;
                }
                $updateFields['conversation_stage'] = 'attempt_failed';
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

