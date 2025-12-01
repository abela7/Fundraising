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
    
    $voice = 'Polly.Amy-Neural';
    
    // Validate amount
    if ($amount <= 0) {
        echo '<Say voice="' . $voice . '">Invalid amount entered. Please try again.</Say>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
        echo '</Response>';
        exit;
    }
    
    if ($amount > $balance && $balance > 0) {
        echo '<Say voice="' . $voice . '"><speak>';
        echo 'The amount you entered, <say-as interpret-as="currency">GBP' . $amount . '</say-as>, <break time="200ms"/> is more than your outstanding balance of <say-as interpret-as="currency">GBP' . $balance . '</say-as>.';
        echo '</speak></Say>';
        echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-payment-confirm.php?caller=' . urlencode($callerNumber) . '&donor_id=' . $donorId . '&amount=' . $amount . '" method="POST" timeout="10">';
        echo '<Say voice="' . $voice . '"><speak><prosody rate="95%">';
        echo 'Press <say-as interpret-as="number">1</say-as> to proceed with <say-as interpret-as="currency">GBP' . $amount . '</say-as>, <break time="300ms"/> or press <say-as interpret-as="number">2</say-as> to enter a different amount.';
        echo '</prosody></speak></Say>';
        echo '</Gather>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
        echo '</Response>';
        exit;
    }
    
    // Confirm the amount
    echo '<Gather numDigits="1" action="' . $baseUrl . 'twilio-ivr-payment-confirm.php?caller=' . urlencode($callerNumber) . '&donor_id=' . $donorId . '&amount=' . $amount . '" method="POST" timeout="10">';
    echo '<Say voice="' . $voice . '"><speak>';
    echo 'You entered <emphasis level="moderate"><say-as interpret-as="currency">GBP' . $amount . '</say-as></emphasis>. <break time="400ms"/>';
    echo 'Press <say-as interpret-as="number">1</say-as> to confirm this payment, <break time="300ms"/> or press <say-as interpret-as="number">2</say-as> to enter a different amount.';
    echo '</speak></Say>';
    echo '</Gather>';
    
    echo '<Say voice="' . $voice . '">We didn\'t receive any input. Please try again later. Goodbye.</Say>';
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

