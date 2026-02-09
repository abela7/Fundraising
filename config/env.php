<?php
declare(strict_types=1);

/**
 * Smart Environment Detection & Database Configuration
 * Automatically detects if running on local or server and uses appropriate database
 */

function isLocalEnvironment(): bool {
    // Check multiple indicators for local environment
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $currentPath = __DIR__;
    
    // Local environment indicators
    $localIndicators = [
        // Web-based detection
        strpos($host, 'localhost') !== false,
        strpos($host, '127.0.0.1') !== false,
        strpos($host, '.local') !== false,
        strpos($serverName, 'localhost') !== false,
        
        // Path-based detection (works for CLI too)
        strpos($documentRoot, 'xampp') !== false,
        strpos($documentRoot, 'wamp') !== false,
        strpos($documentRoot, 'mamp') !== false,
        strpos($currentPath, 'xampp') !== false,
        strpos($currentPath, 'wamp') !== false,
        strpos($currentPath, 'mamp') !== false,
        
        // Windows local development paths
        strpos($currentPath, 'C:\\xampp') !== false,
        strpos($currentPath, 'C:\\wamp') !== false,
        
        // Check if we're in a typical local dev environment
        (PHP_OS_FAMILY === 'Windows' && strpos($currentPath, 'htdocs') !== false)
    ];
    
    return in_array(true, $localIndicators, true);
}

// Smart database configuration
if (isLocalEnvironment()) {
    // LOCAL ENVIRONMENT - Use local database
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');  // Default XAMPP user
    define('DB_PASS', '');      // Default XAMPP password (empty)
    define('DB_NAME', 'abunetdg_fundraising_local');
    define('ENVIRONMENT', 'local');
} else {
    // Check for custom local configuration first
    if (file_exists(__DIR__ . '/env.local.php')) {
        require_once __DIR__ . '/env.local.php';
        define('ENVIRONMENT', 'local_custom');
    } else {
        // LIVE SERVER - Use production database
        // Security: Load credentials from environment variables or external config
        define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
        define('DB_USER', $_ENV['DB_USER'] ?? 'abunetdg_abela');
        define('DB_PASS', $_ENV['DB_PASS'] ?? '2424@Admin');
        define('DB_NAME', $_ENV['DB_NAME'] ?? 'abunetdg_fundraising');
        define('ENVIRONMENT', 'production');
    }
}

date_default_timezone_set('Africa/Addis_Ababa');

// Production error handling
ini_set('display_errors', '0');
// Show all except notices/strict/deprecated to keep logs useful
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);

// Session hardening
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
// Enable secure cookies automatically if HTTPS is detected
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
	ini_set('session.cookie_secure', '1');
}
