<?php
/**
 * Twilio IVR - General Menu Handler (Non-Donors / New Callers)
 * 
 * Options:
 * 1 - Learn about our church (verbal info)
 * 2 - Receive SMS with website links
 * 3 - How to support/donate (bank details)
 * 4 - Get church admin contact details
 * 5 - Repeat menu
 * 
 * Uses Google Neural voice for natural speech
 */

declare(strict_types=1);

// Catch all errors and convert to TwiML response
set_error_handler(function($severity, $message, $file, $line) {
    error_log("IVR PHP Error: $message in $file:$line");
    throw new ErrorException($message, 0, $severity, $file, $line);
});

header('Content-Type: text/xml');

error_log("Twilio IVR General Menu - POST: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

// Google Neural voice
$voice = 'Google.en-GB-Neural2-B';

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    
    // Get caller number - try multiple sources
    $callerNumber = '';
    if (!empty($_GET['caller'])) {
        $callerNumber = $_GET['caller'];
    } elseif (!empty($_POST['From'])) {
        $callerNumber = $_POST['From'];
    } elseif (!empty($_POST['Caller'])) {
        $callerNumber = $_POST['Caller'];
    }
    
    error_log("IVR General Menu - Caller Number: " . $callerNumber);
    
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    // Update call record
    if ($callSid) {
        updateCallSelection($db, $callSid, 'general_menu_' . $digits);
    }
    
    $baseUrl = getBaseUrl();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    switch ($digits) {
        case '1':
            // Learn about church (verbal)
            handleAboutChurch($voice, $baseUrl, $callerNumber);
            break;
            
        case '2':
            // Send SMS with website links
            handleSendInfoSms($db, $callerNumber, $voice, $callSid);
            break;
            
        case '3':
            // How to support/donate
            handleDonationInfo($voice);
            break;
            
        case '4':
            // Church admin contact
            handleContactDetails($db, $callerNumber, $voice, $callSid);
            break;
            
        case '5':
            // Repeat main menu
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
            break;
            
        default:
            echo '<Say voice="' . $voice . '">Invalid option. Please try again.</Say>';
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    }
    
    echo '</Response>';
    
} catch (Throwable $e) {
    error_log("IVR General Menu FATAL Error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    
    // Always return valid TwiML even on error
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="' . $voice . '">We apologize, but we could not complete your request at this time. Please try again later or visit our website at abune teklehaymanot dot org. God bless you. Goodbye.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Option 1: About the Church (Verbal Info)
 */
function handleAboutChurch(string $voice, string $baseUrl, string $callerNumber): void
{
    echo '<Say voice="' . $voice . '">';
    echo 'Liverpool Mekane Kidusan Abune Teklehaymanot is an Ethiopian Orthodox Tewahedo Church serving the Ethiopian community in Liverpool and the surrounding areas.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Our church was established in 2014 and has been a spiritual home for many faithful members. We hold regular services, celebrations, and community events.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We are currently working towards purchasing our own church building, and we warmly welcome any support from the community.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We welcome everyone to join us for worship and fellowship.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    // Offer to send SMS with more info - pass caller number
    echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-general-menu.php?caller=' . urlencode($callerNumber) . '" method="POST" timeout="30">';
    echo '<Say voice="' . $voice . '">';
    echo 'Would you like to receive more information via SMS? Press 2 to receive our website links. Press 5 to return to the main menu. Or simply hang up if you are done.';
    echo '</Say>';
    echo '</Gather>';
    
    echo '<Say voice="' . $voice . '">Thank you for your interest in our church. May God bless you. Goodbye.</Say>';
    echo '<Hangup/>';
}

/**
 * Option 2: Send SMS with Website Links
 */
function handleSendInfoSms($db, string $callerNumber, string $voice, string $callSid): void
{
    error_log("handleSendInfoSms - Caller: $callerNumber, CallSid: $callSid");
    
    // Check if we have a phone number
    if (empty($callerNumber)) {
        error_log("ERROR: No caller number available for SMS");
        echo '<Say voice="' . $voice . '">';
        echo 'We apologize, but we could not identify your phone number to send the SMS. Please visit our website at abune teklehaymanot dot org for more information.';
        echo '</Say>';
        echo '<Pause length="2"/>';
        echo '<Say voice="' . $voice . '">Thank you for calling. God bless you. Goodbye.</Say>';
        echo '<Hangup/>';
        return;
    }
    
    echo '<Say voice="' . $voice . '">';
    echo 'We will send you an SMS with links to learn more about our church.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send SMS with website links
    $smsResult = sendInfoSms($db, $callerNumber);
    
    error_log("SMS Result: " . json_encode($smsResult));
    
    if ($smsResult['success']) {
        echo '<Say voice="' . $voice . '">';
        echo 'The SMS has been sent to your phone. Please check your messages.';
        echo '</Say>';
        
        // Update call record
        if ($callSid) {
            updateSmsSent($db, $callSid);
        }
    } else {
        error_log("SMS Failed - Error: " . ($smsResult['error'] ?? 'Unknown'));
        echo '<Say voice="' . $voice . '">';
        echo 'We could not send the SMS at this time. Please visit our website at abune teklehaymanot dot org for more information.';
        echo '</Say>';
    }
    
    echo '<Pause length="2"/>';
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for your interest in our church. May God bless you abundantly. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Option 3: Donation Information (Bank Details)
 */
function handleDonationInfo(string $voice): void
{
    // Bank details
    $bankName = 'Barclays Bank';
    $accountName = 'Liverpool Abune Teklehaymanot EOTC';
    $sortCode = '20-61-31';
    $accountNumber = '30926233';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for your interest in supporting our church.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We are currently fundraising to purchase our own church building. Your generous donation will help us achieve this goal and continue serving our community.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'To make a donation via bank transfer, please use the following details.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Bank name: ' . $bankName . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Account name: ' . $accountName . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Sort code: ' . speakSortCode($sortCode) . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Account number: ' . speakDigits($accountNumber) . '.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    // Repeat for clarity
    echo '<Say voice="' . $voice . '">';
    echo 'I will repeat the bank details.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Sort code: ' . speakSortCode($sortCode) . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Account number: ' . speakDigits($accountNumber) . '.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'You can also visit our donation website at donate dot abune teklehaymanot dot org to make a pledge or learn about other ways to give.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for your generous heart. May God bless you abundantly. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Option 4: Church Admin Contact Details
 */
function handleContactDetails($db, string $callerNumber, string $voice, string $callSid): void
{
    $churchAdmin = 'Liqe Tighuan Kesis Birhanu';
    $churchPhone = '+44 7473 822244';
    
    error_log("handleContactDetails - Caller: $callerNumber");
    
    // Check if we have a phone number
    if (empty($callerNumber)) {
        echo '<Say voice="' . $voice . '">';
        echo 'You can contact our church administrator, ' . $churchAdmin . ', at ' . speakPhoneNumber($churchPhone) . '.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '">';
        echo 'I will repeat that number. ' . speakPhoneNumber($churchPhone) . '.';
        echo '</Say>';
        echo '<Pause length="2"/>';
        echo '<Say voice="' . $voice . '">Thank you for calling. May God bless you. Goodbye.</Say>';
        echo '<Hangup/>';
        return;
    }
    
    echo '<Say voice="' . $voice . '">';
    echo 'We will send you an SMS with our church administrator contact details.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send SMS with contact details
    $smsResult = sendContactSms($db, $callerNumber, $churchAdmin, $churchPhone);
    
    if ($smsResult['success']) {
        echo '<Say voice="' . $voice . '">';
        echo 'The SMS has been sent to your phone.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        // Update call record
        if ($callSid) {
            updateSmsSent($db, $callSid);
        }
    } else {
        echo '<Say voice="' . $voice . '">';
        echo 'We could not send the SMS at this time.';
        echo '</Say>';
        echo '<Pause length="1"/>';
    }
    
    echo '<Say voice="' . $voice . '">';
    echo 'You can contact our church administrator, ' . $churchAdmin . ', at ' . speakPhoneNumber($churchPhone) . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'I will repeat that number. ' . speakPhoneNumber($churchPhone) . '.';
    echo '</Say>';
    
    echo '<Pause length="2"/>';
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for calling. May God bless you. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Send SMS with website links for more information
 */
function sendInfoSms($db, string $callerPhone): array
{
    error_log("sendInfoSms - Phone: $callerPhone");
    
    try {
        // Normalize phone number first
        $toPhone = normalizePhoneForSms($callerPhone);
        error_log("sendInfoSms - Normalized Phone: $toPhone");
        
        if (empty($toPhone) || strlen($toPhone) < 10) {
            return ['success' => false, 'error' => 'Invalid phone number'];
        }
        
        require_once __DIR__ . '/../../../services/SMSHelper.php';
        
        // Create SMSHelper instance
        $smsHelper = new SMSHelper($db);
        
        $message = "Welcome to Liverpool Mekane Kidusan Abune Teklehaymanot Ethiopian Orthodox Tewahedo Church!\n\n";
        $message .= "To learn more about us, visit:\n";
        $message .= "https://abuneteklehaymanot.org/\n\n";
        $message .= "To support our church building fund, please visit:\n";
        $message .= "https://donate.abuneteklehaymanot.org/\n\n";
        $message .= "May God bless you!";
        
        error_log("sendInfoSms - Sending to: $toPhone");
        
        // Use sendDirect method
        $result = $smsHelper->sendDirect($toPhone, $message, null, 'ivr_info_request');
        
        error_log("sendInfoSms - Result: " . json_encode($result));
        
        return $result;
        
    } catch (Throwable $e) {
        error_log("sendInfoSms ERROR: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send SMS with church admin contact details
 */
function sendContactSms($db, string $callerPhone, string $adminName, string $adminPhone): array
{
    error_log("sendContactSms - Phone: $callerPhone");
    
    try {
        // Normalize phone number first
        $toPhone = normalizePhoneForSms($callerPhone);
        
        if (empty($toPhone) || strlen($toPhone) < 10) {
            return ['success' => false, 'error' => 'Invalid phone number'];
        }
        
        require_once __DIR__ . '/../../../services/SMSHelper.php';
        
        // Create SMSHelper instance
        $smsHelper = new SMSHelper($db);
        
        $message = "Liverpool Mekane Kidusan Abune Teklehaymanot EOTC\n\n";
        $message .= "Church Administrator Contact:\n";
        $message .= "{$adminName} - {$adminPhone}\n\n";
        $message .= "Website: https://abuneteklehaymanot.org/\n\n";
        $message .= "God bless you!";
        
        // Use sendDirect method
        $result = $smsHelper->sendDirect($toPhone, $message, null, 'ivr_contact_request');
        
        return $result;
        
    } catch (Throwable $e) {
        error_log("sendContactSms ERROR: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Helper functions
 */
function normalizePhoneForSms(string $phone): string
{
    // Remove any whitespace
    $phone = trim($phone);
    
    // Convert +44 to 0
    if (strpos($phone, '+44') === 0) {
        return '0' . substr($phone, 3);
    }
    
    // Convert 44 to 0 (without +)
    if (strpos($phone, '44') === 0 && strlen($phone) > 10) {
        return '0' . substr($phone, 2);
    }
    
    return $phone;
}

function speakDigits(string $number): string
{
    $digits = preg_replace('/[^0-9]/', '', $number);
    return implode(' ', str_split($digits));
}

function speakSortCode(string $sortCode): string
{
    $parts = explode('-', $sortCode);
    $spoken = [];
    foreach ($parts as $part) {
        $spoken[] = implode(' ', str_split($part));
    }
    return implode(', ', $spoken);
}

function speakPhoneNumber(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone);
    return implode(' ', str_split($digits));
}

function updateCallSelection($db, string $callSid, string $selection): void
{
    if (empty($callSid)) return;
    
    try {
        $stmt = $db->prepare("UPDATE twilio_inbound_calls SET menu_selection = ? WHERE call_sid = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $selection, $callSid);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log("Update selection error: " . $e->getMessage());
    }
}

function updateSmsSent($db, string $callSid): void
{
    if (empty($callSid)) return;
    
    try {
        // Check if column exists
        $check = $db->query("SHOW COLUMNS FROM twilio_inbound_calls LIKE 'sms_sent'");
        if ($check && $check->num_rows > 0) {
            $stmt = $db->prepare("UPDATE twilio_inbound_calls SET sms_sent = 1 WHERE call_sid = ?");
            if ($stmt) {
                $stmt->bind_param('s', $callSid);
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log("Update SMS sent error: " . $e->getMessage());
    }
}

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}
