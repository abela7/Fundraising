<?php
declare(strict_types=1);

// Basic environment configuration for local XAMPP
// Override with environment variables if needed

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: ''); // XAMPP default
// IMPORTANT: database name matches your schema dump (Fundraising)
define('DB_NAME', getenv('DB_NAME') ?: 'Fundraising');

date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/London');

// Development error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Session hardening
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
// Uncomment on HTTPS deployments
// ini_set('session.cookie_secure', '1');
