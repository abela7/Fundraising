<?php
/**
 * Twilio IVR - Donor Menu Handler
 * 
 * Options:
 * 1 - Check outstanding balance
 * 2 - Make a payment (then asks payment method)
 * 3 - Contact church member (sends SMS)
 * 4 - Repeat menu
 * 
 * Uses Google Neural voice for natural speech
 */

declare(strict_types=1);

header('Content-Type: text/xml');

error_log("Twilio IVR Donor Menu: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    
    $callerNumber = $_GET['caller'] ?? $_POST['From'] ?? '';
    $donorId = (int)($_GET['donor_id'] ?? 0);
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    // Get donor info
    $donor = getDonor($db, $donorId);
    
    // Update call record
    updateCallSelection($db, $callSid, 'donor_menu_' . $digits);
    
    $baseUrl = getBaseUrl();
    
    // Google Neural voice - British male, very natural
    $voice = 'Google.en-GB-Neural2-B';
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    switch ($digits) {
        case '1':
            // Check outstanding balance
            handleBalanceCheck($donor, $voice);
            break;
            
        case '2':
            // Make a payment - ask for payment method
            handlePaymentMethodSelection($db, $donor, $callerNumber, $baseUrl, $voice);
            break;
            
        case '3':
            // Contact church member - send SMS
            handleContactChurch($db, $callerNumber, $donor, $voice);
            break;
            
        case '4':
            // Repeat main menu
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
            break;
            
        default:
            // Invalid option
            echo '<Say voice="' . $voice . '">Invalid option. Please try again.</Say>';
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    }
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("IVR Donor Menu Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Google.en-GB-Neural2-B">We are sorry, we are experiencing technical difficulties. Please try again later. God bless you.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Option 1: Check Balance
 */
function handleBalanceCheck(?array $donor, string $voice): void
{
    if (!$donor) {
        echo '<Say voice="' . $voice . '">We could not retrieve your account information. Please try again later.</Say>';
        echo '<Hangup/>';
        return;
    }
    
    $totalPledged = (float)($donor['total_pledged'] ?? 0);
    $totalPaid = (float)($donor['total_paid'] ?? 0);
    $balance = (float)($donor['balance'] ?? 0);
    
    // Recalculate if needed
    if ($balance <= 0 && $totalPledged > 0) {
        $balance = $totalPledged - $totalPaid;
    }
    
    echo '<Say voice="' . $voice . '">';
    echo 'Here is your account summary.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Your total pledge amount is ' . speakMoney($totalPledged) . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'You have paid ' . speakMoney($totalPaid) . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    if ($balance > 0) {
        echo '<Say voice="' . $voice . '">';
        echo 'Your outstanding balance is ' . speakMoney($balance) . '.';
        echo '</Say>';
    } else {
        echo '<Say voice="' . $voice . '">';
        echo 'Congratulations! You have fully paid your pledge.';
        echo '</Say>';
    }
    
    echo '<Pause length="2"/>';
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for calling. May God bless you abundantly. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Option 2: Make Payment - Ask for payment method
 */
function handlePaymentMethodSelection($db, ?array $donor, string $callerNumber, string $baseUrl, string $voice): void
{
    if (!$donor) {
        echo '<Say voice="' . $voice . '">We could not retrieve your account information. Please try again later.</Say>';
        echo '<Hangup/>';
        return;
    }
    
    $balance = (float)($donor['balance'] ?? 0);
    $totalPledged = (float)($donor['total_pledged'] ?? 0);
    $totalPaid = (float)($donor['total_paid'] ?? 0);
    
    if ($balance <= 0 && $totalPledged > 0) {
        $balance = $totalPledged - $totalPaid;
    }
    
    if ($balance <= 0) {
        echo '<Say voice="' . $voice . '">';
        echo 'Great news! You have no outstanding balance. Your pledge has been fully paid.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '">';
        echo 'Thank you for your generous support. May God bless you. Goodbye.';
        echo '</Say>';
        echo '<Hangup/>';
        return;
    }
    
    echo '<Say voice="' . $voice . '">';
    echo 'Your outstanding balance is ' . speakMoney($balance) . '.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'How would you like to make your payment?';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-payment-method.php?caller=' . urlencode($callerNumber) . '&amp;donor_id=' . $donor['id'] . '&amp;balance=' . $balance . '" method="POST" timeout="60">';
    
    echo '<Say voice="' . $voice . '">';
    echo 'For bank transfer, press 1.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'For cash payment, press 2.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'To go back to the main menu, press 3.';
    echo '</Say>';
    
    echo '</Gather>';
    
    echo '<Say voice="' . $voice . '">We did not receive any input. Goodbye.</Say>';
    echo '<Hangup/>';
}

/**
 * Option 3: Contact Church Member - Send SMS
 */
function handleContactChurch($db, string $callerNumber, ?array $donor, string $voice): void
{
    $churchAdmin = 'Liqe Tighuan Kesis Birhanu';
    $churchPhone = '+44 7473 822244';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We will send you an SMS with the contact details of our church administrator.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send SMS to caller
    $smsResult = sendSmsToCalller($db, $callerNumber, $churchAdmin, $churchPhone);
    
    if ($smsResult) {
        echo '<Say voice="' . $voice . '">';
        echo 'The SMS has been sent to your phone.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '">';
        echo 'Please contact ' . $churchAdmin . ' at ' . speakPhoneNumber($churchPhone) . '.';
        echo '</Say>';
        
        // Update call record
        updateSmsSent($db, $_POST['CallSid'] ?? '');
    } else {
        echo '<Say voice="' . $voice . '">';
        echo 'We could not send the SMS at this time.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '">';
        echo 'Please contact ' . $churchAdmin . ' directly at ' . speakPhoneNumber($churchPhone) . '.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '">';
        echo 'I will repeat that number. ' . speakPhoneNumber($churchPhone) . '.';
        echo '</Say>';
    }
    
    echo '<Pause length="2"/>';
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for calling. May God bless you. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Send SMS to caller with church contact details
 */
function sendSmsToCalller($db, string $callerPhone, string $adminName, string $adminPhone): bool
{
    try {
        require_once __DIR__ . '/../../../services/SMSHelper.php';
        
        // Create SMSHelper instance
        $smsHelper = new SMSHelper($db);
        
        $message = "Liverpool Mekane Kidusan Abune Teklehaymanot EOTC\n\n";
        $message .= "Church Administrator Contact:\n";
        $message .= "{$adminName} - {$adminPhone}\n\n";
        $message .= "God bless you!";
        
        // Normalize phone number
        $toPhone = $callerPhone;
        if (strpos($toPhone, '+44') === 0) {
            $toPhone = '0' . substr($toPhone, 3);
        }
        
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
function getDonor($db, int $donorId): ?array
{
    if ($donorId <= 0) return null;
    
    $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $donor;
}

function speakMoney(float $amount): string
{
    $pounds = (int)floor($amount);
    $pence = (int)round(($amount - $pounds) * 100);
    
    $result = $pounds . ' pounds';
    if ($pence > 0) {
        $result .= ' and ' . $pence . ' pence';
    }
    return $result;
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
