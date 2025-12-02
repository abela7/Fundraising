<?php
/**
 * Twilio IVR - Payment Method Handler
 * 
 * Options:
 * 1 - Bank Transfer (gives bank details + WhatsApp)
 * 2 - Cash Payment (sends WhatsApp to admin)
 * 3 - Back to menu
 */

declare(strict_types=1);

header('Content-Type: text/xml');

error_log("Twilio IVR Payment Method: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    
    $callerNumber = $_GET['caller'] ?? $_POST['From'] ?? '';
    $donorId = (int)($_GET['donor_id'] ?? 0);
    $balance = (float)($_GET['balance'] ?? 0);
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    // Get donor info
    $donor = getDonor($db, $donorId);
    
    // Update call record
    updateCallSelection($db, $callSid, 'payment_method_' . $digits);
    
    $baseUrl = getBaseUrl();
    
    // Use Google Neural voice - British male, very natural
    $voice = 'Google.en-GB-Neural2-B';
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    switch ($digits) {
        case '1':
            // Bank Transfer
            handleBankTransfer($db, $donor, $callerNumber, $balance, $voice);
            break;
            
        case '2':
            // Cash Payment
            handleCashPayment($db, $donor, $callerNumber, $balance, $voice, $callSid);
            break;
            
        case '3':
            // Back to main menu
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
            break;
            
        default:
            echo '<Say voice="' . $voice . '">Invalid option. Please try again.</Say>';
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    }
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("IVR Payment Method Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="Google.en-GB-Neural2-B">We are sorry, we are experiencing technical difficulties. Please try again later. God bless you.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Option 1: Bank Transfer - Give bank details
 */
function handleBankTransfer($db, ?array $donor, string $callerNumber, float $balance, string $voice): void
{
    // Bank details
    $bankName = 'Barclays Bank';
    $accountName = 'Liverpool Abune Teklehaymanot EOTC';
    $sortCode = '20-61-31';
    $accountNumber = '30926233';
    $reference = $donor ? 'PLEDGE-' . $donor['id'] : 'DONATION';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for choosing bank transfer. Here are the bank details.';
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
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'For the payment reference, please use: ' . $reference . '.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    // Repeat for clarity
    echo '<Say voice="' . $voice . '">';
    echo 'I will repeat the important details.';
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
    
    // Send WhatsApp with bank details
    if ($donor) {
        $whatsappSent = sendWhatsAppBankDetails($db, $donor, $callerNumber, $balance);
        
        if ($whatsappSent) {
            echo '<Say voice="' . $voice . '">';
            echo 'We have also sent the bank details to your WhatsApp for your convenience.';
            echo '</Say>';
            echo '<Pause length="1"/>';
        }
    }
    
    echo '<Say voice="' . $voice . '">';
    echo 'Once we receive your payment, you will receive a confirmation message.';
    echo '</Say>';
    echo '<Pause length="2"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for your generous support. May God bless you abundantly. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Option 2: Cash Payment - Notify admin
 */
function handleCashPayment($db, ?array $donor, string $callerNumber, float $balance, string $voice, string $callSid): void
{
    $adminPhone = '07360436171';
    
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for choosing cash payment.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '">';
    echo 'We have notified our church administrator about your request.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    // Send WhatsApp to admin
    $whatsappSent = sendCashPaymentNotification($db, $donor, $callerNumber, $balance, $adminPhone);
    
    // Update database
    updatePaymentRequest($db, $callSid, 'cash', $balance);
    
    echo '<Say voice="' . $voice . '">';
    if ($whatsappSent) {
        echo 'Someone will contact you shortly to arrange payment collection.';
    } else {
        echo 'Please expect a call from our team within the next few days.';
    }
    echo '</Say>';
    
    echo '<Pause length="2"/>';
    echo '<Say voice="' . $voice . '">';
    echo 'Thank you for your generous support. May God bless you abundantly. Goodbye.';
    echo '</Say>';
    echo '<Hangup/>';
}

/**
 * Send WhatsApp with bank details to donor
 */
function sendWhatsAppBankDetails($db, array $donor, string $callerNumber, float $balance): bool
{
    try {
        require_once __DIR__ . '/../../../services/UltraMsgService.php';
        
        // Get WhatsApp service instance from database
        $service = UltraMsgService::fromDatabase($db);
        if (!$service) {
            error_log("WhatsApp service not configured");
            return false;
        }
        
        $reference = 'PLEDGE-' . $donor['id'];
        
        $message = "🏦 *BANK PAYMENT DETAILS*\n\n";
        $message .= "Hello {$donor['name']},\n\n";
        $message .= "Here are the bank details for your payment:\n\n";
        $message .= "*Bank:* Barclays Bank\n";
        $message .= "*Account Name:* Liverpool Abune Teklehaymanot EOTC\n";
        $message .= "*Sort Code:* 20-61-31\n";
        $message .= "*Account Number:* 30926233\n";
        $message .= "*Reference:* {$reference}\n";
        $message .= "*Amount Due:* £" . number_format($balance, 2) . "\n\n";
        $message .= "Once payment is received, we'll send you a confirmation.\n\n";
        $message .= "God bless you! 🙏";
        
        // Use donor's phone from database or caller number
        $phone = $donor['phone'] ?? $callerNumber;
        
        $result = $service->send($phone, $message);
        
        return ($result['success'] ?? false);
        
    } catch (Exception $e) {
        error_log("WhatsApp bank details error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send WhatsApp notification to admin about cash payment request
 */
function sendCashPaymentNotification($db, ?array $donor, string $callerNumber, float $balance, string $adminPhone): bool
{
    try {
        require_once __DIR__ . '/../../../services/UltraMsgService.php';
        
        // Get WhatsApp service instance from database
        $service = UltraMsgService::fromDatabase($db);
        if (!$service) {
            error_log("WhatsApp service not configured");
            return false;
        }
        
        $donorName = $donor ? $donor['name'] : 'Unknown Caller';
        $donorId = $donor ? $donor['id'] : 'N/A';
        $donorPhone = $donor ? ($donor['phone'] ?? $callerNumber) : $callerNumber;
        
        $message = "📞 *CASH PAYMENT REQUEST*\n\n";
        $message .= "*Donor:* {$donorName}\n";
        $message .= "*Phone:* {$donorPhone}\n";
        $message .= "*Donor ID:* {$donorId}\n";
        $message .= "*Outstanding Balance:* £" . number_format($balance, 2) . "\n\n";
        $message .= "This donor called the IVR system and requested to pay by cash.\n\n";
        $message .= "Please contact them to arrange collection.";
        
        $result = $service->send($adminPhone, $message);
        
        return ($result['success'] ?? false);
        
    } catch (Exception $e) {
        error_log("WhatsApp cash notification error: " . $e->getMessage());
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

function updatePaymentRequest($db, string $callSid, string $method, float $amount): void
{
    try {
        $stmt = $db->prepare("UPDATE twilio_inbound_calls SET payment_amount = ?, payment_status = 'pending' WHERE call_sid = ?");
        $stmt->bind_param('ds', $amount, $callSid);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Update payment request error: " . $e->getMessage());
    }
}

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}
