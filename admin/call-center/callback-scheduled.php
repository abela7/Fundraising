<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

// Set timezone to London
date_default_timezone_set('Europe/London');

try {
    $db = db();
    
    // Get parameters
    $appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    
    if (!$appointment_id || !$donor_id) {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // Get appointment details
    $appointment_query = "
        SELECT 
            a.*,
            d.name as donor_name,
            d.phone as donor_phone,
            u.name as agent_name
        FROM call_center_appointments a
        JOIN donors d ON a.donor_id = d.id
        JOIN users u ON a.agent_id = u.id
        WHERE a.id = ? AND a.donor_id = ?
    ";
    
    $stmt = $db->prepare($appointment_query);
    $stmt->bind_param('ii', $appointment_id, $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_object();
    $stmt->close();
    
    if (!$appointment) {
        header('Location: ../donor-management/donors.php');
        exit;
    }
    
    // Check SMS status
    $sms_status = $_GET['sms'] ?? null;
    $sms_error = isset($_GET['sms_error']) ? urldecode($_GET['sms_error']) : null;
    
} catch (Exception $e) {
    error_log("Callback Scheduled Error: " . $e->getMessage());
    header('Location: ../donor-management/donors.php?error=1');
    exit;
}

$page_title = 'Callback Scheduled';
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
        .callback-scheduled-page {
            max-width: 600px;
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
        
        .success-card {
            background: white;
            border: 2px solid #22c55e;
            border-radius: 12px;
            padding: 1.5rem 1rem;
            text-align: center;
            margin-bottom: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        
        .success-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #f0fdf4;
            color: #22c55e;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }
        
        .success-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .success-desc {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0;
        }
        
        .appointment-details {
            background: #f8fafc;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: left;
        }
        
        .appointment-details-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0a6286;
            margin-bottom: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.625rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 0.875rem;
            color: #1e293b;
            font-weight: 700;
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
        
        @media (max-width: 767px) {
            .callback-scheduled-page {
                padding: 0.5rem;
            }
            
            .content-title {
                font-size: 1.25rem;
            }
            
            .success-card {
                padding: 1.25rem 1rem;
            }
            
            .success-icon {
                width: 60px;
                height: 60px;
                font-size: 1.75rem;
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
            <div class="callback-scheduled-page">
                <div class="content-header mb-4">
                    <h1 class="content-title">
                        <i class="fas fa-check-circle me-2"></i>
                        Callback Scheduled
                    </h1>
                    <p class="content-subtitle">The callback has been successfully scheduled</p>
                </div>
                
                <div class="success-card">
                    <div class="success-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="success-title">Appointment Booked!</div>
                    <div class="success-desc">
                        A <?php echo (int)$appointment->slot_duration_minutes; ?>-minute callback slot has been reserved for this donor.
                    </div>
                </div>
                
                <?php if ($sms_status === 'sent'): ?>
                <div class="alert alert-success d-flex align-items-center mb-3">
                    <i class="fas fa-check-circle me-3 fa-lg"></i>
                    <div>
                        <strong>SMS Sent Successfully!</strong><br>
                        <small class="text-muted">The donor has been notified about the missed call.</small>
                    </div>
                </div>
                <?php elseif ($sms_status === 'failed'): ?>
                <div class="alert alert-warning d-flex align-items-center mb-3">
                    <i class="fas fa-exclamation-triangle me-3 fa-lg"></i>
                    <div>
                        <strong>SMS Failed to Send</strong><br>
                        <small><?php echo htmlspecialchars($sms_error ?? 'Unknown error'); ?></small>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="appointment-details">
                    <div class="appointment-details-title">
                        <i class="fas fa-info-circle me-1"></i>Appointment Details
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Donor</span>
                        <span class="detail-value"><?php echo htmlspecialchars($appointment->donor_name); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Phone</span>
                        <span class="detail-value"><?php echo htmlspecialchars($appointment->donor_phone); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date</span>
                        <span class="detail-value" id="appointment-date"><?php echo htmlspecialchars($appointment->appointment_date); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Time</span>
                        <span class="detail-value" id="appointment-time"><?php echo htmlspecialchars($appointment->appointment_time); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Duration</span>
                        <span class="detail-value"><?php echo (int)$appointment->slot_duration_minutes; ?> minutes</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Agent</span>
                        <span class="detail-value"><?php echo htmlspecialchars($appointment->agent_name); ?></span>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-phone me-2"></i>Continue Calling
                    </a>
                    <a href="../donor-management/donors.php" class="btn btn-outline-secondary">
                        <i class="fas fa-list me-2"></i>Donor List
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
    
    if (dateStr && timeStr) {
        const [year, month, day] = dateStr.split('-');
        const [hours, minutes] = timeStr.split(':');
        
        const dateObj = new Date(parseInt(year), parseInt(month) - 1, parseInt(day), 
                                parseInt(hours), parseInt(minutes));
        
        const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];
        const formattedDate = days[dateObj.getDay()] + ', ' + months[dateObj.getMonth()] + ' ' + 
                             dateObj.getDate() + ', ' + dateObj.getFullYear();
        
        let h = dateObj.getHours();
        const m = dateObj.getMinutes();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12;
        h = h ? h : 12;
        const formattedTime = h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
        
        document.getElementById('appointment-date').textContent = formattedDate;
        document.getElementById('appointment-time').textContent = formattedTime;
    }
})();
</script>
</body>
</html>

