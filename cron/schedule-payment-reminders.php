<?php
/**
 * Schedule Payment Reminders
 * 
 * Cron script to schedule SMS reminders for upcoming payment due dates.
 * Run daily at 8am via cPanel cron:
 * 0 8 * * * /usr/local/bin/php /home/YOUR_USER/public_html/Fundraising/cron/schedule-payment-reminders.php
 * 
 * @author Fundraising System
 */

declare(strict_types=1);

// Prevent web access
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    die('Access denied. CLI only.');
}

// Optional: Verify cron key for web-based cron (cPanel)
$valid_cron_key = 'your-secure-cron-key-here'; // Change this!
if (isset($_GET['cron_key']) && $_GET['cron_key'] !== $valid_cron_key) {
    http_response_code(403);
    die('Invalid cron key.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/VoodooSMSService.php';

// Logging function
function cron_log(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    $logFile = __DIR__ . '/../logs/payment-reminders-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
}

try {
    $db = db();
    cron_log("INFO: Starting payment reminder scheduling");
    
    // Check required tables
    $tables_needed = ['sms_queue', 'sms_templates', 'sms_settings', 'payment_plan_schedule', 'donors'];
    foreach ($tables_needed as $table) {
        $check = $db->query("SHOW TABLES LIKE '$table'");
        if (!$check || $check->num_rows === 0) {
            cron_log("ERROR: Required table '$table' does not exist");
            exit(1);
        }
    }
    
    // Get settings
    $settings = [
        'sms_reminder_3day_enabled' => '1',
        'sms_reminder_dueday_enabled' => '1',
        'sms_overdue_7day_enabled' => '1'
    ];
    
    $settings_result = $db->query("SELECT setting_key, setting_value FROM sms_settings");
    if ($settings_result) {
        while ($row = $settings_result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    // Get templates
    $templates = [];
    $template_keys = ['payment_reminder_3day', 'payment_reminder_dueday', 'payment_overdue_7day'];
    foreach ($template_keys as $key) {
        $stmt = $db->prepare("SELECT id, message_en, message_am, message_ti FROM sms_templates WHERE template_key = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $templates[$key] = $row;
        }
    }
    
    $queued = 0;
    $skipped = 0;
    
    // ============================================
    // 3-Day Reminders
    // ============================================
    if ($settings['sms_reminder_3day_enabled'] === '1') {
        cron_log("INFO: Processing 3-day reminders...");
        
        $reminder_date = date('Y-m-d', strtotime('+3 days'));
        
        // Check if reminder_3day_sent column exists
        $col_check = $db->query("SHOW COLUMNS FROM payment_plan_schedule LIKE 'reminder_3day_sent'");
        if ($col_check && $col_check->num_rows > 0) {
            $result = $db->query("
                SELECT 
                    pps.id as schedule_id,
                    pps.payment_plan_id,
                    pps.due_date,
                    pps.amount,
                    pp.donor_id,
                    d.name as donor_name,
                    d.phone as donor_phone,
                    d.preferred_language,
                    d.sms_opt_in
                FROM payment_plan_schedule pps
                JOIN payment_plans pp ON pps.payment_plan_id = pp.id
                JOIN donors d ON pp.donor_id = d.id
                WHERE pps.due_date = '$reminder_date'
                AND pps.status = 'pending'
                AND pps.reminder_3day_sent = 0
                AND d.sms_opt_in = 1
                AND d.phone IS NOT NULL
                AND d.phone != ''
            ");
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (queueReminder($db, $row, 'payment_reminder_3day', $templates, 'reminder_3day')) {
                        $queued++;
                        // Mark as sent
                        $db->query("UPDATE payment_plan_schedule SET reminder_3day_sent = 1 WHERE id = " . (int)$row['schedule_id']);
                    } else {
                        $skipped++;
                    }
                }
            }
        } else {
            cron_log("WARNING: reminder_3day_sent column not found. Run database migration.");
        }
    }
    
    // ============================================
    // Due Day Reminders
    // ============================================
    if ($settings['sms_reminder_dueday_enabled'] === '1') {
        cron_log("INFO: Processing due day reminders...");
        
        $today = date('Y-m-d');
        
        $col_check = $db->query("SHOW COLUMNS FROM payment_plan_schedule LIKE 'reminder_dueday_sent'");
        if ($col_check && $col_check->num_rows > 0) {
            $result = $db->query("
                SELECT 
                    pps.id as schedule_id,
                    pps.payment_plan_id,
                    pps.due_date,
                    pps.amount,
                    pp.donor_id,
                    d.name as donor_name,
                    d.phone as donor_phone,
                    d.preferred_language,
                    d.sms_opt_in
                FROM payment_plan_schedule pps
                JOIN payment_plans pp ON pps.payment_plan_id = pp.id
                JOIN donors d ON pp.donor_id = d.id
                WHERE pps.due_date = '$today'
                AND pps.status = 'pending'
                AND pps.reminder_dueday_sent = 0
                AND d.sms_opt_in = 1
                AND d.phone IS NOT NULL
                AND d.phone != ''
            ");
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (queueReminder($db, $row, 'payment_reminder_dueday', $templates, 'reminder_dueday')) {
                        $queued++;
                        $db->query("UPDATE payment_plan_schedule SET reminder_dueday_sent = 1 WHERE id = " . (int)$row['schedule_id']);
                    } else {
                        $skipped++;
                    }
                }
            }
        }
    }
    
    // ============================================
    // 7-Day Overdue Reminders
    // ============================================
    if ($settings['sms_overdue_7day_enabled'] === '1') {
        cron_log("INFO: Processing 7-day overdue reminders...");
        
        $overdue_date = date('Y-m-d', strtotime('-7 days'));
        
        $col_check = $db->query("SHOW COLUMNS FROM payment_plan_schedule LIKE 'reminder_overdue_sent'");
        if ($col_check && $col_check->num_rows > 0) {
            $result = $db->query("
                SELECT 
                    pps.id as schedule_id,
                    pps.payment_plan_id,
                    pps.due_date,
                    pps.amount,
                    pp.donor_id,
                    d.name as donor_name,
                    d.phone as donor_phone,
                    d.preferred_language,
                    d.sms_opt_in
                FROM payment_plan_schedule pps
                JOIN payment_plans pp ON pps.payment_plan_id = pp.id
                JOIN donors d ON pp.donor_id = d.id
                WHERE pps.due_date = '$overdue_date'
                AND pps.status = 'pending'
                AND pps.reminder_overdue_sent = 0
                AND d.sms_opt_in = 1
                AND d.phone IS NOT NULL
                AND d.phone != ''
            ");
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if (queueReminder($db, $row, 'payment_overdue_7day', $templates, 'reminder_overdue')) {
                        $queued++;
                        $db->query("UPDATE payment_plan_schedule SET reminder_overdue_sent = 1 WHERE id = " . (int)$row['schedule_id']);
                    } else {
                        $skipped++;
                    }
                }
            }
        }
    }
    
    cron_log("COMPLETE: Queued: $queued, Skipped: $skipped");
    
} catch (Exception $e) {
    cron_log("FATAL ERROR: " . $e->getMessage());
    exit(1);
}

/**
 * Queue a reminder SMS
 */
function queueReminder($db, array $payment, string $templateKey, array $templates, string $sourceType): bool {
    global $cron_log;
    
    // Check if template exists
    if (!isset($templates[$templateKey])) {
        cron_log("WARNING: Template '$templateKey' not found. Skipping.");
        return false;
    }
    
    $template = $templates[$templateKey];
    $phone = $payment['donor_phone'];
    $donorId = (int)$payment['donor_id'];
    $templateId = (int)$template['id'];
    
    // Check blacklist
    $blacklist_check = $db->prepare("SELECT id FROM sms_blacklist WHERE phone_number = ?");
    $blacklist_check->bind_param('s', $phone);
    $blacklist_check->execute();
    if ($blacklist_check->get_result()->num_rows > 0) {
        cron_log("SKIP: Donor #$donorId - Phone blacklisted");
        return false;
    }
    
    // Check if already queued today for same schedule
    $scheduleId = (int)$payment['schedule_id'];
    $today = date('Y-m-d');
    $existing_check = $db->prepare("
        SELECT id FROM sms_queue 
        WHERE donor_id = ? AND source_type = ? AND DATE(created_at) = ?
        AND status IN ('pending', 'processing', 'sent')
    ");
    $existing_check->bind_param('iss', $donorId, $sourceType, $today);
    $existing_check->execute();
    if ($existing_check->get_result()->num_rows > 0) {
        cron_log("SKIP: Donor #$donorId - Already queued today for $sourceType");
        return false;
    }
    
    // Get message based on language preference
    $lang = $payment['preferred_language'] ?? 'en';
    $message = $template['message_en']; // Default to English
    
    if ($lang === 'am' && !empty($template['message_am'])) {
        $message = $template['message_am'];
    } elseif ($lang === 'ti' && !empty($template['message_ti'])) {
        $message = $template['message_ti'];
    }
    
    // Replace template variables
    $message = VoodooSMSService::processTemplate($message, [
        'name' => $payment['donor_name'],
        'amount' => number_format((float)$payment['amount'], 2),
        'due_date' => date('j M Y', strtotime($payment['due_date'])),
        'portal_link' => 'donate.abuneteklehaymanot.org/donor'
    ]);
    
    // Insert into queue
    $recipientName = $payment['donor_name'];
    $priority = ($sourceType === 'reminder_overdue') ? 8 : 5; // Higher priority for overdue
    
    $stmt = $db->prepare("
        INSERT INTO sms_queue 
        (donor_id, phone_number, recipient_name, template_id, message_content, message_language,
         source_type, priority, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param('issisisi', $donorId, $phone, $recipientName, $templateId, $message, $lang, $sourceType, $priority);
    
    if ($stmt->execute()) {
        cron_log("QUEUED: Donor #$donorId ($phone) - $sourceType");
        return true;
    } else {
        cron_log("ERROR: Failed to queue for Donor #$donorId - " . $db->error);
        return false;
    }
}

