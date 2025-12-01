<?php
/**
 * Twilio IVR Menu Handler
 * 
 * Processes menu selections:
 * 1 - Make payment
 * 2 - Check balance
 * 3 - Contact church member
 */

declare(strict_types=1);

header('Content-Type: text/xml');

error_log("Twilio IVR Menu: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $db = db();
    
    $callerNumber = $_GET['caller'] ?? $_POST['From'] ?? '';
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    // Normalize and lookup donor
    $normalizedPhone = normalizePhone($callerNumber);
    $donor = lookupDonor($db, $normalizedPhone, $callerNumber);
    
    // Update call record with menu selection
    updateCallSelection($db, $callSid, $digits);
    
    $baseUrl = getBaseUrl();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    switch ($digits) {
        case '1':
            // Make a Payment
            handlePaymentOption($db, $donor, $callerNumber, $baseUrl);
            break;
            
        case '2':
            // Check Balance
            handleBalanceCheck($db, $donor);
            break;
            
        case '3':
            // Contact Church Member
            handleContactChurch();
            break;
            
        default:
            // Invalid option
            echo '<Say voice="alice" language="en-GB">Invalid option. Please try again.</Say>';
            echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    }
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("IVR Menu Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="alice" language="en-GB">We are experiencing technical difficulties. Please try again later.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Option 1: Make a Payment
 */
function handlePaymentOption($db, ?array $donor, string $callerNumber, string $baseUrl): void
{
    $voice = 'Polly.Amy-Neural';
    
    if (!$donor) {
        echo '<Say voice="' . $voice . '"><speak>We couldn\'t find your account with this phone number. <break time="300ms"/> Please contact us directly for assistance.</speak></Say>';
        handleContactChurch();
        return;
    }
    
    $totalPledged = number_format((float)($donor['total_pledged'] ?? 0), 2);
    $totalPaid = number_format((float)($donor['total_paid'] ?? 0), 2);
    $balance = number_format((float)($donor['balance'] ?? 0), 2);
    
    // Recalculate balance if stored value is 0
    if ((float)($donor['balance'] ?? 0) <= 0 && (float)($donor['total_pledged'] ?? 0) > 0) {
        $balance = number_format((float)$donor['total_pledged'] - (float)$donor['total_paid'], 2);
    }
    
    echo '<Say voice="' . $voice . '"><speak>' . htmlspecialchars($donor['name']) . ', <break time="200ms"/> thank you for choosing to make a payment.</speak></Say>';
    echo '<Pause length="1"/>';
    
    if ((float)str_replace(',', '', $balance) > 0) {
        echo '<Say voice="' . $voice . '"><speak><prosody rate="95%">';
        echo 'Your total pledge amount is <say-as interpret-as="currency">GBP' . str_replace(',', '', $totalPledged) . '</say-as>. <break time="400ms"/>';
        echo 'So far, you have paid <say-as interpret-as="currency">GBP' . str_replace(',', '', $totalPaid) . '</say-as>. <break time="400ms"/>';
        echo 'Your outstanding balance is <emphasis level="moderate"><say-as interpret-as="currency">GBP' . str_replace(',', '', $balance) . '</say-as></emphasis>.';
        echo '</prosody></speak></Say>';
        echo '<Pause length="1"/>';
        
        // Ask for payment amount
        echo '<Gather numDigits="5" action="' . $baseUrl . 'twilio-ivr-payment.php?caller=' . urlencode($callerNumber) . '&donor_id=' . $donor['id'] . '&balance=' . urlencode($balance) . '" method="POST" timeout="15" finishOnKey="#">';
        echo '<Say voice="' . $voice . '"><speak><prosody rate="90%">';
        echo 'Please enter the amount you would like to pay, <break time="200ms"/> in pounds, <break time="200ms"/> using your keypad. <break time="300ms"/>';
        echo 'Then press the hash key when you\'re done. <break time="500ms"/>';
        echo 'For example, <break time="200ms"/> to pay fifty pounds, <break time="200ms"/> press five, zero, <break time="200ms"/> then hash.';
        echo '</prosody></speak></Say>';
        echo '</Gather>';
        
        echo '<Say voice="' . $voice . '">We didn\'t receive any input. Please try again.</Say>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    } else {
        echo '<Say voice="' . $voice . '"><speak>';
        echo 'Great news! <break time="300ms"/> You have no outstanding balance. <break time="300ms"/> Your pledge has been fully paid. <break time="500ms"/>';
        echo 'Thank you so much for your generous support. <break time="300ms"/> May God bless you abundantly.';
        echo '</speak></Say>';
        echo '<Hangup/>';
    }
}

/**
 * Option 2: Check Balance
 */
function handleBalanceCheck($db, ?array $donor): void
{
    $voice = 'Polly.Amy-Neural';
    
    if (!$donor) {
        echo '<Say voice="' . $voice . '"><speak>We couldn\'t find your account with this phone number. <break time="300ms"/> Please contact us directly for assistance.</speak></Say>';
        handleContactChurch();
        return;
    }
    
    $totalPledged = number_format((float)($donor['total_pledged'] ?? 0), 2);
    $totalPaid = number_format((float)($donor['total_paid'] ?? 0), 2);
    $balance = (float)($donor['balance'] ?? 0);
    
    // Recalculate if needed
    if ($balance <= 0 && (float)($donor['total_pledged'] ?? 0) > 0) {
        $balance = (float)$donor['total_pledged'] - (float)$donor['total_paid'];
    }
    
    echo '<Say voice="' . $voice . '"><speak>';
    echo 'Hello ' . htmlspecialchars($donor['name']) . '. <break time="300ms"/> Here is your account summary.';
    echo '</speak></Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '"><speak><prosody rate="95%">';
    echo 'Your total pledge amount is <say-as interpret-as="currency">GBP' . str_replace(',', '', $totalPledged) . '</say-as>. <break time="400ms"/>';
    echo 'You have paid <say-as interpret-as="currency">GBP' . str_replace(',', '', $totalPaid) . '</say-as>. <break time="400ms"/>';
    
    if ($balance > 0) {
        echo 'Your outstanding balance is <emphasis level="moderate"><say-as interpret-as="currency">GBP' . number_format($balance, 2) . '</say-as></emphasis>. <break time="600ms"/>';
        echo '</prosody></speak></Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '"><speak><prosody rate="90%">';
        echo 'To make a payment, <break time="200ms"/> you can call us back and press one, <break time="300ms"/> or transfer directly to our bank account. <break time="500ms"/>';
        echo 'Our bank details are: <break time="300ms"/> Sort code, <say-as interpret-as="digits">309626</say-as>. <break time="400ms"/>';
        echo 'Account number, <say-as interpret-as="digits">87410620</say-as>. <break time="400ms"/>';
        echo 'Please use your name as the payment reference.';
        echo '</prosody></speak></Say>';
    } else {
        echo '</prosody></speak></Say>';
        echo '<Say voice="' . $voice . '"><speak>';
        echo '<emphasis level="strong">Congratulations!</emphasis> <break time="300ms"/> You have fully paid your pledge. <break time="300ms"/>';
        echo 'Thank you so much for your generous support.';
        echo '</speak></Say>';
    }
    
    echo '<Pause length="1"/>';
    echo '<Say voice="' . $voice . '"><speak>Thank you for calling. <break time="200ms"/> May God bless you. <break time="200ms"/> Goodbye.</speak></Say>';
    echo '<Hangup/>';
}

/**
 * Option 3: Contact Church Member
 */
function handleContactChurch(): void
{
    $voice = 'Polly.Amy-Neural';
    $churchPhone = '07360436171';
    
    echo '<Say voice="' . $voice . '"><speak><prosody rate="90%">';
    echo 'To speak with a church member, <break time="300ms"/> please call <say-as interpret-as="telephone">' . $churchPhone . '</say-as>. <break time="600ms"/>';
    echo 'I\'ll repeat that number: <break time="300ms"/> <say-as interpret-as="telephone">' . $churchPhone . '</say-as>. <break time="500ms"/>';
    echo 'Thank you for calling. <break time="200ms"/> May God bless you. <break time="200ms"/> Goodbye.';
    echo '</prosody></speak></Say>';
    echo '<Hangup/>';
}

/**
 * Speak money amount clearly
 */
function speakMoney(string $amount): string
{
    $clean = str_replace(',', '', $amount);
    $parts = explode('.', $clean);
    $pounds = (int)$parts[0];
    $pence = isset($parts[1]) ? (int)$parts[1] : 0;
    
    $result = $pounds . ' pounds';
    if ($pence > 0) {
        $result .= ' and ' . $pence . ' pence';
    }
    return $result;
}

/**
 * Speak phone number digit by digit
 */
function speakPhoneNumber(string $phone): string
{
    return implode(' ', str_split(preg_replace('/[^0-9]/', '', $phone)));
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

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}

