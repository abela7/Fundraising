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

header('Content-Type: text/xml');

error_log("Twilio IVR General Menu: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    
    $callerNumber = $_GET['caller'] ?? $_POST['From'] ?? '';
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    // Update call record
    updateCallSelection($db, $callSid, 'general_menu_' . $digits);
    
    $baseUrl = getBaseUrl();
    
    // Google Neural voice - British male, very natural
    $voice = 'Google.en-GB-Neural2-B';
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    switch ($digits) {
        case '1':
            // Learn about church (verbal)
            handleAboutChurch($voice, $baseUrl);
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
    
} catch (Exception $e) {
    error_log("IVR General Menu Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Google.en-GB-Neural2-B">We are sorry, we are experiencing technical difficulties. Please try again later. God bless you.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Option 1: About the Church (Verbal Info)
 */
function handleAboutChurch(string $voice, string $baseUrl): void
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
    
    // Offer to send SMS with more info
    echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-general-menu.php" method="POST" timeout="30">';
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
    echo '<Say voice="' . $voice . '">';
    echo 'We will send you an SMS with links to learn more about our church.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send SMS with website links
    $smsResult = sendInfoSms($db, $callerNumber);
    
    if ($smsResult) {
        echo '<Say voice="' . $voice . '">';
        echo 'The SMS has been sent to your phone. Please check your messages.';
        echo '</Say>';
        
        // Update call record
        updateSmsSent($db, $callSid);
    } else {
        echo '<Say voice="' . $voice . '">';
        echo 'We could not send the SMS at this time. Please visit our website at abune teklehaymanot dot org.';
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
    $churchAdmin = 'Abel';
    $churchPhone = '07360436171';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We will send you an SMS with our church administrator contact details.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send SMS with contact details
    $smsResult = sendContactSms($db, $callerNumber, $churchAdmin, $churchPhone);
    
    if ($smsResult) {
        echo '<Say voice="' . $voice . '">';
        echo 'The SMS has been sent to your phone.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        // Update call record
        updateSmsSent($db, $callSid);
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
function sendInfoSms($db, string $callerPhone): bool
{
    try {
        require_once __DIR__ . '/../../../services/SMSHelper.php';
        
        // Create SMSHelper instance
        $smsHelper = new SMSHelper($db);
        
        $message = "Welcome to Liverpool Mekane Kidusan Abune Teklehaymanot Ethiopian Orthodox Tewahedo Church!\n\n";
        $message .= "To learn more about us, visit:\n";
        $message .= "https://abuneteklehaymanot.org/\n\n";
        $message .= "To support our church building fund, please visit:\n";
        $message .= "https://donate.abuneteklehaymanot.org/\n\n";
        $message .= "May God bless you!";
        
        // Normalize phone number
        $toPhone = normalizePhoneForSms($callerPhone);
        
        // Use sendDirect method
        $result = $smsHelper->sendDirect($toPhone, $message, null, 'ivr_info_request');
        
        return ($result['success'] ?? false);
        
    } catch (Exception $e) {
        error_log("SMS send error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send SMS with church admin contact details
 */
function sendContactSms($db, string $callerPhone, string $adminName, string $adminPhone): bool
{
    try {
        require_once __DIR__ . '/../../../services/SMSHelper.php';
        
        // Create SMSHelper instance
        $smsHelper = new SMSHelper($db);
        
        $message = "Liverpool Mekane Kidusan Abune Teklehaymanot EOTC\n\n";
        $message .= "Church Administrator Contact:\n";
        $message .= "{$adminName} - {$adminPhone}\n\n";
        $message .= "Website: https://abuneteklehaymanot.org/\n\n";
        $message .= "God bless you!";
        
        // Normalize phone number
        $toPhone = normalizePhoneForSms($callerPhone);
        
        // Use sendDirect method
        $result = $smsHelper->sendDirect($toPhone, $message, null, 'ivr_contact_request');
        
        return ($result['success'] ?? false);
        
    } catch (Exception $e) {
        error_log("SMS send error: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper functions
 */
function normalizePhoneForSms(string $phone): string
{
    if (strpos($phone, '+44') === 0) {
        return '0' . substr($phone, 3);
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
    try {
        $stmt = $db->prepare("UPDATE twilio_inbound_calls SET menu_selection = ? WHERE call_sid = ?");
        $stmt->bind_param('ss', $selection, $callSid);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Update selection error: " . $e->getMessage());
    }
}

function updateSmsSent($db, string $callSid): void
{
    try {
        // Check if column exists
        $check = $db->query("SHOW COLUMNS FROM twilio_inbound_calls LIKE 'sms_sent'");
        if ($check && $check->num_rows > 0) {
            $stmt = $db->prepare("UPDATE twilio_inbound_calls SET sms_sent = 1 WHERE call_sid = ?");
            $stmt->bind_param('s', $callSid);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Update SMS sent error: " . $e->getMessage());
    }
}

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}
