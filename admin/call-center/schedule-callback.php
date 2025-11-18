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
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor info
    $donor_query = "SELECT name, phone FROM donors WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: index.php');
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
        
        if ($appointment_date && $appointment_time) {
            // Start transaction
            $db->begin_transaction();
            
            try {
                // Insert appointment
                $appointment_query = "
                    INSERT INTO call_center_appointments 
                    (donor_id, agent_id, session_id, queue_id, appointment_date, appointment_time, 
                     slot_duration_minutes, appointment_type, status, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'callback_no_answer', 'scheduled', ?, ?, NOW())
                ";
                
                $stmt = $db->prepare($appointment_query);
                if (!$stmt) {
                    throw new Exception("Failed to prepare appointment query: " . $db->error);
                }
                
                $stmt->bind_param('iiiissisi', 
                    $donor_id, 
                    $user_id, 
                    $session_id, 
                    $queue_id, 
                    $appointment_date, 
                    $appointment_time, 
                    $slot_duration,
                    $notes,
                    $user_id
                );
                
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
                        'busy' => 'busy_signal'
                    ];
                    $outcome = $outcome_map[$status] ?? 'no_answer';
                    
                    $update_session = "
                        UPDATE call_center_sessions 
                        SET outcome = ?,
                            disposition = 'callback_scheduled_specific_time',
                            callback_scheduled_for = ?,
                            call_ended_at = NOW(),
                            notes = CONCAT(COALESCE(notes, ''), ?, ?)
                        WHERE id = ? AND agent_id = ?
                    ";
                    
                    $callback_note = "\n[Callback scheduled: {$callback_datetime}]";
                    $extra_notes = $notes ? "\nNotes: {$notes}" : '';
                    
                    $stmt = $db->prepare($update_session);
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $outcome, $callback_datetime, $callback_note, $extra_notes, $session_id, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                
                // Update queue
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
        'busy' => 'Line Busy'
    ];
    $status_label = $status_labels[$status] ?? 'No Answer';
    
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
    <style>
        .schedule-callback-page {
            max-width: 700px;
            margin: 0 auto;
            padding: 0.75rem;
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
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        
        .action-buttons .btn {
            flex: 1;
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            font-weight: 600;
        }
        
        .loading-slots {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }
        
        @media (max-width: 767px) {
            .schedule-callback-page {
                padding: 0.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }
            
            .donor-header {
                padding: 0.875rem;
            }
            
            .time-slots-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-section {
                padding: 1rem 0.875rem;
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
                    <i class="fas fa-phone-slash me-2"></i><?php echo htmlspecialchars($status_label); ?>
                </div>
                
                <form method="POST" action="" id="schedule-form">
                    <input type="hidden" name="book_appointment" value="1">
                    <input type="hidden" name="appointment_time" id="selected_time_input" value="">
                    
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
                            <i class="fas fa-clock me-2"></i>Available Time Slots (30 minutes each)
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
<script>
(function() {
    const dateInput = document.getElementById('appointment_date');
    const timeSlotsContainer = document.getElementById('time-slots-container');
    const selectedTimeInput = document.getElementById('selected_time_input');
    const bookBtn = document.getElementById('book-btn');
    const agentId = <?php echo $user_id; ?>;
    
    let selectedSlot = null;
    
    // Load available time slots when date changes
    dateInput.addEventListener('change', function() {
        const selectedDate = this.value;
        if (!selectedDate) return;
        
        loadAvailableSlots(selectedDate);
    });
    
    async function loadAvailableSlots(date) {
        timeSlotsContainer.innerHTML = '<div class="loading-slots"><i class="fas fa-spinner fa-spin me-2"></i>Loading available time slots...</div>';
        
        try {
            const response = await fetch(`get-available-slots.php?date=${date}&agent_id=${agentId}`);
            const data = await response.json();
            
            if (!data.success) {
                timeSlotsContainer.innerHTML = `<div class="alert alert-warning">${data.message || 'Failed to load time slots'}</div>`;
                return;
            }
            
            if (data.slots.length === 0) {
                timeSlotsContainer.innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No available slots for this date. Please choose another date.
                    </div>
                `;
                return;
            }
            
            // Render time slots
            let slotsHtml = '<div class="time-slots-grid">';
            data.slots.forEach(slot => {
                slotsHtml += `
                    <div class="time-slot" data-time="${slot.time}">
                        ${slot.formatted_time}
                    </div>
                `;
            });
            slotsHtml += '</div>';
            
            timeSlotsContainer.innerHTML = slotsHtml;
            
            // Add click handlers to slots
            document.querySelectorAll('.time-slot').forEach(slot => {
                slot.addEventListener('click', function() {
                    // Deselect previous
                    if (selectedSlot) {
                        selectedSlot.classList.remove('selected');
                    }
                    
                    // Select new
                    this.classList.add('selected');
                    selectedSlot = this;
                    selectedTimeInput.value = this.dataset.time;
                    bookBtn.disabled = false;
                });
            });
            
        } catch (error) {
            console.error('Error loading slots:', error);
            timeSlotsContainer.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Failed to load time slots. Please try again.
                </div>
            `;
        }
    }
    
    // Prevent form submission if no time selected
    document.getElementById('schedule-form').addEventListener('submit', function(e) {
        if (!selectedTimeInput.value) {
            e.preventDefault();
            alert('Please select a time slot');
        }
    });
})();
</script>
</body>
</html>
