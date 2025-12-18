<?php
/**
 * PWA Installation Tracking API
 * 
 * Tracks when users install the PWA on their devices.
 * Works with session-based auth (no JWT needed).
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/auth.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$action = $input['action'] ?? '';

try {
    $db = db();
    
    // Ensure table exists
    $db->query("CREATE TABLE IF NOT EXISTS pwa_installations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_type ENUM('donor', 'registrar', 'admin') NOT NULL,
        user_id INT NOT NULL,
        device_type VARCHAR(50) NULL,
        device_platform VARCHAR(100) NULL,
        browser VARCHAR(100) NULL,
        screen_width INT NULL,
        screen_height INT NULL,
        user_agent TEXT NULL,
        install_method VARCHAR(50) NULL,
        installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_opened_at DATETIME NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_type, user_id),
        INDEX idx_installed_at (installed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    switch ($action) {
        case 'install':
            $result = trackInstall($db, $input);
            break;
            
        case 'heartbeat':
            $result = updateHeartbeat($db, $input);
            break;
            
        case 'check':
            $result = checkInstallation($db, $input);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Track a new installation
 */
function trackInstall(mysqli $db, array $input): array {
    $userType = $input['user_type'] ?? '';
    $userId = (int)($input['user_id'] ?? 0);
    
    if (!in_array($userType, ['donor', 'registrar', 'admin'])) {
        throw new Exception('Invalid user type');
    }
    
    if ($userId <= 0) {
        throw new Exception('Invalid user ID');
    }
    
    // Parse user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceInfo = parseUserAgent($userAgent);
    
    // Check if already installed
    $stmt = $db->prepare("SELECT id FROM pwa_installations WHERE user_type = ? AND user_id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('si', $userType, $userId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existing) {
        // Update existing record
        $stmt = $db->prepare("UPDATE pwa_installations SET 
            last_opened_at = NOW(),
            device_type = ?,
            device_platform = ?,
            browser = ?,
            screen_width = ?,
            screen_height = ?,
            user_agent = ?,
            install_method = ?
            WHERE id = ?");
        
        $screenWidth = (int)($input['screen_width'] ?? 0);
        $screenHeight = (int)($input['screen_height'] ?? 0);
        $installMethod = $input['install_method'] ?? 'unknown';
        
        $stmt->bind_param('sssiiisi',
            $deviceInfo['device_type'],
            $deviceInfo['platform'],
            $deviceInfo['browser'],
            $screenWidth,
            $screenHeight,
            $userAgent,
            $installMethod,
            $existing['id']
        );
        $stmt->execute();
        $stmt->close();
        
        return ['status' => 'updated', 'id' => $existing['id']];
    }
    
    // Insert new record
    $stmt = $db->prepare("INSERT INTO pwa_installations 
        (user_type, user_id, device_type, device_platform, browser, screen_width, screen_height, user_agent, install_method, last_opened_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    $screenWidth = (int)($input['screen_width'] ?? 0);
    $screenHeight = (int)($input['screen_height'] ?? 0);
    $installMethod = $input['install_method'] ?? 'unknown';
    
    $stmt->bind_param('sisssiiis',
        $userType,
        $userId,
        $deviceInfo['device_type'],
        $deviceInfo['platform'],
        $deviceInfo['browser'],
        $screenWidth,
        $screenHeight,
        $userAgent,
        $installMethod
    );
    $stmt->execute();
    $installId = $db->insert_id;
    $stmt->close();
    
    return ['status' => 'installed', 'id' => $installId];
}

/**
 * Update last opened timestamp (heartbeat)
 */
function updateHeartbeat(mysqli $db, array $input): array {
    $userType = $input['user_type'] ?? '';
    $userId = (int)($input['user_id'] ?? 0);
    
    if (!in_array($userType, ['donor', 'registrar', 'admin']) || $userId <= 0) {
        throw new Exception('Invalid user');
    }
    
    $stmt = $db->prepare("UPDATE pwa_installations SET last_opened_at = NOW() WHERE user_type = ? AND user_id = ? AND is_active = 1");
    $stmt->bind_param('si', $userType, $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    return ['updated' => $affected > 0];
}

/**
 * Check if user has installed
 */
function checkInstallation(mysqli $db, array $input): array {
    $userType = $input['user_type'] ?? '';
    $userId = (int)($input['user_id'] ?? 0);
    
    if (!in_array($userType, ['donor', 'registrar', 'admin']) || $userId <= 0) {
        return ['installed' => false];
    }
    
    $stmt = $db->prepare("SELECT id, installed_at, last_opened_at, device_type, browser FROM pwa_installations WHERE user_type = ? AND user_id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('si', $userType, $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        return [
            'installed' => true,
            'installed_at' => $result['installed_at'],
            'last_opened' => $result['last_opened_at'],
            'device' => $result['device_type'],
            'browser' => $result['browser']
        ];
    }
    
    return ['installed' => false];
}

/**
 * Parse user agent string
 */
function parseUserAgent(string $ua): array {
    $deviceType = 'unknown';
    $platform = 'unknown';
    $browser = 'unknown';
    
    // Detect device type
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $deviceType = 'ios';
        if (preg_match('/iPhone/i', $ua)) $platform = 'iPhone';
        elseif (preg_match('/iPad/i', $ua)) $platform = 'iPad';
        else $platform = 'iPod';
        
        // Get iOS version
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
    } elseif (preg_match('/Macintosh|Mac OS/i', $ua)) {
        $deviceType = 'desktop';
        $platform = 'macOS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $deviceType = 'desktop';
        $platform = 'Linux';
    }
    
    // Detect browser
    if (preg_match('/EdgA?\/(\d+)/i', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/Chrome\/(\d+)/i', $ua, $m)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Safari\/(\d+)/i', $ua, $m) && !preg_match('/Chrome/i', $ua)) {
        $browser = 'Safari';
        if (preg_match('/Version\/(\d+\.?\d*)/i', $ua, $v)) {
            $browser .= ' ' . $v[1];
        }
    } elseif (preg_match('/Firefox\/(\d+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/SamsungBrowser\/(\d+)/i', $ua, $m)) {
        $browser = 'Samsung ' . $m[1];
    }
    
    return [
        'device_type' => $deviceType,
        'platform' => $platform,
        'browser' => $browser
    ];
}

