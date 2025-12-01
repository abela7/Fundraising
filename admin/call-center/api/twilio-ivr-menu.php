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
    if (!$donor) {
        echo '<Say voice="alice" language="en-GB">We could not find your account with this phone number.</Say>';
        echo '<Say voice="alice" language="en-GB">Please contact us directly for assistance.</Say>';
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
    
    echo '<Say voice="alice" language="en-GB">' . htmlspecialchars($donor['name']) . ', thank you for choosing to make a payment.</Say>';
    echo '<Pause length="1"/>';
    
    if ((float)str_replace(',', '', $balance) > 0) {
        echo '<Say voice="alice" language="en-GB">Your total pledge is ' . speakMoney($totalPledged) . '.</Say>';
        echo '<Say voice="alice" language="en-GB">You have paid ' . speakMoney($totalPaid) . ' so far.</Say>';
        echo '<Say voice="alice" language="en-GB">Your outstanding balance is ' . speakMoney($balance) . '.</Say>';
        echo '<Pause length="1"/>';
        
        // Ask for payment amount
        echo '<Gather numDigits="5" action="' . $baseUrl . 'twilio-ivr-payment.php?caller=' . urlencode($callerNumber) . '&donor_id=' . $donor['id'] . '&balance=' . urlencode($balance) . '" method="POST" timeout="15" finishOnKey="#">';
        echo '<Say voice="alice" language="en-GB">Please enter the amount you wish to pay in pounds using your keypad, followed by the hash key.</Say>';
        echo '<Say voice="alice" language="en-GB">For example, to pay 50 pounds, press 5 0 then hash.</Say>';
        echo '</Gather>';
        
        echo '<Say voice="alice" language="en-GB">We did not receive any input.</Say>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    } else {
        echo '<Say voice="alice" language="en-GB">Great news! You have no outstanding balance. Your pledge has been fully paid.</Say>';
        echo '<Say voice="alice" language="en-GB">Thank you for your generous support. God bless you.</Say>';
        echo '<Hangup/>';
    }
}

/**
 * Option 2: Check Balance
 */
function handleBalanceCheck($db, ?array $donor): void
{
    if (!$donor) {
        echo '<Say voice="alice" language="en-GB">We could not find your account with this phone number.</Say>';
        echo '<Say voice="alice" language="en-GB">Please contact us directly for assistance.</Say>';
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
    
    echo '<Say voice="alice" language="en-GB">Hello ' . htmlspecialchars($donor['name']) . '. Here is your account summary.</Say>';
    echo '<Pause length="1"/>';
    echo '<Say voice="alice" language="en-GB">Your total pledge amount is ' . speakMoney($totalPledged) . '.</Say>';
    echo '<Say voice="alice" language="en-GB">You have paid ' . speakMoney($totalPaid) . '.</Say>';
    
    if ($balance > 0) {
        echo '<Say voice="alice" language="en-GB">Your outstanding balance is ' . speakMoney(number_format($balance, 2)) . '.</Say>';
        echo '<Pause length="1"/>';
        echo '<Say voice="alice" language="en-GB">To make a payment, you can call us back and press 1, or transfer to our bank account.</Say>';
        echo '<Say voice="alice" language="en-GB">Our bank details are: Sort code 3 0 9 6 2 6. Account number 8 7 4 1 0 6 2 0.</Say>';
        echo '<Say voice="alice" language="en-GB">Please use your name as the payment reference.</Say>';
    } else {
        echo '<Say voice="alice" language="en-GB">Congratulations! You have fully paid your pledge. Thank you for your generous support.</Say>';
    }
    
    echo '<Pause length="1"/>';
    echo '<Say voice="alice" language="en-GB">Thank you for calling. God bless you. Goodbye.</Say>';
    echo '<Hangup/>';
}

/**
 * Option 3: Contact Church Member
 */
function handleContactChurch(): void
{
    $churchPhone = '07360436171';
    
    echo '<Say voice="alice" language="en-GB">To speak with a church member, please call ' . speakPhoneNumber($churchPhone) . '.</Say>';
    echo '<Pause length="1"/>';
    echo '<Say voice="alice" language="en-GB">That number again is ' . speakPhoneNumber($churchPhone) . '.</Say>';
    echo '<Say voice="alice" language="en-GB">Thank you for calling. God bless you. Goodbye.</Say>';
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

