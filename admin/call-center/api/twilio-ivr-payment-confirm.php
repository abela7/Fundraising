<?php
/**
 * Twilio IVR Payment Confirmation Handler
 * 
 * Creates a pending pledge_payment record and sends WhatsApp confirmation
 */

declare(strict_types=1);

header('Content-Type: text/xml');

error_log("Twilio IVR Payment Confirm: " . json_encode($_POST) . " | GET: " . json_encode($_GET));

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/UltraMsgService.php';
    
    $db = db();
    
    $callerNumber = $_GET['caller'] ?? '';
    $donorId = (int)($_GET['donor_id'] ?? 0);
    $amount = (float)($_GET['amount'] ?? 0);
    $digits = $_POST['Digits'] ?? '';
    $callSid = $_POST['CallSid'] ?? '';
    
    $baseUrl = getBaseUrl();
    
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    
    $voice = 'Polly.Brian';
    
    if ($digits === '1') {
        // Confirm payment - create pledge_payment record
        $result = createPendingPayment($db, $donorId, $amount, $callerNumber, $callSid);
        
        if ($result['success']) {
            // Update inbound call record
            updateCallPayment($db, $callSid, $amount);
            
            // Send WhatsApp confirmation
            $donor = getDonor($db, $donorId);
            if ($donor) {
                sendWhatsAppConfirmation($db, $donor, $amount, $result['reference']);
            }
            
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Thank you!';
            echo '</Say>';
            echo '<Pause length="1"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Your payment of ' . speakMoney($amount) . ' has been registered.';
            echo '</Say>';
            echo '<Pause length="2"/>';
            
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Please transfer this amount to our bank account.';
            echo '</Say>';
            echo '<Pause length="1"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Sort code: 30, 96, 26.';
            echo '</Say>';
            echo '<Pause length="1"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Account number: 87, 41, 06, 20.';
            echo '</Say>';
            echo '<Pause length="1"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Please use your name as the payment reference.';
            echo '</Say>';
            echo '<Pause length="2"/>';
            
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'We have also sent these details to your WhatsApp.';
            echo '</Say>';
            echo '<Pause length="1"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Once we confirm your payment, you will receive a WhatsApp notification.';
            echo '</Say>';
            echo '<Pause length="2"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Thank you for your generous support. May God bless you abundantly. Goodbye!';
            echo '</Say>';
        } else {
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'We encountered an error processing your request.';
            echo '</Say>';
            echo '<Pause length="1"/>';
            echo '<Say voice="' . $voice . '" language="en-GB">';
            echo 'Please try again later, or contact us directly.';
            echo '</Say>';
        }
        
        echo '<Hangup/>';
        
    } elseif ($digits === '2') {
        // Re-enter amount
        echo '<Redirect>' . $baseUrl . 'twilio-ivr-menu.php?caller=' . urlencode($callerNumber) . '&amp;Digits=1</Redirect>';
        
    } else {
        echo '<Say voice="' . $voice . '" language="en-GB">Invalid option. Please try again.</Say>';
        echo '<Redirect>' . $baseUrl . 'twilio-inbound-call.php</Redirect>';
    }
    
    echo '</Response>';
    
} catch (Exception $e) {
    error_log("IVR Payment Confirm Error: " . $e->getMessage());
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Response>';
    echo '<Say voice="alice" language="en-GB">We are experiencing technical difficulties. Please try again later.</Say>';
    echo '<Hangup/>';
    echo '</Response>';
}

/**
 * Create a pending pledge_payment record
 */
function createPendingPayment($db, int $donorId, float $amount, string $phone, string $callSid): array
{
    try {
        // Get donor's active pledge
        $stmt = $db->prepare("SELECT id FROM pledges WHERE donor_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $pledge = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $pledgeId = $pledge ? (int)$pledge['id'] : null;
        
        // Generate reference number
        $reference = 'IVR-' . date('ymd') . '-' . str_pad((string)$donorId, 4, '0', STR_PAD_LEFT);
        
        // Check if pledge_payments table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'pledge_payments'");
        if ($tableCheck->num_rows === 0) {
            return ['success' => false, 'error' => 'Payment table not found'];
        }
        
        // Insert pending payment
        $stmt = $db->prepare("
            INSERT INTO pledge_payments 
            (pledge_id, donor_id, amount, payment_method, payment_date, reference_number, status, notes, created_at)
            VALUES (?, ?, ?, 'bank_transfer', CURDATE(), ?, 'pending', ?, NOW())
        ");
        
        $notes = "IVR Phone Payment - Call SID: " . $callSid;
        $stmt->bind_param('iidss', $pledgeId, $donorId, $amount, $reference, $notes);
        $result = $stmt->execute();
        $paymentId = $db->insert_id;
        $stmt->close();
        
        if ($result) {
            return ['success' => true, 'payment_id' => $paymentId, 'reference' => $reference];
        }
        
        return ['success' => false, 'error' => 'Insert failed'];
        
    } catch (Exception $e) {
        error_log("Create payment error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get donor info
 */
function getDonor($db, int $donorId): ?array
{
    $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $donor;
}

/**
 * Send WhatsApp confirmation with bank details
 */
function sendWhatsAppConfirmation($db, array $donor, float $amount, string $reference): bool
{
    try {
        $whatsapp = UltraMsgService::fromDatabase($db);
        if (!$whatsapp) {
            error_log("WhatsApp not configured");
            return false;
        }
        
        $donorName = $donor['name'] ?? 'Valued Donor';
        $formattedAmount = number_format($amount, 2);
        $balance = number_format((float)($donor['balance'] ?? 0) - $amount, 2);
        
        $message = "Hello {$donorName},\n\n";
        $message .= "Thank you for registering a payment of *£{$formattedAmount}* via our phone system.\n\n";
        $message .= "*Bank Details:*\n";
        $message .= "Account Name: Liverpool EOTC\n";
        $message .= "Sort Code: 30-96-26\n";
        $message .= "Account Number: 87410620\n";
        $message .= "Reference: *{$reference}* or your name\n\n";
        $message .= "Please transfer *£{$formattedAmount}* using the above details.\n\n";
        $message .= "Once we confirm receipt, we will send you a confirmation message.\n\n";
        if ((float)$balance > 0) {
            $message .= "Your remaining balance after this payment will be: £{$balance}\n\n";
        }
        $message .= "God bless you! 🙏\n";
        $message .= "_Liverpool Abune Teklehaymanot EOTC_";
        
        $result = $whatsapp->send($donor['phone'], $message, [
            'donor_id' => $donor['id'],
            'source_type' => 'ivr_payment',
            'log' => true
        ]);
        
        return $result['success'] ?? false;
        
    } catch (Exception $e) {
        error_log("WhatsApp send error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update inbound call record with payment info
 */
function updateCallPayment($db, string $callSid, float $amount): void
{
    try {
        $stmt = $db->prepare("UPDATE twilio_inbound_calls SET payment_amount = ?, payment_status = 'pending' WHERE call_sid = ?");
        $stmt->bind_param('ds', $amount, $callSid);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Update payment error: " . $e->getMessage());
    }
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

function speakReference(string $ref): string
{
    // Speak each character/digit separately
    $parts = [];
    foreach (str_split($ref) as $char) {
        if ($char === '-') {
            $parts[] = 'dash';
        } else {
            $parts[] = $char;
        }
    }
    return implode(' ', $parts);
}

function getBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/admin/call-center/api/';
}

