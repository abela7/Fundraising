<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone
date_default_timezone_set('Europe/London');

$conn = get_db_connection();
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($session_id <= 0 || $donor_id <= 0) {
    header('Location: view-donor.php?id=' . $donor_id);
    exit;
}

// Fetch call session details
try {
    $query = "
        SELECT 
            ccs.*,
            d.name as donor_name,
            d.phone as donor_phone,
            u.name as agent_name,
            dpp.id as has_payment_plan
        FROM call_center_sessions ccs
        JOIN donors d ON ccs.donor_id = d.id
        LEFT JOIN users u ON ccs.agent_id = u.id
        LEFT JOIN donor_payment_plans dpp ON ccs.payment_plan_id = dpp.id
        WHERE ccs.id = ? AND ccs.donor_id = ?
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param('ii', $session_id, $donor_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $session = $result->fetch_object();
    
    if (!$session) {
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Call session not found'));
        exit;
    }
    
    // Check for linked appointment
    $appt_query = "SELECT id FROM call_center_appointments WHERE session_id = ?";
    $appt_stmt = $conn->prepare($appt_query);
    if (!$appt_stmt) {
        throw new Exception("Prepare failed for appointment query: " . $conn->error);
    }
    $appt_stmt->bind_param('i', $session_id);
    if (!$appt_stmt->execute()) {
        throw new Exception("Execute failed for appointment query: " . $appt_stmt->error);
    }
    $appt_result = $appt_stmt->get_result();
    $has_appointment = $appt_result->num_rows > 0;
    
} catch (mysqli_sql_exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Database error: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ')'));
    exit;
} catch (Exception $e) {
    header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Error: ' . $e->getMessage()));
    exit;
}

// Handle deletion confirmation
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $unlink_plan = isset($_POST['unlink_plan']) ? $_POST['unlink_plan'] : 'no';
    
    try {
        $conn->begin_transaction();
        
        // Step 1: Delete child records that reference this session (RESTRICT constraints)
        // Delete call_center_attempt_log records
        $delete_attempt_log_query = "DELETE FROM call_center_attempt_log WHERE session_id = ?";
        $delete_attempt_stmt = $conn->prepare($delete_attempt_log_query);
        $delete_attempt_stmt->bind_param('i', $session_id);
        $delete_attempt_stmt->execute();
        
        // Delete call_center_sms_log records
        $delete_sms_log_query = "DELETE FROM call_center_sms_log WHERE session_id = ?";
        $delete_sms_stmt = $conn->prepare($delete_sms_log_query);
        $delete_sms_stmt->bind_param('i', $session_id);
        $delete_sms_stmt->execute();
        
        // Delete call_center_workflow_executions records
        $delete_workflow_query = "DELETE FROM call_center_workflow_executions WHERE session_id = ?";
        $delete_workflow_stmt = $conn->prepare($delete_workflow_query);
        $delete_workflow_stmt->bind_param('i', $session_id);
        $delete_workflow_stmt->execute();
        
        // Delete call_center_conversation_steps (CASCADE, but let's be explicit)
        $delete_steps_query = "DELETE FROM call_center_conversation_steps WHERE session_id = ?";
        $delete_steps_stmt = $conn->prepare($delete_steps_query);
        $delete_steps_stmt->bind_param('i', $session_id);
        $delete_steps_stmt->execute();
        
        // Delete call_center_responses (CASCADE, but let's be explicit)
        $delete_responses_query = "DELETE FROM call_center_responses WHERE session_id = ?";
        $delete_responses_stmt = $conn->prepare($delete_responses_query);
        $delete_responses_stmt->bind_param('i', $session_id);
        $delete_responses_stmt->execute();
        
        // Step 2: Delete linked appointment if exists (ON DELETE SET NULL, but let's be explicit)
        if ($has_appointment) {
            $delete_appt_query = "DELETE FROM call_center_appointments WHERE session_id = ?";
            $delete_appt_stmt = $conn->prepare($delete_appt_query);
            $delete_appt_stmt->bind_param('i', $session_id);
            $delete_appt_stmt->execute();
        }
        
        // Step 3: Handle payment plan if exists
        if (!empty($session->payment_plan_id)) {
            $plan_id = (int)$session->payment_plan_id;
            
            if ($unlink_plan === 'delete') {
                // First, unlink this session from the plan
                $unlink_session_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE id = ?";
                $unlink_session_stmt = $conn->prepare($unlink_session_query);
                $unlink_session_stmt->bind_param('i', $session_id);
                $unlink_session_stmt->execute();
                
                // Delete the payment plan
                
                // Unlink all other sessions from this plan first
                $unlink_all_sessions_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
                $unlink_all_stmt = $conn->prepare($unlink_all_sessions_query);
                $unlink_all_stmt->bind_param('i', $plan_id);
                $unlink_all_stmt->execute();
                
                // Reset donor if this is their active plan
                $reset_donor_query = "
                    UPDATE donors 
                    SET active_payment_plan_id = NULL,
                        has_active_plan = 0,
                        payment_status = CASE 
                            WHEN balance > 0 THEN 'not_started'
                            WHEN balance = 0 THEN 'completed'
                            ELSE 'no_pledge'
                        END
                    WHERE id = ? AND active_payment_plan_id = ?
                ";
                $reset_stmt = $conn->prepare($reset_donor_query);
                $reset_stmt->bind_param('ii', $donor_id, $plan_id);
                $reset_stmt->execute();
                
                // Delete the plan
                $delete_plan_query = "DELETE FROM donor_payment_plans WHERE id = ?";
                $delete_plan_stmt = $conn->prepare($delete_plan_query);
                $delete_plan_stmt->bind_param('i', $plan_id);
                $delete_plan_stmt->execute();
            } else {
                // Just unlink this session from the plan
                $unlink_session_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE id = ?";
                $unlink_session_stmt = $conn->prepare($unlink_session_query);
                $unlink_session_stmt->bind_param('i', $session_id);
                $unlink_session_stmt->execute();
            }
        }
        
        // Step 4: Delete the call session (now safe - all child records are gone)
        $delete_query = "DELETE FROM call_center_sessions WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $session_id);
        $delete_stmt->execute();
        
        $conn->commit();
        
        $message = 'Call session deleted successfully.';
        if ($has_appointment) {
            $message .= ' Linked appointment also deleted.';
        }
        if (!empty($session->payment_plan_id) && $unlink_plan === 'delete') {
            $message .= ' Payment plan also deleted.';
        }
        
        header('Location: view-donor.php?id=' . $donor_id . '&success=' . urlencode($message));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        header('Location: view-donor.php?id=' . $donor_id . '&error=' . urlencode('Failed to delete: ' . $e->getMessage()));
        exit;
    }
}

// Calculate duration display
$duration_display = '—';
$duration_sec = (int)($session->duration_seconds ?? 0);

if ($duration_sec > 0) {
    $minutes = floor($duration_sec / 60);
    $seconds = $duration_sec % 60;
    if ($minutes > 0) {
        $duration_display = $minutes . 'm ' . $seconds . 's';
    } else {
        $duration_display = $seconds . 's';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Delete Call Session</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .confirm-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }
        .confirm-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px 16px 0 0;
            text-align: center;
        }
        .confirm-header i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        .confirm-body {
            padding: 2rem;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #1e3c72;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .danger-box {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        .choice-box {
            background: #e7f3ff;
            border: 2px solid #007bff;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
        }
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .action-buttons .btn {
            flex: 1;
            padding: 0.75rem;
            font-weight: 600;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #6c757d;
        }
        .radio-option {
            padding: 0.75rem;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .radio-option:hover {
            border-color: #007bff;
            background: #f8f9fa;
        }
        .radio-option input[type="radio"]:checked + label {
            font-weight: 600;
            color: #007bff;
        }
        
        @media (max-width: 768px) {
            .confirm-header {
                padding: 1.5rem;
            }
            .confirm-header i {
                font-size: 3rem;
            }
            .confirm-body {
                padding: 1.5rem;
            }
            .action-buttons {
                flex-direction: column-reverse;
            }
        }
    </style>
</head>
<body>
    <div class="confirm-card">
        <div class="confirm-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h2 class="mb-0">Confirm Delete Call Session</h2>
        </div>
        
        <div class="confirm-body">
            <div class="danger-box">
                <strong><i class="fas fa-exclamation-circle me-2"></i>Warning:</strong>
                You are about to permanently delete this call record. This action cannot be undone!
            </div>
            
            <div class="info-box">
                <h6 class="mb-3"><strong>Call Session Details</strong></h6>
                <div class="detail-row">
                    <span class="detail-label">Donor:</span>
                    <span><?php echo htmlspecialchars($session->donor_name); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span><?php echo htmlspecialchars($session->donor_phone); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Agent:</span>
                    <span><?php echo htmlspecialchars($session->agent_name ?? 'Unknown'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Date & Time:</span>
                    <span><?php echo date('M d, Y H:i', strtotime($session->call_started_at)); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Duration:</span>
                    <span><?php echo $duration_display; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Outcome:</span>
                    <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $session->outcome ?? 'none')); ?></span>
                </div>
                <?php if ($session->notes): ?>
                <div class="detail-row">
                    <span class="detail-label">Notes:</span>
                    <span><?php echo htmlspecialchars(substr($session->notes, 0, 100)); ?><?php echo strlen($session->notes) > 100 ? '...' : ''; ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($has_appointment): ?>
            <div class="warning-box">
                <strong><i class="fas fa-calendar-alt me-2"></i>Linked Appointment:</strong>
                This call session has a scheduled callback appointment. The appointment will also be deleted.
            </div>
            <?php endif; ?>
            
            <?php if (!empty($session->payment_plan_id)): ?>
            <div class="choice-box">
                <h6 class="mb-3"><strong><i class="fas fa-credit-card me-2"></i>Payment Plan Linked</strong></h6>
                <p>This call resulted in a payment plan. What would you like to do?</p>
                <div class="radio-option">
                    <input type="radio" name="plan_choice" id="unlink" value="unlink" checked>
                    <label for="unlink" class="ms-2">Just unlink the plan (keep it active)</label>
                </div>
                <div class="radio-option">
                    <input type="radio" name="plan_choice" id="delete_plan" value="delete">
                    <label for="delete_plan" class="ms-2">Delete the payment plan too</label>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="info-box mt-3">
                <h6 class="mb-2"><strong>What will happen:</strong></h6>
                <ul class="mb-0">
                    <li>The call session will be permanently deleted</li>
                    <?php if ($has_appointment): ?>
                    <li>The linked appointment will be deleted</li>
                    <?php endif; ?>
                    <?php if (!empty($session->payment_plan_id)): ?>
                    <li id="plan-action-text">The payment plan will be unlinked (but kept active)</li>
                    <?php else: ?>
                    <li>No other records will be affected</li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <form method="POST">
                <?php if (!empty($session->payment_plan_id)): ?>
                <input type="hidden" name="unlink_plan" id="unlink_plan_value" value="unlink">
                <?php endif; ?>
                
                <div class="action-buttons">
                    <a href="view-donor.php?id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash-alt me-2"></i>Yes, Delete Call Session
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($session->has_payment_plan): ?>
    <script>
        // Update hidden input based on radio selection
        document.querySelectorAll('input[name="plan_choice"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.getElementById('unlink_plan_value').value = this.value;
                const actionText = document.getElementById('plan-action-text');
                if (this.value === 'delete') {
                    actionText.textContent = 'The payment plan will also be deleted';
                } else {
                    actionText.textContent = 'The payment plan will be unlinked (but kept active)';
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>

