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
$current_user = current_user();
$is_admin = ($current_user['role'] ?? '') === 'admin';

// Get message ID
$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid message ID: ' . $messageId]);
    exit;
}

try {
    if (!$is_admin) {
        $userId = (int)($current_user['id'] ?? 0);
        $accessStmt = $db->prepare("
            SELECT wm.id
            FROM whatsapp_messages wm
            JOIN whatsapp_conversations wc ON wm.conversation_id = wc.id
            LEFT JOIN donors d ON wc.donor_id = d.id
            WHERE wm.id = ?
              AND (wc.assigned_agent_id = ? OR d.agent_id = ?)
            LIMIT 1
        ");
        $accessStmt->bind_param('iii', $messageId, $userId, $userId);
        $accessStmt->execute();
        $allowed = $accessStmt->get_result()->fetch_assoc();
        $accessStmt->close();

        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
    }

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
