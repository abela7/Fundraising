<?php
/**
 * API: Delete WhatsApp Conversation from local database only
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

// Get conversation ID
$conversationId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

if ($conversationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID']);
    exit;
}

try {
    // Delete all messages first
    $deleteMsg = $db->prepare("DELETE FROM whatsapp_messages WHERE conversation_id = ?");
    if ($deleteMsg) {
        $deleteMsg->bind_param('i', $conversationId);
        $deleteMsg->execute();
        $messagesDeleted = $deleteMsg->affected_rows;
        $deleteMsg->close();
    }
    
    // Delete the conversation
    $deleteConv = $db->prepare("DELETE FROM whatsapp_conversations WHERE id = ?");
    if (!$deleteConv) {
        throw new Exception('Prepare failed: ' . $db->error);
    }
    
    $deleteConv->bind_param('i', $conversationId);
    $deleteConv->execute();
    
    if ($deleteConv->affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Conversation deleted from local database',
            'messages_deleted' => $messagesDeleted ?? 0
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Conversation not found or already deleted'
        ]);
    }
    
    $deleteConv->close();
    
} catch (Throwable $e) {
    error_log("Delete conversation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
