<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function current_user(): ?array {
    // If the session is set, we can return it without a DB query.
    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    return null;
}

function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

function is_admin(): bool {
    // Check session data first to avoid unnecessary DB queries.
    if (isset($_SESSION['user']['role'])) {
        return $_SESSION['user']['role'] === 'admin';
    }
    return false;
}

function require_login(): void {
    if (!is_logged_in()) {
        // Compute app base and correct login target based on area (admin vs registrar)
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $appBase = '';
        $loginPath = '/admin/login.php';

        if (($pos = strpos($script, '/admin/')) !== false) {
            $appBase = substr($script, 0, $pos);
            $loginPath = '/admin/login.php';
        } elseif (($pos = strpos($script, '/registrar/')) !== false) {
            $appBase = substr($script, 0, $pos);
            $loginPath = '/registrar/login.php';
        } else {
            // Fallback to same directory as the current script
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            $appBase = ($dir === '/' ? '' : $dir);
            if (substr($dir, -9) === '/registrar') {
                $loginPath = '/registrar/login.php';
            }
        }

        header('Location: ' . $appBase . $loginPath);
        exit;
    }
}

function require_admin(): void {
    require_login();
    $user = current_user();
    if (!$user) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    
    $role = $user['role'] ?? '';
    
    // Admins can access everything
    if ($role === 'admin') {
        return;
    }
    
    // Registrars can ONLY access donor-management and call-center pages
    if ($role === 'registrar') {
        // Check only the URL path (never query string) to avoid bypass via crafted parameters.
        $script_path = (string)parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
        $request_path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path_to_check = str_replace('\\', '/', $script_path !== '' ? $script_path : $request_path);
        $is_allowed_registrar_path = preg_match('#/admin/(donor-management|call-center)(/|$)#', $path_to_check) === 1;

        if ($is_allowed_registrar_path) {
            // Registrar can access these pages
            return;
        }
        
        // Registrar trying to access admin-only pages - redirect to access denied page
        $_SESSION['access_denied_message'] = 'You are not allowed to access this page. This page is restricted to administrators only.';
        header('Location: /registrar/access-denied.php');
        exit;
    }
    
    // Other roles - deny access
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function login_with_phone_password(string $phone, string $password): bool {
    $db = db();

    // Normalize input phone to digits-only for robust matching
    $inputDigits = preg_replace('/\D+/', '', $phone);

    // First try exact match
    $sql = 'SELECT id, name, phone, role, password_hash, active FROM users WHERE phone = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();

    // Fallback: compare digits-only (ignoring spaces, dashes, etc.)
    if (!$user && $inputDigits !== '') {
        $sql2 = "SELECT id, name, phone, role, password_hash, active
                 FROM users
                 WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = ?
                 LIMIT 1";
        $stmt2 = $db->prepare($sql2);
        $stmt2->bind_param('s', $inputDigits);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $user = $res2->fetch_assoc();
        $stmt2->close();
    }
    $stmt->close();
    if (!$user || (int)$user['active'] !== 1) {
        return false;
    }
    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Keep portal sessions isolated: staff login should not retain donor session state.
    unset($_SESSION['donor'], $_SESSION['otp_phone']);

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'phone' => $user['phone'],
        'role' => $user['role'],
    ];
    // Fix: Use prepared statement to prevent SQL injection
    $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
    $userId = (int)$user['id'];
    $updateStmt->bind_param('i', $userId);
    $updateStmt->execute();
    $updateStmt->close();

    // Audit log login
    require_once __DIR__ . '/audit_helper.php';
    log_audit(
        $db,
        'login',
        'user',
        (int)$user['id'],
        null,
        ['name' => $user['name'], 'role' => $user['role'], 'phone' => $user['phone']],
        get_current_source(),
        (int)$user['id']
    );

    session_regenerate_id(true);
    return true;
}

function logout(): void {
    // Audit log logout before clearing session
    if (isset($_SESSION['user']['id'])) {
        try {
            require_once __DIR__ . '/audit_helper.php';
            $db = db();
            log_audit(
                $db,
                'logout',
                'user',
                (int)$_SESSION['user']['id'],
                ['name' => $_SESSION['user']['name'] ?? '', 'role' => $_SESSION['user']['role'] ?? ''],
                null,
                get_current_source(),
                (int)$_SESSION['user']['id']
            );
        } catch (Exception $e) {
            // Don't fail logout if audit logging fails
            error_log("Audit logging failed on logout: " . $e->getMessage());
        }
    }
    
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Validate donor's trusted device token
 * If device was revoked or expired, log the donor out
 * Call this on every donor page load
 */
function validate_donor_device(): bool {
    // Only check if donor is logged in
    if (!isset($_SESSION['donor']['id'])) {
        return true; // Not logged in, no device to validate
    }

    // Donor pages must not keep staff auth in the same session.
    if (isset($_SESSION['user'])) {
        unset($_SESSION['user']);
    }
    
    // Check if they have a device token cookie
    $cookie_name = 'donor_device_token';
    if (!isset($_COOKIE[$cookie_name])) {
        return true; // No device token, might have logged in without "remember device"
    }
    
    $token = $_COOKIE[$cookie_name];
    
    // Validate token format (64 hex chars)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        // Invalid token format - clear it
        clear_donor_device_cookie();
        return true;
    }
    
    // Check database if tables exist
    try {
        $db = db();
        
        // Check if tables exist
        $table_check = $db->query("SHOW TABLES LIKE 'donor_trusted_devices'");
        if ($table_check->num_rows === 0) {
            return true; // Tables don't exist yet, skip validation
        }
        
        // Verify device is still active
        $stmt = $db->prepare("
            SELECT id, is_active, expires_at, donor_id
            FROM donor_trusted_devices
            WHERE device_token = ? AND donor_id = ?
            LIMIT 1
        ");
        $donor_id = (int)$_SESSION['donor']['id'];
        $stmt->bind_param('si', $token, $donor_id);
        $stmt->execute();
        $device = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // If device not found, revoked, or expired - log out
        if (!$device || !$device['is_active'] || strtotime($device['expires_at']) <= time()) {
            // Device was revoked or expired
            clear_donor_device_cookie();
            
            // Clear session
            unset($_SESSION['donor']);
            unset($_SESSION['otp_phone']);
            
            // Redirect to login with message
            header('Location: /donor/login.php?device_revoked=1');
            exit;
        }
        
        return true; // Device is valid
    } catch (Exception $e) {
        // If DB check fails, allow access (fail open for availability)
        error_log("Device validation failed: " . $e->getMessage());
        return true;
    }
}

/**
 * Clear donor device token cookie
 */
function clear_donor_device_cookie(): void {
    $cookie_options = [
        'expires' => time() - 3600,
        'path' => '/donor/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('donor_device_token', '', $cookie_options);
}
