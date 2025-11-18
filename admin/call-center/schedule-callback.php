<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters from URL
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    
    if (!$donor_id || !$queue_id || !$status) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor information
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
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $callback_date = $_POST['callback_date'] ?? null;
        $callback_time = $_POST['callback_time'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Record the call session
        $call_started_at = date('Y-m-d H:i:s');
        $call_ended_at = date('Y-m-d H:i:s');
        
        // Determine conversation stage and outcome based on status
        $conversation_stage = 'no_connection';
        $outcome = 'callback_scheduled';
        
        if ($status === 'not_picked_up') {
            $conversation_stage = 'no_answer';
        } elseif ($status === 'busy') {
            $conversation_stage = 'busy';
        } elseif ($status === 'busy_cant_talk') {
            $conversation_stage = 'picked_up_busy';
            $outcome = 'callback_requested';
        } elseif ($status === 'not_working') {
            $conversation_stage = 'not_working';
            $outcome = 'number_not_working';
        }
        
        // Calculate callback datetime
        $callback_datetime = null;
        if ($callback_date && $callback_time) {
            $time_map = [
                'morning' => '10:00:00',
                'afternoon' => '14:00:00',
                'evening' => '18:00:00'
            ];
            $time_value = $time_map[$callback_time] ?? '10:00:00';
            $callback_datetime = $callback_date . ' ' . $time_value;
        }
        
        // Insert call session
        $session_query = "
            INSERT INTO call_center_sessions (
                donor_id, agent_id, queue_id, call_started_at, call_ended_at,
                conversation_stage, outcome, callback_scheduled_for, 
                preferred_callback_time, callback_reason, notes, duration_seconds
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $duration = strtotime($call_ended_at) - strtotime($call_started_at);
        $callback_reason = $status === 'busy_cant_talk' ? 'Donor can\'t talk now' : ucwords(str_replace('_', ' ', $status));
        
        $stmt = $db->prepare($session_query);
        $stmt->bind_param(
            'iiissssssssi',
            $donor_id,
            $user_id,
            $queue_id,
            $call_started_at,
            $call_ended_at,
            $conversation_stage,
            $outcome,
            $callback_datetime,
            $callback_time,
            $callback_reason,
            $notes,
            $duration
        );
        
        if ($stmt->execute()) {
            // Update queue attempts
            $update_queue = "UPDATE call_center_queues SET attempts_count = attempts_count + 1, last_attempt_at = NOW() WHERE id = ?";
            $stmt2 = $db->prepare($update_queue);
            $stmt2->bind_param('i', $queue_id);
            $stmt2->execute();
            $stmt2->close();
            
            // If callback scheduled, update queue
            if ($callback_datetime && $status !== 'not_working') {
                $update_callback = "UPDATE call_center_queues SET next_attempt_after = ? WHERE id = ?";
                $stmt3 = $db->prepare($update_callback);
                $stmt3->bind_param('si', $callback_datetime, $queue_id);
                $stmt3->execute();
                $stmt3->close();
            }
            
            $stmt->close();
            
            // Redirect to success page
            header('Location: call-complete.php?success=1&donor_id=' . $donor_id);
            exit;
        } else {
            $error_message = "Failed to save call record. Please try again.";
        }
        $stmt->close();
    }
    
    // Status labels
    $status_labels = [
        'not_picked_up' => 'No Answer',
        'busy' => 'Line Busy',
        'busy_cant_talk' => 'Picked Up - Can\'t Talk',
        'not_working' => 'Number Not Working'
    ];
    
    $status_label = $status_labels[$status] ?? 'Unknown';
    
} catch (Exception $e) {
    error_log("Schedule Callback Error: " . $e->getMessage());
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
        .schedule-page {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        .status-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-badge.danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .form-section {
            background: white;
            border: 1px solid var(--cc-border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .action-buttons .btn {
            flex: 1;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="schedule-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Schedule Callback
                    </h1>
                    <p class="content-subtitle">Set a time to call this donor again</p>
                </div>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <div class="status-badge <?php echo $status === 'not_working' ? 'danger' : 'warning'; ?>">
                    <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($status_label); ?>
                </div>
                
                <?php if ($status === 'not_working'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Number Not Working</strong> - This number appears to be unreachable. 
                        Please verify the contact details before scheduling.
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
                    
                    <?php if ($status !== 'not_working'): ?>
                        <div class="form-section">
                            <div class="mb-3">
                                <label for="callback_date" class="form-label fw-semibold">
                                    <i class="fas fa-calendar me-2"></i>Callback Date
                                </label>
                                <input type="date" 
                                       class="form-control" 
                                       id="callback_date" 
                                       name="callback_date" 
                                       min="<?php echo date('Y-m-d'); ?>" 
                                       required>
                                <small class="text-muted">Select a future date</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="callback_time" class="form-label fw-semibold">
                                    <i class="fas fa-clock me-2"></i>Preferred Time
                                </label>
                                <select class="form-select" id="callback_time" name="callback_time" required>
                                    <option value="">Choose time...</option>
                                    <option value="morning">Morning (9AM - 12PM)</option>
                                    <option value="afternoon">Afternoon (12PM - 5PM)</option>
                                    <option value="evening">Evening (5PM - 8PM)</option>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-section">
                        <label for="notes" class="form-label fw-semibold">
                            <i class="fas fa-sticky-note me-2"></i>Notes (Optional)
                        </label>
                        <textarea class="form-control" 
                                  id="notes" 
                                  name="notes" 
                                  rows="4" 
                                  placeholder="Add any additional information about this call..."></textarea>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="call-status.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo $queue_id; ?>" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save me-2"></i>Save & Continue
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

