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
    
    // Get appointment details with all related info
    $appointment_query = "
        SELECT 
            a.*,
            d.id as donor_id,
            d.name as donor_name,
            d.phone as donor_phone,
            d.city as donor_city,
            d.baptism_name,
            u.name as agent_name,
            u.id as agent_id,
            s.id as session_id,
            s.call_started_at,
            s.call_ended_at,
            s.outcome as session_outcome,
            s.notes as session_notes,
            q.id as queue_id,
            q.queue_type,
            q.priority as queue_priority,
            q.reason_for_queue
        FROM call_center_appointments a
        JOIN donors d ON a.donor_id = d.id
        JOIN users u ON a.agent_id = u.id
        LEFT JOIN call_center_sessions s ON a.session_id = s.id
        LEFT JOIN call_center_queues q ON a.queue_id = q.id
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
    
    // Calculate end time
    $start_time = new DateTime($appointment->appointment_date . ' ' . $appointment->appointment_time);
    $end_time = clone $start_time;
    $end_time->modify('+' . $appointment->slot_duration_minutes . ' minutes');
    
    // Get call history for this donor
    $history_query = "
        SELECT 
            id,
            call_started_at,
            call_ended_at,
            outcome,
            conversation_stage,
            disposition,
            notes
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
            max-width: 900px;
            margin: 0 auto;
            padding: 0.75rem;
        }
        
        .detail-header {
            background: #0a6286;
            color: white;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .detail-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
        }
        
        .detail-header .subtitle {
            font-size: 0.875rem;
            opacity: 0.95;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-top: 0.5rem;
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
        
        .info-section {
            background: white;
            border-radius: 12px;
            padding: 1.25rem 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-size: 0.9375rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .history-item {
            padding: 0.875rem;
            border-left: 3px solid #0a6286;
            background: #f8fafc;
            border-radius: 6px;
            margin-bottom: 0.75rem;
        }
        
        .history-date {
            font-size: 0.8125rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.25rem;
        }
        
        .history-outcome {
            font-size: 0.875rem;
            color: #475569;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            flex: 1;
            min-width: 150px;
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons .btn {
                flex: 1 1 100%;
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
                <!-- Header -->
                <div class="detail-header">
                    <h1>
                        <i class="fas fa-calendar-check me-2"></i>
                        Appointment Details
                    </h1>
                    <div class="subtitle">Complete information about this scheduled callback</div>
                    <span class="status-badge <?php echo htmlspecialchars($appointment->status); ?>">
                        <?php echo ucfirst(htmlspecialchars($appointment->status)); ?>
                    </span>
                </div>
                
                <!-- Appointment Info -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-clock"></i>Appointment Information
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Date</span>
                            <span class="info-value" id="appointment-date"><?php echo htmlspecialchars($appointment->appointment_date); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Time</span>
                            <span class="info-value" id="appointment-time"><?php echo htmlspecialchars($appointment->appointment_time); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Duration</span>
                            <span class="info-value"><?php echo (int)$appointment->slot_duration_minutes; ?> minutes</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">End Time</span>
                            <span class="info-value" id="end-time"><?php echo $end_time->format('H:i:s'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Type</span>
                            <span class="info-value"><?php echo ucwords(str_replace('_', ' ', htmlspecialchars($appointment->appointment_type))); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Priority</span>
                            <span class="info-value"><?php echo ucfirst(htmlspecialchars($appointment->priority)); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Donor Info -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-user"></i>Donor Information
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($appointment->donor_name); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($appointment->donor_phone); ?>" class="text-decoration-none">
                                    <?php echo htmlspecialchars($appointment->donor_phone); ?>
                                </a>
                            </span>
                        </div>
                        <?php if ($appointment->baptism_name): ?>
                        <div class="info-item">
                            <span class="info-label">Baptism Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($appointment->baptism_name); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($appointment->donor_city): ?>
                        <div class="info-item">
                            <span class="info-label">City</span>
                            <span class="info-value"><?php echo htmlspecialchars($appointment->donor_city); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Agent Info -->
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-user-tie"></i>Agent Information
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Assigned Agent</span>
                            <span class="info-value"><?php echo htmlspecialchars($appointment->agent_name); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created By</span>
                            <span class="info-value"><?php echo htmlspecialchars($appointment->agent_name); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Created At</span>
                            <span class="info-value"><?php echo date('M j, Y g:i A', strtotime($appointment->created_at)); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Notes -->
                <?php if ($appointment->notes): ?>
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-sticky-note"></i>Notes
                    </div>
                    <p style="color: #475569; margin: 0;"><?php echo nl2br(htmlspecialchars($appointment->notes)); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Call History -->
                <?php if (count($call_history) > 0): ?>
                <div class="info-section">
                    <div class="section-title">
                        <i class="fas fa-history"></i>Call History (Last 10 calls)
                    </div>
                    <?php foreach ($call_history as $history): ?>
                    <div class="history-item">
                        <div class="history-date">
                            <?php echo date('M j, Y g:i A', strtotime($history->call_started_at)); ?>
                        </div>
                        <div class="history-outcome">
                            <strong>Outcome:</strong> <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($history->outcome ?? 'N/A'))); ?><br>
                            <strong>Stage:</strong> <?php echo ucwords(str_replace('_', ' ', htmlspecialchars($history->conversation_stage ?? 'N/A'))); ?>
                            <?php if ($history->notes): ?>
                            <br><strong>Notes:</strong> <?php echo htmlspecialchars(substr($history->notes, 0, 100)); ?><?php echo strlen($history->notes) > 100 ? '...' : ''; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Actions -->
                <div class="action-buttons">
                    <a href="my-schedule.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Schedule
                    </a>
                    <?php if ($appointment->status === 'scheduled' || $appointment->status === 'confirmed'): ?>
                    <a href="make-call.php?donor_id=<?php echo $appointment->donor_id; ?>&queue_id=<?php echo $appointment->queue_id ?? 0; ?>" class="btn btn-success">
                        <i class="fas fa-phone me-2"></i>Start Call
                    </a>
                    <?php endif; ?>
                    <a href="call-history.php?donor_id=<?php echo $appointment->donor_id; ?>" class="btn btn-info">
                        <i class="fas fa-history me-2"></i>Full History
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
(function() {
    // Format date and time nicely
    const dateStr = <?php echo json_encode($appointment->appointment_date); ?>;
    const timeStr = <?php echo json_encode($appointment->appointment_time); ?>;
    const endTimeStr = <?php echo json_encode($end_time->format('H:i:s')); ?>;
    
    if (dateStr && timeStr) {
        const [year, month, day] = dateStr.split('-');
        const dateObj = new Date(parseInt(year), parseInt(month) - 1, parseInt(day));
        
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];
        const formattedDate = days[dateObj.getDay()] + ', ' + months[dateObj.getMonth()] + ' ' + 
                             dateObj.getDate() + ', ' + dateObj.getFullYear();
        
        const [hours, minutes] = timeStr.split(':');
        const [endHours, endMinutes] = endTimeStr.split(':');
        
        let h = parseInt(hours);
        const m = parseInt(minutes);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        h = h ? h : 12;
        const formattedTime = h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
        
        let eh = parseInt(endHours);
        const em = parseInt(endMinutes);
        const eampm = eh >= 12 ? 'PM' : 'AM';
        eh = eh % 12;
        eh = eh ? eh : 12;
        const formattedEndTime = eh + ':' + String(em).padStart(2, '0') + ' ' + eampm;
        
        document.getElementById('appointment-date').textContent = formattedDate;
        document.getElementById('appointment-time').textContent = formattedTime;
        document.getElementById('end-time').textContent = formattedEndTime;
    }
})();
</script>
</body>
</html>

