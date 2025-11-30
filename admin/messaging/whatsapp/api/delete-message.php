<?php
/**
 * API: Delete WhatsApp Message from local database only
 * Does NOT delete from actual WhatsApp
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../../shared/auth.php';
    require_once __DIR__ . '/../../../../shared/csrf.php';
    require_once __DIR__ . '/../../../../config/db.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Auth failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    verify_csrf();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$db = db();

// Get message ID
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid message ID: ' . $messageId]);
    exit;
}

try {
    // Simply delete the message from local database
    $stmt = $db->prepare("DELETE FROM whatsapp_messages WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $db->error);
    }
    
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Message deleted from local database'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Message not found or already deleted'
        ]);
    }
    
    $stmt->close();
    
} catch (Throwable $e) {
    error_log("Delete message error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
