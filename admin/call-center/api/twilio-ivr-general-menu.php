<?php
/**
 * Twilio IVR - General Menu Handler (Non-Donors)
 * 
 * Options:
 * 1 - Learn about church and donate (gives bank details)
 * 2 - Contact church member (sends SMS)
 * 3 - Repeat menu
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
            // Learn about church and donate
            handleAboutAndDonate($voice);
            break;
            
        case '2':
            // Contact church member
            handleContactChurch($db, $callerNumber, $voice, $callSid);
            break;
            
        case '3':
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
 * Option 1: About the Church and How to Donate
 */
function handleAboutAndDonate(string $voice): void
{
    // Bank details
    $bankName = 'Barclays Bank';
    $accountName = 'Liverpool Abune Teklehaymanot EOTC';
    $sortCode = '20-61-31';
    $accountNumber = '30926233';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Liverpool Abune Teklehaymanot is an Ethiopian Orthodox Tewahedo Church serving the Ethiopian community in Liverpool and surrounding areas.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We welcome everyone to join us for worship and community events.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'To support our church with a donation, you can make a bank transfer to the following account.';
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
    echo 'Thank you for your interest in supporting our church. May God bless you abundantly. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Option 2: Contact Church Member - Send SMS
 */
function handleContactChurch($db, string $callerNumber, string $voice, string $callSid): void
{
    $churchAdmin = 'Abel';
    $churchPhone = '07360436171';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We will send you an SMS with the contact details of our church administrator.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send SMS to caller
    $smsResult = sendSmsToCalller($callerNumber, $churchAdmin, $churchPhone);
    
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
    echo 'Please contact ' . $churchAdmin . ' at ' . speakPhoneNumber($churchPhone) . '.';
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
 * Send SMS to caller with church contact details
 */
function sendSmsToCalller(string $callerPhone, string $adminName, string $adminPhone): bool
{
    try {
        require_once __DIR__ . '/../../../services/SMSHelper.php';
        
        $message = "Liverpool Abune Teklehaymanot EOTC\n\nPlease contact {$adminName} - {$adminPhone}\n\nGod bless you!";
        
        // Normalize phone number
        $toPhone = $callerPhone;
        if (strpos($toPhone, '+44') === 0) {
            $toPhone = '0' . substr($toPhone, 3);
        }
        
        $result = SMSHelper::send($toPhone, $message, [
            'source_type' => 'ivr_contact_request',
            'log' => true
        ]);
        
        return $result['success'] ?? false;
        
    } catch (Exception $e) {
        error_log("SMS send error: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper functions
 */
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
