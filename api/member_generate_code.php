<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }
    if (!verify_csrf(false)) {
        throw new RuntimeException('Invalid CSRF token');
    }
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Missing user_id');
    }

    $db = db();
    // Ensure user exists and is registrar
    $stmt = $db->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['role'] !== 'registrar') {
        throw new RuntimeException('User not found or not a registrar');
    }

    // Generate new 6-digit code and update hash
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $upd = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $upd->bind_param('si', $hash, $userId);
    $upd->execute();
    $upd->close();

    echo json_encode(['success' => true, 'passcode' => $code]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}


