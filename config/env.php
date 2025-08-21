<?php
declare(strict_types=1);

// Check for local configuration first (for development)
if (file_exists(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
} else {
    // Live production configuration (hardcoded)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'abunetdg_abela');
    define('DB_PASS', '2424@Admin');
    define('DB_NAME', 'abunetdg_fundraising');
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
