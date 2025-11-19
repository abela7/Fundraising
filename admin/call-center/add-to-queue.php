<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

header('Content-Type: application/json');

try {
    $db = db();
    
    // Get POST data
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $queue_type = trim($_POST['queue_type'] ?? '');
    $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 5;
    $reason = trim($_POST['reason'] ?? '');
    
    // Validate
    if (!$donor_id) {
        throw new Exception('Donor ID is required');
    }
    
    if (empty($queue_type)) {
        throw new Exception('Queue type is required');
    }
    
    // Validate priority (1-10)
    $priority = max(1, min(10, $priority));
    
    // Check if donor exists
    $check_donor = $db->prepare("SELECT id, name FROM donors WHERE id = ?");
    $check_donor->bind_param('i', $donor_id);
    $check_donor->execute();
    $donor_result = $check_donor->get_result();
    
    if ($donor_result->num_rows === 0) {
        throw new Exception('Donor not found');
    }
    
    $check_donor->close();
    
    // Check if already in queue
    $check_queue = $db->prepare("SELECT id FROM call_center_queues WHERE donor_id = ? AND status = 'pending'");
    $check_queue->bind_param('i', $donor_id);
    $check_queue->execute();
    $queue_result = $check_queue->get_result();
    
    if ($queue_result->num_rows > 0) {
        throw new Exception('Donor is already in the queue');
    }
    
    $check_queue->close();
    
    // Add to queue
    $insert_query = "
        INSERT INTO call_center_queues 
        (donor_id, queue_type, priority, status, reason_for_queue, created_at)
        VALUES (?, ?, ?, 'pending', ?, NOW())
    ";
    
    $stmt = $db->prepare($insert_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare query: ' . $db->error);
    }
    
    $stmt->bind_param('isis', $donor_id, $queue_type, $priority, $reason);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to add to queue: ' . $stmt->error);
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Donor added to queue successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

