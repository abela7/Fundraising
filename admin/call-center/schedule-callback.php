<?php
declare(strict_types=1);

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

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
    
    if (!$donor_id) {
        throw new Exception("Missing Donor ID");
    }

    // Create session if not exists (Lazy creation for No Answer/Busy outcomes)
    // Only on GET request to prevent duplicate creation on POST if session_id was lost
    if ($session_id <= 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $outcome_map = [
            'not_picked_up' => 'no_answer',
            'busy' => 'busy_signal',
            'busy_cant_talk' => 'callback_requested',
            'not_ready_to_pay' => 'not_ready_to_pay'
        ];
        $outcome = $outcome_map[$status] ?? 'no_answer';
        
        $stage_map = [
            'not_picked_up' => 'no_answer',
            'busy' => 'busy_signal',
            'busy_cant_talk' => 'callback_scheduled',
            'not_ready_to_pay' => 'callback_scheduled'
        ];
        $conversation_stage = $stage_map[$status] ?? 'no_answer';
        
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
    
    // Get slot duration from config
    $config_query = "SELECT setting_value FROM call_center_appointment_config WHERE setting_key = 'default_slot_duration' LIMIT 1";
    $config_result = $db->query($config_query);
    $config_row = $config_result ? $config_result->fetch_assoc() : null;
    $slot_duration = $config_row ? (int)$config_row['setting_value'] : 30;
    
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
                    $outcome_map = [
                        'not_picked_up' => 'no_answer',
                        'busy' => 'busy_signal',
                        'busy_cant_talk' => 'callback_requested',
                        'not_ready_to_pay' => 'not_ready_to_pay'
                    ];
                    $outcome = $outcome_map[$status] ?? 'no_answer';
                    
                    // Map status to conversation_stage for accurate tracking
                    $stage_map = [
                        'not_picked_up' => 'no_answer',
                        'busy' => 'busy_signal',
                        'busy_cant_talk' => 'callback_scheduled',
                        'not_ready_to_pay' => 'callback_scheduled'
                    ];
                    $conversation_stage = $stage_map[$status] ?? 'no_answer';
                    
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
                
                // Commit transaction
                $db->commit();
                
                // Redirect to success page
                header('Location: callback-scheduled.php?appointment_id=' . $appointment_id . '&donor_id=' . $donor_id);
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
        .schedule-callback-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 0.75rem;
            padding-top: 20px;
        }
        
        .content-header {
            margin-bottom: 1rem;
        }
        
        .content-title {
            font-size: 1.375rem;
            font-weight: 700;
            color: #0a6286;
            margin: 0;
        }
        
        .content-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0.25rem 0 0 0;
        }
        
        .donor-header {
            background: #0a6286;
            color: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .donor-header h4 {
            margin: 0 0 0.375rem 0;
            font-size: 1.125rem;
            font-weight: 700;
        }
        
        .donor-header .phone {
            font-size: 0.875rem;
            opacity: 0.95;
        }
        
        .status-badge {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        
        .form-section {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            margin-bottom: 1rem;
        }
        
        .form-section-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.375rem;
        }
        
        .form-control, .form-select {
            font-size: 1rem;
            padding: 0.75rem 1rem;
            border-radius: 8px;
        }
        
        /* Calendar Styles */
        .calendar-wrapper {
            margin-top: 0.75rem;
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }
        
        .calendar-nav-btn {
            background: #0a6286;
            color: white;
            border: 1px solid #0a6286;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
        }
        
        .calendar-nav-btn:hover {
            background: #084d68;
            border-color: #084d68;
            transform: scale(1.05);
        }
        
        .calendar-month {
            font-weight: 700;
            font-size: 1rem;
            color: #0a6286;
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.375rem;
        }
        
        .calendar-day-label {
            text-align: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            padding: 0.375rem;
        }
        
        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 600;
            font-size: 0.875rem;
            background: white;
        }
        
        .calendar-day:hover:not(.disabled):not(.empty) {
            border-color: #0a6286;
            background: #f0f9ff;
            transform: scale(1.05);
        }
        
        .calendar-day.selected {
            background: #0a6286;
            color: white;
            border-color: #0a6286;
        }
        
        .calendar-day.today {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        
        .calendar-day.disabled {
            background: #f8fafc;
            color: #cbd5e1;
            cursor: not-allowed;
            border-color: #f1f5f9;
        }
        
        .calendar-day.empty {
            border: none;
            cursor: default;
        }
        
        /* Native date input fallback */
        #appointment_date_native {
            font-size: 1rem;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            width: 100%;
        }
        
        /* Time Slots */
        .time-slots-container {
            margin-top: 0.75rem;
        }
        
        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }
        
        .time-slot {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem 0.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
            font-weight: 600;
            min-height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .time-slot:hover:not(.disabled) {
            border-color: #0a6286;
            background: #f0f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .time-slot.selected {
            border-color: #0a6286;
            background: #0a6286;
            color: white;
            box-shadow: 0 4px 6px -1px rgba(10, 98, 134, 0.3);
        }
        
        .time-slot.disabled {
            background: #f8fafc;
            color: #cbd5e1;
            cursor: not-allowed;
            border-color: #f1f5f9;
        }
        
        .loading-slots {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        
        .action-buttons .btn {
            flex: 1;
            padding: 0.875rem;
            font-weight: 600;
            font-size: 1rem;
        }
        
        @media (max-width: 767px) {
            .schedule-callback-page {
                padding: 0.5rem;
            }
            
            .time-slots-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.5rem;
            }
            
            .time-slot {
                padding: 0.875rem 0.5rem;
                font-size: 0.9375rem;
                min-height: 55px;
            }
            
            .calendar-grid {
                gap: 0.25rem;
            }
            
            .calendar-day {
                font-size: 0.8125rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="schedule-callback-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Schedule Callback
                    </h1>
                    <p class="content-subtitle">Select date and time for callback</p>
                </div>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Error:</strong> <?php echo htmlspecialchars($error_message); ?>
                        <?php if (isset($error_detail)): ?>
                            <br><small><?php echo htmlspecialchars($error_detail); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="donor-header">
                    <h4><?php echo htmlspecialchars($donor->name); ?></h4>
                    <div class="phone">
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor->phone); ?>
                    </div>
                </div>
                
                <div class="status-badge">
                    <?php if ($status === 'busy'): ?>
                        <i class="fas fa-ban me-2"></i>
                    <?php elseif ($status === 'busy_cant_talk'): ?>
                        <i class="fas fa-clock me-2"></i>
                    <?php elseif ($status === 'not_ready_to_pay'): ?>
                        <i class="fas fa-calendar-plus me-2"></i>
                    <?php else: ?>
                        <i class="fas fa-phone-slash me-2"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($status_label); ?>
                </div>
                
                <form method="POST" action="" id="schedule-form">
                    <input type="hidden" name="book_appointment" value="1">
                    <input type="hidden" name="appointment_time" id="selected_time_input" value="">
                    <input type="hidden" name="reason" value="<?php echo htmlspecialchars($reason); ?>">
                    
                    <!-- Date Selection -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <div>
                            <i class="fas fa-calendar me-2"></i>Select Date
                        </div>
                            <small class="text-muted" style="font-weight: 400; font-size: 0.75rem;">Tap a day to select</small>
                        </div>
                        
                        <!-- Custom Calendar UI -->
                        <div id="custom-calendar" class="calendar-wrapper">
                            <div class="calendar-header">
                                <button type="button" class="calendar-nav-btn" id="prev-month">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <div class="calendar-month" id="current-month"></div>
                                <button type="button" class="calendar-nav-btn" id="next-month">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                            <div class="calendar-grid" id="calendar-grid"></div>
                        </div>
                        
                        <!-- Hidden native date input for form submission -->
                        <input type="date" 
                               id="appointment_date" 
                               name="appointment_date" 
                               min="<?php echo date('Y-m-d'); ?>" 
                               max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                               required
                               style="position: absolute; opacity: 0; pointer-events: none;">
                        
                        <small class="text-muted d-block mt-2">
                            <i class="fas fa-info-circle me-1"></i>Select a date within the next 30 days
                        </small>
                    </div>
                    
                    <!-- Time Slot Selection -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <div>
                                <i class="fas fa-clock me-2"></i>Select Time
                            </div>
                            <small class="text-muted" style="font-weight: 400; font-size: 0.75rem;"><?php echo $slot_duration; ?> min slots</small>
                        </div>
                        <div id="time-slots-container" class="time-slots-container">
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-hand-pointer fa-2x mb-2"></i>
                                <p class="mb-0">Please select a date first</p>
                        </div>
                        </div>
                    </div>
                    
                    <!-- Notes -->
                    <div class="form-section">
                        <label for="notes" class="form-label">
                            <i class="fas fa-sticky-note me-2"></i>Notes (Optional)
                        </label>
                        <textarea class="form-control" 
                                  id="notes" 
                                  name="notes" 
                                  rows="3" 
                                  placeholder="Add any notes about this callback..."></textarea>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?><?php echo $session_id > 0 ? '&session_id=' . $session_id : ''; ?>" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <button type="submit" class="btn btn-success btn-lg" id="book-btn" disabled>
                            <i class="fas fa-check me-2"></i>Book Appointment
                        </button>
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
    // Initialize Call Widget
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if session exists, but don't auto-start 
        // (call wasn't answered, so timer shouldn't run)
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
        
        // Pause the timer since call wasn't answered
        CallWidget.pause();
        <?php endif; ?>
        
        // Form submission
        document.getElementById('schedule-form').addEventListener('submit', function(e) {
            <?php if ($session_id > 0): ?>
            // Get duration from widget
            const duration = CallWidget.getDurationSeconds();
            
            // Create hidden input
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'call_duration_seconds';
            input.value = duration;
            this.appendChild(input);
            
            // Stop timer and clear state
            CallWidget.pause();
            CallWidget.resetState();
            <?php endif; ?>
        });
    });

    // Pass agent_id to JS for fetching slots
    const agentId = <?php echo $user_id; ?>;
    
    // Date picker logic
    const dateInput = document.getElementById('appointment_date');
    const slotsContainer = document.getElementById('time-slots-container');
    const bookBtn = document.getElementById('book-btn');
    const timeInput = document.getElementById('selected_time_input');
    
    // Calendar state
    let currentMonth = new Date();
    let selectedDate = null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    const minDate = new Date();
    minDate.setHours(0, 0, 0, 0);
    
    const maxDate = new Date();
    maxDate.setDate(maxDate.getDate() + 30);
    maxDate.setHours(0, 0, 0, 0);
    
    // Render calendar
    function renderCalendar() {
        const year = currentMonth.getFullYear();
        const month = currentMonth.getMonth();
        
        // Update header
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                           'July', 'August', 'September', 'October', 'November', 'December'];
        document.getElementById('current-month').textContent = `${monthNames[month]} ${year}`;
        
        // Get first day of month and total days
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDayOfWeek = firstDay.getDay(); // 0 = Sunday
        
        // Build calendar grid
        let html = '';
        
        // Day labels
        const dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        dayLabels.forEach(label => {
            html += `<div class="calendar-day-label">${label}</div>`;
        });
        
        // Empty cells before first day
        for (let i = 0; i < startingDayOfWeek; i++) {
            html += '<div class="calendar-day empty"></div>';
        }
        
        // Days of month
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            date.setHours(0, 0, 0, 0);
            
            let classes = 'calendar-day';
            let disabled = false;
            
            // Check if date is in valid range
            if (date < minDate || date > maxDate) {
                classes += ' disabled';
                disabled = true;
            }
            
            // Check if today
            if (date.getTime() === today.getTime()) {
                classes += ' today';
            }
            
            // Check if selected
            if (selectedDate && date.getTime() === selectedDate.getTime()) {
                classes += ' selected';
            }
            
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const onClick = disabled ? '' : `onclick="selectDate('${dateStr}')"`;
            
            html += `<div class="${classes}" ${onClick}>${day}</div>`;
        }
        
        document.getElementById('calendar-grid').innerHTML = html;
    }
    
    // Select date
    window.selectDate = function(dateStr) {
        const [year, month, day] = dateStr.split('-').map(Number);
        selectedDate = new Date(year, month - 1, day);
        selectedDate.setHours(0, 0, 0, 0);
        
        // Update hidden input
        dateInput.value = dateStr;
        
        // Re-render calendar to show selection
        renderCalendar();
        
        // Load time slots
        loadTimeSlots(dateStr);
    };
    
    // Load time slots
    function loadTimeSlots(date) {
        if (!date) return;
        
        slotsContainer.innerHTML = '<div class="loading-slots"><i class="fas fa-spinner fa-spin me-2"></i>Loading available slots...</div>';
        bookBtn.disabled = true;
        timeInput.value = '';
        
        fetch(`get-available-slots.php?date=${date}&agent_id=${agentId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.slots.length === 0) {
                        slotsContainer.innerHTML = '<div class="alert alert-warning mb-0"><i class="fas fa-calendar-times me-2"></i>No available slots for this date.</div>';
                    } else {
                        let html = '<div class="time-slots-grid">';
                        data.slots.forEach(slot => {
                            html += `<div class="time-slot" onclick="selectSlot(this, '${slot.time}')">${slot.formatted_time}</div>`;
                        });
                        html += '</div>';
                        slotsContainer.innerHTML = html;
                    }
                } else {
                    slotsContainer.innerHTML = `<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i>${data.message || 'Error loading slots'}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                slotsContainer.innerHTML = '<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load slots. Please try again.</div>';
            });
    }
    
    // Navigation buttons
    document.getElementById('prev-month').addEventListener('click', function() {
        currentMonth.setMonth(currentMonth.getMonth() - 1);
        renderCalendar();
    });
    
    document.getElementById('next-month').addEventListener('click', function() {
        currentMonth.setMonth(currentMonth.getMonth() + 1);
        renderCalendar();
    });
    
    // Select time slot
    window.selectSlot = function(element, time) {
        // Remove selected class from all
        document.querySelectorAll('.time-slot').forEach(el => el.classList.remove('selected'));
        
        // Add to clicked
        element.classList.add('selected');
        
        // Update input
        timeInput.value = time;
        bookBtn.disabled = false;
    };
    
    // Initialize calendar
    renderCalendar();
</script>
</body>
</html>
