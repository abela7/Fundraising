<?php
/**
 * Verify Reset Code & Set New Password API
 * 
 * Two actions:
 * 1. verify - Verify the code is correct
 * 2. reset - Set new password (requires verified token)
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
$phone = trim($input['phone'] ?? '');
$token = trim($input['token'] ?? '');
$action = $input['action'] ?? 'verify';

if (empty($phone) || empty($token)) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $db = db();
    
    // Normalize phone for lookup
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    
    // Find user with this phone
    $stmt = $db->prepare("
        SELECT id, phone, role 
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
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    if ($action === 'verify') {
        // Verify the code
        $code = trim($input['code'] ?? '');
        
        if (empty($code) || strlen($code) !== 6) {
            echo json_encode(['success' => false, 'error' => 'Invalid code format']);
            exit;
        }
        
        // Find valid reset code
        $stmt = $db->prepare("
            SELECT id, code, token, verified 
            FROM password_reset_codes 
            WHERE user_id = ? AND token = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param('is', $user['id'], $token);
        $stmt->execute();
        $resetCode = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$resetCode) {
            echo json_encode(['success' => false, 'error' => 'Code expired. Please request a new one.']);
            exit;
        }
        
        // Check if code matches
        if ($resetCode['code'] !== $code) {
            // Track failed attempts
            $stmt = $db->prepare("
                UPDATE password_reset_codes 
                SET attempts = attempts + 1 
                WHERE id = ?
            ");
            $stmt->bind_param('i', $resetCode['id']);
            $stmt->execute();
            $stmt->close();
            
            // Check if too many attempts
            $stmt = $db->prepare("SELECT attempts FROM password_reset_codes WHERE id = ?");
            $stmt->bind_param('i', $resetCode['id']);
            $stmt->execute();
            $attempts = $stmt->get_result()->fetch_assoc()['attempts'];
            $stmt->close();
            
            if ($attempts >= 5) {
                // Delete the code - too many attempts
                $stmt = $db->prepare("DELETE FROM password_reset_codes WHERE id = ?");
                $stmt->bind_param('i', $resetCode['id']);
                $stmt->execute();
                $stmt->close();
                
                echo json_encode(['success' => false, 'error' => 'Too many failed attempts. Please request a new code.']);
                exit;
            }
            
            echo json_encode(['success' => false, 'error' => 'Incorrect code. Please try again.']);
            exit;
        }
        
        // Code is correct - generate new token for password reset step
        $newToken = bin2hex(random_bytes(32));
        
        $stmt = $db->prepare("
            UPDATE password_reset_codes 
            SET verified = 1, token = ?, verified_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('si', $newToken, $resetCode['id']);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'token' => $newToken,
            'message' => 'Code verified successfully'
        ]);
        
    } elseif ($action === 'reset') {
        // Reset password
        $password = $input['password'] ?? '';
        
        if (empty($password) || strlen($password) !== 6 || !ctype_digit($password)) {
            echo json_encode(['success' => false, 'error' => 'Password must be a 6-digit number']);
            exit;
        }
        
        // Debug: Log the incoming token
        error_log("Reset password attempt - User ID: {$user['id']}, Token length: " . strlen($token));
        
        // Find verified reset code - use a simpler query first
        $stmt = $db->prepare("
            SELECT id, verified, verified_at 
            FROM password_reset_codes 
            WHERE user_id = ? AND token = ?
            LIMIT 1
        ");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . $db->error]);
            exit;
        }
        $stmt->bind_param('is', $user['id'], $token);
        $stmt->execute();
        $resetCode = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$resetCode) {
            error_log("Reset code not found for user {$user['id']} with token");
            echo json_encode(['success' => false, 'error' => 'Session not found. Please start over.']);
            exit;
        }
        
        if (!$resetCode['verified']) {
            echo json_encode(['success' => false, 'error' => 'Code not verified. Please verify your code first.']);
            exit;
        }
        
        // Check if verified within last 15 minutes
        $verifiedAt = strtotime($resetCode['verified_at']);
        $fifteenMinutesAgo = time() - (15 * 60);
        if ($verifiedAt < $fifteenMinutesAgo) {
            echo json_encode(['success' => false, 'error' => 'Session expired. Please start over.']);
            exit;
        }
        
        // Hash the new password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Check if updated_at column exists
        $hasUpdatedAt = false;
        $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'updated_at'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $hasUpdatedAt = true;
        }
        
        // Update user password
        if ($hasUpdatedAt) {
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
        } else {
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        }
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'DB prepare failed for update: ' . $db->error]);
            exit;
        }
        $stmt->bind_param('si', $passwordHash, $user['id']);
        $success = $stmt->execute();
        $updateError = $stmt->error;
        $stmt->close();
        
        if ($success) {
            // Delete all reset codes for this user
            $stmt = $db->prepare("DELETE FROM password_reset_codes WHERE user_id = ?");
            $stmt->bind_param('i', $user['id']);
            $stmt->execute();
            $stmt->close();
            
            error_log("Password reset successful for user {$user['id']}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Password reset successfully'
            ]);
        } else {
            error_log("Password update failed for user {$user['id']}: " . $updateError);
            echo json_encode(['success' => false, 'error' => 'Failed to update password: ' . $updateError]);
        }
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Password reset verification error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
}

