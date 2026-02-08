<?php
/**
 * Get Call Status API
 *
 * Returns real-time status of a Twilio call for frontend polling.
 * Computes call_phase, auto_action, phase_message, and error_info
 * so the frontend can drive UI transitions without guessing.
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/TwilioErrorCodes.php';

    require_login();

    $db = db();
    $sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donorId = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queueId = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;

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

    // Use donor_id from session if not provided in URL
    if ($donorId <= 0) {
        $donorId = (int)($session['donor_id'] ?? 0);
    }

    // ============================================================
    // Compute call_phase from twilio_status + outcome
    // This is the single source of truth for the frontend
    // ============================================================
    $twilioStatus = $session['twilio_status'] ?? '';
    $outcome = $session['outcome'] ?? '';
    $conversationStage = $session['conversation_stage'] ?? '';
    $duration = (int)($session['twilio_duration'] ?? 0);
    $errorCode = $session['twilio_error_code'] ?? null;

    $callPhase = 'initiating';
    $phaseMessage = 'Preparing call...';
    $autoAction = 'wait';
    $autoActionUrl = '';
    $isTerminal = false;
    $phaseStep = 0; // 0=initiating, 1=agent_ringing, 2=agent_answered, 3=donor_ringing, 4=connected/ended
    $phaseIcon = 'fa-spinner fa-spin';
    $phaseColor = 'info';

    switch ($twilioStatus) {
        case 'queued':
        case 'agent_initiated':
            $callPhase = 'initiating';
            $phaseMessage = 'Setting up call...';
            $phaseStep = 0;
            $phaseIcon = 'fa-spinner fa-spin';
            $phaseColor = 'info';
            break;

        case 'agent_ringing':
            $callPhase = 'agent_ringing';
            $phaseMessage = 'Your phone is ringing - please answer!';
            $phaseStep = 1;
            $phaseIcon = 'fa-phone-volume';
            $phaseColor = 'warning';
            break;

        case 'agent_answered':
            $callPhase = 'agent_answered';
            $phaseMessage = 'You answered. Connecting to donor...';
            $phaseStep = 2;
            $phaseIcon = 'fa-spinner fa-spin';
            $phaseColor = 'info';
            break;

        case 'donor_initiated':
            $callPhase = 'donor_dialing';
            $phaseMessage = 'Dialing donor phone...';
            $phaseStep = 3;
            $phaseIcon = 'fa-spinner fa-spin';
            $phaseColor = 'info';
            break;

        case 'donor_ringing':
            $callPhase = 'donor_ringing';
            $phaseMessage = 'Donor phone is ringing...';
            $phaseStep = 3;
            $phaseIcon = 'fa-phone-volume';
            $phaseColor = 'warning';
            break;

        case 'connected':
            $callPhase = 'connected';
            $phaseMessage = 'Call connected! You are speaking with the donor.';
            $phaseStep = 4;
            $phaseIcon = 'fa-comments';
            $phaseColor = 'success';
            $autoAction = 'redirect_conversation';
            $autoActionUrl = '../conversation.php?session_id=' . $sessionId
                . '&donor_id=' . $donorId
                . '&queue_id=' . $queueId;
            break;

        case 'completed':
            $callPhase = 'ended_success';
            $phaseMessage = 'Call completed successfully. Duration: ' . gmdate('i:s', $duration);
            $phaseStep = 4;
            $phaseIcon = 'fa-check-circle';
            $phaseColor = 'success';
            $isTerminal = true;
            $autoAction = 'redirect_conversation';
            $autoActionUrl = '../conversation.php?session_id=' . $sessionId
                . '&donor_id=' . $donorId
                . '&queue_id=' . $queueId;
            break;

        case 'completed_no_talk':
            $callPhase = 'ended_rejected';
            $phaseMessage = 'Donor declined or did not engage.';
            $phaseStep = 4;
            $phaseIcon = 'fa-phone-slash';
            $phaseColor = 'warning';
            $isTerminal = true;
            $autoAction = 'show_schedule_callback';
            break;

        case 'busy':
            $callPhase = 'ended_busy';
            $phaseMessage = 'Donor line was busy or call was rejected.';
            $phaseStep = 4;
            $phaseIcon = 'fa-ban';
            $phaseColor = 'warning';
            $isTerminal = true;
            $autoAction = 'show_busy';
            break;

        case 'no-answer':
            $callPhase = 'ended_no_answer';
            $phaseMessage = 'Donor did not answer the call.';
            $phaseStep = 4;
            $phaseIcon = 'fa-phone-slash';
            $phaseColor = 'danger';
            $isTerminal = true;
            $autoAction = 'show_no_answer';
            break;

        case 'failed':
            $callPhase = 'ended_failed';
            $phaseMessage = 'Call to donor failed.';
            $phaseStep = 4;
            $phaseIcon = 'fa-exclamation-triangle';
            $phaseColor = 'danger';
            $isTerminal = true;
            $autoAction = 'show_failed';
            break;

        case 'canceled':
            $callPhase = 'ended_canceled';
            $phaseMessage = 'Call was canceled before donor answered.';
            $phaseStep = 4;
            $phaseIcon = 'fa-times-circle';
            $phaseColor = 'warning';
            $isTerminal = true;
            $autoAction = 'show_canceled';
            break;

        // Agent-side failures
        case 'agent_failed':
        case 'agent_busy':
        case 'agent_no_answer':
        case 'agent_canceled':
            $callPhase = 'agent_failed';
            $phaseMessage = $session['twilio_error_message'] ?? 'Could not reach your phone.';
            $phaseStep = 1;
            $phaseIcon = 'fa-exclamation-circle';
            $phaseColor = 'danger';
            $isTerminal = true;
            $autoAction = 'show_agent_failed';
            break;

        default:
            // Fallback: check conversation_stage
            if ($conversationStage === 'attempt_failed') {
                $callPhase = 'ended_failed';
                $phaseMessage = $session['twilio_error_message'] ?? 'Call attempt failed.';
                $phaseStep = 4;
                $phaseIcon = 'fa-exclamation-triangle';
                $phaseColor = 'danger';
                $isTerminal = true;
                $autoAction = 'show_failed';
            } elseif ($conversationStage === 'contact_made' && $outcome === 'answered') {
                $callPhase = 'ended_success';
                $phaseMessage = 'Call completed successfully.';
                $phaseStep = 4;
                $phaseIcon = 'fa-check-circle';
                $phaseColor = 'success';
                $isTerminal = true;
                $autoAction = 'redirect_conversation';
                $autoActionUrl = '../conversation.php?session_id=' . $sessionId
                    . '&donor_id=' . $donorId
                    . '&queue_id=' . $queueId;
            }
            break;
    }

    // Build error_info if applicable
    $errorInfo = null;
    if ($errorCode) {
        $errorInfo = TwilioErrorCodes::getErrorInfo($errorCode);
        $errorInfo['code'] = $errorCode;
        $errorInfo['is_retryable'] = TwilioErrorCodes::isRetryable($errorCode);
        $errorInfo['is_bad_number'] = TwilioErrorCodes::isBadNumber($errorCode);
        $errorInfo['recommended_action'] = TwilioErrorCodes::getRecommendedAction($errorCode);
    }

    // Build action URLs for outcome panels
    $commonParams = 'donor_id=' . $donorId . '&queue_id=' . $queueId
        . '&call_started_at=' . urlencode($session['call_started_at'] ?? '')
        . '&session_id=' . $sessionId;

    $actionUrls = [
        'conversation' => '../conversation.php?session_id=' . $sessionId
            . '&donor_id=' . $donorId . '&queue_id=' . $queueId,
        'schedule_callback' => '../schedule-callback.php?' . $commonParams . '&status=not_picked_up',
        'schedule_callback_busy' => '../schedule-callback.php?' . $commonParams . '&status=busy',
        'confirm_invalid' => '../confirm-invalid.php?' . $commonParams . '&reason=not_working',
        'try_again' => '../make-call.php?donor_id=' . $donorId . '&queue_id=' . $queueId,
        'picked_up' => '../availability-check.php?' . $commonParams . '&status=picked_up',
    ];

    // Return enriched status
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        // Raw session data
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
        'duration_seconds' => $session['duration_seconds'],
        // Computed fields for frontend
        'call_phase' => $callPhase,
        'phase_message' => $phaseMessage,
        'phase_step' => $phaseStep,
        'phase_icon' => $phaseIcon,
        'phase_color' => $phaseColor,
        'auto_action' => $autoAction,
        'auto_action_url' => $autoActionUrl,
        'is_terminal' => $isTerminal,
        'error_info' => $errorInfo,
        'action_urls' => $actionUrls,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
