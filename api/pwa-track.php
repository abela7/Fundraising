<?php
/**
 * PWA Installation Tracking - SIMPLE VERSION
 * 
 * Only tracks when app is opened in standalone mode.
 * Uses device fingerprint to prevent duplicates.
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $db = db();
    
    // Ensure table exists with fingerprint column
    $db->query("CREATE TABLE IF NOT EXISTS pwa_installations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_type ENUM('donor', 'registrar', 'admin') NOT NULL,
        user_id INT NOT NULL,
        device_fingerprint VARCHAR(64) NOT NULL,
        device_type VARCHAR(50) NULL,
        device_platform VARCHAR(100) NULL,
        browser VARCHAR(100) NULL,
        screen_width INT NULL,
        screen_height INT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        open_count INT DEFAULT 1,
        is_active TINYINT(1) DEFAULT 1,
        UNIQUE KEY unique_install (user_type, user_id, device_fingerprint),
        INDEX idx_user (user_type, user_id),
        INDEX idx_fingerprint (device_fingerprint)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Get data
    $userType = $input['user_type'] ?? '';
    $userId = (int)($input['user_id'] ?? 0);
    $screenWidth = (int)($input['screen_width'] ?? 0);
    $screenHeight = (int)($input['screen_height'] ?? 0);
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Validate
    if (!in_array($userType, ['donor', 'registrar', 'admin'])) {
        throw new Exception('Invalid user type');
    }
    if ($userId <= 0) {
        throw new Exception('Invalid user ID');
    }
    
    // Create device fingerprint from multiple factors
    $fingerprintData = implode('|', [
        $userType,
        $userId,
        $screenWidth,
        $screenHeight,
        substr($userAgent, 0, 200), // First 200 chars of UA
        // Don't include IP in fingerprint - it can change
    ]);
    $fingerprint = hash('sha256', $fingerprintData);
    $shortFingerprint = substr($fingerprint, 0, 32);
    
    // Parse user agent
    $deviceInfo = parseUserAgent($userAgent);
    
    // Try to insert or update
    $stmt = $db->prepare("INSERT INTO pwa_installations 
        (user_type, user_id, device_fingerprint, device_type, device_platform, browser, screen_width, screen_height, ip_address, user_agent, last_opened_at, open_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
        ON DUPLICATE KEY UPDATE 
            last_opened_at = NOW(),
            open_count = open_count + 1,
            ip_address = VALUES(ip_address),
            is_active = 1");
    
    $stmt->bind_param('sissssiiis',
        $userType,
        $userId,
        $shortFingerprint,
        $deviceInfo['device_type'],
        $deviceInfo['platform'],
        $deviceInfo['browser'],
        $screenWidth,
        $screenHeight,
        $ip,
        $userAgent
    );
    
    $stmt->execute();
    $isNew = $stmt->affected_rows === 1;
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'status' => $isNew ? 'new_install' : 'returning_user',
        'fingerprint' => $shortFingerprint
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function parseUserAgent(string $ua): array {
    $deviceType = 'unknown';
    $platform = 'unknown';
    $browser = 'unknown';
    
    // Device type
    if (preg_match('/iPhone/i', $ua)) {
        $deviceType = 'ios';
        $platform = 'iPhone';
        if (preg_match('/OS (\d+[_\.]\d+)/i', $ua, $m)) {
            $platform .= ' iOS ' . str_replace('_', '.', $m[1]);
        }
    } elseif (preg_match('/iPad/i', $ua)) {
        $deviceType = 'ios';
        $platform = 'iPad';
        if (preg_match('/OS (\d+[_\.]\d+)/i', $ua, $m)) {
            $platform .= ' iOS ' . str_replace('_', '.', $m[1]);
        }
    } elseif (preg_match('/Android/i', $ua)) {
        $deviceType = 'android';
        $platform = 'Android';
        if (preg_match('/Android (\d+\.?\d*)/i', $ua, $m)) {
            $platform .= ' ' . $m[1];
        }
    } elseif (preg_match('/Windows/i', $ua)) {
        $deviceType = 'desktop';
        $platform = 'Windows';
    } elseif (preg_match('/Macintosh/i', $ua)) {
        $deviceType = 'desktop';
        $platform = 'macOS';
    }
    
    // Browser
    if (preg_match('/EdgA?\/(\d+)/i', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $m) && !preg_match('/Edg/i', $ua)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Safari';
        if (preg_match('/Version\/(\d+\.?\d*)/i', $ua, $v)) {
            $browser .= ' ' . $v[1];
        }
    } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/SamsungBrowser\/(\d+)/i', $ua, $m)) {
        $browser = 'Samsung ' . $m[1];
    }
    
    return compact('device_type', 'platform', 'browser');
}
