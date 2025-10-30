<?php
declare(strict_types=1);

/**
 * Security Helper Functions
 * Provides input sanitization, validation, and XSS prevention
 */

/**
 * Sanitize string input - removes HTML tags and special characters
 */
function sanitize_string(string $input, int $max_length = 255): string {
    $sanitized = trim($input);
    $sanitized = strip_tags($sanitized);
    $sanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
    if ($max_length > 0 && mb_strlen($sanitized) > $max_length) {
        $sanitized = mb_substr($sanitized, 0, $max_length);
    }
    return $sanitized;
}

/**
 * Sanitize name - allows letters, spaces, apostrophes, hyphens, and unicode characters
 */
function sanitize_name($input, int $max_length = 255): string {
    if (empty($input) || !is_string($input)) {
        return '';
    }
    $sanitized = trim($input);
    // Remove any HTML tags
    $sanitized = strip_tags($sanitized);
    // Remove any script tags and event handlers
    $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi', '', $sanitized);
    $sanitized = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $sanitized);
    // Allow letters, spaces, apostrophes, hyphens, and unicode characters
    $sanitized = preg_replace('/[^\p{L}\s\'-]/u', '', $sanitized);
    // Remove multiple spaces
    $sanitized = preg_replace('/\s+/', ' ', $sanitized);
    $sanitized = trim($sanitized);
    if ($max_length > 0 && mb_strlen($sanitized) > $max_length) {
        $sanitized = mb_substr($sanitized, 0, $max_length);
    }
    return $sanitized;
}

/**
 * Sanitize phone number - digits only, handles UK format
 */
function sanitize_phone($input): string {
    if (empty($input) || !is_string($input)) {
        return '';
    }
    // Remove all non-digits
    $sanitized = preg_replace('/[^0-9]/', '', $input);
    // Handle +44 or 44 prefix (convert to 07xxx)
    if (!empty($sanitized) && substr($sanitized, 0, 2) === '44' && strlen($sanitized) === 12) {
        $sanitized = '0' . substr($sanitized, 2);
    }
    return $sanitized ?: '';
}

/**
 * Validate UK mobile phone number
 */
function validate_uk_mobile($phone): bool {
    if (empty($phone)) {
        return false;
    }
    $normalized = sanitize_phone($phone);
    return strlen($normalized) === 11 && substr($normalized, 0, 2) === '07';
}

/**
 * Sanitize email address
 */
function sanitize_email($input, int $max_length = 255): ?string {
    if (empty($input) || !is_string($input)) {
        return null;
    }
    $sanitized = trim($input);
    if (empty($sanitized)) {
        return null;
    }
    // Remove any HTML tags
    $sanitized = strip_tags($sanitized);
    // Filter email
    $sanitized = filter_var($sanitized, FILTER_SANITIZE_EMAIL);
    if ($max_length > 0 && mb_strlen($sanitized) > $max_length) {
        $sanitized = mb_substr($sanitized, 0, $max_length);
    }
    return $sanitized ?: null;
}

/**
 * Validate email address
 */
function validate_email($email): bool {
    if (empty($email) || !is_string($email)) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Sanitize integer input
 */
function sanitize_int($input, int $min = null, int $max = null): ?int {
    $value = filter_var($input, FILTER_VALIDATE_INT);
    if ($value === false) {
        return null;
    }
    if ($min !== null && $value < $min) {
        return null;
    }
    if ($max !== null && $value > $max) {
        return null;
    }
    return $value;
}

/**
 * Sanitize float/decimal input
 */
function sanitize_float($input, float $min = null, float $max = null): ?float {
    $value = filter_var($input, FILTER_VALIDATE_FLOAT);
    if ($value === false) {
        return null;
    }
    if ($min !== null && $value < $min) {
        return null;
    }
    if ($max !== null && $value > $max) {
        return null;
    }
    return $value;
}

/**
 * Sanitize enum value - ensures value is in allowed list
 */
function sanitize_enum($input, array $allowed_values, $default = null) {
    $sanitized = trim((string)$input);
    if (in_array($sanitized, $allowed_values, true)) {
        return $sanitized;
    }
    return $default;
}

/**
 * Sanitize boolean input
 */
function sanitize_bool($input): bool {
    if (is_bool($input)) {
        return $input;
    }
    if (is_string($input)) {
        $input = strtolower(trim($input));
        return in_array($input, ['1', 'true', 'on', 'yes'], true);
    }
    return (bool)$input;
}

/**
 * Escape output for HTML display (XSS prevention)
 */
function escape_html($input): string {
    if ($input === null || $input === '' || (!is_string($input) && !is_numeric($input))) {
        return '';
    }
    return htmlspecialchars((string)$input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Escape output for JavaScript (XSS prevention)
 */
function escape_js(string $input): string {
    return json_encode($input, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Validate input length
 */
function validate_length(string $input, int $min = 0, int $max = PHP_INT_MAX): bool {
    $length = mb_strlen($input);
    return $length >= $min && $length <= $max;
}

/**
 * Check for SQL injection patterns (basic check)
 */
function contains_sql_injection($input): bool {
    if (empty($input) || !is_string($input)) {
        return false;
    }
    $dangerous_patterns = [
        '/(\b(ALTER|CREATE|DELETE|DROP|EXEC(UTE)?|INSERT|MERGE|SELECT|UNION|UPDATE)\b)/i',
        '/(--|#|\/\*|\*\/|;|\'|"|`)/',
        '/(\b(OR|AND)\s+\d+\s*=\s*\d+)/i',
        '/(\b(OR|AND)\s+[\'"]?[\w]+[\'"]?\s*=\s*[\'"]?[\w]+[\'"]?)/i',
    ];
    
    foreach ($dangerous_patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    return false;
}

/**
 * Rate limiting check for form submissions
 */
function check_rate_limit(string $identifier, int $max_attempts = 5, int $time_window = 300): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    $key = 'rate_limit_' . md5($identifier);
    $now = time();
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => $now,
            'last_attempt' => $now
        ];
        return true;
    }
    
    $rate_data = $_SESSION[$key];
    
    // Reset if time window has passed
    if (($now - $rate_data['first_attempt']) > $time_window) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => $now,
            'last_attempt' => $now
        ];
        return true;
    }
    
    // Check if max attempts exceeded
    if ($rate_data['attempts'] >= $max_attempts) {
        return false;
    }
    
    // Increment attempts
    $_SESSION[$key]['attempts']++;
    $_SESSION[$key]['last_attempt'] = $now;
    
    return true;
}

/**
 * Validate CSRF token (wrapper for verify_csrf)
 */
function validate_csrf(): bool {
    if (!function_exists('verify_csrf')) {
        require_once __DIR__ . '/csrf.php';
    }
    return verify_csrf(false);
}

/**
 * Get client IP address securely
 */
function get_client_ip(): string {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

