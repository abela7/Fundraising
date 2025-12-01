<?php
/**
 * Twilio Inbound Call Webhook
 * 
 * Handles incoming calls to the Twilio number.
 * When a donor calls back:
 * 1. Look up caller in donors table
 * 2. Send WhatsApp message with details
 * 3. Play brief voice message
 * 4. Log the callback for agent follow-up
 */

declare(strict_types=1);

// Set content type for TwiML response
header('Content-Type: text/xml');

// Log all inbound calls for debugging
error_log("Twilio Inbound Call: " . json_encode($_POST));

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/UltraMsgService.php';
    
    $db = db();
    
    // Get caller info from Twilio
    $callerNumber = $_POST['From'] ?? '';
    $calledNumber = $_POST['To'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    $callStatus = $_POST['CallStatus'] ?? '';
    
    // Normalize phone number (remove +44, add 0)
    $normalizedPhone = $callerNumber;
    if (strpos($callerNumber, '+44') === 0) {
        $normalizedPhone = '0' . substr($callerNumber, 3);
    } elseif (strpos($callerNumber, '44') === 0) {
        $normalizedPhone = '0' . substr($callerNumber, 2);
    }
    
    // Look up donor by phone number
    $donor = null;
    $stmt = $db->prepare("
        SELECT d.*, 
               COALESCE(d.balance, 0) as balance,
               COALESCE(d.total_pledged, 0) as total_pledged,
               c.name as church_name
        FROM donors d
        LEFT JOIN churches c ON d.church_id = c.id
        WHERE d.phone = ? OR d.phone = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $normalizedPhone, $callerNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_assoc();
    $stmt->close();
    
    // Log this callback attempt
    logCallbackAttempt($db, $callerNumber, $callSid, $donor);
    
    // Send WhatsApp message if donor found and WhatsApp is configured
    $whatsappSent = false;
    $whatsappError = null;
    
    if ($donor) {
        $whatsappResult = sendWhatsAppCallback($db, $donor, $callerNumber);
        $whatsappSent = $whatsappResult['success'];
        $whatsappError = $whatsappResult['error'] ?? null;
    }
    
    // Generate TwiML response
    $donorName = $donor ? $donor['name'] : null;
    $twiml = generateTwimlResponse($donorName, $whatsappSent);
    
    echo $twiml;
    
} catch (Exception $e) {
    error_log("Twilio Inbound Error: " . $e->getMessage());
    
    // Return a generic error message
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="alice" language="en-GB">Thank you for calling. We are unable to process your call at this time. Please try again later.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Log the callback attempt to database
 */
function logCallbackAttempt($db, string $callerPhone, string $callSid, ?array $donor): void
{
    try {
        // Check if inbound_calls table exists, create if not
        $tableCheck = $db->query("SHOW TABLES LIKE 'twilio_inbound_calls'");
        if ($tableCheck->num_rows === 0) {
            $db->query("
                CREATE TABLE `twilio_inbound_calls` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `call_sid` VARCHAR(100) NOT NULL,
                    `caller_phone` VARCHAR(20) NOT NULL,
                    `donor_id` INT NULL,
                    `donor_name` VARCHAR(255) NULL,
                    `whatsapp_sent` TINYINT(1) DEFAULT 0,
                    `whatsapp_message_id` VARCHAR(100) NULL,
                    `agent_followed_up` TINYINT(1) DEFAULT 0,
                    `followed_up_by` INT NULL,
                    `followed_up_at` DATETIME NULL,
                    `notes` TEXT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_caller_phone` (`caller_phone`),
                    INDEX `idx_donor_id` (`donor_id`),
                    INDEX `idx_created_at` (`created_at`),
                    INDEX `idx_followed_up` (`agent_followed_up`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        $donorId = $donor ? (int)$donor['id'] : null;
        $donorName = $donor ? $donor['name'] : null;
        
        $stmt = $db->prepare("
            INSERT INTO twilio_inbound_calls (call_sid, caller_phone, donor_id, donor_name)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('ssis', $callSid, $callerPhone, $donorId, $donorName);
        $stmt->execute();
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Failed to log inbound call: " . $e->getMessage());
    }
}

/**
 * Send WhatsApp message to the donor
 */
function sendWhatsAppCallback($db, array $donor, string $phone): array
{
    try {
        // Load WhatsApp service
        $whatsapp = UltraMsgService::fromDatabase($db);
        
        if (!$whatsapp) {
            return ['success' => false, 'error' => 'WhatsApp not configured'];
        }
        
        // Get bank details from settings (if available)
        $bankDetails = getBankDetails($db);
        
        // Build personalized message
        $donorName = $donor['name'] ?? 'Valued Donor';
        $balance = number_format((float)($donor['balance'] ?? 0), 2);
        $reference = str_pad((string)$donor['id'], 4, '0', STR_PAD_LEFT);
        
        $message = "Hello {$donorName},\n\n";
        $message .= "Thank you for returning our call from Liverpool Abune Teklehaymanot Church.\n\n";
        
        if ((float)$donor['balance'] > 0) {
            $message .= "We were calling regarding your outstanding pledge of *£{$balance}*.\n\n";
        } else {
            $message .= "We were calling to speak with you about our church building fund.\n\n";
        }
        
        $message .= "An agent will contact you again shortly. If you'd like to reach us sooner, please reply to this message.\n\n";
        
        // Add bank details if available
        if ($bankDetails) {
            $message .= "*Bank Details for Payment:*\n";
            $message .= "Account Name: {$bankDetails['account_name']}\n";
            $message .= "Sort Code: {$bankDetails['sort_code']}\n";
            $message .= "Account No: {$bankDetails['account_number']}\n";
            $message .= "Reference: Your name or {$reference}\n\n";
        }
        
        $message .= "God bless you! 🙏\n";
        $message .= "_Liverpool Abune Teklehaymanot EOTC_";
        
        // Send the message
        $result = $whatsapp->send($phone, $message, [
            'donor_id' => $donor['id'],
            'source_type' => 'inbound_callback',
            'log' => true
        ]);
        
        // Update the inbound call record with WhatsApp status
        if ($result['success'] && isset($result['message_id'])) {
            $stmt = $db->prepare("
                UPDATE twilio_inbound_calls 
                SET whatsapp_sent = 1, whatsapp_message_id = ?
                WHERE caller_phone = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->bind_param('ss', $result['message_id'], $phone);
            $stmt->execute();
            $stmt->close();
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("WhatsApp callback error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get bank details from settings
 */
function getBankDetails($db): ?array
{
    try {
        $result = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'bank_%'");
        if (!$result || $result->num_rows === 0) {
            // Return default/hardcoded values if not in settings
            return [
                'account_name' => 'Liverpool EOTC',
                'sort_code' => '30-96-26',
                'account_number' => '87aboron'
            ];
        }
        
        $settings = [];
        while ($row = $result->fetch_assoc()) {
            $key = str_replace('bank_', '', $row['setting_key']);
            $settings[$key] = $row['setting_value'];
        }
        
        if (empty($settings['account_name'])) {
            return [
                'account_name' => 'Liverpool EOTC',
                'sort_code' => '30-96-26',
                'account_number' => '87aboron'
            ];
        }
        
        return $settings;
        
    } catch (Exception $e) {
        error_log("Failed to get bank details: " . $e->getMessage());
        return null;
    }
}

/**
 * Generate TwiML response for the caller
 */
function generateTwimlResponse(?string $donorName, bool $whatsappSent): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<Response>';
    
    if ($donorName) {
        // Personalized message for known donors
        $greeting = "Hello " . htmlspecialchars($donorName) . ". ";
        $xml .= '<Say voice="alice" language="en-GB">' . $greeting . '</Say>';
        $xml .= '<Pause length="1"/>';
    } else {
        $xml .= '<Say voice="alice" language="en-GB">Hello, thank you for calling Liverpool Abune Teklehaymanot Church.</Say>';
        $xml .= '<Pause length="1"/>';
    }
    
    if ($whatsappSent) {
        $xml .= '<Say voice="alice" language="en-GB">We have sent you a WhatsApp message with all the details you need, including our bank information for making a payment. Please check your WhatsApp.</Say>';
        $xml .= '<Pause length="1"/>';
        $xml .= '<Say voice="alice" language="en-GB">An agent will also call you back shortly. Thank you, and God bless you.</Say>';
    } else {
        $xml .= '<Say voice="alice" language="en-GB">Thank you for returning our call. An agent will contact you shortly.</Say>';
        $xml .= '<Pause length="1"/>';
        $xml .= '<Say voice="alice" language="en-GB">If you need immediate assistance, please call back during office hours. Thank you, and God bless you.</Say>';
    }
    
    $xml .= '<Hangup/>';
    $xml .= '</Response>';
    
    return $xml;
}

