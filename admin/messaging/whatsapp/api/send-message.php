<?php
/**
 * API: Send WhatsApp Message
 * 
 * Sends a message via UltraMsg and saves to database
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../../shared/auth.php';
    require_once __DIR__ . '/../../../../shared/csrf.php';
    require_once __DIR__ . '/../../../../config/db.php';
    require_once __DIR__ . '/../../../../services/UltraMsgService.php';
    require_login();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
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

// Get input
$conversationId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$phone = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($phone) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Phone and message are required']);
    exit;
}

try {
    // Get UltraMsg service
    $service = UltraMsgService::fromDatabase($db);
    
    if (!$service) {
        throw new Exception('WhatsApp provider not configured. Please set up UltraMsg first.');
    }
    
    // Get or create conversation if not provided - do this FIRST to get donor_id
    $donorId = null;
    $normalizedPhone = normalizePhoneForDb($phone);
    
    if ($conversationId === 0) {
        // Find conversation by phone
        $stmt = $db->prepare("SELECT id, donor_id FROM whatsapp_conversations WHERE phone_number = ?");
        $stmt->bind_param('s', $normalizedPhone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        if ($row) {
            $conversationId = (int)$row['id'];
            $donorId = $row['donor_id'] ? (int)$row['donor_id'] : null;
        } else {
            // Create new conversation
            $stmt = $db->prepare("
                INSERT INTO whatsapp_conversations (phone_number, is_unknown, created_at)
                VALUES (?, 1, NOW())
            ");
            $stmt->bind_param('s', $normalizedPhone);
            $stmt->execute();
            $conversationId = (int)$db->insert_id;
        }
    } else {
        // Get donor_id from existing conversation
        $stmt = $db->prepare("SELECT donor_id FROM whatsapp_conversations WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $donorId = $row['donor_id'] ? (int)$row['donor_id'] : null;
        }
    }
    
    // If no donor_id from conversation, try to find by phone
    if (!$donorId) {
        $stmt = $db->prepare("SELECT id FROM donors WHERE phone = ? OR phone = ? LIMIT 1");
        $phoneWithoutPlus = ltrim($normalizedPhone, '+');
        $stmt->bind_param('ss', $normalizedPhone, $phoneWithoutPlus);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $donorId = (int)$row['id'];
        }
    }
    
    // Send message via UltraMsg - log to whatsapp_log for message history tracking
    $result = $service->send($phone, $message, [
        'donor_id' => $donorId,
        'source_type' => 'whatsapp_inbox',
        'log' => true // Log to whatsapp_log table for message history
    ]);
    
    if (!$result['success']) {
        throw new Exception($result['error'] ?? 'Failed to send message');
    }
    
    // Save message to database
    $messageId = $result['message_id'] ?? null;
    $status = 'sent';
    $senderId = $current_user['id'];
    
    $stmt = $db->prepare("
        INSERT INTO whatsapp_messages 
        (conversation_id, ultramsg_id, direction, message_type, body, status, sender_id, is_from_donor, sent_at, created_at)
        VALUES (?, ?, 'outgoing', 'text', ?, ?, ?, 0, NOW(), NOW())
    ");
    
    $stmt->bind_param('isssi', $conversationId, $messageId, $message, $status, $senderId);
    $stmt->execute();
    $dbMessageId = $db->insert_id;
    
    // Update conversation
    $preview = mb_substr($message, 0, 255);
    $stmt = $db->prepare("
        UPDATE whatsapp_conversations 
        SET last_message_at = NOW(),
            last_message_preview = ?,
            last_message_direction = 'outgoing',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $preview, $conversationId);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message_id' => $dbMessageId,
        'ultramsg_id' => $messageId,
        'conversation_id' => $conversationId
    ]);
    
} catch (Exception $e) {
    error_log("WhatsApp Send Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Normalize phone number for database lookup
 */
function normalizePhoneForDb(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    if (strpos($phone, '+') !== 0) {
        if (strpos($phone, '44') === 0) {
            $phone = '+' . $phone;
        } elseif (strpos($phone, '0') === 0) {
            $phone = '+44' . substr($phone, 1);
        } else {
            $phone = '+' . $phone;
        }
    }
    
    return $phone;
}

