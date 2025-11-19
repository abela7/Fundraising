<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

header('Content-Type: application/json');

$pledge_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$pledge_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid pledge ID']);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM pledges WHERE id = ?");
    $stmt->bind_param('i', $pledge_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pledge = $result->fetch_assoc();
    
    if (!$pledge) {
        echo json_encode(['success' => false, 'error' => 'Pledge not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'pledge' => $pledge]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

