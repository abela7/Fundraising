<?php
declare(strict_types=1);

// Send WhatsApp message asking donor for payment proof after they claim to have already paid.
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/MessagingHelper.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server initialization failed: ' . $e->getMessage()
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

try {
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $phone = trim((string)($_POST['phone'] ?? ''));
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $payment_method = strtolower(trim((string)($_POST['payment_method'] ?? '')));
    $evidence = trim((string)($_POST['evidence'] ?? ''));
    
    if ($donor_id <= 0) {
        throw new Exception('Invalid donor id');
    }

    if ($payment_method === '') {
        $payment_method = 'unspecified';
    }

    $db = db();
    $stmt = $db->prepare('SELECT id, name, phone FROM donors WHERE id = ?');
    if (!$stmt) {
        throw new Exception('Unable to prepare donor lookup query');
    }

    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$donor) {
        throw new Exception('Donor not found');
    }

    $donor_name = (string)($donor['name'] ?? '');
    if ($phone === '') {
        $phone = (string)($donor['phone'] ?? '');
    }

    if ($phone === '') {
        throw new Exception('Donor phone number is missing');
    }

    $method_label = str_replace('_', ' ', $payment_method);
    $message = "Hi {$donor_name},\n\n";
    $message .= "Thanks for paying the full amount, in order to confirm your payment please send us any screenshot or reference or payment day.\n\n";
    $message .= "You told us you paid via: {$method_label}.";

    if ($evidence !== '') {
        $message .= "\nPrevious details: {$evidence}";
    }

    $message .= "\n\nSorry for our previous approach. Thank you.";

    $messaging = new MessagingHelper($db);
    $result = $messaging->sendDirect($phone, $message, MessagingHelper::CHANNEL_WHATSAPP, $donor_id, 'call_center', false);

    if (empty($result['success'])) {
        throw new Exception($result['error'] ?? 'Failed to send WhatsApp request');
    }

    if ($session_id > 0) {
        $note = sprintf(
            ' Paid proof request sent via WhatsApp (method: %s) at %s.',
            $payment_method !== '' ? $payment_method : 'unspecified',
            date('Y-m-d H:i:s')
        );

        if ($evidence !== '') {
            $note .= ' Donor evidence note: ' . $evidence . '.';
        }

        $append_stmt = $db->prepare("
            UPDATE call_center_sessions
            SET notes = CONCAT(COALESCE(notes, ''), ?) 
            WHERE id = ?
        ");

        if ($append_stmt) {
            $append_stmt->bind_param('si', $note, $session_id);
            $append_stmt->execute();
            $append_stmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'WhatsApp request sent. Please ask the donor to share screenshot or reference.',
        'channel' => 'whatsapp',
        'provider_response' => $result['message_id'] ?? null
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
