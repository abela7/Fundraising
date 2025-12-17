<?php
/**
 * Send Password Reset Code API
 * 
 * Sends a 6-digit verification code via WhatsApp (fallback to SMS)
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/MessagingHelper.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
$phone = trim($input['phone'] ?? '');
$resend = $input['resend'] ?? false;

if (empty($phone)) {
    echo json_encode(['success' => false, 'error' => 'Phone number is required']);
    exit;
}

try {
    $db = db();
    
    // Ensure password_reset_codes table exists
    $db->query("
        CREATE TABLE IF NOT EXISTS password_reset_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(6) NOT NULL,
            token VARCHAR(64) NOT NULL,
            verified TINYINT(1) DEFAULT 0,
            attempts INT DEFAULT 0,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_token (token),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Normalize phone for lookup (digits only)
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    
    // Find user with this phone who is a registrar
    $stmt = $db->prepare("
        SELECT id, name, phone, role, active 
        FROM users 
        WHERE (phone = ? OR REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?)
        AND role IN ('registrar', 'admin')
        LIMIT 1
    ");
    $stmt->bind_param('ss', $phone, $phoneDigits);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'No registrar account found with this phone number']);
        exit;
    }
    
    if (!$user['active']) {
        echo json_encode(['success' => false, 'error' => 'This account is not active. Please contact an administrator.']);
        exit;
    }
    
    // Rate limiting: Max 5 codes per hour per phone
    $stmt = $db->prepare("
        SELECT COUNT(*) as cnt FROM password_reset_codes 
        WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $rateCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($rateCheck['cnt'] >= 5) {
        echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
        exit;
    }
    
    // Generate 6-digit code
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Generate secure token
    $token = bin2hex(random_bytes(32));
    
    // Delete old codes for this user
    $stmt = $db->prepare("DELETE FROM password_reset_codes WHERE user_id = ?");
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $stmt->close();
    
    // Store new code (expires in 10 minutes)
    $stmt = $db->prepare("
        INSERT INTO password_reset_codes (user_id, code, token, expires_at, created_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
    ");
    $stmt->bind_param('iss', $user['id'], $code, $token);
    $stmt->execute();
    $stmt->close();
    
    // Send code via WhatsApp (fallback to SMS)
    $messaging = new MessagingHelper($db);
    
    $message = "Your verification code is: {$code}\n\nThis code expires in 10 minutes.\n\nIf you didn't request this, please ignore this message.";
    
    // Try WhatsApp first
    $result = $messaging->sendDirect($user['phone'], $message, 'whatsapp', null, 'password_reset');
    $method = 'whatsapp';
    
    // Fallback to SMS if WhatsApp fails
    if (!$result['success']) {
        $result = $messaging->sendDirect($user['phone'], "Your verification code is: {$code}. Expires in 10 minutes.", 'sms', null, 'password_reset');
        $method = 'sms';
    }
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'token' => $token,
            'method' => $method,
            'message' => "Code sent via " . ($method === 'whatsapp' ? 'WhatsApp' : 'SMS')
        ]);
    } else {
        // Log the error
        error_log("Password reset code send failed for user {$user['id']}: " . ($result['error'] ?? 'Unknown error'));
        
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send verification code. Please try again.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Password reset error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}

