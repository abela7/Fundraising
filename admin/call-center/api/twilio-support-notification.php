<?php
/**
 * Twilio TwiML - Support Request Notification
 * 
 * This endpoint returns TwiML to speak a notification message
 * Used when WhatsApp notification fails
 */

header('Content-Type: text/xml');

$voice = 'Google.en-GB-Neural2-B';
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$donor_name = htmlspecialchars($_GET['donor_name'] ?? 'a donor');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
    <Say voice="<?php echo $voice; ?>">
        Hi Abel. This is an automated notification from the Church Fundraising System.
    </Say>
    <Pause length="1"/>
    <Say voice="<?php echo $voice; ?>">
        A donor named <?php echo $donor_name; ?> has submitted a support request.
    </Say>
    <Pause length="1"/>
    <Say voice="<?php echo $voice; ?>">
        Please go to the donor portal dashboard to check and respond to this enquiry.
    </Say>
    <Pause length="1"/>
    <Say voice="<?php echo $voice; ?>">
        The request ID is <?php echo $request_id; ?>.
    </Say>
    <Pause length="1"/>
    <Say voice="<?php echo $voice; ?>">
        Thank you. Goodbye.
    </Say>
</Response>

