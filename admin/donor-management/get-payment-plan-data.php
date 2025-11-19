<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

header('Content-Type: application/json');

$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$plan_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid plan ID']);
    exit;
}

try {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM donor_payment_plans WHERE id = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_assoc();
    
    if (!$plan) {
        echo json_encode(['success' => false, 'error' => 'Payment plan not found']);
        exit;
    }
    
    echo json_encode(['success' => true, 'plan' => $plan]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

