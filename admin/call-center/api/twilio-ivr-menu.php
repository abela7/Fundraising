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
    $voice = 'Polly.Amy';
    
    if (!$donor) {
        echo '<Say voice="' . $voice . '" language="en-GB">We could not find your account with this phone number. Please contact us directly for assistance.</Say>';
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
    
    echo '<Say voice="' . $voice . '" language="en-GB">' . htmlspecialchars($donor['name']) . ', thank you for choosing to make a payment.</Say>';
    echo '<Pause length="1"/>';
    
    if ((float)str_replace(',', '', $balance) > 0) {
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'Your total pledge amount is ' . speakMoney($totalPledged) . '. ';
        echo 'So far, you have paid ' . speakMoney($totalPaid) . '. ';
        echo 'Your outstanding balance is ' . speakMoney($balance) . '.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        
        // Ask for payment amount
        echo '<Gather numDigits="5" action="' . $baseUrl . 'twilio-ivr-payment.php?caller=' . urlencode($callerNumber) . '&amp;donor_id=' . $donor['id'] . '&amp;balance=' . urlencode($balance) . '" method="POST" timeout="15" finishOnKey="#">';
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'Please enter the amount you would like to pay in pounds using your keypad. ';
        echo 'Then press the hash key when you are done. ';
        echo 'For example, to pay fifty pounds, press 5, 0, then hash.';
        echo '</Say>';
        echo '</Gather>';
        
        echo '<Say voice="' . $voice . '" language="en-GB">We did not receive any input. Please try again.</Say>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    } else {
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'Great news! You have no outstanding balance. Your pledge has been fully paid. ';
        echo 'Thank you so much for your generous support. May God bless you abundantly.';
        echo '</Say>';
        echo '<Hangup/>';
    }
}

/**
 * Option 2: Check Balance
 */
function handleBalanceCheck($db, ?array $donor): void
{
    $voice = 'Polly.Amy';
    
    if (!$donor) {
        echo '<Say voice="' . $voice . '" language="en-GB">We could not find your account with this phone number. Please contact us directly for assistance.</Say>';
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
    
    echo '<Say voice="' . $voice . '" language="en-GB">';
    echo 'Hello ' . htmlspecialchars($donor['name']) . '. Here is your account summary.';
    echo '</Say>';
    echo '<Pause length="1"/>';
    
    echo '<Say voice="' . $voice . '" language="en-GB">';
    echo 'Your total pledge amount is ' . speakMoney($totalPledged) . '. ';
    echo 'You have paid ' . speakMoney($totalPaid) . '. ';
    
    if ($balance > 0) {
        echo 'Your outstanding balance is ' . speakMoney(number_format($balance, 2)) . '.';
        echo '</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'To make a payment, you can call us back and press 1, or transfer directly to our bank account. ';
        echo 'Our bank details are: Sort code, 30 96 26. Account number, 87 41 06 20. ';
        echo 'Please use your name as the payment reference.';
        echo '</Say>';
    } else {
        echo '</Say>';
        echo '<Say voice="' . $voice . '" language="en-GB">';
        echo 'Congratulations! You have fully paid your pledge. ';
        echo 'Thank you so much for your generous support.';
        echo '</Say>';
    }
    
    echo '<Pause length="1"/>';
    echo '<Say voice="' . $voice . '" language="en-GB">Thank you for calling. May God bless you. Goodbye.</Say>';
    echo '<Hangup/>';
}

/**
 * Option 3: Contact Church Member
 */
function handleContactChurch(): void
{
    $voice = 'Polly.Amy';
    $churchPhone = '07360436171';
    
    echo '<Say voice="' . $voice . '" language="en-GB">';
    echo 'To speak with a church member, please call ' . speakPhoneNumber($churchPhone) . '. ';
    echo 'I will repeat that number: ' . speakPhoneNumber($churchPhone) . '. ';
    echo 'Thank you for calling. May God bless you. Goodbye.';
    echo '</Say>';
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

