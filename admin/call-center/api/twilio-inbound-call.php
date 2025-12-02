<?php
/**
 * Twilio Inbound Call IVR System - Main Entry Point
 * 
 * Two flows:
 * 1. DONOR calling (phone found in database) - personalized menu
 * 2. NON-DONOR calling (phone not found) - general menu with more options
 * 
 * Supports custom voice recordings or TTS fallback
 */

declare(strict_types=1);

header('Content-Type: text/xml');

error_log("Twilio Inbound Call: " . json_encode($_POST));

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/IVRRecordingService.php';
    
    $db = db();
    
    // Initialize recording service (will use recordings if available, TTS otherwise)
    $ivr = null;
    try {
        $ivr = new IVRRecordingService($db);
    } catch (Exception $e) {
        error_log("IVR Recording service error: " . $e->getMessage());
    }
    
    // Get caller info
    $callerNumber = $_POST['From'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    // Normalize phone number
    $normalizedPhone = normalizePhone($callerNumber);
    
    // Look up donor
    $donor = lookupDonor($db, $normalizedPhone, $callerNumber);
    
    // Log the inbound call
    logInboundCall($db, $callerNumber, $callSid, $donor);
    
    $baseUrl = getBaseUrl();
    
    // Default voice for TTS fallback
    $voice = 'Google.en-GB-Neural2-B';
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    // Welcome message - use recording if available, otherwise TTS
    if ($ivr && $ivr->hasRecording('welcome_main')) {
        echo $ivr->getTwiML('welcome_main');
    } else {
        echo '<Say voice="' . $voice . '">';
        echo 'Welcome to Liverpool Mekane Kidusan Abune Teklehaymanot, Ethiopian Orthodox Tewahedo Church.';
        echo '</Say>';
    }
    echo '<Pause length="2"/>';
    
    if ($donor) {
        // ===== DONOR FLOW =====
        echo '<Say voice="' . $voice . '">';
        echo 'Hello ' . htmlspecialchars($donor['name']) . '. Thank you for calling us today.';
        echo '</Say>';
        echo '<Pause length="2"/>';
        
        // Donor menu with 5 minute timeout (300 seconds)
        echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-donor-menu.php?caller=' . urlencode($callerNumber) . '&amp;donor_id=' . $donor['id'] . '" method="POST" timeout="300">';
        
        echo '<Say voice="' . $voice . '">';
        echo 'Please choose from the following options.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To check your outstanding balance, press 1.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To make a payment, press 2.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To contact a church member, press 3.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To hear these options again, press 4.';
        echo '</Say>';
        
        echo '</Gather>';
        
    } else {
        // ===== NON-DONOR / NEW CALLER FLOW =====
        echo '<Say voice="' . $voice . '">';
        echo 'Thank you for calling us today. We are happy to assist you.';
        echo '</Say>';
        echo '<Pause length="2"/>';
        
        // New caller menu with expanded options
        // Pass caller number in URL for SMS functionality
        $menuUrl = $baseUrl . 'twilio-ivr-general-menu.php?caller=' . urlencode($callerNumber);
        error_log("General Menu URL: " . $menuUrl);
        echo '<Gather numDigits="1" action="' . htmlspecialchars($menuUrl) . '" method="POST" timeout="300">';
        
        echo '<Say voice="' . $voice . '">';
        echo 'Please choose from the following options.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To learn about our church, press 1.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To receive a link via SMS with more information, press 2.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To hear how you can support our church, press 3.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To get our church administrator contact details, press 4.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        echo '<Say voice="' . $voice . '">';
        echo 'To hear these options again, press 5.';
        echo '</Say>';
        
        echo '</Gather>';
    }
    
    // If no input after 5 minutes
    echo '<Say voice="' . $voice . '">';
    echo 'We did not receive any input. Thank you for calling. God bless you. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("Twilio Inbound Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Google.en-GB-Neural2-B">We are sorry, we are experiencing technical difficulties. Please try again later. God bless you.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

function normalizePhone(string $phone): string
{
    if (strpos($phone, '+44') === 0) {
        return '0' . substr($phone, 3);
    } elseif (strpos($phone, '44') === 0 && strlen($phone) > 10) {
        return '0' . substr($phone, 2);
    }
    return $phone;
}

function lookupDonor($db, string $normalizedPhone, string $originalPhone): ?array
{
    $stmt = $db->prepare("
        SELECT d.id, d.name, d.phone, d.balance, d.total_pledged, d.total_paid
        FROM donors d
        WHERE d.phone = ? OR d.phone = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $normalizedPhone, $originalPhone);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_assoc();
    $stmt->close();
    return $donor;
}

function logInboundCall($db, string $phone, string $callSid, ?array $donor): void
{
    try {
        // Check/create table
        $check = $db->query("SHOW TABLES LIKE 'twilio_inbound_calls'");
        if ($check->num_rows === 0) {
            $db->query("
                CREATE TABLE `twilio_inbound_calls` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `call_sid` VARCHAR(100) NOT NULL,
                    `caller_phone` VARCHAR(20) NOT NULL,
                    `donor_id` INT NULL,
                    `donor_name` VARCHAR(255) NULL,
                    `is_donor` TINYINT(1) DEFAULT 0,
                    `menu_selection` VARCHAR(50) NULL,
                    `payment_method` VARCHAR(20) NULL,
                    `payment_amount` DECIMAL(10,2) NULL,
                    `payment_status` ENUM('none','pending','confirmed','failed') DEFAULT 'none',
                    `whatsapp_sent` TINYINT(1) DEFAULT 0,
                    `sms_sent` TINYINT(1) DEFAULT 0,
                    `agent_followed_up` TINYINT(1) DEFAULT 0,
                    `followed_up_by` INT NULL,
                    `followed_up_at` DATETIME NULL,
                    `notes` TEXT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_caller_phone` (`caller_phone`),
                    INDEX `idx_donor_id` (`donor_id`),
                    INDEX `idx_payment_status` (`payment_status`),
                    INDEX `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        $donorId = $donor ? (int)$donor['id'] : null;
        $donorName = $donor ? $donor['name'] : null;
        $isDonor = $donor ? 1 : 0;
        
        $stmt = $db->prepare("INSERT INTO twilio_inbound_calls (call_sid, caller_phone, donor_id, donor_name, is_donor) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('ssisi', $callSid, $phone, $donorId, $donorName, $isDonor);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Log inbound error: " . $e->getMessage());
    }
}

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}
