<?php
declare(strict_types=1);

// Send bank transfer details to donor via WhatsApp or SMS
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../services/MessagingHelper.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server init error: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $phone = trim((string)($_POST['phone'] ?? ''));
    $channel = strtolower(trim((string)($_POST['channel'] ?? 'whatsapp')));
    $reference = trim((string)($_POST['reference_number'] ?? ''));
    $planId = trim((string)($_POST['plan_id'] ?? ''));
    $planDuration = (int)($_POST['plan_duration'] ?? 0);
    $paymentDay = trim((string)($_POST['payment_day'] ?? ''));
    $isOneTimePayment = ($planId === 'def_0' || $planDuration === 1);

    if (!in_array($channel, ['whatsapp', 'sms'], true)) {
        $channel = 'whatsapp';
    }
    
    if ($donor_id <= 0) {
        throw new Exception('Invalid donor id');
    }
    
    $db = db();
    $donor_name = '';
    $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Database error: unable to prepare donor lookup');
    }
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$donor) {
        throw new Exception('Donor not found');
    }
    
    $donor_name = (string)($donor['name'] ?? '');
    if (empty($phone) && !empty($donor['phone'])) {
        $phone = (string)$donor['phone'];
    }
    
    if (empty($phone)) {
        throw new Exception('Donor phone number is missing');
    }
    
    if ($reference === '') {
        $referenceStmt = $db->prepare("
            SELECT notes
            FROM pledges
            WHERE donor_id = ? AND status = 'approved'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        if ($referenceStmt) {
            $referenceStmt->bind_param('i', $donor_id);
            $referenceStmt->execute();
            $referenceRow = $referenceStmt->get_result()->fetch_assoc();
            $referenceStmt->close();
            if (!empty($referenceRow['notes'])) {
                $reference = preg_replace('/\\D+/', '', (string)$referenceRow['notes']);
            }
        }
    }
    
    if ($reference === '') {
        $reference = 'N/A';
    }
    
    $bankDetails = "Account Name: LMKATH\nAccount Number: 85455687\nSort Code: 53-70-44";
    $messaging = new MessagingHelper($db);
    $message = '';
    $result = null;

    if ($isOneTimePayment) {
        $templateKey = 'one_time_payment_confirmation';
        $templateVariables = [
            'donor_name' => $donor_name,
            'payment_day' => $paymentDay !== '' ? $paymentDay : 'your scheduled payment date',
            'bank_details' => $bankDetails,
            'reference_number' => $reference
        ];

        $result = $messaging->sendFromTemplate(
            $templateKey,
            $donor_id,
            $templateVariables,
            $channel,
            'call_center',
            false,
            true
        );

        // Fallback to direct message if template is missing or sends fail.
        if (!($result['success'] ?? false) && isset($result['error']) && stripos((string)$result['error'], 'not found') !== false) {
            $message = "Hi {$donor_name},\n\n";
            $message .= "Thanks for taking your time to speak to us.\n";
            $message .= "We also would like to thank you for confirming to pay in full at this " . ($paymentDay !== '' ? $paymentDay : 'scheduled date') . ".\n\n";
            $message .= "{$bankDetails}\n";
            $message .= "Reference number: {$reference}";
            $result = $messaging->sendDirect($phone, $message, $channel, $donor_id, 'call_center', false);
        }
    } else {
        $message = "Hi {$donor_name},\n\n";
        $message .= "Account Name: LMKATH\n";
        $message .= "Account Number: 85455687\n";
        $message .= "Sort Code: 53-70-44\n";
        $message .= "Reference number: {$reference}";
        $result = $messaging->sendDirect($phone, $message, $channel, $donor_id, 'call_center', false);
    }
    
    if (!($result['success'] ?? false)) {
        throw new Exception($result['error'] ?? 'Failed to send message');
    }
    
    echo json_encode([
        'success' => true,
        'channel' => $result['channel'] ?? $channel,
        'message' => 'Bank details sent'
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
