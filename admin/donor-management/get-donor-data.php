<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

header('Content-Type: application/json');

$donor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$donor_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid donor ID']);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_assoc();
    
    if (!$donor) {
        echo json_encode(['success' => false, 'error' => 'Donor not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'donor' => $donor]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

