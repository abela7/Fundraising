<?php
/**
 * API: Delete WhatsApp Conversation
 * 
 * Deletes entire conversation including all messages and media
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

// Get conversation ID
$conversationId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

if ($conversationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID']);
    exit;
}

try {
    $db->begin_transaction();
    
    // Get all media files for this conversation to delete from disk
    $mediaStmt = $db->prepare("
        SELECT media_local_path FROM whatsapp_messages 
        WHERE conversation_id = ? AND media_local_path IS NOT NULL
    ");
    $mediaStmt->bind_param('i', $conversationId);
    $mediaStmt->execute();
    $mediaResult = $mediaStmt->get_result();
    
    $filesToDelete = [];
    while ($row = $mediaResult->fetch_assoc()) {
        if (!empty($row['media_local_path'])) {
            $filesToDelete[] = $row['media_local_path'];
        }
    }
    $mediaStmt->close();
    
    // Also check whatsapp_media table if it exists
    $checkMedia = $db->query("SHOW TABLES LIKE 'whatsapp_media'");
    if ($checkMedia && $checkMedia->num_rows > 0) {
        $mediaStmt2 = $db->prepare("
            SELECT local_path FROM whatsapp_media 
            WHERE conversation_id = ? AND local_path IS NOT NULL
        ");
        $mediaStmt2->bind_param('i', $conversationId);
        $mediaStmt2->execute();
        $mediaResult2 = $mediaStmt2->get_result();
        
        while ($row = $mediaResult2->fetch_assoc()) {
            if (!empty($row['local_path'])) {
                $filesToDelete[] = $row['local_path'];
            }
        }
        $mediaStmt2->close();
        
        // Delete from whatsapp_media
        $deleteMedia = $db->prepare("DELETE FROM whatsapp_media WHERE conversation_id = ?");
        $deleteMedia->bind_param('i', $conversationId);
        $deleteMedia->execute();
        $deleteMedia->close();
    }
    
    // Delete all messages
    $deleteMessages = $db->prepare("DELETE FROM whatsapp_messages WHERE conversation_id = ?");
    $deleteMessages->bind_param('i', $conversationId);
    $deleteMessages->execute();
    $messagesDeleted = $deleteMessages->affected_rows;
    $deleteMessages->close();
    
    // Delete the conversation
    $deleteConv = $db->prepare("DELETE FROM whatsapp_conversations WHERE id = ?");
    $deleteConv->bind_param('i', $conversationId);
    $deleteConv->execute();
    $convDeleted = $deleteConv->affected_rows;
    $deleteConv->close();
    
    $db->commit();
    
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
    error_log("WhatsApp conversation $conversationId deleted by user {$current_user['id']}: $messagesDeleted messages, $filesDeleted files");
    
    echo json_encode([
        'success' => true,
        'message' => 'Conversation deleted successfully',
        'messages_deleted' => $messagesDeleted,
        'files_deleted' => $filesDeleted
    ]);
    
} catch (Throwable $e) {
    $db->rollback();
    error_log("Delete conversation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete conversation: ' . $e->getMessage()]);
}

