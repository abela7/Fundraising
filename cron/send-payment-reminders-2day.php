<?php
/**
 * Send Payment Reminders (2 Days Before Due Date)
 * 
 * Sends WhatsApp/SMS reminders to donors whose payment is due in 2 days.
 * Run daily at 8:00 AM via cron:
 * 0 8 * * * /usr/bin/php /path/to/cron/send-payment-reminders-2day.php
 * 
 * Or via web (with cron key):
 * https://yourdomain.com/cron/send-payment-reminders-2day.php?cron_key=YOUR_KEY
 */

declare(strict_types=1);

// Prevent unauthorized web access
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
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
require_once __DIR__ . '/../services/MessagingHelper.php';
require_once __DIR__ . '/../services/UltraMsgService.php';

// Logging
function cron_log(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/payment-reminders-2day-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
}

try {
    $db = db();
    cron_log("INFO: Starting 2-day payment reminder job");
    
    // Check if donor_payment_plans exists
    $check = $db->query("SHOW TABLES LIKE 'donor_payment_plans'");
    if (!$check || $check->num_rows === 0) {
        cron_log("ERROR: donor_payment_plans table does not exist");
        exit(1);
    }
    
    // Bank details (hardcoded for now, can be moved to config)
    $bankDetails = [
        'account_name' => 'LMKATH',
        'account_number' => '85455687',
        'sort_code' => '53-70-44'
    ];
    
    // Initialize MessagingHelper
    $msgHelper = new MessagingHelper($db);
    
    // Get donors whose payment is due in 2 days
    $targetDate = date('Y-m-d', strtotime('+2 days'));
    
    $query = "
        SELECT 
            pp.id as plan_id,
            pp.donor_id,
            pp.monthly_amount,
            pp.next_payment_due,
            pp.payment_method,
            pp.plan_frequency_unit,
            pp.plan_frequency_number,
            d.name as donor_name,
            d.phone as donor_phone,
            d.preferred_language,
            d.sms_opt_in,
            cr.name as rep_name,
            cr.phone as rep_phone
        FROM donor_payment_plans pp
        JOIN donors d ON pp.donor_id = d.id
        LEFT JOIN church_representatives cr ON d.representative_id = cr.id
        WHERE pp.next_payment_due = ?
        AND pp.status = 'active'
        AND d.phone IS NOT NULL
        AND d.phone != ''
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('s', $targetDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    $sentDonors = []; // Track sent donors for admin notification
    
    while ($row = $result->fetch_assoc()) {
        $donorId = (int)$row['donor_id'];
        $firstName = explode(' ', trim($row['donor_name']))[0];
        $amount = '£' . number_format((float)$row['monthly_amount'], 2);
        $dueDate = date('d/m/Y', strtotime($row['next_payment_due']));
        $paymentMethod = $row['payment_method'] ?? 'bank_transfer';
        
        // SMS opt-in check
        if (!$row['sms_opt_in']) {
            cron_log("SKIP: Donor #{$donorId} - SMS opt-out");
            $skipped++;
            continue;
        }
        
        // Build payment instructions based on method
        $paymentInstructions = '';
        $reference = $firstName . $donorId; // Simple reference: FirstNameDonorId
        
        if ($paymentMethod === 'cash') {
            $repName = $row['rep_name'] ?? 'your church representative';
            $repPhone = $row['rep_phone'] ?? '';
            $paymentInstructions = "Please hand over the cash to {$repName}" . ($repPhone ? " ({$repPhone})" : '');
        } else {
            // Bank transfer / Card
            $paymentInstructions = "Bank: {$bankDetails['account_name']}, Account: {$bankDetails['account_number']}, Sort Code: {$bankDetails['sort_code']}, Reference: {$reference}";
        }
        
        // Calculate frequency
        $frequency = '';
        if (!empty($row['plan_frequency_unit'])) {
            $unit = $row['plan_frequency_unit'];
            $num = (int)($row['plan_frequency_number'] ?? 1);
            if ($unit === 'week') $frequency = $num === 1 ? 'per week' : "every {$num} weeks";
            elseif ($unit === 'month') $frequency = $num === 1 ? 'per month' : "every {$num} months";
            elseif ($unit === 'year') $frequency = $num === 1 ? 'per year' : "every {$num} years";
        }
        
        // Variables for template
        $variables = [
            'name' => $firstName,
            'amount' => $amount,
            'due_date' => $dueDate,
            'payment_method' => ucwords(str_replace('_', ' ', $paymentMethod)),
            'payment_instructions' => $paymentInstructions,
            'reference' => $reference,
            'frequency' => $frequency,
            'account_name' => $bankDetails['account_name'],
            'account_number' => $bankDetails['account_number'],
            'sort_code' => $bankDetails['sort_code'],
            'portal_link' => 'https://bit.ly/4p0J1gf'
        ];
        
        // Send using MessagingHelper (WhatsApp → SMS fallback)
        try {
            $sendResult = $msgHelper->sendFromTemplate(
                'payment_reminder_2day',
                $donorId,
                $variables,
                'whatsapp', // Try WhatsApp first
                'cron_payment_reminder',
                false, // Don't queue, send now
                true // Force immediate
            );
            
            if ($sendResult['success']) {
                cron_log("SENT: Donor #{$donorId} ({$row['donor_phone']}) via " . ($sendResult['channel'] ?? 'unknown'));
                $sent++;
                $sentDonors[] = [
                    'name' => $row['donor_name'],
                    'amount' => $amount,
                    'due_date' => $dueDate
                ];
            } else {
                cron_log("FAILED: Donor #{$donorId} - " . ($sendResult['error'] ?? 'Unknown error'));
                $failed++;
            }
        } catch (Exception $e) {
            cron_log("ERROR: Donor #{$donorId} - " . $e->getMessage());
            $failed++;
        }
    }
    
    cron_log("COMPLETE: Sent: $sent, Failed: $failed, Skipped: $skipped");
    
    // Send admin notification via WhatsApp
    $adminPhone = '447360436171'; // Your admin number (UK format with country code)
    
    try {
        $whatsapp = UltraMsgService::fromDatabase($db);
        
        // Build summary message
        $timestamp = date('d/m/Y H:i:s');
        $dueDate = date('d/m/Y', strtotime('+2 days'));
        
        $adminMessage = "🔔 *Payment Reminder Cron Job Complete*\n\n";
        $adminMessage .= "📅 *Run Time:* {$timestamp}\n";
        $adminMessage .= "📆 *Reminders for:* {$dueDate}\n\n";
        $adminMessage .= "📊 *Summary:*\n";
        $adminMessage .= "✅ Sent: {$sent}\n";
        $adminMessage .= "❌ Failed: {$failed}\n";
        $adminMessage .= "⏭️ Skipped: {$skipped}\n\n";
        
        if ($sent > 0) {
            $adminMessage .= "📋 *Donors Notified:*\n";
            $count = 0;
            foreach ($sentDonors as $donor) {
                $count++;
                if ($count <= 10) { // Limit to first 10 donors
                    $adminMessage .= "{$count}. {$donor['name']} - {$donor['amount']} (Due: {$donor['due_date']})\n";
                }
            }
            if ($sent > 10) {
                $remaining = $sent - 10;
                $adminMessage .= "... and {$remaining} more\n";
            }
        } else {
            $adminMessage .= "ℹ️ No reminders sent today.\n";
        }
        
        $adminMessage .= "\n✅ System running smoothly!";
        
        // Send to admin
        $whatsapp->send($adminPhone, $adminMessage);
        cron_log("ADMIN NOTIFICATION: Sent summary to {$adminPhone}");
        
    } catch (Exception $e) {
        cron_log("ADMIN NOTIFICATION FAILED: " . $e->getMessage());
        // Don't fail the whole job if admin notification fails
    }
    
} catch (Exception $e) {
    cron_log("FATAL ERROR: " . $e->getMessage());
    
    // Try to notify admin about the failure
    try {
        $db = db();
        $whatsapp = UltraMsgService::fromDatabase($db);
        $adminPhone = '447360436171';
        $timestamp = date('d/m/Y H:i:s');
        
        $errorMessage = "🚨 *Payment Reminder Cron Job FAILED*\n\n";
        $errorMessage .= "📅 *Time:* {$timestamp}\n";
        $errorMessage .= "❌ *Error:* " . $e->getMessage() . "\n\n";
        $errorMessage .= "Please check the logs immediately!";
        
        $whatsapp->send($adminPhone, $errorMessage);
    } catch (Exception $notifyError) {
        // Silent fail - log already captured the error
    }
    
    exit(1);
}
