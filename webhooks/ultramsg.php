<?php
/**
 * UltraMsg Webhook Endpoint - Full WhatsApp Experience
 * 
 * Handles ALL webhook events from UltraMsg:
 * - Message Received (incoming messages)
 * - Message Created (outgoing message tracking)
 * - ACK (delivery status: sent, delivered, read)
 * - Media Download (images, voice, documents)
 * - Reactions (emoji reactions)
 * 
 * URL: https://yoursite.com/webhooks/ultramsg.php
 */

declare(strict_types=1);

// Allow cross-origin requests from UltraMsg
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log all requests for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/webhook_errors.log');

// Log raw request for debugging
$rawInput = file_get_contents('php://input');
$logFile = __DIR__ . '/../logs/webhook_debug.log';
$logEntry = date('Y-m-d H:i:s') . " | Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logEntry .= "GET: " . json_encode($_GET) . "\n";
$logEntry .= "POST: " . json_encode($_POST) . "\n";
$logEntry .= "RAW: " . $rawInput . "\n";
$logEntry .= str_repeat('-', 80) . "\n";
@file_put_contents($logFile, $logEntry, FILE_APPEND);

require_once __DIR__ . '/../config/db.php';

/**
 * Normalize phone number to consistent format
 */
function normalizePhone(string $phone): string
{
    // Remove all non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Remove @c.us suffix if present
    $phone = preg_replace('/@c\.us$/', '', $phone);
    
    // Ensure it starts with +
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

/**
 * Find donor by phone number
 */
function findDonorByPhone($db, string $phone): ?array
{
    // Try various phone formats
    $phoneVariants = [
        $phone,                                    // +447123456789
        ltrim($phone, '+'),                        // 447123456789
        '0' . substr(ltrim($phone, '+'), 2),       // 07123456789
        substr(ltrim($phone, '+'), 2),             // 7123456789
    ];
    
    $placeholders = implode(',', array_fill(0, count($phoneVariants), '?'));
    
    $stmt = $db->prepare("
        SELECT id, name, phone, agent_id 
        FROM donors 
        WHERE phone IN ($placeholders)
        LIMIT 1
    ");
    
    if (!$stmt) return null;
    
    $types = str_repeat('s', count($phoneVariants));
    $stmt->bind_param($types, ...$phoneVariants);
    $stmt->execute();
    
    $result = $stmt->get_result();
    return $result->fetch_assoc() ?: null;
}

/**
 * Get or create conversation
 */
function getOrCreateConversation($db, string $phone, ?array $donor, ?string $contactName): int
{
    $normalizedPhone = normalizePhone($phone);
    
    // Check if conversation exists
    $stmt = $db->prepare("SELECT id FROM whatsapp_conversations WHERE phone_number = ?");
    $stmt->bind_param('s', $normalizedPhone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Update contact name if provided
        if ($contactName) {
            $db->query("UPDATE whatsapp_conversations SET contact_name = '" . $db->real_escape_string($contactName) . "' WHERE id = " . $row['id']);
        }
        return (int)$row['id'];
    }
    
    // Create new conversation
    $donorId = $donor['id'] ?? null;
    $agentId = $donor['agent_id'] ?? null;
    $isUnknown = $donor === null ? 1 : 0;
    $name = $contactName ?: ($donor['name'] ?? null);
    
    $stmt = $db->prepare("
        INSERT INTO whatsapp_conversations 
        (phone_number, donor_id, assigned_agent_id, contact_name, is_unknown, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->bind_param('siisi', $normalizedPhone, $donorId, $agentId, $name, $isUnknown);
    $stmt->execute();
    
    return (int)$db->insert_id;
}

/**
 * Save incoming message
 */
function saveMessage($db, int $conversationId, array $messageData): int
{
    $stmt = $db->prepare("
        INSERT INTO whatsapp_messages 
        (conversation_id, ultramsg_id, direction, message_type, body, 
         media_url, media_mime_type, media_filename, media_caption,
         latitude, longitude, location_name, location_address,
         contact_name, contact_phone,
         status, is_from_donor, sent_at, created_at)
        VALUES (?, ?, 'incoming', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered', 1, NOW(), NOW())
    ");
    
    $stmt->bind_param(
        'isssssssddssss',
        $conversationId,
        $messageData['id'],
        $messageData['type'],
        $messageData['body'],
        $messageData['media_url'],
        $messageData['media_mime_type'],
        $messageData['media_filename'],
        $messageData['media_caption'],
        $messageData['latitude'],
        $messageData['longitude'],
        $messageData['location_name'],
        $messageData['location_address'],
        $messageData['contact_name'],
        $messageData['contact_phone']
    );
    
    $stmt->execute();
    return (int)$db->insert_id;
}

/**
 * Update conversation with latest message
 */
function updateConversationLastMessage($db, int $conversationId, string $preview): void
{
    $preview = mb_substr($preview, 0, 255);
    
    $stmt = $db->prepare("
        UPDATE whatsapp_conversations 
        SET last_message_at = NOW(),
            last_message_preview = ?,
            last_message_direction = 'incoming',
            unread_count = unread_count + 1,
            updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->bind_param('si', $preview, $conversationId);
    $stmt->execute();
}

/**
 * Log webhook payload for debugging
 */
function logWebhook($db, string $eventType, string $payload, bool $processed, ?string $error = null): void
{
    try {
        $stmt = $db->prepare("
            INSERT INTO whatsapp_webhook_logs 
            (event_type, payload, processed, error_message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $processedInt = $processed ? 1 : 0;
        $stmt->bind_param('ssis', $eventType, $payload, $processedInt, $error);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to log webhook: " . $e->getMessage());
    }
}

// ============================================
// MAIN WEBHOOK HANDLER - Full WhatsApp Experience
// ============================================

try {
    $db = db();
    
    // Check if tables exist
    $tableCheck = $db->query("SHOW TABLES LIKE 'whatsapp_conversations'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        http_response_code(500);
        echo json_encode(['error' => 'Database tables not created']);
        exit;
    }
    
    // Collect data from all possible sources
    $payload = [];
    
    // Source 1: Raw JSON body
    $rawPayload = $rawInput ?? file_get_contents('php://input');
    if (!empty($rawPayload)) {
        $decoded = json_decode($rawPayload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $payload = $decoded;
        }
    }
    
    // Source 2: GET parameters (UltraMsg often sends via GET)
    if (empty($payload) && !empty($_GET)) {
        $payload = $_GET;
        $rawPayload = json_encode($_GET);
    }
    
    // Source 3: POST form data
    if (empty($payload) && !empty($_POST)) {
        $payload = $_POST;
        $rawPayload = json_encode($_POST);
    }
    
    // Source 4: URL-encoded body
    if (empty($payload) && !empty($rawPayload)) {
        parse_str($rawPayload, $parsed);
        if (!empty($parsed)) {
            $payload = $parsed;
        }
    }
    
    if (empty($payload)) {
        logWebhook($db, 'ping', '{}', true);
        echo json_encode(['status' => 'ok', 'message' => 'Webhook is active']);
        exit;
    }
    
    // Determine event type
    $eventType = $payload['event_type'] ?? $payload['event'] ?? $payload['type'] ?? 'message_received';
    $messageData = $payload['data'] ?? $payload;
    
    // Log the webhook
    logWebhook($db, $eventType, $rawPayload, true);
    
    // ============================================
    // ROUTE BY EVENT TYPE
    // ============================================
    
    switch (strtolower($eventType)) {
        case 'message_received':
        case 'message':
        case 'chat':
            handleIncomingMessage($db, $messageData, $rawPayload);
            break;
            
        case 'message_create':
        case 'message_created':
        case 'create':
            handleOutgoingMessage($db, $messageData);
            break;
            
        case 'message_ack':
        case 'ack':
            handleMessageAck($db, $messageData);
            break;
            
        case 'reaction':
        case 'message_reaction':
            handleReaction($db, $messageData);
            break;
            
        default:
            // Try to detect if it's an incoming message by checking for 'from' field
            if (isset($messageData['from']) || isset($messageData['chatId'])) {
                handleIncomingMessage($db, $messageData, $rawPayload);
            } else {
                echo json_encode(['status' => 'ok', 'message' => 'Event type not handled: ' . $eventType]);
            }
    }
    
} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage());
    
    if (isset($db) && isset($rawPayload)) {
        logWebhook($db, 'error', $rawPayload ?? '{}', false, $e->getMessage());
    }
    
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

// ============================================
// EVENT HANDLERS
// ============================================

/**
 * Handle incoming message (from donor)
 */
function handleIncomingMessage($db, array $messageData, string $rawPayload): void
{
    $from = $messageData['from'] ?? $messageData['sender'] ?? $messageData['chatId'] ?? null;
    $body = $messageData['body'] ?? $messageData['text'] ?? $messageData['message'] ?? '';
    $messageId = $messageData['id'] ?? $messageData['msgId'] ?? uniqid('msg_');
    $type = $messageData['type'] ?? $messageData['messageType'] ?? 'chat';
    $contactName = $messageData['pushname'] ?? $messageData['senderName'] ?? $messageData['notifyName'] ?? null;
    
    // Skip if no sender
    if (!$from) {
        echo json_encode(['status' => 'ok', 'message' => 'No sender found']);
        return;
    }
    
    // Skip outgoing messages
    $isFromMe = $messageData['fromMe'] ?? $messageData['isFromMe'] ?? false;
    if ($isFromMe === true || $isFromMe === 'true' || $isFromMe === 1 || $isFromMe === '1') {
        echo json_encode(['status' => 'ok', 'message' => 'Outgoing message skipped']);
        return;
    }
    
    // Extract media
    $mediaUrl = $messageData['media'] ?? $messageData['mediaUrl'] ?? $messageData['url'] ?? $messageData['image'] ?? $messageData['video'] ?? $messageData['audio'] ?? null;
    $mimetype = $messageData['mimetype'] ?? $messageData['mimeType'] ?? null;
    $filename = $messageData['filename'] ?? $messageData['fileName'] ?? null;
    $caption = $messageData['caption'] ?? null;
    
    // Extract location
    $latitude = isset($messageData['lat']) ? (float)$messageData['lat'] : (isset($messageData['latitude']) ? (float)$messageData['latitude'] : null);
    $longitude = isset($messageData['lng']) ? (float)$messageData['lng'] : (isset($messageData['longitude']) ? (float)$messageData['longitude'] : null);
    $locationName = $messageData['loc'] ?? $messageData['locationName'] ?? $messageData['name'] ?? null;
    $locationAddress = $messageData['address'] ?? null;
    
    // Extract contact/vcard
    $vcardName = null;
    $vcardPhone = null;
    if ($type === 'contact' || $type === 'vcard') {
        $vcardName = $messageData['vcardName'] ?? $messageData['displayName'] ?? null;
        $vcardPhone = $messageData['vcardPhone'] ?? null;
    }
    
    // Normalize phone
    $normalizedPhone = normalizePhone($from);
    
    // Find donor
    $donor = findDonorByPhone($db, $normalizedPhone);
    
    // Get or create conversation
    $conversationId = getOrCreateConversation($db, $normalizedPhone, $donor, $contactName);
    
    // Prepare and save message
    $msgData = [
        'id' => $messageId,
        'type' => mapMessageType($type),
        'body' => $body,
        'media_url' => $mediaUrl,
        'media_mime_type' => $mimetype,
        'media_filename' => $filename,
        'media_caption' => $caption,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_name' => $locationName,
        'location_address' => $locationAddress,
        'contact_name' => $vcardName,
        'contact_phone' => $vcardPhone
    ];
    
    $messageDbId = saveMessage($db, $conversationId, $msgData);
    
    // Update conversation
    $preview = $body ?: ($caption ?: getTypePreview(mapMessageType($type)));
    updateConversationLastMessage($db, $conversationId, $preview);
    
    echo json_encode([
        'status' => 'ok',
        'message' => 'Message received',
        'conversation_id' => $conversationId,
        'message_id' => $messageDbId,
        'donor_id' => $donor['id'] ?? null
    ]);
}

/**
 * Handle outgoing message (message we sent - for tracking)
 */
function handleOutgoingMessage($db, array $messageData): void
{
    $messageId = $messageData['id'] ?? $messageData['msgId'] ?? null;
    $to = $messageData['to'] ?? $messageData['chatId'] ?? null;
    
    if (!$messageId || !$to) {
        echo json_encode(['status' => 'ok', 'message' => 'No message ID or recipient']);
        return;
    }
    
    // Update message status to 'sent' if we have it
    $stmt = $db->prepare("UPDATE whatsapp_messages SET status = 'sent', sent_at = NOW() WHERE ultramsg_id = ?");
    $stmt->bind_param('s', $messageId);
    $stmt->execute();
    
    echo json_encode(['status' => 'ok', 'message' => 'Outgoing message tracked']);
}

/**
 * Handle ACK (delivery status updates)
 * ACK levels: 1 = sent, 2 = delivered, 3 = read
 */
function handleMessageAck($db, array $messageData): void
{
    $messageId = $messageData['id'] ?? $messageData['msgId'] ?? null;
    $ack = $messageData['ack'] ?? $messageData['status'] ?? null;
    
    if (!$messageId) {
        echo json_encode(['status' => 'ok', 'message' => 'No message ID for ACK']);
        return;
    }
    
    // Map ACK level to status
    $status = 'sent';
    $updateField = 'sent_at';
    
    if ($ack == 1 || $ack === 'sent') {
        $status = 'sent';
        $updateField = 'sent_at';
    } elseif ($ack == 2 || $ack === 'delivered' || $ack === 'received') {
        $status = 'delivered';
        $updateField = 'delivered_at';
    } elseif ($ack == 3 || $ack === 'read' || $ack === 'seen') {
        $status = 'read';
        $updateField = 'read_at';
    } elseif ($ack == -1 || $ack === 'failed' || $ack === 'error') {
        $status = 'failed';
    }
    
    // Update message status
    $sql = "UPDATE whatsapp_messages SET status = ?, {$updateField} = NOW() WHERE ultramsg_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $status, $messageId);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'ok', 'message' => "Message status updated to: $status"]);
    } else {
        echo json_encode(['status' => 'ok', 'message' => 'Message not found for ACK update']);
    }
}

/**
 * Handle reaction to a message
 */
function handleReaction($db, array $messageData): void
{
    $messageId = $messageData['msgId'] ?? $messageData['id'] ?? null;
    $reaction = $messageData['reaction'] ?? $messageData['emoji'] ?? null;
    $from = $messageData['from'] ?? $messageData['sender'] ?? null;
    
    if (!$messageId || !$reaction) {
        echo json_encode(['status' => 'ok', 'message' => 'No message ID or reaction']);
        return;
    }
    
    // Find the original message
    $stmt = $db->prepare("SELECT conversation_id FROM whatsapp_messages WHERE ultramsg_id = ? LIMIT 1");
    $stmt->bind_param('s', $messageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $original = $result->fetch_assoc();
    
    if (!$original) {
        echo json_encode(['status' => 'ok', 'message' => 'Original message not found for reaction']);
        return;
    }
    
    // Save reaction as a special message
    $stmt = $db->prepare("
        INSERT INTO whatsapp_messages 
        (conversation_id, direction, message_type, body, replied_to_id, is_from_donor, status, created_at)
        VALUES (?, 'incoming', 'reaction', ?, (SELECT id FROM whatsapp_messages WHERE ultramsg_id = ? LIMIT 1), 1, 'delivered', NOW())
    ");
    $stmt->bind_param('iss', $original['conversation_id'], $reaction, $messageId);
    $stmt->execute();
    
    echo json_encode(['status' => 'ok', 'message' => 'Reaction saved']);
}

/**
 * Map UltraMsg message types to our types
 */
function mapMessageType(string $type): string
{
    $typeMap = [
        'chat' => 'text',
        'text' => 'text',
        'image' => 'image',
        'video' => 'video',
        'audio' => 'audio',
        'ptt' => 'voice',
        'voice' => 'voice',
        'document' => 'document',
        'location' => 'location',
        'vcard' => 'contact',
        'contact' => 'contact',
        'sticker' => 'sticker',
        'reaction' => 'reaction'
    ];
    
    return $typeMap[strtolower($type)] ?? 'text';
}

/**
 * Get preview text for non-text message types
 */
function getTypePreview(string $type): string
{
    $previews = [
        'image' => '📷 Image',
        'video' => '🎥 Video',
        'audio' => '🎵 Audio',
        'voice' => '🎤 Voice message',
        'document' => '📄 Document',
        'location' => '📍 Location',
        'contact' => '👤 Contact',
        'sticker' => '🎭 Sticker'
    ];
    
    return $previews[$type] ?? '💬 Message';
}

