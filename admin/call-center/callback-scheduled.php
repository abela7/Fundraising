<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/SMSHelper.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';
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
            d.preferred_language as donor_language,
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
    
    // Check SMS status from GET (after redirect) or handle POST
    $sms_status = $_GET['sms'] ?? null;
    $sms_error = isset($_GET['sms_error']) ? urldecode($_GET['sms_error']) : null;
    $sent_channel = $_GET['channel'] ?? null; // 'sms' or 'whatsapp'
    $fallback_used = isset($_GET['fallback']) ? (int)$_GET['fallback'] : 0;
    
    // Get the call status to determine which template to use
    $call_status = $_GET['status'] ?? 'not_picked_up';

    // Map status to template key
    $template_map = [
        'not_picked_up' => 'missed_call',
        'busy' => 'line_busy',
        'busy_cant_talk' => 'callback_requested',
        'not_ready_to_pay' => 'follow_up_reminder'
    ];
    $template_key = $template_map[$call_status] ?? 'missed_call';
    
    // Check if SMS is available
    $sms_available = false;
    $sms_template = null;
    try {
        $sms_helper = new SMSHelper($db);
        $sms_available = $sms_helper->isReady();
        if ($sms_available) {
            $sms_template = $sms_helper->getTemplate($template_key);
        }
    } catch (Throwable $e) {
        // SMS not available
    }
    
    // Helper function to get first name
    function getFirstName(string $fullName): string {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? $fullName;
    }
    
    // Handle SMS send request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
        verify_csrf();
        
        try {
            if ($sms_available && $sms_template) {
                $firstName = getFirstName($appointment->donor_name);
                $callbackDate = date('D, M j', strtotime($appointment->appointment_date));
                $callbackTime = date('g:i A', strtotime($appointment->appointment_time));
                
                $variables = [
                        'name' => $firstName,
                        'callback_date' => $callbackDate,
                        'callback_time' => $callbackTime
                ];

                // Use template-level delivery mode from sms_templates.
                $msg_helper = new MessagingHelper($db);
                $result = $msg_helper->sendFromTemplate(
                    $template_key,
                    $donor_id,
                    $variables,
                    MessagingHelper::CHANNEL_AUTO,
                    'call_center',
                    false, // queue
                    true   // forceImmediate
                );

                $sent_channel = $result['channel'] ?? 'sms';
                $fallback_used = !empty($result['is_fallback']) ? 1 : 0;
                
                if (!empty($result['success'])) {
                    $sms_status = 'sent';
                } else {
                    $sms_status = 'failed';
                    $sms_error = $result['error'] ?? 'Unknown error';
                }
            }
        } catch (Throwable $e) {
            $sms_status = 'failed';
            $sms_error = $e->getMessage();
        }
        
        // Redirect to prevent form resubmission (preserve status)
        $redirect = "callback-scheduled.php?appointment_id=$appointment_id&donor_id=$donor_id&status=" . urlencode($call_status) . "&sms=$sms_status";
        if ($sms_error) {
            $redirect .= '&sms_error=' . urlencode($sms_error);
        }
        if ($sent_channel) {
            $redirect .= '&channel=' . urlencode((string)$sent_channel);
        }
        if ($fallback_used) {
            $redirect .= '&fallback=1';
        }
        header("Location: $redirect");
        exit;
    }
    
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
        
        .sms-option-card {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 1px solid #0ea5e9;
            border-radius: 12px;
            padding: 1rem;
        }
        
        .sms-option-header {
            color: #0284c7;
            font-size: 0.9375rem;
            margin-bottom: 0.75rem;
        }
        
        .sms-preview {
            background: white;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.875rem;
            color: #334155;
            line-height: 1.5;
        }
        
        .sms-meta {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
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
                
                <?php 
                // Status messages based on call type
                $status_messages = [
                    'not_picked_up' => 'The donor has been notified about the missed call.',
                    'busy' => 'The donor has been notified that their line was busy.',
                    'busy_cant_talk' => 'The donor has been notified about the callback.',
                    'not_ready_to_pay' => 'The donor has been notified about the follow-up.'
                ];
                $sms_success_msg = $status_messages[$call_status] ?? 'The donor has been notified.';
                ?>
                
                <?php if ($sms_status === 'sent'): ?>
                <div class="alert alert-success d-flex align-items-center mb-3">
                    <i class="fas fa-check-circle me-3 fa-lg"></i>
                    <div>
                        <strong>Notification Sent Successfully!</strong><br>
                        <small class="text-muted">
                            <?php
                                $channelLabel = $sent_channel === 'whatsapp' ? 'WhatsApp' : 'SMS';
                                echo htmlspecialchars($sms_success_msg . " (Sent via {$channelLabel}" . ($fallback_used ? ' — SMS fallback used' : '') . ')');
                            ?>
                        </small>
                    </div>
                </div>
                <?php elseif ($sms_status === 'failed'): ?>
                <div class="alert alert-warning d-flex align-items-center mb-3">
                    <i class="fas fa-exclamation-triangle me-3 fa-lg"></i>
                    <div>
                        <strong>Notification Failed to Send</strong><br>
                        <small><?php echo htmlspecialchars($sms_error ?? 'Unknown error'); ?></small>
                    </div>
                </div>
                <?php elseif ($sms_available && $sms_template && !$sms_status): ?>
                <!-- SMS Option Card -->
                <?php
                $firstName = getFirstName($appointment->donor_name);
                $callbackDate = date('D, M j', strtotime($appointment->appointment_date));
                $callbackTime = date('g:i A', strtotime($appointment->appointment_time));
                
                // Resolve template delivery mode (preferred_channel first, then platform fallback).
                $templateMode = strtolower(trim((string)($sms_template['preferred_channel'] ?? '')));
                if (!in_array($templateMode, ['auto', 'sms', 'whatsapp'], true)) {
                    $platformMode = strtolower(trim((string)($sms_template['platform'] ?? '')));
                    $templateMode = in_array($platformMode, ['sms', 'whatsapp'], true) ? $platformMode : 'auto';
                }

                // Channel/language preview based on template mode.
                $langLabels = ['en' => '🇬🇧 English', 'am' => '🇪🇹 Amharic', 'ti' => '🇪🇷 Tigrinya'];
                $usingFallback = false;
                $fallbackNote = '';
                $previewChannelLabel = 'Default (WhatsApp → SMS fallback)';

                if ($templateMode === 'sms') {
                    $previewLang = 'en';
                    $templateMessage = $sms_template['message_en'] ?? '';
                    $previewChannelLabel = 'SMS Always (English)';
                } elseif ($templateMode === 'whatsapp') {
                    $previewLang = 'am';
                    $templateMessage = trim((string)($sms_template['message_am'] ?? ''));
                    $previewChannelLabel = 'WhatsApp Always (Amharic)';
                    if ($templateMessage === '') {
                        $templateMessage = $sms_template['message_en'] ?? '';
                        $usingFallback = true;
                        $fallbackNote = 'Amharic message is missing. WhatsApp-only mode will fail until you add Amharic text.';
                    }
                } else {
                    $previewLang = 'am';
                    $templateMessage = trim((string)($sms_template['message_am'] ?? ''));
                    if ($templateMessage === '') {
                        $templateMessage = $sms_template['message_en'] ?? '';
                        $previewLang = 'en';
                        $usingFallback = true;
                        $fallbackNote = 'No Amharic translation available - default mode will fall back to English SMS.';
                    }
                }

                $previewLangLabel = $langLabels[$previewLang] ?? $langLabels['en'];
                
                $previewMessage = str_replace(
                    ['{name}', '{callback_date}', '{callback_time}'],
                    [$firstName, $callbackDate, $callbackTime],
                    $templateMessage
                );
                
                // Button text based on status
                $button_labels = [
                    'not_picked_up' => 'Send "Missed Call" Notification',
                    'busy' => 'Send "Line Busy" Notification',
                    'busy_cant_talk' => 'Send Callback Notification',
                    'not_ready_to_pay' => 'Send Follow-up Notification'
                ];
                $button_label = $button_labels[$call_status] ?? 'Send Notification';
                ?>
                <div class="sms-option-card mb-3">
                    <div class="sms-option-header">
                        <i class="fas fa-sms me-2"></i>
                        <strong>Send Notification?</strong>
                        <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($sms_template['name'] ?? $template_key); ?></span>
                        <span class="badge bg-secondary ms-1"><?php echo htmlspecialchars($previewChannelLabel); ?></span>
                        <span class="badge bg-info ms-1"><?php echo $previewLangLabel; ?></span>
                    </div>
                    <?php if ($usingFallback): ?>
                    <div class="alert alert-warning py-2 px-3 mb-2" style="font-size: 0.8125rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <?php echo htmlspecialchars($fallbackNote); ?>
                    </div>
                    <?php endif; ?>
                    <div class="sms-preview">
                        <?php echo htmlspecialchars($previewMessage); ?>
                    </div>
                    <div class="sms-meta">
                        <span><i class="fas fa-ruler me-1"></i><?php echo strlen($previewMessage); ?> chars</span>
                        <span><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($appointment->donor_phone); ?></span>
                    </div>
                    <form method="POST" class="mt-3">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="send_sms" value="1">
                        <button type="submit" class="btn btn-info w-100">
                            <i class="fas fa-paper-plane me-2"></i><?php echo htmlspecialchars($button_label); ?>
                        </button>
                    </form>
                </div>
                <?php elseif ($sms_available && !$sms_template && !$sms_status): ?>
                <!-- Template Missing Warning -->
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>SMS Template Not Found!</strong><br>
                    <small>Please create a template with key <code><?php echo htmlspecialchars($template_key); ?></code> in 
                    <a href="../donor-management/sms/templates.php?action=new" target="_blank">SMS Templates</a></small>
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

