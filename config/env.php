<?php
declare(strict_types=1);

// Optional per-environment override file. Not tracked in Git.
// If present, it can pre-define constants like DB_HOST/DB_USER/DB_PASS/DB_NAME
// so the default definitions below will not override them.
if (file_exists(__DIR__ . '/env.local.php')) {
	require __DIR__ . '/env.local.php';
}

/**
 * Reads configuration from environment variables with graceful fallbacks.
 * Also checks $_ENV and $_SERVER to support environments where SetEnv is used.
 */
function env(string $key, ?string $default = null): ?string {
	$value = getenv($key);
	if ($value === false || $value === '') {
		$value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
	}
	return ($value !== null && $value !== '') ? $value : $default;
}

// Basic environment configuration for local XAMPP / default
// Only define if not already provided by env.local.php or the server environment
if (!defined('DB_HOST')) {
	define('DB_HOST', env('DB_HOST', '127.0.0.1'));
}
if (!defined('DB_USER')) {
	define('DB_USER', env('DB_USER', 'root'));
}
if (!defined('DB_PASS')) {
	define('DB_PASS', env('DB_PASS', ''));
}
if (!defined('DB_NAME')) {
	// IMPORTANT: database name matches your schema dump (Fundraising)
	define('DB_NAME', env('DB_NAME', 'Fundraising'));
}

date_default_timezone_set(env('APP_TZ', 'Europe/London'));

// Environment mode: development by default unless overridden
$appEnv = env('APP_ENV', 'development');
if ($appEnv === 'production') {
	// Production: no error display, stricter reporting
	ini_set('display_errors', '0');
	// Show all except notices/strict/deprecated to keep logs useful
	error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
} else {
	// Development defaults
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
}

// Session hardening
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
// Enable secure cookies automatically if HTTPS is detected
if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || env('APP_HTTPS', '0') === '1') {
	ini_set('session.cookie_secure', '1');
}
