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
        ]);
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
$phone = trim($_POST['phone'] ?? '');
$donorId = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
$donorName = trim($_POST['donor_name'] ?? '');
$sqmValue = trim($_POST['sqm_value'] ?? '0');
$totalPaid = trim($_POST['total_paid'] ?? '');
$customMessage = trim($_POST['message'] ?? ''); // Accept custom message

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Donor phone number is missing']);
    exit;
}

// Check if certificate image was uploaded
if (!isset($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $errorMsg = 'No certificate image received';
    if (isset($_FILES['certificate'])) {
        switch ($_FILES['certificate']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMsg = 'Certificate image too large';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMsg = 'No certificate image selected';
                break;
            default:
                $errorMsg = 'Upload error code: ' . $_FILES['certificate']['error'];
        }
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['certificate'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];
$fileMimeType = $file['type'];

// Validate it's a PNG image
if (!in_array($fileMimeType, ['image/png', 'image/jpeg', 'image/webp'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid image format. Expected PNG.']);
    exit;
}

// Validate file size (max 5MB for certificate)
$maxSize = 5 * 1024 * 1024;
if ($fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Certificate image too large (max 5MB)']);
    exit;
}

try {
    // Create uploads directory
    $uploadDir = __DIR__ . '/../../../uploads/certificates/' . date('Y/m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $safeName = preg_replace('/[^a-z0-9]/i', '_', $donorName);
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
        $caption = $customMessage;
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
    ]);

} catch (Exception $e) {
    ob_end_clean();
    error_log("WhatsApp Certificate Send Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
