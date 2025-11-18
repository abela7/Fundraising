<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London
date_default_timezone_set('Europe/London');

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get appointment ID
    $appointment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if (!$appointment_id) {
        header('Location: my-schedule.php');
        exit;
    }
    
    // Get appointment details with all related information
    $appointment_query = "
        SELECT 
            a.*,
            d.id as donor_id,
            d.name as donor_name,
            d.phone as donor_phone,
            d.city as donor_city,
            d.baptism_name,
            u.name as agent_name,
            u.email as agent_email,
            q.queue_type,
            q.priority as queue_priority,
            q.reason_for_queue,
            s.call_started_at,
            s.call_ended_at,
            s.outcome as session_outcome,
            s.conversation_stage,
            s.notes as session_notes
        FROM call_center_appointments a
        JOIN donors d ON a.donor_id = d.id
        JOIN users u ON a.agent_id = u.id
        LEFT JOIN call_center_queues q ON a.queue_id = q.id
        LEFT JOIN call_center_sessions s ON a.session_id = s.id
        WHERE a.id = ? AND a.agent_id = ?
    ";
    
    $stmt = $db->prepare($appointment_query);
    $stmt->bind_param('ii', $appointment_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_object();
    $stmt->close();
    
    if (!$appointment) {
        header('Location: my-schedule.php?error=not_found');
        exit;
    }
    
    // Get donor's pledge information
    $pledge_query = "
        SELECT 
            SUM(amount) as total_pledged,
            SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END) as approved_pledge,
            SUM(CASE WHEN status = 'approved' THEN COALESCE(paid_amount, 0) ELSE 0 END) as paid_amount,
            SUM(CASE WHEN status = 'approved' THEN amount - COALESCE(paid_amount, 0) ELSE 0 END) as balance
        FROM pledges
        WHERE donor_id = ?
    ";
    
    $stmt = $db->prepare($pledge_query);
    $stmt->bind_param('i', $appointment->donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pledge_info = $result->fetch_object();
    $stmt->close();
    
    // Get call history for this donor
    $history_query = "
        SELECT 
            id,
            call_started_at,
            call_ended_at,
            outcome,
            conversation_stage,
            disposition,
            notes,
            TIMESTAMPDIFF(SECOND, call_started_at, call_ended_at) as duration_seconds
        FROM call_center_sessions
        WHERE donor_id = ?
        ORDER BY call_started_at DESC
        LIMIT 10
    ";
    
    $stmt = $db->prepare($history_query);
    $stmt->bind_param('i', $appointment->donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $call_history = [];
    while ($row = $result->fetch_object()) {
        $call_history[] = $row;
    }
    $stmt->close();
    
    // Handle actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_status') {
            $new_status = $_POST['status'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            $update_query = "
                UPDATE call_center_appointments 
                SET status = ?,
                    notes = CONCAT(COALESCE(notes, ''), '\n[', NOW(), '] ', ?),
                    updated_at = NOW()
                WHERE id = ? AND agent_id = ?
            ";
            
            $stmt = $db->prepare($update_query);
            $status_note = "Status changed to: {$new_status}";
            $combined_notes = $appointment->notes . ($notes ? "\n" . $notes : '');
            $stmt->bind_param('ssii', $new_status, $status_note, $appointment_id, $user_id);
            
            if ($stmt->execute()) {
                header('Location: appointment-detail.php?id=' . $appointment_id . '&success=1');
                exit;
            }
            $stmt->close();
        }
        
        if ($action === 'cancel') {
            $reason = $_POST['cancellation_reason'] ?? '';
            
            $cancel_query = "
                UPDATE call_center_appointments 
                SET status = 'cancelled',
                    cancellation_reason = ?,
                    cancelled_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), '\n[Cancelled: ', NOW(), '] ', ?),
                    updated_at = NOW()
                WHERE id = ? AND agent_id = ?
            ";
            
            $stmt = $db->prepare($cancel_query);
            $stmt->bind_param('ssii', $reason, $reason, $appointment_id, $user_id);
            
            if ($stmt->execute()) {
                header('Location: appointment-detail.php?id=' . $appointment_id . '&success=1');
                exit;
            }
            $stmt->close();
        }
    }
    
} catch (Exception $e) {
    error_log("Appointment Detail Error: " . $e->getMessage());
    header('Location: my-schedule.php?error=1');
    exit;
}

$page_title = 'Appointment Details';
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
        .appointment-detail-page {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0.75rem;
        }
        
        .detail-header {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .appointment-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0a6286;
            margin: 0 0 0.5rem 0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .status-badge.scheduled {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge.confirmed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.completed {
            background: #e5e7eb;
            color: #374151;
        }
        
        .status-badge.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
        }
        
        .info-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .info-value {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .donor-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .donor-item {
            display: flex;
            flex-direction: column;
        }
        
        .donor-item-label {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        
        .donor-item-value {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        .pledge-highlight {
            background: linear-gradient(135deg, #0a6286 0%, #0d4f6e 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1rem;
        }
        
        .pledge-amount {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .pledge-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        
        .history-item {
            padding: 1rem;
            border-left: 4px solid #0a6286;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }
        
        .history-date {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.5rem;
        }
        
        .history-outcome {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.25rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }
        
        .btn-action {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .appointment-detail-page {
                padding: 0.5rem;
            }
            
            .header-top {
                flex-direction: column;
                gap: 1rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .donor-info {
                grid-template-columns: 1fr;
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
            <div class="appointment-detail-page">
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i>Appointment updated successfully!
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Header -->
                <div class="detail-header">
                    <div class="header-top">
                        <div>
                            <h1 class="appointment-title">
                                <i class="fas fa-calendar-check me-2"></i>Appointment Details
                            </h1>
                            <span class="status-badge <?php echo htmlspecialchars($appointment->status); ?>">
                                <?php echo ucfirst(htmlspecialchars($appointment->status)); ?>
                            </span>
                        </div>
                        <a href="my-schedule.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Schedule
                        </a>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-card">
                            <div class="info-label">Date</div>
                            <div class="info-value" id="appointment-date"><?php echo htmlspecialchars($appointment->appointment_date); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Time</div>
                            <div class="info-value" id="appointment-time"><?php echo htmlspecialchars($appointment->appointment_time); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Duration</div>
                            <div class="info-value"><?php echo (int)$appointment->slot_duration_minutes; ?> minutes</div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Type</div>
                            <div class="info-value"><?php echo ucwords(str_replace('_', ' ', htmlspecialchars($appointment->appointment_type))); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Donor Information -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-user me-2"></i>Donor Information
                    </h2>
                    <div class="donor-info">
                        <div class="donor-item">
                            <span class="donor-item-label">Full Name</span>
                            <span class="donor-item-value"><?php echo htmlspecialchars($appointment->donor_name); ?></span>
                        </div>
                        <?php if ($appointment->baptism_name): ?>
                        <div class="donor-item">
                            <span class="donor-item-label">Baptism Name</span>
                            <span class="donor-item-value"><?php echo htmlspecialchars($appointment->baptism_name); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="donor-item">
                            <span class="donor-item-label">Phone</span>
                            <span class="donor-item-value">
                                <a href="tel:<?php echo htmlspecialchars($appointment->donor_phone); ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($appointment->donor_phone); ?>
                                </a>
                            </span>
                        </div>
                        <?php if ($appointment->donor_city): ?>
                        <div class="donor-item">
                            <span class="donor-item-label">City</span>
                            <span class="donor-item-value"><?php echo htmlspecialchars($appointment->donor_city); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Pledge Information -->
                <?php if ($pledge_info && $pledge_info->total_pledged > 0): ?>
                <div class="pledge-highlight">
                    <div class="pledge-label">Total Pledged</div>
                    <div class="pledge-amount">£<?php echo number_format((float)$pledge_info->total_pledged, 2); ?></div>
                    <div style="display: flex; gap: 2rem; margin-top: 1rem; font-size: 0.875rem;">
                        <div>
                            <div style="opacity: 0.8;">Paid</div>
                            <div style="font-weight: 700; font-size: 1.125rem;">£<?php echo number_format((float)$pledge_info->paid_amount, 2); ?></div>
                        </div>
                        <div>
                            <div style="opacity: 0.8;">Balance</div>
                            <div style="font-weight: 700; font-size: 1.125rem;">£<?php echo number_format((float)$pledge_info->balance, 2); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Appointment Details -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-info-circle me-2"></i>Appointment Details
                    </h2>
                    <div class="info-grid">
                        <div class="info-card">
                            <div class="info-label">Created By</div>
                            <div class="info-value"><?php echo htmlspecialchars($appointment->agent_name); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Created At</div>
                            <div class="info-value" id="created-at"><?php echo htmlspecialchars($appointment->created_at); ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Priority</div>
                            <div class="info-value"><?php echo ucfirst(htmlspecialchars($appointment->priority)); ?></div>
                        </div>
                        <?php if ($appointment->confirmation_sent): ?>
                        <div class="info-card">
                            <div class="info-label">Confirmation Sent</div>
                            <div class="info-value"><?php echo $appointment->confirmation_sent_at ? date('M j, Y g:i A', strtotime($appointment->confirmation_sent_at)) : 'Yes'; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($appointment->notes): ?>
                    <div style="margin-top: 1rem; padding: 1rem; background: #f8fafc; border-radius: 8px;">
                        <div class="info-label">Notes</div>
                        <div style="white-space: pre-wrap; color: #475569;"><?php echo htmlspecialchars($appointment->notes); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Call History -->
                <?php if (!empty($call_history)): ?>
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-history me-2"></i>Call History (Last 10 Calls)
                    </h2>
                    <?php foreach ($call_history as $call): ?>
                    <div class="history-item">
                        <div class="history-date" id="call-date-<?php echo $call->id; ?>">
                            <?php echo htmlspecialchars($call->call_started_at); ?>
                        </div>
                        <div class="history-outcome">
                            <strong>Outcome:</strong> <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($call->outcome ?? 'N/A'))); ?>
                        </div>
                        <?php if ($call->conversation_stage): ?>
                        <div class="history-outcome">
                            <strong>Stage:</strong> <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($call->conversation_stage))); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($call->duration_seconds): ?>
                        <div class="history-outcome">
                            <strong>Duration:</strong> <?php echo gmdate('H:i:s', (int)$call->duration_seconds); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($call->notes): ?>
                        <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #e2e8f0; font-size: 0.875rem; color: #64748b;">
                            <?php echo htmlspecialchars($call->notes); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Actions -->
                <div class="section">
                    <h2 class="section-title">
                        <i class="fas fa-cog me-2"></i>Actions
                    </h2>
                    <div class="action-buttons">
                        <?php if ($appointment->status === 'scheduled'): ?>
                        <button class="btn btn-success btn-action" onclick="updateStatus('confirmed')">
                            <i class="fas fa-check me-2"></i>Mark as Confirmed
                        </button>
                        <?php endif; ?>
                        
                        <?php if (in_array($appointment->status, ['scheduled', 'confirmed'])): ?>
                        <button class="btn btn-primary btn-action" onclick="updateStatus('in_progress')">
                            <i class="fas fa-phone me-2"></i>Start Call
                        </button>
                        <button class="btn btn-success btn-action" onclick="updateStatus('completed')">
                            <i class="fas fa-check-circle me-2"></i>Mark Complete
                        </button>
                        <?php endif; ?>
                        
                        <?php if (!in_array($appointment->status, ['completed', 'cancelled'])): ?>
                        <button class="btn btn-warning btn-action" onclick="showCancelModal()">
                            <i class="fas fa-times me-2"></i>Cancel Appointment
                        </button>
                        <?php endif; ?>
                        
                        <a href="make-call.php?donor_id=<?php echo $appointment->donor_id; ?>&queue_id=<?php echo $appointment->queue_id ?? 0; ?>" 
                           class="btn btn-outline-primary btn-action">
                            <i class="fas fa-phone-alt me-2"></i>Make Call Now
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Appointment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="cancel">
                    <div class="mb-3">
                        <label class="form-label">Reason for Cancellation</label>
                        <textarea class="form-control" name="cancellation_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Cancel Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function updateStatus(status) {
    if (confirm(`Are you sure you want to mark this appointment as "${status}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="status" value="${status}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function showCancelModal() {
    const modal = new bootstrap.Modal(document.getElementById('cancelModal'));
    modal.show();
}

// Format dates and times
(function() {
    const dateStr = <?php echo json_encode($appointment->appointment_date); ?>;
    const timeStr = <?php echo json_encode($appointment->appointment_time); ?>;
    
    if (dateStr) {
        const [year, month, day] = dateStr.split('-');
        const dateObj = new Date(parseInt(year), parseInt(month) - 1, parseInt(day));
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];
        const formattedDate = days[dateObj.getDay()] + ', ' + months[dateObj.getMonth()] + ' ' + 
                             dateObj.getDate() + ', ' + dateObj.getFullYear();
        document.getElementById('appointment-date').textContent = formattedDate;
    }
    
    if (timeStr) {
        const [hours, minutes] = timeStr.split(':');
        let h = parseInt(hours);
        const m = parseInt(minutes);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        h = h ? h : 12;
        const formattedTime = h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
        document.getElementById('appointment-time').textContent = formattedTime;
    }
    
    // Format created at
    const createdStr = <?php echo json_encode($appointment->created_at); ?>;
    if (createdStr) {
        const createdDate = new Date(createdStr);
        document.getElementById('created-at').textContent = createdDate.toLocaleString('en-GB', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    // Format call history dates
    <?php foreach ($call_history as $call): ?>
    const callDate<?php echo $call->id; ?> = new Date(<?php echo json_encode($call->call_started_at); ?>);
    document.getElementById('call-date-<?php echo $call->id; ?>').textContent = callDate<?php echo $call->id; ?>.toLocaleString('en-GB', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    <?php endforeach; ?>
})();
</script>
</body>
</html>

