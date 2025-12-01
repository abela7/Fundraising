<?php
/**
 * Twilio IVR Payment Handler
 * 
 * Processes payment amount entered via keypad
 * Creates a pending pledge_payment record for admin confirmation
 */

declare(strict_types=1);

header('Content-Type: text/xml');

error_log("Twilio IVR Payment: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/UltraMsgService.php';
    
    $db = db();
    
    $callerNumber = $_GET['caller'] ?? '';
    $donorId = (int)($_GET['donor_id'] ?? 0);
    $balance = (float)str_replace(',', '', $_GET['balance'] ?? '0');
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    $amount = (float)$digits;
    
    $baseUrl = getBaseUrl();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    $voice = 'Polly.Brian';
    
    // Validate amount
    if ($amount <= 0) {
        echo '<Say voice="' . $voice . '" language="en-GB">Invalid amount entered. Please try again.</Say>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
        echo '</Response>';
        exit;
    }
    
    if ($amount > $balance && $balance > 0) {
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'The amount you entered, ' . speakMoney($amount) . ', is more than your outstanding balance of ' . speakMoney($balance) . '.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-payment-confirm.php?caller=' . urlencode($callerNumber) . '&amp;donor_id=' . $donorId . '&amp;amount=' . $amount . '" method="POST" timeout="15">';
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'Press 1 to proceed with ' . speakMoney($amount) . '.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'Or press 2 to enter a different amount.';
        echo '</Say>';
        echo '</Gather>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
        echo '</Response>';
        exit;
    }
    
    // Confirm the amount
    echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-payment-confirm.php?caller=' . urlencode($callerNumber) . '&amp;donor_id=' . $donorId . '&amp;amount=' . $amount . '" method="POST" timeout="15">';
    echo '<Say voice="' . $voice . '" language="en-GB">';
    echo 'You entered ' . speakMoney($amount) . '.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    echo '<Say voice="' . $voice . '" language="en-GB">';
    echo 'Press 1 to confirm this payment.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    echo '<Say voice="' . $voice . '" language="en-GB">';
    echo 'Or press 2 to enter a different amount.';
    echo '</Say>';
    echo '</Gather>';
    
    echo '<Say voice="' . $voice . '" language="en-GB">We did not receive any input. Please try again later. Goodbye.</Say>';
    echo '<Hangup/>';
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("IVR Payment Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="alice" language="en-GB">We are experiencing technical difficulties. Please try again later.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

function speakMoney($amount): string
{
    $amount = (float)$amount;
    $pounds = (int)$amount;
    $pence = (int)(($amount - $pounds) * 100);
    
    $result = $pounds . ' pounds';
    if ($pence > 0) {
        $result .= ' and ' . $pence . ' pence';
    }
    return $result;
}

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}

