<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $queue_id = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : 0;
    $reason_outcome = isset($_POST['refusal_reason']) ? $_POST['refusal_reason'] : 'not_interested';
    $notes = isset($_POST['refusal_notes']) ? trim($_POST['refusal_notes']) : '';
    $duration_seconds = isset($_POST['duration_seconds']) ? (int)$_POST['duration_seconds'] : 0;
    
    // Validate outcome against ENUM (basic check)
    $valid_outcomes = ['not_interested', 'financial_hardship', 'never_pledged_denies', 'already_paid_claims', 'donor_deceased', 'moved_abroad'];
    if (!in_array($reason_outcome, $valid_outcomes)) {
        $reason_outcome = 'not_interested';
    }

    $db->begin_transaction();

    // 1. Update Session
    if ($session_id > 0) {
        $update_session = $db->prepare("
            UPDATE call_center_sessions 
            SET outcome = ?,
                conversation_stage = 'closed_refused',
                duration_seconds = COALESCE(duration_seconds, 0) + ?,
                call_ended_at = NOW(),
                notes = CONCAT(COALESCE(notes, ''), ?)
            WHERE id = ?
        ");
        $note_append = $notes ? "\nRefusal Notes: " . $notes : "";
        $update_session->bind_param('sisi', $reason_outcome, $duration_seconds, $note_append, $session_id);
        $update_session->execute();
        $update_session->close();
    }

    // 2. Update Queue
    if ($queue_id > 0) {
        $update_queue = $db->prepare("
            UPDATE call_center_queues 
            SET status = 'completed',
                completed_at = NOW(),
                last_attempt_outcome = 'refused'
            WHERE id = ?
        ");
        $update_queue->bind_param('i', $queue_id);
        $update_queue->execute();
        $update_queue->close();
    }
    
    // 3. Update Donor (Optional - maybe flag as 'refused' or 'do_not_call'?)
    // For now, we just log the interaction. The donor status might remain active but with a 'refused' history.
    
    $db->commit();
    
    // Redirect to summary
    header("Location: call-complete.php?session_id={$session_id}&donor_id={$donor_id}");
    exit;

} catch (Exception $e) {
    if (isset($db)) {
        $db->rollback();
    }
    error_log("Process Refusal Error: " . $e->getMessage());
    header("Location: index.php?error=refusal_failed");
    exit;
}

