<?php
/**
 * API: Send Certificate via WhatsApp
 *
 * Receives a certificate PNG image, saves it, and sends it directly
 * to the donor's WhatsApp via UltraMsg API.
 */

declare(strict_types=1);

// Ensure we always output JSON even on fatal errors
ob_start();

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
            'error' => 'Fatal error: ' . $error['message']
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../shared/csrf.php';
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../services/UltraMsgService.php';
    require_login();
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    verify_csrf();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$db = db();
$current_user = current_user();

// Get input
$phone = trim($_POST['phone'] ?? '');
$donorId = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
$donorName = ensureUtf8(trim($_POST['donor_name'] ?? ''));
$sqmValue = trim($_POST['sqm_value'] ?? '0');
$totalPaid = trim($_POST['total_paid'] ?? '');
$customMessage = ensureUtf8(trim($_POST['message'] ?? '')); // Accept custom message

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Donor phone number is missing'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Check if certificate image was uploaded
if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMsg = 'No certificate image received';
    $uploadErrCode = isset($_FILES['certificate']) ? (int)$_FILES['certificate']['error'] : -1;
    if (isset($_FILES['certificate'])) {
        switch ($_FILES['certificate']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'Certificate image too large for server upload limits'
                    . ' (upload_max_filesize=' . ini_get('upload_max_filesize')
                    . ', post_max_size=' . ini_get('post_max_size') . ')';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'No certificate image selected';
                break;
            default:
                $errorMsg = 'Upload error code: ' . $_FILES['certificate']['error'];
        }
    }
    error_log(
        'WhatsApp Certificate Upload Error: code=' . $uploadErrCode
        . ', upload_max_filesize=' . ini_get('upload_max_filesize')
        . ', post_max_size=' . ini_get('post_max_size')
    );
    echo json_encode(['success' => false, 'error' => $errorMsg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$file = $_FILES['certificate'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];
$fileMimeType = $file['type'];
error_log('WhatsApp Certificate Upload: size=' . $fileSize . ', mime=' . $fileMimeType);

// Validate supported image formats
if (!in_array($fileMimeType, ['image/png', 'image/jpeg', 'image/webp'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid image format. Expected PNG, JPEG, or WEBP.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Validate file size (max 7MB for certificate; stays under UltraMsg base64 payload limit)
$maxSize = 7 * 1024 * 1024;
if ($fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Certificate image too large (max 7MB)'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    // Create uploads directory
    $uploadDir = __DIR__ . '/../../../uploads/certificates/' . date('Y/m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $asciiName = $donorName;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $donorName);
        if ($converted !== false && $converted !== '') {
            $asciiName = $converted;
        }
    }
    $safeName = preg_replace('/[^a-z0-9]+/i', '_', strtolower($asciiName));
    $safeName = trim((string)$safeName, '_');
    if ($safeName === '') {
        $safeName = $donorId > 0 ? ('donor_' . $donorId) : 'donor';
    }
    $uniqueName = 'cert_' . $safeName . '_' . date('Ymd_His') . '.png';
    $localPath = $uploadDir . '/' . $uniqueName;
    $relativePath = 'uploads/certificates/' . date('Y/m') . '/' . $uniqueName;

    // Move uploaded file
    if (!move_uploaded_file($fileTmpPath, $localPath)) {
        throw new Exception('Failed to save certificate image');
    }

    // Generate public URL for the file (used for database record-keeping)
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    $publicUrl = $baseUrl . '/' . $relativePath;

    // Get UltraMsg service
    $service = UltraMsgService::fromDatabase($db);
    if (!$service) {
        @unlink($localPath);
        throw new Exception('WhatsApp provider not configured. Please set up UltraMsg in WhatsApp Settings first.');
    }

    // Build caption message - use custom message if provided, otherwise use default
    if (!empty($customMessage)) {
        $caption = ensureUtf8($customMessage);
    } else {
        $caption = "Certificate of Contribution\n\n"
            . "Dear {$donorName},\n\n"
            . "Thank you for your generous contribution to Liverpool Abune Teklehaymanot EOTC!\n\n"
            . "Your Contribution:\n"
            . "Amount: {$totalPaid}\n"
            . "Allocation: {$sqmValue} m\u{00B2}\n\n"
            . "May God bless you abundantly!\n\n"
            . "Liverpool Abune Teklehaymanot EOTC";
    }

    // Send image via UltraMsg using base64 (much more reliable than URL)
    // This sends the image data directly so UltraMsg doesn't need to fetch from our server
    error_log("WhatsApp Certificate: Sending to $phone, file=$localPath, size=" . filesize($localPath) . " bytes");
    $result = $service->sendImageFromFile($phone, $localPath, $caption);
    error_log("WhatsApp Certificate: Result = " . json_encode($result));

    if (!$result['success']) {
        @unlink($localPath);
        $errorMsg = 'Failed to send certificate';
        if (isset($result['error'])) {
            $errorMsg = is_array($result['error']) ? json_encode($result['error']) : (string)$result['error'];
        }
        throw new Exception($errorMsg);
    }

    // Normalize phone for database
    $normalizedPhone = normalizePhoneForDb($phone);

    // Get or create conversation
    $conversationId = 0;
    $stmt = $db->prepare("SELECT id FROM whatsapp_conversations WHERE phone_number = ?");
    $stmt->bind_param('s', $normalizedPhone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $conversationId = (int)$row['id'];
    } else {
        // Try to link conversation to donor
        $isUnknown = $donorId > 0 ? 0 : 1;
        $stmt = $db->prepare("INSERT INTO whatsapp_conversations (phone_number, donor_id, is_unknown, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param('sii', $normalizedPhone, $donorId, $isUnknown);
        $stmt->execute();
        $conversationId = (int)$db->insert_id;
    }

    // Save message to whatsapp_messages table
    $messageId = isset($result['message_id']) ? (string)$result['message_id'] : null;
    $status = 'sent';
    $senderId = (int)$current_user['id'];

    $stmt = $db->prepare("
        INSERT INTO whatsapp_messages
        (conversation_id, ultramsg_id, direction, message_type, body, media_url, media_mime_type, media_filename, media_caption, media_local_path, status, sender_id, is_from_donor, sent_at, created_at)
        VALUES (?, ?, 'outgoing', 'image', ?, ?, 'image/png', ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");

    $stmt->bind_param('isssssssi',
        $conversationId, $messageId, $caption,
        $publicUrl, $uniqueName, $caption, $relativePath,
        $status, $senderId
    );
    $stmt->execute();
    $dbMessageId = (int)$db->insert_id;

    // Update conversation with latest message preview
    $preview = "Certificate sent";
    $stmt = $db->prepare("
        UPDATE whatsapp_conversations
        SET last_message_at = NOW(), last_message_preview = ?, last_message_direction = 'outgoing', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('si', $preview, $conversationId);
    $stmt->execute();

    // Clear any buffered output
    ob_end_clean();

    echo json_encode([
        'success' => true,
        'message' => 'Certificate sent to WhatsApp successfully',
        'message_id' => $dbMessageId,
        'ultramsg_id' => $messageId,
        'conversation_id' => $conversationId
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Exception $e) {
    ob_end_clean();
    error_log("WhatsApp Certificate Send Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * Normalize phone number for database storage
 */
function normalizePhoneForDb(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '+') === 0) {
        return $phone; // Already international format
    }
    if (strpos($phone, '44') === 0 && strlen($phone) === 12) {
        return '+' . $phone;
    }
    if (strpos($phone, '251') === 0 && strlen($phone) === 12) {
        return '+' . $phone;
    }
    if (strpos($phone, '0') === 0) {
        return '+44' . substr($phone, 1);
    }
    return '+' . $phone;
}

function ensureUtf8(string $value): string
{
    if ($value === '') {
        return $value;
    }

    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $value;
}
