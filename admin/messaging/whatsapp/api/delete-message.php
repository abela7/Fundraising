<?php
/**
 * API: Delete WhatsApp Message
 * 
 * Deletes a single message and its associated media
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
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
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
$current_user = current_user();

// Get message ID
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid message ID']);
    exit;
}

try {
    // Get message details first
    $stmt = $db->prepare("
        SELECT id, conversation_id, media_local_path, message_type 
        FROM whatsapp_messages WHERE id = ?
    ");
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $message = $result->fetch_assoc();
    $stmt->close();
    
    if (!$message) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Message not found']);
        exit;
    }
    
    $conversationId = $message['conversation_id'];
    $filesToDelete = [];
    
    // Collect media file path
    if (!empty($message['media_local_path'])) {
        $filesToDelete[] = $message['media_local_path'];
    }
    
    // Check whatsapp_media table
    $checkMedia = $db->query("SHOW TABLES LIKE 'whatsapp_media'");
    if ($checkMedia && $checkMedia->num_rows > 0) {
        $mediaStmt = $db->prepare("
            SELECT local_path FROM whatsapp_media WHERE message_id = ?
        ");
        $mediaStmt->bind_param('i', $messageId);
        $mediaStmt->execute();
        $mediaResult = $mediaStmt->get_result();
        
        while ($row = $mediaResult->fetch_assoc()) {
            if (!empty($row['local_path'])) {
                $filesToDelete[] = $row['local_path'];
            }
        }
        $mediaStmt->close();
        
        // Delete from whatsapp_media
        $deleteMedia = $db->prepare("DELETE FROM whatsapp_media WHERE message_id = ?");
        $deleteMedia->bind_param('i', $messageId);
        $deleteMedia->execute();
        $deleteMedia->close();
    }
    
    // Delete the message
    $deleteMsg = $db->prepare("DELETE FROM whatsapp_messages WHERE id = ?");
    $deleteMsg->bind_param('i', $messageId);
    $deleteMsg->execute();
    $deleted = $deleteMsg->affected_rows > 0;
    $deleteMsg->close();
    
    if (!$deleted) {
        throw new Exception('Failed to delete message from database');
    }
    
    // Update conversation last message
    $updateConv = $db->prepare("
        UPDATE whatsapp_conversations 
        SET last_message = (
            SELECT body FROM whatsapp_messages 
            WHERE conversation_id = ? 
            ORDER BY created_at DESC LIMIT 1
        ),
        last_message_at = (
            SELECT created_at FROM whatsapp_messages 
            WHERE conversation_id = ? 
            ORDER BY created_at DESC LIMIT 1
        )
        WHERE id = ?
    ");
    $updateConv->bind_param('iii', $conversationId, $conversationId, $conversationId);
    $updateConv->execute();
    $updateConv->close();
    
    // Delete media files from disk
    $filesDeleted = 0;
    foreach ($filesToDelete as $filePath) {
        $fullPath = __DIR__ . '/../../../../' . ltrim($filePath, '/');
        if (file_exists($fullPath)) {
            if (unlink($fullPath)) {
                $filesDeleted++;
            }
        }
    }
    
    // Log the deletion
    error_log("WhatsApp message $messageId deleted by user {$current_user['id']}");
    
    echo json_encode([
        'success' => true,
        'message' => 'Message deleted successfully',
        'files_deleted' => $filesDeleted
    ]);
    
} catch (Throwable $e) {
    error_log("Delete message error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete message: ' . $e->getMessage()]);
}

