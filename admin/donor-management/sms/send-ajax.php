<?php
/**
 * Send SMS via AJAX
 * 
 * Handles SMS sending from donor profile page
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../services/MessagingHelper.php';
    
    require_login();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $donor_name = isset($_POST['donor_name']) ? trim($_POST['donor_name']) : '';
    
    if (empty($phone)) {
        throw new Exception('Phone number is required');
    }
    
    if (empty($message)) {
        throw new Exception('Message is required');
    }
    
    if (strlen($message) > 1600) {
        throw new Exception('Message too long (max 1600 characters)');
    }
    
    $db = db();
    $current_user = current_user();
    
    // Initialize messaging helper
    $msg = new MessagingHelper($db);
    
    // Send the SMS
    if ($donor_id > 0) {
        // Send to donor (will log with donor_id)
        $result = $msg->sendToDonor($donor_id, $message, 'sms', $current_user['id'] ?? null, 'donor_profile');
    } else {
        // Send direct (without donor association)
        $result = $msg->sendDirect($phone, $message, 'sms', $current_user['id'] ?? null, 'donor_profile');
    }
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'SMS sent successfully',
            'channel' => $result['channel'] ?? 'sms',
            'message_id' => $result['message_id'] ?? null
        ]);
    } else {
        throw new Exception($result['error'] ?? 'Failed to send SMS');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

