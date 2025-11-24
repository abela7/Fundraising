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
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

