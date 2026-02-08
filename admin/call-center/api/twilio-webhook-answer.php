<?php
/**
 * Twilio Webhook: Answer
 * 
 * This webhook is called when the AGENT answers the phone.
 * It returns TwiML instructions to:
 * 1. Say "Connecting to [Donor Name]..."
 * 2. Dial the donor's number
 * 3. Connect both parties
 */

declare(strict_types=1);

header('Content-Type: text/xml');

// Get parameters from Twilio
$donorPhone = $_GET['donor_phone'] ?? '';
$donorName = $_GET['donor_name'] ?? 'the donor';
$sessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

// Normalize donor phone to E.164 format
if (!str_starts_with($donorPhone, '+')) {
    // UK mobile (07xxx) -> +447xxx
    if (preg_match('/^07[0-9]{9}$/', $donorPhone)) {
        $donorPhone = '+44' . substr($donorPhone, 1);
    }
    // UK landline (0151xxx) -> +44151xxx
    elseif (preg_match('/^0[0-9]{10}$/', $donorPhone)) {
        $donorPhone = '+44' . substr($donorPhone, 1);
    }
    // Already starts with 44 but no +
    elseif (preg_match('/^44[0-9]{10,11}$/', $donorPhone)) {
        $donorPhone = '+' . $donorPhone;
    }
}

// Extract first name from full name
$firstName = explode(' ', $donorName)[0];

// Build status callback URL
$baseUrl = 'https://donate.abuneteklehaymanot.org';
$statusCallbackUrl = $baseUrl . '/admin/call-center/api/twilio-status-callback.php?session_id=' . $sessionId;

// Generate TwiML response
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
    <!-- Greet the agent -->
    <Say voice="alice" language="en-GB">
        Connecting you to <?php echo htmlspecialchars($firstName); ?>. Please wait.
    </Say>
    
    <!-- Pause for 1 second -->
    <Pause length="1"/>
    
    <!-- Dial the donor -->
    <Dial
        callerId="<?php echo htmlspecialchars($donorPhone); ?>"
        timeout="30"
        record="record-from-answer"
        recordingStatusCallback="<?php echo htmlspecialchars($baseUrl); ?>/admin/call-center/api/twilio-recording-callback.php?session_id=<?php echo $sessionId; ?>"
        recordingStatusCallbackMethod="POST">
        <Number statusCallback="<?php echo htmlspecialchars($statusCallbackUrl); ?>" statusCallbackMethod="POST" statusCallbackEvent="initiated ringing answered completed">
            <?php echo htmlspecialchars($donorPhone); ?>
        </Number>
    </Dial>
    
    <!-- If donor doesn't answer or call fails -->
    <Say voice="alice" language="en-GB">
        The call could not be connected. Goodbye.
    </Say>
</Response>

