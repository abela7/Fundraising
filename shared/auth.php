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
    // Allow both admin and registrar roles to access admin pages
    $user = current_user();
    if (!$user || !in_array($user['role'] ?? '', ['admin', 'registrar'], true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
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
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'phone' => $user['phone'],
        'role' => $user['role'],
    ];
    $db->query("UPDATE users SET last_login_at = NOW() WHERE id = " . (int)$user['id']);
    session_regenerate_id(true);
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
