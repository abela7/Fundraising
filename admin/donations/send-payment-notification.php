<?php
/**
 * Send Payment Confirmation Notification
 * 
 * Sends a WhatsApp message (with SMS fallback) to notify a donor
 * that their payment has been confirmed.
 */

declare(strict_types=1);

// Start output buffering to prevent stray output
ob_start();

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';

// Clean any output before sending JSON
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

/**
 * Send JSON response and exit
 *
 * @param array<string, mixed> $data Response data
 * @return never
 */
function sendJsonResponse(array $data): void
{
    echo json_encode($data);
    exit;
}

try {
    // Allow both admin and registrar access
    require_login();
    $current_user = current_user();
    if (!in_array($current_user['role'] ?? '', ['admin', 'registrar'])) {
        sendJsonResponse(['success' => false, 'error' => 'Access denied']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'error' => 'Invalid request method']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid JSON input']);
    }

    $donorId = isset($input['donor_id']) ? (int)$input['donor_id'] : 0;
    $phone = trim($input['phone'] ?? '');
    $message = trim($input['message'] ?? '');
    $language = $input['language'] ?? 'en';

    if ($donorId <= 0) {
        sendJsonResponse(['success' => false, 'error' => 'Invalid donor ID']);
    }

    if (empty($phone)) {
        sendJsonResponse(['success' => false, 'error' => 'Phone number is required']);
    }

    if (empty($message)) {
        sendJsonResponse(['success' => false, 'error' => 'Message is required']);
    }

    $db = db();

    // Verify donor exists
    $stmt = $db->prepare("SELECT id, name, phone FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$donor) {
        sendJsonResponse(['success' => false, 'error' => 'Donor not found']);
    }

    // Initialize messaging helper
    $messaging = new MessagingHelper($db);
    
    // Set current user for logging
    $messaging->setCurrentUser(
        (int)($current_user['id'] ?? 0),
        $current_user
    );

    // Send the message (WhatsApp first, SMS fallback)
    $result = $messaging->sendDirect(
        $phone,
        $message,
        MessagingHelper::CHANNEL_AUTO,
        $donorId,
        'payment_confirmation'
    );

    if ($result['success']) {
        // Log the notification
        try {
            // Check if payment_notifications_log table exists
            $tableCheck = $db->query("SHOW TABLES LIKE 'payment_notifications_log'");
            
            if ($tableCheck->num_rows === 0) {
                // Create the table if it doesn't exist
                $db->query("
                    CREATE TABLE IF NOT EXISTS payment_notifications_log (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        donor_id INT NOT NULL,
                        notification_type VARCHAR(50) NOT NULL DEFAULT 'payment_confirmed',
                        channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
                        phone_number VARCHAR(20) NULL,
                        message_preview VARCHAR(500) NULL,
                        sent_by_user_id INT NULL,
                        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        status VARCHAR(20) NOT NULL DEFAULT 'sent',
                        INDEX idx_donor (donor_id),
                        INDEX idx_type (notification_type),
                        INDEX idx_sent_at (sent_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            $userId = (int)($current_user['id'] ?? 0);
            $channel = $result['channel'] ?? 'whatsapp';
            $preview = mb_substr($message, 0, 500);
            
            $logStmt = $db->prepare("
                INSERT INTO payment_notifications_log 
                (donor_id, notification_type, channel, phone_number, message_preview, sent_by_user_id)
                VALUES (?, 'payment_confirmed', ?, ?, ?, ?)
            ");
            $logStmt->bind_param('isssi', $donorId, $channel, $phone, $preview, $userId);
            $logStmt->execute();
            $logStmt->close();
        } catch (Exception $logError) {
            // Don't fail if logging fails
            error_log("Payment notification log error: " . $logError->getMessage());
        }

        sendJsonResponse([
            'success' => true,
            'message' => 'Notification sent successfully',
            'channel' => $result['channel'] ?? 'whatsapp'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to send notification'
        ]);
    }

} catch (Throwable $e) {
    error_log("Send payment notification error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
