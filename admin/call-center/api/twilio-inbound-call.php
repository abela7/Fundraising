<?php
/**
 * Twilio Inbound Call IVR System
 * 
 * Professional menu system for incoming calls:
 * 1. Make a payment
 * 2. Check outstanding balance
 * 3. Contact a church member
 */

declare(strict_types=1);

header('Content-Type: text/xml');

// Log inbound calls
error_log("Twilio Inbound Call: " . json_encode($_POST));

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    
    // Get caller info
    $callerNumber = $_POST['From'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    $digits = $_POST['Digits'] ?? '';
    
    // Normalize phone number
    $normalizedPhone = normalizePhone($callerNumber);
    
    // Look up donor
    $donor = lookupDonor($db, $normalizedPhone, $callerNumber);
    
    // Log the inbound call
    logInboundCall($db, $callerNumber, $callSid, $donor);
    
    // Generate main menu TwiML
    $baseUrl = getBaseUrl();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    // Use Amazon Polly Neural voice for natural sound
    $voice = 'Polly.Amy-Neural';
    
    // Welcome message with SSML for natural pauses and emphasis
    echo '<Say voice="' . $voice . '">';
    echo '<speak>';
    echo '<prosody rate="95%">';  // Slightly slower for clarity
    echo 'Welcome to Liverpool Abune Teklehaymanot <break time="300ms"/> Ethiopian Orthodox Tewahedo Church.';
    echo '</prosody>';
    echo '</speak>';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Personalize if donor found
    if ($donor) {
        echo '<Say voice="' . $voice . '">';
        echo '<speak>';
        echo 'Hello <emphasis level="moderate">' . htmlspecialchars($donor['name']) . '</emphasis>. <break time="300ms"/> Thank you for calling.';
        echo '</speak>';
        echo '</Say>';
        echo '<Pause length="1"/>';
    }
    
    // Menu with Gather - natural pacing
    echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-menu.php?caller=' . urlencode($callerNumber) . '" method="POST" timeout="10">';
    echo '<Say voice="' . $voice . '">';
    echo '<speak>';
    echo '<prosody rate="95%">';
    echo 'Please choose from the following options. <break time="500ms"/>';
    echo 'To make a payment, <break time="200ms"/> press <say-as interpret-as="number">1</say-as>. <break time="400ms"/>';
    echo 'To check your outstanding balance, <break time="200ms"/> press <say-as interpret-as="number">2</say-as>. <break time="400ms"/>';
    echo 'To speak with a church member, <break time="200ms"/> press <say-as interpret-as="number">3</say-as>.';
    echo '</prosody>';
    echo '</speak>';
    echo '</Say>';
    echo '</Gather>';
    
    // If no input
    echo '<Say voice="' . $voice . '">We didn\'t receive any input. Please call back and try again. Goodbye.</Say>';
    echo '<Hangup/>';
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("Twilio Inbound Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="alice" language="en-GB">We are experiencing technical difficulties. Please try again later.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

function normalizePhone(string $phone): string
{
    if (strpos($phone, '+44') === 0) {
        return '0' . substr($phone, 3);
    } elseif (strpos($phone, '44') === 0) {
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
                    `menu_selection` VARCHAR(20) NULL,
                    `payment_amount` DECIMAL(10,2) NULL,
                    `payment_status` ENUM('none','pending','confirmed','failed') DEFAULT 'none',
                    `whatsapp_sent` TINYINT(1) DEFAULT 0,
                    `agent_followed_up` TINYINT(1) DEFAULT 0,
                    `followed_up_by` INT NULL,
                    `followed_up_at` DATETIME NULL,
                    `notes` TEXT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_caller_phone` (`caller_phone`),
                    INDEX `idx_donor_id` (`donor_id`),
                    INDEX `idx_payment_status` (`payment_status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        $donorId = $donor ? (int)$donor['id'] : null;
        $donorName = $donor ? $donor['name'] : null;
        
        $stmt = $db->prepare("INSERT INTO twilio_inbound_calls (call_sid, caller_phone, donor_id, donor_name) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssis', $callSid, $phone, $donorId, $donorName);
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
