<?php
/**
 * UltraMsg Webhook Endpoint
 * 
 * Receives incoming WhatsApp messages from UltraMsg
 * URL: https://yoursite.com/webhooks/ultramsg.php
 * 
 * Set this URL in your UltraMsg dashboard under Instance Settings > Webhook URL
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
// MAIN WEBHOOK HANDLER
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
    
    // Get raw payload
    $rawPayload = file_get_contents('php://input');
    
    // Also check GET parameters (UltraMsg can send via GET)
    if (empty($rawPayload) && !empty($_GET)) {
        $rawPayload = json_encode($_GET);
    }
    
    // Also check POST parameters
    if (empty($rawPayload) && !empty($_POST)) {
        $rawPayload = json_encode($_POST);
    }
    
    if (empty($rawPayload)) {
        // Empty payload - might be a test ping
        logWebhook($db, 'ping', '{}', true);
        echo json_encode(['status' => 'ok', 'message' => 'Webhook is active']);
        exit;
    }
    
    // Parse JSON payload
    $payload = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Try to parse as form data
        parse_str($rawPayload, $payload);
    }
    
    // Log the webhook
    $eventType = $payload['event_type'] ?? $payload['type'] ?? 'message';
    
    // Handle different webhook formats from UltraMsg
    // Format 1: Direct message data
    // Format 2: Wrapped in 'data' key
    $messageData = $payload['data'] ?? $payload;
    
    // Extract message info
    $from = $messageData['from'] ?? $messageData['sender'] ?? $messageData['chatId'] ?? null;
    $body = $messageData['body'] ?? $messageData['text'] ?? $messageData['message'] ?? '';
    $messageId = $messageData['id'] ?? $messageData['msgId'] ?? uniqid('msg_');
    $type = $messageData['type'] ?? $messageData['messageType'] ?? 'text';
    $contactName = $messageData['pushname'] ?? $messageData['senderName'] ?? null;
    
    // Handle media
    $mediaUrl = $messageData['media'] ?? $messageData['mediaUrl'] ?? $messageData['url'] ?? null;
    $mimetype = $messageData['mimetype'] ?? $messageData['mimeType'] ?? null;
    $filename = $messageData['filename'] ?? $messageData['fileName'] ?? null;
    $caption = $messageData['caption'] ?? null;
    
    // Handle location
    $latitude = isset($messageData['lat']) ? (float)$messageData['lat'] : null;
    $longitude = isset($messageData['lng']) ? (float)$messageData['lng'] : null;
    $locationName = $messageData['loc'] ?? $messageData['locationName'] ?? null;
    $locationAddress = $messageData['address'] ?? null;
    
    // Handle contact (vcard)
    $vcardName = null;
    $vcardPhone = null;
    if ($type === 'contact' || $type === 'vcard') {
        $vcardName = $messageData['vcardName'] ?? $messageData['name'] ?? null;
        $vcardPhone = $messageData['vcardPhone'] ?? $messageData['phone'] ?? null;
    }
    
    // Skip if no sender
    if (!$from) {
        logWebhook($db, $eventType, $rawPayload, false, 'No sender found in payload');
        echo json_encode(['status' => 'ok', 'message' => 'No sender found']);
        exit;
    }
    
    // Skip outgoing messages (messages we sent)
    $isFromMe = $messageData['fromMe'] ?? $messageData['isFromMe'] ?? false;
    if ($isFromMe === true || $isFromMe === 'true' || $isFromMe === 1) {
        logWebhook($db, 'outgoing_skip', $rawPayload, true);
        echo json_encode(['status' => 'ok', 'message' => 'Outgoing message skipped']);
        exit;
    }
    
    // Normalize phone number
    $normalizedPhone = normalizePhone($from);
    
    // Find donor by phone
    $donor = findDonorByPhone($db, $normalizedPhone);
    
    // Get or create conversation
    $conversationId = getOrCreateConversation($db, $normalizedPhone, $donor, $contactName);
    
    // Prepare message data for saving
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
    
    // Save the message
    $messageDbId = saveMessage($db, $conversationId, $msgData);
    
    // Update conversation
    $preview = $body ?: ($caption ?: getTypePreview($type));
    updateConversationLastMessage($db, $conversationId, $preview);
    
    // Log successful processing
    logWebhook($db, $eventType, $rawPayload, true);
    
    // Return success
    echo json_encode([
        'status' => 'ok',
        'message' => 'Message received',
        'conversation_id' => $conversationId,
        'message_id' => $messageDbId,
        'donor_id' => $donor['id'] ?? null,
        'is_new_sender' => $donor === null
    ]);
    
} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage());
    
    // Try to log the error
    if (isset($db) && isset($rawPayload)) {
        logWebhook($db, 'error', $rawPayload ?? '{}', false, $e->getMessage());
    }
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
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

