<?php
/**
 * API: Bulk Delete Conversations from local database
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

// Get conversation IDs
$idsJson = $_POST['conversation_ids'] ?? '[]';
$ids = json_decode($idsJson, true);

if (!is_array($ids) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No conversations selected']);
    exit;
}

try {
    // Sanitize IDs
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, fn($id) => $id > 0);
    
    if (empty($ids)) {
        throw new Exception('No valid conversation IDs');
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    
    // Delete messages first
    $deleteMsg = $db->prepare("DELETE FROM whatsapp_messages WHERE conversation_id IN ($placeholders)");
    $deleteMsg->bind_param($types, ...$ids);
    $deleteMsg->execute();
    $messagesDeleted = $deleteMsg->affected_rows;
    $deleteMsg->close();
    
    // Delete conversations
    $deleteConv = $db->prepare("DELETE FROM whatsapp_conversations WHERE id IN ($placeholders)");
    $deleteConv->bind_param($types, ...$ids);
    $deleteConv->execute();
    $convsDeleted = $deleteConv->affected_rows;
    $deleteConv->close();
    
    echo json_encode([
        'success' => true,
        'message' => "Deleted $convsDeleted conversation(s)",
        'conversations_deleted' => $convsDeleted,
        'messages_deleted' => $messagesDeleted
    ]);
    
} catch (Throwable $e) {
    error_log("Bulk delete error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

