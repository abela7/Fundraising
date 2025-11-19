<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

header('Content-Type: application/json');

$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$session_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM call_center_sessions WHERE id = ?");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_assoc();
    
    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Call session not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'session' => $session]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

