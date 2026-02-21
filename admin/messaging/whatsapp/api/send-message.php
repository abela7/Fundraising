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
$is_admin = ($current_user['role'] ?? '') === 'admin';

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
    
    // Resolve conversation and enforce role-based access
    $donorId = null;
    $normalizedPhone = normalizePhoneForDb($phone);
    $userId = (int)($current_user['id'] ?? 0);

    if ($conversationId === 0) {
        // Find existing conversation by phone first
        $stmt = $db->prepare("SELECT id, donor_id FROM whatsapp_conversations WHERE phone_number = ? LIMIT 1");
        $stmt->bind_param('s', $normalizedPhone);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $conversationId = (int)$row['id'];
            $donorId = $row['donor_id'] ? (int)$row['donor_id'] : null;

            if (!$is_admin && !userCanAccessConversation($db, $conversationId, $userId, false)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Access denied to this conversation']);
                exit;
            }
        } else {
            // No conversation exists: non-admin can only start chats for their assigned donor.
            $donor = findDonorForPhone($db, $normalizedPhone);
            if (!$is_admin) {
                if (!$donor || (int)($donor['agent_id'] ?? 0) !== $userId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'You can only chat with your assigned donors']);
                    exit;
                }
            }

            if ($donor) {
                $donorId = (int)$donor['id'];
            }

            if (!$is_admin) {
                $stmt = $db->prepare("
                    INSERT INTO whatsapp_conversations (phone_number, donor_id, is_unknown, assigned_agent_id, created_at)
                    VALUES (?, ?, 0, ?, NOW())
                ");
                $stmt->bind_param('sii', $normalizedPhone, $donorId, $userId);
            } elseif ($donorId) {
                $isUnknown = 0;
                $stmt = $db->prepare("
                    INSERT INTO whatsapp_conversations (phone_number, donor_id, is_unknown, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->bind_param('sii', $normalizedPhone, $donorId, $isUnknown);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO whatsapp_conversations (phone_number, is_unknown, created_at)
                    VALUES (?, 1, NOW())
                ");
                $stmt->bind_param('s', $normalizedPhone);
            }

            $stmt->execute();
            $conversationId = (int)$db->insert_id;
            $stmt->close();
        }
    } else {
        if (!$is_admin && !userCanAccessConversation($db, $conversationId, $userId, false)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied to this conversation']);
            exit;
        }

        // Get donor_id from existing conversation
        $stmt = $db->prepare("SELECT donor_id FROM whatsapp_conversations WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $donorId = $row['donor_id'] ? (int)$row['donor_id'] : null;
        }
    }

    // If no donor_id from conversation, try to find by phone
    if (!$donorId) {
        $donor = findDonorForPhone($db, $normalizedPhone);
        if ($donor) {
            $donorAgentId = (int)($donor['agent_id'] ?? 0);
            if ($is_admin || $donorAgentId === $userId) {
                $donorId = (int)$donor['id'];
            }
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

function userCanAccessConversation(mysqli $db, int $conversationId, int $userId, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }

    $stmt = $db->prepare("
        SELECT wc.id
        FROM whatsapp_conversations wc
        LEFT JOIN donors d ON wc.donor_id = d.id
        WHERE wc.id = ?
          AND (wc.assigned_agent_id = ? OR d.agent_id = ?)
        LIMIT 1
    ");
    $stmt->bind_param('iii', $conversationId, $userId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return !empty($row);
}

function findDonorForPhone(mysqli $db, string $normalizedPhone): ?array
{
    $stmt = $db->prepare("SELECT id, agent_id FROM donors WHERE phone = ? OR phone = ? LIMIT 1");
    $phoneWithoutPlus = ltrim($normalizedPhone, '+');
    $stmt->bind_param('ss', $normalizedPhone, $phoneWithoutPlus);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
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

