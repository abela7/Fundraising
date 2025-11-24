<?php
// admin/donor-management/ajax-update-schedule.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_login();
    require_admin();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $schedule_id = isset($input['id']) ? (int)$input['id'] : 0;
    $new_date = isset($input['date']) ? $input['date'] : '';
    
    if ($schedule_id <= 0 || empty($new_date)) {
        throw new Exception("Invalid input");
    }
    
    $db = db();
    
    // Update
    $stmt = $db->prepare("UPDATE payment_plan_schedule SET due_date = ? WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('si', $new_date, $schedule_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        // Check if it exists but wasn't pending
        $check = $db->query("SELECT status FROM payment_plan_schedule WHERE id = $schedule_id");
        if ($row = $check->fetch_assoc()) {
            if ($row['status'] !== 'pending') {
                throw new Exception("Cannot edit non-pending installment");
            }
        }
    }
    
    // Sync 'Next Payment Due' on main plan
    // Find the plan ID
    $pid_res = $db->query("SELECT plan_id FROM payment_plan_schedule WHERE id = $schedule_id");
    if ($pid_row = $pid_res->fetch_assoc()) {
        $plan_id = $pid_row['plan_id'];
        
        // Find earliest pending date
        $min_res = $db->query("SELECT MIN(due_date) as next_due FROM payment_plan_schedule WHERE plan_id = $plan_id AND status = 'pending'");
        if ($min_row = $min_res->fetch_assoc()) {
            $next_due = $min_row['next_due'];
            if ($next_due) {
                $up_stmt = $db->prepare("UPDATE donor_payment_plans SET next_payment_due = ?, updated_at = NOW() WHERE id = ?");
                $up_stmt->bind_param('si', $next_due, $plan_id);
                $up_stmt->execute();
            }
        }
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

