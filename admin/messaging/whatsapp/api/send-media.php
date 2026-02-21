<?php
/**
 * API: Send WhatsApp Media
 * 
 * Handles sending images, documents, audio, and video via UltraMsg
 */

declare(strict_types=1);

// Ensure we always output JSON even on fatal errors
ob_start();

// Set error handling to capture all errors
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'Fatal error: ' . $error['message'],
            'debug' => $error['file'] . ':' . $error['line']
        ]);
    }
});

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../../shared/auth.php';
    require_once __DIR__ . '/../../../../shared/csrf.php';
    require_once __DIR__ . '/../../../../config/db.php';
    require_once __DIR__ . '/../../../../services/UltraMsgService.php';
    require_login();
} catch (Throwable $e) {
    ob_end_clean();
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
$caption = trim($_POST['caption'] ?? '');
$mediaType = trim($_POST['media_type'] ?? 'image'); // image, document, audio, video

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Phone number is required']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMsg = 'No file uploaded';
    if (isset($_FILES['media'])) {
        switch ($_FILES['media']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'File too large (max 16MB)';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'No file selected';
                break;
            default:
                $errorMsg = 'Upload error code: ' . $_FILES['media']['error'];
        }
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['media'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];
$fileMimeType = $file['type'];

// Validate file size (16MB max for WhatsApp)
$maxSize = 16 * 1024 * 1024;
if ($fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 16MB.']);
    exit;
}

// Allowed mime types
$allowedTypes = [
    'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                   'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                   'text/plain', 'application/zip'],
    'audio' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4'],
    'video' => ['video/mp4', 'video/mpeg', 'video/webm', 'video/quicktime']
];

// Determine media type from mime
// Always use 'audio' for audio files (voice endpoint has stricter format requirements)
if (strpos($fileMimeType, 'image/') === 0) {
    $mediaType = 'image';
} elseif (strpos($fileMimeType, 'audio/') === 0) {
    $mediaType = 'audio'; // Use audio endpoint for all audio (more format-flexible)
} elseif (strpos($fileMimeType, 'video/') === 0) {
    $mediaType = 'video';
} else {
    $mediaType = 'document';
}

// Validate mime type
$allAllowed = array_merge(...array_values($allowedTypes));
if (!in_array($fileMimeType, $allAllowed)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $fileMimeType]);
    exit;
}

try {
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../../../uploads/whatsapp/' . date('Y/m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    $uniqueName = uniqid('wa_') . '_' . time() . '.' . $extension;
    $localPath = $uploadDir . '/' . $uniqueName;
    $relativePath = 'uploads/whatsapp/' . date('Y/m') . '/' . $uniqueName;
    
    // Move uploaded file
    if (!move_uploaded_file($fileTmpPath, $localPath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    // Generate public URL for the file
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    $publicUrl = $baseUrl . '/' . $relativePath;
    
    // Get UltraMsg service
    $service = UltraMsgService::fromDatabase($db);
    if (!$service) {
        throw new Exception('WhatsApp provider not configured');
    }

    // Resolve conversation and enforce role-based access before sending media
    $userId = (int)($current_user['id'] ?? 0);
    $normalizedPhone = normalizePhoneForDb($phone);
    $donorId = null;

    if ($conversationId === 0) {
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
                $stmt = $db->prepare("INSERT INTO whatsapp_conversations (phone_number, is_unknown, created_at) VALUES (?, 1, NOW())");
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
    }
    
    // Send media via UltraMsg based on type
    $result = $service->sendMedia($phone, $publicUrl, $mediaType, $caption, $fileName);
    
    if (!$result['success']) {
        // Clean up uploaded file on failure
        @unlink($localPath);
        
        // Handle error - could be string or array
        $errorMsg = 'Failed to send media';
        if (isset($result['error'])) {
            if (is_array($result['error'])) {
                $errorMsg = json_encode($result['error']);
            } else {
                $errorMsg = (string)$result['error'];
            }
        }
        throw new Exception($errorMsg);
    }
    
    // Save message to database
    $messageId = isset($result['message_id']) ? (string)$result['message_id'] : null;
    $status = 'sent';
    $senderId = (int)$current_user['id'];
    
    $stmt = $db->prepare("
        INSERT INTO whatsapp_messages 
        (conversation_id, ultramsg_id, direction, message_type, body, media_url, media_mime_type, media_filename, media_caption, media_local_path, status, sender_id, is_from_donor, sent_at, created_at)
        VALUES (?, ?, 'outgoing', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");
    
    $body = $caption ?: null;
    $stmt->bind_param('isssssssssi', 
        $conversationId, $messageId, $mediaType, $body, 
        $publicUrl, $fileMimeType, $fileName, $caption, $relativePath, 
        $status, $senderId
    );
    $stmt->execute();
    $dbMessageId = (int)$db->insert_id;
    
    // Update conversation - check filename to determine if it's a voice recording
    $isVoiceRecording = strpos($fileName, 'voice_') === 0;
    $preview = $mediaType === 'image' ? '📷 Photo' : 
               ($mediaType === 'video' ? '🎥 Video' : 
               ($mediaType === 'audio' ? ($isVoiceRecording ? '🎤 Voice message' : '🎵 Audio') : '📎 Document'));
    if ($caption) {
        $preview .= ': ' . mb_substr($caption, 0, 50);
    }
    
    $stmt = $db->prepare("
        UPDATE whatsapp_conversations 
        SET last_message_at = NOW(), last_message_preview = ?, last_message_direction = 'outgoing', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $preview, $conversationId);
    $stmt->execute();
    
    // Clear any buffered output before sending JSON
    ob_end_clean();
    
    echo json_encode([
        'success' => true,
        'message_id' => $dbMessageId,
        'ultramsg_id' => $messageId,
        'conversation_id' => $conversationId,
        'media_url' => $publicUrl,
        'media_type' => $mediaType,
        'filename' => $fileName
    ]);
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("WhatsApp Media Send Error: " . $e->getMessage());
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

