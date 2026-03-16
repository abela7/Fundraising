<?php
declare(strict_types=1);

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';

// Debug helper function
function debug_log($message) {
    error_log("[Schedule Callback Debug] " . $message);
}

try {
require_login();

// Set timezone to London for call center
date_default_timezone_set('Europe/London');

    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Unknown';
    
    // Get parameters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $status = isset($_GET['status']) ? $_GET['status'] : 'not_picked_up';
    $reason = isset($_GET['reason']) ? $_GET['reason'] : '';
    $call_started_at = isset($_GET['call_started_at']) ? urldecode($_GET['call_started_at']) : gmdate('Y-m-d H:i:s');
    
    // Reason labels
    $reason_labels = [
        'driving' => 'Driving',
        'at_work' => 'At Work',
        'eating' => 'Eating / Meal',
        'with_family' => 'With Family',
        'sleeping' => 'Sleeping / Resting',
        'bad_time' => 'Bad Time (General)',
        'requested_later' => 'Requested Later',
        'other' => 'Other'
    ];

    // Determine correct outcome based on status and reason (using existing ENUMs)
    function determine_outcome($status, $reason) {
        if ($status === 'not_picked_up') return 'no_answer';
        if ($status === 'busy') return 'busy_signal';
        if ($status === 'not_ready_to_pay') return 'interested_needs_time';
        
        if ($status === 'busy_cant_talk') {
            switch ($reason) {
                case 'driving': return 'driving_cannot_talk';
                case 'at_work': return 'at_work_cannot_talk';
                case 'with_family': return 'with_family_cannot_talk';
                case 'eating': 
                case 'sleeping': 
                case 'bad_time': 
                case 'requested_later': 
                case 'other': 
                default:
                    return 'busy_call_back_later';
            }
        }
        
        return 'no_answer'; // Default fallback
    }

    // Determine correct stage based on status (using NEW ENUMs)
    function determine_stage($status) {
        // If phone was picked up (busy_cant_talk or not_ready_to_pay), contact was made
        if ($status === 'busy_cant_talk') {
            return 'callback_scheduled';
        }
        if ($status === 'not_ready_to_pay') {
            return 'interested_follow_up';
        }
        // Otherwise (no answer, busy signal), no connection
        return 'attempt_failed';
    }
    
    if (!$donor_id) {
        throw new Exception("Missing Donor ID");
    }

    // Create session if not exists (Lazy creation for No Answer/Busy outcomes)
    // Only on GET request to prevent duplicate creation on POST if session_id was lost
    if ($session_id <= 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $outcome = determine_outcome($status, $reason);
        $conversation_stage = determine_stage($status);
        
        $session_query = "
            INSERT INTO call_center_sessions 
            (donor_id, agent_id, call_started_at, conversation_stage, outcome, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $db->prepare($session_query);
        if ($stmt) {
            $stmt->bind_param('iisss', $donor_id, $user_id, $call_started_at, $conversation_stage, $outcome);
            $stmt->execute();
            $session_id = $db->insert_id;
            $stmt->close();
            
            // Update queue attempts count
            if ($queue_id > 0) {
                $update_queue = "UPDATE call_center_queues 
                                SET attempts_count = attempts_count + 1, 
                                    last_attempt_at = NOW(),
                                    status = 'in_progress'
                                WHERE id = ?";
                $stmt = $db->prepare($update_queue);
                if ($stmt) {
                    $stmt->bind_param('i', $queue_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            // REDIRECT to self with session_id to prevent duplicate creation on refresh or POST
            // Preserve all existing query parameters
            $query_params = $_GET;
            $query_params['session_id'] = $session_id;
            $new_url = 'schedule-callback.php?' . http_build_query($query_params);
            header("Location: " . $new_url);
            exit;
        }
    }
    
    // Get donor info for widget and display
    $donor_query = "
        SELECT d.name, d.phone, d.balance, d.city,
               COALESCE(p.amount, 0) as pledge_amount, 
               p.created_at as pledge_date,
               c.name as church_name,
               COALESCE(
                    (SELECT name FROM users WHERE id = d.registered_by_user_id LIMIT 1),
                    (SELECT u.name FROM pledges p2 JOIN users u ON p2.created_by_user_id = u.id WHERE p2.donor_id = d.id ORDER BY p2.created_at DESC LIMIT 1),
                    (SELECT u.name FROM payments pay JOIN users u ON pay.received_by_user_id = u.id WHERE pay.donor_id = d.id ORDER BY pay.created_at DESC LIMIT 1),
                    'Unknown'
                ) as registrar_name
        FROM donors d
        LEFT JOIN pledges p ON d.id = p.donor_id AND p.status = 'approved'
        LEFT JOIN churches c ON d.church_id = c.id
        WHERE d.id = ? 
        ORDER BY p.created_at DESC 
        LIMIT 1
    ";
    $stmt = $db->prepare($donor_query);
    if (!$stmt) throw new Exception("Failed to prepare donor query: " . $db->error);
    
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        throw new Exception("Donor not found");
    }
    
    // Slot duration: 5 minutes
    $slot_duration = 5;
    
    // Handle form submission - Book appointment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {
        $appointment_date = $_POST['appointment_date'] ?? '';
        $appointment_time = $_POST['appointment_time'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $reason = $_POST['reason'] ?? $reason; 
        $duration_seconds = isset($_POST['call_duration_seconds']) ? (int)$_POST['call_duration_seconds'] : 0;
        
        if ($appointment_date && $appointment_time) {
            // Start transaction
            $db->begin_transaction();
            
            try {
                // Determine appointment type - Map to valid DB ENUM values
                // DB ENUM: 'callback_no_answer','callback_busy','callback_requested','follow_up','payment_discussion'
                $appointment_type = 'callback_no_answer';
                
                if ($status === 'busy') {
                    $appointment_type = 'callback_busy';
                } elseif ($status === 'busy_cant_talk') {
                    $appointment_type = 'callback_requested';
                } elseif ($status === 'not_ready_to_pay') {
                    $appointment_type = 'payment_discussion';
                }
                
                // 4 Explicit Scenarios to avoid dynamic binding issues
                $appointment_status = 'scheduled';
                
                if ($session_id > 0 && $queue_id > 0) {
                    // Scenario 1: Both Session and Queue exist
                    $query = "INSERT INTO call_center_appointments 
                        (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        
                    $stmt = $db->prepare($query);
                    if (!$stmt) throw new Exception("DB Error: " . $db->error);
                    
                    $stmt->bind_param("iiiississsi", 
                        $donor_id, $user_id, $session_id, $queue_id, 
                        $appointment_date, $appointment_time, $slot_duration, 
                        $appointment_type, $appointment_status, $notes, $user_id
                    );
                    
                } elseif ($session_id > 0 && $queue_id <= 0) {
                    // Scenario 2: Session exists, No Queue (queue_id is NULL)
                    $query = "INSERT INTO call_center_appointments 
                        (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                        VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $db->prepare($query);
                    if (!$stmt) throw new Exception("DB Error: " . $db->error);
                    
                    $stmt->bind_param("iiississsi", 
                        $donor_id, $user_id, $session_id, 
                        $appointment_date, $appointment_time, $slot_duration, 
                        $appointment_type, $appointment_status, $notes, $user_id
                    );
                    
                } elseif ($session_id <= 0 && $queue_id > 0) {
                    // Scenario 3: No Session (session_id is NULL), Queue exists
                    $query = "INSERT INTO call_center_appointments 
                        (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                        VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                        
                    $stmt = $db->prepare($query);
                    if (!$stmt) throw new Exception("DB Error: " . $db->error);
                    
                    $stmt->bind_param("iiississsi", 
                        $donor_id, $user_id, $queue_id, 
                        $appointment_date, $appointment_time, $slot_duration, 
                        $appointment_type, $appointment_status, $notes, $user_id
                    );
                    
                } else {
                    // Scenario 4: Neither Session nor Queue exist (Both NULL)
                    $query = "INSERT INTO call_center_appointments 
                        (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                        VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $db->prepare($query);
                    if (!$stmt) throw new Exception("DB Error: " . $db->error);
                    
                    $stmt->bind_param("iississsi", 
                        $donor_id, $user_id, 
                        $appointment_date, $appointment_time, $slot_duration, 
                        $appointment_type, $appointment_status, $notes, $user_id
                    );
                }
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create appointment: " . $stmt->error);
                }
                
                $appointment_id = $db->insert_id;
                $stmt->close();
                
                // Update session if exists
                if ($session_id > 0) {
                    $callback_datetime = $appointment_date . ' ' . $appointment_time;
                    $outcome = determine_outcome($status, $reason);
                    $conversation_stage = determine_stage($status);
                    
                    $update_session = "
                        UPDATE call_center_sessions 
                        SET outcome = ?,
                            conversation_stage = ?,
                            disposition = 'callback_scheduled_specific_time',
                            callback_scheduled_for = ?,
                            callback_reason = ?,
                            call_ended_at = NOW(),
                            duration_seconds = COALESCE(duration_seconds, 0) + ?,
                            notes = CONCAT(COALESCE(notes, ''), ?, ?)
                        WHERE id = ? AND agent_id = ?
                    ";
                    
                    $reason_text = $reason && isset($reason_labels[$reason]) ? $reason_labels[$reason] : $reason;
                    $callback_note = "\n[Callback scheduled: {$callback_datetime}]" . ($reason_text ? " [Reason: {$reason_text}]" : "");
                    $extra_notes = $notes ? "\nNotes: {$notes}" : '';
                    
                    $stmt = $db->prepare($update_session);
                    if ($stmt) {
                        $stmt->bind_param('ssssissii', $outcome, $conversation_stage, $callback_datetime, $reason, $duration_seconds, $callback_note, $extra_notes, $session_id, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                
                // Update queue if exists
                if ($queue_id > 0) {
                $next_attempt = $appointment_date . ' ' . $appointment_time;
                $update_queue = "
                    UPDATE call_center_queues 
                    SET status = 'pending',
                        next_attempt_after = ?,
                        last_attempt_outcome = 'callback_scheduled',
                        assigned_to = ?
                    WHERE id = ?
                ";
                
                $stmt = $db->prepare($update_queue);
                if ($stmt) {
                    $stmt->bind_param('sii', $next_attempt, $user_id, $queue_id);
                    $stmt->execute();
                    $stmt->close();
                    }
                }
                
                // Update donor contact_status based on call outcome
                if (in_array($status, ['not_picked_up', 'busy'], true)) {
                    $contact_update = $db->prepare("UPDATE donors SET contact_status = 'not_answering', updated_at = NOW() WHERE id = ?");
                    if ($contact_update) {
                        $contact_update->bind_param('i', $donor_id);
                        $contact_update->execute();
                        $contact_update->close();
                    }
                }

                // Audit log the callback scheduling
                log_audit(
                    $db,
                    'create',
                    'callback_appointment',
                    $appointment_id,
                    null,
                    [
                        'donor_id' => $donor_id,
                        'session_id' => $session_id,
                        'queue_id' => $queue_id,
                        'appointment_date' => $appointment_date,
                        'appointment_time' => $appointment_time,
                        'appointment_type' => $appointment_type,
                        'reason' => $reason
                    ],
                    'admin_portal',
                    $user_id
                );
                
                // Commit transaction
                $db->commit();
                
                // Redirect to success page (SMS option will be on that page)
                // Pass the status so we can use the correct SMS template
                header('Location: callback-scheduled.php?appointment_id=' . $appointment_id . '&donor_id=' . $donor_id . '&status=' . urlencode($status));
                exit;
                
            } catch (Throwable $e) {
                $db->rollback();
                $error_message = "Error: " . $e->getMessage();
                // Also capture file and line for debugging
                $error_detail = "File: " . $e->getFile() . " Line: " . $e->getLine();
                error_log("Schedule Callback Fatal Error: " . $e->getMessage() . " | " . $error_detail);
            }
        } else {
            $error_message = "Please select both date and time.";
        }
    }
    
    // Status labels
    $status_labels = [
        'not_picked_up' => 'No Answer',
        'busy' => 'Line Busy',
        'busy_cant_talk' => 'Callback Requested',
        'not_ready_to_pay' => 'Not Ready to Pay'
    ];
    $status_label = $status_labels[$status] ?? 'No Answer';
    
    if ($status === 'busy_cant_talk' && $reason && isset($reason_labels[$reason])) {
        $status_label .= ' - ' . $reason_labels[$reason];
    }
    
} catch (Throwable $e) {
    $error_message = "System Error: " . $e->getMessage();
    $error_detail = "File: " . $e->getFile() . " Line: " . $e->getLine();
    error_log("Page Load Fatal Error: " . $e->getMessage());
    // If fatal error before loading HTML, echo it
    if (!isset($page_title)) {
        die('<div style="padding:20px;color:red;border:1px solid red;margin:20px;"><h3>System Error</h3>' . htmlspecialchars($error_message) . '<br><small>' . htmlspecialchars($error_detail) . '</small></div>');
    }
}

$page_title = 'Schedule Callback';
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
    <link rel="stylesheet" href="assets/call-widget.css">
    <style>
        /* ===== Mobile-first step wizard ===== */
        .schedule-callback-page { max-width:480px;margin:0 auto;padding:0.5rem; }

        /* Donor bar */
        .donor-bar { display:flex;align-items:center;gap:0.5rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:0.5rem 0.75rem;margin-bottom:0.75rem; }
        .donor-av { width:34px;height:34px;border-radius:50%;background:#0a6286;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.8rem;flex-shrink:0; }
        .donor-bar-info { flex:1;min-width:0; }
        .donor-bar-name { font-weight:700;font-size:0.8rem;color:#0a6286;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .donor-bar-phone { font-size:0.7rem;color:#64748b; }
        .status-pill { font-size:0.65rem;font-weight:600;padding:0.15rem 0.5rem;border-radius:20px;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;white-space:nowrap; }

        /* Step indicators */
        .steps-bar { display:flex;gap:0;margin-bottom:1rem; }
        .step-ind { flex:1;text-align:center;position:relative; }
        .step-num { width:28px;height:28px;margin:0 auto 0.2rem;border-radius:50%;border:2px solid #cbd5e1;background:#fff;font-weight:700;font-size:0.75rem;color:#94a3b8;display:flex;align-items:center;justify-content:center;transition:all 0.25s; }
        .step-ind.active .step-num { border-color:#0a6286;background:#0a6286;color:#fff; }
        .step-ind.done .step-num { border-color:#22c55e;background:#22c55e;color:#fff; }
        .step-ind::after { content:'';position:absolute;top:13px;left:calc(50% + 16px);right:calc(-50% + 16px);height:2px;background:#e2e8f0;z-index:0; }
        .step-ind:last-child::after { display:none; }
        .step-ind.done::after { background:#22c55e; }
        .step-ind.active::after { background:#bae6fd; }
        .step-lbl { font-size:0.6rem;color:#94a3b8;font-weight:600; }
        .step-ind.active .step-lbl,.step-ind.done .step-lbl { color:#334155; }

        /* Panels */
        .wiz-panel { display:none;animation:wfadeUp 0.2s ease; }
        .wiz-panel.active { display:block; }
        @keyframes wfadeUp { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

        .panel-card { background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem;margin-bottom:0.625rem; }
        .panel-head { font-size:0.85rem;font-weight:700;color:#0a6286;margin-bottom:0.625rem;display:flex;align-items:center;gap:0.375rem; }

        /* Calendar */
        .cal-hdr { display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem; }
        .cal-btn { background:none;border:1px solid #e2e8f0;border-radius:6px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#0a6286;font-size:0.8rem; }
        .cal-btn:hover { background:#f0f9ff; }
        .cal-mo { font-weight:700;font-size:0.85rem;color:#0a6286; }
        .cal-grid { display:grid;grid-template-columns:repeat(7,1fr);gap:2px; }
        .cal-dl { text-align:center;font-size:0.6rem;font-weight:700;color:#94a3b8;padding:0.2rem 0; }
        .cal-d { aspect-ratio:1;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:0.8rem;font-weight:600;cursor:pointer;transition:all 0.15s;color:#334155; }
        .cal-d:hover:not(.off):not(.emp) { background:#f0f9ff;color:#0a6286; }
        .cal-d.sel { background:#0a6286;color:#fff; }
        .cal-d.tod:not(.sel) { box-shadow:inset 0 0 0 2px #f59e0b; }
        .cal-d.off { color:#d1d5db;cursor:default; }
        .cal-d.emp { cursor:default; }

        /* Hour grid */
        .hr-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:0.375rem; }
        .hr-btn { padding:0.5rem 0.25rem;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;text-align:center;cursor:pointer;font-size:0.8rem;font-weight:600;color:#334155;transition:all 0.15s; }
        .hr-btn:hover { border-color:#0a6286;background:#f0f9ff;color:#0a6286; }
        .hr-btn.sel { border-color:#0a6286;background:#0a6286;color:#fff; }
        .hr-cnt { display:block;font-size:0.55rem;font-weight:400;color:#94a3b8;margin-top:1px; }
        .hr-btn.sel .hr-cnt { color:rgba(255,255,255,0.7); }

        /* Minute grid */
        .mn-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:0.375rem; }
        .mn-btn { padding:0.625rem 0;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;text-align:center;cursor:pointer;font-size:0.85rem;font-weight:600;color:#334155;transition:all 0.15s; }
        .mn-btn:hover { border-color:#0a6286;background:#f0f9ff;color:#0a6286; }
        .mn-btn.sel { border-color:#0a6286;background:#0a6286;color:#fff; }

        /* Selection chip */
        .sel-chip { display:inline-flex;align-items:center;gap:0.3rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:0.25rem 0.6rem;font-size:0.75rem;font-weight:600;color:#0a6286;cursor:pointer;margin-bottom:0.5rem; }
        .sel-chip i { font-size:0.6rem; }

        /* Notes */
        .notes-ta { font-size:0.85rem;padding:0.5rem 0.75rem;border-radius:8px;border:1px solid #e2e8f0;resize:vertical;min-height:50px;width:100%; }
        .notes-ta:focus { border-color:#0a6286;outline:none;box-shadow:0 0 0 3px rgba(10,98,134,0.1); }

        /* Bottom buttons */
        .bot-bar { display:flex;gap:0.5rem;margin-top:0.5rem; }
        .bot-bar .btn { flex:1;padding:0.7rem;font-weight:600;font-size:0.9rem;border-radius:10px; }

        .loading-c { text-align:center;padding:1.5rem;color:#94a3b8;font-size:0.85rem; }

        @media(min-width:420px) { .hr-grid{grid-template-columns:repeat(5,1fr)} .mn-grid{grid-template-columns:repeat(6,1fr)} }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="schedule-callback-page">

                <?php if (isset($error_message)): ?>
                <div class="alert alert-danger py-2 small"><i class="fas fa-exclamation-triangle me-1"></i><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <!-- Donor bar -->
                <div class="donor-bar">
                    <div class="donor-av"><?php echo strtoupper(substr($donor->name, 0, 1)); ?></div>
                    <div class="donor-bar-info">
                        <div class="donor-bar-name"><?php echo htmlspecialchars($donor->name); ?></div>
                        <div class="donor-bar-phone"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?></div>
                    </div>
                    <span class="status-pill"><?php echo htmlspecialchars($status_label); ?></span>
                </div>

                <!-- Step indicators -->
                <div class="steps-bar">
                    <div class="step-ind active" id="si1"><div class="step-num">1</div><div class="step-lbl">Date</div></div>
                    <div class="step-ind" id="si2"><div class="step-num">2</div><div class="step-lbl">Hour</div></div>
                    <div class="step-ind" id="si3"><div class="step-num">3</div><div class="step-lbl">Time</div></div>
                </div>

                <form method="POST" action="" id="schedule-form">
                    <input type="hidden" name="book_appointment" value="1">
                    <input type="hidden" name="appointment_time" id="sel_time" value="">
                    <input type="hidden" name="appointment_date" id="sel_date" value="" required>
                    <input type="hidden" name="reason" value="<?php echo htmlspecialchars($reason); ?>">

                    <!-- STEP 1: Date -->
                    <div class="wiz-panel active" id="wp1">
                        <div class="panel-card">
                            <div class="panel-head"><i class="fas fa-calendar-alt"></i> Pick a Date</div>
                            <div class="cal-hdr">
                                <button type="button" class="cal-btn" id="pM"><i class="fas fa-chevron-left"></i></button>
                                <div class="cal-mo" id="cM"></div>
                                <button type="button" class="cal-btn" id="nM"><i class="fas fa-chevron-right"></i></button>
                            </div>
                            <div class="cal-grid" id="cG"></div>
                        </div>
                    </div>

                    <!-- STEP 2: Hour -->
                    <div class="wiz-panel" id="wp2">
                        <div class="sel-chip" onclick="goStep(1)"><i class="fas fa-chevron-left"></i> <span id="chipDate">-</span></div>
                        <div class="panel-card">
                            <div class="panel-head"><i class="fas fa-clock"></i> Pick an Hour</div>
                            <div id="hrContainer" class="loading-c"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</div>
                        </div>
                    </div>

                    <!-- STEP 3: Minute -->
                    <div class="wiz-panel" id="wp3">
                        <div class="sel-chip" onclick="goStep(2)"><i class="fas fa-chevron-left"></i> <span id="chipHour">-</span></div>
                        <div class="panel-card">
                            <div class="panel-head"><i class="fas fa-stopwatch"></i> Pick Exact Time</div>
                            <div id="mnContainer"></div>
                        </div>
                        <div class="panel-card">
                            <label class="panel-head" for="notes" style="margin-bottom:0.375rem;"><i class="fas fa-sticky-note"></i> Notes <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                            <textarea class="notes-ta" id="notes" name="notes" placeholder="Add callback notes..."></textarea>
                        </div>
                        <div class="bot-bar">
                            <a href="call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?><?php echo $session_id > 0 ? '&session_id=' . $session_id : ''; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
                            <button type="submit" class="btn btn-success" id="bookBtn" disabled><i class="fas fa-check me-2"></i>Book</button>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-widget.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($session_id > 0): ?>
    CallWidget.init({
        sessionId: <?php echo $session_id; ?>,
        donorId: <?php echo $donor_id; ?>,
        donorName: '<?php echo addslashes($donor->name); ?>',
        donorPhone: '<?php echo addslashes($donor->phone); ?>',
        pledgeAmount: <?php echo $donor->pledge_amount; ?>,
        pledgeDate: '<?php echo $donor->pledge_date ? date('M j, Y', strtotime($donor->pledge_date)) : 'Unknown'; ?>',
        registrar: '<?php echo addslashes($donor->registrar_name); ?>',
        church: '<?php echo addslashes($donor->church_name ?? $donor->city ?? 'Unknown'); ?>'
    });
    CallWidget.pause();
    <?php endif; ?>

    document.getElementById('schedule-form').addEventListener('submit', function() {
        <?php if ($session_id > 0): ?>
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'call_duration_seconds'; inp.value = CallWidget.getDurationSeconds();
        this.appendChild(inp);
        CallWidget.pause(); CallWidget.resetState();
        <?php endif; ?>
    });
});

const agentId = <?php echo $user_id; ?>;
const dateInput = document.getElementById('sel_date');
const timeInput = document.getElementById('sel_time');
const bookBtn = document.getElementById('bookBtn');
let allSlots = []; // cached from API
let chosenDate = null, chosenHour = null;

// ========== STEP NAV ==========
function goStep(n) {
    document.querySelectorAll('.wiz-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('wp' + n).classList.add('active');
    ['si1','si2','si3'].forEach((id, i) => {
        const el = document.getElementById(id);
        el.classList.remove('active','done');
        if (i + 1 < n) el.classList.add('done');
        else if (i + 1 === n) el.classList.add('active');
    });
    window.scrollTo({top: 0, behavior: 'smooth'});
}

// ========== STEP 1: CALENDAR ==========
let curMonth = new Date();
const today = new Date(); today.setHours(0,0,0,0);
const minD = new Date(); minD.setHours(0,0,0,0);
const maxD = new Date(); maxD.setFullYear(maxD.getFullYear() + 2); maxD.setHours(0,0,0,0);
const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function renderCal() {
    const y = curMonth.getFullYear(), m = curMonth.getMonth();
    document.getElementById('cM').textContent = months[m] + ' ' + y;
    const first = new Date(y, m, 1), days = new Date(y, m+1, 0).getDate(), sw = first.getDay();
    let h = '';
    ['S','M','T','W','T','F','S'].forEach(l => { h += '<div class="cal-dl">'+l+'</div>'; });
    for (let i = 0; i < sw; i++) h += '<div class="cal-d emp"></div>';
    for (let d = 1; d <= days; d++) {
        const dt = new Date(y, m, d); dt.setHours(0,0,0,0);
        let c = 'cal-d';
        if (dt < minD || dt > maxD) c += ' off';
        if (dt.getTime() === today.getTime()) c += ' tod';
        if (chosenDate && dt.getTime() === chosenDate.getTime()) c += ' sel';
        const ds = y+'-'+String(m+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
        const oc = (dt < minD || dt > maxD) ? '' : ` onclick="pickDate('${ds}')"`;
        h += `<div class="${c}"${oc}>${d}</div>`;
    }
    document.getElementById('cG').innerHTML = h;
}
document.getElementById('pM').addEventListener('click', () => { curMonth.setMonth(curMonth.getMonth()-1); renderCal(); });
document.getElementById('nM').addEventListener('click', () => { curMonth.setMonth(curMonth.getMonth()+1); renderCal(); });

window.pickDate = function(ds) {
    const [y,m,d] = ds.split('-').map(Number);
    chosenDate = new Date(y, m-1, d); chosenDate.setHours(0,0,0,0);
    dateInput.value = ds;
    chosenHour = null;
    timeInput.value = '';
    bookBtn.disabled = true;
    renderCal();

    // Format for chip
    const opts = {weekday:'short', month:'short', day:'numeric'};
    document.getElementById('chipDate').textContent = chosenDate.toLocaleDateString('en-GB', opts);

    // Fetch slots then show step 2
    const hrC = document.getElementById('hrContainer');
    hrC.innerHTML = '<div class="loading-c"><i class="fas fa-spinner fa-spin me-1"></i> Loading hours...</div>';
    goStep(2);

    fetch('get-available-slots.php?date='+ds+'&agent_id='+agentId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.slots.length) {
                hrC.innerHTML = '<div class="text-center py-3 text-muted small"><i class="fas fa-calendar-times me-2"></i>No available slots</div>';
                return;
            }
            allSlots = data.slots;
            // Group by hour
            const grouped = {};
            data.slots.forEach(s => {
                const hr = s.hour || 'Other';
                if (!grouped[hr]) grouped[hr] = [];
                grouped[hr].push(s);
            });
            let h = '<div class="hr-grid">';
            for (const hr in grouped) {
                h += `<div class="hr-btn" onclick="pickHour('${hr}', this)">${hr}<span class="hr-cnt">${grouped[hr].length} slots</span></div>`;
            }
            h += '</div>';
            hrC.innerHTML = h;
        })
        .catch(() => { hrC.innerHTML = '<div class="text-center py-3 text-danger small">Failed to load</div>'; });
};

// ========== STEP 2: HOUR ==========
window.pickHour = function(hr, el) {
    chosenHour = hr;
    document.querySelectorAll('.hr-btn').forEach(b => b.classList.remove('sel'));
    el.classList.add('sel');

    document.getElementById('chipHour').textContent = document.getElementById('chipDate').textContent + ' \u2022 ' + hr;

    // Filter slots for this hour and render minute grid
    const slots = allSlots.filter(s => s.hour === hr);
    let h = '<div class="mn-grid">';
    slots.forEach(s => {
        h += `<div class="mn-btn" onclick="pickMin('${s.time}', this)">${s.formatted_time}</div>`;
    });
    h += '</div>';
    document.getElementById('mnContainer').innerHTML = h;

    timeInput.value = '';
    bookBtn.disabled = true;
    goStep(3);
};

// ========== STEP 3: MINUTE ==========
window.pickMin = function(time, el) {
    document.querySelectorAll('.mn-btn').forEach(b => b.classList.remove('sel'));
    el.classList.add('sel');
    timeInput.value = time;
    bookBtn.disabled = false;
};

// Init
renderCal();
</script>
</body>
</html>
