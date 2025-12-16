<?php
/**
 * Send Agent Call Schedule Reminders
 * 
 * Runs daily at 22:00 (10 PM) to notify agents about their scheduled calls for the next day.
 * Sends WhatsApp messages to each agent listing their appointments.
 * 
 * Cron: 0 22 * * * /usr/bin/php /path/to/send-agent-call-reminders.php
 */

declare(strict_types=1);

// Enhanced CLI detection for various PHP environments
$isCLI = (
    php_sapi_name() === 'cli' || 
    php_sapi_name() === 'cgi-fcgi' || 
    php_sapi_name() === 'fpm-fcgi' ||
    php_sapi_name() === 'litespeed' ||
    !isset($_SERVER['HTTP_HOST'])
);

if (!$isCLI) {
    // Web access - require cron key
    $cronKey = $_GET['cron_key'] ?? '';
    $expectedKey = getenv('FUNDRAISING_CRON_KEY') ?: '';
    
    if (empty($expectedKey)) {
        http_response_code(500);
        die("Cron key not configured.");
    }
    
    if ($cronKey !== $expectedKey) {
        http_response_code(403);
        die("Invalid cron key.");
    }
}

// Set timezone
date_default_timezone_set('Europe/London');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/UltraMsgService.php';

// ===========================
// Configuration
// ===========================
$adminPhone = '447360436171'; // Admin WhatsApp number for notifications
$tomorrowDate = date('Y-m-d', strtotime('+1 day'));

echo "================================================================================" . PHP_EOL;
echo "🔔 Agent Call Reminders Cron Job" . PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "Started at: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "Checking appointments for: {$tomorrowDate}" . PHP_EOL;
echo PHP_EOL;

try {
    $db = db();
    
    // Initialize WhatsApp service
    $whatsapp = UltraMsgService::fromDatabase($db);
    
    // Check WhatsApp connection
    $statusCheck = $whatsapp->getStatus();
    if (!$statusCheck['success'] || !in_array($statusCheck['status'], ['authenticated', 'connected'])) {
        throw new Exception("WhatsApp service not available: " . ($statusCheck['error'] ?? 'Unknown error'));
    }
    
    echo "✓ WhatsApp service connected" . PHP_EOL;
    echo PHP_EOL;
    
    // ===========================
    // Fetch Tomorrow's Appointments by Agent
    // ===========================
    $query = "
        SELECT 
            a.agent_id,
            u.name AS agent_name,
            u.phone AS agent_phone,
            a.id AS appointment_id,
            a.donor_id,
            d.name AS donor_name,
            d.phone AS donor_phone,
            a.appointment_date,
            a.appointment_time,
            a.appointment_type,
            a.notes,
            a.status
        FROM call_center_appointments a
        INNER JOIN users u ON a.agent_id = u.id
        INNER JOIN donors d ON a.donor_id = d.id
        WHERE a.appointment_date = ?
            AND a.status IN ('scheduled', 'pending')
            AND u.phone IS NOT NULL
            AND u.phone != ''
        ORDER BY a.agent_id, a.appointment_time
    ";
    
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $db->error);
    }
    
    $stmt->bind_param('s', $tomorrowDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Group appointments by agent
    $agentAppointments = [];
    while ($row = $result->fetch_assoc()) {
        $agentId = (int)$row['agent_id'];
        
        if (!isset($agentAppointments[$agentId])) {
            $agentAppointments[$agentId] = [
                'agent_name' => $row['agent_name'],
                'agent_phone' => $row['agent_phone'],
                'appointments' => []
            ];
        }
        
        $agentAppointments[$agentId]['appointments'][] = [
            'appointment_id' => $row['appointment_id'],
            'donor_name' => $row['donor_name'],
            'donor_phone' => $row['donor_phone'],
            'time' => $row['appointment_time'],
            'type' => $row['appointment_type'],
            'notes' => $row['notes']
        ];
    }
    
    $stmt->close();
    
    if (empty($agentAppointments)) {
        echo "ℹ️ No appointments found for tomorrow." . PHP_EOL;
        echo "✅ Cron job completed successfully!" . PHP_EOL;
        exit(0);
    }
    
    echo "Found " . count($agentAppointments) . " agent(s) with appointments for tomorrow." . PHP_EOL;
    echo PHP_EOL;
    
    // ===========================
    // Send Reminders to Each Agent
    // ===========================
    $successCount = 0;
    $failedCount = 0;
    $skippedCount = 0;
    $sentDetails = [];
    
    foreach ($agentAppointments as $agentId => $agentData) {
        $agentName = $agentData['agent_name'];
        $agentPhone = $agentData['agent_phone'];
        $appointments = $agentData['appointments'];
        $appointmentCount = count($appointments);
        
        echo "Processing Agent: {$agentName} (ID: {$agentId})" . PHP_EOL;
        echo "  Phone: {$agentPhone}" . PHP_EOL;
        echo "  Appointments: {$appointmentCount}" . PHP_EOL;
        
        // Check if reminder already sent
        $checkStmt = $db->prepare("
            SELECT id FROM agent_call_reminders_sent 
            WHERE agent_id = ? AND appointment_date = ?
        ");
        $checkStmt->bind_param('is', $agentId, $tomorrowDate);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->fetch_assoc()) {
            echo "  ⚠️  Reminder already sent today - skipping" . PHP_EOL;
            $skippedCount++;
            $checkStmt->close();
            continue;
        }
        $checkStmt->close();
        
        // Build reminder message
        $tomorrowFormatted = date('l, j F Y', strtotime($tomorrowDate));
        
        $message = "🗓️ *Call Schedule Reminder*\n\n";
        $message .= "Hi {$agentName},\n\n";
        $message .= "You have *{$appointmentCount} appointment(s)* scheduled for tomorrow ({$tomorrowFormatted}):\n\n";
        
        foreach ($appointments as $index => $apt) {
            $num = $index + 1;
            $time = date('H:i', strtotime($apt['time']));
            $type = ucwords(str_replace('_', ' ', $apt['type']));
            
            $message .= "{$num}. *{$time}* - {$type}\n";
            $message .= "   → {$apt['donor_name']}\n";
            $message .= "   → {$apt['donor_phone']}\n";
            
            if (!empty($apt['notes'])) {
                $shortNotes = mb_substr($apt['notes'], 0, 50);
                if (mb_strlen($apt['notes']) > 50) {
                    $shortNotes .= '...';
                }
                $message .= "   📝 {$shortNotes}\n";
            }
            
            $message .= "\n";
        }
        
        $message .= "Please prepare for these calls.\n\n";
        $message .= "View full schedule: https://donate.abuneteklehaymanot.org/admin/call-center/my-schedule.php\n\n";
        $message .= "Good luck! 🙏";
        
        // Normalize phone number
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $agentPhone);
        
        // UK phone normalization
        if (preg_match('/^0([1-9]\d{9})$/', $normalizedPhone, $matches)) {
            $normalizedPhone = '+44' . $matches[1];
        } elseif (preg_match('/^([1-9]\d{9})$/', $normalizedPhone)) {
            $normalizedPhone = '+44' . $normalizedPhone;
        } elseif (preg_match('/^44(\d{10})$/', $normalizedPhone)) {
            $normalizedPhone = '+' . $normalizedPhone;
        }
        
        // Send WhatsApp message
        $sendResult = $whatsapp->send($normalizedPhone, $message, [
            'log' => true,
            'source_type' => 'agent_call_reminder_cron'
        ]);
        
        if ($sendResult['success']) {
            echo "  ✓ Reminder sent successfully" . PHP_EOL;
            $successCount++;
            
            // Log to tracking table
            $appointmentListJson = json_encode($appointments);
            $messagePreview = mb_substr($message, 0, 500);
            $providerId = $sendResult['message_id'] ?? null;
            
            $logStmt = $db->prepare("
                INSERT INTO agent_call_reminders_sent 
                (agent_id, agent_name, agent_phone, reminder_date, appointment_date, 
                 appointment_count, appointment_list, message_preview, provider_message_id, channel, status)
                VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'whatsapp', 'sent')
            ");
            
            $logStmt->bind_param(
                'isssisss',
                $agentId,
                $agentName,
                $normalizedPhone,
                $tomorrowDate,
                $appointmentCount,
                $appointmentListJson,
                $messagePreview,
                $providerId
            );
            
            $logStmt->execute();
            $logStmt->close();
            
            $sentDetails[] = [
                'agent_name' => $agentName,
                'count' => $appointmentCount
            ];
        } else {
            echo "  ✗ Failed to send: " . ($sendResult['error'] ?? 'Unknown error') . PHP_EOL;
            $failedCount++;
        }
        
        echo PHP_EOL;
        
        // Brief delay between sends
        usleep(500000); // 0.5 seconds
    }
    
    // ===========================
    // Send Admin Notification
    // ===========================
    echo "Sending admin notification..." . PHP_EOL;
    
    $adminMessage = "📊 *Agent Call Reminders Summary*\n\n";
    $adminMessage .= "Date: " . date('l, j F Y') . "\n";
    $adminMessage .= "Time: " . date('H:i:s') . "\n";
    $adminMessage .= "For Appointments: " . date('l, j F Y', strtotime($tomorrowDate)) . "\n\n";
    
    $adminMessage .= "✅ *Sent*: {$successCount}\n";
    $adminMessage .= "⚠️ *Skipped* (duplicate): {$skippedCount}\n";
    $adminMessage .= "❌ *Failed*: {$failedCount}\n\n";
    
    if (!empty($sentDetails)) {
        $adminMessage .= "📋 *Reminders Sent:*\n";
        foreach (array_slice($sentDetails, 0, 10) as $detail) {
            $adminMessage .= "→ {$detail['agent_name']}: {$detail['count']} call(s)\n";
        }
        
        if (count($sentDetails) > 10) {
            $remaining = count($sentDetails) - 10;
            $adminMessage .= "... and {$remaining} more\n";
        }
    }
    
    $adminMessage .= "\n✅ Cron job completed.";
    
    $whatsapp->send($adminPhone, $adminMessage, [
        'log' => true,
        'source_type' => 'admin_notification'
    ]);
    
    echo "✓ Admin notification sent" . PHP_EOL;
    echo PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "✅ Cron job completed successfully!" . PHP_EOL;
    echo "   Sent: {$successCount} | Skipped: {$skippedCount} | Failed: {$failedCount}" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    
} catch (Throwable $e) {
    echo PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "❌ ERROR: " . $e->getMessage() . PHP_EOL;
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    
    error_log("Agent call reminders cron error: " . $e->getMessage());
    
    // Try to send error notification to admin
    try {
        if (isset($whatsapp)) {
            $errorMsg = "⚠️ *Agent Call Reminders Cron Error*\n\n";
            $errorMsg .= "Time: " . date('Y-m-d H:i:s') . "\n";
            $errorMsg .= "Error: " . $e->getMessage() . "\n\n";
            $errorMsg .= "Please check the logs.";
            
            $whatsapp->send($adminPhone, $errorMsg, ['log' => false]);
        }
    } catch (Exception $notifyError) {
        // Ignore notification errors
    }
    
    exit(1);
}
