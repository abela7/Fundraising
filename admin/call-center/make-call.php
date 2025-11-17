<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();
// Get user ID from session (auth system uses $_SESSION['user'] array)
$user_id = (int)($_SESSION['user']['id'] ?? 0);
$donor_id = (int)($_GET['donor_id'] ?? 0);
$queue_id = (int)($_GET['queue_id'] ?? 0);

if ($user_id === 0) {
    header('Location: ../login.php');
    exit;
}

if (!$donor_id) {
    header('Location: index.php');
    exit;
}

// Get donor information
$donor_query = "
    SELECT 
        d.*,
        (SELECT COUNT(*) FROM call_center_sessions WHERE donor_id = d.id) as total_calls,
        (SELECT call_started_at FROM call_center_sessions WHERE donor_id = d.id ORDER BY call_started_at DESC LIMIT 1) as last_call_date,
        (SELECT outcome FROM call_center_sessions WHERE donor_id = d.id ORDER BY call_started_at DESC LIMIT 1) as last_outcome
    FROM donors d
    WHERE d.id = ?
";
$stmt = $db->prepare($donor_query);
$stmt->bind_param('i', $donor_id);
$stmt->execute();
$donor = $stmt->get_result()->fetch_object();

if (!$donor) {
    header('Location: index.php');
    exit;
}

// Get call history for this donor
$history_query = "
    SELECT 
        s.*,
        u.name as agent_name
    FROM call_center_sessions s
    LEFT JOIN users u ON s.agent_id = u.id
    WHERE s.donor_id = ?
    ORDER BY s.call_started_at DESC
    LIMIT 5
";
$stmt = $db->prepare($history_query);
$stmt->bind_param('i', $donor_id);
$stmt->execute();
$history_result = $stmt->get_result();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $outcome = $_POST['outcome'] ?? '';
    $conversation_stage = $_POST['conversation_stage'] ?? 'no_connection';
    $donor_response_type = $_POST['donor_response_type'] ?? 'none';
    $disposition = $_POST['disposition'] ?? 'no_action_needed';
    $notes = trim($_POST['notes'] ?? '');
    $payment_discussed = isset($_POST['payment_discussed']) ? 1 : 0;
    $payment_amount_discussed = !empty($_POST['payment_amount_discussed']) ? (float)$_POST['payment_amount_discussed'] : null;
    $callback_scheduled_for = !empty($_POST['callback_scheduled_for']) ? $_POST['callback_scheduled_for'] : null;
    $callback_reason = trim($_POST['callback_reason'] ?? '');
    $preferred_callback_time = $_POST['preferred_callback_time'] ?? null;
    $objections_raised = trim($_POST['objections_raised'] ?? '');
    $promises_made = trim($_POST['promises_made'] ?? '');
    $call_quality = $_POST['call_quality'] ?? null;
    
    // Special flags
    $donor_requested_supervisor = isset($_POST['donor_requested_supervisor']) ? 1 : 0;
    $donor_threatened_legal = isset($_POST['donor_threatened_legal']) ? 1 : 0;
    $donor_claimed_already_paid = isset($_POST['donor_claimed_already_paid']) ? 1 : 0;
    $donor_claimed_never_pledged = isset($_POST['donor_claimed_never_pledged']) ? 1 : 0;
    $language_barrier_encountered = isset($_POST['language_barrier_encountered']) ? 1 : 0;
    
    $call_started_at = date('Y-m-d H:i:s', strtotime('-' . (int)($_POST['call_duration'] ?? 0) . ' seconds'));
    $call_ended_at = date('Y-m-d H:i:s');
    $duration_seconds = (int)($_POST['call_duration'] ?? 0);
    
    // Insert call session
    $insert_query = "
        INSERT INTO call_center_sessions (
            donor_id, agent_id, call_started_at, call_ended_at, duration_seconds,
            outcome, conversation_stage, donor_response_type, disposition,
            callback_scheduled_for, callback_reason, preferred_callback_time,
            payment_discussed, payment_amount_discussed, objections_raised, promises_made,
            call_quality, notes, donor_requested_supervisor, donor_threatened_legal,
            donor_claimed_already_paid, donor_claimed_never_pledged, language_barrier_encountered
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $db->prepare($insert_query);
    $stmt->bind_param(
        'iisssissssssissssiiiiii',
        $donor_id, $user_id, $call_started_at, $call_ended_at, $duration_seconds,
        $outcome, $conversation_stage, $donor_response_type, $disposition,
        $callback_scheduled_for, $callback_reason, $preferred_callback_time,
        $payment_discussed, $payment_amount_discussed, $objections_raised, $promises_made,
        $call_quality, $notes, $donor_requested_supervisor, $donor_threatened_legal,
        $donor_claimed_already_paid, $donor_claimed_never_pledged, $language_barrier_encountered
    );
    
    if ($stmt->execute()) {
        $session_id = $db->insert_id;
        
        // Update donor last_contacted_at
        $db->query("UPDATE donors SET last_contacted_at = NOW() WHERE id = $donor_id");
        
        // Update queue if provided
        if ($queue_id > 0) {
            $update_queue = "
                UPDATE call_center_queues 
                SET attempts_count = attempts_count + 1,
                    last_attempt_at = NOW(),
                    last_attempt_outcome = ?,
                    status = CASE 
                        WHEN ? IN ('mark_as_completed', 'mark_as_not_interested') THEN 'completed'
                        ELSE status
                    END,
                    completed_at = CASE 
                        WHEN ? IN ('mark_as_completed', 'mark_as_not_interested') THEN NOW()
                        ELSE completed_at
                    END
                WHERE id = ?
            ";
            $stmt = $db->prepare($update_queue);
            $stmt->bind_param('sssi', $outcome, $disposition, $disposition, $queue_id);
            $stmt->execute();
        }
        
        // Log the attempt
        $attempt_result = $conversation_stage !== 'no_connection' ? 'connected' : 'no_connection';
        $db->query("
            INSERT INTO call_center_attempt_log (donor_id, session_id, attempt_number, attempted_at, phone_number_used, result)
            VALUES ($donor_id, $session_id, (SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM call_center_attempt_log WHERE donor_id = $donor_id), NOW(), '{$donor->phone}', '$attempt_result')
        ");
        
        $_SESSION['success'] = 'Call recorded successfully!';
        header('Location: index.php');
        exit;
    } else {
        $error = 'Failed to record call. Please try again.';
    }
}

$page_title = 'Make Call';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-phone-alt me-2"></i>
                        Call: <?php echo htmlspecialchars($donor->name); ?>
                    </h1>
                    <p class="content-subtitle">Record call outcome and conversation details</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Queue
                    </a>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Main Form -->
                <div class="col-lg-8">
                    <!-- Call Timer -->
                    <div class="card mb-4 call-timer-card">
                        <div class="card-body text-center">
                            <div class="timer-display" id="callTimer">00:00</div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-success btn-lg" id="startTimerBtn" onclick="startTimer()">
                                    <i class="fas fa-play me-2"></i>Start Timer
                                </button>
                                <button type="button" class="btn btn-danger btn-lg d-none" id="stopTimerBtn" onclick="stopTimer()">
                                    <i class="fas fa-stop me-2"></i>End Call
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Call Script -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-comments me-2"></i>Conversation Script
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="script-section">
                                <h6 class="text-primary"><i class="fas fa-1 me-2"></i>Greeting & Introduction</h6>
                                <p class="script-text">
                                    "Hello, may I speak with <strong><?php echo htmlspecialchars($donor->name); ?></strong>? 
                                    My name is [Your Name] calling from Abune Teklehaymanot Ethiopian Orthodox Church in Liverpool."
                                </p>
                            </div>
                            
                            <div class="script-section">
                                <h6 class="text-primary"><i class="fas fa-2 me-2"></i>Identity Verification</h6>
                                <p class="script-text">
                                    "I'm calling about your pledge for our church building fundraising campaign. 
                                    Can you confirm your mobile number is <strong><?php echo $donor->phone; ?></strong>?"
                                </p>
                            </div>
                            
                            <div class="script-section">
                                <h6 class="text-primary"><i class="fas fa-3 me-2"></i>Pledge Reminder</h6>
                                <p class="script-text">
                                    "Our records show you pledged <strong>£<?php echo number_format($donor->total_pledged, 2); ?></strong> 
                                    for our building fund, and the current balance is <strong>£<?php echo number_format($donor->balance, 2); ?></strong>. 
                                    We're reaching out to see how you'd like to proceed with your pledge."
                                </p>
                            </div>
                            
                            <div class="script-section">
                                <h6 class="text-primary"><i class="fas fa-4 me-2"></i>Payment Discussion</h6>
                                <p class="script-text">
                                    "We offer flexible payment options: monthly installments, one-time payment, or cash collection at church. 
                                    Which option works best for you?"
                                </p>
                            </div>
                            
                            <div class="script-section">
                                <h6 class="text-primary"><i class="fas fa-5 me-2"></i>Portal Information</h6>
                                <p class="script-text">
                                    "We also have an online portal where you can manage your pledge, make payments, 
                                    and track your progress. Would you like us to send you the access link via SMS?"
                                </p>
                            </div>
                            
                            <div class="script-section">
                                <h6 class="text-primary"><i class="fas fa-6 me-2"></i>Closing</h6>
                                <p class="script-text">
                                    "Thank you for your time and continued support of our church. May God bless you!"
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Outcome Form -->
                    <form method="POST" id="callForm">
                        <input type="hidden" name="call_duration" id="callDurationInput" value="0">
                        
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-clipboard-check me-2"></i>Call Outcome
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <!-- Primary Outcome -->
                                    <div class="col-12">
                                        <label class="form-label required">Call Outcome</label>
                                        <select name="outcome" class="form-select" required onchange="updateFormFields(this.value)">
                                            <option value="">Select outcome...</option>
                                            <optgroup label="No Connection">
                                                <option value="no_answer">No Answer</option>
                                                <option value="busy_signal">Busy Signal</option>
                                                <option value="voicemail_left">Voicemail - Left Message</option>
                                                <option value="voicemail_no_message">Voicemail - No Message</option>
                                                <option value="number_not_in_service">Number Not In Service</option>
                                                <option value="wrong_number">Wrong Number</option>
                                            </optgroup>
                                            <optgroup label="Connection Issues">
                                                <option value="answered_hung_up_immediately">Answered & Hung Up</option>
                                                <option value="answered_poor_connection">Poor Connection</option>
                                                <option value="call_dropped_during_talk">Call Dropped</option>
                                                <option value="answered_language_barrier">Language Barrier</option>
                                            </optgroup>
                                            <optgroup label="Busy/Unavailable">
                                                <option value="busy_call_back_later">Busy - Call Back Later</option>
                                                <option value="at_work_cannot_talk">At Work</option>
                                                <option value="driving_cannot_talk">Driving</option>
                                                <option value="with_family_cannot_talk">With Family</option>
                                            </optgroup>
                                            <optgroup label="Negative">
                                                <option value="not_interested">Not Interested</option>
                                                <option value="never_pledged_denies">Denies Making Pledge</option>
                                                <option value="already_paid_claims">Claims Already Paid</option>
                                                <option value="hostile_angry">Hostile/Angry</option>
                                                <option value="requested_no_more_calls">Requested No More Calls</option>
                                            </optgroup>
                                            <optgroup label="Positive Progress">
                                                <option value="interested_needs_time">Interested - Needs Time</option>
                                                <option value="interested_check_finances">Will Check Finances</option>
                                                <option value="interested_discuss_with_family">Will Discuss with Family</option>
                                                <option value="interested_wants_details_by_sms">Wants Details by SMS</option>
                                            </optgroup>
                                            <optgroup label="Special Circumstances">
                                                <option value="financial_hardship">Financial Hardship</option>
                                                <option value="medical_emergency">Medical Emergency</option>
                                                <option value="moved_abroad">Moved Abroad</option>
                                                <option value="donor_deceased">Donor Deceased</option>
                                            </optgroup>
                                            <optgroup label="Success!">
                                                <option value="payment_plan_created">Payment Plan Created</option>
                                                <option value="agreed_to_pay_full">Agreed to Pay Full</option>
                                                <option value="agreed_cash_collection">Agreed to Cash Collection</option>
                                                <option value="payment_made_during_call">Payment Made During Call</option>
                                            </optgroup>
                                        </select>
                                    </div>

                                    <!-- Conversation Stage -->
                                    <div class="col-md-6">
                                        <label class="form-label">How Far Did You Get?</label>
                                        <select name="conversation_stage" class="form-select">
                                            <option value="no_connection">No Connection</option>
                                            <option value="connected_no_identity_check">Connected - No ID Check</option>
                                            <option value="identity_verified">Identity Verified</option>
                                            <option value="pledge_discussed">Pledge Discussed</option>
                                            <option value="payment_options_discussed">Payment Options Discussed</option>
                                            <option value="agreement_reached">Agreement Reached</option>
                                            <option value="plan_finalized">Plan Finalized</option>
                                        </select>
                                    </div>

                                    <!-- Donor Response -->
                                    <div class="col-md-6">
                                        <label class="form-label">Donor Response Type</label>
                                        <select name="donor_response_type" class="form-select">
                                            <option value="none">None</option>
                                            <option value="positive">Positive</option>
                                            <option value="neutral">Neutral</option>
                                            <option value="negative">Negative</option>
                                            <option value="hostile">Hostile</option>
                                            <option value="confused">Confused</option>
                                        </select>
                                    </div>

                                    <!-- Call Quality -->
                                    <div class="col-md-6">
                                        <label class="form-label">Call Quality</label>
                                        <select name="call_quality" class="form-select">
                                            <option value="">Not Applicable</option>
                                            <option value="excellent">Excellent</option>
                                            <option value="good">Good</option>
                                            <option value="fair">Fair</option>
                                            <option value="poor">Poor</option>
                                            <option value="unusable">Unusable</option>
                                        </select>
                                    </div>

                                    <!-- Disposition -->
                                    <div class="col-md-6">
                                        <label class="form-label required">Next Action</label>
                                        <select name="disposition" class="form-select" required id="dispositionSelect">
                                            <option value="no_action_needed">No Action Needed</option>
                                            <option value="retry_same_day">Retry Same Day</option>
                                            <option value="retry_next_day">Retry Next Day</option>
                                            <option value="retry_in_week">Retry in a Week</option>
                                            <option value="callback_scheduled_specific_time">Schedule Specific Callback</option>
                                            <option value="send_sms_then_retry">Send SMS Then Retry</option>
                                            <option value="assign_to_church_rep">Assign to Church Rep</option>
                                            <option value="assign_to_supervisor">Escalate to Supervisor</option>
                                            <option value="mark_as_completed">Mark as Completed</option>
                                            <option value="mark_as_not_interested">Mark as Not Interested</option>
                                        </select>
                                    </div>

                                    <!-- Callback Section (conditional) -->
                                    <div class="col-12 d-none" id="callbackSection">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6 class="card-title">Callback Details</h6>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Callback Date & Time</label>
                                                        <input type="datetime-local" name="callback_scheduled_for" class="form-control">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Preferred Time</label>
                                                        <select name="preferred_callback_time" class="form-select">
                                                            <option value="">Any Time</option>
                                                            <option value="morning">Morning (9 AM - 12 PM)</option>
                                                            <option value="afternoon">Afternoon (12 PM - 5 PM)</option>
                                                            <option value="evening">Evening (5 PM - 9 PM)</option>
                                                            <option value="weekend">Weekend</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Callback Reason</label>
                                                        <input type="text" name="callback_reason" class="form-control" placeholder="e.g., Needs to discuss with spouse">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Payment Discussion -->
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="payment_discussed" id="paymentDiscussed">
                                            <label class="form-check-label" for="paymentDiscussed">
                                                Payment was discussed
                                            </label>
                                        </div>
                                    </div>

                                    <div class="col-md-6" id="paymentAmountSection" style="display: none;">
                                        <label class="form-label">Amount Discussed</label>
                                        <div class="input-group">
                                            <span class="input-group-text">£</span>
                                            <input type="number" name="payment_amount_discussed" class="form-control" step="0.01" min="0">
                                        </div>
                                    </div>

                                    <!-- Special Flags -->
                                    <div class="col-12">
                                        <label class="form-label">Special Circumstances (check all that apply)</label>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="donor_requested_supervisor" id="reqSupervisor">
                                                    <label class="form-check-label" for="reqSupervisor">
                                                        Requested Supervisor
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="donor_threatened_legal" id="threatLegal">
                                                    <label class="form-check-label" for="threatLegal">
                                                        Threatened Legal Action
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="donor_claimed_already_paid" id="claimPaid">
                                                    <label class="form-check-label" for="claimPaid">
                                                        Claims Already Paid
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="donor_claimed_never_pledged" id="claimNever">
                                                    <label class="form-check-label" for="claimNever">
                                                        Claims Never Pledged
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="language_barrier_encountered" id="langBarrier">
                                                    <label class="form-check-label" for="langBarrier">
                                                        Language Barrier
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Objections -->
                                    <div class="col-12">
                                        <label class="form-label">Objections Raised</label>
                                        <textarea name="objections_raised" class="form-control" rows="2" placeholder="What concerns did they express?"></textarea>
                                    </div>

                                    <!-- Promises -->
                                    <div class="col-12">
                                        <label class="form-label">Promises/Commitments Made</label>
                                        <textarea name="promises_made" class="form-control" rows="2" placeholder="What did the donor commit to do?"></textarea>
                                    </div>

                                    <!-- Notes -->
                                    <div class="col-12">
                                        <label class="form-label required">Call Notes</label>
                                        <textarea name="notes" class="form-control" rows="4" placeholder="Detailed notes about the conversation..." required></textarea>
                                        <small class="text-muted">Include any important details, next steps, or special considerations</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 justify-content-end">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-save me-2"></i>Save Call Record
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Donor Info Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-user me-2"></i>Donor Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="donor-detail-item">
                                <label>Name:</label>
                                <strong><?php echo htmlspecialchars($donor->name); ?></strong>
                            </div>
                            <div class="donor-detail-item">
                                <label>Phone:</label>
                                <a href="tel:<?php echo $donor->phone; ?>" class="phone-link">
                                    <i class="fas fa-phone me-1"></i><?php echo $donor->phone; ?>
                                </a>
                            </div>
                            <?php if ($donor->city): ?>
                            <div class="donor-detail-item">
                                <label>City:</label>
                                <?php echo htmlspecialchars($donor->city); ?>
                            </div>
                            <?php endif; ?>
                            <div class="donor-detail-item">
                                <label>Total Pledged:</label>
                                <strong class="text-primary">£<?php echo number_format($donor->total_pledged, 2); ?></strong>
                            </div>
                            <div class="donor-detail-item">
                                <label>Total Paid:</label>
                                <strong class="text-success">£<?php echo number_format($donor->total_paid, 2); ?></strong>
                            </div>
                            <div class="donor-detail-item">
                                <label>Balance:</label>
                                <strong class="text-danger">£<?php echo number_format($donor->balance, 2); ?></strong>
                            </div>
                            <div class="donor-detail-item">
                                <label>Language:</label>
                                <?php echo strtoupper($donor->preferred_language); ?>
                            </div>
                            <div class="donor-detail-item">
                                <label>Previous Calls:</label>
                                <span class="badge bg-secondary"><?php echo $donor->total_calls; ?></span>
                            </div>
                            <?php if ($donor->last_call_date): ?>
                            <div class="donor-detail-item">
                                <label>Last Contact:</label>
                                <?php echo date('M j, Y', strtotime($donor->last_call_date)); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Call History -->
                    <?php if ($history_result->num_rows > 0): ?>
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-history me-2"></i>Recent Call History
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php while ($call = $history_result->fetch_object()): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <small class="text-muted">
                                            <?php echo date('M j, g:i A', strtotime($call->call_started_at)); ?>
                                        </small>
                                        <?php if ($call->duration_seconds): ?>
                                        <small class="text-muted"><?php echo gmdate("i:s", $call->duration_seconds); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-1">
                                        <span class="badge bg-<?php echo in_array($call->outcome, ['payment_plan_created', 'agreed_to_pay_full']) ? 'success' : 'secondary'; ?>">
                                            <?php echo ucwords(str_replace('_', ' ', $call->outcome)); ?>
                                        </span>
                                    </div>
                                    <?php if ($call->notes): ?>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars(substr($call->notes, 0, 100)); ?><?php echo strlen($call->notes) > 100 ? '...' : ''; ?></small>
                                    <?php endif; ?>
                                    <small class="text-muted">By: <?php echo htmlspecialchars($call->agent_name); ?></small>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Timer functionality
let timerSeconds = 0;
let timerInterval = null;

function startTimer() {
    timerSeconds = 0;
    document.getElementById('startTimerBtn').classList.add('d-none');
    document.getElementById('stopTimerBtn').classList.remove('d-none');
    
    timerInterval = setInterval(() => {
        timerSeconds++;
        updateTimerDisplay();
    }, 1000);
}

function stopTimer() {
    clearInterval(timerInterval);
    document.getElementById('callDurationInput').value = timerSeconds;
}

function updateTimerDisplay() {
    const minutes = Math.floor(timerSeconds / 60);
    const seconds = timerSeconds % 60;
    document.getElementById('callTimer').textContent = 
        String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
}

// Show/hide callback section
document.getElementById('dispositionSelect').addEventListener('change', function() {
    const callbackSection = document.getElementById('callbackSection');
    if (this.value === 'callback_scheduled_specific_time') {
        callbackSection.classList.remove('d-none');
    } else {
        callbackSection.classList.add('d-none');
    }
});

// Show/hide payment amount
document.getElementById('paymentDiscussed').addEventListener('change', function() {
    document.getElementById('paymentAmountSection').style.display = this.checked ? 'block' : 'none';
});

// Auto-set conversation stage based on outcome
function updateFormFields(outcome) {
    const stageSelect = document.querySelector('[name="conversation_stage"]');
    const responseSelect = document.querySelector('[name="donor_response_type"]');
    
    // Auto-set conversation stage
    if (['no_answer', 'busy_signal', 'voicemail_left', 'voicemail_no_message', 'number_not_in_service'].includes(outcome)) {
        stageSelect.value = 'no_connection';
        responseSelect.value = 'none';
    } else if (['payment_plan_created', 'agreed_to_pay_full', 'agreed_cash_collection'].includes(outcome)) {
        stageSelect.value = 'plan_finalized';
        responseSelect.value = 'positive';
    } else if (['not_interested', 'hostile_angry', 'requested_no_more_calls'].includes(outcome)) {
        responseSelect.value = 'negative';
    }
}

// Warn before leaving with unsaved changes
let formChanged = false;
document.getElementById('callForm').addEventListener('change', function() {
    formChanged = true;
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged && timerSeconds > 0) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.getElementById('callForm').addEventListener('submit', function() {
    formChanged = false;
});
</script>
</body>
</html>

