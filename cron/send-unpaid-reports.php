<?php
/**
 * Send Unpaid Payment Reports to Agents
 * 
 * At 22:00 each day, identifies donors who had payments due TODAY but did not pay.
 * Groups by assigned agent and sends WhatsApp report to each agent.
 * 
 * Run daily at 22:00 via cron:
 * 0 22 * * * /usr/bin/php /path/to/cron/send-unpaid-reports.php
 * 
 * Or via web (with cron key):
 * https://yourdomain.com/cron/send-unpaid-reports.php?cron_key=YOUR_KEY
 */

declare(strict_types=1);

// Prevent unauthorized web access
$isCli = in_array(php_sapi_name(), ['cli', 'cli-server', 'cgi', 'cgi-fcgi', 'litespeed'], true) 
         || !isset($_SERVER['HTTP_HOST']) 
         || (isset($_SERVER['argc']) && $_SERVER['argc'] > 0);

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
require_once __DIR__ . '/../services/UltraMsgService.php';

/**
 * Logging function
 */
function cron_log(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/unpaid-reports-' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (php_sapi_name() === 'cli') {
        echo $logMessage;
    }
}

/**
 * Ensure tracking table exists
 */
function ensureTrackingTable(mysqli $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS unpaid_reports_sent (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agent_id INT NOT NULL,
            agent_name VARCHAR(100) NULL,
            agent_phone VARCHAR(20) NULL,
            report_date DATE NOT NULL,
            unpaid_count INT NOT NULL DEFAULT 0,
            unpaid_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            donor_list TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            channel ENUM('whatsapp', 'sms', 'both') NOT NULL DEFAULT 'whatsapp',
            message_preview VARCHAR(500) NULL,
            source_type ENUM('cron', 'manual', 'admin_trigger') NOT NULL DEFAULT 'cron',
            INDEX idx_agent_date (agent_id, report_date),
            INDEX idx_report_date (report_date),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Check if agent already received report for this date today
 */
function wasReportSentToday(mysqli $db, int $agentId, string $reportDate): bool {
    $stmt = $db->prepare("
        SELECT id FROM unpaid_reports_sent 
        WHERE agent_id = ? AND report_date = ? AND DATE(sent_at) = CURDATE()
        LIMIT 1
    ");
    $stmt->bind_param('is', $agentId, $reportDate);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

/**
 * Record that report was sent
 */
function recordReportSent(
    mysqli $db, 
    int $agentId, 
    string $agentName, 
    string $agentPhone,
    string $reportDate, 
    int $unpaidCount, 
    float $unpaidTotal,
    array $donorList,
    string $channel,
    string $messagePreview
): void {
    $stmt = $db->prepare("
        INSERT INTO unpaid_reports_sent 
        (agent_id, agent_name, agent_phone, report_date, unpaid_count, unpaid_total, donor_list, channel, message_preview, source_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cron')
    ");
    $donorListJson = json_encode($donorList, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param('isssiissss', 
        $agentId, $agentName, $agentPhone, $reportDate, 
        $unpaidCount, $unpaidTotal, $donorListJson, $channel, $messagePreview
    );
    $stmt->execute();
}

try {
    $db = db();
    cron_log("INFO: Starting unpaid payment reports job");
    
    // Ensure tracking table exists
    ensureTrackingTable($db);
    cron_log("INFO: Tracking table verified");
    
    // Today's date (the day we're checking for unpaid payments)
    $reportDate = date('Y-m-d');
    $reportDateFormatted = date('l, j F Y'); // Monday, 15 December 2025
    
    // Check if donor_payment_plans and pledge_payments exist
    $checkPlans = $db->query("SHOW TABLES LIKE 'donor_payment_plans'");
    if (!$checkPlans || $checkPlans->num_rows === 0) {
        cron_log("ERROR: donor_payment_plans table does not exist");
        exit(1);
    }
    
    $checkPayments = $db->query("SHOW TABLES LIKE 'pledge_payments'");
    $hasPledgePayments = $checkPayments && $checkPayments->num_rows > 0;
    
    // Check if pledge_payments has payment_plan_id column
    $hasPlanIdCol = false;
    if ($hasPledgePayments) {
        $colCheck = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'");
        $hasPlanIdCol = $colCheck && $colCheck->num_rows > 0;
    }
    
    /**
     * Query to find unpaid payments:
     * 1. Payment plan has next_payment_due = TODAY
     * 2. Donor has an assigned agent
     * 3. No payment record in pledge_payments for today (or payment is voided/rejected)
     */
    $query = "
        SELECT 
            pp.id as plan_id,
            pp.donor_id,
            pp.monthly_amount,
            pp.next_payment_due,
            pp.payment_method,
            d.name as donor_name,
            d.phone as donor_phone,
            d.agent_id,
            u.id as agent_user_id,
            u.name as agent_name,
            u.phone as agent_phone,
            u.email as agent_email
        FROM donor_payment_plans pp
        JOIN donors d ON pp.donor_id = d.id
        LEFT JOIN users u ON d.agent_id = u.id
        WHERE pp.next_payment_due = ?
        AND pp.status = 'active'
        AND d.agent_id IS NOT NULL
    ";
    
    // Exclude donors who made a payment today
    if ($hasPledgePayments && $hasPlanIdCol) {
        $query .= "
            AND NOT EXISTS (
                SELECT 1 FROM pledge_payments pay 
                WHERE pay.payment_plan_id = pp.id 
                AND DATE(pay.payment_date) = ?
                AND pay.status IN ('pending', 'approved')
            )
        ";
    } elseif ($hasPledgePayments) {
        // Fallback: check by donor_id if no payment_plan_id column
        $query .= "
            AND NOT EXISTS (
                SELECT 1 FROM pledge_payments pay 
                WHERE pay.donor_id = pp.donor_id 
                AND DATE(pay.payment_date) = ?
                AND pay.status IN ('pending', 'approved')
            )
        ";
    }
    
    $query .= " ORDER BY u.id, d.name";
    
    $stmt = $db->prepare($query);
    if ($hasPledgePayments) {
        $stmt->bind_param('ss', $reportDate, $reportDate);
    } else {
        $stmt->bind_param('s', $reportDate);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Group unpaid payments by agent
    $agentUnpaid = [];
    $totalUnpaid = 0;
    $totalUnpaidAmount = 0.0;
    
    while ($row = $result->fetch_assoc()) {
        $agentId = (int)$row['agent_id'];
        
        if (!isset($agentUnpaid[$agentId])) {
            $agentUnpaid[$agentId] = [
                'agent_id' => $agentId,
                'agent_name' => $row['agent_name'] ?? 'Unknown Agent',
                'agent_phone' => $row['agent_phone'] ?? '',
                'agent_email' => $row['agent_email'] ?? '',
                'donors' => [],
                'total_amount' => 0.0
            ];
        }
        
        $agentUnpaid[$agentId]['donors'][] = [
            'donor_id' => (int)$row['donor_id'],
            'donor_name' => $row['donor_name'],
            'donor_phone' => $row['donor_phone'] ?? '',
            'amount' => (float)$row['monthly_amount'],
            'payment_method' => $row['payment_method'] ?? 'bank_transfer'
        ];
        
        $agentUnpaid[$agentId]['total_amount'] += (float)$row['monthly_amount'];
        $totalUnpaid++;
        $totalUnpaidAmount += (float)$row['monthly_amount'];
    }
    
    cron_log("INFO: Found " . count($agentUnpaid) . " agents with " . $totalUnpaid . " unpaid payments (£" . number_format($totalUnpaidAmount, 2) . ")");
    
    // If no unpaid payments, nothing to do
    if (empty($agentUnpaid)) {
        cron_log("INFO: No unpaid payments found for today. Exiting.");
        
        // Still send admin notification
        $adminPhone = '447360436171';
        try {
            $whatsapp = UltraMsgService::fromDatabase($db);
            $adminMessage = "✅ *Unpaid Reports Cron - All Clear*\n\n";
            $adminMessage .= "📅 *Date:* {$reportDateFormatted}\n";
            $adminMessage .= "⏰ *Run Time:* " . date('H:i:s') . "\n\n";
            $adminMessage .= "🎉 All scheduled payments for today have been received!\n";
            $adminMessage .= "No unpaid payment reports needed.";
            
            $whatsapp->send($adminPhone, $adminMessage);
            cron_log("INFO: Admin notification sent (no unpaid payments)");
        } catch (Exception $e) {
            cron_log("WARNING: Could not send admin notification: " . $e->getMessage());
        }
        
        exit(0);
    }
    
    // Initialize WhatsApp service
    $whatsapp = UltraMsgService::fromDatabase($db);
    
    $reportsSent = 0;
    $reportsFailed = 0;
    $reportsSkipped = 0;
    $agentResults = [];
    
    // Send report to each agent
    foreach ($agentUnpaid as $agentId => $agentData) {
        $agentName = $agentData['agent_name'];
        $agentPhone = $agentData['agent_phone'];
        
        // Skip if no phone number
        if (empty($agentPhone)) {
            cron_log("SKIP: Agent #{$agentId} ({$agentName}) - No phone number");
            $reportsSkipped++;
            continue;
        }
        
        // Format phone (ensure UK format with country code)
        $formattedPhone = preg_replace('/[^0-9]/', '', $agentPhone);
        if (strpos($formattedPhone, '07') === 0) {
            $formattedPhone = '44' . substr($formattedPhone, 1);
        } elseif (strpos($formattedPhone, '7') === 0 && strlen($formattedPhone) === 10) {
            $formattedPhone = '44' . $formattedPhone;
        } elseif (strpos($formattedPhone, '44') !== 0) {
            $formattedPhone = '44' . $formattedPhone;
        }
        
        // Check if report already sent today
        if (wasReportSentToday($db, $agentId, $reportDate)) {
            cron_log("SKIP: Agent #{$agentId} ({$agentName}) - Report already sent today");
            $reportsSkipped++;
            continue;
        }
        
        // Build SHORT notification with link to report page
        $donorCount = count($agentData['donors']);
        $totalAmount = '£' . number_format($agentData['total_amount'], 2);
        
        // Get first 3 donor names for preview
        $donorPreview = array_slice($agentData['donors'], 0, 3);
        $donorNames = array_map(fn($d) => $d['donor_name'], $donorPreview);
        $moreCount = $donorCount - count($donorNames);
        
        $message = "⚠️ *Unpaid Payment Alert*\n\n";
        $message .= "Hi {$agentName},\n\n";
        $message .= "*{$donorCount} donor(s)* missed their payment today.\n";
        $message .= "💰 Total: *{$totalAmount}*\n\n";
        
        // Show first 3 names
        foreach ($donorNames as $name) {
            $message .= "→ {$name}\n";
        }
        if ($moreCount > 0) {
            $message .= "→ ... and {$moreCount} more\n";
        }
        
        $message .= "\n📱 *View full report:*\n";
        $message .= "https://donate.abuneteklehaymanot.org/admin/agent-reports/daily-payments.php?date={$reportDate}";
        
        // Send the message
        try {
            $sendResult = $whatsapp->send($formattedPhone, $message);
            
            if ($sendResult && isset($sendResult['sent']) && $sendResult['sent'] === 'true') {
                cron_log("SENT: Agent #{$agentId} ({$agentName}) - {$donorCount} unpaid donors");
                $reportsSent++;
                
                // Record the report
                recordReportSent(
                    $db,
                    $agentId,
                    $agentName,
                    $formattedPhone,
                    $reportDate,
                    $donorCount,
                    $agentData['total_amount'],
                    $agentData['donors'],
                    'whatsapp',
                    substr($message, 0, 500)
                );
                
                $agentResults[] = [
                    'agent_name' => $agentName,
                    'donor_count' => $donorCount,
                    'total_amount' => $agentData['total_amount'],
                    'status' => 'sent'
                ];
            } else {
                cron_log("FAILED: Agent #{$agentId} ({$agentName}) - Send failed");
                $reportsFailed++;
                $agentResults[] = [
                    'agent_name' => $agentName,
                    'donor_count' => $donorCount,
                    'total_amount' => $agentData['total_amount'],
                    'status' => 'failed'
                ];
            }
        } catch (Exception $e) {
            cron_log("ERROR: Agent #{$agentId} ({$agentName}) - " . $e->getMessage());
            $reportsFailed++;
            $agentResults[] = [
                'agent_name' => $agentName,
                'donor_count' => $donorCount,
                'total_amount' => $agentData['total_amount'],
                'status' => 'error'
            ];
        }
        
        // Small delay to avoid rate limiting
        usleep(500000); // 0.5 second
    }
    
    cron_log("COMPLETE: Reports Sent: {$reportsSent}, Failed: {$reportsFailed}, Skipped: {$reportsSkipped}");
    
    // Send admin notification
    $adminPhone = '447360436171';
    
    try {
        $timestamp = date('d/m/Y H:i:s');
        
        $adminMessage = "📋 *Unpaid Reports Cron Complete*\n\n";
        $adminMessage .= "📅 *Report Date:* {$reportDateFormatted}\n";
        $adminMessage .= "⏰ *Run Time:* {$timestamp}\n\n";
        $adminMessage .= "━━━━━━━━━━━━━━━━━━\n";
        $adminMessage .= "📊 *Summary:*\n";
        $adminMessage .= "✅ Reports Sent: {$reportsSent}\n";
        $adminMessage .= "❌ Failed: {$reportsFailed}\n";
        $adminMessage .= "⏭️ Skipped: {$reportsSkipped}\n";
        $adminMessage .= "💰 Total Unpaid: £" . number_format($totalUnpaidAmount, 2) . "\n";
        $adminMessage .= "👥 Total Donors: {$totalUnpaid}\n";
        $adminMessage .= "━━━━━━━━━━━━━━━━━━\n\n";
        
        // List first 5 agents with results
        if (!empty($agentResults)) {
            $adminMessage .= "*Agent Reports:*\n";
            $showCount = min(5, count($agentResults));
            for ($i = 0; $i < $showCount; $i++) {
                $ar = $agentResults[$i];
                $statusIcon = $ar['status'] === 'sent' ? '✅' : '❌';
                $adminMessage .= "{$statusIcon} {$ar['agent_name']}: {$ar['donor_count']} donors (£" . number_format($ar['total_amount'], 2) . ")\n";
            }
            if (count($agentResults) > 5) {
                $adminMessage .= "... and " . (count($agentResults) - 5) . " more agents\n";
            }
        }
        
        $whatsapp->send($adminPhone, $adminMessage);
        cron_log("INFO: Admin notification sent");
        
    } catch (Exception $e) {
        cron_log("WARNING: Could not send admin notification: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    cron_log("FATAL ERROR: " . $e->getMessage());
    
    // Try to send error notification to admin
    try {
        $db = db();
        $whatsapp = UltraMsgService::fromDatabase($db);
        $adminPhone = '447360436171';
        
        $errorMessage = "🚨 *Unpaid Reports Cron FAILED*\n\n";
        $errorMessage .= "📅 *Date:* " . date('d/m/Y H:i:s') . "\n\n";
        $errorMessage .= "*Error:*\n" . substr($e->getMessage(), 0, 300);
        
        $whatsapp->send($adminPhone, $errorMessage);
    } catch (Exception $notifyError) {
        cron_log("Could not send error notification: " . $notifyError->getMessage());
    }
    
    exit(1);
}

cron_log("INFO: Unpaid reports job finished successfully");
exit(0);
