<?php
/**
 * Process SMS Queue
 * 
 * Cron script to process pending SMS messages in the queue.
 * Run every 5 minutes via cPanel cron:
 * *\/5 * * * * /usr/local/bin/php /home/YOUR_USER/public_html/Fundraising/cron/process-sms-queue.php
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

// Prevent web access
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    // Never hardcode cron keys in version control.
    // Configure this in your server environment instead:
    // - Windows/IIS/Apache: set env var FUNDRAISING_CRON_KEY
    // - Linux/cPanel: export FUNDRAISING_CRON_KEY=... (or set in cron environment)
    $expectedCronKey = (string)getenv('FUNDRAISING_CRON_KEY');
    if ($expectedCronKey === '') {
    http_response_code(403);
        die('Cron key not configured.');
}

    $providedCronKey = (string)($_GET['cron_key'] ?? '');
    if ($providedCronKey === '' || !hash_equals($expectedCronKey, $providedCronKey)) {
    http_response_code(403);
    die('Invalid cron key.');
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/VoodooSMSService.php';

// Configuration
$BATCH_SIZE = 20;       // Process max 20 messages per run
$MAX_RUNTIME = 240;     // Max runtime 4 minutes (leave buffer before next cron)
$startTime = time();

// Logging function
function cron_log(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    // Log to file
    $logFile = __DIR__ . '/../logs/sms-queue-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Also output to console if CLI
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
}

try {
    $db = db();
    
    // Check if queue table exists
    $check = $db->query("SHOW TABLES LIKE 'sms_queue'");
    if (!$check || $check->num_rows === 0) {
        cron_log("ERROR: sms_queue table does not exist");
        exit(1);
    }
    
    // Initialize SMS service
    $sms = VoodooSMSService::fromDatabase($db);
    if (!$sms) {
        cron_log("ERROR: No active SMS provider configured");
        exit(1);
    }
    
    // Check settings for quiet hours
    $quiet_start = '21:00';
    $quiet_end = '09:00';
    $daily_limit = 1000;
    
    $settings_check = $db->query("SHOW TABLES LIKE 'sms_settings'");
    if ($settings_check && $settings_check->num_rows > 0) {
        $settings_result = $db->query("SELECT setting_key, setting_value FROM sms_settings WHERE setting_key IN ('sms_quiet_hours_start', 'sms_quiet_hours_end', 'sms_daily_limit')");
        while ($row = $settings_result->fetch_assoc()) {
            if ($row['setting_key'] === 'sms_quiet_hours_start') $quiet_start = $row['setting_value'];
            if ($row['setting_key'] === 'sms_quiet_hours_end') $quiet_end = $row['setting_value'];
            if ($row['setting_key'] === 'sms_daily_limit') $daily_limit = (int)$row['setting_value'];
        }
    }
    
    // Check if we're in quiet hours
    $now = new DateTime();
    $quiet_start_time = DateTime::createFromFormat('H:i', $quiet_start);
    $quiet_end_time = DateTime::createFromFormat('H:i', $quiet_end);
    
    if ($quiet_start_time && $quiet_end_time) {
        // Handle overnight quiet hours (e.g., 21:00 - 09:00)
        if ($quiet_start_time > $quiet_end_time) {
            // Overnight period
            if ($now >= $quiet_start_time || $now < $quiet_end_time) {
                cron_log("INFO: In quiet hours ($quiet_start - $quiet_end). Skipping.");
                exit(0);
            }
        } else {
            // Same day period
            if ($now >= $quiet_start_time && $now < $quiet_end_time) {
                cron_log("INFO: In quiet hours ($quiet_start - $quiet_end). Skipping.");
                exit(0);
            }
        }
    }
    
    // Check daily limit
    if ($daily_limit > 0) {
        $today = date('Y-m-d');
        $sent_today_result = $db->query("SELECT COUNT(*) as count FROM sms_log WHERE DATE(sent_at) = '$today'");
        $sent_today = $sent_today_result ? (int)$sent_today_result->fetch_assoc()['count'] : 0;
        
        if ($sent_today >= $daily_limit) {
            cron_log("INFO: Daily limit reached ($sent_today / $daily_limit). Skipping.");
            exit(0);
        }
        
        // Adjust batch size if near limit
        $remaining = $daily_limit - $sent_today;
        $BATCH_SIZE = min($BATCH_SIZE, $remaining);
    }
    
    // Get pending messages (scheduled for now or past)
    $stmt = $db->prepare("
        SELECT q.*, d.sms_opt_in 
        FROM sms_queue q
        LEFT JOIN donors d ON q.donor_id = d.id
        WHERE q.status = 'pending'
        AND (q.scheduled_for IS NULL OR q.scheduled_for <= NOW())
        AND q.attempts < q.max_attempts
        ORDER BY q.priority DESC, q.created_at ASC
        LIMIT ?
    ");
    $stmt->bind_param('i', $BATCH_SIZE);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    $total = count($messages);
    cron_log("INFO: Found $total pending messages to process");
    
    if ($total === 0) {
        exit(0);
    }
    
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    
    foreach ($messages as $msg) {
        // Check runtime limit
        if ((time() - $startTime) >= $MAX_RUNTIME) {
            cron_log("WARNING: Max runtime reached. Stopping.");
            break;
        }
        
        $queueId = (int)$msg['id'];
        $phone = $msg['phone_number'];
        $message = $msg['message_content'];
        $donorId = $msg['donor_id'] ? (int)$msg['donor_id'] : null;
        
        // Check if donor opted out
        if ($donorId && isset($msg['sms_opt_in']) && $msg['sms_opt_in'] == 0) {
            // Update queue status
            $db->query("UPDATE sms_queue SET status = 'cancelled', error_message = 'Donor opted out' WHERE id = $queueId");
            cron_log("SKIP: Queue #$queueId - Donor opted out");
            $skipped++;
            continue;
        }
        
        // Check blacklist
        $blacklist_check = $db->prepare("SELECT id FROM sms_blacklist WHERE phone_number = ?");
        $blacklist_check->bind_param('s', $phone);
        $blacklist_check->execute();
        if ($blacklist_check->get_result()->num_rows > 0) {
            $db->query("UPDATE sms_queue SET status = 'cancelled', error_message = 'Phone blacklisted' WHERE id = $queueId");
            cron_log("SKIP: Queue #$queueId - Phone blacklisted");
            $skipped++;
            continue;
        }
        
        // Mark as processing
        $db->query("UPDATE sms_queue SET status = 'processing', attempts = attempts + 1 WHERE id = $queueId");
        
        // Send SMS
        $sendResult = $sms->send($phone, $message, [
            'donor_id' => $donorId,
            'template_id' => $msg['template_id'] ? (int)$msg['template_id'] : null,
            'source_type' => $msg['source_type'] ?? 'queue',
            'log' => true
        ]);
        
        if ($sendResult['success']) {
            // Update queue status
            $messageId = $sendResult['message_id'] ?? '';
            $stmt = $db->prepare("UPDATE sms_queue SET status = 'sent', provider_message_id = ?, sent_at = NOW() WHERE id = ?");
            $stmt->bind_param('si', $messageId, $queueId);
            $stmt->execute();
            
            cron_log("SENT: Queue #$queueId to $phone (Ref: $messageId)");
            $sent++;
        } else {
            // Check if should retry
            $current_attempts = (int)$msg['attempts'] + 1;
            $max_attempts = (int)$msg['max_attempts'];
            
            if ($current_attempts >= $max_attempts) {
                $status = 'failed';
            } else {
                $status = 'pending'; // Will retry
            }
            
            $error = $sendResult['error'] ?? 'Unknown error';
            $stmt = $db->prepare("UPDATE sms_queue SET status = ?, error_message = ? WHERE id = ?");
            $stmt->bind_param('ssi', $status, $error, $queueId);
            $stmt->execute();
            
            cron_log("FAIL: Queue #$queueId - $error (Attempt $current_attempts/$max_attempts)");
            $failed++;
        }
        
        // Small delay between messages
        usleep(200000); // 200ms
    }
    
    cron_log("COMPLETE: Sent: $sent, Failed: $failed, Skipped: $skipped");
    
} catch (Exception $e) {
    cron_log("FATAL ERROR: " . $e->getMessage());
    exit(1);
}

