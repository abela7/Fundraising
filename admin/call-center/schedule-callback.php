<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London for call center
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    $user_name = $_SESSION['user']['name'] ?? 'Unknown';
    
    // Get parameters
    $session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $status = isset($_GET['status']) ? $_GET['status'] : 'not_picked_up';
    $reason = isset($_GET['reason']) ? $_GET['reason'] : '';
    
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
        header('Location: ../donor-management/donors.php');
        exit;
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
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: ../donor-management/donors.php');
        exit;
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
                // Determine appointment type
                $appointment_type = 'callback_no_answer';
                if ($status === 'busy') {
                    $appointment_type = 'callback_busy';
                } elseif ($status === 'busy_cant_talk') {
                    $appointment_type = 'callback_rescheduled';
                } elseif ($status === 'not_ready_to_pay') {
                    $appointment_type = 'callback_not_ready';
                }
                
                // Prepare queue_id for binding (NULL if 0)
                $queue_id_param = ($queue_id > 0) ? $queue_id : null;

                // Insert appointment - handle NULL session_id for foreign key constraint
                if ($session_id > 0) {
                    // Session exists - include session_id
                    $appointment_query = "
                        INSERT INTO call_center_appointments 
                        (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, 
                         slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW())
                    ";
                    
                    $stmt = $db->prepare($appointment_query);
                    if (!$stmt) {
                        throw new Exception("Failed to prepare appointment query: " . $db->error);
                    }
                    
                    $stmt->bind_param('iiiississi', 
                        $donor_id, 
                        $user_id, 
                        $session_id, 
                        $queue_id_param, 
                        $appointment_date, 
                        $appointment_time, 
                        $slot_duration,
                        $appointment_type,
                        $notes,
                        $user_id
                    );
                } else {
                    // No session - use NULL for session_id
                    $appointment_query = "
                        INSERT INTO call_center_appointments 
                        (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, 
                         slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                        VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'scheduled', ?, ?, NOW())
                    ";
                    
                    $stmt = $db->prepare($appointment_query);
                    if (!$stmt) {
                        throw new Exception("Failed to prepare appointment query: " . $db->error);
                    }
                    
                    $stmt->bind_param('iiississi', 
                        $donor_id, 
                        $user_id, 
                        $queue_id_param, 
                        $appointment_date, 
                        $appointment_time, 
                        $slot_duration,
                        $appointment_type,
                        $notes,
                        $user_id
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
                    
                    $update_session = "
                        UPDATE call_center_sessions 
                        SET outcome = ?,
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
                        $stmt->bind_param('sssisii', $outcome, $callback_datetime, $reason, $duration_seconds, $callback_note, $extra_notes, $session_id, $user_id);
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
                
            } catch (Exception $e) {
                $db->rollback();
                $error_message = "Failed to schedule callback: " . $e->getMessage();
                error_log("Schedule Callback Error: " . $e->getMessage());
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
    
} catch (Exception $e) {
    error_log("Schedule Callback Page Error: " . $e->getMessage());
    $error_message = "An error occurred. Please try again.";
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
        }
        
        .form-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.375rem;
        }
        
        .form-control, .form-select {
            font-size: 0.9375rem;
            padding: 0.625rem 0.875rem;
        }
        
        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.625rem;
            margin-top: 0.75rem;
        }
        
        .time-slot {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem 0.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .time-slot:hover:not(.disabled) {
            border-color: #0a6286;
            background: #f0f9ff;
        }
        
        .time-slot.selected {
            border-color: #0a6286;
            background: #0a6286;
            color: white;
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
            padding: 0.75rem;
            font-weight: 600;
        }
        
        @media (max-width: 767px) {
            .time-slots-grid {
                grid-template-columns: repeat(2, 1fr);
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
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
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
                            <i class="fas fa-calendar me-2"></i>Select Date
                        </div>
                        <input type="date" 
                               class="form-control" 
                               id="appointment_date" 
                               name="appointment_date" 
                               min="<?php echo date('Y-m-d'); ?>" 
                               max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                               required>
                        <small class="text-muted d-block mt-2">Choose a date within the next 30 days</small>
                    </div>
                    
                    <!-- Time Slot Selection -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-clock me-2"></i>Available Time Slots (<?php echo $slot_duration; ?> minutes each)
                        </div>
                        <div id="time-slots-container" class="loading-slots">
                            <i class="fas fa-spinner fa-spin me-2"></i>Select a date to see available time slots
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
        
        // Form submission
        document.getElementById('schedule-form').addEventListener('submit', function(e) {
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
        });
    });

    // Pass agent_id to JS for fetching slots
    const agentId = <?php echo $user_id; ?>;
    
    // Date picker logic
    const dateInput = document.getElementById('appointment_date');
    const slotsContainer = document.getElementById('time-slots-container');
    const bookBtn = document.getElementById('book-btn');
    const timeInput = document.getElementById('selected_time_input');
    
    // Set default date to tomorrow if today is late
    // ... (rest of logic remains same but loaded via fetch if needed) ...
</script>
<script>
    // Re-adding the inline script logic for slots from previous version
    document.getElementById('appointment_date').addEventListener('change', function() {
        const date = this.value;
        if (!date) return;
        
        slotsContainer.innerHTML = '<div class="loading-slots"><i class="fas fa-spinner fa-spin me-2"></i>Loading available slots...</div>';
        bookBtn.disabled = true;
        timeInput.value = '';
        
        fetch(`get-available-slots.php?date=${date}&agent_id=${agentId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.slots.length === 0) {
                        slotsContainer.innerHTML = '<div class="alert alert-warning mb-0">No available slots for this date.</div>';
                    } else {
                        let html = '';
                        data.slots.forEach(slot => {
                            html += `<div class="time-slot" onclick="selectSlot(this, '${slot.time}')">${slot.formatted_time}</div>`;
                        });
                        slotsContainer.innerHTML = html;
                    }
                } else {
                    slotsContainer.innerHTML = `<div class="alert alert-danger mb-0">${data.message || 'Error loading slots'}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                slotsContainer.innerHTML = '<div class="alert alert-danger mb-0">Failed to load slots. Please try again.</div>';
            });
    });
    
    function selectSlot(element, time) {
        // Remove selected class from all
        document.querySelectorAll('.time-slot').forEach(el => el.classList.remove('selected'));
        
        // Add to clicked
        element.classList.add('selected');
        
        // Update input
        timeInput.value = time;
        bookBtn.disabled = false;
    }
    
    // Trigger change if date is pre-filled
    if (dateInput.value) {
        dateInput.dispatchEvent(new Event('change'));
    }
</script>
</body>
</html>
