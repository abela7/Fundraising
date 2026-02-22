<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin(); // Only admins can assign

header('Content-Type: application/json');

try {
    $db = db();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request data');
    }
    
    $action = $input['action'] ?? '';
    $donor_ids = $input['donor_ids'] ?? [];
    $agent_id = isset($input['agent_id']) ? (int)$input['agent_id'] : null;
    
    // Validate
    if (!in_array($action, ['assign', 'unassign'])) {
        throw new Exception('Invalid action');
    }
    
    if (empty($donor_ids) || !is_array($donor_ids)) {
        throw new Exception('No donors selected');
    }
    
    if ($action === 'assign' && !$agent_id) {
        throw new Exception('No agent selected');
    }
    
    // Verify agent exists if assigning
    if ($action === 'assign') {
        $agent_check = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role IN ('admin', 'registrar') AND active = 1");
        $agent_check->bind_param('i', $agent_id);
        $agent_check->execute();
        $agent = $agent_check->get_result()->fetch_assoc();
        
        if (!$agent) {
            throw new Exception('Invalid agent selected');
        }
    }
    
    // Process each donor
    $success_count = 0;
    $error_count = 0;
    
    foreach ($donor_ids as $donor_id) {
        $donor_id = (int)$donor_id;
        
        if ($action === 'assign') {
            // Assign donor to agent
            $stmt = $db->prepare("UPDATE donors SET agent_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $agent_id, $donor_id);
        } else {
            // Unassign donor (set to NULL)
            $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
            $stmt->bind_param('i', $donor_id);
        }
        
        if ($stmt->execute()) {
            $success_count++;
        } else {
            $error_count++;
        }
    }
    
    // Build success message
    if ($action === 'assign') {
        $message = "Successfully assigned {$success_count} donor(s) to " . htmlspecialchars($agent['name']);
    } else {
        $message = "Successfully unassigned {$success_count} donor(s)";
    }
    
    if ($error_count > 0) {
        $message .= ". {$error_count} error(s) occurred.";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'success_count' => $success_count,
        'error_count' => $error_count
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

